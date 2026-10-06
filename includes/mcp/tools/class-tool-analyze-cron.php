<?php
/**
 * The analyze_cron tool.
 *
 * @package Auditra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scheduled events per plugin, plus hooks nothing is listening to.
 */
class Auditra_Tool_Analyze_Cron {

	/**
	 * Registers the tool.
	 *
	 * @param Auditra_Tool_Registry $registry Tool registry.
	 * @return void
	 */
	public static function register( $registry ) {
		$registry->register(
			'analyze_cron',
			'Returns every scheduled cron event on this WordPress site grouped by the plugin that owns the hook (with attribution confidence), whether WP-Cron is disabled, and an orphaned list of scheduled hooks that have no registered callback at runtime — usually leftovers from removed plugins.',
			array(
				'type'       => 'object',
				'properties' => new stdClass(),
			),
			array( __CLASS__, 'run' ),
			array(
				'title'       => 'Analyze scheduled events',
				'annotations' => array(
					'openWorldHint' => false,
				),
			)
		);
	}

	/**
	 * Runs the tool.
	 *
	 * @return string JSON string.
	 */
	public static function run() {
		$inventory = new Auditra_Inventory_Collector();
		$slugs     = array();
		foreach ( $inventory->collect() as $record ) {
			$slugs[] = $record['slug'];
		}
		$attribution = new Auditra_Attribution( $slugs );

		$collector = new Auditra_Cron_Collector();
		$events    = $collector->collect();

		$by_owner = array();
		$orphaned = array();

		foreach ( $events as $event ) {
			$owner = $attribution->attribute( $event['hook'], 'hook' );
			$row   = array(
				'hook'     => $event['hook'],
				'schedule' => $event['schedule'],
				'next_run' => $event['next_run'],
			);

			if ( ! $event['has_callback'] ) {
				$orphaned[] = $row;
			}

			$key = null !== $owner ? $owner['slug'] : 'unattributed';
			if ( ! isset( $by_owner[ $key ] ) ) {
				$by_owner[ $key ] = array(
					'owner'      => $key,
					'confidence' => null !== $owner ? $owner['confidence'] : null,
					'events'     => array(),
				);
			}
			$by_owner[ $key ]['events'][] = $row;
		}

		$payload = array(
			'total_events'     => count( $events ),
			'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'by_owner'         => array_values( $by_owner ),
			'orphaned'         => $orphaned,
			'orphan_note'      => 'Orphaned means no callback was registered for the hook at runtime with all active plugins loaded. A plugin that registers its callback conditionally (for example only in admin) can appear here without being a true orphan.',
		);

		return Auditra_Tool_Registry::with_meta( $payload, count( $events ), count( $events ), false );
	}
}
