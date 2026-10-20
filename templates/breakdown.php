<?php
/**
 * Per-star bars. Each row is a filter button.
 *
 * Override: {theme}/enspire-reviews/breakdown.php
 *
 * @package Enspire\Reviews
 * @var array $args {
 *     @type array  $summary avg, count, breakdown.
 *     @type string $rating  Current rating filter.
 * }
 */

defined( 'ABSPATH' ) || exit;

$er_total = max( 1, (int) $args['summary']['count'] );
?>
<ul class="er-breakdown">
	<?php foreach ( $args['summary']['breakdown'] as $er_star => $er_n ) : ?>
		<?php $er_pct = (int) round( (int) $er_n / $er_total * 100 ); ?>
		<li>
			<button type="button" class="er-breakdown__row" data-er-filter="<?php echo esc_attr( (string) $er_star ); ?>" aria-pressed="<?php echo (string) $er_star === (string) ( $args['rating'] ?? '' ) ? 'true' : 'false'; ?>">
				<span class="er-breakdown__label">
					<?php
					/* translators: %d: number of stars */
					echo esc_html( sprintf( _n( '%d star', '%d stars', (int) $er_star, 'enspire-reviews' ), (int) $er_star ) );
					?>
				</span>
				<span class="er-bar" aria-hidden="true"><span class="er-bar__fill" style="width:<?php echo esc_attr( (string) $er_pct ); ?>%"></span></span>
				<span class="er-breakdown__count"><?php echo esc_html( number_format_i18n( (int) $er_n ) ); ?></span>
			</button>
		</li>
	<?php endforeach; ?>
</ul>
