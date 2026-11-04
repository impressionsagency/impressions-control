<?php
/**
 * Impressions Control — v0.4 · Module: Change History + Revert
 *
 * Build step 1 of the v0.4 /change feature (see PLUGIN-V04-CHANGE-CHAT-SPEC.md).
 * The SAFETY NET, built before any /change write path exists:
 *   - creates the wp_imp_change_history table
 *   - snapshot(): capture a post's current _elementor_data BEFORE a write
 *   - /change/revert : restore a snapshot, regenerate assets, clear caches,
 *                      and log the revert itself as a new (also-undoable) row
 *   - /change/history: list changes (read-only), for the panel + testing
 *
 * Loaded by impressions-control.php (main file). Reuses that file's:
 *   - IMP_NS          (REST namespace)
 *   - imp_authed()    (Bearer-token permission callback)
 *   - imp_regen_css() (Elementor global file-cache clear)
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class IMP_Change_History {

	/** Bump when the table schema changes. */
	const DB_VERSION        = '1';
	const OPTION_DB_VERSION = 'imp_change_history_db_version';

	public static function init() {
		add_action( 'plugins_loaded', [ __CLASS__, 'maybe_upgrade' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	/* ------------------------------------------------------------------ */
	/*  Table + install / upgrade                                          */
	/* ------------------------------------------------------------------ */

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'imp_change_history';
	}

	/**
	 * Create/upgrade the history table. Safe to run repeatedly (dbDelta diffs).
	 * before_data / after_data hold full _elementor_data snapshots.
	 */
	public static function install() {
		global $wpdb;
		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			change_id   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id     BIGINT UNSIGNED NOT NULL,
			user_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_role   VARCHAR(50)  NOT NULL DEFAULT '',
			mode        VARCHAR(10)  NOT NULL DEFAULT 'live',
			action      VARCHAR(20)  NOT NULL DEFAULT '',
			before_data LONGTEXT     NULL,
			after_data  LONGTEXT     NULL,
			reverts_id  BIGINT UNSIGNED NULL,
			created_at  DATETIME     NOT NULL,
			PRIMARY KEY  (change_id),
			KEY post_id (post_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
	}

	/** Runs install() on fresh installs and on schema-version bumps. */
	public static function maybe_upgrade() {
		if ( get_option( self::OPTION_DB_VERSION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/* ------------------------------------------------------------------ */
	/*  TEST-ONLY — AIOSEO NAP probe (remove with the /nap-probe route)    */
	/* ------------------------------------------------------------------ */

	/**
	 * Read-only dump of AIOSEO Local Business NAP + the raw structure it's
	 * stored in, so we can build the real reader against actual field names.
	 * Reads whatever the site has — no site-specific values in the plugin.
	 */
	public static function probe_aioseo_nap() {
		global $wpdb;
		$out = [ 'found' => [], 'raw' => [] ];

		// 1. Official API if AIOSEO exposes it.
		if ( function_exists( 'aioseo' ) ) {
			try {
				$a = aioseo();
				// Local Business options live under options->localBusiness on modern AIOSEO.
				if ( isset( $a->options ) && isset( $a->options->localBusiness ) ) {
					$lb = $a->options->localBusiness->all();
					$out['raw']['options_localBusiness'] = $lb;
				}
				// Some versions keep it on a dedicated helper.
				if ( isset( $a->helpers ) && method_exists( $a->helpers, 'getLocalBusiness' ) ) {
					$out['raw']['helpers_getLocalBusiness'] = $a->helpers->getLocalBusiness();
				}
			} catch ( \Throwable $e ) {
				$out['api_error'] = $e->getMessage();
			}
		} else {
			$out['api'] = 'aioseo() not available';
		}

		// 2. Fall back to the raw option rows so we always see SOMETHING.
		$rows = $wpdb->get_results(
			"SELECT option_name FROM {$wpdb->options}
			 WHERE option_name LIKE 'aioseo_options%' OR option_name LIKE '%local_business%'"
		);
		$out['option_names'] = wp_list_pluck( $rows, 'option_name' );

		// 3. Pull the likely blobs (incl. Pro) and surface any NAP-ish keys.
		$candidates = [
			'aioseo_options',
			'aioseo_options_pro',
			'aioseo_options_localized',
			'aioseo_options_dynamic',
			'aioseo_options_dynamic_pro',
		];
		foreach ( $candidates as $opt ) {
			$val = get_option( $opt );
			if ( ! $val ) { continue; }
			$decoded = is_string( $val ) ? json_decode( $val, true ) : $val;
			if ( ! is_array( $decoded ) ) { continue; }

			// Recursively find any branch whose keys look like Local Business NAP.
			$hits = [];
			$walk = function ( $node, $path ) use ( &$walk, &$hits ) {
				if ( ! is_array( $node ) ) { return; }
				$keys = array_map( 'strval', array_keys( $node ) );
				$lc   = array_map( 'strtolower', $keys );
				// A NAP-ish branch has several of these sibling keys.
				$signals = array_intersect( $lc, [ 'name', 'street', 'streetaddress', 'address', 'addressline1', 'city', 'state', 'zip', 'postalcode', 'phone', 'email', 'country' ] );
				if ( count( $signals ) >= 2 ) {
					$hits[ $path ] = $node;
				}
				foreach ( $node as $k => $v ) {
					if ( is_array( $v ) ) { $walk( $v, $path . '/' . $k ); }
				}
			};
			$walk( $decoded, $opt );
			if ( $hits ) {
				$out['found'][ $opt ] = array_keys( $hits );
				$out['raw'][ $opt . '_nap_branches' ] = $hits;
			} else {
				// No NAP branch — at least show the top-level keys so we can see the shape.
				$out['raw'][ $opt . '_top_keys' ] = array_keys( $decoded );
			}
		}

		return $out;
	}

	/* ------------------------------------------------------------------ */
	/*  Context helpers                                                    */
	/* ------------------------------------------------------------------ */

	/** build | live — flipped at launch via the imp_site_mode option. */
	public static function site_mode() {
		return get_option( 'imp_site_mode', 'live' ) === 'build' ? 'build' : 'live';
	}

	/* ------------------------------------------------------------------ */
	/*  Snapshot (called by /change BEFORE any write)                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Capture a post's current _elementor_data as a new history row.
	 * Returns the new change_id (int) or WP_Error.
	 *
	 * $args (all optional): user_id, user_role, reverts_id
	 * When called via Bearer (no logged-in WP user), pass user_id/user_role
	 * from the Worker so the audit trail records who triggered the change.
	 */
	public static function snapshot( $post_id, $action, $args = [] ) {
		global $wpdb;

		$post_id = (int) $post_id;
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return new WP_Error( 'imp_bad_post', 'Post not found.', [ 'status' => 404 ] );
		}

		// get_post_meta returns the stored _elementor_data as-is. Store it raw.
		$before = get_post_meta( $post_id, '_elementor_data', true );
		if ( is_array( $before ) ) {
			$before = wp_json_encode( $before );
		}

		$user       = wp_get_current_user();
		$role_guess = ( $user && ! empty( $user->roles ) ) ? reset( $user->roles ) : '';

		$ok = $wpdb->insert(
			self::table(),
			[
				'post_id'     => $post_id,
				'user_id'     => (int) ( $args['user_id']   ?? get_current_user_id() ),
				'user_role'   => (string) ( $args['user_role'] ?? $role_guess ),
				'mode'        => self::site_mode(),
				'action'      => (string) $action,
				'before_data' => $before,
				'after_data'  => null,
				'reverts_id'  => isset( $args['reverts_id'] ) ? (int) $args['reverts_id'] : null,
				'created_at'  => current_time( 'mysql' ),
			],
			[ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
		);

		if ( ! $ok ) {
			return new WP_Error( 'imp_snapshot_failed', 'Could not write change history.', [ 'status' => 500 ] );
		}
		return (int) $wpdb->insert_id;
	}

	/** Record the applied value after a successful write (optional but recommended). */
	public static function record_after( $change_id, $after ) {
		global $wpdb;
		if ( is_array( $after ) ) {
			$after = wp_json_encode( $after );
		}
		$wpdb->update(
			self::table(),
			[ 'after_data' => $after ],
			[ 'change_id' => (int) $change_id ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	/* ------------------------------------------------------------------ */
	/*  Write-back (the PHP stand-in for the browser Elementor save)       */
	/* ------------------------------------------------------------------ */

	/**
	 * Write _elementor_data back to a post, then regenerate assets + clear caches.
	 *
	 * SLASHING: unlike the fresh json_encode in /elementor-data and /gallery, a
	 * revert reads an EXISTING stored value and writes it back. update_metadata()
	 * runs wp_unslash() on write, so we wp_slash() here to reproduce the snapshot
	 * byte-for-byte. Without it, a value containing a unicode escape (\u00e9) or an
	 * escaped quote gets mangled on restore. Do not remove wp_slash() on this path.
	 */
	public static function write_elementor_data( $post_id, $data_json ) {
		$post_id = (int) $post_id;
		if ( is_array( $data_json ) ) {
			$data_json = wp_json_encode( $data_json );
		}
		update_post_meta( $post_id, '_elementor_data', wp_slash( $data_json ) );
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );

		self::regenerate_and_clear( $post_id );
	}

	/**
	 * Mirror of the plugin's /clear-cache route: delete the post's Elementor CSS
	 * (forces regen), clear Elementor's global file cache, then flush host caches.
	 */
	public static function regenerate_and_clear( $post_id ) {
		$post_id = (int) $post_id;

		// Per-page Elementor CSS
		if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
			( new \Elementor\Core\Files\CSS\Post( $post_id ) )->delete();
		}
		// Elementor global file cache
		if ( function_exists( 'imp_regen_css' ) ) {
			imp_regen_css();
		} elseif ( class_exists( '\Elementor\Plugin' ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
		// Host caches (WPE page cache IS available here since we're not mid-REST-bootstrap
		// the same way; still, on WP Engine it may need a manual clear — see PLUGIN-REFERENCE)
		if ( function_exists( 'wpe_cache_flush' ) )     { wpe_cache_flush(); }
		if ( function_exists( 'wp_cache_clear_cache' ) ) { wp_cache_clear_cache(); }
		if ( function_exists( 'w3tc_flush_all' ) )       { w3tc_flush_all(); }
	}

	/* ------------------------------------------------------------------ */
	/*  Revert                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Revert a single change by change_id: snapshot the current state first (so
	 * the revert is ALSO undoable), then write the `before` snapshot back.
	 */
	public static function revert( $change_id, $args = [] ) {
		global $wpdb;

		$change_id = (int) $change_id;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE change_id = %d', $change_id )
		);
		if ( ! $row ) {
			return new WP_Error( 'imp_no_change', 'Change not found.', [ 'status' => 404 ] );
		}
		if ( null === $row->before_data ) {
			return new WP_Error( 'imp_no_before', 'This change has no before-state to restore.', [ 'status' => 409 ] );
		}

		$post_id = (int) $row->post_id;

		// Snapshot current state, tagged as a revert of $change_id.
		$revert_row = self::snapshot( $post_id, 'revert', array_merge( $args, [ 'reverts_id' => $change_id ] ) );
		if ( is_wp_error( $revert_row ) ) {
			return $revert_row;
		}

		// Restore the before-state + regenerate + clear caches.
		self::write_elementor_data( $post_id, $row->before_data );
		self::record_after( $revert_row, $row->before_data );

		return [
			'ok'          => true,
			'reverted'    => $change_id,
			'new_change'  => $revert_row,
			'post_id'     => $post_id,
			'preview_url' => get_permalink( $post_id ),
		];
	}

	/** Newest non-revert change_id, optionally scoped to one post. */
	public static function latest_change_id( $post_id = 0 ) {
		global $wpdb;
		$table = self::table();
		if ( $post_id ) {
			return (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT change_id FROM {$table} WHERE post_id = %d AND action <> 'revert' ORDER BY change_id DESC LIMIT 1",
				$post_id
			) );
		}
		return (int) $wpdb->get_var(
			"SELECT change_id FROM {$table} WHERE action <> 'revert' ORDER BY change_id DESC LIMIT 1"
		);
	}

	/** History list (no before/after blobs — those can be large). */
	public static function history( $post_id = 0, $per_page = 25 ) {
		global $wpdb;
		$table    = self::table();
		$per_page = max( 1, min( 200, (int) $per_page ) );
		$cols     = 'change_id, post_id, user_id, user_role, mode, action, reverts_id, created_at';

		if ( $post_id ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT {$cols} FROM {$table} WHERE post_id = %d ORDER BY change_id DESC LIMIT %d",
				$post_id, $per_page
			) );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT {$cols} FROM {$table} ORDER BY change_id DESC LIMIT %d",
				$per_page
			) );
		}
		return [ 'count' => count( $rows ), 'changes' => $rows ];
	}

	/* ================================================================== */
	/*  /change pipeline (step 2)                                          */
	/* ================================================================== */

	/**
	 * Orchestrator. Body:
	 * {
	 *   "diff": { action, post_id, target:{widget_id,field,locator}, change:{from,to} },
	 *   "exclusions":      ["color-change vehicle wraps", ...],   // phrases
	 *   "exclusion_terms": ["vehicle wrap","wraps","real estate","decal","marketing agency"], // optional, precise
	 *   "confirm_nap":     false,   // must be true to apply a NAP-touching edit
	 *   "user_id": 0, "user_role": ""
	 * }
	 * Never trusts the diff to write — every guardrail must pass first.
	 */
	public static function handle_change( WP_REST_Request $req ) {
		$b    = $req->get_json_params();
		$diff = $b['diff'] ?? null;
		if ( ! is_array( $diff ) ) {
			return self::deny( 'bad_diff', 'Request must include a structured "diff" object.' );
		}

		$action  = (string) ( $diff['action'] ?? '' );
		$post_id = (int) ( $diff['post_id'] ?? 0 );
		$target  = is_array( $diff['target'] ?? null ) ? $diff['target'] : [];
		$change  = is_array( $diff['change'] ?? null ) ? $diff['change'] : [];
		$from    = (string) ( $change['from'] ?? '' );
		$to      = (string) ( $change['to'] ?? '' );
		$wid     = (string) ( $target['widget_id'] ?? '' );

		// ---- GUARDRAIL 1: scope allow-list ------------------------------
		$allowed = [ 'text_edit', 'photo_swap', 'faq_edit' ];
		if ( 'decline' === $action ) {
			return self::deny( 'declined', (string) ( $diff['decline_reason'] ?: 'Declined.' ), [ 'guardrail' => 'scope' ] );
		}
		if ( ! in_array( $action, $allowed, true ) ) {
			return self::deny(
				'out_of_scope',
				'I can update text, photos, and FAQs on this page — a change like that I\'ll flag to the Impressions team for you.',
				[ 'guardrail' => 'scope', 'action' => $action ]
			);
		}
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return self::deny( 'bad_post', 'post_id not found.', [ 'status' => 404 ] );
		}

		$stripped = [];
		$is_text  = in_array( $action, [ 'text_edit', 'faq_edit' ], true );

		if ( $is_text ) {
			// ---- GUARDRAIL 2: display-HTML-only sanitizer ---------------
			$to = self::sanitize_display_html( $to, $stripped );

			// ---- GUARDRAIL 3: exclusions filter -------------------------
			$terms = self::resolve_exclusion_terms( $b );
			$hit   = self::violates_exclusions( $to, $from, $terms );
			if ( $hit ) {
				return self::deny(
					'exclusion',
					'That edit adds something this business doesn\'t offer ("' . $hit . '"), so I can\'t make it.',
					[ 'guardrail' => 'exclusions', 'matched' => $hit ]
				);
			}
		}

		// ---- GUARDRAIL 4: NAP consistency -------------------------------
		$nap         = self::read_canonical_nap();
		$nap_touched = $is_text ? self::touches_nap( $from, $to, $nap ) : false;
		if ( $nap_touched && empty( $b['confirm_nap'] ) ) {
			return self::deny(
				'nap_confirm',
				'This looks like it changes your business contact info, which needs to update everywhere on the site — confirm to proceed.',
				[ 'guardrail' => 'nap', 'nap_touched' => true, 'needs_confirm' => true ]
			);
		}

		// ---- APPLY (to a working copy first; snapshot only if it changes)
		$raw = get_post_meta( $post_id, '_elementor_data', true );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : null );
		if ( ! is_array( $data ) ) {
			return self::deny( 'no_elementor', 'This page has no Elementor data to edit.', [ 'status' => 409 ] );
		}

		$count = 0;
		if ( $is_text ) {
			self::replace_in_html( $data, $wid, $from, $to, $count );
		} else { // photo_swap
			$old_id  = (int) ( $target['locator'] ?? $from );
			$new_id  = (int) $to;
			$new_url = $new_id ? wp_get_attachment_url( $new_id ) : false;
			if ( ! $new_id || ! $new_url ) {
				return self::deny(
					'photo_library_only',
					'Photo swaps must use an image already in your Media Library.',
					[ 'guardrail' => 'library_only' ]
				);
			}
			self::swap_photo( $data, $old_id, $new_id, $new_url, $count );
		}

		if ( $count < 1 ) {
			return self::deny( 'target_not_found', 'Could not find the target text/photo on that page.', [ 'status' => 422 ] );
		}

		$new_json = wp_json_encode( $data );

		// Snapshot current (pre-write) state, then write.
		$ctx = array_filter( [
			'user_id'   => (int) ( $b['user_id'] ?? 0 ),
			'user_role' => (string) ( $b['user_role'] ?? '' ),
		] );
		$change_id = self::snapshot( $post_id, $action, $ctx );
		if ( is_wp_error( $change_id ) ) {
			return self::deny( 'snapshot_failed', $change_id->get_error_message(), [ 'status' => 500 ] );
		}

		self::write_elementor_data( $post_id, $new_json );
		self::record_after( $change_id, $new_json );

		return new WP_REST_Response( [
			'applied'       => true,
			'action'        => $action,
			'change_id'     => $change_id,
			'post_id'       => $post_id,
			'replacements'  => $count,
			'nap_touched'   => $nap_touched,
			'stripped_tags' => $stripped,
			'preview_url'   => get_permalink( $post_id ),
		], 200 );
	}

	/** Uniform decline/deny response — never writes. */
	private static function deny( $code, $message, $extra = [] ) {
		$status = $extra['status'] ?? 200; // guardrail declines are 200 (a valid, handled outcome)
		unset( $extra['status'] );
		return new WP_REST_Response( array_merge( [
			'applied' => false,
			'code'    => $code,
			'message' => $message,
		], $extra ), $status );
	}

	/* ---- guardrail helpers ------------------------------------------- */

	private static function norm( $s ) {
		return strtolower( preg_replace( '/\s+/', ' ', trim( (string) $s ) ) );
	}

	private static function sanitize_display_html( $html, &$stripped ) {
		foreach ( [ 'style', 'script' ] as $tag ) {
			$clean = preg_replace( '#<' . $tag . '\b[^>]*>.*?</' . $tag . '>#is', '', $html );
			if ( $clean !== $html ) { $stripped[] = $tag; $html = $clean; }
		}
		$clean = preg_replace( '/\[[a-z0-9_\-\/][^\]]*\]/i', '', $html );
		if ( $clean !== $html ) { $stripped[] = 'shortcode'; $html = $clean; }
		return $html;
	}

	/** Prefer explicit exclusion_terms; else derive from exclusion phrases. */
	private static function resolve_exclusion_terms( $b ) {
		if ( ! empty( $b['exclusion_terms'] ) && is_array( $b['exclusion_terms'] ) ) {
			return array_values( array_filter( array_map( 'strval', $b['exclusion_terms'] ) ) );
		}
		$terms = [];
		$stop  = [ 'no', 'not', 'a', 'an', 'the', 'of', 'or', 'and', 'small', 'jobs', 'change' ];
		foreach ( (array) ( $b['exclusions'] ?? [] ) as $phrase ) {
			$words = preg_split( '/[\s\-]+/', self::norm( $phrase ) );
			$words = array_values( array_diff( $words, $stop ) );
			if ( $words ) { $terms[] = implode( ' ', $words ); } // meaningful multiword core
		}
		return $terms;
	}

	/** Returns the matched exclusion term if `to` newly introduces it, else null. */
	private static function violates_exclusions( $to, $from, $terms ) {
		$toN   = self::norm( $to );
		$fromN = self::norm( $from );
		foreach ( $terms as $term ) {
			$t = self::norm( $term );
			if ( '' === $t ) { continue; }
			if ( false !== strpos( $toN, $t ) && false === strpos( $fromN, $t ) ) {
				return $term;
			}
		}
		return null;
	}

	/** Read canonical NAP from AIOSEO Pro Local Business (per-site, no hardcoding). */
	public static function read_canonical_nap() {
		$pro = get_option( 'aioseo_options_pro' );
		if ( is_string( $pro ) ) { $pro = json_decode( $pro, true ); }
		if ( ! is_array( $pro ) ) { return null; }

		$loc = $pro['localBusiness']['locations'] ?? null;
		if ( ! is_array( $loc ) ) { return null; }
		$biz = $loc['business'] ?? ( isset( $loc[0] ) ? $loc[0] : null );
		if ( ! is_array( $biz ) ) { return null; }

		$addr = is_array( $biz['address'] ?? null ) ? $biz['address'] : [];
		$con  = is_array( $biz['contact'] ?? null ) ? $biz['contact'] : [];

		return [
			'name'         => (string) ( $biz['name'] ?? '' ),
			'email'        => (string) ( $con['email'] ?? '' ),
			'phone'        => (string) ( $con['phone'] ?? '' ),
			'phone_digits' => preg_replace( '/\D+/', '', (string) ( $con['phone'] ?? '' ) ),
			'street'       => trim( (string) ( $addr['streetLine1'] ?? '' ) ),
			'zip'          => (string) ( $addr['zipCode'] ?? '' ),
		];
	}

	/** True if this edit changes/removes a canonical NAP value, or injects a new phone/email. */
	private static function touches_nap( $from, $to, $nap ) {
		if ( ! $nap ) { return false; }
		$fromN = self::norm( $from );
		$toN   = self::norm( $to );

		// Text tokens (email, street, name, zip): present in FROM but gone/changed in TO.
		foreach ( [ 'email', 'street', 'name', 'zip' ] as $k ) {
			$val = self::norm( $nap[ $k ] ?? '' );
			if ( '' === $val ) { continue; }
			if ( false !== strpos( $fromN, $val ) && false === strpos( $toN, $val ) ) {
				return true;
			}
		}
		// Phone by digits.
		$pd = $nap['phone_digits'] ?? '';
		if ( $pd ) {
			$fromD = preg_replace( '/\D+/', '', $from );
			$toD   = preg_replace( '/\D+/', '', $to );
			if ( false !== strpos( $fromD, $pd ) && false === strpos( $toD, $pd ) ) {
				return true;
			}
		}
		// TO introduces a NEW phone/email that isn't the canonical one.
		if ( preg_match( '/[\w.+-]+@[\w-]+\.[\w.-]+/', $to, $m ) ) {
			if ( ( $nap['email'] ?? '' ) && false === stripos( $m[0], $nap['email'] ) ) { return true; }
		}
		if ( preg_match( '/\+?\d[\d().\-\s]{8,}\d/', $to ) ) {
			$toD = preg_replace( '/\D+/', '', $to );
			if ( $pd && strlen( $toD ) >= 10 && false === strpos( $toD, $pd ) ) { return true; }
		}
		return false;
	}

	/* ---- apply helpers (recursive over the Elementor element tree) ---- */

	private static function replace_in_html( &$elements, $widget_id, $from, $to, &$count ) {
		if ( ! is_array( $elements ) ) { return; }
		foreach ( $elements as &$el ) {
			if ( ! is_array( $el ) ) { continue; }
			$is_html  = ( ( $el['widgetType'] ?? '' ) === 'html' );
			$id_match = ( '' === $widget_id || ( $el['id'] ?? '' ) === $widget_id );
			if ( $is_html && $id_match && isset( $el['settings']['html'] ) && '' !== $from ) {
				$html = $el['settings']['html'];
				if ( false !== strpos( $html, $from ) ) {
					if ( '' !== $widget_id ) {
						$el['settings']['html'] = str_replace( $from, $to, $html );
						$count += substr_count( $html, $from );
					} else {
						$pos = strpos( $html, $from );
						$el['settings']['html'] = substr_replace( $html, $to, $pos, strlen( $from ) );
						$count++;
						return; // untargeted: first occurrence only
					}
				}
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				self::replace_in_html( $el['elements'], $widget_id, $from, $to, $count );
				if ( '' === $widget_id && $count > 0 ) { return; }
			}
		}
	}

	private static function swap_photo( &$elements, $old_id, $new_id, $new_url, &$count ) {
		if ( ! is_array( $elements ) ) { return; }
		foreach ( $elements as &$el ) {
			if ( ! is_array( $el ) ) { continue; }
			if ( ( $el['widgetType'] ?? '' ) === 'gallery' && ! empty( $el['settings']['gallery'] ) && is_array( $el['settings']['gallery'] ) ) {
				foreach ( $el['settings']['gallery'] as &$img ) {
					if ( is_array( $img ) && (int) ( $img['id'] ?? 0 ) === $old_id ) {
						$img['id']  = $new_id;
						$img['url'] = $new_url;
						$count++;
					}
				}
				unset( $img );
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				self::swap_photo( $el['elements'], $old_id, $new_id, $new_url, $count );
			}
		}
	}

	/* ================================================================== */
	/*  QA endpoints (operator-only) — photo audit + swap                 */
	/* ================================================================== */

	/** Decode a post's _elementor_data to array, or null. */
	private static function decode_data( $post_id ) {
		$raw = get_post_meta( (int) $post_id, '_elementor_data', true );
		if ( is_array( $raw ) ) { return $raw; }
		if ( is_string( $raw ) && '' !== $raw ) {
			$d = json_decode( $raw, true );
			return is_array( $d ) ? $d : null;
		}
		return null;
	}

	/**
	 * Precise, per-MATCH occurrence list for ONE specific attachment id — not
	 * per string, per match. Two <img> URLs for the same photo inside one HTML
	 * widget's markup are TWO occurrences here, each individually addressable,
	 * even though they share the same widget_id and context (that happens when
	 * a page is built as one big HTML blob rather than separate widgets — the
	 * widget-level boundary just doesn't exist between them).
	 *
	 * This is the SAME traversal `swap_occurrence()` uses to replace, so the
	 * occurrence index audit reports is guaranteed to be the index a scoped
	 * swap will act on — one source of truth, no separate counters to drift.
	 *
	 * Each record: { occurrence, widget_id, context, snippet }
	 * snippet = ~50 chars of markup immediately before the match, best-effort,
	 * so a caller can tell "icon" from "gallery" even when widget_id/context
	 * are identical — often catches a nearby class="..." or heading.
	 */
	public static function locate_occurrences( $data, $old_id ) {
		$out     = [];
		$seen    = 0;
		$old_url = wp_get_attachment_url( (int) $old_id ); // needed so raw-<img>-url matches are found
		self::walk_occurrences( $data, $old_id, null, null, $old_url, $out, $seen );
		return $out;
	}

	/**
	 * Core walker — collect mode ($new_id === null) or replace mode. Shared by
	 * locate_occurrences() (audit numbering) and swap_occurrence() (the actual
	 * write), so both always agree on what "occurrence N" means.
	 *
	 * $scope: null                              = act on every occurrence (collect: report all; replace: replace all)
	 *         ['occurrence'=>N]                  = only the Nth occurrence (0-based), anywhere on the page
	 *         ['widget_id'=>W]                   = only occurrences inside that widget's subtree (all of them)
	 *         ['widget_id'=>W,'occurrence'=>N]   = only the Nth occurrence WITHIN that widget's subtree
	 */
	private static function walk_occurrences( &$node, $old_id, $new_id, $new_url, $old_url, &$out, &$seen,
		$scope = null, $ctx = 'media', $widget_id = '', $active = null ) {

		if ( ! is_array( $node ) ) { return; }
		if ( null === $active ) { $active = empty( $scope['widget_id'] ); }

		$this_widget_id = ! empty( $node['id'] ) && is_string( $node['id'] ) ? $node['id'] : $widget_id;
		if ( ! $active && ! empty( $scope['widget_id'] ) && $this_widget_id === (string) $scope['widget_id'] ) {
			$active = true; // entered the target subtree — stays true for everything nested inside it
		}

		$collecting  = ( null === $new_id );
		$want_occ    = isset( $scope['occurrence'] ) ? (int) $scope['occurrence'] : null;
		$wtype       = $node['widgetType'] ?? '';

		// {id,url} media reference — one atomic occurrence.
		$is_media_ref = isset( $node['id'], $node['url'] ) && is_numeric( $node['id'] ) && is_string( $node['url'] );
		if ( $is_media_ref && (int) $node['id'] === $old_id ) {
			$idx = $seen++;
			if ( $collecting ) {
				$out[] = [ 'occurrence' => $idx, 'widget_id' => $this_widget_id, 'context' => $ctx, 'snippet' => '' ];
			} elseif ( $active && ( null === $want_occ || $idx === $want_occ ) ) {
				$node['id'] = $new_id; $node['url'] = $new_url; $out++;
			}
		}

		foreach ( $node as $k => &$v ) {
			if ( is_string( $v ) ) {
				if ( $is_media_ref ) { continue; } // don't re-scan the ref's own url/alt scalars

				// wp-image-N classes — decompose per match, not per string.
				if ( false !== strpos( $v, 'wp-image-' . $old_id ) ) {
					$v = self::replace_matches_one_at_a_time(
						$v, '/wp-image-' . $old_id . '\b/', 'wp-image-' . $new_id,
						$old_id, $collecting, $active, $want_occ, $seen, $out, $this_widget_id, 'html', $v
					);
				}
				// Raw uploads URLs — decompose per match, not per string.
				if ( $old_url && false !== strpos( $v, $old_url ) ) {
					$v = self::replace_matches_one_at_a_time(
						$v, '#' . preg_quote( $old_url, '#' ) . '#', $new_url,
						$old_id, $collecting, $active, $want_occ, $seen, $out, $this_widget_id, 'html-url', $v
					);
				}
			} elseif ( is_array( $v ) ) {
				$child_ctx = $wtype ? $wtype : ( is_string( $k ) ? $k : $ctx );
				self::walk_occurrences( $v, $old_id, $new_id, $new_url, $old_url, $out, $seen, $scope, $child_ctx, $this_widget_id, $active );
			}
		}
		unset( $v );
	}

	/**
	 * Walk every regex match in $subject left-to-right, treating each as its
	 * own occurrence: in collect mode, record it (with a snippet of preceding
	 * text for human disambiguation); in replace mode, substitute only the
	 * ones that are in-scope-and-wanted, leaving other matches in the SAME
	 * string untouched. Returns the (possibly modified) string; in collect
	 * mode the string is returned unchanged.
	 */
	private static function replace_matches_one_at_a_time( $subject, $pattern, $replacement, $old_id,
		$collecting, $active, $want_occ, &$seen, &$out, $widget_id, $context, $orig_subject ) {

		$result = '';
		$offset = 0;
		while ( preg_match( $pattern, $subject, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
			$pos    = $m[0][1];
			$match  = $m[0][0];
			$result .= substr( $subject, $offset, $pos - $offset );
			$idx    = $seen++;

			if ( $collecting ) {
				$snip_start = max( 0, $pos - 50 );
				$out[] = [
					'occurrence' => $idx,
					'widget_id'  => $widget_id,
					'context'    => $context,
					'snippet'    => trim( substr( $orig_subject, $snip_start, $pos - $snip_start ) ),
				];
				$result .= $match; // don't mutate while collecting
			} else {
				if ( $active && ( null === $want_occ || $idx === $want_occ ) ) {
					$result .= $replacement;
					$out++; // $out is the count-by-ref in replace mode
				} else {
					$result .= $match;
				}
			}
			$offset = $pos + strlen( $match );
		}
		$result .= substr( $subject, $offset );
		return $result;
	}

	/**
	 * Replace occurrence(s) of $old_id in $data, using the same numbering as
	 * locate_occurrences(). $scope as documented on walk_occurrences(). Returns
	 * the replacement count.
	 */
	private static function swap_occurrence( &$data, $old_id, $new_id, $new_url, $old_url, $scope, &$count ) {
		$seen = 0;
		self::walk_occurrences( $data, $old_id, $new_id, $new_url, $old_url, $count, $seen, $scope );
	}

	/** Collect every image reference in a node tree as [ ['id'=>, 'url'=>, 'context'=>, 'widget_id'=>], ... ]. */
	private static function collect_images( $node, &$usages, $ctx = 'media', $widget_id = '' ) {
		if ( ! is_array( $node ) ) { return; }

		// An {id,url} media reference (image control, gallery item, background).
		$is_media_ref = ( isset( $node['id'], $node['url'] ) && is_numeric( $node['id'] ) && is_string( $node['url'] ) );
		if ( $is_media_ref ) {
			$usages[] = [ 'id' => (int) $node['id'], 'url' => $node['url'], 'context' => $ctx, 'widget_id' => $widget_id ];
		}

		$wtype = $node['widgetType'] ?? '';
		// If this node itself is an element (has its own id), it becomes the
		// nearest-ancestor widget_id for anything nested inside it.
		$this_widget_id = ! empty( $node['id'] ) && is_string( $node['id'] ) ? $node['id'] : $widget_id;

		foreach ( $node as $k => $v ) {
			if ( is_string( $v ) ) {
				// Don't re-scan a media ref's own scalars (its url/alt/etc) — that double-counts.
				if ( $is_media_ref ) { continue; }
				// Inline wp-image-123 classes (HTML widgets, text editors).
				if ( preg_match_all( '/wp-image-(\d+)/', $v, $m ) ) {
					foreach ( $m[1] as $iid ) {
						$usages[] = [ 'id' => (int) $iid, 'url' => '', 'context' => 'html', 'widget_id' => $this_widget_id ];
					}
				}
				// Raw uploads URLs (background CSS, hardcoded <img src>).
				if ( preg_match_all( '#https?://[^\s"\'\)]+/wp-content/uploads/[^\s"\'\)]+\.(?:jpe?g|png|webp|gif)#i', $v, $mu ) ) {
					foreach ( $mu[0] as $url ) {
						$usages[] = [ 'id' => 0, 'url' => $url, 'context' => 'html-url', 'widget_id' => $this_widget_id ];
					}
				}
			} elseif ( is_array( $v ) ) {
				// Pass a friendlier context label down when we know the widget type.
				$child_ctx = $wtype ? $wtype : ( is_string( $k ) ? $k : $ctx );
				self::collect_images( $v, $usages, $child_ctx, $this_widget_id );
			}
		}
	}

	/** Resolve id/url usages → grouped-by-attachment report for one page. */
	private static function audit_page( $post_id ) {
		$data = self::decode_data( $post_id );
		if ( null === $data ) { return null; }

		$usages = [];
		self::collect_images( $data, $usages );

		// Normalise: resolve URL-only refs to an attachment id where possible.
		$by_id = [];
		foreach ( $usages as $u ) {
			$id = (int) $u['id'];
			if ( ! $id && ! empty( $u['url'] ) && function_exists( 'attachment_url_to_postid' ) ) {
				$id = (int) attachment_url_to_postid( $u['url'] );
			}
			if ( ! $id ) { continue; }
			if ( ! isset( $by_id[ $id ] ) ) {
				$by_id[ $id ] = [ 'id' => $id, 'count' => 0, 'contexts' => [], 'occurrences' => [] ];
			}
			$by_id[ $id ]['count']++;
			$by_id[ $id ]['contexts'][]    = $u['context'];
			$by_id[ $id ]['occurrences'][] = [ 'widget_id' => $u['widget_id'], 'context' => $u['context'] ];
		}

		// Enrich + flag on-page repeats (the hero+card+gallery case).
		$repeated = [];
		foreach ( $by_id as $id => &$row ) {
			$row['filename'] = self::attachment_filename( $id );
			$row['url']      = wp_get_attachment_url( $id );
			$row['contexts'] = array_values( array_unique( $row['contexts'] ) );
			if ( $row['count'] >= 2 ) {
				// Precise per-match occurrences (not per-widget) — handles the case
				// where the SAME image appears twice inside one HTML blob (no separate
				// widget boundary between them). Each occurrence carries a snippet of
				// surrounding markup so a caller can tell them apart even when
				// widget_id/context come back identical (e.g. two <img> tags in one
				// HTML widget). scope:{"occurrence":N} on swap-image targets exactly one.
				$row['occurrences'] = self::locate_occurrences( $data, $id );
				$repeated[] = $row;
			}
		}
		unset( $row );

		usort( $repeated, function ( $a, $b ) { return $b['count'] <=> $a['count']; } );

		return [
			'post_id'          => (int) $post_id,
			'slug'             => get_post_field( 'post_name', $post_id ),
			'images_used'      => count( $by_id ),
			'repeated_on_page' => $repeated,   // PRIMARY flag
			'by_id'            => array_values( $by_id ),
		];
	}

	private static function attachment_filename( $id ) {
		$file = get_post_meta( (int) $id, '_wp_attached_file', true );
		if ( $file ) { return basename( $file ); }
		$url = wp_get_attachment_url( $id );
		return $url ? basename( parse_url( $url, PHP_URL_PATH ) ) : '';
	}

	/**
	 * /photo-audit — one page (post_id given) or the whole site (omitted).
	 * PRIMARY signal: same image used 2+ times ON ONE PAGE (hero/card/gallery).
	 * SECONDARY: same image across multiple pages (soft — often legitimate).
	 */
	public static function photo_audit( $post_id = 0 ) {
		if ( $post_id ) {
			$page = self::audit_page( $post_id );
			if ( null === $page ) {
				return [ 'error' => 'No Elementor data on that page.', 'post_id' => (int) $post_id ];
			}
			return [ 'scope' => 'page' ] + $page;
		}

		// Site-wide: iterate published pages.
		$ids = get_posts( [
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => 500,
			'fields'         => 'ids',
		] );

		$pages_out   = [];
		$cross       = []; // id => [post_ids]
		foreach ( $ids as $pid ) {
			$page = self::audit_page( $pid );
			if ( null === $page ) { continue; }
			if ( ! empty( $page['repeated_on_page'] ) ) {
				$pages_out[] = [
					'post_id'          => $page['post_id'],
					'slug'             => $page['slug'],
					'repeated_on_page' => $page['repeated_on_page'],
				];
			}
			foreach ( $page['by_id'] as $row ) {
				$cross[ $row['id'] ][] = $page['slug'];
			}
		}

		$cross_page = [];
		foreach ( $cross as $id => $slugs ) {
			$slugs = array_values( array_unique( $slugs ) );
			if ( count( $slugs ) >= 2 ) {
				$cross_page[] = [
					'id'       => $id,
					'filename' => self::attachment_filename( $id ),
					'pages'    => $slugs,
				];
			}
		}

		return [
			'scope'                    => 'site',
			'pages_with_repeats'       => $pages_out,   // PRIMARY
			'cross_page_reuse'         => $cross_page,  // SECONDARY (soft)
			'pages_scanned'            => count( $ids ),
		];
	}

	/**
	 * /swap-image — replace old_id with new_id on a page. By default, EVERYWHERE
	 * on the page (gallery, image widget, background, inline HTML) — same as
	 * before. Optionally pass "scope" to target just ONE occurrence when the same
	 * image is shared across independent sections (e.g. an icon grid AND a
	 * gallery) — swapping unscoped in that case just relocates the duplicate
	 * rather than removing it.
	 *
	 *   scope: { "occurrence": 1 }        — replace only the 2nd occurrence (0-based)
	 *                                        of old_id anywhere on the page, in the
	 *                                        SAME order photo-audit's occurrences[]
	 *                                        reports. Use this when two instances of
	 *                                        the same photo live in ONE HTML widget
	 *                                        (e.g. an icon area and a gallery both
	 *                                        built as raw markup) — there's no widget
	 *                                        boundary between them, so widget_id alone
	 *                                        can't tell them apart, but occurrence can.
	 *   scope: { "widget_id": "abc123" }   — replace only inside that one widget/
	 *                                        container (get widget_id per instance
	 *                                        from photo-audit's occurrences[]).
	 *   scope: { "widget_id": "abc123", "occurrence": 0 } — the Nth occurrence WITHIN
	 *                                        that widget specifically (combine both
	 *                                        when a widget itself contains multiple
	 *                                        instances of the same image).
	 *   scope: { "context": "gallery" }    — replace only inside widgets whose type
	 *                                        matches (e.g. "gallery" vs "icon-list").
	 *   omit "scope" entirely              — original behavior, replace everywhere.
	 *
	 * Snapshots first, so it's revertible either way. Rejects a swap that would
	 * put new_id on a page where it's already used (would_duplicate) unless
	 * allow_duplicate:true — checked page-wide regardless of scope.
	 */
	public static function handle_swap_image( WP_REST_Request $req ) {
		$b       = $req->get_json_params();
		$post_id = (int) ( $b['post_id'] ?? 0 );
		$old_id  = (int) ( $b['old_id'] ?? 0 );
		$new_id  = (int) ( $b['new_id'] ?? 0 );
		$allow   = ! empty( $b['allow_duplicate'] );
		$scope   = is_array( $b['scope'] ?? null ) ? $b['scope'] : [];

		if ( ! $post_id || ! get_post( $post_id ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'post_id not found.' ], 404 );
		}
		if ( ! $old_id || ! $new_id ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'old_id and new_id are required.' ], 400 );
		}
		$new_url = wp_get_attachment_url( $new_id );
		if ( ! $new_url ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'new_id is not in the Media Library.' ], 422 );
		}
		$old_url = wp_get_attachment_url( $old_id );

		$data = self::decode_data( $post_id );
		if ( null === $data ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'No Elementor data on that page.' ], 409 );
		}

		// Anti-duplicate: is new_id already on this page? Checked PAGE-WIDE
		// regardless of scope — we never want to introduce a duplicate anywhere.
		if ( ! $allow ) {
			$usages = [];
			self::collect_images( $data, $usages );
			foreach ( $usages as $u ) {
				$uid = (int) $u['id'];
				if ( ! $uid && ! empty( $u['url'] ) ) { $uid = (int) attachment_url_to_postid( $u['url'] ); }
				if ( $uid === $new_id ) {
					return new WP_REST_Response( [
						'ok'      => false,
						'code'    => 'would_duplicate',
						'message' => 'That image is already used on this page — swapping it in here would repeat it. Pass allow_duplicate:true to override.',
						'new_id'  => $new_id,
					], 409 );
				}
			}
		}

		$count = 0;
		if ( ! empty( $scope['context'] ) ) {
			// widget-type matching stays a separate, coarser path
			self::swap_with_context( $data, $old_id, $new_id, $new_url, $old_url, self::norm( $scope['context'] ), $count );
		} else {
			// Everything else — including the default (no scope = replace every
			// occurrence) — goes through the precise per-match walker, so the
			// "replacements" count is always accurate (one per actual match, not
			// one per string that happened to contain 1+ matches).
			$scoped = [];
			if ( ! empty( $scope['widget_id'] ) ) { $scoped['widget_id']  = (string) $scope['widget_id']; }
			if ( isset( $scope['occurrence'] ) )  { $scoped['occurrence'] = (int) $scope['occurrence']; }
			self::swap_occurrence( $data, $old_id, $new_id, $new_url, $old_url, $scoped ?: null, $count );
		}

		if ( $count < 1 ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'old_id was not found on that page (within the given scope, if any).' ], 422 );
		}

		$new_json  = wp_json_encode( $data );
		$change_id = self::snapshot( $post_id, 'photo_swap' );
		if ( is_wp_error( $change_id ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => $change_id->get_error_message() ], 500 );
		}
		self::write_elementor_data( $post_id, $new_json );
		self::record_after( $change_id, $new_json );

		return new WP_REST_Response( [
			'ok'           => true,
			'change_id'    => $change_id,
			'post_id'      => $post_id,
			'old_id'       => $old_id,
			'new_id'       => $new_id,
			'scope'        => $scope ?: null,
			'replacements' => $count,
			'preview_url'  => get_permalink( $post_id ),
		], 200 );
	}

	/** Replace an attachment everywhere in a subtree: {id,url} refs, wp-image-N classes, raw URLs. */
	private static function swap_image_everywhere( &$node, $old_id, $new_id, $new_url, $old_url, &$count ) {
		if ( ! is_array( $node ) ) { return; }

		if ( isset( $node['id'], $node['url'] ) && is_numeric( $node['id'] ) && (int) $node['id'] === $old_id ) {
			$node['id']  = $new_id;
			$node['url'] = $new_url;
			$count++;
		}

		foreach ( $node as $k => &$v ) {
			if ( is_string( $v ) ) {
				$orig = $v;
				$v = preg_replace( '/wp-image-' . $old_id . '\b/', 'wp-image-' . $new_id, $v );
				if ( $old_url ) { $v = str_replace( $old_url, $new_url, $v ); }
				if ( $v !== $orig ) { $count++; }
			} elseif ( is_array( $v ) ) {
				self::swap_image_everywhere( $v, $old_id, $new_id, $new_url, $old_url, $count );
			}
		}
		unset( $v );
	}

	/**
	 * Scope a swap to widgets whose type/label matches $want_context (case-
	 * insensitive substring, e.g. "gallery" matches widgetType "gallery"; "icon"
	 * matches "icon-list"). Looser than widget_id — useful when the caller knows
	 * WHICH SECTION to fix but not the exact widget id.
	 */
	private static function swap_with_context( &$elements, $old_id, $new_id, $new_url, $old_url, $want_context, &$count, $ctx = '' ) {
		if ( ! is_array( $elements ) ) { return; }
		foreach ( $elements as &$el ) {
			if ( ! is_array( $el ) ) { continue; }
			$el_ctx = (string) ( $el['widgetType'] ?? $el['elType'] ?? $ctx );
			if ( '' !== $want_context && false !== stripos( $el_ctx, $want_context ) && isset( $el['settings'] ) ) {
				self::swap_image_everywhere( $el['settings'], $old_id, $new_id, $new_url, $old_url, $count );
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				self::swap_with_context( $el['elements'], $old_id, $new_id, $new_url, $old_url, $want_context, $count, $el_ctx );
			}
		}
		unset( $el );
	}

	/* ------------------------------------------------------------------ */
	/*  REST routes  (namespace + auth reused from the main plugin)        */
	/* ------------------------------------------------------------------ */

	public static function register_routes() {

		/* -------------------------------------------------------------- *
		 * TEST-ONLY — remove when the real /change pipeline ships.        *
		 * Creates a snapshot row via curl so revert can be proven without *
		 * SSH / wp eval. Gated by imp_authed (operator key only).         *
		 * -------------------------------------------------------------- */
		register_rest_route( IMP_NS, '/change/snapshot', [
			'methods'             => 'POST',
			'permission_callback' => 'imp_authed',
			'args'                => [
				'post_id' => [ 'type' => 'integer', 'required' => true ],
				'action'  => [ 'type' => 'string',  'required' => false, 'default' => 'text_edit' ],
			],
			'callback' => function ( WP_REST_Request $req ) {
				$post_id = (int) $req->get_param( 'post_id' );
				$action  = (string) ( $req->get_param( 'action' ) ?: 'text_edit' );
				$id      = self::snapshot( $post_id, $action );
				if ( is_wp_error( $id ) ) {
					$data   = $id->get_error_data();
					$status = ( is_array( $data ) && isset( $data['status'] ) ) ? $data['status'] : 400;
					return new WP_REST_Response( [ 'error' => $id->get_error_message() ], $status );
				}
				return new WP_REST_Response( [ 'ok' => true, 'change_id' => $id, 'post_id' => $post_id ], 200 );
			},
		] );

		/* -------------------------------------------------------------- *
		 * TEST-ONLY probe — remove once the NAP reader is baked into      *
		 * /change. Read-only. Dumps AIOSEO Local Business NAP so we can   *
		 * see the exact storage shape on a real site. Agency-safe: it     *
		 * reads whatever AIOSEO has on the site it runs on — no values in *
		 * the plugin. Gated by imp_authed (operator key only).           *
		 * -------------------------------------------------------------- */
		register_rest_route( IMP_NS, '/nap-probe', [
			'methods'             => 'GET',
			'permission_callback' => 'imp_authed',
			'callback'            => function () {
				return new WP_REST_Response( self::probe_aioseo_nap(), 200 );
			},
		] );

		register_rest_route( IMP_NS, '/change/revert', [
			'methods'             => 'POST',
			'permission_callback' => 'imp_qa_authed', // v0.4.6: was imp_authed (Bearer-only) — QA sessions need this too
			'args'                => [
				'change_id' => [ 'type' => 'integer', 'required' => false ],
				'post_id'   => [ 'type' => 'integer', 'required' => false ],
				'user_id'   => [ 'type' => 'integer', 'required' => false ],
				'user_role' => [ 'type' => 'string',  'required' => false ],
			],
			'callback' => function ( WP_REST_Request $req ) {
				$change_id = (int) $req->get_param( 'change_id' );
				if ( ! $change_id ) { // omit id → most recent (optionally per-post)
					$change_id = self::latest_change_id( (int) $req->get_param( 'post_id' ) );
				}
				if ( ! $change_id ) {
					return new WP_REST_Response( [ 'error' => 'No change to revert.' ], 404 );
				}
				$ctx = array_filter( [
					'user_id'   => (int) $req->get_param( 'user_id' ),
					'user_role' => (string) $req->get_param( 'user_role' ),
				] );
				$result = self::revert( $change_id, $ctx );
				if ( is_wp_error( $result ) ) {
					$data   = $result->get_error_data();
					$status = ( is_array( $data ) && isset( $data['status'] ) ) ? $data['status'] : 400;
					return new WP_REST_Response( [ 'error' => $result->get_error_message() ], $status );
				}
				return new WP_REST_Response( $result, 200 );
			},
		] );

		register_rest_route( IMP_NS, '/change/history', [
			'methods'             => 'GET',
			'permission_callback' => 'imp_qa_authed', // v0.4.6: was imp_authed (Bearer-only)
			'args'                => [
				'post_id'  => [ 'type' => 'integer', 'required' => false ],
				'per_page' => [ 'type' => 'integer', 'required' => false, 'default' => 25 ],
			],
			'callback' => function ( WP_REST_Request $req ) {
				return new WP_REST_Response(
					self::history( (int) $req->get_param( 'post_id' ), (int) $req->get_param( 'per_page' ) ),
					200
				);
			},
		] );

		/* -------------------------------------------------------------- *
		 * /change  (v0.4 step 2 — hand-written-diff mode; no model yet)   *
		 * Accepts a structured diff, runs it through the four guardrails, *
		 * and only on a full pass: snapshot -> apply -> save + cache.     *
		 * The Worker/Claude interpreter that PRODUCES the diff is step 3; *
		 * here the diff is supplied directly so the gate is provable on   *
		 * inputs you control.                                             *
		 * -------------------------------------------------------------- */
		register_rest_route( IMP_NS, '/change', [
			'methods'             => 'POST',
			'permission_callback' => 'imp_authed',
			'callback'            => [ __CLASS__, 'handle_change' ],
		] );

		/* -------------------------------------------------------------- *
		 * QA endpoints (v0.4 — operator-only, NO client guardrails).      *
		 * Auth: imp_qa_authed = Bearer OR logged-in admin + nonce, so     *
		 * Claude-in-Chrome uses them with no key. Reuse the proven        *
		 * snapshot + save + cache path, so QA edits stay revertible.      *
		 * -------------------------------------------------------------- */
		register_rest_route( IMP_NS, '/photo-audit', [
			'methods'             => 'GET',
			'permission_callback' => 'imp_qa_authed',
			'args'                => [
				'post_id' => [ 'type' => 'integer', 'required' => false ], // omit → whole site
			],
			'callback' => function ( WP_REST_Request $req ) {
				return new WP_REST_Response( self::photo_audit( (int) $req->get_param( 'post_id' ) ), 200 );
			},
		] );

		register_rest_route( IMP_NS, '/swap-image', [
			'methods'             => 'POST',
			'permission_callback' => 'imp_qa_authed',
			'callback'            => [ __CLASS__, 'handle_swap_image' ],
		] );
	}
}

IMP_Change_History::init();

/*
 * ------------------------------------------------------------------------
 *  MANUAL TEST (proves revert works before /change or the panel exist)
 * ------------------------------------------------------------------------
 *  1. Snapshot a good page state (WP-CLI on the site):
 *
 *     wp eval 'echo IMP_Change_History::snapshot( 123, "text_edit" );'
 *        → prints the new change_id, e.g. 1
 *
 *  2. Break the page on purpose: edit page 123 in Elementor (or via /elementor-data)
 *     so the front end visibly changes.
 *
 *  3. Revert it:
 *
 *     curl -s -X POST "$IMP_URL/wp-json/impressions/v1/change/revert" \
 *       -H "Authorization: Bearer $IMP_KEY" \
 *       -H "Content-Type: application/json" \
 *       -d '{"change_id":1}'
 *
 *  4. Confirm the front end (cache-busted, e.g. ?x=1) is back to the step-1 state,
 *     and GET /change/history shows the original change + a new "revert" row.
 * ------------------------------------------------------------------------
 */
