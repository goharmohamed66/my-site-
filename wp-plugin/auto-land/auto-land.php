<?php
/**
 * Plugin Name:       Auto Land Wp Funnels v1.0.11
 * Plugin URI:        https://indigo-dog-836598.hostingersite.com/
 * Description:       Adds a REST endpoint that receives a WP Funnels JSON export from the Landing Page Auto tool and imports it as a new funnel. Authenticates with the WordPress Application Password sent in the Authorization header (admin capability required).
 * Version:           1.0.11
 * Requires at least: 5.6
 * Requires PHP:      7.2
 * Author:            Tech Operation
 * Author URI:        https://indigo-dog-836598.hostingersite.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       landing-auto-bridge
 *
 * INSTALL (recommended — Plugins UI):
 *   1. Download landing-auto-bridge.zip
 *   2. WP Admin → Plugins → Add New → Upload Plugin → choose the ZIP → Install Now
 *   3. Activate
 *   4. Test: open https://yourstore.com/wp-json/landing-auto/v1/ping (logged in as admin)
 */

if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
    register_rest_route('landing-auto/v1', '/ping', [
        'methods'  => 'GET',
        'callback' => function () {
            return [
                'ok'        => true,
                'plugin'    => 'Auto Land Wp Funnels',
                'version'   => '1.0.11',
                'wpfunnels' => defined('WPFNL_VERSION') || class_exists('WPFunnels\\Wpfnl'),
            ];
        },
        'permission_callback' => function () {
            return current_user_can('manage_options');
        },
    ]);

    register_rest_route('landing-auto/v1', '/import-funnel', [
        'methods'  => 'POST',
        'callback' => 'lab_import_funnel',
        'permission_callback' => function () {
            return current_user_can('manage_options');
        },
    ]);

    // POST /self-update — fetches a fresh ZIP from `zip_url` and overwrites the
    // plugin in place. Lets us push updates to every store remotely after the
    // first manual install, no more re-uploads.
    //
    //   POST /wp-json/landing-auto/v1/self-update
    //   { "zip_url": "https://indigo-dog-836598.hostingersite.com/wp-plugin/auto-land.zip" }
    register_rest_route('landing-auto/v1', '/self-update', [
        'methods'  => 'POST',
        'callback' => 'lab_self_update',
        'permission_callback' => function () { return current_user_can('manage_options') && current_user_can('install_plugins'); },
    ]);

    // POST /duplicate-funnel — clone an existing live funnel and apply edits
    register_rest_route('landing-auto/v1', '/duplicate-funnel', [
        'methods'  => 'POST',
        'callback' => 'lab_duplicate_funnel',
        'permission_callback' => function () { return current_user_can('manage_options'); },
    ]);

    // GET /debug-funnel/{id} — dump funnel meta + child steps for diagnosis
    register_rest_route('landing-auto/v1', '/debug-funnel/(?P<id>\d+)', [
        'methods'  => 'GET',
        'callback' => function ($req) {
            global $wpdb;
            $id = (int) $req['id'];
            $post = get_post($id);
            if (!$post) return new WP_Error('not_found', 'No such post', ['status' => 404]);
            $meta_keys = ['_steps_order', '_steps', '_first_step', '_funnel_data', 'funnel_identifier', '_wpfnl_main_product'];
            $meta = [];
            foreach ($meta_keys as $k) {
                $raw = get_post_meta($id, $k, true);
                $meta[$k] = is_array($raw) ? $raw : (is_string($raw) ? mb_substr($raw, 0, 500) : $raw);
            }
            // Find child steps two ways
            $by_parent = $wpdb->get_results($wpdb->prepare(
                "SELECT ID, post_title, post_name, post_status FROM {$wpdb->posts}
                 WHERE post_type='wpfunnel_steps' AND post_parent=%d", $id
            ));
            $by_meta = $wpdb->get_results($wpdb->prepare(
                "SELECT p.ID, p.post_title, p.post_name, p.post_status
                 FROM {$wpdb->posts} p
                 JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_funnel_id' AND pm.meta_value=%s
                 WHERE p.post_type='wpfunnel_steps'", (string)$id
            ));
            return [
                'funnel'   => ['id' => $id, 'title' => $post->post_title, 'type' => $post->post_type, 'status' => $post->post_status],
                'meta'     => $meta,
                'steps_by_parent' => $by_parent,
                'steps_by_meta_funnel_id' => $by_meta,
            ];
        },
        'permission_callback' => function () { return current_user_can('manage_options'); },
    ]);

    // GET /find-funnel?name=X — search wpfunnels posts by title (LIKE match)
    register_rest_route('landing-auto/v1', '/find-funnel', [
        'methods'  => 'GET',
        'callback' => function ($req) {
            $name = sanitize_text_field($req->get_param('name') ?: '');
            if ($name === '') return new WP_Error('bad', 'name required', ['status' => 400]);
            global $wpdb;
            // Prefer ORIGINAL funnels (no _imported_via meta) so search picks
            // the user's real Template, not a previous auto-import named "Template".
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT p.ID, p.post_title, p.post_status,
                        (SELECT meta_value FROM {$wpdb->postmeta}
                         WHERE post_id = p.ID AND meta_key = '_imported_via' LIMIT 1) AS imported_via
                 FROM {$wpdb->posts} p
                 WHERE p.post_type IN ('wpfunnels','wpfunnel')
                   AND p.post_status NOT IN ('trash','auto-draft')
                   AND p.post_title LIKE %s
                 ORDER BY (CASE WHEN EXISTS (
                              SELECT 1 FROM {$wpdb->postmeta} pm
                              WHERE pm.post_id = p.ID AND pm.meta_key = '_imported_via'
                          ) THEN 1 ELSE 0 END) ASC, p.ID ASC
                 LIMIT 5",
                '%' . $wpdb->esc_like($name) . '%'
            ));
            return $rows;
        },
        'permission_callback' => function () { return current_user_can('manage_options'); },
    ]);

    // GET /funnels — list every wpfunnels post (any status) with selected meta
    register_rest_route('landing-auto/v1', '/funnels', [
        'methods'  => 'GET',
        'callback' => function () {
            $rows = get_posts([
                'post_type'   => 'wpfunnels',
                'post_status' => ['publish', 'draft', 'pending', 'trash', 'private'],
                'numberposts' => 50,
                'orderby'     => 'ID',
                'order'       => 'DESC',
            ]);
            $out = [];
            foreach ($rows as $r) {
                $meta = get_post_meta($r->ID);
                $out[] = [
                    'id'           => $r->ID,
                    'title'        => $r->post_title,
                    'status'       => $r->post_status,
                    'date'         => $r->post_date,
                    'imported_via' => $meta['_imported_via'][0] ?? null,
                    'first_step'   => $meta['_first_step'][0] ?? null,
                    'funnel_type'  => $meta['_wpfnl_funnel_type'][0] ?? null,
                    'meta_keys'    => array_keys($meta),
                ];
            }
            return $out;
        },
        'permission_callback' => function () { return current_user_can('manage_options'); },
    ]);

    // DELETE /funnels/{id} — hard-delete a funnel and all its step posts.
    // Bypasses WP Funnels' own deletion hooks (which crash on orphan/incomplete
    // funnels missing meta) by deleting straight via $wpdb.
    register_rest_route('landing-auto/v1', '/funnels/(?P<id>\d+)', [
        'methods'  => 'DELETE',
        'callback' => function ($req) {
            global $wpdb;
            $id = (int) $req['id'];
            $post = get_post($id);
            if (!$post || !in_array($post->post_type, ['wpfunnels','wpfunnel'], true)) {
                return new WP_Error('not_found', 'Funnel not found', ['status' => 404]);
            }
            $steps = get_posts([
                'post_type'   => 'wpfunnel_steps',
                'post_parent' => $id,
                'numberposts' => -1,
                'post_status' => 'any',
            ]);
            $deleted_steps = [];
            // Delete via $wpdb directly to skip WP Funnels' delete hooks that
            // assume meta exists (and fatal otherwise).
            foreach ($steps as $s) {
                $wpdb->delete($wpdb->postmeta, ['post_id' => $s->ID]);
                $wpdb->delete($wpdb->posts,    ['ID'      => $s->ID]);
                $deleted_steps[] = $s->ID;
            }
            $wpdb->delete($wpdb->postmeta, ['post_id' => $id]);
            $wpdb->delete($wpdb->posts,    ['ID'      => $id]);
            clean_post_cache($id);
            return ['deleted_funnel' => $id, 'deleted_steps' => $deleted_steps];
        },
        'permission_callback' => function () { return current_user_can('manage_options'); },
    ]);
});

