<?php
/**
 * Who may review a target: not the owner, account age, verified email, site filter.
 *
 * @package Enspire\Reviews
 */

namespace Enspire\Reviews;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Eligibility {

	public function __construct( private Settings $settings ) {}

	/**
	 * @param array{type:string,id:int,owner_id:int} $target Resolved target.
	 * @return bool|WP_Error True when allowed.
	 */
	public function check( int $user_id, array $target ) {
		if ( $user_id <= 0 ) {
			return new WP_Error( 'enspire_reviews_login_required', __( 'Please log in to leave a review.', 'enspire-reviews' ), array( 'status' => 401 ) );
		}

		if ( $user_id === (int) $target['owner_id'] ) {
			return new WP_Error( 'enspire_reviews_own_target', __( "You can't review your own listing.", 'enspire-reviews' ), array( 'status' => 403 ) );
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'enspire_reviews_login_required', __( 'Please log in to leave a review.', 'enspire-reviews' ), array( 'status' => 401 ) );
		}

		$min_days = (int) $this->settings->get( 'min_account_age' );
		if ( $min_days > 0 ) {
			$registered = strtotime( $user->user_registered . ' UTC' );
			if ( ! $registered || ( time() - $registered ) < $min_days * DAY_IN_SECONDS ) {
				return new WP_Error(
					'enspire_reviews_account_too_new',
					sprintf(
						/* translators: %d: number of days */
						_n( 'Your account must be at least %d day old to leave a review.', 'Your account must be at least %d days old to leave a review.', $min_days, 'enspire-reviews' ),
						$min_days
					),
					array( 'status' => 403 )
				);
			}
		}

		if ( $this->settings->get( 'require_verified_email' ) && ! $this->is_email_verified( $user ) ) {
			return new WP_Error( 'enspire_reviews_email_unverified', __( 'Please verify your email address before leaving a review.', 'enspire-reviews' ), array( 'status' => 403 ) );
		}

		/**
		 * Site-specific review rules (e.g. "bought from this vendor").
		 *
		 * Return true to allow, a WP_Error (its message is shown to the user) or false to deny.
		 *
		 * @param bool|WP_Error $allowed     Default true.
		 * @param int           $user_id     Reviewer.
		 * @param string        $object_type Post type slug or 'user'.
		 * @param int           $object_id   Post or user ID.
		 * @param array         $target      Resolved target.
		 */
		$result = apply_filters( 'enspire_reviews_can_review', true, $user_id, $target['type'], (int) $target['id'], $target );

		if ( is_wp_error( $result ) ) {
			if ( ! isset( ( (array) $result->get_error_data() )['status'] ) ) {
				$result->add_data( array( 'status' => 403 ) );
			}
			return $result;
		}
		if ( true !== $result ) {
			return new WP_Error( 'enspire_reviews_not_allowed', __( "You can't review this.", 'enspire-reviews' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * WordPress core has no "verified email" flag. Default: a user is verified once
	 * they set their password from the emailed link (core then clears
	 * user_activation_key). Plugins with real verification should use the filter.
	 */
	public function is_email_verified( \WP_User $user ): bool {
		$verified = '' === (string) $user->user_activation_key || (bool) get_user_meta( $user->ID, '_enspire_email_verified', true );

		/**
		 * Filters whether a user's email address counts as verified.
		 *
		 * @param bool     $verified Default check.
		 * @param \WP_User $user     User.
		 */
		return (bool) apply_filters( 'enspire_reviews_email_verified', $verified, $user );
	}
}
