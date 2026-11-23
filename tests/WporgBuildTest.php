<?php
/**
 * @package HestaHub
 */

namespace HestaHub\Tests;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The update channel sits behind one switch: a wordpress.org build
 * (bin/build-zip.sh --wporg) must load nothing update-related.
 */
final class WporgBuildTest extends TestCase {

	/** @var string */
	private static $build_dir;

	public static function set_up_before_class() {
		parent::set_up_before_class();

		self::$build_dir = sys_get_temp_dir() . '/hestahub-wporg-build-' . getmypid();
		$command         = sprintf(
			'BUILD_DIR=%s %s --wporg 2>&1',
			escapeshellarg( self::$build_dir ),
			escapeshellarg( dirname( __DIR__ ) . '/bin/build-zip.sh' )
		);
		exec( $command, $output, $status );
		if ( 0 !== $status ) {
			self::fail( "The wordpress.org build failed:\n" . implode( "\n", $output ) );
		}
	}

	public static function tear_down_after_class() {
		exec( 'rm -rf ' . escapeshellarg( self::$build_dir ) );
		parent::tear_down_after_class();
	}

	public function test_loading_the_wporg_build_loads_nothing_update_related() {
		$loaded = $this->load_in_subprocess( self::$build_dir . '/hestahub/hestahub.php' );

		$this->assertSame( array(), $loaded['update_classes'], 'No update-checker class is declared.' );
		$this->assertSame( array(), $loaded['update_files'], 'No update-related file is included.' );
		$this->assertSame( array( 'plugins_loaded' ), $loaded['hooks'], 'Only the bootstrap hooks in.' );
	}

	public function test_the_wporg_build_has_no_update_uri_header() {
		$header = get_file_data( self::$build_dir . '/hestahub/hestahub.php', array( 'update_uri' => 'Update URI' ) );

		$this->assertSame( '', $header['update_uri'] );
	}

	/**
	 * Loads a main plugin file in a fresh PHP process with WordPress's hook
	 * functions stubbed, and reports what it loaded.
	 *
	 * @param string $file Main plugin file.
	 * @return array{update_classes: string[], update_files: string[], hooks: string[]}
	 */
	private function load_in_subprocess( $file ) {
		$script = <<<'PHP'
define( 'ABSPATH', '/' );
$GLOBALS['hestahub_hooks'] = array();
function add_action( $hook ) { $GLOBALS['hestahub_hooks'][] = $hook; return true; }
function add_filter( $hook ) { $GLOBALS['hestahub_hooks'][] = $hook; return true; }
require $argv[1];
$update = '/update|puc/i';
echo json_encode( array(
	'update_classes' => array_values( preg_grep( $update, get_declared_classes() ) ),
	'update_files'   => array_values( preg_grep( $update, array_map( 'basename', get_included_files() ) ) ),
	'hooks'          => array_values( array_unique( $GLOBALS['hestahub_hooks'] ) ),
) );
PHP;

		$command = sprintf(
			'%s -r %s %s 2>&1',
			escapeshellarg( PHP_BINARY ),
			escapeshellarg( $script ),
			escapeshellarg( $file )
		);
		exec( $command, $output, $status );
		$this->assertSame( 0, $status, implode( "\n", $output ) );

		$loaded = json_decode( implode( "\n", $output ), true );
		$this->assertIsArray( $loaded, implode( "\n", $output ) );
		return $loaded;
	}
}
