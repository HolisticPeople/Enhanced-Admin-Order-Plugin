<?php
/**
 * Enhanced Admin Order - ShipStation Core Functions
 * Handles all ShipStation integration functionality
 * 
 * @package EnhancedAdminOrder
 * @since 1.8.8
 * @version 2.4.212 - Clean UI: removed verbose connection details box, kept simple Connected status
 * @author Amnon Manneberg
 * 
 * Extracted ShipStation functionality from main plugin file for better modularity.
 * Handles ShipStation API integration, rate fetching, and shipping rate management.
 * 
 * Description: Core ShipStation functionality including meta box rendering, 
 * credentials management, and AJAX handlers for rates processing.
 * 
 * This file contains the main ShipStation integration functions that were
 * extracted from the main plugin file for better modularity.
 * 
 * v1.8.14 Changes: Fixed automatic connection testing and rate display format.
 * - Added automatic connection test on page load (no more popup alerts)
 * - Fixed rate display to use radio buttons with proper formatting
 * - Restored baseline-compatible rate data structure and selection behavior
 * 
 * v1.8.15 Changes: Cosmetic improvements for compact display.
 * - Removed "Available Rates:" heading for more compact layout
 * - Added service name formatting to remove ® and ™ symbols and "- package" suffix
 * - Changed "Connected" status color from blue to green
 * 
 * v1.8.16 Changes: Ultra-compact spacing for single-line rate options.
 * - Reduced padding from 8px to 3px vertically, 2px horizontally
 * - Reduced margin between rate options from 5px to 2px  
 * - Reduced radio button to label spacing from 5px to 3px
 * - Reduced overall container padding from 15px to 8px
 * - Made carrier borders thinner (3px to 2px) and font smaller (12px)
 * - Added line-height: 1.2 for tighter text spacing
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Add ShipStation API Rates meta box.
 * 
 * @since 1.5.8
 * @param WC_Order $order The order object.
 */
function eao_add_shipstation_api_rates_meta_box( $order ) {
    if ( ! is_a( $order, 'WC_Order' ) ) {
        error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] eao_add_shipstation_api_rates_meta_box: Invalid order object.');
        return;
    }
    $screen_id = get_current_screen()->id;
    add_meta_box(
        'eao_shipstation_api_rates_metabox',
        __( 'Shipping Rates', 'enhanced-admin-order' ),
        'eao_render_shipstation_api_rates_meta_box_content',
        $screen_id,
        'side',
        'default',
        array( 'order' => $order )
    );
    error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] eao_shipstation_api_rates_metabox added.');
}

/**
 * Get ShipStation API credentials from EAO custom storage only.
 * 
 * @since 1.5.8
 * @version 2.4.188 - Removed fallback authentication system for reliability
 * @return array API credentials with keys: api_key, api_secret, source
 */
function eao_get_shipstation_api_credentials() {
    $settings = array(
        'api_key' => '',
        'api_secret' => '',
        'source' => 'none'
    );
    
    // Get credentials from EAO custom option only - NO FALLBACKS
    $saved_credentials = get_option('eao_shipstation_v2_api_credentials');
    if (is_array($saved_credentials) && 
        !empty($saved_credentials['api_key']) && 
        !empty($saved_credentials['api_secret'])) {
        $settings['api_key'] = sanitize_text_field($saved_credentials['api_key']);
        $settings['api_secret'] = sanitize_text_field($saved_credentials['api_secret']);
        $settings['source'] = 'eao_custom';
        return $settings;
    }

    // Check if we should migrate from WooCommerce ShipStation plugin (one-time migration)
    if (empty($saved_credentials)) {
        $shipstation_plugin_settings = get_option('woocommerce_wc-shipstation-shipping_settings');
        if (is_array($shipstation_plugin_settings) && 
            !empty($shipstation_plugin_settings['apiKey']) && 
            !empty($shipstation_plugin_settings['apiSecret'])) {
            
            // Migrate credentials to EAO system
            $migrated_credentials = array(
                'api_key' => sanitize_text_field($shipstation_plugin_settings['apiKey']),
                'api_secret' => sanitize_text_field($shipstation_plugin_settings['apiSecret'])
            );
            
            update_option('eao_shipstation_v2_api_credentials', $migrated_credentials);
            error_log('[EAO Plugin] Migrated ShipStation credentials from WooCommerce plugin to EAO system');
            
            $settings['api_key'] = $migrated_credentials['api_key'];
            $settings['api_secret'] = $migrated_credentials['api_secret'];
            $settings['source'] = 'migrated_from_wc';
            return $settings;
        }
    }
    
    return $settings;
}

