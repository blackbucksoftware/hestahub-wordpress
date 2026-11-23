<?php
/**
 * @package HestaHub
 */

namespace HestaHub\Tests\Api;

use HestaHub\Api\Client;
use HestaHub\Api\Credentials;
use HestaHub\Plugin;
use WP_UnitTestCase;

/**
 * Client::get() against mocked HTTP: the one seam where the plugin talks to
 * HestaHub. Contract: https://docs.hestahub.com/v1/
 */
final class ClientTest extends WP_UnitTestCase {

	const KEY = 'hestahub_0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

	/** @var int */
	private $now = 1800000000;

	/** @var array[] Requests the client made: url and args. */
	private $requests = array();

	/** @var array[] Responses still to hand out, oldest first. */
	private $responses = array();

	public function set_up() {
		parent::set_up();
		putenv( 'HESTAHUB_API_KEY' );
		update_option( 'hestahub_api_key', self::KEY );
		update_option( 'hestahub_tenant', 'kronshof' );
		add_filter( 'pre_http_request', array( $this, 'answer' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'answer' ), 10 );
		parent::tear_down();
	}

	public function test_it_requests_the_endpoint_over_https_with_key_and_user_agent() {
		$this->respond( 200, $this->example( 'meta' ) );

		$result = $this->client()->get( 'meta' );

		$this->assertTrue( $result->is_ok() );
		$this->assertSame( 'Kronshof', $result->data()['tenant']['name'] );
		$this->assertCount( 1, $this->requests );
		$request = $this->requests[0];
		$this->assertSame( 'https://kronshof.hestahub.com/api/v1/meta', $request['url'] );
		$this->assertSame( 'GET', $request['args']['method'] );
		$this->assertSame( 'Bearer ' . self::KEY, $request['args']['headers']['Authorization'] );
		$this->assertSame(
			sprintf( 'HestaHub-WP/%s (WordPress %s; PHP %d.%d.%d)', Plugin::VERSION, $GLOBALS['wp_version'], PHP_MAJOR_VERSION, PHP_MINOR_VERSION, PHP_RELEASE_VERSION ),
			$request['args']['user-agent']
		);
		$this->assertSame( 0, $request['args']['redirection'] );
	}

	public function test_within_ttl_it_serves_the_cached_copy_without_a_request() {
		$this->respond( 200, $this->example( 'apartments' ) );
		$this->client()->get( 'apartments' );

		$this->now += 6 * HOUR_IN_SECONDS - 1; // The example's ttl is 6 hours.
		$result = $this->client()->get( 'apartments' );

		$this->assertTrue( $result->is_ok() );
		$this->assertSame( 'hoettur', $result->data()[0]['slug'] );
		$this->assertCount( 1, $this->requests );
	}

	public function test_past_ttl_it_refetches() {
		$this->respond( 200, $this->example( 'apartments' ) );
		$this->respond(
			200,
			$this->example(
				'apartments',
				function ( $body ) {
					$body['data'][0]['slug'] = 'fresh';
					return $body;
				}
			)
		);
		$this->client()->get( 'apartments' );

		$this->now += 6 * HOUR_IN_SECONDS;
		$result = $this->client()->get( 'apartments' );

		$this->assertCount( 2, $this->requests );
		$this->assertSame( 'fresh', $result->data()[0]['slug'] );
	}

	public function test_each_endpoint_has_its_own_copy() {
		$this->respond( 200, $this->example( 'apartments' ) );
		$this->respond( 200, $this->example( 'clinics' ) );

		$apartments = $this->client()->get( 'apartments' );
		$clinics    = $this->client()->get( 'clinics' );

		$this->assertSame( 'https://kronshof.hestahub.com/api/v1/clinics', $this->requests[1]['url'] );
		$this->assertNotSame( $apartments->data(), $clinics->data() );
	}

