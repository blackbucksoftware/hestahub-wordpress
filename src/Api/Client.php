<?php
/**
 * @package HestaHub
 */

namespace HestaHub\Api;

use HestaHub\Plugin;

/**
 * The one place that talks to HestaHub. Contract: https://docs.hestahub.com/v1/
 */
final class Client {

	/**
	 * The minimum Revision of the contract this plugin was built for.
	 */
	const REVISION = '2026-11-23';

	/**
	 * The endpoints v1 serves.
	 */
	const ENDPOINTS = array( 'meta', 'apartments', 'availability', 'clinics' );

	/**
	 * The resources meta may list that this plugin knows how to serve.
	 */
	const RESOURCES = array( 'apartments', 'clinics' );

	/**
	 * Back-off: outages wait one minute, doubling up to the cap; verdicts wait the cap.
	 */
	const FIRST_RETRY = 60;
	const MAX_RETRY   = 3600;

	/**
	 * meta is checked at least this often, whatever its ttl says.
	 */
	const META_MAX_AGE = 86400;

	/**
	 * Option holding each endpoint's back-off: its last failure, consecutive
	 * outages and when it may be retried.
	 */
	const STATE_OPTION = 'hestahub_api_state';

	/** @var Credentials */
	private $credentials;

	/** @var callable */
	private $clock;

	/**
	 * @param Credentials   $credentials Where the Tenant and key come from.
	 * @param callable|null $clock       Returns the current Unix time. Defaults to time().
	 */
	public function __construct( Credentials $credentials, $clock = null ) {
		$this->credentials = $credentials;
		$this->clock       = $clock ? $clock : 'time';
	}

	/**
	 * Reads an endpoint: the cached copy while it is fresh, otherwise a new
	 * fetch. When the fetch fails, the cached copy is served until stale_ttl.
	 *
	 * @param string $endpoint meta, apartments, availability or clinics.
	 */
	public function get( string $endpoint ): Result {
		$this->check_endpoint( $endpoint );
		$now    = $this->now();
		$cached = $this->cached( $endpoint, $now );
		if ( $cached && $now < $cached['fetched_at'] + $this->soft_ttl( $endpoint, $cached['ttl'] ) ) {
			return Result::ok( $cached );
		}

		if ( ! $this->credentials->is_complete() ) {
			return $this->failed( $cached, new Failure( Failure::NOT_CONFIGURED, 0, '', '' ), 0 );
		}

		$state = $this->state( $endpoint );
		if ( $state && $now < $state['retry_at'] ) {
			return $this->failed( $cached, $state['failure'], $state['retry_at'] - $now );
		}

		$fetched = $this->fetch( $endpoint );
		if ( $fetched instanceof Failure ) {
			$wait = $this->back_off( $endpoint, $fetched, $state, $now );
			return $this->failed( $cached, $fetched, $wait );
		}

		$this->clear_state( $endpoint );
		$fetched['fetched_at'] = $now;
		if ( $fetched['stale_ttl'] > 0 ) {
			// The body says how long to keep it. Without that, keep nothing.
			set_transient( $this->cache_key( $endpoint ), $fetched, $fetched['stale_ttl'] );
		}
		return Result::ok( $fetched );
	}

	/**
	 * Fetches an endpoint now, bypassing the cache and the back-off, and
	 * leaving both untouched. For "test connection".
	 *
	 * @param string $endpoint meta, apartments, availability or clinics.
	 */
	public function fetch_live( string $endpoint ): Result {
		$this->check_endpoint( $endpoint );
		if ( ! $this->credentials->is_complete() ) {
			return Result::failed( new Failure( Failure::NOT_CONFIGURED, 0, '', '' ), 0 );
		}

		$fetched = $this->fetch( $endpoint );
		if ( $fetched instanceof Failure ) {
			return Result::failed( $fetched, 0 );
		}
		$fetched['fetched_at'] = $this->now();
		return Result::ok( $fetched );
	}

