<?php
/**
 * Removes everything the plugin stored: every option, transient and site
 * transient whose name starts with "hestahub_", on every site of a network.
 *
 * Transients stored in the database are rows like any option
 * ("_transient_hestahub_…", "_transient_timeout_hestahub_…"), so deleting the
 * rows by name removes them, their timeouts and any orphans alike.
 * Transients held only in a persistent object cache never reach the database
 * and cannot be listed here; they expire on their own.
 *
 * WordPress loads only this file on uninstall, so it must not use the
 * autoloader or anything under src/.
 *
 * @package HestaHub
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Names in a table column that belong to the plugin.
 *
 * @param string $table  Table name.
 * @param string $column Column holding the names.
 * @return string[]
 */
function hestahub_uninstall_names( $table, $column ) {
	global $wpdb;

	$prefixes = array(
		'hestahub_',
		'_transient_hestahub_',
		'_transient_timeout_hestahub_',
		'_site_transient_hestahub_',
		'_site_transient_timeout_hestahub_',
	);

	$where = implode( ' OR ', array_fill( 0, count( $prefixes ), "{$column} LIKE %s" ) );
	$likes = array();
	foreach ( $prefixes as $prefix ) {
		$likes[] = $wpdb->esc_like( $prefix ) . '%';
	}

	return $wpdb->get_col( $wpdb->prepare( "SELECT {$column} FROM {$table} WHERE {$where}", $likes ) );
}

// WP-Cron events, by hook name (src/Api/MetaCheck.php).
foreach ( array( 'hestahub_check_meta' ) as $hestahub_hook ) {
	wp_clear_scheduled_hook( $hestahub_hook );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $hestahub_site_id ) {
		switch_to_blog( $hestahub_site_id );
		foreach ( hestahub_uninstall_names( $GLOBALS['wpdb']->options, 'option_name' ) as $hestahub_name ) {
			delete_option( $hestahub_name );
		}
		restore_current_blog();
	}
	foreach ( hestahub_uninstall_names( $GLOBALS['wpdb']->sitemeta, 'meta_key' ) as $hestahub_name ) {
		delete_site_option( $hestahub_name );
	}
} else {
	foreach ( hestahub_uninstall_names( $GLOBALS['wpdb']->options, 'option_name' ) as $hestahub_name ) {
		delete_option( $hestahub_name );
	}
}
