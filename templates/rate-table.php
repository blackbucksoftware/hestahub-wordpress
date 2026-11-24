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

$hestahub_tax_note = hestahub_tax_note();
$hestahub_has_fee  = null !== hestahub_cleaning_fee( $apartment ); // "0.00" means none: then no column and no note.
?>
<div class="hestahub hestahub-rate-table">
	<?php
	foreach ( hestahub_list( isset( $apartment['pricing'] ) ? $apartment['pricing'] : null, 'seasons' ) as $hestahub_season ) :
		$hestahub_periods = hestahub_list( $hestahub_season, 'periods' );
		$hestahub_tiers   = hestahub_list( $hestahub_season, 'tiers' );
		$hestahub_bundles = hestahub_list( $hestahub_season, 'bundles' );
		?>
		<section class="hestahub-season" data-hestahub-season="<?php echo esc_attr( hestahub_text( $hestahub_season, 'key' ) ); ?>">
			<h3><?php echo esc_html( hestahub_text( $hestahub_season, 'name' ) ); ?></h3>
			<?php if ( $hestahub_periods ) : ?>
				<ul class="hestahub-periods">
					<?php foreach ( $hestahub_periods as $hestahub_period ) : ?>
						<li><?php echo esc_html( hestahub_period( $hestahub_period ) ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php elseif ( ! empty( $hestahub_season['is_default'] ) ) : ?>
				<p class="hestahub-periods"><?php esc_html_e( 'All dates not in another season', 'hestahub' ); ?></p>
			<?php endif; ?>

			<?php if ( $hestahub_tiers ) : ?>
				<div class="hestahub-table-wrap">
					<table class="hestahub-tiers">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Stay', 'hestahub' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Per night', 'hestahub' ); ?></th>
								<?php if ( $hestahub_has_fee ) : ?>
									<th scope="col"><?php esc_html_e( 'Final cleaning', 'hestahub' ); ?></th>
								<?php endif; ?>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $hestahub_tiers as $hestahub_tier ) : ?>
								<tr>
									<th scope="row"><?php echo esc_html( hestahub_tier_label( $hestahub_tier, $hestahub_season ) ); ?></th>
									<td><?php echo esc_html( hestahub_amount( isset( $hestahub_tier['price_per_night'] ) ? $hestahub_tier['price_per_night'] : null ) ); ?></td>
									<?php if ( $hestahub_has_fee ) : ?>
										<td><?php echo esc_html( hestahub_fee( isset( $hestahub_tier['final_cleaning_fee'] ) ? $hestahub_tier['final_cleaning_fee'] : null ) ); ?></td>
									<?php endif; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<?php if ( $hestahub_bundles ) : ?>
				<div class="hestahub-table-wrap">
					<table class="hestahub-bundles">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Package', 'hestahub' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Nights', 'hestahub' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Total', 'hestahub' ); ?></th>
								<?php if ( $hestahub_has_fee ) : ?>
									<th scope="col"><?php esc_html_e( 'Final cleaning', 'hestahub' ); ?></th>
								<?php endif; ?>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $hestahub_bundles as $hestahub_bundle ) : ?>
								<tr>
									<th scope="row">
										<?php echo esc_html( hestahub_text( $hestahub_bundle, 'name' ) ); ?>
										<?php if ( '' !== hestahub_text( $hestahub_bundle, 'description' ) ) : ?>
											<span class="hestahub-bundle-terms"><?php echo esc_html( $hestahub_bundle['description'] ); ?></span>
										<?php endif; ?>
									</th>
									<td><?php echo esc_html( isset( $hestahub_bundle['nights'] ) && is_int( $hestahub_bundle['nights'] ) ? number_format_i18n( $hestahub_bundle['nights'] ) : '' ); ?></td>
									<td><?php echo esc_html( hestahub_amount( isset( $hestahub_bundle['total_price'] ) ? $hestahub_bundle['total_price'] : null ) ); ?></td>
									<?php if ( $hestahub_has_fee ) : ?>
										<td><?php echo esc_html( hestahub_fee( isset( $hestahub_bundle['final_cleaning_fee'] ) ? $hestahub_bundle['final_cleaning_fee'] : null ) ); ?></td>
									<?php endif; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</section>
	<?php endforeach; ?>

	<?php if ( $hestahub_has_fee || '' !== $hestahub_tax_note ) : ?>
	<p class="hestahub-note">
		<?php if ( $hestahub_has_fee ) : ?>
			<?php esc_html_e( 'The final cleaning is charged once per stay, on top of the price of the nights. A stay priced by nights takes the fee of the tier that prices its last night; a package adds its own fee to its total.', 'hestahub' ); ?>
		<?php endif; ?>
		<?php echo esc_html( $hestahub_tax_note ); ?>
	</p>
	<?php endif; ?>
</div>
