<?php
/**
 * Scheduler and processing logic for the Critical CSS plugin.
 */
class Ccss_Cron {
	private $api;

	public function __construct( Ccss_Api $api ) {
		$this->api = $api;
	}

	public function init() {
		add_action( 'ccss_run_scheduled_generation', array( $this, 'run_scheduled_generation' ) );
		add_action( 'save_post', array( $this, 'maybe_generate_on_publish' ), 10, 3 );
		add_action( 'admin_action_ccss_regenerate_single', array( $this, 'handle_single_regeneration' ) );
	}

	public function schedule_event() {
		$disabled = (bool) ccss_get_option( 'disable_cron', 0 );
		$this->clear_schedule();

		if ( $disabled ) {
			ccss_log( 'Cron-based generation is disabled by setting' );
			return;
		}

		$interval = ccss_get_option( 'interval', 'daily' );
		wp_schedule_event( time() + 60, $interval, 'ccss_run_scheduled_generation' );
	}

	public function clear_schedule() {
		wp_clear_scheduled_hook( 'ccss_run_scheduled_generation' );
	}

	public function maybe_generate_on_publish( $post_id, $post, $update ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! $post instanceof WP_Post ) {
			$post = get_post( $post_id );
		}

		if ( ! $post || ! ccss_is_enabled_post_type( $post->post_type ) ) {
			return;
		}

		if ( 'publish' !== $post->post_status ) {
			return;
		}

		ccss_generate_for_post( $post_id );
	}

	public function run_scheduled_generation() {
		$disabled = (bool) ccss_get_option( 'disable_cron', 0 );
		if ( $disabled ) {
			ccss_log( 'Scheduled generation skipped: disabled in settings' );
			return;
		}

		$post_types = ccss_get_enabled_post_types();
		$threshold_days = (int) ccss_get_option( 'rebuild_days', 7 );
		$cutoff = time() - ( $threshold_days * DAY_IN_SECONDS );
		$request_delay = max( 1, (int) ccss_get_option( 'request_delay', 3000 ) );

		$posts = get_posts(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'fields'         => 'ids',
			)
		);

		$processed = 0;
		foreach ( $posts as $post_id ) {
			if ( $processed >= 20 ) {
				break;
			}

			if ( ! $this->should_process_post( $post_id, $cutoff ) ) {
				continue;
			}

			if ( ccss_generate_for_post( $post_id ) ) {
				$processed++;
			}

			// Enforce delay between posts to avoid overwhelming the API server
			sleep( $request_delay / 1000 );
		}
	}

	public function handle_single_regeneration() {
		if ( ! isset( $_GET['post_id'] ) ) {
			return;
		}

		$post_id = absint( wp_unslash( $_GET['post_id'] ) );
		if ( ! $post_id || ! wp_verify_nonce( wp_unslash( $_GET['_wpnonce'] ), 'ccss_regenerate_single_' . $post_id ) ) {
			wp_die( __( 'Invalid request.', 'critical-css-wp' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have permission to do that.', 'critical-css-wp' ) );
		}

		ccss_generate_for_post( $post_id, true );
		wp_safe_redirect( add_query_arg( 'ccss_done', $post_id, admin_url( 'admin.php?page=critical-css-wp' ) ) );
		exit;
	}

	private function should_process_post( $post_id, $cutoff ) {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return false;
		}

		if ( ! ccss_is_enabled_post_type( $post->post_type ) ) {
			return false;
		}

		if ( ! empty( get_post_meta( $post_id, '_critical_css_error', true ) ) ) {
			return false;
		}

		$css = get_post_meta( $post_id, '_critical_css', true );
		if ( empty( $css ) ) {
			return true;
		}

		$generated_at = (int) get_post_meta( $post_id, '_critical_css_generated_at', true );
		if ( empty( $generated_at ) ) {
			return true;
		}

		return $generated_at <= $cutoff;
	}
}
