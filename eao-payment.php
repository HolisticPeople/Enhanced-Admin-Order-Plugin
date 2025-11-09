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
// Stripe invoice: status
add_action('wp_ajax_eao_stripe_get_invoice_status', 'eao_stripe_get_invoice_status');

// PayPal invoice: send/void/status + settings
add_action('wp_ajax_eao_paypal_send_payment_request', 'eao_paypal_send_payment_request');
add_action('wp_ajax_eao_paypal_void_invoice', 'eao_paypal_void_invoice');
add_action('wp_ajax_eao_paypal_get_invoice_status', 'eao_paypal_get_invoice_status');
add_action('wp_ajax_eao_payment_paypal_save_settings', 'eao_payment_paypal_save_settings');
add_action('wp_ajax_eao_payment_webhooks_save_settings', 'eao_payment_webhooks_save_settings');
add_action('wp_ajax_eao_payment_stripe_webhooks_save', 'eao_payment_stripe_webhooks_save');
add_action('wp_ajax_eao_payment_paypal_webhooks_save', 'eao_payment_paypal_webhooks_save');

// REST API webhooks
add_action('rest_api_init', function(){
    register_rest_route('eao/v1', '/stripe/webhook', array(
        'methods' => 'POST',
        'callback' => 'eao_stripe_webhook_handler',
        'permission_callback' => '__return_true',
    ));
    register_rest_route('eao/v1', '/paypal/webhook', array(
        'methods' => 'POST',
        'callback' => 'eao_paypal_webhook_handler',
        'permission_callback' => '__return_true',
    ));
});

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
    if ($billing_email === '' && method_exists($order,'get_user_id')) {
        $uid = (int) $order->get_user_id();
        if ($uid) { $u = get_user_by('id', $uid); if ($u && !empty($u->user_email)) { $billing_email = (string) $u->user_email; } }
    }
    if ($billing_email === '') {
        // Fallback to raw meta in case billing email not stored on the order object yet
        $meta_email = '';
        if (method_exists($order, 'get_meta')) { $meta_email = (string) $order->get_meta('_billing_email', true); }
        if ($meta_email !== '') { $billing_email = $meta_email; }
    }

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
    $existing_invoice_url = (string) $order->get_meta('_eao_stripe_invoice_url', true);
    $has_active_invoice = ($existing_invoice_id !== '' && strtolower($existing_invoice_status) !== 'void');

    // PayPal existing invoice meta
    $pp_existing_invoice_id = (string) $order->get_meta('_eao_paypal_invoice_id', true);
    $pp_existing_invoice_status = (string) $order->get_meta('_eao_paypal_invoice_status', true);
    $pp_existing_invoice_url = (string) $order->get_meta('_eao_paypal_invoice_url', true);
    $pp_status_norm = strtolower($pp_existing_invoice_status);
    $pp_has_active_invoice = ($pp_existing_invoice_id !== '' && !in_array($pp_status_norm, array('void','voided','cancel','canceled','cancelled'), true));
    $last_paid_cents = (int) $order->get_meta('_eao_last_charged_amount_cents', true);
    $last_paid_currency = (string) $order->get_meta('_eao_last_charged_currency', true);

    ?>
    <div id="eao-payment-processing-container">
        <div style="display:flex; gap:16px; align-items:flex-start;">
        <div style="flex:1 1 50%; min-width:420px; border-right:1px solid #dcdcde; padding-right:12px;">
        <table class="form-table">
            <tr>
                <th><label for="eao-pp-amount">Payment Amount</label></th>
                <td>
                    <input type="number" id="eao-pp-amount" step="0.01" min="0.01" value="<?php echo esc_attr(number_format((float)$amount,2,'.','')); ?>" />
                    <button type="button" id="eao-pp-gateway-settings" class="button" title="Payment Settings" style="margin-left:6px;">
                        <span class="dashicons dashicons-admin-generic"></span>
                    </button>
                </td>
            </tr>
        </table>
        <hr style="margin:10px 0 14px; border:0; border-top:1px solid #dcdcde;" />

        <div id="eao-pp-settings-panel" style="display:none; margin:8px 0 12px 0;">
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

            <h4 style="margin-top:18px;">PayPal Settings (Live)</h4>
            <?php $pp_opts = get_option('eao_paypal_settings', array('live_client_id' => '', 'live_secret' => '', 'webhook_id_live' => '')); ?>
            <table class="form-table">
                <tr>
                    <th>Live Client ID</th>
                    <td><input type="text" id="eao-pp-paypal-live-client-id" value="<?php echo esc_attr($pp_opts['live_client_id'] ?? ''); ?>" style="width:420px;" /></td>
                </tr>
                <tr>
                    <th>Live Secret</th>
                    <td><input type="text" id="eao-pp-paypal-live-secret" value="<?php echo esc_attr($pp_opts['live_secret'] ?? ''); ?>" style="width:420px;" /></td>
                </tr>
            </table>
            <p><button type="button" id="eao-pp-save-paypal" class="button">Save PayPal Settings</button></p>

            <h4 style="margin-top:18px;">Webhook Environment</h4>
            <?php $global_env = get_option('eao_webhook_env', 'live'); ?>
            <table class="form-table">
                <tr>
                    <th>Active Environment (Stripe & PayPal)</th>
                    <td>
                        <select id="eao-webhook-env-global">
                            <option value="live"<?php echo ($global_env==='live')?' selected':''; ?>>Production</option>
                            <option value="staging"<?php echo ($global_env==='staging')?' selected':''; ?>>Staging</option>
                        </select>
                    </td>
                </tr>
            </table>

            <h4 style="margin-top:18px;">Stripe Webhook Settings</h4>
            <?php $stripe_opts = get_option('eao_stripe_settings', array()); $stripe_env = get_option('eao_webhook_env','live'); ?>
            <table class="form-table">
                <tr>
                    <th>Webhook URL (Production)</th>
                    <td>
                        <input type="text" id="eao-stripe-webhook-url-live" style="width:560px;" value="<?php echo esc_attr($stripe_opts['webhook_url_live'] ?? get_rest_url(null, 'eao/v1/stripe/webhook')); ?>" />
                        <p class="description">Stripe Dashboard → Developers → Webhooks → Add endpoint (copy this URL).</p>
                    </td>
                </tr>
                <tr>
                    <th>Signing Secret (Production)</th>
                    <td>
                        <input type="text" id="eao-stripe-webhook-secret-live" style="width:420px;" value="<?php echo esc_attr($stripe_opts['webhook_signing_secret_live'] ?? ''); ?>" />
                        <p class="description">From the Stripe endpoint details (Reveal secret).</p>
                    </td>
                </tr>
                <tr>
                    <th>Webhook URL (Staging)</th>
                    <td>
                        <input type="text" id="eao-stripe-webhook-url-stg" style="width:560px;" value="<?php echo esc_attr($stripe_opts['webhook_url_staging'] ?? get_rest_url(null, 'eao/v1/stripe/webhook')); ?>" />
                        <p class="description">Use your staging URL endpoint.</p>
                    </td>
                </tr>
                <tr>
                    <th>Signing Secret (Staging)</th>
                    <td>
                        <input type="text" id="eao-pp-stripe-webhook-secret-staging" style="width:420px;" value="<?php echo esc_attr($stripe_opts['webhook_signing_secret_staging'] ?? ''); ?>" />
                        <p class="description">From the Stripe staging endpoint (Reveal secret).</p>
                    </td>
                </tr>
            </table>
            <p><button type="button" id="eao-pp-save-stripe-webhooks" class="button">Save Stripe Webhooks</button></p>

            <h4 style="margin-top:18px;">PayPal Webhook Settings</h4>
            <?php $paypal_opts = get_option('eao_paypal_settings', array()); $paypal_env = get_option('eao_webhook_env', 'live'); ?>
            <table class="form-table">
                <tr>
                    <th>Webhook URL (Production)</th>
                    <td>
                        <input type="text" id="eao-paypal-webhook-url-live" style="width:560px;" value="<?php echo esc_attr($paypal_opts['webhook_url_live'] ?? get_rest_url(null, 'eao/v1/paypal/webhook')); ?>" />
                        <p class="description">PayPal Developer → Webhooks → Add Webhook (paste this URL).</p>
                    </td>
                </tr>
                <tr>
                    <th>Webhook ID (Production)</th>
                    <td>
                        <input type="text" id="eao-pp-paypal-webhook-id-live2" style="width:420px;" value="<?php echo esc_attr($paypal_opts['webhook_id_live'] ?? ''); ?>" />
                        <p class="description">From the PayPal webhook details page.</p>
                    </td>
                </tr>
                <tr>
                    <th>Webhook URL (Staging)</th>
                    <td>
                        <input type="text" id="eao-paypal-webhook-url-stg" style="width:560px;" value="<?php echo esc_attr($paypal_opts['webhook_url_staging'] ?? get_rest_url(null, 'eao/v1/paypal/webhook')); ?>" />
                        <p class="description">Use your staging URL endpoint.</p>
                    </td>
                </tr>
                <tr>
                    <th>Webhook ID (Staging)</th>
                    <td>
                        <input type="text" id="eao-pp-paypal-webhook-id-staging" style="width:420px;" value="<?php echo esc_attr($paypal_opts['webhook_id_staging'] ?? ''); ?>" />
                        <p class="description">From the PayPal webhook details page.</p>
                    </td>
                </tr>
            </table>
            <p><button type="button" id="eao-pp-save-paypal-webhooks" class="button">Save PayPal Webhooks</button></p>
        </div>

        <h3 style="margin:6px 0 8px;">Immediate Payment</h3>
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

        <hr style="margin:14px 0; border:0; border-top:1px solid #dcdcde;" />

        <h3 style="margin:6px 0 8px;">Request Payment</h3>
        <div id="eao-pp-request-panel" style="margin-top:8px;">
            <p style="margin:6px 0 8px 0; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                <button type="button" id="eao-pp-send-stripe" class="button">Send Stripe Payment Request</button>
                <button type="button" id="eao-pp-send-paypal" class="button">Send PayPal Payment Request</button>
                <button type="button" id="eao-pp-void-stripe" class="button button-secondary" style="<?php echo $has_active_invoice ? '' : 'display:none;'; ?>">Void Stripe Request</button>
                <button type="button" id="eao-pp-void-paypal" class="button button-secondary" style="<?php echo $pp_has_active_invoice ? '' : 'display:none;'; ?>">Void PayPal Request</button>
                <button type="button" id="eao-pp-refresh-status" class="button">Refresh Status</button>
            </p>
            <div id="eao-pp-request-options" style="margin:6px 0 10px 0;">
                <label id="eao-pp-email-label" style="<?php echo ($has_active_invoice || $pp_has_active_invoice) ? 'display:none;' : ''; ?>">Email to send to: <input type="email" id="eao-pp-request-email" value="<?php echo esc_attr($billing_email); ?>" style="width:260px" /></label>
                <span style="margin-left:12px;">Amount source:</span>
                <label style="margin-left:6px;"><input type="radio" name="eao-pp-line-mode" value="grand" checked /> Grand total</label>
                <label style="margin-left:6px;"><input type="radio" name="eao-pp-line-mode" value="itemized" /> Itemized</label>
                <label style="margin-left:12px;display:inline-flex;align-items:center;gap:6px;">
                    <input type="checkbox" id="eao-pp-auto-process" checked />
                    <span>Change Order Status to Processing when paid</span>
                </label>
                <input type="hidden" id="eao-pp-invoice-id" value="<?php echo esc_attr($existing_invoice_id); ?>" />
                <input type="hidden" id="eao-pp-invoice-status" value="<?php echo esc_attr($existing_invoice_status); ?>" />
                <input type="hidden" id="eao-pp-invoice-url" value="<?php echo esc_attr($existing_invoice_url); ?>" />
                <input type="hidden" id="eao-pp-paypal-invoice-id" value="<?php echo esc_attr($pp_existing_invoice_id); ?>" />
                <input type="hidden" id="eao-pp-paypal-invoice-status" value="<?php echo esc_attr($pp_existing_invoice_status); ?>" />
                <input type="hidden" id="eao-pp-paypal-invoice-url" value="<?php echo esc_attr($pp_existing_invoice_url); ?>" />
                <input type="hidden" id="eao-pp-paid-cents" value="<?php echo esc_attr($last_paid_cents); ?>" />
                <input type="hidden" id="eao-pp-paid-currency" value="<?php echo esc_attr($last_paid_currency); ?>" />
                <?php
                // Render requests ledger and remaining balance
                $reqs_list = function_exists('eao_requests_load') ? eao_requests_load($order) : array();
                $order_total_cents = (int) round(((float) $order->get_total()) * 100);
                $already_req_cents = function_exists('eao_requests_sum_requested_cents') ? eao_requests_sum_requested_cents($reqs_list) : 0;
                $remaining_cents = max(0, $order_total_cents - $already_req_cents);
                ?>
                <input type="hidden" id="eao-pp-remaining-cents" value="<?php echo esc_attr($remaining_cents); ?>" />
                <div id="eao-pp-requests-list" style="margin-top:10px;">
                    <?php if (empty($reqs_list)) : ?>
                        <em>No payment requests yet.</em>
                    <?php else : ?>
                        <table class="widefat fixed striped" style="margin-top:6px;">
                            <thead>
                                <tr>
                                    <th style="width:10%;">Gateway</th>
                                    <th style="width:17%;">Amount</th>
                                    <th style="width:10%;">Status</th>
                                    <th style="width:14%;">Link</th>
                                    <th style="width:40%;">Invoice ID</th>
                                    <th style="width:9%; white-space:nowrap;">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="eao-pp-requests-tbody">
                            <?php foreach ($reqs_list as $r) : 
                                $gw = isset($r['gateway']) ? (string) $r['gateway'] : '';
                                $inv = isset($r['invoice_id']) ? (string) $r['invoice_id'] : '';
                                $amt_c = isset($r['amount_cents']) ? (int) $r['amount_cents'] : 0;
                                $cur = isset($r['currency']) ? strtoupper((string) $r['currency']) : 'USD';
                                $state = isset($r['state']) ? strtoupper((string) $r['state']) : 'OPEN';
                                $url = isset($r['url']) ? (string) $r['url'] : '';
                                $voidable = !in_array($state, array('PAID','VOID','CANCELLED','CANCELED'), true);
                            ?>
                                <tr data-gateway="<?php echo esc_attr($gw); ?>" data-invoice-id="<?php echo esc_attr($inv); ?>">
                                    <td><?php echo esc_html( ucfirst(str_replace('_',' ',$gw)) ); ?></td>
                                    <td><?php echo esc_html( ($cur?:'USD') . ' $' . number_format($amt_c/100, 2) ); ?></td>
                                    <td class="eao-pp-req-state"><?php echo esc_html($state); ?></td>
                                    <td><?php if ($url) : ?><a target="_blank" rel="noopener" href="<?php echo esc_url($url); ?>">Open</a><?php else: ?><span style="opacity:.7;">N/A</span><?php endif; ?></td>
                                    <td><small style="white-space:nowrap;"><?php echo esc_html($inv); ?></small></td>
                                    <td>
                                        <?php if ($voidable && $inv !== '') : ?>
                                            <button type="button" class="button button-small eao-pp-void-row" data-gateway="<?php echo esc_attr($gw); ?>" data-invoice="<?php echo esc_attr($inv); ?>">Void</button>
                                        <?php else: ?>
                                            <span style="opacity:.6;">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
            <div id="eao-pp-request-messages"></div>
        </div>

        <div id="eao-pp-messages"></div>

        

        <?php wp_nonce_field('eao_payment_mockup', 'eao_payment_mockup_nonce'); ?>
        <input type="hidden" id="eao-pp-order-id" value="<?php echo esc_attr($order_id); ?>" />
        </div>
        <div style="flex:1 1 50%; min-width:420px; padding-left:12px;">
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

    // EAO PayPal Invoice detection
    $pp_inv_id = (string) $order->get_meta('_eao_paypal_invoice_id', true);
    if ($pp_inv_id !== '') {
        $result['id'] = 'eao_paypal_invoice';
        $result['source'] = 'eao_paypal';
        $result['label'] = 'PayPal (EAO Invoice)';
        $result['mode'] = 'live';
        $result['reference'] = $pp_inv_id;
        $result['message'] = 'Gateway for this order: PayPal (EAO Invoice). Refunds will be recorded in WooCommerce only. Reference: ' . $pp_inv_id . '.';
        return $result;
    }

    // EAO Stripe Invoice detection
    $st_inv_id = (string) $order->get_meta('_eao_stripe_invoice_id', true);
    if ($st_inv_id !== '') {
        $mode_meta = strtolower((string) $order->get_meta('_eao_stripe_payment_mode'));
        $mode = ($mode_meta === 'test') ? 'test' : 'live';
        $result['id'] = 'eao_stripe_' . $mode;
        $result['source'] = 'eao_stripe_invoice';
        $result['label'] = 'Stripe (EAO ' . (($mode === 'test') ? 'Test' : 'Live') . ')';
        $result['mode'] = $mode;
        $result['reference'] = $st_inv_id;
        $result['message'] = 'Gateway for this order: ' . $result['label'] . '. Refunds will be sent through the Enhanced Admin Order Stripe API. Reference: ' . $st_inv_id . '.';
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
    // If still no known label, return empty to hide the banner (no payment yet)
    if (empty($result['label'])) {
        return $result;
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
        $is_eao_paypal = (string) $order->get_meta('_eao_paypal_invoice_id', true) !== '';
        if (!$is_eao_paypal) {
            if (!$located_gateway || !is_object($located_gateway) || !method_exists($located_gateway, 'supports') || !$located_gateway->supports('refunds')) {
                wp_send_json_error(array('message' => 'Unable to locate a refund-capable payment gateway for this order. Please process the refund on the native WooCommerce order screen or review the payment gateway configuration.'));
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
        } else if (isset($is_eao_paypal) && $is_eao_paypal && (!$located_gateway || !method_exists($located_gateway, 'supports') || !$located_gateway->supports('refunds'))) {
            // Remote refund via PayPal APIs using capture or sale/transaction id
            $gateway_label = 'PayPal (EAO Invoice)';
            $token3 = eao_paypal_get_oauth_token();
            if (is_wp_error($token3)) {
                $refund->delete(true);
                wp_send_json_error(array('message' => 'PayPal auth failed: ' . $token3->get_error_message()));
            }
            $capture_id = (string) $order->get_meta('_eao_paypal_capture_id', true);
            if ($capture_id === '') {
                // Attempt to derive capture id from invoice detail (with short retries and fallback endpoint)
                $invoice_id = (string) $order->get_meta('_eao_paypal_invoice_id', true);
                
                $headers_det = array('Authorization' => 'Bearer ' . $token3, 'Accept' => 'application/json');
                for ($attempt = 1; $attempt <= 4 && $capture_id === '' && $invoice_id !== ''; $attempt++) {
                    
                    $det = wp_remote_get('https://api-m.paypal.com/v2/invoicing/invoices/' . rawurlencode($invoice_id), array('headers' => $headers_det, 'timeout' => 15));
                    if (!is_wp_error($det)) {
                        $dx = json_decode(wp_remote_retrieve_body($det), true);
                        if (!empty($dx['payments']) && is_array($dx['payments'])) {
                            foreach ($dx['payments'] as $p) {
                                if (!empty($p['transaction_id'])) { $capture_id = (string)$p['transaction_id']; break; }
                                if (!$capture_id && !empty($p['paypal_transaction_id'])) { $capture_id = (string)$p['paypal_transaction_id']; break; }
                                if (!$capture_id && !empty($p['payment_id'])) { $capture_id = (string)$p['payment_id']; break; }
                                if (!$capture_id && !empty($p['id'])) { $capture_id = (string)$p['id']; break; }
                                if (!$capture_id && !empty($p['reference_id'])) { $capture_id = (string)$p['reference_id']; break; }
                            }
                        }
                        // Some payloads present payments as an object with a 'transactions' array
                        if (!$capture_id && isset($dx['payments']['transactions']) && is_array($dx['payments']['transactions'])) {
                            foreach ($dx['payments']['transactions'] as $tx) {
                                if (!empty($tx['payment_id'])) { $capture_id = (string)$tx['payment_id']; break; }
                                if (!$capture_id && !empty($tx['transaction_id'])) { $capture_id = (string)$tx['transaction_id']; break; }
                                if (!$capture_id && !empty($tx['id'])) { $capture_id = (string)$tx['id']; break; }
                            }
                        }
                    }
                    if ($capture_id === '') {
                        // Fallback endpoint: explicit payments listing for invoice
                        $det2 = wp_remote_get('https://api-m.paypal.com/v2/invoicing/invoices/' . rawurlencode($invoice_id) . '/payments', array('headers' => $headers_det, 'timeout' => 15));
                        if (!is_wp_error($det2)) {
                            $dx2 = json_decode(wp_remote_retrieve_body($det2), true);
                            if (is_array($dx2)) {
                                foreach ($dx2 as $p2) {
                                    if (!empty($p2['transaction_id'])) { $capture_id = (string)$p2['transaction_id']; break; }
                                    if (!$capture_id && !empty($p2['paypal_transaction_id'])) { $capture_id = (string)$p2['paypal_transaction_id']; break; }
                                    if (!$capture_id && !empty($p2['payment_id'])) { $capture_id = (string)$p2['payment_id']; break; }
                                    if (!$capture_id && !empty($p2['id'])) { $capture_id = (string)$p2['id']; break; }
                                }
                            }
                        }
                    }
                    if ($capture_id === '') { sleep(1); }
                }
                if ($capture_id !== '') { $order->update_meta_data('_eao_paypal_capture_id', $capture_id); $order->save(); }
            }
            if ($capture_id === '') {
                $refund->delete(true);
                wp_send_json_error(array('message' => 'PayPal capture reference not found for this invoice.'));
            }
            $currency = (string) $order->get_meta('_eao_last_charged_currency', true);
            if ($currency === '') { $currency = strtoupper(method_exists($order,'get_currency') ? (string)$order->get_currency() : 'USD'); }
            $rf_headers = array('Authorization' => 'Bearer ' . $token3, 'Content-Type' => 'application/json', 'Accept' => 'application/json');
            $rf_body_v2 = array('amount' => array('value' => wc_format_decimal($amount_total, 2), 'currency_code' => $currency));
            $rf_resp = null;
            // Heuristic: 17-char alnum or starting with PAYID indicates a sale/transaction id → use v1 sale refund
            if (preg_match('/^[A-Z0-9]{17}$/', $capture_id) || strpos($capture_id, 'PAYID-') === 0) {
                
                $rf_resp = wp_remote_post('https://api-m.paypal.com/v1/payments/sale/' . rawurlencode($capture_id) . '/refund', array('headers' => $rf_headers, 'body' => wp_json_encode(array('amount' => array('total' => wc_format_decimal($amount_total, 2), 'currency' => $currency))), 'timeout' => 25));
            } else {
                
                $rf_resp = wp_remote_post('https://api-m.paypal.com/v2/payments/captures/' . rawurlencode($capture_id) . '/refund', array('headers' => $rf_headers, 'body' => wp_json_encode($rf_body_v2), 'timeout' => 25));
            }
            if (is_wp_error($rf_resp)) { $refund->delete(true); wp_send_json_error(array('message' => 'PayPal refund error: ' . $rf_resp->get_error_message())); }
            $rf_code = (int) wp_remote_retrieve_response_code($rf_resp);
            $rf_dbg = wp_remote_retrieve_header($rf_resp, 'paypal-debug-id');
            $rf_json = json_decode(wp_remote_retrieve_body($rf_resp), true);
            if ($rf_code >= 400 || !is_array($rf_json) || empty($rf_json['id'])) {
                $refund->delete(true);
                $msg = 'PayPal refund failed' . ($rf_dbg ? ' ['.$rf_dbg.']' : '');
                if (is_array($rf_json) && !empty($rf_json['message'])) { $msg .= ': ' . $rf_json['message']; }
                wp_send_json_error(array('message' => $msg, 'paypal' => $rf_json));
            }
            $gateway_reference_value = (string) $rf_json['id'];
            $gateway_reference_note = 'PayPal refund ' . $gateway_reference_value;
            update_post_meta($refund->get_id(), '_eao_paypal_refund_id', $gateway_reference_value);
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
 * Internal helpers for tracking multiple payment requests (Stripe/PayPal) per order.
 * We persist an array of request records in order meta under `_eao_requests` as JSON.
 * Each record:
 *  - gateway: 'stripe' | 'paypal'
 *  - invoice_id: string
 *  - amount_cents: int
 *  - currency: string
 *  - state: 'open' | 'paid' | 'void'
 *  - auto_process_on_full: bool
 *  - created_at: int (unix)
 *  - paid_at?: int (unix)
 */
function eao_requests_load($order) {
    $raw = (string) $order->get_meta('_eao_requests', true);
    if ($raw === '') { return array(); }
    $arr = json_decode($raw, true);
    return is_array($arr) ? $arr : array();
}
function eao_requests_save($order, $list) {
    $order->update_meta_data('_eao_requests', wp_json_encode(array_values($list)));
}
function eao_requests_sum_requested_cents($list) {
    $sum = 0;
    foreach ($list as $r) {
        if (!is_array($r)) { continue; }
        if (!isset($r['state']) || $r['state'] === 'void') { continue; }
        $sum += isset($r['amount_cents']) ? (int) $r['amount_cents'] : 0;
    }
    return max(0, (int) $sum);
}
function eao_requests_sum_paid_cents($list) {
    $sum = 0;
    foreach ($list as $r) {
        if (!is_array($r)) { continue; }
        if (isset($r['state']) && $r['state'] === 'paid') {
            $sum += isset($r['amount_cents']) ? (int) $r['amount_cents'] : 0;
        }
    }
    return max(0, (int) $sum);
}
function eao_requests_find_index($list, $gateway, $invoice_id) {
    foreach ($list as $i => $r) {
        if (!is_array($r)) { continue; }
        if (($r['gateway'] ?? '') === $gateway && ($r['invoice_id'] ?? '') === $invoice_id) { return (int) $i; }
    }
    return -1;
}
/**
 * After any invoice paid/void update, evaluate whether the order should auto-advance.
 * Rule:
 * - If there are any outstanding (state != paid/void) requests with auto_process_on_full=true, do not advance yet.
 * - When total paid (across all requests) >= order total AND there are no outstanding auto_process requests, advance to Processing if in a pending-like state; else add a note.
 */
function eao_requests_maybe_auto_advance_status($order) {
    $total_cents = (int) round(((float) $order->get_total()) * 100);
    $list = eao_requests_load($order);
    $paid_cents = eao_requests_sum_paid_cents($list);
    $has_outstanding_auto = false;
    foreach ($list as $r) {
        if (!is_array($r)) { continue; }
        $auto = !empty($r['auto_process_on_full']);
        $state = isset($r['state']) ? $r['state'] : 'open';
        if ($auto && $state !== 'paid' && $state !== 'void') { $has_outstanding_auto = true; break; }
    }
    if ($paid_cents >= $total_cents && !$has_outstanding_auto) {
        $current = method_exists($order,'get_status') ? (string) $order->get_status() : '';
        $is_pending_like = in_array($current, array('pending','pending-payment'), true);
        $paid_text = '$' . number_format($paid_cents/100, 2);
        if ($is_pending_like) {
            try { $order->update_status('processing', 'Payment requests fully paid (' . $paid_text . ').'); } catch (Exception $e) { /* noop */ }
        } else {
            $order->add_order_note('Payment requests fully paid (' . $paid_text . ').');
        }
        $order->save();
    }
}

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
    if ($email === '') { $email = method_exists($order,'get_billing_email') ? (string) $order->get_billing_email() : ''; }
    if ($email === '' && method_exists($order,'get_user_id')) { $uid = (int) $order->get_user_id(); if ($uid) { $u = get_user_by('id', $uid); if ($u && !empty($u->user_email)) { $email = (string) $u->user_email; } } }
    $mode  = in_array(($_POST['mode'] ?? 'grand'), array('grand','itemized'), true) ? $_POST['mode'] : 'grand';
    $gw    = isset($_POST['gateway']) ? sanitize_text_field($_POST['gateway']) : 'stripe_live';
    $amount_override = isset($_POST['amount_override']) ? floatval($_POST['amount_override']) : 0.0;
    $auto_process = !empty($_POST['auto_process']);

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
    } else if ($email) {
        // Ensure existing Stripe customer has an email; update if missing
        try {
            $c_resp = wp_remote_get('https://api.stripe.com/v1/customers/' . rawurlencode($stripe_customer_id), array('headers' => array('Authorization' => 'Bearer ' . $secret)));
            if (!is_wp_error($c_resp)) {
                $c_body = json_decode(wp_remote_retrieve_body($c_resp), true);
                $cust_email = is_array($c_body) && !empty($c_body['email']) ? (string) $c_body['email'] : '';
                if ($cust_email === '') {
                    wp_remote_post('https://api.stripe.com/v1/customers/' . rawurlencode($stripe_customer_id), array('headers' => $headers, 'body' => array('email' => $email), 'timeout' => 15));
                }
            }
        } catch (Exception $e) { /* noop */ }
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

    // Validate against remaining balance across all existing requests (non-void)
    $reqs = eao_requests_load($order);
    $gt_override_cents = isset($_POST['grand_total_override_cents']) ? (int) $_POST['grand_total_override_cents'] : 0;
    $order_total_cents = $gt_override_cents > 0 ? $gt_override_cents : (int) round(((float) $order->get_total()) * 100);
    $already_requested_cents = eao_requests_sum_requested_cents($reqs);
    $requested_cents_target = ($amount_override > 0.0)
        ? (int) round($amount_override * 100)
        : (int) round(((float) $order->get_total()) * 100);
    $remaining_cents = max(0, $order_total_cents - $already_requested_cents);
    if ($requested_cents_target <= 0) {
        wp_send_json_error(array('message' => 'Requested amount must be greater than zero.'));
    }
    if ($requested_cents_target > $remaining_cents) {
        wp_send_json_error(array('message' => 'Requested amount exceeds remaining balance of $' . number_format($remaining_cents/100, 2)));
    }

    // Optional: reuse open invoice with same remaining amount (single-invoice reuse)
    $existing_invoice_id = (string) $order->get_meta('_eao_stripe_invoice_id', true);
    $expected_cents = $requested_cents_target;
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
                        wp_send_json_success(array('invoice_id' => $send_body['id'], 'status' => $send_body['status'], 'url' => ($send_body['hosted_invoice_url'] ?? ''), 'message' => 'Payment request sent.', 'sent_to' => $email));
                    }
                }
            }
        }
    }

    // Create invoice items
    if ($mode === 'grand') {
        $amount_cents = $requested_cents_target;
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
    $order->update_meta_data('_eao_stripe_payment_mode', ($gw === 'stripe_live') ? 'live' : 'test');
    $order->save();
    $order->add_order_note('Stripe payment request sent. Invoice #' . ($sent['number'] ?? $sent['id']));

    // Track in multi-request ledger
    $reqs[] = array(
        'gateway' => 'stripe',
        'invoice_id' => (string) $sent['id'],
        'amount_cents' => (int) $expected_cents,
        'currency' => strtoupper($currency),
        'state' => 'open',
        'url' => (string) ($sent['hosted_invoice_url'] ?? ''),
        'auto_process_on_full' => $auto_process ? true : false,
        'created_at' => time(),
    );
    eao_requests_save($order, $reqs);
    $order->save();

    // Remaining after this request
    $order_total_cents2 = $gt_override_cents > 0 ? $gt_override_cents : (int) round(((float) $order->get_total()) * 100);
    $remaining_after = max(0, $order_total_cents2 - eao_requests_sum_requested_cents($reqs));

    wp_send_json_success(array(
        'invoice_id' => $sent['id'],
        'status' => $sent['status'] ?? 'open',
        'url' => ($sent['hosted_invoice_url'] ?? ''),
        'sent_to' => $email,
        'amount_cents' => (int) $expected_cents,
        'currency' => strtoupper($currency),
        'remaining_cents' => $remaining_after
    ));
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
    // Mark ledger entry as void if present
    $reqs = eao_requests_load($order);
    $idx = eao_requests_find_index($reqs, 'stripe', $invoice_id);
    if ($idx >= 0) { $reqs[$idx]['state'] = 'void'; eao_requests_save($order, $reqs); $order->save(); }
    // Remaining after void
    $order_total_cents2 = (int) round(((float) $order->get_total()) * 100);
    $remaining_after = max(0, $order_total_cents2 - eao_requests_sum_requested_cents($reqs));
    wp_send_json_success(array('invoice_id' => $invoice_id, 'status' => ($body['status'] ?? 'void'), 'remaining_cents' => $remaining_after));
}

