<?php
/**
 * What an owned route shows while its data is temporarily unavailable, sent
 * with a 503, inside the page shell. The plugin's own: not among the
 * templates a theme overrides. It must say nothing operational: no error,
 * no diagnostic, no version.
 *
 * @package HestaHub
 */

defined( 'ABSPATH' ) || exit;

?>
<div class="hestahub hestahub-unavailable">
	<h1><?php esc_html_e( 'Temporarily unavailable', 'hestahub' ); ?></h1>
	<p><?php esc_html_e( 'This page cannot be shown right now. Please try again in a little while.', 'hestahub' ); ?></p>
</div>
