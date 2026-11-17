<?php
/**
 * Plugin Name: Impressions Control
 * Description: Agent-API control center for Impressions-managed sites. Authenticated REST endpoints for state, settings, pages, media, galleries, Elementor saves, cache clearing, blog posts, SEO fields, and the v0.4 change-history / revert safety net. Install per client.
 * Version: 0.9.4
 * Author: Impressions Agency
 */

if (!defined('ABSPATH')) exit;

define('IMP_VERSION', '0.9.4');
define('IMP_NS',      'impressions/v1');
define('IMP_KEY_OPT', 'imp_api_key');

/* v0.5 — Hub pairing. The Worker URL this site registers itself with via
   the Settings screen's "Pair with Hub" button. See
   PLUGIN-V05-HUB-CONNECT-SPEC.md in the Claude project for the full design
   (the Worker's /register endpoint is separate work, not yet built as of
   this version — the button below is written to fail gracefully until it
   exists). Override in wp-config.php if the Hub ever moves. */
if (!defined('IMP_HUB_URL')) define('IMP_HUB_URL', 'https://imp-hub.iaautomate.workers.dev');
define('IMP_SLUG_OPT',   'imp_hub_slug');
define('IMP_PAIRED_OPT', 'imp_hub_paired_at');

/* v0.4 — change history + revert safety net (build step 1 of /change).
   Registers wp_imp_change_history + the /change/revert and /change/history
   routes. Uses IMP_NS, imp_authed(), and imp_regen_css() from this file. */
require_once __DIR__ . '/includes/class-imp-change-history.php';

/* v0.5 — content operations: blog posts, media metadata, per-post SEO.
   Uses IMP_NS and imp_authed() from this file. */
require_once __DIR__ . '/includes/class-imp-content.php';

/* v0.9 — design transfer: kit, site CSS/JS files, Theme Builder templates,
   Elementor pages, media/font/video import, read-back. */
require_once __DIR__ . '/includes/class-imp-design.php';

/* ============================================================
   SAFE THEME FOR HUB WRITES (v0.9.3)
   ------------------------------------------------------------
   Some sites (old heavy themes) run out of memory in the theme's save
   hooks when the Hub creates a post. While a Hub WRITE request is being
   handled, load a plain theme instead of the site's real one. Public
   pages, wp-admin and Hub reads (GET) keep the real theme.
   Setting (default empty = off): constant IMP_SAFE_THEME in wp-config.php,
   else the option imp_safe_theme (settings screen or POST /safe-theme).
   Registered at plugin load, before WordPress sets up the theme.
   To undo: clear the setting.
   ============================================================ */
define('IMP_SAFE_THEME_OPT', 'imp_safe_theme');

function imp_safe_theme_slug() {
    $slug = defined('IMP_SAFE_THEME') ? IMP_SAFE_THEME : get_option(IMP_SAFE_THEME_OPT, '');
    return is_string($slug) ? sanitize_title($slug) : '';
}

function imp_is_hub_write_request() {
    $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
    if (!in_array($method, ['POST', 'PUT', 'PATCH'], true)) return false;
    $uri = isset($_SERVER['REQUEST_URI']) ? rawurldecode($_SERVER['REQUEST_URI']) : '';
    return false !== strpos($uri, '/wp-json/' . IMP_NS . '/')
        || false !== strpos($uri, 'rest_route=/' . IMP_NS . '/');
}

(function () {
    if (!imp_is_hub_write_request()) return;
    $safe = imp_safe_theme_slug();
    if ($safe === '') return; // off
    $root = function_exists('get_theme_root') ? get_theme_root() : WP_CONTENT_DIR . '/themes';
    if (!is_dir($root . '/' . $safe)) {
        error_log('Impressions Control: safe theme "' . $safe . '" is not installed, not switching');
        return;
    }
    $use = function () use ($safe) { return $safe; };
    add_filter('template',   $use);
    add_filter('stylesheet', $use);
    $GLOBALS['imp_safe_theme_active'] = $safe;
    error_log('Impressions Control: safe theme used for a Hub write');
})();

/* v0.9.4 — keep a little memory in reserve during Hub writes so the fatal
   handler below can still record what ran out of memory, even when the host
   gives us no error log. Read it back with GET /safe-theme (last_fatal). */
if (imp_is_hub_write_request()) $GLOBALS['imp_mem_reserve'] = str_repeat('x', 1048576);

/* ============================================================
   SELF-UPDATE (v0.5.5) — Part D of PLUGIN-V05-HUB-CONNECT-SPEC.md
   ------------------------------------------------------------
   Bundles Plugin Update Checker (github.com/YahnisElsts/plugin-update-checker,
   MIT license — see vendor/plugin-update-checker/license.txt), pointed at a
   public GitHub repo's Releases. From here on, shipping a fix means: tag a
   release on GitHub and attach the built zip as a release asset — every site
   running this plugin sees a normal "Update available" notice in wp-admin
   (or updates fully automatically if the site has WP's auto-update toggle
   on for this plugin), with no per-site file upload. This is a PUBLIC repo
   on purpose: the plugin has no client secrets in it (every site generates
   its own API key at activation, see IMP_KEY_OPT below), so there's nothing
   to protect by keeping the source private — and a public repo means there's
   no access token embedded in the plugin that could expire and silently
   break updates fleet-wide. enableReleaseAssets() tells it to prefer the
   zip attached to the Release over GitHub's auto-generated (and messier)
   source archive of the repo at that tag.
   ============================================================ */