/** Fetch Stripe invoice status and update order meta */
function eao_stripe_get_invoice_status() {
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
    if ($invoice_id === '') { wp_send_json_error(array('message' => 'No Stripe invoice on record.')); }
    $gw    = isset($_POST['gateway']) ? sanitize_text_field($_POST['gateway']) : 'stripe_live';
    $opts = get_option('eao_stripe_settings', array());
    $secret = ($gw === 'stripe_live') ? ($opts['live_secret'] ?? '') : ($opts['test_secret'] ?? '');
    if (empty($secret)) { wp_send_json_error(array('message' => 'Stripe secret missing')); }
    $resp = wp_remote_get('https://api.stripe.com/v1/invoices/' . rawurlencode($invoice_id), array('headers' => array('Authorization' => 'Bearer ' . $secret)));
    if (is_wp_error($resp)) { wp_send_json_error(array('message' => 'Stripe: status fetch failed: '.$resp->get_error_message())); }
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if (empty($body['id'])) { wp_send_json_error(array('message' => 'Stripe: status fetch failed', 'stripe' => $body)); }
    $status = (string) ($body['status'] ?? 'open');
    $url = (string) ($body['hosted_invoice_url'] ?? '');
    $order->update_meta_data('_eao_stripe_invoice_status', $status);
    if ($url !== '') { 
        $order->update_meta_data('_eao_stripe_invoice_url', $url);
        // Update ledger entry with url
        $reqs = eao_requests_load($order);
        $idx = eao_requests_find_index($reqs, 'stripe', $invoice_id);
        if ($idx >= 0) { $reqs[$idx]['url'] = $url; eao_requests_save($order, $reqs); }
    }
    // Try to persist payment intent and charge id for refunds
    $pi_id = (string) ($body['payment_intent'] ?? '');
    if ($pi_id !== '') {
        $order->update_meta_data('_eao_stripe_payment_intent_id', $pi_id);
        try {
            $pi_resp = wp_remote_get('https://api.stripe.com/v1/payment_intents/' . rawurlencode($pi_id), array('headers' => array('Authorization' => 'Bearer ' . $secret)));
            if (!is_wp_error($pi_resp)) {
                $pi = json_decode(wp_remote_retrieve_body($pi_resp), true);
                $charge_id = '';
                if (!empty($pi['latest_charge'])) { $charge_id = (string) $pi['latest_charge']; }
                if ($charge_id === '' && !empty($pi['charges']['data'][0]['id'])) { $charge_id = (string) $pi['charges']['data'][0]['id']; }
                if ($charge_id !== '') {
                    $order->update_meta_data('_eao_stripe_charge_id', $charge_id);
                    if (method_exists($order, 'set_transaction_id')) { $order->set_transaction_id($charge_id); }
                    $order->update_meta_data('_transaction_id', $charge_id);
                }
            }
        } catch (Exception $e) { /* noop */ }
    }
    $order->save();
    if (strtolower($status) === 'paid') {
        // Mark ledger entry as paid and maybe auto-advance
        $reqs = eao_requests_load($order);
        $idx = eao_requests_find_index($reqs, 'stripe', $invoice_id);
        if ($idx >= 0) {
            $reqs[$idx]['state'] = 'paid';
            $reqs[$idx]['paid_at'] = time();
            if (!empty($body['amount_paid'])) {
                $reqs[$idx]['amount_cents'] = (int) $body['amount_paid'];
                if (!empty($body['currency'])) { $reqs[$idx]['currency'] = strtoupper((string)$body['currency']); }
            }
            eao_requests_save($order, $reqs);
        }
        $paid_cents = isset($body['amount_paid']) ? (int) $body['amount_paid'] : 0;
        $paid_text = $paid_cents ? (' ($' . number_format($paid_cents/100, 2) . ')') : '';
        $order->add_order_note('Stripe invoice paid' . $paid_text . ' (manual refresh).');
        // Persist gateway identity for refunds/labels
        $order->update_meta_data('_eao_payment_gateway', ($gw === 'stripe_live') ? 'stripe_live' : 'stripe_test');
        $order->update_meta_data('_payment_method', ($gw === 'stripe_live') ? 'eao_stripe_live' : 'eao_stripe_test');
        $order->update_meta_data('_payment_method_title', 'Stripe (EAO ' . (($gw === 'stripe_live') ? 'Live' : 'Test') . ')');
        $order->save();
        eao_requests_maybe_auto_advance_status($order);
    }
    // Persist paid amount when available
    if (!empty($body['amount_paid'])) {
        $order->update_meta_data('_eao_last_charged_amount_cents', (int) $body['amount_paid']);
        if (!empty($body['currency'])) { $order->update_meta_data('_eao_last_charged_currency', strtoupper((string)$body['currency'])); }
        $order->save();
    }
    $paid_cents = isset($body['amount_paid']) ? (int) $body['amount_paid'] : 0;
    $currency   = !empty($body['currency']) ? strtoupper((string) $body['currency']) : '';
    wp_send_json_success(array('invoice_id' => $invoice_id, 'status' => $status, 'url' => $url, 'paid_cents' => $paid_cents, 'currency' => $currency));
}

