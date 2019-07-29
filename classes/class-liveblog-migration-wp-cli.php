<?php
/**
 * WPCLI Utilities for live blog migration
 */

/**
 * Holds methods for WP_CLI command related to live blog migration
 * Class LivePress_Migration_CLI
 */
class Liveblog_Migration_WP_CLI extends WPCOM_VIP_CLI_Command {

	public static $cpt_slug;

	/*
	 * Customize this to get livepress blogs for your site
	 *
	 * must return an array of post IDs
	 */
	public static function get_liveblog_ids() {
		global $wpdb;

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT ID FROM $wpdb->posts WHERE post_type = %s AND post_status='publish'",
				self::$cpt_slug
			)
		);
	}

	/**
	 * Convert one live blog from comments to posts
	 *  - Per post:
	 *  - content (comment)
	 *  - timestamp (comment)
	 *  - headline (commentmeta)
	 *  - authors (commentmeta)
	 *  - livepress comment ID (commentmeta)
	 *
	 * ## EXAMPLES
	 *
	 *     wp livepress convert-liveblog --id=12345
	 *     wp livepress convert-liveblog --id=12345 --dry-run
	 *     wp livepress convert-liveblog --id=12345 --dry-run=false
	 *     wp livepress convert-liveblog --id=12345 --dry-run=false --delete=false
	 *     wp livepress convert-liveblog --id=12345 --dry-run=false --delete=true
	 *
	 * @synposis --id [--dry-run] [--delete]
	 *
	 * @subcommand convert-liveblog
	 */
	public function convert_liveblog( $args, $assoc_args ) {

		if ( ! isset( $assoc_args['id'] ) && ! intval( $assoc_args['id'] ) ) {
			WP_CLI::error( 'You must supply a post ID to convert.' );
			exit;
		}
		$liveblog_id = intval( $assoc_args['id'] );

		if ( isset( $assoc_args['dry-run'] ) && 'false' === $assoc_args['dry-run'] ) {
			$dry_run = false;
		} else {
			$dry_run = true;
			WP_CLI::line( '!!! Doing a dry-run, no posts will be updated.' );
		}

		if ( isset( $assoc_args['delete'] ) && 'true' === $assoc_args['delete'] ) {
			$delete = true;
		} else {
			$delete = false;
			WP_CLI::line( '!!! No old posts will be deleted.' );
		}

		global $wpdb;
		global $coauthors_plus;

		$live_blog_comments = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT ID, comment_content, comment_date, comment_date_gmt
				FROM $wpdb->comments
				WHERE comment_type = 'liveblog' AND comment_post_ID = %d",
				$liveblog_id
			)
		);
		if ( $live_blog_comments ) {
			WP_CLI::line( 'Found ' . count( $live_blog_comments ) . ' comments' );
		} else {
			WP_CLI::warning( 'No comments found for post ID ' . $liveblog_id . '. Skipping.' );
			return;
		}

		$post_count = 0;
		foreach ( $live_blog_comments as $lb_comment ) {
			WP_CLI::line( 'Found live blog post ID ' . $lb_comment->ID );
			$content = trim( $lb_comment->comment_content );
			if ( ! $content ) {
				WP_CLI::line( 'Skipping blank post ' . $lb_comment->ID );
				continue;
			}

			$headline     = get_comment_meta( $lb_comment->ID, 'liveblog_headline', true );
			$authors      = get_comment_meta( $lb_comment->ID, 'liveblog_contributors', true );
			$livepress_id = get_comment_meta( $lb_comment->ID, 'livepress_id', true );

			if ( ! $dry_run ) {
				$new_entry_id = wp_insert_post(
					[
						'post_parent'   => $liveblog_id,
						'post_content'  => $content,
						'post_title'    => $headline,
						'post_type'     => self::$cpt_slug,
						'post_status'   => 'publish',
						'post_date'     => $lb_comment->comment_date,
						'post_date_gmt' => $lb_comment->comment_date_gmt,
					]
				);

				if ( $new_entry_id ) {
					WP_CLI::line( 'Inserted live blog comment ID ' . $lb_comment->ID . ' as post ID ' . $new_entry_id );

					$coauthors_plus->add_coauthors( $new_entry_id, $authors, false, 'id' );

					update_post_meta( $new_entry_id, 'livepress_id', $livepress_id );
					update_post_meta( $new_entry_id, 'liveblog_id', $lb_comment->ID );

					// delete post. wp_delete_post() also deletes postmeta.
					if ( $delete ) {
						if ( false === wp_delete_comment( $lb_comment->ID, true ) ) {
							WP_CLI::error( 'Failed to delete comment ' . $lb_comment->ID );
						} else {
							WP_CLI::line( 'Deleted comment ' . $lb_comment->ID );
						}
					}
				} else {
					WP_CLI::error( 'Failed to create post for liveblog comment ID ' . $lb_comment->ID );
				}
			}

			$post_count++;
		}

		self::convert_liveblog_event_data( $liveblog_id, $dry_run );

		// delete postmeta: _livepress_feed_link, livepress_alert_sent, _livepress_post_header_enabled, _livepress_live_status, _livepress_status_uuid, _livepress_update_meta, livepress_feed_order, _livepress_feed_link
		if ( ! $dry_run ) {
			if ( $delete ) {
				delete_post_meta( $liveblog_id, '_livepress_feed_link' );
				delete_post_meta( $liveblog_id, 'livepress_alert_sent' );
				delete_post_meta( $liveblog_id, '_livepress_post_header_enabled' );
				delete_post_meta( $liveblog_id, '_livepress_live_status' );
				delete_post_meta( $liveblog_id, '_livepress_update_meta' );
				delete_post_meta( $liveblog_id, '_livepress_status_uuid' );
				delete_post_meta( $liveblog_id, '_livepress_json_ld' );
				delete_post_meta( $liveblog_id, 'livepress_feed_order' );

				// delete _livepress_lp_shortlink_*
				$meta_count = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->prepare(
						"DELETE FROM $wpdb->postmeta WHERE post_id = %d AND meta_key LIKE %s",
						$liveblog_id,
						'_livepress_lp_shortlink%'
					)
				);
				if ( $meta_count ) {
					WP_CLI::line( 'Removed ' . $meta_count . ' shortlinks for liveblog ID' . $liveblog_id );
				}

				// set the content to blank -- some old sticky posts used the parent post
				wp_update_post(
					[
						'ID'           => $liveblog_id,
						'post_content' => '',
					]
				);
			}

			WP_CLI::success( 'Converted live blog ID ' . $liveblog_id );
		}
	}

	/**
	 * Migrate all live blog comments to posts
	 *
	 * ## EXAMPLES
	 *
	 *     wp liveblog migrate
	 *     wp liveblog migrate --dry-run
	 *     wp liveblog migrate --dry-run=false --delete=true
	 *
	 * @synposis --dry-run [--delete]
	 *
	 * @subcommand migrate
	 */
	public function migrate_liveblog( $args, $assoc_args ) {
		if ( isset( $assoc_args['dry-run'] ) && 'false' === $assoc_args['dry-run'] ) {
			$dry_run = 'false';
		} else {
			$dry_run = 'true';
			WP_CLI::line( '!!! Doing a dry-run, no posts will be updated.' );
		}

		if ( isset( $assoc_args['delete'] ) && 'true' === $assoc_args['delete'] ) {
			$delete = 'true';
		} else {
			$delete = 'false';
			WP_CLI::line( '!!! No old posts will be deleted.' );
		}

		self::$cpt_slug = apply_filters( 'wpcom_liveblog_cpt_slug', WPCOM_Liveblog_CPT::DEFAULT_CPT_SLUG );

		$live_blogs = self::get_liveblog_ids();
		WP_CLI::line( 'Found ' . count( $live_blogs ) . ' live blogs' );

		$blog_count = 0;
		foreach ( $live_blogs as $lb ) {
			WP_CLI::line( 'Found live blog ID ' . $lb->ID );
			$blog_count++;

			self::convert_live_blog(
				[],
				[
					'id'      => $lb->ID,
					'dry-run' => $dry_run,
					'delete'  => $delete,
				]
			);

			if ( 0 === $blog_count % 100 ) {
				WP_CLI::line( 'sleeping' );
				$this->stop_the_insanity();
				sleep( 5 );
			}
		}
	}
}

WP_CLI::add_command( 'liveblog', 'Liveblog_Migration_WP_CLI' );
