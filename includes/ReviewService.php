<?php
/**
 * Business operations shared by the REST API and wp-admin:
 * save, edit, delete, status, reply, report. Recomputes aggregates and fires hooks.
 *
 * @package Enspire\Reviews
 */

namespace Enspire\Reviews;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class ReviewService {

	public const STATUSES = array( Repository::STATUS_APPROVED, Repository::STATUS_SPAM );

	public function __construct(
		private Settings $settings,
		private Repository $repo,
		private Target $target,
		private Aggregator $aggregator,
		private Eligibility $eligibility
	) {}

	/**
	 * Pipeline step 3: the target must be enabled and exist (published post / user with allowed role).
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function check_target( string $object_type, int $object_id ) {
		$target = $this->target->resolve( $object_type, $object_id );
		if ( ! $target ) {
			return new WP_Error( 'enspire_reviews_unknown_target', __( 'This item cannot be reviewed.', 'enspire-reviews' ), array( 'status' => 404 ) );
		}
		return $target;
	}

	/**
	 * Pipeline step 4.
	 *
	 * @param array<string,mixed> $target Resolved target.
	 * @return bool|WP_Error
	 */
	public function check_eligibility( int $user_id, array $target ) {
		return $this->eligibility->check( $user_id, $target );
	}

	/**
	 * Pipeline step 6 (defensive copy of the REST args rules, also used by PHP callers).
	 *
	 * @return string|WP_Error Clean content.
	 */
	public function validate_fields( int $rating, string $content ) {
		if ( $rating < 1 || $rating > 5 ) {
			return new WP_Error( 'enspire_reviews_rating', __( 'Please choose a rating from 1 to 5 stars.', 'enspire-reviews' ), array( 'status' => 400 ) );
		}
		$content = self::clean_text( $content );
		$error   = $this->length_error( $content );
		return $error ?? $content;
	}

	/** Length rule from settings. Returns null when OK. */
	public function length_error( string $content ): ?WP_Error {
		$len = mb_strlen( $content );
		$min = (int) $this->settings->get( 'min_length' );
		$max = (int) $this->settings->get( 'max_length' );

		if ( $len < max( 1, $min ) ) {
			return new WP_Error(
				'enspire_reviews_too_short',
				/* translators: %d: minimum number of characters */
				sprintf( _n( 'Your review must be at least %d character long.', 'Your review must be at least %d characters long.', max( 1, $min ), 'enspire-reviews' ), max( 1, $min ) ),
				array( 'status' => 400 )
			);
		}
		if ( $len > $max ) {
			return new WP_Error(
				'enspire_reviews_too_long',
				/* translators: %d: maximum number of characters */
				sprintf( _n( 'Your review must be at most %d character long.', 'Your review must be at most %d characters long.', $max, 'enspire-reviews' ), $max ),
				array( 'status' => 400 )
			);
		}
		return null;
	}

	/** Plain text only: tags stripped, line breaks kept. */
	public static function clean_text( string $text ): string {
		return trim( sanitize_textarea_field( $text ) );
	}

	public function can_edit( object $review ): bool {
		if ( ! $this->settings->get( 'allow_edit' ) ) {
			return false;
		}
		$days = (int) $this->settings->get( 'edit_window_days' );
		if ( $days <= 0 ) {
			return true;
		}
		return ( time() - (int) strtotime( $review->created_at . ' UTC' ) ) <= $days * DAY_IN_SECONDS;
	}

	public function can_moderate( int $user_id = 0 ): bool {
		$user_id = $user_id ?: get_current_user_id();
		return $user_id > 0 && user_can( $user_id, Installer::CAP );
	}