/** Save PayPal live credentials */
function eao_payment_paypal_save_settings() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'eao_payment_mockup')) {
        wp_send_json_error(array('message' => 'Nonce verification failed.'));
    }
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Permission denied.'));
    }
    $client_id = isset($_POST['live_client_id']) ? sanitize_text_field(wp_unslash($_POST['live_client_id'])) : '';
    $secret = isset($_POST['live_secret']) ? sanitize_text_field(wp_unslash($_POST['live_secret'])) : '';
    $webhook_id = isset($_POST['webhook_id_live']) ? sanitize_text_field(wp_unslash($_POST['webhook_id_live'])) : '';
    $existing = get_option('eao_paypal_settings', array());
    $merged = array_merge($existing, array(
        'live_client_id' => $client_id,
        'live_secret' => $secret,
        'webhook_id_live' => $webhook_id,
    ));
    update_option('eao_paypal_settings', $merged, false);
    wp_send_json_success(array('saved' => true));
}

/** Save webhook settings (Stripe signing secret live and PayPal webhook ID live) */
function eao_payment_webhooks_save_settings() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'eao_payment_mockup')) {
        wp_send_json_error(array('message' => 'Nonce verification failed.'));
    }
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Permission denied.'));
    }
    $stripe_secret = isset($_POST['stripe_webhook_signing_secret_live']) ? sanitize_text_field(wp_unslash($_POST['stripe_webhook_signing_secret_live'])) : '';
    $stripe_secret_stg = isset($_POST['stripe_webhook_signing_secret_staging']) ? sanitize_text_field(wp_unslash($_POST['stripe_webhook_signing_secret_staging'])) : '';
    $paypal_webhook_id = isset($_POST['paypal_webhook_id_live']) ? sanitize_text_field(wp_unslash($_POST['paypal_webhook_id_live'])) : '';
    $paypal_webhook_id_stg = isset($_POST['paypal_webhook_id_staging']) ? sanitize_text_field(wp_unslash($_POST['paypal_webhook_id_staging'])) : '';
    $env = isset($_POST['webhook_env']) ? sanitize_text_field(wp_unslash($_POST['webhook_env'])) : '';

    // Update Stripe option
    $s = get_option('eao_stripe_settings', array());
    if ($stripe_secret !== '') { $s['webhook_signing_secret_live'] = $stripe_secret; }
    if ($stripe_secret_stg !== '') { $s['webhook_signing_secret_staging'] = $stripe_secret_stg; }
    update_option('eao_stripe_settings', $s, false);

    // Update PayPal option
    $p = get_option('eao_paypal_settings', array());
    if ($paypal_webhook_id !== '') { $p['webhook_id_live'] = $paypal_webhook_id; }
    if ($paypal_webhook_id_stg !== '') { $p['webhook_id_staging'] = $paypal_webhook_id_stg; }
    update_option('eao_paypal_settings', $p, false);

    if (in_array($env, array('live','staging'), true)) { update_option('eao_webhook_env', $env, false); }

    wp_send_json_success(array('saved' => true));
}

