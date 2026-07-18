<?php
/**
 * GitHub release updater for public repos.
 *
 * Checks the latest GitHub release and injects updates into
 * WordPress's plugin update system. No token needed for public repos.
 *
 * GitHub ZIPs extract to folders like:
 *   gmatta01-critical-css-wp-<sha>/   (zipball)
 *   critical-css-wp-0.2.3/             (archive by tag)
 * This class renames the extracted folder to critical-css-wp before install.
 */
class Ccss_Updater {

	/** Fixed plugin directory name — never derived from the current install path. */
	const SLUG = 'critical-css-wp';

	/** Main plugin file inside the directory. */
	const MAIN_FILE = 'critical-css-wp.php';

	private $file;
	private $basename;

	public function __construct( $file ) {
		$this->file     = $file;
		// Set immediately — update checks can run before admin_init (cron, manual check).
		$this->basename = plugin_basename( $file );
	}

	/**
	 * Hook into the plugin update check.
	 */
	public function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_update_source' ), 10, 4 );
		add_filter( 'upgrader_post_install', array( $this, 'fix_active_plugin_path' ), 10, 3 );
	}

	/**
	 * Fetch the latest release from GitHub.
	 *
	 * @return array|false Release data or false on failure.
	 */
	private function fetch_latest_release() {
		$cache = get_transient( 'ccss_github_release' );
		if ( false !== $cache ) {
			return $cache;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/gmatta01/critical-css-wp/releases/latest',
			array(
				'headers' => array(
					'Accept'     => 'application/vnd.github.v3+json',
					'User-Agent' => 'critical-css-wp-updater',
				),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$release = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $release['tag_name'] ) || empty( $release['zipball_url'] ) ) {
			return false;
		}

		// Cache for 3 hours (avoids hitting GitHub rate limits).
		set_transient( 'ccss_github_release', $release, 3 * HOUR_IN_SECONDS );

		return $release;
	}

	/**
	 * Check GitHub for a newer version and inject the update.
	 *
	 * @param object $transient The plugin update transient.
	 * @return object
	 */
	public function check_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			ccss_log( 'Updater: no checked plugins, skipping' );
			return $transient;
		}

		// Refresh basename in case the plugin was moved/renamed.
		$this->basename = plugin_basename( $this->file );

		$release = $this->fetch_latest_release();
		if ( false === $release ) {
			ccss_log( 'Updater: fetch_latest_release failed' );
			return $transient;
		}

		$tag     = ltrim( $release['tag_name'], 'v' );
		$current = CCSS_VERSION;
		ccss_log( 'Updater: tag=' . $tag . ' current=' . $current );

		if ( version_compare( $tag, $current, '<=' ) ) {
			ccss_log( 'Updater: no newer version available' );
			return $transient;
		}

		// Archive URL (tag-based). Folder still includes the version suffix and must be renamed.
		$archive_url = 'https://github.com/gmatta01/critical-css-wp/archive/refs/tags/' . $release['tag_name'] . '.zip';

		$correct_plugin = self::SLUG . '/' . self::MAIN_FILE;
		ccss_log( 'Updater: injecting update v' . $tag . ' key=' . $this->basename . ' plugin=' . $correct_plugin );
		$transient->response[ $this->basename ] = (object) array(
			'id'          => self::SLUG,
			'slug'        => self::SLUG,
			'plugin'      => $correct_plugin,
			'new_version' => $tag,
			'url'         => $release['html_url'],
			'package'     => $archive_url,
			'icons'       => array(),
			'banners'     => array(),
			'requires'    => '6.0',
			'tested'      => '6.7',
		);

		return $transient;
	}

	/**
	 * Rename the extracted GitHub folder to critical-css-wp before WordPress installs it.
	 *
	 * @param string      $source        Extracted source path (trailing slash).
	 * @param string      $remote_source Parent of extracted folder.
	 * @param WP_Upgrader $upgrader      Upgrader instance.
	 * @param array       $hook_extra    Extra hook data.
	 * @return string|WP_Error
	 */
	public function fix_update_source( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		if ( ! $this->is_our_upgrade( $source, $hook_extra ) ) {
			return $source;
		}

		global $wp_filesystem;

		$source      = untrailingslashit( $source );
		$desired_dir = self::SLUG;

		if ( basename( $source ) === $desired_dir ) {
			ccss_log( 'Updater: source folder already named ' . $desired_dir );
			return trailingslashit( $source );
		}

		$new_source = trailingslashit( dirname( $source ) ) . $desired_dir;

		ccss_log( 'Updater: renaming ' . basename( $source ) . ' → ' . $desired_dir );

		// Remove a leftover target directory from a previous failed attempt.
		if ( $wp_filesystem && $wp_filesystem->is_dir( $new_source ) ) {
			$wp_filesystem->delete( $new_source, true );
		} elseif ( is_dir( $new_source ) ) {
			$this->rrmdir( $new_source );
		}

		$moved = false;
		if ( $wp_filesystem ) {
			$moved = $wp_filesystem->move( $source, $new_source );
		}
		if ( ! $moved ) {
			$moved = @rename( $source, $new_source );
		}

		if ( ! $moved || ! is_dir( $new_source ) ) {
			ccss_log( 'Updater: failed to rename plugin folder to ' . $desired_dir );
			return new WP_Error(
				'ccss_rename_failed',
				sprintf(
					/* translators: %s: desired plugin directory name */
					__( 'Could not rename the plugin directory to %s.', 'critical-css-wp' ),
					$desired_dir
				)
			);
		}

		return trailingslashit( $new_source );
	}

	/**
	 * If this update moved us out of a bad GitHub folder name, fix active_plugins.
	 *
	 * @param bool  $response   Installation response.
	 * @param array $hook_extra Extra arguments.
	 * @param array $result     Installation result data.
	 * @return bool
	 */
	public function fix_active_plugin_path( $response, $hook_extra, $result ) {
		if ( empty( $hook_extra['plugin'] ) ) {
			return $response;
		}

		if ( self::MAIN_FILE !== basename( $hook_extra['plugin'] ) ) {
			return $response;
		}

		$old_plugin = $hook_extra['plugin'];
		$new_plugin = self::SLUG . '/' . self::MAIN_FILE;

		if ( $old_plugin === $new_plugin ) {
			return $response;
		}

		$active = (array) get_option( 'active_plugins', array() );
		$key    = array_search( $old_plugin, $active, true );
		if ( false !== $key ) {
			$active[ $key ] = $new_plugin;
			update_option( 'active_plugins', array_values( array_unique( $active ) ) );
			ccss_log( 'Updater: rewrote active plugin path ' . $old_plugin . ' → ' . $new_plugin );
		}

		return $response;
	}

	/**
	 * Show plugin info in the WordPress details modal.
	 *
	 * @param object|bool $result
	 * @param string      $action
	 * @param object      $args
	 * @return object|bool
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		$slug = isset( $args->slug ) ? $args->slug : '';
		// Accept either the canonical slug or whatever folder we are currently in.
		$current_slug = dirname( plugin_basename( $this->file ) );
		if ( self::SLUG !== $slug && $current_slug !== $slug ) {
			return $result;
		}

		$release = $this->fetch_latest_release();
		if ( false === $release ) {
			return $result;
		}

		$tag         = ltrim( $release['tag_name'], 'v' );
		$archive_url = 'https://github.com/gmatta01/critical-css-wp/archive/refs/tags/' . $release['tag_name'] . '.zip';

		return (object) array(
			'name'          => 'Critical CSS for WP',
			'slug'          => self::SLUG,
			'version'       => $tag,
			'author'        => '<a href="https://github.com/gmatta01">gmatta01</a>',
			'homepage'      => $release['html_url'],
			'requires'      => '6.0',
			'tested'        => '6.7',
			'download_link' => $archive_url,
			'sections'      => array(
				'description' => $release['body'] ?: 'Critical CSS for WordPress.',
				'changelog'   => $release['body'] ?: '',
			),
			'banners'       => array(),
		);
	}

	/**
	 * Clear the release cache (call this after your own release checks).
	 */
	public static function clear_cache() {
		delete_transient( 'ccss_github_release' );
	}

	/**
	 * Whether this upgrader source belongs to Critical CSS for WP.
	 *
	 * @param string $source     Extracted source path.
	 * @param array  $hook_extra Extra hook data.
	 * @return bool
	 */
	private function is_our_upgrade( $source, $hook_extra ) {
		if ( ! empty( $hook_extra['plugin'] ) && self::MAIN_FILE === basename( $hook_extra['plugin'] ) ) {
			return true;
		}

		$main = trailingslashit( $source ) . self::MAIN_FILE;
		return file_exists( $main );
	}

	/**
	 * Recursively remove a directory (fallback when WP_Filesystem is unavailable).
	 *
	 * @param string $dir Directory path.
	 */
	private function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		if ( false === $items ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				$this->rrmdir( $path );
			} else {
				@unlink( $path );
			}
		}
		@rmdir( $dir );
	}
}
