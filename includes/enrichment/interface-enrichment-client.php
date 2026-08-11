<?php
/**
 * Contract every enrichment client implements.
 *
 * @package Auditra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Auditra_Enrichment_Client_Interface {

	/**
	 * Short source name used in _meta.sources, e.g. 'wporg'.
	 *
	 * @return string
	 */
	public function name();
}
