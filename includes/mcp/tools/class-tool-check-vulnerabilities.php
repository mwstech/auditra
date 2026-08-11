<?php
/**
 * The check_vulnerabilities tool.
 *
 * @package Auditra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Known vulnerabilities matched against installed versions. Findings are
 * version matches, never slug matches. Severity and scores are reported as
 * the source returns them, never invented.
 *
 * Every response declares one of four states (docs/DECISIONS.md 51):
 *
 * - complete       every plugin checked against fresh data.
 * - complete_stale every plugin checked, from cached data past its TTL.
 * - partial        some plugins checked, some not, with the rest named.
 * - not_performed  nothing could be checked, and there is NO findings array.
 *
 * The last one is the point of the design. An empty findings array is shaped
 * like an answer: it says "I looked and found nothing" even when nothing was
 * looked at. Coverage metadata alongside it can be overlooked; a missing field
 * cannot. When no plugin could be checked, the field does not exist.
 *
 * Supply-chain audits travel in their own array for the same reason: they are
 * a claim about the update channel, not about a release, and folding them into
 * a CVSS-sorted findings list would both mislabel and bury them
 * (docs/DECISIONS.md 57).
 */
class Auditra_Tool_Check_Vulnerabilities {

	const STATE_COMPLETE       = 'complete';
	const STATE_COMPLETE_STALE = 'complete_stale';
	const STATE_PARTIAL        = 'partial';
	const STATE_NOT_PERFORMED  = 'not_performed';

	/**
	 * Findings returned per call unless the caller asks for fewer. A neglected
	 * 45-plugin estate produced 171 findings and a 51 KB payload in Phase 8.7
	 * testing, against a 20 KB budget (SPEC section 10); an outage-era estate
	 * with no findings at all had hidden that. Findings are ordered by
	 * published CVSS score so a truncated page is the most severe one.
	 */
	const DEFAULT_LIMIT = 50;
	const MAX_LIMIT     = 200;

	/**
	 * Hard byte budget for the findings array, whatever limit was asked for.
	 * The response budget is 20 KB (SPEC section 10); the rest of the payload
	 * is coverage, core, and _meta. A row-count cap alone cannot hold a byte
	 * budget, because findings vary in size, so the count is a ceiling and
	 * this is the binding constraint.
	 */
	const MAX_FINDINGS_BYTES = 16384;