require_once __DIR__ . '/vendor/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$imp_update_checker = PucFactory::buildUpdateChecker(
    'https://github.com/impressionsagency/impressions-control/',
    __FILE__,
    'impressions-control'
);
$imp_update_checker->getVcsApi()->enableReleaseAssets();

/* ============================================================
   ACTIVATION — generate per-site key
   ============================================================ */
register_activation_hook(__FILE__, function () {
    if (!get_option(IMP_KEY_OPT))
        update_option(IMP_KEY_OPT, wp_generate_password(48, false, false));
    // v0.4: create the change-history table (also self-heals on load via maybe_upgrade).
    if (class_exists('IMP_Change_History'))
        IMP_Change_History::install();
});

/* ============================================================
   AUTH
   ============================================================ */
function imp_authed(WP_REST_Request $req) {
    $stored = get_option(IMP_KEY_OPT);
    if (!$stored) return false;
    $hdr = $req->get_header('authorization');
    if (!$hdr || stripos($hdr, 'Bearer ') !== 0) return false;
    return hash_equals($stored, trim(substr($hdr, 7)));
}

/* ============================================================
   FATAL-ERROR SAFETY NET (v0.5.3)
   ------------------------------------------------------------
   A raw PHP fatal (not a catchable \Throwable — e.g. a class/method
   that genuinely doesn't exist, hit through a code path a try/catch
   didn't cover) normally just terminates the request with whatever
   was already buffered — on hosts with display_errors off, that's a
   silent empty 200 body. That's exactly what we chased through the
   /seo endpoint: hard to debug because nothing ever comes back to
   explain it. This turns a fatal on any of OUR routes into a visible
   JSON diagnostic instead of silence, without touching normal WP
   requests or fatals elsewhere on the site.
   ============================================================ */
add_action('shutdown', function () {
    $err = error_get_last();
    if (!$err || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        return;
    }
    if (empty($_SERVER['REQUEST_URI']) || strpos($_SERVER['REQUEST_URI'], IMP_NS) === false) {
        return; // not one of our API requests — leave the site's normal behavior alone
    }
    unset($GLOBALS['imp_mem_reserve']); // free the reserve so we can record the fatal
    update_option('imp_last_fatal', [
        'time'              => gmdate('c'),
        'uri'               => substr(rawurldecode($_SERVER['REQUEST_URI']), 0, 200),
        'safe_theme_active' => isset($GLOBALS['imp_safe_theme_active']) ? $GLOBALS['imp_safe_theme_active'] : '',
        'message'           => substr($err['message'], 0, 500),
        'file'              => $err['file'],
        'line'              => $err['line'],
    ], false);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    echo wp_json_encode([
        'ok'      => false,
        'error'   => 'fatal_error',
        'message' => $err['message'],
        'file'    => $err['file'],
        'line'    => $err['line'],
    ]);
});

/* QA endpoints (operator-only, no client guardrails). Two ways in:
   - Bearer key  → Slack/Worker/CLI (headless)
   - logged-in Administrator + valid REST nonce → Claude-in-Chrome (no key)
   Gated to manage_options so client Editors can never reach these. */
function imp_qa_authed(WP_REST_Request $req) {
    if (imp_authed($req)) return true; // Bearer path
    $nonce = $req->get_header('x_wp_nonce');
    if ($nonce && wp_verify_nonce($nonce, 'wp_rest') && current_user_can('manage_options'))
        return true; // cookie + nonce path (Claude-in-Chrome)
    return false;
}

/* Expose the REST nonce + base URL on every admin page so a browser-driven
   session (Claude-in-Chrome) can call QA endpoints as the logged-in admin
   without any API key. Nothing is stored; the nonce is short-lived. */
add_action('admin_print_footer_scripts', function () {
    if (!current_user_can('manage_options')) return;
    echo '<script>window.IMP_NONCE=' . wp_json_encode(wp_create_nonce('wp_rest'))
       . ';window.IMP_REST=' . wp_json_encode(esc_url_raw(rest_url('impressions/v1/'))) . ';</script>';
});

/* ============================================================
   HUB PAIRING (v0.5)
   ============================================================ */

/* Best-effort default slug from the site's own domain, e.g.
   "threzroestg" from threzroestg.wpenginepowered.com. Just a starting
   point in the Settings-screen text field — the agency can override it
   before clicking Pair (two clients' domains could collide, or the
   agency may prefer a friendlier slug like "303" over the raw host). */
function imp_default_slug() {
    $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
    $host = preg_replace('/^www\./', '', $host);
    $first_label = preg_replace('/\..*$/', '', $host);
    $slug = sanitize_title($first_label !== '' ? $first_label : $host);
    return $slug !== '' ? $slug : 'site';
}