/** Save Stripe-only webhook settings (URLs, secrets, env) */
function eao_payment_stripe_webhooks_save() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'eao_payment_mockup')) {
        wp_send_json_error(array('message' => 'Nonce verification failed.'));
    }
    if (!current_user_can('manage_woocommerce')) { wp_send_json_error(array('message' => 'Permission denied.')); }
    $live_url = isset($_POST['webhook_url_live']) ? esc_url_raw(wp_unslash($_POST['webhook_url_live'])) : '';
    $stg_url = isset($_POST['webhook_url_staging']) ? esc_url_raw(wp_unslash($_POST['webhook_url_staging'])) : '';
    $live_secret = isset($_POST['webhook_signing_secret_live']) ? sanitize_text_field(wp_unslash($_POST['webhook_signing_secret_live'])) : '';
    $stg_secret = isset($_POST['webhook_signing_secret_staging']) ? sanitize_text_field(wp_unslash($_POST['webhook_signing_secret_staging'])) : '';
    $env = isset($_POST['webhook_env']) ? sanitize_text_field(wp_unslash($_POST['webhook_env'])) : '';
    $s = get_option('eao_stripe_settings', array());
    if ($live_url !== '') { $s['webhook_url_live'] = $live_url; }
    if ($stg_url !== '') { $s['webhook_url_staging'] = $stg_url; }
    if ($live_secret !== '') { $s['webhook_signing_secret_live'] = $live_secret; }
    if ($stg_secret !== '') { $s['webhook_signing_secret_staging'] = $stg_secret; }
    update_option('eao_stripe_settings', $s, false);
    if (in_array($env, array('live','staging'), true)) { update_option('eao_webhook_env', $env, false); }
    wp_send_json_success(array('saved' => true));
}

