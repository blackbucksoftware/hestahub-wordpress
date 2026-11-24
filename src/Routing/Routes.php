<?php
/**
 * @package HestaHub
 */

namespace HestaHub\Routing;

use HestaHub\Admin\Settings;
use HestaHub\Api\Client;
use HestaHub\Feature;
use HestaHub\Plugin;

/**
 * Owns <base> and <base>/<slug> for Apartments and for Clinics.
 *
 * The rules come from the base path settings, never from fetched data. A
 * resource is left out only while the meta copy the plugin holds does not
 * list it; with no meta copy at all, the base paths still resolve.
 *
 * Without data, a route walks the render ladder: the copy until stale_ttl,
 * then 503 for at most 24 hours, then a 200 placeholder. A slug the plugin
 * has never seen answers 404 throughout.
 */
final class Routes implements Feature {

	const QV_RESOURCE = 'hestahub_resource';
	const QV_SLUG     = 'hestahub_slug';
	const QV_MONTH    = 'hestahub_month';

	/**
	 * The rules last flushed, as a hash. When the rules differ, the next
	 * request flushes: after the option that changed them is written.
	 */
	const SIGNATURE = 'hestahub_rewrite_signature';

	/**
	 * The template of each resource's listing and single page.
	 */
	const TEMPLATES = array(
		'apartments' => array( 'apartment-listing', 'apartment' ),
		'clinics'    => array( 'clinic-listing', 'clinic' ),
	);

	/**
	 * What an owned route answers.
	 */
	const FOUND       = 'found';
	const NOT_FOUND   = 'not_found';
	const UNAVAILABLE = 'unavailable';
	const PLACEHOLDER = 'placeholder';

	/**
	 * Page caches store a short 503 and replay it as 200 OK: the contract
	 * wants the body larger than this.
	 */
	const MIN_503_BODY = 512;

	/** @var Client */
	private $client;

	/** @var string The plugin's folder. */
	private $dir;

	/** @var callable Sends a raw header. */
	private $header;

	/**
	 * What this request's owned route resolved to, once known.
	 *
	 * @var array|null resource, slug, answer, item and result.
	 */
	private $resolved = null;

	/**
	 * @param Client        $client The API client.
	 * @param string        $dir    The plugin's folder, holding templates/.
	 * @param callable|null $header Sends a raw header. Defaults to header(),
	 *                              unless headers are already sent.
	 */
	public function __construct( Client $client, string $dir, $header = null ) {
		$this->client = $client;
		$this->dir    = $dir;
		$this->header = $header ? $header : static function ( string $line ): void {
			if ( ! headers_sent() ) {
				header( $line );
			}
		};
	}

	public function register( Plugin $plugin ): void {
		add_action( 'init', array( $this, 'add_rules' ) );
		// Late, so every plugin's rules are registered before a flush.
		add_action( 'wp_loaded', array( $this, 'flush_if_changed' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'parse_query', array( $this, 'parse_query' ) );
		add_filter( 'posts_pre_query', array( $this, 'posts_pre_query' ), 10, 2 );
		add_filter( 'pre_handle_404', array( $this, 'pre_handle_404' ), 10, 2 );
		add_action( 'template_redirect', array( $this, 'template_redirect' ), PHP_INT_MAX );
		add_filter( 'template_include', array( $this, 'template_include' ) );
		add_action( 'wp_head', array( $this, 'canonical' ) );
		add_filter( 'document_title_parts', array( $this, 'document_title_parts' ) );
		// Yoast SEO takes an owned route for the home page: its canonical and
		// title would be the home page's. Ours replace them.
		add_filter( 'wpseo_canonical', array( $this, 'wpseo_canonical' ) );
		add_filter( 'wpseo_title', array( $this, 'wpseo_title' ) );
	}

	/**
	 * Clears the flushed rules' hash, so the next request flushes. On activation.
	 */
	public static function activate(): void {
		delete_option( self::SIGNATURE );
	}

	/**
	 * Drops the stored rules, so WordPress rebuilds them without ours. On
	 * deactivation: a flush now would still include the rules this request
	 * registered. A network deactivation does it on every site.
	 *
	 * @param bool $network_wide Whether the plugin is deactivated network-wide.
	 */
	public static function deactivate( $network_wide = false ): void {
		if ( ! $network_wide || ! is_multisite() ) {
			self::drop_rules();
			return;
		}
		foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site ) {
			switch_to_blog( $site );
			self::drop_rules();
			restore_current_blog();
		}
	}

	private static function drop_rules(): void {
		delete_option( 'rewrite_rules' );
		delete_option( self::SIGNATURE );
	}

