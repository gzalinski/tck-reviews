<?php
/**
 * "Load more" button or page numbers. Both load through REST without a page reload.
 *
 * Override: {theme}/enspire-reviews/pagination.php
 *
 * @package Enspire\Reviews
 * @var array $args {
 *     @type string $mode     load_more | pages.
 *     @type int    $page     Current page.
 *     @type int    $pages    Total pages.
 *     @type bool   $has_more More pages after this one.
 *     @type int    $total    Total reviews for the current filter.
 * }
 */

defined( 'ABSPATH' ) || exit;

$er_page  = (int) $args['page'];
$er_pages = (int) $args['pages'];

if ( $er_pages < 2 ) {
	return;
}
?>
<nav class="er-pagination" aria-label="<?php esc_attr_e( 'Reviews pages', 'enspire-reviews' ); ?>">
	<?php if ( 'load_more' === $args['mode'] ) : ?>
		<?php if ( $args['has_more'] ) : ?>
			<button type="button" class="er-button er-more" data-er-more><?php esc_html_e( 'Load more reviews', 'enspire-reviews' ); ?></button>
		<?php endif; ?>
	<?php else : ?>
		<?php
		// Window: first, last, current ± 2, with gaps.
		$er_show = array_unique( array_filter( array( 1, $er_pages, $er_page - 2, $er_page - 1, $er_page, $er_page + 1, $er_page + 2 ), static fn( $p ) => $p >= 1 && $p <= $er_pages ) );
		sort( $er_show );
		$er_prev = 0;
		?>
		<?php if ( $er_page > 1 ) : ?>
			<button type="button" class="er-page er-page--prev" data-er-page="<?php echo esc_attr( (string) ( $er_page - 1 ) ); ?>" aria-label="<?php esc_attr_e( 'Previous page', 'enspire-reviews' ); ?>">&lsaquo;</button>
		<?php endif; ?>
		<?php foreach ( $er_show as $er_p ) : ?>
			<?php if ( $er_prev && $er_p - $er_prev > 1 ) : ?>
				<span class="er-page er-page--gap" aria-hidden="true">&hellip;</span>
			<?php endif; ?>
			<button type="button" class="er-page" data-er-page="<?php echo esc_attr( (string) $er_p ); ?>"<?php echo $er_p === $er_page ? ' aria-current="page"' : ''; ?>><?php echo esc_html( number_format_i18n( $er_p ) ); ?></button>
			<?php $er_prev = $er_p; ?>
		<?php endforeach; ?>
		<?php if ( $er_page < $er_pages ) : ?>
			<button type="button" class="er-page er-page--next" data-er-page="<?php echo esc_attr( (string) ( $er_page + 1 ) ); ?>" aria-label="<?php esc_attr_e( 'Next page', 'enspire-reviews' ); ?>">&rsaquo;</button>
		<?php endif; ?>
	<?php endif; ?>
</nav>
