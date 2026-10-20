<?php
/**
 * Moderation list: approve, mark as spam, delete, delete reply.
 *
 * @package Enspire\Reviews
 */

namespace Enspire\Reviews\Admin;

use Enspire\Reviews\Repository;
use Enspire\Reviews\Target;
use Enspire\Reviews\Template;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class ReviewsListTable extends \WP_List_Table {

	private Repository $repo;

	public function __construct( Repository $repo ) {
		$this->repo = $repo;
		parent::__construct(
			array(
				'singular' => 'review',
				'plural'   => 'reviews',
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		return array(
			'cb'      => '<input type="checkbox">',
			'review'  => __( 'Review', 'enspire-reviews' ),
			'rating'  => __( 'Rating', 'enspire-reviews' ),
			'target'  => __( 'Reviewed item', 'enspire-reviews' ),
			'author'  => __( 'Author', 'enspire-reviews' ),
			'reports' => __( 'Reports', 'enspire-reviews' ),
			'status'  => __( 'Status', 'enspire-reviews' ),
			'date'    => __( 'Date', 'enspire-reviews' ),
		);
	}

	protected function get_sortable_columns() {
		return array(
			'rating'  => array( 'rating', true ),
			'reports' => array( 'reports', true ),
			'date'    => array( 'date', true ),
		);
	}

	protected function get_default_primary_column_name() {
		return 'review';
	}

	protected function get_bulk_actions() {
		return array(
			'approve' => __( 'Approve', 'enspire-reviews' ),
			'spam'    => __( 'Mark as spam', 'enspire-reviews' ),
			'delete'  => __( 'Delete permanently', 'enspire-reviews' ),
		);
	}

	protected function get_views() {
		$counts  = $this->repo->admin_counts();
		$current = $this->current_view();
		$labels  = array(
			'all'      => __( 'All', 'enspire-reviews' ),
			'approved' => __( 'Published', 'enspire-reviews' ),
			'spam'     => __( 'Spam', 'enspire-reviews' ),
			'reported' => __( 'Reported', 'enspire-reviews' ),
		);
		$views   = array();
		foreach ( $labels as $key => $label ) {
			$url           = 'all' === $key ? admin_url( 'admin.php?page=' . Admin::PAGE ) : add_query_arg( 'view', $key, admin_url( 'admin.php?page=' . Admin::PAGE ) );
			$views[ $key ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
				esc_url( $url ),
				$current === $key ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( $counts[ $key ] ) )
			);
		}
		return $views;
	}

	/**
	 * @param string $which top|bottom.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$types = $this->repo->object_types();
		if ( count( $types ) < 2 ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = isset( $_GET['object_type'] ) ? sanitize_key( wp_unslash( $_GET['object_type'] ) ) : '';
		?>
		<div class="alignleft actions">
			<label class="screen-reader-text" for="er-filter-type"><?php esc_html_e( 'Filter by type', 'enspire-reviews' ); ?></label>
			<select name="object_type" id="er-filter-type">
				<option value=""><?php esc_html_e( 'All types', 'enspire-reviews' ); ?></option>
				<?php foreach ( $types as $type ) : ?>
					<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $current, $type ); ?>><?php echo esc_html( Target::type_label( $type ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'enspire-reviews' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	public function prepare_items() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list params.
		$per_page = $this->get_items_per_page( 'enspire_reviews_per_page', 20 );
		$paged    = $this->get_pagenum();
		$result   = $this->repo->admin_query(
			array(
				'view'        => $this->current_view(),
				'search'      => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
				'object_type' => isset( $_REQUEST['object_type'] ) ? sanitize_key( wp_unslash( $_REQUEST['object_type'] ) ) : '',
				'orderby'     => isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'date',
				'order'       => isset( $_REQUEST['order'] ) ? sanitize_key( wp_unslash( $_REQUEST['order'] ) ) : 'desc',
				'limit'       => $per_page,
				'offset'      => ( $paged - 1 ) * $per_page,
			)
		);
		// phpcs:enable

		$this->items = $result['items'];
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $result['total'] / $per_page ),
			)
		);

		$ids = array_filter( array_map( static fn( $row ) => (int) $row->author_id, $this->items ) );
		if ( $ids ) {
			cache_users( array_values( array_unique( $ids ) ) );
		}
	}

	public function no_items() {
		esc_html_e( 'No reviews found.', 'enspire-reviews' );
	}

	/**
	 * @param object $item Row.
	 */
	public function column_cb( $item ) {
		return sprintf(
			'<label class="screen-reader-text" for="er-cb-%1$d">%2$s</label><input type="checkbox" id="er-cb-%1$d" name="reviews[]" value="%1$d">',
			(int) $item->id,
			esc_html__( 'Select review', 'enspire-reviews' )
		);
	}

	/**
	 * @param object $item Row.
	 */
	protected function column_review( $item ) {
		$out = '<div class="er-admin-content">' . esc_html( wp_trim_words( (string) $item->content, 40 ) ) . '</div>';
		if ( '' !== $item->reply ) {
			$out .= '<div class="er-admin-reply"><strong>' . esc_html__( 'Reply:', 'enspire-reviews' ) . '</strong> ' . esc_html( wp_trim_words( (string) $item->reply, 25 ) ) . '</div>';
		}
		return $out;
	}

	/**
	 * @param object $item Row.
	 */
	protected function column_rating( $item ) {
		return Template::stars( (float) $item->rating ) . ' <span class="er-admin-num">' . (int) $item->rating . '/5</span>';
	}

	/**
	 * @param object $item Row.
	 */
	protected function column_target( $item ) {
		$label = esc_html( Target::type_label( $item->object_type ) );

		if ( Target::USER === $item->object_type ) {
			$user = get_userdata( (int) $item->object_id );
			$name = $user ? sprintf( '<a href="%s">%s</a>', esc_url( get_edit_user_link( $user->ID ) ), esc_html( $user->display_name ) ) : esc_html( '#' . $item->object_id . ' ' . __( '(deleted)', 'enspire-reviews' ) );
		} else {
			$post = get_post( (int) $item->object_id );
			$name = $post
				? sprintf( '<a href="%s">%s</a>', esc_url( (string) get_permalink( $post ) ), esc_html( get_the_title( $post ) ?: '#' . $post->ID ) )
				: esc_html( '#' . $item->object_id . ' ' . __( '(deleted)', 'enspire-reviews' ) );
		}

		return $name . '<br><span class="description">' . $label . '</span>';
	}

	/**
	 * @param object $item Row.
	 */
	protected function column_author( $item ) {
		$user = $item->author_id ? get_userdata( (int) $item->author_id ) : false;
		if ( ! $user ) {
			return '<em>' . esc_html__( 'Former user', 'enspire-reviews' ) . '</em>';
		}
		return sprintf(
			'%s<a href="%s">%s</a><br><span class="description">%s</span>',
			get_avatar( $user->ID, 32, '', '', array( 'class' => 'er-admin-avatar' ) ),
			esc_url( get_edit_user_link( $user->ID ) ),
			esc_html( $user->display_name ),
			esc_html( $user->user_email )
		);
	}

	/**
	 * @param object $item Row.
	 */
	protected function column_reports( $item ) {
		$n = (int) $item->reports;
		return $n > 0 ? '<span class="er-admin-reports">' . esc_html( number_format_i18n( $n ) ) . '</span>' : '&mdash;';
	}

	/**
	 * @param object $item Row.
	 */
	protected function column_status( $item ) {
		return Repository::STATUS_SPAM === $item->status
			? '<span class="er-admin-status er-admin-status--spam">' . esc_html__( 'Spam', 'enspire-reviews' ) . '</span>'
			: '<span class="er-admin-status er-admin-status--approved">' . esc_html__( 'Published', 'enspire-reviews' ) . '</span>';
	}

	/**
	 * @param object $item Row.
	 */
	protected function column_date( $item ) {
		$created = (int) strtotime( $item->created_at . ' UTC' );
		$out     = esc_html( (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $created ) );
		if ( $item->updated_at !== $item->created_at ) {
			$out .= '<br><span class="description">' . esc_html__( 'edited', 'enspire-reviews' ) . '</span>';
		}
		return $out;
	}

	/**
	 * @param object $item        Row.
	 * @param string $column_name Column.
	 */
	protected function column_default( $item, $column_name ) {
		return '';
	}

	/**
	 * @param object $item        Row.
	 * @param string $column_name Column.
	 * @param string $primary     Primary column.
	 */
	protected function handle_row_actions( $item, $column_name, $primary ) {
		if ( $primary !== $column_name ) {
			return '';
		}

		$id      = (int) $item->id;
		$link    = function ( string $action ) use ( $id ): string {
			return wp_nonce_url(
				add_query_arg(
					array(
						'page'   => Admin::PAGE,
						'action' => $action,
						'review' => $id,
					),
					admin_url( 'admin.php' )
				),
				'enspire_review_' . $id
			);
		};
		$actions = array();

		if ( Repository::STATUS_SPAM === $item->status ) {
			$actions['approve'] = sprintf( '<a href="%s">%s</a>', esc_url( $link( 'approve' ) ), esc_html__( 'Not spam', 'enspire-reviews' ) );
		} else {
			$actions['spam'] = sprintf( '<a href="%s">%s</a>', esc_url( $link( 'spam' ) ), esc_html__( 'Spam', 'enspire-reviews' ) );
		}
		if ( '' !== $item->reply ) {
			$actions['delete_reply'] = sprintf( '<a href="%s">%s</a>', esc_url( $link( 'delete_reply' ) ), esc_html__( 'Delete reply', 'enspire-reviews' ) );
		}
		$actions['delete'] = sprintf(
			'<a href="%s" class="submitdelete" onclick="return confirm(%s);">%s</a>',
			esc_url( $link( 'delete' ) ),
			esc_attr( wp_json_encode( __( 'Delete this review permanently?', 'enspire-reviews' ) ) ),
			esc_html__( 'Delete', 'enspire-reviews' )
		);

		return $this->row_actions( $actions );
	}

	private function current_view(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$view = isset( $_REQUEST['view'] ) ? sanitize_key( wp_unslash( $_REQUEST['view'] ) ) : 'all';
		return in_array( $view, array( 'all', 'approved', 'spam', 'reported' ), true ) ? $view : 'all';
	}
}
