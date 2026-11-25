<?php
/**
 * @package HestaHub
 */

namespace HestaHub\Tests\Rendering;

use HestaHub\Tests\Support\FakeHestaHub;
use WP_UnitTestCase;

/**
 * How data reaches a page: the template loader, the shortcode and the
 * template tags. Contract: https://docs.hestahub.com/v1/#templates-and-hooks
 */
final class RendererTest extends WP_UnitTestCase {

	use FakeHestaHub;

	/** @var string|null */
	private $theme_root;

	public function set_up() {
		parent::set_up();
		putenv( 'HESTAHUB_API_KEY' );
		update_option( 'hestahub_tenant', 'kronshof' );
		update_option( 'hestahub_api_key', 'k3y' );
		$this->fake_hestahub();
		$this->use_theme( 'hestahub-child' );
		unset( $GLOBALS['hestahub_test_vars'] );
		wp_dequeue_style( 'hestahub' );
		wp_dequeue_script( 'hestahub' );
	}

	public function tear_down() {
		unset( $GLOBALS['hestahub_test_vars'] );
		parent::tear_down();
	}

	public function test_a_child_theme_override_comes_first() {
		$this->respond( 200, $this->example( 'apartments' ) );

		$this->assertSame( 'child:apartment.php', hestahub_get_rendered( 'apartment', array( 'slug' => 'hoettur' ) ) );
	}

	public function test_then_the_parent_theme() {
		$this->respond( 200, $this->example( 'apartments' ) );

		$this->assertSame( 'parent:rate-table.php', hestahub_get_rendered( 'rate-table', array( 'slug' => 'hoettur' ) ) );
	}

	public function test_then_the_plugins_own_template() {
		$this->respond( 200, $this->example( 'clinics' ) );

		$html = hestahub_get_rendered( 'clinic', array( 'slug' => 'dressurkurs-2027-03-14' ) );

		$this->assertStringContainsString( 'hestahub-clinic', $html );
	}

	public function test_a_listing_gets_the_wire_objects_as_received_and_nothing_else() {
		$this->respond( 200, $this->example( 'apartments' ) );

		$this->expectOutputString( 'parent:apartment-listing.php' );
		hestahub_render( 'apartment-listing' );

		$this->assertSame( array( 'apartments' => $this->data( 'apartments' ) ), $GLOBALS['hestahub_test_vars'] );
	}

	public function test_a_single_gets_its_wire_object_as_received_and_nothing_else() {
		$this->respond( 200, $this->example( 'apartments' ) );

		$this->expectOutputString( 'child:apartment.php' );
		hestahub_render( 'apartment', array( 'slug' => 'hoettur' ) );

		$this->assertSame( array( 'apartment' => $this->data( 'apartments' )[0] ), $GLOBALS['hestahub_test_vars'] );
	}

	public function test_the_rate_table_gets_the_apartment() {
		$this->respond( 200, $this->example( 'apartments' ) );

		$this->expectOutputString( 'parent:rate-table.php' );
		hestahub_render( 'rate-table', array( 'slug' => 'hoettur' ) );

		$this->assertSame( array( 'apartment' => $this->data( 'apartments' )[0] ), $GLOBALS['hestahub_test_vars'] );
	}

	public function test_the_calendar_gets_the_availability_entry_and_the_window() {
		$this->respond( 200, $this->example( 'availability' ) );
		$data = $this->data( 'availability' );

		$this->expectOutputString( 'parent:calendar.php' );
		hestahub_render( 'calendar', array( 'slug' => 'hoettur' ) );

		$this->assertSame(
			array(
				'availability' => $data['apartments'][0],
				'window'       => array(
					'from' => $data['from'],
					'to'   => $data['to'],
				),
			),
			$GLOBALS['hestahub_test_vars']
		);
	}

	public function test_unknown_keys_reach_the_template_unchanged() {
		$this->respond(
			200,
			$this->example(
				'apartments',
				function ( $body ) {
					$body['data'][0]['neu'] = array( 'x' => 1 );
					return $body;
				}
			)
		);

		$this->expectOutputString( 'child:apartment.php' );
		hestahub_render( 'apartment', array( 'slug' => 'hoettur' ) );

		$this->assertSame( array( 'x' => 1 ), $GLOBALS['hestahub_test_vars']['apartment']['neu'] );
	}

	public function test_the_shortcode_renders_the_listing_limited_to_slugs_in_the_order_received() {
		$this->respond( 200, $this->apartments( array( 'a', 'b', 'c' ) ) );

		$html = do_shortcode( '[hestahub show="apartment-listing" slug="c, a"]' );

		$this->assertSame( 'parent:apartment-listing.php', $html );
		$this->assertSame( array( 'a', 'c' ), array_column( $GLOBALS['hestahub_test_vars']['apartments'], 'slug' ) );
	}

	public function test_the_shortcode_renders_one_apartment() {
		$this->respond( 200, $this->example( 'apartments' ) );

		$this->assertSame( 'child:apartment.php', do_shortcode( '[hestahub show="apartment" slug="hoettur"]' ) );
	}

	public function test_without_data_the_shortcode_renders_nothing() {
		$this->respond( 500 );

		$this->assertSame( '', do_shortcode( '[hestahub show="apartment-listing"]' ) );
	}

	public function test_an_unknown_slug_or_kind_renders_nothing() {
		$this->respond( 200, $this->example( 'apartments' ) );

		$this->assertSame( '', do_shortcode( '[hestahub show="apartment" slug="nie-gesehen"]' ) );
		$this->assertSame( '', do_shortcode( '[hestahub show="apartment"]' ), 'A single needs a slug.' );
		$this->assertSame( '', do_shortcode( '[hestahub show="gaestebuch"]' ) );
	}

