<?php
/**
 * Admin page: enable toggle and token lifecycle.
 *
 * @package Auditra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tools > Auditra. Default WordPress admin markup only.
 */
class Auditra_Settings {

	const PAGE_SLUG     = 'auditra';
	const OPTION_NOTICE = 'auditra_show_activation_notice';

	/**
	 * Registers admin hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_auditra_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_auditra_regenerate_token', array( $this, 'handle_regenerate_token' ) );
		add_action( 'admin_post_auditra_dismiss_notice', array( $this, 'handle_dismiss_notice' ) );
		add_action( 'admin_notices', array( $this, 'render_activation_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( AUDITRA_PLUGIN_FILE ), array( $this, 'add_settings_link' ) );
	}

	/**
	 * Adds a Settings link to the plugin's row on the Plugins screen — the
	 * first place anyone looks after activating.
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public function add_settings_link( $links ) {
		if ( ! current_user_can( $this->capability() ) ) {
			return $links;
		}
		$link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'tools.php?page=' . self::PAGE_SLUG ) ),
			esc_html__( 'Settings', 'auditra' )
		);
		array_unshift( $links, $link );
		return $links;
	}

	/**
	 * One notice after activation explaining that the plugin is intentionally
	 * inert until the endpoint is enabled. Disappears permanently once the
	 * endpoint is enabled or the notice is dismissed.
	 *
	 * @return void
	 */
	public function render_activation_notice() {
		if ( '1' !== get_option( self::OPTION_NOTICE, '' ) ) {
			return;
		}
		if ( ! current_user_can( $this->capability() ) ) {
			return;
		}
		// Enabling the endpoint answers the notice; stop showing it.
		if ( '1' === get_option( Auditra_Token_Auth::OPTION_ENABLED, '' ) ) {
			delete_option( self::OPTION_NOTICE );
			return;
		}
		// Already on the settings page: the page says all of this itself.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen instanceof WP_Screen && 'tools_page_' . self::PAGE_SLUG === $screen->id ) {
			return;
		}
		?>
		<div class="notice notice-info">
			<p>
				<strong><?php esc_html_e( 'Auditra is installed and doing nothing — by design.', 'auditra' ); ?></strong>
			</p>
			<p>
				<?php esc_html_e( 'The MCP endpoint is disabled, so the plugin exposes nothing and changes nothing until you enable it and generate a token.', 'auditra' ); ?>
			</p>
			<p>
				<a href="<?php echo esc_url( admin_url( 'tools.php?page=' . self::PAGE_SLUG ) ); ?>" class="button button-primary"><?php esc_html_e( 'Set up Auditra', 'auditra' ); ?></a>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=auditra_dismiss_notice' ), 'auditra_dismiss_notice' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Dismiss', 'auditra' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Dismisses the activation notice for good.
	 *
	 * @return void
	 */
	public function handle_dismiss_notice() {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Auditra.', 'auditra' ) );
		}
		check_admin_referer( 'auditra_dismiss_notice' );

		delete_option( self::OPTION_NOTICE );

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/**
	 * Capability required to manage Auditra.
	 *
	 * @return string
	 */
	private function capability() {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}

	/**
	 * Adds the menu item under Tools.
	 *
	 * @return void
	 */
	public function add_menu() {
		$hook = add_management_page(
			__( 'Auditra', 'auditra' ),
			__( 'Auditra', 'auditra' ),
			$this->capability(),
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);

		// Attach the copy-button script to this screen and no other. Hooking
		// the returned suffix rather than checking inside a global
		// admin_enqueue_scripts callback means the script cannot leak onto
		// another admin page even by accident.
		if ( $hook ) {
			add_action( 'admin_print_footer_scripts-' . $hook, array( $this, 'enqueue_assets' ) );
		}
	}

