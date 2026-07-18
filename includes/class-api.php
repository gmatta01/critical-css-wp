<?php
/**
 * API client for the Critical CSS service.
 */
class Ccss_Api {

	/**
	 * API endpoint for URL-based generation (legacy).
	 */
	public function request_css( $url ) {
		$api_url = ccss_get_option( 'api_url', 'http://100.94.29.96:3001/critical/simple' );
		if ( empty( $api_url ) ) {
			return array(
				'success' => false,
				'error'   => __( 'The API endpoint is not configured.', 'critical-css-wp' ),
			);
		}

		$response = wp_remote_post(
			$api_url,
			array(
				'headers'   => array( 'Content-Type' => 'application/json' ),
				'body'      => wp_json_encode( array( 'url' => $url ) ),
				'timeout'   => 60,
				'sslverify' => false,
			)
		);

		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();
			$code    = $response->get_error_code();
			ccss_log( 'API request failed for ' . $url . ': [' . $code . '] ' . $message );
			return array(
				'success' => false,
				'error'   => $message,
			);
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			$message = sprintf( __( 'The API request returned HTTP %d.', 'critical-css-wp' ), $response_code );
			ccss_log( 'API request returned ' . $response_code . ' for ' . $url );
			return array(
				'success' => false,
				'error'   => $message,
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) || empty( $decoded['css'] ) ) {
			ccss_log( 'API response did not contain critical CSS for ' . $url );
			return array(
				'success' => false,
				'error'   => __( 'The API response did not include CSS.', 'critical-css-wp' ),
			);
		}

