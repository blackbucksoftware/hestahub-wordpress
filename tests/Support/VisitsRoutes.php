<?php
/**
 * @package HestaHub
 */

namespace HestaHub\Tests\Support;

use HestaHub\Api\Client;
use HestaHub\Api\Credentials;
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

	/**
	 * @param string $path The path.
	 */
	private function visit( string $path ): void {
		global $wp_rewrite;
		$wp_rewrite->extra_rules_top = array();
		$this->headers               = array();
		// A fresh request: no earlier request's Routes still hooked.
		foreach ( array( 'parse_query', 'posts_pre_query', 'pre_handle_404', 'template_redirect', 'template_include', 'wp_head', 'document_title_parts', 'wpseo_canonical', 'wpseo_title' ) as $hook ) {
			remove_all_filters( $hook );
		}
		$routes = new Routes(
			$this->client(),
			dirname( __DIR__, 2 ),
			function ( $header ) {
				$this->headers[] = $header;
			}
		);
		$routes->register( hestahub() );
		$routes->add_rules();
		$routes->flush_if_changed();
		$this->go_to( home_url( $path ) );
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
