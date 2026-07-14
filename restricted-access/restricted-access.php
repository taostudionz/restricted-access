<?php
/*
Plugin Name: Restricted Access
Plugin URI: https://wordpress.org/plugins/restricted-access/
Description: Easily hide your WordPress site from public viewing by requiring visitors to log in first. Activate to turn on.
Version: 1.0.0
Author: Hongtao Ding
Author URI: https://tao.co.nz/

Text Domain: restricted-access
Domain Path: /languages

License: GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ID of the page currently serving as the Posts Page, or 0 when none does.
 *
 * The posts page never satisfies is_page(), so it can't be loop-protected and
 * is not eligible as the login page. Only applies when a static front page is
 * used; otherwise page_for_posts is an inert leftover and the page is ordinary.
 *
 * @since 1.0.0
 *
 * @return int
 */
function restricted_access_posts_page_id() {
	return 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_for_posts' ) : 0;
}

/**
 * Get the URL visitors are redirected to for logging in.
 *
 * Uses the login page configured under Settings → Restricted Access. Falls back to
 * the default WordPress login screen when no page is selected, or the selected
 * page no longer exists or is not published.
 *
 * @since 1.0.0
 *
 * @return string The absolute login URL.
 */
function restricted_access_get_login_url() {
	$login_url = '';

	$page_id = (int) get_option( 'restricted_access_login_page_id' );
	if ( $page_id && $page_id !== restricted_access_posts_page_id() && 'publish' === get_post_status( $page_id ) ) {
		$login_url = get_permalink( $page_id );
	}

	if ( ! $login_url ) {
		$login_url = wp_login_url();
	}

	/**
	 * Login URL filter.
	 *
	 * When overriding this with a URL that contains a query string, also hook
	 * {@see 'restricted_access_bypass'} to exempt that URL from the login
	 * redirect — it cannot be matched reliably for loop prevention. A URL on
	 * another host (e.g. an external SSO endpoint) additionally requires the
	 * host to be allowed via {@see 'allowed_redirect_hosts'}, otherwise
	 * wp_safe_redirect() sends visitors to admin_url() instead.
	 *
	 * @since 1.0.0
	 *
	 * @param string $login_url The absolute URL visitors are sent to for logging in.
	 */
	return apply_filters( 'restricted_access_login_url', $login_url );
}

function restricted_access() {

	// Exceptions for AJAX, Cron, or WP-CLI requests
	if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		return;
	}

	// Bail if the current visitor is a logged in user, unless Multisite is enabled
	if ( is_user_logged_in() && ! is_multisite() ) {
		return;
	}

	// Bail if visiting the configured login page. Prevents a redirect loop
	// regardless of the permalink structure
	$login_page_id = (int) get_option( 'restricted_access_login_page_id' );
	if ( $login_page_id && is_page( $login_page_id ) ) {
		return;
	}

	// Get visited URL
	$schema = is_ssl() ? 'https://' : 'http://';
	$url = $schema . ( isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '' ) . ( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '' );

	$login_url = restricted_access_get_login_url();

	// Bail if visiting a query-less login URL. Loop prevention for URLs
	// customized via the filter, which the is_page() check can't know about.
	// Scheme-insensitive so TLS-terminating proxies can't break the match
	if ( false === strpos( $login_url, '?' )
		&& untrailingslashit( set_url_scheme( preg_replace( '/\?.*/', '', $url ), 'http' ) ) === untrailingslashit( set_url_scheme( $login_url, 'http' ) ) ) {
		return;
	}

	/**
	 * Bypass filter.
	 *
	 * @since 1.0.0
	 *
	 * @param bool Whether to disable Restricted Access. Default false.
	 * @param string $url The visited URL.
	 */
	$bypass = apply_filters( 'restricted_access_bypass', false, $url );

	// Bail if bypass is enabled
	if ( $bypass ) {
		return;
	}

	// Only allow Multisite users access to their assigned sites
	if ( is_multisite() && is_user_logged_in() ) {
		if ( ! is_user_member_of_blog() && ! current_user_can( 'setup_network' ) ) {
			$message = apply_filters( 'restricted_access_multisite_message', __( "You're not authorized to access this site.", 'restricted-access' ), $url );
			wp_die( $message, get_option( 'blogname' ) . ' &rsaquo; ' . __( 'Error', 'restricted-access' ) );
		}
		return;
	}

	// Determine redirect URL
	$redirect_url = apply_filters( 'restricted_access_redirect', $url );

	// Set the headers to prevent caching
	nocache_headers();

	// Redirect unauthorized visitors to the login page
	wp_safe_redirect( add_query_arg( 'redirect_to', urlencode( $redirect_url ), $login_url ), 302 );
	exit;
}
add_action( 'template_redirect', 'restricted_access' );