	/**
	 * Every failure, as the next response, its kind, status, code and message.
	 */
	public function failures() {
		$error = function ( $code, $message ) {
			return wp_json_encode(
				array(
					'error'    => array(
						'code'    => $code,
						'message' => $message,
					),
					'revision' => '2026-11-23',
					'docs_url' => 'https://docs.hestahub.com/v1/',
				)
			);
		};
		return array(
			'401 unauthorized'       => array( 401, $error( 'unauthorized', 'No key.' ), 'verdict', 'unauthorized', 'No key.' ),
			'401 key_revoked'        => array( 401, $error( 'key_revoked', 'Revoked.' ), 'verdict', 'key_revoked', 'Revoked.' ),
			'404 tenant_inactive'    => array( 404, $error( 'tenant_inactive', 'No Tenant.' ), 'verdict', 'tenant_inactive', 'No Tenant.' ),
			'404 module_disabled'    => array( 404, $error( 'module_disabled', 'No Module.' ), 'verdict', 'module_disabled', 'No Module.' ),
			'404 unknown code'       => array( 404, $error( 'something_new', 'New.' ), 'verdict', 'something_new', 'New.' ),
			'410 version_gone'       => array( 410, $error( 'version_gone', 'Retired.' ), 'verdict', 'version_gone', 'Retired.' ),
			'500 server_error'       => array( 500, $error( 'server_error', 'Oops.' ), 'outage', 'server_error', 'Oops.' ),
			'503 without a body'     => array( 503, '', 'outage', '', '' ),
			'403 from a firewall'    => array( 403, '<html>Forbidden</html>', 'outage', '', '' ),
			'429'                    => array( 429, '', 'outage', '', '' ),
			'301 redirect'           => array( 301, '', 'outage', '', '' ),
			'200 not JSON'           => array( 200, '<html>Maintenance</html>', 'outage', '', '' ),
			'200 without envelope'   => array( 200, '[{"slug":"hoettur"}]', 'outage', '', '' ),
			'timeout'                => array( new \WP_Error( 'http_request_failed', 'cURL error 28: Timed out' ), '', 'outage', '', 'cURL error 28: Timed out' ),
		);
	}

	/**
	 * @dataProvider failures
	 */
	public function test_a_failure_with_nothing_cached_returns_the_failure( $status, $body, $kind, $code, $message ) {
		$this->respond( $status, $body );

		$result = $this->client()->get( 'apartments' );

		$this->assertFalse( $result->is_ok() );
		$this->assertSame( array(), $result->data() );
		$failure = $result->failure();
		$this->assertSame( $kind, $failure->kind() );
		$this->assertSame( $status instanceof \WP_Error ? 0 : $status, $failure->status() );
		$this->assertSame( $code, $failure->code() );
		$this->assertSame( $message, $failure->message() );
	}

	/**
	 * @dataProvider failures
	 */
	public function test_a_failed_refetch_keeps_serving_the_cached_copy( $status, $body, $kind ) {
		$this->respond( 200, $this->example( 'apartments' ) );
		$this->client()->get( 'apartments' );
		$this->respond( $status, $body );

		$this->now += 6 * HOUR_IN_SECONDS;
		$result = $this->client()->get( 'apartments' );

		$this->assertTrue( $result->is_ok(), 'Stale beats blank.' );
		$this->assertSame( 'hoettur', $result->data()[0]['slug'] );
		$this->assertTrue( $result->is_stale() );
		$this->assertSame( $kind, $result->failure()->kind() );
	}

	public function test_the_copy_survives_an_object_cache_eviction() {
		$was_external = wp_using_ext_object_cache( true );
		try {
			$this->respond( 200, $this->example( 'apartments' ) );
			$this->client()->get( 'apartments' );
			wp_cache_flush(); // A persistent object cache may evict anything at any time.
			$this->respond( 500 );

			$this->now += 6 * HOUR_IN_SECONDS;
			$result = $this->client()->get( 'apartments' );
		} finally {
			wp_using_ext_object_cache( $was_external );
		}

		$this->assertTrue( $result->is_ok(), 'The contract: keep a body until stale_ttl.' );
		$this->assertTrue( $result->is_stale() );
	}

	public function test_past_stale_ttl_a_failure_returns_the_failure() {
		$this->respond( 200, $this->example( 'apartments' ) );
		$this->client()->get( 'apartments' );
		$this->respond( 500 );

		$this->now += WEEK_IN_SECONDS; // The example's stale_ttl is 7 days.
		$result = $this->client()->get( 'apartments' );

		$this->assertFalse( $result->is_ok() );
		$this->assertSame( 'outage', $result->failure()->kind() );
	}

