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
 * The plugin's Apartment listing, Apartment page and rate table, rendered
 * from the vendored example. Contract:
 * https://docs.hestahub.com/v1/#get-apiv1apartments
 */
final class ApartmentTemplatesTest extends WP_UnitTestCase {

	use FakeHestaHub;

	public function set_up() {
		parent::set_up();
		putenv( 'HESTAHUB_API_KEY' );
		update_option( 'hestahub_tenant', 'kronshof' );
		update_option( 'hestahub_api_key', 'k3y' );
		update_option( 'hestahub_apartments_base', 'wohnungen' );
		update_option( 'date_format', 'd.m.Y' );
		$this->set_permalink_structure( '/%postname%/' );
		$this->fake_hestahub();
	}

	public function test_the_listing_shows_every_apartment_in_the_order_received() {
		$this->respond( 200, $this->apartments( array( 'katla', 'hoettur' ) ) );

		$xpath = $this->render( 'apartment-listing' );

		$this->assertSame( array( 'katla', 'hoettur' ), $this->texts( $xpath, '//li[@class="hestahub-card"]//h2' ) );
	}

	public function test_a_card_shows_its_facts_and_links_to_its_page() {
		$this->respond( 200, $this->example( 'apartments' ) );

		$xpath = $this->render( 'apartment-listing' );
		$card  = '//li[@class="hestahub-card"]';

		$this->assertSame( home_url( '/wohnungen/hoettur/' ), $xpath->evaluate( "string($card//h2/a/@href)" ) );
		$this->assertSame( array( 'Alte Scheune', '58 m²', 'Up to 4 persons' ), $this->texts( $xpath, "$card//ul[@class='hestahub-facts']/li" ) );
		$this->assertStringEndsWith( '/wohnzimmer.jpg', $xpath->evaluate( "string($card/img/@src)" ), 'The primary image.' );
	}

	public function test_a_card_carries_the_building_key_and_max_persons_for_a_filter() {
		$this->respond( 200, $this->example( 'apartments' ) );

		$xpath = $this->render( 'apartment-listing' );

		$this->assertSame( 'alte-scheune', $xpath->evaluate( 'string(//li[@class="hestahub-card"]/@data-hestahub-building)' ) );
		$this->assertSame( '4', $xpath->evaluate( 'string(//li[@class="hestahub-card"]/@data-hestahub-max-persons)' ) );
	}

	public function test_a_null_omits_its_token_never_prints_zero() {
		$this->respond(
			200,
			$this->example(
				'apartments',
				function ( $body ) {
					$body['data'][0]['building']    = null;
					$body['data'][0]['qm']          = null;
					$body['data'][0]['max_persons'] = null;
					return $body;
				}
			)
		);

		$xpath = $this->render( 'apartment-listing' );

		$this->assertSame( array(), $this->texts( $xpath, '//ul[@class="hestahub-facts"]/li' ) );
		$this->assertSame( 0.0, $xpath->evaluate( 'count(//li[@data-hestahub-building or @data-hestahub-max-persons])' ) );
	}

	public function test_without_a_primary_image_the_card_shows_the_first() {
		$this->respond(
			200,
			$this->example(
				'apartments',
				function ( $body ) {
					$body['data'][0]['images'][0]['is_primary'] = false;
					return $body;
				}
			)
		);

		$xpath = $this->render( 'apartment-listing' );

		$this->assertStringEndsWith( '/wohnzimmer.jpg', $xpath->evaluate( 'string(//li[@class="hestahub-card"]/img/@src)' ) );
	}

	public function test_without_a_base_path_a_card_has_no_link() {
		delete_option( 'hestahub_apartments_base' );
		$this->respond(
			200,
			$this->example(
				'apartments',
				function ( $body ) {
					$body['data'] = array( $body['data'][0] );
					return $body;
				}
			)
		);

		$xpath = $this->render( 'apartment-listing' );

		$this->assertSame( 0.0, $xpath->evaluate( 'count(//li[@class="hestahub-card"]//a)' ) );
		$this->assertSame( array( 'Höttur' ), $this->texts( $xpath, '//li[@class="hestahub-card"]//h2' ) );
	}