/** Save PayPal-only webhook settings (URLs, ids, env) */
function eao_payment_paypal_webhooks_save() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'eao_payment_mockup')) {
        wp_send_json_error(array('message' => 'Nonce verification failed.'));
    }
    if (!current_user_can('manage_woocommerce')) { wp_send_json_error(array('message' => 'Permission denied.')); }
    $live_url = isset($_POST['webhook_url_live']) ? esc_url_raw(wp_unslash($_POST['webhook_url_live'])) : '';
    $stg_url = isset($_POST['webhook_url_staging']) ? esc_url_raw(wp_unslash($_POST['webhook_url_staging'])) : '';
    $live_id = isset($_POST['webhook_id_live']) ? sanitize_text_field(wp_unslash($_POST['webhook_id_live'])) : '';
    $stg_id = isset($_POST['webhook_id_staging']) ? sanitize_text_field(wp_unslash($_POST['webhook_id_staging'])) : '';
    $env = isset($_POST['webhook_env']) ? sanitize_text_field(wp_unslash($_POST['webhook_env'])) : '';
    $p = get_option('eao_paypal_settings', array());
    if ($live_url !== '') { $p['webhook_url_live'] = $live_url; }
    if ($stg_url !== '') { $p['webhook_url_staging'] = $stg_url; }
    if ($live_id !== '') { $p['webhook_id_live'] = $live_id; }
    if ($stg_id !== '') { $p['webhook_id_staging'] = $stg_id; }
    update_option('eao_paypal_settings', $p, false);
    if (in_array($env, array('live','staging'), true)) { update_option('eao_webhook_env', $env, false); }
    wp_send_json_success(array('saved' => true));
}

/**
 * ===============================
 * PayPal (Live): OAuth + Invoicing (Send/Void/Status)
 * ===============================
 */

/** Obtain OAuth access token (live) */
function eao_paypal_get_oauth_token() {
    $pp = get_option('eao_paypal_settings', array());
    $client_id = isset($pp['live_client_id']) ? trim((string) $pp['live_client_id']) : '';
    $secret    = isset($pp['live_secret']) ? trim((string) $pp['live_secret']) : '';
    if ($client_id === '' || $secret === '') {
        return new WP_Error('missing_keys', 'PayPal live client id/secret missing');
    }
    $cached = get_transient('eao_paypal_access_token_live');
    if (is_array($cached) && !empty($cached['token']) && !empty($cached['exp']) && time() < (int) $cached['exp']) {
        return (string) $cached['token'];
    }
    $headers = array(
        'Authorization' => 'Basic ' . base64_encode($client_id . ':' . $secret),
        'Content-Type'  => 'application/x-www-form-urlencoded',
        'Accept'        => 'application/json'
    );
    $resp = wp_remote_post('https://api-m.paypal.com/v1/oauth2/token', array(
        'headers' => $headers,
        'body'    => 'grant_type=client_credentials',
        'timeout' => 25,
    ));
    if (is_wp_error($resp)) { return $resp; }
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if (!is_array($body) || empty($body['access_token'])) { return new WP_Error('oauth_failed', 'PayPal OAuth failed'); }
    $token = (string) $body['access_token'];
    $ttl   = isset($body['expires_in']) ? max(60, (int) $body['expires_in'] - 60) : 300;
    set_transient('eao_paypal_access_token_live', array('token' => $token, 'exp' => time() + $ttl), $ttl);
    return $token;
}

/** Create and send a PayPal invoice (live only) */
function eao_paypal_send_payment_request() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'eao_payment_mockup')) {
        wp_send_json_error(array('message' => 'Nonce verification failed.'));
    }
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Permission denied.'));
    }
    $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    $order = $order_id ? wc_get_order($order_id) : null;
    if (!$order) { wp_send_json_error(array('message' => 'Order not found')); }

    // Allow multiple requests: validate against remaining balance across all non-void requests

    $email = sanitize_email($_POST['email'] ?? '');
    if ($email === '') { $email = method_exists($order,'get_billing_email') ? (string) $order->get_billing_email() : ''; }
    $mode  = in_array(($_POST['mode'] ?? 'grand'), array('grand','itemized'), true) ? $_POST['mode'] : 'grand';
    $amount_override = isset($_POST['amount_override']) ? floatval($_POST['amount_override']) : 0.0;
    $auto_process = !empty($_POST['auto_process']);

    $currency = strtoupper(method_exists($order,'get_currency') ? (string) $order->get_currency() : (get_woocommerce_currency('USD') ?: 'USD'));
    $amount_value = ($amount_override > 0.0) ? $amount_override : (float) $order->get_total();
    // Validate requested amount against remaining balance
    $reqs = eao_requests_load($order);
    $gt_override_cents = isset($_POST['grand_total_override_cents']) ? (int) $_POST['grand_total_override_cents'] : 0;
    $order_total_cents = $gt_override_cents > 0 ? $gt_override_cents : (int) round(((float) $order->get_total()) * 100);
    $already_requested_cents = eao_requests_sum_requested_cents($reqs);
    $requested_cents_target = (int) round($amount_value * 100);
    $remaining_cents = max(0, $order_total_cents - $already_requested_cents);
    if ($requested_cents_target <= 0) {
        wp_send_json_error(array('message' => 'Requested amount must be greater than zero.'));
    }
    if ($requested_cents_target > $remaining_cents) {
        wp_send_json_error(array('message' => 'Requested amount exceeds remaining balance of $' . number_format($remaining_cents/100, 2)));
    }

    $token = eao_paypal_get_oauth_token();
    if (is_wp_error($token)) { wp_send_json_error(array('message' => 'PayPal auth failed: ' . $token->get_error_message())); }

    $headers = array(
        'Authorization' => 'Bearer ' . $token,
        'Content-Type'  => 'application/json',
        'Accept'        => 'application/json',
        'Prefer'        => 'return=representation'
    );

    // Compose invoice items
    $items = array();
    if ($mode === 'grand') {
        // Single grand total line
        $items[] = array(
            'name' => 'Order #' . $order_id,
            'quantity' => '1',
            'unit_amount' => array('currency_code' => $currency, 'value' => number_format($amount_value, 2, '.', '')),
        );
    } else {
        // Itemized: products + shipping + fees, then an adjustment to match staged grand total
        $sum_created = 0.0;
        foreach ($order->get_items('line_item') as $item) {
            $qty = (int) $item->get_quantity();
            $line_total = (float) $item->get_total() + (float) $item->get_total_tax();
            if ($line_total <= 0 || $qty <= 0) { continue; }
            $unit = round($line_total / max(1,$qty), 2);
            $name = $item->get_name();
            $items[] = array(
                'name' => $name,
                'quantity' => (string) $qty,
                'unit_amount' => array('currency_code' => $currency, 'value' => number_format($unit, 2, '.', '')),
            );
            $sum_created += $unit * $qty;
        }
        // Shipping
        $shipping_total = (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();
        if ($shipping_total > 0) {
            $items[] = array(
                'name' => 'Shipping',
                'quantity' => '1',
                'unit_amount' => array('currency_code' => $currency, 'value' => number_format($shipping_total, 2, '.', '')),
            );
            $sum_created += $shipping_total;
        }
        // Positive fees
        foreach ($order->get_fees() as $fee) {
            $fee_total = (float) $fee->get_total() + (float) $fee->get_total_tax();
            if ($fee_total <= 0) { continue; }
            $items[] = array(
                'name' => 'Fee: ' . $fee->get_name(),
                'quantity' => '1',
                'unit_amount' => array('currency_code' => $currency, 'value' => number_format($fee_total, 2, '.', '')),
            );
            $sum_created += $fee_total;
        }
        // Adjustment delta to include coupons/points so PayPal total matches staged grand total
        $expected = $amount_value;
        $delta = round($expected - $sum_created, 2);
        if (abs($delta) >= 0.01) {
            $items[] = array(
                'name' => ($delta < 0 ? 'Discount/Adjustment' : 'Adjustment'),
                'quantity' => '1',
                'unit_amount' => array('currency_code' => $currency, 'value' => number_format($delta, 2, '.', '')),
            );
        }
    }

    $create_body = array(
        'detail' => array(
            'currency_code' => $currency,
            'reference' => 'EAO-' . $order_id,
            'note' => 'Please complete payment for Order #' . $order_id . '. Thank you.',
            'payment_term' => array('term_type' => 'DUE_ON_RECEIPT')
        ),
        'primary_recipients' => array(array('billing_info' => array('email_address' => $email))),
        'items' => $items,
    );

    $create_url = 'https://api-m.paypal.com/v2/invoicing/invoices';
    $resp = wp_remote_post($create_url, array(
        'headers' => $headers,
        'body'    => wp_json_encode($create_body),
        'timeout' => 25,
    ));
    if (is_wp_error($resp)) { wp_send_json_error(array('message' => 'PayPal create failed: ' . $resp->get_error_message())); }
    $http_code = (int) wp_remote_retrieve_response_code($resp);
    $created = json_decode(wp_remote_retrieve_body($resp), true);
    $inv_id = '';
    if (is_array($created) && !empty($created['id'])) { $inv_id = (string) $created['id']; }
    if ($inv_id === '') {
        $location = wp_remote_retrieve_header($resp, 'location');
        if ($location && preg_match('~/invoicing/invoices/([^/]+)$~', $location, $m)) { $inv_id = $m[1]; }
    }
    if ($inv_id === '' && is_array($created)) {
        // Some responses contain a single link object or an array of links
        if (!empty($created['href']) && preg_match('~/invoicing/invoices/([^/]+)$~', (string) $created['href'], $m)) {
            $inv_id = $m[1];
        } elseif (!empty($created[0]['href']) && preg_match('~/invoicing/invoices/([^/]+)$~', (string) $created[0]['href'], $m)) {
            $inv_id = $m[1];
        } elseif (!empty($created['links']) && is_array($created['links'])) {
            foreach ($created['links'] as $lnk) {
                if (!empty($lnk['rel']) && $lnk['rel'] === 'self' && !empty($lnk['href']) && preg_match('~/invoicing/invoices/([^/]+)$~', (string) $lnk['href'], $m)) { $inv_id = $m[1]; break; }
            }
        }
    }
    if ($inv_id === '') {
        $msg = 'PayPal create failed';
        if (is_array($created)) {
            $name = isset($created['name']) ? $created['name'] : '';
            $m = isset($created['message']) ? $created['message'] : '';
            $dbg = isset($created['debug_id']) ? $created['debug_id'] : '';
            if ($name || $m) { $msg .= ': ' . trim($name . ' ' . $m); }
            if ($dbg) { $msg .= ' ['.$dbg.']'; }
        }
        wp_send_json_error(array('message' => $msg, 'paypal' => $created, 'http' => $http_code));
    }

    // Ensure created payload carries id for visibility
    if (!isset($created['id'])) { $created['id'] = $inv_id; }
    // Send invoice
    $send_url = 'https://api-m.paypal.com/v2/invoicing/invoices/' . rawurlencode($inv_id) . '/send';
    $send_headers = $headers; unset($send_headers['Prefer']);
    $send = wp_remote_post($send_url, array(
        'headers' => $send_headers,
        'body'    => wp_json_encode(array('send_to_recipient' => true)),
        'timeout' => 25,
    ));
    if (is_wp_error($send)) { wp_send_json_error(array('message' => 'PayPal send failed: ' . $send->get_error_message())); }
    $send_http = (int) wp_remote_retrieve_response_code($send);
    if ($send_http >= 400) {
        $dbg = wp_remote_retrieve_header($send, 'paypal-debug-id');
        wp_send_json_error(array('message' => 'PayPal send failed (HTTP '.$send_http.')' . ($dbg ? ' ['.$dbg.']' : '')));
    }
    $sent = json_decode(wp_remote_retrieve_body($send), true);
    $status = isset($sent['status']) ? (string) $sent['status'] : 'SENT';

    // Retrieve invoice details to get canonical payer-view link
    $detail_resp = wp_remote_get('https://api-m.paypal.com/v2/invoicing/invoices/' . rawurlencode($inv_id), array(
        'headers' => array('Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'),
        'timeout' => 20,
    ));
    $payer_url = '';
    if (!is_wp_error($detail_resp)) {
        $detail = json_decode(wp_remote_retrieve_body($detail_resp), true);
        if (is_array($detail) && !empty($detail['links']) && is_array($detail['links'])) {
            foreach ($detail['links'] as $lnk) {
                if (!empty($lnk['rel']) && in_array($lnk['rel'], array('payer-view','payment-link'), true) && !empty($lnk['href'])) {
                    $payer_url = (string) $lnk['href'];
                    break;
                }
            }
        }
    }
    if ($payer_url === '') {
        // Fallback pattern (works for most accounts)
        $payer_url = 'https://www.paypal.com/invoice/payerView/details/' . rawurlencode($inv_id);
    }

    $order->update_meta_data('_eao_paypal_invoice_id', $inv_id);
    $order->update_meta_data('_eao_paypal_invoice_status', $status);
    $order->update_meta_data('_eao_paypal_invoice_url', $payer_url);
    $order->save();
    $order->add_order_note('PayPal payment request sent. Invoice ' . $inv_id);

    // Track in multi-request ledger
    $reqs[] = array(
        'gateway' => 'paypal',
        'invoice_id' => $inv_id,
        'amount_cents' => $requested_cents_target,
        'currency' => strtoupper($currency),
        'state' => 'open',
        'url' => $payer_url,
        'auto_process_on_full' => $auto_process ? true : false,
        'created_at' => time(),
    );
    eao_requests_save($order, $reqs);
    $order->save();

    // Remaining after this request
    $order_total_cents2 = $gt_override_cents > 0 ? $gt_override_cents : (int) round(((float) $order->get_total()) * 100);
    $remaining_after = max(0, $order_total_cents2 - eao_requests_sum_requested_cents($reqs));

    wp_send_json_success(array(
        'invoice_id' => $inv_id,
        'status' => $status,
        'url' => $payer_url,
        'sent_to' => $email,
        'amount_cents' => $requested_cents_target,
        'currency' => strtoupper($currency),
        'remaining_cents' => $remaining_after
    ));
}

