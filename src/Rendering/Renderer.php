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
	const SCRIPT    = 'hestahub';

	/**
	 * The templates a theme may override: the endpoint each reads, the
	 * variable it receives, and whether it shows one item, by slug.
	 */
	const TEMPLATES = array(
		'apartment-listing' => array(
			'endpoint' => 'apartments',
			'variable' => 'apartments',
			'single'   => false,
		),
		'apartment'         => array(
			'endpoint' => 'apartments',
			'variable' => 'apartment',
			'single'   => true,
		),
		'rate-table'        => array(
			'endpoint' => 'apartments',
			'variable' => 'apartment',
			'single'   => true,
		),
		'calendar'          => array(
			'endpoint' => 'availability',
			'variable' => 'availability',
			'single'   => true,
		),
		'clinic-listing'    => array(
			'endpoint' => 'clinics',
			'variable' => 'clinics',
			'single'   => false,
		),
		'clinic'            => array(
			'endpoint' => 'clinics',
			'variable' => 'clinic',
			'single'   => true,
		),
	);

	/** @var Client */
	private $client;

	/** @var string The plugin's folder. */
	private $dir;

	/** @var string[] The endpoints of the templates being rendered, innermost last. */
	private $rendering = array();

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
	 * null when there is nothing to show.
	 *
	 * @param string   $template A template name, e.g. apartment-listing.
	 * @param string[] $slugs    The slugs to show; empty for all.
	 * @return array|null
	 */
	public function variables( string $template, array $slugs = array() ): ?array {
		if ( ! isset( self::TEMPLATES[ $template ] ) ) {
			return null;
		}
		$result = $this->client->get( self::TEMPLATES[ $template ]['endpoint'] );
		return $result->is_ok() ? $this->from_data( $template, $result->data(), $slugs ) : null;
	}

	/**
	 * The variables a template receives, from an endpoint's data; null when
	 * there is nothing to show. A listing may be limited to some slugs, kept
	 * in the order received, and shows nothing when none of them is there;
	 * a single needs its slug.
	 *
	 * @param string   $template A template name.
	 * @param array    $data     The data of the template's endpoint.
	 * @param string[] $slugs    The slugs to show; empty for all.
	 * @return array|null
	 */
	public function from_data( string $template, array $data, array $slugs = array() ): ?array {
		if ( ! isset( self::TEMPLATES[ $template ] ) ) {
			return null;
		}
		$spec = self::TEMPLATES[ $template ];

		if ( 'availability' === $spec['endpoint'] ) {
			// The calendar also needs the window its ranges describe.
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

		if ( $spec['single'] ) {
			$item = $this->find( $data, $slugs );
			return null === $item ? null : array( $spec['variable'] => $item );
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
			if ( ! $data ) {
				return null;
			}
		}
		return array( $spec['variable'] => $data );
	}

	/**
	 * A template rendered with these variables, as HTML. Enqueues the
	 * stylesheet and the script, so a page that renders nothing of ours
	 * carries neither.
	 *
	 * @param string $template  A template name.
	 * @param array  $variables What variables() returned for it.
	 */
	public function render( string $template, array $variables ): string {
		$this->enqueue();
		$this->rendering[] = isset( self::TEMPLATES[ $template ] ) ? self::TEMPLATES[ $template ]['endpoint'] : '';
		ob_start();
		try {
			self::run( $this->locate( $template ), $variables );
		} finally {
			array_pop( $this->rendering );
		}
		return (string) ob_get_clean();
	}

	/**
	 * The envelope of the data the innermost template being rendered reads:
	 * its currency, whether its amounts include VAT, and when it was
	 * fetched. Nulls outside a render, or for data that carries no money.
	 *
	 * @return array currency (string|null), prices_include_tax (bool|null),
	 *               fetched_at (int|null: when the copy was fetched, fresh or stale).
	 */
	public function envelope(): array {
		$endpoint = end( $this->rendering );
		$result   = $endpoint ? $this->client->get( $endpoint ) : null;
		return array(
			'currency'           => $result ? $result->currency() : null,
			'prices_include_tax' => $result ? $result->prices_include_tax() : null,
			'fetched_at'         => $result ? $result->fetched_at() : null,
		);
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
	 * Whether the theme (child or parent) has its own copy of a template.
	 *
	 * @param string $template A template name.
	 */
	public function is_overridden( string $template ): bool {
		return '' !== locate_template( 'hestahub/' . $template . '.php' );
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
	 * Enqueues the stylesheet and the script on a post that uses the
	 * shortcode. Hooked to wp_enqueue_scripts.
	 */
	public function enqueue_for_shortcode(): void {
		// Early, so the styles are in the head; even if the shortcode then
		// finds nothing to show, as it cannot know before it runs.
		$post = get_post();
		if ( is_singular() && $post && has_shortcode( $post->post_content, self::SHORTCODE ) ) {
			$this->enqueue();
		}
	}

	/**
	 * Enqueues the plugin's structural stylesheet, in the head, and its
	 * optional script, deferred in the footer. The script only acts on a
	 * page with a calendar: it pages the months in place. Without it, the
	 * calendar's links reload the page, which the contract requires to work.
	 */
	public function enqueue(): void {
		wp_enqueue_style( self::STYLE, $this->asset( 'hestahub.css' ), array(), Plugin::VERSION );
		wp_enqueue_script(
			self::SCRIPT,
			$this->asset( 'hestahub.js' ),
			array(),
			Plugin::VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * The URL of a file in the plugin's assets/.
	 *
	 * @param string $file The file's name.
	 */
	private function asset( string $file ): string {
		return plugins_url( 'assets/' . $file, $this->dir . '/hestahub.php' );
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
			$slug = is_string( $slug ) ? trim( $slug, " \t\n\r\0\x0B" ) : '';
			if ( '' !== $slug ) {
				$clean[] = $slug;
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
