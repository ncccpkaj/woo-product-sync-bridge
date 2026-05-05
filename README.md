# Woo Product Sync Bridge

A WordPress plugin for WooCommerce that lets store admins transfer, replace, and selectively update products between connected WooCommerce websites using authenticated REST API requests.

Woo Product Sync Bridge is built for manual admin-controlled sync workflows. It supports simple and variable products, product images, gallery images, categories, tags, brands, attributes, variations, variation images, variation galleries, and broad product metadata while skipping reviews and unsafe generated data.

## Features

- Connected Website Manager: Add, edit, disable, test, and remove remote WooCommerce site connections from WooCommerce settings.
- Secure REST Bridge: Uses shared plugin secrets, HMAC request signatures, timestamps, nonces, replay protection, no-cache headers, and failed-auth rate limiting.
- Product Transfer Button: Adds a Transfer Product button on the WooCommerce products list that activates after selecting products.
- Instant Transfer: Transfers selected products one by one with progress, percentage, live log, conflict reporting, and unsupported product reporting.
- Scheduled Transfer: Queues selected products with WooCommerce Action Scheduler for background processing.
- SKU Conflict Handling: Instant transfers show conflicting target products and allow selected full-product replace. Scheduled transfers skip conflicts and log them.
- Product Update Flow: Adds an Update on another site row action for each product, with remote search by SKU or title.
- Partial Updates: Update full product, images, price, descriptions, or other meta only.
- Variable Product Support: Transfers and updates variations using SKU first, then exact attribute matching where applicable.
- Variation Gallery Support: Transfers and updates common variation gallery meta keys and remaps source media IDs to target media IDs.
- Taxonomy Duplicate Prevention: Matches terms by slug first, then name, and creates missing parent-child term trees.
- Media Duplicate Prevention: Reuses media through source-to-target mapping and falls back to inline image data when local `.test` URLs cannot be fetched remotely.
- Logging: Keeps a dedicated sync log with recent log viewer, download, and clear actions.
- Production Guards: Checks PHP and WooCommerce versions, validates REST payloads, clamps settings, and avoids loading admin assets outside the needed screens.

## Requirements

| Requirement | Version |
| --- | --- |
| WordPress | 5.8+ recommended |
| WooCommerce | 5.0+ |
| PHP | 7.4+ |
| WooCommerce tested up to | 10.7.0 |

The plugin uses WooCommerce's bundled Action Scheduler. No third-party plugin is required.

## Installation

1. Download or clone this repository into your WordPress plugins directory:

   ```text
   wp-content/plugins/woo-product-sync-bridge
   ```

2. In WordPress admin, go to Plugins and activate Woo Product Sync Bridge.
3. Make sure WooCommerce is active before using the plugin.
4. Install and activate the plugin on every site that will send or receive products.

The target website must also have this plugin active because product import, replace, search, and partial update logic runs on the receiving website.

## Configuration

Go to:

```text
WooCommerce -> Settings -> Advanced -> Product Sync
```

Available settings:

| Setting | Default | Description |
| --- | --- | --- |
| This site connection info | Auto-generated | Shows this site's URL, REST base, and shared secret for connecting from another store. |
| Batch size | 5 | Products per scheduled batch. Instant transfer currently processes one product per AJAX request for better reliability with large products. |
| API timeout | 45 | Timeout in seconds for each remote API request. |
| Retry count | 3 | Number of retries for scheduled jobs. |
| Connected websites | Empty | Saved remote stores with name, URL, shared secret, enabled state, and test button. |
| Transfer logs | N/A | View recent logs, download log, or clear log. |

Secrets are stored in non-autoloaded options. Remote connection secrets are encrypted before saving when OpenSSL is available.

## Connecting Two Stores

Example local setup:

```text
Source: http://builtplugin.test/
Target: http://shobpai.test/
```

1. Install and activate the plugin on both sites.
2. On the target site, open WooCommerce -> Settings -> Advanced -> Product Sync.
3. Copy the target site's Site URL and Shared secret.
4. On the source site, add a connected website using the target URL and target secret.
5. Click Test connection.
6. Repeat in the opposite direction only if you also want the second site to send products back to the first site.

Local Laragon testing works. Both local hostnames must resolve from PHP on the sending site, and the receiving site must be reachable by WordPress HTTP requests. If media URLs from one local site cannot be sideloaded by the target site, the plugin falls back to inline image data in the product payload.