	public function test_a_fresh_copy_is_neither_stale_nor_failed() {
		$this->respond( 200, $this->example( 'apartments' ) );

		$result = $this->client()->get( 'apartments' );

		$this->assertFalse( $result->is_stale() );
		$this->assertNull( $result->failure() );
	}

	public function test_after_an_outage_it_waits_before_retrying() {
		$this->respond( 500 );
		$first = $this->client()->get( 'apartments' );
		$this->assertSame( MINUTE_IN_SECONDS, $first->retry_after() );

		$this->now += MINUTE_IN_SECONDS - 1;
		$waiting = $this->client()->get( 'apartments' );

		$this->assertCount( 1, $this->requests, 'No request during back-off.' );
		$this->assertSame( 'outage', $waiting->failure()->kind() );
		$this->assertSame( 1, $waiting->retry_after() );

		$this->respond( 500 );
		$this->now += 1;
		$this->client()->get( 'apartments' );
		$this->assertCount( 2, $this->requests );
	}

	public function test_outage_back_off_doubles_up_to_one_hour() {
		$waits = array();
		for ( $i = 0; $i < 9; $i++ ) {
			$this->respond( 503 );
			$result  = $this->client()->get( 'apartments' );
			$waits[] = $result->retry_after();
			$this->now += $result->retry_after();
		}

		$this->assertSame( array( 60, 120, 240, 480, 960, 1920, 3600, 3600, 3600 ), $waits );
	}

	public function test_a_verdict_retries_hourly() {
		$waits = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$this->respond( 401 );
			$result  = $this->client()->get( 'apartments' );
			$waits[] = $result->retry_after();
			$this->now += HOUR_IN_SECONDS - 1;
			$this->client()->get( 'apartments' ); // Still waiting: no request.
			$this->now += 1;
		}