/**
 * Import a funnel JSON exactly the way WP Funnels' own import-from-template does:
 * insert wpfunnel CPT post, then insert each step as wpfunnel_steps CPT and
 * write all the meta (incl. _elementor_data) so Elementor renders correctly.
 *
 * Body: the raw export JSON the tool produces (an array starting with the funnel
 * descriptor, followed by steps_data).
 */
function lab_import_funnel(WP_REST_Request $req) {
    $body = $req->get_json_params();
    if (!is_array($body) || !isset($body[0]['steps_data'])) {
        return new WP_Error('bad_payload', 'Funnel JSON must be an array starting with the funnel descriptor (steps_data missing).', ['status' => 400]);
    }

    $funnel = $body[0];
    $name   = sanitize_text_field($funnel['funnel_name'] ?? 'Imported Funnel');

    // Optional: WC product ID to link into the checkout step's offer products.
    // Set by the Landing Page Auto tool after it duplicates the TEMPLATE product.
    $linked_product_id = 0;
    if (!empty($funnel['funnel_meta']['_linked_product_id'])) {
        $v = $funnel['funnel_meta']['_linked_product_id'];
        $linked_product_id = (int) (is_array($v) ? $v[0] : $v);
        unset($funnel['funnel_meta']['_linked_product_id']);
    }

    // ── 1. Create the funnel post ────────────────────────────────────────
    $funnel_id = wp_insert_post([
        'post_title'  => $name,
        'post_status' => 'publish',
        'post_type'   => 'wpfunnels',
    ], true);
    if (is_wp_error($funnel_id)) return $funnel_id;

    // funnel_meta from the export descriptor (NOT the steps' meta).
    // We carry it over but DEFER the rewrite of step IDs until after we know
    // the new step IDs (see remap step at the end).
    if (!empty($funnel['funnel_meta']) && is_array($funnel['funnel_meta'])) {
        foreach ($funnel['funnel_meta'] as $mk => $mv) {
            update_post_meta($funnel_id, $mk, maybe_unserialize_safe($mv));
        }
    }
    // Some exports also use the legacy "meta" key for funnel-level meta.
    if (!empty($funnel['meta']) && is_array($funnel['meta'])) {
        foreach ($funnel['meta'] as $mk => $mv) {
            update_post_meta($funnel_id, $mk, maybe_unserialize_safe($mv));
        }
    }

    // ── 2. Create each step ──────────────────────────────────────────────
    $created_steps = [];
    $step_ids      = [];
    $id_map        = []; // old step ID (from export) → new step ID

    foreach ($funnel['steps_data'] as $idx => $step) {
        $old_step_id = isset($step['ID']) ? (int) $step['ID'] : (isset($step['id']) ? (int) $step['id'] : 0);
        $step_title = sanitize_text_field($step['title'] ?? ($name . ' — Step ' . ($idx + 1)));
        $step_slug  = sanitize_title($step['slug'] ?? ($name . '-step-' . ($idx + 1)));

        $step_id = wp_insert_post([
            'post_title'   => $step_title,
            'post_name'    => $step_slug,
            'post_status'  => 'publish',
            'post_type'    => 'wpfunnel_steps',
            'post_content' => $step['post_content'] ?? '',
            'post_parent'  => $funnel_id,
        ], true);
        if (is_wp_error($step_id)) {
            // Roll back created posts on failure
            wp_delete_post($funnel_id, true);
            foreach ($step_ids as $sid) wp_delete_post($sid, true);
            return $step_id;
        }
        $step_ids[]      = $step_id;
        $created_steps[] = ['id' => $step_id, 'title' => $step_title, 'slug' => $step_slug, 'old_id' => $old_step_id];
        if ($old_step_id) $id_map[$old_step_id] = $step_id;

        // Link to parent funnel
        update_post_meta($step_id, '_funnel_id', $funnel_id);
        if (!empty($step['step_type'])) {
            update_post_meta($step_id, '_step_type', sanitize_text_field($step['step_type']));
        }

        // ── Write all step meta verbatim ──────────────────────────────────
        if (!empty($step['meta']) && is_array($step['meta'])) {
            foreach ($step['meta'] as $mk => $mv) {
                $val = maybe_unserialize_safe($mv);

                // Elementor stores _elementor_data as a JSON-encoded string. The export
                // wraps it in a 1-element array [json_string]; some templates use the
                // bare string. Either way, persist it as a STRING (not array) so
                // Elementor's frontend reads it.
                if ($mk === '_elementor_data') {
                    if (is_array($val) && count($val) === 1 && is_string($val[0])) $val = $val[0];
                    update_post_meta($step_id, $mk, wp_slash($val));
                    continue;
                }

                update_post_meta($step_id, $mk, $val);
            }
        }

        // Mark this post as "Built with Elementor" so Elementor takes over rendering.
        if (!get_post_meta($step_id, '_elementor_edit_mode', true)) {
            update_post_meta($step_id, '_elementor_edit_mode', 'builder');
        }
        if (!get_post_meta($step_id, '_elementor_template_type', true)) {
            update_post_meta($step_id, '_elementor_template_type', 'wp-page');
        }
        if (!get_post_meta($step_id, '_wp_page_template', true)) {
            update_post_meta($step_id, '_wp_page_template', 'elementor_canvas');
        }

        // Tell Elementor to regenerate the CSS file on next view.
        update_post_meta($step_id, '_elementor_css', null);
    }

    // ── 3. Remap old step IDs in funnel-level meta to the new ones ──────
    // The export's funnel_meta has `_first_step`, `funnel_identifier`,
    // `_steps_order`, `_steps`, `_funnel_data` — all referencing the original
    // site's step IDs (e.g. 388, 390, 398). Without remapping, WP Funnels'
    // admin UI silently skips this funnel (it can't resolve the steps).
    if (!empty($id_map)) {
        $remap_meta_keys = ['_first_step', 'funnel_identifier', '_steps_order', '_steps', '_funnel_data'];
        foreach ($remap_meta_keys as $mk) {
            $val = get_post_meta($funnel_id, $mk, true);
            if ($val === '' || $val === null) continue;
            $val = lab_remap_ids($val, $id_map);
            update_post_meta($funnel_id, $mk, $val);
        }
    }

    // Build a clean WP Funnels step list (used by the admin UI as fallback).
    $clean_steps_list = [];
    foreach ($created_steps as $idx => $cs) {
        $clean_steps_list[$idx] = [
            'id'        => $cs['id'],
            'step_type' => get_post_meta($cs['id'], '_step_type', true) ?: 'landing',
            'name'      => $cs['title'],
        ];
    }
    update_post_meta($funnel_id, '_steps_order', $clean_steps_list);
    update_post_meta($funnel_id, '_steps', $clean_steps_list);
    if (!empty($created_steps)) {
        update_post_meta($funnel_id, '_first_step', (string) $created_steps[0]['id']);
    }
    // funnel_identifier is the {sequence:step_id} JSON used by WP Funnels' Vue UI
    $identifier = [];
    foreach ($created_steps as $idx => $cs) $identifier[(string)($idx + 1)] = $cs['id'];
    update_post_meta($funnel_id, 'funnel_identifier', wp_json_encode($identifier, JSON_UNESCAPED_UNICODE));

    update_post_meta($funnel_id, '_imported_via', 'landing-auto-bridge');
    update_post_meta($funnel_id, '_imported_at', current_time('mysql'));

    // ── 4. Link the new WC product to the checkout step ──────────────────
    // WP Funnels stores selected products on each checkout/upsell step under
    // `_wpfnl_offer_products` as a serialized array of {id, qty, discount}.
    // We write this so the imported funnel is immediately wired to the new product.
    if ($linked_product_id > 0) {
        $offer_payload = serialize([[
            'id'           => $linked_product_id,
            'qty'          => 1,
            'discount_type' => 'no_discount',
            'discount'     => 0,
        ]]);
        foreach ($created_steps as $cs) {
            $type = get_post_meta($cs['id'], '_step_type', true);
            if ($type === 'checkout' || $type === 'upsell' || $type === 'downsell' || $type === 'optin') {
                update_post_meta($cs['id'], '_wpfnl_offer_products', $offer_payload);
                update_post_meta($cs['id'], '_wpfnl_main_product', $linked_product_id);
            }
        }
        update_post_meta($funnel_id, '_wpfnl_main_product', $linked_product_id);
    }

    return [
        'ok'         => true,
        'funnel_id'  => $funnel_id,
        'funnel_name' => $name,
        'edit_url'   => admin_url('post.php?post=' . $funnel_id . '&action=edit'),
        'admin_url'  => admin_url('admin.php?page=wpfunnels&funnel_id=' . $funnel_id),
        'steps'      => $created_steps,
    ];
}