/* POST {slug, site_url, key, site_name} to the Hub's /register endpoint.
   No shared secret required to call it — the Worker proves ownership by
   the key itself (first registration of a slug claims it; a later
   registration of the same slug must present that same key). See
   PLUGIN-V05-HUB-CONNECT-SPEC.md, Part B, for the full reasoning.
   Returns the decoded response body on success, or a WP_Error. */
function imp_pair_with_hub($slug) {
    $slug = sanitize_title($slug);
    if (!$slug) return new WP_Error('bad_slug', 'Slug is required.');

    $resp = wp_remote_post(rtrim(IMP_HUB_URL, '/') . '/register', [
        'timeout' => 15,
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => wp_json_encode([
            'slug'      => $slug,
            'site_url'  => home_url(),
            'key'       => get_option(IMP_KEY_OPT),
            'site_name' => get_bloginfo('name'),
        ]),
    ]);
    if (is_wp_error($resp)) return $resp;

    $code = wp_remote_retrieve_response_code($resp);
    $body = json_decode(wp_remote_retrieve_body($resp), true);

    if ($code >= 200 && $code < 300) {
        update_option(IMP_SLUG_OPT, $slug);
        update_option(IMP_PAIRED_OPT, current_time('mysql'));
        return is_array($body) ? $body : ['ok' => true];
    }

    $msg = (is_array($body) && !empty($body['message'])) ? $body['message'] : wp_remote_retrieve_body($resp);
    return new WP_Error('pair_failed', 'Hub responded ' . $code . ': ' . $msg, ['status' => $code]);
}

/* ============================================================
   HELPERS
   ============================================================ */
function imp_kit_id() {
    if (!class_exists('\Elementor\Plugin')) return 0;
    return (int) \Elementor\Plugin::$instance->kits_manager->get_active_id();
}
function imp_get_kit_settings() {
    $id = imp_kit_id();
    if (!$id) return [];
    $s = get_post_meta($id, '_elementor_page_settings', true);
    return is_array($s) ? $s : [];
}
function imp_regen_css() {
    if (class_exists('\Elementor\Plugin'))
        \Elementor\Plugin::$instance->files_manager->clear_cache();
}
function imp_rand_id() {
    return substr(md5(uniqid('', true)), 0, 7);
}

/* ============================================================
   ROUTES
   ============================================================ */
