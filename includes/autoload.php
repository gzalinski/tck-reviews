<?php
/**
 * PSR-4 style autoloader for the Enspire\Reviews namespace.
 *
 * Enspire\Reviews\Rest\ReviewsController → includes/Rest/ReviewsController.php
 *
 * @package Enspire\Reviews
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'Enspire\\Reviews\\';
		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);
