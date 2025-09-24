<?php
/**
 * Enhanced Admin Order Plugin - Payment Processing (Real)
 * Lean metabox for processing payments from the admin editor
 *
 * @package EnhancedAdminOrder
 * @since 4.7.26
 * @version 4.7.35 - Refund summary on load, accurate remaining, validation, UI cleanup
 * @author Amnon Manneberg
 */

if ( ! defined( 'WPINC' ) ) { die; }

// AJAX endpoints
add_action('wp_ajax_eao_payment_stripe_save_settings', 'eao_payment_stripe_save_settings');
add_action('wp_ajax_eao_payment_stripe_process', 'eao_payment_stripe_process');
// Back-compat route used by earlier integration
add_action('wp_ajax_eao_stripe_process_payment', 'eao_payment_stripe_process');
// Create PaymentIntent and return client_secret
add_action('wp_ajax_eao_payment_stripe_create_intent', 'eao_payment_stripe_create_intent');
// Record result note
add_action('wp_ajax_eao_payment_record_result', 'eao_payment_record_result');
// Refunds
add_action('wp_ajax_eao_payment_get_refund_data', 'eao_payment_get_refund_data');
add_action('wp_ajax_eao_payment_process_refund', 'eao_payment_process_refund');
add_action('wp_ajax_eao_payment_store_summary_snapshot', 'eao_payment_store_summary_snapshot');

/**
 * Add Payment Processing metabox (real)
 */
function eao_add_payment_processing_metabox() {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if ($screen && ($screen->id === 'shop_order' || $screen->id === 'woocommerce_page_wc-orders')) {
        add_meta_box(
            'eao-payment-processing-metabox',
            __('Payment Processing', 'enhanced-admin-order'),
            'eao_render_payment_processing_metabox',
            null,
            'normal',
            'high'
        );
    }
}

/**
 * Render Payment Processing metabox
 */
