<?php
/**
 * @package HestaHub
 */

namespace HestaHub;

/**
 * The plugin's bootstrap. Features are added to it and registered on
 * plugins_loaded. Nothing else runs at load time.
 */
final class Plugin {

	/**
	 * Must match the Version header in hestahub.php.
	 */
	const VERSION = '0.1.0';

	/**
	 * @var Feature[]
	 */
	private $features = array();

	/**
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Adds a feature to be registered on plugins_loaded.
	 *
	 * @param Feature $feature The feature.
	 */
	public function add( Feature $feature ): self {
		$this->features[] = $feature;
		return $this;
	}

	/**
	 * Hooks the plugin into WordPress.
	 */
	public function hook(): void {
		add_action( 'plugins_loaded', array( $this, 'boot' ) );
	}

	/**
	 * Registers every feature. Hooked to plugins_loaded; not for direct use.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		foreach ( $this->features as $feature ) {
			$feature->register( $this );
		}
	}
}
