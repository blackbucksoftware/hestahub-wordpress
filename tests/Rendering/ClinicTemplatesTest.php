<?php
/**
 * @package HestaHub
 */

namespace HestaHub\Tests\Rendering;

use DOMDocument;
use DOMXPath;
use HestaHub\Tests\Support\FakeHestaHub;
use WP_UnitTestCase;

/**
 * The plugin's Clinic listing and Clinic page, rendered from the vendored
 * example. Contract: https://docs.hestahub.com/v1/#get-apiv1clinics
 */
final class ClinicTemplatesTest extends WP_UnitTestCase {

	use FakeHestaHub;

	const SLUG = 'dressurkurs-2027-03-14';

	public function set_up() {
		parent::set_up();
		putenv( 'HESTAHUB_API_KEY' );
		update_option( 'hestahub_tenant', 'kronshof' );
		update_option( 'hestahub_api_key', 'k3y' );
		update_option( 'hestahub_clinics_base', 'kurse' );
		update_option( 'date_format', 'd.m.Y' );
		update_option( 'time_format', 'H:i' );
		update_option( 'timezone_string', 'America/New_York' ); // Not the Tenant's: times show at the offset received.
		$this->set_permalink_structure( '/%postname%/' );
		$this->fake_hestahub();
	}

	public function test_the_listing_shows_every_clinic_in_the_order_received() {
		$this->respond(
			200,
			$this->clinics(
				function ( $one ) {
					$later              = $one;
					$later['slug']      = 'springen';
					$later['name']      = 'Springkurs';
					$later['starts_at'] = '2027-04-01T10:00:00+02:00';
					$later['ends_at']   = null;
					return array( $one, $later );
				}
			)
		);

		$xpath = $this->render( 'clinic-listing' );

		$this->assertSame( array( 'Dressurkurs', 'Springkurs' ), $this->texts( $xpath, '//li[@class="hestahub-card"]//h2' ) );
	}

	public function test_a_card_shows_thumbnail_dates_signup_and_link() {
		$this->respond( 200, $this->example( 'clinics' ) );

		$xpath = $this->render( 'clinic-listing' );
		$card  = '//li[@class="hestahub-card"]';

		$this->assertStringEndsWith( '/foto_thumb.jpg', $xpath->evaluate( "string($card/img/@src)" ) );
		$this->assertSame( home_url( '/kurse/' . self::SLUG . '/' ), $xpath->evaluate( "string($card//h2/a/@href)" ) );
		$this->assertSame( array( '14.03.2027, 14:00 – 16.03.2027, 16:00' ), $this->texts( $xpath, "$card/p[@class='hestahub-when']" ) );
		$this->assertSame( array( 'Signup open' ), $this->texts( $xpath, "$card/p[contains(@class,'hestahub-signup')]" ) );
		$this->assertSame( 'open', $xpath->evaluate( "string($card/@data-hestahub-signup)" ) );
	}

	public function test_an_unknown_signup_value_shows_no_state() {
		$this->respond( 200, $this->changed( array( 'signup' => 'lotterie' ) ) );

		$xpath = $this->render( 'clinic-listing' );

		$this->assertSame( 0.0, $xpath->evaluate( 'count(//*[contains(@class,"hestahub-signup")] | //li[@data-hestahub-signup])' ) );
	}

	public function test_the_listing_fires_its_hook_with_the_clinics() {
		$this->respond( 200, $this->example( 'clinics' ) );
		$seen = null;
		add_action(
			'hestahub_after_clinic_listing',
			function ( $clinics ) use ( &$seen ) {
				$seen = $clinics;
			}
		);

		$this->render( 'clinic-listing' );

		$this->assertSame( json_decode( $this->example( 'clinics' ), true )['data'], $seen );
	}