function eao_render_payment_processing_metabox($post_or_order, $meta_box_args = array()) {
    $order_id = isset($post_or_order->ID) ? (int)$post_or_order->ID : 0;
    if (!$order_id && isset($_GET['order_id'])) { $order_id = (int) $_GET['order_id']; }
    $order = $order_id ? wc_get_order($order_id) : null;
    if (!$order) { echo '<p>Unable to load order.</p>'; return; }

    $amount = 0;
    try { $amount = (float)$order->get_total(); } catch(Exception $e) {}
    $first_name = method_exists($order,'get_billing_first_name') ? (string)$order->get_billing_first_name() : '';
    $last_name  = method_exists($order,'get_billing_last_name') ? (string)$order->get_billing_last_name() : '';

    $opts = get_option('eao_stripe_settings', array(
        'test_secret' => '', 'test_publishable' => '', 'live_secret' => '', 'live_publishable' => ''
    ));
    ?>
    <div id="eao-payment-processing-container">
        <div style="display:flex; gap:16px; align-items:flex-start;">
        <div style="flex:1 1 50%; min-width:420px;">
        <table class="form-table">
            <tr>
                <th><label for="eao-pp-gateway">Payment Gateway</label></th>
                <td>
                    <select id="eao-pp-gateway">
                        <option value="stripe_test">Stripe (Test)</option>
                        <option value="stripe_live" selected>Stripe (Live)</option>
                        <option value="paypal">PayPal</option>
                        <option value="authorize">Authorize.net</option>
                    </select>
                    <button type="button" id="eao-pp-gateway-settings" class="button" style="margin-left:6px;">
                        <span class="dashicons dashicons-admin-generic"></span>
                    </button>
                </td>
            </tr>
            <tr>
                <th><label for="eao-pp-amount">Payment Amount</label></th>
                <td>
                    <input type="number" id="eao-pp-amount" step="0.01" min="0.01" value="<?php echo esc_attr(number_format((float)$amount,2,'.','')); ?>" />
                    <button type="button" id="eao-pp-copy-gt" class="button" style="margin-left:6px;">Copy grand total</button>
                </td>
            </tr>
        </table>

        <div id="eao-pp-card-form" style="margin-top:6px;">
            <h4>Credit Card</h4>
            <table class="form-table">
                <tr>
                    <th><label for="eao-pp-first-name">First Name</label></th>
                    <td>
                        <input type="text" id="eao-pp-first-name" value="<?php echo esc_attr($first_name); ?>" />
                    </td>
                </tr>
                <tr>
                    <th><label for="eao-pp-last-name">Last Name</label></th>
                    <td>
                        <input type="text" id="eao-pp-last-name" value="<?php echo esc_attr($last_name); ?>" />
                    </td>
                </tr>
                <tr id="eao-pp-row-card-number">
                    <th><label for="eao-pp-card-number">Card Number</label></th>
                    <td>
                        <input type="text" id="eao-pp-card-number" maxlength="19" placeholder="0000 0000 0000 0000" value="4242 4242 4242 4242" />
                    </td>
                </tr>
                <tr id="eao-pp-row-expiry">
                    <th><label for="eao-pp-expiry">Expiry</label></th>
                    <td>
                        <?php $yy = substr((string) ((int) date('Y') + 2), -2); ?>
                        <input type="text" id="eao-pp-expiry" maxlength="5" placeholder="MM/YY" value="12/<?php echo esc_attr($yy); ?>" />
                    </td>
                </tr>
                <tr id="eao-pp-row-cvv">
                    <th><label for="eao-pp-cvv">CVV</label></th>
                    <td>
                        <input type="text" id="eao-pp-cvv" maxlength="4" placeholder="XXX" value="123" />
                    </td>
                </tr>
                <tr id="eao-pp-card-element-row" style="display:none;">
                    <th><label>Credit Card</label></th>
                    <td>
                        <div id="eao-pp-card-element" style="padding:10px;border:1px solid #ccd0d4;border-radius:4px;background:#fff;"></div>
                        <div id="eao-pp-card-errors" role="alert" style="color:#cc0000;margin-top:6px;"></div>
                    </td>
                </tr>
            </table>
        </div>

        <p>
            <button type="button" id="eao-pp-process" class="button button-primary">Process Payment</button>
            <label style="margin-left:8px;display:inline-flex;align-items:center;gap:6px;">
                <input type="checkbox" id="eao-pp-change-status" checked />
                <span>Change Order Status to Processing</span>
            </label>
        </p>

        <div id="eao-pp-messages"></div>

        <div id="eao-pp-settings-panel" style="display:none; margin-top:12px;">
            <h4>Stripe Settings</h4>
            <table class="form-table">
                <tr>
                    <th>Test Secret</th>
                    <td><input type="text" id="eao-pp-stripe-test-secret" value="<?php echo esc_attr($opts['test_secret'] ?? ''); ?>" style="width:420px;" /></td>
                </tr>
                <tr>
                    <th>Test Publishable</th>
                    <td><input type="text" id="eao-pp-stripe-test-publishable" value="<?php echo esc_attr($opts['test_publishable'] ?? ''); ?>" style="width:420px;" /></td>
                </tr>
                <tr>
                    <th>Live Secret</th>
                    <td><input type="text" id="eao-pp-stripe-live-secret" value="<?php echo esc_attr($opts['live_secret'] ?? ''); ?>" style="width:420px;" /></td>
                </tr>
                <tr>
                    <th>Live Publishable</th>
                    <td><input type="text" id="eao-pp-stripe-live-publishable" value="<?php echo esc_attr($opts['live_publishable'] ?? ''); ?>" style="width:420px;" /></td>
                </tr>
            </table>
            <p><button type="button" id="eao-pp-save-stripe" class="button">Save Stripe Keys</button></p>
        </div>

        <?php wp_nonce_field('eao_payment_mockup', 'eao_payment_mockup_nonce'); ?>
        <input type="hidden" id="eao-pp-order-id" value="<?php echo esc_attr($order_id); ?>" />
        </div>
        <div style="flex:1 1 50%; min-width:420px;">
            <h4>Refunds</h4>
            <div id="eao-refunds-existing-top" style="margin-bottom:8px;"></div>
            <div id="eao-refunds-initial">
                <button type="button" id="eao-pp-refunds-open" class="button">Process refunds</button>
            </div>
            <div id="eao-refunds-panel" style="display:none;">
                <div id="eao-refunds-existing" style="display:none;"></div>
                <p><label>Refund reason: <input type="text" id="eao-refund-reason" style="width:360px" placeholder="Optional reason (saved in order note)" /></label></p>
                <table class="widefat fixed striped" id="eao-refunds-table" style="margin-top:8px;">
                    <thead>
                        <tr>
                            <th style="width:40%">Product</th>
                            <th style="width:8%">Qty</th>
                            <th style="width:12%">Points</th>
                            <th style="width:12%">Paid ($)</th>
                            <th style="width:8%">Full</th>
                            <th style="width:10%">$ to refund</th>
                            <th style="width:10%">Pts to refund</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                    <tfoot>
                        <tr>
                            <th colspan="5" style="text-align:right;">Totals:</th>
                            <th><input type="number" id="eao-refund-total-money" step="0.01" value="0.00" readonly style="width:70px" /></th>
                            <th><input type="number" id="eao-refund-total-points" step="1" value="0" readonly style="width:60px" /></th>
                        </tr>
                    </tfoot>
                </table>
                <p><button type="button" id="eao-pp-refund-process" class="button button-secondary">Process refund</button></p>
                <div id="eao-refunds-messages"></div>
            </div>
        </div>
        </div>
    </div>
    <?php
}

