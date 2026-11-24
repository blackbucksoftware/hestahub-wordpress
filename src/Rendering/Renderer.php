<?php
/**
 * @package HestaHub
 */

namespace HestaHub\Rendering;

use HestaHub\Api\Client;
use HestaHub\Feature;
use HestaHub\Plugin;

/**
 * Brings HestaHub data to a page through the plugin's templates: on an owned
 * route, in the [hestahub] shortcode, and in the hestahub_render() and
 * hestahub_get() template tags. Contract:
 * https://docs.hestahub.com/v1/#templates-and-hooks
 *
 * A template is found in the child theme's hestahub/, then the parent's,
 * then the plugin's templates/. It renders content only, never the page
 * around it, and receives the wire objects exactly as received.
 */
final class Renderer implements Feature {

	const SHORTCODE = 'hestahub';
	const STYLE     = 'hestahub';

	/**
	 * The templates a theme may override, each with the endpoint it reads,
	 * the variable it receives, and whether it shows one item (by slug).
	 */
	const TEMPLATES = array(
		'apartment-listing' => array( 'apartments', 'apartments', false ),
		'apartment'         => array( 'apartments', 'apartment', true ),
		'rate-table'        => array( 'apartments', 'apartment', true ),
		'calendar'          => array( 'availability', 'availability', true ),
		'clinic-listing'    => array( 'clinics', 'clinics', false ),
		'clinic'            => array( 'clinics', 'clinic', true ),
	);

	/** @var Client */
	private $client;

	/** @var string The plugin's folder. */
	private $dir;

	/**
	 * @param Client $client The API client.
	 * @param string $dir    The plugin's folder, holding templates/ and assets/.
	 */
	public function __construct( Client $client, string $dir ) {
		$this->client = $client;
		$this->dir    = $dir;
	}

	public function register( Plugin $plugin ): void {
		add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_for_shortcode' ) );
	}

	/**
	 * The variables a template receives, from the data the plugin holds;
	 * null when there is nothing to render. A listing may be limited to
	 * some slugs, kept in the order received; a single needs its slug.
	 *
	 * @param string   $template A template name, e.g. apartment-listing.
	 * @param string[] $slugs    The slugs to show; empty for all.
	 * @return array|null
	 */
	public function variables( string $template, array $slugs = array() ): ?array {
		if ( ! isset( self::TEMPLATES[ $template ] ) ) {
			return null;
		}
		list( $endpoint, $variable, $single ) = self::TEMPLATES[ $template ];
		$result                                 = $this->client->get( $endpoint );
		if ( ! $result->is_ok() ) {
			return null;
		}
		$data = $result->data();

		if ( 'availability' === $endpoint ) {
			$entry = $this->find( isset( $data['apartments'] ) && is_array( $data['apartments'] ) ? $data['apartments'] : array(), $slugs );
			if ( null === $entry ) {
				return null;
			}
			return array(
				'availability' => $entry,
				'window'       => array(
					'from' => isset( $data['from'] ) ? $data['from'] : null,
					'to'   => isset( $data['to'] ) ? $data['to'] : null,
				),
			);
		}

		if ( $single ) {
			$item = $this->find( $data, $slugs );
			return null === $item ? null : array( $variable => $item );
		}

		if ( $slugs ) {
			$data = array_values(
				array_filter(
					$data,
					function ( $item ) use ( $slugs ) {
						return is_array( $item ) && isset( $item['slug'] ) && in_array( $item['slug'], $slugs, true );
					}
				)
			);
		}
		return array( $variable => $data );
	}

	/**
	 * A template rendered with these variables, as HTML. Enqueues the
	 * stylesheet, so a page that renders nothing of ours carries none.
	 *
	 * @param string $template  A template name.
	 * @param array  $variables What variables() returned for it.
	 */
	public function render( string $template, array $variables ): string {
		$this->enqueue();
		ob_start();
		self::run( $this->locate( $template ), $variables );
		return (string) ob_get_clean();
	}

	/**
	 * A template rendered from the data the plugin holds; empty when there
	 * is nothing to render. What the shortcode and hestahub_render() show.
	 *
	 * @param string   $template A template name.
	 * @param string[] $slugs    The slugs to show; empty for all.
	 */
	public function show( string $template, array $slugs = array() ): string {
		$variables = $this->variables( $template, $slugs );
		return null === $variables ? '' : $this->render( $template, $variables );
	}

	/**
	 * The theme's copy of a template (child, then parent), else the plugin's.
	 *
	 * @param string $template A template name.
	 */
	public function locate( string $template ): string {
		$theme = locate_template( 'hestahub/' . $template . '.php' );
		return $theme ? $theme : $this->dir . '/templates/' . $template . '.php';
	}

	/**
	 * [hestahub show="apartment-listing" slug="hoettur, katla"]
	 *
	 * @param array|string $atts The shortcode's attributes.
	 */
	public function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'show' => '',
				'slug' => '',
			),
			$atts,
			self::SHORTCODE
		);
		return $this->show( (string) $atts['show'], self::slugs( $atts['slug'] ) );
	}

	/**
	 * Enqueues the stylesheet in the head of a post that uses the shortcode.
	 * Hooked to wp_enqueue_scripts.
	 */
	public function enqueue_for_shortcode(): void {
		$post = get_post();
		if ( is_singular() && $post && has_shortcode( $post->post_content, self::SHORTCODE ) ) {
			$this->enqueue();
		}
	}

	/**
	 * Enqueues the plugin's structural stylesheet.
	 */
	public function enqueue(): void {
		wp_enqueue_style( self::STYLE, plugins_url( 'assets/hestahub.css', $this->dir . '/hestahub.php' ), array(), Plugin::VERSION );
	}

	/**
	 * Slugs from a list or a comma-separated string, trimmed, empty ones
	 * dropped.
	 *
	 * @param mixed $slugs A string, an array of strings, or nothing.
	 * @return string[]
	 */
	public static function slugs( $slugs ): array {
		if ( is_string( $slugs ) ) {
			$slugs = explode( ',', $slugs );
		}
		if ( ! is_array( $slugs ) ) {
			return array();
		}
		$clean = array();
		foreach ( $slugs as $slug ) {
			if ( is_string( $slug ) && '' !== trim( $slug, " \t\n\r\0\x0B" ) ) {
				$clean[] = trim( $slug, " \t\n\r\0\x0B" );
			}
		}
		return $clean;
	}

	/**
	 * The first item whose slug is the first of these slugs, or null.
	 *
	 * @param array    $items The items.
	 * @param string[] $slugs The slugs; only the first counts.
	 */
	private function find( array $items, array $slugs ): ?array {
		if ( ! $slugs ) {
			return null;
		}
		foreach ( $items as $item ) {
			if ( is_array( $item ) && isset( $item['slug'] ) && $item['slug'] === $slugs[0] ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * Includes a template file with these variables and no others in scope.
	 *
	 * @param string $file      The template file.
	 * @param array  $variables Its variables.
	 */
	private static function run( string $file, array $variables ): void {
		( static function () {
			extract( func_get_arg( 1 ) ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- the template's variables are contract.
			include func_get_arg( 0 );
		} )( $file, $variables );
	}
}
