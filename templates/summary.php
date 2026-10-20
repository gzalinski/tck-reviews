<?php
/**
 * Stars + average + count. Used in the block and by [enspire_reviews_summary].
 *
 * Override: {theme}/enspire-reviews/summary.php
 *
 * @package Enspire\Reviews
 * @var array $args {
 *     @type array  $summary avg, count, breakdown.
 *     @type array  $target  Resolved target.
 *     @type string $link    Optional URL to the full reviews.
 *     @type string $context 'block' or 'standalone'.
 * }
 */

use Enspire\Reviews\Template;

defined( 'ABSPATH' ) || exit;

$er_summary = $args['summary'];
$er_count   = (int) $er_summary['count'];
$er_link    = (string) ( $args['link'] ?? '' );
/* translators: %s: number of reviews */
$er_label = sprintf( _n( '%s review', '%s reviews', $er_count, 'enspire-reviews' ), number_format_i18n( $er_count ) );
?>
<div class="er-summary<?php echo 'standalone' === ( $args['context'] ?? '' ) ? ' er-summary--compact' : ''; ?>">
	<?php if ( $er_count > 0 ) : ?>
		<?php echo Template::stars( (float) $er_summary['avg'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Template::stars(). ?>
		<span class="er-summary__avg"><?php echo esc_html( number_format_i18n( (float) $er_summary['avg'], 1 ) ); ?></span>
		<?php if ( '' !== $er_link ) : ?>
			<a class="er-summary__count" href="<?php echo esc_url( $er_link ); ?>">(<?php echo esc_html( $er_label ); ?>)</a>
		<?php else : ?>
			<span class="er-summary__count">(<?php echo esc_html( $er_label ); ?>)</span>
		<?php endif; ?>
	<?php else : ?>
		<?php if ( '' !== $er_link ) : ?>
			<a class="er-summary__none" href="<?php echo esc_url( $er_link ); ?>"><?php esc_html_e( 'No reviews yet', 'enspire-reviews' ); ?></a>
		<?php else : ?>
			<span class="er-summary__none"><?php esc_html_e( 'No reviews yet', 'enspire-reviews' ); ?></span>
		<?php endif; ?>
	<?php endif; ?>
</div>
