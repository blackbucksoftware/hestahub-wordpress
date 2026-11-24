<?php
/**
 * @package HestaHub
 */

namespace HestaHub\Admin;

use HestaHub\Api\Client;
use HestaHub\Api\Credentials;
use HestaHub\Api\Result;
use HestaHub\Feature;
use HestaHub\Health\Probe;
use HestaHub\Plugin;

/**
 * Settings → HestaHub: the connection, the two base paths, "refresh now"
 * and "test connection", and the slugs the plugin currently holds.
 */
final class Settings implements Feature {

	const PAGE       = 'hestahub';
	const GROUP      = 'hestahub';
	const CAPABILITY = 'manage_options';

	const TENANT          = Credentials::TENANT_OPTION;
	const API_KEY         = Credentials::KEY_OPTION;
	const APARTMENTS_BASE = 'hestahub_apartments_base';
	const CLINICS_BASE    = 'hestahub_clinics_base';

	/**
	 * The base path option of each resource.
	 */
	const BASES = array(
		'apartments' => self::APARTMENTS_BASE,
		'clinics'    => self::CLINICS_BASE,
	);

	/** @var Client */
	private $client;

	/** @var Credentials */
	private $credentials;

	/** @var bool Whether "refresh now" ran on this request. */
	private $refreshed = false;

	/** @var Result|null What "test connection" found on this request. */
	private $tested = null;

	/** @var Probe */
	private $probe;

	/** @var array|null What the 503 probe found on this request. */
	private $probed = null;

	/**
	 * @param Client      $client      The API client.
	 * @param Credentials $credentials Where the Tenant and key come from.
	 * @param Probe|null  $probe       The 503 probe "test connection" runs.
	 */
	public function __construct( Client $client, Credentials $credentials, ?Probe $probe = null ) {
		$this->client      = $client;
		$this->credentials = $credentials;
		$this->probe       = $probe ? $probe : new Probe();
	}