/**
 * Duplicate a live source funnel (post + steps + meta) and apply a payload of
 * modifications (text replacements, image injection, testimonial injection,
 * etc.) to the cloned _elementor_data.
 *
 * Body schema:
 *   {
 *     "source_id"?: int,                  // explicit source funnel ID
 *     "source_name"?: "Template",         // OR LIKE-match by title
 *     "new_name": "...",
 *     "slug": "newslug",                  // step slugs become newslug, newslug-checkout, …
 *     "linked_product_id"?: int,
 *     "modifications": {
 *       "text_replacements": [            // applied IN ORDER (longest-first)
 *         { "find": "scaling-checkout", "replace": "newslug-checkout" },
 *         …
 *       ],
 *       "counters": {                     // counter-based replacements
 *         "منافع":   ["benefit1", "benefit2", …],
 *         "عنوان":   ["headline1", …],
 *         "وصف":     ["sub1", …]
 *       },
 *       "html_pairs": [                   // "<h3>عنوان</h3><p>وصف</p>" pair counter
 *         { "find": "<h3>عنوان</h3><p>وصف</p>",
 *           "format": "<h3>{0}</h3><p>{1}</p>",
 *           "values": [["h1","sh1"], ["h2","sh2"], …] }
 *       ],
 *       "images": [
 *         { "id": 8086, "url": "https://…/img.jpg", "alt": "IMG (1)" }, …
 *       ],
 *       "testimonials": [
 *         { "text": "…", "name": "أحمد" }, …
 *       ]
 *     }
 *   }
 */
