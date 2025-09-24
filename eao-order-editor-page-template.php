<?php
/**
 * Enhanced Admin Order Plugin - Order Editor Page Template
 * Main template for editing WooCommerce orders in the admin
 * 
 * @package EnhancedAdminOrder
 * @since 1.0.0
 * @version 2.2.13 - ARCHITECTURE COMPLIANCE: Fixed overlay timing to use existing waitForPageAndProductsToLoad system instead of forcing closure.
 * @version 2.7.7 - Added standalone Points Earning section below main content
 * @author Amnon Manneberg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Add CSS for proper layout
?>
<style>
.eao-search-and-discount-row {
    display: flex;
    gap: 20px;
    align-items: flex-start;
}
.eao-search-column, .eao-discount-column {
    flex: 1;
}
.eao-search-column label, .eao-discount-column label {
    display: block;
    margin-bottom: 5px;
    font-weight: 600;
}
.eao-global-discount-input {
    width: 100px;
}

/* Loading Overlay Styles */
.eao-save-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(255, 255, 255, 0.85);
    z-index: 999999;
    display: none;
    backdrop-filter: blur(2px);
}

.eao-save-overlay-content {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    text-align: center;
    background: #fff;
    padding: 40px;
    border-radius: 8px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
    min-width: 300px;
}

.eao-save-spinner {
    width: 50px;
    height: 50px;
    border: 4px solid #f0f0f0;
    border-top: 4px solid #0073aa;
    border-radius: 50%;
    animation: eao-spin 1s linear infinite;
    margin: 0 auto 20px;
}

@keyframes eao-spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.eao-save-overlay h3 {
    margin: 0 0 10px 0;
    color: #23282d;
    font-size: 18px;
    font-weight: 600;
}

.eao-save-overlay p {
    margin: 0;
    color: #666;
    font-size: 14px;
    line-height: 1.5;
}

.eao-save-stage {
    margin-top: 15px;
    font-weight: 500;
    color: #0073aa;
}

/* Success animation */
.eao-save-success {
    color: #46b450 !important;
}

.eao-save-success .eao-save-spinner {
    border-top-color: #46b450;
    animation: eao-spin-success 0.5s ease-out forwards;
}

