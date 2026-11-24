<?php
/**
 * An Apartment's availability calendar, a partial. A theme overrides it by copying it to
 * <theme>/hestahub/calendar.php. It renders content only: the page around it
 * is the theme's.
 *
 * Variables, the wire objects exactly as received (the contract's schema is
 * the field list): https://docs.hestahub.com/v1/#templates-and-hooks
 *
 * @var array $availability The Apartment's entry in availability: slug, blocked.
 * @var array $window       The half-open window the ranges describe: from, to.
 *                          Either may be null. A day outside the window, or
 *                          with no window, is unknown, never free.
 *
 * @package HestaHub
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="hestahub hestahub-calendar"></div>
