<?php
/**
 * Builds the HTML for shortcodes, the PHP API and REST responses.
 *
 * @package Enspire\Reviews
 */

namespace Enspire\Reviews;

use Enspire\Reviews\Integrations\Schema;

defined( 'ABSPATH' ) || exit;

final class Renderer {

	/** Parts of the full block, in default output order. */
	public const PARTS = array( 'summary', 'breakdown', 'filters', 'list', 'form' );

	public const RATING_FILTERS = array( 'all', 'positive', 'negative', '5', '4', '3', '2', '1' );

	private static int $instances = 0;

	public function __construct(
		private Settings $settings,
		private Repository $repo,
		private Target $target,
		private ReviewService $service,
		private Eligibility $eligibility,
		private AntiBot $antibot,
		private Assets $assets,
		private Schema $schema
	) {}

	/** @return array<string,string> */
	public static function default_atts(): array {
		return array(
			'show'          => '',
			'pagination'    => '',
			'per_page'      => '',
			'sort'          => 'newest',
			'rating'        => 'all',
			'form_position' => '',
			'title'         => '',
		);
	}

	/* ---------------------------------------------------------------------
	 * Full block
	 * ------------------------------------------------------------------ */

	/**
	 * @param string              $object_type Post type, 'user' or '' (current post type).
	 * @param int|string          $object_id   ID, 'author' or '' (current post / queried author).
	 * @param array<string,mixed> $atts        Shortcode-style attributes.
	 */
	public function render( $object_type, $object_id, array $atts = array() ): string {
		$target = $this->resolve( $object_type, $object_id );
		if ( ! $target ) {
			return '';
		}

		$atts = $this->normalize_atts( $atts );
		$this->assets->enqueue();

		++self::$instances;
		$instance_id = 1 === self::$instances ? 'reviews' : 'reviews-' . self::$instances;

		$viewer = $this->viewer( $target, $instance_id );
		$args   = array(
			'instance_id' => $instance_id,
			'target'      => $target,
			'atts'        => $atts,
			'show'        => $atts['show'],
			'summary'     => $this->summary_for( $target ),
			'page'        => $this->list_page( $target, $atts['rating'], $atts['sort'], 1, $atts['per_page'], $atts['pagination'] ),
			'viewer'      => $viewer,
			'settings'    => array(
				'positive_min' => (int) $this->settings->get( 'positive_min' ),
				'negative_max' => (int) $this->settings->get( 'negative_max' ),
				'min_length'   => (int) $this->settings->get( 'min_length' ),
				'max_length'   => (int) $this->settings->get( 'max_length' ),
			),
			'antibot'     => array(),
		);

		if ( $viewer['can_submit'] && in_array( 'form', $atts['show'], true ) ) {
			$this->antibot->enqueue_captcha();
			$args['antibot'] = array(
				'honeypot' => AntiBot::HONEYPOT,
				'token'    => $this->antibot->token(),
				'captcha'  => $this->antibot->widget_html(),
			);
		}

		$this->schema->queue( $target );

		return Template::get( 'reviews.php', $args );
	}

	/**
	 * One page of the list (used by the first render and by GET /reviews).
	 *
	 * @param array<string,mixed> $target Resolved target.
	 * @return array{items: array[], html: string, pagination: string, total: int, pages: int, page: int, has_more: bool, mode: string, rating: string}
	 */
	public function list_page( array $target, string $rating, string $sort, int $page, int $per_page, string $mode ): array {
		[ $min, $max ] = $this->rating_range( $rating );
		$page          = max( 1, $page );
		$per_page      = max( 1, min( $per_page, Settings::MAX_PER_PAGE ) );

		$result = $this->repo->query(
			array(
				'object_type' => $target['type'],
				'object_id'   => (int) $target['id'],
				'min'         => $min,
				'max'         => $max,
				'sort'        => $sort,
				'limit'       => $per_page,
				'offset'      => ( $page - 1 ) * $per_page,
			)
		);

		$items = $this->items_data( $result['items'], $target );
		$html  = '';
		foreach ( $items as $item ) {
			$html .= $this->item_html( $item, $target );
		}

		$pages    = (int) ceil( $result['total'] / $per_page );
		$has_more = $page < $pages;

		return array(
			'items'      => $items,
			'html'       => $html,
			'pagination' => Template::get(
				'pagination.php',
				array(
					'mode'     => $mode,
					'page'     => $page,
					'pages'    => $pages,
					'has_more' => $has_more,
					'total'    => $result['total'],
				)
			),
			'total'      => $result['total'],
			'pages'      => $pages,
			'page'       => $page,
			'has_more'   => $has_more,
			'mode'       => $mode,
			'rating'     => $rating,
		);
	}

