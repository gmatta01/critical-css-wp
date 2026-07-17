<?php
/**
 * Compatibility hooks for caching and optimization plugins.
 */
class Ccss_Compatibility {
	public function init() {
		add_action( 'rocket_after_clean_domain', array( $this, 'handle_cache_clear' ) );
		add_action( 'rocket_after_clean_post', array( $this, 'handle_cache_clear' ) );
		add_action( 'autoptimize_action_cachepurged', array( $this, 'handle_cache_clear' ) );
	}

	public function handle_cache_clear( $post_id = 0 ) {
		if ( $post_id ) {
			$post = get_post( $post_id );
			if ( $post ) {
				ccss_generate_for_post( $post_id, true );
			}
			return;
		}

		$posts = get_posts(
			array(
				'post_type'      => ccss_get_enabled_post_types(),
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'fields'         => 'ids',
			)
		);

		foreach ( $posts as $post_id ) {
			ccss_generate_for_post( $post_id, true );
		}
	}
}
