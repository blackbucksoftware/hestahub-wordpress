<?php
/**
 * @package HestaHub
 */

namespace HestaHub\Tests;

use HestaHub\Feature;
use HestaHub\Plugin;
use WP_UnitTestCase;

final class PluginTest extends WP_UnitTestCase {

	public function test_features_register_when_plugins_loaded_fires() {
		$plugin  = new Plugin();
		$feature = new RecordingFeature();
		$plugin->add( $feature );
		$plugin->hook();

		$this->assertSame( array(), $feature->registered_with, 'Nothing runs before plugins_loaded.' );

		do_action( 'plugins_loaded' );

		$this->assertSame( array( $plugin ), $feature->registered_with );
	}

	public function test_features_register_only_once() {
		$plugin  = new Plugin();
		$feature = new RecordingFeature();
		$plugin->add( $feature );
		$plugin->hook();
		$plugin->hook();

		do_action( 'plugins_loaded' );
		do_action( 'plugins_loaded' );

		$this->assertCount( 1, $feature->registered_with );
	}
}

final class RecordingFeature implements Feature {

	/** @var Plugin[] */
	public $registered_with = array();

	public function register( Plugin $plugin ): void {
		$this->registered_with[] = $plugin;
	}
}
