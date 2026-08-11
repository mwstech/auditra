<?php
/**
 * The get_capabilities tool.
 *
 * @package Auditra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Describes what this server will be able to answer and what it will not.
 * Phase 0: content is a hardcoded orientation document; the round trip is
 * what matters.
 */
class Auditra_Tool_Get_Capabilities {

	/**
	 * Registers the tool.
	 *
	 * @param Auditra_Tool_Registry $registry Tool registry.
	 * @return void
	 */
	public static function register( $registry ) {
		$registry->register(
			'get_capabilities',
			'Describes what this Auditra server can and cannot answer about the WordPress site it runs on. Call this first to orient yourself.',
			array(
				'type'       => 'object',
				'properties' => new stdClass(),
			),
			array( __CLASS__, 'run' )
		);
	}

	/**
	 * Runs the tool.
	 *
	 * @return string JSON string.
	 */
	public static function run() {
		$capabilities = array(
			'server'            => 'Auditra',
			'version'           => AUDITRA_VERSION,
			'read_only'         => true,
			'description'       => 'A read-only MCP server for inspecting this WordPress site\'s plugin estate. It reports facts, never verdicts; analysis is the client\'s job.',
			'available_now'     => array(
				'get_capabilities'      => 'This orientation document.',
				'list_plugins'          => 'Paginated inventory of every installed plugin, mu-plugin, and drop-in: slug, name, version, status, update availability, and health flags (see flag_definitions). detail=true adds author, truncated description, requirements, auto-update setting, disk footprint, and raw wordpress.org figures: last updated, tested-up-to, active installs, rating, rating count, support threads, resolved ratio.',
				'get_site_overview'     => 'The site environment: WordPress, PHP, and database versions each with support facts \u2014 PHP and the database carry the vendor-published end-of-life date and whether it has passed, WordPress carries the security status wordpress.org publishes for that release \u2014 theme, multisite status, object cache, debug mode, memory limits, cron state, plugin counts, and published post count.',
				'check_vulnerabilities' => 'Known published vulnerabilities matched against the plugin versions actually installed, plus WordPress core: CVE identifiers, CVSS score and severity as published, affected range, and fixed-in version. Version matches only, never slug matches. Supply-chain audits are returned separately in supply_chain; see supply_chain_note.',
				'analyze_autoload'      => 'Autoloaded option weight per plugin with attribution confidence, the largest individual options, and an explicit unattributed bucket with its share of total bytes.',
				'analyze_cron'          => 'Scheduled events grouped by owning plugin, WP-Cron state, and orphaned hooks with no registered callback (with the conditional-registration caveat stated).',
				'analyze_database'      => 'Non-core tables with approximate row counts and sizes, attributed to plugins with confidence; orphaned tables listed separately. Tables outside the WordPress prefix are invisible.',
				'analyze_usage'         => 'Content features per plugin (shortcodes, blocks, post types, taxonomies) with occurrence counts in post content, and a zero_content_usage flag. Measures content usage only; see usage_note.',
				'get_plugin_details'    => 'The complete record for up to five named plugins, composed from every source: inventory, wordpress.org, vulnerabilities, autoload weight, cron, tables, and content usage. The drill-down after list_plugins.',
			),
			'usage_note'        => 'zero_content_usage means exactly: the plugin registers shortcodes, blocks, or post types and none appear in post content. Plugins registering no content features are reported not_measurable, never unused — hooks, filters, admin screens, REST endpoints, and template code are invisible to content scanning. Counts scan post_content only; shortcodes in widgets, options, post meta, or theme templates count zero while possibly appearing on every page. Checks that do not run return null with a reason, never zero.',
			'attribution_note'  => 'Attribution confidence: high = curated slug-to-prefix mapping; medium = prefix derived mechanically from the plugin slug; anything else is reported unattributed rather than guessed. Known limits: cron orphans can be false positives when a plugin registers callbacks conditionally, and database tables that do not use the WordPress table prefix are not visible.',
			'tool_list_note'    => 'If a tool listed in available_now does not appear in your tool list, your tool list is stale. On MCP revision 2026-07-28 the tools/list response carries a 24-hour ttlMs, so a stale list refreshes itself within a day; on older protocol revisions there is no expiry signal, so refresh by reconnecting to this server.',
			'supply_chain_note' => array(
				'what_this_is'  => 'A supply-chain audit is a finding about the plugin\'s update channel rather than about a bug in its code: someone with publishing rights shipped a release the original author did not write. It carries no CVE and no CVSS score, so it is returned in its own supply_chain array by check_vulnerabilities and get_plugin_details, is never merged into findings, and is never paginated away. On list_plugins it appears as its own flag, separate from has_vulnerability.',
				'attribution'   => 'Every supply_chain entry carries a source field naming its publisher, so a finding quoted out of this response keeps its attribution. These verdicts are published by WPVulnerability and are reproduced here exactly as published, identified by their audit ID and publication date. Auditra performs no analysis of its own on plugin code, reaches no independent conclusion about any plugin or its authors, and neither endorses nor disputes a verdict. It reports that an audit exists, what it says, and whether the installed version falls inside the range it names. A verdict is an accusation made by a third party about software someone else wrote: weigh it as such, attribute it to its source rather than to this site, and direct any question about a specific verdict, its evidence, or its accuracy to WPVulnerability at https://www.wpvulnerability.net/, not to Auditra or to the site being audited.',
				'verdicts'      => array(
					'malicious'  => 'The audit found code in the affected versions that does not belong to the plugin: the release was compromised, not merely suspect. Treat an installed version inside the affected range as running attacker-supplied code.',
					'suspicious' => 'The audit found changes consistent with a compromise but did not confirm malicious behavior. Unresolved, not cleared.',
					'cleaned'    => 'The plugin was compromised and a clean release followed. Versions inside the affected range still contain the compromised code; the fact that a fix exists says nothing about a site still running an affected version.',
				),
				'version_match' => 'confirmed means the installed version was compared against the audit\'s published range and falls inside it. undetermined means the range is published as a repository revision (for example trunk@r2268077) rather than a version number, so no version comparison was possible; the audit applies to this plugin, and whether this particular installed version is inside it was not determined here. undetermined is never reported as clean.',
				'ioc_count'     => 'The number of indicators of compromise the audit recorded. The indicators themselves are the auditor\'s to publish and are not reproduced here. A count of zero means the audit recorded none, not that the plugin is clean.',
			),
			'degradation_note'  => 'Every tool reports what it could not do. _meta.sources carries one object per consulted source with its status (ok, stale, unavailable), a reason code when it is not ok (no_outbound_http, upstream_error, timeout, network_error, backoff_active, unparseable_response, oversized_response), when the source last answered successfully, and when a retry becomes possible. When a source is unreachable but cached data survives, that data is served and labeled with its age rather than withheld; past a source-specific maximum age it is discarded instead. check_vulnerabilities additionally declares a state of complete, complete_stale, partial, or not_performed, and in the not_performed state returns no findings array at all, because an empty findings array is shaped like an answer.',
			'flag_definitions'  => array(
				'has_vulnerability'         => 'At least one published vulnerability record whose affected version range includes the installed version.',
				'vulnerability_unknown'     => 'The vulnerability source could not be consulted for this plugin. Neither this flag nor has_vulnerability means the plugin was checked and is clean.',
				'supply_chain_malicious'    => 'A supply-chain audit found attacker-supplied code in versions including the one installed. See supply_chain_note.',
				'supply_chain_suspicious'   => 'A supply-chain audit found changes consistent with a compromise, unconfirmed, in versions including the one installed. See supply_chain_note.',
				'supply_chain_cleaned'      => 'A supply-chain audit found a compromise later fixed in a clean release, and the installed version is inside the compromised range. See supply_chain_note.',
				'supply_chain_undetermined' => 'A supply-chain audit exists for this plugin, but its affected range is published as a repository revision rather than a version number, so whether the installed version is inside it was not determined. Not a clean result.',
				'closed_on_wporg'           => 'wordpress.org explicitly reports this plugin closed (its API returns closed:true, usually with a date and reason).',
				'no_wporg_record'           => 'wordpress.org returned "not found" for this slug. Could be premium, custom, renamed, or removed; these are not distinguishable, so no stronger claim is made.',
				'not_updated_2y'            => 'Last wordpress.org update more than 730 days ago (and not more than 1460; see not_updated_4y).',
				'not_updated_4y'            => 'Last wordpress.org update more than 1460 days ago. Implies not_updated_2y; only this stronger flag is emitted.',
				'untested_current_wp'       => 'The tested-up-to value resolves to a WordPress release cycle three or more positions behind the newest cycle in the release list wordpress.org publishes.',
				'requires_newer_php'        => 'The plugin header requires a PHP version newer than the one running.',
				'requires_newer_wp'         => 'The plugin header requires a WordPress version newer than the one running.',
				'network_active'            => 'Active network-wide on a multisite.',
				'single_file'               => 'A single-file plugin living directly in the plugins directory.',
				'mu_plugin'                 => 'A must-use plugin; always active, never on the plugins screen.',
				'dropin'                    => 'A drop-in (e.g. object-cache.php) occupying a WordPress override slot.',
			),
			'planned_tools'     => array(
				'note' => 'None. The v1 tool set is complete.',
			),
			'does_not_measure'  => array(
				'per_plugin_runtime_cost' => 'Per-plugin execution time cannot be measured without a profiler and is never reported.',
				'front_end_asset_weight'  => 'Front-end asset attribution is not measured.',
				'write_operations'        => 'This server performs no write operation of any kind against the site.',
			),
		);

		return Auditra_Tool_Registry::with_meta( $capabilities, 1, 1, false );
	}
}