	public function test_the_listing_rate_table_matches_seasons_by_key() {
		$this->respond(
			200,
			$this->example(
				'apartments',
				function ( $body ) {
					$body['data']   = array( $body['data'][0] );
					$second         = $body['data'][0];
					$second['slug'] = 'katla';
					$second['name'] = 'Katla';
					// Only the high season, under another name: matched by key, not name.
					$second['pricing']['seasons']            = array( $body['data'][0]['pricing']['seasons'][1] );
					$second['pricing']['seasons'][0]['name'] = 'Sommer';
					$body['data'][]                          = $second;
					return $body;
				}
			)
		);

		$xpath = $this->render( 'apartment-listing' );

		$this->assertSame( array( 'Apartment', 'Nebensaison', 'Hauptsaison', 'Final cleaning' ), $this->texts( $xpath, '//table[@class="hestahub-rates"]/thead//th' ) );
		$katla = '//table[@class="hestahub-rates"]/tbody/tr[2]';
		$this->assertSame( '', trim( $xpath->evaluate( "string($katla/td[1])" ), " \t\n\r\0\x0B" ), 'No low season for Katla.' );
		$this->assertSame( array( '7+ nights: €115.00 per night' ), $this->texts( $xpath, "$katla/td[2]//li" ) );
	}

	public function test_the_listing_rate_table_lists_tiers_and_bundles_in_order() {
		$this->respond( 200, $this->example( 'apartments' ) );

		$xpath = $this->render( 'apartment-listing' );

		$this->assertSame(
			array( '3–6 nights: €95.00 per night', '7+ nights: €85.00 per night', 'Wochenendpauschale (2 nights): €295.00' ),
			$this->texts( $xpath, '//table[@class="hestahub-rates"]/tbody/tr[1]/td[1]//li' )
		);
	}

	public function test_the_cleaning_column_shows_one_value_a_range_or_nothing() {
		$this->respond( 200, $this->example( 'apartments' ) );
		$this->assertSame( '€45.00', $this->cleaning_cell() );

		$this->respond(
			200,
			$this->example(
				'apartments',
				function ( $body ) {
					$body['data'][0]['pricing']['seasons'][1]['tiers'][0]['final_cleaning_fee'] = '60.00';
					$body['data'][0]['pricing']['seasons'][0]['bundles'][0]['final_cleaning_fee'] = '0.00';
					return $body;
				}
			)
		);
		( new \HestaHub\Api\Client( new \HestaHub\Api\Credentials() ) )->flush();
		$this->assertSame( '€45.00–60.00', $this->cleaning_cell() );

		$this->respond(
			200,
			$this->example(
				'apartments',
				function ( $body ) {
					foreach ( $body['data'][0]['pricing']['seasons'] as $s => $season ) {
						foreach ( array( 'tiers', 'bundles' ) as $kind ) {
							foreach ( $season[ $kind ] as $r => $row ) {
								$body['data'][0]['pricing']['seasons'][ $s ][ $kind ][ $r ]['final_cleaning_fee'] = '0.00';
							}
						}
					}
					return $body;
				}
			)
		);
		( new \HestaHub\Api\Client( new \HestaHub\Api\Credentials() ) )->flush();
		$this->assertSame( '', $this->cleaning_cell() );
	}

	public function test_the_listing_fires_its_hook_after_its_content_with_the_apartments() {
		$this->respond( 200, $this->example( 'apartments' ) );
		$seen = null;
		add_action(
			'hestahub_after_apartment_listing',
			function ( $apartments ) use ( &$seen ) {
				$seen = $apartments;
				echo '<p id="after">Anfrage</p>';
			}
		);

		$html = $this->html( 'apartment-listing' );

		$this->assertSame( json_decode( $this->example( 'apartments' ), true )['data'], $seen );
		$this->assertGreaterThan( strpos( $html, 'hestahub-rates' ), strpos( $html, 'id="after"' ) );
	}

	public function test_the_page_shows_name_facts_description_and_features() {
		$this->respond(
			200,
			$this->example(
				'apartments',
				function ( $body ) {
					$body['data'][0]['description'] = "Erster Absatz.\n\nZweiter Absatz.";
					return $body;
				}
			)
		);
		$this->respond( 200, $this->example( 'availability' ) );

		$xpath = $this->render( 'apartment', 'hoettur' );

		$this->assertSame( array( 'Höttur' ), $this->texts( $xpath, '//h1' ) );
		$this->assertSame( array( 'Alte Scheune', '58 m²', 'Up to 4 persons' ), $this->texts( $xpath, '//article/ul[@class="hestahub-facts"]/li' ) );
		$this->assertSame( array( 'Erster Absatz.', 'Zweiter Absatz.' ), $this->texts( $xpath, '//div[@class="hestahub-description"]/p' ) );
		$this->assertSame( array( 'Zwei Schlafzimmer', 'Voll ausgestattete Küche', 'Terrasse mit Blick auf die Koppeln' ), $this->texts( $xpath, '//ul[@class="hestahub-features"]/li' ) );
	}

