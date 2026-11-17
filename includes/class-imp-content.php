<?php
/**
 * Impressions Control — v0.7 · Module: Content Operations
 *
 * Blog posts, media import/metadata, and per-post AIOSEO fields. All
 * endpoints here are Bearer-only (imp_authed), same as /page and
 * /elementor-data — agency-side callers, no client-facing exposure.
 *
 * v0.6.0 changes (per hub-plugin-change-brief.md, 2026-10-02):
 *   - NEW: POST /media-import — download-and-sideload an image from an
 *     allow-listed URL into the Media Library with real WP thumbnails,
 *     alt/caption/title, optional post attach, and a sha256 duplicate guard.
 *   - handle_write_post now honors categories (name or id, auto-creates
 *     missing terms), excerpt, featured_image_url (imports then sets the
 *     thumbnail), and a scheduled_for alias for date. Every write endpoint
 *     now returns `updated` + `ignored` so the caller can verify what
 *     actually landed.
 *   - handle_get_seo / handle_set_seo accept both the canonical field names
 *     and the aliases the brief's test run actually sent
 *     (seo_title/meta_description/focus_keyphrase), plus the wider AIOSEO
 *     field set (additional_keywords, og_*, twitter_*, schema_type).
 *     Unknown fields are reported in `ignored` instead of silently dropped.
 *     handle_set_seo best-effort triggers AIOSEO's own analyzer so
 *     handle_get_seo can return a real score; on any site/version where
 *     that hook isn't available it falls back to the saved score column
 *     (exactly what handle_get_seo already reads).
 *
 * v0.7.0 changes (Round 2 of hub-plugin-change-brief.md, found testing
 * v0.6.0 against a live draft):
 *   - FIX (highest priority): status:"future" could silently publish
 *     immediately instead of scheduling. handle_write_post now parses
 *     `date`/`scheduled_for` explicitly against the site timezone
 *     (wp_timezone()), computes post_date + post_date_gmt itself, rejects
 *     an unparseable or past date for a "future" request (never falls back
 *     to publishing), and re-reads the saved post after the write to
 *     confirm WordPress actually kept it scheduled — returning an error
 *     instead of a false "ok" if it didn't. status:"publish" now requires
 *     an explicit confirm_publish:true, so a bad/ignored date can never
 *     result in a live publish.
 *   - FIX: `id` is now accepted as an alias for `post_id` (never silently
 *     creates a new post when an id is passed). On an update, omitted
 *     `title`/`content` now leave the existing values untouched instead of
 *     being blanked to ''.
 *   - FIX: imported attachments no longer get a placeholder caption/
 *     description — both are now explicitly set (to the provided `caption`
 *     or '' otherwise) instead of left to whatever media_handle_sideload()
 *     defaulted them to. `featured_image_alt` on /posts now reaches
 *     _wp_attachment_image_alt (previously ignored).
 *   - NEW (optional, D): /posts accepts `content_images: [{url, alt?,
 *     caption?}]` — each is imported the same way as featured_image_url,
 *     and matching `<img src="...">` URLs already in `content` are
 *     rewritten to the new Media Library URL.
 *   - FIX (E): `keywords` is now accepted as an alias for
 *     additional_keywords on /seo (was silently ignored).
 *   - (F) Categories are confirmed assigned via wp_set_post_categories()
 *     (which calls wp_set_post_terms() on the `category` taxonomy) by term
 *     id, matched by name — not by slug — so "Blog" resolves to the
 *     existing term regardless of slug. Also best-effort sets AIOSEO's
 *     primary-term meta for the first resolved category (unverified
 *     against a live install — same caveat as the other best-effort AIOSEO
 *     fields below; wrapped so a miss here never fails the category write
 *     itself).
 *
 * v0.8.0 changes (Round 3 of hub-plugin-change-brief.md, 2026-10-02 —
 * schema/JSON-LD for AEO):
 *   - NEW: POST /posts accepts an optional `jsonld` field — a JSON-LD
 *     object or array of objects (FAQPage, Article, or any other
 *     schema.org type the caller builds). Stored as post meta
 *     (`_imp_jsonld`), independent of AIOSEO's own schema output (never
 *     touches AIOSEO's `schema` column — see the brief's Option 1 vs
 *     Option 2 discussion for why). Sending `jsonld: []` or `jsonld: null`
 *     clears it. A non-array/non-object value is reported in `ignored`
 *     rather than silently stored.
 *   - NEW: on the public-facing singular post view, a `wp_head`-hooked
 *     method prints whatever's saved in `_imp_jsonld` as one
 *     `<script type="application/ld+json">` tag (array of objects wrapped
 *     as a JSON array; a single object printed as-is). Encoded with
 *     wp_json_encode()'s default slash-escaping (JSON_UNESCAPED_SLASHES is
 *     deliberately NOT used) so a string value containing "</script>" in
 *     the JSON can't break out of the tag.
 *   - NEW: handle_get_seo now also returns the saved `jsonld` (or null)
 *     alongside the existing AIOSEO fields, so a caller can confirm what's
 *     live without a separate endpoint.
 *
 * Loaded by impressions-control.php (main file). Reuses that file's:
 *   - IMP_NS       (REST namespace)
 *   - imp_authed() (Bearer-token permission callback)
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class IMP_Content {

	/** Hosts POST /media-import is allowed to fetch from. Suffix-matched
	 *  (a leading "." in the list entry means "this host or any subdomain"). */
	const MEDIA_IMPORT_ALLOWLIST = [
		'.r2.dev',
		'cdn.leonardo.ai',
		'impressions.agency',
		// v0.9.2 — exact hostnames (no leading "."). Never widen the S3 one to
		// a "*.s3.us-west-2.amazonaws.com" suffix: that trusts every bucket in the region.
		'img.prohost.ai',
		'hostaway-platform.s3.us-west-2.amazonaws.com',
	];

	/** Hard cap on what POST /media-import will download, in bytes. */
	const MEDIA_IMPORT_MAX_BYTES = 10 * 1024 * 1024; // 10 MB

	/** Content types POST /media-import will accept. */
	const MEDIA_IMPORT_ALLOWED_MIME = [ 'image/jpeg', 'image/png', 'image/webp' ];

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
		// Round 3: print whatever JSON-LD is saved on the post being viewed.
		add_action( 'wp_head', [ __CLASS__, 'print_jsonld' ] );
	}

	public static function register_routes() {

		/* ------------------------------------------------------------ *
		 * POST /posts — create or update a blog post.
		 * ------------------------------------------------------------ */
		register_rest_route( IMP_NS, '/posts', [
			'methods'             => 'POST',
			'permission_callback' => 'imp_authed',
			'callback'            => [ __CLASS__, 'handle_write_post' ],
		] );

		register_rest_route( IMP_NS, '/posts', [
			'methods'             => 'GET',
			'permission_callback' => 'imp_authed',
			'callback'            => [ __CLASS__, 'handle_list_posts' ],
		] );

		register_rest_route( IMP_NS, '/posts/(?P<id>\d+)', [
			'methods'             => 'GET',
			'permission_callback' => 'imp_authed',
			'callback'            => [ __CLASS__, 'handle_get_post' ],
		] );

		register_rest_route( IMP_NS, '/media', [
			'methods'             => 'GET',
			'permission_callback' => 'imp_authed',
			'callback'            => [ __CLASS__, 'handle_list_media' ],
		] );

		register_rest_route( IMP_NS, '/media/(?P<id>\d+)', [
			'methods'             => 'POST',
			'permission_callback' => 'imp_authed',
			'callback'            => [ __CLASS__, 'handle_update_media' ],
		] );

		/* ------------------------------------------------------------ *
		 * POST /media-import — NEW (v0.6.0). Download an image from an
		 * allow-listed https URL and sideload it into the Media Library
		 * with real WP thumbnail sizes, metadata, and a dedup guard.
		 * Body: { url, filename?, alt_text?, caption?, title?, post_id? }
		 * See MEDIA_IMPORT_ALLOWLIST / MEDIA_IMPORT_MAX_BYTES above.
		 * ------------------------------------------------------------ */
		register_rest_route( IMP_NS, '/media-import', [
			'methods'             => 'POST',
			'permission_callback' => 'imp_authed',
			'callback'            => [ __CLASS__, 'handle_media_import' ],
		] );

		register_rest_route( IMP_NS, '/seo', [
			'methods'             => 'GET',
			'permission_callback' => 'imp_authed',
			'args'                => [ 'post_id' => [ 'type' => 'integer', 'required' => true ] ],
			'callback'            => [ __CLASS__, 'handle_get_seo' ],
		] );

		register_rest_route( IMP_NS, '/seo', [
			'methods'             => 'POST',
			'permission_callback' => 'imp_authed',
			'callback'            => [ __CLASS__, 'handle_set_seo' ],
		] );
	}

	/* ------------------------------------------------------------------ */
	/*  Posts                                                              */
	/* ------------------------------------------------------------------ */

	/** Resolve a mixed list of category names/ids to term ids, creating any
	 *  named category that doesn't exist yet. Returns [ term_ids, created ]. */
	private static function resolve_categories( $list ) {
		$ids     = [];
		$created = [];
		foreach ( (array) $list as $entry ) {
			if ( is_numeric( $entry ) ) {
				$ids[] = (int) $entry;
				continue;
			}
			$name = sanitize_text_field( (string) $entry );
			if ( '' === $name ) { continue; }
			$term = get_term_by( 'name', $name, 'category' );
			if ( ! $term ) {
				$inserted = wp_insert_term( $name, 'category' );
				if ( is_wp_error( $inserted ) ) { continue; }
				$ids[]     = (int) $inserted['term_id'];
				$created[] = $name;
			} else {
				$ids[] = (int) $term->term_id;
			}
		}
		return [ $ids, $created ];
	}

	/** Best-effort: tell AIOSEO which term is the "primary term" for this
	 *  post's category taxonomy, so AIOSEO's own UI/schema treats it as
	 *  such (round 2, item F). AIOSEO's primary-term storage key has moved
	 *  between versions and this hasn't been confirmed against a live
	 *  install — same caveat as the other best-effort AIOSEO fields below —
	 *  so it's wrapped: a miss here never fails the category write itself,
	 *  which already succeeded via wp_set_post_categories() above. */
	private static function maybe_set_aioseo_primary_category( $post_id, $term_id ) {
		try {
			update_post_meta( $post_id, '_aioseo_primary_term_category', (int) $term_id );
		} catch ( \Throwable $e ) {
			// Best-effort only — see docblock above.
		}
	}

	/** Parse a caller-supplied date string against the site's configured
	 *  timezone (round 2, item A). Returns [ post_date, post_date_gmt ]
	 *  (both "Y-m-d H:i:s") on success, or a WP_Error on an unparseable
	 *  string. Never silently falls back to "now". */
	private static function parse_site_date( $date_in ) {
		$tz = wp_timezone();
		try {
			$dt = new DateTime( (string) $date_in, $tz );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'bad_date', 'Could not parse "date" — use "Y-m-d H:i:s" in the site timezone (' . $tz->getName() . ').', [ 'status' => 400 ] );
		}
		$post_date = $dt->format( 'Y-m-d H:i:s' );
		$dt_utc    = clone $dt;
		$dt_utc->setTimezone( new DateTimeZone( 'UTC' ) );
		return [ $post_date, $dt_utc->format( 'Y-m-d H:i:s' ), $dt, $tz ];
	}

	public static function handle_write_post( WP_REST_Request $req ) {
		$b       = $req->get_json_params();
		// `id` is accepted as an alias for `post_id` (round 2, item B) — never
		// silently falls through to creating a new post when an id was passed.
		$post_id = (int) ( $b['post_id'] ?? ( $b['id'] ?? 0 ) );

		$updated = [];
		$ignored = [];
		$known   = [
			'post_id', 'id', 'title', 'content', 'status', 'date', 'scheduled_for',
			'slug', 'categories', 'tags', 'excerpt', 'featured_media',
			'featured_image_url', 'featured_image_alt', 'content_images',
			'confirm_publish', 'jsonld',
		];
		foreach ( array_keys( (array) $b ) as $field ) {
			if ( ! in_array( $field, $known, true ) ) { $ignored[] = $field; }
		}

		if ( $post_id && ! get_post( $post_id ) ) {
			return new WP_Error( 'bad_post', 'post_id not found.', [ 'status' => 404 ] );
		}
		// v0.9 FIX: this endpoint forces post_type 'post', so updating a page's
		// id here silently converted the page into a blog post (seen in the
		// 2026-10-15 staging test). Refuse instead.
		if ( $post_id && 'post' !== get_post_type( $post_id ) ) {
			return new WP_Error( 'wrong_type', 'id ' . $post_id . ' is a ' . get_post_type( $post_id ) . ', not a blog post. Use /page or /design/page for pages.', [ 'status' => 409 ] );
		}

		$allowed_status = [ 'draft', 'publish', 'future', 'pending', 'private' ];
		$requested      = $b['status'] ?? 'draft';
		if ( ! in_array( $requested, $allowed_status, true ) ) {
			return new WP_Error( 'bad_status', 'status must be one of: ' . implode( ', ', $allowed_status ) . '.', [ 'status' => 400 ] );
		}
		// Safety guardrail (round 2, item A): the Hub's default is draft-first.
		// An explicit live publish requires confirm_publish:true — an invalid
		// or ignored schedule must never fall through to a publish.
		if ( 'publish' === $requested && empty( $b['confirm_publish'] ) ) {
			return new WP_Error( 'confirm_publish_required', 'status:"publish" requires confirm_publish:true in the same request. Use status:"draft" (default) to stage it, or status:"future" with a "date" to schedule it instead.', [ 'status' => 400 ] );
		}
		$status = $requested;

		$post_data = [ 'post_type' => 'post', 'post_status' => $status ];

		if ( $post_id ) {
			// Update: only touch title/content when the caller actually sent
			// them, so an omitted field leaves the existing value alone
			// (round 2, item B — previously this always blanked them to '').
			if ( array_key_exists( 'title', $b ) )   { $post_data['post_title']   = sanitize_text_field( $b['title'] ); $updated[] = 'title'; }
			if ( array_key_exists( 'content', $b ) ) { $post_data['post_content'] = $b['content']; $updated[] = 'content'; }
		} else {
			$post_data['post_title']   = sanitize_text_field( $b['title'] ?? '' );
			$post_data['post_content'] = $b['content'] ?? '';
			$updated[] = 'title';
			$updated[] = 'content';
		}
		$updated[] = 'status';

		// `scheduled_for` is accepted as an alias for `date` (the brief's naming);
		// both are parsed against the site's configured timezone, not assumed.
		$date_in = $b['date'] ?? ( $b['scheduled_for'] ?? null );

		if ( 'future' === $status ) {
			if ( empty( $date_in ) ) {
				return new WP_Error( 'no_date', 'status:"future" needs a "date" (or "scheduled_for"), e.g. "2026-10-03 11:00:00" in the site timezone. Never falls back to publishing.', [ 'status' => 400 ] );
			}
			$parsed = self::parse_site_date( $date_in );
			if ( is_wp_error( $parsed ) ) { return $parsed; }
			[ $post_date, $post_date_gmt, $dt, $tz ] = $parsed;
			$now = new DateTime( 'now', $tz );
			if ( $dt <= $now ) {
				return new WP_Error( 'date_in_past', 'The "date" for a scheduled (future) post must be after the current site time (' . $now->format( 'Y-m-d H:i:s' ) . ' ' . $tz->getName() . '). Rejected rather than published.', [ 'status' => 400 ] );
			}
			$post_data['post_date']     = $post_date;
			$post_data['post_date_gmt'] = $post_date_gmt;
			$post_data['edit_date']     = true;
			$updated[] = 'date';
		} elseif ( ! empty( $date_in ) ) {
			// A date on a non-scheduled write (e.g. backdating a draft) — same
			// parse/validate, but no future-only requirement.
			$parsed = self::parse_site_date( $date_in );
			if ( is_wp_error( $parsed ) ) { return $parsed; }
			[ $post_date, $post_date_gmt ] = $parsed;
			$post_data['post_date']     = $post_date;
			$post_data['post_date_gmt'] = $post_date_gmt;
			$post_data['edit_date']     = true;
			$updated[] = 'date';
		}

		if ( ! empty( $b['slug'] ) ) {
			$post_data['post_name'] = sanitize_title( $b['slug'] );
			$updated[] = 'slug';
		}
		if ( array_key_exists( 'excerpt', $b ) ) {
			$post_data['post_excerpt'] = sanitize_text_field( $b['excerpt'] );
			$updated[] = 'excerpt';
		}

		if ( $post_id ) {
			$post_data['ID'] = $post_id;
			$result = wp_update_post( $post_data, true );
		} else {
			$result = wp_insert_post( $post_data, true );
		}
		if ( is_wp_error( $result ) ) { return $result; }
		$post_id = $result;

		// Re-read from the DB rather than trusting $post_data — WordPress core
		// silently downgrades status:"future" to "publish" if it doesn't
		// consider post_date_gmt to actually be in the future (round 2, item
		// A). We validated that above, but if the site's WP timezone setting
		// doesn't actually match what the caller assumed, surface that as an
		// error instead of reporting a false "ok".
		$saved = get_post( $post_id );
		if ( 'future' === $status && $saved->post_status !== 'future' ) {
			return new WP_Error(
				'schedule_failed',
				'WordPress did not keep this post scheduled (saved status: "' . $saved->post_status . '"). This usually means the site\'s configured timezone (Settings → General) doesn\'t match the "date" given. The post was not left published if it was previously a draft/pending — check hub_post_get.',
				[ 'status' => 500 ]
			);
		}

		$categories_created = [];
		if ( ! empty( $b['categories'] ) && is_array( $b['categories'] ) ) {
			[ $cat_ids, $categories_created ] = self::resolve_categories( $b['categories'] );
			if ( $cat_ids ) {
				// wp_set_post_categories() -> wp_set_post_terms() on the
				// `category` taxonomy, by term id (never by slug/name string),
				// so a name match against an existing "Blog" term (regardless
				// of its slug) is what actually gets assigned (round 2, item F).
				wp_set_post_categories( $post_id, $cat_ids );
				self::maybe_set_aioseo_primary_category( $post_id, $cat_ids[0] );
			}
			$updated[] = 'categories';
		}
		if ( ! empty( $b['tags'] ) && is_array( $b['tags'] ) ) {
			wp_set_post_tags( $post_id, array_map( 'sanitize_text_field', $b['tags'] ) );
			$updated[] = 'tags';
		}

		$featured_media_id = null;
		if ( ! empty( $b['featured_media'] ) ) {
			$featured_media_id = (int) $b['featured_media'];
			set_post_thumbnail( $post_id, $featured_media_id );
			$updated[] = 'featured_media';
		} elseif ( ! empty( $b['featured_image_url'] ) ) {
			$imported = self::import_media_from_url( [
				'url'      => $b['featured_image_url'],
				'alt_text' => $b['featured_image_alt'] ?? null,
				'post_id'  => $post_id,
			] );
			if ( is_wp_error( $imported ) ) {
				$ignored[] = 'featured_image_url (' . $imported->get_error_message() . ')';
			} else {
				$featured_media_id = (int) $imported['media_id'];
				set_post_thumbnail( $post_id, $featured_media_id );
				$updated[] = 'featured_image_url';
				if ( ! empty( $b['featured_image_alt'] ) ) { $updated[] = 'featured_image_alt'; }
			}
		}

		// NEW (round 2, item D): import each content_images[] entry and rewrite
		// matching <img src> URLs already in the post's content to the new
		// Media Library URL, so inline images can be imported without a
		// scratch post the way featured_image_url already does.
		$content_images_imported = [];
		if ( ! empty( $b['content_images'] ) && is_array( $b['content_images'] ) ) {
			$content_now = get_post_field( 'post_content', $post_id );
			$changed     = false;
			foreach ( $b['content_images'] as $img ) {
				$src = is_array( $img ) ? ( $img['url'] ?? '' ) : '';
				if ( '' === $src ) { continue; }
				$imported = self::import_media_from_url( [
					'url'      => $src,
					'alt_text' => is_array( $img ) ? ( $img['alt'] ?? null ) : null,
					'caption'  => is_array( $img ) ? ( $img['caption'] ?? null ) : null,
					'post_id'  => $post_id,
				] );
				if ( is_wp_error( $imported ) ) {
					$ignored[] = 'content_images:' . $src . ' (' . $imported->get_error_message() . ')';
					continue;
				}
				$content_images_imported[] = [ 'url' => $src, 'media_id' => $imported['media_id'], 'new_url' => $imported['source_url'] ];
				if ( false !== strpos( $content_now, $src ) ) {
					$content_now = str_replace( $src, $imported['source_url'], $content_now );
					$changed = true;
				}
			}
			if ( $changed ) {
				wp_update_post( [ 'ID' => $post_id, 'post_content' => $content_now ] );
				$updated[] = 'content_images';
			}
		}

		// NEW (round 3): optional JSON-LD (FAQPage/Article/etc.) saved as post
		// meta and printed independently of AIOSEO's own schema output (see
		// print_jsonld() below). `jsonld: []` or `jsonld: null` clears it;
		// anything else that isn't an array/object is reported in `ignored`
		// rather than stored, since a bad value here would silently break the
		// printed <script> tag on the live page.
		if ( array_key_exists( 'jsonld', $b ) ) {
			$jsonld_in = $b['jsonld'];
			if ( null === $jsonld_in || ( is_array( $jsonld_in ) && empty( $jsonld_in ) ) ) {
				delete_post_meta( $post_id, '_imp_jsonld' );
				$updated[] = 'jsonld';
			} elseif ( is_array( $jsonld_in ) ) {
				update_post_meta( $post_id, '_imp_jsonld', $jsonld_in );
				$updated[] = 'jsonld';
			} else {
				$ignored[] = 'jsonld (must be a JSON object or array of objects)';
			}
		}

		$saved = get_post( $post_id ); // re-read once more in case content_images touched it

		return [
			'ok'                  => true,
			'post_id'             => $post_id,
			'status'              => $saved->post_status,
			'date'                => $saved->post_date,
			'permalink'           => get_permalink( $post_id ),
			'scheduled_for'       => 'future' === $saved->post_status ? $saved->post_date : null,
			'categories'          => wp_get_post_categories( $post_id, [ 'fields' => 'names' ] ),
			'tags'                => wp_get_post_tags( $post_id, [ 'fields' => 'names' ] ),
			'excerpt'             => get_post_field( 'post_excerpt', $post_id ),
			'featured_media'      => $featured_media_id ?: ( get_post_thumbnail_id( $post_id ) ?: null ),
			'categories_created'  => $categories_created,
			'content_images'      => $content_images_imported,
			'jsonld'              => get_post_meta( $post_id, '_imp_jsonld', true ) ?: null,
			'updated'             => array_values( array_unique( $updated ) ),
			'ignored'             => $ignored,
		];
	}

	/** Round 3: print the post's saved JSON-LD (if any) on its singular view.
	 *  Hooked to wp_head. Uses wp_json_encode()'s default slash-escaping
	 *  (no JSON_UNESCAPED_SLASHES) so a "</script>" substring inside a JSON
	 *  string value can't close the tag early. A single saved object is
	 *  printed as-is; an array of objects is printed as a JSON array (both
	 *  are valid script contents either way the brief's two shapes land). */
	public static function print_jsonld() {
		if ( ! is_singular( 'post' ) ) { return; }
		$post_id = get_queried_object_id();
		if ( ! $post_id ) { return; }
		$data = get_post_meta( $post_id, '_imp_jsonld', true );
		if ( empty( $data ) || ! is_array( $data ) ) { return; }
		echo '<script type="application/ld+json">' . wp_json_encode( $data ) . "</script>\n";
	}

	public static function handle_list_posts( WP_REST_Request $req ) {
		$args = [
			'post_type'      => 'post',
			'post_status'    => $req->get_param( 'status' ) ?: 'any',
			'posts_per_page' => min( (int) ( $req->get_param( 'per_page' ) ?: 20 ), 100 ),
		];
		$s = $req->get_param( 'search' );
		if ( $s ) { $args['s'] = sanitize_text_field( $s ); }

		$out = [];
		foreach ( get_posts( $args ) as $p ) {
			$out[] = [
				'id'     => $p->ID,
				'title'  => $p->post_title,
				'slug'   => $p->post_name,
				'status' => $p->post_status,
				'date'   => $p->post_date,
			];
		}
		return [ 'count' => count( $out ), 'posts' => $out ];
	}

	public static function handle_get_post( WP_REST_Request $req ) {
		$id = (int) $req->get_param( 'id' );
		$p  = get_post( $id );
		if ( ! $p || 'post' !== $p->post_type ) {
			return new WP_Error( 'bad_post', 'Post not found.', [ 'status' => 404 ] );
		}
		return [
			'id'         => $p->ID,
			'title'      => $p->post_title,
			'slug'       => $p->post_name,
			'status'     => $p->post_status,
			'date'       => $p->post_date,
			'content'    => $p->post_content,
			'excerpt'    => $p->post_excerpt,
			'categories' => wp_get_post_categories( $p->ID, [ 'fields' => 'names' ] ),
			'tags'       => wp_get_post_tags( $p->ID, [ 'fields' => 'names' ] ),
		];
	}

	/* ------------------------------------------------------------------ */
	/*  Media                                                              */
	/* ------------------------------------------------------------------ */

	public static function handle_list_media( WP_REST_Request $req ) {
		$args = [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => $req->get_param( 'mime_type' ) ?: 'image',
			'posts_per_page' => min( (int) ( $req->get_param( 'per_page' ) ?: 20 ), 100 ),
			'paged'          => max( 1, (int) ( $req->get_param( 'page' ) ?: 1 ) ),
		];

		$results = [];
		foreach ( get_posts( $args ) as $a ) {
			$meta = wp_get_attachment_metadata( $a->ID );
			$results[] = [
				'id'          => $a->ID,
				'filename'    => basename( get_attached_file( $a->ID ) ),
				'source_url'  => wp_get_attachment_url( $a->ID ),
				'width'       => $meta['width']  ?? null,
				'height'      => $meta['height'] ?? null,
				'alt'         => get_post_meta( $a->ID, '_wp_attachment_image_alt', true ),
				'title'       => $a->post_title,
				'caption'     => $a->post_excerpt,
				'description' => $a->post_content,
			];
		}
		return [ 'count' => count( $results ), 'results' => $results ];
	}

	public static function handle_update_media( WP_REST_Request $req ) {
		$id = (int) $req->get_param( 'id' );
		if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
			return new WP_Error( 'bad_media', 'Attachment not found.', [ 'status' => 404 ] );
		}
		$b       = $req->get_json_params();
		$updated = [];
		$ignored = [];
		$known   = [ 'alt_text', 'title', 'caption', 'description' ];
		foreach ( array_keys( (array) $b ) as $field ) {
			if ( ! in_array( $field, $known, true ) ) { $ignored[] = $field; }
		}

		if ( array_key_exists( 'alt_text', $b ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $b['alt_text'] ) );
			$updated[] = 'alt_text';
		}

		$post_update = [];
		if ( array_key_exists( 'title', $b ) )       { $post_update['post_title']   = sanitize_text_field( $b['title'] ); }
		if ( array_key_exists( 'caption', $b ) )     { $post_update['post_excerpt'] = sanitize_text_field( $b['caption'] ); }
		if ( array_key_exists( 'description', $b ) ) { $post_update['post_content'] = wp_kses_post( $b['description'] ); }
		if ( $post_update ) {
			$post_update['ID'] = $id;
			wp_update_post( $post_update );
			$updated = array_merge( $updated, array_keys( $post_update ) );
		}

		return [ 'ok' => true, 'attachment_id' => $id, 'updated' => array_values( array_diff( array_unique( $updated ), [ 'ID' ] ) ), 'ignored' => $ignored ];
	}

	/* ------------------------------------------------------------------ */
	/*  Media import (NEW v0.6.0)                                          */
	/* ------------------------------------------------------------------ */

	/** True if $url's host is on the allowlist (suffix match for a
	 *  ".domain" entry, exact match otherwise). */
	private static function host_allowed( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( ! $host ) { return false; }
		foreach ( self::MEDIA_IMPORT_ALLOWLIST as $entry ) {
			$entry = strtolower( $entry );
			if ( 0 === strpos( $entry, '.' ) ) {
				if ( $host === ltrim( $entry, '.' ) || substr( $host, -strlen( $entry ) ) === $entry ) { return true; }
			} elseif ( $host === $entry ) {
				return true;
			}
		}
		return false;
	}

	/** Core of POST /media-import, also reused by handle_write_post for
	 *  `featured_image_url`. Returns an array on success:
	 *  { media_id, source_url, width, height, sizes, duplicate }
	 *  or a WP_Error on failure. */
	public static function import_media_from_url( $args ) {
		$url = esc_url_raw( $args['url'] ?? '' );
		if ( ! $url || 0 !== stripos( $url, 'https://' ) ) {
			return new WP_Error( 'bad_url', 'url must be a https:// URL.', [ 'status' => 400 ] );
		}
		if ( ! self::host_allowed( $url ) ) {
			return new WP_Error( 'host_not_allowed', 'This host is not on the media-import allowlist.', [ 'status' => 403 ] );
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) { return $tmp; }

		$size = @filesize( $tmp );
		if ( $size === false || $size <= 0 ) {
			@unlink( $tmp );
			return new WP_Error( 'empty_download', 'Downloaded file was empty or unreadable.', [ 'status' => 502 ] );
		}
		if ( $size > self::MEDIA_IMPORT_MAX_BYTES ) {
			@unlink( $tmp );
			return new WP_Error( 'too_large', 'File exceeds the ' . ( self::MEDIA_IMPORT_MAX_BYTES / 1024 / 1024 ) . 'MB cap.', [ 'status' => 413 ] );
		}

		$filetype = wp_check_filetype( $args['filename'] ?? basename( wp_parse_url( $url, PHP_URL_PATH ) ) );
		$img_info = @getimagesize( $tmp );
		$mime     = $img_info['mime'] ?? ( $filetype['type'] ?? '' );
		if ( ! in_array( $mime, self::MEDIA_IMPORT_ALLOWED_MIME, true ) ) {
			@unlink( $tmp );
			return new WP_Error( 'bad_content_type', 'Content type "' . $mime . '" is not image/jpeg, image/png, or image/webp.', [ 'status' => 415 ] );
		}

		// Duplicate guard: sha256 of the downloaded bytes, stored in post meta
		// on the attachment it was first imported as.
		$sha256 = hash_file( 'sha256', $tmp );
		$existing = get_posts( [
			'post_type'      => 'attachment',
			'posts_per_page' => 1,
			'meta_key'       => '_imp_sha256',
			'meta_value'     => $sha256,
			'fields'         => 'ids',
		] );
		if ( $existing ) {
			@unlink( $tmp );
			$id   = (int) $existing[0];
			$meta = wp_get_attachment_metadata( $id );
			return [
				'ok'         => true,
				'media_id'   => $id,
				'source_url' => wp_get_attachment_url( $id ),
				'width'      => $meta['width']  ?? null,
				'height'     => $meta['height'] ?? null,
				'sizes'      => $meta['sizes']  ?? [],
				'duplicate'  => true,
			];
		}

		$filename   = sanitize_file_name( $args['filename'] ?? basename( wp_parse_url( $url, PHP_URL_PATH ) ) );
		$file_array = [ 'name' => $filename, 'tmp_name' => $tmp ];
		$post_id    = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;

		// media_handle_sideload wraps wp_handle_sideload + wp_insert_attachment +
		// wp_generate_attachment_metadata, so every registered WP thumbnail size
		// gets created the same as a normal media-library upload.
		$media_id = media_handle_sideload( $file_array, $post_id, $args['title'] ?? null );
		if ( is_wp_error( $media_id ) ) {
			@unlink( $tmp );
			return $media_id;
		}

		update_post_meta( $media_id, '_imp_sha256', $sha256 );

		// Round 2, item C: media_handle_sideload() leaves whatever placeholder
		// caption/description WordPress defaults an attachment to (observed as
		// literal "#image_title" in testing). Explicitly set both on every
		// import — to the caller's `caption` if given, otherwise '' — rather
		// than only touching them when a value was supplied, so no leftover
		// placeholder text survives.
		$post_update = [
			'ID'           => $media_id,
			'post_excerpt' => ! empty( $args['caption'] ) ? sanitize_text_field( $args['caption'] ) : '',
			'post_content' => '',
		];
		if ( ! empty( $args['title'] ) ) { $post_update['post_title'] = sanitize_text_field( $args['title'] ); }
		wp_update_post( $post_update );
		if ( ! empty( $args['alt_text'] ) ) {
			update_post_meta( $media_id, '_wp_attachment_image_alt', sanitize_text_field( $args['alt_text'] ) );
		}

		$meta = wp_get_attachment_metadata( $media_id );
		return [
			'ok'         => true,
			'media_id'   => (int) $media_id,
			'source_url' => wp_get_attachment_url( $media_id ),
			'width'      => $meta['width']  ?? null,
			'height'     => $meta['height'] ?? null,
			'sizes'      => $meta['sizes']  ?? [],
			'duplicate'  => false,
		];
	}

	public static function handle_media_import( WP_REST_Request $req ) {
		$b = $req->get_json_params();
		if ( empty( $b['url'] ) ) {
			return new WP_Error( 'no_url', 'url is required.', [ 'status' => 400 ] );
		}
		$result = self::import_media_from_url( $b );
		if ( is_wp_error( $result ) ) { return $result; }
		return $result;
	}

	/* ------------------------------------------------------------------ */
	/*  SEO (AIOSEO per-post fields)                                       */
	/* ------------------------------------------------------------------ */

	/** Class names AIOSEO's per-post model has used across major versions. */
	private static function aioseo_model_classes() {
		return [
			'\\AIOSEO\\Plugin\\Common\\Models\\Post', // AIOSEO Free/Pro, older 4.x
			'\\AIOSEO\\Common\\Models\\Post',         // AIOSEO Free/Pro, current 4.x
		];
	}

	/** Load the AIOSEO model for a post, trying each known namespace. Never throws.
	 *  $allow_new (v0.9.7, SEO writes only): AIOSEO's getPost() returns a fresh,
	 *  unsaved model (post_id set, no id yet) when the post has no AIOSEO row,
	 *  e.g. a post created while AIOSEO was not loaded. Accept it so the caller's
	 *  save() creates the row. Reads keep requiring an existing row. */
	private static function get_aioseo_model( $post_id, $allow_new = false ) {
		foreach ( self::aioseo_model_classes() as $class ) {
			if ( ! class_exists( $class ) || ! method_exists( $class, 'getPost' ) ) { continue; }
			try {
				$p = $class::getPost( $post_id );
				if ( $p && isset( $p->id ) ) { return $p; }
				if ( $allow_new && is_object( $p ) ) {
					if ( empty( $p->post_id ) ) { $p->post_id = $post_id; }
					if ( (int) $p->post_id === (int) $post_id ) { return $p; }
				}
			} catch ( \Throwable $e ) {
				continue; // try the next namespace rather than fatal
			}
		}
		return null;
	}

	/** Direct read of AIOSEO's own DB table — stable across versions, used as a
	 *  fallback for reading (never for writing, so we don't fight the plugin's
	 *  own cache/model layer). Returns null if the table doesn't exist. */
	private static function get_aioseo_row( $post_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'aioseo_posts';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d", $post_id ), ARRAY_A ); // phpcs:ignore
		return $row ?: null;
	}

	/** Normalize AIOSEO's `keyphrases` field to a plain array regardless of
	 *  whether the model handed it back as a JSON string, a stdClass, or
	 *  already an array. */
	private static function keyphrases_to_array( $val ) {
		if ( is_array( $val ) ) { return $val; }
		if ( is_object( $val ) ) { return json_decode( wp_json_encode( $val ), true ) ?: []; }
		if ( is_string( $val ) && '' !== $val ) { return json_decode( $val, true ) ?: []; }
		return [];
	}

	/** Pick the first present/non-empty candidate score column. */
	private static function extract_score( $arr ) {
		foreach ( [ 'seo_score', 'page_analysis_score', 'headline_analysis_score' ] as $f ) {
			if ( isset( $arr[ $f ] ) && $arr[ $f ] !== '' && $arr[ $f ] !== null ) { return (int) $arr[ $f ]; }
		}
		return null;
	}

	/** Maps every accepted write field (canonical name + aliases the brief's
	 *  test run actually used) to how it's written on the AIOSEO model.
	 *  'simple' => a direct $model->{prop} assignment of a sanitized string.
	 *  'keyphrase' / 'additional_keywords' / 'og_image' get bespoke handling
	 *  below because AIOSEO nests them under `keyphrases` / `og_image`. */
	private static function seo_field_map() {
		return [
			'title'                 => [ 'prop' => 'title',             'kind' => 'simple' ],
			'seo_title'             => [ 'prop' => 'title',             'kind' => 'simple' ],
			'description'           => [ 'prop' => 'description',       'kind' => 'simple' ],
			'meta_description'      => [ 'prop' => 'description',       'kind' => 'simple' ],
			'keyphrase'             => [ 'kind' => 'keyphrase' ],
			'focus_keyphrase'       => [ 'kind' => 'keyphrase' ],
			'additional_keywords'   => [ 'kind' => 'additional_keywords' ],
			'keywords'              => [ 'kind' => 'additional_keywords' ], // round 2, item E alias
			'og_title'              => [ 'prop' => 'og_title',          'kind' => 'simple' ],
			'og_description'        => [ 'prop' => 'og_description',    'kind' => 'simple' ],
			'og_image_custom_url'   => [ 'prop' => 'og_image_custom_url','kind' => 'simple' ],
			'twitter_title'         => [ 'prop' => 'twitter_title',     'kind' => 'simple' ],
			'twitter_description'   => [ 'prop' => 'twitter_description','kind' => 'simple' ],
			'schema_type'           => [ 'prop' => 'schema_type',       'kind' => 'simple' ],
		];
	}

	public static function handle_get_seo( WP_REST_Request $req ) {
		$post_id = (int) $req->get_param( 'post_id' );
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return new WP_Error( 'bad_post', 'post_id not found.', [ 'status' => 404 ] );
		}
		if ( ! function_exists( 'aioseo' ) ) {
			return new WP_Error( 'no_aioseo', 'AIOSEO is not active on this site.', [ 'status' => 409 ] );
		}

		$title = $description = $keyphrase = $score = null;
		$additional_keywords = [];
		$source = 'none';

		try {
			$p = self::get_aioseo_model( $post_id );
			if ( $p ) {
				$title       = $p->title ?? null;
				$description = $p->description ?? null;
				if ( ! empty( $p->keyphrases ) ) {
					$k                   = self::keyphrases_to_array( $p->keyphrases );
					$keyphrase           = $k['focus']['keyphrase'] ?? null;
					$additional_keywords = array_values( array_map(
						static fn( $row ) => $row['keyphrase'] ?? null,
						array_filter( (array) ( $k['additional'] ?? [] ) )
					) );
				}
				$score  = self::extract_score( (array) $p );
				$source = 'model';
			}
		} catch ( \Throwable $e ) {
			// Fall through to the raw table below rather than fatal.
		}

		$raw = self::get_aioseo_row( $post_id );
		if ( $raw ) {
			if ( $title === null && ! empty( $raw['title'] ) )             { $title = $raw['title']; }
			if ( $description === null && ! empty( $raw['description'] ) ) { $description = $raw['description']; }
			if ( $keyphrase === null && ! empty( $raw['keyphrases'] ) ) {
				$k         = json_decode( $raw['keyphrases'], true );
				$keyphrase = $k['focus']['keyphrase'] ?? null;
			}
			if ( $score === null ) { $score = self::extract_score( $raw ); }
			if ( 'none' === $source ) { $source = 'raw_table'; }
		}

		return [
			'post_id'             => $post_id,
			'title'               => $title,
			'description'         => $description,
			'keyphrase'           => $keyphrase,
			'additional_keywords' => $additional_keywords,
			'score'               => $score,
			'jsonld'              => get_post_meta( $post_id, '_imp_jsonld', true ) ?: null,
			'_source'             => $source,
		];
	}

	/** Best-effort: ask AIOSEO to re-analyze this post right now so the score
	 *  handle_get_seo reads back is current, rather than waiting for AIOSEO's
	 *  own next editor-open/save cycle. Tried across a few call shapes seen
	 *  in different AIOSEO 4.x builds; any failure here is swallowed — the
	 *  write itself already succeeded, and handle_get_seo already falls back
	 *  to the saved seo_score column when no fresh score is available. */
	private static function trigger_aioseo_analysis( $post_id ) {
		try {
			if ( function_exists( 'aioseo' ) ) {
				$aioseo = aioseo();
				if ( isset( $aioseo->standalone ) && method_exists( $aioseo->standalone, 'runPageAnalyzer' ) ) {
					$aioseo->standalone->runPageAnalyzer( $post_id );
					return true;
				}
				if ( class_exists( '\\AIOSEO\\Plugin\\Common\\Analyzer\\Analyzer' ) ) {
					$analyzer = new \AIOSEO\Plugin\Common\Analyzer\Analyzer();
					if ( method_exists( $analyzer, 'run' ) ) { $analyzer->run( get_permalink( $post_id ), $post_id ); return true; }
				}
			}
		} catch ( \Throwable $e ) {
			// Best-effort only — see docblock above.
		}
		return false;
	}

	public static function handle_set_seo( WP_REST_Request $req ) {
		try {
			$b = $req->get_json_params();
			if ( ! is_array( $b ) ) {
				$decoded = json_decode( $req->get_body(), true );
				$b       = is_array( $decoded ) ? $decoded : [];
			}

			$post_id = (int) ( $b['post_id'] ?? 0 );
			if ( ! $post_id || ! get_post( $post_id ) ) {
				return new WP_Error( 'bad_post', 'post_id not found.', [ 'status' => 404 ] );
			}
			if ( ! function_exists( 'aioseo' ) ) {
				return new WP_Error( 'no_aioseo', 'AIOSEO is not active on this site.', [ 'status' => 409 ] );
			}

			$p = self::get_aioseo_model( $post_id, true );
			if ( ! $p ) {
				return new WP_Error( 'aioseo_post_failed', 'Could not load/create the AIOSEO record for this post (checked known model namespaces: ' . implode( ', ', self::aioseo_model_classes() ) . ').', [ 'status' => 500 ] );
			}

			$field_map = self::seo_field_map();
			$updated   = [];
			$ignored   = [];
			unset( $b['post_id'] );

			foreach ( $b as $field => $value ) {
				if ( ! isset( $field_map[ $field ] ) ) {
					$ignored[] = $field;
					continue;
				}
				$spec = $field_map[ $field ];
				try {
					if ( 'simple' === $spec['kind'] ) {
						$p->{$spec['prop']} = sanitize_text_field( (string) $value );
					} elseif ( 'keyphrase' === $spec['kind'] ) {
						$existing                       = self::keyphrases_to_array( $p->keyphrases ?? null );
						$existing['focus']['keyphrase'] = sanitize_text_field( (string) $value );
						$p->keyphrases                  = $existing;
					} elseif ( 'additional_keywords' === $spec['kind'] ) {
						$existing                 = self::keyphrases_to_array( $p->keyphrases ?? null );
						$existing['additional']   = array_map(
							static fn( $kw ) => [ 'keyphrase' => sanitize_text_field( (string) $kw ) ],
							array_filter( (array) $value )
						);
						$p->keyphrases            = $existing;
					}
					$updated[] = $field;
				} catch ( \Throwable $e ) {
					// Known field, but this AIOSEO version wouldn't take it —
					// report instead of silently dropping.
					$ignored[] = $field . ' (' . $e->getMessage() . ')';
				}
			}

			$p->save();

			$analyzed = self::trigger_aioseo_analysis( $post_id );
			$score    = null;
			if ( $analyzed ) {
				$fresh = self::get_aioseo_row( $post_id );
				$score = $fresh ? self::extract_score( $fresh ) : null;
			}
			if ( $score === null ) {
				$raw   = self::get_aioseo_row( $post_id );
				$score = $raw ? self::extract_score( $raw ) : null; // fallback: saved column
			}

			return [
				'ok'       => true,
				'post_id'  => $post_id,
				'updated'  => $updated,
				'ignored'  => $ignored,
				'analyzed' => $analyzed,
				'score'    => $score,
			];
		} catch ( \Throwable $e ) {
			return new WP_Error( 'seo_set_exception', get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine(), [ 'status' => 500 ] );
		}
	}
}

IMP_Content::init();
