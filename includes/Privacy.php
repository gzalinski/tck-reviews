<?php
/**
 * Personal data exporter / eraser, and cleanup when users or posts are deleted.
 *
 * @package Enspire\Reviews
 */

namespace Enspire\Reviews;

defined( 'ABSPATH' ) || exit;

final class Privacy {

	private const PER_PAGE = 50;

	public function __construct( private Repository $repo ) {}

	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		add_action( 'delete_user', array( $this, 'on_delete_user' ) );
		add_action( 'wpmu_delete_user', array( $this, 'on_delete_user' ) );
		add_action( 'before_delete_post', array( $this, 'on_delete_post' ), 10, 2 );
	}

	/**
	 * @param array<string,array> $exporters Registered exporters.
	 * @return array<string,array>
	 */
	public function register_exporter( $exporters ): array {
		$exporters['enspire-reviews'] = array(
			'exporter_friendly_name' => __( 'Reviews', 'enspire-reviews' ),
			'callback'               => array( $this, 'export' ),
		);
		return (array) $exporters;
	}

	/**
	 * @param array<string,array> $erasers Registered erasers.
	 * @return array<string,array>
	 */
	public function register_eraser( $erasers ): array {
		$erasers['enspire-reviews'] = array(
			'eraser_friendly_name' => __( 'Reviews', 'enspire-reviews' ),
			'callback'             => array( $this, 'erase' ),
		);
		return (array) $erasers;
	}

	/**
	 * @param string $email Email address.
	 * @param int    $page  Page, 1-based.
	 * @return array{data: array, done: bool}
	 */
	public function export( $email, $page = 1 ): array {
		$user = get_user_by( 'email', (string) $email );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$page = max( 1, (int) $page );
		$rows = $this->repo->by_author( (int) $user->ID, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE );
		$data = array();

		foreach ( $rows as $row ) {
			$data[] = array(
				'group_id'    => 'enspire-reviews',
				'group_label' => __( 'Reviews', 'enspire-reviews' ),
				'item_id'     => 'enspire-review-' . $row->id,
				'data'        => array(
					array(
						'name'  => __( 'Reviewed item', 'enspire-reviews' ),
						'value' => $this->target_label( $row->object_type, (int) $row->object_id ),
					),
					array(
						'name'  => __( 'Rating', 'enspire-reviews' ),
						'value' => (string) $row->rating,
					),
					array(
						'name'  => __( 'Review', 'enspire-reviews' ),
						'value' => (string) $row->content,
					),
					array(
						'name'  => __( 'Date', 'enspire-reviews' ),
						'value' => (string) $row->created_at . ' UTC',
					),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => count( $rows ) < self::PER_PAGE,
		);
	}

	/**
	 * Anonymize the user's reviews (ratings are kept) and drop their reports.
	 *
	 * @param string $email Email address.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( $email, $page = 1 ): array {
		$user = get_user_by( 'email', (string) $email );
		if ( ! $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$anonymized = $this->repo->anonymize_author( (int) $user->ID );
		$this->repo->delete_reports_by_user( (int) $user->ID );

		return array(
			'items_removed'  => $anonymized > 0,
			'items_retained' => $anonymized > 0,
			'messages'       => $anonymized > 0
				? array( __( 'Reviews were anonymized ("Former user"); their ratings and text were kept.', 'enspire-reviews' ) )
				: array(),
			'done'           => true,
		);
	}

	/**
	 * User deleted: anonymize reviews they wrote; delete reviews about them.
	 * Their aggregate user meta is removed by WordPress with the user.
	 *
	 * @param int $user_id User ID.
	 */
	public function on_delete_user( $user_id ): void {
		$user_id = (int) $user_id;
		$this->repo->anonymize_author( $user_id );
		$this->repo->delete_reports_by_user( $user_id );
		$this->repo->delete_for_target( Target::USER, $user_id );
	}

	/**
	 * Post permanently deleted: delete reviews about it.
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    Post.
	 */
	public function on_delete_post( $post_id, $post = null ): void {
		$post = $post instanceof \WP_Post ? $post : get_post( (int) $post_id );
		if ( ! $post || in_array( $post->post_type, array( 'revision', 'nav_menu_item', 'customize_changeset' ), true ) ) {
			return;
		}
		$this->repo->delete_for_target( $post->post_type, (int) $post->ID );
	}

	private function target_label( string $object_type, int $object_id ): string {
		if ( Target::USER === $object_type ) {
			$user = get_userdata( $object_id );
			return $user ? $user->display_name : '#' . $object_id;
		}
		$post = get_post( $object_id );
		return $post ? get_the_title( $post ) . ' (' . get_permalink( $post ) . ')' : '#' . $object_id;
	}
}
