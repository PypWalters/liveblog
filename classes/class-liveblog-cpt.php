<?php
/**
 * Live Blog Custom Post Type
 */

/**
 * Register and handle the "Live Blog" Custom Post Type
 */
class Liveblog_CPT {

	const DEFAULT_CPT_SLUG = 'liveblog';

	public static $cpt_slug;

	/**
	 * Register the Live Blog post type
	 *
	 * @return object|WP_Error
	 */
	public static function register_post_type() {
		self::$cpt_slug = apply_filters( 'liveblog_cpt_slug', self::DEFAULT_CPT_SLUG );

		add_action( 'before_delete_post', [ __CLASS__, 'delete_children' ] );
		add_action( 'pre_get_posts', [ __CLASS__, 'filter_children_from_query' ] );
		add_filter( 'parse_query', [ __CLASS__, 'hierarchical_posts_filter' ] );
		add_filter( 'post_type_link', [ __CLASS__, 'post_type_link' ], 10, 4 );
		add_filter( self::$cpt_slug . '_rewrite_rules', [ __CLASS__, 'rewrite_rules' ] );

		return register_post_type(
			self::$cpt_slug,
			[
				'labels'    => [
					'name'          => 'Live blogs',
					'singular_name' => 'Live blog',
				],
				'menu_icon' => 'dashicons-admin-post',
			]
		);
	}

	/**
	 * Remove nested child posts when a parent is removed.
	 *
	 * @param int $parent ID of the parent post being deleted
	 */
	public static function delete_children( $parent ) {

		// Remove the query filter.
		remove_filter( 'parse_query', [ __CLASS__, 'hierarchical_posts_filter' ] );
		remove_action( 'pre_get_posts', [ __CLASS__, 'filter_children_from_query' ] );
		$parent = (int) $parent; // Force a cast as an integer.

		$post = get_post( $parent );

		// Only delete children of top-level posts.
		if ( 0 !== $post->post_parent || self::$cpt_slug !== $post->post_type ) {
			return;
		}

		// Get all children
		$children = new WP_Query(
			[
				'post_type'        => self::$cpt_slug,
				'post_parent'      => $parent,
				'suppress_filters' => false,
			]
		);

		// Remove the action so it doesn't fire again
		remove_action( 'before_delete_post', [ __CLASS__, 'delete_children' ] );

		if ( $children->have_posts() ) {
			foreach ( $children->posts as $child ) {
				// Never delete top level posts!
				if ( 0 === (int) $child->post_parent ) {
					continue;
				}
				wp_delete_post( $child->ID, true );
			}
		}

		add_action( 'before_delete_post', [ __CLASS__, 'delete_children' ] );
		add_action( 'pre_get_posts', [ __CLASS__, 'filter_children_from_query' ] );
		add_filter( 'parse_query', [ __CLASS__, 'hierarchical_posts_filter' ] );

	}

	public static function filter_children_from_query( $query ) {

		$post_type = $query->get( 'post_type' );

		// only applies to indexes and post format
		if ( is_author() || is_search() || is_feed() || ( ( $query->is_home() || $query->is_archive() ) && ( empty( $post_type ) || in_array( $post_type, [ self::$cpt_slug ], true ) ) ) ) {
			$parent = $query->get( 'post_parent' );
			if ( empty( $parent ) ) {
				$query->set( 'post_parent', 0 );
			}
		}

	}

	/**
	 * Posts cannot typically have parent-child relationships.
	 *
	 * Our updates, however, are all "owned" by a traditional
	 * post so we know how to lump things together on the front-end
	 * and in the post editor.
	 *
	 * @param WP_Query $query Current query.
	 *
	 * @return WP_Query
	 */
	public static function hierarchical_posts_filter( $query ) {
		global $pagenow, $typenow;

		if ( is_admin() && 'edit.php' === $pagenow && in_array( $typenow, [ self::$cpt_slug ], true ) ) {
			$query->query_vars['post_parent'] = 0;
		}

		return $query;
	}

	/**
	 * Permalinks for child posts should use IDs, not slugs.
	 *
	 * @param string  $post_link The post's permalink.
	 * @param WP_Post $post      The post in question.
	 * @param bool    $leavename Whether to keep the post name.
	 * @param bool    $sample    Is it a sample permalink.
	 *
	 * @return string
	 */
	public static function post_type_link( $post_link, $post, $leavename, $sample ) {
		if ( self::$cpt_slug !== $post->post_type || 0 === $post->post_parent ) {
			return $post_link;
		}

		return get_permalink( $post->post_parent ) . "$post->ID/";
	}

	/**
	 * Modifies the rewrite rules for the live blog CPT.
	 *
	 * @param array $rules The Rules.
	 *
	 * @return array
	 */
	public static function rewrite_rules( $rules ) {

		// Unset the broken rule.
		unset( $rules['live-blog/(.+?)(?:/([0-9]+))?/?$'] );

		// Matches live-blog/post-name/
		$rules['live-blog/([^/]+)/?$'] = 'index.php?' .  self::$cpt_slug . '=$matches[1]';

		// matches live-blog/post-name/12345/ -- where 12345 is a post ID from liveblog
		$rules['live-blog/[^/]+/([0-9]+)/?$'] = 'index.php?post_type=' . self::$cpt_slug . '&p=$matches[1]';

		return $rules;
	}
}

add_action( 'init', [ 'Liveblog_CPT', 'register_post_type' ] );
