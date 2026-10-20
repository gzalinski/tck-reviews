<?php
/**
 * Template lookup with theme override: {theme}/enspire-reviews/{file}.php
 *
 * @package Enspire\Reviews
 */

namespace Enspire\Reviews;

defined( 'ABSPATH' ) || exit;

final class Template {

	public static function locate( string $file ): string {
		$file  = ltrim( str_replace( array( '..', '\\' ), '', $file ), '/' );
		$theme = locate_template( array( 'enspire-reviews/' . $file ) );
		$path  = $theme ? $theme : ENSPIRE_REVIEWS_DIR . 'templates/' . $file;

		/**
		 * Filters the absolute path of a template file.
		 *
		 * @param string $path Resolved path.
		 * @param string $file Template file name, e.g. 'item.php'.
		 */
		return (string) apply_filters( 'enspire_reviews_template_path', $path, $file );
	}

	/**
	 * Render a template to a string. Templates receive their data as `$args`.
	 *
	 * @param array<string,mixed> $args Template data.
	 */
	public static function get( string $file, array $args = array() ): string {
		$path = self::locate( $file );
		if ( ! is_readable( $path ) ) {
			return '';
		}
		ob_start();
		( static function ( string $__path, array $args ): void {
			include $__path;
		} )( $path, $args );
		return (string) ob_get_clean();
	}

	/**
	 * Echo a template (for use inside other templates).
	 *
	 * @param array<string,mixed> $args Template data.
	 */
	public static function part( string $file, array $args = array() ): void {
		echo self::get( $file, $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- templates escape their own output.
	}

	/** Star rating display. CSS fills the stars from --er-rating. */
	public static function stars( float $rating, string $modifier = '' ): string {
		$rating = max( 0.0, min( 5.0, $rating ) );
		/* translators: %s: rating, e.g. 4.5 */
		$label = sprintf( __( 'Rated %s out of 5', 'enspire-reviews' ), number_format_i18n( $rating, 1 ) );
		return sprintf(
			'<span class="er-stars%1$s" style="--er-rating:%2$s" role="img" aria-label="%3$s"></span>',
			$modifier ? ' er-stars--' . sanitize_html_class( $modifier ) : '',
			esc_attr( (string) round( $rating, 2 ) ),
			esc_attr( $label )
		);
	}
}
