<?php
/**
 * All SQL lives here. Every query with a value goes through $wpdb->prepare().
 *
 * @package Enspire\Reviews
 */

namespace Enspire\Reviews;

defined( 'ABSPATH' ) || exit;

final class Repository {

	public const META_AVG       = '_enspire_rating_avg';
	public const META_COUNT     = '_enspire_rating_count';
	public const META_BREAKDOWN = '_enspire_rating_breakdown';

	public const STATUS_APPROVED = 'approved';
	public const STATUS_SPAM     = 'spam';

	/** Sort whitelist: public key => ORDER BY clause. Never interpolate request data. */
	public const ORDER = array(
		'newest'  => 'created_at DESC, id DESC',
		'highest' => 'rating DESC, created_at DESC, id DESC',
		'lowest'  => 'rating ASC, created_at DESC, id DESC',
	);

	private const ADMIN_ORDERBY = array(
		'date'    => 'created_at',
		'rating'  => 'rating',
		'reports' => 'reports',
	);

	private const FORMATS = array(
		'object_type' => '%s',
		'object_id'   => '%d',
		'author_id'   => '%d',
		'rating'      => '%d',
		'content'     => '%s',
		'status'      => '%s',
		'reply'       => '%s',
		'reply_at'    => '%s',
		'reports'     => '%d',
		'ip_hash'     => '%s',
		'created_at'  => '%s',
		'updated_at'  => '%s',
	);

	private \wpdb $db;

	public function __construct() {
		global $wpdb;
		$this->db = $wpdb;
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'enspire_reviews';
	}

