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
// Stripe invoice: send/void
add_action('wp_ajax_eao_stripe_send_payment_request', 'eao_stripe_send_payment_request');
add_action('wp_ajax_eao_stripe_void_invoice', 'eao_stripe_void_invoice');

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
    $billing_email = method_exists($order,'get_billing_email') ? (string)$order->get_billing_email() : '';

    $opts = get_option('eao_stripe_settings', array(
        'test_secret' => '', 'test_publishable' => '', 'live_secret' => '', 'live_publishable' => ''
    ));
    $gateway_summary = eao_payment_describe_order_gateway($order);
    $gateway_label_text = '';
    if (is_array($gateway_summary)) {
        if (!empty($gateway_summary['label'])) {
            $gateway_label_text = (string) $gateway_summary['label'];
        } elseif (!empty($gateway_summary['message'])) {
            $gateway_label_text = trim((string) $gateway_summary['message']);
        }
        if ($gateway_label_text === '' && !empty($gateway_summary['id'])) {
            $gateway_label_text = ucfirst(str_replace('_', ' ', (string) $gateway_summary['id']));
        }
    }
    $gateway_notice_style = ($gateway_label_text === '') ? 'display:none;' : '';

    $existing_invoice_id = (string) $order->get_meta('_eao_stripe_invoice_id', true);
    $existing_invoice_status = (string) $order->get_meta('_eao_stripe_invoice_status', true);
    $has_active_invoice = ($existing_invoice_id !== '' && strtolower($existing_invoice_status) !== 'void');

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

        <div id="eao-pp-request-panel" style="margin-top:8px;">
            <p style="margin:6px 0 8px 0;">
                <button type="button" id="eao-pp-send-request" class="button"<?php echo $has_active_invoice ? ' disabled="disabled"' : ''; ?>>Send Payment Request</button>
                <button type="button" id="eao-pp-void-invoice" class="button button-secondary" style="<?php echo $has_active_invoice ? '' : 'display:none;'; ?>">Void Payment Request</button>
            </p>
            <div id="eao-pp-request-options" style="margin:6px 0 10px 0;">
                <label>Email to send to: <input type="email" id="eao-pp-request-email" value="<?php echo esc_attr($billing_email); ?>" style="width:260px" /></label>
                <span style="margin-left:12px;">Amount source:</span>
                <label style="margin-left:6px;"><input type="radio" name="eao-pp-line-mode" value="grand" checked /> Grand total</label>
                <label style="margin-left:6px;"><input type="radio" name="eao-pp-line-mode" value="itemized" /> Itemized</label>
                <input type="hidden" id="eao-pp-invoice-id" value="<?php echo esc_attr($existing_invoice_id); ?>" />
                <input type="hidden" id="eao-pp-invoice-status" value="<?php echo esc_attr($existing_invoice_status); ?>" />
            </div>
            <div id="eao-pp-request-messages"></div>
        </div>

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
            <div id="eao-refunds-gateway-summary" class="eao-refunds-gateway-banner" style="margin:4px 0 12px;padding:10px 12px;border-left:4px solid #72aee6;background:#f0f6fc;border-radius:4px;color:#1d2327;<?php echo esc_attr($gateway_notice_style); ?>">
                <p class="eao-refunds-gateway-label" style="margin:0;font-weight:600;">Charge done by - <span id="eao-refunds-gateway-label-text" style="font-weight:600;"><?php echo esc_html($gateway_label_text); ?></span></p>
            </div>
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
        $order->update_meta_data('_eao_payment_gateway', $gateway);
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
        if (!empty($last4)) { $pm_title .= ' **** ' . $last4; }
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

    $gateway_info = eao_payment_describe_order_gateway($order);
    wp_send_json_success(array('items' => $items, 'refunds' => $existing, 'gateway' => $gateway_info));
}

/**
 * Determine if this order was charged via the Enhanced Admin Order Stripe integration.
 *
 * @param WC_Order $order
 * @return bool
 */
function eao_payment_order_has_eao_stripe_charge($order) {
    if (!($order instanceof WC_Order)) {
        return false;
    }
    $charge_id = (string) $order->get_meta('_eao_stripe_charge_id', true);
    $pi_id = (string) $order->get_meta('_eao_stripe_payment_intent_id', true);
    return ($charge_id !== '' || $pi_id !== '');
}

