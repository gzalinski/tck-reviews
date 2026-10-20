<?php
/**
 * Activation, schema upgrades, capability and uninstall.
 *
 * @package Enspire\Reviews
 */

namespace Enspire\Reviews;

defined( 'ABSPATH' ) || exit;

final class Installer {

	public const DB_OPTION = 'enspire_reviews_db_version';
	public const CAP       = 'enspire_moderate_reviews';

	/**
	 * Activation hook.
	 *
	 * @param bool $network_wide Network activation on multisite.
	 */
	public static function activate( $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::install();
				restore_current_blog();
			}
			return;
		}
		self::install();
	}

	/**
	 * Runs on plugins_loaded. Installs or upgrades when the stored db_version differs.
	 * Also covers new multisite sites, which get their tables on first load.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::DB_OPTION ) !== ENSPIRE_REVIEWS_DB_VERSION ) {
			self::install();
		}
	}

	public static function install(): void {
		Repository::create_tables();

		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( self::CAP ) ) {
			$role->add_cap( self::CAP );
		}

		add_option( Settings::OPTION, Settings::defaults() );
		update_option( self::DB_OPTION, ENSPIRE_REVIEWS_DB_VERSION, false );
	}

	/**
	 * Called from uninstall.php. Removes data only on sites where "delete all data" is on.
	 */
	public static function uninstall(): void {
		if ( is_multisite() ) {
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::uninstall_site();
				restore_current_blog();
			}
			return;
		}
		self::uninstall_site();
	}

	private static function uninstall_site(): void {
		$settings = get_option( Settings::OPTION, array() );
		if ( empty( $settings['delete_data'] ) ) {
			return;
		}

		Repository::drop_all();
		// Meta was removed with SQL; drop cached copies (persistent object caches).
		wp_cache_flush();

		delete_option( Settings::OPTION );
		delete_option( self::DB_OPTION );

		foreach ( wp_roles()->role_objects as $role ) {
			if ( $role->has_cap( self::CAP ) ) {
				$role->remove_cap( self::CAP );
			}
		}

		wp_clear_scheduled_hook( Integrations\FacetWP::HOOK );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Integrations\FacetWP::HOOK, array(), 'enspire-reviews' );
		}
	}
}
