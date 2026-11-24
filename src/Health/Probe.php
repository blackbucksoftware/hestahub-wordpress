<?php
/**
 * @package HestaHub
 */

namespace HestaHub\Health;

use HestaHub\Api\Client;
use HestaHub\Routing\Routes;

/**
 * Checks once, on request, whether the host or a page cache rewrites the
 * plugin's 503: it asks this site for one of its owned URLs with a
 * one-time token, which makes that one request answer the real 503 with a
 * marker in its body, and looks at what comes back. Contract:
 * https://docs.hestahub.com/v1/#telling-someone
 *
 * Run only from "test connection", never on a schedule.
 */
final class Probe {

	const PARAM = 'hestahub_probe';
	const TOKEN = 'hestahub_probe';

	const OK          = 'ok';
	const REWRITTEN   = 'rewritten';
	const UNREACHABLE = 'unreachable';
	const NO_ROUTE    = 'no_route';

	/**
	 * Requests an owned URL in forced-unavailable mode.
	 *
	 * @return array result (ok, rewritten, unreachable, no_route), status
	 *               (the HTTP status that came back, 0 if none), message.
	 */
	public function run(): array {
		$url = null;
		foreach ( Client::RESOURCES as $resource ) {
			$url = Routes::url( $resource );
			if ( null !== $url ) {
				break;
			}
		}
		if ( null === $url ) {
			return self::outcome( self::NO_ROUTE, 0, '' );
		}

		$token = wp_generate_password( 32, false );
		set_transient( self::TOKEN, $token, MINUTE_IN_SECONDS );
		$response = wp_remote_get(
			add_query_arg( self::PARAM, $token, $url ),
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's hook, as for loopback requests.
			)
		);
		delete_transient( self::TOKEN );

		if ( is_wp_error( $response ) ) {
			return self::outcome( self::UNREACHABLE, 0, $response->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$intact = 503 === $status && false !== strpos( (string) wp_remote_retrieve_body( $response ), self::marker( $token ) );
		return self::outcome( $intact ? self::OK : self::REWRITTEN, $status, '' );
	}

	/**
	 * The token of the probe this request is, or null when it is none.
	 */
	public static function requested(): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the token is the check.
		$given = isset( $_GET[ self::PARAM ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::PARAM ] ) ) : '';
		$token = get_transient( self::TOKEN );
		return '' !== $given && is_string( $token ) && '' !== $token && hash_equals( $token, $given ) ? $token : null;
	}

	/**
	 * What a probed 503 carries in its body.
	 *
	 * @param string $token The probe's token.
	 */
	public static function marker( string $token ): string {
		return '<!-- hestahub-probe:' . $token . ' -->';
	}

	/**
	 * @param string $result  The result.
	 * @param int    $status  The status that came back.
	 * @param string $message Why the request failed.
	 */
	private static function outcome( string $result, int $status, string $message ): array {
		return array(
			'result'  => $result,
			'status'  => $status,
			'message' => $message,
		);
	}
}
