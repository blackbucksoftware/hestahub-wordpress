<?php
/**
 * @package HestaHub
 */

namespace HestaHub\Tests\Rendering;

use HestaHub\Rendering\Calendar;
use WP_UnitTestCase;

/**
 * Day states from availability's half-open ranges, the window, and paging.
 * Contract: https://docs.hestahub.com/v1/#get-apiv1availability
 */
final class CalendarTest extends WP_UnitTestCase {

	const WINDOW = array(
		'from' => '2026-11-23',
		'to'   => '2027-11-23',
	);

	public function test_a_range_books_its_nights_with_arrival_and_departure_at_its_ends() {
		$calendar = $this->calendar( array( array( '2027-07-03', '2027-07-10' ) ) );

		$this->assertSame( 'free', $calendar->state( '2027-07-02' ) );
		$this->assertSame( 'arrival', $calendar->state( '2027-07-03' ), 'The first blocked night.' );
		$this->assertSame( 'booked', $calendar->state( '2027-07-04' ) );
		$this->assertSame( 'booked', $calendar->state( '2027-07-09' ) );
		$this->assertSame( 'departure', $calendar->state( '2027-07-10' ), 'The morning the stay ends: free to arrive on.' );
		$this->assertSame( 'free', $calendar->state( '2027-07-11' ) );
	}

	public function test_adjacent_ranges_are_a_changeover_never_merged() {
		$calendar = $this->calendar( array( array( '2027-07-03', '2027-07-10' ), array( '2027-07-10', '2027-07-17' ) ) );

		$this->assertSame( 'changeover', $calendar->state( '2027-07-10' ) );
		$this->assertSame( 'departure', $calendar->state( '2027-07-17' ) );
	}

	public function test_a_one_night_stay_is_arrival_then_departure() {
		$calendar = $this->calendar( array( array( '2027-07-03', '2027-07-04' ) ) );

		$this->assertSame( 'arrival', $calendar->state( '2027-07-03' ) );
		$this->assertSame( 'departure', $calendar->state( '2027-07-04' ) );
	}

	public function test_days_before_today_are_past() {
		$calendar = $this->calendar( array( array( '2026-11-20', '2026-11-27' ) ), '2026-11-24' );

		$this->assertSame( 'past', $calendar->state( '2026-11-23' ) );
		$this->assertSame( 'booked', $calendar->state( '2026-11-24' ) );
	}

	public function test_days_outside_the_window_are_unknown_never_free() {
		$calendar = $this->calendar( array(), '2026-11-20' );

		$this->assertSame( 'unknown', $calendar->state( '2026-11-22' ), 'Before data.from.' );
		$this->assertSame( 'free', $calendar->state( '2026-11-23' ), 'data.from is inside.' );
		$this->assertSame( 'free', $calendar->state( '2027-11-22' ) );
		$this->assertSame( 'unknown', $calendar->state( '2027-11-23' ), 'data.to is outside: half-open.' );
	}

	public function test_without_a_window_every_day_is_unknown() {
		$calendar = new Calendar( array( 'blocked' => array() ), array( 'from' => null, 'to' => null ), '2026-11-24' );

		$this->assertSame( 'unknown', $calendar->state( '2026-12-01' ) );
	}

	public function test_a_range_under_way_at_the_window_start_is_clipped() {
		$calendar = $this->calendar( array( array( '2026-11-20', '2026-11-27' ) ), '2026-11-23' );

		$this->assertSame( 'booked', $calendar->state( '2026-11-23' ), 'Clipped: no arrival shown at the window start.' );
		$this->assertSame( 'departure', $calendar->state( '2026-11-27' ) );
	}

	public function test_unreadable_ranges_are_skipped() {
		$calendar = new Calendar(
			array(
				'blocked' => array(
					'kaputt',
					array( 'from' => '2027-07-03' ),
					array(
						'from' => '2027-08-01',
						'to'   => '2027-08-03',
					),
				),
			),
			self::WINDOW,
			'2026-11-24'
		);

		$this->assertSame( 'free', $calendar->state( '2027-07-04' ) );
		$this->assertSame( 'arrival', $calendar->state( '2027-08-01' ) );
	}

	public function test_three_months_from_the_current_one_in_weeks() {
		$calendar = $this->calendar( array(), '2026-11-24' );

		$months = $calendar->months( $calendar->page( null ) );

		$this->assertSame( array( '2026-11', '2026-12', '2027-01' ), array_column( $months, 'month' ) );
		$weeks = $months[0]['weeks'];
		$this->assertNull( $weeks[0][0], 'November 2026 starts on a Sunday: Monday to Saturday are blank.' );
		$this->assertSame( '2026-11-01', $weeks[0][6]['date'] );
		$this->assertSame( '2026-11-30', $weeks[5][0]['date'] );
		$this->assertCount( 7, end( $weeks ) );
	}

	public function test_weeks_start_on_the_sites_first_day() {
		$calendar = $this->calendar( array(), '2026-11-24', 0 );

		$weeks = $calendar->months( '2026-11' )[0]['weeks'];

		$this->assertSame( '2026-11-01', $weeks[0][0]['date'] );
	}

	public function test_paging_starts_at_the_current_month_and_cannot_go_before_it() {
		$calendar = $this->calendar( array(), '2026-11-24' );

		$this->assertSame( '2026-11', $calendar->page( null ) );
		$this->assertSame( '2026-11', $calendar->page( '2026-08' ) );
		$this->assertSame( '2026-11', $calendar->page( 'neulich' ) );
		$this->assertNull( $calendar->previous( '2026-11' ) );
		$this->assertSame( '2026-11', $calendar->previous( '2027-02' ) );
		$this->assertSame( '2027-02', $calendar->next( '2026-11' ) );
	}

	public function test_paging_stops_at_the_window_end() {
		$calendar = $this->calendar( array(), '2026-11-24' );

		$this->assertSame( '2027-11', $calendar->page( '2028-05' ), 'The last month with a known day.' );
		$this->assertSame( '2027-11', $calendar->next( '2027-08' ) );
		$this->assertNull( $calendar->next( '2027-09' ), 'November 2027 is already shown.' );
		$this->assertNull( $calendar->next( '2027-11' ) );
	}

	public function test_a_previous_page_never_skips_months() {
		$calendar = $this->calendar( array(), '2026-11-24' );

		$this->assertSame( '2026-12', $calendar->previous( '2027-03' ) );
	}

	/**
	 * @param array[] $ranges        Blocked ranges as [from, to].
	 * @param string  $today         Today.
	 * @param int     $start_of_week 0 for Sunday, 1 for Monday.
	 */
	private function calendar( array $ranges, string $today = '2026-11-24', int $start_of_week = 1 ): Calendar {
		$blocked = array();
		foreach ( $ranges as $range ) {
			$blocked[] = array(
				'from' => $range[0],
				'to'   => $range[1],
			);
		}
		return new Calendar(
			array(
				'slug'    => 'hoettur',
				'blocked' => $blocked,
			),
			self::WINDOW,
			$today,
			$start_of_week
		);
	}
}
