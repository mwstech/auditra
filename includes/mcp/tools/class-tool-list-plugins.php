<?php
/**
 * The list_plugins tool.
 *
 * @package Auditra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Paginated plugin inventory. Compact rows by default, detail on request.
 * This phase carries only flags knowable without network access.
 */
class Auditra_Tool_List_Plugins {

	const DEFAULT_LIMIT = 25;
	const MAX_LIMIT     = 100;

	// Detail mode is for looking closely at a handful of plugins, not for
	// dumping the estate; its lower cap is what keeps Phase 3 enrichment
	// inside the 20 KB budget (docs/DECISIONS.md 17).
	const MAX_DETAIL_LIMIT = 10;

	/**
	 * Registers the tool.
	 *
	 * @param Auditra_Tool_Registry $registry Tool registry.
	 * @return void
	 */
	public static function register( $registry ) {
		$registry->register(
			'list_plugins',
			'Returns the installed plugin inventory of this WordPress site: for each plugin its slug, name, version, active/inactive/mu/dropin status, whether an update is available, and offline health flags such as single_file or requires_newer_php. Paginated (default 25, max 100 rows). Pass detail=true for author, description, requirements, auto-update setting, and disk footprint per plugin; detail mode is for close inspection and caps at 10 rows per page.',
			array(
				'type'       => 'object',
				'properties' => array(
					'status'     => array(
						'type'        => 'string',
						'enum'        => array( 'all', 'active', 'inactive', 'mu', 'dropin' ),
						'default'     => 'all',
						'description' => 'Filter by plugin status.',
					),
					'has_update' => array(
						'type'        => 'boolean',
						'description' => 'When true, only plugins with an available update; when false, only plugins without one.',
					),
					'limit'      => array(
						'type'        => 'integer',
						'default'     => self::DEFAULT_LIMIT,
						'minimum'     => 1,
						'maximum'     => self::MAX_LIMIT,
						'description' => 'Rows per page, capped server-side: 100 for compact rows, 10 when detail=true.',
					),
					'offset'     => array(
						'type'        => 'integer',
						'default'     => 0,
						'minimum'     => 0,
						'description' => 'Rows to skip, for pagination.',
					),
					'detail'     => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Include expanded fields per plugin.',
					),
				),
			),
			array( __CLASS__, 'run' )
		);
	}

	/**
	 * Runs the tool.
	 *
	 * @param array $args Tool arguments.
	 * @return string JSON string.
	 */
	public static function run( $args ) {
		$status     = isset( $args['status'] ) && is_scalar( $args['status'] ) ? (string) $args['status'] : 'all';
		$has_update = isset( $args['has_update'] ) ? (bool) $args['has_update'] : null;
		$detail     = ! empty( $args['detail'] );
		$max_limit  = $detail ? self::MAX_DETAIL_LIMIT : self::MAX_LIMIT;
		$limit      = isset( $args['limit'] ) && is_scalar( $args['limit'] ) ? (int) $args['limit'] : min( self::DEFAULT_LIMIT, $max_limit );
		$limit      = max( 1, min( $max_limit, $limit ) );
		$offset     = isset( $args['offset'] ) && is_scalar( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

		$collector = new Auditra_Inventory_Collector();
		$records   = $collector->collect();

		// Network-derived flags (docs/DECISIONS.md 18): booleans in the flags
		// array, never numbers. When a source is unavailable its flags are
		// simply absent, _meta.sources says why, and coverage names the slugs
		// nobody looked at. Absent must not read as "no", so a row nobody
		// could check carries vulnerability_unknown: with neither flag, the
		// row was checked and is clean (docs/DECISIONS.md 51).
		$manager       = new Auditra_Enrichment_Manager();
		$vuln_client   = Auditra_Tool_Check_Vulnerabilities::provider( $manager );
		$wporg_client  = new Auditra_WPOrg_Client( $manager );
		$slug_versions = array();
		foreach ( $records as $record ) {
			if ( in_array( $record['status'], array( 'active', 'inactive' ), true ) ) {
				$slug_versions[ $record['slug'] ] = $record['version'];
			}
		}
		$vuln_map   = $vuln_client->has_vulnerability_map( $slug_versions );
		$supply_map = $vuln_client->supply_chain_map( $slug_versions );
		$wporg_map  = $wporg_client->records( array_keys( $slug_versions ) );

		// Release cycles place a tested-up-to value relative to the current
		// WordPress release without pretending minor-version arithmetic works
		// across majors (6.9 -> 7.0).
		$eol_client  = new Auditra_Lifecycle_Client( $manager );
		$cycle_index = null;
		$cycles      = $eol_client->cycles( 'WordPress' );
		if ( null !== $cycles ) {
			$cycle_index = array();
			foreach ( $cycles as $i => $cycle ) {
				$cycle_index[ $cycle['cycle'] ] = $i;
			}
		}

		$records = array_values(
			array_filter(
				$records,
				function ( $record ) use ( $status, $has_update ) {
					if ( 'all' !== $status && $record['status'] !== $status ) {
						return false;
					}
					if ( null !== $has_update && $record['update_available'] !== $has_update ) {
						return false;
					}
					return true;
				}
			)
		);

		$total = count( $records );
		$page  = array_slice( $records, $offset, $limit );

		$rows = array();
		foreach ( $page as $record ) {
			// array_key_exists, not isset: a checked-and-clean slug maps to
			// false, which isset() would report the same as never checked.
			$has_vuln = array_key_exists( $record['slug'], $vuln_map ) ? $vuln_map[ $record['slug'] ] : null;
			$supply   = array_key_exists( $record['slug'], $supply_map ) ? $supply_map[ $record['slug'] ] : null;
			$wporg    = isset( $wporg_map[ $record['slug'] ] ) ? $wporg_map[ $record['slug'] ] : null;
			$rows[]   = self::row( $record, $detail, $has_vuln, $wporg, $cycle_index, $supply );
		}

		$payload = array(
			'plugins' => $rows,
			'counts'  => self::status_counts( $collector ),
			'limit'   => $limit,
			'offset'  => $offset,
		);

		$coverage = array();
		foreach ( array(
			'wpvulnerability' => $vuln_map,
			'wporg'           => $wporg_map,
		) as $source => $map ) {
			$unchecked = array();
			foreach ( array_keys( $slug_versions ) as $slug ) {
				if ( ! array_key_exists( $slug, $map ) || null === $map[ $slug ] ) {
					$unchecked[] = $slug;
				}
			}
			if ( array() !== $unchecked ) {
				$coverage[ $source ] = array(
					'complete'        => false,
					'plugins_checked' => count( $slug_versions ) - count( $unchecked ),
					'plugins_total'   => count( $slug_versions ),
					'unchecked_slugs' => $unchecked,
				);
			}
		}
		if ( array() !== $coverage ) {
			$payload['coverage'] = $coverage;
		}

		return Auditra_Tool_Registry::with_meta(
			$payload,
			$total,
			count( $rows ),
			( $offset + count( $rows ) ) < $total,
			$manager->source_status()
		);
	}

	/**
	 * Overall status counts, independent of the active filter, so the client
	 * always sees the shape of the whole site.
	 *
	 * @param Auditra_Inventory_Collector $collector Collector (reuses its cache).
	 * @return array
	 */
	private static function status_counts( $collector ) {
		$counts = array(
			'active'   => 0,
			'inactive' => 0,
			'mu'       => 0,
			'dropin'   => 0,
		);
		foreach ( $collector->collect() as $record ) {
			if ( isset( $counts[ $record['status'] ] ) ) {
				++$counts[ $record['status'] ];
			}
		}
		return $counts;
	}

	/**
	 * Builds one response row.
	 *
	 * @param array      $record      Inventory record.
	 * @param bool       $detail      Whether to include expanded fields.
	 * @param bool|null  $has_vuln    Vulnerability presence, null when unknown.
	 * @param array|null $wporg       wordpress.org answer, null when unknown.
	 * @param array|null $cycle_index WP release cycle => position map, null when unknown.
	 * @param mixed      $supply      Supply-chain verdict, false, or null when unknown.
	 * @return array
	 */
	private static function row( $record, $detail, $has_vuln = null, $wporg = null, $cycle_index = null, $supply = null ) {
		$row = array(
			'slug'             => $record['slug'],
			'name'             => $record['name'],
			'version'          => $record['version'],
			'status'           => $record['status'],
			'update_available' => $record['update_available'],
			'latest_version'   => $record['latest_version'],
			'flags'            => self::flags( $record, $has_vuln, $wporg, $cycle_index, $supply ),
		);

		if ( $detail ) {
			$row['author']      = $record['author'];
			$row['description'] = self::truncate( $record['description'], 200 );
			$row['plugin_uri']  = $record['plugin_uri'];
			// The text domain almost always equals the slug; only the
			// exceptions are worth bytes.
			if ( $record['text_domain'] !== $record['slug'] ) {
				$row['text_domain'] = $record['text_domain'];
			}
			$row['requires_wp']  = $record['requires_wp'];
			$row['requires_php'] = $record['requires_php'];
			if ( $record['auto_update'] ) {
				$row['auto_update'] = true;
			}
			$row['disk_size']  = Auditra_Tool_Registry::format_bytes( $record['disk_size'] );
			$row['file_count'] = $record['file_count'];

			// Raw wordpress.org figures live in detail mode only; compact
			// rows carry flags, never numbers (docs/DECISIONS.md 18).
			if ( null !== $wporg && ! empty( $wporg['found'] ) ) {
				$row['wporg_last_updated'] = isset( $wporg['last_updated'] ) ? $wporg['last_updated'] : null;
				$row['wporg_tested']       = isset( $wporg['tested'] ) ? $wporg['tested'] : null;
				$row['active_installs']    = isset( $wporg['active_installs'] ) ? $wporg['active_installs'] : null;
				$row['rating']             = isset( $wporg['rating'] ) ? $wporg['rating'] : null;
				$row['num_ratings']        = isset( $wporg['num_ratings'] ) ? $wporg['num_ratings'] : null;
				$row['support_threads']    = isset( $wporg['support_threads'] ) ? $wporg['support_threads'] : null;
				if ( isset( $wporg['support_threads'], $wporg['support_threads_resolved'] ) && $wporg['support_threads'] > 0 ) {
					$row['support_resolved_ratio'] = round( $wporg['support_threads_resolved'] / $wporg['support_threads'], 2 );
				}
			}
		}

		// Null, empty-string, and empty-array fields carry no information;
		// omitting them keeps a full-site detail response inside the 20 KB
		// budget.
		return array_filter(
			$row,
			function ( $value ) {
				return null !== $value && '' !== $value && array() !== $value;
			}
		);
	}

	/**
	 * Health flags for a record. Every network-derived flag is mechanically
	 * defined; thresholds are documented in get_capabilities
	 * (docs/DECISIONS.md 25).
	 *
	 * @param array      $record      Inventory record.
	 * @param bool|null  $has_vuln    Vulnerability presence, null when unknown.
	 * @param array|null $wporg       wordpress.org answer, null when unknown.
	 * @param array|null $cycle_index WP release cycle => position map.
	 * @param mixed      $supply      Supply-chain verdict, false, or null when unknown.
	 * @return string[]
	 */
	private static function flags( $record, $has_vuln = null, $wporg = null, $cycle_index = null, $supply = null ) {
		$flags = array();

		if ( true === $has_vuln ) {
			$flags[] = 'has_vulnerability';
		} elseif ( null === $has_vuln && in_array( $record['status'], array( 'active', 'inactive' ), true ) ) {
			// Nobody looked. Without this the row is indistinguishable from a
			// row that was checked and came back clean, and the safe-looking
			// reading is the wrong one. Only for rows that were candidates for
			// a lookup: mu-plugins and drop-ins have no wp.org identity to look
			// up at all, and their own flags already say so.
			$flags[] = 'vulnerability_unknown';
		}

		// Separate from has_vulnerability on purpose: a hijacked update channel
		// is not a bug in a release, and one flag covering both would let the
		// more serious fact hide behind the commoner one.
		if ( is_string( $supply ) ) {
			$flags[] = 'undetermined' === $supply ? 'supply_chain_undetermined' : 'supply_chain_' . $supply;
		}

		if ( null !== $wporg ) {
			if ( empty( $wporg['found'] ) ) {
				$flags[] = ! empty( $wporg['closed'] ) ? 'closed_on_wporg' : 'no_wporg_record';
			} else {
				if ( isset( $wporg['last_updated'] ) ) {
					$age_days = ( time() - strtotime( $wporg['last_updated'] ) ) / DAY_IN_SECONDS;
					if ( $age_days > 1460 ) {
						$flags[] = 'not_updated_4y'; // Implies not_updated_2y; only the strongest is emitted.
					} elseif ( $age_days > 730 ) {
						$flags[] = 'not_updated_2y';
					}
				}
				if ( null !== $cycle_index && isset( $wporg['tested'] ) && self::tested_cycles_behind( $wporg['tested'], $cycle_index ) >= 3 ) {
					$flags[] = 'untested_current_wp';
				}
			}
		}

		if ( $record['network_active'] ) {
			$flags[] = 'network_active';
		}
		if ( 'mu' === $record['status'] ) {
			$flags[] = 'mu_plugin';
		} elseif ( 'dropin' === $record['status'] ) {
			$flags[] = 'dropin';
		} elseif ( $record['single_file'] ) {
			$flags[] = 'single_file';
		}
		if ( '' !== $record['requires_php'] && version_compare( PHP_VERSION, $record['requires_php'], '<' ) ) {
			$flags[] = 'requires_newer_php';
		}
		if ( '' !== $record['requires_wp'] && version_compare( get_bloginfo( 'version' ), $record['requires_wp'], '<' ) ) {
			$flags[] = 'requires_newer_wp';
		}

		return $flags;
	}

	/**
	 * How many release cycles a tested-up-to value sits behind the newest
	 * cycle. Uses the ordered wordpress.org release list (index 0 = newest) so major
	 * boundaries (6.9 -> 7.0) need no arithmetic. Unknown cycles count as
	 * infinitely behind only if older than every known cycle; unmatchable
	 * values return 0 so no flag is raised on bad data.
	 *
	 * @param string $tested      Tested-up-to value, e.g. "6.4.2".
	 * @param array  $cycle_index Cycle => position map.
	 * @return int
	 */
	private static function tested_cycles_behind( $tested, $cycle_index ) {
		foreach ( $cycle_index as $cycle => $index ) {
			if ( $tested === $cycle || 0 === strpos( $tested . '.', $cycle . '.' ) ) {
				return $index;
			}
		}
		return 0;
	}

	/**
	 * Truncates free text. Descriptions are capped at 200 characters, no
	 * exceptions (SPEC section 10).
	 *
	 * @param string $text Text to truncate.
	 * @param int    $max  Maximum characters.
	 * @return string
	 */
	private static function truncate( $text, $max ) {
		if ( function_exists( 'mb_substr' ) && function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $text ) > $max ? mb_substr( $text, 0, $max - 1 ) . '…' : $text;
		}
		return strlen( $text ) > $max ? substr( $text, 0, $max - 1 ) . '…' : $text;
	}
}