/** Save Stripe keys */
function eao_payment_stripe_save_settings() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'eao_payment_mockup')) {
        wp_send_json_error(array('message' => 'Nonce failed'));
    }
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Permission denied'));
    }
    $opts = (array) get_option('eao_stripe_settings', array());
    $opts['test_secret'] = sanitize_text_field($_POST['test_secret'] ?? ($opts['test_secret'] ?? ''));
    $opts['test_publishable'] = sanitize_text_field($_POST['test_publishable'] ?? ($opts['test_publishable'] ?? ''));
    $opts['live_secret'] = sanitize_text_field($_POST['live_secret'] ?? ($opts['live_secret'] ?? ''));
    $opts['live_publishable'] = sanitize_text_field($_POST['live_publishable'] ?? ($opts['live_publishable'] ?? ''));
    update_option('eao_stripe_settings', $opts);
    wp_send_json_success(array('message' => 'Saved', 'data' => $opts));
}

/** Real Stripe processing (test-mode) */
function eao_payment_stripe_process() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'eao_payment_mockup')) {
        wp_send_json_error(array('message' => 'Nonce verification failed.'));
    }
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Permission denied.'));
    }

    $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    $amount   = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
    $gw       = isset($_POST['gateway']) ? sanitize_text_field($_POST['gateway']) : 'stripe_test';
    if (!$order_id || $amount <= 0) {
        wp_send_json_error(array('message' => 'Invalid order or amount'));
    }

    $opts = get_option('eao_stripe_settings', array());
    $secret = ($gw === 'stripe_live') ? ($opts['live_secret'] ?? '') : ($opts['test_secret'] ?? '');
    if (empty($secret)) {
        wp_send_json_error(array('message' => 'Stripe secret key is missing for ' . (($gw === 'stripe_live') ? 'Live' : 'Test') ));
    }

    $amount_cents = (int) round($amount * 100);
    $headers = array(
        'Authorization' => 'Bearer ' . $secret,
        'Content-Type'  => 'application/x-www-form-urlencoded'
    );

    // Create PaymentIntent
    $body = array(
        'amount' => $amount_cents,
        'currency' => get_woocommerce_currency('USD') ?: 'usd',
        'payment_method_types[]' => 'card',
        'metadata[order_id]' => $order_id,
        'description' => 'HolisticPeople.com - ' . (
            (method_exists($order,'get_formatted_billing_full_name') && $order->get_formatted_billing_full_name())
                ? $order->get_formatted_billing_full_name()
                : trim((method_exists($order,'get_billing_first_name')?$order->get_billing_first_name():'') . ' ' . (method_exists($order,'get_billing_last_name')?$order->get_billing_last_name():''))
        ) . ' - Order# ' . $order_id
    );
    $resp = wp_remote_post('https://api.stripe.com/v1/payment_intents', array(
        'headers' => $headers,
        'body' => $body,
        'timeout' => 25
    ));
    if (is_wp_error($resp)) {
        wp_send_json_error(array('message' => 'Stripe error: ' . $resp->get_error_message()));
    }
    $pi = json_decode(wp_remote_retrieve_body($resp), true);
    if (empty($pi['id'])) {
        wp_send_json_error(array('message' => 'Stripe: could not create PaymentIntent', 'stripe' => $pi));
    }

    // Create test PaymentMethod (server-side for simplicity)
    $pm_resp = wp_remote_post('https://api.stripe.com/v1/payment_methods', array(
        'headers' => $headers,
        'body' => array(
            'type' => 'card',
            'card[number]' => '4242424242424242',
            'card[exp_month]' => '12',
            'card[exp_year]' => date('Y') + 2,
            'card[cvc]' => '123'
        )
    ));
    if (is_wp_error($pm_resp)) {
        wp_send_json_error(array('message' => 'Stripe PM error: ' . $pm_resp->get_error_message()));
    }
    $pm = json_decode(wp_remote_retrieve_body($pm_resp), true);
    if (empty($pm['id'])) {
        wp_send_json_error(array('message' => 'Stripe: could not create PaymentMethod', 'stripe' => $pm));
    }

    // Confirm
    $confirm_resp = wp_remote_post('https://api.stripe.com/v1/payment_intents/' . rawurlencode($pi['id']) . '/confirm', array(
        'headers' => $headers,
        'body' => array('payment_method' => $pm['id'])
    ));
    if (is_wp_error($confirm_resp)) {
        wp_send_json_error(array('message' => 'Stripe confirm error: ' . $confirm_resp->get_error_message()));
    }
    $confirmed = json_decode(wp_remote_retrieve_body($confirm_resp), true);
    wp_send_json_success(array('message' => 'Stripe test payment processed', 'payment_intent' => $confirmed));
}

