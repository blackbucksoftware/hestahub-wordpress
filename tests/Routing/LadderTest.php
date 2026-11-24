<?php
/**
 * @package HestaHub
 */

namespace HestaHub\Tests\Routing;

use HestaHub\Tests\Support\FakeHestaHub;
use HestaHub\Tests\Support\VisitsRoutes;
use WP_UnitTestCase;

/**
 * What an owned route answers when there is no data: the render ladder,
 * counted from the last successful fetch. Contract:
 * https://docs.hestahub.com/v1/#what-a-route-returns-when-there-is-no-data
 */
final class LadderTest extends WP_UnitTestCase {

	use FakeHestaHub;
	use VisitsRoutes;

	/** @var int|null The last status sent. */
	private $status;

	/** @var string[] What the 503 preamble did, in order. */
	private $preamble = array();

	public function set_up() {
		parent::set_up();
		putenv( 'HESTAHUB_API_KEY' );
		update_option( 'hestahub_tenant', 'kronshof' );
		update_option( 'hestahub_api_key', 'k3y' );
		update_option( 'hestahub_apartments_base', 'wohnungen' );
		$this->set_permalink_structure( '/%postname%/' );
		$this->fake_hestahub();
		add_filter(
			'status_header',
			function ( $header, $code ) {
				$this->status     = $code;
				$this->preamble[] = 'status_header ' . $code;
				return $header;
			},
			10,
			2
		);
		add_action(
			'litespeed_control_set_nocache',
			function () {
				$this->preamble[] = 'litespeed_control_set_nocache' . ( defined( 'DONOTCACHEPAGE' ) && true === DONOTCACHEPAGE ? ' after DONOTCACHEPAGE' : '' );
			}
		);
	}

	public function test_a_stale_copy_is_served_while_the_api_is_down() {
		$this->respond( 200, $this->example( 'apartments' ) );
		$this->client()->get( 'apartments' );
		$this->respond( 500 );
		$this->respond( 500 ); // The page's calendar.
		$this->now += DAY_IN_SECONDS; // Past the example's ttl, within its stale_ttl.

		$this->request( '/wohnungen/hoettur/' );

		$this->assertSame( 200, $this->status );
		$this->assertStringContainsString( 'hestahub-apartment"', $this->content() );
	}

	public function test_a_fresh_install_that_cannot_fetch_answers_503_with_the_real_retry_interval() {
		$this->respond( 500 );

		$this->request( '/wohnungen/' );

		$this->assertSame( 503, $this->status );
		$this->assertContains( 'Retry-After: 60', $this->headers, 'The first back-off step.' );
		$this->assertStringContainsString( 'hestahub-unavailable', $this->content() );
		$this->assertFalse( is_404() );
	}

	public function test_past_stale_ttl_it_answers_503_for_up_to_24_hours() {
		$this->respond( 200, $this->example( 'apartments' ) );
		$this->client()->get( 'apartments' );
		$this->respond( 500 );
		$this->now += WEEK_IN_SECONDS + DAY_IN_SECONDS - 1; // The example's stale_ttl is a week.

		$this->request( '/wohnungen/hoettur/' );

		$this->assertSame( 503, $this->status );
		$this->assertStringContainsString( 'hestahub-unavailable', $this->content() );
	}

	public function test_after_24_hours_without_data_it_answers_200_with_a_placeholder() {
		$this->respond( 200, $this->example( 'apartments' ) );
		$this->client()->get( 'apartments' );
		$this->respond( 500 );
		$this->now += WEEK_IN_SECONDS + DAY_IN_SECONDS;

		$this->request( '/wohnungen/hoettur/' );

		$this->assertSame( 200, $this->status );
		$this->assertFalse( is_404() );
		$this->assertStringContainsString( 'hestahub-placeholder', $this->content() );
		$this->assertSame( array(), $this->headers, 'No Retry-After on a 200.' );
	}

	public function test_a_fresh_install_reaches_the_placeholder_24_hours_after_its_first_failure() {
		$this->respond( 500 );
		$this->client()->get( 'apartments' );
		$this->respond( 500 );
		$this->now += DAY_IN_SECONDS;

		$this->request( '/wohnungen/' );

		$this->assertSame( 200, $this->status );
		$this->assertStringContainsString( 'hestahub-placeholder', $this->content() );
	}

