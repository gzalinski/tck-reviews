<?php
/**
 * Recomputes avg / count / breakdown from approved rows. Never increments.
 *
 * @package Enspire\Reviews
 */

namespace Enspire\Reviews;

defined( 'ABSPATH' ) || exit;

final class Aggregator {

	public function __construct( private Repository $repo, private Target $target ) {}

	/**
	 * @return array{avg:float,count:int,breakdown:array<int,int>}
	 */
	public function recompute( string $object_type, int $object_id ): array {
		$breakdown = Target::empty_breakdown();
		$sum       = 0;
		$count     = 0;

		foreach ( $this->repo->rating_counts( $object_type, $object_id ) as $rating => $n ) {
			if ( isset( $breakdown[ $rating ] ) ) {
				$breakdown[ $rating ] = $n;
				$sum                 += $rating * $n;
				$count               += $n;
			}
		}

		$agg = array(
			'avg'       => $count ? round( $sum / $count, 2 ) : 0.0,
			'count'     => $count,
			'breakdown' => $breakdown,
		);

		$this->target->save_aggregate( $object_type, $object_id, $agg );

		/**
		 * Fires after a target's aggregate was recomputed and stored.
		 *
		 * @param string $object_type Post type slug or 'user'.
		 * @param int    $object_id   Post or user ID.
		 * @param array  $agg         { avg, count, breakdown }.
		 */
		do_action( 'enspire_reviews_aggregate_updated', $object_type, $object_id, $agg );

		return $agg;
	}
}
