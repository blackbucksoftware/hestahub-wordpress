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
 * hestahub_calendar() derives the months and day states; hestahub_as_of() the
 * "As of" ("Stand:") line, which is always shown, fresh or stale.
 *
 * The plugin's script (assets/hestahub.js) pages the months in place. It
 * needs the classes hestahub-calendar on the outer element and
 * hestahub-calendar-nav around the month links; without them, the links
 * reload the page, which works too.
 *
 * @package HestaHub
 */

defined( 'ABSPATH' ) || exit;
$hestahub_calendar = hestahub_calendar( $availability, $window );
$hestahub_as_of    = hestahub_as_of();
?>
<div class="hestahub hestahub-calendar">
	<?php if ( '' !== $hestahub_as_of ) : ?>
		<p class="hestahub-stand">
			<?php
			/* translators: %s: the date and time the availability was last fetched. */
			echo esc_html( sprintf( __( 'As of %s', 'hestahub' ), $hestahub_as_of ) );
			?>
		</p>
	<?php endif; ?>

	<?php if ( $hestahub_calendar['previous'] || $hestahub_calendar['next'] ) : ?>
		<nav class="hestahub-calendar-nav" aria-label="<?php esc_attr_e( 'Calendar months', 'hestahub' ); ?>">
			<?php if ( $hestahub_calendar['previous'] ) : ?>
				<a class="hestahub-calendar-previous" rel="prev" href="<?php echo esc_url( $hestahub_calendar['previous'] ); ?>"><?php esc_html_e( 'Earlier months', 'hestahub' ); ?></a>
			<?php endif; ?>
			<?php if ( $hestahub_calendar['next'] ) : ?>
				<a class="hestahub-calendar-next" rel="next" href="<?php echo esc_url( $hestahub_calendar['next'] ); ?>"><?php esc_html_e( 'Later months', 'hestahub' ); ?></a>
			<?php endif; ?>
		</nav>
	<?php endif; ?>

	<div class="hestahub-months">
		<?php foreach ( $hestahub_calendar['months'] as $hestahub_month ) : ?>
			<table class="hestahub-month" data-hestahub-month="<?php echo esc_attr( $hestahub_month['month'] ); ?>">
				<caption><?php echo esc_html( $hestahub_month['label'] ); ?></caption>
				<thead>
					<tr>
						<?php foreach ( $hestahub_calendar['weekdays'] as $hestahub_weekday ) : ?>
							<th scope="col"><?php echo esc_html( $hestahub_weekday ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $hestahub_month['weeks'] as $hestahub_week ) : ?>
						<tr>
							<?php foreach ( $hestahub_week as $hestahub_day ) : ?>
								<?php if ( null === $hestahub_day ) : ?>
									<td class="hestahub-day hestahub-day--blank"></td>
								<?php else : ?>
									<td class="hestahub-day hestahub-day--<?php echo esc_attr( $hestahub_day['state'] ); ?>">
										<time datetime="<?php echo esc_attr( $hestahub_day['date'] ); ?>"><?php echo esc_html( number_format_i18n( $hestahub_day['day'] ) ); ?></time>
										<span class="hestahub-visually-hidden"><?php echo esc_html( $hestahub_calendar['states'][ $hestahub_day['state'] ] ); ?></span>
									</td>
								<?php endif; ?>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endforeach; ?>
	</div>

	<ul class="hestahub-legend">
		<?php foreach ( $hestahub_calendar['states'] as $hestahub_state => $hestahub_label ) : ?>
			<li><span class="hestahub-day hestahub-day--<?php echo esc_attr( $hestahub_state ); ?> hestahub-legend-swatch" aria-hidden="true"></span> <?php echo esc_html( $hestahub_label ); ?></li>
		<?php endforeach; ?>
	</ul>
</div>