add_action('rest_api_init', function () {
    $auth = 'imp_authed';

    /* ----------------------------------------------------------
       GET /state
       ---------------------------------------------------------- */
    register_rest_route(IMP_NS, '/state', [
        'methods'             => 'GET',
        'permission_callback' => $auth,
        'callback'            => function () {
            $theme = wp_get_theme();
            $tpls  = get_posts(['post_type'=>'elementor_library','numberposts'=>-1,'post_status'=>'any']);
            $templates = [];
            foreach ($tpls as $t) {
                $templates[] = [
                    'id'         => $t->ID,
                    'title'      => $t->post_title,
                    'type'       => get_post_meta($t->ID, '_elementor_template_type', true),
                    'status'     => $t->post_status,
                    'conditions' => get_post_meta($t->ID, '_elementor_conditions', true),
                ];
            }
            $pages = [];
            foreach (get_posts(['post_type'=>'page','numberposts'=>-1,'post_status'=>'any']) as $p) {
                $pages[] = ['id'=>$p->ID,'title'=>$p->post_title,'slug'=>$p->post_name,'status'=>$p->post_status];
            }
            $forms = [];
            if (class_exists('GFAPI')) {
                foreach (GFAPI::get_forms() as $f) $forms[] = ['id'=>$f['id'],'title'=>$f['title']];
            }
            return [
                'site'                   => ['url'=>home_url(),'name'=>get_bloginfo('name')],
                'theme'                  => ['name'=>$theme->get('Name'),'stylesheet'=>get_stylesheet()],
                'elementor'              => [
                    'active'     => defined('ELEMENTOR_VERSION'),
                    'version'    => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : null,
                    'pro'        => defined('ELEMENTOR_PRO_VERSION') ? ELEMENTOR_PRO_VERSION : null,
                    'active_kit_id' => imp_kit_id(),
                ],
                'pages'                  => $pages,
                'theme_builder_templates'=> $templates,
                'gravity_forms'          => $forms,
                'plugin_version'         => IMP_VERSION,
                'safe_theme'             => imp_safe_theme_slug(),
                'last_fatal'             => get_option('imp_last_fatal', null),
            ];
        },
    ]);

    /* ----------------------------------------------------------
       GET/POST /safe-theme  (v0.9.3) — read / set the safe-theme slug.
       POST {"theme":"twentytwentyfour"} turns it on; {"theme":""} turns it off.
       ---------------------------------------------------------- */
    register_rest_route(IMP_NS, '/safe-theme', [
        [
            'methods'             => 'GET',
            'permission_callback' => $auth,
            'callback'            => function () {
                $slug = imp_safe_theme_slug();
                return [
                    'theme'        => $slug,
                    'enabled'      => $slug !== '',
                    'installed'    => $slug !== '' && is_dir(get_theme_root() . '/' . $slug),
                    'from_constant'=> defined('IMP_SAFE_THEME'),
                    'last_fatal'   => get_option('imp_last_fatal', null),
                ];
            },
        ],
        [
            'methods'             => 'POST',
            'permission_callback' => $auth,
            'callback'            => function (WP_REST_Request $req) {
                if (defined('IMP_SAFE_THEME')) return new WP_Error('locked','Set by IMP_SAFE_THEME in wp-config.php',['status'=>409]);
                $body = $req->get_json_params();
                $slug = is_array($body) && isset($body['theme']) && is_string($body['theme']) ? sanitize_title($body['theme']) : null;
                if ($slug === null) return new WP_Error('bad_theme','theme (string) required; empty turns it off',['status'=>400]);
                if ($slug !== '' && !is_dir(get_theme_root() . '/' . $slug))
                    return new WP_Error('not_installed','Theme "'.$slug.'" is not installed on this site',['status'=>409]);
                update_option(IMP_SAFE_THEME_OPT, $slug, false);
                return ['ok'=>true,'theme'=>$slug,'enabled'=>$slug !== ''];
            },
        ],
    ]);

    /* ----------------------------------------------------------
       GET /site-settings
       ---------------------------------------------------------- */
    register_rest_route(IMP_NS, '/site-settings', [
        'methods'             => 'GET',
        'permission_callback' => $auth,
        'callback'            => function () {
            return ['kit_id'=>imp_kit_id(),'settings'=>imp_get_kit_settings()];
        },
    ]);

    /* ----------------------------------------------------------
       POST /site-settings
       ---------------------------------------------------------- */
    register_rest_route(IMP_NS, '/site-settings', [
        'methods'             => 'POST',
        'permission_callback' => $auth,
        'callback'            => function (WP_REST_Request $req) {
            $id = imp_kit_id();
            if (!$id) return new WP_Error('no_kit','No active Elementor kit',['status'=>409]);
            $body     = $req->get_json_params();
            $settings = isset($body['settings']) ? $body['settings'] : $body;
            if (!is_array($settings)) return new WP_Error('bad_settings','settings must be an object',['status'=>400]);
            $merged   = array_merge(imp_get_kit_settings(), $settings);
            update_post_meta($id, '_elementor_page_settings', $merged);
            imp_regen_css();
            return ['ok'=>true,'kit_id'=>$id,'applied_keys'=>array_keys($settings)];
        },
    ]);

    /* ----------------------------------------------------------
       POST /apply-baseline
       ---------------------------------------------------------- */
    register_rest_route(IMP_NS, '/apply-baseline', [
        'methods'             => 'POST',
        'permission_callback' => $auth,
        'callback'            => function () {
            $id   = imp_kit_id();
            if (!$id) return new WP_Error('no_kit','No active Elementor kit',['status'=>409]);
            $file = plugin_dir_path(__FILE__).'baseline.json';
            if (!file_exists($file)) return new WP_Error('no_baseline','baseline.json not found',['status'=>404]);
            $base = json_decode(file_get_contents($file), true);
            if (!is_array($base)) return new WP_Error('bad_baseline','baseline.json invalid',['status'=>500]);
            $settings = isset($base['settings']) ? $base['settings'] : $base;
            update_post_meta($id, '_elementor_page_settings', array_merge(imp_get_kit_settings(), $settings));
            imp_regen_css();
            return ['ok'=>true,'kit_id'=>$id,'applied_keys'=>array_keys($settings)];
        },
    ]);

    /* ----------------------------------------------------------
       POST /page  (create or update)
       Body: { title, slug, status?, template?, content?, meta? }
       meta keys: _elementor_data, _elementor_edit_mode, _elementor_template_type
       ---------------------------------------------------------- */
    register_rest_route(IMP_NS, '/page', [
        'methods'             => 'POST',
        'permission_callback' => $auth,
        'callback'            => function (WP_REST_Request $req) {
            $b      = $req->get_json_params();
            $title  = sanitize_text_field($b['title'] ?? '');
            $slug   = sanitize_title($b['slug'] ?? $title);
            $status = in_array($b['status'] ?? 'publish', ['publish','draft','private']) ? $b['status'] : 'publish';

            // find existing by slug
            $existing = get_page_by_path($slug, OBJECT, 'page');
            $pid = $existing ? $existing->ID : 0;

            $post_data = ['post_type'=>'page','post_title'=>$title,'post_name'=>$slug,'post_status'=>$status,'post_content'=>''];
            // v0.9: give API-created pages an administrator author (was 0), so
            // Elementor trusts their HTML the same as a page an admin built.
            if (!$existing && class_exists('IMP_Design')) $post_data['post_author'] = IMP_Design::author_id();
            if (isset($b['template'])) $post_data['page_template'] = $b['template'];

            if ($pid) {
                $post_data['ID'] = $pid;
                wp_update_post($post_data);
            } else {
                $pid = wp_insert_post($post_data);
                if (is_wp_error($pid)) return $pid;
            }

            // strip <style>/<script> from content before storing
            $content = $b['content'] ?? '';
            $stripped = [];
            foreach (['style','script'] as $tag) {
                $clean = preg_replace('#<'.$tag.'\b[^>]*>.*?</'.$tag.'>#is', '', $content);
                if ($clean !== $content) { $stripped[] = $tag; $content = $clean; }
            }

            // meta
            // v0.9 FIX: raw HTML `content` used to be written into _elementor_data,
            // which must be Elementor JSON, so Elementor rendered an empty page.
            // HTML now goes to post_content (with builder mode off unless real
            // Elementor data is supplied). For full designs use POST /design/page.
            $meta = $b['meta'] ?? [];
            if ($content !== '') wp_update_post(['ID'=>$pid,'post_content'=>$content]);
            if (isset($meta['_elementor_data'])) {
                $ed = $meta['_elementor_data'];
                $meta['_elementor_data'] = wp_slash(is_array($ed) ? wp_json_encode($ed) : $ed);
                if (!isset($meta['_elementor_edit_mode'])) $meta['_elementor_edit_mode'] = 'builder';
            }

            foreach ($meta as $k => $v) update_post_meta($pid, $k, $v);

            // AIOSEO: suppress auto-redirect on slug change
            if (function_exists('aioseo')) {
                $aioseo_post = \AIOSEO\Plugin\Common\Models\Post::getPost($pid);
                if ($aioseo_post && isset($aioseo_post->id)) {
                    $aioseo_post->robots_noindex = 0;
                    $aioseo_post->save();
                }
            }

            imp_regen_css();

            return ['ok'=>true,'page_id'=>$pid,'slug'=>$slug,'created'=>!$existing,'stripped_tags'=>$stripped];
        },
    ]);

    /* ----------------------------------------------------------
       POST /media  (sideload from URL)
       Body: { url, filename?, title?, alt?, resize_w? }
       ---------------------------------------------------------- */
    register_rest_route(IMP_NS, '/media', [
        'methods'             => 'POST',
        'permission_callback' => $auth,
        'callback'            => function (WP_REST_Request $req) {
            require_once ABSPATH.'wp-admin/includes/media.php';
            require_once ABSPATH.'wp-admin/includes/file.php';
            require_once ABSPATH.'wp-admin/includes/image.php';

            $b        = $req->get_json_params();
            $url      = esc_url_raw($b['url'] ?? '');
            if (!$url) return new WP_Error('no_url','url is required',['status'=>400]);

            $filename = sanitize_file_name($b['filename'] ?? basename(parse_url($url, PHP_URL_PATH)));
            $tmp      = download_url($url);
            if (is_wp_error($tmp)) return $tmp;

            // optional resize before sideload
            $resize_w = (int)($b['resize_w'] ?? 1300);
            if ($resize_w > 0) {
                $img = wp_get_image_editor($tmp);
                if (!is_wp_error($img)) {
                    $size = $img->get_size();
                    if ($size['width'] > $resize_w) {
                        $img->resize($resize_w, null, false);
                        $img->save($tmp);
                    }
                }
            }

            $file_array = ['name'=>$filename,'tmp_name'=>$tmp];
            $id = media_handle_sideload($file_array, 0, $b['title'] ?? null);
            @unlink($tmp);
            if (is_wp_error($id)) return $id;

            if (!empty($b['alt'])) update_post_meta($id, '_wp_attachment_image_alt', sanitize_text_field($b['alt']));

            return ['ok'=>true,'attachment_id'=>$id,'url'=>wp_get_attachment_url($id)];
        },
    ]);

    /* ----------------------------------------------------------
       GET /media-search  (v0.3; widened to imp_qa_authed in v0.4.6 so
       Claude-in-Chrome / QA sessions can search the library with no key)
       Query params: q OR search (either works), per_page (default 20), mime_type
       Returns id, filename, source_url, width, height for each result
       ---------------------------------------------------------- */
    register_rest_route(IMP_NS, '/media-search', [
        'methods'             => 'GET',
        'permission_callback' => 'imp_qa_authed',
        'callback'            => function (WP_REST_Request $req) {
            $args = [
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => min((int)($req->get_param('per_page') ?? 20), 100),
                'post_mime_type' => $req->get_param('mime_type') ?? 'image',
            ];
            $s = $req->get_param('search') ?: $req->get_param('q');
            if ($s) $args['s'] = sanitize_text_field($s);

            $results = [];
            foreach (get_posts($args) as $a) {
                $meta    = wp_get_attachment_metadata($a->ID);
                $results[] = [
                    'id'         => $a->ID,
                    'filename'   => basename(get_attached_file($a->ID)),
                    'source_url' => wp_get_attachment_url($a->ID),
                    'width'      => $meta['width']  ?? null,
                    'height'     => $meta['height'] ?? null,
                    'alt'        => get_post_meta($a->ID, '_wp_attachment_image_alt', true),
                    'title'      => $a->post_title,
                ];
            }
            return ['count'=>count($results),'results'=>$results];
        },
    ]);

    /* ----------------------------------------------------------
       POST /elementor-data  (NEW v0.3)
       Write _elementor_data to any page or template AND
       trigger Elementor CSS regeneration in the same call.
       Body: { post_id, elementor_data (array), regen_css? }
       ---------------------------------------------------------- */
    register_rest_route(IMP_NS, '/elementor-data', [
        'methods'             => 'POST',
        'permission_callback' => $auth,
        'callback'            => function (WP_REST_Request $req) {
            $b       = $req->get_json_params();
            $post_id = (int)($b['post_id'] ?? 0);
            if (!$post_id || !get_post($post_id))
                return new WP_Error('bad_post','post_id not found',['status'=>404]);

            $data = $b['elementor_data'] ?? null;
            if (!is_array($data))
                return new WP_Error('bad_data','elementor_data must be an array',['status'=>400]);

            $json = wp_json_encode($data);
            // v0.9 FIX: update_post_meta() unslashes its value, which stripped the
            // backslashes from every escaped quote in the JSON and left Elementor
            // with unreadable data (blank page) whenever the design contained a ".
            update_post_meta($post_id, '_elementor_data',      wp_slash($json));
            update_post_meta($post_id, '_elementor_edit_mode', 'builder');

            // Delete the per-page Elementor CSS file so it regenerates on next view
            if (class_exists('\Elementor\Core\Files\CSS\Post')) {
                $css_file = new \Elementor\Core\Files\CSS\Post($post_id);
                $css_file->delete();
            }

            // Also clear the global file cache
            imp_regen_css();

            return [
                'ok'      => true,
                'post_id' => $post_id,
                'bytes'   => strlen($json),
            ];
        },
    ]);

    /* ----------------------------------------------------------
       POST /clear-cache  (NEW v0.3)
       Clears Elementor file cache + WP Engine page cache.
       Optional body: { post_id } to also delete the per-page CSS.
       ---------------------------------------------------------- */
    register_rest_route(IMP_NS, '/clear-cache', [
        'methods'             => 'POST',
        'permission_callback' => $auth,
        'callback'            => function (WP_REST_Request $req) {
            $cleared = [];

            // 1. Elementor file cache (all pages)
            imp_regen_css();
            $cleared[] = 'elementor_file_cache';

            // 2. Per-page CSS if post_id given
            $b       = $req->get_json_params() ?? [];
            $post_id = (int)($b['post_id'] ?? 0);
            if ($post_id && class_exists('\Elementor\Core\Files\CSS\Post')) {
                $css = new \Elementor\Core\Files\CSS\Post($post_id);
                $css->delete();
                $cleared[] = 'elementor_page_css_'.$post_id;
            }

            // 3. WP Engine page cache (wpe_cache_flush is available on WPE servers)
            if (function_exists('wpe_cache_flush')) {
                wpe_cache_flush();
                $cleared[] = 'wpe_page_cache';
            }

            // 4. WP Super Cache
            if (function_exists('wp_cache_clear_cache')) {
                wp_cache_clear_cache();
                $cleared[] = 'wp_super_cache';
            }

            // 5. W3 Total Cache
            if (function_exists('w3tc_flush_all')) {
                w3tc_flush_all();
                $cleared[] = 'w3tc';
            }

            return ['ok'=>true,'cleared'=>$cleared];
        },
    ]);

    /* ----------------------------------------------------------
       POST /gallery  (NEW v0.3)
       Build a complete page with a native Elementor gallery widget.
       Writes _elementor_data as the 3-container structure
       (before-HTML / gallery-container / after-HTML) and clears
       the page CSS so it regenerates on next view.

       Body: {
         post_id,
         before_html,        // HTML for sections above the gallery
         after_html,         // HTML for sections below the gallery
         gallery_images,     // [{ id, url }, ...]
         columns?,           // default 3
         bg_color?           // default #04060f
       }
       ---------------------------------------------------------- */
    register_rest_route(IMP_NS, '/gallery', [
        'methods'             => 'POST',
        'permission_callback' => $auth,
        'callback'            => function (WP_REST_Request $req) {
            $b       = $req->get_json_params();
            $post_id = (int)($b['post_id'] ?? 0);
            if (!$post_id || !get_post($post_id))
                return new WP_Error('bad_post','post_id not found',['status'=>404]);

            $images   = $b['gallery_images'] ?? [];
            if (empty($images))
                return new WP_Error('no_images','gallery_images is required and must not be empty',['status'=>400]);

            $before   = $b['before_html']  ?? '';
            $after    = $b['after_html']   ?? '';
            $cols     = max(1, min(6, (int)($b['columns'] ?? 3)));
            $bg       = sanitize_hex_color($b['bg_color'] ?? '#04060f') ?: '#04060f';
            $r        = 'imp_rand_id';

            $no_pad  = ['unit'=>'px','top'=>'0','right'=>'0','bottom'=>'0','left'=>'0','isLinked'=>true];
            $gal_pad = ['unit'=>'px','top'=>'8','right'=>'20','bottom'=>'48','left'=>'20','isLinked'=>false];

            $data = [
                // ---- BEFORE: HTML widget ----
                [
                    'id'       => $r(),
                    'elType'   => 'container',
                    'settings' => ['content_width'=>'full','padding'=>$no_pad],
                    'elements' => [[
                        'id'         => $r(),
                        'elType'     => 'widget',
                        'widgetType' => 'html',
                        'settings'   => ['html'=>$before],
                        'elements'   => [],
                    ]],
                ],
                // ---- GALLERY container ----
                [
                    'id'       => $r(),
                    'elType'   => 'container',
                    'settings' => [
                        'content_width'        => 'boxed',
                        'padding'              => $gal_pad,
                        'background_background'=> 'classic',
                        'background_color'     => $bg,
                    ],
                    'elements' => [[
                        'id'         => $r(),
                        'elType'     => 'widget',
                        'widgetType' => 'gallery',
                        'settings'   => [
                            'gallery_type'              => 'single',
                            'gallery'                   => $images,
                            'columns'                   => $cols,
                            'columns_tablet'            => 2,
                            'columns_mobile'            => 1,
                            'gallery_layout'            => 'masonry',
                            'thumbnail_image_size'      => 'large',
                            'gap'                       => ['unit'=>'px','size'=>16,'sizes'=>[]],
                            'image_border_radius'       => ['unit'=>'px','size'=>14,'sizes'=>[]],
                            'image_border_radius_hover' => ['unit'=>'px','size'=>14,'sizes'=>[]],
                            'image_hover_animation'     => 'shrink-contained',
                            'image_animation_duration'  => ['unit'=>'px','size'=>800,'sizes'=>[]],
                            'image_blend_mode'          => 'normal',
                            'link'                      => 'file',
                            'open_lightbox'             => 'yes',
                        ],
                        'elements' => [],
                    ]],
                ],
                // ---- AFTER: HTML widget ----
                [
                    'id'       => $r(),
                    'elType'   => 'container',
                    'settings' => ['content_width'=>'full','padding'=>$no_pad],
                    'elements' => [[
                        'id'         => $r(),
                        'elType'     => 'widget',
                        'widgetType' => 'html',
                        'settings'   => ['html'=>$after],
                        'elements'   => [],
                    ]],
                ],
            ];

            $json = wp_json_encode($data);
            update_post_meta($post_id, '_elementor_data',      wp_slash($json)); // v0.9 FIX (see /elementor-data)
            update_post_meta($post_id, '_elementor_edit_mode', 'builder');

            // clear per-page CSS so it regenerates
            if (class_exists('\Elementor\Core\Files\CSS\Post')) {
                (new \Elementor\Core\Files\CSS\Post($post_id))->delete();
            }
            imp_regen_css();

            return [
                'ok'          => true,
                'post_id'     => $post_id,
                'image_count' => count($images),
                'note'        => 'Open the page in Elementor and save once (or Tools -> Regenerate CSS) to fully enqueue gallery assets.',
            ];
        },
    ]);

}); // end rest_api_init

