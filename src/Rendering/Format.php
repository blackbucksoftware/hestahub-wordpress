<?php
/**
 * @package HestaHub
 */

namespace HestaHub\Rendering;

/**
 * Words and numbers for templates, from wire values as received. Amounts
 * are formatted from their decimal strings, never parsed into numbers: the
 * contract forbids arithmetic on an amount, and a float would round.
 */
final class Format {

	/**
	 * Currency symbols the plugin knows; any other code prints as the code.
	 */
	const SYMBOLS = array(
		'EUR' => '€',
		'USD' => '$',
		'GBP' => '£',
	);

	/**
	 * An amount for the site's locale, with its currency: "1.295,00 €" in
	 * German, "€1,295.00" in English. An amount that is not a decimal string
	 * prints as received.
	 *
	 * @param string      $amount   A decimal string, e.g. "95.00".
	 * @param string|null $currency An ISO 4217 code, or null for none.
	 */
	public static function amount( string $amount, ?string $currency ): string {
		return self::with_currency( self::number( $amount ), $currency );
	}

	/**
	 * Two amounts as a range with one currency sign: "45,00–60,00 €".
	 *
	 * @param string      $low      The lower decimal string.
	 * @param string      $high     The higher decimal string.
	 * @param string|null $currency An ISO 4217 code, or null for none.
	 */
	public static function amount_range( string $low, string $high, ?string $currency ): string {
		return self::with_currency( self::number( $low ) . '–' . self::number( $high ), $currency );
	}

	/**
	 * The listing's Endreinigung for an Apartment: the distinct non-zero
	 * final_cleaning_fee values across its tiers and Bundles, as one amount
	 * when they agree, a range when they differ, null when there are none.
	 *
	 * @param array       $apartment An Apartment as received.
	 * @param string|null $currency  An ISO 4217 code, or null for none.
	 */
	public static function cleaning_fee( array $apartment, ?string $currency ): ?string {
		$fees = array();
		foreach ( self::list_of( $apartment['pricing'] ?? null, 'seasons' ) as $season ) {
			foreach ( array( 'tiers', 'bundles' ) as $kind ) {
				foreach ( self::list_of( $season, $kind ) as $row ) {
					$fee = isset( $row['final_cleaning_fee'] ) && is_string( $row['final_cleaning_fee'] ) ? $row['final_cleaning_fee'] : null;
					if ( null !== $fee && self::is_decimal( $fee ) && ! self::is_zero( $fee ) && ! isset( $fees[ self::canonical( $fee ) ] ) ) {
						$fees[ self::canonical( $fee ) ] = $fee; // The first spelling of each value.
					}
				}
			}
		}
		if ( ! $fees ) {
			return null;
		}
		uksort( $fees, array( self::class, 'compare' ) );
		$low  = reset( $fees );
		$high = end( $fees );
		return 1 === count( $fees ) ? self::amount( $low, $currency ) : self::amount_range( $low, $high, $currency );
	}

	/**
	 * Whether a fee is to be left out: "0.00" means none.
	 *
	 * @param mixed $fee A final_cleaning_fee as received.
	 */
	public static function is_no_fee( $fee ): bool {
		return ! is_string( $fee ) || ( self::is_decimal( $fee ) && self::is_zero( $fee ) );
	}

	/**
	 * An Apartment's facts in words, keyed by kind: building, size,
	 * persons. A null leaves its fact out.
	 *
	 * @param array $apartment An Apartment as received.
	 * @return array<string, string>
	 */
	public static function facts( array $apartment ): array {
		$facts = array();
		if ( isset( $apartment['building']['name'] ) && is_string( $apartment['building']['name'] ) && '' !== $apartment['building']['name'] ) {
			$facts['building'] = $apartment['building']['name'];
		}
		if ( isset( $apartment['qm'] ) && is_int( $apartment['qm'] ) ) {
			/* translators: %s: floor area in square metres. */
			$facts['size'] = sprintf( __( '%s m²', 'hestahub' ), number_format_i18n( $apartment['qm'] ) );
		}
		if ( isset( $apartment['max_persons'] ) && is_int( $apartment['max_persons'] ) ) {
			/* translators: %s: the maximum number of persons. */
			$facts['persons'] = sprintf( _n( 'Up to %s person', 'Up to %s persons', $apartment['max_persons'], 'hestahub' ), number_format_i18n( $apartment['max_persons'] ) );
		}
		return $facts;
	}

	/**
	 * A tier's night range, in words: "3–6 nights", "7+ nights", "3 nights".
	 *
	 * @param array $tier A tier as received.
	 */
	public static function nights( array $tier ): string {
		$min = isset( $tier['min_nights'] ) && is_int( $tier['min_nights'] ) ? $tier['min_nights'] : null;
		$max = isset( $tier['max_nights'] ) && is_int( $tier['max_nights'] ) ? $tier['max_nights'] : null;
		if ( null === $min ) {
			return '';
		}
		if ( null === $max ) {
			/* translators: %s: the minimum number of nights. An open-ended tier, e.g. "7+ nights". */
			return sprintf( _n( '%s+ night', '%s+ nights', $min, 'hestahub' ), number_format_i18n( $min ) );
		}
		if ( $max === $min ) {
			/* translators: %s: a number of nights. */
			return sprintf( _n( '%s night', '%s nights', $min, 'hestahub' ), number_format_i18n( $min ) );
		}
		/* translators: 1: the minimum, 2: the maximum number of nights, e.g. "3–6 nights". */
		return sprintf( __( '%1$s–%2$s nights', 'hestahub' ), number_format_i18n( $min ), number_format_i18n( $max ) );
	}