	/* ---------------------------------------------------------------------
	 * Summary
	 * ------------------------------------------------------------------ */

	/**
	 * @param array<string,mixed> $atts link.
	 */
	public function summary_html( $object_type, $object_id, array $atts = array() ): string {
		$target = $this->resolve( $object_type, $object_id );
		if ( ! $target ) {
			return '';
		}
		$this->assets->enqueue_style();
		return Template::get(
			'summary.php',
			array(
				'summary' => $this->summary_for( $target ),
				'target'  => $target,
				'link'    => esc_url_raw( (string) ( $atts['link'] ?? '' ) ),
				'context' => 'standalone',
			)
		);
	}

	/** @return array{avg:float,count:int,breakdown:array<int,int>}|null */
	public function summary( $object_type, $object_id ): ?array {
		$type = $this->resolve_type( $object_type );
		$id   = $this->resolve_id( $type, $object_id );
		if ( '' === $type || ! $this->target->is_type_enabled( $type ) ) {
			return null;
		}
		return $this->target->get_aggregate( $type, $id );
	}

	/**
	 * Aggregate with zero defaults (for templates).
	 *
	 * @param array<string,mixed> $target Resolved target.
	 * @return array{avg:float,count:int,breakdown:array<int,int>}
	 */
	public function summary_for( array $target ): array {
		return $this->target->get_aggregate( $target['type'], (int) $target['id'] ) ?? array(
			'avg'       => 0.0,
			'count'     => 0,
			'breakdown' => Target::empty_breakdown(),
		);
	}

	/* ---------------------------------------------------------------------
	 * "Reviews about me" (owner dashboard)
	 * ------------------------------------------------------------------ */

	/**
	 * @param array<string,mixed> $atts per_page.
	 */
	public function owner_html( array $atts = array() ): string {
		$this->assets->enqueue();

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return Template::get(
				'owner.php',
				array(
					'logged_in' => false,
					'login_url' => wp_login_url( $this->current_url() ),
				)
			);
		}

