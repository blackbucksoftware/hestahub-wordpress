<?php
/**
 * @package HestaHub
 */

namespace HestaHub\Health;

use HestaHub\Admin\Settings;
use HestaHub\Api\Client;
use HestaHub\Api\Credentials;
use HestaHub\Api\EndpointState;
use HestaHub\Api\Failure;
use HestaHub\Feature;
use HestaHub\Plugin;

/**
 * Tells the right person when HestaHub reads fail, and stays quiet when
 * they do not: admin notices, a Site Health test, and email by WP-Cron.
 * Contract: https://docs.hestahub.com/v1/#telling-someone
 *
 * Only the endpoints in use count: meta always, apartments and
 * availability or clinics while meta lists them. Email goes to the admin
 * address once per run of failures, never again for the same run, and
 * never from a plugin that never fetched successfully.
 */
final class Escalation implements Feature {

	const HOOK    = 'hestahub_escalate';
	const EMAILED = 'hestahub_emailed';
	const TEST    = 'hestahub_connection';

	/** @var Client */
	private $client;

	/** @var Credentials */
	private $credentials;

	/** @var callable */
	private $clock;

	/**
	 * @param Client        $client      The API client.
	 * @param Credentials   $credentials Where the Tenant comes from.
	 * @param callable|null $clock       Returns the current Unix time. Defaults to time().
	 */
	public function __construct( Client $client, Credentials $credentials, $clock = null ) {
		$this->client      = $client;
		$this->credentials = $credentials;
		$this->clock       = $clock ? $clock : 'time';
	}

	public function register( Plugin $plugin ): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_filter( 'site_status_tests', array( $this, 'site_status_tests' ) );
		add_action( self::HOOK, array( $this, 'escalate' ) );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::HOOK );
		}
	}

	/**
	 * On deactivation.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Prints a notice per problem due one. Hooked to admin_notices.
	 */
	public function render(): void {
		if ( ! current_user_can( Settings::CAPABILITY ) ) {
			return;
		}
		foreach ( $this->diagnosis()->problems() as $problem ) {
			if ( ! $problem['notice'] ) {
				continue;
			}
			$class = Failure::VERDICT === $problem['kind'] ? 'notice-error' : 'notice-warning';
			echo '<div class="notice ' . esc_attr( $class ) . ' hestahub-health"><p><strong>' . esc_html__( 'HestaHub:', 'hestahub' ) . '</strong> ' . esc_html( $problem['diagnosis'] ) . '</p><p>' . esc_html( $problem['remedy'] ) . '</p></div>';
		}
	}

	/**
	 * Adds the connection test to Site Health.
	 *
	 * @param array $tests Site Health's tests.
	 */
	public function site_status_tests( $tests ) {
		$tests['direct'][ self::TEST ] = array(
			'label' => __( 'HestaHub connection', 'hestahub' ),
			'test'  => array( $this, 'site_status' ),
		);
		return $tests;
	}

	/**
	 * Site Health's result: critical for a verdict, recommended for an
	 * outage due a notice, good otherwise.
	 */
	public function site_status(): array {
		$status = 'good';
		$label  = __( 'HestaHub answers', 'hestahub' );
		$text   = '';
		foreach ( $this->diagnosis()->problems() as $problem ) {
			if ( ! $problem['notice'] ) {
				continue;
			}
			$verdict = Failure::VERDICT === $problem['kind'];
			if ( 'critical' !== $status ) {
				$status = $verdict ? 'critical' : 'recommended';
				$label  = $problem['diagnosis'];
			}
			$text .= '<p>' . esc_html( $problem['diagnosis'] ) . ' ' . esc_html( $problem['remedy'] ) . '</p>';
		}
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'HestaHub', 'hestahub' ),
				'color' => 'critical' === $status ? 'red' : 'blue',
			),
			'description' => '' === $text ? '<p>' . esc_html__( 'The plugin reads from HestaHub without errors.', 'hestahub' ) . '</p>' : $text,
			'test'        => self::TEST,
		);
	}

	/**
	 * Emails the admin about every problem newly due an email. Hooked to
	 * the hourly WP-Cron event.
	 */
	public function escalate(): void {
		$diagnosis = $this->diagnosis();
		$problems  = $diagnosis->problems();
		$emailed   = $this->emailed();

		$current = array();
		foreach ( $problems as $problem ) {
			foreach ( $problem['streaks'] as $endpoint => $since ) {
				$current[ $endpoint . ':' . $since ] = true;
			}
		}
		$emailed = array_intersect_key( $emailed, $current ); // Forget runs that ended.

		$due = array();
		if ( $diagnosis->ever_fetched() ) {
			foreach ( $problems as $problem ) {
				if ( ! $problem['email'] ) {
					continue;
				}
				$new = false;
				foreach ( $problem['streaks'] as $endpoint => $since ) {
					$new = $new || ! isset( $emailed[ $endpoint . ':' . $since ] );
				}
				if ( $new ) {
					$due[] = $problem;
				}
			}
		}

		if ( $due && $this->send( $due ) ) {
			foreach ( $due as $problem ) {
				foreach ( $problem['streaks'] as $endpoint => $since ) {
					$emailed[ $endpoint . ':' . $since ] = true;
				}
			}
		}
		update_option( self::EMAILED, $emailed, false );
	}

	/**
	 * @param array[] $problems The problems to tell.
	 */
	private function send( array $problems ): bool {
		$lines = array();
		foreach ( $problems as $problem ) {
			$lines[] = $problem['diagnosis'] . "\n" . $problem['remedy'];
		}
		$lines[] = admin_url( 'options-general.php?page=' . Settings::PAGE );
		return wp_mail(
			(string) get_option( 'admin_email' ),
			/* translators: %s: the site's name. */
			sprintf( __( '[%s] HestaHub needs attention', 'hestahub' ), wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ) ),
			implode( "\n\n", $lines )
		);
	}

	private function diagnosis(): Diagnosis {
		$tenant = $this->credentials->tenant();
		$states = array();
		foreach ( $this->endpoints() as $endpoint ) {
			$states[ $endpoint ] = EndpointState::load( $endpoint, $tenant );
		}
		return new Diagnosis( $states, $tenant, (int) call_user_func( $this->clock ) );
	}

	/**
	 * The endpoints in use: meta, and what the meta copy lists.
	 *
	 * @return string[]
	 */
	private function endpoints(): array {
		$endpoints = array( 'meta' );
		foreach ( (array) $this->client->held_resources() as $resource ) {
			$endpoints[] = $resource;
			if ( 'apartments' === $resource ) {
				$endpoints[] = 'availability';
			}
		}
		return $endpoints;
	}

	/**
	 * @return array<string, true> endpoint:failing_since of each run already emailed.
	 */
	private function emailed(): array {
		$emailed = get_option( self::EMAILED, array() );
		return is_array( $emailed ) ? $emailed : array();
	}
}