	/**
	 * A tier's label: its name, else its Season's name and night range.
	 *
	 * @param array $tier   A tier as received.
	 * @param array $season Its Season as received.
	 */
	public static function tier_label( array $tier, array $season ): string {
		if ( isset( $tier['name'] ) && is_string( $tier['name'] ) && '' !== $tier['name'] ) {
			return $tier['name'];
		}
		$name = isset( $season['name'] ) && is_string( $season['name'] ) ? $season['name'] : '';
		return '' === $name ? self::nights( $tier ) : $name . ', ' . self::nights( $tier );
	}

	/**
	 * A date from the wire (YYYY-MM-DD) in the site's date format.
	 *
	 * @param mixed $day The date as received.
	 */
	public static function day( $day ): string {
		if ( ! is_string( $day ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) {
			return is_string( $day ) ? $day : '';
		}
		return (string) mysql2date( (string) get_option( 'date_format' ), $day );
	}

	/**
	 * A Season's Period, both days inclusive: "26.06.2027 – 28.08.2027".
	 *
	 * @param array $period A Period as received.
	 */
	public static function period( array $period ): string {
		return self::day( $period['first_day'] ?? null ) . ' – ' . self::day( $period['last_day'] ?? null );
	}

	/**
	 * The members of a list field that are objects.
	 *
	 * @param mixed  $object An object as received, or anything else.
	 * @param string $key    The list field.
	 * @return array[]
	 */
	public static function list_of( $object, string $key ): array {
		if ( ! is_array( $object ) || ! isset( $object[ $key ] ) || ! is_array( $object[ $key ] ) ) {
			return array();
		}
		return array_values( array_filter( $object[ $key ], 'is_array' ) );
	}

	/**
	 * A decimal string with the locale's separators, unchanged in value.
	 *
	 * @param string $amount A decimal string.
	 */
	private static function number( string $amount ): string {
		if ( ! self::is_decimal( $amount ) ) {
			return $amount;
		}
		global $wp_locale;
		$decimal   = isset( $wp_locale->number_format['decimal_point'] ) ? $wp_locale->number_format['decimal_point'] : '.';
		$thousands = isset( $wp_locale->number_format['thousands_sep'] ) ? $wp_locale->number_format['thousands_sep'] : ',';
		$sign      = '-' === $amount[0] ? '-' : '';
		$parts     = explode( '.', ltrim( $amount, '-' ), 2 );
		$whole     = strrev( implode( $thousands, str_split( strrev( $parts[0] ), 3 ) ) );
		return $sign . $whole . ( isset( $parts[1] ) ? $decimal . $parts[1] : '' );
	}

	/**
	 * A formatted number with its currency, placed for the locale.
	 *
	 * @param string      $number   The formatted number or range.
	 * @param string|null $currency An ISO 4217 code, or null for none.
	 */
	private static function with_currency( string $number, ?string $currency ): string {
		if ( null === $currency || '' === $currency ) {
			return $number;
		}
		$symbol = isset( self::SYMBOLS[ $currency ] ) ? self::SYMBOLS[ $currency ] : $currency;
		if ( 0 === strpos( determine_locale(), 'en' ) ) {
			return isset( self::SYMBOLS[ $currency ] ) ? $symbol . $number : $symbol . "\u{00A0}" . $number;
		}
		return $number . "\u{00A0}" . $symbol;
	}

	private static function is_decimal( string $amount ): bool {
		return 1 === preg_match( '/^-?\d+(\.\d+)?$/', $amount );
	}

	private static function is_zero( string $amount ): bool {
		return 1 === preg_match( '/^-?0+(\.0+)?$/', $amount );
	}

	/**
	 * A non-negative decimal string without leading zeros or trailing
	 * fractional zeros, so equal values compare equal.
	 *
	 * @param string $amount A decimal string.
	 */
	private static function canonical( string $amount ): string {
		$parts = explode( '.', $amount, 2 );
		$whole = ltrim( $parts[0], '0' );
		$frac  = isset( $parts[1] ) ? rtrim( $parts[1], '0' ) : '';
		return ( '' === $whole ? '0' : $whole ) . ( '' === $frac ? '' : '.' . $frac );
	}

	/**
	 * Orders two canonical non-negative decimal strings by value, without
	 * turning them into numbers.
	 *
	 * @param string $a A canonical decimal string.
	 * @param string $b Another.
	 */
	private static function compare( $a, $b ): int {
		$a = explode( '.', (string) $a, 2 ) + array( 1 => '' );
		$b = explode( '.', (string) $b, 2 ) + array( 1 => '' );
		if ( strlen( $a[0] ) !== strlen( $b[0] ) ) {
			return strlen( $a[0] ) <=> strlen( $b[0] );
		}
		$length = max( strlen( $a[1] ), strlen( $b[1] ) );
		return strcmp( $a[0] . str_pad( $a[1], $length, '0' ), $b[0] . str_pad( $b[1], $length, '0' ) ) <=> 0;
	}
}