	/**
	 * Registers the tool.
	 *
	 * @param Auditra_Tool_Registry $registry Tool registry.
	 * @return void
	 */
	public static function register( $registry ) {
		$registry->register(
			'check_vulnerabilities',
			'Returns known published vulnerabilities that affect the plugin versions actually installed on this WordPress site, plus WordPress core. Each finding carries the affected slug, installed version, CVE identifiers, CVSS score and severity as published, the affected version range, and the fixed-in version where known. A plugin merely appearing in the vulnerability database is not reported; only version matches are. Supply-chain audits — plugins whose update channel shipped code the author did not write — are returned in a separate supply_chain array with a verdict of malicious, suspicious, or cleaned; they are never merged into findings, because a compromised release is not a CVE. Every response carries a state: complete, complete_stale (answered from cached data past its expiry, labeled with its age), partial (some plugins could not be checked, and they are named), or not_performed (nothing could be checked, and no findings array is returned at all). Findings are ordered by published CVSS score, highest first, and paginated; supply_chain entries are never paginated away.',
			array(
				'type'       => 'object',
				'properties' => array(
					'slugs'        => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Plugin slugs to check. Omit to check every installed plugin.',
					),
					'include_core' => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Also check the running WordPress core version.',
					),
					'limit'        => array(
						'type'        => 'integer',
						'default'     => self::DEFAULT_LIMIT,
						'minimum'     => 1,
						'maximum'     => self::MAX_LIMIT,
						'description' => 'Findings per page, capped server-side at 200.',
					),
					'offset'       => array(
						'type'        => 'integer',
						'default'     => 0,
						'minimum'     => 0,
						'description' => 'Findings to skip, for pagination.',
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
		// Scalars only; see docs/DECISIONS.md 68.
		$requested    = isset( $args['slugs'] ) && is_array( $args['slugs'] ) ? array_map( 'strval', array_filter( $args['slugs'], 'is_scalar' ) ) : null;
		$include_core = ! isset( $args['include_core'] ) || (bool) $args['include_core'];
		$limit        = isset( $args['limit'] ) && is_scalar( $args['limit'] ) ? (int) $args['limit'] : self::DEFAULT_LIMIT;
		$limit        = max( 1, min( self::MAX_LIMIT, $limit ) );
		$offset       = isset( $args['offset'] ) && is_scalar( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

		$collector     = new Auditra_Inventory_Collector();
		$slug_versions = array();
		foreach ( $collector->collect() as $record ) {
			// mu-plugins and drop-ins have no wp.org identity to look up.
			if ( ! in_array( $record['status'], array( 'active', 'inactive' ), true ) ) {
				continue;
			}
			if ( null !== $requested && ! in_array( $record['slug'], $requested, true ) ) {
				continue;
			}
			$slug_versions[ $record['slug'] ] = $record['version'];
		}

		$manager  = new Auditra_Enrichment_Manager();
		$provider = self::provider( $manager );

		$result = $provider->plugin_findings( $slug_versions );
		$core   = $include_core ? $provider->core_findings( get_bloginfo( 'version' ) ) : null;

		$checked_units = $result['checked'] + ( null !== $core ? 1 : 0 );
		$total_units   = count( $slug_versions ) + ( $include_core ? 1 : 0 );

		$coverage = array(
			'plugins_checked' => $result['checked'],
			'plugins_total'   => count( $slug_versions ),
			'unchecked_slugs' => $result['unchecked'],
		);
		if ( $include_core ) {
			$coverage['core_checked'] = null !== $core;
		}

		// Nothing was looked at. Say so, say why, and return no findings array:
		// there is no way to misread a response that contains no findings field.
		if ( 0 === $checked_units && $total_units > 0 ) {
			$payload = array(
				'state'    => self::STATE_NOT_PERFORMED,
				'reason'   => self::reason( $manager ),
				'coverage' => $coverage,
			);
			return Auditra_Tool_Registry::with_meta( $payload, null, null, false, $manager->source_status() );
		}

		$stale_used = array() !== $result['stale'] || ( null !== $core && ! empty( $core['stale'] ) );
		if ( array() !== $result['unchecked'] || ( $include_core && null === $core ) ) {
			$state = self::STATE_PARTIAL;
		} elseif ( $stale_used ) {
			$state = self::STATE_COMPLETE_STALE;
		} else {
			$state = self::STATE_COMPLETE;
		}

		$findings = self::by_severity( $result['findings'] );
		$total    = count( $findings );
		$page     = self::within_budget( array_slice( $findings, $offset, $limit ) );

		$payload = array(
			'state'    => $state,
			'findings' => $page,
			'coverage' => $coverage,
			'unparsed' => $result['unparsed'],
		);

		// A separate array, never folded into findings and never truncated. A
		// supply-chain audit says someone shipped code through this plugin's
		// update channel that the author did not write; that is a different
		// claim from "this release has a bug", and sorting it into a
		// CVSS-ordered list would bury it (it carries no CVSS at all).
		if ( array() !== $result['supply_chain'] ) {
			$payload['supply_chain'] = $result['supply_chain'];
		}

		if ( self::STATE_PARTIAL === $state ) {
			// Scoped explicitly: these findings describe the checked subset and
			// say nothing about the rest.
			$payload['findings_scope'] = 'Findings and supply_chain entries cover only the checked plugins listed in coverage; the unchecked slugs were not examined for either.';
			$payload['reason']         = self::reason( $manager );
		}

		if ( self::STATE_COMPLETE_STALE === $state ) {
			$oldest = $result['oldest_fetched_at'];
			if ( null !== $core && ! empty( $core['stale'] ) ) {
				$oldest = ( null === $oldest ) ? $core['fetched_at'] : min( $oldest, $core['fetched_at'] );
			}
			$payload['data_age'] = array(
				'fetched_at' => gmdate( 'Y-m-d\TH:i:s\Z', (int) $oldest ),
				'age_hours'  => round( ( time() - (int) $oldest ) / HOUR_IN_SECONDS, 1 ),
				'note'       => 'The upstream source was unreachable; these findings come from cached data past its expiry. Anything published since the fetch timestamp is not represented.',
			);
		}

		if ( $include_core ) {
			$payload['core'] = array(
				'version' => get_bloginfo( 'version' ),
				'checked' => null !== $core,
			);
			if ( null !== $core ) {
				$payload['core']['findings'] = $core['findings'];
				$payload['unparsed']        += $core['unparsed'];
			}
		}

		return Auditra_Tool_Registry::with_meta(
			$payload,
			$total,
			count( $page ),
			( $offset + count( $page ) ) < $total,
			$manager->source_status()
		);
	}

	/**
	 * The vulnerability provider for this request.
	 *
	 * One provider ships. The filter is the seam a second one would arrive
	 * through: implement Auditra_Vulnerability_Provider_Interface in one
	 * file and return an instance here. See CONTRIBUTING.md.
	 *
	 * @param Auditra_Enrichment_Manager $manager Shared manager.
	 * @return Auditra_Vulnerability_Provider_Interface
	 */
	public static function provider( $manager ) {
		$default = new Auditra_WPVulnerability_Client( $manager );

		/**
		 * Filters the vulnerability data provider.
		 *
		 * @param Auditra_Vulnerability_Provider_Interface $provider Default provider.
		 * @param Auditra_Enrichment_Manager               $manager  Shared enrichment manager.
		 */
		$provider = apply_filters( 'auditra_vulnerability_provider', $default, $manager );

		return $provider instanceof Auditra_Vulnerability_Provider_Interface ? $provider : $default;
	}

	/**
	 * Why the check could not run, or ran short: the reason code of the worst
	 * source status this request. A fact, with no advice attached.
	 *
	 * @param Auditra_Enrichment_Manager $manager Shared manager.
	 * @return string
	 */
	private static function reason( $manager ) {
		foreach ( $manager->source_status() as $status ) {
			if ( isset( $status['reason'] ) && 'unavailable' === $status['status'] ) {
				return $status['reason'];
			}
		}
		return Auditra_Enrichment_Manager::REASON_NETWORK_ERROR;
	}

	/**
	 * Trims a page to the byte budget, keeping at least one finding so a
	 * single enormous record still gets reported rather than silently
	 * disappearing. Whatever falls off is still counted in _meta.total and
	 * flagged by _meta.truncated, so nothing is dropped silently.
	 *
	 * @param array[] $page Findings for this page, most severe first.
	 * @return array[]
	 */
	private static function within_budget( $page ) {
		$kept  = array();
		$bytes = 0;
		foreach ( $page as $finding ) {
			$bytes += strlen( (string) wp_json_encode( $finding ) );
			if ( $bytes > self::MAX_FINDINGS_BYTES && array() !== $kept ) {
				break;
			}
			$kept[] = $finding;
		}
		return $kept;
	}

	/**
	 * Orders findings by published CVSS score, highest first, so that a
	 * truncated page is the most severe one rather than an arbitrary one.
	 * Unscored findings (the source publishes no CVSS for roughly half of all
	 * records) sort last but keep a stable slug order.
	 *
	 * @param array[] $findings Findings.
	 * @return array[]
	 */
	public static function by_severity( $findings ) {
		usort(
			$findings,
			function ( $a, $b ) {
				$sa = isset( $a['cvss_score'] ) ? (float) $a['cvss_score'] : -1.0;
				$sb = isset( $b['cvss_score'] ) ? (float) $b['cvss_score'] : -1.0;
				if ( $sa !== $sb ) {
					return ( $sb < $sa ) ? -1 : 1;
				}
				return strcmp( $a['slug'], $b['slug'] );
			}
		);
		return $findings;
	}
}