function lab_duplicate_funnel(WP_REST_Request $req) {
    $body = $req->get_json_params();
    if (!is_array($body)) return new WP_Error('bad_payload', 'JSON body required', ['status' => 400]);

    // ── 1. Resolve source funnel ─────────────────────────────────────────
    // Prefer the original Template (no _imported_via meta), oldest first.
    $source_id = !empty($body['source_id']) ? (int) $body['source_id'] : 0;
    if (!$source_id && !empty($body['source_name'])) {
        global $wpdb;
        $like = '%' . $wpdb->esc_like($body['source_name']) . '%';
        $source_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             WHERE p.post_type IN ('wpfunnels','wpfunnel')
               AND p.post_status NOT IN ('trash','auto-draft')
               AND p.post_title LIKE %s
             ORDER BY (CASE WHEN EXISTS (
                          SELECT 1 FROM {$wpdb->postmeta} pm
                          WHERE pm.post_id = p.ID AND pm.meta_key = '_imported_via'
                      ) THEN 1 ELSE 0 END) ASC, p.ID ASC
             LIMIT 1",
            $like
        ));
    }
    if (!$source_id) return new WP_Error('not_found', 'Source funnel not found', ['status' => 404]);

    $source = get_post($source_id);
    if (!$source) return new WP_Error('not_found', 'Source post missing', ['status' => 404]);

    // WP Funnels links steps to funnels via `_funnel_id` meta (NOT post_parent)
    // — query both ways for compatibility.
    $source_steps = get_posts([
        'post_type'   => 'wpfunnel_steps',
        'numberposts' => -1,
        'orderby'     => 'ID',
        'order'       => 'ASC',
        'post_status' => 'any',
        'meta_query'  => [[ 'key' => '_funnel_id', 'value' => (string) $source_id ]],
    ]);
    if (empty($source_steps)) {
        // Fallback: try post_parent (for older funnels or our auto-imports)
        $source_steps = get_posts([
            'post_type'   => 'wpfunnel_steps',
            'post_parent' => $source_id,
            'numberposts' => -1,
            'orderby'     => 'ID',
            'order'       => 'ASC',
            'post_status' => 'any',
        ]);
    }
    if (empty($source_steps)) {
        // Last resort: read step IDs from `_steps_order` meta on the funnel
        $order = maybe_unserialize(get_post_meta($source_id, '_steps_order', true));
        if (is_array($order) && !empty($order)) {
            $ids = [];
            foreach ($order as $entry) {
                if (is_array($entry) && !empty($entry['id'])) $ids[] = (int) $entry['id'];
                elseif (is_numeric($entry)) $ids[] = (int) $entry;
            }
            if ($ids) {
                $source_steps = get_posts([
                    'post_type'   => 'wpfunnel_steps',
                    'post__in'    => $ids,
                    'orderby'     => 'post__in',
                    'numberposts' => -1,
                    'post_status' => 'any',
                ]);
            }
        }
    }
    if (empty($source_steps)) return new WP_Error('no_steps', 'Source funnel #' . $source_id . ' has no steps (no posts found via _funnel_id, post_parent, or _steps_order)', ['status' => 400]);

    $new_name          = sanitize_text_field($body['new_name'] ?? $source->post_title);
    $slug              = sanitize_title($body['slug'] ?? '');
    $linked_product_id = !empty($body['linked_product_id']) ? (int) $body['linked_product_id'] : 0;
    $mods              = is_array($body['modifications'] ?? null) ? $body['modifications'] : [];

    // ── 2. Clone funnel post ─────────────────────────────────────────────
    $new_funnel_id = wp_insert_post([
        'post_title'   => $new_name,
        'post_status'  => 'publish',
        'post_type'    => $source->post_type,
        'post_content' => $source->post_content,
    ], true);
    if (is_wp_error($new_funnel_id)) return $new_funnel_id;

    foreach (get_post_meta($source_id) as $mk => $mvs) {
        if (in_array($mk, ['_edit_lock', '_edit_last'], true)) continue;
        update_post_meta($new_funnel_id, $mk, maybe_unserialize($mvs[0]));
    }

    // ── 3. Clone each step ───────────────────────────────────────────────
    $id_map        = [];
    $created_steps = [];
    // The LANDING step gets the clean slug (e.g. /swimmingfloat/) because
    // that's the URL the user shares in ads. Checkout / thank-you get
    // suffixes to avoid collisions.
    $type_suffix   = ['landing' => '', 'checkout' => '-checkout', 'thankyou' => '-thank-you',
                      'upsell' => '-upsell', 'downsell' => '-downsell', 'optin' => '-optin'];

    foreach ($source_steps as $idx => $src) {
        $src_meta  = get_post_meta($src->ID);
        $step_type = $src_meta['_step_type'][0] ?? 'landing';
        $new_slug  = $slug ? ($slug . ($type_suffix[$step_type] ?? '-' . ($idx + 1))) : '';

        // Per-step counter state for benefits/headlines/etc.
        $counters  = ['benefit' => 0, 'headline' => 0, 'subheadline' => 0, 'pair' => 0, 'image' => 0, 'testimonial' => 0];

        // post_content: apply text replacements
        $new_content = lab_apply_text($src->post_content, $mods, $counters);

        // Insert the new step. We let WP auto-uniquify the slug if needed
        // (collisions become "<slug>-2" etc.) — that's safer than aggressive
        // rival deletion which proved to wipe steps from the same run.
        $new_step_id = wp_insert_post([
            'post_title'   => $new_name . ' — ' . ucfirst($step_type),
            'post_name'    => $new_slug ?: ($src->post_name . '-copy'),
            'post_status'  => 'publish',
            'post_type'    => 'wpfunnel_steps',
            'post_parent'  => $new_funnel_id,
            'post_content' => $new_content,
        ], true);
        if (is_wp_error($new_step_id)) {
            wp_delete_post($new_funnel_id, true);
            foreach ($created_steps as $cs) wp_delete_post($cs['id'], true);
            return $new_step_id;
        }

        $id_map[$src->ID] = $new_step_id;
        $created_steps[]  = ['id' => $new_step_id, 'old_id' => $src->ID, 'type' => $step_type, 'slug' => $new_slug];

        // Copy step meta (with elementor data modifications)
        foreach ($src_meta as $mk => $mvs) {
            if (in_array($mk, ['_edit_lock', '_edit_last'], true)) continue;
            $val = maybe_unserialize($mvs[0]);

            if ($mk === '_elementor_data') {
                $elem_str = is_array($val) ? ($val[0] ?? '') : $val;
                if (is_string($elem_str)) {
                    $tree = json_decode($elem_str, true);
                    if (is_array($tree)) {
                        $tree = lab_walk_elementor($tree, $mods, $counters);
                        $val  = wp_json_encode($tree, JSON_UNESCAPED_UNICODE);
                    }
                }
                update_post_meta($new_step_id, $mk, wp_slash($val));
                continue;
            }

            update_post_meta($new_step_id, $mk, $val);
        }

        update_post_meta($new_step_id, '_funnel_id', $new_funnel_id);
        update_post_meta($new_step_id, '_elementor_css', null);
    }

    // ── 4. Remap step IDs in funnel-level meta ───────────────────────────
    $remap_keys = ['_first_step', 'funnel_identifier', '_steps_order', '_steps', '_funnel_data'];
    foreach ($remap_keys as $mk) {
        $val = get_post_meta($new_funnel_id, $mk, true);
        if ($val === '' || $val === null) continue;
        update_post_meta($new_funnel_id, $mk, lab_remap_ids($val, $id_map));
    }

    // ── 5. Link product to checkout/upsell/downsell/optin steps ──────────
    // WP Funnels reads it from `_wpfnl_<step_type>_products` (per get_offer_data
    // in wpfnl-functions.php). Each entry is an array with `id` + `quantity`.
    if ($linked_product_id > 0) {
        $offer_entry = [[
            'id'            => $linked_product_id,
            'quantity'      => 1,
            'qty'           => 1,                  // legacy alias
            'discount_type' => 'no_discount',
            'discount'      => 0,
        ]];
        foreach ($created_steps as $cs) {
            if (in_array($cs['type'], ['checkout', 'upsell', 'downsell', 'optin'], true)) {
                update_post_meta($cs['id'], '_wpfnl_' . $cs['type'] . '_products', $offer_entry);
                // Keep our legacy meta too so older code paths still work
                update_post_meta($cs['id'], '_wpfnl_offer_products',                $offer_entry);
                update_post_meta($cs['id'], '_wpfnl_main_product',                  $linked_product_id);
                // The "Funnel Trigger" UI reads this — force it to "assign" mode
                // so the product list above is honoured (not Dynamic Funnels).
                update_post_meta($cs['id'], '_wpfnl_funnel_trigger',                'assign');
            }
        }
        update_post_meta($new_funnel_id, '_wpfnl_main_product', $linked_product_id);
    }

    update_post_meta($new_funnel_id, '_imported_via', 'auto-land-duplicate');
    update_post_meta($new_funnel_id, '_imported_at', current_time('mysql'));

    return [
        'ok'          => true,
        'source_id'   => $source_id,
        'funnel_id'   => $new_funnel_id,
        'funnel_name' => $new_name,
        'edit_url'    => admin_url('post.php?post=' . $new_funnel_id . '&action=edit'),
        'admin_url'   => admin_url('admin.php?page=wpfunnels&funnel_id=' . $new_funnel_id),
        'steps'       => array_map(function ($cs) {
            return ['id' => $cs['id'], 'type' => $cs['type'], 'slug' => $cs['slug']];
        }, $created_steps),
    ];
}

