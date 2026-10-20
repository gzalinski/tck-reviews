<?php
/**
 * schema.org aggregateRating.
 *
 * - enspire_reviews_schema_aggregate_rating() returns the AggregateRating array so the
 *   theme can add it to its own schema (e.g. directory_seo_single_schema()).
 * - Optional standalone output: a JSON-LD block in the footer for every target whose
 *   full reviews block was rendered (Settings → Output). Off by default to avoid
 *   duplicating the theme's schema.
 *
 * @package Enspire\Reviews
 */

namespace Enspire\Reviews\Integrations;

use Enspire\Reviews\Settings;
use Enspire\Reviews\Target;

defined( 'ABSPATH' ) || exit;

final class Schema {

	/** @var array<string,array<string,mixed>> */
	private array $queue = array();

	public function __construct( private Settings $settings, private Target $target ) {}

	/** @return array<string,mixed>|null */
	public function aggregate_rating( string $object_type, int $object_id ): ?array {
		if ( ! $this->settings->get( 'jsonld' ) ) {
			return null;
		}
		$agg = $this->target->get_aggregate( $object_type, $object_id );
		if ( ! $agg ) {
			return null;
		}
		return array(
			'@type'       => 'AggregateRating',
			'ratingValue' => round( (float) $agg['avg'], 2 ),
			'reviewCount' => (int) $agg['count'],
			'bestRating'  => 5,
			'worstRating' => 1,
		);
	}

	/**
	 * @param array<string,mixed> $target Resolved target.
	 */
	public function queue( array $target ): void {
		if ( $this->settings->get( 'jsonld' ) && $this->settings->get( 'jsonld_standalone' ) ) {
			$this->queue[ $target['type'] . ':' . $target['id'] ] = $target;
		}
	}

	public function print_footer(): void {
		foreach ( $this->queue as $target ) {
			$rating = $this->aggregate_rating( $target['type'], (int) $target['id'] );
			if ( ! $rating ) {
				continue;
			}

			$type = match ( $target['type'] ) {
				Target::USER => 'Organization',
				'product'    => 'Product',
				default      => 'LocalBusiness',
			};

			$data = array(
				'@context'        => 'https://schema.org',
				'@type'           => $type,
				'name'            => wp_strip_all_tags( (string) $target['title'] ),
				'url'             => (string) $target['url'],
				'aggregateRating' => $rating,
			);

			/**
			 * Filters the standalone JSON-LD for one target. Return null to skip it.
			 *
			 * @param array|null $data   JSON-LD data.
			 * @param array      $target Resolved target.
			 */
			$data = apply_filters( 'enspire_reviews_schema', $data, $target );
			if ( ! is_array( $data ) ) {
				continue;
			}

			printf(
				"<script type=\"application/ld+json\">%s</script>\n",
				wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with < > escaped.
			);
		}
		$this->queue = array();
	}
}
