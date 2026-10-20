<?php
/**
 * Public PHP API.
 *
 * @package Enspire\Reviews
 */

use Enspire\Reviews\Plugin;
use Enspire\Reviews\Template;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin instance (services: settings, repository, target, service, renderer…).
 */
function enspire_reviews(): Plugin {
	return Plugin::instance();
}

/**
 * Full reviews block: summary, breakdown, filters, list, form.
 *
 * @param string     $object_type Post type slug, 'user', or '' for the current post's type.
 * @param int|string $object_id   ID, 'author' (post_author of the current post), or '' for the current post / queried author.
 * @param array      $atts        Same keys as the [enspire_reviews] shortcode.
 */
function enspire_reviews_render( string $object_type, $object_id, array $atts = array() ): string {
	return enspire_reviews()->renderer->render( $object_type, $object_id, $atts );
}

/**
 * Compact summary: stars + average + count. For cards and map popups.
 *
 * @param string     $object_type Post type slug or 'user'.
 * @param int|string $object_id   ID or 'author'.
 * @param array      $atts        Optional: link (URL to the full reviews).
 */
function enspire_reviews_summary_html( string $object_type, $object_id, array $atts = array() ): string {
	return enspire_reviews()->renderer->summary_html( $object_type, $object_id, $atts );
}

/**
 * Aggregate data for a target.
 *
 * @param string     $object_type Post type slug or 'user'.
 * @param int|string $object_id   ID or 'author'.
 * @return array{avg: float, count: int, breakdown: array<int,int>}|null Null when the target has no approved reviews or its type is disabled.
 */
function enspire_reviews_summary( string $object_type, $object_id ): ?array {
	return enspire_reviews()->renderer->summary( $object_type, $object_id );
}

/**
 * Accessible star rating markup (0–5, decimals allowed).
 */
function enspire_reviews_stars_html( float $rating ): string {
	return Template::stars( $rating );
}

/**
 * schema.org AggregateRating for a target, or null (no reviews / JSON-LD disabled).
 * Use it to add `aggregateRating` to the theme's own schema.
 *
 * @return array<string,mixed>|null
 */
function enspire_reviews_schema_aggregate_rating( string $object_type, int $object_id ): ?array {
	return enspire_reviews()->schema->aggregate_rating( $object_type, $object_id );
}

/**
 * FacetWP facet names that index the post author's rating (Settings → FacetWP).
 *
 * @return string[]
 */
function enspire_reviews_author_rating_facets(): array {
	return array_map( 'strval', (array) enspire_reviews()->settings->get( 'facetwp_author_facets' ) );
}
