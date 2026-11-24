<?php
/**
 * @package HestaHub
 */

namespace HestaHub\Api;

/**
 * What the plugin remembers about fetching one endpoint for one Tenant:
 * when it last succeeded, since when it has been failing, the last failure,
 * and the back-off. Kept in its own option, hestahub_api_state_<endpoint>,
 * so endpoints never overwrite each other. A Tenant change starts afresh.
 */
final class EndpointState {

	/**
	 * Back-off: outages wait one minute, doubling up to the cap; verdicts wait
	 * the cap. The contract sets the cap; the first step is the plugin's.
	 */
	const FIRST_RETRY = 60;
	const MAX_RETRY   = 3600;

	/** @var string */
	private $endpoint;

	/** @var string */
	private $tenant;

	/** @var int|null */
	private $last_success;

	/** @var int|null */
	private $failing_since;

	/** @var int|null */
	private $stale_until;

	/** @var Failure|null */
	private $failure;

	/** @var int */
	private $outages;

	/** @var int */
	private $retry_at;

	private function __construct( string $endpoint, string $tenant ) {
		$this->endpoint = $endpoint;
		$this->tenant   = $tenant;
		$this->reset();
	}

	/**
	 * The stored state of an endpoint for a Tenant; a fresh one when there is
	 * none, or when it belongs to another Tenant.
	 *
	 * @param string $endpoint The endpoint.
	 * @param string $tenant   The Tenant's subdomain.
	 */
	public static function load( string $endpoint, string $tenant ): self {
		$state  = new self( $endpoint, $tenant );
		$stored = get_option( self::option( $endpoint ), array() );
		if ( ! is_array( $stored ) || ! isset( $stored['tenant'] ) || $stored['tenant'] !== $tenant ) {
			return $state;
		}
		$state->last_success  = self::int_or_null( $stored, 'last_success' );
		$state->failing_since = self::int_or_null( $stored, 'failing_since' );
		$state->stale_until   = self::int_or_null( $stored, 'stale_until' );
		$state->failure       = Failure::from_array( isset( $stored['failure'] ) ? $stored['failure'] : null );
		$state->outages       = (int) self::int_or_null( $stored, 'outages' );
		$state->retry_at      = (int) self::int_or_null( $stored, 'retry_at' );
		return $state;
	}

	/**
	 * Records a successful fetch.
	 *
	 * @param int $now       The current time.
	 * @param int $stale_ttl How long the body may be kept: its stale_ttl.
	 */
	public function succeeded( int $now, int $stale_ttl ): void {
		$this->reset();
		$this->last_success = $now;
		$this->stale_until  = $now + $stale_ttl;
		$this->save();
	}

	/**
	 * Records that the copy was discarded before its stale_ttl. For "refresh now".
	 *
	 * @param int $now The current time.
	 */
	public function copy_dropped( int $now ): void {
		if ( null !== $this->stale_until && $this->stale_until > $now ) {
			$this->stale_until = $now;
			$this->save();
		}
	}

	/**
	 * Records a failed fetch and returns how long to wait before the next.
	 *
	 * @param Failure $failure The failure.
	 * @param int     $now     The current time.
	 */
	public function failed( Failure $failure, int $now ): int {
		if ( Failure::VERDICT === $failure->kind() ) {
			$this->outages = 0;
			$wait          = self::MAX_RETRY;
		} else {
			++$this->outages;
			$wait = (int) min( self::FIRST_RETRY * pow( 2, min( $this->outages - 1, 16 ) ), self::MAX_RETRY );
		}
		if ( null === $this->failing_since ) {
			$this->failing_since = $now;
		}
		$this->failure  = $failure;
		$this->retry_at = $now + $wait;
		$this->save();
		return $wait;
	}

	/**
	 * Lets the next read fetch at once. The clocks (last success, failing
	 * since) keep running. For "refresh now".
	 */
	public function retry_now(): void {
		if ( $this->retry_at ) {
			$this->retry_at = 0;
			$this->save();
		}
	}

	/**
	 * Seconds left to wait before the next fetch; 0 when it may happen now.
	 *
	 * @param int $now The current time.
	 */
	public function wait( int $now ): int {
		return $this->failure ? max( 0, $this->retry_at - $now ) : 0;
	}

	/**
	 * The last failure while the endpoint is failing, else null.
	 */
	public function failure(): ?Failure {
		return $this->failure;
	}

	/**
	 * When the endpoint last fetched successfully, or null if never.
	 */
	public function last_success(): ?int {
		return $this->last_success;
	}

	/**
	 * When the last copy stopped being servable: past its stale_ttl, or
	 * discarded. Null if never fetched.
	 */
	public function stale_until(): ?int {
		return $this->stale_until;
	}

	/**
	 * When the current run of failures began, or null when not failing.
	 */
	public function failing_since(): ?int {
		return $this->failing_since;
	}

	/**
	 * The option an endpoint's state is kept in.
	 *
	 * @param string $endpoint The endpoint.
	 */
	public static function option( string $endpoint ): string {
		return 'hestahub_api_state_' . $endpoint;
	}

	private function reset(): void {
		$this->last_success  = null;
		$this->failing_since = null;
		$this->stale_until   = null;
		$this->failure       = null;
		$this->outages       = 0;
		$this->retry_at      = 0;
	}

	private function save(): void {
		update_option(
			self::option( $this->endpoint ),
			array(
				'tenant'        => $this->tenant,
				'last_success'  => $this->last_success,
				'failing_since' => $this->failing_since,
				'stale_until'   => $this->stale_until,
				'failure'       => $this->failure ? $this->failure->to_array() : null,
				'outages'       => $this->outages,
				'retry_at'      => $this->retry_at,
			),
			false
		);
	}

	/**
	 * @param array  $stored The stored state.
	 * @param string $key    A key in it.
	 */
	private static function int_or_null( array $stored, string $key ): ?int {
		return isset( $stored[ $key ] ) && is_int( $stored[ $key ] ) ? $stored[ $key ] : null;
	}
}
