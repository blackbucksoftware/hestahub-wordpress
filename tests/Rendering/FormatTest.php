<?php
/**
 * @package HestaHub
 */

namespace HestaHub\Tests\Rendering;

use HestaHub\Rendering\Format;
use WP_UnitTestCase;

/**
 * Words and numbers from wire values: amounts from decimal strings, never
 * computed; the listing's Endreinigung; tier labels; inclusive periods.
 */
final class FormatTest extends WP_UnitTestCase {

	/** @var array|null The locale's number format before a test changed it. */
	private $number_format;

	public function tear_down() {
		global $wp_locale;
		if ( null !== $this->number_format ) {
			$wp_locale->number_format = $this->number_format;
		}
		parent::tear_down();
	}

	public function test_an_amount_in_english() {
		$this->assertSame( '€95.00', Format::amount( '95.00', 'EUR' ) );
		$this->assertSame( '€1,234,567.10', Format::amount( '1234567.10', 'EUR' ) );
	}

	public function test_an_amount_in_german() {
		$this->german();

		$this->assertSame( "1.295,00\u{00A0}€", Format::amount( '1295.00', 'EUR' ) );
	}

	public function test_an_amount_is_never_rounded() {
		$this->assertSame( '€0.10', Format::amount( '0.10', 'EUR' ) );
		$this->assertSame( '€90,071,992,547,409,931.99', Format::amount( '90071992547409931.99', 'EUR' ), 'Past what a float holds.' );
	}

	public function test_an_unknown_currency_prints_its_code_and_none_prints_nothing() {
		$this->german();

		$this->assertSame( "95,00\u{00A0}CHF", Format::amount( '95.00', 'CHF' ) );
		$this->assertSame( '95,00', Format::amount( '95.00', null ) );
	}

	public function test_what_is_not_a_decimal_string_prints_as_received() {
		$this->assertSame( '€ab', Format::amount( 'ab', 'EUR' ) );
	}

	public function test_the_cleaning_fee_is_one_value_when_they_agree() {
		$this->assertSame( '€45.00', Format::cleaning_fee( $this->apartment( array( '45.00', '45.0', '0.00' ) ), 'EUR' ) );
	}

	public function test_the_cleaning_fee_is_a_range_ordered_by_value_when_they_differ() {
		$this->german();

		$this->assertSame( "60,00–100,00\u{00A0}€", Format::cleaning_fee( $this->apartment( array( '100.00', '60.00', '75.50' ) ), 'EUR' ) );
	}

	public function test_the_cleaning_fee_is_nothing_when_there_is_none() {
		$this->assertNull( Format::cleaning_fee( $this->apartment( array( '0.00', '0.00' ) ), 'EUR' ) );
		$this->assertNull( Format::cleaning_fee( array( 'pricing' => null ), 'EUR' ) );
	}

	public function test_night_ranges() {
		$this->assertSame( '3–6 nights', Format::nights( array( 'min_nights' => 3, 'max_nights' => 6 ) ) );
		$this->assertSame( '12+ nights', Format::nights( array( 'min_nights' => 12, 'max_nights' => null ) ) );
		$this->assertSame( '1 night', Format::nights( array( 'min_nights' => 1, 'max_nights' => 1 ) ) );
	}

	public function test_a_tier_label_is_its_name_else_its_season_and_nights() {
		$season = array( 'name' => 'Hauptsaison' );

		$this->assertSame( 'Kurzurlaub', Format::tier_label( array( 'name' => 'Kurzurlaub', 'min_nights' => 2, 'max_nights' => 3 ), $season ) );
		$this->assertSame( 'Hauptsaison, 7+ nights', Format::tier_label( array( 'name' => null, 'min_nights' => 7, 'max_nights' => null ), $season ) );
	}

	public function test_a_period_shows_both_days_inclusive_in_the_site_format() {
		update_option( 'date_format', 'j. F Y' );

		$this->assertSame( '26. June 2027 – 28. August 2027', Format::period( array( 'first_day' => '2027-06-26', 'last_day' => '2027-08-28' ) ) );
	}

	public function test_when_a_clinic_runs_at_the_offset_received() {
		update_option( 'date_format', 'd.m.Y' );
		update_option( 'time_format', 'H:i' );
		update_option( 'timezone_string', 'Asia/Tokyo' ); // Ignored: the instant carries the Tenant's offset.

		$this->assertSame( '14.03.2027, 14:00 – 16.03.2027, 16:00', Format::when( '2027-03-14T14:00:00+01:00', '2027-03-16T16:00:00+01:00' ) );
		$this->assertSame( '14.03.2027, 14:00–17:30', Format::when( '2027-03-14T14:00:00+01:00', '2027-03-14T17:30:00+01:00' ) );
		$this->assertSame( '14.03.2027, 14:00', Format::when( '2027-03-14T14:00:00+01:00', null ), 'No set end.' );
		$this->assertSame( '', Format::when( 'bald', null ) );
	}

	public function test_signup_states_and_unknown_values() {
		$this->assertSame( 'Signup open', Format::signup( 'open' ) );
		$this->assertSame( 'Waitlist only', Format::signup( 'waitlist' ) );
		$this->assertSame( 'Fully booked', Format::signup( 'full' ) );
		$this->assertSame( 'Signup closed', Format::signup( 'closed' ) );
		$this->assertSame( 'Signup not yet open', Format::signup( 'not_yet_open' ) );
		$this->assertSame( '', Format::signup( 'lotterie' ), 'An unknown state prints nothing.' );
		$this->assertSame( '', Format::signup( null ) );
	}

	public function test_horses_and_unknown_values() {
		$this->assertSame( 'Bring your own horse.', Format::horses( 'own_only' ) );
		$this->assertSame( 'Bring your own horse or ride one of ours.', Format::horses( 'own_or_loan' ) );
		$this->assertSame( 'No horses needed.', Format::horses( 'none' ) );
		$this->assertSame( '', Format::horses( 'esel' ) );
	}

	public function test_a_signup_url_only_while_open_or_waitlist() {
		$clinic = array( 'portal_url' => 'https://kronshof.hestahub.com/portal/x' );

		$this->assertSame( $clinic['portal_url'], Format::signup_url( $clinic + array( 'signup' => 'open' ) ) );
		$this->assertSame( $clinic['portal_url'], Format::signup_url( $clinic + array( 'signup' => 'waitlist' ) ) );
		$this->assertNull( Format::signup_url( $clinic + array( 'signup' => 'full' ) ) );
		$this->assertNull( Format::signup_url( $clinic + array( 'signup' => 'lotterie' ) ), 'Unknown: closed for any call to action.' );
		$this->assertNull( Format::signup_url( array( 'signup' => 'open', 'portal_url' => null ) ) );
	}

	private function german(): void {
		global $wp_locale;
		$this->number_format      = $wp_locale->number_format;
		$wp_locale->number_format = array(
			'thousands_sep' => '.',
			'decimal_point' => ',',
		);
		add_filter(
			'determine_locale',
			function () {
				return 'de_DE';
			}
		);
	}

	/**
	 * An Apartment whose tiers carry these final cleaning fees.
	 *
	 * @param string[] $fees The fees.
	 */
	private function apartment( array $fees ): array {
		$tiers = array();
		foreach ( $fees as $fee ) {
			$tiers[] = array( 'final_cleaning_fee' => $fee );
		}
		return array(
			'pricing' => array(
				'seasons' => array(
					array(
						'tiers'   => $tiers,
						'bundles' => array(),
					),
				),
			),
		);
	}
}
