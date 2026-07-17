# WordPress Critical CSS Plugin — Prompt

---

**Create a WordPress plugin that integrates with a self-hosted Critical CSS API service.**

## API Details

The API runs on our Tailscale network at:

```
POST YOUR_API_URL
Content-Type: application/json

{"url": "https://example.com/page"}
```

Returns:
```json
{
  "url": "https://example.com/page",
  "css": "body{...} ..."
}
```

---

## 1. Core Functionality

- On page publish/update, fetch critical CSS from the API for that page's URL
- Store the returned CSS as post meta (`_critical_css`)
- Inject the critical CSS inline in `<head>` on the frontend using `wp_head` (early priority, before enqueued styles)
- Defer all originally enqueued styles by removing their `media="all"` or switching to `media="print"` with `onload="this.media='all'"` (the loadCSS pattern) — but only if critical CSS exists for that page

## 2. Scope

- Work for all public post types: posts, pages, and custom post types
- Only for published/publishable statuses
- Admins can select which post types are enabled via a settings page

## 3. Scheduler

- Use WP Cron to set up a recurring job (daily or hourly, configurable)
- The cron job processes all published items of selected post types that either:
  - Have no critical CSS stored yet
  - Have a stored CSS that's older than X days (configurable)
- Batch process with a reasonable limit per run (e.g., 20 URLs per cron tick) to avoid timeouts

## 4. Manual Controls

- Settings page under Settings → Critical CSS
  - API URL field (default: `YOUR_API_URL`)
  - Post type checkboxes
  - Cron interval dropdown (hourly, twice daily, daily, weekly)
  - Re-generation threshold in days
  - "Regenerate All" button
  - "Regenerate Single" action row on post/page list tables
- Bulk action on post list tables: "Regenerate Critical CSS"
- Admin notice showing count of pages with/without critical CSS

## 5. Cache & Optimization Plugin Compatibility

- **WP Rocket:** Clear critical CSS for a URL when WP Rocket cache is cleared. Hook into `rocket_after_clean_domain` and `rocket_after_clean_post` to re-trigger generation.
- **Autoptimize:** When Autoptimize's "Inline & Defer CSS" is active, our plugin should NOT double-defer. Check if `autoptimize_filter_css_defer` is active and skip our deferral.
- **Elementor:** Critical CSS generation should work with Elementor pages. Elementor loads heavy inline CSS — the API handles this already by rendering with Chromium.
- **W3 Total Cache / WP Super Cache / LiteSpeed Cache:** Clear relevant cache when regenerating critical CSS for a URL.

## 6. Frontend Injection Logic

```php
// In wp_head, early (priority 0):
if ( is_singular() ) {
    $css = get_post_meta( get_the_ID(), '_critical_css', true );
    if ( $css ) {
        echo '<style id="critical-css-inline">' . $css . '</style>';
    }
}

// Defer styles (only when critical CSS exists):
// Loop through enqueued styles, add defer attributes
// Keep theme's main style, but defer non-critical ones
```

## 7. Deferral Strategy

- Identify stylesheets that are render-blocking (typically theme + plugin CSS)
- Add `media="print" onload="this.media='all'"` to those stylesheets
- Exclude:
  - Admin bar styles (always render normally)
  - Dashicons
  - Any stylesheet with `data-critical-skip` attribute
- This is the same pattern WP Rocket and Autoptimize use for CSS deferral

## 8. Error Handling & Logging

- Log API failures (timeout, non-200 response) to `wp-content/debug.log` when WP_DEBUG is on
- If API returns an error for a URL, store the error message in post meta (`_critical_css_error`) and skip that page on next cron run
- Set a reasonable HTTP timeout (30 seconds)

## 9. Admin UX

- Add a column "Critical CSS" to the post/page list tables showing "✅ Yes" or "❌ No"
- Show the stored CSS size in KB next to the status
- Hover tooltip on the status showing when it was last generated

## 10. Uninstall

- On plugin deletion, remove all `_critical_css` and `_critical_css_error` post meta
- Remove the cron schedule
- Remove options

---

## Code Structure

```
/critical-css-wp/
├── critical-css-wp.php          # Plugin header + main hooks
├── includes/
│   ├── class-api.php            # API client (HTTP requests)
│   ├── class-admin.php          # Settings page, columns, bulk actions
│   ├── class-cron.php           # Scheduler logic
│   ├── class-frontend.php       # Inline injection + style deferral
│   └── class-compatibility.php  # WP Rocket, Autoptimize, Elementor, etc.
├── assets/
│   └── admin.css
└── languages/
```

---

## API Client Details

```php
// class-api.php
$response = wp_remote_post( $api_url, [
    'headers' => [ 'Content-Type' => 'application/json' ],
    'body'    => json_encode( [ 'url' => $page_url ] ),
    'timeout' => 30,
] );
if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
    // Log error, store in post meta
    return false;
}
$body = json_decode( wp_remote_retrieve_body( $response ), true );
return $body['css'] ?? false;
```

---

## Notes

- No external libraries needed — use WordPress HTTP API (`wp_remote_post`)
- Follow WordPress coding standards
- Add proper nonce checks on all admin actions
- Escaping on all output
- Plugin should work on PHP 8.0+ and WordPress 6.0+
- Use a unique prefix (`ccss_`) for options and meta keys
