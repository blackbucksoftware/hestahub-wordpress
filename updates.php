<?php
/**
 * The update channel.
 *
 * Checks a static JSON manifest attached to this repo's latest GitHub Release
 * and offers its zip as an update. The manifest has no "autoupdate" key, so
 * auto-updates stay off unless the webmaster turns them on.
 *
 * This file is the one switch: hestahub.php loads it only if it exists, and a
 * wordpress.org build (bin/build-zip.sh --wporg) leaves it out, together with
 * lib/plugin-update-checker/ and the Update URI header. Nothing else in the
 * plugin may refer to updates.
 *
 * @package HestaHub
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lib/plugin-update-checker/plugin-update-checker.php';

$hestahub_update_checker = \YahnisElsts\PluginUpdateChecker\v6\PucFactory::buildUpdateChecker(
	'https://github.com/blackbucksoftware/hestahub-wordpress/releases/latest/download/hestahub.json',
	__DIR__ . '/hestahub.php',
	'hestahub'
);

// The manifest is a static file: send none of the library's default query
// arguments (installed version, PHP version, locale).
$hestahub_update_checker->addQueryArgFilter(
	static function () {
		return array();
	}
);
