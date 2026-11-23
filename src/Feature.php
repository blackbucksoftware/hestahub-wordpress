<?php
/**
 * @package HestaHub
 */

namespace HestaHub;

/**
 * A part of the plugin that hooks itself into WordPress.
 *
 * Features are added to the Plugin in hestahub.php and registered once,
 * on plugins_loaded. A feature does its hooking in register(), never in
 * its constructor.
 */
interface Feature {

	/**
	 * Hooks the feature into WordPress.
	 *
	 * @param Plugin $plugin The plugin the feature belongs to.
	 */
	public function register( Plugin $plugin ): void;
}
