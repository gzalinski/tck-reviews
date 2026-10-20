<?php
/**
 * One review. Also returned as `html` by the REST API.
 *
 * Override: {theme}/enspire-reviews/item.php
 *
 * @package Enspire\Reviews
 * @var array $args {
 *     @type array $item        Item data (see Renderer::item_data / enspire_reviews_item_data).
 *     @type array $target      Resolved target.
 *     @type bool  $show_target Show a link to the reviewed item (owner dashboard).
 *     @type int   $max_length  Max reply length.
 * }
 */

use Enspire\Reviews\Template;

defined( 'ABSPATH' ) || exit;

$er_item  = $args['item'];
$er_id    = (int) $er_item['id'];
$er_field = 'er-reply-' . $er_id;
?>
<li class="er-item<?php echo $er_item['is_own'] ? ' er-item--own' : ''; ?>" data-er-review="<?php echo esc_attr( (string) $er_id ); ?>">
	<article class="er-item__inner">
		<header class="er-item__header">
			<img class="er-item__avatar" src="<?php echo esc_url( $er_item['avatar_url'] ); ?>" alt="" width="48" height="48" loading="lazy" decoding="async">
			<div class="er-item__meta">
				<span class="er-item__author"><?php echo esc_html( $er_item['author_name'] ); ?></span>
				<?php if ( $er_item['is_own'] ) : ?>
					<span class="er-badge"><?php esc_html_e( 'Your review', 'enspire-reviews' ); ?></span>
				<?php endif; ?>
				<div class="er-item__sub">
					<?php echo Template::stars( (float) $er_item['rating'], 'small' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<time datetime="<?php echo esc_attr( $er_item['date_iso'] ); ?>"><?php echo esc_html( $er_item['date'] ); ?></time>
					<?php if ( $er_item['edited'] ) : ?>
						<span class="er-item__edited"><?php esc_html_e( '(edited)', 'enspire-reviews' ); ?></span>
					<?php endif; ?>
				</div>
				<?php if ( ! empty( $args['show_target'] ) && '' !== $er_item['target']['title'] ) : ?>
					<a class="er-item__target" href="<?php echo esc_url( $er_item['target']['url'] ); ?>"><?php echo esc_html( $er_item['target']['title'] ); ?></a>
				<?php endif; ?>
			</div>
		</header>

		<div class="er-item__content">
			<?php echo wp_kses_post( $er_item['content_html'] ); ?>
		</div>

		<?php if ( '' !== $er_item['reply'] ) : ?>
			<div class="er-reply">
				<p class="er-reply__title">
					<?php
					echo esc_html(
						'' !== $er_item['owner_name']
							/* translators: %s: owner display name */
							? sprintf( __( 'Reply from %s', 'enspire-reviews' ), $er_item['owner_name'] )
							: __( 'Reply from the owner', 'enspire-reviews' )
					);
					?>
					<?php if ( '' !== $er_item['reply_date'] ) : ?>
						<span class="er-reply__date"><?php echo esc_html( $er_item['reply_date'] ); ?></span>
					<?php endif; ?>
				</p>
				<div class="er-reply__content"><?php echo wp_kses_post( $er_item['reply_html'] ); ?></div>
			</div>
		<?php endif; ?>

		<?php if ( $er_item['can_reply'] || $er_item['can_report'] ) : ?>
			<div class="er-item__actions">
				<?php if ( $er_item['can_reply'] ) : ?>
					<button type="button" class="er-link" data-er-reply-toggle aria-controls="<?php echo esc_attr( $er_field ); ?>" aria-expanded="false">
						<?php '' !== $er_item['reply'] ? esc_html_e( 'Edit reply', 'enspire-reviews' ) : esc_html_e( 'Reply', 'enspire-reviews' ); ?>
					</button>
					<?php if ( '' !== $er_item['reply'] ) : ?>
						<button type="button" class="er-link" data-er-reply-delete><?php esc_html_e( 'Delete reply', 'enspire-reviews' ); ?></button>
					<?php endif; ?>
				<?php endif; ?>
				<?php if ( $er_item['can_report'] ) : ?>
					<button type="button" class="er-link er-link--muted" data-er-report><?php esc_html_e( 'Report', 'enspire-reviews' ); ?></button>
				<?php endif; ?>
			</div>

			<?php if ( $er_item['can_reply'] ) : ?>
				<form class="er-reply-form" id="<?php echo esc_attr( $er_field ); ?>" data-er-reply-form hidden>
					<label class="er-sr-only" for="<?php echo esc_attr( $er_field ); ?>-text"><?php esc_html_e( 'Your reply', 'enspire-reviews' ); ?></label>
					<textarea id="<?php echo esc_attr( $er_field ); ?>-text" name="reply" rows="3" maxlength="<?php echo esc_attr( (string) (int) $args['max_length'] ); ?>" required><?php echo esc_textarea( $er_item['reply'] ); ?></textarea>
					<div class="er-reply-form__actions">
						<button type="submit" class="er-button er-button--primary"><?php esc_html_e( 'Save reply', 'enspire-reviews' ); ?></button>
						<button type="button" class="er-button" data-er-reply-cancel><?php esc_html_e( 'Cancel', 'enspire-reviews' ); ?></button>
					</div>
					<p class="er-msg" data-er-message role="status"></p>
				</form>
			<?php endif; ?>
		<?php endif; ?>
	</article>
</li>
