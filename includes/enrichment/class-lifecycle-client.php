<?php
/**
 * Support lifecycle facts for PHP, the database engine, and WordPress.
 *
 * PHP, MySQL and MariaDB come from a bundled table compiled from each vendor's
 * own published policy; no network call is involved and nothing here can be
 * unavailable. WordPress comes from api.wordpress.org, which publishes the
 * authoritative security status of every release — WordPress itself publishes
 * no per-branch end-of-life dates, so none are invented (docs/DECISIONS.md 67).
 *
 * A version whose cycle is absent from the table is reported with no support
 * claim attached. Silence, never a guess.
 *
 * @package Auditra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Answers "how supported is this version?" without depending on a third-party
 * aggregator.
 */
class Auditra_Lifecycle_Client implements Auditra_Enrichment_Client_Interface {

	const HOST      = 'api.wordpress.org';
	const CACHE_TTL = DAY_IN_SECONDS;

	/**
	 * How old a cached WordPress release list may be and still be served once
	 * the API is unreachable. Thirty days: release history only ever gains
	 * rows, and a month-old copy answers "is this version current?" the same
	 * way for every version that existed when it was fetched.
	 */
	const MAX_STALE = MONTH_IN_SECONDS;

	/**
	 * Products answered from the bundled table rather than the network.
	 *
	 * @var string[]
	 */
	const BUNDLED = array( 'php', 'mysql', 'mariadb' );

	/**
	 * Product key for WordPress, lowercased to match the normalized input.
	 *
	 * Declared once, here, rather than written inline at each comparison:
	 * `phpcbf` applies the CapitalPDangit sniff to a bare lowercase literal and
	 * silently rewrites it to the capitalised spelling, which can never equal a
	 * strtolower()'d value. That rewrite has broken this comparison once
	 * already. Keep the ignore, and compare against this constant.
	 */
	// phpcs:ignore WordPress.WP.CapitalPDangit.Misspelled -- Deliberately lowercase: this is a normalized array key, not prose.
	const PRODUCT_WORDPRESS = 'wordpress';

	/**
	 * Shared manager.
	 *
	 * @var Auditra_Enrichment_Manager
	 */
	private $manager;

	/**
	 * Parsed lifecycle table, loaded once per request.
	 *
	 * @var array|null
	 */
	private static $table = null;

	/**
	 * Constructor.
	 *
	 * @param Auditra_Enrichment_Manager $manager Shared manager.
	 */
	public function __construct( Auditra_Enrichment_Manager $manager ) {
		$this->manager = $manager;
	}

	/**
	 * Source name for _meta.sources. Only the WordPress half touches the
	 * network, and it does so through the wordpress.org API, so that is the
	 * source a client sees reported.
	 *
	 * @return string
	 */
	public function name() {
		return 'wporg';
	}

	/**
	 * Support status for several product/version pairs at once.
	 *
	 * @param array<string, string> $products Map of product => running version,
	 *                                        e.g. array( 'php' => '8.2.29' ).
	 * @return array<string, ?array> Map of product => status array or null.
	 */
	public function support_statuses( $products ) {
		$statuses = array_fill_keys( array_keys( $products ), null );

		foreach ( $products as $product => $version ) {
			$product = strtolower( (string) $product );
			if ( in_array( $product, self::BUNDLED, true ) ) {
				$statuses[ $product ] = $this->bundled_status( $product, (string) $version );
				continue;
			}
			if ( self::PRODUCT_WORDPRESS === $product ) {
				$statuses[ $product ] = $this->wordpress_status( (string) $version );
			}
		}

		return $statuses;
	}

