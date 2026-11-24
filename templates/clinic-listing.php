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
<div class="hestahub hestahub-clinic-listing">
	<ul class="hestahub-cards">
		<?php
		foreach ( $clinics as $hestahub_clinic ) :
			if ( ! is_array( $hestahub_clinic ) ) {
				continue;
			}
			$hestahub_url    = '' !== hestahub_text( $hestahub_clinic, 'slug' ) ? hestahub_url( 'clinics', $hestahub_clinic['slug'] ) : null;
			$hestahub_signup = hestahub_signup( $hestahub_clinic );
			$hestahub_when   = hestahub_when( $hestahub_clinic );
			?>
			<li class="hestahub-card"
				<?php if ( '' !== $hestahub_signup ) : ?>
					data-hestahub-signup="<?php echo esc_attr( $hestahub_clinic['signup'] ); ?>"
				<?php endif; ?>
			>
				<?php if ( '' !== hestahub_text( $hestahub_clinic, 'thumbnail_url' ) ) : ?>
					<img class="hestahub-card-image" src="<?php echo esc_url( $hestahub_clinic['thumbnail_url'] ); ?>" alt="" loading="lazy">
				<?php endif; ?>
				<h2 class="hestahub-card-title">
					<?php if ( $hestahub_url ) : ?>
						<a href="<?php echo esc_url( $hestahub_url ); ?>"><?php echo esc_html( hestahub_text( $hestahub_clinic, 'name' ) ); ?></a>
					<?php else : ?>
						<?php echo esc_html( hestahub_text( $hestahub_clinic, 'name' ) ); ?>
					<?php endif; ?>
				</h2>
				<?php if ( '' !== $hestahub_when ) : ?>
					<p class="hestahub-when"><?php echo esc_html( $hestahub_when ); ?></p>
				<?php endif; ?>
				<?php if ( '' !== $hestahub_signup ) : ?>
					<p class="hestahub-signup hestahub-signup--<?php echo esc_attr( $hestahub_clinic['signup'] ); ?>"><?php echo esc_html( $hestahub_signup ); ?></p>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
</div>
<?php
do_action( 'hestahub_after_clinic_listing', $clinics );