For live production sites, HTTPS is strongly recommended. HMAC protects request integrity, but HTTPS also encrypts product data, image payloads, and metadata while they travel between websites.

## Product Transfer

Go to WooCommerce -> Products.

1. Select one or more products using the product checkboxes.
2. Click Transfer Product.
3. Select the destination website.
4. Choose Instant Transfer or Scheduled Transfer.
5. Start the transfer.

Instant transfer:

- Processes products one by one through AJAX.
- Shows a progress bar and live log.
- Transfers simple and variable products.
- Shows SKU conflicts in a separate conflict box.
- Lets you replace selected conflicting target products after the normal run completes.
- Shows unsupported product types in a separate box.

Scheduled transfer:

- Queues jobs in Action Scheduler.
- Retries failed jobs based on the retry count setting.
- Skips SKU conflicts and writes them to the log.

Grouped and external products are out of scope for version 1.0.0 and are skipped with a clear log message.

## Product Update

Each product row has an Update on another site action.

Workflow:

1. Click Update on another site.
2. Select the target website.
3. Search target products by SKU or title.
4. Select the matching target product.
5. Choose the update part.
6. Run Update Instantly or Schedule Update.

Update parts:

| Part | What it updates |
| --- | --- |
| Full product | Replaces the selected target product with the full exported source product. |
| Images | Featured image, product gallery, variation images, and variation galleries. |
| Price | Product price fields and variation prices, matched by SKU first and attributes second. |
| Description + short description | Long description and short description only. |
| Other meta | Product meta except blacklisted image, price, description, category, generated, cache, lookup, and plugin bookkeeping fields. |

## Product Data Coverage

Supported in version 1.0.0:

- Simple products.
- Variable products.
- Product title, slug, status, visibility, SKU, descriptions, menu order.
- Regular price, sale price, sale dates, tax settings, stock, dimensions, virtual/downloadable flags, purchase note.
- Featured image and product gallery.
- Product categories, tags, and `product_brand` when available.
- Global and custom product attributes.
- Variation attributes, SKU, status, prices, stock, dimensions, image, gallery, and meta.
- Broad product and variation metadata, including common SEO plugin meta such as Yoast, Rank Math, and AIOSEO fields when present.

Not transferred:

- Product reviews and comments.
- Generated WooCommerce rating and review counters.
- WooCommerce lookup/cache/transient fields.
- WordPress edit locks, old slugs, trash metadata, and plugin bookkeeping data.
- Grouped and external products in version 1.0.0.

## REST API

Namespace:

```text
wpsb/v1
```

Endpoints:

| Endpoint | Method | Purpose |
| --- | --- | --- |
| `/wpsb/v1/site-info` | GET | Returns site and plugin information. |
| `/wpsb/v1/connection/test` | POST | Verifies authentication and plugin availability. |
| `/wpsb/v1/products/search` | POST | Searches target products by SKU or title with pagination. |
| `/wpsb/v1/products/import` | POST | Creates a new product. SKU conflicts are returned instead of overwritten. |
| `/wpsb/v1/products/replace` | POST | Fully replaces a selected target product. |
| `/wpsb/v1/products/update-partial` | POST | Updates a selected part of a target product. |

Authentication headers:

```text
X-WPSB-Timestamp
X-WPSB-Nonce
X-WPSB-Signature
```

The signature is calculated with HMAC SHA-256 over:

```text
METHOD
REST_PATH
TIMESTAMP
NONCE
SHA256_BODY_HASH
```

Security behavior:

- Requests older than 5 minutes are rejected.
- Reused nonce/signature combinations are rejected.
- Failed authentication attempts are temporarily rate-limited.
- REST responses include no-cache headers.
- Admin AJAX actions require a WordPress nonce and WooCommerce/admin capability.
- Product payloads are size-limited and validated before import/update handlers run.

## Performance Notes

- Use Scheduled Transfer for many products or products with many images.
- Keep batch size small for image-heavy variable products.
- API timeout is measured in seconds and applies to each individual remote request, not the whole transfer run.
- If you transfer 100 products, each product request gets its own timeout window.
- Increasing the timeout can help image-heavy products, but very high values can tie up PHP workers. On shared hosting, 45 to 120 seconds is usually safer than 300 to 500 seconds.
- The plugin clears WooCommerce product transients after create/update operations.
- Mapping and job history use custom database tables instead of large autoloaded options.

