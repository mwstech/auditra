<?php
/**
 * The get_site_overview tool.
 *
 * @package Auditra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site environment enriched with support lifecycle facts. Facts only:
 * versions, cycles, dates, and whether dates have passed. No severity labels,
 * no advice — the client does the judgment.
 */
class Auditra_Tool_Get_Site_Overview {

	/**
	 * Registers the tool.
	 *
	 * @param Auditra_Tool_Registry $registry Tool registry.
	 * @return void
	 */
	public static function register( $registry ) {
		$registry->register(
			'get_site_overview',
			'Returns this WordPress site\'s environment: WordPress, PHP, and database versions with their support status \u2014 for PHP and the database, the vendor-published end-of-life date and whether it has passed; for WordPress, the security status wordpress.org publishes for that exact release (latest, outdated, or insecure) \u2014 active theme and parent, multisite status, object cache, debug mode, memory limits, cron state, plugin counts by status, and published post count.',
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
		$collector = new Auditra_Site_Context_Collector();
		$context   = $collector->collect();

		$manager = new Auditra_Enrichment_Manager();
		$eol     = new Auditra_Lifecycle_Client( $manager );

		$statuses = $eol->support_statuses(
			array(
				'php'                          => $context['php_version'],
				'wordpress'                    => $context['wordpress_version'],
				$context['database']['flavor'] => $context['database']['version'],
			)
		);

		$support = array();
		foreach ( $statuses as $product => $status ) {
			if ( null !== $status ) {
				$support[ $product ] = $status;
			}
		}
		if ( array() !== $support ) {
			$context['support_status'] = $support;
		}

		return Auditra_Tool_Registry::with_meta(
			$context,
			1,
			1,
			false,
			$manager->source_status()
		);
	}
}
