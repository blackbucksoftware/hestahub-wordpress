<?php
/**
 * @package HestaHub
 */

namespace HestaHub\Api;

/**
 * What a read from HestaHub produced: a copy to render, a failure, or both
 * (a stale copy served because the refetch failed or is waiting), plus the
 * clocks the render ladder and escalation count from.
 */
final class Result {

	/** @var array|null */
	private $copy;

	/** @var bool */
	private $stale;

	/** @var Failure|null */
	private $failure;

	/** @var int */
	private $retry_after;

	/** @var int|null */
	private $last_success;

	/** @var int|null */
	private $failing_since;

	/** @var bool */
	private $unavailable;

	/**
	 * @param array $parts copy (data, revision, currency, prices_include_tax,
	 *                     fetched_at) or null; stale; failure (Failure|null);
	 *                     retry_after; last_success; failing_since;
	 *                     unavailable.
	 */
	public function __construct( array $parts ) {
		$this->copy          = isset( $parts['copy'] ) ? $parts['copy'] : null;
		$this->stale         = ! empty( $parts['stale'] );
		$this->failure       = isset( $parts['failure'] ) ? $parts['failure'] : null;
		$this->retry_after   = isset( $parts['retry_after'] ) ? (int) $parts['retry_after'] : 0;
		$this->last_success  = isset( $parts['last_success'] ) ? (int) $parts['last_success'] : null;
		$this->failing_since = isset( $parts['failing_since'] ) ? (int) $parts['failing_since'] : null;
		$this->unavailable   = ! empty( $parts['unavailable'] );
	}

	/**
	 * Whether there is a copy to render.
	 */
	public function is_ok(): bool {
		return null !== $this->copy;
	}

	/**
	 * The envelope's data, exactly as received; an empty array when not ok.
	 */
	public function data(): array {
		return $this->field( 'data', array() );
	}

	/**
	 * The Revision that answered, or null when unknown.
	 */
	public function revision(): ?string {
		return $this->field( 'revision' );
	}

	/**
	 * The envelope's ISO 4217 currency, or null when the body has none.
	 */
	public function currency(): ?string {
		return $this->field( 'currency' );
	}

	/**
	 * Whether the body's amounts include VAT, or null when the body does not say.
	 */
	public function prices_include_tax(): ?bool {
		return $this->field( 'prices_include_tax' );
	}

	/**
	 * When this copy was fetched (Unix time), or null when there is none.
	 * The calendar's "Stand:" line.
	 */
	public function fetched_at(): ?int {
		return $this->field( 'fetched_at' );
	}

	/**
	 * Whether the copy is past its ttl and a refetch has not yet succeeded.
	 */
	public function is_stale(): bool {
		return $this->stale;
	}

	/**
	 * Why the last fetch failed, or null when it did not.
	 */
	public function failure(): ?Failure {
		return $this->failure;
	}

	/**
	 * Seconds until the plugin will next try to fetch, after a failure; 0
	 * when the last fetch did not fail. What a 503 sends as its Retry-After.
	 */
	public function retry_after(): int {
		return $this->retry_after;
	}

	/**
	 * When the endpoint last fetched successfully for this Tenant (Unix time),
	 * even after that copy has expired; null if never. The render ladder
	 * counts from it, and escalation never emails a plugin that never fetched.
	 */
	public function last_success(): ?int {
		return $this->last_success;
	}

	/**
	 * When the current run of failures began (Unix time), or null when the
	 * last fetch did not fail. Escalation counts "24 continuous hours" from it.
	 */
	public function failing_since(): ?int {
		return $this->failing_since;
	}

	/**
	 * Whether there is no copy and the render ladder is on its 503 step:
	 * failing, and within 24 hours of losing the last copy (or, never having
	 * fetched, of the first failure). Past that, a placeholder answers 200.
	 */
	public function is_temporarily_unavailable(): bool {
		return $this->unavailable;
	}

	/**
	 * @param string $key     A key of the copy.
	 * @param mixed  $default What to return without a copy.
	 * @return mixed
	 */
	private function field( string $key, $default = null ) {
		return null === $this->copy ? $default : $this->copy[ $key ];
	}
}
