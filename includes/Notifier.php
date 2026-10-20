<?php
/**
 * Emails the admin and the target owner about new reviews.
 *
 * @package Enspire\Reviews
 */

namespace Enspire\Reviews;

defined( 'ABSPATH' ) || exit;

final class Notifier {

	public function __construct( private Settings $settings ) {}

	public function register(): void {
		add_action( 'enspire_reviews_saved', array( $this, 'on_saved' ), 20, 3 );
	}

	/**
	 * @param object              $review Review row.
	 * @param bool                $is_new Created (not edited).
	 * @param array<string,mixed> $target Resolved target.
	 */
	public function on_saved( $review, $is_new, $target ): void {
		if ( ! $is_new || ! is_object( $review ) || ! is_array( $target ) ) {
			return;
		}

		$author      = get_userdata( (int) $review->author_id );
		$author_name = $author ? $author->display_name : __( 'Former user', 'enspire-reviews' );
		$site        = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		$stars       = str_repeat( '★', (int) $review->rating ) . str_repeat( '☆', 5 - (int) $review->rating );

		/* translators: 1: site name, 2: reviewed item */
		$subject = sprintf( __( '[%1$s] New review: %2$s', 'enspire-reviews' ), $site, wp_specialchars_decode( (string) $target['title'], ENT_QUOTES ) );

		$body = implode(
			"\n",
			array(
				/* translators: %s: reviewed item */
				sprintf( __( 'A new review was posted for %s.', 'enspire-reviews' ), wp_specialchars_decode( (string) $target['title'], ENT_QUOTES ) ),
				'',
				/* translators: %s: reviewer name */
				sprintf( __( 'Author: %s', 'enspire-reviews' ), $author_name ),
				/* translators: 1: stars, 2: rating */
				sprintf( __( 'Rating: %1$s (%2$d/5)', 'enspire-reviews' ), $stars, (int) $review->rating ),
				'',
				(string) $review->content,
				'',
				(string) $target['url'],
			)
		);

		if ( $this->settings->get( 'notify_admin' ) ) {
			$admin_body = $body . "\n\n" .
				/* translators: %s: admin URL */
				sprintf( __( 'Moderate: %s', 'enspire-reviews' ), admin_url( 'admin.php?page=enspire-reviews' ) );
			wp_mail( (string) get_option( 'admin_email' ), $subject, $admin_body );
		}

		$owner_id = (int) ( $target['owner_id'] ?? 0 );
		if ( $this->settings->get( 'notify_owner' ) && $owner_id && $owner_id !== (int) $review->author_id ) {
			$owner = get_userdata( $owner_id );
			if ( $owner && is_email( $owner->user_email ) ) {
				wp_mail( $owner->user_email, $subject, $body );
			}
		}
	}
}
