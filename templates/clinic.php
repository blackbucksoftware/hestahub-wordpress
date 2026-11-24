<?php
/**
 * A Clinic's page. A theme overrides it by copying it to
 * <theme>/hestahub/clinic.php. It renders content only: the page around it
 * is the theme's.
 *
 * Variables, the wire objects exactly as received (the contract's schema is
 * the field list): https://docs.hestahub.com/v1/#templates-and-hooks
 *
 * @var array $clinic The Clinic.
 *
 * @package HestaHub
 */

defined( 'ABSPATH' ) || exit;

$hestahub_signup     = hestahub_signup( $clinic );
$hestahub_signup_url = hestahub_signup_url( $clinic );
$hestahub_horses     = hestahub_horses( $clinic );
?>
<article class="hestahub hestahub-clinic">
	<h1 class="hestahub-title"><?php echo esc_html( hestahub_text( $clinic, 'name' ) ); ?></h1>

	<?php if ( '' !== hestahub_when( $clinic ) ) : ?>
		<p class="hestahub-when"><?php echo esc_html( hestahub_when( $clinic ) ); ?></p>
	<?php endif; ?>

	<?php if ( '' !== hestahub_text( $clinic, 'photo_url' ) ) : ?>
		<figure class="hestahub-photo">
			<img src="<?php echo esc_url( $clinic['photo_url'] ); ?>" alt="<?php echo esc_attr( hestahub_text( $clinic, 'name' ) ); ?>" loading="lazy">
		</figure>
	<?php endif; ?>

	<?php if ( '' !== hestahub_text( $clinic, 'description' ) ) : ?>
		<div class="hestahub-description"><?php echo wpautop( esc_html( $clinic['description'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped, then paragraphs. ?></div>
	<?php endif; ?>

	<ul class="hestahub-facts">
		<?php if ( '' !== $hestahub_horses ) : ?>
			<li class="hestahub-fact-horses"><?php echo esc_html( $hestahub_horses ); ?></li>
		<?php endif; ?>
		<?php if ( isset( $clinic['apartment_guests_only'] ) && true === $clinic['apartment_guests_only'] ) : ?>
			<li class="hestahub-fact-guests-only"><?php esc_html_e( 'Only guests staying in one of our apartments can sign up.', 'hestahub' ); ?></li>
		<?php endif; ?>
		<?php if ( '' !== hestahub_text( $clinic, 'fee' ) ) : ?>
			<li class="hestahub-fact-fee">
				<?php
				/* translators: %s: the fee per rider. */
				echo esc_html( sprintf( __( '%s per rider', 'hestahub' ), hestahub_amount( $clinic['fee'] ) ) );
				?>
			</li>
		<?php endif; ?>
		<?php if ( '' !== hestahub_text( $clinic, 'loan_horse_fee' ) ) : ?>
			<li class="hestahub-fact-loan-horse-fee">
				<?php
				/* translators: %s: the fee per loan horse. */
				echo esc_html( sprintf( __( '%s per loan horse', 'hestahub' ), hestahub_amount( $clinic['loan_horse_fee'] ) ) );
				?>
			</li>
		<?php endif; ?>
		<?php if ( isset( $clinic['min_participants'] ) && is_int( $clinic['min_participants'] ) ) : ?>
			<li class="hestahub-fact-min-participants">
				<?php
				/* translators: %s: the minimum number of participants. */
				echo esc_html( sprintf( _n( 'Minimum %s participant', 'Minimum %s participants', $clinic['min_participants'], 'hestahub' ), number_format_i18n( $clinic['min_participants'] ) ) );
				?>
			</li>
		<?php endif; ?>
	</ul>
	<?php if ( '' !== hestahub_tax_note() && ( '' !== hestahub_text( $clinic, 'fee' ) || '' !== hestahub_text( $clinic, 'loan_horse_fee' ) ) ) : ?>
		<p class="hestahub-note"><?php echo esc_html( hestahub_tax_note() ); ?></p>
	<?php endif; ?>

	<?php if ( '' !== $hestahub_signup ) : ?>
		<section class="hestahub-signup hestahub-signup--<?php echo esc_attr( $clinic['signup'] ); ?>">
			<h2><?php echo esc_html( $hestahub_signup ); ?></h2>
			<?php if ( '' !== hestahub_moment( $clinic['signup_opens_at'] ?? null ) ) : ?>
				<p class="hestahub-signup-opens">
					<?php
					/* translators: %s: date and time signups open. */
					echo esc_html( sprintf( __( 'Signup opens %s', 'hestahub' ), hestahub_moment( $clinic['signup_opens_at'] ) ) );
					?>
				</p>
			<?php endif; ?>
			<?php if ( '' !== hestahub_moment( $clinic['signup_closes_at'] ?? null ) ) : ?>
				<p class="hestahub-signup-closes">
					<?php
					/* translators: %s: date and time signups close. */
					echo esc_html( sprintf( __( 'Signup closes %s', 'hestahub' ), hestahub_moment( $clinic['signup_closes_at'] ) ) );
					?>
				</p>
			<?php endif; ?>
			<?php if ( null !== $hestahub_signup_url ) : ?>
				<p><a class="hestahub-signup-link" href="<?php echo esc_url( $hestahub_signup_url ); ?>"><?php echo esc_html( 'waitlist' === $clinic['signup'] ? __( 'Join the waitlist', 'hestahub' ) : __( 'Sign up', 'hestahub' ) ); ?></a></p>
			<?php endif; ?>
		</section>
	<?php endif; ?>
</article>
<?php
do_action( 'hestahub_after_clinic', $clinic );
