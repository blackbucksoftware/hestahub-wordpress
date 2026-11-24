<?php
/**
 * What an owned route shows once its data has been gone for more than a day,
 * sent with a 200. The plugin's own: not among the templates a theme
 * overrides. It must say nothing operational: no error,
 * no diagnostic, no version.
 *
 * @package HestaHub
 */

defined( 'ABSPATH' ) || exit;

$hestahub_resource = (string) get_query_var( \HestaHub\Routing\Routes::QV_RESOURCE );

get_header();
?>
<main class="hestahub hestahub-placeholder">
	<h1><?php echo esc_html( \HestaHub\Admin\Settings::label( $hestahub_resource ) ); ?></h1>
	<p><?php esc_html_e( 'There is nothing to show here at the moment. Please check back later.', 'hestahub' ); ?></p>
</main>
<?php
get_footer();