/**
 * Renders the content for the ShipStation API Rates meta box.
 *
 * @since 1.5.8
 * @param mixed $post_or_order_object Can be WP_Post or WC_Order.
 * @param array $metabox_args Arguments passed from add_meta_box.
 */
function eao_render_shipstation_api_rates_meta_box_content( $post_or_order_object, $metabox_args ) {
    // Ensure we have the WC_Order object
    $order = null;
    if ( isset($metabox_args['args']['order']) && is_a($metabox_args['args']['order'], 'WC_Order') ) {
        $order = $metabox_args['args']['order'];
    } elseif ( is_a($post_or_order_object, 'WC_Order') ) {
        $order = $post_or_order_object;
    } elseif ( is_object($post_or_order_object) && isset($post_or_order_object->ID) ) {
        $order = wc_get_order($post_or_order_object->ID);
    }

        if ( ! $order ) {
        echo '<p>' . esc_html__( 'Order context not available for ShipStation Rates.', 'enhanced-admin-order' ) . '</p>';
            return;
    }
    
    $credentials = eao_get_shipstation_api_credentials();
    $api_key_present = !empty($credentials['api_key']);
    $api_secret_present = !empty($credentials['api_secret']);
    $order_id = $order->get_id();
    $nonce = wp_create_nonce('eao_shipstation_v2_nonce');

    if ( ! $order->has_shipping_address() && $order->needs_shipping_address()) {
        echo '<p>' . esc_html__( 'No shipping address found. Please add a shipping address to the order.', 'enhanced-admin-order' ) . '</p>';
        return;
    }

    $currency_symbol = get_woocommerce_currency_symbol();
    ?>
    <div class="eao-meta-box-content">

        <!-- Section 1: Enter Custom Shipping Rate -->
        <div class="eao-custom-rate-section" style="padding-bottom:15px;">
            <h4><?php esc_html_e( 'Enter Custom Shipping Rate', 'enhanced-admin-order' ); ?></h4>
            <div style="display: flex; align-items: center; gap: 10px;">
                <span style="font-size: 1.2em;"><?php echo $currency_symbol; ?></span>
                <input type="number" id="eao_custom_shipping_rate_amount" name="eao_custom_shipping_rate_amount" 
                       value="0.00" 
                       step="0.1" class="short wc_input_price" style="width:100px;" placeholder="0.00" />
                
                <button type="button" class="button eao-apply-custom-shipping-rate">
                    <?php esc_html_e( 'Apply Custom Rate', 'enhanced-admin-order' ); ?>
                </button>
                </div>
            </div>

        <!-- Section 2: Get Live ShipStation Rates -->
        <div class="eao-shipstation-live-rates-section" style="margin-top: 20px;">
            <h4><?php esc_html_e( 'Get Live ShipStation Rates', 'enhanced-admin-order' ); ?></h4>
            
            <?php if ( !$api_key_present || !$api_secret_present ) : ?>
                <div style="margin-bottom: 10px; color: #d63638;">
                    <p><strong><?php esc_html_e( 'API credentials missing!', 'enhanced-admin-order' ); ?></strong></p>
                    <p><?php esc_html_e( 'Please add ShipStation API credentials to proceed.', 'enhanced-admin-order' ); ?></p>
                    </div>
                
                <div style="margin-bottom: 10px;">
                    <label for="eao_shipstation_api_key" style="display:block; margin-bottom: 5px;"><?php esc_html_e( 'API Key:', 'enhanced-admin-order' ); ?></label>
            <input type="text" id="eao_shipstation_api_key" style="width: 100%; margin-bottom: 5px;">
                    <label for="eao_shipstation_api_secret" style="display:block; margin-bottom: 5px;"><?php esc_html_e( 'API Secret:', 'enhanced-admin-order' ); ?></label>
            <input type="password" id="eao_shipstation_api_secret" style="width: 100%; margin-bottom: 5px;">
                </div>

                <button type="button" class="button eao-shipstation-connect" data-nonce="<?php echo esc_attr( $nonce ); ?>">
                    <?php esc_html_e( 'Connect to ShipStation', 'enhanced-admin-order' ); ?>
                </button>
            <?php else : ?>
                <div id="eao-shipstation-connection-status" style="margin-bottom: 10px;">
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 5px;">
                        <div>
                            <strong><?php esc_html_e( 'Status:', 'enhanced-admin-order' ); ?></strong> 
                            <span id="eao-connection-status-text" style="font-weight: bold;"><?php esc_html_e( 'Not verified', 'enhanced-admin-order' ); ?></span>
                        </div>
                        <button type="button" class="button eao-shipstation-test-connection"><?php esc_html_e( 'Test Connection', 'enhanced-admin-order' ); ?></button>
                        <button type="button" class="button eao-shipstation-settings-toggle" style="padding: 4px 8px;" title="<?php esc_attr_e( 'ShipStation Settings', 'enhanced-admin-order' ); ?>">
                            <span class="dashicons dashicons-admin-generic" style="line-height: 1; font-size: 16px;"></span>
                        </button>
                    </div>
            </div>
            
                <!-- Hidden credentials form (shown when cog icon clicked) -->
                <div id="eao-shipstation-credentials-form" style="display: none; margin-bottom: 15px; padding: 15px; background: #f8f9fa; border: 1px solid #ddd; border-radius: 4px;">
                    <h4 style="margin-top: 0;"><?php esc_html_e( 'ShipStation API Credentials', 'enhanced-admin-order' ); ?></h4>
                    <div style="margin-bottom: 10px;">
                        <label for="eao_shipstation_api_key_edit" style="display:block; margin-bottom: 5px;"><?php esc_html_e( 'API Key:', 'enhanced-admin-order' ); ?></label>
                        <input type="text" id="eao_shipstation_api_key_edit" style="width: 100%; margin-bottom: 5px;" value="<?php echo esc_attr( $credentials['api_key'] ); ?>">
                        <label for="eao_shipstation_api_secret_edit" style="display:block; margin-bottom: 5px;"><?php esc_html_e( 'API Secret:', 'enhanced-admin-order' ); ?></label>
                        <input type="password" id="eao_shipstation_api_secret_edit" style="width: 100%; margin-bottom: 10px;" value="<?php echo esc_attr( $credentials['api_secret'] ); ?>">
                    </div>
                    <button type="button" class="button button-primary eao-shipstation-update-credentials" data-nonce="<?php echo esc_attr( $nonce ); ?>">
                        <?php esc_html_e( 'Update Credentials', 'enhanced-admin-order' ); ?>
                    </button>
                    <button type="button" class="button eao-shipstation-cancel-edit" style="margin-left: 10px;">
                        <?php esc_html_e( 'Cancel', 'enhanced-admin-order' ); ?>
                    </button>
                </div>
                
                <div id="eao-shipstation-connection-debug" style="display:none; margin-bottom:10px; font-size:12px; background:#f8f8f8; border:1px solid #eee; padding:8px; border-radius:4px; color:#333;"></div>
                
                <!-- Detailed error information -->
                <div id="eao-shipstation-error-details" style="display:none; margin-bottom:10px; font-size:11px; background:#fff3cd; border:1px solid #ffeaa7; padding:8px; border-radius:4px; color:#856404;">
                    <strong><?php esc_html_e( 'Diagnostic Information:', 'enhanced-admin-order' ); ?></strong>
                    <div id="eao-error-details-content"></div>
                </div>
                
                <div>
                     <button type="button" class="button button-primary eao-shipstation-get-rates-action" data-order-id="<?php echo esc_attr( $order_id ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
                        <?php esc_html_e( 'Get Shipping Rates from ShipStation', 'enhanced-admin-order' ); ?>
                    </button>
        </div>
        
                <div class="eao-shipstation-rates-container" style="display: none; margin-top: 15px; border: 1px solid #ddd; border-radius: 4px;">
                    <div class="eao-shipstation-rates-loading" style="text-align: center; display: none; padding: 15px;">
                        <p><em><?php esc_html_e( 'Loading rates...', 'enhanced-admin-order' ); ?></em></p>
            </div>
            
            <div class="eao-shipstation-rates-list" style="max-height: 250px; overflow-y: auto;"></div>
            
                    <div class="eao-shipstation-rates-error" style="color: red; display: none; padding: 15px;"></div>
        </div>

                <!-- Adjustment section -->
                <div class="eao-shipstation-rate-adjustment-section" style="display: none; margin-top: 15px; padding:15px; background-color:#f0f5fa; border:1px solid #c9d8e4; border-radius: 4px;">
                    <h4><?php esc_html_e( 'Adjust Selected Rate', 'enhanced-admin-order' ); ?></h4>
                    <div class="eao-adjustment-options">
                        <label><input type="radio" name="eao_adjustment_type" value="no_adjustment" checked> <?php esc_html_e( 'None', 'enhanced-admin-order' ); ?></label>
                        <label style="margin-left: 10px;"><input type="radio" name="eao_adjustment_type" value="percentage_discount"> <?php esc_html_e( 'Percent', 'enhanced-admin-order' ); ?></label>
                        <label style="margin-left: 10px;"><input type="radio" name="eao_adjustment_type" value="fixed_discount"> <?php esc_html_e( 'Fixed', 'enhanced-admin-order' ); ?></label>
            </div>

                    <div id="eao-adjustment-input-percentage" style="display: none; margin-top: 10px;">
                        <input type="number" id="eao-adjustment-percentage-value" min="0" max="100" step="0.1" style="width: 60px;"> %
        </div>
                    <div id="eao-adjustment-input-fixed" style="display: none; margin-top: 10px;">
                        <?php echo $currency_symbol; ?><input type="number" id="eao-adjustment-fixed-value" min="0" step="0.1" style="width: 80px;">
    </div>
                    <p style="margin-top: 15px;">
                        <strong><?php esc_html_e( 'Final Rate:', 'enhanced-admin-order' ); ?></strong> 
                        <span id="eao-shipstation-final-rate-display" style="font-weight: bold;"></span>
                    </p>
            </div>
            
                <!-- Apply button -->
                <div class="eao-shipstation-rates-apply" style="margin-top: 15px; display: none;">
                <button type="button" class="button button-primary eao-shipstation-apply-rate"
                      data-order-id="<?php echo esc_attr( $order_id ); ?>"
                      data-nonce="<?php echo esc_attr( $nonce ); ?>">
                          <?php esc_html_e( 'Apply Selected ShipStation Rate', 'enhanced-admin-order' ); ?>
                    </button>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <!-- ShipStation JavaScript has been moved to eao-shipstation-metabox.js for better reliability -->
    <?php
}

