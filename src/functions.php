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
		$variables = hestahub_get( $what, $args );
		if ( null !== $variables ) {
			echo hestahub()->get( \HestaHub\Rendering\Renderer::class )->render( $what, $variables ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- templates escape.
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

/*
 * Template helpers: words and numbers from wire values as received. The
 * plugin's templates use them; a theme's copy may too. They return plain
 * text, to be escaped where printed.
 */

if ( ! function_exists( 'hestahub_amount' ) ) {
	/**
	 * An amount for the site's locale with the currency of the data being
	 * rendered: "95,00 €". Formatted from the decimal string, never computed.
	 * Outside a template the plugin renders, there is no such data, and the
	 * amount prints without a currency.
	 *
	 * @param mixed $amount A decimal string as received.
	 */
	function hestahub_amount( $amount ): string {
		return is_string( $amount ) ? \HestaHub\Rendering\Format::amount( $amount, hestahub_envelope()['currency'] ) : '';
	}
}

if ( ! function_exists( 'hestahub_fee' ) ) {
	/**
	 * A final_cleaning_fee as an amount; empty for "0.00", which means none.
	 *
	 * @param mixed $fee A final_cleaning_fee as received.
	 */
	function hestahub_fee( $fee ): string {
		return \HestaHub\Rendering\Format::is_no_fee( $fee ) ? '' : hestahub_amount( $fee );
	}
}

if ( ! function_exists( 'hestahub_tax_note' ) ) {
	/**
	 * "All prices include VAT." when the data being rendered says so; empty
	 * otherwise, since the contract forbids assuming either.
	 */
	function hestahub_tax_note(): string {
		return true === hestahub_envelope()['prices_include_tax'] ? __( 'All prices include VAT.', 'hestahub' ) : '';
	}
}

if ( ! function_exists( 'hestahub_cleaning_fee' ) ) {
	/**
	 * An Apartment's Endreinigung for the listing: one amount, a range, or
	 * null when it has none.
	 *
	 * @param array $apartment An Apartment as received.
	 */
	function hestahub_cleaning_fee( array $apartment ): ?string {
		return \HestaHub\Rendering\Format::cleaning_fee( $apartment, hestahub_envelope()['currency'] );
	}
}

if ( ! function_exists( 'hestahub_tier_label' ) ) {
	/**
	 * A tier's label: its name, else its Season's name and night range.
	 *
	 * @param array $tier   A tier as received.
	 * @param array $season Its Season as received.
	 */
	function hestahub_tier_label( array $tier, array $season ): string {
		return \HestaHub\Rendering\Format::tier_label( $tier, $season );
	}
}

if ( ! function_exists( 'hestahub_nights' ) ) {
	/**
	 * A tier's night range: "3–6 nights", "7+ nights".
	 *
	 * @param array $tier A tier as received.
	 */
	function hestahub_nights( array $tier ): string {
		return \HestaHub\Rendering\Format::nights( $tier );
	}
}

if ( ! function_exists( 'hestahub_period' ) ) {
	/**
	 * A Season's Period, both days inclusive, in the site's date format.
	 *
	 * @param array $period A Period as received.
	 */
	function hestahub_period( array $period ): string {
		return \HestaHub\Rendering\Format::period( $period );
	}
}

if ( ! function_exists( 'hestahub_text' ) ) {
	/**
	 * A text field of an object as received; empty when it is missing,
	 * null or not text.
	 *
	 * @param mixed  $object An object as received.
	 * @param string $key    The field.
	 */
	function hestahub_text( $object, string $key ): string {
		return is_array( $object ) && isset( $object[ $key ] ) && is_string( $object[ $key ] ) ? $object[ $key ] : '';
	}
}

if ( ! function_exists( 'hestahub_facts' ) ) {
	/**
	 * An Apartment's facts in words, keyed by kind (building, size,
	 * persons): "Alte Scheune", "58 m²", "Up to 4 persons". A null leaves
	 * its fact out, never prints 0.
	 *
	 * @param array $apartment An Apartment as received.
	 * @return array<string, string>
	 */
	function hestahub_facts( array $apartment ): array {
		return \HestaHub\Rendering\Format::facts( $apartment );
	}
}

if ( ! function_exists( 'hestahub_list' ) ) {
	/**
	 * The members of a list field that are objects, e.g. an Apartment's
	 * images or a Season's tiers; empty when the field is missing.
	 *
	 * @param mixed  $object An object as received.
	 * @param string $key    The list field.
	 * @return array[]
	 */
	function hestahub_list( $object, string $key ): array {
		return \HestaHub\Rendering\Format::list_of( $object, $key );
	}
}

if ( ! function_exists( 'hestahub_url' ) ) {
	/**
	 * The URL of the Apartment or Clinic listing, or of one item by slug;
	 * null while its base path is not set.
	 *
	 * @param string      $resource apartments or clinics.
	 * @param string|null $slug     An item's slug, or null for the listing.
	 */
	function hestahub_url( string $resource, ?string $slug = null ): ?string {
		return \HestaHub\Routing\Routes::url( $resource, $slug );
	}
}

/**
 * The currency and VAT flag of the data being rendered.
 *
 * @internal
 * @return array currency, prices_include_tax.
 */
function hestahub_envelope(): array {
	$renderer = hestahub()->get( \HestaHub\Rendering\Renderer::class );
	return $renderer instanceof \HestaHub\Rendering\Renderer ? $renderer->envelope() : array(
		'currency'           => null,
		'prices_include_tax' => null,
	);
}