	public function test_the_page_shows_name_when_photo_and_description() {
		$this->respond( 200, $this->example( 'clinics' ) );

		$xpath = $this->render( 'clinic', self::SLUG );

		$this->assertSame( array( 'Dressurkurs' ), $this->texts( $xpath, '//h1' ) );
		$this->assertSame( array( '14.03.2027, 14:00 – 16.03.2027, 16:00' ), $this->texts( $xpath, '//p[@class="hestahub-when"]' ) );
		$this->assertStringEndsWith( '/foto.jpg', $xpath->evaluate( 'string(//figure[@class="hestahub-photo"]/img/@src)' ) );
		$this->assertSame( array( 'Drei Tage Dressurarbeit in Kleingruppen. Für Reiter ab 12 Jahren.' ), $this->texts( $xpath, '//div[@class="hestahub-description"]/p' ) );
		$this->assertSame( 1.0, $xpath->evaluate( 'count(//div[@class="hestahub-description"]/p/br)' ), 'A line break stays one.' );
	}

	public function test_the_page_shows_horses_fees_and_minimum() {
		$this->respond( 200, $this->example( 'clinics' ) );

		$xpath = $this->render( 'clinic', self::SLUG );

		$this->assertSame(
			array( 'Bring your own horse or ride one of ours.', '€320.00 per rider', '€90.00 per loan horse', 'Minimum 4 participants' ),
			$this->texts( $xpath, '//ul[@class="hestahub-facts"]/li' )
		);
		$this->assertSame( array( 'All prices include VAT.' ), $this->texts( $xpath, '//p[@class="hestahub-note"]' ) );
	}

	public function test_nulls_and_unknown_horses_say_nothing() {
		$this->respond(
			200,
			$this->changed(
				array(
					'horses'           => 'esel',
					'loan_horse_fee'   => null,
					'min_participants' => null,
				)
			)
		);

		$xpath = $this->render( 'clinic', self::SLUG );

		$this->assertSame( array( '€320.00 per rider' ), $this->texts( $xpath, '//ul[@class="hestahub-facts"]/li' ) );
	}

	public function test_a_clinic_for_apartment_guests_only_says_so() {
		$this->respond( 200, $this->changed( array( 'apartment_guests_only' => true ) ) );

		$xpath = $this->render( 'clinic', self::SLUG );

		$this->assertSame( 1.0, $xpath->evaluate( 'count(//li[@class="hestahub-fact-guests-only"])' ) );
	}

	public function test_an_open_end_shows_only_the_start_and_one_day_shows_the_date_once() {
		$this->respond( 200, $this->changed( array( 'ends_at' => null ) ) );
		$this->assertSame( array( '14.03.2027, 14:00' ), $this->texts( $this->render( 'clinic', self::SLUG ), '//p[@class="hestahub-when"]' ) );

		( new \HestaHub\Api\Client( new \HestaHub\Api\Credentials() ) )->flush();
		$this->respond( 200, $this->changed( array( 'ends_at' => '2027-03-14T17:30:00+01:00' ) ) );
		$this->assertSame( array( '14.03.2027, 14:00–17:30' ), $this->texts( $this->render( 'clinic', self::SLUG ), '//p[@class="hestahub-when"]' ) );
	}

	public function test_the_page_shows_the_signup_state_and_window() {
		$this->respond(
			200,
			$this->changed(
				array(
					'signup'          => 'not_yet_open',
					'signup_opens_at' => '2027-01-01T09:00:00+01:00',
				)
			)
		);

		$xpath = $this->render( 'clinic', self::SLUG );

		$this->assertSame( array( 'Signup not yet open' ), $this->texts( $xpath, '//section[contains(@class,"hestahub-signup")]/h2' ) );
		$this->assertSame( array( 'Signup opens 01.01.2027, 09:00' ), $this->texts( $xpath, '//p[@class="hestahub-signup-opens"]' ) );
		$this->assertSame( array( 'Signup closes 07.03.2027, 23:59' ), $this->texts( $xpath, '//p[@class="hestahub-signup-closes"]' ) );
		$this->assertSame( 0.0, $xpath->evaluate( 'count(//a[@class="hestahub-signup-link"])' ) );
	}