@keyframes eao-spin-success {
    0% { transform: rotate(0deg) scale(1); }
    50% { transform: rotate(180deg) scale(1.1); }
    100% { transform: rotate(360deg) scale(1); border-top-color: #46b450; border-right-color: #46b450; }
}

.eao-save-checkmark {
    display: none;
    width: 50px;
    height: 50px;
    margin: 0 auto 20px;
    border-radius: 50%;
    background: #46b450;
    position: relative;
}

.eao-save-checkmark:before {
    content: '✓';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    color: white;
    font-size: 24px;
    font-weight: bold;
}

/* Progress Bar Styles */
.eao-progress-container {
    width: 100%;
    height: 8px;
    background-color: #f0f0f0;
    border-radius: 4px;
    margin: 20px 0;
    overflow: hidden;
}

.eao-progress-bar {
    height: 100%;
    background: linear-gradient(90deg, #0073aa, #005a87);
    border-radius: 4px;
    transition: width 0.5s ease;
    width: 0%;
}

.eao-progress-text {
    margin-top: 10px;
    font-size: 12px;
    color: #666;
    font-weight: 500;
}

/* YITH Points Interface Styles */
.eao-yith-points-section {
    margin-top: 20px;
}

.eao-yith-points-container {
    padding: 15px;
}

.eao-points-info {
    max-width: 400px;
}

.eao-points-display {
    background: #f8f9fa;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 15px;
    margin-bottom: 10px;
}

.eao-points-available {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 1px solid #ddd;
}

.eao-points-available label {
    font-weight: 600;
    color: #23282d;
    margin: 0;
}

.eao-points-value {
    font-size: 18px;
    font-weight: bold;
    color: #0073aa;
    background: #fff;
    padding: 5px 10px;
    border-radius: 3px;
    border: 1px solid #0073aa;
}

.eao-points-redemption label {
    display: block;
    font-weight: 600;
    margin-bottom: 8px;
    color: #23282d;
}

.eao-points-input-group {
    display: flex;
    gap: 10px;
    align-items: center;
    margin-bottom: 0;
}

.eao-points-input {
    width: 120px;
    padding: 6px 8px;
    border: 1px solid #ddd;
    border-radius: 3px;
    font-size: 14px;
}

.eao-points-unit {
    color: #666;
    font-size: 14px;
    font-style: italic;
}

.eao-points-conversion {
    margin-top: 5px;
}

.eao-points-conversion-text {
    color: #666;
    font-style: italic;
}

.eao-points-no-balance {
    text-align: center;
    padding: 20px;
    color: #666;
}

.eao-points-guest-notice {
    text-align: center;
    padding: 20px;
    color: #666;
    background: #f8f9fa;
    border: 1px solid #ddd;
    border-radius: 4px;
}

.eao-points-actions {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 15px;
}

.eao-points-award label {
    font-weight: 600;
    color: #23282d;
    display: block;
    margin-bottom: 8px;
}

.eao-award-info {
    color: #666;
    font-size: 13px;
    line-height: 1.4;
}

.eao-award-text {
    font-style: italic;
}

.eao-points-slider-container {
    margin-bottom: 10px;
}

.eao-points-slider {
    width: 100%;
    height: 6px;
    border-radius: 3px;
    background: #ddd;
    outline: none;
    opacity: 0.8;
    transition: opacity 0.2s;
    margin-bottom: 10px;
}

.eao-points-slider:hover {
    opacity: 1;
}

.eao-points-slider::-webkit-slider-thumb {
    appearance: none;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: #0073aa;
    cursor: pointer;
    border: 2px solid #fff;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}

.eao-points-slider::-moz-range-thumb {
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: #0073aa;
    cursor: pointer;
    border: 2px solid #fff;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}

.eao-points-limits {
    margin-bottom: 8px;
}

.eao-points-limit-text {
    color: #666;
    font-size: 12px;
    font-style: italic;
}

.eao-points-input-group {
    display: flex;
    gap: 10px;
    align-items: center;
    margin-bottom: 0;
}

/* Points Earning Display Styles */
.eao-award-display {
    background: #f9f9f9;
    padding: 8px 12px;
    border-radius: 4px;
    border-left: 3px solid #0073aa;
    margin-bottom: 5px;
}

.eao-award-points {
    font-size: 14px;
    color: #0073aa;
}

.eao-award-points strong {
    font-size: 16px;
    font-weight: 600;
}

.eao-award-text {
    color: #666;
    font-size: 13px;
}

.eao-points-breakdown {
    margin-top: 8px;
}

.eao-points-breakdown summary {
    cursor: pointer;
    font-size: 12px;
    color: #666;
    outline: none;
}

.eao-points-breakdown summary:hover {
    color: #0073aa;
}

.eao-points-breakdown[open] summary {
    margin-bottom: 5px;
}

.eao-points-breakdown div {
    margin-top: 5px;
    font-size: 11px;
    background: #f9f9f9;
    padding: 8px;
    border-radius: 3px;
    border: 1px solid #e1e1e1;
}
</style>
<?php

// Ensure $order is an instance of WC_Order
if ( ! $order instanceof WC_Order ) {
    echo '<div class="wrap"><h1>' . esc_html__( 'Error: Invalid Order', 'enhanced-admin-order' ) . '</h1>';
    echo '<p>' . esc_html__( 'A valid order object was not provided.', 'enhanced-admin-order' ) . '</p></div>';
    return;
}

$screen_id = get_current_screen()->id; 
$order_statuses = wc_get_order_statuses();
$current_status = 'wc-' . $order->get_status(); // Ensure it has 'wc-' prefix for consistency with wc_get_order_statuses keys

// Date and Time components
$date_created = $order->get_date_created();
$order_date = $date_created ? $date_created->date( 'Y-m-d' ) : '';
$order_time = $date_created ? $date_created->date( 'H:i' ) : '';

// IMPORTANT: This action hook allows other plugins (and ourselves) to add meta boxes.
// It needs the $screen_id and the $order object (which often stands in for $post in meta box contexts).
do_action( 'add_meta_boxes', $screen_id, $order );

?>
<!-- Loading Overlay -->
<div id="eao-save-overlay" class="eao-save-overlay">
    <div class="eao-save-overlay-content">
        <div class="eao-save-spinner"></div>
        <div class="eao-save-checkmark"></div>
        <h3 id="eao-save-title"><?php esc_html_e( 'Saving Order...', 'enhanced-admin-order' ); ?></h3>
        <p id="eao-save-message"><?php esc_html_e( 'Please wait while we update your order details.', 'enhanced-admin-order' ); ?></p>
        <div id="eao-save-stage" class="eao-save-stage"></div>
        <div class="eao-progress-container">
            <div id="eao-progress-bar" class="eao-progress-bar"></div>
        </div>
        <span id="eao-progress-text" class="eao-progress-text"></span>
    </div>
</div>

<div class="wrap" id="eao-order-editor-wrap">
    <h1><?php printf( esc_html__( 'Enhanced Order Editor - Order #%s', 'enhanced-admin-order' ), esc_html( $order->get_order_number() ) ); ?></h1>
    <p style="font-size: 11px; color: #666; margin: -5px 0 15px 0;">
        <?php printf( esc_html__( 'Plugin Version: %s', 'enhanced-admin-order' ), esc_html( EAO_PLUGIN_VERSION ) ); ?>
    </p>

    <form id="eao-order-form" method="post">
        <?php wp_nonce_field( 'eao_save_order_details', 'eao_order_details_nonce' ); ?>
        <input type="hidden" name="eao_order_id" value="<?php echo esc_attr( $order->get_id() ); ?>">

        <div id="poststuff">
            <div id="post-body" class="metabox-holder columns-2">
                <!-- Main content -->
                <div id="post-body-content">
                    <div class="eao-section order-details-general postbox">
                        <h2 class="hndle"><span><?php esc_html_e( 'Order Details', 'enhanced-admin-order' ); ?></span></h2>
                        <div class="inside">
                            <div class="eao-main-details-columns">
                                <div class="eao-details-column eao-general-details-column">
                                    <p class="form-field form-field-wide eao-datetime-field">
                                        <label for="eao_order_date"><?php esc_html_e( 'Order Date:', 'enhanced-admin-order' ); ?></label>
                                        <span class="eao-datetime-inputs">
                                        <input type="date" id="eao_order_date" name="eao_order_date" value="<?php echo esc_attr( $order_date ); ?>" class="eao-input-date">
                                        <input type="time" id="eao_order_time" name="eao_order_time" value="<?php echo esc_attr( $order_time ); ?>" class="eao-input-time">
                                        </span>
                                        <span class="eao-previous-value eao-previous-datetime-block" id="eao_previous_order_datetime" style="display: none;"></span>
                                    </p>
                                    <p class="form-field form-field-wide">
                                        <label for="eao_order_status"><?php esc_html_e( 'Status:', 'enhanced-admin-order' ); ?></label>
                                        <select id="eao_order_status" name="eao_order_status" class="eao-select wc-enhanced-select">
                                            <?php foreach ( $order_statuses as $status_key => $status_name ) : ?>
                                                <option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( $status_key, $current_status ); ?>><?php echo esc_html( $status_name ); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <span class="eao-previous-value" id="eao_previous_order_status" style="display: none;"></span>
                                    </p>
                                    <div class="form-field form-field-wide eao-customer-section">
                                        <div class="eao-customer-main-line">
                                            <label for="eao_customer_display_name" class="eao-inline-label"><?php esc_html_e( 'Customer:', 'enhanced-admin-order' ); ?></label>
                                            <div id="eao_customer_current_display" class="eao-inline-block">
                                            <span id="eao_customer_display_name_wrapper">
                                                <span id="eao_customer_display_name">
                                                    <?php 
                                                    $customer_name = $order->get_formatted_billing_full_name();
                                                    $customer_id = $order->get_customer_id();
                                                    if ( $customer_id ) {
                                                        $customer_user = get_user_by( 'id', $customer_id );
                                                        $customer_display = $customer_user ? $customer_user->display_name : $customer_name; 
                                                        $customer_link = admin_url( 'user-edit.php?user_id=' . $customer_id );
                                                        echo '<a href="' . esc_url( $customer_link ) . '" target="_blank">' . esc_html( $customer_display ) . '</a> (ID: ' . esc_html( $customer_id ) . ')';
                                                    } else {
                                                        echo esc_html( $customer_name ) . ' (' . esc_html__( 'Guest', 'enhanced-admin-order' ) . ')';
                                                    }
                                                    ?>
                                                </span>
                                            </span>
                                                <button type="button" class="button eao-change-customer-button" id="eao_trigger_change_customer"><?php esc_html_e( 'Change', 'enhanced-admin-order' ); ?></button>
                                            </div>
                                        </div>
                                        <?php 
                                        // Present user roles under the customer display when a customer is assigned
                                        if ( isset($customer_id) && $customer_id ) :
                                            $customer_user_for_roles = get_user_by('id', $customer_id);
                                            $role_names_map = function_exists('wp_roles') ? wp_roles()->get_names() : array();
                                            $role_display_names = array();
                                            if ( $customer_user_for_roles && is_array($customer_user_for_roles->roles) ) {
                                                foreach ( $customer_user_for_roles->roles as $role_slug ) {
                                                    if ( isset($role_names_map[$role_slug]) ) {
                                                        $role_display_names[] = translate_user_role( $role_names_map[$role_slug] );
                                                    } else {
                                                        $role_display_names[] = $role_slug;
                                                    }
                                                }
                                            }
                                        ?>
                                        <div class="eao-customer-roles-line" style="margin-top: 4px;">
                                            <label class="eao-inline-label"><?php esc_html_e('Roles:', 'enhanced-admin-order'); ?></label>
                                            <span class="eao-inline-block"><?php echo !empty($role_display_names) ? esc_html( implode(', ', $role_display_names) ) : esc_html__('None', 'enhanced-admin-order'); ?></span>
                                        </div>
                                        <?php endif; ?>
                                        <div class="eao-customer-previous-line">
                                            <span class="eao-previous-value" id="eao_previous_customer" style="display: none;"></span>
                                        </div>
                                        
                                        <div id="eao_customer_search_ui" style="display: none; margin-top: 10px;">
                                            <input type="search" id="eao_customer_search_term" name="eao_customer_search_term" placeholder="<?php esc_attr_e( 'Search by name, email, or ID...', 'enhanced-admin-order' ); ?>" class="widefat">
                                            <div id="eao_customer_search_results" style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; background: #fff; margin-top: 5px;"></div>
                                            <button type="button" class="button button-secondary eao-cancel-customer-change" id="eao_cancel_change_customer" style="margin-top: 5px; display: none;"><?php esc_html_e( 'Cancel Change', 'enhanced-admin-order' ); ?></button>
                                        </div>
                                        <input type="hidden" name="eao_customer_id" id="eao_customer_id_hidden" value="<?php echo esc_attr( $customer_id ); ?>">
                                    </div>
                                </div>

                                <div class="eao-details-column eao-address-column" id="eao-billing-column">
                                    <h3>
                                        <span><?php esc_html_e( 'Billing Details', 'enhanced-admin-order' ); ?></span>
                                        <div>
                                            <button type="button" class="button eao-save-address-button is-small page-title-action" data-address-type="billing" style="display: none;"><?php esc_html_e( 'Save', 'enhanced-admin-order' ); ?></button>
                                        <button type="button" class="button eao-edit-address-button is-small page-title-action" data-address-type="billing"><?php esc_html_e( 'Edit', 'enhanced-admin-order' ); ?></button>
                                        </div>
                                    </h3>
                                    <div class="eao-address-section" id="eao-billing-address-section">
                                        <div class="eao-address-display-container">
                                            <div class="eao-address-display-current">
                                                <address class="eao-current-address-display">
                                                    <?php 
                                                    $billing_details = $order->get_address('billing');
                                                    $countries = WC()->countries->get_countries();
                                                    $billing_address_parts = [];

                                                    $name_line = trim( (isset($billing_details['first_name']) ? $billing_details['first_name'] : '') . ' ' . (isset($billing_details['last_name']) ? $billing_details['last_name'] : '') );
                                                    if ( !empty($name_line) ) $billing_address_parts[] = esc_html($name_line);
                                                    if ( !empty($billing_details['company']) ) $billing_address_parts[] = esc_html($billing_details['company']);
                                                    if ( !empty($billing_details['address_1']) ) $billing_address_parts[] = esc_html($billing_details['address_1']);
                                                    if ( !empty($billing_details['address_2']) ) $billing_address_parts[] = esc_html($billing_details['address_2']);
                                                    
                                                                                        $city_line = esc_html(isset($billing_details['city']) ? $billing_details['city'] : '');
                                    $state_line = esc_html(isset($billing_details['state']) ? $billing_details['state'] : '');
                                    $postcode_line = esc_html(isset($billing_details['postcode']) ? $billing_details['postcode'] : '');
                                                    
                                                    $city_state_postcode = $city_line;
                                                    if ($state_line) {
                                                        $city_state_postcode .= ($city_line ? ', ' : '') . $state_line;
                                                    }
                                                    if ($postcode_line) {
                                                        $city_state_postcode .= ($city_state_postcode ? ' ' : '') . $postcode_line;
                                                    }
                                                    if (!empty(trim($city_state_postcode))) $billing_address_parts[] = trim($city_state_postcode);

                                                                                        $country_code = isset($billing_details['country']) ? $billing_details['country'] : '';
                                    $country_name = isset($countries[$country_code]) ? $countries[$country_code] : $country_code;
                                                    if ( !empty($country_name) ) $billing_address_parts[] = esc_html($country_name);

                                                    // Email with robust fallbacks
                                                    $billing_email = $order->get_billing_email();
                                                    if ( empty($billing_email) && isset($billing_details['email']) ) {
                                                        $billing_email = $billing_details['email'];
                                                    }
                                                    if ( empty($billing_email) ) {
                                                        $billing_email = $order->get_meta('_billing_email');
                                                    }
                                                    if ( !empty($billing_email) ) {
                                                        $billing_address_parts[] = 'Email: ' . esc_html($billing_email);
                                                    }

                                                    // Phone with robust fallbacks
                                                    $billing_phone = $order->get_billing_phone();
                                                    if ( empty($billing_phone) && isset($billing_details['phone']) ) {
                                                        $billing_phone = $billing_details['phone'];
                                                    }
                                                    if ( empty($billing_phone) ) {
                                                        $billing_phone = $order->get_meta('_billing_phone');
                                                    }
                                                    // Fallback to customer user meta if order does not contain a phone
                                                    $user_meta_phone = '';
                                                    $customer_id_for_phone = $order->get_customer_id();
                                                    if ( empty($billing_phone) && $customer_id_for_phone ) {
                                                        $user_meta_phone = get_user_meta( $customer_id_for_phone, 'billing_phone', true );
                                                        if ( ! empty( $user_meta_phone ) ) {
                                                            $billing_phone = $user_meta_phone;
                                                        }
                                                    }
                                                    // DEBUG: Log sources to confirm why phone may be missing
                                                    if ( defined('WP_DEBUG') && WP_DEBUG ) {
                                                        $billing_debug = array(
                                                            'order_id' => $order->get_id(),
                                                            'getter_phone' => $order->get_billing_phone(),
                                                            'details_phone' => isset($billing_details['phone']) ? $billing_details['phone'] : null,
                                                            'meta_phone' => $order->get_meta('_billing_phone'),
                                                            'user_meta_phone' => $user_meta_phone,
                                                            'final_phone' => $billing_phone,
                                                            'getter_email' => $order->get_billing_email(),
                                                            'details_email' => isset($billing_details['email']) ? $billing_details['email'] : null,
                                                            'meta_email' => $order->get_meta('_billing_email'),
                                                            'final_email' => $billing_email,
                                                        );
                                                        error_log('[EAO Billing Debug] ' . print_r($billing_debug, true));
                                                    }
                                                    if ( !empty($billing_phone) ) {
                                                        $billing_address_parts[] = 'Phone: ' . esc_html($billing_phone);
                                                    }

                                                    if (empty($billing_address_parts)) {
                                                        echo esc_html__( 'N/A', 'enhanced-admin-order' );
                                                    } else {
                                                        echo implode( '<br>', $billing_address_parts );
                                                    }
                                                    // Console debug for front-end confirmation (safe, only logs)
                                                    ?>
                                                    <script type="text/javascript">
                                                        (function(){
                                                            var dbg = {
                                                                context: 'EAO Billing Summary Render',
                                                                orderId: <?php echo json_encode($order->get_id()); ?>,
                                                                phoneFinal: <?php echo json_encode(isset($billing_phone)?$billing_phone:null); ?>,
                                                                emailFinal: <?php echo json_encode(isset($billing_email)?$billing_email:null); ?>
                                                            };
                                                            if (window && window.console) { console.log(dbg); }
                                                        })();
                                                    </script>
                                                    <?php
    // Task 1: Determine per-order address keys
    $initial_billing_key = $order->get_meta('_eao_billing_address_key', true);
    if (empty($initial_billing_key) && isset($_GET['bill_key'])) {
        $initial_billing_key = sanitize_text_field( wp_unslash( $_GET['bill_key'] ) );
    }
    if ($initial_billing_key === '' || $initial_billing_key === null) {
        $initial_billing_key = get_post_meta($order->get_id(), '_eao_billing_address_key', true);
    }
    $initial_shipping_key = $order->get_meta('_eao_shipping_address_key', true);
    if (empty($initial_shipping_key) && isset($_GET['ship_key'])) {
        $initial_shipping_key = sanitize_text_field( wp_unslash( $_GET['ship_key'] ) );
    }
    if ($initial_shipping_key === '' || $initial_shipping_key === null) {
        $initial_shipping_key = get_post_meta($order->get_id(), '_eao_shipping_address_key', true);
    }
    $customer_id_for_keys = $order->get_customer_id();
    if (empty($initial_billing_key)) {
        $selected_billing = ($customer_id_for_keys && class_exists('THWMA_Utils')) ? THWMA_Utils::get_custom_addresses($customer_id_for_keys, 'billing', 'selected_address') : false;
        $initial_billing_key = $selected_billing ? $selected_billing : 'default_meta';
    }
    if (empty($initial_shipping_key)) {
        $selected_shipping = ($customer_id_for_keys && class_exists('THWMA_Utils')) ? THWMA_Utils::get_custom_addresses($customer_id_for_keys, 'shipping', 'selected_address') : false;
        $initial_shipping_key = $selected_shipping ? $selected_shipping : 'default_meta';
    }
                                                    ?>
                                                </address>
                                            </div>
                                            <div class="eao-previous-address-display" style="display: none;">
                                                <span class="eao-previous-label"><?php esc_html_e( 'Was:', 'enhanced-admin-order' ); ?></span>
                                                <address class="eao-previous-address-value"></address>
                                            </div>
                                        </div>
                                        <div class="eao-address-select-container">
                                            <p><label for="eao_billing_address_select"><?php esc_html_e( 'Choose an address:', 'enhanced-admin-order' ); ?></label></p>
                                            <select id="eao_billing_address_select" name="eao_billing_address_select" class="eao-address-select enhanced-select" data-address-type="billing"></select>
                                            <input type="hidden" id="eao_billing_address_key" name="eao_billing_address_key" value="<?php echo esc_attr($initial_billing_key); ?>">
                                        </div>
                                        <div id="eao-billing-fields-inputs" class="eao-address-fields-input-wrapper" style="display: none;">
                                            <p style="margin:6px 0 10px 0;">
                                                <label style="display:inline-flex;align-items:center;gap:6px;">
                                                    <input type="checkbox" id="eao_billing_update_address_book" name="eao_billing_update_address_book" value="yes" checked>
                                                    <span><?php esc_html_e('Update customer address book', 'enhanced-admin-order'); ?></span>
                                                </label>
                                            </p>
                                            <?php
                                            $billing_fields = WC()->countries->get_address_fields( $order->get_billing_country(), 'billing_' );
                                            foreach ( $billing_fields as $key => $field ) {
                                                // Use WC-expected IDs for country/state so built-in scripts work
                                                if (in_array($key, array('billing_country','billing_state'), true)) {
                                                    $field_id = $key; // e.g., billing_country, billing_state
                                                } else {
                                                    $field_id = 'eao_' . $key;
                                                }
                                                $field_name = 'eao_' . $key;
                                                $field_value = $order->{'get_' . $key}(); // e.g., $order->get_billing_first_name();
                                                $field_type = isset($field['type']) ? $field['type'] : 'text'; // Ensure type is set
                                                $field_label = isset($field['label']) ? $field['label'] : ''; // Ensure label is set, default to empty string
                                                // Remove 'required' => false from merge if present, as WC sets it based on locale.
                                                $merged_class = isset($field['class']) && is_array($field['class']) ? $field['class'] : array();
                                                // Ensure WC binding classes
                                                if ($key === 'billing_country' && !in_array('country_to_state', $merged_class, true)) { $merged_class[] = 'country_to_state'; }
                                                if ($key === 'billing_state' && !in_array('state_select', $merged_class, true)) { $merged_class[] = 'state_select'; }
                                                $merged_class[] = 'form-row-wide';
                                                $merged_label_class = isset($field['label_class']) && is_array($field['label_class']) ? $field['label_class'] : array();
                                                $merged_input_class = isset($field['input_class']) && is_array($field['input_class']) ? $field['input_class'] : array();
                                                $merged_input_class[] = 'eao-address-input';
                                                $field_args = array_merge($field, array(
                                                    'type' => $field_type,
                                                    'label' => $field_label,
                                                    'class' => $merged_class,
                                                    'label_class' => $merged_label_class,
                                                    'input_class' => $merged_input_class,
                                                    'return' => false,
                                                    'id' => $field_id,
                                                    'custom_attributes' => array('data-eao-field' => $key)
                                                ));
                                                // Ensure 'required' attribute is correctly passed or omitted
                                                if (isset($field['required'])) {
                                                    $field_args['required'] = $field['required'];
                                                } else {
                                                     // If not set by get_address_fields, woocommerce_form_field might set it based on locale. 
                                                     // To be safe, we can explicitly set it to false if not provided, or let WC handle it.
                                                     // For now, let WC handle it by not setting it if not present in $field.
                                                }
                                                woocommerce_form_field( $key, $field_args, $field_value );
                                                echo '<span class="eao-previous-value" id="eao_previous_' . esc_attr($key) . '" style="display: none;"></span>';
                                            }
                                            ?>
                                            <script type="text/javascript">
                                            jQuery(function($){
                                                // Build a server-derived country→hasStates map as a fallback when wc_country_select_params is unavailable
                                                window.EAO = window.EAO || {};
                                                if (!window.EAO.countryHasStates) {
                                                    window.EAO.countryHasStates = <?php 
                                                        $__all = WC()->countries->get_countries();
                                                        $has_states_map = array();
                                                        foreach ($__all as $__code => $__name) {
                                                            $has_states_map[$__code] = !empty(WC()->countries->get_states($__code));
                                                        }
                                                        echo wp_json_encode($has_states_map);
                                                    ?>;
                                                }
                                                if (!window.EAO.statesByCountry) {
                                                    window.EAO.statesByCountry = <?php 
                                                        $__all2 = WC()->countries->get_countries();
                                                        $states_map = array();
                                                        foreach ($__all2 as $__code2 => $__name2) {
                                                            $states_map[$__code2] = WC()->countries->get_states($__code2);
                                                        }
                                                        echo wp_json_encode($states_map);
                                                    ?>;
                                                }
                                                function populateStates(scope){
                                                    try {
                                                        var $wrapper = $(scope === 'billing' ? '#eao-billing-address-section' : '#eao-shipping-address-section');
                                                        var $country = $wrapper.find('#' + scope + '_country, #eao_' + scope + '_country');
                                                        var $state = $wrapper.find('#' + scope + '_state, #eao_' + scope + '_state');
                                                        var $stateRow = $state.closest('.form-row, tr, p');
                                                        var country = $country.val();
                                                        // Server-derived truth for whether a country has states
                                                        var hasStatesEAO = (window.EAO && window.EAO.countryHasStates && (country in window.EAO.countryHasStates)) ? !!window.EAO.countryHasStates[country] : true;
                                                        if (!hasStatesEAO) {
                                                            $stateRow.hide();
                                                            $state.prop('required', false);
                                                            var $label0 = $stateRow.find('label');
                                                            if ($label0.length){ var t0 = $label0.text().replace(/\*$/, '').trim(); $label0.text(t0); }
                                                            return;
                                                        }
                                                        // Ensure row visible/required
                                                        $stateRow.show();
                                                        $state.prop('required', true);
                                                        var $labelX = $stateRow.find('label');
                                                        if ($labelX.length){ var tX = $labelX.text().replace(/\*$/, '').trim(); $labelX.text(tX + ' *'); }
                                                        // Populate options from server-derived states first; fallback to WC map
                                                        var states = (window.EAO && window.EAO.statesByCountry && window.EAO.statesByCountry[country]) ? (window.EAO.statesByCountry[country] || {}) : null;
                                                        if (!states || Object.keys(states).length === 0) {
                                                            var statesMap = (window.wc_country_select_params && window.wc_country_select_params.states) ? window.wc_country_select_params.states : null;
                                                            states = (statesMap && Object.prototype.hasOwnProperty.call(statesMap, country)) ? (statesMap[country] || {}) : null;
                                                        }
                                                        if (states && Object.keys(states).length > 0) {
                                                            // If current state field is not a select, replace it with a select element
                                                            if (!$state.is('select')) {
                                                                var nameAttr = $state.attr('name') || (scope + '_state');
                                                                var idAttr = $state.attr('id') || (scope + '_state');
                                                                var $select = $('<select/>', { id: idAttr, name: nameAttr }).addClass('state_select');
                                                                $state.replaceWith($select);
                                                                $state = $select;
                                                            }
                                                            var current = $state.val();
                                                            var prevCountry = $state.attr('data-eao-states-country') || '';
                                                            $state.empty();
                                                            // Always include an empty option so state doesn't default after country change
                                                            $state.append($('<option/>', { value: '', text: '' }));
                                                            $.each(states, function(code, name){ $state.append($('<option/>').attr('value', code).text(name)); });
                                                            // If country changed, clear selection; otherwise keep if still valid
                                                            if (prevCountry === country && current && states[current]) { $state.val(current); } else { $state.val(''); }
                                                            $state.attr('data-eao-states-country', country);
                                                            // Refresh select2 if present
                                                            try { $state.trigger('change.select2'); } catch(_e) { try { $state.trigger('change'); } catch(__) {} }
                                                        } else {
                                                            var tries = parseInt($state.attr('data-eao-states-retry')||'0',10);
                                                            if (tries < 5) {
                                                                $state.attr('data-eao-states-retry', String(tries+1));
                                                                setTimeout(function(){ populateStates(scope); }, 150);
                                                            }
                                                        }
                                                    } catch(_){ }
                                                }
                                                function toggleStateRequired(scope){
                                                    try {
                                                        var $wrapper = $(scope === 'billing' ? '#eao-billing-address-section' : '#eao-shipping-address-section');
                                                        var $country = $wrapper.find('#' + scope + '_country, #eao_' + scope + '_country');
                                                        var $stateRow = $wrapper.find('#' + scope + '_state, #eao_' + scope + '_state').closest('.form-row, tr, p');
                                                        var country = $country.val();
                                                        var statesMap = (window.wc_country_select_params && window.wc_country_select_params.states) ? window.wc_country_select_params.states : null;
                                                        // Prefer server-derived map; fallback to wc map; default true
                                                        var hasStates = (window.EAO && window.EAO.countryHasStates && (country in window.EAO.countryHasStates))
                                                            ? !!window.EAO.countryHasStates[country]
                                                            : true;
                                                        if (statesMap && Object.prototype.hasOwnProperty.call(statesMap, country)) {
                                                            var states = statesMap[country] || {};
                                                            hasStates = Object.keys(states).length > 0;
                                                        }
                                                        if ($stateRow.length){
                                                            var prev = $stateRow.attr('data-eao-has-states');
                                                            var prevBool = prev === 'true';
                                                            if (prev === undefined || prevBool !== hasStates) {
                                                                $stateRow.attr('data-eao-has-states', hasStates ? 'true' : 'false');
                                                                $stateRow.toggle(hasStates);
                                                                $stateRow.find('input,select').prop('required', hasStates);
                                                                var $label = $stateRow.find('label');
                                                                if ($label.length){
                                                                    var text = $label.text().replace(/\*$/, '').trim();
                                                                    $label.text(text + (hasStates ? ' *' : ''));
                                                                }
                                                            }
                                                        }
                                                    } catch(_){ }
                                                }
                                                function initBillingStateToggle(){
                                                    // Use WooCommerce built-in country select handler if present
                                                    try { if ($.fn.wc_country_select) { $('#billing_country').wc_country_select(); } } catch(_){ }
                                                    toggleStateRequired('billing');
                                                    populateStates('billing');
                                                }
                                                setTimeout(initBillingStateToggle, 0);
                                                $(document).on('change blur focusout', '#billing_country, #eao_billing_country', function(){
                                                    toggleStateRequired('billing');
                                                    populateStates('billing');
                                                });
                                                // Avoid interfering with autocomplete/history popups
                                                $('#eao-billing-address-section').on('input keydown', 'input,textarea', function(e){ e.stopPropagation(); });
                                            });
                                            </script>
                                        </div>
                                    </div>
                                </div>
                            
                                <div class="eao-details-column eao-address-column" id="eao-shipping-column">
                                    <h3>
                                        <span><?php esc_html_e( 'Shipping Details', 'enhanced-admin-order' ); ?></span>
                                        <div>
                                            <button type="button" class="button eao-save-address-button is-small page-title-action" data-address-type="shipping" style="display: none;"><?php esc_html_e( 'Save', 'enhanced-admin-order' ); ?></button>
                                        <button type="button" class="button eao-edit-address-button is-small page-title-action" data-address-type="shipping"><?php esc_html_e( 'Edit', 'enhanced-admin-order' ); ?></button>
                                        </div>
                                    </h3>
                                    <div class="eao-address-section" id="eao-shipping-address-section">
                                        <div class="eao-address-display-container">
                                            <div class="eao-address-display-current">
                                                <address class="eao-current-address-display">
                                                    <?php 
                                                    $shipping_details = $order->get_address('shipping');
                                                    // $countries is already available from billing section
                                                    $shipping_address_parts = [];

                                                    $s_name_line = trim( (isset($shipping_details['first_name']) ? $shipping_details['first_name'] : '') . ' ' . (isset($shipping_details['last_name']) ? $shipping_details['last_name'] : '') );
                                                    if ( !empty($s_name_line) ) $shipping_address_parts[] = esc_html($s_name_line);
                                                    if ( !empty($shipping_details['company']) ) $shipping_address_parts[] = esc_html($shipping_details['company']);
                                                    if ( !empty($shipping_details['address_1']) ) $shipping_address_parts[] = esc_html($shipping_details['address_1']);
                                                    if ( !empty($shipping_details['address_2']) ) $shipping_address_parts[] = esc_html($shipping_details['address_2']);
                                                    
                                                                                                        $s_city_line = esc_html(isset($shipping_details['city']) ? $shipping_details['city'] : '');
                                    $s_state_line = esc_html(isset($shipping_details['state']) ? $shipping_details['state'] : '');
                                    $s_postcode_line = esc_html(isset($shipping_details['postcode']) ? $shipping_details['postcode'] : '');
                                                    
                                                    $s_city_state_postcode = $s_city_line;
                                                    if ($s_state_line) {
                                                        $s_city_state_postcode .= ($s_city_line ? ', ' : '') . $s_state_line;
                                                    }
                                                    if ($s_postcode_line) {
                                                        $s_city_state_postcode .= ($s_city_state_postcode ? ' ' : '') . $s_postcode_line;
                                                    }
                                                    if (!empty(trim($s_city_state_postcode))) $shipping_address_parts[] = trim($s_city_state_postcode);

                                                                                                        $s_country_code = isset($shipping_details['country']) ? $shipping_details['country'] : '';
                                    $s_country_name = isset($countries[$s_country_code]) ? $countries[$s_country_code] : $s_country_code;
                                                    if ( !empty($s_country_name) ) $shipping_address_parts[] = esc_html($s_country_name);
                                                    
                                                    // Shipping phone: prefer the multi-address entry selected for this order when available
                                                    $shipping_phone = '';
                                                    $customer_for_phone = $order->get_customer_id();
                                                    if ($customer_for_phone && class_exists('THWMA_Utils') && isset($initial_shipping_key) && strpos($initial_shipping_key, 'address_') === 0) {
                                                        $selected_shipping_addr = THWMA_Utils::get_custom_addresses($customer_for_phone, 'shipping', $initial_shipping_key);
                                                        if (is_array($selected_shipping_addr)) {
                                                            // Override details with the selected entry for display
                                                            $shipping_details = array_merge($shipping_details, array_filter($selected_shipping_addr));
                                                            $shipping_phone = isset($selected_shipping_addr['phone']) ? $selected_shipping_addr['phone'] : '';
                                                        }
                                                    }
                                                    if ( empty($shipping_phone) ) {
                                                        $shipping_phone = isset($shipping_details['phone']) ? $shipping_details['phone'] : '';
                                                    }
                                                    if ( empty($shipping_phone) && $customer_for_phone ) {
                                                        $shipping_phone = get_user_meta($customer_for_phone, 'shipping_phone', true);
                                                    }
                                                    if ( !empty($shipping_phone) ) {
                                                        $shipping_address_parts[] = 'Phone: ' . esc_html($shipping_phone);
                                                    }

                                                    if (empty($shipping_address_parts)) {
                                                        echo esc_html__( 'N/A', 'enhanced-admin-order' );
                                                    } else {
                                                        echo implode( '<br>', $shipping_address_parts );
                                                    }
                                                    ?>
                                                </address>
                                            </div>
                                            <div class="eao-previous-address-display" style="display: none;">
                                                <span class="eao-previous-label"><?php esc_html_e( 'Was:', 'enhanced-admin-order' ); ?></span>
                                                <address class="eao-previous-address-value"></address>
                                            </div>
                                        </div>
                                        <div class="eao-address-select-container">
                                            <p><label for="eao_shipping_address_select"><?php esc_html_e( 'Choose an address:', 'enhanced-admin-order' ); ?></label></p>
                                            <select id="eao_shipping_address_select" name="eao_shipping_address_select" class="eao-address-select enhanced-select" data-address-type="shipping"></select>
                                            <input type="hidden" id="eao_shipping_address_key" name="eao_shipping_address_key" value="<?php echo esc_attr($initial_shipping_key); ?>">
                                        </div>
                                        <div id="eao-shipping-fields-inputs" class="eao-address-fields-input-wrapper" style="display: none;">
                                            <p style="margin:6px 0 10px 0;">
                                                <label style="display:inline-flex;align-items:center;gap:6px;">
                                                    <input type="checkbox" id="eao_shipping_update_address_book" name="eao_shipping_update_address_book" value="yes" checked>
                                                    <span><?php esc_html_e('Update customer address book', 'enhanced-admin-order'); ?></span>
                                                </label>
                                            </p>
                                            <?php
                                            $shipping_fields = WC()->countries->get_address_fields( $order->get_shipping_country(), 'shipping_' );
                                            foreach ( $shipping_fields as $key => $field ) {
                                                if (in_array($key, array('shipping_country','shipping_state'), true)) {
                                                    $field_id = $key;
                                                } else {
                                                    $field_id = 'eao_' . $key;
                                                }
                                                $field_name = 'eao_' . $key;
                                                $field_value = $order->{'get_' . $key}();
                                                $field_type = isset($field['type']) ? $field['type'] : 'text'; // Ensure type is set
                                                $field_label = isset($field['label']) ? $field['label'] : ''; // Ensure label is set
                                                $merged_class = isset($field['class']) && is_array($field['class']) ? $field['class'] : array();
                                                if ($key === 'shipping_country' && !in_array('country_to_state', $merged_class, true)) { $merged_class[] = 'country_to_state'; }
                                                if ($key === 'shipping_state' && !in_array('state_select', $merged_class, true)) { $merged_class[] = 'state_select'; }
                                                $merged_class[] = 'form-row-wide';
                                                $merged_label_class = isset($field['label_class']) && is_array($field['label_class']) ? $field['label_class'] : array();
                                                $merged_input_class = isset($field['input_class']) && is_array($field['input_class']) ? $field['input_class'] : array();
                                                $merged_input_class[] = 'eao-address-input';
                                                $field_args = array_merge($field, array(
                                                    'type' => $field_type,
                                                    'label' => $field_label,
                                                    'class' => $merged_class,
                                                    'label_class' => $merged_label_class,
                                                    'input_class' => $merged_input_class,
                                                    'return' => false,
                                                    'id' => $field_id,
                                                    'custom_attributes' => array('data-eao-field' => $key)
                                                ));
                                                if (isset($field['required'])) {
                                                    $field_args['required'] = $field['required'];
                                                }
                                                woocommerce_form_field( $key, $field_args, $field_value );
                                                echo '<span class="eao-previous-value" id="eao_previous_' . esc_attr($key) . '" style="display: none;"></span>';
                                            }
                                            ?>
                                            <script type="text/javascript">
                                            jQuery(function($){
                                                function populateStates(scope){
                                                    try {
                    var $wrapper = $(scope === 'billing' ? '#eao-billing-address-section' : '#eao-shipping-address-section');
                    var $country = $wrapper.find('#' + scope + '_country, #eao_' + scope + '_country');
                    var $state = $wrapper.find('#' + scope + '_state, #eao_' + scope + '_state');
                    var $stateRow = $state.closest('.form-row, tr, p');
                    var country = $country.val();
                    var hasStatesEAO = (window.EAO && window.EAO.countryHasStates && (country in window.EAO.countryHasStates)) ? !!window.EAO.countryHasStates[country] : true;
                    if (!hasStatesEAO) {
                        $stateRow.hide();
                        $state.prop('required', false);
                        var $label = $stateRow.find('label');
                        if ($label.length){ var text = $label.text().replace(/\*$/, '').trim(); $label.text(text); }
                        return;
                    }
                    $stateRow.show();
                    $state.prop('required', true);
                    var $label2 = $stateRow.find('label');
                    if ($label2.length){ var text2 = $label2.text().replace(/\*$/, '').trim(); $label2.text(text2 + ' *'); }
                    var states = (window.EAO && window.EAO.statesByCountry && window.EAO.statesByCountry[country]) ? (window.EAO.statesByCountry[country] || {}) : null;
                    if (!states || Object.keys(states).length === 0) {
                        var statesMap = (window.wc_country_select_params && window.wc_country_select_params.states) ? window.wc_country_select_params.states : null;
                        states = (statesMap && Object.prototype.hasOwnProperty.call(statesMap, country)) ? (statesMap[country] || {}) : null;
                    }
                    if (states && Object.keys(states).length > 0) {
                        if (!$state.is('select')) {
                            var nameAttr = $state.attr('name') || (scope + '_state');
                            var idAttr = $state.attr('id') || (scope + '_state');
                            var $select = $('<select/>', { id: idAttr, name: nameAttr }).addClass('state_select');
                            $state.replaceWith($select);
                            $state = $select;
                        }
                        var current = $state.val();
                        var prevCountry = $state.attr('data-eao-states-country') || '';
                        $state.empty();
                        $state.append($('<option/>', { value: '', text: '' }));
                        $.each(states, function(code, name){ $state.append($('<option/>').attr('value', code).text(name)); });
                        if (prevCountry === country && current && states[current]) { $state.val(current); } else { $state.val(''); }
                        $state.attr('data-eao-states-country', country);
                    } else {
                        var tries = parseInt($state.attr('data-eao-states-retry')||'0',10);
                        if (tries < 5) {
                            $state.attr('data-eao-states-retry', String(tries+1));
                            setTimeout(function(){ populateStates(scope); }, 150);
                        }
                    }
                } catch(_){ }
                                                }
                                                function toggleStateRequired(scope){
                                                    try {
                                                        var $wrapper = $(scope === 'billing' ? '#eao-billing-address-section' : '#eao-shipping-address-section');
                                                        var $country = $wrapper.find('#eao_' + scope + '_country');
                                                        var $stateRow = $wrapper.find('#eao_' + scope + '_state').closest('.form-row, tr, p');
                                                        var country = $country.val();
                                                        var statesMap = (window.wc_country_select_params && window.wc_country_select_params.states) ? window.wc_country_select_params.states : null;
                                                        var hasStates = (window.EAO && window.EAO.countryHasStates && (country in window.EAO.countryHasStates))
                                                            ? !!window.EAO.countryHasStates[country]
                                                            : true;
                                                        if (statesMap && Object.prototype.hasOwnProperty.call(statesMap, country)) {
                                                            var states = statesMap[country] || {};
                                                            hasStates = Object.keys(states).length > 0;
                                                        }
                                                        if ($stateRow.length){
                                                            var prev = $stateRow.attr('data-eao-has-states');
                                                            var prevBool = prev === 'true';
                                                            if (prev === undefined || prevBool !== hasStates) {
                                                                $stateRow.attr('data-eao-has-states', hasStates ? 'true' : 'false');
                                                                $stateRow.toggle(hasStates);
                                                                $stateRow.find('input,select').prop('required', hasStates);
                                                                var $label = $stateRow.find('label');
                                                                if ($label.length){
                                                                    var text = $label.text().replace(/\*$/, '').trim();
                                                                    $label.text(text + (hasStates ? ' *' : ''));
                                                                }
                                                            }
                                                        }
                                                    } catch(_){ }
                                                }
                                                function initShippingStateToggle(){
                                                    try { if ($.fn.wc_country_select) { $('#shipping_country').wc_country_select(); } } catch(_){ }
                                                    toggleStateRequired('shipping');
                                                    populateStates('shipping');
                                                }
                                                setTimeout(initShippingStateToggle, 0);
                                                $(document).on('change blur focusout', '#shipping_country, #eao_shipping_country', function(){
                                                    toggleStateRequired('shipping');
                                                    populateStates('shipping');
                                                });
                                                $('#eao-shipping-address-section').on('input keydown', 'input,textarea', function(e){ e.stopPropagation(); });
                                            });
                                            </script>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <?php 
                    /**
                     * Fires to display meta boxes in the 'normal' context (main column).
                     */
                    do_meta_boxes( $screen_id, 'normal', $order ); 
                    ?>

                    <?php // Product Search and Items will go here ?>

                    <!-- YITH Points interface moved to inline summary section (legacy box fully removed) -->

                </div><!-- /post-body-content -->

                <!-- Sidebar -->
                <div id="postbox-container-1" class="postbox-container">
                    <div id="submitdiv" class="postbox eao-actions-metabox">
                        <h2 class="hndle"><span><?php esc_html_e( 'Order Actions', 'enhanced-admin-order' ); ?></span></h2>
                        <div class="inside">
                            <div id="major-publishing-actions">
                                <div id="publishing-action">
                                    <button type="button" name="eao_cancel_changes" class="button button-secondary eao-cancel-button" id="eao-cancel-changes" disabled><?php esc_html_e( 'Cancel Changes', 'enhanced-admin-order' ); ?></button>
                                    <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'eao_reorder', 'source_order_id' => $order->get_id() ), admin_url( 'admin.php' ) ), 'eao_reorder_' . $order->get_id() ) ); ?>" target="_blank" class="button button-secondary" id="eao-reorder-button"><?php esc_html_e( 'Reorder', 'enhanced-admin-order' ); ?></a>
                                    <button type="submit" name="eao_save_order" class="button button-primary button-large eao-update-button" id="eao-save-order" value="save" disabled><?php esc_html_e( 'Update Order', 'enhanced-admin-order' ); ?></button>
                                </div>
                                <div class="clear"></div>
                            </div>
                        </div>
                    </div>
                    <?php 
                    /**
                     * Fires after the 'Order Actions' meta box in the side column 
                     * and before other 'side' context meta boxes.
                     * 
                     * @param string   $screen_id The ID of the current screen.
                     * @param WC_Order $order     The order object.
                     */
                    do_meta_boxes( $screen_id, 'side', $order ); 
                    ?>
                </div><!-- /postbox-container-1 -->

            </div><!-- /post-body -->
            <br class="clear">
        </div><!-- /poststuff -->
    </form>
