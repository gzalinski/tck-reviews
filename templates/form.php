<?php
/**
 * Review form, or the right message instead of it.
 *
 * - Guest: login (and register) link back to #reviews.
 * - Owner: "You can't review your own listing."
 * - Already reviewed: prefilled edit form inside the edit window, otherwise their review.
 * - Not eligible: the reason.
 *
 * Override: {theme}/enspire-reviews/form.php
 *
 * @package Enspire\Reviews
 * @var array $args Same as reviews.php.
 */

use Enspire\Reviews\Template;

defined( 'ABSPATH' ) || exit;

$er_viewer = $args['viewer'];
$er_own    = $er_viewer['own_review'];
$er_prefix = $args['instance_id'];
$er_min    = (int) $args['settings']['min_length'];
$er_max    = (int) $args['settings']['max_length'];
?>
<div class="er-form-wrap">
	<?php if ( ! $er_viewer['logged_in'] ) : ?>
		<p class="er-notice er-notice--login">
			<?php esc_html_e( 'Log in to leave a review.', 'enspire-reviews' ); ?>
			<a href="<?php echo esc_url( $er_viewer['login_url'] ); ?>"><?php esc_html_e( 'Log in', 'enspire-reviews' ); ?></a>
			<?php if ( '' !== $er_viewer['register_url'] ) : ?>
				<?php esc_html_e( 'or', 'enspire-reviews' ); ?>
				<a href="<?php echo esc_url( $er_viewer['register_url'] ); ?>"><?php esc_html_e( 'Register', 'enspire-reviews' ); ?></a>
			<?php endif; ?>
		</p>

	<?php elseif ( $er_viewer['is_owner'] ) : ?>
		<p class="er-notice"><?php esc_html_e( "You can't review your own listing.", 'enspire-reviews' ); ?></p>

	<?php elseif ( $er_own && ! $er_viewer['can_edit'] ) : ?>
		<h3 class="er-form__title"><?php esc_html_e( 'Your review', 'enspire-reviews' ); ?></h3>
		<ul class="er-list er-list--own">
			<?php
			Template::part(
				'item.php',
				array(
					'item'        => $er_own,
					'target'      => $args['target'],
					'show_target' => false,
					'max_length'  => $er_max,
				)
			);
			?>
		</ul>

	<?php elseif ( ! $er_own && ! $er_viewer['can_review'] ) : ?>
		<p class="er-notice"><?php echo esc_html( $er_viewer['message'] ); ?></p>

	<?php else : ?>
		<?php
		$er_edit   = (bool) $er_own;
		$er_rating = $er_edit ? (int) $er_own['rating'] : 0;
		?>
		<form class="er-form" data-er-form data-er-mode="<?php echo $er_edit ? 'edit' : 'create'; ?>" data-er-review-id="<?php echo esc_attr( $er_edit ? (string) $er_own['id'] : '' ); ?>" novalidate>
			<h3 class="er-form__title" data-er-form-title>
				<?php $er_edit ? esc_html_e( 'Edit your review', 'enspire-reviews' ) : esc_html_e( 'Write a review', 'enspire-reviews' ); ?>
			</h3>

			<fieldset class="er-rating-input">
				<legend><?php esc_html_e( 'Your rating', 'enspire-reviews' ); ?></legend>
				<div class="er-rating-input__stars">
					<?php for ( $er_i = 5; $er_i >= 1; $er_i-- ) : ?>
						<input type="radio" id="<?php echo esc_attr( $er_prefix . '-rating-' . $er_i ); ?>" name="rating" value="<?php echo esc_attr( (string) $er_i ); ?>" <?php checked( $er_rating, $er_i ); ?> required>
						<label for="<?php echo esc_attr( $er_prefix . '-rating-' . $er_i ); ?>" title="<?php /* translators: %d: number of stars */ echo esc_attr( sprintf( _n( '%d star', '%d stars', $er_i, 'enspire-reviews' ), $er_i ) ); ?>">
							<span class="er-sr-only"><?php /* translators: %d: number of stars */ echo esc_html( sprintf( _n( '%d star', '%d stars', $er_i, 'enspire-reviews' ), $er_i ) ); ?></span>
						</label>
					<?php endfor; ?>
				</div>
			</fieldset>

			<p class="er-field">
				<label for="<?php echo esc_attr( $er_prefix . '-content' ); ?>"><?php esc_html_e( 'Your review', 'enspire-reviews' ); ?></label>
				<textarea id="<?php echo esc_attr( $er_prefix . '-content' ); ?>" name="content" rows="5" minlength="<?php echo esc_attr( (string) $er_min ); ?>" maxlength="<?php echo esc_attr( (string) $er_max ); ?>" required aria-describedby="<?php echo esc_attr( $er_prefix . '-hint' ); ?>"><?php echo $er_edit ? esc_textarea( $er_own['content'] ) : ''; ?></textarea>
				<span class="er-hint" id="<?php echo esc_attr( $er_prefix . '-hint' ); ?>">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: min characters, 2: max characters */
							__( 'Between %1$d and %2$d characters.', 'enspire-reviews' ),
							max( 1, $er_min ),
							$er_max
						)
					);
					?>
				</span>
			</p>

			<div class="er-hp" aria-hidden="true">
				<label for="<?php echo esc_attr( $er_prefix . '-hp' ); ?>"><?php esc_html_e( 'Leave this field empty', 'enspire-reviews' ); ?></label>
				<input type="text" id="<?php echo esc_attr( $er_prefix . '-hp' ); ?>" name="<?php echo esc_attr( $args['antibot']['honeypot'] ?? 'er_website' ); ?>" value="" tabindex="-1" autocomplete="off">
			</div>
			<input type="hidden" name="er_ts" value="<?php echo esc_attr( $args['antibot']['token'] ?? '' ); ?>">

			<?php echo $args['antibot']['captcha'] ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in AntiBot::widget_html(). ?>

			<div class="er-form__actions">
				<button type="submit" class="er-button er-button--primary" data-er-submit>
					<?php $er_edit ? esc_html_e( 'Update review', 'enspire-reviews' ) : esc_html_e( 'Submit review', 'enspire-reviews' ); ?>
				</button>
				<button type="button" class="er-link er-link--danger" data-er-delete-own<?php echo $er_edit ? '' : ' hidden'; ?>>
					<?php esc_html_e( 'Delete my review', 'enspire-reviews' ); ?>
				</button>
			</div>

			<p class="er-msg" data-er-message role="status"></p>
			<noscript><p class="er-notice"><?php esc_html_e( 'Please enable JavaScript to submit a review.', 'enspire-reviews' ); ?></p></noscript>
		</form>
	<?php endif; ?>
</div>
