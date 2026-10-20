<?php
/**
 * Service container and hook wiring.
 *
 * @package Enspire\Reviews
 */

namespace Enspire\Reviews;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?Plugin $instance = null;

	public Settings $settings;
	public Repository $repository;
	public Target $target;
	public Aggregator $aggregator;
	public Eligibility $eligibility;
	public RateLimiter $rate_limiter;
	public AntiBot $antibot;
	public ReviewService $service;
	public Assets $assets;
	public Integrations\Schema $schema;
	public Renderer $renderer;

	private bool $booted = false;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {
		$this->settings     = new Settings();
		$this->repository   = new Repository();
		$this->target       = new Target( $this->settings );
		$this->aggregator   = new Aggregator( $this->repository, $this->target );
		$this->eligibility  = new Eligibility( $this->settings );
		$this->rate_limiter = new RateLimiter();
		$this->antibot      = new AntiBot( $this->settings );
		$this->service      = new ReviewService( $this->settings, $this->repository, $this->target, $this->aggregator, $this->eligibility );
		$this->assets       = new Assets( $this->settings );
		$this->schema       = new Integrations\Schema( $this->settings, $this->target );
		$this->renderer     = new Renderer(
			$this->settings,
			$this->repository,
			$this->target,
			$this->service,
			$this->eligibility,
			$this->antibot,
			$this->assets,
			$this->schema
		);
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		Installer::maybe_upgrade();

		add_action( 'init', array( $this, 'init' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest' ) );
		add_action( 'wp_enqueue_scripts', array( $this->assets, 'register' ) );
		add_action( 'wp_footer', array( $this->schema, 'print_footer' ), 20 );

		$flush = array( $this->settings, 'flush' );
		add_action( 'add_option_' . Settings::OPTION, $flush );
		add_action( 'update_option_' . Settings::OPTION, $flush );

		( new Notifier( $this->settings ) )->register();
		( new Privacy( $this->repository ) )->register();

		if ( Integrations\FacetWP::active() ) {
			( new Integrations\FacetWP( $this->settings ) )->register();
		}

		if ( is_admin() ) {
			( new Admin\Admin( $this->settings, $this->repository, $this->service ) )->register();
		}
	}

	public function init(): void {
		load_plugin_textdomain( 'enspire-reviews', false, dirname( plugin_basename( ENSPIRE_REVIEWS_FILE ) ) . '/languages' );
		( new Shortcodes( $this->renderer ) )->register();
	}

	public function register_rest(): void {
		( new Rest\ReviewsController(
			$this->settings,
			$this->repository,
			$this->service,
			$this->renderer,
			$this->rate_limiter,
			$this->antibot
		) )->register_routes();
	}
}
