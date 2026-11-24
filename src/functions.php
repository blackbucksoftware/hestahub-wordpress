<?php
/**
 * Template tags. Contract: https://docs.hestahub.com/v1/#templates-and-hooks
 *
 * @package HestaHub
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'hestahub_render' ) ) {
	/**
	 * Prints a HestaHub template from the data the plugin holds; prints
	 * nothing when there is nothing to show.
	 *
	 *     hestahub_render( 'apartment-listing', array( 'slug' => 'hoettur, katla' ) );
	 *     hestahub_render( 'rate-table', array( 'slug' => 'hoettur' ) );
	 *
	 * @param string $what apartment-listing, apartment, rate-table, calendar,
	 *                     clinic-listing or clinic.
	 * @param array  $args slug: one slug, a comma-separated list, or an array.
	 *                     A listing shows only these; a single shows the first.
	 */
	function hestahub_render( string $what, array $args = array() ): void {
		$renderer = hestahub()->get( \HestaHub\Rendering\Renderer::class );
		if ( $renderer instanceof \HestaHub\Rendering\Renderer ) {
			echo $renderer->show( $what, \HestaHub\Rendering\Renderer::slugs( isset( $args['slug'] ) ? $args['slug'] : array() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- templates escape.
		}
	}
}

if ( ! function_exists( 'hestahub_get' ) ) {
	/**
	 * The variables the template would receive, without rendering anything;
	 * null when there is nothing to show.
	 *
	 *     $vars = hestahub_get( 'apartment', array( 'slug' => 'hoettur' ) );
	 *     // array( 'apartment' => array( 'slug' => 'hoettur', ... ) )
	 *
	 * @param string $what See hestahub_render().
	 * @param array  $args See hestahub_render().
	 * @return array|null
	 */
	function hestahub_get( string $what, array $args = array() ): ?array {
		$renderer = hestahub()->get( \HestaHub\Rendering\Renderer::class );
		if ( ! $renderer instanceof \HestaHub\Rendering\Renderer ) {
			return null;
		}
		return $renderer->variables( $what, \HestaHub\Rendering\Renderer::slugs( isset( $args['slug'] ) ? $args['slug'] : array() ) );
	}
}
