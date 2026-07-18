<?php
/**
 * Administrative UI for the Critical CSS plugin.
 *
 * Provides a top-level menu with two tabs:
 *   - Pages (default): progress bar, pages table, bulk & per-row generation.
 *   - Settings: API URL, post types, cron interval, rebuild threshold.
 */
class Ccss_Admin {
	private $api;

	public function __construct( Ccss_Api $api ) {
		$this->api = $api;
	}

	public function init() {
		add_action( 'admin_menu', array( $this, 'add_menu_pages' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( CCSS_PLUGIN_FILE ), array( $this, 'plugin_action_links' ) );
		add_action( 'update_option_ccss_settings', array( $this, 'refresh_schedule' ) );

		// AJAX for single generation from the pages table.
		add_action( 'wp_ajax_ccss_generate_single', array( $this, 'ajax_generate_single' ) );
		add_action( 'wp_ajax_ccss_bulk_generate', array( $this, 'ajax_bulk_generate' ) );

		// Post list table enhancements.
		add_filter( 'post_row_actions', array( $this, 'add_single_action' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'add_single_action' ), 10, 2 );

		$post_types = ccss_get_enabled_post_types();
		foreach ( $post_types as $post_type ) {
			add_filter( "manage_{$post_type}_posts_columns", array( $this, 'add_column' ) );
			add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
			add_filter( "bulk_actions-edit-{$post_type}", array( $this, 'register_bulk_action' ) );
			add_filter( "handle_bulk_actions-edit-{$post_type}", array( $this, 'handle_bulk_action' ), 10, 3 );
		}
	}

	// ─── Menu ──────────────────────────────────────────────────────────

	public function add_menu_pages() {
		add_menu_page(
			__( 'Critical CSS', 'critical-css-wp' ),
			__( 'Critical CSS', 'critical-css-wp' ),
			'manage_options',
			'critical-css-wp',
			array( $this, 'render_pages_tab' ),
			'dashicons-performance',
			30
		);

		add_submenu_page(
			'critical-css-wp',
			__( 'Pages', 'critical-css-wp' ),
			__( 'Pages', 'critical-css-wp' ),
			'manage_options',
			'critical-css-wp',
			array( $this, 'render_pages_tab' )
		);

		add_submenu_page(
			'critical-css-wp',
			__( 'Settings', 'critical-css-wp' ),
			__( 'Settings', 'critical-css-wp' ),
			'manage_options',
			'critical-css-wp-settings',
			array( $this, 'render_settings_tab' )
		);
	}

	// ─── Settings ──────────────────────────────────────────────────────

	public function register_settings() {
		register_setting( 'ccss_settings_group', 'ccss_settings', array( $this, 'sanitize_settings' ) );
	}

	public function sanitize_settings( $input ) {
		$sanitized = array();
		$sanitized['api_url']         = esc_url_raw( $input['api_url'] ?? ccss_get_option( 'api_url' ) );
		$sanitized['api_key']         = sanitize_text_field( $input['api_key'] ?? '' );
		$sanitized['public_base_url']  = esc_url_raw( $input['public_base_url'] ?? '', array( 'http', 'https' ) );
		$sanitized['post_types']       = array_filter( array_map( 'sanitize_key', (array) ( $input['post_types'] ?? array() ) ) );
		$sanitized['interval']         = in_array( $input['interval'] ?? 'daily', array( 'hourly', 'twicedaily', 'daily', 'weekly' ), true ) ? $input['interval'] : 'daily';
		$sanitized['rebuild_days']     = max( 1, absint( $input['rebuild_days'] ?? 7 ) );
		return $sanitized;
	}

	public function render_settings_tab() {
		$settings   = ccss_get_settings();
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Critical CSS', 'critical-css-wp' ); ?></h1>
			<?php $this->render_tabs( 'settings' ); ?>

			<form method="post" action="options.php" class="ccss-settings-form">
				<?php settings_fields( 'ccss_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ccss_api_url"><?php esc_html_e( 'API URL', 'critical-css-wp' ); ?></label></th>
						<td>
							<input type="url" id="ccss_api_url" name="ccss_settings[api_url]"
								value="<?php echo esc_attr( $settings['api_url'] ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'The endpoint that returns critical CSS. Change this when your API location moves.', 'critical-css-wp' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'API Key', 'critical-css-wp' ); ?> <?php if ( ! empty( $settings['api_key'] ) ) : ?><span class="ccss-indicator" title="<?php esc_attr_e( 'API key is configured', 'critical-css-wp' ); ?>">🔑</span><?php else : ?><span class="ccss-indicator ccss-indicator-missing" title="<?php esc_attr_e( 'API key not set', 'critical-css-wp' ); ?>">❌</span><?php endif; ?></th>
						<td>
							<div style="display:flex;align-items:center;gap:6px;">
								<input type="password" id="ccss_api_key" name="ccss_settings[api_key]"
									value="<?php echo esc_attr( $settings['api_key'] ?? '' ); ?>" class="regular-text" autocomplete="off" />
								<button type="button" id="ccss_toggle_key" class="button button-secondary" style="flex-shrink:0;" title="<?php esc_attr_e( 'Show/hide API key', 'critical-css-wp' ); ?>">👁</button>
							</div>
							<p class="description"><?php esc_html_e( 'API key sent as X-API-Key header on every request.', 'critical-css-wp' ); ?></p>
						</td>
					</tr>
					<script>
					(function(){
						var input = document.getElementById('ccss_api_key');
						var btn = document.getElementById('ccss_toggle_key');
						if (input && btn) {
							btn.addEventListener('click', function(){
								var show = input.type === 'password';
								input.type = show ? 'text' : 'password';
								btn.textContent = show ? '🙈' : '👁';
							});
						}
					})();
					</script>
					<tr>
						<th scope="row"><label for="ccss_public_base_url"><?php esc_html_e( 'Public Site URL', 'critical-css-wp' ); ?></label></th>
						<td>
							<input type="url" id="ccss_public_base_url" name="ccss_settings[public_base_url]"
								value="<?php echo esc_attr( $settings['public_base_url'] ?? '' ); ?>" class="regular-text"
								placeholder="<?php echo esc_attr( site_url() ); ?>" />
							<p class="description"><?php esc_html_e( 'Override the base URL sent to the API. Set this to your production URL (e.g. https://yoursite.com) when testing from local dev. The plugin replaces your local domain with this before calling the API.', 'critical-css-wp' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Enabled Post Types', 'critical-css-wp' ); ?></th>
						<td>
							<?php foreach ( $post_types as $pt ) : ?>
								<label style="display:block;margin-bottom:4px;">
									<input type="checkbox" name="ccss_settings[post_types][]"
										value="<?php echo esc_attr( $pt->name ); ?>"
										<?php checked( in_array( $pt->name, (array) $settings['post_types'], true ) ); ?> />
									<?php echo esc_html( $pt->labels->name ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ccss_interval"><?php esc_html_e( 'Cron Interval', 'critical-css-wp' ); ?></label></th>
						<td>
							<select id="ccss_interval" name="ccss_settings[interval]">
								<?php foreach ( array( 'hourly' => __( 'Hourly', 'critical-css-wp' ), 'twicedaily' => __( 'Twice Daily', 'critical-css-wp' ), 'daily' => __( 'Daily', 'critical-css-wp' ), 'weekly' => __( 'Weekly', 'critical-css-wp' ) ) as $val => $label ) : ?>
									<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $settings['interval'], $val ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ccss_rebuild_days"><?php esc_html_e( 'Rebuild Threshold (Days)', 'critical-css-wp' ); ?></label></th>
						<td>
							<input type="number" id="ccss_rebuild_days" name="ccss_settings[rebuild_days]"
								value="<?php echo esc_attr( $settings['rebuild_days'] ); ?>" min="1" class="small-text" />
							<p class="description"><?php esc_html_e( 'Regenerate CSS for pages older than this many days during cron runs.', 'critical-css-wp' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	// ─── Pages Tab ─────────────────────────────────────────────────────

	public function render_pages_tab() {
		$stats     = $this->get_stats();
		$page_num  = isset( $_GET['ccss_paged'] ) ? max( 1, absint( wp_unslash( $_GET['ccss_paged'] ) ) ) : 1;
		$per_page  = 20;
		$post_type = isset( $_GET['ccss_post_type'] ) ? sanitize_key( wp_unslash( $_GET['ccss_post_type'] ) ) : '';
		$status_f  = isset( $_GET['ccss_status'] ) ? sanitize_key( wp_unslash( $_GET['ccss_status'] ) ) : '';

		// Validate post_type filter against enabled types.
		if ( $post_type && ! in_array( $post_type, ccss_get_enabled_post_types(), true ) ) {
			$post_type = '';
		}

		$rows_data = $this->get_pages_rows( $page_num, $per_page, $post_type, $status_f );
		$rows      = $rows_data['rows'];
		$total     = $rows_data['total'];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Critical CSS', 'critical-css-wp' ); ?></h1>
			<?php $this->render_tabs( 'pages' ); ?>

			<?php $this->render_progress_card( $stats ); ?>

			<form method="get" class="ccss-filters" style="margin-bottom:12px;">
				<input type="hidden" name="page" value="critical-css-wp" />
				<select name="ccss_post_type">
					<option value=""><?php esc_html_e( 'All Post Types', 'critical-css-wp' ); ?></option>
					<?php foreach ( ccss_get_enabled_post_types() as $pt ) : ?>
						<option value="<?php echo esc_attr( $pt ); ?>" <?php selected( $post_type, $pt ); ?>><?php echo esc_html( get_post_type_object( $pt )->labels->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="ccss_status">
					<option value=""><?php esc_html_e( 'All Statuses', 'critical-css-wp' ); ?></option>
					<option value="with" <?php selected( $status_f, 'with' ); ?>><?php esc_html_e( 'With CSS', 'critical-css-wp' ); ?></option>
					<option value="without" <?php selected( $status_f, 'without' ); ?>><?php esc_html_e( 'Without CSS', 'critical-css-wp' ); ?></option>
					<option value="error" <?php selected( $status_f, 'error' ); ?>><?php esc_html_e( 'With Error', 'critical-css-wp' ); ?></option>
				</select>
				<?php submit_button( __( 'Filter', 'critical-css-wp' ), 'secondary', 'ccss_filter', false ); ?>
			</form>

			<div class="ccss-bulk-actions" style="margin-bottom:8px;">
				<button type="button" id="ccss-generate-selected" class="button button-secondary" disabled>
					<?php esc_html_e( 'Generate Selected', 'critical-css-wp' ); ?>
				</button>
				<button type="button" id="ccss-generate-all" class="button button-primary">
					<?php esc_html_e( 'Generate All', 'critical-css-wp' ); ?>
				</button>
				<span id="ccss-bulk-progress" style="display:none;margin-left:12px;"></span>
			</div>

			<table class="wp-list-table widefat fixed striped ccss-pages-table">
				<thead>
					<tr>
						<th class="check-column"><input type="checkbox" id="ccss-select-all" /></th>
						<th><?php esc_html_e( 'Title', 'critical-css-wp' ); ?></th>
						<th><?php esc_html_e( 'Post Type', 'critical-css-wp' ); ?></th>
						<th><?php esc_html_e( 'Status', 'critical-css-wp' ); ?></th>
						<th><?php esc_html_e( 'Size', 'critical-css-wp' ); ?></th>
						<th><?php esc_html_e( 'Last Generated', 'critical-css-wp' ); ?></th>
						<th><?php esc_html_e( 'Error', 'critical-css-wp' ); ?></th>
						<th><?php esc_html_e( 'Action', 'critical-css-wp' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="8"><?php esc_html_e( 'No published pages found for the selected post types.', 'critical-css-wp' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $row ) : ?>
							<tr data-post-id="<?php echo (int) $row['id']; ?>">
								<th class="check-column"><input type="checkbox" class="ccss-row-checkbox" value="<?php echo (int) $row['id']; ?>" /></th>
								<td>
									<strong><a href="<?php echo esc_url( get_edit_post_link( $row['id'] ) ); ?>"><?php echo esc_html( $row['title'] ); ?></a></strong>
								</td>
								<td><?php echo esc_html( $row['post_type_label'] ); ?></td>
								<td>
									<span class="ccss-status-badge ccss-status-<?php echo esc_attr( $row['status_class'] ); ?>">
										<?php echo esc_html( $row['status_text'] ); ?>
									</span>
								</td>
								<td class="ccss-col-size"><?php echo esc_html( $row['size'] ); ?></td>
								<td class="ccss-col-generated"><?php echo esc_html( $row['generated'] ); ?></td>
								<td><span class="ccss-error-msg"><?php echo esc_html( $row['error'] ); ?></span></td>
								<td>
									<button type="button" class="button button-small ccss-generate-one" data-post-id="<?php echo (int) $row['id']; ?>">
										<?php esc_html_e( 'Generate', 'critical-css-wp' ); ?>
									</button>
									<span class="ccss-row-spinner spinner" style="display:none;"></span>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php $this->render_pagination( $total, $per_page, $page_num, $post_type, $status_f ); ?>
		</div>
		<?php
	}

	private function render_tabs( $active ) {
		?>
		<nav class="nav-tab-wrapper" style="margin-bottom:16px;">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=critical-css-wp' ) ); ?>"
				class="nav-tab <?php echo 'pages' === $active ? 'nav-tab-active' : ''; ?>">
				<?php esc_html_e( 'Pages', 'critical-css-wp' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=critical-css-wp-settings' ) ); ?>"
				class="nav-tab <?php echo 'settings' === $active ? 'nav-tab-active' : ''; ?>">
				<?php esc_html_e( 'Settings', 'critical-css-wp' ); ?>
			</a>
		</nav>
		<?php
	}

	private function render_progress_card( $stats ) {
		$pct = $stats['total'] > 0 ? round( ( $stats['with_css'] / $stats['total'] ) * 100 ) : 0;
		?>
		<div class="ccss-progress-card" style="background:#fff;border:1px solid #c3c4c7;padding:16px;margin-bottom:16px;border-radius:4px;">
			<div style="display:flex;gap:24px;flex-wrap:wrap;align-items:center;">
				<div>
					<div class="ccss-stat-count" style="font-size:24px;font-weight:700;"><?php echo (int) $stats['with_css']; ?> / <?php echo (int) $stats['total']; ?></div>
					<div style="color:#666;"><?php esc_html_e( 'Pages with critical CSS', 'critical-css-wp' ); ?></div>
				</div>
				<div style="flex:1;min-width:200px;">
					<div class="ccss-progress-bar" style="height:12px;background:#e0e0e0;border-radius:6px;overflow:hidden;margin-bottom:6px;">
						<div class="ccss-progress-fill" style="width:<?php echo (int) $pct; ?>%;height:100%;background:#2271b1;border-radius:6px;transition:width .3s;"></div>
					</div>
					<div style="display:flex;justify-content:space-between;font-size:12px;color:#666;">
						<span class="ccss-stat-pct"><?php echo esc_html( sprintf( __( '%d%% complete', 'critical-css-wp' ), $pct ) ); ?></span>
						<span class="ccss-stat-detail">
							<?php echo esc_html( sprintf( __( 'Missing: %d', 'critical-css-wp' ), $stats['without_css'] ) ); ?>
							<?php if ( $stats['with_errors'] > 0 ) : ?>
								| <?php echo esc_html( sprintf( __( 'Errors: %d', 'critical-css-wp' ), $stats['with_errors'] ) ); ?>
							<?php endif; ?>
						</span>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	private function render_pagination( $total, $per_page, $current, $post_type, $status_f ) {
		$total_pages = ceil( $total / $per_page );
		if ( $total_pages <= 1 ) {
			return;
		}

		$base_url = add_query_arg(
			array_filter( array( 'page' => 'critical-css-wp', 'ccss_post_type' => $post_type, 'ccss_status' => $status_f ) ),
			admin_url( 'admin.php' )
		);

		echo '<div class="tablenav" style="margin-top:12px;"><div class="tablenav-pages">';
		echo '<span class="displaying-num">' . esc_html( sprintf( __( '%d items', 'critical-css-wp' ), $total ) ) . '</span>';

		for ( $i = 1; $i <= $total_pages; $i++ ) {
			if ( $i === $current ) {
				echo '<span class="tablenav-pages-navspan button disabled">' . (int) $i . '</span>';
			} else {
				echo '<a class="button" href="' . esc_url( add_query_arg( 'ccss_paged', $i, $base_url ) ) . '">' . (int) $i . '</a>';
			}
		}
		echo '</div></div>';
	}

	// ─── Data Helpers ──────────────────────────────────────────────────

	private function get_stats() {
		$post_types  = ccss_get_enabled_post_types();
		$with_css    = 0;
		$without_css = 0;
		$with_errors = 0;
		$total       = 0;

		foreach ( $post_types as $pt ) {
			$ids = get_posts( array(
				'post_type'      => $pt,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			) );
			foreach ( $ids as $pid ) {
				$total++;
				if ( ccss_has_critical_css( $pid ) ) {
					$with_css++;
				} else {
					$without_css++;
				}
				if ( ! empty( get_post_meta( $pid, '_critical_css_error', true ) ) ) {
					$with_errors++;
				}
			}
		}

		return compact( 'with_css', 'without_css', 'with_errors', 'total' );
	}

	private function get_pages_rows( $page_num, $per_page, $post_type_filter, $status_filter ) {
		$post_types = $post_type_filter ? array( $post_type_filter ) : ccss_get_enabled_post_types();
		if ( empty( $post_types ) ) {
			return array( 'rows' => array(), 'total' => 0 );
		}

		$all_ids = array();
		foreach ( $post_types as $pt ) {
			$ids = get_posts( array(
				'post_type'      => $pt,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			) );
			$all_ids = array_merge( $all_ids, $ids );
		}

		if ( $status_filter ) {
			$all_ids = array_filter( $all_ids, function ( $pid ) use ( $status_filter ) {
				$has = ccss_has_critical_css( $pid );
				$err = ! empty( get_post_meta( $pid, '_critical_css_error', true ) );
				if ( 'with' === $status_filter ) {
					return $has;
				}
				if ( 'without' === $status_filter ) {
					return ! $has && ! $err;
				}
				if ( 'error' === $status_filter ) {
					return $err;
				}
				return true;
			} );
		}

		$total    = count( $all_ids );
		$offset   = ( $page_num - 1 ) * $per_page;
		$page_ids = array_slice( $all_ids, $offset, $per_page );

		$rows = array();
		$now  = time();
		foreach ( $page_ids as $pid ) {
			$post     = get_post( $pid );
			$has      = ccss_has_critical_css( $pid );
			$err      = get_post_meta( $pid, '_critical_css_error', true );
			$gen      = (int) get_post_meta( $pid, '_critical_css_generated_at', true );

			if ( $has ) {
				$status_class = 'ok';
				$status_text  = __( '✅ Yes', 'critical-css-wp' );
			} elseif ( $err ) {
				$status_class = 'error';
				$status_text  = __( '❌ Error', 'critical-css-wp' );
			} else {
				$status_class = 'none';
				$status_text  = __( '❌ No', 'critical-css-wp' );
			}

			$rows[] = array(
				'id'              => $pid,
				'title'           => $post ? $post->post_title : '(#' . $pid . ')',
				'post_type_label' => $post ? get_post_type_object( $post->post_type )->labels->singular_name : '',
				'status_class'    => $status_class,
				'status_text'     => $status_text,
				'size'            => $has ? ccss_get_css_size_kb( $pid ) . ' KB' : '—',
				'generated'       => $gen ? human_time_diff( $gen, $now ) . ' ' . __( 'ago', 'critical-css-wp' ) : '—',
				'error'           => $err ?: '',
			);
		}

		return compact( 'rows', 'total' );
	}

	// ─── AJAX Handlers ─────────────────────────────────────────────────

	public function ajax_generate_single() {
		check_ajax_referer( 'ccss_admin', '_ccss_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'critical-css-wp' ) ) );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post ID.', 'critical-css-wp' ) ) );
		}

		$result = ccss_generate_for_post( $post_id, true );
		if ( ! $result ) {
			$error = get_post_meta( $post_id, '_critical_css_error', true );
			wp_send_json_error( array( 'message' => $error ?: __( 'Generation failed.', 'critical-css-wp' ) ) );
		}

		wp_send_json_success( array(
			'message'       => __( 'Critical CSS generated.', 'critical-css-wp' ),
			'size'          => ccss_get_css_size_kb( $post_id ) . ' KB',
			'generated'     => human_time_diff( (int) get_post_meta( $post_id, '_critical_css_generated_at', true ), time() ) . ' ' . __( 'ago', 'critical-css-wp' ),
			'stats'         => $this->get_stats(),
		) );
	}

	public function ajax_bulk_generate() {
		check_ajax_referer( 'ccss_admin', '_ccss_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'critical-css-wp' ) ) );
		}

		// Single-page mode: generate one post at a time, return stats + progress.
		// This is called repeatedly by the JS for each page in bulk.
		$single_id = isset( $_POST['single_id'] ) ? absint( wp_unslash( $_POST['single_id'] ) ) : 0;
		$remaining = isset( $_POST['remaining'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['remaining'] ) ) : array();
		$total_bulk = isset( $_POST['total'] ) ? absint( wp_unslash( $_POST['total'] ) ) : 0;

		if ( $single_id ) {
			$result = ccss_generate_for_post( $single_id, true );
			$done = $total_bulk - count( $remaining );

			wp_send_json_success( array(
				'message'       => sprintf( __( 'Generated: %d of %d', 'critical-css-wp' ), $done, $total_bulk ),
				'done'          => $done,
				'total'         => $total_bulk,
				'remaining'     => array_values( $remaining ),
				'stats'         => $this->get_stats(),
				'last_generated' => $result ? ccss_get_css_size_kb( $single_id ) . ' KB' : 'failed',
			) );
		}

		// Legacy fallback: process all in one request (for backwards compat).
		$post_ids = isset( $_POST['post_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['post_ids'] ) ) : array();
		if ( empty( $post_ids ) ) {
			$enabled  = ccss_get_enabled_post_types();
			$post_ids = get_posts( array(
				'post_type'      => $enabled,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'fields'         => 'ids',
			) );
		}

		$processed = 0;
		foreach ( $post_ids as $pid ) {
			if ( ccss_generate_for_post( $pid, true ) ) {
				$processed++;
			}
		}

		wp_send_json_success( array(
			'message'   => sprintf( __( 'Generated critical CSS for %d of %d pages.', 'critical-css-wp' ), $processed, count( $post_ids ) ),
			'processed' => $processed,
			'stats'     => $this->get_stats(),
		) );
	}

	// ─── Assets ────────────────────────────────────────────────────────

	public function enqueue_assets( $hook ) {
		$valid = in_array( $hook, array( 'toplevel_page_critical-css-wp', 'critical-css_page_critical-css-wp-settings' ), true );
		if ( ! $valid ) {
			return;
		}

		wp_enqueue_style( 'ccss-admin', CCSS_PLUGIN_URL . 'assets/admin.css', array(), CCSS_VERSION );
		wp_enqueue_script( 'ccss-admin', CCSS_PLUGIN_URL . 'assets/admin.js', array( 'jquery' ), CCSS_VERSION, true );
		wp_localize_script( 'ccss-admin', 'CCSS_Admin', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'ccss_admin' ),
			'i18n'     => array(
				'generating'      => __( 'Generating…', 'critical-css-wp' ),
				'generated'       => __( 'Generated', 'critical-css-wp' ),
				'failed'          => __( 'Failed', 'critical-css-wp' ),
				'no_pages'        => __( 'No pages selected.', 'critical-css-wp' ),
				'confirm_all'     => __( 'Generate critical CSS for ALL pages? This may take a while.', 'critical-css-wp' ),
				'confirm_selected' => __( 'Generate critical CSS for the selected pages?', 'critical-css-wp' ),
			),
		) );
	}

	// ─── Post List Table ───────────────────────────────────────────────

	public function add_column( $columns ) {
		$columns['ccss_status'] = __( 'Critical CSS', 'critical-css-wp' );
		return $columns;
	}

	public function render_column( $column, $post_id ) {
		if ( 'ccss_status' !== $column ) {
			return;
		}

		$has       = ccss_has_critical_css( $post_id );
		$generated = (int) get_post_meta( $post_id, '_critical_css_generated_at', true );
		$tooltip   = $generated ? sprintf( __( 'Last generated %s ago', 'critical-css-wp' ), human_time_diff( $generated, time() ) ) : __( 'Not generated yet', 'critical-css-wp' );
		$size      = $has ? ' <span class="ccss-size">' . ccss_get_css_size_kb( $post_id ) . ' KB</span>' : '';
		$icon      = $has ? '✅' : '❌';

		echo '<span class="ccss-status" title="' . esc_attr( $tooltip ) . '">' . esc_html( $icon ) . '</span>' . $size;
	}

	public function register_bulk_action( $actions ) {
		$actions['ccss_regenerate'] = __( 'Regenerate Critical CSS', 'critical-css-wp' );
		return $actions;
	}

	public function handle_bulk_action( $redirect_to, $do_action, $post_ids ) {
		if ( 'ccss_regenerate' !== $do_action ) {
			return $redirect_to;
		}

		$processed = 0;
		foreach ( $post_ids as $post_id ) {
			if ( ccss_generate_for_post( $post_id ) ) {
				$processed++;
			}
		}

		return add_query_arg( 'ccss_bulk_processed', $processed, $redirect_to );
	}

	public function add_single_action( $actions, $post ) {
		if ( ! ccss_is_enabled_post_type( $post->post_type ) ) {
			return $actions;
		}

		$actions['ccss_regenerate'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=critical-css-wp' ) ),
			esc_html__( 'Critical CSS', 'critical-css-wp' )
		);

		return $actions;
	}

	// ─── Plugin Links ──────────────────────────────────────────────────

	public function plugin_action_links( $links ) {
		$settings_link = sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=critical-css-wp' ) ), __( 'Critical CSS', 'critical-css-wp' ) );
		array_unshift( $links, $settings_link );
		return $links;
	}

	public function refresh_schedule() {
		$cron = new Ccss_Cron( new Ccss_Api() );
		$cron->schedule_event();
	}
}