	/**
	 * Matches a version against the bundled table.
	 *
	 * @param string $product Product key.
	 * @param string $version Running version.
	 * @return array|null Status row, or null when the cycle is not in the table.
	 */
	private function bundled_status( $product, $version ) {
		$cycles = $this->bundled_cycles( $product );
		if ( array() === $cycles ) {
			return null;
		}

		$best = null;
		foreach ( $cycles as $cycle ) {
			if ( $cycle['cycle'] === $version || 0 === strpos( $version . '.', $cycle['cycle'] . '.' ) ) {
				// Longest match wins: 10.11 must beat 10.1 for version 10.11.2.
				if ( null === $best || strlen( $cycle['cycle'] ) > strlen( $best['cycle'] ) ) {
					$best = $cycle;
				}
			}
		}
		if ( null === $best ) {
			return null; // Unknown cycle: report the version, claim nothing.
		}

		$today  = gmdate( 'Y-m-d' );
		$status = array(
			'product'    => $product,
			'version'    => $version,
			'cycle'      => $best['cycle'],
			'eol_date'   => isset( $best['eol'] ) ? $best['eol'] : null,
			'eol_passed' => isset( $best['eol'] ) ? ( $best['eol'] < $today ) : null,
			'source'     => 'vendor_published_schedule',
		);
		if ( isset( $best['active_until'] ) ) {
			$status['active_support_ended'] = $best['active_until'] < $today;
		}
		if ( ! empty( $best['lts'] ) ) {
			$status['lts'] = true;
		}

		return array_filter(
			$status,
			function ( $value ) {
				return null !== $value;
			}
		);
	}

	/**
	 * WordPress support status, from the release list wordpress.org publishes.
	 *
	 * Reports the status WordPress itself assigns — insecure, outdated, or
	 * latest — rather than an end-of-life date. WordPress publishes no
	 * per-branch EOL schedule and backports security fixes across many
	 * branches, so an EOL date for a WordPress branch would be somebody's
	 * inference, not a published fact.
	 *
	 * @param string $version Running WordPress version.
	 * @return array|null
	 */
	private function wordpress_status( $version ) {
		$releases = $this->wordpress_releases();
		if ( null === $releases || ! isset( $releases[ $version ] ) ) {
			return null;
		}

		$status = array(
			'product'         => self::PRODUCT_WORDPRESS,
			'version'         => $version,
			'cycle'           => $this->cycle_of( $version ),
			'security_status' => (string) $releases[ $version ],
			'source'          => 'wordpress_org_release_list',
		);

		foreach ( $releases as $release => $state ) {
			if ( 'latest' === $state ) {
				$status['latest_version'] = (string) $release;
				break;
			}
		}

		return $status;
	}

	/**
	 * The ordered cycle list for one product, newest first. Used by
	 * list_plugins to place a tested-up-to value relative to the current
	 * release.
	 *
	 * @param string $product Product name, case-insensitive.
	 * @return array[]|null Cycle rows, or null when unavailable.
	 */
	public function cycles( $product ) {
		$product = strtolower( (string) $product );

		if ( in_array( $product, self::BUNDLED, true ) ) {
			$cycles = $this->bundled_cycles( $product );
			return array() === $cycles ? null : $cycles;
		}

		if ( self::PRODUCT_WORDPRESS !== $product ) {
			return null;
		}

		$releases = $this->wordpress_releases();
		if ( null === $releases ) {
			return null;
		}

		// Collapse the version list to its major.minor cycles, newest first.
		$seen = array();
		foreach ( array_keys( $releases ) as $version ) {
			$cycle = $this->cycle_of( (string) $version );
			if ( '' !== $cycle ) {
				$seen[ $cycle ] = true;
			}
		}
		$cycles = array_keys( $seen );
		usort(
			$cycles,
			function ( $a, $b ) {
				return version_compare( $b, $a );
			}
		);

		$rows = array();
		foreach ( $cycles as $cycle ) {
			$rows[] = array( 'cycle' => $cycle );
		}
		return array() === $rows ? null : $rows;
	}

