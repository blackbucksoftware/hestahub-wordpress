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

	/** @var Client */
	private $client;

	/** @var string The plugin's folder. */
	private $dir;

	/**
	 * What this request's owned route resolved to, once known.
	 *
	 * @var array|null resource, slug, and found.
	 */
	private $resolved = null;

	/**
	 * @param Client $client The API client.
	 * @param string $dir    The plugin's folder, holding templates/.
	 */
	public function __construct( Client $client, string $dir ) {
		$this->client = $client;
		$this->dir    = $dir;
	}

	public function register( Plugin $plugin ): void {
		add_action( 'init', array( $this, 'add_rules' ) );
		// Late, so every plugin's rules are registered before a flush.
		add_action( 'wp_loaded', array( $this, 'flush_if_changed' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'parse_query', array( $this, 'parse_query' ) );
		add_filter( 'posts_pre_query', array( $this, 'posts_pre_query' ), 10, 2 );
		add_filter( 'pre_handle_404', array( $this, 'pre_handle_404' ), 10, 2 );
		add_filter( 'template_include', array( $this, 'template_include' ) );
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
	 * Answers an owned route: 200 for the listing and for a slug in the
	 * current data, 404 for any other slug. Never 410.
	 *
	 * @param bool      $preempt  Whether to skip WordPress's 404 handling.
	 * @param \WP_Query $wp_query The main query.
	 */
	public function pre_handle_404( $preempt, $wp_query ) {
		if ( ! $this->is_owned_route( $wp_query ) ) {
			return $preempt;
		}
		$resolved = $this->resolve( $wp_query );
		if ( $resolved['found'] ) {
			status_header( 200 );
		} else {
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
		return true;
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
		$name     = self::TEMPLATES[ $resolved['resource'] ][ null === $resolved['slug'] ? 0 : 1 ];
		$theme    = locate_template( 'hestahub/' . $name . '.php' );
		return $theme ? $theme : $this->dir . '/templates/' . $name . '.php';
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
	 * What an owned route resolves to: its listing, or a slug that is or is
	 * not in the current data.
	 *
	 * @param \WP_Query $query The main query.
	 * @return array resource, slug (null for the listing), found.
	 */
	private function resolve( $query ): array {
		if ( null !== $this->resolved ) {
			return $this->resolved;
		}
		$resource = (string) $query->get( self::QV_RESOURCE );
		$slug     = (string) $query->get( self::QV_SLUG );
		$this->resolved = array(
			'resource' => $resource,
			'slug'     => '' === $slug ? null : $slug,
			'found'    => '' === $slug || $this->in_current_data( $resource, $slug ),
		);
		return $this->resolved;
	}

	/**
	 * Whether the current data of a resource has an item with this slug.
	 *
	 * @param string $resource apartments or clinics.
	 * @param string $slug     The slug.
	 */
	private function in_current_data( string $resource, string $slug ): bool {
		$result = $this->client->get( $resource );
		foreach ( $result->is_ok() ? $result->data() : array() as $item ) {
			if ( is_array( $item ) && isset( $item['slug'] ) && $item['slug'] === $slug ) {
				return true;
			}
		}
		return false;
	}
}