/** Cancel a PayPal invoice */
function eao_paypal_void_invoice() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'eao_payment_mockup')) {
        wp_send_json_error(array('message' => 'Nonce verification failed.'));
    }
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Permission denied.'));
    }
    $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    $order = $order_id ? wc_get_order($order_id) : null;
    if (!$order) { wp_send_json_error(array('message' => 'Order not found')); }
    $invoice_id = sanitize_text_field($_POST['invoice_id'] ?? (string) $order->get_meta('_eao_paypal_invoice_id', true));
    if ($invoice_id === '') { wp_send_json_error(array('message' => 'No PayPal invoice to void.')); }
    $token = eao_paypal_get_oauth_token();
    if (is_wp_error($token)) { wp_send_json_error(array('message' => 'PayPal auth failed: ' . $token->get_error_message())); }
    $headers = array('Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json');
    $resp = wp_remote_post('https://api-m.paypal.com/v2/invoicing/invoices/' . rawurlencode($invoice_id) . '/cancel', array(
        'headers' => $headers,
        'body'    => wp_json_encode(array('subject' => 'Cancelled', 'note' => 'Cancelled by EAO')),
        'timeout' => 25,
    ));
    if (is_wp_error($resp)) { wp_send_json_error(array('message' => 'PayPal: cancel failed: ' . $resp->get_error_message())); }
    // Update status
    $order->update_meta_data('_eao_paypal_invoice_status', 'CANCELLED');
    $order->save();
    $order->add_order_note('PayPal payment request cancelled. Invoice ' . $invoice_id);
    // Mark ledger entry as void if present
    $reqs = eao_requests_load($order);
    $idx = eao_requests_find_index($reqs, 'paypal', $invoice_id);
    if ($idx >= 0) { $reqs[$idx]['state'] = 'void'; eao_requests_save($order, $reqs); $order->save(); }
    // Remaining after void
    $order_total_cents2 = (int) round(((float) $order->get_total()) * 100);
    $remaining_after = max(0, $order_total_cents2 - eao_requests_sum_requested_cents($reqs));
    wp_send_json_success(array('invoice_id' => $invoice_id, 'status' => 'CANCELLED', 'remaining_cents' => $remaining_after));
}

/** Fetch PayPal invoice status */
function eao_paypal_get_invoice_status() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'eao_payment_mockup')) {
        wp_send_json_error(array('message' => 'Nonce verification failed.'));
    }
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Permission denied.'));
    }
    $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    $order = $order_id ? wc_get_order($order_id) : null;
    if (!$order) { wp_send_json_error(array('message' => 'Order not found')); }
    $invoice_id = sanitize_text_field($_POST['invoice_id'] ?? (string) $order->get_meta('_eao_paypal_invoice_id', true));
    if ($invoice_id === '') { wp_send_json_error(array('message' => 'No PayPal invoice on record.')); }
    $token = eao_paypal_get_oauth_token();
    if (is_wp_error($token)) { wp_send_json_error(array('message' => 'PayPal auth failed: ' . $token->get_error_message())); }
    $headers = array('Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json');
    $resp = wp_remote_get('https://api-m.paypal.com/v2/invoicing/invoices/' . rawurlencode($invoice_id), array('headers' => $headers, 'timeout' => 25));
    if (is_wp_error($resp)) { wp_send_json_error(array('message' => 'PayPal: status fetch failed: ' . $resp->get_error_message())); }
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if (!is_array($body) || empty($body['status'])) { wp_send_json_error(array('message' => 'PayPal: status fetch failed')); }
    
    $status = (string) $body['status'];
    $order->update_meta_data('_eao_paypal_invoice_status', $status);
    if (!empty($body['links']) && is_array($body['links'])) {
        foreach ($body['links'] as $lnk) {
            if (!empty($lnk['rel']) && $lnk['rel'] === 'payer-view' && !empty($lnk['href'])) {
                $order->update_meta_data('_eao_paypal_invoice_url', (string) $lnk['href']);
                break;
            }
        }
    }
    // Also mirror URL into ledger if we have one stored
    $ledger_url = (string) $order->get_meta('_eao_paypal_invoice_url', true);
    if ($ledger_url !== '') {
        $reqs = eao_requests_load($order);
        $idx2 = eao_requests_find_index($reqs, 'paypal', $invoice_id);
        if ($idx2 >= 0) { $reqs[$idx2]['url'] = $ledger_url; eao_requests_save($order, $reqs); }
    }
    // Try to extract paid/total amount from response for UI and notes
    $paid_cents = 0; $currency = '';
    // payment_summary.paid_amount
    if (!empty($body['payment_summary']['paid_amount']['value'])) {
        $paid_cents = (int) round(((float) $body['payment_summary']['paid_amount']['value']) * 100);
        $currency = isset($body['payment_summary']['paid_amount']['currency_code']) ? strtoupper((string) $body['payment_summary']['paid_amount']['currency_code']) : '';
    }
    // payments[0].amount
    if ($paid_cents === 0 && !empty($body['payments']) && is_array($body['payments'])) {
        $p = $body['payments'][0] ?? array();
        if (!empty($p['amount']['value'])) {
            $paid_cents = (int) round(((float) $p['amount']['value']) * 100);
            $currency = isset($p['amount']['currency_code']) ? strtoupper((string) $p['amount']['currency_code']) : '';
        }
    }
    // amount or total_amount fallbacks
    if ($paid_cents === 0 && !empty($body['amount']) && is_array($body['amount']) && isset($body['amount']['value'])) {
        $paid_cents = (int) round(((float) $body['amount']['value']) * 100);
        $currency = isset($body['amount']['currency_code']) ? strtoupper((string) $body['amount']['currency_code']) : '';
    } elseif ($paid_cents === 0 && !empty($body['total_amount']) && is_array($body['total_amount']) && isset($body['total_amount']['value'])) {
        $paid_cents = (int) round(((float) $body['total_amount']['value']) * 100);
        $currency = isset($body['total_amount']['currency_code']) ? strtoupper((string) $body['total_amount']['currency_code']) : '';
    }
    if ($paid_cents > 0) {
        $order->update_meta_data('_eao_last_charged_amount_cents', $paid_cents);
        if ($currency !== '') { $order->update_meta_data('_eao_last_charged_currency', $currency); }
    }
    // Extract and store a refund reference (capture/sale id) when available
    if (!empty($body['payments'])) {
        $cap = '';
        $p = $body['payments'];
        if (is_array($p)) {
            if (!empty($p['transactions']) && is_array($p['transactions'])) {
                foreach ($p['transactions'] as $tx) {
                    if (!empty($tx['payment_id'])) { $cap = (string)$tx['payment_id']; break; }
                    if (!$cap && !empty($tx['transaction_id'])) { $cap = (string)$tx['transaction_id']; break; }
                    if (!$cap && !empty($tx['id'])) { $cap = (string)$tx['id']; break; }
                }
            } else {
                foreach ($p as $tx) {
                    if (!is_array($tx)) { continue; }
                    if (!empty($tx['payment_id'])) { $cap = (string)$tx['payment_id']; break; }
                    if (!$cap && !empty($tx['transaction_id'])) { $cap = (string)$tx['transaction_id']; break; }
                    if (!$cap && !empty($tx['id'])) { $cap = (string)$tx['id']; break; }
                }
            }
        }
        if ($cap !== '') { $order->update_meta_data('_eao_paypal_capture_id', $cap); }
    }
    $order->save();
    if (strtoupper($status) === 'PAID') {
        // Mark ledger entry as paid and maybe auto-advance
        $reqs = eao_requests_load($order);
        $idx = eao_requests_find_index($reqs, 'paypal', $invoice_id);
        if ($idx >= 0) {
            $reqs[$idx]['state'] = 'paid';
            $reqs[$idx]['paid_at'] = time();
            if ($paid_cents > 0) {
                $reqs[$idx]['amount_cents'] = (int) $paid_cents;
                if ($currency !== '') { $reqs[$idx]['currency'] = $currency; }
            }
            eao_requests_save($order, $reqs);
        }
        // Compose amount text if known
        $paid_cents_meta = (int) $order->get_meta('_eao_last_charged_amount_cents', true);
        $paid_text = $paid_cents ? ('$' . number_format($paid_cents/100, 2)) : ($paid_cents_meta ? ('$' . number_format($paid_cents_meta/100, 2)) : '');
        $note = 'PayPal invoice paid' . ($paid_text ? ' (' . $paid_text . ')' : '') . ' (manual refresh).';
        $order->add_order_note($note);
        // Persist gateway identity for refunds/labels
        $order->update_meta_data('_eao_payment_gateway', 'paypal_invoice');
        $order->update_meta_data('_payment_method', 'eao_paypal_invoice');
        $order->update_meta_data('_payment_method_title', 'PayPal (EAO Invoice)');
        $order->save();
        eao_requests_maybe_auto_advance_status($order);
    }
    wp_send_json_success(array('invoice_id' => $invoice_id, 'status' => $status, 'url' => (string) $order->get_meta('_eao_paypal_invoice_url', true), 'paid_cents' => $paid_cents, 'currency' => $currency));
}

