<?php
/**
 * Plugin Name:       HestaHub
 * Plugin URI:        https://github.com/blackbucksoftware/hestahub-wordpress
 * Description:       Shows your HestaHub Apartments, availability and Clinics on your own website.
 * Version:           0.1.0
 * Requires at least: 6.8
 * Requires PHP:      7.4
 * Author:            Blackbuck Software
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain:       hestahub
 * Domain Path:       /languages
 * Update URI:        https://updates.hestahub.com/hestahub
 *
 * @package HestaHub
 */

defined( 'ABSPATH' ) || exit;

// The update channel, behind one switch: a wordpress.org build leaves updates.php out.
// Loaded before the autoloader check, so a release missing its autoloader can still be updated.
if ( file_exists( __DIR__ . '/updates.php' ) ) {
	require __DIR__ . '/updates.php';
}

if ( ! file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	// A git checkout without `composer install`. Release zips ship the autoloader.
	return;
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/functions.php';

/**
 * The plugin instance.
 */
function hestahub(): \HestaHub\Plugin {
	static $plugin = null;
	if ( null === $plugin ) {
		$plugin = new \HestaHub\Plugin();
	}
	return $plugin;
}

$hestahub_credentials = new \HestaHub\Api\Credentials();
$hestahub_client      = new \HestaHub\Api\Client( $hestahub_credentials );
$hestahub_renderer    = new \HestaHub\Rendering\Renderer( $hestahub_client, __DIR__ );
hestahub()
	->add( new \HestaHub\I18n( plugin_basename( __FILE__ ) ) )
	->add( new \HestaHub\Api\MetaCheck( $hestahub_client ) )
	->add( new \HestaHub\Admin\Settings( $hestahub_client, $hestahub_credentials ) )
	->add( $hestahub_renderer )
	->add( new \HestaHub\Routing\Routes( $hestahub_client, $hestahub_renderer, __DIR__ ) )
	->add( new \HestaHub\Routing\ShadowNotice() );

register_activation_hook( __FILE__, array( \HestaHub\Routing\Routes::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \HestaHub\Routing\Routes::class, 'deactivate' ) );
register_deactivation_hook( __FILE__, array( \HestaHub\Api\MetaCheck::class, 'unschedule' ) );

hestahub()->hook();
