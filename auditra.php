<?php
/**
 * Plugin Name: Auditra
 * Plugin URI: https://www.macronimous.com/free-tools/auditra/
 * Description: Turns this site into a read-only MCP server so AI clients can inspect and reason about its plugin estate.
 * Version: 1.0.2
 * Author: Macronimous Web Solutions
 * Author URI: https://www.macronimous.com/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: auditra
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * Multisite note: v1 operates on the individual site it runs on and is not
 * network-tested. On multisite, managing Auditra requires a network admin
 * (manage_network_options). See docs/DECISIONS.md 35.
 *
 * @package Auditra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AUDITRA_VERSION', '1.0.2' );
define( 'AUDITRA_PLUGIN_FILE', __FILE__ );
define( 'AUDITRA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once AUDITRA_PLUGIN_DIR . 'includes/class-plugin.php';

/**
 * Flags a fresh activation so the admin gets one orienting notice.
 *
 * The plugin deliberately does nothing until an administrator enables the
 * endpoint, which without this notice reads as a broken install.
 *
 * @return void
 */
function auditra_on_activation() {
	add_option( 'auditra_show_activation_notice', '1', '', false );
}
register_activation_hook( __FILE__, 'auditra_on_activation' );

Auditra_Plugin::instance();