/** Create PI and return client_secret for Stripe.js confirmation */
function eao_payment_stripe_create_intent() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'eao_payment_mockup')) {
        wp_send_json_error(array('message' => 'Nonce verification failed.'));
    }
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Permission denied.'));
    }

    $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    $amount   = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
    $gw       = isset($_POST['gateway']) ? sanitize_text_field($_POST['gateway']) : 'stripe_test';
    if (!$order_id || $amount <= 0) { wp_send_json_error(array('message' => 'Invalid order/amount')); }

    $opts = get_option('eao_stripe_settings', array());
    $secret = ($gw === 'stripe_live') ? ($opts['live_secret'] ?? '') : ($opts['test_secret'] ?? '');
    $publishable = ($gw === 'stripe_live') ? ($opts['live_publishable'] ?? '') : ($opts['test_publishable'] ?? '');
    if (empty($secret) || empty($publishable)) { wp_send_json_error(array('message' => 'Stripe keys missing')); }

    $amount_cents = (int) round($amount * 100);
    $headers = array('Authorization' => 'Bearer ' . $secret, 'Content-Type' => 'application/x-www-form-urlencoded');
    $resp = wp_remote_post('https://api.stripe.com/v1/payment_intents', array(
        'headers' => $headers,
        'body' => array(
            'amount' => $amount_cents,
            'currency' => get_woocommerce_currency('USD') ?: 'usd',
            'metadata[order_id]' => $order_id,
            'description' => 'HolisticPeople.com - ' . (function($oid){ $o=wc_get_order($oid); if(!$o){return '';} if(method_exists($o,'get_formatted_billing_full_name') && $o->get_formatted_billing_full_name()){return $o->get_formatted_billing_full_name();} return trim((method_exists($o,'get_billing_first_name')?$o->get_billing_first_name():'').' '.(method_exists($o,'get_billing_last_name')?$o->get_billing_last_name():'')); })($order_id) . ' - Order# ' . $order_id
        ),
        'timeout' => 25
    ));
    if (is_wp_error($resp)) { wp_send_json_error(array('message' => $resp->get_error_message())); }
    $pi = json_decode(wp_remote_retrieve_body($resp), true);
    if (empty($pi['client_secret'])) { wp_send_json_error(array('message' => 'Stripe: cannot create PI', 'stripe' => $pi)); }
    wp_send_json_success(array('client_secret' => $pi['client_secret'], 'publishable' => $publishable, 'amount_cents' => $amount_cents));
}