/**
 * Apply text + counter + html-pair replacements to a single string. Mutates
 * $counters by reference so each call advances the counter state.
 */
function lab_apply_text($s, $mods, &$counters) {
    if (!is_string($s) || $s === '') return $s;

    // 1. Plain text replacements (run in given order — JS sends longest-first)
    if (!empty($mods['text_replacements'])) {
        foreach ($mods['text_replacements'] as $r) {
            if (isset($r['find'])) $s = str_replace($r['find'], $r['replace'] ?? '', $s);
        }
    }

    // 2. HTML pair replacements (e.g. <h3>عنوان</h3><p>وصف</p>)
    if (!empty($mods['html_pairs'])) {
        foreach ($mods['html_pairs'] as $pair) {
            $find   = $pair['find']   ?? '';
            $format = $pair['format'] ?? '';
            $values = $pair['values'] ?? [];
            if ($find === '' || empty($values)) continue;
            while (($pos = strpos($s, $find)) !== false) {
                $i  = $counters['pair'] % count($values);
                $v  = $values[$i];
                $rp = $format;
                if (is_array($v)) {
                    foreach ($v as $j => $vv) $rp = str_replace('{' . $j . '}', $vv, $rp);
                } else {
                    $rp = str_replace('{0}', $v, $rp);
                }
                $s = substr($s, 0, $pos) . $rp . substr($s, $pos + strlen($find));
                $counters['pair']++;
            }
        }
    }

    // 3. Counter replacements: each occurrence advances index
    if (!empty($mods['counters']) && is_array($mods['counters'])) {
        foreach ($mods['counters'] as $find => $values) {
            if (!is_array($values) || empty($values)) continue;
            $key = $find === 'منافع' ? 'benefit' : ($find === 'عنوان' ? 'headline' : ($find === 'وصف' ? 'subheadline' : md5($find)));
            // Use a u-modifier-safe regex on the literal string
            $pattern = '/' . preg_quote($find, '/') . '/u';
            $s = preg_replace_callback($pattern, function ($m) use ($values, $key, &$counters) {
                $i = $counters[$key] ?? 0;
                $r = $values[$i] ?? ($values[count($values) - 1] ?? '');
                $counters[$key] = $i + 1;
                return $r;
            }, $s);
        }
    }

    return $s;
}