/**
 * Attempt to locate the WooCommerce payment gateway instance associated with an order.
 *
 * @param WC_Order $order
 * @return array{ id: string, gateway: ?WC_Payment_Gateway }
 */
function eao_payment_locate_wc_gateway_for_order($order) {
    if (!($order instanceof WC_Order) || !class_exists('WC_Payment_Gateways')) {
        return array('id' => '', 'gateway' => null);
    }

    $candidates = array();
    $meta_gateway = $order->get_meta('_eao_payment_gateway', true);
    if (!empty($meta_gateway)) {
        $candidates[] = strtolower(trim((string) $meta_gateway));
    }
    $payment_method = $order->get_payment_method();
    if (!empty($payment_method)) {
        $candidates[] = strtolower(trim((string) $payment_method));
    }
    $legacy_method = $order->get_meta('_payment_method', true);
    if (!empty($legacy_method)) {
        $candidates[] = strtolower(trim((string) $legacy_method));
    }

    $candidates = array_values(array_unique(array_filter($candidates)));

    if (empty($candidates)) {
        return array('id' => '', 'gateway' => null);
    }

    $alias_map = array(
        'stripe_live' => array('stripe', 'woocommerce_stripe', 'stripe_cc', 'woo_stripe_payment', 'wc_stripe'),
        'stripe_test' => array('stripe', 'woocommerce_stripe', 'stripe_cc', 'woo_stripe_payment', 'wc_stripe'),
        'stripe' => array('stripe', 'woocommerce_stripe', 'stripe_cc', 'woo_stripe_payment', 'wc_stripe'),
        'paypal' => array('ppcp', 'ppec_paypal', 'paypal'),
        'ppcp' => array('ppcp', 'ppec_paypal'),
        'authorize' => array('eh_authorize_net_aim_card', 'wc_authorize_net_cim_credit_card', 'wc_authorize_net_cim_echeck', 'authorize_net', 'authorizenet'),
        'authorize_net' => array('eh_authorize_net_aim_card', 'wc_authorize_net_cim_credit_card', 'authorize_net', 'authorizenet'),
    );

    $extended = $candidates;
    foreach ($candidates as $candidate) {
        if (isset($alias_map[$candidate])) {
            $extended = array_merge($extended, $alias_map[$candidate]);
        }
    }
    $candidates = array_values(array_unique(array_filter($extended)));

    $gateways = WC_Payment_Gateways::instance()->payment_gateways();
    foreach ($candidates as $candidate) {
        if (isset($gateways[$candidate])) {
            return array('id' => $candidate, 'gateway' => $gateways[$candidate]);
        }
    }

    foreach ($candidates as $candidate) {
        foreach ($gateways as $id => $gateway_obj) {
            if (false !== strpos($id, $candidate)) {
                return array('id' => $id, 'gateway' => $gateway_obj);
            }
        }
    }

    return array('id' => '', 'gateway' => null);
}
/**
 * Describe the detected payment gateway for an order.
 *
 * @param WC_Order $order
 * @return array{ id: string, label: string, source: string, mode: string, reference: string, message: string }
 */
