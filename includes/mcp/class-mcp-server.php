<?php
/**
 * MCP transport: route registration and JSON-RPC 2.0 envelope.
 *
 * Speaks Streamable HTTP with plain application/json responses (permitted by
 * the MCP spec as the non-SSE variant). Stateless: no session ID is issued.
 *
 * @package Auditra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers POST /wp-json/auditra/v1/mcp/{token} and dispatches JSON-RPC.
 */
class Auditra_MCP_Server {

	const REST_NAMESPACE = 'auditra/v1';

	/**
	 * Legacy protocol versions this server supports, newest first: the
	 * revisions that negotiate through an initialize handshake. Verified
	 * against the live MCP specification on 2026-07-27.
	 *
	 * @var string[]
	 */
	const PROTOCOL_VERSIONS = array( '2025-11-25', '2025-06-18', '2025-03-26' );

	/**
	 * Modern protocol versions: revisions that carry version, identity, and
	 * capabilities in per-request _meta (spec 2026-07-28 and later). A request
	 * is served in the era its shape declares; the server holds no state
	 * about which era a client speaks.
	 *
	 * @var string[]
	 */
	const MODERN_VERSIONS = array( '2026-07-28' );

	/**
	 * Reserved _meta keys from the 2026-07-28 revision.
	 */
	const META_PROTOCOL_VERSION    = 'io.modelcontextprotocol/protocolVersion';
	const META_CLIENT_CAPABILITIES = 'io.modelcontextprotocol/clientCapabilities';
	const META_SERVER_INFO         = 'io.modelcontextprotocol/serverInfo';

	/**
	 * Spec-defined error codes (2026-07-28), allocated from the sub-range the
	 * MCP specification reserves.
	 */
	const ERR_HEADER_MISMATCH     = -32020;
	const ERR_UNSUPPORTED_VERSION = -32022;

	/**
	 * Longest Origin header parsed. A serialized origin is a scheme, a host,
	 * and maybe a port; the longest legal hostname is 253 characters.
	 */
	const MAX_ORIGIN_CHARS = 512;

	/**
	 * Longest attacker-supplied value echoed back in an error message.
	 */
	const MAX_ECHO_CHARS = 64;

	/**
	 * Longest header value decoded from the Base64 sentinel form. Generous
	 * against the longest tool name; anything past it cannot match one.
	 */
	const MAX_HEADER_VALUE_CHARS = 1024;

	/**
	 * Freshness hint on cacheable list results, in milliseconds: 24 hours.
	 * The tool catalog is fixed at build time and changes only when the
	 * plugin itself is updated, so a long TTL is honest; a day bounds how
	 * long a client can miss a new tool after an update without reconnecting
	 * (docs/DECISIONS.md 58).
	 */
	const LIST_TTL_MS = 86400000;

	/**
	 * Shared intermediaries must never cache MCP responses from this server:
	 * the token rides in the URL path, and a cached response is a cached
	 * secret-addressed answer.
	 */
	const LIST_CACHE_SCOPE = 'private';

	/**
	 * Authentication mechanism.
	 *
	 * @var Auditra_Auth_Interface
	 */
	private $auth;

	/**
	 * Tool catalog.
	 *
	 * @var Auditra_Tool_Registry
	 */
	private $registry;

	/**
	 * Nesting level of the output buffer this class opened, or null when it
	 * holds none. Recorded so cleanup can close exactly its own buffer.
	 *
	 * @var int|null
	 */
	private $buffer_level = null;

	/**
	 * Constructor.
	 *
	 * @param Auditra_Auth_Interface $auth     Authentication mechanism.
	 * @param Auditra_Tool_Registry  $registry Tool catalog.
	 */
	public function __construct( Auditra_Auth_Interface $auth, Auditra_Tool_Registry $registry ) {
		$this->auth     = $auth;
		$this->registry = $registry;
	}