	/**
	 * The wordpress.org release list, keyed by version, from cache or network.
	 *
	 * @return array<string, string>|null
	 */
	private function wordpress_releases() {
		$lookup = $this->manager->store_lookup( 'wp_releases', self::CACHE_TTL, self::MAX_STALE );

		if ( 'fresh' === $lookup['state'] ) {
			$this->manager->record_ok( $this->name() );
			return $lookup['data'];
		}
		if ( 'stale' === $lookup['state'] ) {
			$this->manager->record_stale( $this->name(), $lookup['fetched_at'] );
			return $lookup['data'];
		}
		if ( 'blocked' === $lookup['state'] ) {
			$this->manager->record_unavailable( $this->name(), Auditra_Enrichment_Manager::REASON_BACKOFF, $lookup['next_retry'] );
			return null;
		}
		if ( $this->manager->is_blocked( self::HOST ) ) {
			return $this->degrade( $lookup, Auditra_Enrichment_Manager::REASON_NO_OUTBOUND );
		}

		$bodies   = $this->manager->fetch_multiple( array( 'releases' => 'https://api.wordpress.org/core/stable-check/1.0/' ) );
		$releases = $this->parse_releases( $bodies['releases'] );

		if ( null === $releases ) {
			$this->manager->store_put_failure( 'wp_releases' );
			$fallback = $this->degrade( $lookup, $this->manager->failure_reason( 'releases' ) );
			$this->manager->store_flush();
			return $fallback;
		}

		$this->manager->store_put( 'wp_releases', $releases );
		$this->manager->record_ok( $this->name() );
		$this->manager->record_last_success( $this->name() );
		$this->manager->store_flush();
		return $releases;
	}

	/**
	 * Parses the stable-check payload: a flat map of version => status.
	 *
	 * @param string|null $body Response body.
	 * @return array<string, string>|null
	 */
	private function parse_releases( $body ) {
		if ( null === $body ) {
			return null;
		}
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || array() === $data ) {
			return null;
		}
		$releases = array();
		foreach ( $data as $version => $state ) {
			if ( is_scalar( $state ) && is_scalar( $version ) ) {
				$releases[ (string) $version ] = substr( (string) $state, 0, 20 );
			}
		}
		return array() === $releases ? null : $releases;
	}

	/**
	 * Falls back to a stale cached release list, or reports it unanswered.
	 *
	 * @param array  $lookup Store lookup that preceded the fetch.
	 * @param string $reason Reason code for the failure.
	 * @return array|null
	 */
	private function degrade( $lookup, $reason ) {
		$age = time() - $lookup['fetched_at'];
		if ( is_array( $lookup['data'] ) && $lookup['fetched_at'] > 0 && $age < self::MAX_STALE ) {
			$this->manager->record_stale( $this->name(), $lookup['fetched_at'] );
			return $lookup['data'];
		}
		$this->manager->record_unavailable( $this->name(), $reason );
		return null;
	}

	/**
	 * The major.minor cycle a version belongs to.
	 *
	 * @param string $version Version string.
	 * @return string
	 */
	private function cycle_of( $version ) {
		$parts = explode( '.', $version );
		if ( ! isset( $parts[0] ) || '' === $parts[0] ) {
			return '';
		}
		return isset( $parts[1] ) ? $parts[0] . '.' . $parts[1] : $parts[0];
	}

	/**
	 * Cycle rows for a bundled product, newest first as authored.
	 *
	 * @param string $product Product key.
	 * @return array[]
	 */
	private function bundled_cycles( $product ) {
		$table = self::table();
		return isset( $table[ $product ]['cycles'] ) && is_array( $table[ $product ]['cycles'] ) ? $table[ $product ]['cycles'] : array();
	}

	/**
	 * Loads and caches the bundled lifecycle table.
	 *
	 * @return array
	 */
	private static function table() {
		if ( null !== self::$table ) {
			return self::$table;
		}
		self::$table = array();
		$path        = AUDITRA_PLUGIN_DIR . 'includes/data/lifecycle.json';
		if ( is_readable( $path ) ) {
			$decoded = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Bundled plugin file, not a remote or user-supplied path.
			if ( is_array( $decoded ) ) {
				self::$table = $decoded;
			}
		}
		return self::$table;
	}
}
