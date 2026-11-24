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

$hestahub_when       = hestahub_when( $clinic );
$hestahub_signup     = hestahub_signup( $clinic );
$hestahub_signup_url = hestahub_signup_url( $clinic );
$hestahub_opens      = hestahub_date_time( $clinic['signup_opens_at'] ?? null );
$hestahub_closes     = hestahub_date_time( $clinic['signup_closes_at'] ?? null );
$hestahub_horses     = hestahub_horses( $clinic );
$hestahub_fee        = hestahub_text( $clinic, 'fee' );
$hestahub_loan_fee   = hestahub_text( $clinic, 'loan_horse_fee' );
?>
<article class="hestahub hestahub-clinic">
	<h1 class="hestahub-title"><?php echo esc_html( hestahub_text( $clinic, 'name' ) ); ?></h1>

	<?php if ( '' !== $hestahub_when ) : ?>
		<p class="hestahub-when"><?php echo esc_html( $hestahub_when ); ?></p>
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
		<?php if ( '' !== $hestahub_fee ) : ?>
			<li class="hestahub-fact-fee">
				<?php
				/* translators: %s: the fee per rider. */
				echo esc_html( sprintf( __( '%s per rider', 'hestahub' ), hestahub_amount( $hestahub_fee ) ) );
				?>
			</li>
		<?php endif; ?>
		<?php if ( '' !== $hestahub_loan_fee ) : ?>
			<li class="hestahub-fact-loan-horse-fee">
				<?php
				/* translators: %s: the fee per loan horse. */
				echo esc_html( sprintf( __( '%s per loan horse', 'hestahub' ), hestahub_amount( $hestahub_loan_fee ) ) );
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
	<?php if ( '' !== hestahub_tax_note() && ( '' !== $hestahub_fee || '' !== $hestahub_loan_fee ) ) : ?>
		<p class="hestahub-note"><?php echo esc_html( hestahub_tax_note() ); ?></p>
	<?php endif; ?>

	<?php if ( '' !== $hestahub_signup || '' !== $hestahub_opens || '' !== $hestahub_closes ) : ?>
		<section class="hestahub-signup<?php echo '' === $hestahub_signup ? '' : ' hestahub-signup--' . esc_attr( $clinic['signup'] ); ?>">
			<?php if ( '' !== $hestahub_signup ) : ?>
				<h2><?php echo esc_html( $hestahub_signup ); ?></h2>
			<?php endif; ?>
			<?php if ( '' !== $hestahub_opens ) : ?>
				<p class="hestahub-signup-opens">
					<?php
					/* translators: %s: date and time signups open. */
					echo esc_html( sprintf( __( 'Signup opens %s', 'hestahub' ), $hestahub_opens ) );
					?>
				</p>
			<?php endif; ?>
			<?php if ( '' !== $hestahub_closes ) : ?>
				<p class="hestahub-signup-closes">
					<?php
					/* translators: %s: date and time signups close. */
					echo esc_html( sprintf( __( 'Signup closes %s', 'hestahub' ), $hestahub_closes ) );
					?>
				</p>
			<?php endif; ?>
			<?php if ( null !== $hestahub_signup_url ) : ?>
				<p><a class="hestahub-signup-link" href="<?php echo esc_url( $hestahub_signup_url ); ?>"><?php echo esc_html( hestahub_signup_action( $clinic ) ); ?></a></p>
			<?php endif; ?>
		</section>
	<?php endif; ?>
</article>
<?php
do_action( 'hestahub_after_clinic', $clinic );
