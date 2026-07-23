<?php
/**
 * Frontend injection for the Critical CSS plugin.
 */
class Ccss_Frontend {
	private $defer_handles = array();

	public function init() {
		add_action( 'wp_head', array( $this, 'output_inline_css' ), 0 );
		add_action( 'wp_print_styles', array( $this, 'prepare_style_deferral' ), PHP_INT_MAX );
		add_filter( 'style_loader_tag', array( $this, 'maybe_defer_style' ), 10, 4 );
		add_action( 'wp_footer', array( $this, 'output_debug_info' ), 999 );
	}

	public function output_inline_css() {
		if ( ! is_singular() || $this->should_bypass() ) {
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

	/**
	 * Output debug info when ?ccss_debug=1 is in the URL.
	 */
	public function output_debug_info() {
		if ( ! isset( $_GET['ccss_debug'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return;
		}

		$has_css  = ! empty( get_post_meta( $post_id, '_critical_css', true ) );
		$gen_time = (int) get_post_meta( $post_id, '_critical_css_generated_at', true );
		$css_size = ccss_get_css_size_kb( $post_id );
		$deferred = count( $this->defer_handles );

		echo "\n<!-- Critical CSS Debug:\n";
		echo '  Post ID: ' . (int) $post_id . "\n";
		echo '  Critical CSS: ' . ( $has_css ? 'YES (' . $css_size . ' KB)' : 'NO' ) . "\n";
		if ( $gen_time ) {
			echo '  Generated: ' . esc_html( human_time_diff( $gen_time, time() ) ) . " ago\n";
		}
		echo '  Styles deferred: ' . (int) $deferred . "\n";
		echo "-->\n";
	}

	public function prepare_style_deferral() {
		if ( ! is_singular() || $this->should_bypass() ) {
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

		// Only defer styles with media="all" or no explicit media.
		if ( '' !== $media && 'all' !== $media ) {
			return $tag;
		}

		// Add media="print" with onload to make it non-render-blocking.
		if ( false !== strpos( $tag, "media='all'" ) ) {
			$deferred = str_replace( "media='all'", "media='print' onload=\"this.media='all'\"", $tag );
		} elseif ( false !== strpos( $tag, 'media="all"' ) ) {
			$deferred = str_replace( 'media="all"', 'media="print" onload="this.media=\'all\'"', $tag );
		} else {
			$deferred = str_replace( '<link ', '<link media="print" onload="this.media=\'all\'" ', $tag );
		}

		// Noscript fallback for users without JavaScript.
		$deferred .= "\n" . '<noscript><link rel="stylesheet" href="' . esc_url( $href ) . '" media="all" /></noscript>';

		return $deferred;
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

	/**
	 * Generation crawls use ?ccss_bypass=1 so previously injected critical CSS
	 * is not re-ingested (which caused multi-MB compounding on regenerate).
	 */
	private function should_bypass() {
		return isset( $_GET['ccss_bypass'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}
}
