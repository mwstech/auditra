<?php
/**
 * Enrichment manager: shared HTTP, caching, and failure accounting for every
 * enrichment client.
 *
 * The contract (SPEC section 8): every client returns data or null, never
 * throws, always caches, and every failure is recorded so tools can report
 * _meta.sources honestly. Enrichment must never block or break a response.
 *
 * Two facilities exist for reporting degradation truthfully:
 *
 * - Per-source status with a reason code (docs/DECISIONS.md 52). "Something
 *   failed" is not actionable; "the site has no outbound HTTP access" and "the
 *   upstream returned an error" call for completely different responses.
 * - Stale-while-unavailable (docs/DECISIONS.md 53). A failed lookup no longer
 *   destroys the last good payload, so a multi-day outage degrades to
 *   explicitly labeled old data rather than to nothing.
 *
 * @package Auditra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orchestrates enrichment clients. One instance per request, shared across
 * clients so unavailability is reported once per source.
 */
class Auditra_Enrichment_Manager {

	/**
	 * A slow third-party API must never make the MCP endpoint appear hung,
	 * but a populated response is legitimately slower than an empty one.
	 * Total timeout is filterable via auditra_http_timeout.
	 */
	const CONNECT_TIMEOUT = 3;
	const TOTAL_TIMEOUT   = 10;

	/**
	 * Parallel fetches run in batches of this size. 45 simultaneous
	 * connections to a volunteer-run API is abuse, not parallelism.
	 */
	const MAX_CONCURRENCY = 8;

	/**
	 * Largest enrichment response accepted from any upstream, in bytes.
	 * Real payloads are kilobytes; anything past this is treated as an
	 * outage rather than parsed.
	 */
	const MAX_RESPONSE_BYTES = 2097152;

	/**
	 * Persistent store: a single non-autoloaded option holding a keyed map of
	 * entry => {data, fetched_at}. See docs/DECISIONS.md 22.
	 */
	const STORE_OPTION = 'auditra_enrich_store';

	/**
	 * Store key prefix for the "this source last answered at" markers.
	 */
	const LAST_SUCCESS_PREFIX = 'last_success_';

	/**
	 * Store entries untouched for this long are dropped at flush. Must be at
	 * least the longest MAX_STALE any client serves, or pruning would delete
	 * data the stale fallback was about to use.
	 */
	const PRUNE_AFTER = MONTH_IN_SECONDS;

	/**
	 * Failed lookups negative-cache with escalating backoff on consecutive
	 * failures. At wordpress.org scale, thousands of installs retrying a
	 * broken free service every 15 minutes forever is how a plugin gets
	 * blocked at the network level (docs/DECISIONS.md 34). Reset on success.
	 *
	 * @var int[]
	 */
	const FAILURE_BACKOFF = array( 900, 3600, 21600, 86400 );

	/**
	 * Reason codes reported per source. Facts about what happened, with no
	 * advice and no severity attached.
	 */
	const REASON_NO_OUTBOUND    = 'no_outbound_http';
	const REASON_UPSTREAM_ERROR = 'upstream_error';
	const REASON_TIMEOUT        = 'timeout';
	const REASON_NETWORK_ERROR  = 'network_error';
	const REASON_BACKOFF        = 'backoff_active';
	const REASON_UNPARSEABLE    = 'unparseable_response';
	const REASON_OVERSIZED      = 'oversized_response';

	/**
	 * Source statuses, worst-wins, keyed by source name. Each value is
	 * {status, reason, last_success, next_retry, stale_age}.
	 *
	 * @var array<string, array>
	 */
	private $statuses = array();

	/**
	 * Status ranking. A source that answered some slugs from stale data and
	 * failed on others reports the worse of the two.
	 *
	 * @var array<string, int>
	 */
	private static $status_rank = array(
		'ok'          => 0,
		'stale'       => 1,
		'unavailable' => 2,
	);

	/**
	 * Transport-level failure reasons from the most recent fetch, keyed like
	 * the URL map that produced them.
	 *
	 * @var array<string, string>
	 */
	private $transport_errors = array();

	/**
	 * In-memory copy of the persistent store for this request.
	 *
	 * @var array|null
	 */
	private $store = null;

	/**
	 * Whether the store has unwritten changes.
	 *
	 * @var bool
	 */
	private $store_dirty = false;

