<?php
/**
 * EAF_SSO – Front-end EVE SSO authentication for character standings.
 *
 * Flow:
 *  1. User clicks "LOG IN with EVE Online" on the shortcode page.
 *  2. They are sent to EVE SSO (login.eveonline.com) with state + scope.
 *  3. EVE redirects back to admin-post.php?action=eaf_sso_callback.
 *  4. We exchange the code, decode the JWT, cache character info + access token,
 *     set a secure cookie, and redirect the user back to the originating page.
 *  5. On subsequent page loads the cookie is read; character info is passed to
 *     JS via wp_localize_script so the SSO toolbar can render correctly.
 *  6. The JS "Available to my character only" toggle calls ajax_standings, which
 *     fetches (and caches) standings from ESI, returning them for client-side filtering.
 *
 * Storage keys
 * ─────────────
 *  Cookie   eaf_char_token                           – random 32-char token
 *  Transient eaf_char_auth_{token}     (24 h)        – { character_id, character_name }
 *  Transient eaf_access_token_{cid}    (19 min)      – ESI access token string
 *  Transient eaf_standings_{cid}       (30 min)      – standings array from ESI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EAF_SSO {

	const COOKIE_NAME      = 'eaf_char_token';
	const SCOPE            = 'esi-characters.read_standings.v1';
	const CHAR_TTL         = DAY_IN_SECONDS;       // 24 h — character identity
	const TOKEN_TTL        = 19 * MINUTE_IN_SECONDS; // slightly under EVE's 20-min access token
	const STANDINGS_TTL    = 30 * MINUTE_IN_SECONDS;

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	public static function init(): void {
		// OAuth callback — must be accessible to visitors who are not WP users.
		add_action( 'admin_post_nopriv_eaf_sso_callback', [ __CLASS__, 'handle_callback' ] );
		add_action( 'admin_post_eaf_sso_callback',        [ __CLASS__, 'handle_callback' ] );

		// AJAX: fetch standings for the authenticated character.
		add_action( 'wp_ajax_eaf_standings',        [ __CLASS__, 'ajax_standings' ] );
		add_action( 'wp_ajax_nopriv_eaf_standings', [ __CLASS__, 'ajax_standings' ] );

		// AJAX: log out (clear cookie + transient).
		add_action( 'wp_ajax_eaf_sso_logout',        [ __CLASS__, 'ajax_logout' ] );
		add_action( 'wp_ajax_nopriv_eaf_sso_logout', [ __CLASS__, 'ajax_logout' ] );

		// GDPR: register eaf_char_token with the WP Consent API standard
		// (https://wordpress.org/plugins/wp-consent-api/). Supported by CookieYes,
		// Complianz, CookieHub, and other major GDPR plugins — the cookie appears in
		// cookie declarations automatically without a manual entry or scanner run.
		// Safe to hook regardless of whether the API plugin is installed.
		$plugin = plugin_basename( EAF_DIR . 'eve-agent-finder.php' );
		add_filter( "wp_consent_api_registered_{$plugin}", '__return_true' );
		add_action( 'plugins_loaded', [ __CLASS__, 'register_wp_consent_api' ] );
	}

	// ── WP Consent API cookie registration ────────────────────────────────────

	/**
	 * Registers eaf_char_token with the WP Consent API.
	 *
	 * wp_add_cookie_info( $name, $service, $category, $expires, $description )
	 *
	 * Category 'functional': the cookie enables a service explicitly requested
	 * by the visitor (EVE SSO login). It contains no personal data and is only
	 * ever set when the user clicks the LOG IN with EVE Online button.
	 *
	 * Only registers if the WP Consent API plugin is active.
	 */
	public static function register_wp_consent_api(): void {
		if ( ! function_exists( 'wp_add_cookie_info' ) ) {
			return;
		}
		wp_add_cookie_info(
			self::COOKIE_NAME,
			'EVE Agent Finder',
			'functional',
			'1 day',
			'Set when a visitor authenticates via EVE Online SSO on the EVE Agent Finder. Stores a random session token (no personal data) linking the browser to an authenticated EVE character. Only set when the user explicitly clicks the LOG IN with EVE Online button.'
		);
	}

	// ── Credential helpers ────────────────────────────────────────────────────

	/**
	 * Returns [ client_id, client_secret ] from WP options.
	 * The secret is stored opaquely and never returned by the settings form.
	 */
	public static function get_credentials(): array {
		return [
			(string) get_option( 'eaf_sso_client_id',     '' ),
			(string) get_option( 'eaf_sso_client_secret', '' ),
		];
	}

	/** True only when both client_id and client_secret have been saved. */
	public static function is_configured(): bool {
		[ $id, $secret ] = self::get_credentials();
		return $id !== '' && $secret !== '';
	}

	// ── Cookie / transient auth state ─────────────────────────────────────────

	/**
	 * Returns [ 'character_id' => ..., 'character_name' => ... ] for the
	 * current visitor, or null if not authenticated.
	 */
	public static function get_current_auth(): ?array {
		$token = self::read_cookie();
		if ( $token === '' ) return null;

		$data = get_transient( 'eaf_char_auth_' . $token );
		if ( ! is_array( $data ) || empty( $data['character_id'] ) ) return null;
		return $data;
	}

	// ── Auth URL builder ──────────────────────────────────────────────────────

	/**
	 * Build an EVE SSO authorisation URL.
	 * Stores a one-time state token in a transient (10 min TTL).
	 *
	 * @param string $return_url  The page the user should land on after auth.
	 */
	public static function build_auth_url( string $return_url ): string {
		[ $client_id ] = self::get_credentials();

		$state = wp_generate_password( 32, false, false );
		set_transient( 'eaf_sso_state_' . $state, [ 'return_url' => $return_url ], 10 * MINUTE_IN_SECONDS );

		return add_query_arg(
			[
				'response_type' => 'code',
				'redirect_uri'  => admin_url( 'admin-post.php?action=eaf_sso_callback' ),
				'client_id'     => $client_id,
				'scope'         => self::SCOPE,
				'state'         => $state,
			],
			'https://login.eveonline.com/v2/oauth/authorize'
		);
	}

	// ── OAuth callback ────────────────────────────────────────────────────────

	public static function handle_callback(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth callback; WP nonces cannot be used
		if ( empty( $_GET['code'] ) || empty( $_GET['state'] ) ) {
			wp_die( 'EVE SSO: Missing code or state parameter.' );
		}

		$state = sanitize_text_field( wp_unslash( $_GET['state'] ) );
		$code  = sanitize_text_field( wp_unslash( $_GET['code'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$transient_data = get_transient( 'eaf_sso_state_' . $state );
		delete_transient( 'eaf_sso_state_' . $state );

		if ( ! is_array( $transient_data ) || empty( $transient_data['return_url'] ) ) {
			wp_die( 'EVE SSO: Invalid or expired state token. Please try again.' );
		}

		$return_url = (string) $transient_data['return_url'];

		[ $client_id, $client_secret ] = self::get_credentials();
		if ( $client_id === '' || $client_secret === '' ) {
			wp_die( 'EVE SSO: Plugin is not configured. Please contact the site administrator.' );
		}

		// Exchange authorisation code for tokens.
		$response = wp_remote_post(
			'https://login.eveonline.com/v2/oauth/token',
			[
				'timeout' => 20,
				'headers' => [
					'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					'Content-Type'  => 'application/x-www-form-urlencoded',
				],
				'body' => [
					'grant_type' => 'authorization_code',
					'code'       => $code,
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			wp_die( 'EVE SSO: Token request failed — ' . esc_html( $response->get_error_message() ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 || ! is_array( $body ) || empty( $body['access_token'] ) ) {
			wp_die( 'EVE SSO: Invalid token response from EVE Online. Please try again.' );
		}

		$access_token = (string) $body['access_token'];

		// Decode JWT payload to extract character identity.
		// Signature is not verified — the token was obtained directly from EVE SSO
		// over HTTPS in exchange for an auth code we initiated, so it cannot have
		// been tampered with in transit. Claims are used only for display / filtering.
		$parts = explode( '.', $access_token );
		if ( count( $parts ) < 2 ) {
			wp_die( 'EVE SSO: Malformed access token.' );
		}

		$b64 = strtr( $parts[1], '-_', '+/' );
		$rem = strlen( $b64 ) % 4;
		if ( $rem ) {
			$b64 .= str_repeat( '=', 4 - $rem );
		}
		$payload = json_decode( base64_decode( $b64 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( ! is_array( $payload ) || empty( $payload['sub'] ) ) {
			wp_die( 'EVE SSO: Could not read character identity from token.' );
		}

		if ( ! preg_match( '/(\d+)$/', (string) $payload['sub'], $m ) ) {
			wp_die( 'EVE SSO: Could not parse character ID from token.' );
		}

		$character_id   = (string) $m[1];
		$character_name = ! empty( $payload['name'] ) ? (string) $payload['name'] : ( 'Character ' . $character_id );

		// Persist auth data.
		$cookie_token = wp_generate_password( 32, false, false );

		set_transient( 'eaf_char_auth_' . $cookie_token, [
			'character_id'   => $character_id,
			'character_name' => $character_name,
		], self::CHAR_TTL );

		set_transient( 'eaf_access_token_' . $character_id, $access_token, self::TOKEN_TTL );

		// Invalidate any stale standings cache for this character.
		delete_transient( 'eaf_standings_' . $character_id );

		// Set cookie — httponly, samesite=lax, secure when HTTPS is available.
		$cookie_opts = [
			'expires'  => time() + self::CHAR_TTL,
			'path'     => '/',
			'domain'   => '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		];

		// setcookie with options array requires PHP 7.3+; use that path if possible,
		// otherwise fall back to the six-argument form.
		if ( PHP_VERSION_ID >= 70300 ) {
			setcookie( self::COOKIE_NAME, $cookie_token, $cookie_opts ); // phpcs:ignore WordPress.Arrays.ArrayKeySpacingRestrictions.NoSpacesAroundArrayKeys
		} else {
			setcookie( self::COOKIE_NAME, $cookie_token, $cookie_opts['expires'], $cookie_opts['path'], $cookie_opts['domain'], $cookie_opts['secure'], $cookie_opts['httponly'] ); // phpcs:ignore WordPress.Arrays.ArrayKeySpacingRestrictions.NoSpacesAroundArrayKeys
		}

		wp_safe_redirect( $return_url );
		exit;
	}

	// ── AJAX: standings ───────────────────────────────────────────────────────

	/**
	 * Returns standings data for the authenticated character.
	 * Data is fetched from ESI and cached in a transient.
	 *
	 * Response shape:
	 *   { success: true, data: { standings: [ { from_id, from_type, standing }, … ] } }
	 *   { success: false, data: { need_reauth: true } }   (token expired)
	 */
	public static function ajax_standings(): void {
		check_ajax_referer( 'eaf_public', 'nonce' );

		$auth = self::get_current_auth();
		if ( ! $auth ) {
			wp_send_json_error( [ 'need_reauth' => true, 'message' => 'Not authenticated.' ] );
		}

		$character_id = $auth['character_id'];

		// Check standings cache first.
		$cached = get_transient( 'eaf_standings_' . $character_id );
		if ( is_array( $cached ) ) {
			wp_send_json_success( [ 'standings' => $cached, 'character_name' => $auth['character_name'] ] );
		}

		// Fetch fresh standings from ESI.
		$access_token = (string) get_transient( 'eaf_access_token_' . $character_id );
		if ( $access_token === '' ) {
			// Access token has expired — user needs to re-authenticate to refresh standings.
			wp_send_json_error( [ 'need_reauth' => true, 'message' => 'Session expired. Please re-authenticate.' ] );
		}

		$esi_url  = 'https://esi.evetech.net/latest/characters/' . rawurlencode( $character_id ) . '/standings/?datasource=tranquility';
		$response = wp_remote_get( $esi_url, [
			'timeout' => 15,
			'headers' => [
				'Authorization' => 'Bearer ' . $access_token,
				'Accept'        => 'application/json',
			],
		] );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( [ 'message' => 'ESI request failed: ' . $response->get_error_message() ] );
		}

		$status     = (int) wp_remote_retrieve_response_code( $response );
		$standings  = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status !== 200 || ! is_array( $standings ) ) {
			wp_send_json_error( [ 'message' => 'ESI returned an unexpected response (HTTP ' . $status . ').' ] );
		}

		// Cache standings.
		set_transient( 'eaf_standings_' . $character_id, $standings, self::STANDINGS_TTL );

		wp_send_json_success( [ 'standings' => $standings, 'character_name' => $auth['character_name'] ] );
	}

	// ── AJAX: logout ──────────────────────────────────────────────────────────

	public static function ajax_logout(): void {
		check_ajax_referer( 'eaf_public', 'nonce' );

		$token = self::read_cookie();
		if ( $token !== '' ) {
			$auth = get_transient( 'eaf_char_auth_' . $token );
			if ( is_array( $auth ) && ! empty( $auth['character_id'] ) ) {
				delete_transient( 'eaf_access_token_' . $auth['character_id'] );
				delete_transient( 'eaf_standings_'    . $auth['character_id'] );
			}
			delete_transient( 'eaf_char_auth_' . $token );
		}

		// Expire the cookie.
		if ( PHP_VERSION_ID >= 70300 ) {
			setcookie( self::COOKIE_NAME, '', [ 'expires' => time() - 3600, 'path' => '/', 'samesite' => 'Lax', 'httponly' => true, 'secure' => is_ssl() ] ); // phpcs:ignore WordPress.Arrays.ArrayKeySpacingRestrictions.NoSpacesAroundArrayKeys
		} else {
			setcookie( self::COOKIE_NAME, '', time() - 3600, '/' );
		}

		wp_send_json_success( [ 'message' => 'Logged out.' ] );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private static function read_cookie(): string {
		return isset( $_COOKIE[ self::COOKIE_NAME ] )
			? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) )
			: '';
	}
}
