<?php
/**
 * Plugin bootstrap.
 *
 * @package Auditra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the plugin together. Nothing here touches WordPress state.
 */
class Auditra_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Auditra_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Returns the shared instance, booting it on first call.
	 *
	 * @return Auditra_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Loads components and registers hooks.
	 *
	 * @return void
	 */
	private function boot() {
		require_once AUDITRA_PLUGIN_DIR . 'includes/class-security.php';
		require_once AUDITRA_PLUGIN_DIR . 'includes/mcp/class-tool-registry.php';
		require_once AUDITRA_PLUGIN_DIR . 'includes/mcp/class-mcp-server.php';
		require_once AUDITRA_PLUGIN_DIR . 'includes/collectors/class-inventory.php';
		require_once AUDITRA_PLUGIN_DIR . 'includes/collectors/class-site-context.php';
		require_once AUDITRA_PLUGIN_DIR . 'includes/collectors/class-attribution.php';
		require_once AUDITRA_PLUGIN_DIR . 'includes/collectors/class-autoload.php';
		require_once AUDITRA_PLUGIN_DIR . 'includes/collectors/class-cron.php';
		require_once AUDITRA_PLUGIN_DIR . 'includes/collectors/class-database.php';
		require_once AUDITRA_PLUGIN_DIR . 'includes/collectors/class-usage.php';

		// Capture CPT/taxonomy registration files; no-op off Auditra requests.
		Auditra_Usage_Collector::listen();
		require_once AUDITRA_PLUGIN_DIR . 'includes/enrichment/interface-enrichment-client.php';
		require_once AUDITRA_PLUGIN_DIR . 'includes/enrichment/interface-vulnerability-provider.php';
		require_once AUDITRA_PLUGIN_DIR . 'includes/enrichment/class-enrichment-manager.php';
		require_once AUDITRA_PLUGIN_DIR . 'includes/enrichment/class-lifecycle-client.php';
		require_once AUDITRA_PLUGIN_DIR . 'includes/enrichment/class-wpvulnerability-client.php';
		require_once AUDITRA_PLUGIN_DIR . 'includes/enrichment/class-wporg-client.php';

		$server = new Auditra_MCP_Server( new Auditra_Token_Auth(), $this->build_registry() );
		add_action( 'rest_api_init', array( $server, 'register_routes' ) );
		add_filter( 'rest_pre_serve_request', array( $server, 'serve_empty_accepted_response' ), 10, 4 );

		if ( is_admin() ) {
			require_once AUDITRA_PLUGIN_DIR . 'includes/class-settings.php';
			$settings = new Auditra_Settings();
			$settings->register();
		}
	}

	/**
	 * Builds the tool registry for this phase.
	 *
	 * @return Auditra_Tool_Registry
	 */
	private function build_registry() {
		$registry = new Auditra_Tool_Registry();
		$registry->load_tools_from( AUDITRA_PLUGIN_DIR . 'includes/mcp/tools' );
		return $registry;
	}
}