/**
 * Restrict REST API for authorized users only
 *
 * @since 1.0.0
 * @param WP_Error|null|bool $result WP_Error if authentication error, null if authentication
 *                              method wasn't used, true if authentication succeeded.
 *
 * @return WP_Error|null|bool
 */
function restricted_access_rest_api( $result ) {
	if ( null === $result && ! is_user_logged_in() ) {
		return new WP_Error( 'rest_unauthorized', __( 'Only authenticated users can access the REST API.', 'restricted-access' ), array( 'status' => rest_authorization_required_code() ) );
	}
	return $result;
}
add_filter( 'rest_authentication_errors', 'restricted_access_rest_api', 99 );

/*
 * Settings page: Settings → Restricted Access
 */
function restricted_access_admin_menu() {
	add_options_page(
		__( 'Restricted Access', 'restricted-access' ),
		__( 'Restricted Access', 'restricted-access' ),
		'manage_options',
		'restricted-access',
		'restricted_access_settings_page'
	);
}
add_action( 'admin_menu', 'restricted_access_admin_menu' );

function restricted_access_register_settings() {
	register_setting(
		'restricted_access',
		'restricted_access_login_page_id',
		array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 0,
		)
	);

	add_settings_section( 'restricted_access_main', '', '__return_false', 'restricted-access' );

	add_settings_field(
		'restricted_access_login_page_id',
		__( 'Login page', 'restricted-access' ),
		'restricted_access_login_page_field',
		'restricted-access',
		'restricted_access_main'
	);
}
add_action( 'admin_init', 'restricted_access_register_settings' );

function restricted_access_login_page_field() {
	$dropdown = wp_dropdown_pages(
		array(
			'name'              => 'restricted_access_login_page_id',
			'selected'          => (int) get_option( 'restricted_access_login_page_id' ),
			'show_option_none'  => __( '&mdash; Default WordPress login &mdash;', 'restricted-access' ),
			'option_none_value' => '0',
			'exclude'           => array_filter( array( restricted_access_posts_page_id() ) ),
			'echo'              => 0,
		)
	);

	// wp_dropdown_pages() outputs nothing at all when no page qualifies
	if ( $dropdown ) {
		echo $dropdown;
	} else {
		echo '<p>' . esc_html__( 'No eligible pages found. The default WordPress login screen will be used.', 'restricted-access' ) . '</p>';
	}
	echo '<p class="description">' . esc_html__( 'Logged-out visitors are redirected to this page. It should contain a login form that honors the "redirect_to" query argument. If no page is selected, or the selected page is deleted or unpublished, the default WordPress login screen is used.', 'restricted-access' ) . '</p>';
}

function restricted_access_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<form action="options.php" method="post">
			<?php
			settings_fields( 'restricted_access' );
			do_settings_sections( 'restricted-access' );
			submit_button();
			?>
		</form>
	</div>
	<?php
}

function restricted_access_action_links( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=restricted-access' ) ) . '">' . esc_html__( 'Settings', 'restricted-access' ) . '</a>' );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'restricted_access_action_links' );

/*
 * Localization
 */
function restricted_access_load_textdomain() {
	load_plugin_textdomain( 'restricted-access', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}
add_action( 'plugins_loaded', 'restricted_access_load_textdomain' );
