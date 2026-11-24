<?php
/**
 * @package HestaHub
 */

namespace HestaHub\Tests\Routing;

use HestaHub\Api\Client;
use HestaHub\Api\Credentials;
use HestaHub\Rendering\Renderer;
use HestaHub\Routing\Routes;
use HestaHub\Tests\Support\FakeHestaHub;
use WP_UnitTestCase;

/**
 * What an owned URL answers, and with which template.
 */
final class RequestTest extends WP_UnitTestCase {

	use FakeHestaHub;

	/** @var int|null The last status sent. */
	private $status;

	/** @var Routes */
	private $routes;

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
				$this->status = $code;
				return $header;
			},
			10,
			2
		);

		global $wp_rewrite;
		$wp_rewrite->extra_rules_top = array();
		$client       = new Client( new Credentials() );
		$this->routes = new Routes( $client, new Renderer( $client, dirname( __DIR__, 2 ) ), dirname( __DIR__, 2 ) );
		$this->routes->register( hestahub() );
		$this->routes->add_rules();
		$this->routes->flush_if_changed();
	}

	public function test_the_listing_answers_200_inside_the_page_shell() {
		$this->respond( 200, $this->example( 'apartments' ) );

		$this->go_to( home_url( '/wohnungen/' ) );

		$this->assertFalse( is_404() );
		$this->assertFalse( is_home(), 'Not the blog.' );
		$this->assertSame( 200, $this->status );
		$this->assertStringEndsWith( '/templates/shell.php', apply_filters( 'template_include', 'index.php' ) );
		$this->assertStringContainsString( 'hestahub-apartment-listing', $this->content() );
	}

	public function test_a_slug_in_the_current_data_answers_200_with_the_apartment_template() {
		$this->respond( 200, $this->example( 'apartments' ) );
		$this->respond( 200, $this->example( 'availability' ) ); // The page's calendar.

		$this->go_to( home_url( '/wohnungen/hoettur/' ) );

		$this->assertFalse( is_404() );
		$this->assertSame( 200, $this->status );
		$this->assertStringEndsWith( '/templates/shell.php', apply_filters( 'template_include', 'index.php' ) );
		$this->assertStringContainsString( 'hestahub-apartment"', $this->content() );
	}

	public function test_a_slug_never_resolved_answers_404() {
		$this->respond( 200, $this->example( 'apartments' ) );

		$this->go_to( home_url( '/wohnungen/nie-gesehen/' ) );

		$this->assertTrue( is_404() );
		$this->assertSame( 404, $this->status );
		$this->assertSame( 'index.php', apply_filters( 'template_include', 'index.php' ), 'The theme\'s 404 template.' );
	}

	public function test_a_slug_gone_from_a_successful_response_answers_404_never_410() {
		$client = new Client( new Credentials() );
		$this->respond( 200, $this->example( 'apartments' ) );
		$client->get( 'apartments' );
		$client->flush();
		$this->respond(
			200,
			$this->example(
				'apartments',
				function ( $body ) {
					$body['data'][0]['slug'] = 'neu';
					return $body;
				}
			)
		);

		$this->go_to( home_url( '/wohnungen/hoettur/' ) );

		$this->assertTrue( is_404() );
		$this->assertSame( 404, $this->status );
	}

	public function test_a_page_two_levels_under_the_base_stays_reachable() {
		// The contract: grandchildren survive, orphaned under a base that no longer resolves.
		$base  = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'wohnungen' ) );
		$child = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'alt', 'post_parent' => $base ) );
		$grand = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'galerie', 'post_parent' => $child ) );

		$this->go_to( home_url( '/wohnungen/alt/galerie/' ) );

		$this->assertSame( $grand, get_queried_object_id() );
		$this->assertCount( 0, $this->requests );
	}

	public function test_a_base_path_not_set_is_not_owned() {
		$this->go_to( home_url( '/kurse/' ) );

		$this->assertNotSame( 'clinics', get_query_var( 'hestahub_resource' ) );
		$this->assertCount( 0, $this->requests );
	}

	public function test_the_listing_resolves_whether_or_not_the_api_answers() {
		$this->respond( 500 );

		$this->go_to( home_url( '/wohnungen/' ) );

		$this->assertFalse( is_404(), 'Routes come from configuration, never from data.' );
		$this->assertSame( 'apartments', get_query_var( 'hestahub_resource' ) );
	}

	/**
	 * What the page shell prints between the theme's header and footer.
	 */
	private function content(): string {
		ob_start();
		$this->routes->render_content();
		return (string) ob_get_clean();
	}
}
