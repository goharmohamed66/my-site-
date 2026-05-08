<?php
/**
 * Plugin Name:       Auto Land
 * Plugin URI:        https://indigo-dog-836598.hostingersite.com/
 * Description:       Adds a REST endpoint that receives a WP Funnels JSON export from the Landing Page Auto tool and imports it as a new funnel. Authenticates with the WordPress Application Password sent in the Authorization header (admin capability required).
 * Version:           1.0.3
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
                'plugin'    => 'Auto Land',
                'version'   => '1.0.3',
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

    // GET /funnels — list every wpfunnel post (any status) with selected meta
    register_rest_route('landing-auto/v1', '/funnels', [
        'methods'  => 'GET',
        'callback' => function () {
            $rows = get_posts([
                'post_type'   => 'wpfunnel',
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

    // DELETE /funnels/{id} — hard-delete a funnel and all its step posts
    register_rest_route('landing-auto/v1', '/funnels/(?P<id>\d+)', [
        'methods'  => 'DELETE',
        'callback' => function ($req) {
            $id = (int) $req['id'];
            $post = get_post($id);
            if (!$post || $post->post_type !== 'wpfunnel') {
                return new WP_Error('not_found', 'Funnel not found', ['status' => 404]);
            }
            $steps = get_posts([
                'post_type'   => 'wpfunnel_steps',
                'post_parent' => $id,
                'numberposts' => -1,
                'post_status' => 'any',
            ]);
            $deleted_steps = [];
            foreach ($steps as $s) { wp_delete_post($s->ID, true); $deleted_steps[] = $s->ID; }
            wp_delete_post($id, true);
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
        'post_type'   => 'wpfunnel',
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