	public function test_a_captioned_image_has_an_empty_alt_an_uncaptioned_one_the_name() {
		$this->respond( 200, $this->example( 'apartments' ) );
		$this->respond( 200, $this->example( 'availability' ) );

		$xpath = $this->render( 'apartment', 'hoettur' );

		$figures = $xpath->query( '//div[@class="hestahub-gallery"]/figure' );
		$this->assertSame( 2, $figures->length, 'In the order received.' );
		$this->assertSame( '', $xpath->evaluate( 'string(img/@alt)', $figures->item( 0 ) ) );
		$this->assertSame( 'Wohnzimmer', $xpath->evaluate( 'string(figcaption)', $figures->item( 0 ) ) );
		$this->assertSame( 'Höttur', $xpath->evaluate( 'string(img/@alt)', $figures->item( 1 ) ) );
		$this->assertSame( 0.0, $xpath->evaluate( 'count(figcaption)', $figures->item( 1 ) ) );
	}

	public function test_the_page_shows_the_blueprint_rate_table_and_calendar() {
		$this->respond( 200, $this->example( 'apartments' ) );
		$this->respond( 200, $this->example( 'availability' ) );

		$xpath = $this->render( 'apartment', 'hoettur' );

		$this->assertStringEndsWith( '/grundriss.png', $xpath->evaluate( 'string(//figure[@class="hestahub-blueprint"]/img/@src)' ) );
		$this->assertSame( 1.0, $xpath->evaluate( 'count(//div[contains(@class,"hestahub-rate-table")])' ) );
		$this->assertSame( 1.0, $xpath->evaluate( 'count(//div[contains(@class,"hestahub-calendar")])' ) );
	}

	public function test_a_booking_link_only_when_portal_url_is_set() {
		$this->respond( 200, $this->example( 'apartments' ) );
		$this->respond( 200, $this->example( 'availability' ) );
		$this->assertSame( 0.0, $this->render( 'apartment', 'hoettur' )->evaluate( 'count(//a[@class="hestahub-booking-link"])' ) );

		( new \HestaHub\Api\Client( new \HestaHub\Api\Credentials() ) )->flush();
		$this->respond(
			200,
			$this->example(
				'apartments',
				function ( $body ) {
					$body['data'][0]['portal_url'] = 'https://kronshof.hestahub.com/portal/hoettur';
					return $body;
				}
			)
		);
		$this->respond( 200, $this->example( 'availability' ) );

		$xpath = $this->render( 'apartment', 'hoettur' );

		$this->assertSame( 'https://kronshof.hestahub.com/portal/hoettur', $xpath->evaluate( 'string(//a[@class="hestahub-booking-link"]/@href)' ), 'Exactly as received.' );
	}

	public function test_the_page_fires_its_hook_with_the_apartment() {
		$this->respond( 200, $this->example( 'apartments' ) );
		$this->respond( 200, $this->example( 'availability' ) );
		$seen = null;
		add_action(
			'hestahub_after_apartment',
			function ( $apartment ) use ( &$seen ) {
				$seen = $apartment;
			}
		);

		$this->render( 'apartment', 'hoettur' );

		$this->assertSame( json_decode( $this->example( 'apartments' ), true )['data'][0], $seen );
	}

	public function test_the_rate_table_shows_each_season_with_its_periods_tiers_and_bundles() {
		$this->respond( 200, $this->example( 'apartments' ) );

		$xpath = $this->render( 'rate-table', 'hoettur' );

		$this->assertSame( array( 'Nebensaison', 'Hauptsaison' ), $this->texts( $xpath, '//section/h3' ) );
		$this->assertSame( array( 'All dates not in another season' ), $this->texts( $xpath, '//section[1]/p[@class="hestahub-periods"]' ) );
		// The published example's dates move with the day it was built.
		$period = json_decode( $this->example( 'apartments' ), true )['data'][0]['pricing']['seasons'][1]['periods'][0];
		$day    = static function ( $date ) {
			return implode( '.', array_reverse( explode( '-', $date ) ) );
		};
		$this->assertSame( array( $day( $period['first_day'] ) . ' – ' . $day( $period['last_day'] ) ), $this->texts( $xpath, '//section[2]/ul[@class="hestahub-periods"]/li' ), 'Both days inclusive.' );
		$this->assertSame( array( 'Nebensaison, 3–6 nights', 'Nebensaison, 7+ nights' ), $this->texts( $xpath, '//section[1]//table[@class="hestahub-tiers"]/tbody/tr/th' ) );
		$this->assertSame( array( '€95.00', '€45.00' ), $this->texts( $xpath, '//section[1]//table[@class="hestahub-tiers"]/tbody/tr[1]/td' ) );
		$this->assertSame( 'Freitag, 15 Uhr, bis Sonntag, 17 Uhr', $xpath->evaluate( 'string(//span[@class="hestahub-bundle-terms"])' ) );
		$this->assertSame( array( '2', '€295.00', '€45.00' ), $this->texts( $xpath, '//table[@class="hestahub-bundles"]/tbody/tr[1]/td' ) );
	}

