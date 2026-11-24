<?php
/**
 * @package HestaHub
 */

namespace HestaHub\Tests\Routing;

use HestaHub\Routing\ShadowNotice;
use WP_UnitTestCase;

/**
 * Taking a base path raises a persistent notice listing what it shadows.
 */
final class ShadowNoticeTest extends WP_UnitTestCase {

	/** @var int[] Page IDs by name. */
	private $pages = array();

	public function set_up() {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		// Only this notice is under test; the plugin's own Notices would add the PHP 7 notice on PHP 7.
		remove_all_actions( 'admin_notices' );
		( new ShadowNotice() )->register( hestahub() );

		$this->pages['base']       = $this->page( 'wohnungen', 0, 'Unsere Wohnungen' );
		$this->pages['child']      = $this->page( 'hoettur', $this->pages['base'], 'Hoettur alt' );
		$this->pages['sibling']    = $this->page( 'anreise', $this->pages['base'], 'Anreise' );
		$this->pages['grandchild'] = $this->page( 'galerie', $this->pages['child'], 'Galerie' );
		$this->pages['elsewhere']  = $this->page( 'impressum', 0, 'Impressum' );
	}

	public function test_taking_a_base_path_lists_the_page_its_children_and_grandchildren_each_linked() {
		update_option( 'hestahub_apartments_base', 'wohnungen' );

		$notice = $this->notices();

		foreach ( array( 'base', 'child', 'sibling', 'grandchild' ) as $page ) {
			$this->assertStringContainsString( esc_url( get_edit_post_link( $this->pages[ $page ], 'raw' ) ), $notice, $page );
		}
		$this->assertStringNotContainsString( 'Impressum', $notice );
	}

	public function test_it_tells_shadowed_children_from_orphaned_grandchildren() {
		update_option( 'hestahub_apartments_base', 'wohnungen' );

		$notice = $this->notices();

		$shadowed = substr( $notice, 0, strpos( $notice, 'still reachable' ) );
		$orphaned = substr( $notice, strpos( $notice, 'still reachable' ) );
		$this->assertStringContainsString( 'Hoettur alt', $shadowed );
		$this->assertStringContainsString( 'Anreise', $shadowed );
		$this->assertStringContainsString( 'Galerie', $orphaned );
		$this->assertStringNotContainsString( 'Galerie', $shadowed );
	}

	public function test_it_lists_the_core_endpoints_shadowed_by_accident() {
		update_option( 'hestahub_apartments_base', 'wohnungen' );

		$notice = $this->notices();

		foreach ( array( 'feed', 'embed', 'trackback' ) as $endpoint ) {
			$this->assertStringContainsString( esc_url( home_url( "/wohnungen/$endpoint/" ) ), $notice );
		}
	}

	public function test_a_draft_child_is_not_listed() {
		self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'entwurf',
				'post_title'  => 'Entwurf',
				'post_status' => 'draft',
				'post_parent' => $this->pages['base'],
			)
		);

		update_option( 'hestahub_apartments_base', 'wohnungen' );

		$this->assertStringNotContainsString( 'Entwurf', $this->notices() );
	}

	public function test_a_nested_base_path_finds_its_page() {
		$parent = $this->page( 'urlaub', 0, 'Urlaub' );
		$this->page( 'ferien', $parent, 'Ferien hier' );

		update_option( 'hestahub_clinics_base', 'urlaub/ferien' );

		$this->assertStringContainsString( 'Ferien hier', $this->notices() );
	}

	public function test_a_base_path_with_nothing_under_it_raises_nothing() {
		update_option( 'hestahub_clinics_base', 'kurse' );

		$this->assertSame( '', $this->notices() );
	}

	public function test_the_notice_persists_until_dismissed() {
		update_option( 'hestahub_apartments_base', 'wohnungen' );
		$this->assertNotSame( '', $this->notices() );
		$this->assertNotSame( '', $this->notices(), 'Still there on the next screen.' );

		$this->dismiss( 'apartments' );

		$this->assertSame( '', $this->notices() );
	}

	public function test_dismissing_needs_manage_options_and_a_nonce() {
		update_option( 'hestahub_apartments_base', 'wohnungen' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		try {
			$this->dismiss( 'apartments' );
			$this->fail( 'An editor dismissed it.' );
		} catch ( \WPDieException $e ) {
			$this->assertNotEmpty( get_option( 'hestahub_shadowed_apartments' ) );
		}
	}

	public function test_only_administrators_see_it() {
		update_option( 'hestahub_apartments_base', 'wohnungen' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( '', $this->notices() );
	}

	public function test_giving_up_the_base_path_drops_the_notice() {
		update_option( 'hestahub_apartments_base', 'wohnungen' );

		update_option( 'hestahub_apartments_base', '' );

		$this->assertSame( '', $this->notices() );
	}

	public function test_moving_the_base_path_replaces_the_notice() {
		update_option( 'hestahub_apartments_base', 'wohnungen' );

		update_option( 'hestahub_apartments_base', 'impressum' );

		$notice = $this->notices();
		$this->assertStringContainsString( 'Impressum', $notice );
		$this->assertStringNotContainsString( 'Unsere Wohnungen', $notice );
	}

	private function page( string $slug, int $parent, string $title ): int {
		return self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => $slug,
				'post_title'  => $title,
				'post_parent' => $parent,
			)
		);
	}

	private function notices(): string {
		ob_start();
		do_action( 'admin_notices' );
		return trim( ob_get_clean(), " \t\n\r\0\x0B" );
	}

	/**
	 * Follows the notice's dismiss link, as far as its redirect.
	 *
	 * @param string $resource apartments or clinics.
	 */
	private function dismiss( string $resource ): void {
		$_GET['resource']     = $resource;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'hestahub_dismiss_shadow_' . $resource );
		$redirect             = function () {
			throw new \RuntimeException( 'redirected' );
		};
		add_filter( 'wp_redirect', $redirect );
		try {
			do_action( 'admin_post_hestahub_dismiss_shadow' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		} finally {
			remove_filter( 'wp_redirect', $redirect );
			unset( $_GET['resource'], $_REQUEST['_wpnonce'] );
		}
	}
}