	public static function reports_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'enspire_review_reports';
	}

	/* ---------------------------------------------------------------------
	 * Schema
	 * ------------------------------------------------------------------ */

	public static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$reviews = self::table();
		$reports = self::reports_table();

		// author_id NULL = anonymized ("Former user"). NULL (not 0) so several
		// anonymized reviews can coexist under the one_per_user UNIQUE key.
		dbDelta(
			array(
				"CREATE TABLE {$reviews} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  object_type varchar(32) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  author_id bigint(20) unsigned NULL DEFAULT NULL,
  rating tinyint(3) unsigned NOT NULL DEFAULT 0,
  content text NOT NULL,
  status varchar(10) NOT NULL DEFAULT 'approved',
  reply text NULL,
  reply_at datetime NULL DEFAULT NULL,
  reports smallint(5) unsigned NOT NULL DEFAULT 0,
  ip_hash char(64) NULL DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY one_per_user (object_type,object_id,author_id),
  KEY by_target (object_type,object_id,status,rating),
  KEY author_id (author_id)
) {$charset};",
				"CREATE TABLE {$reports} (
  review_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (review_id,user_id),
  KEY user_id (user_id)
) {$charset};",
			)
		);
	}

	/** Drop tables and aggregate meta (uninstall). */
	public static function drop_all(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i, %i', self::table(), self::reports_table() ) );
		foreach ( array( $wpdb->postmeta, $wpdb->usermeta ) as $meta_table ) {
			$wpdb->query(
				$wpdb->prepare(
					'DELETE FROM %i WHERE meta_key IN (%s, %s, %s)',
					$meta_table,
					self::META_AVG,
					self::META_COUNT,
					self::META_BREAKDOWN
				)
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * Single rows
	 * ------------------------------------------------------------------ */

	public function find( int $id ): ?object {
		$row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ) );
		return $row ? $this->hydrate( $row ) : null;
	}

	public function find_by_author( string $object_type, int $object_id, int $author_id ): ?object {
		if ( $author_id <= 0 ) {
			return null;
		}
		$row = $this->db->get_row(
			$this->db->prepare(
				'SELECT * FROM %i WHERE object_type = %s AND object_id = %d AND author_id = %d',
				self::table(),
				$object_type,
				$object_id,
				$author_id
			)
		);
		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Insert a review. Returns the new ID, or 0 on failure (e.g. UNIQUE key race).
	 *
	 * @param array<string,mixed> $data Column => value.
	 */
	public function insert( array $data ): int {
		$data = array_intersect_key( $data, self::FORMATS );
		$ok   = $this->db->insert( self::table(), $data, $this->formats( $data ) );
		return $ok ? (int) $this->db->insert_id : 0;
	}

	/**
	 * @param array<string,mixed> $data Column => value.
	 */
	public function update( int $id, array $data ): bool {
		$data = array_intersect_key( $data, self::FORMATS );
		if ( ! $data ) {
			return false;
		}
		return false !== $this->db->update( self::table(), $data, array( 'id' => $id ), $this->formats( $data ), array( '%d' ) );
	}

	public function delete( int $id ): bool {
		$this->db->delete( self::reports_table(), array( 'review_id' => $id ), array( '%d' ) );
		return (bool) $this->db->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
	}

	public function set_status( int $id, string $status ): bool {
		return $this->update(
			$id,
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql', true ),
			)
		);
	}

	public function set_reply( int $id, ?string $reply ): bool {
		return $this->update(
			$id,
			array(
				'reply'    => $reply,
				'reply_at' => null === $reply ? null : current_time( 'mysql', true ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Lists
	 * ------------------------------------------------------------------ */

	/**
	 * Public list for one target. Sort and rating range are whitelisted by the caller
	 * and again here.
	 *
	 * @param array<string,mixed> $args object_type, object_id, status, min, max, sort, limit, offset.
	 * @return array{items: object[], total: int}
	 */
	public function query( array $args ): array {
		$args = wp_parse_args(
			$args,
			array(
				'object_type' => '',
				'object_id'   => 0,
				'status'      => self::STATUS_APPROVED,
				'min'         => 1,
				'max'         => 5,
				'sort'        => 'newest',
				'limit'       => 10,
				'offset'      => 0,
			)
		);

		$order = self::ORDER[ $args['sort'] ] ?? self::ORDER['newest'];
		$min   = max( 1, min( 5, (int) $args['min'] ) );
		$max   = max( $min, min( 5, (int) $args['max'] ) );

		$total = (int) $this->db->get_var(
			$this->db->prepare(
				'SELECT COUNT(*) FROM %i WHERE object_type = %s AND object_id = %d AND status = %s AND rating BETWEEN %d AND %d',
				self::table(),
				(string) $args['object_type'],
				(int) $args['object_id'],
				(string) $args['status'],
				$min,
				$max
			)
		);

		$items = array();
		if ( $total > 0 ) {
			$rows  = $this->db->get_results(
				$this->db->prepare(
					"SELECT * FROM %i WHERE object_type = %s AND object_id = %d AND status = %s AND rating BETWEEN %d AND %d ORDER BY {$order} LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $order comes from self::ORDER.
					self::table(),
					(string) $args['object_type'],
					(int) $args['object_id'],
					(string) $args['status'],
					$min,
					$max,
					max( 1, (int) $args['limit'] ),
					max( 0, (int) $args['offset'] )
				)
			);
			$items = array_map( array( $this, 'hydrate' ), (array) $rows );
		}

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Approved review counts per star for one target.
	 *
	 * @return array<int,int> rating => count
	 */
	public function rating_counts( string $object_type, int $object_id ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT rating, COUNT(*) AS c FROM %i WHERE object_type = %s AND object_id = %d AND status = %s GROUP BY rating',
				self::table(),
				$object_type,
				$object_id,
				self::STATUS_APPROVED
			)
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->rating ] = (int) $row->c;
		}
		return $out;
	}

	/**
	 * Reviews about everything a user owns: their user profile and their posts.
	 *
	 * @param string[] $post_types Enabled post types.
	 * @return array{items: object[], total: int}
	 */
	public function owner_query( int $owner_id, array $post_types, bool $include_user, int $limit, int $offset ): array {
		$or     = array();
		$params = array( self::table(), self::STATUS_APPROVED );

		if ( $include_user ) {
			$or[]     = '(r.object_type = %s AND r.object_id = %d)';
			$params[] = Target::USER;
			$params[] = $owner_id;
		}
		if ( $post_types ) {
			$in       = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
			$or[]     = "(r.object_type IN ({$in}) AND EXISTS (SELECT 1 FROM %i p WHERE p.ID = r.object_id AND p.post_type = r.object_type AND p.post_author = %d))";
			$params   = array_merge( $params, array_values( $post_types ) );
			$params[] = $this->db->posts;
			$params[] = $owner_id;
		}
		if ( ! $or ) {
			return array(
				'items' => array(),
				'total' => 0,
			);
		}

		$where = 'r.status = %s AND (' . implode( ' OR ', $or ) . ')';
		$total = (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM %i r WHERE {$where}", $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$params[] = max( 1, $limit );
		$params[] = max( 0, $offset );
		$rows     = $this->db->get_results( $this->db->prepare( "SELECT r.* FROM %i r WHERE {$where} ORDER BY r.created_at DESC, r.id DESC LIMIT %d OFFSET %d", $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'items' => array_map( array( $this, 'hydrate' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Admin list: any status, search, filter by type, sortable.
	 *
	 * @param array<string,mixed> $args view, search, object_type, orderby, order, limit, offset.
	 * @return array{items: object[], total: int}
	 */
	public function admin_query( array $args ): array {
		$args = wp_parse_args(
			$args,
			array(
				'view'        => 'all',
				'search'      => '',
				'object_type' => '',
				'orderby'     => 'date',
				'order'       => 'desc',
				'limit'       => 20,
				'offset'      => 0,
			)
		);

		$where  = array( '1=1' );
		$params = array( self::table() );

		if ( in_array( $args['view'], array( self::STATUS_APPROVED, self::STATUS_SPAM ), true ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['view'];
		} elseif ( 'reported' === $args['view'] ) {
			$where[] = 'reports > 0';
		}
		if ( '' !== $args['search'] ) {
			$where[]  = '(content LIKE %s OR reply LIKE %s)';
			$like     = '%' . $this->db->esc_like( (string) $args['search'] ) . '%';
			$params[] = $like;
			$params[] = $like;
		}
		if ( '' !== $args['object_type'] ) {
			$where[]  = 'object_type = %s';
			$params[] = (string) $args['object_type'];
		}

		$orderby = self::ADMIN_ORDERBY[ $args['orderby'] ] ?? 'created_at';
		$order   = 'asc' === strtolower( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$sql     = implode( ' AND ', $where );

		$total = (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM %i WHERE {$sql}", $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$params[] = max( 1, (int) $args['limit'] );
		$params[] = max( 0, (int) $args['offset'] );
		$rows     = $this->db->get_results( $this->db->prepare( "SELECT * FROM %i WHERE {$sql} ORDER BY {$orderby} {$order}, id DESC LIMIT %d OFFSET %d", $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'items' => array_map( array( $this, 'hydrate' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Counts for the admin views.
	 *
	 * @return array{all:int, approved:int, spam:int, reported:int}
	 */
	public function admin_counts(): array {
		$out  = array(
			'all'      => 0,
			'approved' => 0,
			'spam'     => 0,
			'reported' => 0,
		);
		$rows = $this->db->get_results( $this->db->prepare( 'SELECT status, COUNT(*) AS c, SUM(reports > 0) AS r FROM %i GROUP BY status', self::table() ) );
		foreach ( (array) $rows as $row ) {
			$out['all'] += (int) $row->c;
			if ( isset( $out[ $row->status ] ) ) {
				$out[ $row->status ] = (int) $row->c;
			}
			$out['reported'] += (int) $row->r;
		}
		return $out;
	}

	/** @return string[] */
	public function object_types(): array {
		return array_map( 'strval', (array) $this->db->get_col( $this->db->prepare( 'SELECT DISTINCT object_type FROM %i ORDER BY object_type', self::table() ) ) );
	}

	/* ---------------------------------------------------------------------
	 * Reports
	 * ------------------------------------------------------------------ */

	/** Adds one report per user. Returns false when this user already reported the review. */
	public function add_report( int $review_id, int $user_id ): bool {
		$inserted = $this->db->query(
			$this->db->prepare(
				'INSERT IGNORE INTO %i (review_id, user_id, created_at) VALUES (%d, %d, %s)',
				self::reports_table(),
				$review_id,
				$user_id,
				current_time( 'mysql', true )
			)
		);
		if ( ! $inserted ) {
			return false;
		}
		$this->recount_reports( array( $review_id ) );
		return true;
	}

	public function has_reported( int $review_id, int $user_id ): bool {
		return (bool) $this->db->get_var(
			$this->db->prepare( 'SELECT 1 FROM %i WHERE review_id = %d AND user_id = %d', self::reports_table(), $review_id, $user_id )
		);
	}

	/** Remove a user's reports (privacy erase / user deleted) and recount the affected reviews. */
	public function delete_reports_by_user( int $user_id ): int {
		$ids = array_map(
			'intval',
			(array) $this->db->get_col( $this->db->prepare( 'SELECT review_id FROM %i WHERE user_id = %d', self::reports_table(), $user_id ) )
		);
		if ( ! $ids ) {
			return 0;
		}
		$this->db->delete( self::reports_table(), array( 'user_id' => $user_id ), array( '%d' ) );
		$this->recount_reports( $ids );
		return count( $ids );
	}

	/**
	 * @param int[] $review_ids Reviews to recount.
	 */
	private function recount_reports( array $review_ids ): void {
		$review_ids = array_values( array_unique( array_filter( array_map( 'intval', $review_ids ) ) ) );
		if ( ! $review_ids ) {
			return;
		}
		$in     = implode( ', ', array_fill( 0, count( $review_ids ), '%d' ) );
		$params = array_merge( array( self::table(), self::reports_table() ), $review_ids );
		$this->db->query(
			$this->db->prepare(
				"UPDATE %i r SET r.reports = (SELECT COUNT(*) FROM %i x WHERE x.review_id = r.id) WHERE r.id IN ({$in})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$params
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Privacy / cleanup
	 * ------------------------------------------------------------------ */

	/** @return object[] */
	public function by_author( int $author_id, int $limit, int $offset ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT * FROM %i WHERE author_id = %d ORDER BY id ASC LIMIT %d OFFSET %d',
				self::table(),
				$author_id,
				$limit,
				$offset
			)
		);
		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/** Anonymize: author becomes "Former user", ratings are kept. */
	public function anonymize_author( int $author_id ): int {
		return (int) $this->db->query(
			$this->db->prepare( 'UPDATE %i SET author_id = NULL, ip_hash = NULL WHERE author_id = %d', self::table(), $author_id )
		);
	}

	/** Delete every review about one target (and their reports). */
	public function delete_for_target( string $object_type, int $object_id ): int {
		$this->db->query(
			$this->db->prepare(
				'DELETE x FROM %i x INNER JOIN %i r ON r.id = x.review_id WHERE r.object_type = %s AND r.object_id = %d',
				self::reports_table(),
				self::table(),
				$object_type,
				$object_id
			)
		);
		return (int) $this->db->query(
			$this->db->prepare( 'DELETE FROM %i WHERE object_type = %s AND object_id = %d', self::table(), $object_type, $object_id )
		);
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * @param array<string,mixed> $data Column => value.
	 * @return string[]
	 */
	private function formats( array $data ): array {
		return array_map( static fn( $key ) => self::FORMATS[ $key ], array_keys( $data ) );
	}

	private function hydrate( object $row ): object {
		$row->id        = (int) $row->id;
		$row->object_id = (int) $row->object_id;
		$row->author_id = null === $row->author_id ? 0 : (int) $row->author_id;
		$row->rating    = (int) $row->rating;
		$row->reports   = (int) $row->reports;
		$row->reply     = null === $row->reply ? '' : (string) $row->reply;
		return $row;
	}
}
