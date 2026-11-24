<?php
/**
 * @package HestaHub
 */

namespace HestaHub\Tests\Health;

use HestaHub\Api\Client;
use HestaHub\Api\Credentials;
use HestaHub\Health\Escalation;
use HestaHub\Tests\Support\FakeHestaHub;
use WP_UnitTestCase;

/**
 * Who is told what, and when, as HestaHub reads fail. Contract:
 * https://docs.hestahub.com/v1/#telling-someone
 */
final class EscalationTest extends WP_UnitTestCase {

	use FakeHestaHub;

	/** @var int */
	private $now = 1800000000;

	/** @var array[] Emails sent: to, subject, message. */
	private $mails = array();

	public function set_up() {
		parent::set_up();
		putenv( 'HESTAHUB_API_KEY' );
		update_option( 'hestahub_tenant', 'kronshof' );
		update_option( 'hestahub_api_key', 'k3y' );
		update_option( 'admin_email', 'webmaster@example.org' );
		$this->fake_hestahub();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter(
			'pre_wp_mail',
			function ( $sent, $mail ) {
				$this->mails[] = $mail;
				return true;
			},
			10,
			2
		);
		( $this->escalation() )->register( hestahub() );
	}

	public function test_a_verdict_is_told_at_once_in_a_notice_site_health_and_one_email() {
		$this->fetched_once();
		$this->break_down( 'apartments', 401, 'unauthorized' );

		$this->assertStringContainsString( 'HestaHub does not accept the API key.', $this->notices() );
		$this->assertSame( 'critical', $this->escalation()->site_status()['status'] );
		$this->escalate();
		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'webmaster@example.org', $this->mails[0]['to'] );
		$this->assertStringContainsString( 'check the API key', $this->mails[0]['message'] );
	}

	public function test_an_email_is_never_repeated_for_the_same_run_of_failures() {
		$this->fetched_once();
		$this->break_down( 'apartments', 401, 'unauthorized' );

		$this->escalate();
		$this->now += HOUR_IN_SECONDS;
		$this->break_down( 'apartments', 401, 'unauthorized' ); // Retried hourly, still failing.
		$this->escalate();

		$this->assertCount( 1, $this->mails );
	}

	public function test_a_new_run_of_failures_is_emailed_again() {
		$this->fetched_once();
		$this->break_down( 'apartments', 401, 'unauthorized' );
		$this->escalate();

		$this->now += HOUR_IN_SECONDS;
		$this->succeed( 'apartments' );
		$this->escalate();
		$this->now += HOUR_IN_SECONDS;
		$this->break_down( 'apartments', 401, 'unauthorized' );
		$this->escalate();

		$this->assertCount( 2, $this->mails );
	}

	public function test_an_outage_raises_a_notice_after_24_continuous_hours() {
		$this->fetched_once();
		$this->break_down( 'apartments', 503, '' );

		$this->now += DAY_IN_SECONDS - 1;
		$this->assertSame( '', $this->notices() );
		$this->assertSame( 'good', $this->escalation()->site_status()['status'] );

		$this->now += 1;
		$this->assertStringContainsString( 'has not answered successfully since', $this->notices() );
		$this->assertStringContainsString( 'contact HestaHub', $this->notices() );
	}

	public function test_an_outage_is_emailed_once_the_fifth_day_begins() {
		$this->fetched_once();
		$this->break_down( 'apartments', 503, '' );

		$this->now += 4 * DAY_IN_SECONDS - 1;
		$this->escalate();
		$this->assertCount( 0, $this->mails );

		$this->now += 1;
		$this->escalate();
		$this->assertCount( 1, $this->mails );
	}

	public function test_a_plugin_that_never_fetched_successfully_sends_no_email() {
		$this->break_down( 'meta', 401, 'unauthorized' );

		$this->escalate();

		$this->assertCount( 0, $this->mails );
		$this->assertStringContainsString( 'does not accept the API key', $this->notices(), 'The notice still shows.' );
	}

	/**
	 * @dataProvider audiences
	 */
	public function test_each_code_names_its_diagnosis_and_who_can_fix_it( $endpoint, $status, $code, $diagnosis, $remedy ) {
		$this->fetched_once();
		$this->break_down( $endpoint, $status, $code, 'Raw developer message.' );

		$notices = $this->notices();

		$this->assertStringContainsString( $diagnosis, $notices );
		$this->assertStringContainsString( $remedy, $notices );
	}

	public function audiences() {
		return array(
			'unauthorized'    => array( 'apartments', 401, 'unauthorized', 'does not accept the API key', 'You can fix this: check the API key' ),
			'key_revoked'     => array( 'apartments', 401, 'key_revoked', 'has been revoked', 'You can fix this: create a new API key' ),
			'tenant_inactive' => array( 'meta', 404, 'tenant_inactive', 'no active Tenant at kronshof.hestahub.com', 'Whoever administers kronshof.hestahub.com can fix this, not the webmaster.' ),
			'module_disabled' => array( 'clinics', 404, 'module_disabled', 'kronshof.hestahub.com does not have the Clinics module', 'Whoever administers kronshof.hestahub.com can fix this, not the webmaster.' ),
			'version_gone'    => array( 'meta', 410, 'version_gone', 'retired the API version', 'Update the HestaHub plugin.' ),
			'an unknown code' => array( 'apartments', 404, 'mystery', 'HTTP status 404, code mystery: Raw developer message.', 'Check the connection' ),
		);
	}

	public function test_the_same_diagnosis_on_several_endpoints_is_one_notice() {
		$this->fetched_once();
		$this->break_down( 'apartments', 401, 'unauthorized' );
		$this->break_down( 'clinics', 401, 'unauthorized' );

		$this->assertSame( 1, substr_count( $this->notices(), 'does not accept the API key' ) );
	}

	public function test_one_email_tells_every_problem_newly_due() {
		$this->fetched_once();
		$this->break_down( 'apartments', 401, 'unauthorized' );
		$this->break_down( 'clinics', 404, 'module_disabled' );

		$this->escalate();

		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'does not accept the API key', $this->mails[0]['message'] );
		$this->assertStringContainsString( 'Clinics module', $this->mails[0]['message'] );
	}

	public function test_an_endpoint_meta_does_not_list_raises_nothing() {
		$this->fetched_once( array( 'apartments' ) );
		$this->break_down( 'clinics', 404, 'module_disabled' ); // A stray shortcode, say.

		$this->escalate();

		$this->assertSame( '', $this->notices() );
		$this->assertCount( 0, $this->mails );
	}

	public function test_an_apartment_gone_from_a_successful_response_raises_nothing() {
		$this->fetched_once();
		$this->respond( 200, $this->example( 'apartments' ) );
		$this->client()->get( 'apartments' );
		$this->now += DAY_IN_SECONDS;
		$this->respond(
			200,
			$this->example(
				'apartments',
				function ( $body ) {
					$body['data'] = array();
					return $body;
				}
			)
		);
		$this->client()->get( 'apartments' );

		$this->escalate();

		$this->assertSame( '', $this->notices() );
		$this->assertCount( 0, $this->mails );
	}

	public function test_only_administrators_see_the_notices() {
		$this->fetched_once();
		$this->break_down( 'apartments', 401, 'unauthorized' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( '', $this->notices() );
	}

	public function test_the_check_runs_hourly_by_wp_cron() {
		$this->assertSame( 'hourly', wp_get_schedule( Escalation::HOOK ) );
	}

	/**
	 * Meta fetched successfully, listing these resources.
	 *
	 * @param string[] $resources The resources.
	 */
	private function fetched_once( array $resources = array( 'apartments', 'clinics' ) ): void {
		$this->respond(
			200,
			$this->example(
				'meta',
				function ( $body ) use ( $resources ) {
					$body['data']['resources'] = $resources;
					return $body;
				}
			)
		);
		$this->client()->get( 'meta' );
	}

	/**
	 * An endpoint fails now.
	 *
	 * @param string $endpoint The endpoint.
	 * @param int    $status   The HTTP status.
	 * @param string $code     The error code, or '' for no error body.
	 * @param string $message  The error message.
	 */
	private function break_down( string $endpoint, int $status, string $code, string $message = 'Something.' ): void {
		$this->respond(
			$status,
			'' === $code ? '' : wp_json_encode(
				array(
					'error'    => array(
						'code'    => $code,
						'message' => $message,
					),
					'revision' => '2026-11-23',
					'docs_url' => 'https://docs.hestahub.com/v1/',
				)
			)
		);
		\HestaHub\Api\EndpointState::load( $endpoint, 'kronshof' )->retry_now(); // Past the back-off.
		delete_option( 'hestahub_cache_' . $endpoint ); // No fresh copy to serve instead.
		$this->client()->get( $endpoint );
	}

	/**
	 * @param string $endpoint The endpoint.
	 */
	private function succeed( string $endpoint ): void {
		$this->respond( 200, $this->example( $endpoint ) );
		\HestaHub\Api\EndpointState::load( $endpoint, 'kronshof' )->retry_now();
		$this->client()->get( $endpoint );
	}

	private function escalate(): void {
		do_action( Escalation::HOOK );
	}

	/**
	 * The escalation's notices, without the plugin's other ones.
	 */
	private function notices(): string {
		remove_all_actions( 'admin_notices' );
		add_action( 'admin_notices', array( $this->escalation(), 'render' ) );
		ob_start();
		do_action( 'admin_notices' );
		return trim( (string) ob_get_clean(), " \t\n\r\0\x0B" );
	}

	private function client(): Client {
		return new Client( new Credentials(), array( $this, 'now' ) );
	}

	private function escalation(): Escalation {
		return new Escalation( $this->client(), new Credentials(), array( $this, 'now' ) );
	}

	/**
	 * The clock the plugin sees.
	 */
	public function now(): int {
		return $this->now;
	}
}
