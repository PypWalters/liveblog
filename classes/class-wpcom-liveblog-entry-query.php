<?php

/**
 * Responsible for querying the Liveblog entries.
 */
class WPCOM_Liveblog_Entry_Query {

	public function __construct( $post_id, $key ) {
		$this->post_id = $post_id;
		$this->key     = $key;
	}

	/**
	 * Query the database for specific liveblog entries
	 *
	 * @param array $args the same args for the core `get_posts()`.
	 * @return array array of `WPCOM_Liveblog_Entry` objects with the found entries
	 */
	public function get( $args = array() ) {
		$defaults = array(
			'post_type'   => WPCOM_Liveblog_CPT::$cpt_slug,
			'post_parent' => $this->post_id,
			'orderby'     => 'post_date_gmt',
			'order'       => 'DESC',
		);

		$args    = wp_parse_args( $args, $defaults );

		error_log(var_export($args,true));
		$entries = get_posts( $args );
		error_log(var_export($entries,true));

		return self::entries_from_posts( $entries );
	}

	/**
	 * Get all of the liveblog entries
	 *
	 * @param array $args the same args for the core `get_posts()`
	 */
	public function get_all( $args = array() ) {
		// Due to liveblog lazy loading, duplicate entries may be displayed
		// if we actually pass the 'posts_per_page' argument to get_posts
		// in this class.
		$number = 0;
		if ( isset( $args['posts_per_page'] ) ) {
			$number = intval( $args['posts_per_page'] );
			unset( $args['posts_per_page'] );
		}

		return $this->get( $args );
	}

	public function count( $args = array() ) {
		return count( $this->get_all( $args ) );
	}

	public function get_by_id( $id ) {
		$entry = get_post( $id );
		/*
		 * TODO: Update comment for WP_Post
		 *
		 * When running tests, WP_Comment's comment_ID and comment_post_ID return strings. However, post_id
		 * returns a string (test_update_should_update_original_entry) or
		 * an integer (test_get_by_id_should_return_the_entry). For this to pass, coerce comment_post_ID to
		 * an integer before using a strict comparison.
		 */
		if ( intval( $entry->post_parent ) !== intval( $this->post_id ) ) {
			return null;
		}
		$entries = self::entries_from_posts( array( $entry ) );
		return $entries[0];
	}

	public function get_latest() {

		$entries = $this->get( array( 'posts_per_page' => 1 ) );

		if ( empty( $entries ) ) {
			return null;
		}

		return reset( $entries );
	}

	/**
	 * Returns latest entry id.
	 *
	 * @return int
	 */
	public function get_latest_id() {

		$latest = $this->get_latest();

		if ( is_null( $latest ) ) {
			return null;
		}

		if ( ! is_a( $latest, 'WPCOM_Liveblog_Entry' ) ) {
			return null;
		}

		return $latest->get_id();
	}

	public function get_latest_timestamp() {

		$latest = $this->get_latest();

		if ( is_null( $latest ) ) {
			return null;
		}

		if ( ! is_a( $latest, 'WPCOM_Liveblog_Entry' ) ) {
			return null;
		}

		return $latest->get_timestamp();
	}

	/**
	 * Get entries between two timestamps from a list of entries supplied.
	 *
	 * @param array $entries
	 * @param int   $start_timestamp
	 * @param int   $end_timestamp
	 * @return array
	 */
	public function find_between_timestamps( $entries, $start_timestamp, $end_timestamp ) {
		$entries_between = array();

		foreach ( (array) $entries as $entry ) {
			if ( $entry->get_timestamp() >= $start_timestamp && $entry->get_timestamp() <= $end_timestamp ) {
				$entries_between[] = $entry;
			}
		}

		return $entries_between;
	}

	/**
	 * Get entries between two timestamps from all entries.
	 *
	 * @param int $start_timestamp
	 * @param int $end_timestamp
	 * @return array
	 */
	public function get_between_timestamps( $start_timestamp, $end_timestamp ) {
		$all_entries = $this->get_all_entries_asc();
		return $this->find_between_timestamps( $all_entries, $start_timestamp, $end_timestamp );
	}

	public function has_any() {
		return (bool) $this->get();
	}

	public function get_all_entries_asc() {
		$cached_entries_asc_key = $this->key . '_entries_asc_' . $this->post_id;
		$cached_entries_asc     = wp_cache_get( $cached_entries_asc_key, 'liveblog' );
		if ( false !== $cached_entries_asc ) {
			return $cached_entries_asc;
		}
		$all_entries_asc = $this->get( array( 'order' => 'ASC' ) );
		wp_cache_set( $cached_entries_asc_key, $all_entries_asc, 'liveblog' );
		return $all_entries_asc;
	}

	public static function entries_from_posts( $entries = array() ) {

		if ( empty( $entries ) ) {
			return null;
		}

		return array_map( array( 'WPCOM_Liveblog_Entry', 'from_post' ), $entries );
	}

	public static function assoc_array_by_id( $entries ) {
		$result = array();

		foreach ( (array) $entries as $entry ) {
			$result[ $entry->get_id() ] = $entry;
		}

		return $result;
	}

	/**
	 * Returns the Liveblog entries between the two given (optional) timestamps.
	 *
	 * @param int $max_timestamp Maximum timestamp for the Liveblog entries.
	 * @param int $min_timestamp Minimum timestamp for the Liveblog entries.
	 *
	 * @return WPCOM_Liveblog_Entry[]
	 */
	public function get_for_lazyloading( $max_timestamp, $min_timestamp ) {

		$entries = $this->get_all();
		if ( ! $entries ) {
			return array();
		}

		if ( $max_timestamp ) {
			foreach ( $entries as $key => $entry ) {
				$timestamp = $entry->get_timestamp();

				if (
					( $max_timestamp && $timestamp >= $max_timestamp )
					|| ( $min_timestamp && $timestamp <= $min_timestamp )
				) {
					unset( $entries[ $key ] );
				}
			}
		}

		return $entries;
	}
}
