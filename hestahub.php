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
 *
 * @package HestaHub
 */

defined( 'ABSPATH' ) || exit;

if ( ! file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	// A git checkout without `composer install`. Release zips ship the autoloader.
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

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

hestahub()->hook();
