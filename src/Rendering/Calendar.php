<?php
/**
 * @package HestaHub
 */

namespace HestaHub\Rendering;

/**
 * An Apartment's availability as months of days, each in one state, derived
 * from availability's half-open ranges and window. Contract:
 * https://docs.hestahub.com/v1/#get-apiv1availability
 *
 * A range blocks the nights from `from` up to the night before `to`. Ranges
 * are never merged: where one ends on the day another starts, that day is a
 * changeover. A day outside the window is unknown, never free.
 *
 * Dates are calendar dates (YYYY-MM-DD) in the Tenant's local calendar, so
 * they are compared as strings and stepped in UTC, never shifted by a zone.
 */
final class Calendar {

	const FREE       = 'free';
	const BOOKED     = 'booked';
	const ARRIVAL    = 'arrival';
	const DEPARTURE  = 'departure';
	const CHANGEOVER = 'changeover';
	const PAST       = 'past';
	const UNKNOWN    = 'unknown';

	/**
	 * Every state, in the order a legend lists them.
	 */
	const STATES = array( self::FREE, self::ARRIVAL, self::BOOKED, self::DEPARTURE, self::CHANGEOVER, self::PAST, self::UNKNOWN );

	/**
	 * How many months a page shows.
	 */
	const MONTHS = 3;

	/** @var array[] Readable ranges: from, to. */
	private $ranges = array();

	/** @var array<string, true> Days a range starts on. */
	private $starts = array();

	/** @var array<string, true> Days a range ends on. */
	private $ends = array();

	/** @var string|null */
	private $from;

	/** @var string|null */
	private $to;

	/** @var string */
	private $today;

	/** @var int 0 for Sunday … 6 for Saturday. */
	private $start_of_week;

	/**
	 * @param array  $availability  The Apartment's availability entry as received.
	 * @param array  $window        The window: from, to (either may be null).
	 * @param string $today         Today, YYYY-MM-DD, in the Tenant's calendar.
	 * @param int    $start_of_week 0 for Sunday … 6 for Saturday.
	 */
	public function __construct( array $availability, array $window, string $today, int $start_of_week = 1 ) {
		$this->from          = self::date_or_null( $window['from'] ?? null );
		$this->to            = self::date_or_null( $window['to'] ?? null );
		$this->today         = $today;
		$this->start_of_week = $start_of_week;

		$blocked = isset( $availability['blocked'] ) && is_array( $availability['blocked'] ) ? $availability['blocked'] : array();
		foreach ( $blocked as $range ) {
			$from = is_array( $range ) ? self::date_or_null( $range['from'] ?? null ) : null;
			$to   = is_array( $range ) ? self::date_or_null( $range['to'] ?? null ) : null;
			if ( null === $from || null === $to || $from >= $to ) {
				continue;
			}
			$this->ranges[] = array(
				'from' => $from,
				'to'   => $to,
			);
			// A range already under way at the window's start is clipped: it has no arrival here.
			if ( null === $this->from || $from >= $this->from ) {
				$this->starts[ $from ] = true;
			}
			$this->ends[ $to ] = true;
		}
	}

	/**
	 * A day's state.
	 *
	 * @param string $date YYYY-MM-DD.
	 */
	public function state( string $date ): string {
		if ( $date < $this->today ) {
			return self::PAST;
		}
		if ( null === $this->from || null === $this->to || $date < $this->from || $date >= $this->to ) {
			return self::UNKNOWN;
		}
		$starts = isset( $this->starts[ $date ] );
		$ends   = isset( $this->ends[ $date ] );
		if ( $starts && $ends ) {
			return self::CHANGEOVER;
		}
		foreach ( $this->ranges as $range ) {
			// Inside a stay, or at the window's start inside one already under way.
			if ( $range['from'] < $date && $date < $range['to'] ) {
				return self::BOOKED;
			}
		}
		if ( $starts ) {
			return self::ARRIVAL;
		}
		if ( $ends ) {
			return self::DEPARTURE;
		}
		return self::FREE;
	}

	/**
	 * The first month of the page asked for (YYYY-MM), kept between the
	 * current month and the last month with a known day.
	 *
	 * @param mixed $month The month asked for, e.g. from the query string.
	 */
	public function page( $month ): string {
		$first = substr( $this->today, 0, 7 );
		$last  = $this->last_month();
		if ( ! is_string( $month ) || ! preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $month ) || $month < $first ) {
			return $first;
		}
		return $month > $last ? $last : $month;
	}

	/**
	 * The page before one, or null on the first.
	 *
	 * @param string $start The page's first month, YYYY-MM.
	 */
	public function previous( string $start ): ?string {
		$first = substr( $this->today, 0, 7 );
		if ( $start <= $first ) {
			return null;
		}
		$previous = self::add_months( $start, -self::MONTHS );
		return $previous < $first ? $first : $previous;
	}

	/**
	 * The page after one, or null when it would show no known day.
	 *
	 * @param string $start The page's first month, YYYY-MM.
	 */
	public function next( string $start ): ?string {
		$next = self::add_months( $start, self::MONTHS );
		return $next > $this->last_month() ? null : $next;
	}

	/**
	 * A page's months, each as weeks of seven days; a day outside its
	 * month is null.
	 *
	 * @param string $start The first month, YYYY-MM.
	 * @return array[] month (YYYY-MM), weeks (arrays of date and state, or null).
	 */
	public function months( string $start ): array {
		$months = array();
		for ( $i = 0; $i < self::MONTHS; $i++ ) {
			$month    = self::add_months( $start, $i );
			$months[] = array(
				'month' => $month,
				'weeks' => $this->weeks( $month ),
			);
		}
		return $months;
	}

	/**
	 * @param string $month YYYY-MM.
	 * @return array[]
	 */
	private function weeks( string $month ): array {
		$day   = new \DateTimeImmutable( $month . '-01', new \DateTimeZone( 'UTC' ) );
		$blank = ( (int) $day->format( 'w' ) - $this->start_of_week + 7 ) % 7;
		$cells = array_fill( 0, $blank, null );
		while ( $day->format( 'Y-m' ) === $month ) {
			$date    = $day->format( 'Y-m-d' );
			$cells[] = array(
				'date'  => $date,
				'state' => $this->state( $date ),
			);
			$day     = $day->modify( '+1 day' );
		}
		while ( count( $cells ) % 7 ) {
			$cells[] = null;
		}
		return array_chunk( $cells, 7 );
	}

	/**
	 * The last month with a known day: the month of the day before the
	 * window's end; the current month without a window.
	 */
	private function last_month(): string {
		$first = substr( $this->today, 0, 7 );
		if ( null === $this->to ) {
			return $first;
		}
		$last = ( new \DateTimeImmutable( $this->to, new \DateTimeZone( 'UTC' ) ) )->modify( '-1 day' )->format( 'Y-m' );
		return $last < $first ? $first : $last;
	}

	/**
	 * @param string $month  YYYY-MM.
	 * @param int    $months How many months to add; may be negative.
	 */
	private static function add_months( string $month, int $months ): string {
		return ( new \DateTimeImmutable( $month . '-01', new \DateTimeZone( 'UTC' ) ) )->modify( sprintf( '%+d months', $months ) )->format( 'Y-m' );
	}

	/**
	 * @param mixed $value A date as received.
	 */
	private static function date_or_null( $value ): ?string {
		return is_string( $value ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : null;
	}
}
