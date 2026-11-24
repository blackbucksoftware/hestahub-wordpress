<?php
/**
 * @package HestaHub
 */

namespace HestaHub\Health;

use HestaHub\Admin\Settings;
use HestaHub\Api\Client;
use HestaHub\Feature;
use HestaHub\Plugin;

/**
 * Admin notices that are not health issues: a dismissible prompt to set a
 * base path, a plugin below meta's minimum version, a site on PHP 7.
 * Contract: https://docs.hestahub.com/v1/#being-out-of-date
 */
final class Notices implements Feature {

	const DISMISSED = 'hestahub_setup_dismissed';
	const ACTION    = 'hestahub_dismiss_setup';

	/** @var Client */
	private $client;

	/** @var string */
	private $php;

	/**
	 * @param Client      $client The API client.
	 * @param string|null $php    The PHP version. Defaults to PHP_VERSION.
	 */
	public function __construct( Client $client, ?string $php = null ) {
		$this->client = $client;
		$this->php    = null === $php ? PHP_VERSION : $php;
	}

	public function register( Plugin $plugin ): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'dismiss' ) );
	}

	/**
	 * Hooked to admin_notices.
	 */
	public function render(): void {
		if ( ! current_user_can( Settings::CAPABILITY ) ) {
			return;
		}
		$this->render_setup();
		$this->render_minimum_version();
		$this->render_php();
	}

	/**
	 * Forgets the setup prompt, then goes back. Hooked to admin-post.
	 */
	public function dismiss(): void {
		if ( ! current_user_can( Settings::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage HestaHub.', 'hestahub' ), 403 );
		}
		check_admin_referer( self::ACTION );
		update_option( self::DISMISSED, true );
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url() );
		exit;
	}

	/**
	 * A prompt for each resource meta lists whose base path is empty, until
	 * dismissed.
	 */
	private function render_setup(): void {
		if ( get_option( self::DISMISSED ) ) {
			return;
		}
		$missing = array();
		foreach ( (array) $this->client->held_resources() as $resource ) {
			$base = get_option( Settings::BASES[ $resource ], '' );
			if ( ! is_string( $base ) || '' === $base ) {
				$missing[] = Settings::label( $resource );
			}
		}
		if ( ! $missing ) {
			return;
		}
		$settings = admin_url( 'options-general.php?page=' . Settings::PAGE );
		$dismiss  = wp_nonce_url( add_query_arg( 'action', self::ACTION, admin_url( 'admin-post.php' ) ), self::ACTION );
		echo '<div class="notice notice-info hestahub-setup"><p>';
		printf(
			/* translators: 1: Apartments, Clinics, or both, 2: link to the settings screen. */
			esc_html__( 'Set a base path for %1$s to show them on your site: %2$s', 'hestahub' ),
			esc_html( implode( ', ', $missing ) ),
			'<a href="' . esc_url( $settings ) . '">' . esc_html__( 'Settings → HestaHub', 'hestahub' ) . '</a>'
		);
		echo '</p><p><a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Dismiss', 'hestahub' ) . '</a></p></div>';
	}

	/**
	 * A notice while this plugin is below meta's minimum_plugin_version.
	 */
	private function render_minimum_version(): void {
		$meta    = $this->client->held( 'meta' );
		$minimum = isset( $meta['minimum_plugin_version'] ) && is_string( $meta['minimum_plugin_version'] ) ? $meta['minimum_plugin_version'] : '';
		if ( '' === $minimum || ! version_compare( Plugin::VERSION, $minimum, '<' ) ) {
			return;
		}
		echo '<div class="notice notice-warning hestahub-minimum-version"><p>';
		printf(
			/* translators: 1: this plugin's version, 2: the minimum version HestaHub asks for. */
			esc_html__( 'HestaHub asks for version %2$s of its plugin or newer; this site runs %1$s. Please update the HestaHub plugin.', 'hestahub' ),
			esc_html( Plugin::VERSION ),
			esc_html( $minimum )
		);
		echo '</p></div>';
	}

	/**
	 * A notice on PHP 7. It recommends upgrading and never blocks.
	 */
	private function render_php(): void {
		if ( ! version_compare( $this->php, '8.0', '<' ) ) {
			return;
		}
		echo '<div class="notice notice-warning hestahub-php"><p>';
		esc_html_e( 'This site runs on PHP 7. The HestaHub plugin still works, but PHP 7 no longer receives security updates. Ask your host to move the site to a current PHP version.', 'hestahub' );
		echo '</p></div>';
	}
}