		return array(
			'success' => true,
			'css'     => (string) $decoded['css'],
		);
	}

	/**
	 * Generate critical CSS from HTML + CSS via chunked processing.
	 *
	 * Divides CSS into chunks that fit the API's payload limit,
	 * sends each chunk separately, then merges all critical CSS results.
	 * This ensures ALL stylesheets are processed for maximum accuracy.
	 *
	 * @param string $html     Rendered page HTML.
	 * @param string $all_css  Full combined CSS content.
	 * @return array{success: bool, css?: string, error?: string}
	 */
	public function request_css_chunked( $html, $all_css, $skip_delay = false ) {
		$api_url = ccss_get_inline_api_url();
		if ( empty( $api_url ) ) {
			return array( 'success' => false, 'error' => __( 'The API endpoint is not configured.', 'critical-css-wp' ) );
		}

		if ( empty( $html ) || empty( $all_css ) ) {
			return array( 'success' => false, 'error' => __( 'HTML and CSS content are required.', 'critical-css-wp' ) );
		}

		// Keep total payload under ~320 KB to stay reliably under the API limit.
		// HTML(~220 KB) + CSS(~100 KB) ≈ 320 KB — well under the ~385 KB limit.
		$max_css_per_chunk = 100 * 1024;

		// If CSS fits in one chunk, send directly.
		if ( strlen( $all_css ) <= $max_css_per_chunk ) {
			return $this->request_css_from_html( $html, $all_css );
		}

		// Split CSS into chunks at rule boundaries (closing brace).
		$chunks = $this->split_css_at_rules( $all_css, $max_css_per_chunk );
		$total_chunks = count( $chunks );

		ccss_log( 'API chunked: splitting ' . round( strlen( $all_css ) / 1024, 1 ) . ' KB CSS into ' . $total_chunks . ' chunks' );

		$all_critical = '';
		$successes    = 0;

		foreach ( $chunks as $i => $chunk ) {
			$chunk_num = $i + 1;

			// Delay between chunks to avoid overwhelming the API server.
			// Skip when called from bulk/background processes (each page already has natural spacing).
			if ( ! $skip_delay && $i > 0 ) {
				sleep( 2 );
			}

			ccss_log( 'API chunked: processing chunk ' . $chunk_num . ' of ' . $total_chunks . ' (' . round( strlen( $chunk ) / 1024, 1 ) . ' KB)' );

			$result = $this->request_css_from_html( $html, $chunk );

			// Retry once on failure (API may be temporarily overloaded).
			if ( ! $result['success'] && ! $skip_delay ) {
				ccss_log( 'API chunked: chunk ' . $chunk_num . ' first attempt failed — retrying after 3s delay' );
				sleep( 3 );
				$result = $this->request_css_from_html( $html, $chunk );
			}

			if ( $result['success'] ) {
				$all_critical .= $result['css'] . "\n";
				$successes++;
				ccss_log( 'API chunked: chunk ' . $chunk_num . ' success (' . round( strlen( $result['css'] ) / 1024, 1 ) . ' KB critical CSS)' );
			} else {
				ccss_log( 'API chunked: chunk ' . $chunk_num . ' failed — ' . $result['error'] );
			}
		}

		if ( 0 === $successes ) {
			return array( 'success' => false, 'error' => __( 'All chunks failed.', 'critical-css-wp' ) );
		}

		// Deduplicate merged critical CSS.
		$all_critical = $this->deduplicate_css( $all_critical );

		ccss_log( 'API chunked: merged ' . $successes . ' of ' . $total_chunks . ' chunks → ' . round( strlen( $all_critical ) / 1024, 1 ) . ' KB deduplicated critical CSS' );

		return array( 'success' => true, 'css' => $all_critical );
	}

	/**
	 * Split CSS at rule boundaries rather than arbitrary byte positions.
	 *
	 * str_split() can break a CSS rule like:
	 *   .selector { color:
	 *   red; }
	 * which produces invalid CSS that the API rejects.
	 *
	 * This splits only at `}` (closing brace) positions near the chunk size.
	 *
	 * @param string $css     Full CSS content.
	 * @param int    $max_len Maximum chunk length in bytes.
	 * @return string[] Chunks of valid CSS.
	 */
	private function split_css_at_rules( $css, $max_len ) {
		$chunks  = array();
		$pos     = 0;
		$css_len = strlen( $css );

		while ( $pos < $css_len ) {
			$end = $pos + $max_len;
			if ( $end >= $css_len ) {
				$chunks[] = substr( $css, $pos );
				break;
			}

			// Find the next `}` after the cut point.
			$brace = strpos( $css, '}', $end );
			if ( false === $brace ) {
				// No more closing braces — take the rest.
				$chunks[] = substr( $css, $pos );
				break;
			}

			$chunks[] = substr( $css, $pos, $brace - $pos + 1 );
			$pos      = $brace + 1;
		}

		return $chunks;
	}

	/**
	 * Basic CSS deduplication — removes duplicate rules.
	 *
	 * @param string $css Raw CSS.
	 * @return string Deduplicated CSS.
	 */
	private function deduplicate_css( $css ) {
		// Extract rule blocks: selector { ... }
		preg_match_all( '/([^{]+)\{([^}]+)\}/', $css, $matches, PREG_SET_ORDER );
		$seen  = array();
		$clean = '';

		foreach ( $matches as $match ) {
			$selector = trim( $match[1] );
			$block    = trim( $match[2] );

			// Normalize whitespace for dedup comparison.
			$key = $selector . '{' . preg_replace( '/\s+/', ' ', $block ) . '}';
			if ( ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = true;
				$clean .= $selector . '{' . $block . '}' . "\n";
			}
		}

		return ! empty( $clean ) ? $clean : $css;
	}

	/**
	 * Generate critical CSS from rendered page HTML + CSS content.
	 *
	 * Sends the HTML and CSS to the /critical endpoint which returns
	 * the critical (above-the-fold) CSS. Used by the chunked processor
	 * for each chunk, and can also be called directly for simple pages.
	 *
	 * @param string $html Rendered page HTML.
	 * @param string $css  Stylesheet content.
	 * @return array{success: bool, css?: string, error?: string}
	 */
	public function request_css_from_html( $html, $css ) {
		$api_url = ccss_get_inline_api_url();
		if ( empty( $api_url ) ) {
			return array(
				'success' => false,
				'error'   => __( 'The API endpoint is not configured.', 'critical-css-wp' ),
			);
		}

		if ( empty( $html ) || empty( $css ) ) {
			return array(
				'success' => false,
				'error'   => __( 'HTML and CSS content are required.', 'critical-css-wp' ),
			);
		}

		ccss_log( 'API inline: sending ' . round( strlen( $html ) / 1024, 1 ) . ' KB HTML + ' . round( strlen( $css ) / 1024, 1 ) . ' KB CSS to ' . $api_url );

		$response = wp_remote_post(
			$api_url,
			array(
				'headers'   => array( 'Content-Type' => 'application/json' ),
				'body'      => wp_json_encode( array(
					'html' => $html,
					'css'  => $css,
				) ),
				'timeout'   => 60,
				'sslverify' => false,
			)
		);

		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();
			$code    = $response->get_error_code();
			ccss_log( 'API inline request failed: [' . $code . '] ' . $message );
			return array(
				'success' => false,
				'error'   => $message,
			);
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			$message = sprintf( __( 'The API request returned HTTP %d.', 'critical-css-wp' ), $response_code );
			ccss_log( 'API inline request returned ' . $response_code );
			return array(
				'success' => false,
				'error'   => $message,
			);
		}

		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) || empty( $decoded['css'] ) ) {
			ccss_log( 'API inline response did not contain critical CSS' );
			return array(
				'success' => false,
				'error'   => __( 'The API response did not include CSS.', 'critical-css-wp' ),
			);
		}

		return array(
			'success' => true,
			'css'     => (string) $decoded['css'],
		);
	}
}

