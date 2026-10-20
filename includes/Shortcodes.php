<?php
/**
 * [enspire_reviews], [enspire_reviews_summary], [enspire_reviews_owner].
 *
 * @package Enspire\Reviews
 */

namespace Enspire\Reviews;

defined( 'ABSPATH' ) || exit;

final class Shortcodes {

	public function __construct( private Renderer $renderer ) {}

	public function register(): void {
		add_shortcode( 'enspire_reviews', array( $this, 'reviews' ) );
		add_shortcode( 'enspire_reviews_summary', array( $this, 'summary' ) );
		add_shortcode( 'enspire_reviews_owner', array( $this, 'owner' ) );
	}

	/**
	 * @param array<string,string>|string $atts Attributes.
	 */
	public function reviews( $atts ): string {
		$defaults = array(
			'object_type' => '',
			'object_id'   => '',
		) + Renderer::default_atts();
		$atts     = $this->atts( $defaults, $atts, 'enspire_reviews' );

		return $this->renderer->render( $atts['object_type'], $atts['object_id'], $atts );
	}

	/**
	 * @param array<string,string>|string $atts Attributes.
	 */
	public function summary( $atts ): string {
		$atts = $this->atts(
			array(
				'object_type' => '',
				'object_id'   => '',
				'link'        => '',
			),
			$atts,
			'enspire_reviews_summary'
		);

		return $this->renderer->summary_html( $atts['object_type'], $atts['object_id'], $atts );
	}

	/**
	 * @param array<string,string>|string $atts Attributes.
	 */
	public function owner( $atts ): string {
		$atts = $this->atts( array( 'per_page' => '' ), $atts, 'enspire_reviews_owner' );
		return $this->renderer->owner_html( $atts );
	}

	/**
	 * @param array<string,string>        $defaults Defaults.
	 * @param array<string,string>|string $atts     Raw attributes.
	 * @return array<string,string>
	 */
	private function atts( array $defaults, $atts, string $tag ): array {
		$atts = shortcode_atts( $defaults, (array) $atts, $tag );

		/**
		 * Filters shortcode attributes after defaults are applied.
		 *
		 * @param array  $atts Attributes.
		 * @param string $tag  Shortcode tag.
		 */
		return (array) apply_filters( 'enspire_reviews_shortcode_atts', $atts, $tag );
	}
}
