<?php
/**
 * Plugin Name: Landing Auto Bridge
 * Description: REST endpoint that receives a WP Funnels JSON export from the Landing Page Auto tool and imports it as a new funnel. Uses the WordPress Application Password sent in the Authorization header for auth (admin caps required).
 * Version: 1.0.2
 * Author: Tech Operation
 *
 * INSTALL
 *   Upload this file to: wp-content/mu-plugins/landing-auto-bridge.php
 *   (create the mu-plugins folder if it doesn't exist — no activation needed)
 *
 *   Or as a regular plugin:
 *     wp-content/plugins/landing-auto-bridge/landing-auto-bridge.php
 *     then activate it from Plugins screen.
 */

if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
    register_rest_route('landing-auto/v1', '/ping', [
        'methods'  => 'GET',
        'callback' => function () {
            return [
                'ok'        => true,
                'plugin'    => 'Landing Auto Bridge',
                'version'   => '1.0.2',
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
        // Don't write our internal hint as a meta on the funnel post.
        unset($funnel['funnel_meta']['_linked_product_id']);
    }

    // ── 1. Create the funnel post ────────────────────────────────────────
    $funnel_id = wp_insert_post([
        'post_title'  => $name,
        'post_status' => 'publish',
        'post_type'   => 'wpfunnel',
    ], true);
    if (is_wp_error($funnel_id)) return $funnel_id;

    // Top-level funnel meta (carry over what's in the export, skip steps_data)
    if (!empty($funnel['meta']) && is_array($funnel['meta'])) {
        foreach ($funnel['meta'] as $mk => $mv) {
            update_post_meta($funnel_id, $mk, maybe_unserialize_safe($mv));
        }
    }

    // ── 2. Create each step ──────────────────────────────────────────────
    $created_steps = [];
    $step_ids      = [];

    foreach ($funnel['steps_data'] as $idx => $step) {
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
        $created_steps[] = ['id' => $step_id, 'title' => $step_title, 'slug' => $step_slug];

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

    // ── 3. Persist the step ID list on the funnel ────────────────────────
    update_post_meta($funnel_id, '_steps_order', wp_list_pluck($created_steps, 'id'));
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