	/**
	 * Pipeline steps 7–9: insert, or update the user's own row (UNIQUE key).
	 *
	 * @param array<string,mixed> $target Resolved target.
	 * @return array{review:object,is_new:bool,target:array}|WP_Error
	 */
	public function save( int $user_id, array $target, int $rating, string $content, string $ip_hash ) {
		$content = $this->validate_fields( $rating, $content );
		if ( is_wp_error( $content ) ) {
			return $content;
		}

		$existing = $this->repo->find_by_author( $target['type'], (int) $target['id'], $user_id );
		if ( $existing ) {
			return $this->update( $existing, $target, $rating, $content, $ip_hash, false );
		}

		$now = current_time( 'mysql', true );
		$id  = $this->repo->insert(
			array(
				'object_type' => $target['type'],
				'object_id'   => (int) $target['id'],
				'author_id'   => $user_id,
				'rating'      => $rating,
				'content'     => $content,
				'status'      => Repository::STATUS_APPROVED, // v1: published immediately.
				'ip_hash'     => $ip_hash,
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);

		if ( ! $id ) {
			// Lost a race against the one_per_user key, or a DB error.
			if ( $this->repo->find_by_author( $target['type'], (int) $target['id'], $user_id ) ) {
				return new WP_Error( 'enspire_reviews_exists', __( 'You have already reviewed this.', 'enspire-reviews' ), array( 'status' => 409 ) );
			}
			return new WP_Error( 'enspire_reviews_db', __( 'Your review could not be saved. Please try again.', 'enspire-reviews' ), array( 'status' => 500 ) );
		}

		return $this->after_save( (int) $id, $target, true );
	}

	/**
	 * Edit an existing review. Authors are bound by the edit window; moderators ($force) are not.
	 *
	 * @param array<string,mixed> $target Resolved target.
	 * @return array{review:object,is_new:bool,target:array}|WP_Error
	 */
	public function update( object $review, array $target, int $rating, string $content, ?string $ip_hash, bool $force ) {
		if ( ! $force && ! $this->can_edit( $review ) ) {
			return new WP_Error(
				'enspire_reviews_exists',
				$this->settings->get( 'allow_edit' )
					? __( 'The time to edit your review has passed.', 'enspire-reviews' )
					: __( 'You have already reviewed this.', 'enspire-reviews' ),
				array( 'status' => 409 )
			);
		}

		$content = $this->validate_fields( $rating, $content );
		if ( is_wp_error( $content ) ) {
			return $content;
		}

		$data = array(
			'rating'     => $rating,
			'content'    => $content,
			'updated_at' => current_time( 'mysql', true ),
		);
		if ( null !== $ip_hash ) {
			$data['ip_hash'] = $ip_hash;
		}
		$this->repo->update( (int) $review->id, $data );

		return $this->after_save( (int) $review->id, $target, false );
	}

	/**
	 * @param array<string,mixed> $target Resolved target.
	 * @return array{review:object,is_new:bool,target:array}
	 */
	private function after_save( int $id, array $target, bool $is_new ): array {
		$review = $this->repo->find( $id );
		$this->aggregator->recompute( $target['type'], (int) $target['id'] );

		/**
		 * Fires after a review was created or edited.
		 *
		 * @param object $review Review row.
		 * @param bool   $is_new True on create.
		 * @param array  $target Resolved target.
		 */
		do_action( 'enspire_reviews_saved', $review, $is_new, $target );

		return array(
			'review' => $review,
			'is_new' => $is_new,
			'target' => $target,
		);
	}

	public function delete( object $review ): void {
		$this->repo->delete( (int) $review->id );
		$this->aggregator->recompute( $review->object_type, (int) $review->object_id );

		/**
		 * Fires after a review was deleted.
		 *
		 * @param object $review The deleted row.
		 */
		do_action( 'enspire_reviews_deleted', $review );
	}

	public function set_status( object $review, string $status ): bool {
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}
		if ( $status === $review->status ) {
			return true;
		}
		$this->repo->set_status( (int) $review->id, $status );
		$this->aggregator->recompute( $review->object_type, (int) $review->object_id );

		/**
		 * Fires after a moderator changed a review's status.
		 *
		 * @param int    $review_id  Review ID.
		 * @param string $new_status approved|spam.
		 * @param string $old_status approved|spam.
		 * @param object $review     Row before the change.
		 */
		do_action( 'enspire_reviews_status_changed', (int) $review->id, $status, (string) $review->status, $review );
		return true;
	}

	/** Set (string) or remove (null) the owner's reply. Returns the fresh row. */
	public function set_reply( object $review, ?string $reply ): object {
		$reply = null === $reply ? null : self::clean_text( $reply );
		$this->repo->set_reply( (int) $review->id, '' === $reply ? null : $reply );
		return $this->repo->find( (int) $review->id ) ?? $review;
	}

	/** @return bool|WP_Error */
	public function report( object $review, int $user_id ) {
		if ( ! $this->repo->add_report( (int) $review->id, $user_id ) ) {
			return new WP_Error( 'enspire_reviews_already_reported', __( 'You have already reported this review.', 'enspire-reviews' ), array( 'status' => 409 ) );
		}
		return true;
	}

	/** Owner of the review's target, or 0 when the target is gone or disabled. */
	public function owner_of( object $review ): int {
		$target = $this->target->resolve( $review->object_type, (int) $review->object_id );
		return $target ? (int) $target['owner_id'] : 0;
	}
}