	/**
	 * Registers the MCP route. Auth happens inside the handler so the error
	 * shapes stay under our control.
	 *
	 * @return void
	 */
	public function register_routes() {
		// Other plugins sometimes emit stray notices during REST bootstrap.
		// Capture everything from here on so the JSON body stays clean.
		//
		// The buffer is opened here and closed in discard_buffer(), which is
		// reached on every path out of the request: the normal one through
		// rest_pre_serve_request, and a shutdown fallback for the case where
		// the response never gets that far. Only the buffer opened here is
		// ever closed — see discard_buffer() for why that matters.
		if ( $this->is_mcp_request() ) {
			ob_start();
			$this->buffer_level = ob_get_level();
			add_action( 'shutdown', array( $this, 'discard_buffer' ), 0 );
		}

		register_rest_route(
			self::REST_NAMESPACE,
			'/mcp/(?P<token>[A-Za-z0-9_-]+)',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle_post' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'handle_get' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * There is no SSE stream in v1; GET is not part of this transport.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_get() {
		return new WP_REST_Response( array( 'error' => 'method_not_allowed' ), 405 );
	}

	/**
	 * Handles a JSON-RPC message POSTed to the MCP endpoint.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function handle_post( $request ) {
		if ( ! $this->auth->is_endpoint_enabled() ) {
			return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}

		// DNS-rebinding guard, per the transport spec: a present-but-foreign
		// Origin is rejected with 403 and a JSON-RPC error carrying no id.
		// An absent Origin MUST pass — MCP clients are server-to-server and
		// send none; only browsers volunteer this header. Getting that
		// backwards would break every legitimate install.
		if ( ! $this->origin_allowed( $request ) ) {
			return $this->error_response( null, -32600, 'Origin not allowed', 403 );
		}

		// Rate limiting sits before authentication so failed-token attempts
		// are throttled too.
		if ( ! Auditra_Request_Guard::allow_request() ) {
			return new WP_REST_Response( array( 'error' => 'rate_limited' ), 429 );
		}

		if ( ! $this->auth->verify( (string) $request->get_param( 'token' ) ) ) {
			Auditra_Request_Guard::log_failed_auth();
			return new WP_REST_Response( array( 'error' => 'unauthorized' ), 401 );
		}

		$message = json_decode( $request->get_body(), true );

		if ( null === $message && JSON_ERROR_NONE !== json_last_error() ) {
			return $this->error_response( null, -32700, 'Parse error', 400 );
		}

		if ( ! is_array( $message ) || ! isset( $message['jsonrpc'] ) || '2.0' !== $message['jsonrpc'] || empty( $message['method'] ) || ! is_string( $message['method'] ) ) {
			return $this->error_response( null, -32600, 'Invalid Request', 400 );
		}

		// Notifications and client responses get 202 Accepted with an empty
		// body and no JSON-RPC response object.
		if ( ! array_key_exists( 'id', $message ) ) {
			return new WP_REST_Response( null, 202 );
		}

		$id     = $message['id'];
		$params = isset( $message['params'] ) && is_array( $message['params'] ) ? $message['params'] : array();
		$meta   = isset( $params['_meta'] ) && is_array( $params['_meta'] ) ? $params['_meta'] : array();

		// Era selection, per request, exactly as the 2026-07-28 versioning
		// page specifies for dual-era servers: a request carrying modern
		// per-request _meta is served statelessly under the new revision;
		// anything else is served under the legacy handshake revisions. No
		// state is held about which era a client spoke last time.
		if ( array_key_exists( self::META_PROTOCOL_VERSION, $meta ) ) {
			return $this->handle_modern( $request, $id, $message['method'], $params, $meta );
		}

		switch ( $message['method'] ) {
			case 'initialize':
				return $this->result_response( $id, $this->initialize_result( $params ) );

			case 'tools/list':
				return $this->result_response( $id, array( 'tools' => $this->registry->list_tools() ) );

			case 'tools/call':
				return $this->tools_call( $id, $params );

			case 'ping':
				return $this->result_response( $id, new stdClass() );

			default:
				return $this->error_response( $id, -32601, 'Method not found' );
		}
	}

	/**
	 * Serves one request under the 2026-07-28 revision.
	 *
	 * Validation order: header agreement first (a body and header that
	 * disagree is a request-smuggling shape and is rejected outright, never
	 * resolved in favor of either), then required _meta fields, then version
	 * support, then dispatch.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @param mixed           $id      JSON-RPC id.
	 * @param string          $method  JSON-RPC method.
	 * @param array           $params  Request params.
	 * @param array           $meta    Request _meta.
	 * @return WP_REST_Response
	 */
	private function handle_modern( $request, $id, $method, $params, $meta ) {
		$version = is_scalar( $meta[ self::META_PROTOCOL_VERSION ] ) ? (string) $meta[ self::META_PROTOCOL_VERSION ] : '';

		// The MCP-Protocol-Version header MUST be present and MUST match the
		// _meta value; Mcp-Method MUST be present and match the body method;
		// Mcp-Name MUST accompany tools/call and match params.name. Missing
		// and mismatched are the same failure: HeaderMismatch, HTTP 400.
		$header_version = (string) $request->get_header( 'MCP-Protocol-Version' );
		if ( $header_version !== $version ) {
			return $this->error_response( $id, self::ERR_HEADER_MISMATCH, 'Header mismatch: MCP-Protocol-Version header does not match the _meta protocol version.', 400 );
		}

		$header_method = (string) $request->get_header( 'Mcp-Method' );
		if ( $header_method !== $method ) {
			return $this->error_response( $id, self::ERR_HEADER_MISMATCH, 'Header mismatch: Mcp-Method header does not match the request body method.', 400 );
		}

		if ( 'tools/call' === $method ) {
			$header_name = $this->decode_header_value( (string) $request->get_header( 'Mcp-Name' ) );
			$body_name   = isset( $params['name'] ) && is_scalar( $params['name'] ) ? (string) $params['name'] : '';
			if ( $header_name !== $body_name ) {
				return $this->error_response( $id, self::ERR_HEADER_MISMATCH, 'Header mismatch: Mcp-Name header does not match the request body tool name.', 400 );
			}
		}

		// clientCapabilities is required on every modern request; a request
		// without it is malformed.
		if ( ! array_key_exists( self::META_CLIENT_CAPABILITIES, $meta ) ) {
			return $this->error_response( $id, -32602, 'Invalid params: _meta is missing io.modelcontextprotocol/clientCapabilities.', 400 );
		}

		if ( ! in_array( $version, self::MODERN_VERSIONS, true ) ) {
			return $this->error_response(
				$id,
				self::ERR_UNSUPPORTED_VERSION,
				'Unsupported protocol version',
				400,
				array(
					'supported' => array_merge( self::MODERN_VERSIONS, self::PROTOCOL_VERSIONS ),
					// Echoed because the spec's own example does, so a client
					// can see what the server read — but capped and stripped:
					// it is attacker-supplied text heading into a model's
					// context, and a version string is never long.
					'requested' => $this->safe_echo( $version ),
				)
			);
		}

		switch ( $method ) {
			case 'server/discover':
				return $this->result_response( $id, $this->discover_result() );

			case 'tools/list':
				return $this->result_response(
					$id,
					$this->modern_result(
						array(
							'tools'      => $this->registry->list_tools(),
							'ttlMs'      => self::LIST_TTL_MS,
							'cacheScope' => self::LIST_CACHE_SCOPE,
						)
					)
				);

			case 'tools/call':
				$response = $this->tools_call( $id, $params );
				$body     = $response->get_data();
				if ( isset( $body['result'] ) && is_array( $body['result'] ) ) {
					$body['result'] = $this->modern_result( $body['result'] );
					$response->set_data( $body );
				}
				return $response;

			default:
				// initialize and ping land here deliberately: the handshake
				// is retired in this revision and ping was removed from it.
				// Unknown method on Streamable HTTP is 404 with -32601.
				return $this->error_response( $id, -32601, 'Method not found', 404 );
		}
	}

	/**
	 * Builds the server/discover result. Its shape is defined fresh in the
	 * 2026-07-28 revision and does not mirror the old initialize result.
	 *
	 * @return array
	 */
	private function discover_result() {
		return $this->modern_result(
			array(
				'supportedVersions' => array_merge( self::MODERN_VERSIONS, self::PROTOCOL_VERSIONS ),
				'capabilities'      => array( 'tools' => new stdClass() ),
				'instructions'      => 'Read-only MCP server for inspecting this WordPress site\'s plugin estate. Call get_capabilities first to orient yourself; it documents every tool, flag, and degradation behavior.',
				'ttlMs'             => self::LIST_TTL_MS,
				'cacheScope'        => self::LIST_CACHE_SCOPE,
			)
		);
	}

	/**
	 * Stamps the fields the 2026-07-28 revision expects on every result:
	 * resultType (required) and the server's identity in _meta (SHOULD).
	 *
	 * @param array $result Bare result payload.
	 * @return array
	 */
	private function modern_result( $result ) {
		$result['resultType'] = 'complete';

		$result_meta                           = isset( $result['_meta'] ) && is_array( $result['_meta'] ) ? $result['_meta'] : array();
		$result_meta[ self::META_SERVER_INFO ] = array(
			'name'    => 'Auditra',
			'version' => AUDITRA_VERSION,
		);
		$result['_meta']                       = $result_meta;

		return $result;
	}

	/**
	 * Decodes the Base64 sentinel format the transport defines for header
	 * values that cannot ride as plain ASCII (=?base64?...?=). Values not in
	 * sentinel form pass through untouched. The spec requires decoding before
	 * comparison against the body.
	 *
	 * @param string $value Raw header value.
	 * @return string
	 */
	private function decode_header_value( $value ) {
		if ( 0 !== strpos( $value, '=?base64?' ) || '?=' !== substr( $value, -2 ) ) {
			return $value;
		}
		// Cap before decoding, not after. The decoded value is only ever
		// compared against a tool name, so anything longer than the longest
		// name cannot match — and decoding a multi-megabyte header to learn
		// that is work an attacker gets for free.
		if ( strlen( $value ) > self::MAX_HEADER_VALUE_CHARS ) {
			return $value;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- The MCP Streamable HTTP transport defines this encoding for non-ASCII header values and requires servers to decode before comparing against the body; the result is only ever compared with a tool name, never executed or stored.
		$decoded = base64_decode( substr( $value, 9, -2 ), true );
		return false === $decoded ? $value : $decoded;
	}

	/**
	 * Builds the initialize result, negotiating the protocol version.
	 *
	 * @param array $params Client params.
	 * @return array
	 */
	private function initialize_result( $params ) {
		$requested = isset( $params['protocolVersion'] ) ? (string) $params['protocolVersion'] : '';
		$version   = in_array( $requested, self::PROTOCOL_VERSIONS, true ) ? $requested : self::PROTOCOL_VERSIONS[0];

		return array(
			'protocolVersion' => $version,
			'capabilities'    => array( 'tools' => new stdClass() ),
			'serverInfo'      => array(
				'name'    => 'Auditra',
				'version' => AUDITRA_VERSION,
			),
		);
	}

	/**
	 * Dispatches tools/call to the registry.
	 *
	 * @param mixed $id     JSON-RPC id.
	 * @param array $params Call params.
	 * @return WP_REST_Response
	 */
	private function tools_call( $id, $params ) {
		// Scalars only. A tool name arriving as an array or an object must not
		// reach a string cast, which warns on an array and throws on an
		// object — the same discipline the enrichment parser applies before
		// version_compare() (docs/DECISIONS.md 48).
		$name = isset( $params['name'] ) && is_scalar( $params['name'] ) ? (string) $params['name'] : '';

		if ( '' === $name || ! $this->registry->has( $name ) ) {
			// The name is echoed to say which tool was not found, but capped
			// and stripped of control characters: it is attacker-supplied
			// text on its way into a model's context.
			return $this->error_response( $id, -32602, 'Unknown tool: ' . $this->safe_echo( $name ) );
		}

		$arguments = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();

		return $this->result_response( $id, $this->registry->call( $name, $arguments ) );
	}

	/**
	 * Wraps a result in a JSON-RPC envelope.
	 *
	 * @param mixed $id     JSON-RPC id.
	 * @param mixed $result Result value.
	 * @return WP_REST_Response
	 */
	private function result_response( $id, $result ) {
		return new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => $result,
			),
			200
		);
	}

