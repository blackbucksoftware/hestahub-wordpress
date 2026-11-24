<?php
/**
 * @package HestaHub
 */

namespace HestaHub\Tests\Routing;

use HestaHub\Api\Client;
use HestaHub\Api\Credentials;
use HestaHub\Routing\Routes;
use HestaHub\Tests\Support\FakeHestaHub;
use WP_UnitTestCase;

/**
 * The rewrite rules: from the settings, gated by meta, flushed when they change.
 */
final class RulesTest extends WP_UnitTestCase {

	use FakeHestaHub;

	/** @var int How often the rules were regenerated. */
	private $flushes = 0;

	public function set_up() {
		parent::set_up();
		putenv( 'HESTAHUB_API_KEY' );
		update_option( 'hestahub_tenant', 'kronshof' );
		update_option( 'hestahub_api_key', 'k3y' );
		$this->set_permalink_structure( '/%postname%/' );
		$this->fake_hestahub();
		add_filter(
			'rewrite_rules_array',
			function ( $rules ) {
				++$this->flushes;
				return $rules;
			}
		);
	}

	public function test_a_base_path_owns_its_listing_and_its_slugs() {
		update_option( 'hestahub_apartments_base', 'urlaub/wohnungen' );

		$rules = $this->next_request();

		$this->assertSame( 'index.php?hestahub_resource=apartments', $rules['^urlaub/wohnungen/?$'] );
		$this->assertSame( 'index.php?hestahub_resource=apartments&hestahub_slug=$matches[1]', $rules['^urlaub/wohnungen/([^/]+)/?$'] );
	}

	public function test_an_empty_base_path_owns_nothing() {
		update_option( 'hestahub_apartments_base', 'wohnungen' );

		$rules = $this->next_request();

		$this->assertSame( array(), preg_grep( '/hestahub_resource=clinics/', $rules ) );
	}

	public function test_a_resource_meta_does_not_list_owns_nothing() {
		update_option( 'hestahub_apartments_base', 'wohnungen' );
		update_option( 'hestahub_clinics_base', 'kurse' );
		$this->hold_meta( array( 'apartments' ) );

		$rules = $this->next_request();

		$this->assertArrayHasKey( '^wohnungen/?$', $rules );
		$this->assertSame( array(), preg_grep( '/hestahub_resource=clinics/', $rules ) );
	}

	public function test_without_a_meta_copy_the_base_paths_still_resolve() {
		update_option( 'hestahub_clinics_base', 'kurse' );

		$rules = $this->next_request();

		$this->assertArrayHasKey( '^kurse/?$', $rules );
	}

	public function test_it_registers_the_query_vars() {
		$this->next_request();

		$vars = apply_filters( 'query_vars', array() );

		$this->assertContains( 'hestahub_resource', $vars );
		$this->assertContains( 'hestahub_slug', $vars );
		$this->assertContains( 'hestahub_month', $vars );
	}

	public function test_a_changed_base_path_is_flushed_on_the_next_request() {
		update_option( 'hestahub_apartments_base', 'wohnungen' );
		$this->next_request();

		update_option( 'hestahub_apartments_base', 'ferien' );
		$rules = $this->next_request();

		$this->assertArrayHasKey( '^ferien/?$', $rules );
		$this->assertArrayNotHasKey( '^wohnungen/?$', $rules );
	}

	public function test_a_changed_meta_is_flushed_on_the_next_request() {
		update_option( 'hestahub_clinics_base', 'kurse' );
		$this->next_request();

		$this->hold_meta( array( 'apartments' ) );
		$rules = $this->next_request();

		$this->assertArrayNotHasKey( '^kurse/?$', $rules );
	}

	public function test_unchanged_rules_are_not_flushed() {
		update_option( 'hestahub_apartments_base', 'wohnungen' );
		$this->next_request();
		$this->flushes = 0;

		$this->next_request();

		$this->assertSame( 0, $this->flushes );
	}

	public function test_activation_flushes_on_the_next_request() {
		update_option( 'hestahub_apartments_base', 'wohnungen' );
		$this->next_request();
		$this->flushes = 0;

		Routes::activate();
		$this->next_request();

		$this->assertSame( 1, $this->flushes );
	}

	public function test_deactivation_drops_the_rules() {
		update_option( 'hestahub_apartments_base', 'wohnungen' );
		$this->next_request();

		Routes::deactivate();

		$this->assertEmpty( get_option( 'rewrite_rules' ) );
	}

	public function test_network_deactivation_drops_the_rules_on_every_site() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		$site = self::factory()->blog->create();
		switch_to_blog( $site );
		update_option( 'rewrite_rules', array( '^wohnungen/?$' => 'x' ) );
		restore_current_blog();

		Routes::deactivate( true );

		switch_to_blog( $site );
		$this->assertEmpty( get_option( 'rewrite_rules' ) );
		restore_current_blog();
	}

	/**
	 * Runs init and wp_loaded as a fresh request would, and returns the
	 * stored rewrite rules.
	 */
	private function next_request(): array {
		global $wp_rewrite;
		$wp_rewrite->extra_rules_top = array();
		$routes                      = new Routes( $this->client(), dirname( __DIR__, 2 ) );
		$routes->register( hestahub() );
		$routes->add_rules();
		$routes->flush_if_changed();
		return (array) get_option( 'rewrite_rules' );
	}

	/**
	 * Caches a meta copy that lists these resources.
	 *
	 * @param string[] $resources The resources.
	 */
	private function hold_meta( array $resources ): void {
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

	private function client(): Client {
		return new Client( new Credentials() );
	}
}
