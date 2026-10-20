<?php
/**
 * Filter tabs (All / Positive / Negative) and sort select.
 *
 * Override: {theme}/enspire-reviews/filters.php
 *
 * @package Enspire\Reviews
 * @var array $args Same as reviews.php.
 */

defined( 'ABSPATH' ) || exit;

$er_breakdown = $args['summary']['breakdown'];
$er_pos       = 0;
$er_neg       = 0;
foreach ( $er_breakdown as $er_star => $er_n ) {
	if ( $er_star >= $args['settings']['positive_min'] ) {
		$er_pos += (int) $er_n;
	}
	if ( $er_star <= $args['settings']['negative_max'] ) {
		$er_neg += (int) $er_n;
	}
}

$er_tabs = array(
	'all'      => array( __( 'All', 'enspire-reviews' ), (int) $args['summary']['count'] ),
	'positive' => array( __( 'Positive', 'enspire-reviews' ), $er_pos ),
	'negative' => array( __( 'Negative', 'enspire-reviews' ), $er_neg ),
);
$er_sorts = array(
	'newest'  => __( 'Newest', 'enspire-reviews' ),
	'highest' => __( 'Highest rated', 'enspire-reviews' ),
	'lowest'  => __( 'Lowest rated', 'enspire-reviews' ),
);
$er_select_id = $args['instance_id'] . '-sort';
?>
<div class="er-filters">
	<div class="er-filters__tabs" role="group" aria-label="<?php esc_attr_e( 'Filter reviews', 'enspire-reviews' ); ?>">
		<?php foreach ( $er_tabs as $er_key => $er_tab ) : ?>
			<button type="button" class="er-chip" data-er-filter="<?php echo esc_attr( $er_key ); ?>" aria-pressed="<?php echo $er_key === $args['atts']['rating'] ? 'true' : 'false'; ?>">
				<?php echo esc_html( $er_tab[0] ); ?>
				<span class="er-chip__count" data-er-count="<?php echo esc_attr( $er_key ); ?>"><?php echo esc_html( number_format_i18n( $er_tab[1] ) ); ?></span>
			</button>
		<?php endforeach; ?>
	</div>
	<div class="er-sort">
		<label for="<?php echo esc_attr( $er_select_id ); ?>"><?php esc_html_e( 'Sort by', 'enspire-reviews' ); ?></label>
		<select id="<?php echo esc_attr( $er_select_id ); ?>" data-er-sort>
			<?php foreach ( $er_sorts as $er_key => $er_label ) : ?>
				<option value="<?php echo esc_attr( $er_key ); ?>" <?php selected( $args['atts']['sort'], $er_key ); ?>><?php echo esc_html( $er_label ); ?></option>
			<?php endforeach; ?>
		</select>
	</div>
</div>
