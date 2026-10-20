<?php
/**
 * Full reviews block. Parts are output in the order of $args['show'].
 *
 * Override: {theme}/enspire-reviews/reviews.php
 *
 * @package Enspire\Reviews
 * @var array $args {
 *     @type string $instance_id HTML id (first instance: "reviews").
 *     @type array  $target      type, id, owner_id, owner_name, title, url.
 *     @type array  $atts        Normalized attributes.
 *     @type array  $show        Parts to output.
 *     @type array  $summary     avg, count, breakdown.
 *     @type array  $page        First page: html, pagination, total, pages, page, has_more.
 *     @type array  $viewer      Current visitor state for the form.
 *     @type array  $settings    positive_min, negative_max, min_length, max_length.
 *     @type array  $antibot     honeypot, token, captcha (only when the form can be submitted).
 * }
 */

use Enspire\Reviews\Template;

defined( 'ABSPATH' ) || exit;

$er_target = $args['target'];
$er_atts   = $args['atts'];
?>
<section
	id="<?php echo esc_attr( $args['instance_id'] ); ?>"
	class="er-reviews"
	data-er-instance
	data-er-type="<?php echo esc_attr( $er_target['type'] ); ?>"
	data-er-id="<?php echo esc_attr( (string) $er_target['id'] ); ?>"
	data-er-sort="<?php echo esc_attr( $er_atts['sort'] ); ?>"
	data-er-rating="<?php echo esc_attr( $er_atts['rating'] ); ?>"
	data-er-page="1"
	data-er-per-page="<?php echo esc_attr( (string) $er_atts['per_page'] ); ?>"
	data-er-pagination="<?php echo esc_attr( $er_atts['pagination'] ); ?>"
	data-er-positive="<?php echo esc_attr( (string) $args['settings']['positive_min'] ); ?>"
	data-er-negative="<?php echo esc_attr( (string) $args['settings']['negative_max'] ); ?>"
>
	<?php if ( '' !== $er_atts['title'] ) : ?>
		<h2 class="er-reviews__title"><?php echo esc_html( $er_atts['title'] ); ?></h2>
	<?php endif; ?>

	<div class="er-sr-only" aria-live="polite" data-er-live></div>

	<?php foreach ( $args['show'] as $er_part ) : ?>
		<?php if ( 'summary' === $er_part ) : ?>
			<div class="er-region er-region--summary" data-er-region="summary">
				<?php
				Template::part(
					'summary.php',
					array(
						'summary' => $args['summary'],
						'target'  => $er_target,
						'link'    => '',
						'context' => 'block',
					)
				);
				?>
			</div>
		<?php elseif ( 'breakdown' === $er_part ) : ?>
			<div class="er-region er-region--breakdown" data-er-region="breakdown">
				<?php
				Template::part(
					'breakdown.php',
					array(
						'summary' => $args['summary'],
						'rating'  => $er_atts['rating'],
					)
				);
				?>
			</div>
		<?php elseif ( 'filters' === $er_part ) : ?>
			<div class="er-region er-region--filters" data-er-region="filters">
				<?php Template::part( 'filters.php', $args ); ?>
			</div>
		<?php elseif ( 'list' === $er_part ) : ?>
			<div class="er-region er-region--list" data-er-region="list">
				<?php Template::part( 'list.php', $args ); ?>
				<div data-er-region="pagination">
					<?php echo $args['page']['pagination']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered by pagination.php. ?>
				</div>
			</div>
		<?php elseif ( 'form' === $er_part ) : ?>
			<div class="er-region er-region--form" data-er-region="form">
				<?php Template::part( 'form.php', $args ); ?>
			</div>
		<?php endif; ?>
	<?php endforeach; ?>
</section>