function eao_payment_describe_order_gateway($order) {
    $result = array(
        'id' => '',
        'label' => '',
        'source' => 'unknown',
        'mode' => '',
        'reference' => '',
        'message' => ''
    );
    if (!($order instanceof WC_Order)) {
        return $result;
    }

    if (eao_payment_order_has_eao_stripe_charge($order)) {
        $mode_meta = strtolower((string) $order->get_meta('_eao_stripe_payment_mode'));
        $mode = ($mode_meta === 'test') ? 'test' : 'live';
        $charge_id = (string) $order->get_meta('_eao_stripe_charge_id', true);
        if (!$charge_id) {
            $charge_id = (string) $order->get_meta('_eao_stripe_payment_intent_id', true);
        }
        $result['id'] = 'eao_stripe_' . $mode;
        $result['source'] = 'eao_stripe';
        $result['label'] = 'Stripe (EAO ' . (($mode === 'test') ? 'Test' : 'Live') . ')';
        $result['mode'] = $mode;
        if (!empty($charge_id)) {
            $result['reference'] = $charge_id;
        }
        $reference_text = $result['reference'] ? ' Reference: ' . $result['reference'] . '.' : '';
        $result['message'] = 'Gateway for this order: ' . $result['label'] . '. Refunds will be sent through the Enhanced Admin Order Stripe API.' . $reference_text;
        return $result;
    }

    $resolution = eao_payment_locate_wc_gateway_for_order($order);
    if (!empty($resolution['id'])) {
        $result['id'] = (string) $resolution['id'];
    }
    if (!empty($resolution['gateway']) && $resolution['gateway'] instanceof WC_Payment_Gateway) {
        $result['source'] = 'woocommerce_gateway';
        $gw = $resolution['gateway'];
        $label = '';
        if (method_exists($gw, 'get_method_title')) {
            $label = trim((string) $gw->get_method_title());
        }
        if (!$label && method_exists($gw, 'get_title')) {
            $label = trim((string) $gw->get_title());
        }
        if ($label) {
            $result['label'] = $label;
        }
    }

    if (empty($result['label'])) {
        $fallback = (string) $order->get_payment_method_title();
        if (!$fallback) {
            $fallback = (string) $order->get_meta('_payment_method_title', true);
        }
        if ($fallback) {
            $result['label'] = $fallback;
        }
    }

    if (empty($result['label']) && !empty($result['id'])) {
        $result['label'] = ucfirst(str_replace('_', ' ', $result['id']));
    }
    if (empty($result['label'])) {
        $result['label'] = 'Unknown / manual payment';
    }

    $reference = (string) $order->get_transaction_id();
    if ($reference) {
        $result['reference'] = $reference;
    }

    if ($result['source'] === 'woocommerce_gateway') {
        $detail = $result['reference'] ? ' Reference: ' . $result['reference'] . '.' : '';
        $result['message'] = 'Gateway for this order: ' . $result['label'] . '. Refund requests will be sent to this WooCommerce gateway automatically.' . $detail;
    } else {
        $detail = $result['reference'] ? ' Reference: ' . $result['reference'] . '.' : '';
        $result['message'] = 'Gateway for this order: ' . $result['label'] . '. Refunds will be recorded in WooCommerce only.' . $detail;
    }

    return $result;
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

    // Snapshot current internal notes so we can remove gateway-generated duplicates later.
    $existing_note_ids = array();
    foreach (wc_get_order_notes(array('order_id' => $order_id, 'type' => 'internal', 'orderby' => 'date_created', 'order' => 'ASC')) as $note_obj) {
        if (is_object($note_obj) && method_exists($note_obj, 'get_id')) {
            $existing_note_ids[] = $note_obj->get_id();
        }
    }

    $reason = 'Refund via Enhanced Admin Order - HolisticPeople.com - Order# ' . $order_id;
    if ($points_total > 0) { $reason .= ' | Points to refund: ' . $points_total; }
    if (!empty($user_reason)) { $reason .= ' | Reason: ' . $user_reason; }

    $manual_stripe = ($amount_total > 0) && eao_payment_order_has_eao_stripe_charge($order);
    $stripe_mode = (string) $order->get_meta('_eao_stripe_payment_mode');
    if ($stripe_mode !== 'test') {
        $stripe_mode = 'live';
    }

    $located_gateway_id = '';
    $located_gateway = null;
    if ($amount_total > 0 && !$manual_stripe) {
        $gateway_resolution = eao_payment_locate_wc_gateway_for_order($order);
        $located_gateway_id = isset($gateway_resolution['id']) ? (string) $gateway_resolution['id'] : '';
        $located_gateway = isset($gateway_resolution['gateway']) ? $gateway_resolution['gateway'] : null;
        if (!$located_gateway || !is_object($located_gateway) || !method_exists($located_gateway, 'supports') || !$located_gateway->supports('refunds')) {
            wp_send_json_error(array('message' => 'Unable to locate a refund-capable payment gateway for this order. Please process the refund on the native WooCommerce order screen or review the payment gateway configuration.'));
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

    $gateway_label = '';
    $gateway_reference_value = '';
    $gateway_reference_note = '';
    $remote_processed = false;

    if ($amount_total > 0) {
        if ($manual_stripe) {
            $charge_id = (string) $order->get_meta('_eao_stripe_charge_id');
            $opts = get_option('eao_stripe_settings', array());
            $secret = ($stripe_mode === 'live') ? ($opts['live_secret'] ?? '') : ($opts['test_secret'] ?? '');
            $gateway_label = 'Stripe (EAO ' . (($stripe_mode === 'live') ? 'Live' : 'Test') . ')';
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
            if (empty($charge_id)) {
                $refund->delete(true);
                wp_send_json_error(array('message' => 'Stripe charge reference not found on this order. Payment may have been processed outside Stripe integration.'));
            }
            if (empty($secret)) {
                $refund->delete(true);
                wp_send_json_error(array('message' => 'Stripe API key missing for ' . (($stripe_mode === 'live') ? 'Live' : 'Test') . ' mode.'));
            }
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
                $refund->delete(true);
                wp_send_json_error(array('message' => 'Stripe refund error: ' . $rf->get_error_message()));
            }
            $rf_body = json_decode(wp_remote_retrieve_body($rf), true);
            if (empty($rf_body['id'])) {
                $refund->delete(true);
                wp_send_json_error(array('message' => 'Stripe refund failed', 'stripe' => $rf_body));
            }
            $gateway_reference_value = (string) $rf_body['id'];
            $gateway_label = 'Stripe ' . (($stripe_mode === 'live') ? 'Live' : 'Test');
            $gateway_reference_note = 'Stripe refund ' . $gateway_reference_value . (($stripe_mode === 'live') ? ' (Live)' : ' (Test)');
            update_post_meta($refund->get_id(), '_eao_stripe_refund_id', $gateway_reference_value);
            update_post_meta($refund->get_id(), '_eao_refund_reference', $gateway_reference_value);
            $remote_processed = true;
        } else {
            $gateway_amount = (float) wc_format_decimal($amount_total, 2);
            $gateway_result = $located_gateway->process_refund($order_id, $gateway_amount, $user_reason);
            if (is_wp_error($gateway_result)) {
                $refund->delete(true);
                wp_send_json_error(array('message' => $gateway_result->get_error_message()));
            }
            if ($gateway_result === false) {
                $refund->delete(true);
                wp_send_json_error(array('message' => 'The payment gateway declined the refund request.'));
            }
            $remote_processed = true;
            if (method_exists($located_gateway, 'get_method_title')) {
                $gateway_label = trim((string) $located_gateway->get_method_title());
            }
            if (!$gateway_label && method_exists($located_gateway, 'get_title')) {
                $gateway_label = trim((string) $located_gateway->get_title());
            }
            if (!$gateway_label) {
                $gateway_label = $located_gateway_id ? ucfirst(str_replace('_', ' ', $located_gateway_id)) : 'Payment Gateway';
            }
            if (is_object($gateway_result)) {
                if (isset($gateway_result->id) && is_string($gateway_result->id) && $gateway_result->id !== '') {
                    $gateway_reference_value = $gateway_result->id;
                } elseif (isset($gateway_result->transaction_id) && is_string($gateway_result->transaction_id) && $gateway_result->transaction_id !== '') {
                    $gateway_reference_value = $gateway_result->transaction_id;
                }
            } elseif (is_string($gateway_result) && $gateway_result !== '') {
                $gateway_reference_value = $gateway_result;
            }
            if ($gateway_reference_value !== '') {
                $gateway_reference_note = 'Gateway reference ' . $gateway_reference_value;
                update_post_meta($refund->get_id(), '_eao_refund_reference', $gateway_reference_value);
            }
        }
    }

    if ($remote_processed && $gateway_label !== '') {
        update_post_meta($refund->get_id(), '_eao_refunded_via_gateway', $gateway_label);
    }

    if ($points_total > 0) {
        if (function_exists('ywpar_increase_points')) {
            ywpar_increase_points($order->get_customer_id(), $points_total, sprintf(__('Redeemed points returned for Order #%d', 'enhanced-admin-order'), $order_id), $order_id);
        } elseif (function_exists('ywpar_get_customer')) {
            $cust = ywpar_get_customer($order->get_customer_id());
            if ($cust && method_exists($cust, 'update_points')) {
                $cust->update_points($points_total, 'order_points_return', array('order_id' => $order_id, 'description' => 'Redeemed points returned'));
            }
        }
        update_post_meta($refund->get_id(), '_eao_points_refunded', (int) $points_total);
        if (!empty($points_map)) { update_post_meta($refund->get_id(), '_eao_points_refunded_map', wp_json_encode($points_map)); }
    }

    if ($remote_processed && $gateway_reference_note !== '' && method_exists($refund, 'get_reason') && method_exists($refund, 'set_reason')) {
        $refund->set_reason(trim($refund->get_reason() . ' | ' . $gateway_reference_note));
        $refund->save();
    }

    // Remove duplicate gateway refund notes that may have been added automatically.
    if (!empty($existing_note_ids)) {
        $recent_notes = wc_get_order_notes(array('order_id' => $order_id, 'type' => 'internal', 'orderby' => 'date_created', 'order' => 'DESC', 'limit' => 5));
        foreach ($recent_notes as $note_obj) {
            if (!is_object($note_obj) || !method_exists($note_obj, 'get_id')) { continue; }
            $note_id = $note_obj->get_id();
            if (in_array($note_id, $existing_note_ids, true)) { continue; }
            $content_plain = trim(wp_strip_all_tags($note_obj->get_content()));
            if ($content_plain === '') { continue; }
            if (stripos($content_plain, 'order refunded') === 0 || stripos($content_plain, 'refunded') === 0) {
                wc_delete_order_note($note_id);
            }
        }
    }

    $human_parts = array();
    if ($amount_total > 0) {
        if ($remote_processed) {
            $message = 'Refund of $' . wc_format_decimal($amount_total, 2) . ' was processed successfully';
            if ($gateway_label !== '') {
                $message .= ' through ' . $gateway_label;
            } else {
                $message .= ' through the payment gateway';
            }
            if ($gateway_reference_note !== '') { $message .= ' (' . $gateway_reference_note . ')'; }
            $human_parts[] = $message . '.';
        } else {
            $human_parts[] = 'Refund of $' . wc_format_decimal($amount_total, 2) . ' was recorded without contacting a payment gateway.';
        }
    } else {
        $human_parts[] = 'Points-only refund recorded.';
    }
    if ($points_total > 0) { $human_parts[] = 'Points refunded: ' . (int) $points_total . '.'; }
    $human = trim(implode(' ', $human_parts));
    if ($human === '') {
        $human = 'EAO refund recorded.';
    } else {
        $human = 'EAO Refund: ' . $human;
    }
    $order->add_order_note($human, false, false);

    wp_send_json_success(array('refund_id' => $refund->get_id(), 'amount' => wc_format_decimal($amount_total, 2), 'points' => (int) $points_total));
}



/**
 * ===============================
 * Stripe Invoice: Send / Void
 * ===============================
 */

/**
 * Create and send a Stripe invoice email (Hosted Invoice Page) for the order.
 * - Due date: immediate
 * - ACH is allowed automatically for US orders in USD (if enabled on account)
 * - Optionally itemized vs grand total lines
 */
function eao_stripe_send_payment_request() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'eao_payment_mockup')) {
        wp_send_json_error(array('message' => 'Nonce verification failed.'));
    }
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Permission denied.'));
    }

    $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    $order = $order_id ? wc_get_order($order_id) : null;
    if (!$order) { wp_send_json_error(array('message' => 'Order not found')); }

    $email = sanitize_email($_POST['email'] ?? '');
    $mode  = in_array(($_POST['mode'] ?? 'grand'), array('grand','itemized'), true) ? $_POST['mode'] : 'grand';
    $gw    = isset($_POST['gateway']) ? sanitize_text_field($_POST['gateway']) : 'stripe_live';
    $amount_override = isset($_POST['amount_override']) ? floatval($_POST['amount_override']) : 0.0;

    $opts = get_option('eao_stripe_settings', array());
    $secret = ($gw === 'stripe_live') ? ($opts['live_secret'] ?? '') : ($opts['test_secret'] ?? '');
    if (empty($secret)) {
        wp_send_json_error(array('message' => 'Stripe secret key is missing for ' . (($gw === 'stripe_live') ? 'Live' : 'Test') ));
    }
    $headers = array('Authorization' => 'Bearer ' . $secret, 'Content-Type' => 'application/x-www-form-urlencoded');

    $currency = strtoupper(method_exists($order,'get_currency') ? (string) $order->get_currency() : (get_woocommerce_currency('USD') ?: 'USD'));
    $billing_country  = strtoupper(method_exists($order,'get_billing_country') ? (string) $order->get_billing_country() : '');
    $shipping_country = strtoupper(method_exists($order,'get_shipping_country') ? (string) $order->get_shipping_country() : '');
    $allow_ach = ($currency === 'USD' && ( $billing_country === 'US' || $shipping_country === 'US'));

    // Ensure Stripe Customer
    $stripe_customer_id = (string) $order->get_meta('_eao_stripe_customer_id', true);
    if ($stripe_customer_id === '') {
        $cust_body = array();
        if ($email) { $cust_body['email'] = $email; }
        $cust_body['name'] = trim(($order->get_billing_first_name() ?: '') . ' ' . ($order->get_billing_last_name() ?: ''));
        $cust_body['metadata[order_id]'] = $order_id;
        $cust_resp = wp_remote_post('https://api.stripe.com/v1/customers', array('headers' => $headers, 'body' => $cust_body, 'timeout' => 25));
        if (is_wp_error($cust_resp)) { wp_send_json_error(array('message' => 'Stripe: customer create failed: '.$cust_resp->get_error_message())); }
        $cust = json_decode(wp_remote_retrieve_body($cust_resp), true);
        if (empty($cust['id'])) { wp_send_json_error(array('message' => 'Stripe: could not create customer', 'stripe' => $cust)); }
        $stripe_customer_id = (string) $cust['id'];
        $order->update_meta_data('_eao_stripe_customer_id', $stripe_customer_id);
        $order->save();
    }

    // SAFETY: Clear any pending invoice items for this customer (previous failed attempts)
    // to prevent totals from accumulating when re-sending on the same order.
    try {
        $starting_after = '';
        for ($page = 0; $page < 5; $page++) {
            $list_url = 'https://api.stripe.com/v1/invoiceitems?customer=' . rawurlencode($stripe_customer_id) . '&pending=true&limit=100' . ($starting_after ? ('&starting_after=' . rawurlencode($starting_after)) : '');
            $list_resp = wp_remote_get($list_url, array('headers' => array('Authorization' => 'Bearer ' . $secret)));
            if (is_wp_error($list_resp)) { break; }
            $list = json_decode(wp_remote_retrieve_body($list_resp), true);
            if (!is_array($list) || empty($list['data'])) { break; }
            foreach ($list['data'] as $pending_item) {
                if (!empty($pending_item['id'])) {
                    wp_remote_request('https://api.stripe.com/v1/invoiceitems/' . rawurlencode($pending_item['id']), array('method' => 'DELETE', 'headers' => array('Authorization' => 'Bearer ' . $secret)));
                    $starting_after = (string) $pending_item['id'];
                }
            }
            if (empty($list['has_more'])) { break; }
        }
    } catch (Exception $e) { /* swallow */ }

    // Optional: reuse open invoice with same remaining amount
    $existing_invoice_id = (string) $order->get_meta('_eao_stripe_invoice_id', true);
    $expected_cents = ($amount_override > 0.0)
        ? (int) round($amount_override * 100)
        : (int) round(((float) $order->get_total()) * 100);
    if ($existing_invoice_id) {
        $inv_resp = wp_remote_get('https://api.stripe.com/v1/invoices/' . rawurlencode($existing_invoice_id), array('headers' => array('Authorization' => 'Bearer ' . $secret)));
        if (!is_wp_error($inv_resp)) {
            $inv = json_decode(wp_remote_retrieve_body($inv_resp), true);
            if (!empty($inv['id']) && !empty($inv['status']) && in_array($inv['status'], array('draft','open'), true)) {
                $remain = isset($inv['amount_remaining']) ? (int) $inv['amount_remaining'] : (isset($inv['amount_due']) ? (int) $inv['amount_due'] : 0);
                if ($remain === $expected_cents) {
                    // Resend existing invoice
                    if ($inv['status'] === 'draft') {
                        wp_remote_post('https://api.stripe.com/v1/invoices/' . rawurlencode($inv['id']) . '/finalize', array('headers' => $headers));
                    }
                    $send_resp = wp_remote_post('https://api.stripe.com/v1/invoices/' . rawurlencode($inv['id']) . '/send', array('headers' => $headers));
                    $send_body = json_decode(wp_remote_retrieve_body($send_resp), true);
                    if (!empty($send_body['id'])) {
                        $order->update_meta_data('_eao_stripe_invoice_status', (string) $send_body['status']);
                        $order->update_meta_data('_eao_stripe_invoice_url', (string) ($send_body['hosted_invoice_url'] ?? ''));
                        $order->save();
                        $order->add_order_note('Stripe payment request re-sent. Invoice #' . ($send_body['number'] ?? $send_body['id']));
                        wp_send_json_success(array('invoice_id' => $send_body['id'], 'status' => $send_body['status'], 'url' => ($send_body['hosted_invoice_url'] ?? ''), 'message' => 'Payment request sent.'));
                    }
                }
            }
        }
    }

    // Create invoice items
    if ($mode === 'grand') {
        $amount_cents = ($amount_override > 0.0)
            ? (int) round($amount_override * 100)
            : (int) round(((float) $order->get_total()) * 100);
        $ii = wp_remote_post('https://api.stripe.com/v1/invoiceitems', array(
            'headers' => $headers,
            'body' => array(
                'customer' => $stripe_customer_id,
                'amount'   => $amount_cents,
                'currency' => strtolower($currency),
                'description' => 'Order #' . $order_id,
                'metadata[order_id]' => $order_id
            )
        ));
        if (is_wp_error($ii)) { wp_send_json_error(array('message' => 'Stripe: invoice item failed: '.$ii->get_error_message())); }
    } else {
        // Itemized: products + shipping + fees; then adjustment to match staged grand total (discounts/points)
        $sum_created_cents = 0;
        foreach ($order->get_items('line_item') as $item) {
            $line_total = (float) $item->get_total() + (float) $item->get_total_tax();
            if ($line_total <= 0) { continue; }
            $qty = (int) $item->get_quantity();
            $desc = $item->get_name();
            if ($qty > 1) { $desc .= ' x ' . $qty; }
            $ii = wp_remote_post('https://api.stripe.com/v1/invoiceitems', array(
                'headers' => $headers,
                'body' => array(
                    'customer' => $stripe_customer_id,
                    'amount'   => (int) round($line_total * 100),
                    'currency' => strtolower($currency),
                    'description' => $desc,
                    'metadata[order_id]' => $order_id
                )
            ));
            if (is_wp_error($ii)) { wp_send_json_error(array('message' => 'Stripe: invoice item failed: '.$ii->get_error_message())); }
            $created = json_decode(wp_remote_retrieve_body($ii), true);
            if (!empty($created['amount'])) { $sum_created_cents += (int) $created['amount']; }
        }
        // Shipping
        $shipping_total = (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();
        if ($shipping_total > 0) {
            $ii = wp_remote_post('https://api.stripe.com/v1/invoiceitems', array(
                'headers' => $headers,
                'body' => array(
                    'customer' => $stripe_customer_id,
                    'amount'   => (int) round($shipping_total * 100),
                    'currency' => strtolower($currency),
                    'description' => 'Shipping',
                    'metadata[order_id]' => $order_id
                )
            ));
            if (is_wp_error($ii)) { wp_send_json_error(array('message' => 'Stripe: invoice item (shipping) failed: '.$ii->get_error_message())); }
            $created = json_decode(wp_remote_retrieve_body($ii), true);
            if (!empty($created['amount'])) { $sum_created_cents += (int) $created['amount']; }
        }
        // Fees (positive only)
        foreach ($order->get_fees() as $fee) {
            $fee_total = (float) $fee->get_total() + (float) $fee->get_total_tax();
            if ($fee_total <= 0) { continue; }
            $ii = wp_remote_post('https://api.stripe.com/v1/invoiceitems', array(
                'headers' => $headers,
                'body' => array(
                    'customer' => $stripe_customer_id,
                    'amount'   => (int) round($fee_total * 100),
                    'currency' => strtolower($currency),
                    'description' => 'Fee: ' . $fee->get_name(),
                    'metadata[order_id]' => $order_id
                )
            ));
            if (is_wp_error($ii)) { wp_send_json_error(array('message' => 'Stripe: invoice item (fee) failed: '.$ii->get_error_message())); }
            $created = json_decode(wp_remote_retrieve_body($ii), true);
            if (!empty($created['amount'])) { $sum_created_cents += (int) $created['amount']; }
        }
        // Adjustment delta to include coupons/points so Stripe total matches staged grand total
        $expected_cents = ($amount_override > 0.0)
            ? (int) round($amount_override * 100)
            : (int) round(((float) $order->get_total()) * 100);
        $delta_cents = $expected_cents - $sum_created_cents;
        if ($delta_cents !== 0) {
            $descAdj = ($delta_cents < 0) ? 'Discount/Points adjustment' : 'Adjustment';
            $ii = wp_remote_post('https://api.stripe.com/v1/invoiceitems', array(
                'headers' => $headers,
                'body' => array(
                    'customer' => $stripe_customer_id,
                    'amount'   => (int) $delta_cents,
                    'currency' => strtolower($currency),
                    'description' => $descAdj,
                    'metadata[order_id]' => $order_id
                )
            ));
            if (is_wp_error($ii)) { wp_send_json_error(array('message' => 'Stripe: invoice item (adjustment) failed: '.$ii->get_error_message())); }
        }
    }

    // Create invoice
    $body = array(
        'customer' => $stripe_customer_id,
        'collection_method' => 'send_invoice',
        // immediate due (buffer a few minutes to avoid timezone/clock drift issues)
        'due_date' => time() + 600,
        'metadata[order_id]' => $order_id,
        // Ensure the invoice captures the invoice items we just created
        'pending_invoice_items_behavior' => 'include',
    );
    $pm_types = array('card');
    if ($allow_ach) { $pm_types[] = 'us_bank_account'; }
    foreach ($pm_types as $i => $t) { $body['payment_settings[payment_method_types]['.$i.']'] = $t; }

    $inv_create = wp_remote_post('https://api.stripe.com/v1/invoices', array('headers' => $headers, 'body' => $body, 'timeout' => 25));
    if (is_wp_error($inv_create)) { wp_send_json_error(array('message' => 'Stripe: invoice create failed: '.$inv_create->get_error_message())); }
    $invoice = json_decode(wp_remote_retrieve_body($inv_create), true);
    if (empty($invoice['id'])) { wp_send_json_error(array('message' => 'Stripe: invoice create failed', 'stripe' => $invoice)); }

    // Finalize and send
    wp_remote_post('https://api.stripe.com/v1/invoices/' . rawurlencode($invoice['id']) . '/finalize', array('headers' => $headers));
    $send = wp_remote_post('https://api.stripe.com/v1/invoices/' . rawurlencode($invoice['id']) . '/send', array('headers' => $headers));
    if (is_wp_error($send)) { wp_send_json_error(array('message' => 'Stripe: invoice send failed: '.$send->get_error_message())); }
    $sent = json_decode(wp_remote_retrieve_body($send), true);
    if (empty($sent['id'])) { wp_send_json_error(array('message' => 'Stripe: invoice send failed', 'stripe' => $sent)); }

    // Persist
    $order->update_meta_data('_eao_stripe_invoice_id', (string) $sent['id']);
    $order->update_meta_data('_eao_stripe_invoice_url', (string) ($sent['hosted_invoice_url'] ?? ''));
    $order->update_meta_data('_eao_stripe_invoice_status', (string) ($sent['status'] ?? 'open'));
    $order->save();
    $order->add_order_note('Stripe payment request sent. Invoice #' . ($sent['number'] ?? $sent['id']));

    wp_send_json_success(array('invoice_id' => $sent['id'], 'status' => $sent['status'] ?? 'open', 'url' => ($sent['hosted_invoice_url'] ?? '')));
}

