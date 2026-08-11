<?php
/**
 * Removes every option the plugin created.
 *
 * @package Auditra
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'auditra_enabled' );
delete_option( 'auditra_token' );
delete_option( 'auditra_enrich_store' );
delete_option( 'auditra_auth_log' );
delete_option( 'auditra_show_activation_notice' );
delete_transient( 'auditra_disk_footprint' );
delete_transient( 'auditra_rate_buckets' );
