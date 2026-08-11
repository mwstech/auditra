<?php
/**
 * The analyze_autoload tool.
 *
 * @package Auditra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Autoloaded option weight attributed per plugin, with the unattributed
 * bucket always visible — especially when it is large.
 */
class Auditra_Tool_Analyze_Autoload {

	const DEFAULT_TOP = 20;
	const MAX_TOP     = 100;
	const LARGEST_N   = 10;

	/**
	 * Registers the tool.
	 *
	 * @param Auditra_Tool_Registry $registry Tool registry.
	 * @return void
	 */
	public static function register( $registry ) {
		$registry->register(
			'analyze_autoload',
			'Returns the weight of autoloaded options on this WordPress site: total autoloaded bytes and count, per-plugin attributed bytes and option counts each with an attribution confidence level (high = curated mapping, medium = prefix derived from the slug), the largest individual options with their owners, and an explicit unattributed bucket for everything whose owner is unknown. Answers "which plugin is bloating the options table".',
			array(
				'type'       => 'object',
				'properties' => array(
					'top' => array(
						'type'        => 'integer',
						'default'     => self::DEFAULT_TOP,
						'minimum'     => 1,
						'maximum'     => self::MAX_TOP,
						'description' => 'How many attributed owners to return, heaviest first.',
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
		$top = isset( $args['top'] ) && is_scalar( $args['top'] ) ? max( 1, min( self::MAX_TOP, (int) $args['top'] ) ) : self::DEFAULT_TOP;

		$inventory = new Auditra_Inventory_Collector();
		$slugs     = array();
		foreach ( $inventory->collect() as $record ) {
			$slugs[] = $record['slug'];
		}
		$attribution = new Auditra_Attribution( $slugs );

		$collector = new Auditra_Autoload_Collector();
		$options   = $collector->collect();

		$total_bytes = array_sum( $options );
		$total_count = count( $options );

		$owners        = array();
		$unattributed  = array(
			'bytes'   => 0,
			'options' => 0,
		);
		$largest       = array();
		$largest_taken = 0;

		foreach ( $options as $name => $size ) {
			$owner = $attribution->attribute( $name, 'option' );

			if ( $largest_taken < self::LARGEST_N ) {
				$largest[] = array(
					'option'     => $name,
					'size'       => Auditra_Tool_Registry::format_bytes( $size ),
					'owner'      => null !== $owner ? $owner['slug'] : null,
					'confidence' => null !== $owner ? $owner['confidence'] : null,
				);
				++$largest_taken;
			}

			if ( null === $owner ) {
				$unattributed['bytes']   += $size;
				$unattributed['options'] += 1;
				continue;
			}

			$slug = $owner['slug'];
			if ( ! isset( $owners[ $slug ] ) ) {
				$owners[ $slug ] = array(
					'slug'       => $slug,
					'bytes'      => 0,
					'options'    => 0,
					'confidence' => $owner['confidence'],
				);
			}
			$owners[ $slug ]['bytes']   += $size;
			$owners[ $slug ]['options'] += 1;
			// A mixed-tier owner is only as trustworthy as its weakest match.
			if ( 'medium' === $owner['confidence'] ) {
				$owners[ $slug ]['confidence'] = 'medium';
			}
		}

		usort(
			$owners,
			function ( $a, $b ) {
				return $b['bytes'] - $a['bytes'];
			}
		);
		$owner_total = count( $owners );
		$owners      = array_slice( array_values( $owners ), 0, $top );
		foreach ( $owners as $i => $owner ) {
			$owners[ $i ]['size'] = Auditra_Tool_Registry::format_bytes( $owner['bytes'] );
			unset( $owners[ $i ]['bytes'] );
		}

		$payload = array(
			'total'        => array(
				'size'    => Auditra_Tool_Registry::format_bytes( $total_bytes ),
				'bytes'   => $total_bytes,
				'options' => $total_count,
			),
			'owners'       => $owners,
			'largest'      => $largest,
			'unattributed' => array(
				'size'         => Auditra_Tool_Registry::format_bytes( $unattributed['bytes'] ),
				'bytes'        => $unattributed['bytes'],
				'options'      => $unattributed['options'],
				'pct_of_total' => $total_bytes > 0 ? round( 100 * $unattributed['bytes'] / $total_bytes, 1 ) : 0,
			),
		);

		return Auditra_Tool_Registry::with_meta( $payload, $owner_total, count( $owners ), count( $owners ) < $owner_total );
	}
}
