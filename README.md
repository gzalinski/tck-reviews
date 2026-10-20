# Enspire Reviews

Standalone WordPress plugin: 1–5 star reviews for any post type and for users (e.g. WC Vendors vendors). Shortcodes, PHP API, REST API, moderation, FacetWP and JSON-LD integration. WP comments are not used.

Requires WordPress 6.4+ and PHP 8.1+.

## Setup

1. Upload `enspire-reviews/` to `wp-content/plugins/` and activate.
2. **Settings → Enspire Reviews → Targets**: tick the post types that can be reviewed (e.g. `directory`). For vendors: enable users and select the `vendor` role only.
3. Output the block with a shortcode or the PHP API.

## Shortcodes

```
[enspire_reviews object_type="directory" object_id="123"]
[enspire_reviews object_type="user" object_id="45" pagination="pages" per_page="5"]
[enspire_reviews_summary object_type="directory" object_id="123" link="#reviews"]

# Product page (WC Vendors): shop reviews for the product's vendor
[enspire_reviews object_type="user" object_id="author" title="Shop reviews"]
[enspire_reviews_summary object_type="user" object_id="author"]

# "Reviews about me" for the logged-in owner (reply + report only)
[enspire_reviews_owner]
```

| Attribute | Values (default) |
|---|---|
| `object_type` | post type slug or `user` (current post's type) |
| `object_id` | int, `author` (post_author of the current post; queried author on an author archive) (current post ID) |
| `show` | `summary,breakdown,filters,list,form` (all; filters/breakdown follow the Display settings) — order = output order |
| `pagination` | `load_more` / `pages` (setting) |
| `per_page` | int, max 50 (setting) |
| `sort` | `newest` / `highest` / `lowest` (`newest`) |
| `rating` | `all` / `positive` / `negative` / `1`..`5` (`all`) |
| `form_position` | `top` / `bottom` |
| `title` | optional heading, e.g. "Shop reviews" |

The first block on a page gets `id="reviews"` (then `reviews-2`, …). The guest login link returns to it.

## PHP API

```php
echo enspire_reviews_render( 'directory', get_the_ID(), array( 'pagination' => 'load_more' ) );
echo enspire_reviews_summary_html( 'user', $vendor_id );
$summary = enspire_reviews_summary( 'directory', $post_id ); // [ avg, count, breakdown ] or null
$rating  = enspire_reviews_schema_aggregate_rating( 'directory', $post_id ); // AggregateRating array or null
echo enspire_reviews_stars_html( 4.5 );
```

## Owner replies

Settings → Enspire Reviews → Moderation → "Owners can reply to reviews" (on by default). When off, reply buttons disappear, existing replies are hidden on the site and in the REST output, and the reply endpoints return 403. Replies stay in the database and reappear when the setting is turned back on. Moderators still see and can delete them in wp-admin → Reviews.

## Templates

Copy any file from `templates/` to `{your-theme}/enspire-reviews/` to override it:
`reviews.php summary.php breakdown.php filters.php list.php item.php form.php pagination.php owner.php`.
Templates receive their data in `$args`. Keep the `data-er-*` attributes — the script relies on them.

Restyle with CSS custom properties on `.er-reviews` (`--er-star`, `--er-accent`, `--er-radius`, …) or turn the plugin CSS off (Settings → Output).

## REST API (`/wp-json/enspire-reviews/v1`)

| Route | Method | Who |
|---|---|---|
| `/reviews` | GET | public (approved only) → `{ html, items, has_more, total, pages, page, pagination }` |
| `/reviews` | POST | logged in — creates, or edits the user's own review |
| `/reviews/{id}` | PATCH, DELETE | author or moderator |
| `/reviews/{id}/reply` | POST, DELETE | target owner (403 when replies are turned off) |
| `/reviews/{id}/report` | POST | logged in, not the author |
| `/reviews/{id}/status` | POST | `enspire_moderate_reviews` |
| `/summary` | GET | public → `{ avg, count, breakdown, html, breakdown_html }` |

Cookie auth + `X-WP-Nonce`. Status codes: 400 invalid, 401 not logged in, 403 not allowed, 404 unknown/disabled target, 409 already reviewed / edit window closed / already reported, 429 rate limit.
Security plugins must allow guest access to the two GET routes.

## Hooks

Filters
- `enspire_reviews_can_review( true|WP_Error, $user_id, $object_type, $object_id, $target )` — site rules, e.g. "bought from this vendor".
- `enspire_reviews_owner_id( $owner_id, $object_type, $object_id )`
- `enspire_reviews_item_data( $data, $row, $target )`
- `enspire_reviews_template_path( $path, $file )`
- `enspire_reviews_shortcode_atts( $atts, $tag )`
- `enspire_reviews_email_verified( $verified, $user )` — plug in real email verification.
- `enspire_reviews_client_ip( $ip )` — return the real client IP behind a proxy/CDN.
- `enspire_reviews_schema( $data, $target )` — standalone JSON-LD (return null to skip).

Actions
- `enspire_reviews_saved( $review, $is_new, $target )`
- `enspire_reviews_status_changed( $review_id, $new_status, $old_status, $review )`
- `enspire_reviews_deleted( $review )`
- `enspire_reviews_aggregate_updated( $object_type, $object_id, $agg )`

## Aggregates

Recomputed from approved rows after every change (never incremented) and stored as post meta or user meta:
`_enspire_rating_avg`, `_enspire_rating_count`, `_enspire_rating_breakdown`. With no approved reviews the meta is removed.

## FacetWP

- **Directory listings by their own rating**: Rating facet, data source `_enspire_rating_avg`. Posts are re-indexed after each change.
- **Products by their vendor's rating**: Settings → FacetWP → tick `product` and enter the facet name (e.g. `product_vendor_rating`). Use a Rating facet whose data source exists on every product (e.g. "Post Type"); the value is replaced with the vendor's average. Products of vendors without reviews get no row and drop out while the filter is active. After a vendor's rating changes, their products are re-indexed in background batches of 50 (Action Scheduler if available, else WP-Cron).
- Run a full FacetWP re-index after installing or changing these settings.

## WC Vendors notes

- Settings → Targets: users enabled, allowed role `vendor` only.
- Product template: `[enspire_reviews object_type="user" object_id="author" title="Shop reviews"]`. Every product of a vendor shows the same shop reviews.
- With WC Vendors Pro, disable its Vendor Ratings to avoid two vendor ratings.

## Security

- Only logged-in users can write. The author is always `get_current_user_id()`, never read from the request.
- `object_type` from the browser is never trusted: it must be an enabled post type (published post) or `user` (enabled, allowed role).
- All SQL is in `includes/Repository.php` and uses `$wpdb->prepare()`; sort/filter values map through whitelists.
- Plain-text storage; output through `esc_html()` + `wpautop()`, `esc_attr()`, `esc_url()`.
- Anti-spam: per-user write limit, per-IP read limit, honeypot, signed minimum submit time, optional Turnstile / reCAPTCHA v2 verified server-side (secret never sent to the browser).
- The raw IP is never stored, only an HMAC (`ip_hash`).
- Registration is the spam entry point: protect the registration form outside this plugin.

## Privacy

- Personal data exporter and eraser are registered.
- Erasing a user or deleting them anonymizes their reviews ("Former user"; stored as `author_id` NULL, exposed as 0) and keeps the ratings. Reviews about a deleted user are deleted; their aggregate meta goes with the user.
- Permanently deleting a post deletes the reviews about it.

## Uninstall

Data is kept by default. Turn on Settings → Advanced → "Delete all data on uninstall" before deleting the plugin to drop the tables, options and rating meta.

## Development

`assets/src` holds the sources; `assets/dist` is what the plugin loads. There is no build step yet — copy `src` to `dist` (or add a minifier) after editing.
