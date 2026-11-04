<?php
/**
 * Impressions Control — v0.9 · Module: Design Transfer
 *
 * Carries a design built in Claude into WordPress without losing styling,
 * animation or scripts. Built from the 2026-10-15 staging test, which found:
 *   1. /page and /elementor-data strip or lose <style>/<script>.
 *   2. /elementor-data saved JSON without wp_slash(), so any double quote in
 *      the design corrupted the data and Elementor rendered nothing.
 *   3. Pages created through the API have no author (post_author 0), so
 *      Elementor treats their HTML as untrusted and strips <style>/<script>.
 *
 * The fix is structural, not a filter tweak:
 *   - Design CSS and JS never travel inside page content. They are saved as
 *     versioned files under uploads/impressions-design/ and enqueued on every
 *     front-end page (POST /design/assets). WordPress never sanitizes them.
 *   - Pages and Theme Builder templates are written as real Elementor data,
 *     slashed correctly, owned by an administrator (POST /design/page,
 *     POST /design/template).
 *   - Global colors/fonts/width go into the active Elementor kit FIRST
 *     (POST /design/kit), so pages and templates reference globals.
 *   - Large media (video, fonts, images) import from allow-listed URLs
 *     (POST /design/asset-import).
 *   - v0.9.1: CSS, JS and Elementor JSON can be pulled from an allowed GitHub
 *     URL (css_url / js_url / elementor_data_url) with a sha256 fingerprint
 *     check, instead of travelling inside the request body.
 *   - Everything can be read back (GET /design/page/{id}, GET /design/assets)
 *     so the launch report can verify what landed.
 *
 * Recommended order for a build: kit → assets → templates → pages.
 *
 * Auth: imp_qa_authed — Bearer key (Hub) OR a logged-in Administrator with a
 * REST nonce (Claude-in-Chrome QA). Never reachable by client Editors.
 * Publishing still requires confirm_publish:true, same rule as /posts.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class IMP_Design {

	const ASSETS_OPT     = 'imp_design_assets';
	const KIT_HISTORY    = 'imp_design_kit_history';
	const DIR            = 'impressions-design';
	const HISTORY_KEEP   = 5;
	const IMPORT_MAX     = 64 * 1024 * 1024; // 64 MB (videos)
	const IMPORT_HOSTS   = [ '.r2.dev', '.r2.cloudflarestorage.com', 'cdn.leonardo.ai', 'impressions.agency', '.impressions.agency' ];
	const IMPORT_MIME    = [
		'image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/svg+xml', 'image/gif',
		'video/mp4', 'video/webm',
		'font/woff2', 'font/woff', 'application/font-woff', 'application/font-woff2', 'application/octet-stream',
	];
	/* v0.9.1 — build files can be pulled from GitHub instead of being sent in the
	   request body. Only these URL prefixes are accepted (override with the
	   imp_design_source_prefixes option, an array of https:// prefixes). */
	const SOURCE_PREFIXES = [ 'https://raw.githubusercontent.com/impressionsagency/' ];
	const SOURCE_MAX      = 12 * 1024 * 1024;
	const TEMPLATE_TYPES = [ 'header', 'footer', 'single-post', 'single-page', 'archive', 'search-results', 'error-404', 'section', 'popup', 'loop-item' ];
	const PAGE_TEMPLATES = [ 'elementor_header_footer', 'elementor_canvas', 'default' ];

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ], 100 );
		add_filter( 'upload_mimes', [ __CLASS__, 'allow_font_mimes' ] );
	}

	/* ------------------------------------------------------------------ */
	/* Routes                                                              */
	/* ------------------------------------------------------------------ */

	public static function register_routes() {
		$auth = 'imp_qa_authed';

		register_rest_route( IMP_NS, '/design/kit', [
			[ 'methods' => 'GET',  'permission_callback' => $auth, 'callback' => [ __CLASS__, 'get_kit' ] ],
			[ 'methods' => 'POST', 'permission_callback' => $auth, 'callback' => [ __CLASS__, 'set_kit' ] ],
		] );
		register_rest_route( IMP_NS, '/design/assets', [
			[ 'methods' => 'GET',  'permission_callback' => $auth, 'callback' => [ __CLASS__, 'get_assets' ] ],
			[ 'methods' => 'POST', 'permission_callback' => $auth, 'callback' => [ __CLASS__, 'set_assets' ] ],
		] );
		register_rest_route( IMP_NS, '/design/asset-import', [
			'methods' => 'POST', 'permission_callback' => $auth, 'callback' => [ __CLASS__, 'asset_import' ],
		] );
		register_rest_route( IMP_NS, '/design/template', [
			'methods' => 'POST', 'permission_callback' => $auth, 'callback' => [ __CLASS__, 'write_template' ],
		] );
		register_rest_route( IMP_NS, '/design/page', [
			'methods' => 'POST', 'permission_callback' => $auth, 'callback' => [ __CLASS__, 'write_page' ],
		] );
		register_rest_route( IMP_NS, '/design/page/(?P<id>\d+)', [
			'methods' => 'GET', 'permission_callback' => $auth, 'callback' => [ __CLASS__, 'read_page' ],
		] );
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	/** The administrator who "owns" design writes, so Elementor trusts the
	 *  HTML (unfiltered_html). Override with the imp_design_author option. */
	public static function author_id() {
		$opt = (int) get_option( 'imp_design_author', 0 );
		if ( $opt && user_can( $opt, 'unfiltered_html' ) ) { return $opt; }
		if ( get_current_user_id() && current_user_can( 'unfiltered_html' ) ) { return get_current_user_id(); }
		$admins = get_users( [ 'role' => 'administrator', 'orderby' => 'ID', 'order' => 'ASC', 'number' => 1, 'fields' => 'ID' ] );
		return $admins ? (int) $admins[0] : 0;
	}

	/** Run $fn as the design author so wp_insert_post / kses see an admin. */
	private static function as_author( callable $fn ) {
		$prev = get_current_user_id();
		$aid  = self::author_id();
		if ( $aid && $aid !== $prev ) { wp_set_current_user( $aid ); }
		try { return $fn( $aid ); }
		finally { if ( $aid && $aid !== $prev ) { wp_set_current_user( $prev ); } }
	}

	private static function err( $code, $msg, $status = 400 ) {
		return new WP_Error( $code, $msg, [ 'status' => $status ] );
	}

	/** Elementor needs every element to carry a unique id. Fill any missing. */
	private static function ensure_ids( &$elements ) {
		if ( ! is_array( $elements ) ) { return; }
		foreach ( $elements as &$el ) {
			if ( ! is_array( $el ) ) { continue; }
			if ( empty( $el['id'] ) ) { $el['id'] = substr( md5( uniqid( '', true ) ), 0, 7 ); }
			if ( ! isset( $el['settings'] ) || ! is_array( $el['settings'] ) ) { $el['settings'] = (object) []; }
			if ( ! isset( $el['elements'] ) ) { $el['elements'] = []; }
			if ( ! isset( $el['isInner'] ) ) { $el['isInner'] = false; }
			if ( is_array( $el['elements'] ) ) { self::ensure_ids( $el['elements'] ); }
		}
	}

	/** Write Elementor data (slashed, snapshotted, CSS regenerated). */
	private static function save_elementor( $post_id, $data, $action ) {
		self::ensure_ids( $data );
		$change_id = null;
		if ( class_exists( 'IMP_Change_History' ) && get_post_meta( $post_id, '_elementor_data', true ) ) {
			$snap = IMP_Change_History::snapshot( $post_id, $action );
			if ( ! is_wp_error( $snap ) ) { $change_id = $snap; }
		}
		$json = wp_json_encode( $data );
		if ( class_exists( 'IMP_Change_History' ) ) {
			IMP_Change_History::write_elementor_data( $post_id, $json ); // wp_slash + regen + cache clear
			if ( $change_id ) { IMP_Change_History::record_after( $change_id, $json ); }
		} else {
			update_post_meta( $post_id, '_elementor_data', wp_slash( $json ) );
			update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
			imp_regen_css();
		}
		if ( defined( 'ELEMENTOR_VERSION' ) ) { update_post_meta( $post_id, '_elementor_version', ELEMENTOR_VERSION ); }
		return [ 'bytes' => strlen( $json ), 'change_id' => $change_id ];
	}

	private static function publish_guard( $b, $default = 'draft' ) {
		$status = $b['status'] ?? $default;
		if ( ! in_array( $status, [ 'draft', 'publish', 'private', 'pending' ], true ) ) {
			return self::err( 'bad_status', 'status must be draft, publish, private or pending.' );
		}
		if ( 'publish' === $status && empty( $b['confirm_publish'] ) ) {
			return self::err( 'confirm_publish_required', 'status:"publish" requires confirm_publish:true in the same request.' );
		}
		return $status;
	}

	private static function source_ok( $url ) {
		$prefixes = get_option( 'imp_design_source_prefixes', self::SOURCE_PREFIXES );
		foreach ( (array) $prefixes as $p ) {
			if ( is_string( $p ) && 0 === strpos( $url, $p ) ) { return true; }
		}
		return false;
	}

	/** Fetch a build file (CSS, JS, Elementor JSON) from an allowed URL and, when
	 *  $sha is given, refuse it unless its sha256 starts with $sha. */
	public static function fetch_text( $url, $sha = '' ) {
		$url = esc_url_raw( (string) $url );
		if ( ! $url || 0 !== strpos( $url, 'https://' ) ) { return self::err( 'bad_url', 'Source URL must be https://' ); }
		if ( ! self::source_ok( $url ) ) { return self::err( 'source_not_allowed', 'Source URL is not under an allowed prefix.', 403 ); }
		$r = wp_remote_get( $url, [ 'timeout' => 60, 'limit_response_size' => self::SOURCE_MAX, 'headers' => [ 'Cache-Control' => 'no-cache' ] ] );
		if ( is_wp_error( $r ) ) { return $r; }
		$code = wp_remote_retrieve_response_code( $r );
		if ( 200 !== (int) $code ) { return self::err( 'fetch_failed', 'Source returned HTTP ' . $code, 502 ); }
		$body = wp_remote_retrieve_body( $r );
		$hash = hash( 'sha256', $body );
		if ( $sha && 0 !== strpos( $hash, strtolower( $sha ) ) ) {
			return self::err( 'sha_mismatch', 'Fetched file fingerprint ' . substr( $hash, 0, 12 ) . ' does not match ' . $sha, 409 );
		}
		return $body;
	}

	/** elementor_data inline, or elementor_data_url (+ elementor_data_sha). */
	private static function resolve_data( $b ) {
		if ( is_array( $b['elementor_data'] ?? null ) ) { return $b['elementor_data']; }
		if ( ! empty( $b['elementor_data_url'] ) ) {
			$txt = self::fetch_text( $b['elementor_data_url'], $b['elementor_data_sha'] ?? '' );
			if ( is_wp_error( $txt ) ) { return $txt; }
			$d = json_decode( $txt, true );
			if ( ! is_array( $d ) ) { return self::err( 'bad_data', 'elementor_data_url did not contain a JSON array.' ); }
			return $d;
		}
		return self::err( 'bad_data', 'Send elementor_data (array) or elementor_data_url.' );
	}

	private static function upload_dir() {
		$u = wp_upload_dir();
		return [ trailingslashit( $u['basedir'] ) . self::DIR, trailingslashit( $u['baseurl'] ) . self::DIR ];
	}

	/* ------------------------------------------------------------------ */
	/* Kit (step 1)                                                         */
	/* ------------------------------------------------------------------ */

	public static function get_kit() {
		return [ 'kit_id' => imp_kit_id(), 'settings' => imp_get_kit_settings() ];
	}

	/** Body: { settings:{...}, replace?:bool }. Saves the old kit for undo. */
	public static function set_kit( WP_REST_Request $req ) {
		$id = imp_kit_id();
		if ( ! $id ) { return self::err( 'no_kit', 'No active Elementor kit.', 409 ); }
		$b = (array) $req->get_json_params();
		$settings = $b['settings'] ?? null;
		if ( ! empty( $b['undo'] ) ) {
			$hist = (array) get_option( self::KIT_HISTORY, [] );
			$prev = array_pop( $hist );
			if ( ! $prev ) { return self::err( 'no_history', 'No earlier kit to restore.', 409 ); }
			update_post_meta( $id, '_elementor_page_settings', $prev['settings'] );
			update_option( self::KIT_HISTORY, $hist, false );
			imp_regen_css();
			return [ 'ok' => true, 'kit_id' => $id, 'restored_from' => $prev['at'] ];
		}
		if ( ! is_array( $settings ) ) { return self::err( 'bad_settings', 'settings must be an object.' ); }
		$current = imp_get_kit_settings();
		$hist    = (array) get_option( self::KIT_HISTORY, [] );
		$hist[]  = [ 'at' => current_time( 'mysql' ), 'settings' => $current ];
		update_option( self::KIT_HISTORY, array_slice( $hist, -self::HISTORY_KEEP ), false );
		$new = ! empty( $b['replace'] ) ? $settings : array_merge( $current, $settings );
		update_post_meta( $id, '_elementor_page_settings', $new );
		imp_regen_css();
		return [ 'ok' => true, 'kit_id' => $id, 'applied_keys' => array_keys( $settings ), 'replaced' => ! empty( $b['replace'] ) ];
	}

	/* ------------------------------------------------------------------ */
	/* Site-wide CSS / JS (step 2)                                          */
	/* ------------------------------------------------------------------ */

	public static function get_assets() {
		$a = get_option( self::ASSETS_OPT, [] );
		return [ 'ok' => true, 'assets' => $a ?: null ];
	}

	/** Body: { css?, js?, label? } — omitted parts are kept; "" clears.
	 *  Or { undo:true } to restore the previous version. */
	public static function set_assets( WP_REST_Request $req ) {
		$b   = (array) $req->get_json_params();
		$opt = (array) get_option( self::ASSETS_OPT, [] );
		[ $dir, $url ] = self::upload_dir();
		if ( ! wp_mkdir_p( $dir ) ) { return self::err( 'no_dir', 'Could not create uploads/' . self::DIR, 500 ); }
		if ( ! file_exists( $dir . '/index.php' ) ) { @file_put_contents( $dir . '/index.php', "<?php // silence\n" ); }

		if ( ! empty( $b['undo'] ) ) {
			$hist = (array) ( $opt['history'] ?? [] );
			$prev = array_pop( $hist );
			if ( ! $prev ) { return self::err( 'no_history', 'No earlier assets to restore.', 409 ); }
			$prev['history'] = $hist;
			update_option( self::ASSETS_OPT, $prev, false );
			self::flush_caches();
			return [ 'ok' => true, 'restored' => $prev['ver'] ?? null, 'css_url' => $prev['css_url'] ?? null, 'js_url' => $prev['js_url'] ?? null ];
		}

		$next = [
			'css_url' => $opt['css_url'] ?? null, 'js_url' => $opt['js_url'] ?? null,
			'css_file' => $opt['css_file'] ?? null, 'js_file' => $opt['js_file'] ?? null,
		];
		$written = [];
		foreach ( [ 'css', 'js' ] as $kind ) {
			if ( ! empty( $b[ $kind . '_url' ] ) ) {
				$txt = self::fetch_text( $b[ $kind . '_url' ], $b[ $kind . '_sha' ] ?? '' );
				if ( is_wp_error( $txt ) ) { return $txt; }
				$b[ $kind ] = $txt;
			}
			if ( ! array_key_exists( $kind, $b ) ) { continue; }
			$code = (string) $b[ $kind ];
			if ( '' === $code ) { $next[ $kind . '_url' ] = null; $next[ $kind . '_file' ] = null; $written[] = $kind . ':cleared'; continue; }
			$hash = substr( hash( 'sha256', $code ), 0, 12 );
			$name = 'site-' . $hash . '.' . $kind;
			if ( false === @file_put_contents( $dir . '/' . $name, $code ) ) { return self::err( 'write_failed', 'Could not write ' . $name, 500 ); }
			$next[ $kind . '_url' ]  = $url . '/' . $name;
			$next[ $kind . '_file' ] = $name;
			$written[] = $kind . ':' . strlen( $code ) . 'b';
		}
		$next['ver']   = substr( md5( ( $next['css_file'] ?? '' ) . ( $next['js_file'] ?? '' ) ), 0, 10 );
		$next['label'] = sanitize_text_field( $b['label'] ?? '' );
		$next['at']    = current_time( 'mysql' );
		$hist = (array) ( $opt['history'] ?? [] );
		if ( ! empty( $opt['ver'] ) ) { unset( $opt['history'] ); $hist[] = $opt; }
		$next['history'] = array_slice( $hist, -self::HISTORY_KEEP );
		update_option( self::ASSETS_OPT, $next, false );
		self::flush_caches();
		return [ 'ok' => true, 'written' => $written, 'ver' => $next['ver'], 'css_url' => $next['css_url'], 'js_url' => $next['js_url'] ];
	}

	public static function enqueue_assets() {
		$a = get_option( self::ASSETS_OPT, [] );
		if ( empty( $a ) ) { return; }
		$ver = $a['ver'] ?? IMP_VERSION;
		if ( ! empty( $a['css_url'] ) ) { wp_enqueue_style( 'imp-design', $a['css_url'], [], $ver ); }
		if ( ! empty( $a['js_url'] ) ) {
			wp_enqueue_script( 'imp-design', $a['js_url'], [], $ver, [ 'in_footer' => true, 'strategy' => 'defer' ] );
		}
	}

	private static function flush_caches() {
		imp_regen_css();
		if ( function_exists( 'wpe_cache_flush' ) ) { wpe_cache_flush(); }
		if ( function_exists( 'wp_cache_clear_cache' ) ) { wp_cache_clear_cache(); }
		if ( function_exists( 'w3tc_flush_all' ) ) { w3tc_flush_all(); }
	}

	public static function allow_font_mimes( $m ) {
		$m['woff']  = 'font/woff';
		$m['woff2'] = 'font/woff2';
		return $m;
	}

	/* ------------------------------------------------------------------ */
	/* Media / fonts / video import                                        */
	/* ------------------------------------------------------------------ */

	private static function host_ok( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( ! $host ) { return false; }
		foreach ( self::IMPORT_HOSTS as $e ) {
			if ( 0 === strpos( $e, '.' ) ) {
				if ( $host === ltrim( $e, '.' ) || substr( $host, -strlen( $e ) ) === $e ) { return true; }
			} elseif ( $host === $e ) { return true; }
		}
		return false;
	}

	/** Body: { url, filename?, alt_text?, title? }. Images and videos go to the
	 *  Media Library; fonts go to uploads/impressions-design/fonts/. */
	public static function asset_import( WP_REST_Request $req ) {
		$b   = (array) $req->get_json_params();
		$url = esc_url_raw( $b['url'] ?? '' );
		if ( ! $url || 0 !== stripos( $url, 'https://' ) ) { return self::err( 'bad_url', 'url must be https://' ); }
		if ( ! self::host_ok( $url ) && ! self::source_ok( $url ) ) { return self::err( 'host_not_allowed', 'Host not on the design import allowlist.', 403 ); }

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$filename = sanitize_file_name( $b['filename'] ?? basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
		$ext      = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		$tmp      = download_url( $url, 120 );
		if ( is_wp_error( $tmp ) ) { return $tmp; }
		$size = @filesize( $tmp );
		if ( ! $size ) { @unlink( $tmp ); return self::err( 'empty_download', 'Downloaded file was empty.', 502 ); }
		if ( $size > self::IMPORT_MAX ) { @unlink( $tmp ); return self::err( 'too_large', 'File is over 64 MB.', 413 ); }

		$sha = hash_file( 'sha256', $tmp );

		// Fonts: plain file in our folder, no attachment.
		if ( in_array( $ext, [ 'woff2', 'woff' ], true ) ) {
			[ $dir, $base ] = self::upload_dir();
			wp_mkdir_p( $dir . '/fonts' );
			$dest = $dir . '/fonts/' . $filename;
			if ( ! @copy( $tmp, $dest ) ) { @unlink( $tmp ); return self::err( 'write_failed', 'Could not save font.', 500 ); }
			@unlink( $tmp );
			return [ 'ok' => true, 'kind' => 'font', 'url' => $base . '/fonts/' . $filename, 'bytes' => $size ];
		}

		$mime = wp_check_filetype( $filename )['type'] ?: '';
		if ( ! in_array( $mime, self::IMPORT_MIME, true ) ) { @unlink( $tmp ); return self::err( 'bad_type', 'Type "' . $mime . '" not allowed.', 415 ); }

		$dupe = get_posts( [ 'post_type' => 'attachment', 'posts_per_page' => 1, 'meta_key' => '_imp_sha256', 'meta_value' => $sha, 'fields' => 'ids' ] );
		if ( $dupe ) {
			@unlink( $tmp );
			return [ 'ok' => true, 'kind' => strtok( $mime, '/' ), 'media_id' => (int) $dupe[0], 'url' => wp_get_attachment_url( $dupe[0] ), 'duplicate' => true ];
		}
		$id = self::as_author( function () use ( $tmp, $filename, $b ) {
			return media_handle_sideload( [ 'name' => $filename, 'tmp_name' => $tmp ], 0, $b['title'] ?? null );
		} );
		if ( is_wp_error( $id ) ) { @unlink( $tmp ); return $id; }
		update_post_meta( $id, '_imp_sha256', $sha );
		wp_update_post( [ 'ID' => $id, 'post_content' => '', 'post_excerpt' => '' ] );
		if ( ! empty( $b['alt_text'] ) ) { update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $b['alt_text'] ) ); }
		return [ 'ok' => true, 'kind' => strtok( $mime, '/' ), 'media_id' => (int) $id, 'url' => wp_get_attachment_url( $id ), 'bytes' => $size, 'duplicate' => false ];
	}

	/* ------------------------------------------------------------------ */
	/* Theme Builder templates (step 3)                                     */
	/* ------------------------------------------------------------------ */

	/** Body: { id?, slug?, title, type, elementor_data:[], conditions?:[], status?, confirm_publish? }
	 *  conditions e.g. ["include/general"] (whole site) or ["include/singular/post"]. */
	public static function write_template( WP_REST_Request $req ) {
		$b    = (array) $req->get_json_params();
		$type = (string) ( $b['type'] ?? '' );
		if ( ! in_array( $type, self::TEMPLATE_TYPES, true ) ) {
			return self::err( 'bad_type', 'type must be one of: ' . implode( ', ', self::TEMPLATE_TYPES ) );
		}
		$data = self::resolve_data( $b );
		if ( is_wp_error( $data ) ) { return $data; }
		$b['elementor_data'] = $data;
		$status = self::publish_guard( $b, 'draft' );
		if ( is_wp_error( $status ) ) { return $status; }
		$title = sanitize_text_field( $b['title'] ?? ucfirst( $type ) );
		$slug  = sanitize_title( $b['slug'] ?? ( 'imp-' . $type . '-' . $title ) );

		$id = (int) ( $b['id'] ?? 0 );
		if ( ! $id ) {
			$found = get_posts( [ 'post_type' => 'elementor_library', 'name' => $slug, 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids' ] );
			$id = $found ? (int) $found[0] : 0;
		} elseif ( 'elementor_library' !== get_post_type( $id ) ) {
			return self::err( 'wrong_type', 'id ' . $id . ' is not an Elementor template.', 409 );
		}

		$res = self::as_author( function ( $aid ) use ( $id, $title, $slug, $status ) {
			$data = [ 'post_type' => 'elementor_library', 'post_title' => $title, 'post_name' => $slug, 'post_status' => $status, 'post_author' => $aid ];
			if ( $id ) { $data['ID'] = $id; return wp_update_post( $data, true ); }
			return wp_insert_post( $data, true );
		} );
		if ( is_wp_error( $res ) ) { return $res; }
		$id = (int) $res;

		update_post_meta( $id, '_elementor_template_type', $type );
		wp_set_object_terms( $id, $type, 'elementor_library_type' );
		$saved = self::save_elementor( $id, $b['elementor_data'], 'design_template' );

		$conditions = array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $b['conditions'] ?? [] ) ) ) );
		if ( array_key_exists( 'conditions', $b ) ) {
			update_post_meta( $id, '_elementor_conditions', $conditions );
			self::regen_conditions();
		}
		return [ 'ok' => true, 'template_id' => $id, 'type' => $type, 'status' => $status, 'conditions' => $conditions, 'bytes' => $saved['bytes'], 'change_id' => $saved['change_id'] ];
	}

	private static function regen_conditions() {
		try {
			if ( class_exists( '\ElementorPro\Modules\ThemeBuilder\Module' ) ) {
				\ElementorPro\Modules\ThemeBuilder\Module::instance()->get_conditions_manager()->get_cache()->regenerate();
			}
		} catch ( \Throwable $e ) {
			// Conditions still apply after Elementor's own next regeneration.
		}
	}

	/* ------------------------------------------------------------------ */
	/* Pages (step 4)                                                       */
	/* ------------------------------------------------------------------ */

	/** Body: { id?, slug, title, elementor_data:[], template?, parent_slug?, menu_order?,
	 *          page_settings?:{}, status?, confirm_publish? } */
	public static function write_page( WP_REST_Request $req ) {
		$b = (array) $req->get_json_params();
		$data = self::resolve_data( $b );
		if ( is_wp_error( $data ) ) { return $data; }
		$b['elementor_data'] = $data;
		$status = self::publish_guard( $b, 'draft' );
		if ( is_wp_error( $status ) ) { return $status; }
		$title    = sanitize_text_field( $b['title'] ?? '' );
		$slug     = sanitize_title( $b['slug'] ?? $title );
		if ( ! $slug ) { return self::err( 'bad_slug', 'slug or title is required.' ); }
		$template = in_array( $b['template'] ?? '', self::PAGE_TEMPLATES, true ) ? $b['template'] : 'elementor_header_footer';

		$parent = 0;
		if ( ! empty( $b['parent_slug'] ) ) {
			$p = get_page_by_path( sanitize_title( $b['parent_slug'] ), OBJECT, 'page' );
			if ( ! $p ) { return self::err( 'no_parent', 'Parent page "' . $b['parent_slug'] . '" not found. Push the parent first.', 409 ); }
			$parent = $p->ID;
		}

		$id = (int) ( $b['id'] ?? 0 );
		if ( $id && 'page' !== get_post_type( $id ) ) {
			return self::err( 'wrong_type', 'id ' . $id . ' is a ' . get_post_type( $id ) . ', not a page.', 409 );
		}
		if ( ! $id ) {
			$path = $parent ? get_page_uri( $parent ) . '/' . $slug : $slug;
			$p    = get_page_by_path( $path, OBJECT, 'page' );
			$id   = $p ? $p->ID : 0;
		}
		$created = ! $id;

		$res = self::as_author( function ( $aid ) use ( $id, $title, $slug, $status, $parent, $template, $b ) {
			$data = [
				'post_type' => 'page', 'post_title' => $title, 'post_name' => $slug, 'post_status' => $status,
				'post_parent' => $parent, 'post_author' => $aid, 'page_template' => $template,
			];
			if ( isset( $b['menu_order'] ) ) { $data['menu_order'] = (int) $b['menu_order']; }
			if ( $id ) { $data['ID'] = $id; return wp_update_post( $data, true ); }
			$data['post_content'] = '';
			return wp_insert_post( $data, true );
		} );
		if ( is_wp_error( $res ) ) { return $res; }
		$id = (int) $res;

		update_post_meta( $id, '_wp_page_template', $template );
		if ( is_array( $b['page_settings'] ?? null ) ) { update_post_meta( $id, '_elementor_page_settings', $b['page_settings'] ); }
		$saved = self::save_elementor( $id, $b['elementor_data'], 'design_page' );

		return [
			'ok' => true, 'page_id' => $id, 'created' => $created, 'status' => $status, 'slug' => $slug,
			'url' => get_permalink( $id ), 'template' => $template, 'bytes' => $saved['bytes'], 'change_id' => $saved['change_id'],
		];
	}

	public static function read_page( WP_REST_Request $req ) {
		$id = (int) $req['id'];
		$p  = get_post( $id );
		if ( ! $p ) { return self::err( 'bad_post', 'Not found.', 404 ); }
		$raw  = get_post_meta( $id, '_elementor_data', true );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		return [
			'ok' => true, 'id' => $id, 'post_type' => $p->post_type, 'status' => $p->post_status,
			'title' => $p->post_title, 'slug' => $p->post_name, 'url' => get_permalink( $id ),
			'author' => (int) $p->post_author, 'author_can_unfiltered_html' => user_can( (int) $p->post_author, 'unfiltered_html' ),
			'template' => get_post_meta( $id, '_wp_page_template', true ),
			'template_type' => get_post_meta( $id, '_elementor_template_type', true ),
			'conditions' => get_post_meta( $id, '_elementor_conditions', true ),
			'edit_mode' => get_post_meta( $id, '_elementor_edit_mode', true ),
			'elementor_ok' => is_array( $data ), 'elementor_bytes' => is_string( $raw ) ? strlen( $raw ) : 0,
			'elementor_data' => $data,
		];
	}
}

IMP_Design::init();