	/**
	 * Hooked to init.
	 */
	public function add_rules(): void {
		foreach ( $this->bases() as $resource => $base ) {
			$regex = '^' . preg_quote( $base, '#' );
			$query = 'index.php?' . self::QV_RESOURCE . '=' . $resource;
			add_rewrite_rule( $regex . '/?$', $query, 'top' );
			add_rewrite_rule( $regex . '/([^/]+)/?$', $query . '&' . self::QV_SLUG . '=$matches[1]', 'top' );
		}
	}

	/**
	 * Flushes the rewrite rules when ours changed since the last flush.
	 * Hooked to wp_loaded.
	 */
	public function flush_if_changed(): void {
		$signature = md5( (string) wp_json_encode( $this->bases() ) );
		if ( get_option( self::SIGNATURE ) === $signature ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( self::SIGNATURE, $signature );
	}

	/**
	 * @param string[] $vars The public query vars.
	 * @return string[]
	 */
	public function query_vars( $vars ) {
		$vars[] = self::QV_RESOURCE;
		$vars[] = self::QV_SLUG;
		$vars[] = self::QV_MONTH;
		return $vars;
	}

	/**
	 * An owned route is not the blog's home.
	 *
	 * @param \WP_Query $query The query.
	 */
	public function parse_query( $query ): void {
		if ( $query->is_main_query() && $this->is_owned_route( $query ) ) {
			$query->is_home = false;
		}
	}

	/**
	 * Skips the posts query on an owned route: it shows no posts.
	 *
	 * @param array|null $posts Posts, or null to run the query.
	 * @param \WP_Query  $query The query.
	 */
	public function posts_pre_query( $posts, $query ) {
		return $query->is_main_query() && $this->is_owned_route( $query ) ? array() : $posts;
	}

	/**
	 * Answers an owned route: 200 for data and for the placeholder, 503 while
	 * the data is temporarily unavailable, 404 for a slug not in the data.
	 * Never 410.
	 *
	 * @param bool      $preempt  Whether to skip WordPress's 404 handling.
	 * @param \WP_Query $wp_query The main query.
	 */
	public function pre_handle_404( $preempt, $wp_query ) {
		if ( ! $this->is_owned_route( $wp_query ) ) {
			return $preempt;
		}
		$resolved = $this->resolve( $wp_query );
		switch ( $resolved['answer'] ) {
			case self::NOT_FOUND:
				$wp_query->set_404();
				status_header( 404 );
				nocache_headers();
				break;
			case self::UNAVAILABLE:
				$this->send_503( $resolved['result']->retry_after() );
				break;
			default:
				status_header( 200 );
		}
		return true;
	}

	/**
	 * Pads a 503 body past what page caches would store. Hooked late to
	 * template_redirect, just before the template renders.
	 */
	public function template_redirect(): void {
		global $wp_query;
		if ( ! $this->is_owned_route( $wp_query ) || self::UNAVAILABLE !== $this->resolve( $wp_query )['answer'] ) {
			return;
		}
		$length = 0;
		ob_start(
			function ( $buffer, $phase ) use ( &$length ) {
				$length += strlen( $buffer );
				if ( ( $phase & PHP_OUTPUT_HANDLER_FINAL ) && $length <= self::MIN_503_BODY ) {
					$buffer .= "\n<!-- " . str_repeat( '-', self::MIN_503_BODY - $length ) . ' -->';
				}
				return $buffer;
			}
		);
	}

	/**
	 * The theme's copy of the route's template, else the plugin's.
	 *
	 * @param string $template The template WordPress chose.
	 */
	public function template_include( $template ) {
		global $wp_query;
		if ( ! $this->is_owned_route( $wp_query ) || is_404() ) {
			return $template;
		}
		$resolved = $this->resolve( $wp_query );
		switch ( $resolved['answer'] ) {
			case self::UNAVAILABLE:
				$name = 'unavailable';
				break;
			case self::PLACEHOLDER:
				$name = 'placeholder';
				break;
			default:
				$name = self::TEMPLATES[ $resolved['resource'] ][ null === $resolved['slug'] ? 0 : 1 ];
		}
		$theme = locate_template( 'hestahub/' . $name . '.php' );
		return $theme ? $theme : $this->dir . '/templates/' . $name . '.php';
	}

	/**
	 * Prints the route's canonical link: core prints one only for a post.
	 * Hooked to wp_head.
	 */
	public function canonical(): void {
		$url = $this->canonical_url();
		if ( null !== $url ) {
			echo '<link rel="canonical" href="' . esc_url( $url ) . '" />' . "\n";
		}
	}

	/**
	 * Names the route in the document title, where the theme lets WordPress
	 * build it (title-tag).
	 *
	 * @param array $parts The title's parts.
	 */
	public function document_title_parts( $parts ) {
		$title = $this->title();
		if ( null !== $title ) {
			$parts['title'] = $title;
		}
		return $parts;
	}

	/**
	 * Drops Yoast SEO's canonical on an owned route: canonical() prints ours.
	 *
	 * @param string|false $canonical Yoast's canonical.
	 * @return string|false
	 */
	public function wpseo_canonical( $canonical ) {
		return null === $this->canonical_url() ? $canonical : false;
	}

	/**
	 * Replaces Yoast SEO's title on an owned route.
	 *
	 * @param string $title Yoast's title.
	 */
	public function wpseo_title( $title ) {
		$ours = $this->title();
		if ( null === $ours ) {
			return $title;
		}
		return $ours . ' ' . apply_filters( 'document_title_separator', '-' ) . ' ' . get_bloginfo( 'name', 'display' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's hook.
	}

	/**
	 * Before any output, as one unit, what keeps page caches from storing
	 * the outage; then the status and when to come back.
	 *
	 * @param int $retry_after Seconds until the plugin next tries to fetch.
	 */
	private function send_503( int $retry_after ): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		do_action( 'litespeed_control_set_nocache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's hook.
		nocache_headers();
		status_header( 503 );
		call_user_func( $this->header, 'Retry-After: ' . max( 1, $retry_after ) );
	}

	/**
	 * The canonical URL of the owned route this request is on; null off one
	 * and on a 404.
	 */
	private function canonical_url(): ?string {
		global $wp_query;
		if ( ! $this->is_owned_route( $wp_query ) || is_404() ) {
			return null;
		}
		$resolved = $this->resolve( $wp_query );
		$path     = $this->bases()[ $resolved['resource'] ];
		if ( null !== $resolved['slug'] ) {
			$path .= '/' . $resolved['slug'];
		}
		return home_url( user_trailingslashit( $path ) );
	}

	/**
	 * The route's title: the item's name, else the resource's label; null
	 * off an owned route and on a 404.
	 */
	private function title(): ?string {
		global $wp_query;
		if ( ! $this->is_owned_route( $wp_query ) || is_404() ) {
			return null;
		}
		$resolved = $this->resolve( $wp_query );
		$item     = $resolved['item'];
		if ( is_array( $item ) && isset( $item['name'] ) && is_string( $item['name'] ) && '' !== $item['name'] ) {
			return $item['name'];
		}
		return Settings::label( $resolved['resource'] );
	}

	/**
	 * The base path of each resource this site owns: set, and not left out
	 * by meta.
	 *
	 * @return array<string, string> Resource => base path.
	 */
	private function bases(): array {
		$listed = $this->client->held_resources();
		$bases  = array();
		foreach ( Settings::BASES as $resource => $option ) {
			$base = get_option( $option, '' );
			if ( ! is_string( $base ) || '' === $base ) {
				continue;
			}
			if ( null !== $listed && ! in_array( $resource, $listed, true ) ) {
				continue;
			}
			$bases[ $resource ] = $base;
		}
		return $bases;
	}

	/**
	 * Whether a query is for one of the routes this site owns.
	 *
	 * @param \WP_Query|null $query The query.
	 */
	private function is_owned_route( $query ): bool {
		return $query instanceof \WP_Query && array_key_exists( (string) $query->get( self::QV_RESOURCE ), $this->bases() );
	}

	/**
	 * What an owned route resolves to: its resource, its slug (null for the
	 * listing), what it answers, its item, and the read behind it.
	 *
	 * @param \WP_Query $query The main query.
	 * @return array resource, slug, answer, item, result.
	 */
	private function resolve( $query ): array {
		if ( null !== $this->resolved ) {
			return $this->resolved;
		}
		$resource = (string) $query->get( self::QV_RESOURCE );
		$slug     = (string) $query->get( self::QV_SLUG );
		$slug     = '' === $slug ? null : $slug;
		$result   = $this->client->get( $resource );
		$item     = null;

		if ( $result->is_ok() ) {
			$items  = array_filter( $result->data(), 'is_array' );
			$item   = null === $slug ? null : $this->find( $items, $slug );
			$answer = null === $slug || null !== $item ? self::FOUND : self::NOT_FOUND;
		} elseif ( null !== $slug && ! $this->client->ever_listed( $resource, $slug ) ) {
			$answer = self::NOT_FOUND;
		} else {
			$answer = $result->is_temporarily_unavailable() ? self::UNAVAILABLE : self::PLACEHOLDER;
		}

		$this->resolved = array(
			'resource' => $resource,
			'slug'     => $slug,
			'answer'   => $answer,
			'item'     => $item,
			'result'   => $result,
		);
		return $this->resolved;
	}

	/**
	 * The item with this slug, or null.
	 *
	 * @param array[] $items The copy's items.
	 * @param string  $slug  The slug.
	 */
	private function find( array $items, string $slug ): ?array {
		foreach ( $items as $item ) {
			if ( isset( $item['slug'] ) && $item['slug'] === $slug ) {
				return $item;
			}
		}
		return null;
	}
}