/**
 * AJAX handler for saving ShipStation API credentials.
 * 
 * @since 1.5.8
 */
add_action('wp_ajax_eao_shipstation_v2_save_credentials', 'eao_ajax_shipstation_v2_save_credentials_handler');
function eao_ajax_shipstation_v2_save_credentials_handler() {
    // Clean any previous output to ensure clean JSON response
    if (ob_get_level()) {
        ob_clean();
    }
    
    check_ajax_referer('eao_shipstation_v2_nonce', 'eao_nonce');
    
    $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
    $api_secret = isset($_POST['api_secret']) ? sanitize_text_field($_POST['api_secret']) : '';
    
    if (empty($api_key) || empty($api_secret)) {
        // Clean output buffer before JSON response
        if (ob_get_level()) {
            ob_clean();
        }
        wp_send_json_error(array('message' => __( 'API Key and Secret are required.', 'enhanced-admin-order' )));
        return;
    }
    
    $test_result = eao_test_shipstation_api_credentials($api_key, $api_secret);
    
    // Clean output buffer before JSON response
    if (ob_get_level()) {
        ob_clean();
    }
    
    if ($test_result['success']) {
        update_option('eao_shipstation_v2_api_credentials', array(
            'api_key' => $api_key,
            'api_secret' => $api_secret
        ));
        wp_send_json_success(array('message' => __( 'Credentials verified and saved.', 'enhanced-admin-order' )));
                } else {
        wp_send_json_error(array('message' => $test_result['message']));
    }
}

