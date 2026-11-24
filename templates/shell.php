<?php
/**
 * The page around an owned route's content: the theme's header and footer.
 * The plugin's own, not one of the templates a theme overrides; a theme
 * styles or overrides the content templates instead.
 *
 * @package HestaHub
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main class="hestahub-page">
<?php
$hestahub_routes = hestahub()->get( \HestaHub\Routing\Routes::class );
if ( $hestahub_routes instanceof \HestaHub\Routing\Routes ) {
	$hestahub_routes->render_content();
}
?>
</main>
<?php
get_footer();
