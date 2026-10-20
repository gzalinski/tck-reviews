<?php
/**
 * Plugin settings: one option array, getters with defaults, per-field sanitizing.
 *
 * @package Enspire\Reviews
 */

namespace Enspire\Reviews;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION         = 'enspire_reviews_settings';
	public const MAX_PER_PAGE   = 50;
	public const MAX_LENGTH_CAP = 10000;

	/** @var array<string,mixed>|null */
	private ?array $cache = null;

	/**
	 * Default values for every setting.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			// Targets.
			'post_types'                => array(),
			'users_enabled'             => false,
			'user_roles'                => array(),
			// Display.
			'per_page'                  => 10,
			'pagination'                => 'load_more',
			'positive_min'              => 4,
			'negative_max'              => 2,
			'show_filters'              => true,
			'show_breakdown'            => true,
			'min_length'                => 20,
			'max_length'                => 1000,
			'allow_edit'                => true,
			'edit_window_days'          => 30,
			// Moderation.
			'notify_admin'              => true,
			'notify_owner'              => true,
			// Eligibility.
			'min_account_age'           => 0,
			'require_verified_email'    => false,
			// Anti-spam.
			'writes_per_hour'           => 5,
			'reads_per_minute'          => 60,
			'min_submit_time'           => 3,
			'captcha'                   => 'none',
			'captcha_site_key'          => '',
			'captcha_secret_key'        => '',
			// Output.
			'jsonld'                    => true,
			'jsonld_standalone'         => false,
			'load_css'                  => true,
			// FacetWP.
			'facetwp_author_post_types' => array(),
			'facetwp_author_facets'     => array(),
			// Advanced.
			'delete_data'               => false,
		);
	}

	/**
	 * Field schema: which tab a setting belongs to and how it is sanitized.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function schema(): array {
		return array(
			'post_types'                => array( 'tab' => 'targets', 'type' => 'post_types' ),
			'users_enabled'             => array( 'tab' => 'targets', 'type' => 'bool' ),
			'user_roles'                => array( 'tab' => 'targets', 'type' => 'roles' ),

			'per_page'                  => array( 'tab' => 'display', 'type' => 'int', 'min' => 1, 'max' => self::MAX_PER_PAGE ),
			'pagination'                => array( 'tab' => 'display', 'type' => 'enum', 'values' => array( 'load_more', 'pages' ) ),
			'positive_min'              => array( 'tab' => 'display', 'type' => 'int', 'min' => 1, 'max' => 5 ),
			'negative_max'              => array( 'tab' => 'display', 'type' => 'int', 'min' => 1, 'max' => 5 ),
			'show_filters'              => array( 'tab' => 'display', 'type' => 'bool' ),
			'show_breakdown'            => array( 'tab' => 'display', 'type' => 'bool' ),
			'min_length'                => array( 'tab' => 'display', 'type' => 'int', 'min' => 0, 'max' => self::MAX_LENGTH_CAP ),
			'max_length'                => array( 'tab' => 'display', 'type' => 'int', 'min' => 1, 'max' => self::MAX_LENGTH_CAP ),
			'allow_edit'                => array( 'tab' => 'display', 'type' => 'bool' ),
			'edit_window_days'          => array( 'tab' => 'display', 'type' => 'int', 'min' => 0, 'max' => 3650 ),

			'notify_admin'              => array( 'tab' => 'moderation', 'type' => 'bool' ),
			'notify_owner'              => array( 'tab' => 'moderation', 'type' => 'bool' ),

			'min_account_age'           => array( 'tab' => 'eligibility', 'type' => 'int', 'min' => 0, 'max' => 3650 ),
			'require_verified_email'    => array( 'tab' => 'eligibility', 'type' => 'bool' ),

			'writes_per_hour'           => array( 'tab' => 'antispam', 'type' => 'int', 'min' => 0, 'max' => 1000 ),
			'reads_per_minute'          => array( 'tab' => 'antispam', 'type' => 'int', 'min' => 0, 'max' => 100000 ),
			'min_submit_time'           => array( 'tab' => 'antispam', 'type' => 'int', 'min' => 0, 'max' => 300 ),
			'captcha'                   => array( 'tab' => 'antispam', 'type' => 'enum', 'values' => array( 'none', 'turnstile', 'recaptcha' ) ),
			'captcha_site_key'          => array( 'tab' => 'antispam', 'type' => 'text' ),
			'captcha_secret_key'        => array( 'tab' => 'antispam', 'type' => 'secret' ),

			'jsonld'                    => array( 'tab' => 'output', 'type' => 'bool' ),
			'jsonld_standalone'         => array( 'tab' => 'output', 'type' => 'bool' ),
			'load_css'                  => array( 'tab' => 'output', 'type' => 'bool' ),

			'facetwp_author_post_types' => array( 'tab' => 'facetwp', 'type' => 'post_types' ),
			'facetwp_author_facets'     => array( 'tab' => 'facetwp', 'type' => 'list' ),

			'delete_data'               => array( 'tab' => 'advanced', 'type' => 'bool' ),
		);
	}

	/**
	 * All settings merged over defaults.
	 *
	 * @return array<string,mixed>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$saved       = get_option( self::OPTION, array() );
			$this->cache = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		}
		return $this->cache;
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public function get( string $key ) {
		$all = $this->all();
		return $all[ $key ] ?? null;
	}

	public function flush(): void {
		$this->cache = null;
	}

	/**
	 * Public post types that can be enabled as review targets.
	 * `user` is a reserved keyword and never offered as a post type.
	 *
	 * @return array<string,\WP_Post_Type>
	 */
	public static function reviewable_post_types(): array {
		$types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $types['attachment'], $types['user'] );
		return $types;
	}

	/**
	 * Sanitize callback for register_setting().
	 *
	 * The settings page posts one tab at a time (with a hidden `_tab` field), so
	 * only that tab's fields are read from the input; every other value is kept.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ): array {
		$this->flush();
		$current = $this->all();
		if ( ! is_array( $input ) ) {
			return $current;
		}

		$tab = isset( $input['_tab'] ) ? sanitize_key( (string) $input['_tab'] ) : '';
		$out = $current;

		foreach ( self::schema() as $key => $field ) {
			if ( '' !== $tab && $field['tab'] !== $tab ) {
				continue;
			}
			if ( '' === $tab && ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$out[ $key ] = $this->sanitize_field( $field, $input[ $key ] ?? null, $current[ $key ] );
		}

		if ( $out['min_length'] > $out['max_length'] ) {
			$out['min_length'] = $out['max_length'];
		}

		if ( $out['negative_max'] >= $out['positive_min'] ) {
			$out['negative_max'] = $current['negative_max'];
			$out['positive_min'] = $current['positive_min'];
			if ( function_exists( 'add_settings_error' ) ) {
				add_settings_error(
					self::OPTION,
					'enspire_reviews_thresholds',
					__( 'The "negative" threshold must be lower than the "positive" threshold. The previous values were kept.', 'enspire-reviews' )
				);
			}
		}

		return $out;
	}

	/**
	 * Sanitize one field according to its schema.
	 *
	 * @param array<string,mixed> $field   Schema entry.
	 * @param mixed               $raw     Raw value.
	 * @param mixed               $current Current value.
	 * @return mixed
	 */
	private function sanitize_field( array $field, $raw, $current ) {
		switch ( $field['type'] ) {
			case 'bool':
				return ! empty( $raw );

			case 'int':
				$value = is_numeric( $raw ) ? (int) $raw : (int) $current;
				return max( (int) $field['min'], min( (int) $field['max'], $value ) );

			case 'enum':
				return in_array( $raw, $field['values'], true ) ? $raw : $current;

			case 'post_types':
				$allowed = array_keys( self::reviewable_post_types() );
				return array_values( array_intersect( array_map( 'sanitize_key', (array) $raw ), $allowed ) );

			case 'roles':
				$allowed = array_keys( wp_roles()->roles );
				return array_values( array_intersect( array_map( 'sanitize_key', (array) $raw ), $allowed ) );

			case 'text':
				return sanitize_text_field( (string) $raw );

			case 'secret':
				// Blank keeps the saved secret, so it never has to be printed back into the form.
				$value = trim( sanitize_text_field( (string) $raw ) );
				return '' === $value ? $current : $value;

			case 'list':
				$parts = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
				$parts = array_map( 'sanitize_key', array_map( 'trim', array_map( 'strval', $parts ) ) );
				return array_values( array_unique( array_filter( $parts ) ) );
		}
		return $current;
	}
}