	public function test_refresh_now_during_an_outage_starts_the_24_hours_then() {
		$this->respond( 200, $this->example( 'apartments' ) );
		$this->client()->get( 'apartments' );
		$this->now += HOUR_IN_SECONDS;
		$this->client()->flush(); // Discards the copy holding the site up.
		$this->respond( 500 );
		$this->client()->get( 'apartments' );
		$this->respond( 500 );
		$this->now += DAY_IN_SECONDS;

		$this->request( '/wohnungen/' );

		$this->assertSame( 200, $this->status, 'At most 24 hours of 503.' );
		$this->assertStringContainsString( 'hestahub-placeholder', $this->content() );
	}

	public function test_without_credentials_it_answers_200_with_a_placeholder() {
		delete_option( 'hestahub_api_key' );

		$this->request( '/wohnungen/' );

		$this->assertCount( 0, $this->requests );
		$this->assertSame( 200, $this->status, 'Nothing to retry, so no 503.' );
		$this->assertStringContainsString( 'hestahub-placeholder', $this->content() );
	}

	public function test_a_slug_never_resolved_answers_404_during_an_outage() {
		$this->respond( 500 );

		$this->request( '/wohnungen/nie-gesehen/' );

		$this->assertTrue( is_404() );
		$this->assertSame( 404, $this->status );
	}

	public function test_a_slug_once_resolved_answers_503_during_an_outage() {
		$this->respond( 200, $this->example( 'apartments' ) );
		$this->request( '/wohnungen/' );
		$this->respond( 500 );
		$this->now += WEEK_IN_SECONDS;

		$this->request( '/wohnungen/hoettur/' );

		$this->assertSame( 503, $this->status );
	}

	public function test_a_slug_once_resolved_answers_the_placeholder_after_24_hours() {
		$this->respond( 200, $this->example( 'apartments' ) );
		$this->request( '/wohnungen/' );
		$this->respond( 500 );
		$this->now += WEEK_IN_SECONDS + DAY_IN_SECONDS;

		$this->request( '/wohnungen/hoettur/' );

		$this->assertSame( 200, $this->status );
		$this->assertStringContainsString( 'hestahub-placeholder', $this->content() );
	}

	/**
	 * nocache_headers() returns early once output is sent, as it always is
	 * under PHPUnit, so its filter cannot show it here; a separate process
	 * trips over the test suite's own deprecations. What can be seen: the
	 * constant, then the LiteSpeed action, then the status.
	 */
	public function test_the_503_preamble_runs_in_order_before_any_output() {
		$this->respond( 500 );

		$this->request( '/wohnungen/' );

		$this->assertSame(
			array(
				'litespeed_control_set_nocache after DONOTCACHEPAGE',
				'status_header 503',
			),
			array_values( array_filter( $this->preamble, array( $this, 'is_preamble' ) ) )
		);
	}

	public function test_a_503_body_is_larger_than_512_bytes() {
		$this->respond( 500 );
		$this->request( '/wohnungen/' );

		ob_start();
		do_action( 'template_redirect' );
		echo '<p>Kurz.</p>';
		ob_end_flush();
		$body = ob_get_clean();

		$this->assertGreaterThan( 512, strlen( $body ), 'Page caches otherwise store the outage as 200 OK.' );
		$this->assertStringStartsWith( '<p>Kurz.</p>', $body );
	}

	public function test_no_error_text_reaches_a_visitor() {
		$this->respond( 500, $this->example( 'error' ) );
		$this->request( '/wohnungen/' );

		$body = $this->content();

		$error = json_decode( $this->example( 'error' ), true )['error'];
		$this->assertStringNotContainsString( $error['message'], $body );
		$this->assertStringNotContainsString( $error['code'], $body );
		$this->assertStringNotContainsString( '500', $body );
	}

	/**
	 * Visits a path, forgetting the status and preamble of the last visit.
	 *
	 * @param string $path The path.
	 */
	private function request( string $path ): void {
		$this->status   = null;
		$this->preamble = array();
		$this->visit( $path );
	}

	private function is_preamble( string $step ): bool {
		return 'status_header 200' !== $step;
	}
}