	public function test_a_named_tier_is_labelled_by_its_name() {
		$this->respond(
			200,
			$this->example(
				'apartments',
				function ( $body ) {
					$body['data'][0]['pricing']['seasons'][0]['tiers'][0]['name'] = 'Kurzurlaub';
					return $body;
				}
			)
		);

		$xpath = $this->render( 'rate-table', 'hoettur' );

		$this->assertSame( 'Kurzurlaub', $xpath->evaluate( 'string(//section[1]//table[@class="hestahub-tiers"]/tbody/tr[1]/th)' ) );
	}

	public function test_a_zero_cleaning_fee_is_left_out() {
		$this->respond(
			200,
			$this->example(
				'apartments',
				function ( $body ) {
					$body['data'][0]['pricing']['seasons'][0]['tiers'][0]['final_cleaning_fee'] = '0.00';
					return $body;
				}
			)
		);

		$xpath = $this->render( 'rate-table', 'hoettur' );

		$this->assertSame( '', $xpath->evaluate( 'string(//section[1]//table[@class="hestahub-tiers"]/tbody/tr[1]/td[2])' ) );
	}

	public function test_without_any_cleaning_fee_the_rate_table_has_no_cleaning_column_or_note() {
		$this->respond(
			200,
			$this->example(
				'apartments',
				function ( $body ) {
					foreach ( $body['data'][0]['pricing']['seasons'] as $s => $season ) {
						foreach ( array( 'tiers', 'bundles' ) as $kind ) {
							foreach ( $season[ $kind ] as $r => $row ) {
								$body['data'][0]['pricing']['seasons'][ $s ][ $kind ][ $r ]['final_cleaning_fee'] = '0.00';
							}
						}
					}
					return $body;
				}
			)
		);

		$html = $this->html( 'rate-table', 'hoettur' );

		$this->assertStringNotContainsString( 'Final cleaning', $html );
		$this->assertStringNotContainsString( 'final cleaning', $html );
	}

	public function test_a_season_without_a_name_heads_its_column_with_nothing_never_its_key() {
		$this->respond(
			200,
			$this->example(
				'apartments',
				function ( $body ) {
					$body['data'][0]['pricing']['seasons'][1]['name'] = null;
					return $body;
				}
			)
		);

		$xpath = $this->render( 'apartment-listing' );

		$this->assertSame( array( 'Apartment', 'Nebensaison', '', 'Final cleaning' ), $this->texts( $xpath, '//table[@class="hestahub-rates"]/thead//th' ) );
	}

	public function test_the_rate_table_says_prices_include_vat_only_when_the_envelope_does() {
		$this->respond( 200, $this->example( 'apartments' ) );
		$this->assertStringContainsString( 'All prices include VAT.', $this->html( 'rate-table', 'hoettur' ) );

		( new \HestaHub\Api\Client( new \HestaHub\Api\Credentials() ) )->flush();
		$this->respond(
			200,
			$this->example(
				'apartments',
				function ( $body ) {
					unset( $body['prices_include_tax'] );
					return $body;
				}
			)
		);
		$this->assertStringNotContainsString( 'VAT', $this->html( 'rate-table', 'hoettur' ) );
	}

	private function cleaning_cell(): string {
		return trim( $this->render( 'apartment-listing' )->evaluate( 'string(//td[@class="hestahub-cleaning-fee"])' ), " \t\n\r\0\x0B" );
	}

	/**
	 * What hestahub_render() prints.
	 *
	 * @param string      $template The template.
	 * @param string|null $slug     The slug, for a single.
	 */
	private function html( string $template, ?string $slug = null ): string {
		ob_start();
		hestahub_render( $template, null === $slug ? array() : array( 'slug' => $slug ) );
		return (string) ob_get_clean();
	}

	/**
	 * @param string      $template The template.
	 * @param string|null $slug     The slug, for a single.
	 */
	private function render( string $template, ?string $slug = null ): DOMXPath {
		$html = $this->html( $template, $slug );
		$this->assertNotSame( '', $html, 'Rendered something.' );
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8"><body>' . $html . '</body>' );
		libxml_clear_errors();
		return new DOMXPath( $dom );
	}

	/**
	 * The trimmed, whitespace-collapsed text of each match.
	 *
	 * @param DOMXPath $xpath The document.
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

	/**
	 * The apartments example with one copy of its Apartment per slug, named
	 * after it.
	 *
	 * @param string[] $slugs The slugs, in order.
	 */
	private function apartments( array $slugs ): string {
		return $this->example(
			'apartments',
			function ( $body ) use ( $slugs ) {
				$one          = $body['data'][0];
				$body['data'] = array();
				foreach ( $slugs as $slug ) {
					$one['slug']    = $slug;
					$one['name']    = $slug;
					$body['data'][] = $one;
				}
				return $body;
			}
		);
	}
}
