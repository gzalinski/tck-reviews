<?php
/**
 * Plugin Name:       Enspire Reviews
 * Description:       Star ratings and reviews for any post type and for users (e.g. vendors). Shortcodes, PHP API, REST API, moderation, FacetWP and JSON-LD integration.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Enspire
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       enspire-reviews
 * Domain Path:       /languages
 *
 * @package Enspire\Reviews
 */

defined( 'ABSPATH' ) || exit;

define( 'ENSPIRE_REVIEWS_VERSION', '1.0.0' );
define( 'ENSPIRE_REVIEWS_DB_VERSION', '1' );
define( 'ENSPIRE_REVIEWS_FILE', __FILE__ );
define( 'ENSPIRE_REVIEWS_DIR', plugin_dir_path( __FILE__ ) );
define( 'ENSPIRE_REVIEWS_URL', plugin_dir_url( __FILE__ ) );

require_once ENSPIRE_REVIEWS_DIR . 'includes/autoload.php';
require_once ENSPIRE_REVIEWS_DIR . 'includes/functions.php';

register_activation_hook( __FILE__, array( \Enspire\Reviews\Installer::class, 'activate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		\Enspire\Reviews\Plugin::instance()->boot();
	}
);
