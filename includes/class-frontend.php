<?php
/**
 * Frontend injection for the Critical CSS plugin.
 */
class Ccss_Frontend {
	private $defer_handles = array();

	public function init() {
		add_action( 'wp_head', array( $this, 'output_inline_css' ), 0 );
		add_action( 'wp_enqueue_scripts', array( $this, 'prepare_style_deferral' ), 100 );
		add_filter( 'style_loader_tag', array( $this, 'maybe_defer_style' ), 10, 4 );
	}

	public function output_inline_css() {
		if ( ! is_singular() ) {
			return;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return;
		}

		$css = get_post_meta( $post_id, '_critical_css', true );
		if ( empty( $css ) ) {
			return;
		}

		printf( '<style id="critical-css-inline" data-post-id="%d">%s</style>', (int) $post_id, $css );
	}

	public function prepare_style_deferral() {
		if ( ! is_singular() ) {
			return;
		}

		$post_id = get_the_ID();
		if ( ! $post_id || empty( get_post_meta( $post_id, '_critical_css', true ) ) ) {
			return;
		}

		if ( $this->should_skip_deferral() ) {
			return;
		}

		global $wp_styles;
		if ( ! $wp_styles ) {
			return;
		}

		foreach ( $wp_styles->queue as $handle ) {
			if ( $this->should_defer_handle( $handle ) ) {
				$this->defer_handles[ $handle ] = true;
			}
		}
	}

	public function maybe_defer_style( $tag, $handle, $href, $media ) {
		if ( empty( $this->defer_handles[ $handle ] ) ) {
			return $tag;
		}

		if ( 'all' !== $media ) {
			return $tag;
		}

		return str_replace( 'media="all"', 'media="print" onload="this.media=\'all\'"', $tag );
	}

	private function should_defer_handle( $handle ) {
		$skip_handles = array( 'dashicons', 'admin-bar', 'wp-block-library', 'wp-block-library-theme', 'classic-theme-styles', 'style' );
		if ( in_array( $handle, $skip_handles, true ) ) {
			return false;
		}

		return true;
	}

	private function should_skip_deferral() {
		return is_admin() || is_preview() || has_filter( 'autoptimize_filter_css_defer' );
	}
}