## Logs

Logs are stored under:

```text
wp-content/uploads/woo-product-sync-bridge/sync.log
```

The plugin writes deny files in the log directory to reduce direct web access risk. Logs can be viewed, downloaded, or cleared from WooCommerce -> Settings -> Advanced -> Product Sync.

Logs include timestamps, operation type, source and target IDs where available, current step, failures, conflicts, and final status.

## File Structure

```text
woo-product-sync-bridge/
+-- woo-product-sync-bridge.php
+-- README.md
+-- includes/
|   +-- class-wpsb-admin.php
|   +-- class-wpsb-ajax.php
|   +-- class-wpsb-auth.php
|   +-- class-wpsb-connections.php
|   +-- class-wpsb-exporter.php
|   +-- class-wpsb-http-client.php
|   +-- class-wpsb-importer.php
|   +-- class-wpsb-installer.php
|   +-- class-wpsb-jobs.php
|   +-- class-wpsb-logger.php
|   +-- class-wpsb-mapping.php
|   +-- class-wpsb-rest-controller.php
|   +-- class-wpsb-settings.php
|   +-- class-wpsb-utils.php
+-- assets/
    +-- css/
    |   +-- admin.css
    +-- js/
        +-- admin.js
```

## Frequently Asked Questions

### Can I test this on Laragon local sites?

Yes. You can test with local sites such as `http://builtplugin.test/` and `http://shobpai.test/`. The plugin does not require a live server. Both sites need the plugin active, and the target URL must be reachable from the source site's PHP environment.

### Do I need to edit code on both websites?

For development, edit the plugin source where you are building it. For testing real transfer/update behavior, the receiving website must run the same updated plugin version, because the target site handles import, replace, image sync, variation sync, and update requests.

### Why does a SKU conflict appear?

WooCommerce requires product SKUs to be unique. If the target site already has the same SKU, the plugin does not overwrite it automatically. Instant mode shows the conflict and lets you replace the selected target product. Scheduled mode skips the product and logs the conflict.

### Why did only some products transfer?

Check the live log and the unsupported product box. Unsupported product types are skipped. Very large variable products with many images can hit server limits, so Scheduled Transfer and a smaller batch size are recommended.

### Are product reviews transferred?

No. Reviews and review counters are intentionally excluded in version 1.0.0.

### Does the plugin bypass cache plugins?

The plugin sends and returns admin/REST no-cache headers and clears WooCommerce product transients after updates. It does not rely on frontend cached pages, so LiteSpeed Cache, Redis Object Cache, and similar plugins should not cache the sync API responses.

### Is HTTP safe for production?

Use HTTPS for production. The plugin signs requests with HMAC, but HTTPS is still needed to encrypt product data and metadata during transfer.

## Troubleshooting

Connection test fails:

- Confirm the target site URL is correct.
- Confirm the target site's shared secret was copied correctly.
- Confirm WooCommerce and this plugin are active on the target site.
- Confirm WordPress REST API is not blocked by a security plugin or server rule.

Images do not transfer:

- Confirm the source image file exists.
- Confirm the target site can fetch the source image URL.
- On local `.test` domains, inline image fallback should handle many cases, but PHP upload limits and memory limits can still stop very large image payloads.

Scheduled jobs do not run:

- Check WooCommerce -> Status -> Scheduled Actions.
- Trigger WP-Cron or configure a real cron on production.
- Check the plugin log for retry messages.

Variable product price or image update misses a variation:

- Add unique SKUs to variations where possible.
- If SKU is missing, the plugin falls back to exact attribute matching.
- If attributes differ between source and target, that variation can be skipped and logged.

## Changelog

### 1.0.0

- Initial production-labeled release.
- Added manual product transfer and update workflows.
- Added simple and variable product support.
- Added product, gallery, variation image, and variation gallery sync.
- Added SKU conflict reporting and selected replace flow.
- Added paginated remote product search.
- Added HMAC REST authentication, replay protection, no-cache headers, and failed-auth rate limiting.
- Added custom mapping and job tables.
- Added dedicated log viewer, download, and clear actions.

## License

GPL-2.0-or-later. See `LICENSE` for details.
