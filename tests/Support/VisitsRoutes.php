<?php
/**
 * @package HestaHub
 */

namespace HestaHub\Tests\Support;

use HestaHub\Api\Client;
use HestaHub\Api\Credentials;
use HestaHub\Rendering\Renderer;
use HestaHub\Routing\Routes;

/**
 * Requests a path as a fresh request would, through a Routes on a clock the
 * test controls. Needs FakeHestaHub.
 */
trait VisitsRoutes {

	/** @var int The time the plugin sees. */
	private $now = 1800000000;

	/** @var string[] Headers sent besides the status. */
	private $headers = array();

	/** @var Routes|null The last request's Routes. */
	private $routes;

	/**
	 * @param string $path The path.
	 */
	private function visit( string $path ): void {
		global $wp_rewrite;
		$wp_rewrite->extra_rules_top = array();
		$this->headers               = array();
		// A fresh request: no earlier request's Routes still hooked.
		foreach ( array( 'parse_query', 'posts_pre_query', 'pre_handle_404', 'template_redirect', 'template_include', 'hestahub_print_content', 'get_footer', 'wp_enqueue_scripts', 'wp_head', 'document_title_parts', 'wpseo_canonical', 'wpseo_title' ) as $hook ) {
			remove_all_filters( $hook );
		}
		$client = $this->client();
		$routes = new Routes(
			$client,
			new Renderer( $client, dirname( __DIR__, 2 ) ),
			dirname( __DIR__, 2 ),
			function ( $header ) {
				$this->headers[] = $header;
			}
		);
		$routes->register( hestahub() );
		$routes->add_rules();
		$routes->flush_if_changed();
		$this->routes = $routes;
		$this->go_to( home_url( $path ) );
	}

	/**
	 * What the page shell prints between the theme's header and footer.
	 */
	private function content(): string {
		ob_start();
		$this->routes->render_content();
		return (string) ob_get_clean();
	}

	private function client(): Client {
		return new Client(
			new Credentials(),
			function () {
				return $this->now;
			}
		);
	}
}