/**
 * AJAX handler for testing ShipStation API connection.
 * 
 * @since 1.5.8
 */
add_action('wp_ajax_eao_shipstation_v2_test_connection', 'eao_ajax_shipstation_v2_test_connection_handler');
function eao_ajax_shipstation_v2_test_connection_handler() {
    // Clean any previous output to ensure clean JSON response
    while (ob_get_level()) {
        ob_get_clean();
    }
    
    // Start fresh output buffer
    ob_start();
    
    $start_time = microtime(true);
            // error_log('[EAO ShipStation Debug] Test connection handler started at: ' . date('Y-m-d H:i:s'));
    
    check_ajax_referer('eao_shipstation_v2_nonce', 'eao_nonce');
    
    $credentials = eao_get_shipstation_api_credentials();
    
    // Validate credentials format first
    $validation = eao_validate_shipstation_credentials($credentials);
    if (!$validation['valid']) {
        $end_time = microtime(true);
        error_log('[EAO ShipStation Debug] Validation failed after: ' . ($end_time - $start_time) . ' seconds');
        
        // Clean output buffer before JSON response
        if (ob_get_level()) {
            ob_clean();
        }
        
        wp_send_json_error(array(
            'message' => __('Credential validation failed: ', 'enhanced-admin-order') . implode(', ', $validation['issues']),
            'source' => $validation['source']
        ));
        return;
    }
    
            // error_log('[EAO ShipStation Debug] Starting API test after: ' . (microtime(true) - $start_time) . ' seconds');
    
    $test_result = eao_test_shipstation_api_credentials($credentials['api_key'], $credentials['api_secret']);
    
    $end_time = microtime(true);
    $total_time = $end_time - $start_time;
    error_log('[EAO ShipStation Debug] API test completed after: ' . $total_time . ' seconds');
    
    // Debug logging
    // error_log('[EAO ShipStation Debug] Test result: ' . print_r($test_result, true));
    
    // Clean output buffer before JSON response
    if (ob_get_level()) {
        ob_clean();
    }
    
    if ($test_result['success']) {
        $success_response = array(
            'message' => __('Connection successful.', 'enhanced-admin-order'),
            'source' => $credentials['source'],
            'debug_info' => isset($test_result['debug_info']) ? $test_result['debug_info'] : array(),
            'performance' => array(
                'total_time' => round($total_time, 2),
                'timestamp' => date('Y-m-d H:i:s')
            )
        );
                    // error_log('[EAO ShipStation Debug] Success response: ' . print_r($success_response, true));
        
        // FINAL output buffer cleaning before JSON
        while (ob_get_level()) {
            $final_output = ob_get_clean();
            if (!empty($final_output)) {
                error_log('[EAO ShipStation Debug] FINAL CAPTURED: ' . substr($final_output, 0, 200));
            }
        }
        
        wp_send_json_success($success_response);
    } else {
        $error_response = array(
            'message' => $test_result['message'],
            'source' => $credentials['source'],
            'debug_info' => isset($test_result['debug_info']) ? $test_result['debug_info'] : array(),
            'performance' => array(
                'total_time' => round($total_time, 2),
                'timestamp' => date('Y-m-d H:i:s')
            )
        );
        error_log('[EAO ShipStation Debug] Error response: ' . print_r($error_response, true));
        
        // FINAL output buffer cleaning before JSON
        while (ob_get_level()) {
            $final_output = ob_get_clean();
            if (!empty($final_output)) {
                error_log('[EAO ShipStation Debug] FINAL CAPTURED: ' . substr($final_output, 0, 200));
            }
        }
        
        wp_send_json_error($error_response);
    }
}

