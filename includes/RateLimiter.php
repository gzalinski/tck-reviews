<?php
/**
 * Fixed-window counters stored in transients (object cache when available).
 *
 * @package Enspire\Reviews
 */

namespace Enspire\Reviews;

defined( 'ABSPATH' ) || exit;

final class RateLimiter {

	/**
	 * Count one hit. Returns false when the limit for the current window is reached.
	 *
	 * @param string $bucket Counter group ('write', 'read', 'report').
	 * @param string $key    User ID or ip_hash.
	 * @param int    $limit  Max hits per window. 0 disables the limit.
	 * @param int    $window Window length in seconds.
	 */
	public function hit( string $bucket, string $key, int $limit, int $window ): bool {
		if ( $limit <= 0 ) {
			return true;
		}

		$name = 'enspire_rl_' . md5( $bucket . '|' . $key );
		$now  = time();
		$data = get_transient( $name );

		if ( ! is_array( $data ) || (int) ( $data['reset'] ?? 0 ) <= $now ) {
			$data = array(
				'count' => 0,
				'reset' => $now + $window,
			);
		}
		if ( (int) $data['count'] >= $limit ) {
			return false;
		}

		++$data['count'];
		set_transient( $name, $data, max( 1, (int) $data['reset'] - $now ) );
		return true;
	}

	/**
	 * Salted hash of the client IP. The raw IP is never stored.
	 *
	 * Behind a proxy/CDN use the `enspire_reviews_client_ip` filter to return the real client IP.
	 */
	public static function ip_hash(): string {
		return hash_hmac( 'sha256', self::client_ip(), wp_salt( 'auth' ) );
	}

	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filters the client IP used for rate limiting, ip_hash and captcha verification.
		 *
		 * @param string $ip REMOTE_ADDR.
		 */
		return (string) apply_filters( 'enspire_reviews_client_ip', $ip );
	}
}