</div><!-- /wrap --> 

<script type="text/javascript">
// CRITICAL FIX: Immediate global function stubs to prevent ReferenceErrors
// These are called by Form Submission module before jQuery ready completes
(function() {
    'use strict';
    
    // Placeholder that queues calls until actual function is ready
    window.refreshOrderComponents = function() {
        
        
        // Try to call actual function if it exists
        if (typeof window.refreshOrderComponentsActual === 'function') {
            
            return window.refreshOrderComponentsActual();
        }
        
        // Queue for later execution
        setTimeout(function() {
            if (typeof window.refreshOrderComponentsActual === 'function') {
                
                window.refreshOrderComponentsActual();
            } else {
                
            }
        }, 500);
    };
    
    window.completeComponentRefresh = function() {
        
        
        if (typeof window.completeComponentRefreshActual === 'function') {
            return window.completeComponentRefreshActual();
        }
        
        setTimeout(function() {
            if (typeof window.completeComponentRefreshActual === 'function') {
                
                window.completeComponentRefreshActual();
            } else {
                
            }
        }, 500);
    };
    
    
})();
</script>

<script type="text/javascript">
/* Enhanced Admin Order Plugin v1.8.10 - Fixed Save Functionality */
jQuery(document).ready(function($) {
        
    
    var orderId = <?php echo absint($order->get_id()); ?>;
    var isCurrentlySaving = false;
    // hasUnsavedChanges removed - now handled by modular Change Detection system
    
    
    
    // Check if elements exist
    var $saveButton = $('#eao-save-order');
    var $cancelButton = $('#eao-cancel-changes');
    var $form = $('#eao-order-form');
    
    
    
    // Check if overlay should be shown from page refresh after save
    if (sessionStorage.getItem('eao_save_overlay_active') === 'true') {
        
        
        // Show overlay immediately
        $('#eao-save-overlay').show();
        $('body').css('overflow', 'hidden');
        
        // Restore saved state
        var savedStage = sessionStorage.getItem('eao_save_stage') || '<?php esc_html_e( "Loading updated order...", "enhanced-admin-order" ); ?>';
        var savedProgress = parseInt(sessionStorage.getItem('eao_save_progress')) || 80;
        var savedProgressText = sessionStorage.getItem('eao_save_progress_text') || '<?php esc_html_e( "Page loading...", "enhanced-admin-order" ); ?>';
        
        updateSaveStage(savedStage);
        updateProgress(savedProgress, savedProgressText);
        
        // Set loading state
        $('#eao-save-title').text('<?php esc_html_e( "Loading Updated Order...", "enhanced-admin-order" ); ?>');
        $('#eao-save-message').text('<?php esc_html_e( "Please wait while we prepare your updated order data.", "enhanced-admin-order" ); ?>');
        $('.eao-save-overlay-content').removeClass('eao-save-success');
        $('.eao-save-spinner').show();
        $('.eao-save-checkmark').hide();
        
        // Wait for the page to fully load, including product list
        waitForPageAndProductsToLoad();
    }
    
    // Store original form values for legacy compatibility
    var originalFormData = $form.serialize();
    
    /**
     * Update button states - now delegates to modular system
     */
    function updateButtonStates() {
        // Get current change state from modular system
        var hasUnsavedChanges = false;
        if (window.EAO && window.EAO.ChangeDetection) {
            hasUnsavedChanges = window.EAO.ChangeDetection.triggerCheck();
        }
        
        if (isCurrentlySaving) {
            $saveButton.prop('disabled', true).text('Saving...');
            $cancelButton.prop('disabled', true);
        } else if (hasUnsavedChanges) {
            $saveButton.prop('disabled', false).text('Update Order');
            $cancelButton.prop('disabled', false);
        } else {
            $saveButton.prop('disabled', true).text('Update Order');
            $cancelButton.prop('disabled', true);
        }
        // console.log('[EAO] Button states updated - hasChanges:', hasUnsavedChanges, 'isSaving:', isCurrentlySaving);
    }
    
    /**
     * Show the save overlay
     */
    function showSaveOverlay() {
        $('#eao-save-overlay').fadeIn(300);
        $('body').css('overflow', 'hidden'); // Prevent scrolling
        
        // Reset overlay state
        $('#eao-save-title').text('<?php esc_html_e( "Saving Order...", "enhanced-admin-order" ); ?>');
        $('#eao-save-message').text('<?php esc_html_e( "Please wait while we update your order details.", "enhanced-admin-order" ); ?>');
        $('#eao-save-stage').text('');
        $('.eao-save-overlay-content').removeClass('eao-save-success');
        $('.eao-save-spinner').show();
        $('.eao-save-checkmark').hide();
        
        // Reset progress bar
        updateProgress(10, '<?php esc_html_e( "Initializing...", "enhanced-admin-order" ); ?>');
        
        // Clear any existing emergency timeout
        if (window.eaoEmergencyTimeout) {
            clearTimeout(window.eaoEmergencyTimeout);
        }
        
        // Set emergency timeout to force overlay closure after 30 seconds
        window.eaoEmergencyTimeout = setTimeout(function() {
            
            
            // Force reset the saving state
            isCurrentlySaving = false;
            // hasUnsavedChanges now handled by modular Change Detection system
            
            // Update overlay with timeout message
            $('#eao-save-title').text('Timeout');
            $('#eao-save-message').text('The save operation took longer than expected. The page will refresh to ensure data integrity.');
            $('#eao-save-stage').text('Refreshing page...');
            updateProgress(100, 'Operation timed out - refreshing page');
            
            // Wait 2 seconds then reload the page
            setTimeout(function() {
                
                location.reload();
            }, 2000);
        }, 30000); // 30 second emergency timeout
        
        
    }
    
    /**
     * Hide the save overlay (only used for errors now)
     */
    function hideSaveOverlay() {
        // Clear emergency timeout since overlay is being properly hidden
        if (window.eaoEmergencyTimeout) {
            clearTimeout(window.eaoEmergencyTimeout);
            window.eaoEmergencyTimeout = null;
        }
        
        $('#eao-save-overlay').fadeOut(300);
        $('body').css('overflow', ''); // Restore scrolling
        
    }
    
    /**
     * Update the save stage text
     */
    function updateSaveStage(stageText) {
        if (window.EAO && window.EAO.FormSubmission) {
            try { window.EAO.FormSubmission.updateSaveStage(stageText); } catch(_) {}
        }
        $('#eao-save-stage').text(stageText);
        
    }
    
    /**
     * Update progress bar and text
     */
    function updateProgress(percentage, progressText) {
        var p = parseInt(percentage, 10) || 0;
        // Map legacy bumps to conservative values so modular bar doesn't jump ahead
        var map = {75:55, 80:60, 85:70, 90:75, 92:78, 95:85, 98:97, 100:100};
        if (map[p]) { p = map[p]; }
        // Never regress compared to modular stored progress
        try {
            var stored = parseInt(sessionStorage.getItem('eao_save_progress')||'0',10) || 0;
            if (p < stored) { p = stored; }
        } catch(_) {}
        // Also never regress below the modular in-memory progress
        try {
            if (window.EAO && window.EAO.FormSubmission && typeof window.EAO.FormSubmission.currentProgress === 'number') {
                if (p < window.EAO.FormSubmission.currentProgress) { p = window.EAO.FormSubmission.currentProgress; }
            }
        } catch(_) {}
        if (window.EAO && window.EAO.FormSubmission && typeof window.EAO.FormSubmission.hintProgress === 'function') {
            try { window.EAO.FormSubmission.hintProgress(p, progressText||''); } catch(_) {}
        }
        // Also update legacy visual bar for continuity
        $('#eao-progress-bar').css('width', p + '%');
        $('#eao-progress-text').text(progressText || '');
        
    }
    
    /**
     * Transition to success state (don't hide overlay)
     */
    function showSaveSuccess() {
        $('.eao-save-overlay-content').addClass('eao-save-success');
        $('#eao-save-title').text('<?php esc_html_e( "Success!", "enhanced-admin-order" ); ?>');
        $('#eao-save-message').text('<?php esc_html_e( "Your order has been updated successfully.", "enhanced-admin-order" ); ?>');
        
        // Update progress
        updateProgress(70, '<?php esc_html_e( "Save completed successfully", "enhanced-admin-order" ); ?>');
        
        // Animate spinner to checkmark
        setTimeout(function() {
            $('.eao-save-spinner').fadeOut(200, function() {
                $('.eao-save-checkmark').fadeIn(200);
            });
        }, 500);
        
        
    }
    
    /**
     * Transition to page loading state (don't hide overlay)
     */
    function transitionToPageLoading() {
        
        
        // Update overlay content for page loading
        $('#eao-save-title').text('<?php esc_html_e( "Loading Updated Order...", "enhanced-admin-order" ); ?>');
        $('#eao-save-message').text('<?php esc_html_e( "Please wait while we prepare your updated order data.", "enhanced-admin-order" ); ?>');
        $('.eao-save-overlay-content').removeClass('eao-save-success');
        
        // Show spinner, hide checkmark
        $('.eao-save-checkmark').hide();
        $('.eao-save-spinner').show();
        
        // Update progress
        updateProgress(80, '<?php esc_html_e( "Refreshing page...", "enhanced-admin-order" ); ?>');
    }
    
    /**
     * Wait for page and product list to fully load before hiding overlay
     */
    function waitForPageAndProductsToLoad() {
        
        
        // CRITICAL: Force cleanup of all loading indicators IMMEDIATELY to prevent infinite loop
        
        $('.spinner.is-active').removeClass('is-active');
        $('.loading').hide();
        $('.ajax-loading:visible').hide();
        $('#eao-items-loading-placeholder').remove();
        $('.eao-searching-placeholder').remove();
        $('.eao-loading-message').remove();
        
        var maxWaitTime = 8000; // REDUCED: 8 seconds maximum wait (was 10)
        var startTime = Date.now();
        var checkInterval = 200; // INCREASED: Check every 200ms (was 100ms) to reduce CPU load
        var currentProgress = parseInt(sessionStorage.getItem('eao_save_progress')) || 80;
        
        // Update progress for page loading start
        updateProgress(currentProgress, '<?php esc_html_e( "Page loaded, checking components...", "enhanced-admin-order" ); ?>');
        
        function checkPageReady() {
            var currentTime = Date.now();
            var elapsedTime = currentTime - startTime;
            
            // CRITICAL FIX: Force completion after 6 seconds to prevent infinite loops (REDUCED from 8s)
            if (elapsedTime > 6000) {
                
                updateProgress(100, '<?php esc_html_e( "Loading complete (timeout)", "enhanced-admin-order" ); ?>');
                completePageLoad();
                return;
            }
            
            // ADDITIONAL FIX: After 3 seconds, skip loading indicator checks entirely
            var skipLoadingChecks = elapsedTime > 3000;
            if (skipLoadingChecks) {
                
            }
            
            // Update progress based on time elapsed (gradual increase)
            var timeProgress = Math.min(15, (elapsedTime / 6000) * 15); // Up to 15% more based on time
            var newProgress = currentProgress + timeProgress;
            
            // Check if page components are ready
            var pageReady = true;
            var readyChecks = [];
            var componentProgress = 0;
            var totalComponents = 5;
            
            // Check 1: Basic DOM elements are present
            if ($('#eao-order-form').length === 0) {
                pageReady = false;
                readyChecks.push('Form not found');
            } else {
                componentProgress++;
            }
            
            // Check 2: Product sections are loaded (look for product containers)
            var $productContainers = $('.eao-product-item, .order-item, .item');
            if ($productContainers.length === 0) {
                // If there are supposed to be products but none are loaded yet
                var $productSections = $('#eao-order-items-metabox, .order-items, .woocommerce-order-items');
                if ($productSections.length > 0) {
                    pageReady = false;
                    readyChecks.push('Products not loaded');
                } else {
                    componentProgress++; // No products section means it's ready
                }
            } else {
                componentProgress++;
            }
            
            // Check 3: No visible loading indicators (ENHANCED DEBUGGING)
            var $loadingIndicators = $('.loading, .spinner.is-active, .ajax-loading:visible');
            
            // DEBUGGING: Log exactly what loading indicators are found
            if ($loadingIndicators.length > 0) {
                // console.log('[EAO DEBUG] Found', $loadingIndicators.length, 'loading indicators:');
                $loadingIndicators.each(function(index) {
                    var $el = $(this);
                    console.log('  -', index + ':', $el.prop('tagName'), 'Classes:', $el.attr('class'), 'Visible:', $el.is(':visible'), 'Text:', $el.text().substring(0, 50));
                });
                
                // AGGRESSIVE: Try to remove any remaining spinners we find
                $loadingIndicators.removeClass('is-active loading').hide();
                
                // Re-check after cleanup
                var $remainingIndicators = $('.loading, .spinner.is-active, .ajax-loading:visible');
                if ($remainingIndicators.length > 0) {
                    pageReady = false;
                    readyChecks.push('Loading indicators still visible (' + $remainingIndicators.length + ')');
                } else {
                    // console.log('[EAO DEBUG] All loading indicators cleaned up successfully');
                    componentProgress++;
                }
            } else {
                componentProgress++;
            }
            
            // Check 4: Essential JavaScript modules are available (IMPROVED LOGIC)
            var modulesReady = true;
            if (typeof window.EAO === 'undefined') {
                if (elapsedTime < 2000) { // Give it 2 seconds to load
                    modulesReady = false;
                    pageReady = false;
                    readyChecks.push('EAO modules loading');
                }
            } else if (!window.EAO.ChangeDetection || !window.EAO.FormSubmission) {
                if (elapsedTime < 3000) { // Give it 3 seconds to load core modules
                    modulesReady = false;
                    pageReady = false;
                    readyChecks.push('Core modules loading');
                }
            }
            
            if (modulesReady || elapsedTime >= 3000) {
                componentProgress++;
                if (elapsedTime >= 3000 && !modulesReady) {
                    console.warn('[EAO] Modules not fully loaded after 3s, proceeding anyway');
                }
            }
            
            // Check 5: All images are loaded (RELAXED CHECK)
            var $images = $('img:visible');
            var imagesReady = true;
            if ($images.length > 0 && elapsedTime < 2000) { // Only check images for first 2 seconds
                $images.each(function() {
                    if (this.complete === false) {
                        imagesReady = false;
                        return false; // Break the loop
                    }
                });
                
                if (!imagesReady) {
                    pageReady = false;
                    readyChecks.push('Images still loading');
                }
            } else {
                componentProgress++; // Skip image check after 2 seconds
            }
            
            if (imagesReady || elapsedTime >= 2000) {
                componentProgress++;
            }
            
            // Update progress based on component readiness
            var componentPercentage = (componentProgress / totalComponents) * 15; // Up to 15% for components
            newProgress = currentProgress + componentPercentage + timeProgress;
            newProgress = Math.min(95, newProgress); // Cap at 95% until fully ready
            
            // Update progress with specific feedback
            var progressText = '';
            if (readyChecks.length === 0) {
                progressText = '<?php esc_html_e( "All components ready", "enhanced-admin-order" ); ?>';
            } else if (readyChecks.length === 1) {
                progressText = '<?php esc_html_e( "Waiting for: ", "enhanced-admin-order" ); ?>' + readyChecks[0];
            } else {
                progressText = '<?php esc_html_e( "Loading ", "enhanced-admin-order" ); ?>' + readyChecks.length + '<?php esc_html_e( " components...", "enhanced-admin-order" ); ?>';
            }
            
            updateProgress(Math.round(newProgress), progressText);
            
            
            
            if (pageReady) {
                // Wait a bit more to ensure everything is settled
                updateProgress(98, '<?php esc_html_e( "Finalizing...", "enhanced-admin-order" ); ?>');
                setTimeout(function() {
                    updateProgress(100, '<?php esc_html_e( "Ready!", "enhanced-admin-order" ); ?>');
                    setTimeout(completePageLoad, 200);
                }, 300);
            } else {
                // Continue checking with increased interval to reduce CPU load
                setTimeout(checkPageReady, checkInterval);
            }
        }
        
        function completePageLoad() {
            
            
            // Clear the session storage
            sessionStorage.removeItem('eao_save_overlay_active');
            sessionStorage.removeItem('eao_save_stage');
            sessionStorage.removeItem('eao_save_progress');
            sessionStorage.removeItem('eao_save_progress_text');
            
            // Hide the overlay
            hideSaveOverlay();
            
            
        }
        
        // Start checking with longer initial delay
        setTimeout(checkPageReady, 1000); // INCREASED: 1000ms initial delay (was 500ms)
    }
    
    /**
     * Check for changes - now delegates to modular Change Detection system
     */
    function checkForChanges() {
        // Delegate to the modular Change Detection system
        if (window.EAO && window.EAO.ChangeDetection) {
            var hasChanges = window.EAO.ChangeDetection.triggerCheck();
            updateButtonStates(); // Update buttons based on current state
            return hasChanges;
        }
        
        // Fallback for when modules aren't loaded yet
        console.log('[TEMPLATE DEBUG] Modular Change Detection not available, using basic fallback');
        var currentFormData = $form.serialize();
        var formChanged = (originalFormData !== currentFormData);
        updateButtonStates();
        return formChanged;
    }
    
    // NOTE: markFormAsSaved function removed - now handled by eao-change-detection.js module
    // Form Submission module (eao-form-submission.js) properly delegates to Change Detection module
    
    // Save button handler is now managed by eao-form-submission.js module
    // This removes duplicate handler code to eliminate conflicts
    
        /**
     * Monitor form changes
     */
    $form.on('change keyup', 'input, select, textarea', function() {
        if (!isCurrentlySaving) {
            checkForChanges();
        }
    });
    
    // Initial button state setup
    updateButtonStates();
    
    
    
    // Template loaded successfully
    
    // Expose template's checkForChanges function with a specific name
    window.templateCheckForChanges = checkForChanges;
    
    // YITH Points Global Data Initialization - ALWAYS RUN FOR CUSTOMERS
    <?php if ( eao_yith_is_available() && $order->get_customer_id() > 0 ) : 
        $customer_id = $order->get_customer_id();
        $customer_points = eao_yith_get_customer_points( $customer_id );
        
        // Check for existing YITH points redemption
        $existing_points_redeemed = 0;
        $existing_discount_amount = 0;
        
        // Check for YITH coupon metadata
        $coupon_points = $order->get_meta( '_ywpar_coupon_points' );
        $coupon_amount = $order->get_meta( '_ywpar_coupon_amount' );
        
        if ( ! empty( $coupon_points ) && is_numeric( $coupon_points ) ) {
            $existing_points_redeemed = intval( $coupon_points );
            $existing_discount_amount = ! empty( $coupon_amount ) ? floatval( $coupon_amount ) : ( $existing_points_redeemed * 0.10 );
        }
        
        // Also check for YITH discount coupons in case metadata is missing
        if ( $existing_points_redeemed == 0 ) {
            $used_coupons = $order->get_coupon_codes();
            foreach ( $used_coupons as $coupon_code ) {
                if ( strpos( $coupon_code, 'ywpar_discount_' ) === 0 ) {
                    $coupon = new WC_Coupon( $coupon_code );
                    if ( $coupon->get_id() && ! empty( $coupon->get_meta( 'ywpar_coupon' ) ) ) {
                        $existing_discount_amount = floatval( $coupon->get_amount() );
                        $existing_points_redeemed = intval( $existing_discount_amount * 10 ); // 10 points = $1
                        break;
                    }
                }
            }
            // Fallback: parse coupon line items for discount amount if code meta was not found
            if ( $existing_points_redeemed == 0 ) {
                foreach ( $order->get_items( 'coupon' ) as $coupon_item ) {
                    $code = method_exists( $coupon_item, 'get_code' ) ? $coupon_item->get_code() : '';
                    if ( $code && strpos( $code, 'ywpar_discount_' ) === 0 ) {
                        $existing_discount_amount = abs( (float) $coupon_item->get_discount() );
                        $existing_points_redeemed = intval( round( $existing_discount_amount * 10 ) );
                        break;
                    }
                }
            }
        }
        
        // Calculate total available points (current + already used)
        $total_available_points = $customer_points + $existing_points_redeemed;
    ?>
    
    // CRITICAL: Global YITH Points Data (always available for customers)
    var existingPointsRedeemed = <?php echo intval( $existing_points_redeemed ); ?>;
    var existingDiscountAmount = <?php echo floatval( $existing_discount_amount ); ?>;
    var totalAvailablePoints = <?php echo intval( $total_available_points ); ?>;
    var customerCurrentPoints = <?php echo intval( $customer_points ); ?>;
    
    // CRITICAL: Expose YITH data globally for Main Coordinator access
    window.existingPointsRedeemed = existingPointsRedeemed;
    window.existingDiscountAmount = existingDiscountAmount;
    window.totalAvailablePoints = totalAvailablePoints;
    window.customerCurrentPoints = customerCurrentPoints;
    // Also expose pending intent so UI can reflect saved slider selection on reload
    try {
        window.pendingPointsToRedeem = <?php echo intval( $order->get_meta('_eao_pending_points_to_redeem', true) ); ?>;
    } catch (e) { window.pendingPointsToRedeem = 0; }
    try { console.log('[EAO][PointsInit] Injected globals:', { existingPointsRedeemed: existingPointsRedeemed, existingDiscountAmount: existingDiscountAmount, pendingPointsToRedeem: window.pendingPointsToRedeem }); } catch(e){}
    
    // CRITICAL: Set the order customer ID and initial address keys in eaoEditorParams
    if (typeof eaoEditorParams !== 'undefined') {
        eaoEditorParams.order_customer_id = <?php echo intval( $order->get_customer_id() ); ?>;
        eaoEditorParams.initial_billing_address_key = <?php echo json_encode( $initial_billing_key ); ?>;
        eaoEditorParams.initial_shipping_address_key = <?php echo json_encode( $initial_shipping_key ); ?>;
        // console.log('[EAO Address DEBUG][Template inject] order=', <?php echo intval( $order->get_id() ); ?>, 'billKey=', eaoEditorParams.initial_billing_address_key || 'empty', 'shipKey=', eaoEditorParams.initial_shipping_address_key || 'empty');
    } else {
        // console.log('[EAO Address DEBUG][Template inject] eaoEditorParams undefined at inject time');
    }
    
    console.log('[EAO YITH Global] YITH data initialized for customer order:', {
        pointsRedeemed: existingPointsRedeemed,
        discountAmount: existingDiscountAmount,
        totalAvailable: totalAvailablePoints,
        currentPoints: customerCurrentPoints,
        customerId: <?php echo intval( $order->get_customer_id() ); ?>
    });
    
    // Initialize current discount for summary display
    // Prefer saved snapshot meta if present
    try {
        var savedSnapshot = <?php echo wp_json_encode( $order->get_meta('_eao_current_points_discount', true) ); ?>;
        if (savedSnapshot && typeof savedSnapshot === 'object' && (savedSnapshot.points || savedSnapshot.amount)) {
            var ssPts = parseInt(savedSnapshot.points || 0, 10) || 0;
            var ssAmt = parseFloat(savedSnapshot.amount || (ssPts * 0.10)) || 0;
            if (ssPts > 0 || ssAmt > 0) {
                window.eaoCurrentPointsDiscount = { points: ssPts, amount: ssAmt };
                try { console.log('[EAO][PointsInit] Seed current from saved snapshot:', window.eaoCurrentPointsDiscount); } catch(e){}
            }
        }
    } catch(e){}
    if (existingPointsRedeemed > 0) {
        window.eaoCurrentPointsDiscount = { points: existingPointsRedeemed, amount: existingDiscountAmount };
        try { console.log('[EAO][PointsInit] Seed current from existing redemption:', window.eaoCurrentPointsDiscount); } catch(e){}
    } else if (typeof window.pendingPointsToRedeem !== 'undefined' && window.pendingPointsToRedeem > 0) {
        // Fallback to pending intent when there is no redeemed discount yet
        var ptsInit = parseInt(window.pendingPointsToRedeem, 10) || 0;
        window.eaoCurrentPointsDiscount = { points: ptsInit, amount: (ptsInit * 0.10) };
        try { console.log('[EAO][PointsInit] Seed current from pending intent:', window.eaoCurrentPointsDiscount); } catch(e){}
    }
    
    <?php else : ?>
    
    // Guest order or YITH not available - set customer ID and keys for fallback logic
    if (typeof eaoEditorParams !== 'undefined') {
        eaoEditorParams.order_customer_id = <?php echo intval( $order->get_customer_id() ); ?>;
        eaoEditorParams.initial_billing_address_key = <?php echo json_encode( $initial_billing_key ); ?>;
        eaoEditorParams.initial_shipping_address_key = <?php echo json_encode( $initial_shipping_key ); ?>;
    }
    
    console.log('[EAO YITH Global] Order customer ID set:', <?php echo intval( $order->get_customer_id() ); ?>);
    
    <?php endif; ?>
    
    // YITH Points Interface JavaScript
    if ($('#eao_points_to_redeem').length > 0) {
        console.log('[EAO] YITH Points interface initialized');
        
        var $pointsInput = $('#eao_points_to_redeem');
        var $pointsSlider = $('#eao_points_slider');
        var currentPointsDiscount = 0;
        
        console.log('[EAO YITH] Using global YITH data for metabox interface');
        
        // Initialize with existing redemption or pending intent
        var initPtsVal = 0;
        if (existingPointsRedeemed > 0) { initPtsVal = existingPointsRedeemed; }
        else if (typeof window.pendingPointsToRedeem !== 'undefined' && window.pendingPointsToRedeem > 0) { initPtsVal = parseInt(window.pendingPointsToRedeem, 10) || 0; }
        if (initPtsVal >= 0) {
            console.log('[EAO][PointsInit] UI initialize slider/input to:', initPtsVal);
            $pointsInput.val(initPtsVal);
            $pointsSlider.val(initPtsVal);
            updatePointsConversion(initPtsVal);
            try { if (typeof updateOrderSummaryWithPointsDiscount === 'function') { updateOrderSummaryWithPointsDiscount(initPtsVal); } } catch(e){}
            // Seed staged + last posted to survive first two‑phase render and prevent downgrades
            try {
                window.eaoStagedPointsDiscount = { points: initPtsVal, amount: (initPtsVal / 10) };
                window.EAO = window.EAO || {}; window.EAO.FormSubmission = window.EAO.FormSubmission || {}; window.EAO.FormSubmission.lastPostedPoints = initPtsVal;
                window.eaoPointsLockUntil = Date.now() + 1500;
                if (window.jQuery) {
                    jQuery(document).off('eaoSummaryUpdated.pointsInitFix').on('eaoSummaryUpdated.pointsInitFix', function(){
                        if (window.eaoFirstSummaryFixApplied) return; window.eaoFirstSummaryFixApplied = true;
                        try { if (typeof updateOrderSummaryWithPointsDiscount === 'function') { updateOrderSummaryWithPointsDiscount(initPtsVal); } } catch(e){}
                        try { jQuery(document).off('eaoSummaryUpdated.pointsInitFix'); } catch(_) {}
                    });
                }
            } catch(_){}
        }
        
        /**
         * Sync slider and input values
         */
        function syncSliderAndInput(value) {
            $pointsInput.val(value);
            $pointsSlider.val(value);
            updatePointsConversion(value);
            updateOrderSummaryWithPointsDiscount(value);
        }
        
        // Expose sync function globally so the module can use it
        window.eaoSyncSliderAndInput = syncSliderAndInput;
    }
    
    // YITH Points Functions - ALWAYS AVAILABLE (moved outside metabox conditional)
    
    // Expose update function globally so the module can use it directly
    window.updateOrderSummaryWithPointsDiscount = updateOrderSummaryWithPointsDiscount;
    
    // Calculate and display points conversion value
    function updatePointsConversion(points) {
        var $conversionDisplay = $('#eao-points-conversion-display');
        
        if ($conversionDisplay.length === 0) {
            return; // Element doesn't exist, skip conversion display
        }
        
        if (points <= 0) {
            $conversionDisplay.text('Discount will be applied to order total when you click Update Order');
            return;
        }
        
        // FIXED: 10 points = $1 (so 1 point = $0.10)
        var discountValue = points * 0.10;
        var formattedDiscount = '$' + discountValue.toFixed(2);
        
        if (window.existingPointsRedeemed > 0 && points !== window.existingPointsRedeemed) {
            var changeText = points > window.existingPointsRedeemed ? 'Additional' : 'Reduced';
            $conversionDisplay.html('<strong>' + points + ' points = ' + formattedDiscount + ' discount</strong> (' + changeText + ' from current) - Click Update Order to apply');
        } else if (window.existingPointsRedeemed > 0 && points === window.existingPointsRedeemed) {
            $conversionDisplay.html('<strong>' + points + ' points = ' + formattedDiscount + ' discount</strong> (Current redemption)');
        } else {
            $conversionDisplay.html('<strong>' + points + ' points = ' + formattedDiscount + ' discount</strong> - Click Update Order to apply');
        }
    }
    
    // Update order summary with points discount integrated into summary section
    function updateOrderSummaryWithPointsDiscount(points) {
        var discountAmount = points * 0.10; // 10 points = $1
        
        // Store points discount globally for the order summary
        window.eaoCurrentPointsDiscount = {
            points: points,
            amount: discountAmount
        };
        
        // Use refreshSummaryOnly for points changes to preserve shipping data
        if (typeof window.refreshSummaryOnly === 'function' && 
            typeof window.currentOrderItems !== 'undefined' &&
            typeof window.currentOrderSummaryData !== 'undefined') {
            
            window.refreshSummaryOnly(window.currentOrderItems, window.currentOrderSummaryData);
            
            // Call checkForChanges to enable Update Order button ONLY if there's a change
            if (typeof window.templateCheckForChanges === 'function') {
                // Only trigger changes if the points differ from existing redemption
                if (points !== window.existingPointsRedeemed) {
                    window.templateCheckForChanges();
                }
            }
        }
    }
    
    /**
     * Update YITH points max calculation based on current order summary
     * This function is called when the order summary data changes
     */
    function updateYITHPointsMaxCalculation(summaryData) {
        if (totalAvailablePoints <= 0) {
            return; // No points available
        }
        
        // Get Products Total from the order summary data
        var productsTotalRaw = summaryData && summaryData.products_total_raw ? 
                               parseFloat(summaryData.products_total_raw) : 0;
        
        if (productsTotalRaw <= 0) {
            return; // No products total available
        }
        
        // Calculate points equivalent to products total (10 points = $1)
        var pointsEquivalentToProductsTotal = Math.floor(productsTotalRaw * 10);
        
        // Calculate smart max points using TOTAL available points (current + already used)
        var smartMaxPoints = Math.min(totalAvailablePoints, pointsEquivalentToProductsTotal);
        
        // Update the slider and input max values
        $('#eao_points_slider').attr('max', smartMaxPoints);
        $('#eao_points_to_redeem').attr('max', smartMaxPoints);
        
        // Update the limits display with clearer messaging
        var limitsText = '';
        if (existingPointsRedeemed > 0) {
            limitsText = 'Max redeemable: ' + smartMaxPoints.toLocaleString() + ' points (' + 
                       customerCurrentPoints.toLocaleString() + ' current + ' + 
                       existingPointsRedeemed.toLocaleString() + ' used in order)';
        } else {
            limitsText = 'Max redeemable: ' + smartMaxPoints.toLocaleString() + ' points (' + 
                       customerCurrentPoints.toLocaleString() + ' available)';
        }
        
        $('#eao-points-limits-display').text(limitsText);
        
        // If current points exceed new max, reset to new max
        var currentPoints = 0;
        try {
            var $pointsField = $('#eao_points_to_redeem');
            if ($pointsField.length > 0) {
                currentPoints = parseInt($pointsField.val()) || 0;
            }
        } catch (error) {
            console.warn('[EAO YITH] Error reading current points value:', error);
            currentPoints = 0;
        }
        if (currentPoints > smartMaxPoints) {
            $('#eao_points_to_redeem').val(smartMaxPoints);
            $('#eao_points_slider').val(smartMaxPoints);
            updatePointsConversion(smartMaxPoints);
            updateOrderSummaryWithPointsDiscount(smartMaxPoints);
        }
        
        console.log('[EAO YITH] Updated max points calculation - Products Total: $' + productsTotalRaw.toFixed(2) + 
                   ' = ' + pointsEquivalentToProductsTotal + ' points, Smart Max: ' + smartMaxPoints);
    }
    
    // Hook into order summary updates if the global function exists
    if (typeof window.eaoOrderSummaryUpdateHooks === 'undefined') {
        window.eaoOrderSummaryUpdateHooks = [];
    }
    window.eaoOrderSummaryUpdateHooks.push(updateYITHPointsMaxCalculation);
    
    /**
     * Initialize points calculation when page loads
     */
    function initializeYITHPointsCalculation() {
        // Set initial conversion display
        updatePointsConversion(existingPointsRedeemed);
        
        // Try to get order summary data if available
        if (typeof window.currentOrderSummaryData !== 'undefined' && window.currentOrderSummaryData) {
            console.log('[EAO YITH] Initializing points calculation with existing summary data');
            updateYITHPointsMaxCalculation(window.currentOrderSummaryData);
        } else {
            // Wait for order summary to load and then initialize
            var initRetries = 0;
            var maxRetries = 20; // Wait up to 2 seconds
            
            function waitForSummaryData() {
                initRetries++;
                
                if (typeof window.currentOrderSummaryData !== 'undefined' && window.currentOrderSummaryData) {
                    console.log('[EAO YITH] Summary data loaded, initializing points calculation');
                    updateYITHPointsMaxCalculation(window.currentOrderSummaryData);
                    return;
                }
                
                if (initRetries < maxRetries) {
                    setTimeout(waitForSummaryData, 100);
                } else {
                    console.log('[EAO YITH] Could not load order summary data for points calculation');
                    // Show fallback message
                    $('#eao-points-limits-display').text('Max points will be calculated when order summary loads');
                }
            }
            
            setTimeout(waitForSummaryData, 100);
        }
    }
    
    // Initialize when page is ready
    $(document).ready(function() {
        setTimeout(initializeYITHPointsCalculation, 500); // Give other scripts time to load
    });
    
    /**
     * Transition to component refresh state (no page reload)
     */
    function transitionToComponentRefresh() {
        console.log('[EAO] Transitioning to component refresh state...');
        
        // Update overlay content for component refreshing
        $('#eao-save-title').text('<?php esc_html_e( "Updating Order Display...", "enhanced-admin-order" ); ?>');
        $('#eao-save-message').text('<?php esc_html_e( "Please wait while we refresh the order components.", "enhanced-admin-order" ); ?>');
        $('.eao-save-overlay-content').removeClass('eao-save-success');
        
        // Show spinner, hide checkmark
        $('.eao-save-checkmark').hide();
        $('.eao-save-spinner').show();
        
        // Update progress
        updateProgress(80, '<?php esc_html_e( "Preparing to refresh components...", "enhanced-admin-order" ); ?>');
    }
    
    /**
     * Refresh order components dynamically without page reload
     */
    function refreshOrderComponents() {
        console.log('[EAO] Starting dynamic component refresh...');
        
        updateProgress(85, '<?php esc_html_e( "Refreshing product list...", "enhanced-admin-order" ); ?>');
        
        // Step 1: Try to refresh products using existing functions
        var refreshSteps = [];
        var currentStep = 0;
        
        // Define refresh steps
        refreshSteps.push({
            name: 'Product List',
            progress: 90,
            text: '<?php esc_html_e( "Updating product list...", "enhanced-admin-order" ); ?>',
            action: function(callback) {
                if (typeof window.fetchAndDisplayOrderProducts === 'function') {
                    console.log('[EAO] Refreshing products with fetchAndDisplayOrderProducts');
                    try {
                        // CRITICAL FIX: Pass callback to fetchAndDisplayOrderProducts so it signals when complete
                        window.fetchAndDisplayOrderProducts(function(error) {
                            if (error) {
                                console.error('[EAO] Error refreshing products:', error);
                            } else {
                                console.log('[EAO] Products refresh completed successfully');
                            }
                            callback(); // Always call callback, regardless of error
                        });
                    } catch (e) {
                        console.error('[EAO] Exception calling fetchAndDisplayOrderProducts:', e);
                        callback();
                    }
                } else {
                    console.log('[EAO] fetchAndDisplayOrderProducts not available, skipping product refresh');
                    callback();
                }
            }
        });
        
        // Defer Order Notes refresh; will run in Finalize as last step
        refreshSteps.push({
            name: 'Order Notes (deferred)',
            progress: 92,
            text: '<?php esc_html_e( "Preparing notes refresh...", "enhanced-admin-order" ); ?>',
            action: function(callback) { setTimeout(callback, 50); }
        });
        
        refreshSteps.push({
            name: 'Order Data',
            progress: 95,
            text: '<?php esc_html_e( "Updating order details...", "enhanced-admin-order" ); ?>',
            action: function(callback) {
                if (typeof window.loadOrderData === 'function') {
                    console.log('[EAO] Refreshing order data with loadOrderData');
                    try {
                        window.loadOrderData();
                        setTimeout(callback, 500); // Give it time to complete
                    } catch (e) {
                        console.error('[EAO] Error refreshing order data:', e);
                        callback();
                    }
                } else {
                    console.log('[EAO] loadOrderData not available, refreshing form values manually');
                    refreshFormValues();
                    setTimeout(callback, 200);
                }
            }
        });

        // NEW: After order data refresh, refresh FluentCRM metabox and YITH points globals if applicable
        refreshSteps.push({
            name: 'CRM & Points',
            progress: 97,
            text: '<?php esc_html_e( "Refreshing CRM and points...", "enhanced-admin-order" ); ?>',
            action: function(callback) {
                try {
                    // Refresh FluentCRM profile using the same real-time module used on customer change
                    var cid = 0;
                    // Prefer hidden input on the form for most reliable current customer id
                    try {
                        var $hid = jQuery('#eao_customer_id_hidden');
                        if ($hid && $hid.length) { cid = parseInt($hid.val()||'0',10) || 0; }
                    } catch(_){ }
                    try {
                        var $inp = jQuery('#eao_points_to_redeem_inline');
                        if ($inp && $inp.length) {
                            // Ensure max recalculation after save reflects current products total
                            if (window.EAO && window.EAO.YithPoints && typeof window.EAO.YithPoints.updatePointsMaxCalculation === 'function') {
                                window.EAO.YithPoints.updatePointsMaxCalculation();
                            }
                        }
                    } catch(_){ }
                    if (cid && window.EAO && window.EAO.FluentCRM && typeof window.EAO.FluentCRM.updateCustomerProfile === 'function') {
                        window.EAO.FluentCRM.updateCustomerProfile(cid);
                    }
                } catch(crmErr) { console.warn('[EAO] CRM refresh step error:', crmErr); }

                try {
                    // Trigger a lightweight points max recalculation so slider limits reflect new customer balance
                    if (window.EAO && window.EAO.YithPoints && typeof window.EAO.YithPoints.updatePointsMaxCalculation === 'function') {
                        window.EAO.YithPoints.updatePointsMaxCalculation();
                    } else if (typeof window.updateOrderSummaryWithPointsDiscount === 'function') {
                        // Fallback to template function if module not present
                        window.updateOrderSummaryWithPointsDiscount();
                    }
                } catch(ptsErr) { console.warn('[EAO] Points refresh step error:', ptsErr); }

                // Ensure YITH globals are present after save; if missing, fetch and force a re-render
                try {
                    var globalsMissing = (typeof window.totalAvailablePoints === 'undefined' ||
                                          typeof window.customerCurrentPoints === 'undefined' ||
                                          typeof window.existingPointsRedeemed === 'undefined');
                    // If available points are zero on unpaid order, zero staged/current and ensure summary shows zero
                    try {
                        var st = '';
                        try { st = String(jQuery('#order_status').val()||''); } catch(_){ }
                        if (!st && window.eaoEditorParams && window.eaoEditorParams.order_status) { st = 'wc-' + String(window.eaoEditorParams.order_status||''); }
                        var norm = String(st||'').replace(/^wc-/,'').toLowerCase();
                        var isUnpaid = !(norm === 'processing' || norm === 'completed' || norm === 'shipped');
                        if (isUnpaid && parseInt(window.totalAvailablePoints||0,10) === 0) {
                            if (window.eaoStagedPointsDiscount) { window.eaoStagedPointsDiscount.points = 0; window.eaoStagedPointsDiscount.amount = 0; }
                            if (window.eaoCurrentPointsDiscount) { window.eaoCurrentPointsDiscount.points = 0; window.eaoCurrentPointsDiscount.amount = 0; }
                        }
                    } catch(_){ }
                    if (cid > 0 && globalsMissing && window.eaoEditorParams && window.eaoEditorParams.ajax_url && window.eaoEditorParams.nonce && !window._eaoPostSaveFetchingYith) {
                        window._eaoPostSaveFetchingYith = true;
                        jQuery.post(window.eaoEditorParams.ajax_url, {
                            action: 'eao_get_yith_points_globals',
                            nonce: window.eaoEditorParams.nonce,
                            order_id: window.eaoEditorParams.order_id
                        }, function(resp){
                            window._eaoPostSaveFetchingYith = false;
                            try {
                                if (resp && resp.success && resp.data) {
                                    window.existingPointsRedeemed = parseInt(resp.data.existingPointsRedeemed||0,10);
                                    window.existingDiscountAmount = parseFloat(resp.data.existingDiscountAmount||0);
                                    window.totalAvailablePoints = parseInt(resp.data.totalAvailablePoints||0,10);
                                    window.customerCurrentPoints = parseInt(resp.data.customerCurrentPoints||0,10);
                                    if (window.EAO && window.EAO.MainCoordinator) {
                                        var summary = window.EAO.MainCoordinator.calculateUnifiedSummaryData(window.currentOrderItems || []);
                                        window.EAO.MainCoordinator.renderAllOrderItemsAndSummary(window.currentOrderItems || [], summary);
                                    } else if (typeof window.renderAllOrderItemsAndSummary === 'function') {
                                        var sum2 = (typeof window.calculateCurrentSummaryData === 'function') ? window.calculateCurrentSummaryData(window.currentOrderItems || []) : (window.currentOrderSummaryData || {});
                                        window.renderAllOrderItemsAndSummary(window.currentOrderItems || [], sum2);
                                    }
                                }
                            } catch(_re){ }
                        }, 'json');
                    }
                } catch(_y){ }

                setTimeout(callback, 150);
            }
        });
        
        refreshSteps.push({
            name: 'Finalize',
            progress: 98,
            text: '<?php esc_html_e( "Finalizing updates...", "enhanced-admin-order" ); ?>',
            action: function(callback) {
                // Clear any temporary states
                if (typeof window.stagedOrderItems !== 'undefined') {
                    window.stagedOrderItems = [];
                }
                if (typeof window.itemsPendingDeletion !== 'undefined') {
                    window.itemsPendingDeletion = [];
                }
                
                // CRITICAL FIX: Clear staged notes after successful save using existing architecture
                if (window.EAO && window.EAO.OrderNotes && typeof window.EAO.OrderNotes.clearStagedNotes === 'function') {
                    console.log('[EAO] Clearing staged notes after successful save using module');
                    window.EAO.OrderNotes.clearStagedNotes();
                } else if (typeof window.stagedNotes !== 'undefined') {
                    console.log('[EAO] Fallback: Clearing staged notes manually - count:', window.stagedNotes.length);
                    window.stagedNotes = [];
                    
                    // Update the notes display using fallback methods
                    if (typeof window.updateStagedNotesDisplay === 'function') {
                        const $notesContainer = $('#eao-custom-order-notes .inside');
                        if ($notesContainer.length) {
                            window.updateStagedNotesDisplay($notesContainer);
                            console.log('[EAO] Updated notes display via global function');
                        }
                    }
                }
                
                // Update original form data to reflect current state
                originalFormData = $form.serialize();
                hasUnsavedChanges = false;
                updateButtonStates();
                
                // Perform notes refresh LAST so it reflects just-saved state
                try {
                    var orderIdF = eaoEditorParams && eaoEditorParams.order_id ? eaoEditorParams.order_id : null;
                    if (!orderIdF) { setTimeout(callback, 300); return; }
                    var nonceF = (eao_ajax && (eao_ajax.save_order_nonce || eao_ajax.refresh_notes_nonce)) ? (eao_ajax.save_order_nonce || eao_ajax.refresh_notes_nonce) : '';
                    console.log('[EAO Notes][Finalize] Posting notes refresh LAST', { orderIdF: orderIdF, hasNonce: !!nonceF });
                    jQuery.post(eao_ajax.ajax_url, { action: 'eao_refresh_order_notes', nonce: nonceF, order_id: orderIdF })
                        .done(function(resp){
                            console.log('[EAO Notes][Finalize] Refresh response', resp);
                            try {
                                if (resp && resp.success && resp.data && resp.data.notes_html) {
                                    var $wrap = jQuery('#eao-existing-notes-list-wrapper');
                                    if ($wrap.length) { $wrap.html(resp.data.notes_html); }
                                }
                            } catch(_){ }
                            setTimeout(callback, 300);
                        })
                        .fail(function(){ setTimeout(callback, 300); });
                } catch(_e) {
                    setTimeout(callback, 300);
                }
            }
        });
        
        // Execute refresh steps sequentially
        function executeNextStep() {
            if (currentStep >= refreshSteps.length) {
                // All steps complete
                completeComponentRefresh();
                return;
            }
            
            var step = refreshSteps[currentStep];
            console.log('[EAO] Executing refresh step:', step.name);
            
            updateProgress(step.progress, step.text);
            
            step.action(function() {
                currentStep++;
                setTimeout(executeNextStep, 100); // Small delay between steps
            });
        }
        
        // Start the refresh process
        executeNextStep();
    }
    
    /**
     * Refresh form values manually if no refresh functions available
     */
    function refreshFormValues() {
        console.log('[EAO] Refreshing form values manually...');
        
        // Reset any modified indicators
        $('.eao-previous-value').hide();
        $('.eao-field-modified').removeClass('eao-field-modified');
        
        // Reset staging areas using existing architecture
        if (window.EAO && window.EAO.OrderNotes && typeof window.EAO.OrderNotes.clearStagedNotes === 'function') {
            console.log('[EAO] Clearing staged notes in refreshFormValues using module');
            window.EAO.OrderNotes.clearStagedNotes();
        } else if (typeof window.stagedNotes !== 'undefined') {
            console.log('[EAO] Fallback: Clearing staged notes in refreshFormValues - count:', window.stagedNotes.length);
            window.stagedNotes = [];
            
            // Update the notes display using fallback methods
            if (typeof window.updateStagedNotesDisplay === 'function') {
                const $notesContainer = $('#eao-custom-notes-meta-box');
                if ($notesContainer.length) {
                    window.updateStagedNotesDisplay($notesContainer);
                    console.log('[EAO] Updated notes display via global function in refreshFormValues');
                }
            }
        }
        if (typeof window.pendingBillingAddress !== 'undefined') {
            window.pendingBillingAddress = null;
        }
        if (typeof window.pendingShippingAddress !== 'undefined') {
            window.pendingShippingAddress = null;
        }

        // NEW: Rebuild Billing/Shipping summary blocks from current form values (no server reload)
        try {
            if (window.EAO && window.EAO.ChangeDetection && typeof window.EAO.ChangeDetection.getAddressFieldsData === 'function') {
                var billingFields = window.EAO.ChangeDetection.getAddressFieldsData('billing') || {};
                var shippingFields = window.EAO.ChangeDetection.getAddressFieldsData('shipping') || {};

                // Billing display rebuild
                (function(){
                    var html = '';
                    var line1 = (billingFields.first_name || '') + ' ' + (billingFields.last_name || '');
                    html += (line1.trim());
                    if (billingFields.company) html += '<br>' + billingFields.company;
                    if (billingFields.address_1) html += '<br>' + billingFields.address_1;
                    if (billingFields.address_2) html += '<br>' + billingFields.address_2;
                    if (billingFields.city) html += '<br>' + billingFields.city;
                    if (billingFields.state) html += (billingFields.city ? ', ' : '<br>') + billingFields.state;
                    if (billingFields.postcode) html += ' ' + billingFields.postcode;
                    if (billingFields.country) html += '<br>' + billingFields.country;
                    if (billingFields.email) html += '<br>Email: ' + billingFields.email;
                    if (billingFields.phone) html += '<br>Phone: ' + billingFields.phone;
                    var $billingDisplay = jQuery('#eao-billing-address-section .eao-current-address-display');
                    if ($billingDisplay.length) {
                        $billingDisplay.html(html.replace(/^<br>|<br>$/g,'') || 'N/A');
                    }
                })();

                // Shipping display rebuild (prefer phone/shipping_phone)
                (function(){
                    var html = '';
                    var line1 = (shippingFields.first_name || '') + ' ' + (shippingFields.last_name || '');
                    html += (line1.trim());
                    if (shippingFields.company) html += '<br>' + shippingFields.company;
                    if (shippingFields.address_1) html += '<br>' + shippingFields.address_1;
                    if (shippingFields.address_2) html += '<br>' + shippingFields.address_2;
                    if (shippingFields.city) html += '<br>' + shippingFields.city;
                    if (shippingFields.state) html += (shippingFields.city ? ', ' : '<br>') + shippingFields.state;
                    if (shippingFields.postcode) html += ' ' + shippingFields.postcode;
                    if (shippingFields.country) html += '<br>' + shippingFields.country;
                    var phoneVal = shippingFields.phone || shippingFields.shipping_phone || '';
                    var emailVal = shippingFields.email || shippingFields.shipping_email || '';
                    if (emailVal) html += '<br>Email: ' + emailVal;
                    if (phoneVal) html += '<br>Phone: ' + phoneVal;
                    var $shippingDisplay = jQuery('#eao-shipping-address-section .eao-current-address-display');
                    if ($shippingDisplay.length) {
                        $shippingDisplay.html(html.replace(/^<br>|<br>$/g,'') || 'N/A');
                    }
                })();
                // Update baseline so the refreshed summary persists as the new initial state
                try {
                    if (window.EAO && window.EAO.ChangeDetection && typeof window.EAO.ChangeDetection.storeInitialData === 'function') {
                        window.EAO.ChangeDetection.storeInitialData();
                    }
                    // Ensure single source of truth for customer id is current
                    try {
                        var $hid = jQuery('#eao_customer_id_hidden');
                        if ($hid && $hid.length) {
                            var cur = parseInt($hid.val()||'0',10)||0;
                            if (cur > 0) { window.eaoEditorParams.order_customer_id = cur; }
                        }
                    } catch(_){ }
                } catch (e) {}
            }
        } catch (e) {
            console.warn('[EAO] refreshFormValues address-summary rebuild failed:', e);
        }
    }
    
    /**
     * Complete the component refresh process
     */
    function completeComponentRefresh() {
        console.log('[EAO] Component refresh complete, hiding overlay');
        
        // Clear emergency timeout since we're completing normally
        if (window.eaoEmergencyTimeout) {
            clearTimeout(window.eaoEmergencyTimeout);
            window.eaoEmergencyTimeout = null;
        }
        
        // CRITICAL: Force cleanup of all loading indicators before final completion
        console.log('[EAO] Cleaning up all loading indicators...');
        $('.spinner.is-active').removeClass('is-active');
        $('.loading').hide();
        $('.ajax-loading').hide();
        $('#eao-items-loading-placeholder').remove();
        
        // Final progress update
        updateProgress(100, '<?php esc_html_e( "All components updated!", "enhanced-admin-order" ); ?>');

        // FINAL GUARD: Refresh notes list last to avoid stale redraws from earlier steps
        try {
            var orderIdF = (typeof eaoEditorParams !== 'undefined' && eaoEditorParams && eaoEditorParams.order_id) ? eaoEditorParams.order_id : null;
            if (orderIdF) {
                var nonceF = (typeof eao_ajax !== 'undefined' && (eao_ajax.save_order_nonce || eao_ajax.refresh_notes_nonce)) ? (eao_ajax.save_order_nonce || eao_ajax.refresh_notes_nonce) : '';
                jQuery.post(eao_ajax.ajax_url, { action: 'eao_refresh_order_notes', nonce: nonceF, order_id: orderIdF })
                    .done(function(resp){
                        if (resp && resp.success && resp.data && resp.data.notes_html) {
                            var $wrap = jQuery('#eao-existing-notes-list-wrapper');
                            if ($wrap.length) { $wrap.html(resp.data.notes_html); }
                        }
                    });
            }
        } catch(_){ }
        
        // CRITICAL FIX: Ensure proper state reset after save completion
        isCurrentlySaving = false;
        hasUnsavedChanges = false;

        // Declare template as the preferred restorer to prevent duplicate restores from other modules
        try { window.eaoPostSavePreferredRestorer = 'template'; } catch(e) {}

        // FINAL REPAINT: After overlay is hidden by the submission module, ensure grid is visible
        try {
            setTimeout(function(){
                try { window.eaoDebugPostSaveUntil = Date.now() + 15000; } catch(e) {}
                console.log('[EAO][PostSave] Template finalize: scheduling grid restoration');
                var listEl = document.getElementById('eao-current-order-items-list');
                // Ensure list DOM exists and is visible
                if (!listEl) {
                    var wrap = document.getElementById('eao-order-items-display-edit-section');
                    if (wrap) {
                        wrap.innerHTML = '';
                        listEl = document.createElement('div');
                        listEl.id = 'eao-current-order-items-list';
                        wrap.appendChild(listEl);
                        console.log('[EAO][PostSave] Template: recreated #eao-current-order-items-list');
                    } else {
                        console.warn('[EAO][PostSave] Template: missing #eao-order-items-display-edit-section');
                    }
                } else {
                    listEl.style.display = '';
                    listEl.style.minHeight = '100px';
                }
                // Prefer a fresh rehydrate via products fetch (single render path)
                if (typeof window.fetchAndDisplayOrderProducts === 'function') {
                    console.log('[EAO][PostSave] Template: calling fetchAndDisplayOrderProducts');
                    window.fetchAndDisplayOrderProducts(function(){
                        try { console.log('[EAO][PostSave] Template: fetch complete; rendering grid'); } catch(e) {}
                        try {
                            var list2 = document.getElementById('eao-current-order-items-list');
                            if (window.EAO && window.EAO.ProductsTable && typeof window.EAO.ProductsTable.render === 'function' && list2) {
                                window.EAO.ProductsTable.render(window.currentOrderItems || [], { container: list2 });
                            }
                        } catch(e) {}
                    });
                } else if (window.EAO && window.EAO.ProductsTable && typeof window.EAO.ProductsTable.render === 'function' && listEl) {
                    // Fallback: render directly if fetch function is unavailable
                    // Delay slightly to ensure any layout reflows complete before first draw
                    setTimeout(function(){ window.EAO.ProductsTable.render(window.currentOrderItems || [], { container: listEl }); }, 120);
                }
            }, 350);
        } catch(e) {}
        
        // Update form data to current state
        originalFormData = $form.serialize();
        
        // CRITICAL FIX: Clear pending addresses AFTER component refresh to ensure they stay cleared
        console.log('[EAO] Final address clearing after component refresh...');
        try {
            // Clear global variables
            window.pendingBillingAddress = null;
            window.pendingShippingAddress = null;
            
            // Hide "Was:" displays using direct jQuery
            const $billingPrev = $('#eao-billing-address-section .eao-previous-address-display');
            const $shippingPrev = $('#eao-shipping-address-section .eao-previous-address-display');
            const $billingButton = $('.eao-edit-address-button[data-address-type="billing"]');
            const $shippingButton = $('.eao-edit-address-button[data-address-type="shipping"]');
            
            console.log('[EAO] Final clearing - element check:');
            console.log('  - Billing prev display found:', $billingPrev.length, 'visible:', $billingPrev.is(':visible'));
            console.log('  - Shipping prev display found:', $shippingPrev.length, 'visible:', $shippingPrev.is(':visible'));
            
            $billingPrev.hide();
            $shippingPrev.hide();
            $billingButton.text('Edit');
            $shippingButton.text('Edit');
            
            console.log('[EAO] After final clearing:');
            console.log('  - Billing prev display visible:', $billingPrev.is(':visible'));
            console.log('  - Shipping prev display visible:', $shippingPrev.is(':visible'));
            console.log('[EAO] Final address clearing completed');
            
        } catch (finalClearError) {
            console.error('[EAO] Error in final address clearing:', finalClearError);
        }
        
        // CRITICAL FIX: Update YITH points existing redemption data after save
        if (typeof existingPointsRedeemed !== 'undefined' && 
            typeof window.eaoCurrentPointsDiscount !== 'undefined' && 
            window.eaoCurrentPointsDiscount) {
            
            var newPointsRedeemed = window.eaoCurrentPointsDiscount.points || 0;
            var newDiscountAmount = window.eaoCurrentPointsDiscount.amount || 0;
            
            // Update the existing redemption values to reflect what was just saved
            existingPointsRedeemed = newPointsRedeemed;
            existingDiscountAmount = newDiscountAmount;
            
            console.log('[EAO YITH] Updated existing redemption data after save:', {
                points: existingPointsRedeemed,
                amount: existingDiscountAmount
            });
            
            // Update the conversion display to show current state
            if (typeof updatePointsConversion === 'function') {
                updatePointsConversion(existingPointsRedeemed);
            }
        }
        
        // CRITICAL FIX: Update change detection baseline after successful save
        console.log('[EAO] Updating change detection baseline after save...');
        if (typeof window.storeInitialData === 'function') {
            window.storeInitialData();
            console.log('[EAO] Change detection baseline updated via global function');
        } else if (window.EAO && window.EAO.ChangeDetection && typeof window.EAO.ChangeDetection.storeInitialData === 'function') {
            window.EAO.ChangeDetection.storeInitialData();
            console.log('[EAO] Change detection baseline updated via module function');
        } else {
            console.warn('[EAO] Could not find storeInitialData function to update change detection baseline');
        }
        
        // CLEAN: Use existing change detection system - let it handle everything
        setTimeout(function() {
            console.log('[EAO] Triggering change detection refresh...');
            
            // Use the existing clean API - no manual state manipulation
            if (window.EAO && window.EAO.ChangeDetection && typeof window.EAO.ChangeDetection.triggerCheck === 'function') {
                const hasChanges = window.EAO.ChangeDetection.triggerCheck();
                console.log('[EAO] Change detection result after baseline update:', hasChanges);
                // Note: triggerCheck() already calls updateButtonStates() internally
            } else if (typeof window.checkForChanges === 'function') {
                const hasChanges = window.checkForChanges();
                console.log('[EAO] Legacy change detection result:', hasChanges);
            } else {
                console.warn('[EAO] No change detection function found');
                // Fallback to manual button update only
                updateButtonStates();
            }
        }, 100); // Minimal delay for change detection processing
        
        // Force button state update
        updateButtonStates();
        
        // USE EXISTING ARCHITECTURE: Call the proper overlay completion system
        console.log('[EAO] Component refresh complete, starting overlay completion process...');
        setTimeout(function() {
            // Check if the proper overlay completion system exists
            if (typeof waitForPageAndProductsToLoad === 'function') {
                console.log('[EAO] Using existing waitForPageAndProductsToLoad system');
                waitForPageAndProductsToLoad();
            } else {
                console.warn('[EAO] waitForPageAndProductsToLoad not available, falling back to completePageLoad');
                if (typeof completePageLoad === 'function') {
                    completePageLoad();
                } else {
                    console.error('[EAO] No overlay completion system available');
                    // Emergency fallback only
                    hideSaveOverlay();
                }
            }
        }, 200); // Small delay to let components settle
    }
    // CRITICAL FIX: Expose refreshOrderComponents globally for form submission module
    window.refreshOrderComponentsActual = refreshOrderComponents;
    window.completeComponentRefreshActual = completeComponentRefresh;
    window.refreshOrderComponents = refreshOrderComponents;
    window.completeComponentRefresh = completeComponentRefresh;
    console.log('[EAO Template] refreshOrderComponents exposed globally with fallback compatibility');

    // v2.9.31: Emergency inline tests removed - modular EAO Products system is working correctly
    console.log('[EAO Template v2.9.31] Using modular EAO Products system for Apply Admin Settings');
});

</script>
 
</body>
