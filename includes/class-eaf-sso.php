<?php
/**
 * EAF_SSO – Front-end EVE SSO authentication for character standings.
 *
 * Flow:
 *  1. User clicks "LOG IN with EVE Online" on the shortcode page.
 *  2. They are sent to EVE SSO (login.eveonline.com) with state + scope.
 *  3. EVE redirects back to admin-post.php?action=eaf_sso_callback.
 *  4. We exchange the code, decode the JWT, cache character info + access token
 *     + refresh token, set a secure cookie, and redirect back to the originating page.
 *  5. On subsequent page loads the cookie is read; character info is passed to
 *     JS via wp_localize_script so the SSO toolbar can render correctly.
 *  6. The JS "Available to my character only" toggle calls ajax_standings, which
 *     fetches (and caches) standings from ESI, returning them for client-side filtering.
 *     If the access token has expired it is silently refreshed using the stored
 *     refresh token before fetching standings. Only if the refresh itself fails
 *     is a re-authenticate prompt shown to the user.
 *
 * Storage keys
 * ─────────────
 *  Cookie    eaf_char_token                           – random 32-char token
 *  Transient eaf_char_auth_{token}     (24 h)        – { character_id, character_name }
 *  Transient eaf_access_token_{cid}    (19 min)      – ESI access token string
 *  Transient eaf_refresh_token_{cid}   (90 days)     – EVE refresh token string
 *  Transient eaf_standings_{cid}       (30 min)      – standings array from ESI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EAF_SSO {

	const COOKIE_NAME   = 'eaf_char_token';
	const SCOPE         = 'esi-characters.read_standings.v1';
	const CHAR_TTL      = DAY_IN_SECONDS;
	const TOKEN_TTL     = 19 * MINUTE_IN_SECONDS;
	const REFRESH_TTL   = 90 * DAY_IN_SECONDS;
	const STANDINGS_TTL = 30 * MINUTE_IN_SECONDS;

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	public static function init(): void {
		add_action( 'admin_post_nopriv_eaf_sso_callback', [ __CLASS__, 'handle_callback' ] );
		add_action( 'admin_post_eaf_sso_callback',        [ __CLASS__, 'handle_callback' ] );

		add_action( 'wp_ajax_eaf_standings',        [ __CLASS__, 'ajax_standings' ] );
		add_action( 'wp_ajax_nopriv_eaf_standings', [ __CLASS__, 'ajax_standings' ] );

		add_action( 'wp_ajax_eaf_sso_logout',        [ __CLASS__, 'ajax_logout' ] );
		add_action( 'wp_ajax_nopriv_eaf_sso_logout', [ __CLASS__, 'ajax_logout' ] );
	}

	// ── Credential helpers ────────────────────────────────────────────────────

	public static function get_credentials(): array {
		return [
			(string) get_option( 'eaf_sso_client_id',     '' ),
			(string) get_option( 'eaf_sso_client_secret', '' ),
		];
	}

	public static function is_configured(): bool {
		[ $id, $secret ] = self::get_credentials();
		return $id !== '' && $secret !== '';
	}

	// ── Cookie / transient auth state ─────────────────────────────────────────

	public static function get_current_auth(): ?array {
		$token = self::read_cookie();
		if ( $token === '' ) return null;

		$data = get_transient( 'eaf_char_auth_' . $token );
		if ( ! is_array( $data ) || empty( $data['character_id'] ) ) return null;
		return $data;
	}

	// ── Auth URL builder ──────────────────────────────────────────────────────

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
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
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

		$token_data = self::exchange_code( $code, $client_id, $client_secret );
		if ( is_wp_error( $token_data ) ) {
			wp_die( 'EVE SSO: ' . esc_html( $token_data->get_error_message() ) );
		}

		list( $character_id, $character_name, $access_token, $refresh_token ) = $token_data;

		$cookie_token = wp_generate_password( 32, false, false );

		set_transient( 'eaf_char_auth_' . $cookie_token, [
			'character_id'   => $character_id,
			'character_name' => $character_name,
		], self::CHAR_TTL );

		set_transient( 'eaf_access_token_' . $character_id, $access_token, self::TOKEN_TTL );

		if ( $refresh_token !== '' ) {
			set_transient( 'eaf_refresh_token_' . $character_id, $refresh_token, self::REFRESH_TTL );
		}

		delete_transient( 'eaf_standings_' . $character_id );

		$cookie_opts = [
			'expires'  => time() + self::CHAR_TTL,
			'path'     => '/',
			'domain'   => '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		];

		if ( PHP_VERSION_ID >= 70300 ) {
			setcookie( self::COOKIE_NAME, $cookie_token, $cookie_opts ); // phpcs:ignore WordPress.Arrays.ArrayKeySpacingRestrictions.NoSpacesAroundArrayKeys
		} else {
			setcookie( self::COOKIE_NAME, $cookie_token, $cookie_opts['expires'], $cookie_opts['path'], $cookie_opts['domain'], $cookie_opts['secure'], $cookie_opts['httponly'] ); // phpcs:ignore WordPress.Arrays.ArrayKeySpacingRestrictions.NoSpacesAroundArrayKeys
		}

		wp_safe_redirect( $return_url );
		exit;
	}

	// ── AJAX: standings ───────────────────────────────────────────────────────

	public static function ajax_standings(): void {
		check_ajax_referer( 'eaf_public', 'nonce' );

		$auth = self::get_current_auth();
		if ( ! $auth ) {
			wp_send_json_error( [ 'need_reauth' => true, 'message' => 'Not authenticated.' ] );
		}

		$character_id = $auth['character_id'];

		// Serve from cache if still fresh.
		$cached = get_transient( 'eaf_standings_' . $character_id );
		if ( is_array( $cached ) ) {
			wp_send_json_success( [ 'standings' => $cached, 'character_name' => $auth['character_name'] ] );
		}

		// Ensure we have a valid access token, refreshing silently if needed.
		$access_token = self::get_valid_access_token( $character_id );
		if ( is_wp_error( $access_token ) ) {
			wp_send_json_error( [ 'need_reauth' => true, 'message' => $access_token->get_error_message() ] );
		}

		// Fetch fresh standings from ESI.
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

		$status    = (int) wp_remote_retrieve_response_code( $response );
		$standings = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status === 401 || $status === 403 ) {
			delete_transient( 'eaf_access_token_'  . $character_id );
			delete_transient( 'eaf_refresh_token_' . $character_id );
			wp_send_json_error( [ 'need_reauth' => true, 'message' => 'Session expired. Please re-authenticate.' ] );
		}

		if ( $status !== 200 || ! is_array( $standings ) ) {
			wp_send_json_error( [ 'message' => 'ESI returned an unexpected response (HTTP ' . $status . ').' ] );
		}

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
				delete_transient( 'eaf_access_token_'  . $auth['character_id'] );
				delete_transient( 'eaf_refresh_token_' . $auth['character_id'] );
				delete_transient( 'eaf_standings_'     . $auth['character_id'] );
			}
			delete_transient( 'eaf_char_auth_' . $token );
		}

		if ( PHP_VERSION_ID >= 70300 ) {
			setcookie( self::COOKIE_NAME, '', [ 'expires' => time() - 3600, 'path' => '/', 'samesite' => 'Lax', 'httponly' => true, 'secure' => is_ssl() ] ); // phpcs:ignore WordPress.Arrays.ArrayKeySpacingRestrictions.NoSpacesAroundArrayKeys
		} else {
			setcookie( self::COOKIE_NAME, '', time() - 3600, '/' );
		}

		wp_send_json_success( [ 'message' => 'Logged out.' ] );
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Returns a valid access token for the given character ID.
	 * If the cached access token has expired, attempts a silent refresh.
	 * Returns WP_Error if no valid token can be obtained.
	 *
	 * @param string $character_id
	 * @return string|WP_Error
	 */
	private static function get_valid_access_token( $character_id ) {
		$access_token = (string) get_transient( 'eaf_access_token_' . $character_id );
		if ( $access_token !== '' ) {
			return $access_token;
		}

		$refresh_token = (string) get_transient( 'eaf_refresh_token_' . $character_id );
		if ( $refresh_token === '' ) {
			return new WP_Error( 'no_refresh_token', 'Session expired. Please re-authenticate.' );
		}

		[ $client_id, $client_secret ] = self::get_credentials();

		$response = wp_remote_post(
			'https://login.eveonline.com/v2/oauth/token',
			[
				'timeout' => 15,
				'headers' => [
					'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					'Content-Type'  => 'application/x-www-form-urlencoded',
				],
				'body' => [
					'grant_type'    => 'refresh_token',
					'refresh_token' => $refresh_token,
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'refresh_failed', 'Could not refresh session: ' . $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 || empty( $body['access_token'] ) ) {
			delete_transient( 'eaf_refresh_token_' . $character_id );
			return new WP_Error( 'refresh_rejected', 'Session expired. Please re-authenticate.' );
		}

		$new_access  = (string) $body['access_token'];
		$new_refresh = ! empty( $body['refresh_token'] ) ? (string) $body['refresh_token'] : $refresh_token;

		set_transient( 'eaf_access_token_'  . $character_id, $new_access,  self::TOKEN_TTL   );
		set_transient( 'eaf_refresh_token_' . $character_id, $new_refresh, self::REFRESH_TTL );

		return $new_access;
	}

	/**
	 * Exchanges an authorisation code for tokens and decodes the JWT.
	 * Returns array [ character_id, character_name, access_token, refresh_token ] or WP_Error.
	 *
	 * @param string $code
	 * @param string $client_id
	 * @param string $client_secret
	 * @return array|WP_Error
	 */
	private static function exchange_code( $code, $client_id, $client_secret ) {
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
			return new WP_Error( 'token_request_failed', 'Token request failed — ' . $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 || ! is_array( $body ) || empty( $body['access_token'] ) ) {
			return new WP_Error( 'invalid_token_response', 'Invalid token response from EVE Online. Please try again.' );
		}

		$access_token  = (string) $body['access_token'];
		$refresh_token = ! empty( $body['refresh_token'] ) ? (string) $body['refresh_token'] : '';

		$parts = explode( '.', $access_token );
		if ( count( $parts ) < 2 ) {
			return new WP_Error( 'malformed_token', 'Malformed access token.' );
		}

		$b64 = strtr( $parts[1], '-_', '+/' );
		$rem = strlen( $b64 ) % 4;
		if ( $rem ) {
			$b64 .= str_repeat( '=', 4 - $rem );
		}
		$payload = json_decode( base64_decode( $b64 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( ! is_array( $payload ) || empty( $payload['sub'] ) ) {
			return new WP_Error( 'no_identity', 'Could not read character identity from token.' );
		}

		if ( ! preg_match( '/(\d+)$/', (string) $payload['sub'], $m ) ) {
			return new WP_Error( 'no_char_id', 'Could not parse character ID from token.' );
		}

		$character_id   = (string) $m[1];
		$character_name = ! empty( $payload['name'] ) ? (string) $payload['name'] : ( 'Character ' . $character_id );

		return [ $character_id, $character_name, $access_token, $refresh_token ];
	}

	private static function read_cookie(): string {
		return isset( $_COOKIE[ self::COOKIE_NAME ] )
			? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) )
			: '';
	}
}