	/**
	 * Registers the copy-to-clipboard behaviour for the connection URL.
	 *
	 * Printed through wp_add_inline_script() against a registered handle
	 * rather than as a raw <script> tag: inline tags in admin markup are a
	 * wordpress.org review finding, and an enqueued script is what lets a
	 * site's own Content-Security-Policy apply.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		$handle = 'auditra-admin';

		// No file to load: the handle exists purely to carry the inline
		// script, which is why it is registered rather than enqueued from a
		// URL. wp_register_script with a false src is the documented way.
		wp_register_script( $handle, false, array(), AUDITRA_VERSION, true );
		wp_enqueue_script( $handle );
		wp_add_inline_script( $handle, $this->copy_script() );
	}

	/**
	 * The copy-button script. Plain DOM, no dependencies, and it degrades to
	 * the readonly field the user can select by hand.
	 *
	 * @return string
	 */
	private function copy_script() {
		return <<<'JS'
( function () {
	var button = document.getElementById( 'auditra-copy-url' );
	if ( ! button ) {
		return;
	}
	button.addEventListener( 'click', function () {
		var field = document.getElementById( 'auditra-connection-url' );
		if ( ! field ) {
			return;
		}
		field.select();
		var done = function () {
			var flag = document.getElementById( 'auditra-copy-done' );
			if ( flag ) {
				flag.style.display = 'inline';
			}
		};
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( field.value ).then( done, done );
		} else {
			document.execCommand( 'copy' );
			done();
		}
	} );
}() );
JS;
	}

	/**
	 * Saves the enable toggle.
	 *
	 * @return void
	 */
	public function handle_save_settings() {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Auditra.', 'auditra' ) );
		}
		check_admin_referer( 'auditra_save_settings' );

		$enabled = isset( $_POST['auditra_enabled'] ) ? '1' : '0';
		update_option( Auditra_Token_Auth::OPTION_ENABLED, $enabled );

		$this->redirect_back( 'settings_saved' );
	}

	/**
	 * Generates a token, or revokes and regenerates when one already exists.
	 *
	 * @return void
	 */
	public function handle_regenerate_token() {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Auditra.', 'auditra' ) );
		}
		check_admin_referer( 'auditra_regenerate_token' );

		Auditra_Token_Auth::generate_token();

		$this->redirect_back( 'token_regenerated' );
	}

	/**
	 * Redirects back to the settings page with a notice key.
	 *
	 * @param string $notice Notice key.
	 * @return void
	 */
	private function redirect_back( $notice ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => self::PAGE_SLUG,
					'auditra_notice' => $notice,
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	/**
	 * Renders the settings page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Auditra.', 'auditra' ) );
		}

		$enabled   = '1' === get_option( Auditra_Token_Auth::OPTION_ENABLED, '' );
		$token     = get_option( Auditra_Token_Auth::OPTION_TOKEN, '' );
		$has_token = is_string( $token ) && '' !== $token;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of a notice key set by our own redirect.
		$notice = isset( $_GET['auditra_notice'] ) ? sanitize_key( wp_unslash( $_GET['auditra_notice'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Auditra', 'auditra' ); ?></h1>

			<?php if ( 'settings_saved' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'auditra' ); ?></p></div>
			<?php elseif ( 'token_regenerated' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'A new token was generated. Any previous token stopped working immediately.', 'auditra' ); ?></p></div>
			<?php endif; ?>

			<p><?php esc_html_e( 'Auditra exposes a read-only MCP endpoint so AI clients can inspect this site\'s plugin estate. The endpoint is off by default and requires a secret token.', 'auditra' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="auditra_save_settings" />
				<?php wp_nonce_field( 'auditra_save_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'MCP endpoint', 'auditra' ); ?></th>
						<td>
							<label for="auditra_enabled">
								<input name="auditra_enabled" type="checkbox" id="auditra_enabled" value="1" <?php checked( $enabled ); ?> />
								<?php esc_html_e( 'Enable the MCP endpoint', 'auditra' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Enabling exposes read-only information about this site to anyone holding the token: the plugin list with versions and health data, WordPress/PHP/database versions, known vulnerabilities matched to installed versions, autoloaded option weight, cron schedules, database table names and sizes, and shortcode/block usage counts. No post content, user data, or credentials are ever exposed, and nothing can be changed through the endpoint. While disabled, the endpoint answers 404 to everything.', 'auditra' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Changes', 'auditra' ) ); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Access token', 'auditra' ); ?></h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="auditra_regenerate_token" />
				<?php wp_nonce_field( 'auditra_regenerate_token' ); ?>
				<?php if ( $has_token ) : ?>
					<p><?php esc_html_e( 'A token exists. Revoking and regenerating invalidates the old token immediately: every existing connection (including any Claude or other MCP connector using the old URL) will stop working until it is updated with the new URL.', 'auditra' ); ?></p>
					<?php submit_button( __( 'Revoke and regenerate', 'auditra' ), 'secondary', 'submit', false ); ?>
				<?php else : ?>
					<p><?php esc_html_e( 'No token exists yet. Generate one to build the connection URL.', 'auditra' ); ?></p>
					<?php submit_button( __( 'Generate token', 'auditra' ), 'primary', 'submit', false ); ?>
				<?php endif; ?>
			</form>

			<?php if ( $enabled && $has_token ) : ?>
				<h2><?php esc_html_e( 'Connection URL', 'auditra' ); ?></h2>
				<p><?php esc_html_e( 'Add this URL to your MCP client (for example, Claude custom connectors). Treat it like a password.', 'auditra' ); ?></p>
				<?php
				$connection_url = rest_url( Auditra_MCP_Server::REST_NAMESPACE . '/mcp/' . $token );
				// Remote MCP clients require HTTPS. A site whose stored URL is
				// http:// but which redirects to https silently breaks the
				// connection: the 301 drops the POST body and the client sees
				// an unreachable server, not a redirect. Offer the https form
				// so nobody copies a URL that cannot work.
				$is_https  = 0 === strpos( $connection_url, 'https://' );
				$https_url = set_url_scheme( $connection_url, 'https' );
				?>
				<p>
					<input type="text" readonly id="auditra-connection-url" class="large-text code" value="<?php echo esc_attr( $is_https ? $connection_url : $https_url ); ?>" onfocus="this.select();" />
				</p>
				<?php if ( ! $is_https ) : ?>
					<div class="notice notice-warning inline">
						<p>
							<strong><?php esc_html_e( 'This site is configured with an http:// address, so the URL above has been switched to https:// for you.', 'auditra' ); ?></strong>
						</p>
						<p>
							<?php esc_html_e( 'Remote MCP clients require HTTPS. If this site redirects http to https, copying the http:// form would appear to fail for a confusing reason: the redirect discards the request body, so the client reports the server as unreachable rather than redirected.', 'auditra' ); ?>
						</p>
						<p>
							<?php esc_html_e( 'If this site genuinely has no working HTTPS certificate, a remote MCP client cannot connect to it at all until that is fixed.', 'auditra' ); ?>
						</p>
					</div>
				<?php endif; ?>
				<?php if ( '' === (string) get_option( 'permalink_structure', '' ) ) : ?>
					<p class="description">
						<?php esc_html_e( 'This site uses plain permalinks, so the URL above carries a query string (?rest_route=). The endpoint works in this form. If your MCP client rejects the URL or mangles the query string, switch Settings → Permalinks to any option other than Plain and copy the URL again.', 'auditra' ); ?>
					</p>
				<?php endif; ?>
				<p>
					<button type="button" class="button" id="auditra-copy-url"><?php esc_html_e( 'Copy URL', 'auditra' ); ?></button>
					<span id="auditra-copy-done" style="display:none;"><?php esc_html_e( 'Copied.', 'auditra' ); ?></span>
				</p>
			<?php elseif ( $has_token ) : ?>
				<p><?php esc_html_e( 'A token exists but the endpoint is disabled, so no connection URL is shown. Enable the endpoint above.', 'auditra' ); ?></p>
			<?php endif; ?>

			<hr />

			<h2><?php esc_html_e( 'External services', 'auditra' ); ?></h2>
			<p><?php esc_html_e( 'To enrich its answers, Auditra contacts three public services. The only data ever sent is plugin slugs and version strings. No site content, no URLs beyond the API hosts, and no personal data leave this site.', 'auditra' ); ?></p>
			<table class="widefat striped" style="max-width:700px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Service', 'auditra' ); ?></th>
						<th><?php esc_html_e( 'What is sent', 'auditra' ); ?></th>
						<th><?php esc_html_e( 'What it answers', 'auditra' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td>api.wordpress.org</td>
						<td><?php esc_html_e( 'Plugin slugs', 'auditra' ); ?></td>
						<td><?php esc_html_e( 'Last updated, tested-up-to, installs, ratings, support activity', 'auditra' ); ?></td>
					</tr>
					<tr>
						<td>wpvulnerability.net</td>
						<td><?php esc_html_e( 'Plugin slugs and the WordPress version', 'auditra' ); ?></td>
						<td><?php esc_html_e( 'Published vulnerability records', 'auditra' ); ?></td>
					</tr>
					</tbody>
			</table>

			<?php $auth_log = Auditra_Request_Guard::auth_log(); ?>
			<?php if ( array() !== $auth_log ) : ?>
				<hr />
				<h2><?php esc_html_e( 'Failed authentication attempts', 'auditra' ); ?></h2>
				<p><?php esc_html_e( 'The most recent failed attempts against the endpoint (up to 50, newest first). No token material is ever recorded. The log clears when the token is regenerated.', 'auditra' ); ?></p>
				<table class="widefat striped" style="max-width:700px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Time (UTC)', 'auditra' ); ?></th>
							<th><?php esc_html_e( 'IP address', 'auditra' ); ?></th>
							<th><?php esc_html_e( 'User agent', 'auditra' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $auth_log as $attempt ) : ?>
							<tr>
								<td><?php echo esc_html( isset( $attempt['time'] ) ? $attempt['time'] : '' ); ?></td>
								<td><?php echo esc_html( isset( $attempt['ip'] ) ? $attempt['ip'] : '' ); ?></td>
								<td><?php echo esc_html( isset( $attempt['user_agent'] ) ? $attempt['user_agent'] : '' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