/** Helper: find order id by meta key/value */
function eao_find_order_id_by_meta($meta_key, $value) {
    if ($meta_key === '' || $value === '') { return 0; }
    $q = new WP_Query(array(
        'post_type' => array('shop_order','shop_order_placehold'),
        'post_status' => 'any',
        'meta_query' => array(
            array('key' => $meta_key, 'value' => $value)
        ),
        'fields' => 'ids',
        'posts_per_page' => 1
    ));
    if (!is_wp_error($q) && !empty($q->posts)) { return (int) $q->posts[0]; }
    return 0;
}

/** Stripe webhook handler */
function eao_stripe_webhook_handler( WP_REST_Request $request ) {
    $payload = $request->get_body();
    $sig_header = $request->get_header('stripe-signature');
    $settings = get_option('eao_stripe_settings', array());
    $env = get_option('eao_webhook_env', 'live');
    $secret = (string) (($env === 'staging') ? ($settings['webhook_signing_secret_staging'] ?? '') : ($settings['webhook_signing_secret_live'] ?? ''));
    

    // Basic signature verification (tolerance 5 minutes)
    if ($secret !== '' && $sig_header) {
        $parts = array();
        foreach (explode(',', $sig_header) as $seg) {
            $kv = explode('=', trim($seg), 2);
            if (count($kv) === 2) { $parts[$kv[0]] = $kv[1]; }
        }
        $ts = isset($parts['t']) ? (int) $parts['t'] : 0;
        $v1 = isset($parts['v1']) ? $parts['v1'] : '';
        if ($ts < (time() - 300)) { return new WP_REST_Response(array('ok' => false, 'reason' => 'timestamp_too_old'), 400); }
        $signed_payload = $ts . '.' . $payload;
        $expected = hash_hmac('sha256', $signed_payload, $secret);
        if (!hash_equals($expected, $v1)) {
            return new WP_REST_Response(array('ok' => false, 'reason' => 'bad_signature'), 400);
        }
    }

    $event = json_decode($payload, true);
    if (!is_array($event) || empty($event['type'])) { return new WP_REST_Response(array('ok' => false), 400); }

    $type = (string) $event['type'];
    $object = isset($event['data']['object']) ? $event['data']['object'] : array();
    

    // Only handle EAO invoice events
    if (strpos($type, 'invoice.') === 0) {
        $invoice_id = (string) ($object['id'] ?? '');
        $order_id = 0;
        if (!empty($object['metadata']['order_id'])) { $order_id = (int) $object['metadata']['order_id']; }
        if (!$order_id && $invoice_id !== '') { $order_id = eao_find_order_id_by_meta('_eao_stripe_invoice_id', $invoice_id); }
        if ($order_id) {
            $order = wc_get_order($order_id);
            if ($order) {
                // Match against our request ledger (allow multiple invoices)
                $reqs = eao_requests_load($order);
                $idx = eao_requests_find_index($reqs, 'stripe', $invoice_id);
                if ($idx < 0) {
                    // If not found, ignore silently (not an EAO-created invoice)
                    return new WP_REST_Response(array('ok' => true, 'ignored' => 'not_tracked'), 200);
                }
                if ($type === 'invoice.paid') {
                    $order->update_meta_data('_eao_stripe_invoice_status', 'paid');
                    // Persist paid amount if present
                    if (!empty($object['amount_paid'])) {
                        $order->update_meta_data('_eao_last_charged_amount_cents', (int) $object['amount_paid']);
                        if (!empty($object['currency'])) { $order->update_meta_data('_eao_last_charged_currency', strtoupper((string)$object['currency'])); }
                    }
                    // Try to fetch charge id from payment_intent
                    $pi_id = (string) ($object['payment_intent'] ?? '');
                    $settings2 = get_option('eao_stripe_settings', array());
                    $secret2 = (string) ($settings2['live_secret'] ?? '');
                    if ($pi_id !== '' && $secret2 !== '') {
                        try {
                            $pi_resp2 = wp_remote_get('https://api.stripe.com/v1/payment_intents/' . rawurlencode($pi_id), array('headers' => array('Authorization' => 'Bearer ' . $secret2)));
                            if (!is_wp_error($pi_resp2)) {
                                $pi2 = json_decode(wp_remote_retrieve_body($pi_resp2), true);
                                $charge_id2 = '';
                                if (!empty($pi2['latest_charge'])) { $charge_id2 = (string) $pi2['latest_charge']; }
                                if ($charge_id2 === '' && !empty($pi2['charges']['data'][0]['id'])) { $charge_id2 = (string) $pi2['charges']['data'][0]['id']; }
                                if ($charge_id2 !== '') {
                                    $order->update_meta_data('_eao_stripe_payment_intent_id', $pi_id);
                                    $order->update_meta_data('_eao_stripe_charge_id', $charge_id2);
                                    if (method_exists($order, 'set_transaction_id')) { $order->set_transaction_id($charge_id2); }
                                    $order->update_meta_data('_transaction_id', $charge_id2);
                                }
                            }
                        } catch (Exception $e) { /* noop */ }
                    }
                    // Mark this request as paid in the ledger
                    $reqs[$idx]['state'] = 'paid';
                    $reqs[$idx]['paid_at'] = time();
                    if (!empty($object['amount_paid'])) {
                        $reqs[$idx]['amount_cents'] = (int) $object['amount_paid'];
                        $reqs[$idx]['currency'] = strtoupper((string) ($object['currency'] ?? ($reqs[$idx]['currency'] ?? '')));
                    }
                    eao_requests_save($order, $reqs);
                    $order->save();
                    $paid_text = '';
                    $amt_for_note = !empty($reqs[$idx]['amount_cents']) ? (int) $reqs[$idx]['amount_cents'] : (!empty($object['amount_paid']) ? (int) $object['amount_paid'] : 0);
                    if ($amt_for_note > 0) { $paid_text = ' ($' . number_format($amt_for_note/100, 2) . ')'; }
                    $order->add_order_note('Stripe invoice paid' . $paid_text . '.');
                    // Persist gateway identity for refunds/labels
                    $order->update_meta_data('_eao_payment_gateway', 'stripe_live');
                    $order->update_meta_data('_payment_method', 'eao_stripe_live');
                    $order->update_meta_data('_payment_method_title', 'Stripe (EAO Live)');
                    $order->save();
                    // Evaluate auto-advance rule across all requests
                    eao_requests_maybe_auto_advance_status($order);
                } elseif ($type === 'invoice.voided') {
                    $order->update_meta_data('_eao_stripe_invoice_status', 'void');
                    // Mark void in ledger
                    $reqs[$idx]['state'] = 'void';
                    eao_requests_save($order, $reqs);
                    $order->save();
                    $order->add_order_note('Stripe invoice voided via webhook.');
                } elseif ($type === 'invoice.payment_failed') {
                    $order->update_meta_data('_eao_stripe_invoice_status', 'open');
                    $order->save();
                    $order->add_order_note('Stripe invoice payment failed (webhook).');
                }
            }
        }
    }

    return new WP_REST_Response(array('ok' => true));
}