	/**
	 * Discards every cached copy and every back-off, so the next read fetches.
	 * For "refresh now". It also discards the stale copy that may be keeping
	 * the site up.
	 */
	public function flush(): void {
		foreach ( self::ENDPOINTS as $endpoint ) {
			delete_transient( $this->cache_key( $endpoint ) );
		}
		delete_option( self::STATE_OPTION );
	}

	/**
	 * The resources meta lists that this plugin serves (apartments, clinics),
	 * in meta's order; unknown members are left out. Null while meta is
	 * unavailable.
	 *
	 * @return string[]|null
	 */
	public function resources(): ?array {
		$meta = $this->get( 'meta' );
		if ( ! $meta->is_ok() ) {
			return null;
		}
		$data = $meta->data();
		if ( ! isset( $data['resources'] ) || ! is_array( $data['resources'] ) ) {
			return array();
		}
		return array_values( array_intersect( $data['resources'], self::RESOURCES ) );
	}

	/**
	 * @param string $endpoint The endpoint.
	 * @throws \InvalidArgumentException When v1 has no such endpoint.
	 */
	private function check_endpoint( string $endpoint ): void {
		if ( ! in_array( $endpoint, self::ENDPOINTS, true ) ) {
			throw new \InvalidArgumentException( esc_html( "Unknown HestaHub endpoint: $endpoint" ) );
		}
	}

	/**
	 * How long a copy is fresh: the body's ttl, but meta at most a day.
	 *
	 * @param string $endpoint The endpoint.
	 * @param int    $ttl      The body's ttl.
	 */
	private function soft_ttl( string $endpoint, int $ttl ): int {
		return 'meta' === $endpoint ? min( $ttl, self::META_MAX_AGE ) : $ttl;
	}

	/**
	 * The result of a failed or skipped fetch: the cached copy while there is one.
	 *
	 * @param array|null $cached      The cached copy.
	 * @param Failure    $failure     Why the fetch failed.
	 * @param int        $retry_after Seconds until the next fetch may be tried.
	 */
	private function failed( $cached, Failure $failure, int $retry_after ): Result {
		return $cached
			? Result::ok( $cached, true, $failure, $retry_after )
			: Result::failed( $failure, $retry_after );
	}

	/**
	 * Records a failure and returns how long to wait before the next fetch.
	 *
	 * @param string     $endpoint The endpoint.
	 * @param Failure    $failure  The failure.
	 * @param array|null $state    The endpoint's back-off before this failure.
	 * @param int        $now      The current time.
	 */
	private function back_off( string $endpoint, Failure $failure, $state, int $now ): int {
		if ( Failure::VERDICT === $failure->kind() ) {
			$outages = 0;
			$wait    = self::MAX_RETRY;
		} else {
			$outages = ( $state ? $state['outages'] : 0 ) + 1;
			$wait    = (int) min( self::FIRST_RETRY * pow( 2, min( $outages - 1, 16 ) ), self::MAX_RETRY );
		}

		$all              = $this->all_states();
		$all[ $endpoint ] = array(
			'failure'  => $failure->to_array(),
			'outages'  => $outages,
			'retry_at' => $now + $wait,
		);
		update_option( self::STATE_OPTION, $all, false );
		return $wait;
	}

	/**
	 * An endpoint's back-off, or null when its last fetch succeeded.
	 *
	 * @param string $endpoint The endpoint.
	 * @return array|null failure (Failure), outages and retry_at.
	 */
	private function state( string $endpoint ): ?array {
		$all = $this->all_states();
		if ( ! isset( $all[ $endpoint ] ) || ! is_array( $all[ $endpoint ] ) ) {
			return null;
		}
		$failure = Failure::from_array( isset( $all[ $endpoint ]['failure'] ) ? $all[ $endpoint ]['failure'] : null );
		if ( ! $failure ) {
			return null;
		}
		return array(
			'failure'  => $failure,
			'outages'  => (int) ( isset( $all[ $endpoint ]['outages'] ) ? $all[ $endpoint ]['outages'] : 0 ),
			'retry_at' => (int) ( isset( $all[ $endpoint ]['retry_at'] ) ? $all[ $endpoint ]['retry_at'] : 0 ),
		);
	}