	/**
	 * Records a source as unavailable for this request, with the reason.
	 *
	 * @param string      $name       Source name.
	 * @param string      $reason     One of the REASON_* codes.
	 * @param int|null    $next_retry Unix time a retry becomes possible, when a
	 *                                backoff window is open.
	 * @return void
	 */
	public function record_unavailable( $name, $reason = self::REASON_NETWORK_ERROR, $next_retry = null ) {
		$this->record_status(
			$name,
			'unavailable',
			array(
				'reason'     => $reason,
				'next_retry' => $next_retry,
			)
		);
	}

	/**
	 * Records that a source answered, but from cached data past its TTL.
	 *
	 * @param string $name       Source name.
	 * @param int    $fetched_at Unix time the stale data was fetched.
	 * @return void
	 */
	public function record_stale( $name, $fetched_at ) {
		$this->record_status(
			$name,
			'stale',
			array(
				'reason'     => 'serving_cached_data',
				'fetched_at' => (int) $fetched_at,
			)
		);
	}

	/**
	 * Records that a source answered normally.
	 *
	 * @param string $name Source name.
	 * @return void
	 */
	public function record_ok( $name ) {
		$this->record_status( $name, 'ok', array() );
	}

	/**
	 * Merges one observation into a source's status, worst status winning and
	 * the oldest stale timestamp winning within a status.
	 *
	 * @param string $name   Source name.
	 * @param string $status ok, stale, or unavailable.
	 * @param array  $detail Extra fields.
	 * @return void
	 */
	private function record_status( $name, $status, $detail ) {
		if ( ! isset( $this->statuses[ $name ] ) ) {
			$this->statuses[ $name ] = array( 'status' => 'ok' );
		}
		$current = $this->statuses[ $name ];
		$replace = self::$status_rank[ $status ] > self::$status_rank[ $current['status'] ];

		if ( $replace ) {
			$this->statuses[ $name ] = array_merge( array( 'status' => $status ), $detail );
			return;
		}
		if ( $status !== $current['status'] ) {
			return;
		}
		// Same status seen again: keep the oldest data and the earliest retry,
		// so the reported figures are the least flattering true ones.
		if ( isset( $detail['fetched_at'], $current['fetched_at'] ) && $detail['fetched_at'] < $current['fetched_at'] ) {
			$this->statuses[ $name ]['fetched_at'] = $detail['fetched_at'];
		}
		if ( isset( $detail['next_retry'] ) && ( ! isset( $current['next_retry'] ) || null === $current['next_retry'] ) ) {
			$this->statuses[ $name ]['next_retry'] = $detail['next_retry'];
		}
	}

	/**
	 * Per-source status objects for _meta.sources. Facts only: what the status
	 * is, why, when the source last answered successfully, and when a retry
	 * becomes possible.
	 *
	 * @return array<string, array>
	 */
	public function source_status() {
		$out = array();
		foreach ( $this->statuses as $name => $status ) {
			$row = array( 'status' => $status['status'] );
			if ( isset( $status['reason'] ) && 'ok' !== $status['status'] ) {
				$row['reason'] = $status['reason'];
			}
			$last_success = $this->last_success( $name );
			if ( null !== $last_success ) {
				$row['last_success'] = gmdate( 'Y-m-d\TH:i:s\Z', $last_success );
			}
			if ( 'stale' === $status['status'] && isset( $status['fetched_at'] ) ) {
				$row['data_fetched_at'] = gmdate( 'Y-m-d\TH:i:s\Z', $status['fetched_at'] );
				$row['data_age_hours']  = round( ( time() - $status['fetched_at'] ) / HOUR_IN_SECONDS, 1 );
			}
			if ( isset( $status['next_retry'] ) && null !== $status['next_retry'] ) {
				$row['next_retry'] = gmdate( 'Y-m-d\TH:i:s\Z', (int) $status['next_retry'] );
			}
			$out[ $name ] = $row;
		}
		return $out;
	}