/* ============================================================
   SETTINGS PAGE
   ============================================================ */
add_action('admin_menu', function () {
    add_options_page('Impressions Control','Impressions Control','manage_options','impressions-control', function () {
        if (!current_user_can('manage_options')) return;

        if (isset($_POST['imp_rotate']) && check_admin_referer('imp_rotate')) {
            update_option(IMP_KEY_OPT, wp_generate_password(48, false, false));
            echo '<div class="notice notice-success"><p>API key rotated.</p></div>';
        }

        if (isset($_POST['imp_pair']) && check_admin_referer('imp_pair')) {
            $slug   = sanitize_title($_POST['imp_slug'] ?? imp_default_slug());
            $result = imp_pair_with_hub($slug);
            if (is_wp_error($result)) {
                echo '<div class="notice notice-error"><p>Pairing failed: '.esc_html($result->get_error_message()).'</p></div>';
            } else {
                echo '<div class="notice notice-success"><p>Paired with Hub as "'.esc_html($slug).'".</p></div>';
            }
        }

        if (isset($_POST['imp_safe_save']) && check_admin_referer('imp_safe_save') && !defined('IMP_SAFE_THEME')) {
            $s = sanitize_title($_POST['imp_safe_theme'] ?? '');
            if ($s !== '' && !is_dir(get_theme_root() . '/' . $s)) {
                echo '<div class="notice notice-error"><p>Theme "'.esc_html($s).'" is not installed.</p></div>';
            } else {
                update_option(IMP_SAFE_THEME_OPT, $s, false);
                echo '<div class="notice notice-success"><p>Safe theme '.($s === '' ? 'turned off' : 'set to "'.esc_html($s).'"').'.</p></div>';
            }
        }

        $key         = get_option(IMP_KEY_OPT);
        $url         = home_url();
        $slug        = get_option(IMP_SLUG_OPT) ?: imp_default_slug();
        $paired_at   = get_option(IMP_PAIRED_OPT);

        echo '<div class="wrap"><h1>Impressions Control</h1>';
        echo '<p>Version: <code>'.esc_html(IMP_VERSION).'</code> &middot; Base URL: <code>'.esc_html($url).'</code></p>';
        echo '<p>API key (server-to-server, keep secret):</p>';
        echo '<p><input type="text" readonly style="width:560px;font-family:monospace" value="'.esc_attr($key).'"></p>';
        echo '<form method="post">'.wp_nonce_field('imp_rotate','_wpnonce',true,false);
        echo '<button class="button" name="imp_rotate" value="1" onclick="return confirm(\'Rotate the key? The old key stops working immediately.\')">Rotate key</button></form>';

        echo '<hr style="margin:24px 0">';
        echo '<h2>Hub connection</h2>';
        if ($paired_at) {
            echo '<p style="color:#1a7a1a">&#10003; Paired as <code>'.esc_html($slug).'</code> (last paired '.esc_html($paired_at).' site time).</p>';
        } else {
            echo '<p style="color:#a00">Not yet paired.</p>';
        }
        echo '<form method="post">'.wp_nonce_field('imp_pair','_wpnonce',true,false);
        echo '<p><label>Slug &nbsp;<input type="text" name="imp_slug" value="'.esc_attr($slug).'" style="font-family:monospace"></label></p>';
        echo '<button class="button button-primary" name="imp_pair" value="1">Pair with Hub</button>';
        echo '<p style="margin-top:6px;color:#666">Hub: <code>'.esc_html(IMP_HUB_URL).'</code></p></form>';

        echo '<hr style="margin:24px 0">';
        echo '<h2>Safe theme for Hub writes</h2>';
        echo '<p>While the Hub writes to this site, load this plain theme instead of the real one (fixes memory-exhausted saves on old themes). Public pages and wp-admin are unaffected. Leave empty to turn off.</p>';
        echo '<form method="post">'.wp_nonce_field('imp_safe_save','_wpnonce',true,false);
        echo '<p><input type="text" name="imp_safe_theme" value="'.esc_attr(imp_safe_theme_slug()).'" placeholder="twentytwentyfour" style="font-family:monospace"'.(defined('IMP_SAFE_THEME') ? ' readonly' : '').'> ';
        echo '<button class="button" name="imp_safe_save" value="1"'.(defined('IMP_SAFE_THEME') ? ' disabled' : '').'>Save</button></p>';
        if (defined('IMP_SAFE_THEME')) echo '<p style="color:#666">Set by <code>IMP_SAFE_THEME</code> in wp-config.php.</p>';
        echo '</form>';

        echo '<hr style="margin:24px 0">';
        echo '<h2>Endpoints (v0.9)</h2>';
        echo '<table class="widefat" style="max-width:760px"><thead><tr><th>Method</th><th>Path</th><th>What it does</th></tr></thead><tbody>';
        $eps = [
            ['GET',  '/state',          'Read full site state (theme, Elementor, pages, templates, forms)'],
            ['GET/POST', '/safe-theme', 'Read / set the safe theme loaded during Hub writes; empty = off (NEW v0.9.3)'],
            ['GET',  '/site-settings',  'Dump active Elementor kit settings'],
            ['POST', '/site-settings',  'Apply settings JSON to kit + regenerate CSS'],
            ['POST', '/apply-baseline', 'Apply bundled baseline.json to kit'],
            ['POST', '/page',           'Create or update a page (content, meta, Elementor data)'],
            ['POST', '/media',          'Sideload + resize an image from a URL into the Media Library'],
            ['GET',  '/media-search',   'Search Media Library — returns id, url, width, height'],
            ['GET',  '/media',          'Browse/paginate the Media Library (NEW v0.5)'],
            ['POST', '/media/{id}',     'Update media metadata: alt_text, title, caption, description (NEW v0.5)'],
            ['POST', '/posts',          'Create or update a blog post, incl. scheduling via status:future (NEW v0.5)'],
            ['GET',  '/posts',          'List posts (NEW v0.5)'],
            ['GET',  '/posts/{id}',     'Read one post\'s full content/excerpt/categories/tags (NEW v0.5.2)'],
            ['GET',  '/seo',            'Read a post\'s AIOSEO title/description/focus keyword + SEO score (v0.5.1: score added, crash-hardened)'],
            ['POST', '/seo',            'Write a post\'s AIOSEO title/description/focus keyword (v0.5.1: crash-hardened)'],
            ['POST', '/elementor-data', 'Write _elementor_data to any post + clear its CSS'],
            ['POST', '/clear-cache',    'Clear Elementor + WPE/W3TC/WP Super Cache'],
            ['POST', '/gallery',        'Build a full 3-container gallery page (before/gallery/after)'],
            ['GET/POST', '/design/kit',        'Read / write Elementor global kit (colors, fonts, width) with undo (NEW v0.9)'],
            ['GET/POST', '/design/assets',     'Site-wide design CSS + JS saved as versioned files, enqueued everywhere, with undo (NEW v0.9)'],
            ['POST', '/design/asset-import',   'Import image / video / font from an allow-listed URL (NEW v0.9)'],
            ['POST', '/design/template',       'Create/update a Theme Builder template (header, footer, single, archive, 404...) + conditions (NEW v0.9)'],
            ['POST', '/design/page',           'Create/update a real Elementor page from element JSON, admin-owned, snapshotted (NEW v0.9)'],
            ['GET',  '/design/page/{id}',      'Read back a page/template: type, author, template, Elementor data (NEW v0.9)'],
            ['POST', '/change/revert',  'Revert a single change by change_id (or most recent)'],
            ['GET',  '/change/history', 'List change history for a post or site-wide'],
        ];
        foreach ($eps as [$m,$p,$d]) {
            echo '<tr><td><code>'.$m.'</code></td><td><code>'.esc_html($url.'/wp-json/impressions/v1'.$p).'</code></td><td>'.esc_html($d).'</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p style="margin-top:12px;color:#666">Send header <code>Authorization: Bearer &lt;key&gt;</code> on every request.</p></div>';
    });
});