/** Void a previously created invoice */
function eao_stripe_void_invoice() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'eao_payment_mockup')) {
        wp_send_json_error(array('message' => 'Nonce verification failed.'));
    }
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Permission denied.'));
    }
    $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    $order = $order_id ? wc_get_order($order_id) : null;
    if (!$order) { wp_send_json_error(array('message' => 'Order not found')); }
    $invoice_id = sanitize_text_field($_POST['invoice_id'] ?? (string) $order->get_meta('_eao_stripe_invoice_id', true));
    if ($invoice_id === '') { wp_send_json_error(array('message' => 'No invoice to void.')); }
    $gw    = isset($_POST['gateway']) ? sanitize_text_field($_POST['gateway']) : 'stripe_live';
    $opts = get_option('eao_stripe_settings', array());
    $secret = ($gw === 'stripe_live') ? ($opts['live_secret'] ?? '') : ($opts['test_secret'] ?? '');
    if (empty($secret)) { wp_send_json_error(array('message' => 'Stripe secret missing')); }
    $headers = array('Authorization' => 'Bearer ' . $secret);

    $resp = wp_remote_post('https://api.stripe.com/v1/invoices/' . rawurlencode($invoice_id) . '/void', array('headers' => $headers));
    if (is_wp_error($resp)) { wp_send_json_error(array('message' => 'Stripe: void failed: '.$resp->get_error_message())); }
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if (empty($body['id'])) { wp_send_json_error(array('message' => 'Stripe: void failed', 'stripe' => $body)); }
    $order->update_meta_data('_eao_stripe_invoice_status', (string) ($body['status'] ?? 'void'));
    $order->save();
    $order->add_order_note('Stripe payment request voided. Invoice #' . ($body['number'] ?? $invoice_id));
    wp_send_json_success(array('invoice_id' => $invoice_id, 'status' => ($body['status'] ?? 'void')));
}