/** PayPal webhook handler */
function eao_paypal_webhook_handler( WP_REST_Request $request ) {
    $body = $request->get_body();
    $headers = array_change_key_case($request->get_headers(), CASE_LOWER);
    $pp = get_option('eao_paypal_settings', array());
    $env = get_option('eao_webhook_env', 'live');
    $webhook_id = (string) (($env === 'staging') ? ($pp['webhook_id_staging'] ?? '') : ($pp['webhook_id_live'] ?? ''));
    
    

    // Obtain headers if needed in the future (no logging in production)
    $tid  = (string) $request->get_header('paypal-transmission-id');

    $verified = false;
    // Verify via PayPal API if webhook id is set
    if ($webhook_id !== '') {
        $token = eao_paypal_get_oauth_token();
        if (!is_wp_error($token)) {
            $verify = array(
                'transmission_id' => $request->get_header('paypal-transmission-id'),
                'transmission_time' => $request->get_header('paypal-transmission-time'),
                'cert_url' => $request->get_header('paypal-cert-url'),
                'auth_algo' => $request->get_header('paypal-auth-algo'),
                'transmission_sig' => $request->get_header('paypal-transmission-sig'),
                'webhook_id' => $webhook_id,
                // Use object form, as PayPal examples expect JSON object semantics
                'webhook_event' => json_decode($body, false)
            );
            $resp = wp_remote_post('https://api-m.paypal.com/v1/notifications/verify-webhook-signature', array(
                'headers' => array('Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'),
                'body' => wp_json_encode($verify),
                'timeout' => 25,
            ));
            if (!is_wp_error($resp)) {
                $res = json_decode(wp_remote_retrieve_body($resp), true);
                $http = (int) wp_remote_retrieve_response_code($resp);
                if (!empty($res['verification_status']) && $res['verification_status'] === 'SUCCESS') {
                    $verified = true;
                }
            }
        }
    }

    $event = json_decode($body, true);
    if (!is_array($event) || empty($event['event_type'])) { return new WP_REST_Response(array('ok' => false), 400); }
    $type = (string) $event['event_type'];
    $resource = isset($event['resource']) ? $event['resource'] : array();
    // Handle both shapes: resource.id/detail and resource.invoice.id/detail
    $invoice_id = '';
    $reference = '';
    if (!empty($resource['id'])) { $invoice_id = (string) $resource['id']; }
    if (!empty($resource['detail']['reference'])) { $reference = (string) $resource['detail']['reference']; }
    if (empty($invoice_id) && !empty($resource['invoice']['id'])) { $invoice_id = (string) $resource['invoice']['id']; }
    if (empty($reference) && !empty($resource['invoice']['detail']['reference'])) { $reference = (string) $resource['invoice']['detail']['reference']; }
    

    // If verification failed, reject the request (no fallback in any environment)
    if (!$verified) { return new WP_REST_Response(array('ok' => false, 'reason' => 'verification_failed'), 400); }
    $order_id = 0;
    if (strpos($reference, 'EAO-') === 0) { $order_id = (int) substr($reference, 4); }
    if (!$order_id && $invoice_id !== '') { $order_id = eao_find_order_id_by_meta('_eao_paypal_invoice_id', $invoice_id); }
    if ($order_id) {
        $order = wc_get_order($order_id);
        if ($order) {
            // Match against our request ledger (allow multiple invoices)
            $reqs = eao_requests_load($order);
            $idx = eao_requests_find_index($reqs, 'paypal', $invoice_id);
            if ($idx < 0) { return new WP_REST_Response(array('ok' => true, 'ignored' => 'not_tracked')); }
                if ($type === 'INVOICING.INVOICE.PAID') {
                $order->update_meta_data('_eao_paypal_invoice_status', 'PAID');
                $order->save();
                $current = method_exists($order,'get_status') ? (string) $order->get_status() : '';
                $is_pending_like = in_array($current, array('pending','pending-payment'), true);
                // Attempt to fetch and persist paid amount
                $paid_text_note = '';
                // Attempt to fetch and persist paid amount
                $token2 = eao_paypal_get_oauth_token();
                if (!is_wp_error($token2)) {
                    $detail_resp2 = wp_remote_get('https://api-m.paypal.com/v2/invoicing/invoices/' . rawurlencode($invoice_id), array('headers' => array('Authorization' => 'Bearer ' . $token2, 'Accept' => 'application/json'), 'timeout' => 15));
                if (!is_wp_error($detail_resp2)) {
                        $d2 = json_decode(wp_remote_retrieve_body($detail_resp2), true);
                    if (is_array($d2)) { /* no log in production */ }
                        if (!empty($d2['amount']['value'])) {
                            $order->update_meta_data('_eao_last_charged_amount_cents', (int) round(((float)$d2['amount']['value']) * 100));
                            if (!empty($d2['amount']['currency_code'])) { $order->update_meta_data('_eao_last_charged_currency', strtoupper((string)$d2['amount']['currency_code'])); }
                            $order->save();
                            $paid_text_note = '$' . number_format((float)$d2['amount']['value'], 2);
                        } elseif (!empty($d2['total_amount']['value'])) {
                            $order->update_meta_data('_eao_last_charged_amount_cents', (int) round(((float)$d2['total_amount']['value']) * 100));
                            if (!empty($d2['total_amount']['currency_code'])) { $order->update_meta_data('_eao_last_charged_currency', strtoupper((string)$d2['total_amount']['currency_code'])); }
                            $order->save();
                            $paid_text_note = '$' . number_format((float)$d2['total_amount']['value'], 2);
                        }
                    // Try to capture the PayPal capture/transaction id for refunding
                    if (empty($order->get_meta('_eao_paypal_capture_id', true))) {
                        $capture_id = '';
                        if (!empty($d2['payments']) && is_array($d2['payments'])) {
                            
                            foreach ($d2['payments'] as $pay) {
                                if (!is_array($pay)) { continue; }
                                if (!empty($pay['transaction_id'])) { $capture_id = (string)$pay['transaction_id']; break; }
                                if (!$capture_id && !empty($pay['paypal_transaction_id'])) { $capture_id = (string)$pay['paypal_transaction_id']; break; }
                                if (!$capture_id && !empty($pay['id'])) { $capture_id = (string)$pay['id']; break; }
                                if (!$capture_id && !empty($pay['reference_id'])) { $capture_id = (string)$pay['reference_id']; break; }
                                if (!$capture_id && !empty($pay['paypal_reference_id'])) { $capture_id = (string)$pay['paypal_reference_id']; break; }
                                if (!$capture_id && !empty($pay['payment_id'])) { $capture_id = (string)$pay['payment_id']; break; }
                            }
                        }
                        if (!$capture_id && isset($d2['payments']['transactions']) && is_array($d2['payments']['transactions'])) {
                            foreach ($d2['payments']['transactions'] as $tx) {
                                if (!empty($tx['payment_id'])) { $capture_id = (string)$tx['payment_id']; break; }
                                if (!$capture_id && !empty($tx['transaction_id'])) { $capture_id = (string)$tx['transaction_id']; break; }
                                if (!$capture_id && !empty($tx['id'])) { $capture_id = (string)$tx['id']; break; }
                            }
                        }
                        if ($capture_id !== '') { $order->update_meta_data('_eao_paypal_capture_id', $capture_id); $order->save(); }
                    }
                    }
                }
                if ($paid_text_note === '') {
                    $pc = (int) $order->get_meta('_eao_last_charged_amount_cents', true);
                    if ($pc > 0) { $paid_text_note = '$' . number_format($pc/100, 2); }
                }
                // Mark ledger entry as paid
                $reqs[$idx]['state'] = 'paid';
                $reqs[$idx]['paid_at'] = time();
                if ($paid_text_note !== '') {
                    $reqs[$idx]['amount_cents'] = (int) $order->get_meta('_eao_last_charged_amount_cents', true);
                    $reqs[$idx]['currency'] = (string) $order->get_meta('_eao_last_charged_currency', true);
                }
                eao_requests_save($order, $reqs);
                $note_text = 'PayPal invoice paid' . ($paid_text_note ? ' (' . $paid_text_note . ')' : '') . '.';
                $order->add_order_note($note_text);
                // Persist gateway identity for refunds/labels
                $order->update_meta_data('_eao_payment_gateway', 'paypal_invoice');
                $order->update_meta_data('_payment_method', 'eao_paypal_invoice');
                $order->update_meta_data('_payment_method_title', 'PayPal (EAO Invoice)');
                $order->save();
                // Evaluate auto-advance rule across all requests
                eao_requests_maybe_auto_advance_status($order);
            } elseif ($type === 'INVOICING.INVOICE.CANCELLED') {
                $order->update_meta_data('_eao_paypal_invoice_status', 'CANCELLED');
                // Mark ledger as void
                $reqs[$idx]['state'] = 'void';
                eao_requests_save($order, $reqs);
                $order->save();
                $order->add_order_note('PayPal invoice cancelled via webhook.');
            } elseif ($type === 'INVOICING.INVOICE.REFUNDED') {
                $order->add_order_note('PayPal invoice refunded via webhook.');
            }
        }
    }

    // Payment capture refund events (maps by stored capture id)
    if (strpos($type, 'PAYMENT.CAPTURE.REFUND') === 0 || $type === 'PAYMENT.CAPTURE.REFUNDED') {
        $capture_id = (string) ($resource['id'] ?? '');
        if ($capture_id !== '') {
            $oid = eao_find_order_id_by_meta('_eao_paypal_capture_id', $capture_id);
            if ($oid) {
                $ord = wc_get_order($oid);
                if ($ord) {
                    $amt_text = '';
                    if (!empty($resource['amount']['value'])) { $amt_text = '$' . number_format((float) $resource['amount']['value'], 2); }
                    $ord->add_order_note('PayPal refund completed' . ($amt_text ? ' (' . $amt_text . ')' : '') . ' via webhook.');
                }
            }
        }
    }

    // Payment capture completed: try to associate capture id to invoice/order for future refunds
    if ($type === 'PAYMENT.CAPTURE.COMPLETED') {
        $cap_id = (string) ($resource['id'] ?? '');
        $related_invoice = (string) ($resource['supplementary_data']['related_ids']['invoice_id'] ?? '');
        
        if ($cap_id !== '' && $related_invoice !== '') {
            $oid = eao_find_order_id_by_meta('_eao_paypal_invoice_id', $related_invoice);
            if ($oid) {
                $ord = wc_get_order($oid);
                if ($ord) { $ord->update_meta_data('_eao_paypal_capture_id', $cap_id); $ord->save(); }
            }
        }
    }

    return new WP_REST_Response(array('ok' => true));
}
