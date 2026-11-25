<?php
/**
 * The page around an owned route's content: the theme's header and footer.
 *
 * A theme may override it, to put the content into its own layout, by
 * copying it into <theme>/hestahub/shell.php. A theme's copy must call
 * hestahub_content() once, where the content belongs, and get_header() and
 * get_footer(). Contract: https://docs.hestahub.com/v1/#templates-and-hooks
 *
 * @package HestaHub
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main class="hestahub-page">
<?php hestahub_content(); ?>
</main>
<?php
get_footer();
