<?php
/**
 * The get_plugin_details tool.
 *
 * @package Auditra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything known about up to five named plugins, composed from every
 * collector and enrichment source. The five-slug cap is what keeps this from
 * blowing the context window; it is enforced server-side.
 */
class Auditra_Tool_Get_Plugin_Details {

	const MAX_SLUGS = 5;

	/**
	 * Findings reported per plugin, most severe first. check_vulnerabilities
	 * is where a full, paginated list lives; this tool is a composite record
	 * and has four other sections to fit inside the same 20 KB budget.
	 */
	const MAX_FINDINGS_PER_PLUGIN = 10;

	/**
	 * Registers the tool.
	 *
	 * @param Auditra_Tool_Registry $registry Tool registry.
	 * @return void
	 */
	public static function register( $registry ) {
		$registry->register(
			'get_plugin_details',
			'Returns the complete record for up to five named plugins: inventory (version, author, requirements, disk footprint), wordpress.org data (last updated, tested, installs, rating, support), vulnerabilities matched to the installed version, autoloaded option weight, scheduled cron events, database tables, and content-feature usage. The drill-down after list_plugins.',
			array(
				'type'       => 'object',
				'properties' => array(
					'slugs' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'maxItems'    => self::MAX_SLUGS,
						'description' => 'Plugin slugs, at most 5. More than 5 are ignored beyond the cap.',
					),
				),
				'required'   => array( 'slugs' ),
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
		// Scalars only; see docs/DECISIONS.md 68.
		$slugs = isset( $args['slugs'] ) && is_array( $args['slugs'] ) ? array_slice( array_map( 'strval', array_filter( $args['slugs'], 'is_scalar' ) ), 0, self::MAX_SLUGS ) : array();
		if ( array() === $slugs ) {
			return Auditra_Tool_Registry::with_meta(
				array( 'error' => 'The slugs argument is required: an array of 1 to 5 plugin slugs.' ),
				0,
				0,
				false
			);
		}

		$inventory = new Auditra_Inventory_Collector();
		$records   = array();
		$all_slugs = array();
		foreach ( $inventory->collect() as $record ) {
			$all_slugs[] = $record['slug'];
			if ( in_array( $record['slug'], $slugs, true ) ) {
				$records[ $record['slug'] ] = $record;
			}
		}

		$manager     = new Auditra_Enrichment_Manager();
		$wporg       = new Auditra_WPOrg_Client( $manager );
		$vulns       = Auditra_Tool_Check_Vulnerabilities::provider( $manager );
		$attribution = new Auditra_Attribution( $all_slugs );

		$found_slugs   = array_keys( $records );
		$wporg_map     = $wporg->records( $found_slugs );
		$slug_versions = array();
		foreach ( $records as $slug => $record ) {
			$slug_versions[ $slug ] = $record['version'];
		}
		$vuln_result = $vulns->plugin_findings( $slug_versions );

		$autoload_by_slug = self::autoload_by_slug( $attribution );
		$cron_by_slug     = self::cron_by_slug( $attribution );
		$tables_by_slug   = self::tables_by_slug( $attribution );
		$usage_by_slug    = self::usage_by_slug();

		$details = array();
		foreach ( $slugs as $slug ) {
			if ( ! isset( $records[ $slug ] ) ) {
				$details[] = array(
					'slug'  => $slug,
					'error' => 'No installed plugin with this slug.',
				);
				continue;
			}
			$record = $records[ $slug ];

			$detail = array(
				'slug'      => $slug,
				'inventory' => array(
					'name'             => $record['name'],
					'version'          => $record['version'],
					'status'           => $record['status'],
					'author'           => $record['author'],
					'description'      => function_exists( 'mb_substr' ) ? mb_substr( $record['description'], 0, 200 ) : substr( $record['description'], 0, 200 ),
					'requires_wp'      => $record['requires_wp'],
					'requires_php'     => $record['requires_php'],
					'update_available' => $record['update_available'],
					'latest_version'   => $record['latest_version'],
					'auto_update'      => $record['auto_update'],
					'disk_size'        => Auditra_Tool_Registry::format_bytes( $record['disk_size'] ),
					'file_count'       => $record['file_count'],
				),
			);

			$sources_missing = array();

			$wporg_answer = isset( $wporg_map[ $slug ] ) ? $wporg_map[ $slug ] : null;
			if ( null === $wporg_answer ) {
				$sources_missing[] = 'wporg';
			} else {
				$detail['wporg'] = $wporg_answer;
			}

			$findings = array();
			foreach ( $vuln_result['findings'] as $finding ) {
				if ( $finding['slug'] === $slug ) {
					$findings[] = $finding;
				}
			}
			$findings = Auditra_Tool_Check_Vulnerabilities::by_severity( $findings );
			// Absent, not empty: a plugin nobody could check must not carry a
			// field that reads as "no vulnerabilities" (docs/DECISIONS.md 51).
			if ( in_array( $slug, $vuln_result['unchecked'], true ) ) {
				$sources_missing[] = 'wpvulnerability';
			} else {
				// Five slugs times an unbounded finding list blows the 20 KB
				// budget on a neglected estate; a plugin carrying dozens of
				// advisories gets its most severe ones and an honest count.
				$detail['vulnerabilities'] = array_slice( $findings, 0, self::MAX_FINDINGS_PER_PLUGIN );
				if ( count( $findings ) > self::MAX_FINDINGS_PER_PLUGIN ) {
					$detail['vulnerabilities_total']     = count( $findings );
					$detail['vulnerabilities_truncated'] = true;
				}
				// Its own field, never inside vulnerabilities, and never
				// truncated: a compromised update channel is a different claim
				// from a vulnerable release (docs/DECISIONS.md 57).
				$supply_chain = array();
				foreach ( $vuln_result['supply_chain'] as $entry ) {
					if ( $entry['slug'] === $slug ) {
						$supply_chain[] = $entry;
					}
				}
				if ( array() !== $supply_chain ) {
					$detail['supply_chain'] = $supply_chain;
				}
				if ( in_array( $slug, $vuln_result['stale'], true ) ) {
					$detail['vulnerabilities_data_age'] = array(
						'fetched_at' => gmdate( 'Y-m-d\TH:i:s\Z', (int) $vuln_result['oldest_fetched_at'] ),
						'age_hours'  => round( ( time() - (int) $vuln_result['oldest_fetched_at'] ) / HOUR_IN_SECONDS, 1 ),
					);
				}
			}

			$detail['autoload']    = isset( $autoload_by_slug[ $slug ] ) ? $autoload_by_slug[ $slug ] : array(
				'size'    => '0 B',
				'options' => 0,
			);
			$detail['cron_events'] = isset( $cron_by_slug[ $slug ] ) ? $cron_by_slug[ $slug ] : array();
			$detail['tables']      = isset( $tables_by_slug[ $slug ] ) ? $tables_by_slug[ $slug ] : array();
			if ( isset( $usage_by_slug[ $slug ] ) ) {
				$detail['content_usage'] = $usage_by_slug[ $slug ];
			} else {
				$detail['content_usage_note'] = 'Registers no shortcodes, blocks, post types, or taxonomies; content usage is not measurable for this plugin and says nothing about whether it is used.';
			}

			// Which sources had nothing to say about this specific plugin. Why
			// they had nothing to say is in _meta.sources, once per source.
			if ( array() !== $sources_missing ) {
				$detail['unchecked_sources'] = $sources_missing;
			}

			$details[] = $detail;
		}

		return Auditra_Tool_Registry::with_meta(
			array( 'plugins' => $details ),
			count( $details ),
			count( $details ),
			false,
			$manager->source_status()
		);
	}

	/**
	 * Autoload weight per slug.
	 *
	 * @param Auditra_Attribution $attribution Attribution engine.
	 * @return array<string, array>
	 */
	private static function autoload_by_slug( $attribution ) {
		$collector = new Auditra_Autoload_Collector();
		$totals    = array();
		foreach ( $collector->collect() as $name => $size ) {
			$owner = $attribution->attribute( $name, 'option' );
			if ( null === $owner ) {
				continue;
			}
			$slug = $owner['slug'];
			if ( ! isset( $totals[ $slug ] ) ) {
				$totals[ $slug ] = array(
					'bytes'      => 0,
					'options'    => 0,
					'confidence' => $owner['confidence'],
				);
			}
			$totals[ $slug ]['bytes']   += $size;
			$totals[ $slug ]['options'] += 1;
		}
		$out = array();
		foreach ( $totals as $slug => $total ) {
			$out[ $slug ] = array(
				'size'       => Auditra_Tool_Registry::format_bytes( $total['bytes'] ),
				'options'    => $total['options'],
				'confidence' => $total['confidence'],
			);
		}
		return $out;
	}

	/**
	 * Cron events per slug.
	 *
	 * @param Auditra_Attribution $attribution Attribution engine.
	 * @return array<string, array[]>
	 */
	private static function cron_by_slug( $attribution ) {
		$collector = new Auditra_Cron_Collector();
		$out       = array();
		foreach ( $collector->collect() as $event ) {
			$owner = $attribution->attribute( $event['hook'], 'hook' );
			if ( null === $owner ) {
				continue;
			}
			$out[ $owner['slug'] ][] = array(
				'hook'     => $event['hook'],
				'schedule' => $event['schedule'],
				'next_run' => $event['next_run'],
			);
		}
		return $out;
	}

	/**
	 * Tables per slug.
	 *
	 * @param Auditra_Attribution $attribution Attribution engine.
	 * @return array<string, array[]>
	 */
	private static function tables_by_slug( $attribution ) {
		$collector = new Auditra_Database_Collector();
		$out       = array();
		foreach ( $collector->collect() as $table ) {
			$owner = $attribution->attribute( $table['stripped_name'], 'table' );
			if ( null === $owner ) {
				continue;
			}
			$out[ $owner['slug'] ][] = array(
				'table'       => $table['name'],
				'rows_approx' => $table['rows_approx'],
				'data_size'   => Auditra_Tool_Registry::format_bytes( $table['data_bytes'] ),
			);
		}
		return $out;
	}

	/**
	 * Content features with counts per slug (small sites only need one pass).
	 *
	 * @return array<string, array>
	 */
	private static function usage_by_slug() {
		$collector = new Auditra_Usage_Collector();
		$features  = $collector->features_by_plugin();

		$out = array();
		foreach ( $features['by_slug'] as $slug => $feature_set ) {
			$entry = array();
			if ( isset( $feature_set['shortcodes'] ) ) {
				$counts = $collector->count_shortcodes( $feature_set['shortcodes'] );
				foreach ( $counts as $tag => $occurrences ) {
					$entry['shortcodes'][] = array(
						'tag'         => $tag,
						'occurrences' => $occurrences,
					);
				}
			}
			if ( isset( $feature_set['blocks'] ) ) {
				$counts = $collector->count_blocks( $feature_set['blocks'] );
				foreach ( $counts as $name => $occurrences ) {
					$entry['blocks'][] = array(
						'name'        => $name,
						'occurrences' => $occurrences,
					);
				}
			}
			if ( isset( $feature_set['post_types'] ) ) {
				foreach ( $collector->count_post_types( $feature_set['post_types'] ) as $type => $published ) {
					$entry['post_types'][] = array(
						'post_type' => $type,
						'published' => $published,
					);
				}
			}
			if ( isset( $feature_set['taxonomies'] ) ) {
				foreach ( $collector->count_taxonomies( $feature_set['taxonomies'] ) as $taxonomy => $terms ) {
					$entry['taxonomies'][] = array(
						'taxonomy' => $taxonomy,
						'terms'    => $terms,
					);
				}
			}
			$out[ $slug ] = $entry;
		}
		return $out;
	}
}
