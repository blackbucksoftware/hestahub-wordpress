<?php
/**
 * The Clinic listing. A theme overrides it by copying it to
 * <theme>/hestahub/clinic-listing.php. It renders content only: the page around it
 * is the theme's.
 *
 * Variables, the wire objects exactly as received (the contract's schema is
 * the field list): https://docs.hestahub.com/v1/#templates-and-hooks
 *
 * @var array[] $clinics Every Clinic shown, in the order received.
 *
 * @package HestaHub
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="hestahub hestahub-clinic-listing"></div>
