<?php
/**
 * Usage collector: which content features plugins register, and whether any
 * content actually uses them.
 *
 * Measures content usage ONLY. Functionality delivered through hooks,
 * filters, admin screens, REST endpoints, or template code is invisible here,
 * and callers must present that limitation, not bury it
 * (docs/DECISIONS.md 32).
 *
 * @package Auditra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects registered shortcodes, blocks, post types, and taxonomies per
 * plugin, with occurrence counts in post content.
 */
class Auditra_Usage_Collector {

	/**
	 * Identifiers counted per query. Bounds query size on sites registering
	 * an unusual number of shortcodes or blocks.
	 */
	const COUNT_CHUNK = 50;

	/**
	 * Post type => registering file, captured by the listener.
	 *
	 * @var array<string, string>
	 */
	private static $post_type_files = array();

	/**
	 * Taxonomy => registering file, captured by the listener.
	 *
	 * @var array<string, string>
	 */
	private static $taxonomy_files = array();

	/**
	 * Registers the capture listeners. Gated to Auditra REST requests so
	 * ordinary site traffic pays nothing for the backtraces.
	 *
	 * @return void
	 */
	public static function listen() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( false === strpos( $uri, 'auditra/v1' ) ) {
			return;
		}

		add_action(
			'registered_post_type',
			function ( $post_type ) {
				self::$post_type_files[ $post_type ] = self::calling_file();
			}
		);
		add_action(
			'registered_taxonomy',
			function ( $taxonomy ) {
				self::$taxonomy_files[ $taxonomy ] = self::calling_file();
			}
		);
	}

	/**
	 * First file in the backtrace outside WordPress core and outside this
	 * plugin (our own listener closure is always frame zero). An empty return
	 * means the registration came from core itself and is not a plugin
	 * feature at all.
	 *
	 * @return string
	 */
	private static function calling_file() {
		$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 12 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Attribution capture on Auditra requests only, gated in listen().
		foreach ( $trace as $frame ) {
			if ( ! isset( $frame['file'] ) ) {
				continue;
			}
			$file = wp_normalize_path( $frame['file'] );
			if ( ! self::is_core_file( $file ) && 0 !== strpos( $file, wp_normalize_path( AUDITRA_PLUGIN_DIR ) ) ) {
				return $file;
			}
		}
		return '';
	}

	/**
	 * Whether a file belongs to WordPress core: wp-includes, wp-admin, or the
	 * bootstrap files sitting directly in the WordPress root (index.php,
	 * wp-settings.php, ...), which is where deep backtraces bottom out.
	 *
	 * @param string $file Normalized absolute path.
	 * @return bool
	 */
	private static function is_core_file( $file ) {
		$file = wp_normalize_path( $file );
		$root = wp_normalize_path( ABSPATH );
		if ( 0 === strpos( $file, wp_normalize_path( ABSPATH . WPINC ) ) || 0 === strpos( $file, wp_normalize_path( ABSPATH . 'wp-admin' ) ) ) {
			return true;
		}
		return trailingslashit( wp_normalize_path( dirname( $file ) ) ) === $root;
	}

	/**
	 * Registered content features grouped by owning plugin slug.
	 *
	 * @return array{by_slug: array, unattributed: array}
	 */
	public function features_by_plugin() {
		$by_slug      = array();
		$unattributed = array(
			'shortcodes' => array(),
			'blocks'     => array(),
			'post_types' => array(),
			'taxonomies' => array(),
		);

		// Shortcodes: reflection on the callback gives the declaring file,
		// which is accurate attribution — unlike prefix matching. Core's own
		// shortcodes (gallery, caption, ...) are core features, not plugin
		// features, and are skipped entirely.
		global $shortcode_tags;
		foreach ( (array) $shortcode_tags as $tag => $callback ) {
			$file = $this->callback_file( $callback );
			if ( '' !== $file && $this->is_core_file( $file ) ) {
				continue;
			}
			$slug = $this->slug_from_file( $file );
			if ( null === $slug ) {
				$unattributed['shortcodes'][] = (string) $tag;
				continue;
			}
			$by_slug[ $slug ]['shortcodes'][] = (string) $tag;
		}

		// Blocks: namespace matching only — a weaker signal than reflection,
		// reported with lower confidence downstream.
		if ( class_exists( 'WP_Block_Type_Registry' ) ) {
			foreach ( WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $block ) {
				$namespace = strtok( (string) $name, '/' );
				if ( 'core' === $namespace ) {
					continue;
				}
				$slug = $this->slug_from_namespace( $namespace );
				if ( null === $slug ) {
					$unattributed['blocks'][] = (string) $name;
					continue;
				}
				$by_slug[ $slug ]['blocks'][] = (string) $name;
			}
		}

		// Post types and taxonomies: files captured by the gated listener.
		// An empty file means core registered it; core built-ins are not
		// plugin features and are skipped, not "unattributed".
		foreach ( self::$post_type_files as $post_type => $file ) {
			if ( '' === $file ) {
				continue;
			}
			$slug = $this->slug_from_file( $file );
			if ( null === $slug ) {
				$unattributed['post_types'][] = (string) $post_type;
				continue;
			}
			$by_slug[ $slug ]['post_types'][] = (string) $post_type;
		}
		foreach ( self::$taxonomy_files as $taxonomy => $file ) {
			if ( '' === $file ) {
				continue;
			}
			$slug = $this->slug_from_file( $file );
			if ( null === $slug ) {
				$unattributed['taxonomies'][] = (string) $taxonomy;
				continue;
			}
			$by_slug[ $slug ]['taxonomies'][] = (string) $taxonomy;
		}

		return array(
			'by_slug'      => $by_slug,
			'unattributed' => array_filter( $unattributed ),
		);
	}

	/**
	 * Number of countable posts (revisions and auto-drafts excluded).
	 *
	 * @return int
	 */
	public function countable_posts() {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only count.
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type != 'revision' AND post_status != 'auto-draft'"
		);
	}

	/**
	 * Occurrence counts for a set of shortcode tags, in one query.
	 *
	 * @param string[] $tags Shortcode tags.
	 * @return array<string, int>
	 */
	public function count_shortcodes( $tags ) {
		return $this->count_patterns(
			$tags,
			function ( $tag ) {
				global $wpdb;
				return array( '%[' . $wpdb->esc_like( $tag ) . ']%', '%[' . $wpdb->esc_like( $tag ) . ' %' );
			}
		);
	}

	/**
	 * Occurrence counts for a set of block names, in one query.
	 *
	 * @param string[] $names Block names (namespace/block).
	 * @return array<string, int>
	 */
	public function count_blocks( $names ) {
		return $this->count_patterns(
			$names,
			function ( $name ) {
				global $wpdb;
				return array( '%<!-- wp:' . $wpdb->esc_like( $name ) . ' %', '%<!-- wp:' . $wpdb->esc_like( $name ) . '%' );
			}
		);
	}

	/**
	 * Batched LIKE counting: one query for all identifiers, not one each.
	 *
	 * @param string[] $identifiers      Identifiers to count.
	 * @param callable $pattern_builder  Returns LIKE patterns for one identifier.
	 * @return array<string, int>
	 */
	private function count_patterns( $identifiers, $pattern_builder ) {
		global $wpdb;

		$identifiers = array_values( $identifiers );
		if ( array() === $identifiers ) {
			return array();
		}

		$counts = array();

		// Chunked so a site registering hundreds of shortcodes cannot build
		// an unbounded query. Every SELECT fragment below is a fixed literal
		// with %s placeholders and a loop-index alias; no caller-supplied
		// value is ever concatenated into SQL.
		foreach ( array_chunk( $identifiers, self::COUNT_CHUNK ) as $chunk_index => $chunk ) {
			$selects = array();
			$values  = array();
			foreach ( $chunk as $i => $identifier ) {
				$clauses = array();
				foreach ( (array) call_user_func( $pattern_builder, $identifier ) as $pattern ) {
					$clauses[] = 'post_content LIKE %s';
					$values[]  = $pattern;
				}
				// A builder that yields no patterns would emit
				// "SUM(CASE WHEN THEN ...)" — a syntax error and a
				// prepare()-without-placeholders notice. No current builder
				// does; the guard is here so a future one cannot.
				if ( array() === $clauses ) {
					continue;
				}
				$selects[] = 'SUM(CASE WHEN ' . implode( ' OR ', $clauses ) . ' THEN 1 ELSE 0 END) AS c' . (int) $i;
			}
			if ( array() === $selects ) {
				continue;
			}

			$sql = 'SELECT ' . implode( ', ', $selects ) . " FROM {$wpdb->posts} WHERE post_type != 'revision' AND post_status != 'auto-draft'";
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $sql is assembled from fixed literals and %s placeholders only; all values are bound through prepare().
			$row = $wpdb->get_row( $wpdb->prepare( $sql, $values ), ARRAY_A );

			foreach ( $chunk as $i => $identifier ) {
				$counts[ $identifier ] = isset( $row[ 'c' . (int) $i ] ) ? (int) $row[ 'c' . (int) $i ] : 0;
			}
			unset( $chunk_index );
		}

		return $counts;
	}

	/**
	 * Published-post counts for post types.
	 *
	 * @param string[] $post_types Post types.
	 * @return array<string, int>
	 */
	public function count_post_types( $post_types ) {
		$counts = array();
		foreach ( $post_types as $post_type ) {
			$count                = wp_count_posts( $post_type );
			$counts[ $post_type ] = isset( $count->publish ) ? (int) $count->publish : 0;
		}
		return $counts;
	}

	/**
	 * Term counts for taxonomies.
	 *
	 * @param string[] $taxonomies Taxonomies.
	 * @return array<string, int>
	 */
	public function count_taxonomies( $taxonomies ) {
		$counts = array();
		foreach ( $taxonomies as $taxonomy ) {
			$count               = wp_count_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) ); // phpcs:ignore Universal.Arrays.MixedKeyedUnkeyedArray, WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$counts[ $taxonomy ] = is_wp_error( $count ) ? 0 : (int) $count;
		}
		return $counts;
	}

	/**
	 * The declaring file of any callable, or '' when reflection fails.
	 * Never fatals on exotic callbacks.
	 *
	 * @param mixed $callback Callback in any registerable form.
	 * @return string
	 */
	private function callback_file( $callback ) {
		try {
			if ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
				$callback = explode( '::', $callback, 2 );
			}
			if ( is_array( $callback ) && 2 === count( $callback ) ) {
				$reflection = new ReflectionMethod( $callback[0], $callback[1] );
			} elseif ( is_object( $callback ) && ! ( $callback instanceof Closure ) ) {
				$reflection = new ReflectionMethod( $callback, '__invoke' );
			} elseif ( is_callable( $callback ) ) {
				$reflection = new ReflectionFunction( $callback );
			} else {
				return '';
			}
			return (string) $reflection->getFileName();
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Maps an absolute file path to the plugin that ships it.
	 *
	 * @param string $file Absolute path.
	 * @return string|null Slug, or null when not inside the plugins directory.
	 */
	private function slug_from_file( $file ) {
		if ( '' === $file ) {
			return null;
		}
		$file        = wp_normalize_path( $file );
		$plugins_dir = trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );
		if ( 0 !== strpos( $file, $plugins_dir ) ) {
			return null;
		}
		$relative = substr( $file, strlen( $plugins_dir ) );
		$slash    = strpos( $relative, '/' );
		return false === $slash ? basename( $relative, '.php' ) : substr( $relative, 0, $slash );
	}

	/**
	 * Maps a block namespace to an installed plugin slug. Namespace equality
	 * or underscore/hyphen-normalized equality only; anything cleverer risks
	 * wrong owners.
	 *
	 * @param string $block_namespace Block namespace.
	 * @return string|null
	 */
	private function slug_from_namespace( $block_namespace ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$slugs      = array();
		$normalized = str_replace( '_', '-', strtolower( $block_namespace ) );
		foreach ( array_keys( get_plugins() ) as $plugin_file ) {
			$slug    = '.' === dirname( $plugin_file ) ? basename( $plugin_file, '.php' ) : dirname( $plugin_file );
			$slugs[] = $slug;
			if ( strtolower( $slug ) === $normalized ) {
				return $slug;
			}
		}

		// Second chance through the curated prefix map: the aioseo namespace
		// belongs to all-in-one-seo-pack, which no slug comparison derives.
		$attribution = new Auditra_Attribution( $slugs );
		$owner       = $attribution->attribute( str_replace( '-', '_', strtolower( $block_namespace ) ) . '_', 'namespace' );
		if ( null !== $owner && 'high' === $owner['confidence'] && $attribution->is_installed( $owner['slug'] ) ) {
			return $owner['slug'];
		}
		return null;
	}
}
