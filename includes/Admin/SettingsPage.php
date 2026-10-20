<?php
/**
 * Settings → Enspire Reviews. One option, Settings API, one tab saved at a time.
 *
 * @package Enspire\Reviews
 */

namespace Enspire\Reviews\Admin;

use Enspire\Reviews\Integrations\FacetWP;
use Enspire\Reviews\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {

	public const SLUG  = 'enspire-reviews-settings';
	private const GROUP = 'enspire_reviews';

	public function __construct( private Settings $settings ) {}

	public function register(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->settings, 'sanitize' ),
				'default'           => Settings::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/** @return array<string,string> */
	private function tabs(): array {
		return array(
			'targets'     => __( 'Targets', 'enspire-reviews' ),
			'display'     => __( 'Display', 'enspire-reviews' ),
			'moderation'  => __( 'Moderation', 'enspire-reviews' ),
			'eligibility' => __( 'Eligibility', 'enspire-reviews' ),
			'antispam'    => __( 'Anti-spam', 'enspire-reviews' ),
			'output'      => __( 'Output', 'enspire-reviews' ),
			'facetwp'     => __( 'FacetWP', 'enspire-reviews' ),
			'advanced'    => __( 'Advanced', 'enspire-reviews' ),
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tabs = $this->tabs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'targets';
		$tab = isset( $tabs[ $tab ] ) ? $tab : 'targets';
		?>
		<div class="wrap er-admin">
			<h1><?php esc_html_e( 'Enspire Reviews', 'enspire-reviews' ); ?></h1>
			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a class="nav-tab<?php echo $key === $tab ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => $key ), admin_url( 'options-general.php' ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php settings_errors(); ?>
			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<input type="hidden" name="<?php echo esc_attr( Settings::OPTION ); ?>[_tab]" value="<?php echo esc_attr( $tab ); ?>">
				<table class="form-table" role="presentation">
					<?php $this->{'tab_' . $tab}(); ?>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tabs
	 * ------------------------------------------------------------------ */

	private function tab_targets(): void {
		$types = array();
		foreach ( Settings::reviewable_post_types() as $slug => $obj ) {
			$types[ $slug ] = $obj->labels->singular_name . ' (' . $slug . ')';
		}
		$roles = array();
		foreach ( wp_roles()->roles as $slug => $role ) {
			$roles[ $slug ] = translate_user_role( $role['name'] );
		}

		$this->row( __( 'Post types that can be reviewed', 'enspire-reviews' ), $this->checkboxes( 'post_types', $types ), __( 'Only published posts of these types accept reviews. The post author is the owner: they cannot review it and can reply.', 'enspire-reviews' ) );
		$this->row( __( 'Users', 'enspire-reviews' ), $this->checkbox( 'users_enabled', __( 'Users can be reviewed (e.g. vendors)', 'enspire-reviews' ) ) );
		$this->row( __( 'Allowed user roles', 'enspire-reviews' ), $this->checkboxes( 'user_roles', $roles ), __( 'Only users with one of these roles can be reviewed. None selected = no user can be reviewed. For WC Vendors select "Vendor" only.', 'enspire-reviews' ) );
		$this->row(
			__( 'Usage', 'enspire-reviews' ),
			'<code>[enspire_reviews object_type="directory" object_id="123"]</code><br>' .
			'<code>[enspire_reviews object_type="user" object_id="author" title="Shop reviews"]</code><br>' .
			'<code>[enspire_reviews_summary object_type="directory" object_id="123" link="#reviews"]</code><br>' .
			'<code>[enspire_reviews_owner]</code>'
		);
	}

	private function tab_display(): void {
		$this->row( __( 'Reviews per page', 'enspire-reviews' ), $this->number( 'per_page', 1, Settings::MAX_PER_PAGE ) );
		$this->row(
			__( 'Pagination', 'enspire-reviews' ),
			$this->select(
				'pagination',
				array(
					'load_more' => __( '"Load more" button', 'enspire-reviews' ),
					'pages'     => __( 'Page numbers', 'enspire-reviews' ),
				)
			)
		);
		$this->row( __( 'Positive', 'enspire-reviews' ), $this->number( 'positive_min', 1, 5 ) . ' ' . esc_html__( 'stars and up', 'enspire-reviews' ) );
		$this->row( __( 'Negative', 'enspire-reviews' ), $this->number( 'negative_max', 1, 5 ) . ' ' . esc_html__( 'stars and below', 'enspire-reviews' ) );
		$this->row( __( 'Filters', 'enspire-reviews' ), $this->checkbox( 'show_filters', __( 'Show filter tabs and sorting', 'enspire-reviews' ) ) );
		$this->row( __( 'Breakdown', 'enspire-reviews' ), $this->checkbox( 'show_breakdown', __( 'Show per-star bars', 'enspire-reviews' ) ) );
		$this->row( __( 'Minimum length', 'enspire-reviews' ), $this->number( 'min_length', 0, Settings::MAX_LENGTH_CAP ) . ' ' . esc_html__( 'characters', 'enspire-reviews' ) );
		$this->row( __( 'Maximum length', 'enspire-reviews' ), $this->number( 'max_length', 1, Settings::MAX_LENGTH_CAP ) . ' ' . esc_html__( 'characters', 'enspire-reviews' ) );
		$this->row( __( 'Editing', 'enspire-reviews' ), $this->checkbox( 'allow_edit', __( 'Authors can edit their review', 'enspire-reviews' ) ) );
		$this->row( __( 'Edit window', 'enspire-reviews' ), $this->number( 'edit_window_days', 0, 3650 ) . ' ' . esc_html__( 'days (0 = no limit)', 'enspire-reviews' ) );
	}

	private function tab_moderation(): void {
		$this->row( __( 'Publishing', 'enspire-reviews' ), '<p>' . esc_html__( 'Reviews are published immediately. Moderators can hide (spam) or delete them under Reviews in the admin menu.', 'enspire-reviews' ) . '</p>' );
		$this->row( __( 'Notify admin', 'enspire-reviews' ), $this->checkbox( 'notify_admin', __( 'Email the site admin about new reviews', 'enspire-reviews' ) ) );
		$this->row( __( 'Notify owner', 'enspire-reviews' ), $this->checkbox( 'notify_owner', __( 'Email the owner of the reviewed item about new reviews', 'enspire-reviews' ) ) );
		$this->row(
			__( 'Owner replies', 'enspire-reviews' ),
			$this->checkbox( 'allow_replies', __( 'Owners can reply to reviews', 'enspire-reviews' ) ),
			__( 'When off, reply buttons are removed and existing replies are hidden on the site (they stay in the database and come back when you turn this on again).', 'enspire-reviews' )
		);
	}

	private function tab_eligibility(): void {
		$this->row( __( 'Minimum account age', 'enspire-reviews' ), $this->number( 'min_account_age', 0, 3650 ) . ' ' . esc_html__( 'days', 'enspire-reviews' ) );
		$this->row(
			__( 'Verified email', 'enspire-reviews' ),
			$this->checkbox( 'require_verified_email', __( 'Require a verified email address', 'enspire-reviews' ) ),
			__( 'Default check: the user has set their password from the emailed link. Plugins with their own verification can use the enspire_reviews_email_verified filter.', 'enspire-reviews' )
		);
		$this->row( __( 'Registration', 'enspire-reviews' ), '<p>' . esc_html__( 'Only logged-in users can review, so registration is the spam entry point. Protect the registration form (captcha / email verification) with another plugin.', 'enspire-reviews' ) . '</p>' );
	}

	private function tab_antispam(): void {
		$this->row( __( 'Writes per user', 'enspire-reviews' ), $this->number( 'writes_per_hour', 0, 1000 ) . ' ' . esc_html__( 'per hour (0 = no limit)', 'enspire-reviews' ) );
		$this->row( __( 'Reads per IP', 'enspire-reviews' ), $this->number( 'reads_per_minute', 0, 100000 ) . ' ' . esc_html__( 'per minute (0 = no limit)', 'enspire-reviews' ) );
		$this->row( __( 'Minimum submit time', 'enspire-reviews' ), $this->number( 'min_submit_time', 0, 300 ) . ' ' . esc_html__( 'seconds after the form loaded', 'enspire-reviews' ) );
		$this->row(
			__( 'Captcha', 'enspire-reviews' ),
			$this->select(
				'captcha',
				array(
					'none'      => __( 'None', 'enspire-reviews' ),
					'turnstile' => __( 'Cloudflare Turnstile', 'enspire-reviews' ),
					'recaptcha' => __( 'Google reCAPTCHA v2 (checkbox)', 'enspire-reviews' ),
				)
			)
		);
		$this->row( __( 'Site key', 'enspire-reviews' ), $this->text( 'captcha_site_key' ) );
		$this->row(
			__( 'Secret key', 'enspire-reviews' ),
			$this->secret( 'captcha_secret_key' ),
			__( 'Stored on the server only, never sent to the browser. Leave blank to keep the saved key.', 'enspire-reviews' )
		);
	}

	private function tab_output(): void {
		$this->row( __( 'JSON-LD', 'enspire-reviews' ), $this->checkbox( 'jsonld', __( 'Provide aggregateRating (enspire_reviews_schema_aggregate_rating())', 'enspire-reviews' ) ) );
		$this->row(
			__( 'Standalone JSON-LD', 'enspire-reviews' ),
			$this->checkbox( 'jsonld_standalone', __( 'Also print a JSON-LD block for each rendered reviews block', 'enspire-reviews' ) ),
			__( 'Leave off when the theme already outputs schema for the page (it would be duplicated).', 'enspire-reviews' )
		);
		$this->row( __( 'Styles', 'enspire-reviews' ), $this->checkbox( 'load_css', __( 'Load plugin CSS', 'enspire-reviews' ) ) );
	}

	private function tab_facetwp(): void {
		if ( ! FacetWP::active() ) {
			$this->row( __( 'Status', 'enspire-reviews' ), '<p>' . esc_html__( 'FacetWP is not active. These settings apply once it is.', 'enspire-reviews' ) . '</p>' );
		}
		$this->row(
			__( 'Own rating', 'enspire-reviews' ),
			'<p>' . wp_kses_post( __( 'For reviewed post types (e.g. directory): create a <strong>Rating</strong> facet with the data source <code>_enspire_rating_avg</code>. Posts are re-indexed automatically after each review change.', 'enspire-reviews' ) ) . '</p>'
		);
		$types = array();
		foreach ( Settings::reviewable_post_types() as $slug => $obj ) {
			$types[ $slug ] = $obj->labels->singular_name . ' (' . $slug . ')';
		}
		$this->row(
			__( 'Index author rating on post types', 'enspire-reviews' ),
			$this->checkboxes( 'facetwp_author_post_types', $types ),
			__( 'Posts of these types inherit the rating of their author (a reviewed user), e.g. products → vendor.', 'enspire-reviews' )
		);
		$this->row(
			__( 'Facets using the author rating', 'enspire-reviews' ),
			$this->text( 'facetwp_author_facets', true ),
			__( 'Comma-separated facet names, e.g. product_vendor_rating. Use a Rating facet whose data source exists on every post (e.g. "Post Type"); the value is replaced. Run a full FacetWP re-index after changing these settings.', 'enspire-reviews' )
		);
	}

	private function tab_advanced(): void {
		$this->row(
			__( 'Uninstall', 'enspire-reviews' ),
			$this->checkbox( 'delete_data', __( 'Delete all reviews, settings and rating meta when the plugin is deleted', 'enspire-reviews' ) )
		);
	}

	/* ---------------------------------------------------------------------
	 * Field helpers (return escaped HTML)
	 * ------------------------------------------------------------------ */

	private function row( string $label, string $field_html, string $description = '' ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
		echo $field_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by the escaped helpers below.
		if ( '' !== $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}
		echo '</td></tr>';
	}

	private function name( string $key ): string {
		return Settings::OPTION . '[' . $key . ']';
	}

	private function checkbox( string $key, string $label ): string {
		return sprintf(
			'<label><input type="checkbox" name="%s" value="1"%s> %s</label>',
			esc_attr( $this->name( $key ) ),
			checked( (bool) $this->settings->get( $key ), true, false ),
			esc_html( $label )
		);
	}

	/**
	 * @param array<string,string> $options value => label.
	 */
	private function checkboxes( string $key, array $options ): string {
		if ( ! $options ) {
			return '<em>' . esc_html__( 'None available.', 'enspire-reviews' ) . '</em>';
		}
		$current = (array) $this->settings->get( $key );
		$out     = '<fieldset>';
		foreach ( $options as $value => $label ) {
			$out .= sprintf(
				'<label class="er-admin-check"><input type="checkbox" name="%s[]" value="%s"%s> %s</label><br>',
				esc_attr( $this->name( $key ) ),
				esc_attr( $value ),
				checked( in_array( $value, $current, true ), true, false ),
				esc_html( $label )
			);
		}
		return $out . '</fieldset>';
	}

	private function number( string $key, int $min, int $max ): string {
		return sprintf(
			'<input type="number" class="small-text" name="%s" value="%d" min="%d" max="%d" step="1">',
			esc_attr( $this->name( $key ) ),
			(int) $this->settings->get( $key ),
			$min,
			$max
		);
	}

	/**
	 * @param array<string,string> $options value => label.
	 */
	private function select( string $key, array $options ): string {
		$out = sprintf( '<select name="%s">', esc_attr( $this->name( $key ) ) );
		foreach ( $options as $value => $label ) {
			$out .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( (string) $this->settings->get( $key ), $value, false ), esc_html( $label ) );
		}
		return $out . '</select>';
	}

	private function text( string $key, bool $is_list = false ): string {
		$value = $this->settings->get( $key );
		$value = $is_list ? implode( ', ', (array) $value ) : (string) $value;
		return sprintf( '<input type="text" class="regular-text" name="%s" value="%s">', esc_attr( $this->name( $key ) ), esc_attr( $value ) );
	}

	private function secret( string $key ): string {
		$saved = '' !== (string) $this->settings->get( $key );
		return sprintf(
			'<input type="password" class="regular-text" name="%s" value="" autocomplete="new-password" placeholder="%s">',
			esc_attr( $this->name( $key ) ),
			esc_attr( $saved ? __( 'Saved – leave blank to keep', 'enspire-reviews' ) : '' )
		);
	}
}
