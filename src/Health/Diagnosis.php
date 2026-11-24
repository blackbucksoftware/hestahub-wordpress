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
	 * The current problems, one per diagnosis.
	 *
	 * @return array[] kind (verdict, outage), code, message, status, since,
	 *                 diagnosis, remedy (who can fix it, and how), notice
	 *                 and email (whether each is due), streaks (endpoint =>
	 *                 failing_since, which tells one run of failures from
	 *                 the next).
	 */
	public function problems(): array {
		$problems = array();
		foreach ( $this->states as $endpoint => $state ) {
			$failure = $state->failure();
			$since   = $state->failing_since();
			if ( null === $failure || null === $since ) {
				continue;
			}
			$verdict = Failure::VERDICT === $failure->kind();
			$key     = $verdict ? $failure->code() . '|' . $failure->status() . '|' . ( 'module_disabled' === $failure->code() ? $this->module( $endpoint ) : '' ) : 'outage';
			if ( ! isset( $problems[ $key ] ) ) {
				$problems[ $key ] = array(
					'kind'    => $failure->kind(),
					'code'    => $failure->code(),
					'message' => $failure->message(),
					'status'  => $failure->status(),
					'since'   => $since,
					'module'  => $this->module( $endpoint ),
					'streaks' => array(),
				);
			}
			$problems[ $key ]['since']                 = min( $problems[ $key ]['since'], $since );
			$problems[ $key ]['streaks'][ $endpoint ] = $since;
		}

		$told = array();
		foreach ( $problems as $problem ) {
			$verdict = Failure::VERDICT === $problem['kind'];
			$age     = $this->now - $problem['since'];
			$told[]  = array(
				'kind'      => $problem['kind'],
				'code'      => $problem['code'],
				'message'   => $problem['message'],
				'status'    => $problem['status'],
				'since'     => $problem['since'],
				'diagnosis' => $this->diagnosis( $problem ),
				'remedy'    => $this->remedy( $problem ),
				'notice'    => $verdict || $age >= self::NOTICE_AFTER,
				'email'     => $verdict || $age >= self::EMAIL_AFTER,
				'streaks'   => $problem['streaks'],
			);
		}
		return $told;
	}

	/**
	 * What is wrong, in the webmaster's words.
	 *
	 * @param array $problem A problem.
	 */
	private function diagnosis( array $problem ): string {
		if ( Failure::VERDICT !== $problem['kind'] ) {
			/* translators: %s: date and time of the first failure. */
			return sprintf( __( 'HestaHub has not answered successfully since %s. The site shows the last copy it holds for as long as it may, then a placeholder.', 'hestahub' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $problem['since'] ) );
		}
		switch ( $problem['code'] ) {
			case 'unauthorized':
				return __( 'HestaHub does not accept the API key.', 'hestahub' );
			case 'key_revoked':
				return __( 'The API key has been revoked in HestaHub.', 'hestahub' );
			case 'tenant_inactive':
				/* translators: %s: the HestaHub address, e.g. kronshof.hestahub.com. */
				return sprintf( __( 'HestaHub knows no active Tenant at %s.', 'hestahub' ), $this->host() );
			case 'module_disabled':
				/* translators: 1: the HestaHub address, 2: Apartments or Clinics. */
				return sprintf( __( '%1$s does not have the %2$s module.', 'hestahub' ), $this->host(), $problem['module'] );
			case 'not_found':
				return __( 'HestaHub does not know an endpoint this plugin asked for.', 'hestahub' );
			case 'version_gone':
				return __( 'HestaHub has retired the API version this plugin uses.', 'hestahub' );
		}
		/* translators: 1: HTTP status, 2: error code, 3: HestaHub's message. */
		return trim( sprintf( __( 'HestaHub answered with HTTP status %1$d, code %2$s: %3$s', 'hestahub' ), $problem['status'], '' === $problem['code'] ? '—' : $problem['code'], $problem['message'] ), " \t\n\r\0\x0B:" );
	}

	/**
	 * Who can fix a problem, and how.
	 *
	 * @param array $problem A problem.
	 */
	private function remedy( array $problem ): string {
		if ( Failure::VERDICT !== $problem['kind'] ) {
			return __( 'Nothing to do here: the plugin keeps retrying. If it goes on, contact HestaHub.', 'hestahub' );
		}
		switch ( $problem['code'] ) {
			case 'unauthorized':
				return __( 'You can fix this: check the API key under Settings → HestaHub, or replace it.', 'hestahub' );
			case 'key_revoked':
				return __( 'You can fix this: create a new API key in HestaHub and enter it under Settings → HestaHub.', 'hestahub' );
			case 'tenant_inactive':
			case 'module_disabled':
				/* translators: %s: the HestaHub address, e.g. kronshof.hestahub.com. */
				return sprintf( __( 'Whoever administers %s can fix this, not the webmaster.', 'hestahub' ), $this->host() );
			case 'not_found':
			case 'version_gone':
				return __( 'Update the HestaHub plugin.', 'hestahub' );
		}
		return __( 'Check the connection under Settings → HestaHub.', 'hestahub' );
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
