<?php
/**
 * @package HestaHub
 */

namespace HestaHub\Api;

/**
 * Why a fetch from HestaHub failed.
 *
 * A verdict (401, 404, 410) will not change by retrying; an outage (any other
 * failure: 5xx, a timeout, a connection error, an unparseable body, an
 * unexpected status) may. Without a Tenant and key, no request is made at all.
 */
final class Failure {

	const VERDICT        = 'verdict';
	const OUTAGE         = 'outage';
	const NOT_CONFIGURED = 'not_configured';

	/** @var string */
	private $kind;

	/** @var int */
	private $status;

	/** @var string */
	private $code;

	/** @var string */
	private $message;

	/**
	 * @param string $kind    VERDICT, OUTAGE or NOT_CONFIGURED.
	 * @param int    $status  HTTP status, 0 when no response arrived.
	 * @param string $code    The error envelope's code, '' when there was none.
	 * @param string $message The error envelope's message or the transport error, '' when there was none.
	 */
	public function __construct( string $kind, int $status, string $code, string $message ) {
		$this->kind    = $kind;
		$this->status  = $status;
		$this->code    = $code;
		$this->message = $message;
	}

	/**
	 * Classifies a response by its HTTP status, as the contract requires.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $code    The error envelope's code.
	 * @param string $message The error envelope's message.
	 */
	public static function from_status( int $status, string $code, string $message ): self {
		$kind = in_array( $status, array( 401, 404, 410 ), true ) ? self::VERDICT : self::OUTAGE;
		return new self( $kind, $status, $code, $message );
	}

	/**
	 * VERDICT, OUTAGE or NOT_CONFIGURED.
	 */
	public function kind(): string {
		return $this->kind;
	}

	/**
	 * HTTP status, 0 when no response arrived.
	 */
	public function status(): int {
		return $this->status;
	}

	/**
	 * The error envelope's code. For an administrator, never a visitor.
	 */
	public function code(): string {
		return $this->code;
	}

	/**
	 * The error envelope's message, or the transport error. For an administrator, never a visitor.
	 */
	public function message(): string {
		return $this->message;
	}

	/**
	 * Whether another failure is the same diagnosis: any two outages are;
	 * verdicts are when status and code agree.
	 *
	 * @param Failure $other Another failure.
	 */
	public function same_diagnosis( Failure $other ): bool {
		if ( $this->kind !== $other->kind ) {
			return false;
		}
		return self::VERDICT !== $this->kind || ( $this->status === $other->status && $this->code === $other->code );
	}

	/**
	 * For storage.
	 *
	 * @return array kind, status, code, message.
	 */
	public function to_array(): array {
		return array( $this->kind, $this->status, $this->code, $this->message );
	}

	/**
	 * From storage.
	 *
	 * @param mixed $stored What to_array() returned.
	 */
	public static function from_array( $stored ): ?self {
		if ( ! is_array( $stored ) || 4 !== count( $stored ) ) {
			return null;
		}
		list( $kind, $status, $code, $message ) = $stored;
		return new self( (string) $kind, (int) $status, (string) $code, (string) $message );
	}
}
