<?php
/**
 * Uninstall: drops tables, options and rating meta — only when
 * Settings → Advanced → "Delete all data on uninstall" is on (checked per site).
 *
 * @package Enspire\Reviews
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'ENSPIRE_REVIEWS_DIR' ) ) {
	define( 'ENSPIRE_REVIEWS_DIR', plugin_dir_path( __FILE__ ) );
}

require_once __DIR__ . '/includes/autoload.php';

\Enspire\Reviews\Installer::uninstall();
