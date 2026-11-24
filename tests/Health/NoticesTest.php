<?php
/**
 * @package HestaHub
 */

namespace HestaHub\Tests\Health;

use HestaHub\Api\Client;
use HestaHub\Api\Credentials;
use HestaHub\Health\Escalation;
use HestaHub\Health\Notices;
use HestaHub\Tests\Support\FakeHestaHub;
use WP_UnitTestCase;

/**
 * The notices that are not health issues: setup, minimum version, PHP 7.
 */
final class NoticesTest extends WP_UnitTestCase {

	use FakeHestaHub;

	public function set_up() {
		parent::set_up();
		putenv( 'HESTAHUB_API_KEY' );
		update_option( 'hestahub_tenant', 'kronshof' );
		update_option( 'hestahub_api_key', 'k3y' );
		$this->fake_hestahub();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function test_an_empty_base_path_raises_a_setup_prompt() {
		$this->hold_meta( array( 'apartments', 'clinics' ) );
		update_option( 'hestahub_clinics_base', 'kurse' );

		$notices = $this->notices();

		$this->assertStringContainsString( 'Set a base path for Apartments', $notices );
		$this->assertStringNotContainsString( 'Clinics to show', $notices );
	}

	public function test_the_setup_prompt_is_dismissible() {
		$this->hold_meta( array( 'apartments' ) );

		$this->dismiss();

		$this->assertStringNotContainsString( 'Set a base path', $this->notices() );
	}

	public function test_an_empty_base_path_is_not_a_health_issue() {
		$this->hold_meta( array( 'apartments' ) );

		$status = ( new Escalation( new Client( new Credentials() ), new Credentials() ) )->site_status();

		$this->assertSame( 'good', $status['status'] );
	}

	public function test_no_prompt_once_every_base_path_is_set() {
		$this->hold_meta( array( 'apartments' ) );
		update_option( 'hestahub_apartments_base', 'wohnungen' );

		$this->assertStringNotContainsString( 'Set a base path', $this->notices() );
	}

	public function test_below_the_minimum_plugin_version_it_asks_for_an_update() {
		$this->hold_meta( array( 'apartments' ), '99.0.0' );

		$this->assertStringContainsString( 'Please update the HestaHub plugin.', $this->notices() );
	}

	public function test_at_or_above_the_minimum_version_it_says_nothing() {
		$this->hold_meta( array( 'apartments' ), \HestaHub\Plugin::VERSION );

		$this->assertStringNotContainsString( 'update the HestaHub plugin', $this->notices() );
	}

	public function test_on_php_7_it_says_php_7_and_never_blocks() {
		$notices = $this->notices( '7.4.33' );

		$this->assertStringContainsString( 'PHP 7', $notices );
		$this->assertStringNotContainsString( '8.', $notices, 'It names no supported branch.' );
	}

	public function test_on_php_8_it_says_nothing_about_php() {
		$this->assertStringNotContainsString( 'PHP', $this->notices( '8.0.0' ) );
	}

	public function test_only_administrators_see_them() {
		$this->hold_meta( array( 'apartments' ), '99.0.0' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( '', $this->notices( '7.4.0' ) );
	}

	/**
	 * Caches a meta copy.
	 *
	 * @param string[] $resources The resources it lists.
	 * @param string   $minimum   Its minimum_plugin_version.
	 */
	private function hold_meta( array $resources, string $minimum = '0.0.1' ): void {
		$this->respond(
			200,
			$this->example(
				'meta',
				function ( $body ) use ( $resources, $minimum ) {
					$body['data']['resources']              = $resources;
					$body['data']['minimum_plugin_version'] = $minimum;
					return $body;
				}
			)
		);
		( new Client( new Credentials() ) )->get( 'meta' );
	}

	/**
	 * @param string $php The PHP version the site runs.
	 */
	private function notices( string $php = '8.3.0' ): string {
		remove_all_actions( 'admin_notices' );
		( new Notices( new Client( new Credentials() ), $php ) )->register( hestahub() );
		ob_start();
		do_action( 'admin_notices' );
		return trim( (string) ob_get_clean(), " \t\n\r\0\x0B" );
	}

	private function dismiss(): void {
		( new Notices( new Client( new Credentials() ) ) )->register( hestahub() );
		$_REQUEST['_wpnonce'] = wp_create_nonce( Notices::ACTION );
		$redirect             = function () {
			throw new \RuntimeException( 'redirected' );
		};
		add_filter( 'wp_redirect', $redirect );
		try {
			do_action( 'admin_post_' . Notices::ACTION );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		} finally {
			remove_filter( 'wp_redirect', $redirect );
			unset( $_REQUEST['_wpnonce'] );
		}
	}
}