/** Add order note for success/failure */
function eao_payment_record_result() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'eao_payment_mockup')) {
        wp_send_json_error(array('message' => 'Nonce verification failed.'));
    }
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Permission denied.'));
    }
    $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    $order = $order_id ? wc_get_order($order_id) : null;
    if (!$order) { wp_send_json_error(array('message' => 'Order not found')); }
    $gateway = sanitize_text_field($_POST['gateway'] ?? 'stripe_test');
    $success = filter_var($_POST['success'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $details = isset($_POST['details']) ? wp_kses_post(stripslashes_deep($_POST['details'])) : '';
    $amount_hint = isset($_POST['amount_hint']) ? floatval($_POST['amount_hint']) : null;
    $label = ($gateway === 'stripe_live') ? 'Stripe Live' : 'Stripe Test';
    // Parse details first to reliably extract the amount/ids
    $summary_extra = '';
    $pi_id = '';
    $charge_id = '';
    $amount_cents = null;
    $currency = '';
    if (!empty($details)) {
        $decoded = json_decode($details, true);
        if (is_array($decoded)) {
            if (!empty($decoded['id'])) { $pi_id = $decoded['id']; }
            if (!empty($decoded['amount'])) { $amount_cents = (int) $decoded['amount']; }
            if (!empty($decoded['currency'])) { $currency = strtoupper($decoded['currency']); }
            if (!empty($decoded['charges']['data'][0]['id'])) { $charge_id = $decoded['charges']['data'][0]['id']; }
            // Try to extract last4 from the Stripe response
            $last4 = '';
            if (!empty($decoded['charges']['data'][0]['payment_method_details']['card']['last4'])) {
                $last4 = (string) $decoded['charges']['data'][0]['payment_method_details']['card']['last4'];
            } elseif (!empty($decoded['charges']['data'][0]['payment_method_details']['card_present']['last4'])) {
                $last4 = (string) $decoded['charges']['data'][0]['payment_method_details']['card_present']['last4'];
            } elseif ($label === 'Stripe Test') {
                // Safe fallback for our test token flow
                $last4 = '4242';
            }
            // Fallbacks when embedded charges are not present
            if (!$charge_id && !empty($decoded['latest_charge'])) { $charge_id = $decoded['latest_charge']; }
            if (!$charge_id && !empty($decoded['charges']) && is_array($decoded['charges']) && !empty($decoded['charges']['data'])) {
                foreach ($decoded['charges']['data'] as $ch) {
                    if (!empty($ch['id'])) { $charge_id = $ch['id']; break; }
                }
            }
            if (!$success && !empty($decoded['message'])) { $summary_extra = ' | Error: ' . sanitize_text_field($decoded['message']); }
        } else if (!$success) {
            $summary_extra = ' | Error: ' . wp_strip_all_tags($details);
        }
    }
    // Build the note text after parsing details
    if ($success) {
        if (!empty($amount_cents)) {
            $note = 'Payment of $' . number_format(((float)$amount_cents)/100.0, 2) . ' was processed successfully through ' . $label . '.';
        } elseif ($amount_hint !== null && $amount_hint > 0) {
            $note = 'Payment of $' . number_format($amount_hint, 2) . ' was processed successfully through ' . $label . '.';
        } else {
            $note = 'Payment was processed successfully through ' . $label . '.';
        }
    } else {
        $note = 'Payment attempt failed through ' . $label . '.';
        if ($summary_extra) { $note .= $summary_extra; }
    }
    // If amount could not be parsed from PI, try fallback from client data attribute posted later (ignored if not present)

    // Persist Stripe identifiers for future refunds (platform awareness)
    if ($success) {
        if ($pi_id) { $order->update_meta_data('_eao_stripe_payment_intent_id', $pi_id); }
        if ($charge_id) { $order->update_meta_data('_eao_stripe_charge_id', $charge_id); }
        $order->update_meta_data('_eao_stripe_payment_mode', ($gateway === 'stripe_live') ? 'live' : 'test');
        // Persist last charged amount and currency for proportional refunds
        if (!empty($amount_cents)) {
            $order->update_meta_data('_eao_last_charged_amount_cents', (int) $amount_cents);
            if (!empty($currency)) { $order->update_meta_data('_eao_last_charged_currency', $currency); }
        } elseif ($amount_hint !== null && $amount_hint > 0) {
            $order->update_meta_data('_eao_last_charged_amount_cents', (int) round($amount_hint * 100));
        }
        // Record native WooCommerce payment method so it shows in admin lists
        $pm_id = ($gateway === 'stripe_live') ? 'eao_stripe_live' : 'eao_stripe_test';
        $pm_title = $label;
        if (!empty($last4)) { $pm_title .= ' •••• ' . $last4; }
        if (method_exists($order, 'set_payment_method')) { $order->set_payment_method($pm_id); }
        if (method_exists($order, 'set_payment_method_title')) { $order->set_payment_method_title($pm_title); }
        // Also update legacy metas for safety
        $order->update_meta_data('_payment_method', $pm_id);
        $order->update_meta_data('_payment_method_title', $pm_title);
        $order->save();
    }

    $order->add_order_note($note, false, true);
    wp_send_json_success(array('message' => 'Note added'));
}

/**
 * Get refund data for an order (items + existing refunds)
 */
function eao_payment_get_refund_data() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'eao_payment_mockup')) {
        wp_send_json_error(array('message' => 'Nonce verification failed.'));
    }
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Permission denied.'));
    }
    $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    $order = $order_id ? wc_get_order($order_id) : null;
    if (!$order) { wp_send_json_error(array('message' => 'Order not found')); }

    $items = array();
    // Build proportion bases per line (fallback to subtotal when total is zero)
    $order_items = $order->get_items('line_item');
    $products_total = 0.0;
    $per_item_base = array();
    foreach ($order_items as $iid => $it) {
        $base = (float) $it->get_total() + (float) $it->get_total_tax();
        if ($base <= 0.0001) { $base = (float) $it->get_subtotal() + (float) $it->get_subtotal_tax(); }
        $per_item_base[$iid] = $base;
        $products_total += $base;
    }
    // Charged amount and remaining after previous refunds (separate products vs shipping)
    $charged_cents_meta = (int) $order->get_meta('_eao_last_charged_amount_cents');
    $charged_cents = $charged_cents_meta > 0 ? $charged_cents_meta : (int) round(((float) $order->get_total()) * 100);
    $charged_total = $charged_cents / 100.0;
    $shipping_paid_total = (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();
    $already_refunded_total = 0.0;
    foreach ($order->get_refunds() as $r) { $already_refunded_total += (float) $r->get_amount(); }
    $charged_remaining_total = max(0.0, $charged_total - $already_refunded_total);

    // First pass: compute charge-share per line and subtract line refunds
    $line_share = array();
    $line_remaining_share = array();
    $sum_remaining = 0.0;
    $products_refunded_total = 0.0;
    foreach ($order_items as $item_id => $item) {
        $wc_base = isset($per_item_base[$item_id]) ? (float) $per_item_base[$item_id] : 0.0;
        // Distribute only the non-shipping part of the charge to products
        $product_charged_total = max(0.0, $charged_total - $shipping_paid_total);
        $share = ($products_total > 0.0) ? ($product_charged_total * ($wc_base / $products_total)) : 0.0;
        $refunded_item = method_exists($order, 'get_total_refunded_for_item') ? (float) $order->get_total_refunded_for_item($item_id) : 0.0;
        $refunded_tax  = method_exists($order, 'get_total_tax_refunded_for_item') ? (float) $order->get_total_tax_refunded_for_item($item_id) : 0.0;
        $refunded_line = $refunded_item + $refunded_tax;
        $remaining = max(0.0, $share - $refunded_line);
        $products_refunded_total += $refunded_line;
        $line_share[$item_id] = array('share' => $share, 'refunded' => $refunded_line);
        $line_remaining_share[$item_id] = $remaining;
        $sum_remaining += $remaining;
    }

    // Scale remaining so the sum never exceeds the product portion of the remaining charge
    $scaled_remaining_cents = array();
    $product_charged_total = max(0.0, $charged_total - $shipping_paid_total);
    $product_remaining_total = max(0.0, $product_charged_total - $products_refunded_total);
    $target_cents = (int) round($product_remaining_total * 100);
    if ($sum_remaining <= 0.0001) {
        foreach ($order_items as $item_id => $item) { $scaled_remaining_cents[$item_id] = 0; }
    } else {
        $factor = min(1.0, ($product_remaining_total > 0 ? ($product_remaining_total / $sum_remaining) : 0));
        $acc = 0; $i = 0; $last = count($order_items) - 1;
        foreach ($order_items as $item_id => $item) {
            $rem = $line_remaining_share[$item_id] * $factor;
            $cents = ($i === $last) ? max(0, $target_cents - $acc) : (int) round($rem * 100);
            $scaled_remaining_cents[$item_id] = $cents; $acc += $cents; $i++;
        }
    }

    // Points redeemed on order (total), distribute initial per item and subtract previously refunded per line
    $points_redeemed_total = (int) $order->get_meta('_ywpar_coupon_points', true);
    $per_item_points_initial = array();
    $acc_pts = 0; $i_pts = 0; $last_pts = max(0, count($order_items) - 1);
    foreach ($order_items as $iid => $it_tmp) {
        $base = isset($per_item_base[$iid]) ? (float) $per_item_base[$iid] : 0.0;
        $portion = ($products_total > 0.0) ? ($base / $products_total) : 0.0;
        $alloc = ($i_pts === $last_pts) ? max(0, $points_redeemed_total - $acc_pts) : (int) round($points_redeemed_total * $portion);
        $per_item_points_initial[$iid] = $alloc; $acc_pts += $alloc; $i_pts++;
    }
    // Sum per-line points already refunded using stored maps per refund
    $per_item_points_refunded = array();
    foreach ($order->get_refunds() as $r) {
        $map_json = (string) get_post_meta($r->get_id(), '_eao_points_refunded_map', true);
        if ($map_json) {
            $map = json_decode($map_json, true);
            if (is_array($map)) {
                foreach ($map as $iid => $pts) {
                    $iid = absint($iid);
                    $per_item_points_refunded[$iid] = ($per_item_points_refunded[$iid] ?? 0) + (int) $pts;
                }
            }
        }
    }
    $per_item_points_remaining = array();
    foreach ($order_items as $iid => $it_tmp) {
        $initial = (int) ($per_item_points_initial[$iid] ?? 0);
        $refunded = (int) ($per_item_points_refunded[$iid] ?? 0);
        $per_item_points_remaining[$iid] = max(0, $initial - $refunded);
    }

    // Build response rows: paid shows full share; if remaining is less, UI will strike-through
    foreach ($order_items as $item_id => $item) {
        $product = $item->get_product();
        $sku = $product ? $product->get_sku() : '';
        $image = $product ? wp_get_attachment_image_url($product->get_image_id(), 'thumbnail') : '';
        $qty = (int) $item->get_quantity();
        // Points: show remaining and also provide initial for UI strike-through
        $points_initial = (int) ($per_item_points_initial[$item_id] ?? 0);
        $points_remaining = (int) ($per_item_points_remaining[$item_id] ?? 0);
        $share = isset($line_share[$item_id]) ? $line_share[$item_id]['share'] : 0.0;
        $remaining_scaled = isset($scaled_remaining_cents[$item_id]) ? ($scaled_remaining_cents[$item_id] / 100.0) : 0.0;
        $items[] = array(
            'item_id' => $item_id,
            'name' => $item->get_name(),
            'sku' => $sku,
            'image' => $image,
            'qty' => $qty,
            'points_initial' => $points_initial,
            'points' => $points_remaining,
            'paid' => wc_format_decimal($share, 2),
            'remaining' => wc_format_decimal($remaining_scaled, 2)
        );
    }

    // Add shipping row(s)
    $shipping_items = $order->get_items('shipping');
    if (!empty($shipping_items)) {
        foreach ($shipping_items as $sh_id => $sh_item) {
            $sh_paid = (float) $sh_item->get_total() + (float) $sh_item->get_total_tax();
            $sh_refunded = 0.0;
            if (method_exists($order, 'get_total_refunded_for_item')) { $sh_refunded += (float) $order->get_total_refunded_for_item($sh_id, 'shipping'); }
            if (method_exists($order, 'get_total_tax_refunded_for_item')) { $sh_refunded += (float) $order->get_total_tax_refunded_for_item($sh_id, 'shipping'); }
            $sh_remaining = max(0.0, $sh_paid - $sh_refunded);
            $items[] = array(
                'item_id' => $sh_id,
                'name' => 'Shipping: ' . $sh_item->get_name(),
                'sku' => '',
                'image' => '',
                'qty' => '',
                'points_initial' => 0,
                'points' => 0,
                'paid' => wc_format_decimal($sh_paid, 2),
                'remaining' => wc_format_decimal($sh_remaining, 2)
            );
        }
    }


    $existing = array();
    foreach ($order->get_refunds() as $refund) {
        $existing[] = array(
            'id' => $refund->get_id(),
            'amount' => wc_format_decimal($refund->get_amount(), 2),
            'reason' => $refund->get_reason(),
            'date' => $refund->get_date_created() ? $refund->get_date_created()->date_i18n('Y-m-d H:i') : '',
            'points' => (int) get_post_meta($refund->get_id(), '_eao_points_refunded', true)
        );
    }

    wp_send_json_success(array('items' => $items, 'refunds' => $existing));
}