	public function register( Plugin $plugin ): void {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'add_page' ) );
	}

	/**
	 * Registers the four options and how each is saved. Hooked to admin_init.
	 */
	public function register_settings(): void {
		register_setting(
			self::GROUP,
			self::TENANT,
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => array( $this, 'sanitize_tenant' ),
			)
		);
		register_setting(
			self::GROUP,
			self::API_KEY,
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => array( $this, 'sanitize_api_key' ),
			)
		);
		foreach ( self::BASES as $resource => $option ) {
			register_setting(
				self::GROUP,
				$option,
				array(
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => function ( $value ) use ( $option ) {
						return $this->sanitize_base( $option, $value );
					},
				)
			);
		}
	}

	/**
	 * Adds the page and handles its actions before any output. Hooked to admin_menu.
	 */
	public function add_page(): void {
		$hook = add_options_page(
			__( 'HestaHub', 'hestahub' ),
			__( 'HestaHub', 'hestahub' ),
			self::CAPABILITY,
			self::PAGE,
			array( $this, 'render' )
		);
		if ( $hook ) {
			add_action( 'load-' . $hook, array( $this, 'handle_action' ) );
		}
	}

	/**
	 * Stores the HestaHub address as the bare subdomain. Accepts a pasted
	 * host or URL. Refuses anything that is not a valid subdomain.
	 *
	 * @param mixed $value The submitted value; null when the form left it out.
	 */
	public function sanitize_tenant( $value ): string {
		$old = $this->stored( self::TENANT );
		if ( ! is_string( $value ) ) {
			return $old;
		}
		$tenant = strtolower( trim( $value, " \t\n\r\0\x0B" ) );
		$tenant = preg_replace( '#^[a-z]+://#', '', $tenant );
		$tenant = preg_replace( '#[/?\#].*$#', '', $tenant );
		$tenant = preg_replace( '#\.hestahub\.com$#', '', $tenant );
		if ( '' !== $tenant && ! preg_match( '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $tenant ) ) {
			add_settings_error(
				self::TENANT,
				'hestahub_tenant_invalid',
				__( 'The HestaHub address must be your subdomain: the part before .hestahub.com, made of letters, digits and hyphens.', 'hestahub' )
			);
			return $old;
		}
		return $tenant;
	}

	/**
	 * Stores a newly entered key. A blank field means "unchanged", because
	 * the field never shows the key. A key from the environment or the
	 * constant wins, so the form never stores one beside it.
	 *
	 * @param mixed $value The submitted value; null when the form left it out.
	 */
	public function sanitize_api_key( $value ): string {
		$old = $this->stored( self::API_KEY );
		if ( ! is_string( $value ) || $this->credentials->is_key_external() ) {
			return $old;
		}
		$key = sanitize_text_field( $value );
		return '' === $key ? $old : $key;
	}

	/**
	 * Stores a base path as slash-separated URL segments, without leading or
	 * trailing slashes. Refuses one while permalinks are plain, and one equal
	 * to the other resource's base path.
	 *
	 * @param string $option The base path option.
	 * @param mixed  $value  The submitted value; null when the form left it out.
	 */
	public function sanitize_base( string $option, $value ): string {
		$old = $this->stored( $option );
		if ( ! is_string( $value ) ) {
			return $old;
		}
		$segments = array_filter( array_map( 'sanitize_title', explode( '/', $value ) ), 'strlen' );
		$base     = implode( '/', $segments );
		if ( '' === $base || $base === $old ) {
			return $base;
		}
		if ( $this->plain_permalinks() ) {
			add_settings_error(
				$option,
				$option . '_plain_permalinks',
				__( 'A base path needs pretty permalinks. While permalinks are set to “Plain”, no URL under a base path can work. Choose another structure under Settings → Permalinks first.', 'hestahub' )
			);
			return $old;
		}
		foreach ( self::BASES as $other ) {
			if ( $other !== $option && $this->stored( $other ) === $base ) {
				add_settings_error(
					$option,
					$option . '_taken',
					__( 'Apartments and Clinics need different base paths.', 'hestahub' )
				);
				return $old;
			}
		}
		return $base;
	}

	/**
	 * Runs "refresh now" or "test connection" when the page is posted to.
	 * Hooked to the page's load action, before any output.
	 */
	public function handle_action(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified below.
		$action = isset( $_POST['hestahub_action'] ) ? sanitize_key( wp_unslash( $_POST['hestahub_action'] ) ) : '';
		if ( 'refresh' !== $action && 'test' !== $action ) {
			return;
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage HestaHub.', 'hestahub' ), 403 );
		}
		check_admin_referer( 'hestahub_' . $action );

		if ( 'refresh' === $action ) {
			$this->client->flush();
			$this->refreshed = true;
			return;
		}
		$this->tested = $this->client->fetch_live( 'meta' );
		$this->probed = $this->probe->run();
	}

	/**
	 * Prints the page.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$resources = $this->client->resources();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'HestaHub', 'hestahub' ); ?></h1>
			<?php settings_errors(); ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<h2><?php esc_html_e( 'Connection', 'hestahub' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="hestahub-tenant"><?php esc_html_e( 'HestaHub address', 'hestahub' ); ?></label></th>
						<td>
							<code>https://</code><input type="text" id="hestahub-tenant" name="<?php echo esc_attr( self::TENANT ); ?>" value="<?php echo esc_attr( $this->stored( self::TENANT ) ); ?>" class="regular-text" autocomplete="off" spellcheck="false"><code>.hestahub.com</code>
							<p class="description"><?php esc_html_e( 'Your HestaHub subdomain.', 'hestahub' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hestahub-api-key"><?php esc_html_e( 'API key', 'hestahub' ); ?></label></th>
						<td><?php $this->render_key_field(); ?></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Base paths', 'hestahub' ); ?></h2>
				<p><?php esc_html_e( 'Where your Apartments and Clinics appear on this site. Empty until you set one: until then, the plugin owns no URL.', 'hestahub' ); ?></p>
				<?php $this->render_base_fields( $resources ); ?>

				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Maintenance', 'hestahub' ); ?></h2>
			<?php $this->render_outcome(); ?>
			<?php $this->render_probe(); ?>
			<p><?php esc_html_e( '“Refresh now” discards the copies this site keeps, including the one that may be keeping the site up while HestaHub cannot be reached. “Test connection” asks HestaHub directly and changes nothing.', 'hestahub' ); ?></p>
			<form method="post" style="display:inline">
				<?php wp_nonce_field( 'hestahub_test' ); ?>
				<input type="hidden" name="hestahub_action" value="test">
				<?php submit_button( __( 'Test connection', 'hestahub' ), 'secondary', 'submit', false ); ?>
			</form>
			<form method="post" style="display:inline">
				<?php wp_nonce_field( 'hestahub_refresh' ); ?>
				<input type="hidden" name="hestahub_action" value="refresh">
				<?php submit_button( __( 'Refresh now', 'hestahub' ), 'secondary', 'submit', false ); ?>
			</form>

			<h2><?php esc_html_e( 'What this site holds', 'hestahub' ); ?></h2>
			<?php $this->render_held(); ?>
		</div>
		<?php
	}

	private function render_key_field(): void {
		$source = $this->credentials->key_source();
		$masked = $this->mask( $this->credentials->key() );

		if ( $this->credentials->is_key_external() ) {
			?>
			<input type="text" id="hestahub-api-key" value="<?php echo esc_attr( $masked ); ?>" class="regular-text" readonly>
			<p class="description">
				<?php
				echo esc_html(
					Credentials::SOURCE_ENVIRONMENT === $source
						? __( 'Read from the environment variable HESTAHUB_API_KEY. Change it there.', 'hestahub' )
						: __( 'Read from the constant HESTAHUB_API_KEY in wp-config.php. Change it there.', 'hestahub' )
				);
				?>
			</p>
			<?php
			return;
		}
		?>
		<input type="password" id="hestahub-api-key" name="<?php echo esc_attr( self::API_KEY ); ?>" value="" placeholder="<?php echo esc_attr( $masked ); ?>" class="regular-text" autocomplete="new-password" spellcheck="false">
		<p class="description">
			<?php
			echo esc_html(
				Credentials::SOURCE_OPTION === $source
					? __( 'Stored in the database. Leave blank to keep it; enter a new key to replace it.', 'hestahub' )
					: __( 'Not set yet. You can also set it in wp-config.php as the constant HESTAHUB_API_KEY, or as an environment variable of that name.', 'hestahub' )
			);
			?>
		</p>
		<?php
	}

	/**
	 * @param string[]|null $resources What meta lists, or null while unknown.
	 */
	private function render_base_fields( ?array $resources ): void {
		if ( null === $resources ) {
			echo '<p class="description">' . esc_html__( 'The base paths can be set once the connection works: HestaHub says which of Apartments and Clinics you have.', 'hestahub' ) . '</p>';
			return;
		}
		if ( ! $resources ) {
			echo '<p class="description">' . esc_html__( 'HestaHub lists neither Apartments nor Clinics for you.', 'hestahub' ) . '</p>';
			return;
		}
		$plain = $this->plain_permalinks();
		if ( $plain ) {
			echo '<div class="notice notice-warning inline"><p>';
			printf(
				/* translators: %s: link to the Permalinks settings. */
				esc_html__( 'Permalinks are set to “Plain”, so no URL under a base path can work. Choose another structure under %s to set a base path.', 'hestahub' ),
				'<a href="' . esc_url( admin_url( 'options-permalink.php' ) ) . '">' . esc_html__( 'Settings → Permalinks', 'hestahub' ) . '</a>'
			);
			echo '</p></div>';
		}
		echo '<table class="form-table" role="presentation">';
		foreach ( $resources as $resource ) {
			$option = self::BASES[ $resource ];
			$id     = 'hestahub-' . $resource . '-base';
			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( self::label( $resource ) ) . '</label></th><td>';
			echo '<code>' . esc_html( trailingslashit( home_url() ) ) . '</code>';
			echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $option ) . '" value="' . esc_attr( $this->stored( $option ) ) . '" class="regular-text" spellcheck="false"' . ( $plain ? ' readonly' : '' ) . '>';
			echo '</td></tr>';
		}
		echo '</table>';
	}

	private function render_outcome(): void {
		if ( $this->refreshed ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'The cached copies are discarded. The next page view fetches fresh data.', 'hestahub' ) . '</p></div>';
			return;
		}
		if ( null === $this->tested ) {
			return;
		}
		$result = $this->tested;
		if ( ! $result->is_ok() ) {
			$failure = $result->failure();
			echo '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'The connection failed.', 'hestahub' ) . '</strong></p><p>';
			if ( null === $failure || \HestaHub\Api\Failure::NOT_CONFIGURED === $failure->kind() ) {
				esc_html_e( 'Enter the HestaHub address and an API key first.', 'hestahub' );
			} elseif ( '' !== $failure->code() ) {
				echo '<code>' . esc_html( $failure->code() ) . '</code> ' . esc_html( $failure->message() );
			} elseif ( $failure->status() ) {
				/* translators: %d: HTTP status code. */
				echo esc_html( trim( sprintf( __( 'HestaHub answered with HTTP status %d.', 'hestahub' ), $failure->status() ) . ' ' . $failure->message(), ' ' ) );
			} else {
				echo esc_html( $failure->message() );
			}
			echo '</p></div>';
			return;
		}
		$data   = $result->data();
		$rows   = array(
			__( 'Tenant', 'hestahub' )                 => isset( $data['tenant']['name'] ) && is_string( $data['tenant']['name'] ) ? $data['tenant']['name'] : '',
			__( 'Revision', 'hestahub' )               => (string) $result->revision(),
			__( 'Minimum plugin version', 'hestahub' ) => isset( $data['minimum_plugin_version'] ) && is_string( $data['minimum_plugin_version'] ) ? $data['minimum_plugin_version'] : '',
			__( 'API key', 'hestahub' )                => $this->mask( $this->credentials->key() ),
		);
		echo '<div class="notice notice-success inline"><p><strong>' . esc_html__( 'The connection works.', 'hestahub' ) . '</strong></p><table class="widefat striped" style="width:auto"><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( '' === $value ? '—' : $value ) . '</td></tr>';
		}
		echo '</tbody></table><p></p></div>';
	}

	/**
	 * What the 503 probe found.
	 */
	private function render_probe(): void {
		if ( null === $this->probed ) {
			return;
		}
		switch ( $this->probed['result'] ) {
			case Probe::OK:
				$class = 'notice-success';
				$text  = __( 'Outage check: the host passes the plugin’s 503 through unchanged, so page caches will not store an outage.', 'hestahub' );
				break;
			case Probe::REWRITTEN:
				$class = 'notice-warning';
				/* translators: %d: the HTTP status that came back. */
				$text = sprintf( __( 'Outage check: the host or a cache changed the plugin’s 503 (it came back with HTTP status %d, or without the plugin’s page). During an outage visitors may see a cached or error page instead. Ask your host to pass 503 responses through.', 'hestahub' ), $this->probed['status'] );
				break;
			case Probe::UNREACHABLE:
				$class = 'notice-warning';
				/* translators: %s: why the request failed. */
				$text = sprintf( __( 'Outage check: this site could not request its own page: %s', 'hestahub' ), $this->probed['message'] );
				break;
			default:
				$class = 'notice-info';
				$text  = __( 'Outage check: set a base path first. The check needs one of the plugin’s pages.', 'hestahub' );
		}
		echo '<div class="notice ' . esc_attr( $class ) . ' inline hestahub-probe"><p>' . esc_html( $text ) . '</p></div>';
	}

	/**
	 * The slugs of the copies the plugin holds, whatever meta says now.
	 */
	private function render_held(): void {
		echo '<table class="form-table" role="presentation">';
		foreach ( Client::RESOURCES as $resource ) {
			$slugs = array();
			foreach ( (array) $this->client->held( $resource ) as $item ) {
				if ( is_array( $item ) && isset( $item['slug'] ) && is_string( $item['slug'] ) ) {
					$slugs[] = $item['slug'];
				}
			}
			echo '<tr><th scope="row">' . esc_html( self::label( $resource ) ) . '</th><td>';
			echo $slugs
				? '<code>' . implode( '</code>, <code>', array_map( 'esc_html', $slugs ) ) . '</code>'
				: esc_html__( 'None', 'hestahub' );
			echo '</td></tr>';
		}
		echo '</table>';
	}

	/**
	 * A resource's name as shown.
	 *
	 * @param string $resource apartments or clinics.
	 */
	public static function label( string $resource ): string {
		return 'clinics' === $resource ? __( 'Clinics', 'hestahub' ) : __( 'Apartments', 'hestahub' );
	}

	private function plain_permalinks(): bool {
		return '' === (string) get_option( 'permalink_structure' );
	}

	/**
	 * A key as shown: its last four characters.
	 *
	 * @param string $key The key.
	 */
	private function mask( string $key ): string {
		return '' === $key ? '' : '••••' . substr( $key, -4 );
	}

	/**
	 * An option as stored, read without its sanitizer's help.
	 *
	 * @param string $option The option.
	 */
	private function stored( string $option ): string {
		$value = get_option( $option, '' );
		return is_string( $value ) ? $value : '';
	}
}
