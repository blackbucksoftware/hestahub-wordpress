<?php
/**
 * @package HestaHub
 */

namespace HestaHub\Api;

use HestaHub\Feature;
use HestaHub\Plugin;

/**
 * Checks meta twice a day by WP-Cron, so it is checked at least every 24
 * hours even when no page reads it. WP-Cron itself runs on page loads, or on
 * a real cron where the host sets one up. The Client fetches only when its
 * copy is due.
 */
final class MetaCheck implements Feature {

	const HOOK = 'hestahub_check_meta';

	/** @var Client */
	private $client;

	/**
	 * @param Client $client The API client.
	 */
	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * @param Plugin $plugin The plugin.
	 */
	public function register( Plugin $plugin ): void {
		add_action( self::HOOK, array( $this, 'check' ) );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), 'twicedaily', self::HOOK );
		}
	}

	/**
	 * Hooked to the cron event; not for direct use.
	 */
	public function check(): void {
		$this->client->get( 'meta' );
	}

	/**
	 * Removes the cron event. On deactivation and uninstall.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}
}
