<?php
/**
 * [enspire_reviews_owner]: "Reviews about me" across the user's posts and profile.
 * Reply + report only; owners never hide or delete reviews about themselves.
 *
 * Override: {theme}/enspire-reviews/owner.php
 *
 * @package Enspire\Reviews
 * @var array $args {
 *     @type bool   $logged_in
 *     @type string $login_url   (guests)
 *     @type string $html        Items (item.php).
 *     @type int    $total
 *     @type string $pagination  paginate_links() output.
 * }
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="er-reviews er-owner" data-er-instance data-er-owner-view>
	<div class="er-sr-only" aria-live="polite" data-er-live></div>
	<?php if ( empty( $args['logged_in'] ) ) : ?>
		<p class="er-notice er-notice--login">
			<?php esc_html_e( 'Log in to see reviews about you.', 'enspire-reviews' ); ?>
			<a href="<?php echo esc_url( $args['login_url'] ); ?>"><?php esc_html_e( 'Log in', 'enspire-reviews' ); ?></a>
		</p>
	<?php elseif ( 0 === (int) $args['total'] ) : ?>
		<p class="er-empty"><?php esc_html_e( 'There are no reviews about you yet.', 'enspire-reviews' ); ?></p>
	<?php else : ?>
		<ul class="er-list" data-er-list>
			<?php echo $args['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered by item.php. ?>
		</ul>
		<?php if ( '' !== $args['pagination'] ) : ?>
			<nav class="er-pagination er-pagination--links"><?php echo wp_kses_post( $args['pagination'] ); ?></nav>
		<?php endif; ?>
	<?php endif; ?>
</section>