	/**
	 * Forgets an endpoint's back-off.
	 *
	 * @param string $endpoint The endpoint.
	 */
	private function clear_state( string $endpoint ): void {
		$all = $this->all_states();
		if ( isset( $all[ $endpoint ] ) ) {
			unset( $all[ $endpoint ] );
			update_option( self::STATE_OPTION, $all, false );
		}
	}

	private function all_states(): array {
		$all = get_option( self::STATE_OPTION, array() );
		return is_array( $all ) ? $all : array();
	}

	/**
	 * The cached copy of an endpoint, unless it is past stale_ttl.
	 *
	 * @param string $endpoint The endpoint.
	 * @param int    $now      The current time.
	 */
	private function cached( string $endpoint, int $now ): ?array {
		$cached = get_transient( $this->cache_key( $endpoint ) );
		if ( ! is_array( $cached ) || ! isset( $cached['data'], $cached['ttl'], $cached['stale_ttl'], $cached['fetched_at'] ) ) {
			return null;
		}
		if ( $now >= $cached['fetched_at'] + $cached['stale_ttl'] ) {
			return null;
		}
		return $cached;
	}

	/**
	 * Requests an endpoint once and reads the envelope.
	 *
	 * @param string $endpoint The endpoint.
	 * @return array|Failure The success envelope's data, revision, currency,
	 *                       prices_include_tax, ttl and stale_ttl, or why it failed.
	 */
	private function fetch( string $endpoint ) {
		$response = wp_remote_get(
			'https://' . $this->credentials->tenant() . '.hestahub.com/api/v1/' . $endpoint,
			array(
				'headers'     => array(
					'Authorization' => 'Bearer ' . $this->credentials->key(),
					'Accept'        => 'application/json',
				),
				'user-agent'  => $this->user_agent(),
				'redirection' => 0,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new Failure( Failure::OUTAGE, 0, '', $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status > 299 ) {
			$error = is_array( $body ) && isset( $body['error'] ) && is_array( $body['error'] ) ? $body['error'] : array();
			return Failure::from_status(
				$status,
				isset( $error['code'] ) && is_string( $error['code'] ) ? $error['code'] : '',
				isset( $error['message'] ) && is_string( $error['message'] ) ? $error['message'] : ''
			);
		}

		if ( ! is_array( $body ) || ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
			// No success envelope: an outage, whatever the status said.
			return new Failure( Failure::OUTAGE, $status, '', '' );
		}

		$cache = isset( $body['cache'] ) && is_array( $body['cache'] ) ? $body['cache'] : array();
		return array(
			'data'               => $body['data'],
			'revision'           => isset( $body['revision'] ) && is_string( $body['revision'] ) ? $body['revision'] : null,
			'currency'           => isset( $body['currency'] ) && is_string( $body['currency'] ) ? $body['currency'] : null,
			'prices_include_tax' => isset( $body['prices_include_tax'] ) && is_bool( $body['prices_include_tax'] ) ? $body['prices_include_tax'] : null,
			'ttl'                => $this->seconds( $cache, 'ttl' ),
			'stale_ttl'          => $this->seconds( $cache, 'stale_ttl' ),
		);
	}

	/**
	 * A non-negative number of seconds from the body's cache object, 0 when missing.
	 *
	 * @param array  $cache The body's cache object.
	 * @param string $key   ttl or stale_ttl.
	 */
	private function seconds( array $cache, string $key ): int {
		return isset( $cache[ $key ] ) && is_int( $cache[ $key ] ) && $cache[ $key ] > 0 ? $cache[ $key ] : 0;
	}

	private function cache_key( string $endpoint ): string {
		return 'hestahub_cache_' . $endpoint;
	}

	private function now(): int {
		return (int) call_user_func( $this->clock );
	}

	private function user_agent(): string {
		return sprintf(
			'HestaHub-WP/%s (WordPress %s; PHP %d.%d.%d)',
			Plugin::VERSION,
			get_bloginfo( 'version' ),
			PHP_MAJOR_VERSION,
			PHP_MINOR_VERSION,
			PHP_RELEASE_VERSION
		);
	}
}