/**
 * Walk an Elementor data tree (parsed JSON), apply text/counter/pair edits to
 * every string leaf, and inject images + testimonials into matching widgets.
 */
function lab_walk_elementor($node, $mods, &$counters) {
    if (!is_array($node)) return $node;

    // Image injection on image-* widgets
    $w = $node['widgetType'] ?? null;
    if ($w && !empty($mods['images']) && is_array($mods['images'])) {
        $imgs = $mods['images'];
        if (in_array($w, ['image-box', 'image', 'icon-box'], true)
            && isset($node['settings']) && is_array($node['settings'])
            && isset($node['settings']['image'])) {
            $i = $counters['image'] % count($imgs);
            $img = $imgs[$i];
            $node['settings']['image'] = [
                'url'    => $img['url'] ?? '',
                'id'     => $img['id']  ?? '',
                'size'   => 'full',
                'alt'    => $img['alt'] ?? '',
                'source' => 'library',
            ];
            $counters['image']++;
        }
        if ($w === 'image-carousel' && isset($node['settings']) && is_array($node['settings'])) {
            $node['settings']['carousel'] = array_map(function ($img) {
                return [
                    'url'    => $img['url'] ?? '',
                    'id'     => $img['id']  ?? '',
                    'size'   => 'full',
                    'alt'    => $img['alt'] ?? '',
                    'source' => 'library',
                ];
            }, $imgs);
        }
    }

    // Testimonial injection
    if ($w === 'testimonial' && !empty($mods['testimonials']) && isset($node['settings']) && is_array($node['settings'])) {
        $ts = $mods['testimonials'];
        $i  = $counters['testimonial'] % count($ts);
        $t  = $ts[$i];
        if (!empty($t['text'])) $node['settings']['testimonial_content'] = $t['text'];
        if (!empty($t['name'])) $node['settings']['testimonial_name']    = $t['name'];
        $counters['testimonial']++;
    }

    // Recurse into children + apply text replacements to string leaves
    foreach ($node as $k => $v) {
        if (is_string($v)) {
            $node[$k] = lab_apply_text($v, $mods, $counters);
        } elseif (is_array($v)) {
            $node[$k] = lab_walk_elementor($v, $mods, $counters);
        }
    }

    return $node;
}

