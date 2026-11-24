<?php
/**
 * @package HestaHub
 */

namespace HestaHub\Tests\Rendering;

use DOMDocument;
use DOMXPath;
use HestaHub\Api\Client;
use HestaHub\Api\Credentials;
use HestaHub\Tests\Support\FakeHestaHub;
use WP_UnitTestCase;

/**
 * The calendar partial, rendered server-side from availability. Dates are
 * set relative to today, as the live API's window is.
 */
final class CalendarTemplateTest extends WP_UnitTestCase {

	use FakeHestaHub;

	/** @var \DateTimeImmutable Today, in the site's calendar. */
	private $today;

	public function set_up() {
		parent::set_up();
		putenv( 'HESTAHUB_API_KEY' );
		update_option( 'hestahub_tenant', 'kronshof' );
		update_option( 'hestahub_api_key', 'k3y' );
		update_option( 'date_format', 'd.m.Y' );
		update_option( 'time_format', 'H:i' );
		update_option( 'start_of_week', 1 );
		$this->fake_hestahub();
		$this->today = new \DateTimeImmutable( wp_date( 'Y-m-d' ), new \DateTimeZone( 'UTC' ) );
		unset( $_GET['hestahub_month'] );
		set_query_var( 'hestahub_month', '' );
	}

	public function tear_down() {
		unset( $_GET['hestahub_month'] );
		parent::tear_down();
	}

	public function test_it_renders_three_months_from_the_current_one() {
		$this->respond( 200, $this->availability( array() ) );

		$xpath = $this->render();

		$this->assertSame(
			array( $this->month( 0 ), $this->month( 1 ), $this->month( 2 ) ),
			$this->attributes( $xpath, '//table[@class="hestahub-month"]/@data-hestahub-month' )
		);
	}

	public function test_each_day_carries_its_state() {
		$this->respond( 200, $this->availability( array( array( 10, 13 ), array( 13, 15 ) ) ) );

		$xpath = $this->render();

		$this->assertSame( 'arrival', $this->state( $xpath, 10 ) );
		$this->assertSame( 'booked', $this->state( $xpath, 11 ) );
		$this->assertSame( 'changeover', $this->state( $xpath, 13 ) );
		$this->assertSame( 'departure', $this->state( $xpath, 15 ) );
		$this->assertSame( 'free', $this->state( $xpath, 16 ) );
		if ( '01' !== $this->today->format( 'd' ) ) {
			$this->assertSame( 'past', $this->state( $xpath, -1 ) );
		}
	}

	public function test_a_day_names_its_state_for_screen_readers() {
		$this->respond( 200, $this->availability( array( array( 10, 13 ) ) ) );

		$xpath = $this->render();

		$this->assertSame( 'Arrival', trim( $xpath->evaluate( 'string(//td[time/@datetime="' . $this->day( 10 ) . '"]/span)' ), " \t\n\r\0\x0B" ) );
	}

	public function test_days_past_the_window_render_as_unknown() {
		$this->respond( 200, $this->availability( array(), 20 ) ); // The window ends in 20 days.

		$xpath = $this->render();

		$this->assertSame( 'free', $this->state( $xpath, 19 ) );
		$this->assertSame( 'unknown', $this->state( $xpath, 20 ) );
		$this->assertSame( 'unknown', $this->state( $xpath, 40 ) );
	}

	public function test_the_legend_names_every_state() {
		$this->respond( 200, $this->availability( array() ) );

		$xpath = $this->render();

		$this->assertSame( array( 'Free', 'Arrival', 'Booked', 'Departure', 'Changeover', 'Past', 'Not known' ), $this->texts( $xpath, '//ul[@class="hestahub-legend"]/li' ) );
		$this->assertSame( 7.0, $xpath->evaluate( 'count(//ul[@class="hestahub-legend"]/li/span[contains(@class,"hestahub-day--")])' ) );
	}

	public function test_the_stand_line_shows_the_last_successful_fetch_even_when_stale() {
		$fetched = time() - 2 * DAY_IN_SECONDS;
		$client  = new Client(
			new Credentials(),
			function () use ( $fetched ) {
				return $fetched;
			}
		);
		$this->respond( 200, $this->availability( array() ) );
		$client->get( 'availability' );
		$this->respond( 500 ); // The refetch fails: the stale copy is served.

		$xpath = $this->render();

		$this->assertSame( array( 'As of ' . wp_date( 'd.m.Y H:i', $fetched ) ), $this->texts( $xpath, '//p[@class="hestahub-stand"]' ) );
	}

