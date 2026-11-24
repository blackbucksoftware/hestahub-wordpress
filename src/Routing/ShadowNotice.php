<?php
/**
 * @package HestaHub
 */

namespace HestaHub\Routing;

use HestaHub\Admin\Settings;
use HestaHub\Feature;
use HestaHub\Plugin;

/**
 * On taking a base path, raises a persistent admin notice listing what it
 * now shadows: the page at the base and its direct children; its deeper
 * descendants, still reachable but orphaned under a base that no longer
 * resolves; and the page's feed, embed and trackback URLs.
 *
 * What was shadowed is recorded when the base path is saved, in
 * hestahub_shadowed_<resource>, and shown until an administrator dismisses it.
 */
final class ShadowNotice implements Feature {

	const OPTION_PREFIX = 'hestahub_shadowed_';
	const ACTION        = 'hestahub_dismiss_shadow';

	public function register( Plugin $plugin ): void {
		foreach ( Settings::BASES as $resource => $option ) {
			add_action(
				'add_option_' . $option,
				function ( $name, $value ) use ( $resource ) {
					$this->taken( $resource, $value );
				},
				10,
				2
			);
			add_action(
				'update_option_' . $option,
				function ( $old, $value ) use ( $resource ) {
					$this->taken( $resource, $value );
				},
				10,
				2
			);
		}
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'dismiss' ) );
	}

	/**
	 * Records what a newly saved base path shadows, replacing any earlier
	 * record for the resource.
	 *
	 * @param string $resource apartments or clinics.
	 * @param mixed  $base     The saved base path.
	 */
	private function taken( string $resource, $base ): void {
		delete_option( self::OPTION_PREFIX . $resource );
		if ( ! is_string( $base ) || '' === $base ) {
			return;
		}
		$page = get_page_by_path( $base );
		if ( ! $page ) {
			return;
		}
		$children = $this->children( $page->ID );
		$orphans  = array();
		foreach ( $children as $child ) {
			$orphans = array_merge( $orphans, $this->descendants( $child ) );
		}
		update_option(
			self::OPTION_PREFIX . $resource,
			array(
				'base'     => $base,
				'page'     => $page->ID,
				'children' => $children,
				'orphans'  => $orphans,
			)
		);
	}

	/**
	 * Prints each recorded notice to administrators. Hooked to admin_notices.
	 */
	public function render(): void {
		if ( ! current_user_can( Settings::CAPABILITY ) ) {
			return;
		}
		foreach ( array_keys( Settings::BASES ) as $resource ) {
			$record = get_option( self::OPTION_PREFIX . $resource );
			if ( ! is_array( $record ) || empty( $record['base'] ) || empty( $record['page'] ) ) {
				continue;
			}
			$base = $record['base'];
			echo '<div class="notice notice-warning"><p><strong>';
			printf(
				/* translators: 1: Apartments or Clinics, 2: the base path, e.g. /apartments/. */
				esc_html__( 'The %1$s base path %2$s now shadows content on this site.', 'hestahub' ),
				esc_html( Settings::label( $resource ) ),
				'<code>' . esc_html( '/' . $base . '/' ) . '</code>'
			);
			echo '</strong></p><p>';
			esc_html_e( 'Shadowed: these pages no longer show at their URLs, because the plugin\'s pages show there instead:', 'hestahub' );
			echo ' ' . $this->links( array_merge( array( (int) $record['page'] ), (array) $record['children'] ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in links().
			if ( ! empty( $record['orphans'] ) ) {
				echo '<p>';
				esc_html_e( 'These pages are still reachable, but orphaned under a base path that no longer resolves:', 'hestahub' );
				echo ' ' . $this->links( (array) $record['orphans'] ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in links().
			}
			$endpoints = array();
			foreach ( array( 'feed', 'embed', 'trackback' ) as $endpoint ) {
				$url         = home_url( '/' . $base . '/' . $endpoint . '/' );
				$endpoints[] = '<a href="' . esc_url( $url ) . '">' . esc_html( '/' . $base . '/' . $endpoint . '/' ) . '</a>';
			}
			echo '<p>';
			esc_html_e( 'Also shadowed, as a side effect: the page\'s feed, embed and trackback URLs:', 'hestahub' );
			echo ' ' . implode( ', ', $endpoints ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			$dismiss = wp_nonce_url(
				add_query_arg(
					array(
						'action'   => self::ACTION,
						'resource' => $resource,
					),
					admin_url( 'admin-post.php' )
				),
				self::ACTION . '_' . $resource
			);
			echo '<p><a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Dismiss', 'hestahub' ) . '</a></p></div>';
		}
	}

	/**
	 * Forgets a resource's notice, then goes back. Hooked to admin-post.
	 */
	public function dismiss(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below.
		$resource = isset( $_GET['resource'] ) ? sanitize_key( wp_unslash( $_GET['resource'] ) ) : '';
		if ( ! array_key_exists( $resource, Settings::BASES ) || ! current_user_can( Settings::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage HestaHub.', 'hestahub' ), 403 );
		}
		check_admin_referer( self::ACTION . '_' . $resource );
		delete_option( self::OPTION_PREFIX . $resource );
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url() );
		exit;
	}

	/**
	 * A page's direct children.
	 *
	 * @param int $parent The page's ID.
	 * @return int[]
	 */
	private function children( int $parent ): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'   => 'page',
					'post_parent' => $parent,
					'post_status' => array( 'publish', 'private', 'future', 'draft', 'pending' ),
					'numberposts' => -1,
					'orderby'     => array(
						'menu_order' => 'ASC',
						'title'      => 'ASC',
					),
					'fields'      => 'ids',
				)
			)
		);
	}

	/**
	 * A page's children, their children, and so on.
	 *
	 * @param int $parent The page's ID.
	 * @return int[]
	 */
	private function descendants( int $parent ): array {
		$found = array();
		foreach ( $this->children( $parent ) as $child ) {
			$found[] = $child;
			$found   = array_merge( $found, $this->descendants( $child ) );
		}
		return $found;
	}

	/**
	 * Pages as a list of links to edit them, skipping any since deleted.
	 *
	 * @param int[] $ids Page IDs.
	 */
	private function links( array $ids ): string {
		$links = array();
		foreach ( $ids as $id ) {
			$post = get_post( (int) $id );
			if ( ! $post || 'trash' === $post->post_status ) {
				continue;
			}
			$url     = get_edit_post_link( $post->ID, 'raw' );
			$title   = get_the_title( $post );
			$title   = '' === $title ? '#' . $post->ID : $title;
			$links[] = $url
				? '<a href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a>'
				: esc_html( $title );
		}
		return implode( ', ', $links );
	}
}
