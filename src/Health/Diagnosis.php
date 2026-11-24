<?php
/**
 * @package HestaHub
 */

namespace HestaHub\Health;

use HestaHub\Api\EndpointState;
use HestaHub\Api\Failure;

/**
 * What is wrong with the plugin's reads from HestaHub, who can fix it, and
 * whether it is time to say so. Contract:
 * https://docs.hestahub.com/v1/#telling-someone
 *
 * A verdict (401, 404, 410) is told at once: retrying cannot help. An
 * outage is told in a notice after 24 continuous hours and by email once
 * the fifth day has begun. Failures with the same diagnosis on several
 * endpoints are one problem.
 */
final class Diagnosis {

	const NOTICE_AFTER = DAY_IN_SECONDS;
	const EMAIL_AFTER  = 4 * DAY_IN_SECONDS;

	/** @var EndpointState[] Endpoint => state. */
	private $states;

	/** @var string */
	private $tenant;

	/** @var int */
	private $now;

	/**
	 * @param EndpointState[] $states The states of the endpoints in use, by endpoint.
	 * @param string          $tenant The Tenant's subdomain.
	 * @param int             $now    The current time.
	 */
	public function __construct( array $states, string $tenant, int $now ) {
		$this->states = $states;
		$this->tenant = $tenant;
		$this->now    = $now;
	}

	/**
	 * Whether any endpoint in use ever fetched successfully. A plugin that
	 * never did sends no email.
	 */
	public function ever_fetched(): bool {
		foreach ( $this->states as $state ) {
			if ( null !== $state->last_success() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The current problems, one per diagnosis. Each endpoint keeps its own
	 * clock: a problem's notice is due once any of its endpoints has failed
	 * long enough, and its email names the runs of failures now due one.
	 *
	 * @return array[] kind (verdict, outage), code, message, status, since
	 *                 (the oldest run), diagnosis, remedy (who can fix it,
	 *                 and how), notice (whether due), due (the runs due an
	 *                 email, as endpoint => failing_since).
	 */
	public function problems(): array {
		$problems = array();
		foreach ( $this->states as $endpoint => $state ) {
			$failure = $state->failure();
			$since   = $state->failing_since();
			if ( null === $failure || null === $since ) {
				continue;
			}
			$key = $this->problem_key( $failure, $endpoint );
			if ( ! isset( $problems[ $key ] ) ) {
				$words            = $this->words( $failure, $endpoint, $since );
				$problems[ $key ] = array(
					'kind'      => $failure->kind(),
					'code'      => $failure->code(),
					'message'   => $failure->message(),
					'status'    => $failure->status(),
					'since'     => $since,
					'diagnosis' => $words[0],
					'remedy'    => $words[1],
					'notice'    => false,
					'due'       => array(),
				);
			}
			$verdict = Failure::VERDICT === $failure->kind();
			$age     = $this->now - $since;
			if ( $since < $problems[ $key ]['since'] ) {
				$problems[ $key ]['since']     = $since;
				$problems[ $key ]['diagnosis'] = $this->words( $failure, $endpoint, $since )[0];
			}
			$problems[ $key ]['notice'] = $problems[ $key ]['notice'] || $verdict || $age >= self::NOTICE_AFTER;
			if ( $verdict || $age >= self::EMAIL_AFTER ) {
				$problems[ $key ]['due'][ $endpoint ] = $since;
			}
		}
		return array_values( $problems );
	}

	/**
	 * Which problem a failure belongs to: all outages are one; a verdict is
	 * its status and code, and for module_disabled also its Module.
	 *
	 * @param Failure $failure  The failure.
	 * @param string  $endpoint Its endpoint.
	 */
	private function problem_key( Failure $failure, string $endpoint ): string {
		if ( Failure::VERDICT !== $failure->kind() ) {
			return 'outage';
		}
		$module = 'module_disabled' === $failure->code() ? $this->module( $endpoint ) : '';
		return implode( '|', array( $failure->code(), $failure->status(), $module ) );
	}

	/**
	 * What is wrong and who can fix it, in the webmaster's words.
	 *
	 * @param Failure $failure  The failure.
	 * @param string  $endpoint Its endpoint.
	 * @param int     $since    When its run of failures began.
	 * @return string[] diagnosis, remedy.
	 */
	private function words( Failure $failure, string $endpoint, int $since ): array {
		if ( Failure::VERDICT !== $failure->kind() ) {
			return array(
				/* translators: %s: date and time of the first failure. */
				sprintf( __( 'HestaHub has not answered successfully since %s. The site shows the last copy it holds for as long as it may, then a placeholder.', 'hestahub' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $since ) ),
				__( 'Nothing to do here: the plugin keeps retrying. If it goes on, contact HestaHub.', 'hestahub' ),
			);
		}
		/* translators: %s: the HestaHub address, e.g. kronshof.hestahub.com. */
		$tenants = sprintf( __( 'Whoever administers %s can fix this, not the webmaster.', 'hestahub' ), $this->host() );
		$update  = __( 'Update the HestaHub plugin.', 'hestahub' );
		switch ( $failure->code() ) {
			case 'unauthorized':
				return array( __( 'HestaHub does not accept the API key.', 'hestahub' ), __( 'You can fix this: check the API key under Settings → HestaHub, or replace it.', 'hestahub' ) );
			case 'key_revoked':
				return array( __( 'The API key has been revoked in HestaHub.', 'hestahub' ), __( 'You can fix this: create a new API key in HestaHub and enter it under Settings → HestaHub.', 'hestahub' ) );
			case 'tenant_inactive':
				/* translators: %s: the HestaHub address, e.g. kronshof.hestahub.com. */
				return array( sprintf( __( 'HestaHub knows no active Tenant at %s.', 'hestahub' ), $this->host() ), $tenants );
			case 'module_disabled':
				/* translators: 1: the HestaHub address, 2: Apartments or Clinics. */
				return array( sprintf( __( '%1$s does not have the %2$s module.', 'hestahub' ), $this->host(), $this->module( $endpoint ) ), $tenants );
			case 'not_found':
				return array( __( 'HestaHub does not know an endpoint this plugin asked for.', 'hestahub' ), $update );
			case 'version_gone':
				return array( __( 'HestaHub has retired the API version this plugin uses.', 'hestahub' ), $update );
		}
		return array(
			/* translators: 1: HTTP status, 2: error code, 3: HestaHub's message. */
			trim( sprintf( __( 'HestaHub answered with HTTP status %1$d, code %2$s: %3$s', 'hestahub' ), $failure->status(), '' === $failure->code() ? '—' : $failure->code(), $failure->message() ), " \t\n\r\0\x0B:" ),
			__( 'Check the connection under Settings → HestaHub.', 'hestahub' ),
		);
	}

	/**
	 * The Module an endpoint serves.
	 *
	 * @param string $endpoint The endpoint.
	 */
	private function module( string $endpoint ): string {
		return 'clinics' === $endpoint ? __( 'Clinics', 'hestahub' ) : __( 'Apartments', 'hestahub' );
	}

	private function host(): string {
		return $this->tenant . '.hestahub.com';
	}
}