/**
 * Process refund using WooCommerce APIs (records official wc_refund)
 */
function eao_payment_process_refund() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'eao_payment_mockup')) {
        wp_send_json_error(array('message' => 'Nonce verification failed.'));
    }
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Permission denied.'));
    }
    $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    $order = $order_id ? wc_get_order($order_id) : null;
    if (!$order) { wp_send_json_error(array('message' => 'Order not found')); }

    $lines_json = isset($_POST['lines']) ? wp_unslash($_POST['lines']) : '[]';
    $lines = json_decode($lines_json, true);
    if (!is_array($lines)) { $lines = array(); }
    $user_reason = isset($_POST['reason']) ? sanitize_text_field(wp_unslash($_POST['reason'])) : '';

    $line_items = array();
    $amount_total = 0.0;
    $points_total = 0;
    $points_map = array();
    foreach ($lines as $row) {
        $item_id = isset($row['item_id']) ? absint($row['item_id']) : 0;
        $money = isset($row['money']) ? floatval($row['money']) : 0;
        $points = isset($row['points']) ? intval($row['points']) : 0;
        if ($item_id && $money > 0) {
            $line_items[$item_id] = array('qty' => 0, 'refund_total' => wc_format_decimal($money, 2));
            $amount_total += $money;
        }
        if ($points > 0) { $points_total += $points; $points_map[$item_id] = $points; }
    }
    if ($amount_total <= 0 && $points_total <= 0) {
        wp_send_json_error(array('message' => 'Nothing to refund'));
    }

    $reason = 'Refund via Enhanced Admin Order - HolisticPeople.com - Order# ' . $order_id;
    if ($points_total > 0) { $reason .= ' | Points to refund: ' . $points_total; }
    if (!empty($user_reason)) { $reason .= ' | Reason: ' . $user_reason; }

    // If there is a Stripe charge recorded and a money amount to refund, send refund to Stripe
    $stripe_refund_id = '';
    if ($amount_total > 0) {
        $mode = (string) $order->get_meta('_eao_stripe_payment_mode');
        $charge_id = (string) $order->get_meta('_eao_stripe_charge_id');
        $opts = get_option('eao_stripe_settings', array());
        $secret = ($mode === 'live') ? ($opts['live_secret'] ?? '') : ($opts['test_secret'] ?? '');
        // If missing, try to retrieve from PaymentIntent
        if (empty($charge_id)) {
            $pi_id = (string) $order->get_meta('_eao_stripe_payment_intent_id');
            if (!empty($secret) && !empty($pi_id)) {
                $headers = array('Authorization' => 'Bearer ' . $secret);
                $pi_resp = wp_remote_get('https://api.stripe.com/v1/payment_intents/' . rawurlencode($pi_id), array('headers' => $headers));
                if (!is_wp_error($pi_resp)) {
                    $pi_body = json_decode(wp_remote_retrieve_body($pi_resp), true);
                    if (!empty($pi_body['latest_charge'])) {
                        $charge_id = $pi_body['latest_charge'];
                        $order->update_meta_data('_eao_stripe_charge_id', $charge_id);
                        $order->save();
                    } elseif (!empty($pi_body['charges']['data'][0]['id'])) {
                        $charge_id = $pi_body['charges']['data'][0]['id'];
                        $order->update_meta_data('_eao_stripe_charge_id', $charge_id);
                        $order->save();
                    }
                }
            }
        }
        if (!empty($secret) && !empty($charge_id)) {
            $headers = array('Authorization' => 'Bearer ' . $secret, 'Content-Type' => 'application/x-www-form-urlencoded');
            $rf = wp_remote_post('https://api.stripe.com/v1/refunds', array(
                'headers' => $headers,
                'body' => array(
                    'charge' => $charge_id,
                    'amount' => (int) round($amount_total * 100),
                    'reason' => 'requested_by_customer',
                    'metadata[order_id]' => $order_id
                )
            ));
            if (is_wp_error($rf)) {
                wp_send_json_error(array('message' => 'Stripe refund error: ' . $rf->get_error_message()));
            }
            $rf_body = json_decode(wp_remote_retrieve_body($rf), true);
            if (empty($rf_body['id'])) {
                wp_send_json_error(array('message' => 'Stripe refund failed', 'stripe' => $rf_body));
            }
            $stripe_refund_id = $rf_body['id'];
            $reason .= ' | Stripe refund ' . $stripe_refund_id . (($mode==='live')?' (Live)':' (Test)');
        } else if ($amount_total > 0) {
            // Explain why Stripe refund could not be sent
            if (empty($charge_id)) {
                return wp_send_json_error(array('message' => 'Stripe charge reference not found on this order. Payment may have been processed outside Stripe integration.'));
            }
            if (empty($secret)) {
                return wp_send_json_error(array('message' => 'Stripe API key missing for ' . (($mode==='live')?'Live':'Test') . ' mode.')); 
            }
        }
    }

    $refund = wc_create_refund(array(
        'amount' => wc_format_decimal($amount_total, 2),
        'reason' => $reason,
        'order_id' => $order_id,
        'line_items' => $line_items,
        'refund_payment' => false,
        'restock_items' => false
    ));
    if (is_wp_error($refund)) {
        wp_send_json_error(array('message' => $refund->get_error_message()));
    }

    // Process YITH points refund if requested
    if ($points_total > 0) {
        // Credit back redeemed points to the customer (add points)
        if (function_exists('ywpar_increase_points')) {
            ywpar_increase_points($order->get_customer_id(), $points_total, sprintf(__('Redeemed points returned for Order #%d', 'enhanced-admin-order'), $order_id), $order_id);
        } elseif (function_exists('ywpar_get_customer')) {
            $cust = ywpar_get_customer($order->get_customer_id());
            if ($cust && method_exists($cust, 'update_points')) {
                $cust->update_points($points_total, 'order_points_return', array('order_id' => $order_id, 'description' => 'Redeemed points returned'));
            }
        }
        // Store how many points were refunded with this refund record
        update_post_meta($refund->get_id(), '_eao_points_refunded', (int) $points_total);
        if (!empty($points_map)) { update_post_meta($refund->get_id(), '_eao_points_refunded_map', wp_json_encode($points_map)); }
    }

    $human = 'Refund of $' . wc_format_decimal($amount_total, 2) . ' was processed successfully through ' . (($mode==='live')?'Stripe Live':'Stripe Test') . '.';
    if ($points_total > 0) { $human .= ' Points refunded: ' . (int) $points_total . '.'; }
    $order->add_order_note($human);

    wp_send_json_success(array('refund_id' => $refund->get_id(), 'amount' => wc_format_decimal($amount_total, 2), 'points' => (int) $points_total));
}

