<?php
/**
 * Honeypot, minimum submit time (signed timestamp) and captcha (Turnstile / reCAPTCHA v2).
 *
 * @package Enspire\Reviews
 */

namespace Enspire\Reviews;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class AntiBot {

	public const HONEYPOT = 'er_website';
	public const TOKEN    = 'er_ts';

	public function __construct( private Settings $settings ) {}

	/** Signed form-render timestamp, verified on submit. */
	public function token(): string {
		$time = time();
		return $time . '.' . $this->sign( $time );
	}

	public function captcha_provider(): string {
		$provider = (string) $this->settings->get( 'captcha' );
		if ( 'none' === $provider || '' === (string) $this->settings->get( 'captcha_site_key' ) || '' === (string) $this->settings->get( 'captcha_secret_key' ) ) {
			return 'none';
		}
		return $provider;
	}

	/** Widget markup. Only the public site key is printed; the secret stays on the server. */
	public function widget_html(): string {
		$site_key = (string) $this->settings->get( 'captcha_site_key' );
		switch ( $this->captcha_provider() ) {
			case 'turnstile':
				return sprintf( '<div class="er-captcha cf-turnstile" data-sitekey="%s"></div>', esc_attr( $site_key ) );
			case 'recaptcha':
				return sprintf( '<div class="er-captcha g-recaptcha" data-sitekey="%s"></div>', esc_attr( $site_key ) );
		}
		return '';
	}

	public function enqueue_captcha(): void {
		switch ( $this->captcha_provider() ) {
			case 'turnstile':
				wp_enqueue_script( 'enspire-reviews-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, array( 'in_footer' => true, 'strategy' => 'async' ) ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
				break;
			case 'recaptcha':
				wp_enqueue_script( 'enspire-reviews-recaptcha', 'https://www.google.com/recaptcha/api.js', array(), null, array( 'in_footer' => true, 'strategy' => 'async' ) ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
				break;
		}
	}

	/**
	 * Pipeline step 5: honeypot empty, min submit time, captcha verified server-side.
	 *
	 * @param array<string,mixed> $params Request params.
	 * @return bool|WP_Error
	 */
	public function verify( array $params ) {
		if ( '' !== trim( (string) ( $params[ self::HONEYPOT ] ?? '' ) ) ) {
			return new WP_Error( 'enspire_reviews_spam', __( 'Your review could not be submitted.', 'enspire-reviews' ), array( 'status' => 400 ) );
		}

		$min = (int) $this->settings->get( 'min_submit_time' );
		if ( $min > 0 ) {
			$parts = explode( '.', (string) ( $params[ self::TOKEN ] ?? '' ), 2 );
			$time  = (int) $parts[0];
			if ( 2 !== count( $parts ) || $time <= 0 || ! hash_equals( $this->sign( $time ), $parts[1] ) ) {
				return new WP_Error( 'enspire_reviews_token', __( 'Please reload the page and try again.', 'enspire-reviews' ), array( 'status' => 400 ) );
			}
			if ( time() - $time < $min ) {
				return new WP_Error( 'enspire_reviews_too_fast', __( 'That was quick! Please take a moment and submit again.', 'enspire-reviews' ), array( 'status' => 400 ) );
			}
		}

		$provider = $this->captcha_provider();
		if ( 'none' !== $provider ) {
			$response = trim( (string) ( $params['captcha_token'] ?? '' ) );
			if ( '' === $response ) {
				return new WP_Error( 'enspire_reviews_captcha', __( 'Please complete the captcha.', 'enspire-reviews' ), array( 'status' => 400 ) );
			}
			if ( ! $this->verify_captcha( $provider, $response ) ) {
				return new WP_Error( 'enspire_reviews_captcha', __( 'Captcha verification failed. Please try again.', 'enspire-reviews' ), array( 'status' => 400 ) );
			}
		}

		return true;
	}

	private function verify_captcha( string $provider, string $response ): bool {
		$url = 'turnstile' === $provider
			? 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
			: 'https://www.google.com/recaptcha/api/siteverify';

		$result = wp_remote_post(
			$url,
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => (string) $this->settings->get( 'captcha_secret_key' ),
					'response' => $response,
					'remoteip' => RateLimiter::client_ip(),
				),
			)
		);
		if ( is_wp_error( $result ) || 200 !== (int) wp_remote_retrieve_response_code( $result ) ) {
			return false;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $result ), true );
		return is_array( $body ) && ! empty( $body['success'] );
	}

	private function sign( int $time ): string {
		return substr( hash_hmac( 'sha256', $time . '|' . get_current_user_id(), wp_salt( 'nonce' ) ), 0, 32 );
	}
}
