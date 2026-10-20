<?php
/**
 * REST API: enspire-reviews/v1
 *
 * Write pipeline (fixed order, fail fast):
 *  1. nonce + permission_callback   2. rate limit   3. target   4. eligibility
 *  5. anti-bot                      6. fields (args + service)
 *  7. insert / update own row       8. recompute aggregate   9. hooks / re-index
 *
 * @package Enspire\Reviews
 */

namespace Enspire\Reviews\Rest;

use Enspire\Reviews\AntiBot;
use Enspire\Reviews\Installer;
use Enspire\Reviews\RateLimiter;
use Enspire\Reviews\Renderer;
use Enspire\Reviews\Repository;
use Enspire\Reviews\ReviewService;
use Enspire\Reviews\Settings;
use Enspire\Reviews\Template;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class ReviewsController extends WP_REST_Controller {

	/** @var string */
	protected $namespace = 'enspire-reviews/v1';

	/** @var string */
	protected $rest_base = 'reviews';

	public function __construct(
		private Settings $settings,
		private Repository $repo,
		private ReviewService $service,
		private Renderer $renderer,
		private RateLimiter $limiter,
		private AntiBot $antibot
	) {}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => '__return_true',
					'args'                => $this->list_args(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'logged_in_permission' ),
					'args'                => array_merge( $this->target_args(), $this->write_args() ),
				),
			)
		);

		$id_arg = array(
			'id' => array(
				'type'     => 'integer',
				'minimum'  => 1,
				'required' => true,
			),
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)',
			array(
				'args' => $id_arg,
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'author_permission' ),
					'args'                => $this->write_args(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'author_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/reply',
			array(
				'args' => $id_arg,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_reply' ),
					'permission_callback' => array( $this, 'owner_permission' ),
					'args'                => array(
						'reply' => array(
							'type'              => 'string',
							'required'          => true,
							'validate_callback' => array( $this, 'validate_reply' ),
							'sanitize_callback' => array( ReviewService::class, 'clean_text' ),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_reply' ),
					'permission_callback' => array( $this, 'owner_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/report',
			array(
				'args' => $id_arg,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'report' ),
					'permission_callback' => array( $this, 'report_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/status',
			array(
				'args' => $id_arg,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'set_status' ),
					'permission_callback' => array( $this, 'moderator_permission' ),
					'args'                => array(
						'status' => array(
							'type'     => 'string',
							'enum'     => ReviewService::STATUSES,
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/summary',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_summary' ),
					'permission_callback' => '__return_true',
					'args'                => $this->target_args(),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Read
	 * ------------------------------------------------------------------ */

	/**
	 * GET /reviews — approved only. Response: { html, items, has_more, total, pages, page, pagination }.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		$limited = $this->limit_reads();
		if ( $limited ) {
			return $limited;
		}

		$target = $this->service->check_target( (string) $request['object_type'], (int) $request['object_id'] );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$per_page = (int) ( $request['per_page'] ?: $this->settings->get( 'per_page' ) );
		$mode     = (string) ( $request['pagination'] ?: $this->settings->get( 'pagination' ) );
		$page     = $this->renderer->list_page( $target, (string) $request['rating'], (string) $request['sort'], (int) $request['page'], $per_page, $mode );

		return rest_ensure_response(
			array(
				'html'       => $page['html'],
				'items'      => $page['items'],
				'has_more'   => $page['has_more'],
				'total'      => $page['total'],
				'pages'      => $page['pages'],
				'page'       => $page['page'],
				'pagination' => $page['pagination'],
			)
		);
	}

	/**
	 * GET /summary — { avg, count, breakdown, html, breakdown_html }.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_summary( WP_REST_Request $request ) {
		$limited = $this->limit_reads();
		if ( $limited ) {
			return $limited;
		}

		$target = $this->service->check_target( (string) $request['object_type'], (int) $request['object_id'] );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		return rest_ensure_response( $this->summary_payload( $target ) );
	}

	/* ---------------------------------------------------------------------
	 * Write
	 * ------------------------------------------------------------------ */

	/**
	 * POST /reviews — create, or edit the current user's own review (one per user and target).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$user_id = get_current_user_id();

		if ( ! $this->limiter->hit( 'write', (string) $user_id, (int) $this->settings->get( 'writes_per_hour' ), HOUR_IN_SECONDS ) ) {
			return $this->too_many();
		}

		$target = $this->service->check_target( (string) $request['object_type'], (int) $request['object_id'] );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$eligible = $this->service->check_eligibility( $user_id, $target );
		if ( is_wp_error( $eligible ) ) {
			return $eligible;
		}

		$human = $this->antibot->verify( $request->get_params() );
		if ( is_wp_error( $human ) ) {
			return $human;
		}

		$result = $this->service->save( $user_id, $target, (int) $request['rating'], (string) $request['content'], RateLimiter::ip_hash() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->write_response( $result );
	}

	/**
	 * PATCH /reviews/{id} — author (within the edit window) or moderator.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$user_id      = get_current_user_id();
		$review       = $this->repo->find( (int) $request['id'] );
		$is_moderator = $this->service->can_moderate( $user_id );
		$is_author    = $review && (int) $review->author_id === $user_id;

		if ( ! $review ) {
			return $this->not_found();
		}

		if ( ! $is_moderator && ! $this->limiter->hit( 'write', (string) $user_id, (int) $this->settings->get( 'writes_per_hour' ), HOUR_IN_SECONDS ) ) {
			return $this->too_many();
		}

		$target = $this->service->check_target( $review->object_type, (int) $review->object_id );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		if ( $is_author && ! $is_moderator ) {
			$human = $this->antibot->verify( $request->get_params() );
			if ( is_wp_error( $human ) ) {
				return $human;
			}
		}

		$result = $this->service->update(
			$review,
			$target,
			(int) $request['rating'],
			(string) $request['content'],
			$is_author ? RateLimiter::ip_hash() : null,
			$is_moderator && ! $is_author
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->write_response( $result );
	}

	/**
	 * DELETE /reviews/{id} — author or moderator.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$review = $this->repo->find( (int) $request['id'] );
		if ( ! $review ) {
			return $this->not_found();
		}

		$this->service->delete( $review );

		$target  = $this->service->check_target( $review->object_type, (int) $review->object_id );
		$summary = is_wp_error( $target ) ? null : $this->summary_payload( $target );

		return rest_ensure_response(
			array(
				'deleted' => true,
				'id'      => (int) $review->id,
				'summary' => $summary,
				'message' => __( 'Your review has been deleted.', 'enspire-reviews' ),
			)
		);
	}

	/**
	 * POST /reviews/{id}/reply — target owner. Plain text, replaces any previous reply.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_reply( WP_REST_Request $request ) {
		return $this->reply_response( (int) $request['id'], (string) $request['reply'], (bool) $request->get_header( 'x_er_owner_view' ) );
	}

	/**
	 * DELETE /reviews/{id}/reply — target owner.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_reply( WP_REST_Request $request ) {
		return $this->reply_response( (int) $request['id'], null, (bool) $request->get_header( 'x_er_owner_view' ) );
	}

	/**
	 * POST /reviews/{id}/report — logged in, not the author. One report per user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function report( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		if ( ! $this->limiter->hit( 'report', (string) $user_id, max( 10, 2 * (int) $this->settings->get( 'writes_per_hour' ) ), HOUR_IN_SECONDS ) ) {
			return $this->too_many();
		}

		$review = $this->repo->find( (int) $request['id'] );
		if ( ! $review ) {
			return $this->not_found();
		}

		$result = $this->service->report( $review, $user_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'reported' => true,
				'message'  => __( 'Thanks. The moderators will take a look.', 'enspire-reviews' ),
			)
		);
	}

	/**
	 * POST /reviews/{id}/status — moderators: approved | spam.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_status( WP_REST_Request $request ) {
		$review = $this->repo->find( (int) $request['id'] );
		if ( ! $review ) {
			return $this->not_found();
		}
		$this->service->set_status( $review, (string) $request['status'] );

		return rest_ensure_response(
			array(
				'id'     => (int) $review->id,
				'status' => (string) $request['status'],
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Permissions
	 * ------------------------------------------------------------------ */

	/** @return bool|WP_Error */
	public function logged_in_permission() {
		if ( is_user_logged_in() ) {
			return true;
		}
		return new WP_Error( 'rest_not_logged_in', __( 'Please log in to continue.', 'enspire-reviews' ), array( 'status' => 401 ) );
	}

	/** @return bool|WP_Error */
	public function author_permission( WP_REST_Request $request ) {
		$review = $this->review_for_permission( $request );
		if ( is_wp_error( $review ) ) {
			return $review;
		}
		if ( (int) $review->author_id === get_current_user_id() || $this->service->can_moderate() ) {
			return true;
		}
		return $this->forbidden();
	}

	/** @return bool|WP_Error */
	public function owner_permission( WP_REST_Request $request ) {
		$review = $this->review_for_permission( $request );
		if ( is_wp_error( $review ) ) {
			return $review;
		}
		$owner = $this->service->owner_of( $review );
		if ( $owner > 0 && get_current_user_id() === $owner ) {
			return true;
		}
		return $this->forbidden();
	}

	/** @return bool|WP_Error */
	public function report_permission( WP_REST_Request $request ) {
		$review = $this->review_for_permission( $request );
		if ( is_wp_error( $review ) ) {
			return $review;
		}
		if ( Repository::STATUS_APPROVED !== $review->status ) {
			return $this->not_found();
		}
		if ( (int) $review->author_id === get_current_user_id() ) {
			return new WP_Error( 'enspire_reviews_own_review', __( "You can't report your own review.", 'enspire-reviews' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/** @return bool|WP_Error */
	public function moderator_permission() {
		$logged_in = $this->logged_in_permission();
		if ( is_wp_error( $logged_in ) ) {
			return $logged_in;
		}
		return current_user_can( Installer::CAP ) ? true : $this->forbidden();
	}

	/** @return object|WP_Error */
	private function review_for_permission( WP_REST_Request $request ) {
		$logged_in = $this->logged_in_permission();
		if ( is_wp_error( $logged_in ) ) {
			return $logged_in;
		}
		$review = $this->repo->find( (int) $request['id'] );
		return $review ?? $this->not_found();
	}

	/* ---------------------------------------------------------------------
	 * Args
	 * ------------------------------------------------------------------ */

	/** @return array<string,array<string,mixed>> */
	private function target_args(): array {
		return array(
			'object_type' => array(
				'type'              => 'string',
				'required'          => true,
				'pattern'           => '^[a-z0-9_-]{1,32}$',
				// A custom sanitize_callback disables WP's schema checks, so validate explicitly.
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'sanitize_key',
			),
			'object_id'   => array(
				'type'     => 'integer',
				'required' => true,
				'minimum'  => 1,
			),
		);
	}

	/** @return array<string,array<string,mixed>> */
	private function list_args(): array {
		return array_merge(
			$this->target_args(),
			array(
				'rating'     => array(
					'type'    => 'string',
					'enum'    => Renderer::RATING_FILTERS,
					'default' => 'all',
				),
				'sort'       => array(
					'type'    => 'string',
					'enum'    => array_keys( Repository::ORDER ),
					'default' => 'newest',
				),
				'page'       => array(
					'type'    => 'integer',
					'minimum' => 1,
					'default' => 1,
				),
				'per_page'   => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => Settings::MAX_PER_PAGE,
				),
				'pagination' => array(
					'type' => 'string',
					'enum' => array( 'load_more', 'pages' ),
				),
			)
		);
	}

	/** @return array<string,array<string,mixed>> */
	private function write_args(): array {
		return array(
			// Integer 1..5: out-of-range values are rejected with 400, never clamped.
			'rating'             => array(
				'type'     => 'integer',
				'minimum'  => 1,
				'maximum'  => 5,
				'required' => true,
			),
			'content'            => array(
				'type'              => 'string',
				'required'          => true,
				'validate_callback' => array( $this, 'validate_content' ),
				'sanitize_callback' => array( ReviewService::class, 'clean_text' ),
			),
			AntiBot::HONEYPOT    => array(
				'type'    => 'string',
				'default' => '',
			),
			AntiBot::TOKEN       => array(
				'type'    => 'string',
				'default' => '',
			),
			'captcha_token'      => array(
				'type'    => 'string',
				'default' => '',
			),
		);
	}

	/**
	 * @param mixed $value Raw value.
	 * @return bool|WP_Error
	 */
	public function validate_content( $value ) {
		if ( ! is_string( $value ) ) {
			return new WP_Error( 'rest_invalid_type', __( 'Please write a review.', 'enspire-reviews' ), array( 'status' => 400 ) );
		}
		return $this->service->length_error( ReviewService::clean_text( $value ) ) ?? true;
	}

	/**
	 * @param mixed $value Raw value.
	 * @return bool|WP_Error
	 */
	public function validate_reply( $value ) {
		$text = is_string( $value ) ? ReviewService::clean_text( $value ) : '';
		$max  = (int) $this->settings->get( 'max_length' );
		if ( '' === $text ) {
			return new WP_Error( 'enspire_reviews_reply_empty', __( 'Please write a reply.', 'enspire-reviews' ), array( 'status' => 400 ) );
		}
		if ( mb_strlen( $text ) > $max ) {
			return new WP_Error(
				'enspire_reviews_reply_too_long',
				/* translators: %d: maximum number of characters */
				sprintf( __( 'Your reply must be at most %d characters long.', 'enspire-reviews' ), $max ),
				array( 'status' => 400 )
			);
		}
		return true;
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * @param array{review:object,is_new:bool,target:array} $result Saved review.
	 */
	private function write_response( array $result ): WP_REST_Response {
		$item = $this->renderer->item_data( $result['review'], $result['target'] );

		$response = new WP_REST_Response(
			array(
				'review'  => $item,
				'html'    => $this->renderer->item_html( $item, $result['target'] ),
				'summary' => $this->summary_payload( $result['target'] ),
				'message' => $result['is_new']
					? __( 'Thanks for your review!', 'enspire-reviews' )
					: __( 'Your review has been updated.', 'enspire-reviews' ),
			)
		);
		$response->set_status( $result['is_new'] ? 201 : 200 );
		return $response;
	}

	/** @return WP_REST_Response|WP_Error */
	private function reply_response( int $review_id, ?string $reply, bool $show_target ) {
		$review = $this->repo->find( $review_id );
		if ( ! $review ) {
			return $this->not_found();
		}
		$target = $this->service->check_target( $review->object_type, (int) $review->object_id );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$review = $this->service->set_reply( $review, $reply );
		$item   = $this->renderer->item_data( $review, $target );

		return rest_ensure_response(
			array(
				'review'  => $item,
				'html'    => $this->renderer->item_html( $item, $target, $show_target ),
				'message' => null === $reply ? __( 'Reply deleted.', 'enspire-reviews' ) : __( 'Reply saved.', 'enspire-reviews' ),
			)
		);
	}

	/**
	 * @param array<string,mixed> $target Resolved target.
	 * @return array<string,mixed>
	 */
	private function summary_payload( array $target ): array {
		$summary = $this->renderer->summary_for( $target );
		return array(
			'avg'            => $summary['avg'],
			'count'          => $summary['count'],
			'breakdown'      => $summary['breakdown'],
			'html'           => Template::get(
				'summary.php',
				array(
					'summary' => $summary,
					'target'  => $target,
					'link'    => '',
					'context' => 'block',
				)
			),
			'breakdown_html' => Template::get(
				'breakdown.php',
				array(
					'summary' => $summary,
					'rating'  => '',
				)
			),
		);
	}

	private function limit_reads(): ?WP_Error {
		if ( $this->limiter->hit( 'read', RateLimiter::ip_hash(), (int) $this->settings->get( 'reads_per_minute' ), MINUTE_IN_SECONDS ) ) {
			return null;
		}
		return $this->too_many();
	}

	private function too_many(): WP_Error {
		return new WP_Error( 'enspire_reviews_rate_limited', __( 'Too many requests. Please wait a moment and try again.', 'enspire-reviews' ), array( 'status' => 429 ) );
	}

	private function not_found(): WP_Error {
		return new WP_Error( 'enspire_reviews_not_found', __( 'Review not found.', 'enspire-reviews' ), array( 'status' => 404 ) );
	}

	private function forbidden(): WP_Error {
		return new WP_Error( 'enspire_reviews_forbidden', __( "You don't have permission to do that.", 'enspire-reviews' ), array( 'status' => 403 ) );
	}
}
