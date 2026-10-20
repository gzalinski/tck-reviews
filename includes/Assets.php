<?php
/**
 * Front-end assets. Enqueued only when a shortcode or render function runs.
 *
 * @package Enspire\Reviews
 */

namespace Enspire\Reviews;

defined( 'ABSPATH' ) || exit;

final class Assets {

	public const HANDLE = 'enspire-reviews';

	private bool $style_done  = false;
	private bool $script_done = false;

	public function __construct( private Settings $settings ) {}

	/** wp_enqueue_scripts: register, and enqueue CSS early on singular pages that contain a shortcode (no FOUC). */
	public function register(): void {
		$this->ensure_registered();

		if ( is_singular() ) {
			$post = get_post();
			if ( $post && preg_match( '/\[enspire_reviews(_summary|_owner)?[\s\]]/', (string) $post->post_content ) ) {
				$this->enqueue_style();
			}
		}
	}

	public function enqueue_style(): void {
		if ( $this->style_done || ! $this->settings->get( 'load_css' ) ) {
			return;
		}
		$this->ensure_registered();
		wp_enqueue_style( self::HANDLE );
		$this->style_done = true;
	}

	public function enqueue(): void {
		$this->enqueue_style();
		if ( $this->script_done ) {
			return;
		}
		$this->ensure_registered();
		wp_enqueue_script( self::HANDLE );
		wp_add_inline_script( self::HANDLE, 'window.enspireReviews = ' . wp_json_encode( $this->config(), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ) . ';', 'before' );
		$this->script_done = true;
	}

	private function ensure_registered(): void {
		if ( ! wp_style_is( self::HANDLE, 'registered' ) ) {
			wp_register_style( self::HANDLE, ENSPIRE_REVIEWS_URL . 'assets/dist/reviews.css', array(), ENSPIRE_REVIEWS_VERSION );
		}
		if ( ! wp_script_is( self::HANDLE, 'registered' ) ) {
			wp_register_script(
				self::HANDLE,
				ENSPIRE_REVIEWS_URL . 'assets/dist/reviews.js',
				array(),
				ENSPIRE_REVIEWS_VERSION,
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
		}
	}

	/** @return array<string,mixed> */
	private function config(): array {
		$logged_in = is_user_logged_in();
		return array(
			'root'     => esc_url_raw( rest_url( 'enspire-reviews/v1/' ) ),
			// Only logged-in users need a nonce; a stale guest nonce from a cached page would break GETs.
			'nonce'    => $logged_in ? wp_create_nonce( 'wp_rest' ) : '',
			'loggedIn' => $logged_in,
			'i18n'     => array(
				'error'              => __( 'Something went wrong. Please try again.', 'enspire-reviews' ),
				'sessionExpired'     => __( 'Your session has expired. Please reload the page.', 'enspire-reviews' ),
				'ratingRequired'     => __( 'Please choose a rating.', 'enspire-reviews' ),
				/* translators: %d: number of characters */
				'tooShort'           => __( 'Your review must be at least %d characters long.', 'enspire-reviews' ),
				/* translators: %d: number of characters */
				'tooLong'            => __( 'Your review must be at most %d characters long.', 'enspire-reviews' ),
				'confirmDelete'      => __( 'Delete your review? This cannot be undone.', 'enspire-reviews' ),
				'confirmReport'      => __( 'Report this review to the moderators?', 'enspire-reviews' ),
				'confirmDeleteReply' => __( 'Delete your reply?', 'enspire-reviews' ),
				'reported'           => __( 'Reported', 'enspire-reviews' ),
				'deleted'            => __( 'Your review has been deleted.', 'enspire-reviews' ),
				'replyDeleted'       => __( 'Reply deleted.', 'enspire-reviews' ),
				'writeTitle'         => __( 'Write a review', 'enspire-reviews' ),
				'editTitle'          => __( 'Edit your review', 'enspire-reviews' ),
				'submit'             => __( 'Submit review', 'enspire-reviews' ),
				'update'             => __( 'Update review', 'enspire-reviews' ),
				'emptyAll'           => __( 'No reviews yet.', 'enspire-reviews' ),
				'emptyFiltered'      => __( 'No reviews match this filter.', 'enspire-reviews' ),
				/* translators: %d: number of reviews */
				'loaded'             => __( '%d reviews shown.', 'enspire-reviews' ),
			),
		);
	}
}