	/**
	 * Whether any source is degraded this request.
	 *
	 * @return bool
	 */
	public function has_degraded_source() {
		foreach ( $this->statuses as $status ) {
			if ( 'ok' !== $status['status'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether outbound HTTP to a host is blocked by site configuration.
	 * Honoring WP_HTTP_BLOCK_EXTERNAL keeps us a good citizen on locked-down
	 * hosts and gives tests a clean way to simulate a firewalled site.
	 *
	 * @param string $host Host name, e.g. 'api.wordpress.org'.
	 * @return bool
	 */
	public function is_blocked( $host ) {
		if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) || ! WP_HTTP_BLOCK_EXTERNAL ) {
			return false;
		}
		if ( defined( 'WP_ACCESSIBLE_HOSTS' ) && WP_ACCESSIBLE_HOSTS ) {
			$allowed = array_map( 'trim', explode( ',', WP_ACCESSIBLE_HOSTS ) );
			return ! in_array( $host, $allowed, true );
		}
		return true;
	}

	/**
	 * Fetches several URLs in parallel with WordPress's bundled Requests
	 * library. Returns response bodies keyed like the input; a failed request
	 * (transport error, non-2xx status, empty body) yields null for its key.
	 * Never throws.
	 *
	 * @param array<string, string> $urls Map of key => URL.
	 * @return array<string, ?string> Map of key => body or null.
	 */
	public function fetch_multiple( $urls ) {
		$bodies = array();
		foreach ( $this->fetch_multiple_raw( $urls ) as $key => $response ) {
			$ok             = null !== $response && $response['status'] >= 200 && $response['status'] < 300 && '' !== $response['body'];
			$bodies[ $key ] = $ok ? $response['body'] : null;
		}
		return $bodies;
	}

	/**
	 * Like fetch_multiple, but preserves the HTTP status so callers can treat
	 * meaningful non-2xx answers (wordpress.org replies 404 for both closed
	 * and unknown plugins) as answers rather than failures. Null means the
	 * request itself failed at the transport level.
	 *
	 * @param array<string, string> $urls Map of key => URL.
	 * @return array<string, ?array{status: int, body: string}>
	 */
	public function fetch_multiple_raw( $urls ) {
		$bodies                 = array_fill_keys( array_keys( $urls ), null );
		$this->transport_errors = array();
		if ( array() === $urls ) {
			return $bodies;
		}

		$requests = array();
		foreach ( $urls as $key => $url ) {
			$requests[ $key ] = array(
				'url'     => $url,
				'type'    => 'GET',
				'headers' => array(
					'Accept'     => 'application/json',
					'User-Agent' => 'Auditra/' . AUDITRA_VERSION . ' (WordPress plugin; https://www.macronimous.com/free-tools/auditra/)',
				),
			);
		}

		/**
		 * Filters the total HTTP timeout for enrichment requests, in seconds.
		 *
		 * The default errs generous: populated vulnerability responses do more
		 * backend work than empty ones, and silent degradation already
		 * protects the endpoint from hanging.
		 *
		 * @param int $timeout Default 10.
		 */
		$total_timeout = max( 1, (int) apply_filters( 'auditra_http_timeout', self::TOTAL_TIMEOUT ) );

		$options = array(
			'timeout'          => $total_timeout,
			'connect_timeout'  => self::CONNECT_TIMEOUT,
			// Requests is used directly for parallel fetching, which means
			// wp_safe_remote_get()'s SSRF guards do not apply. The three
			// hosts are hardcoded literals and none of them redirect, so
			// refusing to follow redirects costs nothing and removes the only
			// path by which a compromised upstream could point this plugin at
			// an internal address.
			'follow_redirects' => false,
			'verify'           => true,
		);

		// Batched, not all at once: politeness toward volunteer-run APIs.
		foreach ( array_chunk( $requests, self::MAX_CONCURRENCY, true ) as $batch ) {
			try {
				if ( class_exists( '\WpOrg\Requests\Requests' ) ) {
					$responses = \WpOrg\Requests\Requests::request_multiple( $batch, $options );
				} else {
					// WordPress < 6.2 ships the legacy class name.
					$responses = \Requests::request_multiple( $batch, $options ); // phpcs:ignore PHPCompatibility -- legacy fallback.
				}
			} catch ( \Throwable $e ) {
				foreach ( array_keys( $batch ) as $key ) {
					$this->transport_errors[ $key ] = $this->classify_throwable( $e );
				}
				continue;
			}

			foreach ( $responses as $key => $response ) {
				if ( ! is_object( $response ) || ! isset( $response->status_code, $response->body ) ) {
					// Exceptions come back as objects without a status. Which
					// kind matters: a timeout may be transient, a refused
					// connection usually is not.
					$this->transport_errors[ $key ] = $response instanceof \Throwable
						? $this->classify_throwable( $response )
						: self::REASON_NETWORK_ERROR;
					continue;
				}
				$body = (string) $response->body;
				// A compromised or spoofed upstream must not be able to
				// exhaust memory with an enormous body. Oversized responses
				// are discarded, which degrades exactly like an outage.
				if ( strlen( $body ) > self::MAX_RESPONSE_BYTES ) {
					$this->transport_errors[ $key ] = self::REASON_OVERSIZED;
					continue;
				}
				$bodies[ $key ] = array(
					'status' => (int) $response->status_code,
					'body'   => $body,
				);
			}
		}

		return $bodies;
	}

	/**
	 * Why one key from the most recent fetch failed at the transport level.
	 *
	 * @param string $key      Key from the URL map.
	 * @param int    $status   HTTP status seen, 0 when the request never got one.
	 * @return string One of the REASON_* codes.
	 */
	public function failure_reason( $key, $status = 0 ) {
		if ( isset( $this->transport_errors[ $key ] ) ) {
			return $this->transport_errors[ $key ];
		}
		if ( $status > 0 ) {
			return self::REASON_UPSTREAM_ERROR;
		}
		return self::REASON_NETWORK_ERROR;
	}

	/**
	 * Separates a timeout from every other transport failure. cURL reports a
	 * timeout as error 28 and Requests passes the text through.
	 *
	 * @param \Throwable $e Exception from the HTTP layer.
	 * @return string
	 */
	private function classify_throwable( $e ) {
		$message = $e->getMessage();
		if ( preg_match( '/timed?\s?out|timeout|cURL error 28/i', $message ) ) {
			return self::REASON_TIMEOUT;
		}
		return self::REASON_NETWORK_ERROR;
	}

	/**
	 * Looks an entry up in the persistent store and says what the caller
	 * should do with it. Unlike transients, the store is a plain
	 * non-autoloaded option: it always hits the database, so persistence does
	 * not depend on the site's object cache. Expiry is checked here in PHP.
	 *
	 * The returned state is one of:
	 *
	 * - `fresh`  data inside its TTL. Use it, do not fetch.
	 * - `stale`  data past its TTL that cannot be refreshed right now because
	 *            a backoff window is open, and still inside max_stale. Use it,
	 *            label it.
	 * - `blocked` no usable data and a backoff window is open. Do not fetch.
	 * - `retry`  fetch now. Any `data` returned alongside is the last good
	 *            payload, for the caller to fall back to if the fetch fails.
	 *
	 * @param string $key       Entry key.
	 * @param int    $max_age   Maximum age in seconds for data to count as fresh.
	 * @param int    $max_stale Maximum age in seconds for data to be servable
	 *                          at all once the upstream is unreachable.
	 * @return array{state: string, data: mixed, fetched_at: int, next_retry: ?int}
	 */
	public function store_lookup( $key, $max_age, $max_stale ) {
		$store = $this->load_store();
		$entry = isset( $store[ $key ] ) && is_array( $store[ $key ] ) ? $store[ $key ] : null;

		$result = array(
			'state'      => 'retry',
			'data'       => null,
			'fetched_at' => 0,
			'next_retry' => null,
		);
		if ( null === $entry ) {
			return $result;
		}

		$has_data   = array_key_exists( 'data', $entry ) && null !== $entry['data'];
		$fetched_at = isset( $entry['fetched_at'] ) ? (int) $entry['fetched_at'] : 0;
		$age        = time() - $fetched_at;

		$result['data']       = $has_data ? $entry['data'] : null;
		$result['fetched_at'] = $fetched_at;

		if ( $has_data && $age < $max_age ) {
			$result['state'] = 'fresh';
			return $result;
		}

		// A failure streak holds off the next attempt. Entries written before
		// this phase recorded a failure by nulling data and stamping
		// fetched_at, so fall back to fetched_at when failed_at is absent.
		$failures = isset( $entry['failures'] ) ? (int) $entry['failures'] : 0;
		if ( $failures > 0 ) {
			$failed_at  = isset( $entry['failed_at'] ) ? (int) $entry['failed_at'] : $fetched_at;
			$backoff    = self::FAILURE_BACKOFF[ min( $failures, count( self::FAILURE_BACKOFF ) ) - 1 ];
			$next_retry = $failed_at + $backoff;
			if ( time() < $next_retry ) {
				$result['next_retry'] = $next_retry;
				$result['state']      = ( $has_data && $age < $max_stale ) ? 'stale' : 'blocked';
				return $result;
			}
		}

		return $result;
	}

	/**
	 * Queues a successful lookup for the persistent store. Call store_flush()
	 * once per batch; one option write per request, not one per plugin.
	 *
	 * @param string $key  Entry key.
	 * @param mixed  $data Data to store.
	 * @return void
	 */
	public function store_put( $key, $data ) {
		$store             = $this->load_store();
		$store[ $key ]     = array(
			'data'       => $data,
			'fetched_at' => time(),
		);
		$this->store       = $store;
		$this->store_dirty = true;
	}

	/**
	 * Records a failed lookup. The last good payload and its timestamp are
	 * kept: six-day-old vulnerability data is worth far more than nothing, and
	 * discarding it here would make stale-while-unavailable impossible. Only
	 * the failure counters change, and they drive the escalating backoff.
	 *
	 * @param string $key Entry key.
	 * @return void
	 */
	public function store_put_failure( $key ) {
		$store = $this->load_store();
		$entry = isset( $store[ $key ] ) && is_array( $store[ $key ] ) ? $store[ $key ] : array();

		$entry['data']       = array_key_exists( 'data', $entry ) ? $entry['data'] : null;
		$entry['fetched_at'] = isset( $entry['fetched_at'] ) ? (int) $entry['fetched_at'] : 0;
		$entry['failures']   = ( isset( $entry['failures'] ) ? (int) $entry['failures'] : 0 ) + 1;
		$entry['failed_at']  = time();

		$store[ $key ]     = $entry;
		$this->store       = $store;
		$this->store_dirty = true;
	}

	/**
	 * Notes the moment a source last answered successfully, so every tool can
	 * report it during a later outage.
	 *
	 * @param string $source Source name.
	 * @return void
	 */
	public function record_last_success( $source ) {
		$store                                        = $this->load_store();
		$store[ self::LAST_SUCCESS_PREFIX . $source ] = array(
			'data'       => time(),
			'fetched_at' => time(),
		);
		$this->store                                  = $store;
		$this->store_dirty                            = true;
	}

	/**
	 * When a source last answered successfully, or null if never.
	 *
	 * @param string $source Source name.
	 * @return int|null
	 */
	public function last_success( $source ) {
		$store = $this->load_store();
		$key   = self::LAST_SUCCESS_PREFIX . $source;
		if ( ! isset( $store[ $key ]['data'] ) || ! is_int( $store[ $key ]['data'] ) ) {
			return null;
		}
		return $store[ $key ]['data'];
	}

	/**
	 * Writes queued store entries in a single option update, pruning entries
	 * old enough to be useless to anyone. The window matches the longest
	 * max-staleness any client will serve, so pruning can never delete data
	 * that stale-while-unavailable would still have used.
	 *
	 * @return void
	 */
	public function store_flush() {
		if ( ! $this->store_dirty || null === $this->store ) {
			return;
		}
		$cutoff = time() - self::PRUNE_AFTER;
		foreach ( $this->store as $key => $entry ) {
			if ( ! is_array( $entry ) ) {
				unset( $this->store[ $key ] );
				continue;
			}
			// Last-success markers are the only record of when a source last
			// answered; a long outage must not erase the answer to "when did
			// this last work?".
			if ( 0 === strpos( $key, self::LAST_SUCCESS_PREFIX ) ) {
				continue;
			}
			// Touched, not fetched: an entry failing every day is current
			// bookkeeping even though its payload is ancient, and pruning it
			// would reset the backoff and start the hammering again.
			$touched = max(
				isset( $entry['fetched_at'] ) ? (int) $entry['fetched_at'] : 0,
				isset( $entry['failed_at'] ) ? (int) $entry['failed_at'] : 0
			);
			if ( $touched < $cutoff ) {
				unset( $this->store[ $key ] );
			}
		}
		if ( false === get_option( self::STORE_OPTION, false ) ) {
			add_option( self::STORE_OPTION, $this->store, '', false );
		} else {
			update_option( self::STORE_OPTION, $this->store, false );
		}
		$this->store_dirty = false;
	}

	/**
	 * Loads the persistent store once per request.
	 *
	 * @return array
	 */
	private function load_store() {
		if ( null === $this->store ) {
			$stored      = get_option( self::STORE_OPTION, array() );
			$this->store = is_array( $stored ) ? $stored : array();
		}
		return $this->store;
	}
}
