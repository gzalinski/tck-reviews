<?php
/**
 * Review list (first page). Later pages arrive from GET /reviews as item.php HTML.
 *
 * Override: {theme}/enspire-reviews/list.php
 *
 * @package Enspire\Reviews
 * @var array $args Same as reviews.php.
 */

defined( 'ABSPATH' ) || exit;

$er_page     = $args['page'];
$er_filtered = 'all' !== $args['atts']['rating'];
?>
<ul class="er-list" data-er-list>
	<?php echo $er_page['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered by item.php. ?>
</ul>
<p class="er-empty" data-er-empty<?php echo $er_page['total'] > 0 ? ' hidden' : ''; ?>>
	<?php echo esc_html( $er_filtered ? __( 'No reviews match this filter.', 'enspire-reviews' ) : __( 'No reviews yet.', 'enspire-reviews' ) ); ?>
</p>
