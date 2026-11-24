<?php
/**
 * @package HestaHub
 */

namespace HestaHub\Tests\Health;

use HestaHub\Health\Probe;
use HestaHub\Tests\Support\FakeHestaHub;
use HestaHub\Tests\Support\VisitsRoutes;
use WP_UnitTestCase;

/**
 * "Test connection" checks once whether the host rewrites the plugin's 503.
 */
final class ProbeTest extends WP_UnitTestCase {

	use FakeHestaHub;
	use VisitsRoutes;

	/** @var int|null */
	private $status;

	public function set_up() {
		parent::set_up();
		putenv( 'HESTAHUB_API_KEY' );
		update_option( 'hestahub_tenant', 'kronshof' );
		update_option( 'hestahub_api_key', 'k3y' );
		update_option( 'hestahub_apartments_base', 'wohnungen' );
		$this->set_permalink_structure( '/%postname%/' );
		add_filter(
			'status_header',
			function ( $header, $code ) {
				$this->status = $code;
				return $header;
			},
			10,
			2
		);
	}

	public function tear_down() {
		unset( $_GET[ Probe::PARAM ] );
		parent::tear_down();
	}

	public function test_a_request_with_the_token_answers_the_real_503_with_its_marker() {
		$this->fake_hestahub();
		$this->respond( 200, $this->example( 'apartments' ) ); // Data is there: the probe forces the 503 anyway.
		set_transient( Probe::TOKEN, 'geheim', MINUTE_IN_SECONDS );
		$_GET[ Probe::PARAM ] = 'geheim';

		$this->visit( '/wohnungen/?hestahub_probe=geheim' );

		$this->assertSame( 503, $this->status );
		$this->assertContains( 'Retry-After: 1', $this->headers );
		$this->assertStringContainsString( Probe::marker( 'geheim' ), $this->body() );
	}

	public function test_a_wrong_or_expired_token_does_nothing() {
		$this->fake_hestahub();
		$this->respond( 200, $this->example( 'apartments' ) );
		$_GET[ Probe::PARAM ] = 'geraten';

		$this->visit( '/wohnungen/?hestahub_probe=geraten' );

		$this->assertSame( 200, $this->status );
	}

	public function test_an_intact_503_is_reported_ok() {
		$this->answer_probe(
			function ( $token ) {
				return $this->response( 503, '<html>' . Probe::marker( $token ) . '</html>' );
			}
		);

		$this->assertSame( Probe::OK, ( new Probe() )->run()['result'] );
		$this->assertFalse( get_transient( Probe::TOKEN ), 'The token is single-use.' );
	}

	public function test_a_503_turned_into_another_status_is_reported_rewritten() {
		$this->answer_probe(
			function ( $token ) {
				return $this->response( 200, '<html>' . Probe::marker( $token ) . '</html>' );
			}
		);

		$outcome = ( new Probe() )->run();

		$this->assertSame( Probe::REWRITTEN, $outcome['result'] );
		$this->assertSame( 200, $outcome['status'] );
	}

	public function test_a_503_replaced_by_the_hosts_own_page_is_reported_rewritten() {
		$this->answer_probe(
			function () {
				return $this->response( 503, '<html>Service Unavailable</html>' );
			}
		);

		$this->assertSame( Probe::REWRITTEN, ( new Probe() )->run()['result'] );
	}

	public function test_an_unreachable_site_is_reported_with_why() {
		$this->answer_probe(
			function () {
				return new \WP_Error( 'http_request_failed', 'cURL error 7' );
			}
		);

		$outcome = ( new Probe() )->run();

		$this->assertSame( Probe::UNREACHABLE, $outcome['result'] );
		$this->assertSame( 'cURL error 7', $outcome['message'] );
	}

	public function test_without_a_base_path_it_asks_for_one_and_requests_nothing() {
		delete_option( 'hestahub_apartments_base' );
		$requested = false;
		add_filter(
			'pre_http_request',
			function () use ( &$requested ) {
				$requested = true;
				return new \WP_Error( 'x', 'x' );
			}
		);

		$this->assertSame( Probe::NO_ROUTE, ( new Probe() )->run()['result'] );
		$this->assertFalse( $requested );
	}

	public function test_it_probes_the_owned_url_with_its_token() {
		$url = null;
		$this->answer_probe(
			function ( $token, $requested ) use ( &$url ) {
				$url = $requested;
				return $this->response( 503, Probe::marker( $token ) );
			}
		);

		( new Probe() )->run();

		$this->assertStringStartsWith( home_url( '/wohnungen/?hestahub_probe=' ), $url );
	}

	/**
	 * Answers the probe's request to this site.
	 *
	 * @param callable $answer Receives the token and URL, returns a response.
	 */
	private function answer_probe( callable $answer ): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $answer ) {
				parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
				$this->assertSame( get_transient( Probe::TOKEN ), $query['hestahub_probe'] ?? null, 'The token is live while the probe runs.' );
				return $answer( $query['hestahub_probe'] ?? '', $url );
			},
			10,
			3
		);
	}

	private function response( int $status, string $body ): array {
		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => $status,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * What the page prints, through the plugin's 503 output buffer.
	 */
	private function body(): string {
		ob_start();
		do_action( 'template_redirect' );
		echo '<p>Vorübergehend nicht verfügbar.</p>';
		ob_end_flush();
		return (string) ob_get_clean();
	}
}
