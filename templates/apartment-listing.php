<?php
/**
 * The Apartment listing. A theme overrides it by copying it to
 * <theme>/hestahub/apartment-listing.php. It renders content only: the page around it
 * is the theme's.
 *
 * Variables, the wire objects exactly as received (the contract's schema is
 * the field list): https://docs.hestahub.com/v1/#templates-and-hooks
 *
 * @var array[] $apartments Every Apartment shown, in the order received.
 *
 * @package HestaHub
 */

defined( 'ABSPATH' ) || exit;

$hestahub_seasons = array(); // Season key => name, in order of first appearance.
foreach ( $apartments as $hestahub_apartment ) {
	foreach ( hestahub_list( isset( $hestahub_apartment['pricing'] ) ? $hestahub_apartment['pricing'] : null, 'seasons' ) as $hestahub_season ) {
		$hestahub_key = hestahub_text( $hestahub_season, 'key' );
		if ( '' !== $hestahub_key && ! isset( $hestahub_seasons[ $hestahub_key ] ) ) {
			$hestahub_seasons[ $hestahub_key ] = hestahub_text( $hestahub_season, 'name' ); // Matched on the key, printed by name.
		}
	}
}
?>
<div class="hestahub hestahub-apartment-listing">
	<ul class="hestahub-cards">
		<?php
		foreach ( $apartments as $hestahub_apartment ) :
			if ( ! is_array( $hestahub_apartment ) ) {
				continue;
			}
			$hestahub_name     = hestahub_text( $hestahub_apartment, 'name' );
			$hestahub_building = isset( $hestahub_apartment['building'] ) && is_array( $hestahub_apartment['building'] ) ? $hestahub_apartment['building'] : null;
			$hestahub_url      = '' !== hestahub_text( $hestahub_apartment, 'slug' ) ? hestahub_url( 'apartments', $hestahub_apartment['slug'] ) : null;
			$hestahub_images   = hestahub_list( $hestahub_apartment, 'images' );
			$hestahub_primary  = null;
			foreach ( $hestahub_images as $hestahub_image ) {
				if ( ! empty( $hestahub_image['is_primary'] ) ) {
					$hestahub_primary = $hestahub_image;
					break;
				}
			}
			if ( null === $hestahub_primary && $hestahub_images ) {
				$hestahub_primary = $hestahub_images[0];
			}
			?>
			<li class="hestahub-card"
				<?php if ( '' !== hestahub_text( $hestahub_building, 'key' ) ) : ?>
					data-hestahub-building="<?php echo esc_attr( $hestahub_building['key'] ); ?>"
				<?php endif; ?>
				<?php if ( isset( $hestahub_apartment['max_persons'] ) && is_int( $hestahub_apartment['max_persons'] ) ) : ?>
					data-hestahub-max-persons="<?php echo esc_attr( (string) $hestahub_apartment['max_persons'] ); ?>"
				<?php endif; ?>
			>
				<?php if ( '' !== hestahub_text( $hestahub_primary, 'url' ) ) : ?>
					<img class="hestahub-card-image" src="<?php echo esc_url( $hestahub_primary['url'] ); ?>" alt="" loading="lazy">
				<?php endif; ?>
				<h2 class="hestahub-card-title">
					<?php if ( $hestahub_url ) : ?>
						<a href="<?php echo esc_url( $hestahub_url ); ?>"><?php echo esc_html( $hestahub_name ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $hestahub_name ); ?>
					<?php endif; ?>
				</h2>
				<ul class="hestahub-facts">
					<?php foreach ( hestahub_facts( $hestahub_apartment ) as $hestahub_kind => $hestahub_fact ) : ?>
						<li class="hestahub-fact-<?php echo esc_attr( $hestahub_kind ); ?>"><?php echo esc_html( $hestahub_fact ); ?></li>
					<?php endforeach; ?>
				</ul>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php if ( $hestahub_seasons ) : ?>
		<div class="hestahub-table-wrap">
			<table class="hestahub-rates">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Apartment', 'hestahub' ); ?></th>
						<?php foreach ( $hestahub_seasons as $hestahub_season_name ) : ?>
							<th scope="col"><?php echo esc_html( $hestahub_season_name ); ?></th>
						<?php endforeach; ?>
						<th scope="col"><?php esc_html_e( 'Final cleaning', 'hestahub' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( $apartments as $hestahub_apartment ) :
						if ( ! is_array( $hestahub_apartment ) ) {
							continue;
						}
						$hestahub_seasons_by_key = array();
						foreach ( hestahub_list( isset( $hestahub_apartment['pricing'] ) ? $hestahub_apartment['pricing'] : null, 'seasons' ) as $hestahub_season ) {
							$hestahub_seasons_by_key[ hestahub_text( $hestahub_season, 'key' ) ] = $hestahub_season;
						}
						?>
						<tr>
							<th scope="row"><?php echo esc_html( hestahub_text( $hestahub_apartment, 'name' ) ); ?></th>
							<?php foreach ( array_keys( $hestahub_seasons ) as $hestahub_key ) : ?>
								<td data-hestahub-season="<?php echo esc_attr( $hestahub_key ); ?>">
									<?php if ( isset( $hestahub_seasons_by_key[ $hestahub_key ] ) ) : ?>
										<ul class="hestahub-rate-lines">
											<?php foreach ( hestahub_list( $hestahub_seasons_by_key[ $hestahub_key ], 'tiers' ) as $hestahub_tier ) : ?>
												<li>
													<?php
													/* translators: 1: a night range, e.g. "3–6 nights", 2: the price per night. */
													echo esc_html( sprintf( __( '%1$s: %2$s per night', 'hestahub' ), '' !== hestahub_text( $hestahub_tier, 'name' ) ? $hestahub_tier['name'] : hestahub_nights( $hestahub_tier ) /* The Season is the column. */, hestahub_amount( isset( $hestahub_tier['price_per_night'] ) ? $hestahub_tier['price_per_night'] : null ) ) );
													?>
												</li>
											<?php endforeach; ?>
											<?php foreach ( hestahub_list( $hestahub_seasons_by_key[ $hestahub_key ], 'bundles' ) as $hestahub_bundle ) : ?>
												<li>
													<?php
													$hestahub_nights = isset( $hestahub_bundle['nights'] ) && is_int( $hestahub_bundle['nights'] ) ? $hestahub_bundle['nights'] : 0;
													/* translators: 1: a package's name, 2: its number of nights, 3: its total price. */
													echo esc_html( sprintf( _n( '%1$s (%2$s night): %3$s', '%1$s (%2$s nights): %3$s', $hestahub_nights, 'hestahub' ), hestahub_text( $hestahub_bundle, 'name' ), number_format_i18n( $hestahub_nights ), hestahub_amount( isset( $hestahub_bundle['total_price'] ) ? $hestahub_bundle['total_price'] : null ) ) );
													?>
												</li>
											<?php endforeach; ?>
										</ul>
									<?php endif; ?>
								</td>
							<?php endforeach; ?>
							<td class="hestahub-cleaning-fee"><?php echo esc_html( (string) hestahub_cleaning_fee( $hestahub_apartment ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php if ( '' !== hestahub_tax_note() ) : ?>
			<p class="hestahub-note"><?php echo esc_html( hestahub_tax_note() ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
</div>
<?php
do_action( 'hestahub_after_apartment_listing', $apartments );
