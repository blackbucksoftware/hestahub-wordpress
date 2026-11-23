<?php
/**
 * Removes everything the plugin stored: options, transients and site
 * transients whose names start with "hestahub_", on every site of a network.
 *
 * WordPress loads only this file on uninstall, so it must not use the
 * autoloader or anything under src/.
 *
 * Transients held only in a persistent object cache never reach the database
 * and cannot be listed here; they expire on their own.
 *
 * @package HestaHub
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Deletes the plugin's options and transients from the current site's options table.
 */
function hestahub_uninstall_site() {
	global $wpdb;

	$prefixes = array( 'hestahub_', '_transient_hestahub_', '_site_transient_hestahub_' );
	$where    = implode( ' OR ', array_fill( 0, count( $prefixes ), 'option_name LIKE %s' ) );
	$likes    = array_map(
		static function ( $prefix ) use ( $wpdb ) {
			return $wpdb->esc_like( $prefix ) . '%';
		},
		$prefixes
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE {$where}", $likes ) );

	foreach ( $names as $name ) {
		if ( 0 === strpos( $name, '_site_transient_' ) ) {
			// Single site keeps site transients in the options table.
			delete_site_transient( substr( $name, strlen( '_site_transient_' ) ) );
		} elseif ( 0 === strpos( $name, '_transient_' ) ) {
			delete_transient( substr( $name, strlen( '_transient_' ) ) );
		} else {
			delete_option( $name );
		}
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $hestahub_site_id ) {
		switch_to_blog( $hestahub_site_id );
		hestahub_uninstall_site();
		restore_current_blog();
	}

	global $wpdb;
	$hestahub_prefixes = array( 'hestahub_', '_site_transient_hestahub_' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$hestahub_keys = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT meta_key FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
			$wpdb->esc_like( $hestahub_prefixes[0] ) . '%',
			$wpdb->esc_like( $hestahub_prefixes[1] ) . '%'
		)
	);
	foreach ( array_unique( $hestahub_keys ) as $hestahub_key ) {
		if ( 0 === strpos( $hestahub_key, '_site_transient_' ) ) {
			delete_site_transient( substr( $hestahub_key, strlen( '_site_transient_' ) ) );
		} else {
			delete_site_option( $hestahub_key );
		}
	}
} else {
	hestahub_uninstall_site();
}
