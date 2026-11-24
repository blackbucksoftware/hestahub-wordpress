<?php
/**
 * What an owned route shows while its data is temporarily unavailable, sent
 * with a 503. A theme overrides it by copying it to
 * <theme>/hestahub/unavailable.php. It must say nothing operational: no error,
 * no diagnostic, no version.
 *
 * @package HestaHub
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main class="hestahub hestahub-unavailable">
	<h1><?php esc_html_e( 'Temporarily unavailable', 'hestahub' ); ?></h1>
	<p><?php esc_html_e( 'This page cannot be shown right now. Please try again in a little while.', 'hestahub' ); ?></p>
</main>
<?php
get_footer();
