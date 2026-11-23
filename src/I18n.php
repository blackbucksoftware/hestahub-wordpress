<?php
/**
 * @package HestaHub
 */

namespace HestaHub;

/**
 * Loads the translations shipped in languages/. A translation in
 * wp-content/languages/plugins/ still wins.
 */
final class I18n implements Feature {

	/** @var string The plugin's basename, e.g. hestahub/hestahub.php. */
	private $basename;

	/**
	 * @param string $basename The plugin's basename, from plugin_basename().
	 */
	public function __construct( string $basename ) {
		$this->basename = $basename;
	}

	public function register( Plugin $plugin ): void {
		add_action( 'init', array( $this, 'load' ) );
	}

	/**
	 * Hooked to init.
	 */
	public function load(): void {
		load_plugin_textdomain( 'hestahub', false, dirname( $this->basename ) . '/languages' );
	}
}
