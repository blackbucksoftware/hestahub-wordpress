<?php
/**
 * PHPUnit bootstrap: loads the WordPress test suite, then this plugin.
 *
 * @package HestaHub
 */

$hestahub_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $hestahub_tests_dir ) {
	$hestahub_tests_dir = dirname( __DIR__ ) . '/.wp-tests/tests';
}

if ( ! file_exists( $hestahub_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test suite not found in $hestahub_tests_dir. Run bin/install-wp-tests.sh first.\n" );
	exit( 1 );
}

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );

require_once $hestahub_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/hestahub.php';
	}
);

require $hestahub_tests_dir . '/includes/bootstrap.php';
