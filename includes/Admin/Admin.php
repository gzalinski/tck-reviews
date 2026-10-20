<?php
/**
 * wp-admin: Reviews moderation screen and Settings → Enspire Reviews.
 *
 * @package Enspire\Reviews
 */

namespace Enspire\Reviews\Admin;

use Enspire\Reviews\Installer;
use Enspire\Reviews\Repository;
use Enspire\Reviews\ReviewService;
use Enspire\Reviews\Settings;

defined( 'ABSPATH' ) || exit;

final class Admin {

	public const PAGE = 'enspire-reviews';

	private SettingsPage $settings_page;
	private ?ReviewsListTable $table = null;
	private string $hook             = '';

	public function __construct(
		private Settings $settings,
		private Repository $repo,
		private ReviewService $service
	) {
		$this->settings_page = new SettingsPage( $settings );
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this->settings_page, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( ENSPIRE_REVIEWS_FILE ), array( $this, 'action_links' ) );
	}

	public function menu(): void {
		$this->hook = (string) add_menu_page(
			__( 'Reviews', 'enspire-reviews' ),
			__( 'Reviews', 'enspire-reviews' ),
			Installer::CAP,
			self::PAGE,
			array( $this, 'render_list' ),
			'dashicons-star-filled',
			26
		);
		add_action( 'load-' . $this->hook, array( $this, 'load_list' ) );

		add_options_page(
			__( 'Enspire Reviews', 'enspire-reviews' ),
			__( 'Enspire Reviews', 'enspire-reviews' ),
			'manage_options',
			SettingsPage::SLUG,
			array( $this->settings_page, 'render' )
		);
	}

	/**
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue( $hook_suffix ): void {
		if ( $hook_suffix === $this->hook || 'settings_page_' . SettingsPage::SLUG === $hook_suffix ) {
			wp_enqueue_style( 'enspire-reviews-admin', ENSPIRE_REVIEWS_URL . 'assets/dist/admin.css', array(), ENSPIRE_REVIEWS_VERSION );
		}
	}

	/**
	 * @param string[] $links Plugin row links.
	 * @return string[]
	 */
	public function action_links( $links ): array {
		$links   = (array) $links;
		$links[] = sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'options-general.php?page=' . SettingsPage::SLUG ) ), esc_html__( 'Settings', 'enspire-reviews' ) );
		return $links;
	}

	/** Runs before output: handle actions (capability + nonce), then redirect. */
	public function load_list(): void {
		if ( ! current_user_can( Installer::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to moderate reviews.', 'enspire-reviews' ), 403 );
		}

		$this->table = new ReviewsListTable( $this->repo );
		$this->handle_actions();

		add_screen_option(
			'per_page',
			array(
				'default' => 20,
				'option'  => 'enspire_reviews_per_page',
			)
		);
		add_filter( 'set_screen_option_enspire_reviews_per_page', static fn( $status, $option, $value ) => max( 1, min( 200, (int) $value ) ), 10, 3 );
	}

	private function handle_actions(): void {
		$action = $this->table ? $this->table->current_action() : false;
		if ( ! $action || ! in_array( $action, array( 'approve', 'spam', 'delete', 'delete_reply' ), true ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified right below.
		if ( isset( $_REQUEST['review'] ) && ! is_array( $_REQUEST['review'] ) ) {
			$ids = array( absint( $_REQUEST['review'] ) );
			check_admin_referer( 'enspire_review_' . $ids[0] );
		} else {
			check_admin_referer( 'bulk-reviews' );
			$ids = array_map( 'absint', (array) ( $_REQUEST['reviews'] ?? array() ) );
		}
		// phpcs:enable

		if ( ! current_user_can( Installer::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to moderate reviews.', 'enspire-reviews' ), 403 );
		}

		$done = 0;
		foreach ( array_filter( $ids ) as $id ) {
			$review = $this->repo->find( $id );
			if ( ! $review ) {
				continue;
			}
			switch ( $action ) {
				case 'approve':
					$this->service->set_status( $review, Repository::STATUS_APPROVED );
					break;
				case 'spam':
					$this->service->set_status( $review, Repository::STATUS_SPAM );
					break;
				case 'delete':
					$this->service->delete( $review );
					break;
				case 'delete_reply':
					$this->service->set_reply( $review, null );
					break;
			}
			++$done;
		}

		$back = remove_query_arg( array( 'action', 'action2', 'review', 'reviews', '_wpnonce', '_wp_http_referer', 'done', 'count' ), wp_get_referer() ?: admin_url( 'admin.php?page=' . self::PAGE ) );
		wp_safe_redirect(
			add_query_arg(
				array(
					'done'  => $action,
					'count' => $done,
				),
				$back
			)
		);
		exit;
	}

	public function render_list(): void {
		if ( ! $this->table ) {
			return;
		}
		$this->table->prepare_items();
		?>
		<div class="wrap er-admin">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Reviews', 'enspire-reviews' ); ?></h1>
			<hr class="wp-header-end">
			<?php $this->notice(); ?>
			<p class="description"><?php esc_html_e( 'Reviews are published immediately. Mark a review as spam to hide it, or delete it. Owners can reply and report, but cannot hide reviews about themselves.', 'enspire-reviews' ); ?></p>
			<?php $this->table->views(); ?>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
				<?php
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
				if ( '' !== $view ) {
					printf( '<input type="hidden" name="view" value="%s">', esc_attr( $view ) );
				}
				$this->table->search_box( __( 'Search reviews', 'enspire-reviews' ), 'enspire-reviews' );
				$this->table->display();
				?>
			</form>
		</div>
		<?php
	}

	private function notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		if ( empty( $_GET['done'] ) ) {
			return;
		}
		$count  = isset( $_GET['count'] ) ? absint( $_GET['count'] ) : 0;
		$action = sanitize_key( wp_unslash( $_GET['done'] ) );
		// phpcs:enable

		$messages = array(
			/* translators: %d: number of reviews */
			'approve'      => _n( '%d review approved.', '%d reviews approved.', $count, 'enspire-reviews' ),
			/* translators: %d: number of reviews */
			'spam'         => _n( '%d review marked as spam.', '%d reviews marked as spam.', $count, 'enspire-reviews' ),
			/* translators: %d: number of reviews */
			'delete'       => _n( '%d review deleted.', '%d reviews deleted.', $count, 'enspire-reviews' ),
			/* translators: %d: number of replies */
			'delete_reply' => _n( '%d reply deleted.', '%d replies deleted.', $count, 'enspire-reviews' ),
		);
		if ( isset( $messages[ $action ] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sprintf( $messages[ $action ], $count ) ) );
		}
	}
}
