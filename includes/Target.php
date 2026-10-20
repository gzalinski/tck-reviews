<?php
/**
 * Review targets: object_type (post type slug or `user`) + object_id.
 *
 * @package Enspire\Reviews
 */

namespace Enspire\Reviews;

defined( 'ABSPATH' ) || exit;

final class Target {

	/** Reserved object_type for user targets. */
	public const USER = 'user';

	public function __construct( private Settings $settings ) {}

	public function is_type_enabled( string $object_type ): bool {
		if ( self::USER === $object_type ) {
			return (bool) $this->settings->get( 'users_enabled' );
		}
		return in_array( $object_type, (array) $this->settings->get( 'post_types' ), true ) && post_type_exists( $object_type );
	}

	/**
	 * Validate a target coming from anywhere (shortcode, PHP, REST). Never trust object_type.
	 *
	 * @return array{type:string,id:int,owner_id:int,owner_name:string,title:string,url:string}|null
	 *         Null when the type is disabled, the object does not exist, a post is not
	 *         published or a user does not have an allowed role.
	 */
	public function resolve( string $object_type, int $object_id ): ?array {
		$object_type = sanitize_key( $object_type );
		if ( '' === $object_type || strlen( $object_type ) > 32 || $object_id <= 0 || ! $this->is_type_enabled( $object_type ) ) {
			return null;
		}

		if ( self::USER === $object_type ) {
			$user = get_userdata( $object_id );
			if ( ! $user || ! $this->user_has_allowed_role( $user ) ) {
				return null;
			}
			$owner_id = (int) $user->ID;
			$title    = (string) $user->display_name;
			$url      = (string) get_author_posts_url( $user->ID );
		} else {
			$post = get_post( $object_id );
			if ( ! $post || $post->post_type !== $object_type || 'publish' !== $post->post_status || '' !== $post->post_password ) {
				return null;
			}
			$owner_id = (int) $post->post_author;
			$title    = (string) get_the_title( $post );
			$url      = (string) get_permalink( $post );
		}

		/**
		 * Filters the owner of a review target. The owner cannot review the target and can reply.
		 *
		 * @param int    $owner_id    Default: post_author, or the user itself for user targets.
		 * @param string $object_type Post type slug or 'user'.
		 * @param int    $object_id   Post or user ID.
		 */
		$owner_id = (int) apply_filters( 'enspire_reviews_owner_id', $owner_id, $object_type, $object_id );
		$owner    = $owner_id ? get_userdata( $owner_id ) : false;

		return array(
			'type'       => $object_type,
			'id'         => $object_id,
			'owner_id'   => $owner_id,
			'owner_name' => $owner ? (string) $owner->display_name : '',
			'title'      => $title,
			'url'        => $url,
		);
	}

	/** Empty role list = no user can be reviewed (safe default: admins are never reviewable by accident). */
	public function user_has_allowed_role( \WP_User $user ): bool {
		$allowed = (array) $this->settings->get( 'user_roles' );
		return (bool) array_intersect( $allowed, (array) $user->roles );
	}

	/**
	 * Store aggregate meta. With zero approved reviews the meta is removed, so FacetWP
	 * skips the object and summary() returns null.
	 *
	 * @param array{avg:float,count:int,breakdown:array<int,int>} $agg Aggregate.
	 */
	public function save_aggregate( string $object_type, int $object_id, array $agg ): void {
		$is_user = self::USER === $object_type;
		if ( $is_user ? ! get_userdata( $object_id ) : ! get_post( $object_id ) ) {
			return;
		}

		$keys = array( Repository::META_AVG, Repository::META_COUNT, Repository::META_BREAKDOWN );
		if ( $agg['count'] < 1 ) {
			foreach ( $keys as $key ) {
				$is_user ? delete_user_meta( $object_id, $key ) : delete_post_meta( $object_id, $key );
			}
			return;
		}

		$values = array(
			Repository::META_AVG       => (string) round( (float) $agg['avg'], 2 ),
			Repository::META_COUNT     => (int) $agg['count'],
			Repository::META_BREAKDOWN => $agg['breakdown'],
		);
		foreach ( $values as $key => $value ) {
			$is_user ? update_user_meta( $object_id, $key, $value ) : update_post_meta( $object_id, $key, $value );
		}
	}

	/**
	 * Read aggregate meta.
	 *
	 * @return array{avg:float,count:int,breakdown:array<int,int>}|null
	 */
	public function get_aggregate( string $object_type, int $object_id ): ?array {
		if ( $object_id <= 0 ) {
			return null;
		}
		$is_user = self::USER === $object_type;
		$get     = static fn( string $key ) => $is_user ? get_user_meta( $object_id, $key, true ) : get_post_meta( $object_id, $key, true );

		$count = (int) $get( Repository::META_COUNT );
		if ( $count < 1 ) {
			return null;
		}

		$breakdown = self::empty_breakdown();
		$saved     = $get( Repository::META_BREAKDOWN );
		if ( is_array( $saved ) ) {
			foreach ( $breakdown as $star => $n ) {
				$breakdown[ $star ] = (int) ( $saved[ $star ] ?? 0 );
			}
		}

		return array(
			'avg'       => (float) $get( Repository::META_AVG ),
			'count'     => $count,
			'breakdown' => $breakdown,
		);
	}

	/** @return array<int,int> */
	public static function empty_breakdown(): array {
		return array(
			5 => 0,
			4 => 0,
			3 => 0,
			2 => 0,
			1 => 0,
		);
	}

	/** Human label of an object type ("Directory", "User"). */
	public static function type_label( string $object_type ): string {
		if ( self::USER === $object_type ) {
			return __( 'User', 'enspire-reviews' );
		}
		$obj = get_post_type_object( $object_type );
		return $obj ? (string) $obj->labels->singular_name : $object_type;
	}
}
