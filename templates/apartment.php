<?php
/**
 * An Apartment's page. A theme overrides it by copying it to
 * <theme>/hestahub/apartment.php. It renders content only: the page around it
 * is the theme's.
 *
 * Variables, the wire objects exactly as received (the contract's schema is
 * the field list): https://docs.hestahub.com/v1/#templates-and-hooks
 *
 * @var array $apartment The Apartment.
 *
 * @package HestaHub
 */

defined( 'ABSPATH' ) || exit;

$hestahub_name     = isset( $apartment['name'] ) && is_string( $apartment['name'] ) ? $apartment['name'] : '';
$hestahub_slug     = isset( $apartment['slug'] ) && is_string( $apartment['slug'] ) ? $apartment['slug'] : '';
$hestahub_building = isset( $apartment['building'] ) && is_array( $apartment['building'] ) ? $apartment['building'] : null;
?>
<article class="hestahub hestahub-apartment">
	<h1 class="hestahub-title"><?php echo esc_html( $hestahub_name ); ?></h1>

	<ul class="hestahub-facts">
		<?php if ( $hestahub_building && isset( $hestahub_building['name'] ) && is_string( $hestahub_building['name'] ) ) : ?>
			<li class="hestahub-fact-building"><?php echo esc_html( $hestahub_building['name'] ); ?></li>
		<?php endif; ?>
		<?php if ( isset( $apartment['qm'] ) && is_int( $apartment['qm'] ) ) : ?>
			<li class="hestahub-fact-size">
				<?php
				/* translators: %s: floor area in square metres. */
				echo esc_html( sprintf( __( '%s m²', 'hestahub' ), number_format_i18n( $apartment['qm'] ) ) );
				?>
			</li>
		<?php endif; ?>
		<?php if ( isset( $apartment['max_persons'] ) && is_int( $apartment['max_persons'] ) ) : ?>
			<li class="hestahub-fact-persons">
				<?php
				/* translators: %s: the maximum number of persons. */
				echo esc_html( sprintf( _n( 'Up to %s person', 'Up to %s persons', $apartment['max_persons'], 'hestahub' ), number_format_i18n( $apartment['max_persons'] ) ) );
				?>
			</li>
		<?php endif; ?>
	</ul>

	<?php if ( isset( $apartment['description'] ) && is_string( $apartment['description'] ) && '' !== $apartment['description'] ) : ?>
		<div class="hestahub-description"><?php echo wpautop( esc_html( $apartment['description'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped, then paragraphs. ?></div>
	<?php endif; ?>

	<?php
	$hestahub_features = isset( $apartment['features'] ) && is_array( $apartment['features'] ) ? array_filter( $apartment['features'], 'is_string' ) : array();
	if ( $hestahub_features ) :
		?>
		<ul class="hestahub-features">
			<?php foreach ( $hestahub_features as $hestahub_feature ) : ?>
				<li><?php echo esc_html( $hestahub_feature ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php
	$hestahub_images = hestahub_list( $apartment, 'images' );
	if ( $hestahub_images ) :
		?>
		<div class="hestahub-gallery">
			<?php
			foreach ( $hestahub_images as $hestahub_image ) :
				if ( ! isset( $hestahub_image['url'] ) || ! is_string( $hestahub_image['url'] ) ) {
					continue;
				}
				$hestahub_caption = isset( $hestahub_image['caption'] ) && is_string( $hestahub_image['caption'] ) && '' !== $hestahub_image['caption'] ? $hestahub_image['caption'] : null;
				?>
				<figure class="hestahub-image<?php echo empty( $hestahub_image['is_primary'] ) ? '' : ' hestahub-image--primary'; ?>">
					<img src="<?php echo esc_url( $hestahub_image['url'] ); ?>" alt="<?php echo esc_attr( null === $hestahub_caption ? $hestahub_name : '' ); ?>" loading="lazy">
					<?php if ( null !== $hestahub_caption ) : ?>
						<figcaption><?php echo esc_html( $hestahub_caption ); ?></figcaption>
					<?php endif; ?>
				</figure>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php if ( isset( $apartment['blueprint'] ) && is_string( $apartment['blueprint'] ) && '' !== $apartment['blueprint'] ) : ?>
		<figure class="hestahub-blueprint">
			<img src="<?php echo esc_url( $apartment['blueprint'] ); ?>" alt="" loading="lazy">
			<figcaption><?php esc_html_e( 'Floor plan', 'hestahub' ); ?></figcaption>
		</figure>
	<?php endif; ?>

	<?php if ( '' !== $hestahub_slug ) : ?>
		<section class="hestahub-prices">
			<h2><?php esc_html_e( 'Prices', 'hestahub' ); ?></h2>
			<?php hestahub_render( 'rate-table', array( 'slug' => $hestahub_slug ) ); ?>
		</section>
		<section class="hestahub-availability">
			<h2><?php esc_html_e( 'Availability', 'hestahub' ); ?></h2>
			<?php hestahub_render( 'calendar', array( 'slug' => $hestahub_slug ) ); ?>
		</section>
	<?php endif; ?>

	<?php if ( isset( $apartment['portal_url'] ) && is_string( $apartment['portal_url'] ) && '' !== $apartment['portal_url'] ) : ?>
		<p class="hestahub-booking"><a class="hestahub-booking-link" href="<?php echo esc_url( $apartment['portal_url'] ); ?>"><?php esc_html_e( 'Book now', 'hestahub' ); ?></a></p>
	<?php endif; ?>
</article>
<?php
do_action( 'hestahub_after_apartment', $apartment );