/**
 * WP Funnels exports often store meta values as already-decoded arrays. If a
 * value is a string that looks like a serialized PHP value, unserialize it;
 * otherwise return as-is.
 */
function maybe_unserialize_safe($val) {
    if (is_string($val) && preg_match('/^[aOs]:\d+:/', $val)) {
        $u = @unserialize($val);
        if ($u !== false || $val === 'b:0;') return $u;
    }
    return $val;
}

/**
 * Remap old step IDs to new ones inside any meta value (string, array,
 * serialized PHP, or JSON). Walks recursively through arrays; for strings
 * does a regex replace on whole-number occurrences only (so we don't touch
 * IDs embedded inside larger numbers).
 */
function lab_remap_ids($val, array $id_map) {
    if (empty($id_map)) return $val;

    // Arrays: walk recursively, also remap integer leaves whose value matches an old ID.
    if (is_array($val)) {
        $out = [];
        foreach ($val as $k => $v) {
            $out[$k] = lab_remap_ids($v, $id_map);
        }
        return $out;
    }

    if (is_int($val)) {
        return isset($id_map[$val]) ? $id_map[$val] : $val;
    }

    if (is_string($val)) {
        // 1. Try unserialize: if it's serialized PHP, recurse into the structure
        if (preg_match('/^[aOsb]:\d*:?/', $val)) {
            $u = @unserialize($val);
            if ($u !== false || $val === 'b:0;') {
                return serialize(lab_remap_ids($u, $id_map));
            }
        }
        // 2. Try JSON: if it's a JSON object/array, recurse and re-encode
        $trimmed = trim($val);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $j = json_decode($val, true);
            if (is_array($j)) {
                return wp_json_encode(lab_remap_ids($j, $id_map), JSON_UNESCAPED_UNICODE);
            }
        }
        // 3. Plain string: replace any whole-number occurrence of an old ID with the new ID
        $replacements = [];
        foreach ($id_map as $old => $new) {
            $replacements['/(?<![0-9])' . $old . '(?![0-9])/'] = (string) $new;
        }
        return preg_replace(array_keys($replacements), array_values($replacements), $val);
    }

    return $val;
}

