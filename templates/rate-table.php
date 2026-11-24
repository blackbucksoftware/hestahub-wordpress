<?php
/**
 * An Apartment's rate table, a partial. A theme overrides it by copying it to
 * <theme>/hestahub/rate-table.php. It renders content only: the page around it
 * is the theme's.
 *
 * Variables, the wire objects exactly as received (the contract's schema is
 * the field list): https://docs.hestahub.com/v1/#templates-and-hooks
 *
 * @var array $apartment The Apartment; its Rate structure is $apartment['pricing'].
 *
 * @package HestaHub
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="hestahub hestahub-rate-table"></div>
