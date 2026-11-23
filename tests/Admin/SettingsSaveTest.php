<?php
/**
 * @package HestaHub
 */

namespace HestaHub\Tests\Admin;

use HestaHub\Admin\Settings;
use HestaHub\Api\Client;
use HestaHub\Api\Credentials;
use WP_UnitTestCase;

/**
 * Saving the settings screen: what each field stores.
 */
final class SettingsSaveTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		putenv( 'HESTAHUB_API_KEY' );
		$GLOBALS['wp_settings_errors'] = array();
		$this->set_permalink_structure( '/%postname%/' );
		$credentials = new Credentials();
		( new Settings( new Client( $credentials ), $credentials ) )->register_settings();
	}

	public function tear_down() {
		putenv( 'HESTAHUB_API_KEY' );
		parent::tear_down();
	}

	/**
	 * @dataProvider base_paths
	 */
	public function test_a_base_path_is_stored_without_slashes_and_as_url_segments( $input, $stored ) {
		update_option( 'hestahub_apartments_base', $input );

		$this->assertSame( $stored, get_option( 'hestahub_apartments_base' ) );
	}

	public function base_paths() {
		return array(
			'plain'          => array( 'ferienwohnungen', 'ferienwohnungen' ),
			'slashes'        => array( '/ferienwohnungen/', 'ferienwohnungen' ),
			'nested'         => array( 'urlaub/wohnungen', 'urlaub/wohnungen' ),
			'spaces, case'   => array( ' Unsere Kurse ', 'unsere-kurse' ),
			'empty segments' => array( 'urlaub//wohnungen/', 'urlaub/wohnungen' ),
			'empty'          => array( '', '' ),
		);
	}

	public function test_base_paths_ship_empty() {
		$this->assertSame( '', get_option( 'hestahub_apartments_base' ) );
		$this->assertSame( '', get_option( 'hestahub_clinics_base' ) );
	}

	public function test_while_permalinks_are_plain_a_base_path_is_refused_with_a_reason() {
		$this->set_permalink_structure( '' );

		update_option( 'hestahub_clinics_base', 'kurse' );

		$this->assertSame( '', get_option( 'hestahub_clinics_base' ) );
		$this->assertSame( array( 'hestahub_clinics_base' ), wp_list_pluck( get_settings_errors(), 'setting' ) );
	}

	public function test_while_permalinks_are_plain_a_base_path_can_still_be_cleared() {
		update_option( 'hestahub_clinics_base', 'kurse' );
		$this->set_permalink_structure( '' );

		update_option( 'hestahub_clinics_base', '' );

		$this->assertSame( '', get_option( 'hestahub_clinics_base' ) );
		$this->assertSame( array(), get_settings_errors() );
	}

	public function test_the_two_base_paths_may_not_be_the_same() {
		update_option( 'hestahub_apartments_base', 'angebote' );

		update_option( 'hestahub_clinics_base', '/angebote/' );

		$this->assertSame( '', get_option( 'hestahub_clinics_base' ) );
		$this->assertCount( 1, get_settings_errors() );
	}

	public function test_a_field_missing_from_the_form_keeps_its_value() {
		// options.php saves null for a registered field the form did not
		// show: a base path whose resource meta does not list right now, or
		// a key read from the environment.
		update_option( 'hestahub_apartments_base', 'wohnungen' );
		update_option( 'hestahub_tenant', 'kronshof' );
		update_option( 'hestahub_api_key', 'abc123' );

		update_option( 'hestahub_apartments_base', null );
		update_option( 'hestahub_tenant', null );
		update_option( 'hestahub_api_key', null );

		$this->assertSame( 'wohnungen', get_option( 'hestahub_apartments_base' ) );
		$this->assertSame( 'kronshof', get_option( 'hestahub_tenant' ) );
		$this->assertSame( 'abc123', get_option( 'hestahub_api_key' ) );
	}

	/**
	 * @dataProvider addresses
	 */
	public function test_the_hestahub_address_is_stored_as_the_subdomain( $input ) {
		update_option( 'hestahub_tenant', $input );

		$this->assertSame( 'kronshof', get_option( 'hestahub_tenant' ) );
	}

	public function addresses() {
		return array(
			'subdomain'  => array( 'kronshof' ),
			'host'       => array( 'kronshof.hestahub.com' ),
			'url'        => array( 'https://kronshof.hestahub.com/' ),
			'case/space' => array( ' Kronshof ' ),
		);
	}

	public function test_an_invalid_address_is_refused_with_a_reason() {
		update_option( 'hestahub_tenant', 'kronshof' );

		update_option( 'hestahub_tenant', 'kron_shof!' );

		$this->assertSame( 'kronshof', get_option( 'hestahub_tenant' ) );
		$this->assertSame( array( 'hestahub_tenant' ), wp_list_pluck( get_settings_errors(), 'setting' ) );
	}

	public function test_the_address_can_be_cleared() {
		update_option( 'hestahub_tenant', 'kronshof' );

		update_option( 'hestahub_tenant', '' );

		$this->assertSame( '', get_option( 'hestahub_tenant' ) );
	}

	public function test_a_new_key_is_stored_as_text() {
		update_option( 'hestahub_api_key', " 0a1b2c3d\n" );

		$this->assertSame( '0a1b2c3d', get_option( 'hestahub_api_key' ) );
	}

	public function test_a_blank_key_field_keeps_the_stored_key() {
		// The field never shows the key, so submitting it blank means "unchanged".
		update_option( 'hestahub_api_key', '0a1b2c3d' );

		update_option( 'hestahub_api_key', '' );

		$this->assertSame( '0a1b2c3d', get_option( 'hestahub_api_key' ) );
	}

	public function test_a_key_from_the_environment_is_never_overwritten_by_the_form() {
		putenv( 'HESTAHUB_API_KEY=fromenv1' );

		update_option( 'hestahub_api_key', 'typed999' );

		$this->assertSame( '', get_option( 'hestahub_api_key' ) );
	}
}