	public function test_a_listing_limited_to_slugs_not_there_renders_nothing() {
		$this->respond( 200, $this->example( 'apartments' ) );

		$this->assertSame( '', do_shortcode( '[hestahub show="apartment-listing" slug="nie-gesehen"]' ) );
	}

	public function test_an_empty_listing_from_the_api_still_renders() {
		$this->respond( 200, $this->apartments( array() ) );

		$this->assertSame( 'parent:apartment-listing.php', do_shortcode( '[hestahub show="apartment-listing"]' ) );
		$this->assertSame( array( 'apartments' => array() ), $GLOBALS['hestahub_test_vars'] );
	}

	public function test_without_data_hestahub_render_prints_nothing() {
		$this->respond( 500 );

		$this->assertSame( '', hestahub_get_rendered( 'apartment-listing' ) );
		$this->assertFalse( wp_style_is( 'hestahub', 'enqueued' ) );
	}

	public function test_hestahub_get_returns_the_template_variables_and_renders_nothing() {
		$this->respond( 200, $this->example( 'clinics' ) );

		ob_start();
		$vars = hestahub_get( 'clinic', array( 'slug' => 'dressurkurs-2027-03-14' ) );
		$this->assertSame( '', ob_get_clean() );

		$this->assertSame( array( 'clinic' => $this->data( 'clinics' )[0] ), $vars );
		$this->assertFalse( wp_style_is( 'hestahub', 'enqueued' ), 'Nothing rendered, nothing enqueued.' );
	}

	public function test_hestahub_get_without_data_returns_null() {
		$this->respond( 500 );

		$this->assertNull( hestahub_get( 'apartment-listing' ) );
	}

	public function test_rendering_enqueues_the_stylesheet() {
		$this->respond( 200, $this->example( 'apartments' ) );

		$this->expectOutputString( 'parent:apartment-listing.php' );
		hestahub_render( 'apartment-listing' );

		$this->assertTrue( wp_style_is( 'hestahub', 'enqueued' ) );
	}

	public function test_a_page_without_plugin_output_gets_no_stylesheet() {
		$post = self::factory()->post->create( array( 'post_content' => 'Willkommen.' ) );
		$this->go_to( get_permalink( $post ) );

		do_action( 'wp_enqueue_scripts' );

		$this->assertFalse( wp_style_is( 'hestahub', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'hestahub', 'enqueued' ) );
	}

	public function test_a_page_with_the_shortcode_gets_the_stylesheet_in_its_head() {
		$post = self::factory()->post->create( array( 'post_content' => '[hestahub show="apartment-listing"]' ) );
		$this->go_to( get_permalink( $post ) );

		do_action( 'wp_enqueue_scripts' );

		$this->assertTrue( wp_style_is( 'hestahub', 'enqueued' ) );
	}

	public function test_a_page_with_the_shortcode_gets_the_calendar_script_deferred_in_its_footer() {
		$post = self::factory()->post->create( array( 'post_content' => '[hestahub show="calendar" slug="hoettur"]' ) );
		$this->go_to( get_permalink( $post ) );

		do_action( 'wp_enqueue_scripts' );

		$this->assertTrue( wp_script_is( 'hestahub', 'enqueued' ) );
		$script = wp_scripts()->registered['hestahub'];
		$this->assertStringEndsWith( '/assets/hestahub.js', $script->src );
		$this->assertSame( array(), $script->deps, 'No jQuery.' );
		$this->assertSame( 1, $script->extra['group'] ?? null, 'In the footer.' );
		$this->assertSame( 'defer', $script->extra['strategy'] ?? null );
	}

	public function test_the_stylesheet_sets_no_colours() {
		$css = file_get_contents( dirname( __DIR__, 2 ) . '/assets/hestahub.css' );

		$this->assertDoesNotMatchRegularExpression( '/#[0-9a-f]{3,8}\b|rgba?\(|hsla?\(/i', $css );
		$this->assertStringContainsString( '--hestahub-day-booked', $css );
	}

	/**
	 * Switches to one of the fixture themes.
	 *
	 * @param string $stylesheet The theme's folder.
	 */
	private function use_theme( string $stylesheet ): void {
		$root = dirname( __DIR__ ) . '/fixtures/themes';
		register_theme_directory( $root );
		wp_clean_themes_cache();
		switch_theme( $stylesheet );
	}

	/**
	 * An example's data, as decoded.
	 *
	 * @param string $name The example.
	 * @return array
	 */
	private function data( string $name ): array {
		return json_decode( $this->example( $name ), true )['data'];
	}

	/**
	 * The apartments example with one copy of its Apartment per slug.
	 *
	 * @param string[] $slugs The slugs, in order.
	 */
	private function apartments( array $slugs ): string {
		return $this->example(
			'apartments',
			function ( $body ) use ( $slugs ) {
				$one          = $body['data'][0];
				$body['data'] = array();
				foreach ( $slugs as $slug ) {
					$one['slug']    = $slug;
					$body['data'][] = $one;
				}
				return $body;
			}
		);
	}
}

/**
 * What hestahub_render() prints.
 *
 * @param string $what What to render.
 * @param array  $args Its arguments.
 */
function hestahub_get_rendered( string $what, array $args = array() ): string {
	ob_start();
	hestahub_render( $what, $args );
	return (string) ob_get_clean();
}