		$per_page = max( 1, min( absint( $atts['per_page'] ?? 0 ) ?: (int) $this->settings->get( 'per_page' ), Settings::MAX_PER_PAGE ) );
		$paged    = isset( $_GET['er_page'] ) ? max( 1, absint( $_GET['er_page'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination.

		$result = $this->repo->owner_query(
			$user_id,
			(array) $this->settings->get( 'post_types' ),
			(bool) $this->settings->get( 'users_enabled' ),
			$per_page,
			( $paged - 1 ) * $per_page
		);

		$html = '';
		foreach ( $result['items'] as $row ) {
			$target = $this->target->resolve( $row->object_type, (int) $row->object_id ) ?? array(
				'type'       => $row->object_type,
				'id'         => (int) $row->object_id,
				'owner_id'   => 0, // Target unpublished or disabled: read-only.
				'owner_name' => '',
				'title'      => '',
				'url'        => '',
			);
			$html  .= $this->item_html( $this->item_data( $row, $target ), $target, true );
		}

		$pages = (int) ceil( $result['total'] / $per_page );

		return Template::get(
			'owner.php',
			array(
				'logged_in'  => true,
				'html'       => $html,
				'total'      => $result['total'],
				'pagination' => $pages > 1 ? (string) paginate_links(
					array(
						'base'    => esc_url_raw( add_query_arg( 'er_page', '%#%' ) ),
						'format'  => '',
						'current' => $paged,
						'total'   => $pages,
					)
				) : '',
				'settings'   => array( 'max_length' => (int) $this->settings->get( 'max_length' ) ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Items
	 * ------------------------------------------------------------------ */

	/**
	 * @param object[]            $rows   Review rows.
	 * @param array<string,mixed> $target Resolved target.
	 * @return array[]
	 */
	public function items_data( array $rows, array $target ): array {
		$ids = array_filter( array_map( static fn( $row ) => (int) $row->author_id, $rows ) );
		if ( $ids ) {
			cache_users( array_values( array_unique( $ids ) ) );
		}
		return array_map( fn( $row ) => $this->item_data( $row, $target ), $rows );
	}

	/**
	 * Public item data. Exposes display name and avatar only — never email, user ID or IP.
	 *
	 * @param array<string,mixed> $target Resolved target.
	 * @return array<string,mixed>
	 */
	public function item_data( object $row, array $target ): array {
		$viewer  = get_current_user_id();
		$author  = $row->author_id ? get_userdata( (int) $row->author_id ) : false;
		$created = (int) strtotime( $row->created_at . ' UTC' );
		$updated = (int) strtotime( $row->updated_at . ' UTC' );
		$replied = $row->reply_at ? (int) strtotime( $row->reply_at . ' UTC' ) : 0;
		$format  = (string) get_option( 'date_format' );
		$is_own  = $viewer > 0 && (int) $row->author_id === $viewer;
		$reply   = $this->service->replies_enabled() ? (string) $row->reply : '';

		$data = array(
			'id'           => (int) $row->id,
			'rating'       => (int) $row->rating,
			'content'      => (string) $row->content,
			'content_html' => wpautop( esc_html( (string) $row->content ) ),
			'author_name'  => $author ? (string) $author->display_name : __( 'Former user', 'enspire-reviews' ),
			'avatar_url'   => (string) get_avatar_url( $author ? $author->ID : 0, array( 'size' => 96 ) ),
			'date_iso'     => gmdate( 'c', $created ),
			'date'         => (string) wp_date( $format, $created ),
			'edited'       => $updated - $created > MINUTE_IN_SECONDS,
			'reply'        => $reply,
			'reply_html'   => '' !== $reply ? wpautop( esc_html( $reply ) ) : '',
			'reply_date'   => '' !== $reply && $replied ? (string) wp_date( $format, $replied ) : '',
			'owner_name'   => (string) ( $target['owner_name'] ?? '' ),
			'status'       => (string) $row->status,
			'is_own'       => $is_own,
			'can_reply'    => $viewer > 0 && $viewer === (int) ( $target['owner_id'] ?? 0 ) && $this->service->replies_enabled(),
			'can_report'   => $viewer > 0 && ! $is_own,
			'can_delete'   => $is_own || $this->service->can_moderate( $viewer ),
			'target'       => array(
				'type'  => (string) $target['type'],
				'id'    => (int) $target['id'],
				'title' => (string) ( $target['title'] ?? '' ),
				'url'   => (string) ( $target['url'] ?? '' ),
			),
		);

		/**
		 * Filters the data of one review item (template + REST `items`).
		 *
		 * @param array  $data   Item data.
		 * @param object $row    Raw row. Do not expose author_id or ip_hash.
		 * @param array  $target Resolved target.
		 */
		return (array) apply_filters( 'enspire_reviews_item_data', $data, $row, $target );
	}

	/**
	 * @param array<string,mixed> $item   Item data.
	 * @param array<string,mixed> $target Resolved target.
	 */
	public function item_html( array $item, array $target, bool $show_target = false ): string {
		return Template::get(
			'item.php',
			array(
				'item'        => $item,
				'target'      => $target,
				'show_target' => $show_target,
				'max_length'  => (int) $this->settings->get( 'max_length' ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/** @return array<string,mixed>|null */
	public function resolve( $object_type, $object_id ): ?array {
		$type = $this->resolve_type( $object_type );
		if ( '' === $type ) {
			return null;
		}
		return $this->target->resolve( $type, $this->resolve_id( $type, $object_id ) );
	}

	private function resolve_type( $object_type ): string {
		$type = sanitize_key( (string) $object_type );
		return '' !== $type ? $type : (string) get_post_type();
	}

	/**
	 * `author`: post_author of the current post (on an author archive with type=user: the queried author).
	 * Empty: current post ID (type=user: queried author on an author archive, else nothing).
	 */
	private function resolve_id( string $type, $raw ): int {
		$raw = is_string( $raw ) ? strtolower( trim( $raw ) ) : $raw;

		if ( 'author' === $raw ) {
			if ( Target::USER === $type && is_author() ) {
				return (int) get_queried_object_id();
			}
			$post = get_post();
			return $post ? (int) $post->post_author : 0;
		}

		if ( null === $raw || '' === $raw || 0 === $raw || '0' === $raw ) {
			if ( Target::USER === $type ) {
				return is_author() ? (int) get_queried_object_id() : 0;
			}
			return (int) get_the_ID();
		}

		return absint( $raw );
	}

	/**
	 * @param array<string,mixed> $atts Raw attributes.
	 * @return array{show:string[],pagination:string,per_page:int,sort:string,rating:string,title:string}
	 */
	public function normalize_atts( array $atts ): array {
		$atts = array_merge( self::default_atts(), array_intersect_key( $atts, self::default_atts() ) );

		if ( '' === trim( (string) $atts['show'] ) ) {
			$show = self::PARTS;
			if ( ! $this->settings->get( 'show_breakdown' ) ) {
				$show = array_diff( $show, array( 'breakdown' ) );
			}
			if ( ! $this->settings->get( 'show_filters' ) ) {
				$show = array_diff( $show, array( 'filters' ) );
			}
		} else {
			$show = array_map( 'trim', explode( ',', strtolower( (string) $atts['show'] ) ) );
		}
		$show = array_values( array_unique( array_intersect( $show, self::PARTS ) ) );

		if ( in_array( 'form', $show, true ) && in_array( $atts['form_position'], array( 'top', 'bottom' ), true ) ) {
			$rest = array_values( array_diff( $show, array( 'form' ) ) );
			$show = 'top' === $atts['form_position'] ? array_merge( array( 'form' ), $rest ) : array_merge( $rest, array( 'form' ) );
		}

		$pagination = in_array( $atts['pagination'], array( 'load_more', 'pages' ), true ) ? $atts['pagination'] : (string) $this->settings->get( 'pagination' );
		$per_page   = absint( $atts['per_page'] ) ?: (int) $this->settings->get( 'per_page' );

		return array(
			'show'       => $show,
			'pagination' => $pagination,
			'per_page'   => max( 1, min( $per_page, Settings::MAX_PER_PAGE ) ),
			'sort'       => isset( Repository::ORDER[ $atts['sort'] ] ) ? (string) $atts['sort'] : 'newest',
			'rating'     => in_array( (string) $atts['rating'], self::RATING_FILTERS, true ) ? (string) $atts['rating'] : 'all',
			'title'      => sanitize_text_field( (string) $atts['title'] ),
		);
	}

	/**
	 * Map a whitelisted filter to an inclusive [min, max] star range.
	 *
	 * @return array{0:int,1:int}
	 */
	public function rating_range( string $filter ): array {
		return match ( $filter ) {
			'positive'                => array( (int) $this->settings->get( 'positive_min' ), 5 ),
			'negative'                => array( 1, (int) $this->settings->get( 'negative_max' ) ),
			'1', '2', '3', '4', '5'   => array( (int) $filter, (int) $filter ),
			default                   => array( 1, 5 ),
		};
	}

	/**
	 * What the current visitor can do with the form.
	 *
	 * @param array<string,mixed> $target Resolved target.
	 * @return array<string,mixed>
	 */
	private function viewer( array $target, string $instance_id ): array {
		$user_id = get_current_user_id();
		$viewer  = array(
			'logged_in'    => $user_id > 0,
			'is_owner'     => false,
			'own_review'   => null,
			'can_edit'     => false,
			'can_review'   => false,
			'can_submit'   => false,
			'message'      => '',
			'login_url'    => '',
			'register_url' => '',
		);

		if ( ! $user_id ) {
			$viewer['login_url'] = wp_login_url( $this->current_url() . '#' . $instance_id );
			if ( get_option( 'users_can_register' ) ) {
				$viewer['register_url'] = wp_registration_url();
			}
			return $viewer;
		}

		if ( $user_id === (int) $target['owner_id'] ) {
			$viewer['is_owner'] = true;
			return $viewer;
		}

		$own = $this->repo->find_by_author( $target['type'], (int) $target['id'], $user_id );
		if ( $own ) {
			$viewer['own_review'] = $this->item_data( $own, $target );
			$viewer['can_edit']   = $this->service->can_edit( $own );
			$viewer['can_submit'] = $viewer['can_edit'];
			return $viewer;
		}

		$check = $this->eligibility->check( $user_id, $target );
		if ( is_wp_error( $check ) ) {
			$viewer['message'] = $check->get_error_message();
			return $viewer;
		}

		$viewer['can_review'] = true;
		$viewer['can_submit'] = true;
		return $viewer;
	}

	private function current_url(): string {
		if ( is_singular() ) {
			$url = get_permalink();
			if ( $url ) {
				return $url;
			}
		}
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		return set_url_scheme( 'http://' . $host . $uri );
	}
}
