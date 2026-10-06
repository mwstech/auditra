<?php
/**
 * The analyze_database tool.
 *
 * @package Auditra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Non-core tables with sizes and owners, plus tables owned by nobody
 * installed.
 */
class Auditra_Tool_Analyze_Database {

	/**
	 * Registers the tool.
	 *
	 * @param Auditra_Tool_Registry $registry Tool registry.
	 * @return void
	 */
	public static function register( $registry ) {
		$registry->register(
			'analyze_database',
			'Returns every non-core database table under this WordPress site\'s table prefix with approximate row count, data size, index size, and the plugin it is attributed to (with confidence). Tables whose prefix maps to no installed plugin are listed separately as orphaned — usually leftovers from deleted plugins. Tables not using the WordPress prefix are invisible to this tool.',
			array(
				'type'       => 'object',
				'properties' => new stdClass(),
			),
			array( __CLASS__, 'run' ),
			array(
				'title'       => 'Analyze database tables',
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

		$collector = new Auditra_Database_Collector();
		$tables    = $collector->collect();

		$attributed = array();
		$orphaned   = array();

		foreach ( $tables as $table ) {
			$owner = $attribution->attribute( $table['stripped_name'], 'table' );
			$row   = array(
				'table'       => $table['name'],
				'rows_approx' => $table['rows_approx'],
				'data_size'   => Auditra_Tool_Registry::format_bytes( $table['data_bytes'] ),
				'index_size'  => Auditra_Tool_Registry::format_bytes( $table['index_bytes'] ),
			);

			if ( null !== $owner && $attribution->is_installed( $owner['slug'] ) ) {
				$row['owner']      = $owner['slug'];
				$row['confidence'] = $owner['confidence'];
				$attributed[]      = $row;
				continue;
			}

			// A prefix matching a known-but-not-installed plugin is a strong
			// hint about who left the table behind; still orphaned.
			if ( null !== $owner ) {
				$row['possible_former_owner'] = $owner['slug'];
			}
			$orphaned[] = $row;
		}

		$payload = array(
			'tables'     => $attributed,
			'orphaned'   => $orphaned,
			'row_note'   => 'Row counts are storage-engine estimates, not exact counts.',
			'scope_note' => 'Only tables using this site\'s WordPress table prefix are visible; plugins creating tables outside the prefix are not listed.',
		);

		return Auditra_Tool_Registry::with_meta( $payload, count( $tables ), count( $tables ), false );
	}
}