/**
 * Self-update: download the latest plugin ZIP from `zip_url` and replace this
 * plugin's files in place. Requires manage_options + install_plugins caps.
 *
 * Body:
 *   { "zip_url": "https://.../auto-land.zip" }
 *
 * Returns:
 *   { ok: true, old_version, new_version }
 */
function lab_self_update(WP_REST_Request $req) {
    $body = $req->get_json_params();
    $zip_url = is_array($body) ? ($body['zip_url'] ?? '') : '';
    if (!$zip_url || !preg_match('#^https?://#i', $zip_url)) {
        return new WP_Error('bad_url', 'zip_url required (https://…)', ['status' => 400]);
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-admin/includes/misc.php';

    if (!WP_Filesystem()) {
        return new WP_Error('fs_init', 'WP_Filesystem init failed', ['status' => 500]);
    }
    global $wp_filesystem;

    $old_version = '';
    $self_path = plugin_dir_path(__FILE__) . basename(__FILE__);
    if (file_exists($self_path)) {
        $hdr = get_file_data($self_path, ['Version' => 'Version']);
        $old_version = $hdr['Version'] ?? '';
    }

    // 1. Download the ZIP to a temp file
    $tmp = download_url($zip_url, 60);
    if (is_wp_error($tmp)) return new WP_Error('download_failed', 'Download failed: ' . $tmp->get_error_message(), ['status' => 500]);

    // 2. Unzip into wp-content/plugins/ (overwrites the existing folder)
    $plugins_dir = WP_PLUGIN_DIR;
    $unzip = unzip_file($tmp, $plugins_dir);
    @unlink($tmp);
    if (is_wp_error($unzip)) return new WP_Error('unzip_failed', 'Unzip failed: ' . $unzip->get_error_message(), ['status' => 500]);

    // 3. Read the new version from the just-extracted file
    $new_version = '';
    if (file_exists($self_path)) {
        $hdr = get_file_data($self_path, ['Version' => 'Version']);
        $new_version = $hdr['Version'] ?? '';
    }

    return [
        'ok'          => true,
        'old_version' => $old_version,
        'new_version' => $new_version,
        'plugins_dir' => $plugins_dir,
    ];
}