		$this->assertSame( array( 3600, 3600, 3600 ), $waits );
		$this->assertCount( 3, $this->requests );
	}

	public function test_back_off_also_holds_while_serving_stale() {
		$this->respond( 200, $this->example( 'apartments' ) );
		$this->client()->get( 'apartments' );
		$this->now += 6 * HOUR_IN_SECONDS;
		$this->respond( 500 );
		$this->client()->get( 'apartments' );

		$this->now += 30;
		$result = $this->client()->get( 'apartments' );

		$this->assertCount( 2, $this->requests );
		$this->assertTrue( $result->is_stale() );
		$this->assertSame( 'outage', $result->failure()->kind() );
	}

	public function test_a_success_ends_the_back_off() {
		$this->respond( 500 );
		$this->client()->get( 'apartments' );
		$this->now += MINUTE_IN_SECONDS;
		$this->respond( 200, $this->example( 'apartments' ) );
		$this->client()->get( 'apartments' );

		$this->now += 6 * HOUR_IN_SECONDS;
		$this->respond( 500 );
		$result = $this->client()->get( 'apartments' );

		$this->assertSame( MINUTE_IN_SECONDS, $result->retry_after(), 'The count starts over.' );
		$this->assertSame( 0, $this->fresh_retry_after() );
	}

	private function fresh_retry_after() {
		$this->now += MINUTE_IN_SECONDS;
		$this->respond( 200, $this->example( 'apartments' ) );
		return $this->client()->get( 'apartments' )->retry_after();
	}

	public function credentials_missing() {
		return array(
			'no key'           => array( 'hestahub_api_key', '' ),
			'no tenant'        => array( 'hestahub_tenant', '' ),
			'tenant not a host' => array( 'hestahub_tenant', 'evil.example/x' ),
		);
	}

	/**
	 * @dataProvider credentials_missing
	 */
	public function test_without_credentials_it_makes_no_request( $option, $value ) {
		update_option( $option, $value );

		$result = $this->client()->get( 'meta' );

		$this->assertCount( 0, $this->requests );
		$this->assertFalse( $result->is_ok() );
		$this->assertSame( 'not_configured', $result->failure()->kind() );
		$this->assertSame( 0, $result->retry_after() );
	}

	public function test_meta_is_refetched_at_least_every_24_hours() {
		$long_ttl = function ( $body ) {
			$body['cache']['ttl'] = 3 * DAY_IN_SECONDS;
			return $body;
		};
		$this->respond( 200, $this->example( 'meta', $long_ttl ) );
		$this->respond( 200, $this->example( 'meta', $long_ttl ) );
		$this->client()->get( 'meta' );

		$this->now += DAY_IN_SECONDS - 1;
		$this->client()->get( 'meta' );
		$this->assertCount( 1, $this->requests );

		$this->now += 1;
		$this->client()->get( 'meta' );
		$this->assertCount( 2, $this->requests );
	}

	public function examples() {
		return array(
			'meta'         => array( 'meta', null, null ),
			'apartments'   => array( 'apartments', 'EUR', true ),
			'availability' => array( 'availability', null, null ),
			'clinics'      => array( 'clinics', 'EUR', true ),
		);
	}

	/**
	 * @dataProvider examples
	 */
	public function test_it_reads_each_example( $endpoint, $currency, $prices_include_tax ) {
		$example = json_decode( $this->example( $endpoint ), true );
		$this->respond( 200, $this->example( $endpoint ) );

		$result = $this->client()->get( $endpoint );

		$this->assertTrue( $result->is_ok() );
		$this->assertSame( $example['data'], $result->data(), 'Data is passed on exactly as received.' );
		$this->assertSame( '2026-11-23', $result->revision() );
		$this->assertSame( $currency, $result->currency() );
		$this->assertSame( $prices_include_tax, $result->prices_include_tax() );
		$this->assertSame( $this->now, $result->fetched_at() );
	}

	/**
	 * @dataProvider examples
	 */
	public function test_unknown_keys_and_enum_values_are_tolerated( $endpoint ) {
		$changed = json_decode(
			$this->example(
				$endpoint,
				function ( $body ) {
					$body['something_new']           = array( 'nested' => true );
					$body['cache']['something_new']  = 1;
					$body['data']                    = $this->add_unknowns( $body['data'] );
					return $body;
				}
			),
			true
		);
		$this->respond( 200, wp_json_encode( $changed ) );

		$result = $this->client()->get( $endpoint );

		$this->assertTrue( $result->is_ok() );
		$this->assertSame( $changed['data'], $result->data() );
		$this->assertSame( '2026-11-23', $result->revision() );
	}

	public function test_a_missing_envelope_field_degrades_that_field_alone() {
		$this->respond(
			200,
			$this->example(
				'apartments',
				function ( $body ) {
					unset( $body['currency'], $body['revision'] );
					$body['prices_include_tax'] = 'yes';
					return $body;
				}
			)
		);

		$result = $this->client()->get( 'apartments' );

		$this->assertTrue( $result->is_ok() );
		$this->assertNull( $result->currency() );
		$this->assertNull( $result->revision() );
		$this->assertNull( $result->prices_include_tax() );
		$this->assertSame( 'hoettur', $result->data()[0]['slug'] );
	}

	public function test_without_cache_rules_the_body_is_served_but_not_kept() {
		$no_cache = function ( $body ) {
			unset( $body['cache'] );
			return $body;
		};
		$this->respond( 200, $this->example( 'apartments', $no_cache ) );
		$this->respond( 200, $this->example( 'apartments', $no_cache ) );

		$first  = $this->client()->get( 'apartments' );
		$second = $this->client()->get( 'apartments' );

		$this->assertTrue( $first->is_ok() );
		$this->assertTrue( $second->is_ok() );
		$this->assertCount( 2, $this->requests, 'No numbers of its own: nothing to keep it for.' );
	}

	public function test_failing_since_is_when_the_streak_began() {
		$this->respond( 200, $this->example( 'apartments' ) );
		$this->client()->get( 'apartments' );
		$this->assertNull( $this->client()->get( 'apartments' )->failing_since() );

		$this->now += 6 * HOUR_IN_SECONDS;
		$began = $this->now;
		$this->respond( 500 );
		$this->client()->get( 'apartments' );
		$this->now += MINUTE_IN_SECONDS;
		$this->respond( 401 );
		$result = $this->client()->get( 'apartments' );

		$this->assertSame( $began, $result->failing_since() );

		$this->now += HOUR_IN_SECONDS;
		$this->respond( 200, $this->example( 'apartments' ) );
		$this->assertNull( $this->client()->get( 'apartments' )->failing_since() );
	}

	public function test_last_success_outlives_the_cached_copy() {
		$this->assertNull( $this->client_with_failure()->last_success(), 'Never fetched.' );

		$this->now += HOUR_IN_SECONDS;
		$fetched = $this->now;
		$this->respond( 200, $this->example( 'apartments' ) );
		$this->assertSame( $fetched, $this->client()->get( 'apartments' )->last_success() );

		$this->now += 8 * DAY_IN_SECONDS; // Past stale_ttl: the copy is gone.
		$this->respond( 500 );
		$result = $this->client()->get( 'apartments' );

		$this->assertFalse( $result->is_ok() );
		$this->assertSame( $fetched, $result->last_success() );
	}

	public function test_a_copy_from_another_tenant_is_never_served() {
		$this->respond( 200, $this->example( 'apartments' ) );
		$this->client()->get( 'apartments' );

		update_option( 'hestahub_tenant', 'other' );
		$this->respond( 401 );
		$result = $this->client()->get( 'apartments' );

		$this->assertSame( 'https://other.hestahub.com/api/v1/apartments', $this->requests[1]['url'] );
		$this->assertFalse( $result->is_ok() );
		$this->assertNull( $result->last_success() );
	}

	public function test_another_tenants_back_off_does_not_hold() {
		$this->respond( 500 );
		$this->client()->get( 'apartments' );

		update_option( 'hestahub_tenant', 'other' );
		$this->respond( 200, $this->example( 'apartments' ) );
		$result = $this->client()->get( 'apartments' );

		$this->assertCount( 2, $this->requests );
		$this->assertTrue( $result->is_ok() );
	}

	public function test_endpoints_back_off_independently() {
		$this->respond( 500 );
		$this->client()->get( 'apartments' );
		$this->respond( 500 );
		$this->client()->get( 'clinics' );

		$this->now += MINUTE_IN_SECONDS;
		$this->respond( 200, $this->example( 'apartments' ) );
		$this->respond( 200, $this->example( 'clinics' ) );

		$this->assertTrue( $this->client()->get( 'apartments' )->is_ok() );
		$this->assertTrue( $this->client()->get( 'clinics' )->is_ok() );
	}

	private function client_with_failure() {
		$this->respond( 500 );
		return $this->client()->get( 'apartments' );
	}

	/**
	 * Adds an unknown key to every object, and turns every enum-like string
	 * the contract names into an unknown value.
	 *
	 * @param mixed $value Decoded JSON.
	 */
	private function add_unknowns( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = in_array( $key, array( 'status', 'horses', 'signup' ), true ) && is_string( $item )
				? 'something_new'
				: $this->add_unknowns( $item );
		}
		if ( $value && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			$value['something_new'] = 'x';
		}
		return $value;
	}

	/**
	 * pre_http_request callback: records the request, hands out the next response.
	 */
	public function answer( $preempt, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		if ( ! $this->responses ) {
			$this->fail( "Unexpected request to $url" );
		}
		return array_shift( $this->responses );
	}

	/**
	 * Queues a response.
	 *
	 * @param int|\WP_Error $status HTTP status, or a transport error.
	 * @param string        $body   Response body.
	 */
	private function respond( $status, $body = '' ) {
		if ( $status instanceof \WP_Error ) {
			$this->responses[] = $status;
			return;
		}
		$this->responses[] = array(
			'headers'  => array( 'content-type' => 'application/json; charset=utf-8' ),
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
	 * A vendored example body, optionally changed.
	 *
	 * @param string        $name   Example name: meta, apartments, availability, clinics, error.
	 * @param callable|null $change Receives the decoded body, returns it changed.
	 */
	private function example( $name, $change = null ) {
		$body = json_decode( file_get_contents( dirname( __DIR__ ) . "/fixtures/api/$name.example.json" ), true );
		if ( $change ) {
			$body = $change( $body );
		}
		return wp_json_encode( $body );
	}

	private function client() {
		return new Client(
			new Credentials(),
			function () {
				return $this->now;
			}
		);
	}
}
