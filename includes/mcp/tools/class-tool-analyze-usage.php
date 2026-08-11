<?php
/**
 * The analyze_usage tool.
 *
 * @package Auditra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content-feature usage per plugin. Narrow by design: a plugin registering no
 * content features is unmeasurable this way, not unused
 * (docs/DECISIONS.md 32).
 */
class Auditra_Tool_Analyze_Usage {

	const DEFAULT_MAX_POSTS = 20000;

	/**
	 * Registers the tool.
	 *
	 * @param Auditra_Tool_Registry $registry Tool registry.
	 * @return void
	 */
	public static function register( $registry ) {
		$registry->register(
			'analyze_usage',
			'Returns, per plugin, the content features it registers (shortcodes, blocks, post types, taxonomies) with real occurrence counts in post content, and a zero_content_usage flag when registered features appear nowhere. Measures content usage only: plugins working through hooks, filters, admin screens, or REST endpoints register no content features and are reported as not measurable, never as unused.',
			array(
				'type'       => 'object',
				'properties' => array(
					'slugs'     => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Plugin slugs to analyze. Omit for all plugins registering content features.',
					),
					'max_posts' => array(
						'type'        => 'integer',
						'default'     => self::DEFAULT_MAX_POSTS,
						'description' => 'Skip content scanning above this post count; counts return null with a stated reason rather than a misleading zero.',
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
		// Scalars only. An array or object inside the list must not reach
		// strval(), which warns and yields the literal string "Array"
		// (docs/DECISIONS.md 68).
		$requested = isset( $args['slugs'] ) && is_array( $args['slugs'] ) ? array_map( 'strval', array_filter( $args['slugs'], 'is_scalar' ) ) : null;
		$max_posts = isset( $args['max_posts'] ) && is_scalar( $args['max_posts'] ) ? max( 1, (int) $args['max_posts'] ) : self::DEFAULT_MAX_POSTS;

		$collector = new Auditra_Usage_Collector();
		$features  = $collector->features_by_plugin();

		$post_count = $collector->countable_posts();
		$skipped    = $post_count > $max_posts
			? sprintf( 'Content scanning skipped: the site has %d countable posts, above the max_posts limit of %d. Occurrence counts are null, not zero — nothing was counted.', $post_count, $max_posts )
			: null;

		// Gather every identifier across plugins so counting stays batched.
		$all_shortcodes = array();
		$all_blocks     = array();
		foreach ( $features['by_slug'] as $slug => $feature_set ) {
			$all_shortcodes = array_merge( $all_shortcodes, isset( $feature_set['shortcodes'] ) ? $feature_set['shortcodes'] : array() );
			$all_blocks     = array_merge( $all_blocks, isset( $feature_set['blocks'] ) ? $feature_set['blocks'] : array() );
		}
		$shortcode_counts = null === $skipped ? $collector->count_shortcodes( $all_shortcodes ) : array();
		$block_counts     = null === $skipped ? $collector->count_blocks( $all_blocks ) : array();

		$plugins = array();
		foreach ( $features['by_slug'] as $slug => $feature_set ) {
			if ( null !== $requested && ! in_array( $slug, $requested, true ) ) {
				continue;
			}
			$row = array( 'slug' => $slug );

			$any_usage   = false;
			$any_feature = false;
			$all_counted = true;

			if ( isset( $feature_set['shortcodes'] ) ) {
				$any_feature       = true;
				$row['shortcodes'] = array();
				foreach ( $feature_set['shortcodes'] as $tag ) {
					$occurrences         = null === $skipped ? $shortcode_counts[ $tag ] : null;
					$row['shortcodes'][] = array(
						'tag'         => $tag,
						'occurrences' => $occurrences,
					);
					$any_usage           = $any_usage || ( (int) $occurrences > 0 );
					$all_counted         = $all_counted && null !== $occurrences;
				}
			}
			if ( isset( $feature_set['blocks'] ) ) {
				$any_feature   = true;
				$row['blocks'] = array();
				foreach ( $feature_set['blocks'] as $name ) {
					$occurrences     = null === $skipped ? $block_counts[ $name ] : null;
					$row['blocks'][] = array(
						'name'        => $name,
						'occurrences' => $occurrences,
					);
					$any_usage       = $any_usage || ( (int) $occurrences > 0 );
					$all_counted     = $all_counted && null !== $occurrences;
				}
			}
			if ( isset( $feature_set['post_types'] ) ) {
				$any_feature       = true;
				$row['post_types'] = array();
				$type_counts       = $collector->count_post_types( $feature_set['post_types'] );
				foreach ( $type_counts as $type => $published ) {
					$row['post_types'][] = array(
						'post_type' => $type,
						'published' => $published,
					);
					$any_usage           = $any_usage || $published > 0;
				}
			}
			if ( isset( $feature_set['taxonomies'] ) ) {
				$any_feature       = true;
				$row['taxonomies'] = array();
				$term_counts       = $collector->count_taxonomies( $feature_set['taxonomies'] );
				foreach ( $term_counts as $taxonomy => $terms ) {
					$row['taxonomies'][] = array(
						'taxonomy' => $taxonomy,
						'terms'    => $terms,
					);
					$any_usage           = $any_usage || $terms > 0;
				}
			}

			// The flag applies only when features exist, every count actually
			// ran, and every count came back zero.
			if ( $any_feature && $all_counted && ! $any_usage ) {
				$row['zero_content_usage'] = true;
			}

			$plugins[] = $row;
		}

		// Plugins with no content features at all: named, with the reason,
		// never a zero.
		$inventory      = new Auditra_Inventory_Collector();
		$not_measurable = array();
		foreach ( $inventory->collect() as $record ) {
			if ( 'active' !== $record['status'] ) {
				continue;
			}
			if ( isset( $features['by_slug'][ $record['slug'] ] ) ) {
				continue;
			}
			if ( null !== $requested && ! in_array( $record['slug'], $requested, true ) ) {
				continue;
			}
			$not_measurable[] = $record['slug'];
		}

		$payload = array(
			'plugins'             => $plugins,
			'not_measurable'      => $not_measurable,
			'not_measurable_note' => 'These active plugins register no shortcodes, blocks, post types, or taxonomies. They are not unused — plugins working through hooks, filters, admin screens, REST endpoints, or template code are invisible to content-usage measurement and can be load-bearing.',
			'scan'                => array(
				'countable_posts' => $post_count,
				'max_posts'       => $max_posts,
			),
			'scope_note'          => 'Counts scan post_content only. Shortcodes can also live in widgets, options, post meta, and theme templates; a shortcode used in a template counts zero here while appearing on every page. Zero occurrences is not proof of absence.',
		);
		if ( null !== $skipped ) {
			$payload['scan']['skipped_reason'] = $skipped;
		}
		if ( array() !== $features['unattributed'] ) {
			$payload['unattributed_features'] = $features['unattributed'];
		}

		return Auditra_Tool_Registry::with_meta( $payload, count( $plugins ), count( $plugins ), false );
	}
}
