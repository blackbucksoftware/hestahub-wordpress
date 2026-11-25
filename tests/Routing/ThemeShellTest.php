<?php
/**
 * @package HestaHub
 */

namespace HestaHub\Tests\Routing;

use HestaHub\Tests\Support\FakeHestaHub;
use HestaHub\Tests\Support\VisitsRoutes;
use WP_UnitTestCase;

/**
 * A theme puts an owned route into its own layout by overriding the page
 * shell. Contract: https://docs.hestahub.com/v1/#templates-and-hooks
 */
final class ThemeShellTest extends WP_UnitTestCase {

	use FakeHestaHub;
	use VisitsRoutes;

	/**
	 * Where this test's themes live. A fresh folder per test: get_header()
	 * and get_footer() load their files once per PHP process.
	 *
	 * @var string
	 */
	private $root;

	/** @var int|null The last status sent. */
	private $status;

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

		$this->root = get_temp_dir() . 'hestahub-themes-' . uniqid();
	}

	public function tear_down() {
		global $wp_theme_directories;
		$wp_theme_directories = array_values( array_diff( $wp_theme_directories, array( $this->root ) ) );
		wp_clean_themes_cache();
		$this->remove( $this->root );
		parent::tear_down();
	}

	public function test_a_child_themes_shell_comes_before_its_parents() {
		$this->theme( 'parent', self::shell( 'parent' ) );
		$this->theme( 'child', self::shell( 'child' ), 'parent' );
		$this->use_theme( 'child' );
		$this->respond( 200, $this->example( 'apartments' ) );

		$this->assertStringContainsString( 'shell:child', $this->page( '/wohnungen/' ) );
	}

	public function test_a_child_theme_without_a_shell_gets_its_parents() {
		$this->theme( 'parent', self::shell( 'parent' ) );
		$this->theme( 'child', null, 'parent' );
		$this->use_theme( 'child' );
		$this->respond( 200, $this->example( 'apartments' ) );

		$this->assertStringContainsString( 'shell:parent', $this->page( '/wohnungen/' ) );
	}

	public function test_a_theme_without_a_shell_gets_the_plugins() {
		$this->theme( 'plain', null );
		$this->use_theme( 'plain' );
		$this->respond( 200, $this->example( 'apartments' ) );

		$page = $this->page( '/wohnungen/' );

		$this->assertMatchesRegularExpression( '#<header>H</header>\s*<main class="hestahub-page">\s*<div class="hestahub hestahub-apartment-listing">.*</main>\s*<footer>F</footer>#s', $page );
		$this->assertStringNotContainsString( 'id="secondary"', $page );
	}

	public function test_the_content_renders_once_inside_the_themes_column() {
		$this->theme( 'columns', self::shell( 'columns' ) );
		$this->use_theme( 'columns' );
		$this->respond( 200, $this->example( 'apartments' ) );

		$page = $this->page( '/wohnungen/' );

		$this->assertSame( 200, $this->status );
		$this->assertMatchesRegularExpression( '#<main class="col">\s*<div class="hestahub hestahub-apartment-listing">.*</main><aside id="secondary">S</aside></div><footer>F</footer>#s', $page );
		$this->assertSame( 1, substr_count( $page, 'hestahub-apartment-listing' ) );
		$this->assertStringNotContainsString( 'hestahub-page', $page, 'The theme\'s layout, not the plugin\'s.' );
		$this->assertMatchesRegularExpression( '#<head>.*hestahub\.css.*</head>#s', $page );
	}

	public function test_the_placeholder_renders_in_the_themes_column_with_its_status() {
		delete_option( 'hestahub_api_key' );
		$this->theme( 'columns', self::shell( 'columns' ) );
		$this->use_theme( 'columns' );

		$page = $this->page( '/wohnungen/' );

		$this->assertSame( 200, $this->status );
		$this->assertMatchesRegularExpression( '#<main class="col">.*hestahub-placeholder.*</main><aside id="secondary">#s', $page );
	}

	public function test_the_unavailable_page_renders_in_the_themes_column_with_its_status_and_length() {
		$this->respond( 500 );
		$this->theme( 'columns', self::shell( 'columns' ) );
		$this->use_theme( 'columns' );

		$this->status = null;
		$this->visit( '/wohnungen/' );
		ob_start();
		do_action( 'template_redirect' ); // Pads the 503 body.
		include apply_filters( 'template_include', 'index.php' );
		ob_end_flush();
		$page = (string) ob_get_clean();

		$this->assertSame( 503, $this->status );
		$this->assertMatchesRegularExpression( '#<main class="col">.*hestahub-unavailable.*</main><aside id="secondary">#s', $page );
		$this->assertGreaterThan( 512, strlen( $page ) );
	}

	public function test_a_shell_that_never_prints_the_content_gets_it_before_its_footer() {
		$this->setExpectedIncorrectUsage( 'hestahub_content' );
		$this->theme( 'forgot', '<?php get_header(); ?><div class="row"><main class="col"></main><?php get_sidebar(); ?></div><?php get_footer();' );
		$this->use_theme( 'forgot' );
		$this->respond( 200, $this->example( 'apartments' ) );

		$page = $this->page( '/wohnungen/' );

		$this->assertMatchesRegularExpression( '#<aside id="secondary">S</aside></div><main class="hestahub-page">\s*<div class="hestahub hestahub-apartment-listing">.*</main><footer>F</footer>#s', $page );
		$this->assertSame( 1, substr_count( $page, 'hestahub-apartment-listing' ) );
		$this->assertSame( 1, substr_count( $page, '<header>' ) );
	}

	public function test_a_shell_without_a_footer_gets_no_fallback() {
		$this->theme( 'bare', '<?php get_header(); ?><main class="col"></main>' );
		$this->use_theme( 'bare' );
		$this->respond( 200, $this->example( 'apartments' ) );

		$this->assertStringNotContainsString( 'hestahub-apartment-listing', $this->page( '/wohnungen/' ) );
	}

	public function test_the_fallback_shows_the_unavailable_page_with_its_status() {
		$this->setExpectedIncorrectUsage( 'hestahub_content' );
		$this->respond( 500 );
		$this->theme( 'forgot', '<?php get_header(); ?><main class="col"></main><?php get_footer();' );
		$this->use_theme( 'forgot' );

		$page = $this->page( '/wohnungen/' );

		$this->assertSame( 503, $this->status );
		$this->assertMatchesRegularExpression( '#<main class="hestahub-page">.*hestahub-unavailable.*</main><footer>F</footer>#s', $page );
	}

	public function test_off_an_owned_route_the_tag_prints_nothing() {
		$this->visit( '/' );

		ob_start();
		hestahub_content();

		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * A theme shell that wraps the content in a column beside a sidebar.
	 *
	 * @param string $marker Printed first, to tell shells apart.
	 */
	private static function shell( string $marker ): string {
		return '<?php get_header(); ?>shell:' . $marker . '<div class="row"><main class="col"><?php hestahub_content(); ?></main><?php get_sidebar(); ?></div><?php get_footer();';
	}

	/**
	 * The whole page an owned route serves.
	 *
	 * @param string $path The path.
	 */
	private function page( string $path ): string {
		$this->status = null;
		$this->visit( $path );
		// visit() clears wp_head; put back core's stylesheet printing.
		add_action( 'wp_head', 'wp_enqueue_scripts', 1 );
		add_action( 'wp_head', 'wp_print_styles', 8 );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		$GLOBALS['wp_styles'] = null;
		ob_start();
		include apply_filters( 'template_include', 'index.php' );
		return (string) ob_get_clean();
	}

	/**
	 * Writes a theme with a header, a footer, a sidebar and the given shell.
	 *
	 * @param string      $name     The theme's folder.
	 * @param string|null $shell    hestahub/shell.php, or null for none.
	 * @param string|null $template The parent theme's folder.
	 */
	private function theme( string $name, ?string $shell, ?string $template = null ): void {
		$dir = $this->root . '/' . $name;
		wp_mkdir_p( $dir . '/hestahub' );
		file_put_contents( $dir . '/style.css', "/*\nTheme Name: $name\n" . ( $template ? "Template: $template\n" : '' ) . '*/' );
		file_put_contents( $dir . '/index.php', '' );
		file_put_contents( $dir . '/header.php', '<!doctype html><html><head><?php wp_head(); ?></head><body><header>H</header>' );
		file_put_contents( $dir . '/footer.php', '<footer>F</footer></body></html>' );
		file_put_contents( $dir . '/sidebar.php', '<aside id="secondary">S</aside>' );
		if ( null !== $shell ) {
			file_put_contents( $dir . '/hestahub/shell.php', $shell );
		}
	}

	private function use_theme( string $stylesheet ): void {
		register_theme_directory( $this->root );
		wp_clean_themes_cache();
		switch_theme( $stylesheet );
	}

	private function remove( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $dir );
	}
}
