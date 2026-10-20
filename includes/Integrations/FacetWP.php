<?php
/**
 * FacetWP integration (loaded only when FacetWP is active).
 *
 * 1. Post targets (e.g. directory listings): a Rating facet with data source
 *    `_enspire_rating_avg` works natively; we re-index the post after each change.
 * 2. Posts that inherit their author's rating (e.g. products → vendor): facets listed in
 *    Settings → FacetWP get the author's `_enspire_rating_avg` user meta as their value.
 *    After a user's aggregate changes, all their posts of those types are re-indexed in
 *    background batches (Action Scheduler if available, else WP-Cron), deduplicated per user.
 *
 * @package Enspire\Reviews
 */

namespace Enspire\Reviews\Integrations;

use Enspire\Reviews\Repository;
use Enspire\Reviews\Settings;
use Enspire\Reviews\Target;

defined( 'ABSPATH' ) || exit;

final class FacetWP {

	public const HOOK  = 'enspire_reviews_facetwp_reindex_author';
	public const BATCH = 50;
	private const GROUP = 'enspire-reviews';

	public function __construct( private Settings $settings ) {}

	public static function active(): bool {
		return function_exists( 'FWP' );
	}

	public function register(): void {
		add_action( 'enspire_reviews_aggregate_updated', array( $this, 'on_aggregate_updated' ), 10, 2 );
		add_filter( 'facetwp_index_row', array( $this, 'index_row' ), 10, 2 );
		add_action( self::HOOK, array( $this, 'reindex_author_batch' ), 10, 2 );
	}

	/**
	 * @param string $object_type Post type or 'user'.
	 * @param int    $object_id   Post or user ID.
	 */
	public function on_aggregate_updated( $object_type, $object_id ): void {
		if ( Target::USER === $object_type ) {
			if ( $this->author_post_types() && $this->author_facets() ) {
				$this->schedule( (int) $object_id, 0, true );
			}
			return;
		}
		$this->index_post( (int) $object_id );
	}

	/**
	 * Author-rating facets: value = the post author's average.
	 * No reviews → empty value → FacetWP skips the row, so the post drops out while
	 * the rating filter is active (expected).
	 *
	 * @param array<string,mixed> $params Row params.
	 * @return array<string,mixed>
	 */
	public function index_row( $params, $indexer = null ): array {
		$params = (array) $params;
		if ( ! in_array( (string) ( $params['facet_name'] ?? '' ), $this->author_facets(), true ) ) {
			return $params;
		}

		$post_id = (int) ( $params['post_id'] ?? 0 );
		if ( ! in_array( get_post_type( $post_id ), $this->author_post_types(), true ) ) {
			$params['facet_value'] = '';
			return $params;
		}

		$author = (int) get_post_field( 'post_author', $post_id );
		$avg    = $author ? get_user_meta( $author, Repository::META_AVG, true ) : '';
		if ( '' === $avg || false === $avg ) {
			$params['facet_value'] = '';
			return $params;
		}

		$params['facet_value']         = round( (float) $avg, 2 );
		$params['facet_display_value'] = $params['facet_value'];
		return $params;
	}

	/**
	 * Re-index one batch of an author's posts, then queue the next batch.
	 *
	 * @param int $author_id User ID.
	 * @param int $offset    Offset.
	 */
	public function reindex_author_batch( $author_id, $offset = 0 ): void {
		$types = $this->author_post_types();
		if ( ! $types ) {
			return;
		}

		$ids = get_posts(
			array(
				'post_type'        => $types,
				'post_status'      => 'publish',
				'author'           => (int) $author_id,
				'fields'           => 'ids',
				'posts_per_page'   => self::BATCH,
				'offset'           => max( 0, (int) $offset ),
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);

		foreach ( $ids as $post_id ) {
			$this->index_post( (int) $post_id );
		}

		if ( count( $ids ) === self::BATCH ) {
			$this->schedule( (int) $author_id, (int) $offset + self::BATCH, false );
		}
	}

	private function schedule( int $author_id, int $offset, bool $dedupe ): void {
		$args = array( $author_id, $offset );

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			if ( $dedupe && function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK, $args, self::GROUP ) ) {
				return;
			}
			as_enqueue_async_action( self::HOOK, $args, self::GROUP );
			return;
		}

		if ( $dedupe && wp_next_scheduled( self::HOOK, $args ) ) {
			return;
		}
		wp_schedule_single_event( time(), self::HOOK, $args );
	}

	private function index_post( int $post_id ): void {
		if ( $post_id > 0 && self::active() && isset( FWP()->indexer ) && method_exists( FWP()->indexer, 'index' ) ) {
			FWP()->indexer->index( $post_id );
		}
	}

	/** @return string[] */
	private function author_post_types(): array {
		return array_map( 'strval', (array) $this->settings->get( 'facetwp_author_post_types' ) );
	}

	/** @return string[] */
	private function author_facets(): array {
		return array_map( 'strval', (array) $this->settings->get( 'facetwp_author_facets' ) );
	}
}
