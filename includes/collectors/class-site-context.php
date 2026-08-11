<?php
/**
 * Site context collector: the environment this WordPress runs in.
 *
 * Reads WordPress and PHP directly. Makes no network calls. Knows nothing
 * about MCP.
 *
 * @package Auditra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects versions, environment flags, and top-level counts.
 */
class Auditra_Site_Context_Collector {

	/**
	 * Returns the site context as a plain array.
	 *
	 * @return array
	 */
	public function collect() {
		global $wpdb;

		$db    = $this->database_info( $wpdb );
		$theme = wp_get_theme();

		$context = array(
			'wordpress_version'     => get_bloginfo( 'version' ),
			'php_version'           => PHP_VERSION,
			'database'              => $db,
			'active_theme'          => array(
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
			),
			'multisite'             => is_multisite(),
			'external_object_cache' => (bool) wp_using_ext_object_cache(),
			'wp_debug'              => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'memory_limit'          => array(
				'wp_constant' => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : null,
				'php_ini'     => (string) ini_get( 'memory_limit' ),
			),
			'max_execution_time'    => (int) ini_get( 'max_execution_time' ),
			'wp_cron_disabled'      => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'plugin_counts'         => $this->plugin_counts(),
			'published_posts'       => (int) wp_count_posts( 'post' )->publish,
		);

		$parent = $theme->parent();
		if ( $parent instanceof WP_Theme ) {
			$context['active_theme']['parent'] = array(
				'name'    => $parent->get( 'Name' ),
				'version' => $parent->get( 'Version' ),
			);
		}

		if ( is_multisite() ) {
			$context['site_count'] = (int) get_blog_count();
		}

		return $context;
	}

	/**
	 * Database flavor and true version. MariaDB and MySQL are distinct
	 * products in the lifecycle table, and MariaDB hides behind a "5.5.5-" prefix
	 * in replication-compatible server strings.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 * @return array{flavor: string, version: string}
	 */
	private function database_info( $wpdb ) {
		$server_info = '';
		if ( method_exists( $wpdb, 'db_server_info' ) ) {
			$server_info = (string) $wpdb->db_server_info();
		}

		if ( false !== stripos( $server_info, 'mariadb' ) ) {
			$version = '';
			if ( preg_match( '/(\d+\.\d+\.\d+)/', preg_replace( '/^5\.5\.5-/', '', $server_info ), $m ) ) {
				$version = $m[1];
			}
			return array(
				'flavor'  => 'mariadb',
				'version' => $version,
			);
		}

		return array(
			'flavor'  => 'mysql',
			'version' => (string) $wpdb->db_version(),
		);
	}

	/**
	 * Plugin counts by status, from the inventory collector.
	 *
	 * @return array
	 */
	private function plugin_counts() {
		$counts    = array(
			'active'   => 0,
			'inactive' => 0,
			'mu'       => 0,
			'dropin'   => 0,
		);
		$collector = new Auditra_Inventory_Collector();
		foreach ( $collector->collect() as $record ) {
			if ( isset( $counts[ $record['status'] ] ) ) {
				++$counts[ $record['status'] ];
			}
		}
		return $counts;
	}
}