	/**
	 * Wraps an error in a JSON-RPC envelope.
	 *
	 * @param mixed      $id      JSON-RPC id, null when unknowable.
	 * @param int        $code    JSON-RPC error code.
	 * @param string     $message Error message.
	 * @param int        $status  HTTP status, 200 for well-formed requests.
	 * @param array|null $data    Optional error data member.
	 * @return WP_REST_Response
	 */
	private function error_response( $id, $code, $message, $status = 200, $data = null ) {
		$error = array(
			'code'    => $code,
			'message' => $message,
		);
		if ( null !== $data ) {
			$error['data'] = $data;
		}
		return new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'error'   => $error,
			),
			$status
		);
	}

	/**
	 * Discards stray buffered output before WordPress writes the response,
	 * and serves 202 notifications with a genuinely empty body.
	 *
	 * Runs on rest_pre_serve_request for every REST request; acts only on ours.
	 *
	 * @param bool             $served  Whether the request has already been served.
	 * @param WP_REST_Response $result  Result to send.
	 * @param WP_REST_Request  $request Request used to generate the response.
	 * @param WP_REST_Server   $server  Server instance.
	 * @return bool
	 */
	public function serve_empty_accepted_response( $served, $result, $request, $server ) {
		unset( $server );

		// Close our own buffer first, whatever route ended up serving this
		// request. It was opened from the request URI, before routing, so a
		// request that never reaches our callback — a token that fails the
		// route pattern, say — still has a buffer of ours waiting to be
		// closed. Returning early without closing it would leave it open for
		// the rest of the request.
		$this->discard_buffer();

		if ( 0 !== strpos( $request->get_route(), '/' . self::REST_NAMESPACE . '/mcp/' ) ) {
			return $served;
		}

		if ( 202 === $result->get_status() ) {
			return true;
		}

		return $served;
	}

	/**
	 * Closes the output buffer opened in register_routes(), discarding
	 * whatever stray output landed in it.
	 *
	 * Closes only this class's own buffer and anything nested inside it,
	 * never a buffer that was already open when we started. WordPress is a
	 * shared environment: core, themes, and other plugins open buffers of
	 * their own, and tearing down the whole stack (`while ob_get_level() > 0`)
	 * closes buffers belonging to code that is still expecting to close them
	 * itself. Idempotent, so the shutdown fallback is a no-op once the normal
	 * path has run.
	 *
	 * @return void
	 */
	public function discard_buffer() {
		if ( null === $this->buffer_level ) {
			return;
		}
		while ( ob_get_level() >= $this->buffer_level ) {
			if ( ! @ob_end_clean() ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A buffer another component marked unremovable must end the loop, not warn.
				break;
			}
		}
		$this->buffer_level = null;
	}

	/**
	 * Caps and de-fangs an attacker-supplied value before it appears in an
	 * error message. Control characters go, including the newlines that would
	 * let a value forge extra lines in a log, and the result is capped.
	 *
	 * @param string $value Attacker-supplied value.
	 * @return string
	 */
	private function safe_echo( $value ) {
		$value = preg_replace( '/[^\P{C}]+/u', '', $value );
		if ( null === $value ) {
			return ''; // Invalid UTF-8 made the match fail; echo nothing.
		}
		return strlen( $value ) > self::MAX_ECHO_CHARS ? substr( $value, 0, self::MAX_ECHO_CHARS ) . '…' : $value;
	}

	/**
	 * Whether the request's Origin, if any, is allowed.
	 *
	 * Absent is allowed: MCP clients call server-to-server and send no
	 * Origin; the header exists to let browsers announce cross-site requests,
	 * and it is exactly those — including the "null" opaque origin — that a
	 * DNS-rebinding attack would arrive under. Present is allowed only for
	 * this site's own origin, since no browser page on another host has any
	 * business POSTing here.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return bool
	 */
	private function origin_allowed( $request ) {
		$origin = trim( (string) $request->get_header( 'Origin' ) );
		if ( '' === $origin ) {
			return true;
		}

		// Bound the work before parsing. A serialized origin is a scheme, a
		// host, and maybe a port; nothing legitimate approaches this, and
		// parsing an unbounded header on an unauthenticated request is free
		// work for an attacker.
		if ( strlen( $origin ) > self::MAX_ORIGIN_CHARS ) {
			return false;
		}

		$candidate = $this->parse_origin( $origin );
		if ( null === $candidate ) {
			return false; // Not a serialized origin at all, including "null".
		}

		/**
		 * Filters the host:port authorities allowed to send browser-borne
		 * requests to the MCP endpoint. Defaults to this site's own, derived
		 * from home_url() and site_url().
		 *
		 * Values are compared as authorities, not URLs: "example.com:443".
		 * Anything that does not parse is ignored rather than treated as a
		 * wildcard.
		 *
		 * @param string[] $allowed Allowed host:port authorities.
		 */
		$allowed = apply_filters( 'auditra_allowed_origins', $this->site_authorities() );

		foreach ( (array) $allowed as $entry ) {
			$entry = strtolower( trim( (string) $entry ) );
			// A filtered value given as a full URL is accepted too, but only
			// when it parses; an unparseable entry must never become a
			// wildcard that matches every unparseable Origin.
			if ( false !== strpos( $entry, '://' ) ) {
				$parsed = $this->parse_origin( $entry );
				$entry  = null === $parsed ? '' : $parsed;
			}
			if ( '' !== $entry && $entry === $candidate ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Parses a serialized origin to its lowercase host:port authority.
	 *
	 * Strict by design. The Origin header is defined as a scheme, a host, and
	 * an optional port — nothing else. A value carrying userinfo, a path, a
	 * query, or a fragment is not something a browser produces, and parsing
	 * it leniently is how "https://evil.com@example.com" ends up reading as
	 * example.com. Anything that is not exactly a serialized origin is
	 * rejected rather than interpreted.
	 *
	 * The scheme must be http or https but is not part of the returned
	 * authority: the host is the boundary a DNS-rebinding attack has to
	 * cross, and sites behind a TLS-terminating proxy routinely record
	 * home_url() with a scheme that does not match how browsers reach them.
	 *
	 * @param string $value Candidate origin.
	 * @return string|null Lowercase host:port, or null when not an origin.
	 */
	private function parse_origin( $value ) {
		$value = strtolower( $value );

		if ( 1 !== preg_match( '#^(https?)://([a-z0-9.\-]+|\[[0-9a-f:]+\])(?::([0-9]{1,5}))?$#', $value, $m ) ) {
			return null;
		}

		$host = $m[2];
		// A trailing dot is the DNS-absolute form of the same name, but
		// browsers treat it as a distinct origin and never send it. Rejecting
		// is both the safe direction and the accurate one.
		if ( '.' === substr( $host, -1 ) ) {
			return null;
		}

		if ( isset( $m[3] ) && '' !== $m[3] ) {
			$port = (int) $m[3];
			if ( $port < 1 || $port > 65535 ) {
				return null;
			}
		} else {
			$port = 'https' === $m[1] ? 443 : 80;
		}

		return $host . ':' . $port;
	}

	/**
	 * This site's own authorities, from home_url() and site_url().
	 *
	 * A URL without an explicit port contributes both default ports: a site
	 * recorded as http:// but served over https — a TLS-terminating proxy,
	 * or a configuration nobody got around to updating — must still accept
	 * its own browser origin.
	 *
	 * @return string[]
	 */
	private function site_authorities() {
		$authorities = array();
		foreach ( array( home_url(), site_url() ) as $url ) {
			$parts = wp_parse_url( strtolower( (string) $url ) );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
				continue;
			}
			if ( isset( $parts['port'] ) ) {
				$authorities[] = $parts['host'] . ':' . (int) $parts['port'];
				continue;
			}
			$authorities[] = $parts['host'] . ':80';
			$authorities[] = $parts['host'] . ':443';
		}
		return array_values( array_unique( $authorities ) );
	}

	/**
	 * Whether the current request targets the MCP endpoint.
	 *
	 * @return bool
	 */
	private function is_mcp_request() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		return false !== strpos( $uri, self::REST_NAMESPACE . '/mcp/' );
	}
}
