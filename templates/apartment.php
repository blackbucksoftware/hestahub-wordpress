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

$hestahub_name     = hestahub_text( $apartment, 'name' );
$hestahub_slug     = hestahub_text( $apartment, 'slug' );
?>
<article class="hestahub hestahub-apartment">
	<h1 class="hestahub-title"><?php echo esc_html( $hestahub_name ); ?></h1>

	<ul class="hestahub-facts">
		<?php foreach ( hestahub_facts( $apartment ) as $hestahub_kind => $hestahub_fact ) : ?>
			<li class="hestahub-fact-<?php echo esc_attr( $hestahub_kind ); ?>"><?php echo esc_html( $hestahub_fact ); ?></li>
		<?php endforeach; ?>
	</ul>

	<?php if ( '' !== hestahub_text( $apartment, 'description' ) ) : ?>
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
				if ( '' === hestahub_text( $hestahub_image, 'url' ) ) {
					continue;
				}
				$hestahub_caption = '' !== hestahub_text( $hestahub_image, 'caption' ) ? $hestahub_image['caption'] : null;
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

	<?php if ( '' !== hestahub_text( $apartment, 'blueprint' ) ) : ?>
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

	<?php if ( '' !== hestahub_text( $apartment, 'portal_url' ) ) : ?>
		<p class="hestahub-booking"><a class="hestahub-booking-link" href="<?php echo esc_url( $apartment['portal_url'] ); ?>"><?php esc_html_e( 'Book now', 'hestahub' ); ?></a></p>
	<?php endif; ?>
</article>
<?php
do_action( 'hestahub_after_apartment', $apartment );
