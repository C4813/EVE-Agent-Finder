<?php
/**
 * EAF_Public  –  Shortcode registration, asset enqueueing, and AJAX handlers.
 *
 * All HTML lives in public/templates/shortcode.php (and info-modal.php).
 * All JS lives in public/js/eaf-public.js.
 * All CSS lives in public/css/eaf-public.css.
 *
 * Shortcode: [eve_agent_finder]
 *
 * Optional attributes:
 *   sec_class  = "highsec"   comma-separated: highsec,lowsec,nullsec
 *   min_jumps  = "0"         minimum jumps from low-sec
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EAF_Public {

	public function init(): void {
		add_shortcode( 'eve_agent_finder',             [ $this, 'render_shortcode' ] );
		add_action(    'wp_enqueue_scripts',          [ $this, 'enqueue_assets'   ] );
		add_action(    'wp_ajax_eaf_agents',          [ $this, 'ajax_agents'      ] );
		add_action(    'wp_ajax_nopriv_eaf_agents',   [ $this, 'ajax_agents'      ] );
		add_action(    'wp_ajax_eaf_filters',         [ $this, 'ajax_filters'     ] );
		add_action(    'wp_ajax_nopriv_eaf_filters',  [ $this, 'ajax_filters'     ] );
		add_action(    'wp_ajax_eaf_nearest',         [ $this, 'ajax_nearest'     ] );
		add_action(    'wp_ajax_nopriv_eaf_nearest',  [ $this, 'ajax_nearest'     ] );
		add_action(    'wp_ajax_eaf_suggest',         [ $this, 'ajax_suggest'     ] );
		add_action(    'wp_ajax_nopriv_eaf_suggest',  [ $this, 'ajax_suggest'     ] );
		add_action(    'wp_ajax_eaf_changelog',       [ $this, 'ajax_changelog'   ] );
		add_action(    'wp_ajax_nopriv_eaf_changelog',[ $this, 'ajax_changelog'   ] );
	}

	// ── Assets ────────────────────────────────────────────────────────────────

	public function enqueue_assets(): void {
		wp_enqueue_style(
			'eaf-public',
			EAF_URL . 'public/css/eaf-public.css',
			[],
			EAF_VERSION
		);

		wp_register_script(
			'eaf-public',
			EAF_URL . 'public/js/eaf-public.js',
			[ 'jquery' ],
			EAF_VERSION,
			true
		);
	}

	// ── Shortcode ─────────────────────────────────────────────────────────────

	public function render_shortcode( array $atts = [] ): string {
		if ( ! EAF_Query::is_ready() ) {
			return '<div class="eaf-notice eaf-notice-warn">⚠ EVE Agent Finder: data not yet imported. '
			     . 'Please visit the WordPress admin → <strong>EVE Agent Finder</strong> and run the import pipeline first.</div>';
		}

		$atts = shortcode_atts( [
			'sec_class' => 'highsec',
			'min_jumps' => '0',
		], $atts, 'eve_agent_finder' );

		$cfg = wp_json_encode( [
			'default_sec_class' => array_map( 'trim', explode( ',', $atts['sec_class'] ) ),
			'default_min_jumps' => max( 0, intval( $atts['min_jumps'] ) ),
		] );

		// SSO state: pass auth URL and current character info (if logged in) to JS.
		$sso_configured = EAF_SSO::is_configured();
		$auth           = $sso_configured ? EAF_SSO::get_current_auth() : null;
		$auth_url       = '';
		$return_url = ( is_ssl() ? 'https://' : 'http://' )
			. sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) )
			. sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) );
		$auth_url = $sso_configured ? EAF_SSO::build_auth_url( $return_url ) : '';

		wp_enqueue_script( 'eaf-public' );
		wp_localize_script( 'eaf-public', 'EAF', [
			'ajax_url'        => admin_url( 'admin-ajax.php' ),
			'nonce'           => wp_create_nonce( 'eaf_public' ),
			'sso_configured'  => $sso_configured ? '1' : '0',
			'sso_auth_url'    => $auth_url,
			'sso_char_name'   => $auth ? $auth['character_name'] : '',
			'sso_authed'      => $auth ? '1' : '0',
			'sso_img_url'     => EAF_URL . 'public/img/eve-sso.png',
		] );

		ob_start();
		require EAF_DIR . 'public/templates/shortcode.php';
		return ob_get_clean();
	}

	// ── AJAX: filter option lists ─────────────────────────────────────────────

	public function ajax_filters(): void {
		check_ajax_referer( 'eaf_public', 'nonce' );
		$cached = get_transient( 'eaf_filter_options' );
		if ( $cached !== false ) {
			wp_send_json_success( $cached );
		}
		$options = EAF_Query::get_filter_options();
		set_transient( 'eaf_filter_options', $options, HOUR_IN_SECONDS * 12 );
		wp_send_json_success( $options );
	}

	// ── AJAX: agent data ──────────────────────────────────────────────────────

	public function ajax_agents(): void {
		check_ajax_referer( 'eaf_public', 'nonce' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- value is sanitized via array_map( 'sanitize_key', ... ) on the next line.
		$raw_classes = isset( $_POST['sec_class'] ) ? (array) wp_unslash( $_POST['sec_class'] ) : [ 'highsec' ];
		$sec_class   = array_map( 'sanitize_key', $raw_classes );
		$min_jumps   = absint( isset( $_POST['min_jumps'] ) ? wp_unslash( $_POST['min_jumps'] ) : 0 );

		$agents = EAF_Query::get_agents( [
			'sec_class'       => $sec_class,
			'min_lowsec_dist' => $min_jumps,
		] );

		wp_send_json_success( [
			'agents' => $agents,
			'total'  => count( $agents ),
		] );
	}

	// ── AJAX: nearest-to BFS ──────────────────────────────────────────────────

	public function ajax_nearest(): void {
		check_ajax_referer( 'eaf_public', 'nonce' );
		$system_name = sanitize_text_field( wp_unslash( $_POST['system_name'] ?? '' ) );
		if ( ! $system_name ) {
			wp_send_json_error( array( 'message' => 'No system name provided.' ) );
		}
		$result = EAF_Calculator::bfs_from_system( $system_name );
		if ( ! $result['success'] ) {
			wp_send_json_error( $result );
		}
		wp_send_json_success( $result );
	}

	// ── AJAX: system name autocomplete ────────────────────────────────────────

	public function ajax_suggest(): void {
		check_ajax_referer( 'eaf_public', 'nonce' );
		$q = sanitize_text_field( wp_unslash( $_POST['q'] ?? '' ) );
		if ( strlen( $q ) < 2 ) {
			wp_send_json_success( array() );
		}
		wp_send_json_success( EAF_Query::suggest_systems( $q ) );
	}

	// ── AJAX: changelog from readme.txt ───────────────────────────────────────

	public function ajax_changelog(): void {
		check_ajax_referer( 'eaf_public', 'nonce' );
		$readme = EAF_DIR . 'readme.txt';
		if ( ! file_exists( $readme ) ) {
			wp_send_json_error( array( 'message' => 'readme.txt not found.' ) );
		}
		$content = file_get_contents( $readme ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		// Extract everything from == Changelog == onwards
		$pos = strpos( $content, '== Changelog ==' );
		if ( $pos === false ) {
			wp_send_json_error( array( 'message' => 'No changelog section found.' ) );
		}
		$changelog = trim( substr( $content, $pos + strlen( '== Changelog ==' ) ) );
		// Trim at the next == section == if one exists
		if ( preg_match( '/\n== [^=]+ ==/', $changelog, $m, PREG_OFFSET_CAPTURE ) ) {
			$changelog = trim( substr( $changelog, 0, $m[0][1] ) );
		}
		wp_send_json_success( array( 'changelog' => $changelog ) );
	}
}
