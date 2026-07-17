# Critical CSS for WP

Critical CSS for WP generates and stores critical CSS for published WordPress content using a configurable HTTP API endpoint. It injects the generated CSS inline on the frontend and can defer render-blocking styles when a page has critical CSS available.

## Features

- Generates CSS for published posts, pages, and other public post types
- Stores generated CSS in post meta using the `_critical_css` key
- Injects the CSS inline in the page head via `wp_head`
- Defers non-critical enqueued styles using the `media="print" onload="this.media='all'"` pattern
- Supports manual regeneration from the settings screen, bulk actions, and single-post actions
- Uses WP Cron for scheduled regeneration with configurable intervals and thresholds
- Provides a settings page under Settings → Critical CSS

## Configuration

1. Activate the plugin.
2. Open Settings → Critical CSS.
3. Set the API URL for the critical CSS service.
4. Select enabled post types.
5. Save the settings and trigger a manual regeneration if needed.

## Notes

- The API endpoint is configurable so it can be changed later without changing code.
- The plugin keeps the implementation simple and extendable for future API changes.
- Logs are written to the WordPress debug log when `WP_DEBUG` and `WP_DEBUG_LOG` are enabled.