	public function test_a_signup_link_only_while_open_or_waitlist_and_with_a_portal_url() {
		$portal = 'https://kronshof.hestahub.com/portal/dressurkurs';
		$cases  = array(
			array( 'open', null, null ),
			array( 'open', $portal, 'Sign up' ),
			array( 'waitlist', $portal, 'Join the waitlist' ),
			array( 'full', $portal, null ),
			array( 'closed', $portal, null ),
			array( 'not_yet_open', $portal, null ),
			array( 'lotterie', $portal, null ),
		);
		foreach ( $cases as $case ) {
			( new \HestaHub\Api\Client( new \HestaHub\Api\Credentials() ) )->flush();
			$this->respond(
				200,
				$this->changed(
					array(
						'signup'     => $case[0],
						'portal_url' => $case[1],
					)
				)
			);

			$xpath = $this->render( 'clinic', self::SLUG );

			$label = implode( ', ', $case );
			if ( null === $case[2] ) {
				$this->assertSame( 0.0, $xpath->evaluate( 'count(//a[@class="hestahub-signup-link"])' ), $label );
			} else {
				$this->assertSame( $portal, $xpath->evaluate( 'string(//a[@class="hestahub-signup-link"]/@href)' ), $label );
				$this->assertSame( array( $case[2] ), $this->texts( $xpath, '//a[@class="hestahub-signup-link"]' ), $label );
			}
		}
	}

	public function test_an_unknown_signup_value_shows_no_state_on_the_page() {
		$this->respond( 200, $this->changed( array( 'signup' => 'lotterie' ) ) );

		$xpath = $this->render( 'clinic', self::SLUG );

		$this->assertSame( 0.0, $xpath->evaluate( 'count(//section[contains(@class,"hestahub-signup")])' ) );
	}

	public function test_the_page_fires_its_hook_with_the_clinic() {
		$this->respond( 200, $this->example( 'clinics' ) );
		$seen = null;
		add_action(
			'hestahub_after_clinic',
			function ( $clinic ) use ( &$seen ) {
				$seen = $clinic;
			}
		);

		$this->render( 'clinic', self::SLUG );

		$this->assertSame( json_decode( $this->example( 'clinics' ), true )['data'][0], $seen );
	}

	/**
	 * The clinics example with its Clinic's fields changed.
	 *
	 * @param array $fields Field => value.
	 */
	private function changed( array $fields ): string {
		return $this->clinics(
			function ( $one ) use ( $fields ) {
				return array( array_merge( $one, $fields ) );
			}
		);
	}

	/**
	 * The clinics example with its data replaced.
	 *
	 * @param callable $make Receives the example's Clinic, returns the list.
	 */
	private function clinics( callable $make ): string {
		return $this->example(
			'clinics',
			function ( $body ) use ( $make ) {
				$body['data'] = $make( $body['data'][0] );
				return $body;
			}
		);
	}

	/**
	 * @param string      $template The template.
	 * @param string|null $slug     The slug, for a single.
	 */
	private function render( string $template, ?string $slug = null ): DOMXPath {
		ob_start();
		hestahub_render( $template, null === $slug ? array() : array( 'slug' => $slug ) );
		$html = (string) ob_get_clean();
		$this->assertNotSame( '', $html, 'Rendered something.' );
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8"><body>' . $html . '</body>' );
		libxml_clear_errors();
		return new DOMXPath( $dom );
	}

	/**
	 * @param DOMXPath $xpath The page.
	 * @param string   $query An XPath query.
	 * @return string[]
	 */
	private function texts( DOMXPath $xpath, string $query ): array {
		$texts = array();
		foreach ( $xpath->query( $query ) as $node ) {
			$texts[] = trim( preg_replace( '/\s+/u', ' ', $node->textContent ), " \t\n\r\0\x0B" );
		}
		return $texts;
	}
}
