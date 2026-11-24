<?php
/**
 * @package HestaHub
 */

namespace HestaHub\Api;

use HestaHub\Plugin;

/**
 * The one place that talks to HestaHub. Contract: https://docs.hestahub.com/v1/
 *
 * Reads go through get(): the cached copy while it is fresh (ttl), otherwise
 * a fetch; when the fetch fails, the cached copy until stale_ttl, whatever the
 * failure; then a back-off before the next try. TTLs come only from the body.
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
	 * The contract: "A client MUST check meta at least once every 24 hours",
	 * so a meta copy is fresh for its ttl but never longer than this.
	 */
	const META_MAX_AGE = 86400;

	/**
	 * The contract: with no copy to serve, answer 503 "for at most 24 hours",
	 * then a placeholder.
	 */
	const UNAVAILABLE_FOR = 86400;

	/**
	 * Every slug a successful response of a resource ever listed, for the
	 * Tenant: hestahub_listed_<resource>. The contract: during an outage, a
	 * slug the plugin has never resolved answers 404.
	 */
	const LISTED_PREFIX = 'hestahub_listed_';

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
	 * fetch. When the fetch fails or must wait, the cached copy is served
	 * until stale_ttl.
	 *
	 * @param string $endpoint meta, apartments, availability or clinics.
	 */
	public function get( string $endpoint ): Result {
		$this->check_endpoint( $endpoint );
		$now    = $this->now();
		$tenant = $this->credentials->tenant();
		$state  = EndpointState::load( $endpoint, $tenant );
		$cached = $this->cached( $endpoint, $tenant, $now );

		if ( $cached && $now < $cached['fetched_at'] + $this->soft_ttl( $endpoint, $cached['ttl'] ) ) {
			return $this->result( $cached, false, $state, 0, $now );
		}

		if ( ! $this->credentials->is_complete() ) {
			return new Result(
				array(
					'copy'    => $cached,
					'stale'   => (bool) $cached,
					'failure' => new Failure( Failure::NOT_CONFIGURED, 0, '', '' ),
				)
			);
		}

		$wait = $state->wait( $now );
		if ( $wait > 0 ) {
			return $this->result( $cached, true, $state, $wait, $now );
		}

		$fetched = $this->fetch( $endpoint );
		if ( $fetched instanceof Failure ) {
			$wait = $state->failed( $fetched, $now );
			return $this->result( $cached, true, $state, $wait, $now );
		}

		$state->succeeded( $now, $fetched['stale_ttl'] );
		if ( in_array( $endpoint, self::RESOURCES, true ) ) {
			$this->remember_slugs( $endpoint, $tenant, $fetched['data'] );
		}
		$fetched['fetched_at'] = $now;
		$fetched['tenant']     = $tenant;
		if ( $fetched['stale_ttl'] > 0 ) {
			// An option, not a transient: a persistent object cache may evict a
			// transient early, and the contract says to keep a body until
			// stale_ttl. cached() enforces the expiry.
			update_option( $this->cache_key( $endpoint ), $fetched, false );
		} else {
			// The body says how long to keep it. Without that, keep nothing.
			delete_option( $this->cache_key( $endpoint ) );
		}
		return $this->result( $fetched, false, $state, 0, $now );
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
			return new Result( array( 'failure' => new Failure( Failure::NOT_CONFIGURED, 0, '', '' ) ) );
		}

		$fetched = $this->fetch( $endpoint );
		if ( $fetched instanceof Failure ) {
			return new Result( array( 'failure' => $fetched ) );
		}
		$fetched['fetched_at'] = $this->now();
		return new Result( array( 'copy' => $fetched ) );
	}

	/**
	 * The data of the copy the plugin holds for an endpoint, fresh or stale,
	 * without fetching. Null when it holds none. For the settings screen.
	 *
	 * @param string $endpoint meta, apartments, availability or clinics.
	 */
	public function held( string $endpoint ): ?array {
		$this->check_endpoint( $endpoint );
		$copy = $this->cached( $endpoint, $this->credentials->tenant(), $this->now() );
		return $copy ? $copy['data'] : null;
	}

	/**
	 * Whether a successful response of a resource, for this Tenant, ever
	 * listed this slug.
	 *
	 * @param string $resource apartments or clinics.
	 * @param string $slug     The slug.
	 */
	public function ever_listed( string $resource, string $slug ): bool {
		return isset( $this->listed_slugs( $resource, $this->credentials->tenant() )[ $slug ] );
	}

	/**
	 * Discards every cached copy and lets the next read of every endpoint
	 * fetch at once. For "refresh now". It also discards the stale copy that
	 * may be keeping the site up.
	 */
	public function flush(): void {
		$tenant = $this->credentials->tenant();
		foreach ( self::ENDPOINTS as $endpoint ) {
			delete_option( $this->cache_key( $endpoint ) );
			$state = EndpointState::load( $endpoint, $tenant );
			$state->copy_dropped( $this->now() );
			$state->retry_now();
		}
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
		return $meta->is_ok() ? $this->listed_resources( $meta->data() ) : null;
	}

	/**
	 * Like resources(), but from the meta copy the plugin holds, without
	 * fetching. Null when it holds none.
	 *
	 * @return string[]|null
	 */
	public function held_resources(): ?array {
		$meta = $this->held( 'meta' );
		return null === $meta ? null : $this->listed_resources( $meta );
	}

	/**
	 * The resources a meta body lists that this plugin serves, in order,
	 * each once.
	 *
	 * @param array $meta meta's data.
	 * @return string[]
	 */
	private function listed_resources( array $meta ): array {
		$resources = array();
		if ( isset( $meta['resources'] ) && is_array( $meta['resources'] ) ) {
			foreach ( $meta['resources'] as $resource ) {
				if ( in_array( $resource, self::RESOURCES, true ) && ! in_array( $resource, $resources, true ) ) {
					$resources[] = $resource;
				}
			}
		}
		return $resources;
	}

	/**
	 * Adds the slugs a response lists to those ever listed.
	 *
	 * @param string $resource apartments or clinics.
	 * @param string $tenant   The Tenant's subdomain.
	 * @param array  $data     The response's data.
	 */
	private function remember_slugs( string $resource, string $tenant, array $data ): void {
		$known = $this->listed_slugs( $resource, $tenant );
		$all   = $known;
		foreach ( $data as $item ) {
			if ( is_array( $item ) && isset( $item['slug'] ) && is_string( $item['slug'] ) ) {
				$all[ $item['slug'] ] = true;
			}
		}
		if ( count( $all ) !== count( $known ) ) {
			update_option(
				self::LISTED_PREFIX . $resource,
				array(
					'tenant' => $tenant,
					'slugs'  => $all,
				),
				false
			);
		}
	}

	/**
	 * @param string $resource apartments or clinics.
	 * @param string $tenant   The Tenant's subdomain.
	 * @return array<string, true>
	 */
	private function listed_slugs( string $resource, string $tenant ): array {
		$stored = get_option( self::LISTED_PREFIX . $resource );
		if ( ! is_array( $stored ) || ! isset( $stored['tenant'], $stored['slugs'] ) || $stored['tenant'] !== $tenant || ! is_array( $stored['slugs'] ) ) {
			return array();
		}
		return $stored['slugs'];
	}

	/**
	 * @param array|null    $copy  The copy to serve, if any.
	 * @param bool          $stale Whether a served copy is past its ttl.
	 * @param EndpointState $state The endpoint's state.
	 * @param int           $wait  Seconds until the next fetch may be tried.
	 * @param int           $now   The current time.
	 */
	private function result( $copy, bool $stale, EndpointState $state, int $wait, int $now ): Result {
		return new Result(
			array(
				'copy'          => $copy,
				'stale'         => $copy && $stale,
				'failure'       => $state->failure(),
				'retry_after'   => $wait,
				'last_success'  => $state->last_success(),
				'failing_since' => $state->failing_since(),
				'unavailable'   => ! $copy && $this->is_unavailable( $state, $now ),
			)
		);
	}

	/**
	 * Whether a failing endpoint with no copy is still within the 24 hours of
	 * 503, counted from when its last copy stopped being servable, or for an
	 * install that never fetched, from its first failure.
	 *
	 * @param EndpointState $state The endpoint's state.
	 * @param int           $now   The current time.
	 */
	private function is_unavailable( EndpointState $state, int $now ): bool {
		if ( ! $state->failure() ) {
			return false;
		}
		$since = null !== $state->stale_until() ? $state->stale_until() : $state->failing_since();
		return null !== $since && $now < $since + self::UNAVAILABLE_FOR;
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
	 * The cached copy of an endpoint, unless it is past stale_ttl or was
	 * fetched for another Tenant.
	 *
	 * @param string $endpoint The endpoint.
	 * @param string $tenant   The Tenant's subdomain.
	 * @param int    $now      The current time.
	 */
	private function cached( string $endpoint, string $tenant, int $now ): ?array {
		$cached = get_option( $this->cache_key( $endpoint ) );
		if ( ! is_array( $cached ) || ! isset( $cached['data'], $cached['ttl'], $cached['stale_ttl'], $cached['fetched_at'], $cached['tenant'] ) ) {
			return null;
		}
		if ( $cached['tenant'] !== $tenant || $now >= $cached['fetched_at'] + $cached['stale_ttl'] ) {
			return null;
		}
		return $cached;
	}

	/**
	 * Requests an endpoint once and reads the envelope field by field.
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
				(string) self::typed( $error, 'code', 'is_string' ),
				(string) self::typed( $error, 'message', 'is_string' )
			);
		}

		if ( ! is_array( $body ) || ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
			// No success envelope: an outage, whatever the status said.
			return new Failure( Failure::OUTAGE, $status, '', '' );
		}

		$cache = self::typed( $body, 'cache', 'is_array' );
		return array(
			'data'               => $body['data'],
			'revision'           => self::typed( $body, 'revision', 'is_string' ),
			'currency'           => self::typed( $body, 'currency', 'is_string' ),
			'prices_include_tax' => self::typed( $body, 'prices_include_tax', 'is_bool' ),
			'ttl'                => self::seconds( $cache, 'ttl' ),
			'stale_ttl'          => self::seconds( $cache, 'stale_ttl' ),
		);
	}

	/**
	 * A field of a decoded object if it has the expected type, else null.
	 *
	 * @param mixed    $object A decoded JSON object (array), or anything else.
	 * @param string   $key    The field.
	 * @param callable $is     Type check, e.g. is_string.
	 * @return mixed
	 */
	private static function typed( $object, string $key, callable $is ) {
		return is_array( $object ) && isset( $object[ $key ] ) && $is( $object[ $key ] ) ? $object[ $key ] : null;
	}

	/**
	 * A positive number of seconds from the body's cache object, 0 when missing.
	 *
	 * @param array|null $cache The body's cache object.
	 * @param string     $key   ttl or stale_ttl.
	 */
	private static function seconds( $cache, string $key ): int {
		$seconds = self::typed( $cache, $key, 'is_int' );
		return null !== $seconds && $seconds > 0 ? $seconds : 0;
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
