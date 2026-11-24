<?php
/**
 * @package HestaHub
 */

namespace HestaHub\Tests\Routing;

use HestaHub\Tests\Support\FakeHestaHub;
use HestaHub\Tests\Support\VisitsRoutes;
use WP_UnitTestCase;

/**
 * What an owned route puts in the page head: its canonical link and its
 * title, nothing else. Contract: https://docs.hestahub.com/v1/#routes
 */
final class HeadTest extends WP_UnitTestCase {

	use FakeHestaHub;
	use VisitsRoutes;

	public function set_up() {
		parent::set_up();
		putenv( 'HESTAHUB_API_KEY' );
		update_option( 'hestahub_tenant', 'kronshof' );
		update_option( 'hestahub_api_key', 'k3y' );
		update_option( 'hestahub_apartments_base', 'urlaub/wohnungen' );
		update_option( 'blogname', 'Kronshof' );
		$this->set_permalink_structure( '/%postname%/' );
		$this->fake_hestahub();
	}

	public function test_the_listing_has_its_own_canonical() {
		$this->respond( 200, $this->example( 'apartments' ) );
		$this->visit( '/urlaub/wohnungen/' );

		$this->assertSame( array( home_url( '/urlaub/wohnungen/' ) ), $this->canonicals() );
	}

	public function test_an_apartment_has_its_own_canonical() {
		$this->respond( 200, $this->example( 'apartments' ) );
		$this->visit( '/urlaub/wohnungen/hoettur/' );

		$this->assertSame( array( home_url( '/urlaub/wohnungen/hoettur/' ) ), $this->canonicals() );
	}

	public function test_the_placeholder_has_its_canonical_too() {
		delete_option( 'hestahub_api_key' );
		$this->visit( '/urlaub/wohnungen/' );

		$this->assertSame( array( home_url( '/urlaub/wohnungen/' ) ), $this->canonicals() );
	}

	public function test_a_404_has_no_canonical_of_ours() {
		$this->respond( 200, $this->example( 'apartments' ) );
		$this->visit( '/urlaub/wohnungen/nie-gesehen/' );

		$this->assertSame( array(), $this->canonicals() );
	}

	public function test_an_apartment_is_titled_by_its_name() {
		$this->respond( 200, $this->example( 'apartments' ) );
		$this->visit( '/urlaub/wohnungen/hoettur/' );

		$this->assertSame( 'Höttur &#8211; Kronshof', wp_get_document_title() );
	}

	public function test_the_listing_is_titled_by_its_resource() {
		$this->respond( 200, $this->example( 'apartments' ) );
		$this->visit( '/urlaub/wohnungen/' );

		$this->assertSame( 'Apartments &#8211; Kronshof', wp_get_document_title() );
	}

	public function test_nothing_else_reaches_the_head() {
		$this->respond( 200, $this->example( 'apartments' ) );
		$this->visit( '/urlaub/wohnungen/hoettur/' );

		$head = $this->head();

		$this->assertStringNotContainsString( 'og:', $head );
		$this->assertStringNotContainsString( 'application/ld+json', $head );
		$this->assertStringNotContainsString( 'name="description"', $head );
	}

	public function test_yoasts_home_page_canonical_gives_way_to_ours() {
		$this->respond( 200, $this->example( 'apartments' ) );
		$this->visit( '/urlaub/wohnungen/hoettur/' );

		$this->assertFalse( apply_filters( 'wpseo_canonical', home_url( '/' ) ), 'One canonical: ours.' );
		$this->assertSame( array( home_url( '/urlaub/wohnungen/hoettur/' ) ), $this->canonicals() );
	}

	public function test_yoasts_home_page_title_gives_way_to_ours() {
		$this->respond( 200, $this->example( 'apartments' ) );
		$this->visit( '/urlaub/wohnungen/hoettur/' );

		$this->assertSame( 'Höttur - Kronshof', apply_filters( 'wpseo_title', 'Kronshof - Ein Hof' ) );
	}

	public function test_off_an_owned_route_yoast_and_core_are_left_alone() {
		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'impressum', 'post_title' => 'Impressum' ) );
		$this->visit( '/impressum/' );

		$this->assertSame( $page, get_queried_object_id() );
		$this->assertSame( home_url( '/' ), apply_filters( 'wpseo_canonical', home_url( '/' ) ) );
		$this->assertSame( 'Kronshof - Ein Hof', apply_filters( 'wpseo_title', 'Kronshof - Ein Hof' ) );
		$this->assertSame( 'Impressum &#8211; Kronshof', wp_get_document_title() );
	}

	private function head(): string {
		ob_start();
		do_action( 'wp_head' );
		return (string) ob_get_clean();
	}

	/**
	 * The canonical URLs the head carries.
	 *
	 * @return string[]
	 */
	private function canonicals(): array {
		preg_match_all( '#<link rel=["\']canonical["\'] href=["\']([^"\']+)["\']#', $this->head(), $matches );
		return $matches[1];
	}
}