	public function test_the_first_page_links_forward_only() {
		$this->respond( 200, $this->availability( array() ) );

		$xpath = $this->render();

		$this->assertSame( 0.0, $xpath->evaluate( 'count(//a[@rel="prev"])' ) );
		$this->assertStringContainsString( 'hestahub_month=' . $this->month( 3 ), $xpath->evaluate( 'string(//a[@rel="next"]/@href)' ) );
	}

	public function test_a_later_page_starts_where_asked_and_links_both_ways() {
		$this->respond( 200, $this->availability( array() ) );
		set_query_var( 'hestahub_month', $this->month( 3 ) );

		$xpath = $this->render();

		$this->assertSame( $this->month( 3 ), $xpath->evaluate( 'string(//table[@class="hestahub-month"][1]/@data-hestahub-month)' ) );
		$this->assertStringNotContainsString( 'hestahub_month', $xpath->evaluate( 'string(//a[@rel="prev"]/@href)' ), 'Back to the first page: no parameter.' );
		$this->assertStringContainsString( 'hestahub_month=' . $this->month( 6 ), $xpath->evaluate( 'string(//a[@rel="next"]/@href)' ) );
	}

	public function test_paging_stops_at_the_window_end() {
		$this->respond( 200, $this->availability( array(), 40 ) );

		$xpath = $this->render();

		$this->assertSame( 0.0, $xpath->evaluate( 'count(//a[@rel="next"])' ), 'Every known day is on the first page.' );
	}

	public function test_a_page_asked_past_the_window_shows_its_last_months() {
		$this->respond( 200, $this->availability( array() ) );
		$_GET['hestahub_month'] = $this->month( 30 );

		$xpath = $this->render();

		$last = ( new \DateTimeImmutable( $this->day( 365 ), new \DateTimeZone( 'UTC' ) ) )->modify( '-1 day' )->format( 'Y-m' );
		$this->assertSame( $last, $xpath->evaluate( 'string(//table[@class="hestahub-month"][1]/@data-hestahub-month)' ) );
		$this->assertSame( 0.0, $xpath->evaluate( 'count(//a[@rel="next"])' ) );
	}

	/**
	 * An availability body for hoettur: ranges and window end as days from today.
	 *
	 * @param array[] $ranges Blocked ranges as [from, to] days from today.
	 * @param int     $until  The window's end, in days from today.
	 */
	private function availability( array $ranges, int $until = 365 ): string {
		return $this->example(
			'availability',
			function ( $body ) use ( $ranges, $until ) {
				$blocked = array();
				foreach ( $ranges as $range ) {
					$blocked[] = array(
						'from' => $this->day( $range[0] ),
						'to'   => $this->day( $range[1] ),
					);
				}
				$body['data']['from']                     = $this->day( 0 );
				$body['data']['to']                       = $this->day( $until );
				$body['data']['apartments'][0]['blocked'] = $blocked;
				return $body;
			}
		);
	}

	private function day( int $offset ): string {
		return $this->today->modify( sprintf( '%+d days', $offset ) )->format( 'Y-m-d' );
	}

	private function month( int $offset ): string {
		return $this->today->modify( 'first day of this month' )->modify( sprintf( '%+d months', $offset ) )->format( 'Y-m' );
	}

	/**
	 * The state of a day on the page, by its offset from today.
	 *
	 * @param DOMXPath $xpath  The page.
	 * @param int      $offset Days from today.
	 */
	private function state( DOMXPath $xpath, int $offset ): string {
		$class = $xpath->evaluate( 'string(//td[time/@datetime="' . $this->day( $offset ) . '"]/@class)' );
		$this->assertNotSame( '', $class, 'The day is on the page.' );
		return (string) preg_replace( '/.*hestahub-day--(\w+).*/', '$1', $class );
	}

	private function render(): DOMXPath {
		ob_start();
		hestahub_render( 'calendar', array( 'slug' => 'hoettur' ) );
		$html = (string) ob_get_clean();
		$this->assertNotSame( '', $html );
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8"><body>' . $html . '</body>' );
		libxml_clear_errors();
		return new DOMXPath( $dom );
	}

	/**
	 * @param DOMXPath $xpath The page.
	 * @param string   $query An XPath query for attributes.
	 * @return string[]
	 */
	private function attributes( DOMXPath $xpath, string $query ): array {
		$values = array();
		foreach ( $xpath->query( $query ) as $node ) {
			$values[] = $node->nodeValue;
		}
		return $values;
	}

	/**
	 * @param DOMXPath $xpath The page.
	 * @param string   $query An XPath query.
	 * @return string[]
	 */
	private function texts( DOMXPath $xpath, string $query ): array {
		$texts = array();
		foreach ( $xpath->query( $query ) as $node ) {
			$texts[] = trim( preg_replace( '/\s+/u', ' ', $node->textContent ), " \t\n\r\0\x0B" );
		}
		return $texts;
	}
}
