<?php
/**
 * @package HestaHub
 */

namespace HestaHub\Api;

/**
 * What a read from HestaHub produced: a copy to render, a failure, or both
 * (a stale copy served because the refetch failed or is waiting).
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

	/**
	 * @param array|null   $copy        A copy (see ok()), or null when there is none.
	 * @param bool         $stale       Whether the copy is past its ttl.
	 * @param Failure|null $failure     Why the last fetch failed, if it did.
	 * @param int          $retry_after Seconds until the next fetch may be tried.
	 */
	private function __construct( $copy, bool $stale, $failure, int $retry_after ) {
		$this->copy        = $copy;
		$this->stale       = $stale;
		$this->failure     = $failure;
		$this->retry_after = $retry_after;
	}

	/**
	 * A copy to render.
	 *
	 * @param array        $copy        data, revision, currency, prices_include_tax and fetched_at.
	 * @param bool         $stale       Whether the copy is past its ttl.
	 * @param Failure|null $failure     Why the refetch failed, when a stale copy is served.
	 * @param int          $retry_after Seconds until the next fetch may be tried, when it failed.
	 */
	public static function ok( array $copy, bool $stale = false, $failure = null, int $retry_after = 0 ): self {
		return new self( $copy, $stale, $failure, $retry_after );
	}

	/**
	 * Nothing to render.
	 *
	 * @param Failure $failure     Why.
	 * @param int     $retry_after Seconds until the next fetch may be tried.
	 */
	public static function failed( Failure $failure, int $retry_after ): self {
		return new self( null, false, $failure, $retry_after );
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
		return $this->copy ? $this->copy['data'] : array();
	}

	/**
	 * The Revision that answered, or null when unknown.
	 */
	public function revision(): ?string {
		return $this->copy ? $this->copy['revision'] : null;
	}

	/**
	 * The envelope's ISO 4217 currency, or null when the body has none.
	 */
	public function currency(): ?string {
		return $this->copy ? $this->copy['currency'] : null;
	}

	/**
	 * Whether the body's amounts include VAT, or null when the body does not say.
	 */
	public function prices_include_tax(): ?bool {
		return $this->copy ? $this->copy['prices_include_tax'] : null;
	}

	/**
	 * When the copy was fetched (Unix time), or null when there is none.
	 */
	public function fetched_at(): ?int {
		return $this->copy ? $this->copy['fetched_at'] : null;
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
	 * Seconds until the next fetch may be tried after a failure; 0 when the
	 * last fetch did not fail. A 503's Retry-After.
	 */
	public function retry_after(): int {
		return $this->retry_after;
	}
}
