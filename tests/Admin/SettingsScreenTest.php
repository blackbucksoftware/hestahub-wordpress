<?php
/**
 * @package HestaHub
 */

namespace HestaHub\Tests\Admin;

use HestaHub\Admin\Settings;
use HestaHub\Api\Client;
use HestaHub\Api\Credentials;
use HestaHub\Tests\Support\FakeHestaHub;
use WP_UnitTestCase;
use WPDieException;

/**
 * The settings screen: what it shows, and its two actions.
 */
final class SettingsScreenTest extends WP_UnitTestCase {

	use FakeHestaHub;

	const KEY = '0a1b2c3d4e5f6a7b8c9d';

	public static function wpSetUpBeforeClass() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';
	}

	public function set_up() {
		parent::set_up();
		putenv( 'HESTAHUB_API_KEY' );
		$_POST = array();
		$this->set_permalink_structure( '/%postname%/' );
		update_option( 'hestahub_tenant', 'kronshof' );
		update_option( 'hestahub_api_key', self::KEY );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->fake_hestahub();
	}

	public function tear_down() {
		putenv( 'HESTAHUB_API_KEY' );
		$_POST    = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	public function test_a_stored_key_is_shown_masked_to_its_last_four() {
		$this->respond_all();

		$page = $this->render();

		$this->assertStringContainsString( '••••8c9d', $page );
		$this->assertStringNotContainsString( self::KEY, $page );
		$this->assertStringContainsString( 'name="hestahub_api_key"', $page );
	}

	public function test_a_key_from_the_environment_is_read_only_and_says_so() {
		putenv( 'HESTAHUB_API_KEY=fffffffffff1234' );
		$this->respond_all();

		$page = $this->render();

		$this->assertStringContainsString( '••••1234', $page );
		$this->assertStringContainsString( 'environment variable', $page );
		$this->assertStringNotContainsString( 'name="hestahub_api_key"', $page, 'Nothing to submit.' );
	}

	public function test_a_base_path_is_offered_only_for_a_resource_meta_lists() {
		$this->respond( 200, $this->only_apartments() );

		$page = $this->render();

		$this->assertStringContainsString( 'name="hestahub_apartments_base"', $page );
		$this->assertStringNotContainsString( 'name="hestahub_clinics_base"', $page );
	}

	public function test_without_meta_no_base_path_is_offered() {
		$this->respond( 500 );

		$page = $this->render();

		$this->assertStringNotContainsString( 'name="hestahub_apartments_base"', $page );
		$this->assertStringNotContainsString( 'name="hestahub_clinics_base"', $page );
	}

	public function test_while_permalinks_are_plain_the_base_paths_refuse_input_and_say_why() {
		$this->set_permalink_structure( '' );
		$this->respond_all();

		$page = $this->render();

		$this->assertMatchesRegularExpression( '/name="hestahub_apartments_base"[^>]*readonly/', $page );
		$this->assertStringContainsString( 'options-permalink.php', $page );
	}

	public function test_it_lists_the_slugs_the_plugin_holds() {
		$this->respond_all();

		$page = $this->render();

		$this->assertStringContainsString( '<code>hoettur</code>', $page );
		$this->assertStringContainsString( '<code>dressurkurs-2027-03-14</code>', $page );
	}

	public function test_held_slugs_show_even_while_meta_fails() {
		$client = $this->client();
		$this->respond( 200, $this->example( 'apartments' ) );
		$client->get( 'apartments' );
		$this->respond( 500 ); // meta, for the screen.

		$page = $this->render_with( $client );

		$this->assertStringContainsString( '<code>hoettur</code>', $page );
	}

	public function test_the_screen_never_fetches_apartments_or_clinics() {
		$this->respond( 200, $this->example( 'meta' ) );

		$this->render();

		$this->assertCount( 1, $this->requests, 'Only meta, to know which base paths to offer.' );
	}

	public function test_test_connection_shows_tenant_revision_minimum_version_and_key() {
		$this->respond_all();
		$this->respond( 200, $this->example( 'meta' ) ); // The live call.
		$this->post( 'test' );

		$page = $this->render();

		$this->assertStringContainsString( 'Kronshof', $page );
		$this->assertStringContainsString( '2026-11-23', $page );
		$this->assertStringContainsString( '1.0.0', $page );
		$this->assertStringContainsString( '••••8c9d', $page );
	}

	public function test_test_connection_reads_the_revision_from_the_envelope() {
		$this->respond( 200, $this->example( 'meta', function ( $body ) {
			$body['revision']         = '2027-01-01';
			$body['data']['revision'] = '2026-11-23';
			return $body;
		} ) ); // The live call.
		$this->post( 'test' );
		$this->respond( 200, $this->example( 'meta' ) ); // meta, for the screen.

		$this->assertStringContainsString( '2027-01-01', $this->render() );
	}

	public function test_test_connection_shows_status_and_message_without_a_code() {
		$this->respond( 502, 'Bad Gateway' ); // The live call.
		$this->post( 'test' );
		$this->respond( 502, 'Bad Gateway' ); // meta, for the screen.

		$page = $this->render();

		$this->assertStringContainsString( '502', $page );
	}

	public function test_test_connection_shows_a_failures_code_and_message() {
		$this->respond( 401, $this->example( 'error' ) ); // The live call.
		$this->post( 'test' );
		$this->respond( 401, $this->example( 'error' ) ); // meta, for the screen.

		$page = $this->render();

		$this->assertStringContainsString( '<code>key_revoked</code> The API key was revoked.', $page );
	}

	public function test_test_connection_bypasses_and_keeps_the_cache() {
		$client = $this->client();
		$this->respond( 200, $this->example( 'meta' ) );
		$client->get( 'meta' );
		$this->respond( 200, $this->example( 'meta', function ( $body ) {
			$body['data']['tenant']['name'] = 'Live';
			return $body;
		} ) );

		$this->post( 'test', $client );

		$this->assertCount( 2, $this->requests, 'It asked HestaHub.' );
		$this->assertSame( 'Kronshof', $client->get( 'meta' )->data()['tenant']['name'], 'The cached copy is untouched.' );
	}

	public function test_refresh_now_discards_the_cached_copies() {
		$client = $this->client();
		$this->respond( 200, $this->example( 'apartments' ) );
		$client->get( 'apartments' );

		$this->post( 'refresh', $client );
		$this->respond( 200, $this->example( 'apartments' ) );
		$client->get( 'apartments' );

		$this->assertCount( 2, $this->requests, 'The next read fetched.' );
	}

	public function test_the_actions_need_manage_options() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->expectException( WPDieException::class );
		$this->post( 'refresh' );
	}

	public function test_the_actions_need_a_valid_nonce() {
		$_POST['hestahub_action'] = 'refresh';
		$_REQUEST['_wpnonce']     = 'forged';

		$this->expectException( WPDieException::class );
		$this->settings()->handle_action();
	}

	/**
	 * Caches apartments and clinics as a visitor's page view would, and
	 * queues meta for the screen.
	 */
	private function respond_all(): void {
		$client = $this->client();
		$this->respond( 200, $this->example( 'apartments' ) );
		$this->respond( 200, $this->example( 'clinics' ) );
		$client->get( 'apartments' );
		$client->get( 'clinics' );
		$this->respond( 200, $this->example( 'meta' ) );
	}

	private function only_apartments(): string {
		return $this->example(
			'meta',
			function ( $body ) {
				$body['data']['resources'] = array( 'apartments' );
				return $body;
			}
		);
	}

	/**
	 * Posts an action to the page, with a valid nonce.
	 *
	 * @param string      $action refresh or test.
	 * @param Client|null $client The client the page uses.
	 */
	private function post( string $action, $client = null ): void {
		$_POST['hestahub_action'] = $action;
		$_REQUEST['_wpnonce']     = wp_create_nonce( 'hestahub_' . $action );
		$this->settings = $this->settings( $client );
		$this->settings->handle_action();
	}

	/** @var Settings|null The page an action was posted to. */
	private $settings;

	private function render_with( Client $client ): string {
		$this->settings = $this->settings( $client );
		return $this->render();
	}

	private function render(): string {
		$settings = $this->settings ? $this->settings : $this->settings();
		ob_start();
		$settings->render();
		return ob_get_clean();
	}

	private function settings( $client = null ): Settings {
		return new Settings( $client ? $client : $this->client(), new Credentials() );
	}

	private function client(): Client {
		return new Client( new Credentials() );
	}
}
