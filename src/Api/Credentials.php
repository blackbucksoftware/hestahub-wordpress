<?php
/**
 * @package HestaHub
 */

namespace HestaHub\Api;

/**
 * Where the plugin finds the Tenant and its API key.
 *
 * The key comes from the HESTAHUB_API_KEY environment variable, then the
 * HESTAHUB_API_KEY constant, then the hestahub_api_key option, and is used
 * exactly as found. The Tenant is the subdomain in the hestahub_tenant option.
 */
final class Credentials {

	const SOURCE_ENVIRONMENT = 'environment';
	const SOURCE_CONSTANT    = 'constant';
	const SOURCE_OPTION      = 'option';
	const SOURCE_NONE        = 'none';

	const TENANT_OPTION = 'hestahub_tenant';
	const KEY_OPTION    = 'hestahub_api_key';

	/**
	 * The API key, or '' when there is none.
	 */
	public function key(): string {
		$found = $this->find_key();
		return $found[1];
	}

	/**
	 * Which source the key is read from: one of the SOURCE_* constants.
	 */
	public function key_source(): string {
		$found = $this->find_key();
		return $found[0];
	}

	/**
	 * Whether the key is set outside WordPress (environment or constant), so
	 * the settings screen can neither show nor change it.
	 */
	public function is_key_external(): bool {
		return in_array( $this->key_source(), array( self::SOURCE_ENVIRONMENT, self::SOURCE_CONSTANT ), true );
	}

	/**
	 * The Tenant's subdomain, or '' when it is unset or not a valid subdomain.
	 */
	public function tenant(): string {
		$tenant = get_option( self::TENANT_OPTION, '' );
		if ( ! is_string( $tenant ) || ! preg_match( '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $tenant ) ) {
			return '';
		}
		return $tenant;
	}

	/**
	 * Whether there is both a Tenant and a key to call it with.
	 */
	public function is_complete(): bool {
		return '' !== $this->tenant() && '' !== $this->key();
	}

	/**
	 * @return string[] Source and key.
	 */
	private function find_key(): array {
		$env = getenv( 'HESTAHUB_API_KEY' );
		if ( is_string( $env ) && '' !== $env ) {
			return array( self::SOURCE_ENVIRONMENT, $env );
		}
		if ( defined( 'HESTAHUB_API_KEY' ) && is_string( HESTAHUB_API_KEY ) && '' !== HESTAHUB_API_KEY ) {
			return array( self::SOURCE_CONSTANT, HESTAHUB_API_KEY );
		}
		$option = get_option( self::KEY_OPTION, '' );
		if ( is_string( $option ) && '' !== $option ) {
			return array( self::SOURCE_OPTION, $option );
		}
		return array( self::SOURCE_NONE, '' );
	}
}