/**
 * AJAX handler for getting ShipStation rates.
 * 
 * @since 1.5.8
 */
add_action('wp_ajax_eao_shipstation_v2_get_rates', 'eao_ajax_shipstation_v2_get_rates_handler');
function eao_ajax_shipstation_v2_get_rates_handler() {
    // Clean any previous output to ensure clean JSON response
    while (ob_get_level()) {
        ob_get_clean();
    }
    
    // Start fresh output buffer
    ob_start();
    
    // Robust nonce check and early exit if not valid
    if (!isset($_POST['eao_nonce']) || !wp_verify_nonce(sanitize_text_field(stripslashes($_POST['eao_nonce'])), 'eao_shipstation_v2_nonce')) {
        error_log('[EAO ShipStation AJAX Get Rates] Nonce verification failed.');
        wp_send_json_error(array('message' => __('Security check failed. Please refresh and try again.', 'enhanced-admin-order')), 403);
        return;
    }

    // Ensure order_id is provided and is an integer
    if (!isset($_POST['order_id']) || !ctype_digit((string)$_POST['order_id'])) {
        error_log('[EAO ShipStation AJAX Get Rates] Invalid or missing order_id.');
        wp_send_json_error(array('message' => __('Invalid order ID provided.', 'enhanced-admin-order')), 400);
        return;
    }
    $order_id = intval($_POST['order_id']);

    try {
        $order = wc_get_order($order_id);
        if (!$order) {
            error_log('[EAO ShipStation AJAX Get Rates] Order not found for ID: ' . $order_id);
            wp_send_json_error(array('message' => __('Order not found.', 'enhanced-admin-order')), 404);
            return;
        }

        // Apply transient (unsaved) shipping fields from the request so users can get rates without saving
        $posted_ship_country  = isset($_POST['ship_country'])   ? sanitize_text_field( wp_unslash( $_POST['ship_country'] ) )   : '';
        $posted_ship_postcode = isset($_POST['ship_postcode'])  ? sanitize_text_field( wp_unslash( $_POST['ship_postcode'] ) )  : '';
        $posted_ship_city     = isset($_POST['ship_city'])      ? sanitize_text_field( wp_unslash( $_POST['ship_city'] ) )      : '';
        $posted_ship_state    = isset($_POST['ship_state'])     ? sanitize_text_field( wp_unslash( $_POST['ship_state'] ) )     : '';
        $posted_ship_addr1    = isset($_POST['ship_address_1']) ? sanitize_text_field( wp_unslash( $_POST['ship_address_1'] ) ) : '';
        $posted_ship_addr2    = isset($_POST['ship_address_2']) ? sanitize_text_field( wp_unslash( $_POST['ship_address_2'] ) ) : '';

        if ($posted_ship_country !== '') { $order->set_shipping_country($posted_ship_country); }
        if ($posted_ship_postcode !== '') { $order->set_shipping_postcode($posted_ship_postcode); }
        if ($posted_ship_city !== '') { $order->set_shipping_city($posted_ship_city); }
        if ($posted_ship_state !== '') { $order->set_shipping_state($posted_ship_state); }
        if ($posted_ship_addr1 !== '') { $order->set_shipping_address_1($posted_ship_addr1); }
        if ($posted_ship_addr2 !== '') { $order->set_shipping_address_2($posted_ship_addr2); }

        // Check if order actually needs shipping by examining products
        $needs_shipping = false;
        foreach ($order->get_items() as $item) {
            if ($item instanceof WC_Order_Item_Product) {
                $product = $item->get_product();
                if ($product && $product->needs_shipping()) {
                    $needs_shipping = true;
                    break;
                }
            }
        }
        
        if (!$needs_shipping) {
            error_log('[EAO ShipStation AJAX Get Rates] Order ID ' . $order_id . ' does not contain any products that need shipping.');
            wp_send_json_error(array('message' => __('This order does not require shipping.', 'enhanced-admin-order')));
            return;
        }        

        if (empty($order->get_shipping_postcode()) || empty($order->get_shipping_country())) {
            error_log('[EAO ShipStation AJAX Get Rates] Order ID ' . $order_id . ' is missing essential shipping address components (postcode or country).');
            wp_send_json_error(array('message' => __('Order is missing essential shipping address details (postcode or country). Please complete the shipping address.', 'enhanced-admin-order')));
            return;
        }
        
        $base_request_data = eao_build_shipstation_rates_request($order);
        if (!$base_request_data) {
            error_log('[EAO ShipStation AJAX Get Rates] Could not build rate request data for order ID: ' . $order_id);
            wp_send_json_error(array('message' => __('Could not prepare data for ShipStation. Please check order details.', 'enhanced-admin-order')));
            return;
        }
        
        $default_carriers = array('stamps_com', 'ups_walleted');
        $carriers_to_try_from_filter = apply_filters('eao_shipstation_carriers_to_query', $default_carriers);
        
        if (!is_array($carriers_to_try_from_filter)) {
            error_log('[EAO ShipStation AJAX Get Rates] Filter "eao_shipstation_carriers_to_query" did not return an array. Using default carriers. Value received: ' . print_r($carriers_to_try_from_filter, true));
            $carriers_to_try = $default_carriers; // Fallback to default
        } else {
            $carriers_to_try = $carriers_to_try_from_filter;
        }
        
        $all_rates = array();
        $carrier_errors = array();
        
        foreach ($carriers_to_try as $carrier_code) {
            if (!is_string($carrier_code) || empty(trim($carrier_code))) {
                error_log('[EAO ShipStation AJAX Get Rates] Invalid carrier code found in carriers list: ' . print_r($carrier_code, true));
                continue; // Skip invalid carrier codes
            }
            $request_data = $base_request_data;
            $request_data['carrierCode'] = $carrier_code;
            
            if ($carrier_code === 'ups_walleted' || $carrier_code === 'ups') { 
                $request_data = eao_customize_ups_request($request_data);
            }
            
            $carrier_rates_result = eao_get_shipstation_carrier_rates($request_data);
            
            if (isset($carrier_rates_result['success']) && $carrier_rates_result['success'] && isset($carrier_rates_result['rates']) && is_array($carrier_rates_result['rates']) && !empty($carrier_rates_result['rates'])) {
                foreach ($carrier_rates_result['rates'] as &$rate) {
                    if (is_array($rate) && !isset($rate['carrierCode'])) { 
                        $rate['carrierCode'] = $carrier_code;
                    }
                }
                $all_rates = array_merge($all_rates, $carrier_rates_result['rates']);
            } else {
                $error_message = (isset($carrier_rates_result['message']) && is_string($carrier_rates_result['message'])) ? $carrier_rates_result['message'] : __('Unknown error fetching rates from carrier.', 'enhanced-admin-order');
                $carrier_errors[$carrier_code] = $error_message;
                error_log('[EAO ShipStation AJAX Get Rates] Error for carrier ' . $carrier_code . ': ' . $error_message);
            }
        }
        
        if (empty($all_rates)) {
            $error_details = '';
            if (!empty($carrier_errors)) {
                foreach ($carrier_errors as $carrier => $error) {
                    $carrier_name = eao_get_shipstation_carrier_display_name($carrier);
                    $error_details .= "<strong>" . esc_html($carrier_name) . "</strong>: " . esc_html($error) . "<br>";
                }
            } else {
                $error_details = __('No specific carrier errors reported, but no rates were returned.', 'enhanced-admin-order');
            }
            error_log('[EAO ShipStation AJAX Get Rates] No rates available for order ID: ' . $order_id . '. Errors: ' . $error_details);
            wp_send_json_error(array(
                'message' => __('No shipping rates available.', 'enhanced-admin-order'),
                'error_details' => $error_details
            ));
            return;
        }
        
        $formatted_rates_result = eao_format_shipstation_rates_response($all_rates);
        
        $partial_success_message = '';
        if (!empty($carrier_errors)) { 
            $failed_carriers_messages = array();
            foreach ($carrier_errors as $carrier => $error) {
                $carrier_name = eao_get_shipstation_carrier_display_name($carrier);
                $failed_carriers_messages[] = esc_html($carrier_name) . ": " . esc_html($error);
            }
            if (!empty($failed_carriers_messages)) {
                $partial_success_message = __('Some carriers failed: ', 'enhanced-admin-order') . implode('; ', $failed_carriers_messages);
            }
        }
        
        // FINAL output buffer cleaning before JSON
        while (ob_get_level()) {
            $final_output = ob_get_clean();
            if (!empty($final_output)) {
                error_log('[EAO ShipStation Get Rates] FINAL CAPTURED: ' . substr($final_output, 0, 200));
            }
        }
        
        wp_send_json_success(array(
            'rates' => isset($formatted_rates_result['rates']) ? $formatted_rates_result['rates'] : array(),
            'partial_error' => $partial_success_message 
        ));

    } catch (Throwable $e) { // Catch any PHP 7+ error or exception
        error_log('[EAO ShipStation AJAX Get Rates] CRITICAL ERROR for order ID ' . $order_id . ': ' . $e->getMessage() . "\nStack Trace:\n" . $e->getTraceAsString());
        wp_send_json_error(array(
            'message' => __('A critical server error occurred while fetching rates. Please check the logs or contact support.', 'enhanced-admin-order'),
            'debug_message' => 'Error: ' . $e->getMessage() // Send a generic message to user, but log details
        ), 500);
    }
} 