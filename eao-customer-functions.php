<?php
/**
 * Enhanced Admin Order - Customer Management Functions
 * 
 * Functions for handling customer search, address management, and customer-related operations.
 * 
 * Author: Amnon Manneberg
 * Version: 1.9.7 - Version standardized after complete modular refactoring.
 * 
 * @since 1.5.5
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * AJAX handler for customer search.
 * Uses a hybrid approach: standard WP_User_Query + direct meta table search for name fields.
 * 
 * @since 1.1.4
 */
add_action('wp_ajax_eao_search_customers', 'eao_ajax_search_customers'); // Restoring action

function eao_ajax_search_customers() {
    // Clean any stray output before processing
    while (ob_get_level() > 0) { 
        ob_end_clean(); 
    }
    
    check_ajax_referer('eao_search_customers_nonce', 'nonce');

    $search_term = isset($_POST['search_term']) ? sanitize_text_field($_POST['search_term']) : '';

    if (empty($search_term)) {
        header('Content-Type: application/json; charset=' . get_option('blog_charset'));
        echo json_encode(array('success' => false, 'data' => array('message' => 'Search term cannot be empty.')));
        wp_die();
    }

    $users_found = array();
    $found_user_ids = array();
    $user_scores = array(); // Track how many times each user was found
    
    global $wpdb;
    
    // Split search term into individual words for multi-word searches
    $search_words = preg_split('/\s+/', trim($search_term));
    $search_terms = array($search_term); // Always search full phrase first
    
    // If multi-word search, also search each word individually
    if (count($search_words) > 1) {
        foreach ($search_words as $word) {
            if (!empty($word)) {
                $search_terms[] = $word;
            }
        }
    }
    
    // Search for each term (full phrase + individual words)
    foreach ($search_terms as $term) {
        // Standard WP_User_Query search (user_login, user_email, display_name, etc.)
        $user_query_args = array(
            'search'         => '*'. esc_attr($term) .'*',
            'search_columns' => array(
                'ID',
                'user_login',
                'user_nicename',
                'user_email',
                'display_name'
            ),
            'fields'        => array('ID', 'display_name', 'user_email', 'user_login'),
            'number' => 50, // Increased to capture more potential matches
        );

        $user_query = new WP_User_Query($user_query_args);
        
        // Collect user IDs and increment score
        if (!empty($user_query->get_results())) {
            foreach ($user_query->get_results() as $user) {
                if (!isset($found_user_ids[$user->ID])) {
                    $found_user_ids[$user->ID] = $user;
                    $user_scores[$user->ID] = 0;
                }
                $user_scores[$user->ID]++;
            }
        }
        
        // Search in meta fields (first_name, last_name, billing_first_name, billing_last_name)
        $meta_search_sql = $wpdb->prepare(
            "SELECT DISTINCT user_id FROM {$wpdb->usermeta} 
            WHERE meta_key IN ('first_name', 'last_name', 'billing_first_name', 'billing_last_name')
            AND meta_value LIKE %s",
            '%' . $wpdb->esc_like($term) . '%'
        );
        
        $meta_user_ids = $wpdb->get_col($meta_search_sql);
        
        // Get user objects for meta search results and increment scores
        if (!empty($meta_user_ids)) {
            $new_user_ids = array_diff($meta_user_ids, array_keys($found_user_ids));
            if (!empty($new_user_ids)) {
                $meta_users_query = new WP_User_Query(array(
                    'include' => $new_user_ids,
                    'fields' => array('ID', 'display_name', 'user_email', 'user_login'),
                ));
                
                if (!empty($meta_users_query->get_results())) {
                    foreach ($meta_users_query->get_results() as $user) {
                        if (!isset($found_user_ids[$user->ID])) {
                            $found_user_ids[$user->ID] = $user;
                            $user_scores[$user->ID] = 0;
                        }
                    }
                }
            }
            
            // Increment scores for all meta matches
            foreach ($meta_user_ids as $uid) {
                if (isset($found_user_ids[$uid])) {
                    $user_scores[$uid]++;
                }
            }
        }
    }
    
    // Sort users by score (highest first)
    arsort($user_scores);
    
    // Limit to top 20 results
    $top_user_ids = array_slice(array_keys($user_scores), 0, 20, true);
    
    // Get sorted user objects
    $all_users = array();
    foreach ($top_user_ids as $uid) {
        $all_users[] = $found_user_ids[$uid];
    }

    if (!empty($all_users)) {
        foreach ($all_users as $user) {
            $first_name = sanitize_text_field((string) get_user_meta($user->ID, 'first_name', true));
            $last_name  = sanitize_text_field((string) get_user_meta($user->ID, 'last_name', true));

            if ($first_name === '') {
                $first_name = sanitize_text_field((string) get_user_meta($user->ID, 'billing_first_name', true));
            }
            if ($last_name === '') {
                $last_name = sanitize_text_field((string) get_user_meta($user->ID, 'billing_last_name', true));
            }

            $display_name_source = sanitize_text_field(trim((string) $user->display_name));
            if ($first_name === '' || $last_name === '') {
                $display_name_parts = preg_split('/\s+/', $display_name_source);
                if (is_array($display_name_parts) && !empty($display_name_parts)) {
                    if ($first_name === '' && !empty($display_name_parts[0])) {
                        $first_name = sanitize_text_field((string) $display_name_parts[0]);
                    }
                    if ($last_name === '' && count($display_name_parts) > 1) {
                        $last_name = sanitize_text_field((string) $display_name_parts[count($display_name_parts) - 1]);
                    }
                }
            }

            $name_parts   = array_filter(array($first_name, $last_name), 'strlen');
            $display_name = !empty($name_parts) ? implode(' ', $name_parts) : $display_name_source;

            if ($display_name === '') {
                $display_name = sanitize_text_field((string) $user->user_login);
            }

            $display_name = trim(preg_replace('/\s+/', ' ', $display_name));
            $email        = sanitize_email($user->user_email);

            $users_found[] = array(
                'id' => $user->ID,
                'text' => sprintf(
                    '%s (%s) - ID: %d',
                    $display_name,
                    $email,
                    $user->ID
                ),
                'display_name' => $display_name // For easier JS update if needed
            );
        }
        // Use manual JSON response to avoid wp_send_json_success issues
        header('Content-Type: application/json; charset=' . get_option('blog_charset'));
        echo json_encode(array('success' => true, 'data' => $users_found));
        error_log('[EAO Customer Search] Success response sent manually with ' . count($users_found) . ' users');
    } else {
        // Use manual JSON response to avoid wp_send_json_success issues
        header('Content-Type: application/json; charset=' . get_option('blog_charset'));
        echo json_encode(array('success' => true, 'data' => array())); // Send empty success array if no users found
        error_log('[EAO Customer Search] Success response sent manually with 0 users');
    }
    wp_die();
}

/**
 * Function to get customer addresses (billing and shipping), including defaults and ThemeHigh plugin addresses.
 * Adapted from the user's provided snippet.
 *
 * @since 1.2.2
 * @param int $customer_id The ID of the customer.
 * @return array An array containing 'billing' and 'shipping' addresses.
 */
function eao_get_customer_all_addresses( $customer_id ) {
    // error_log('[EAO DEBUG] =========== HIT: eao_get_customer_all_addresses for CUST ID: ' . $customer_id . ' ===========');

    if ( ! $customer_id ) {
        // error_log('[EAO DEBUG] eao_get_customer_all_addresses: Early exit due to 0 or invalid customer_id.');
        return array( 'billing' => array(), 'shipping' => array() );
    }

    $customer = new WC_Customer( $customer_id );
    // We still instantiate WC_Customer as it's used elsewhere or could be a fallback.
    // However, for default addresses, we will also try get_user_meta directly.

    $addresses = array(
        'billing'  => array(),
        'shipping' => array(),
    );
    $countries = WC()->countries;

    // SIMPLIFIED is_address_empty for AJAX debugging:
    // Checks if address_1 has any content.
    $is_address_empty = function($address_array, $type_for_log = 'unknown') {
        $address_1_present_and_not_empty_string = isset($address_array['address_1']) && trim((string)$address_array['address_1']) !== '';
        // error_log('[EAO DEBUG SIMPLIFIED] is_address_empty (type: ' . $type_for_log . ') for address_1: "' . ($address_array['address_1'] ?? 'NOT SET') . '". Result (is_empty?): ' . (!$address_1_present_and_not_empty_string ? 'TRUE (empty or not set)' : 'FALSE (has content)'));
        return !$address_1_present_and_not_empty_string; 
    };

    // --- Default Billing Address ---
    // Attempt 1: Via WC_Customer object
    $default_billing_address = $customer->get_billing(); 
    // error_log('[EAO DEBUG] Raw Default Billing Address from WC_Customer: ' . print_r($default_billing_address, true));

    // Attempt 2: Via get_user_meta (more direct)
    $meta_billing = array();
    $billing_keys_from_meta = array('billing_first_name', 'billing_last_name', 'billing_company', 'billing_address_1', 'billing_address_2', 'billing_city', 'billing_state', 'billing_postcode', 'billing_country', 'billing_email', 'billing_phone');
    foreach($billing_keys_from_meta as $meta_key) {
        $short_key = str_replace('billing_', '', $meta_key);
        $meta_billing[$short_key] = get_user_meta($customer_id, $meta_key, true);
    }
    // error_log('[EAO DEBUG] Billing Address from get_user_meta: ' . print_r($meta_billing, true));

    // Use meta_billing if its address_1 is populated, otherwise fallback to WC_Customer's version if that one is populated.
    $chosen_billing = $meta_billing;
    if ( $is_address_empty($chosen_billing, 'chosen_billing_from_meta') && !$is_address_empty($default_billing_address, 'chosen_billing_from_wc_customer_fallback') ) {
        $chosen_billing = $default_billing_address;
        // error_log('[EAO DEBUG] Default Billing: Using WC_Customer data as get_user_meta version was empty.');
    } else if ( !$is_address_empty($chosen_billing, 'chosen_billing_from_meta_final_check') ){
         // error_log('[EAO DEBUG] Default Billing: Using get_user_meta data.');
    }


    // Ensure all expected keys are present in chosen_billing, even if empty, for consistency
    $all_billing_keys = array('first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone');
    foreach($all_billing_keys as $key) {
        if (!isset($chosen_billing[$key])) {
            $chosen_billing[$key] = '';
        }
    }
    
    if ( !$is_address_empty($chosen_billing, 'default_billing_final_check') ) {
        $chosen_billing['label'] = __( 'Default Billing', 'enhanced-admin-order' );
        $chosen_billing['formatted_address'] = $countries->get_formatted_address( $chosen_billing );
        $addresses['billing']['default'] = $chosen_billing;
        // error_log('[EAO DEBUG] Added Default Billing Address. Data: ' . print_r($chosen_billing, true));
    } else {
        // error_log('[EAO DEBUG] SKIPPED Default Billing Address (empty after checks). Data considered: ' . print_r($chosen_billing, true));
    }

    // --- Default Shipping Address --- (Similar logic)
    $default_shipping_address = $customer->get_shipping();
    // error_log('[EAO DEBUG] Raw Default Shipping Address from WC_Customer: ' . print_r($default_shipping_address, true));
    
    $meta_shipping = array();
    // Include shipping_phone to align with multi-address integration
    $shipping_keys_from_meta = array('shipping_first_name', 'shipping_last_name', 'shipping_company', 'shipping_address_1', 'shipping_address_2', 'shipping_city', 'shipping_state', 'shipping_postcode', 'shipping_country', 'shipping_phone');
    foreach($shipping_keys_from_meta as $meta_key) {
        $short_key = str_replace('shipping_', '', $meta_key);
        $meta_shipping[$short_key] = get_user_meta($customer_id, $meta_key, true);
    }
    // error_log('[EAO DEBUG] Shipping Address from get_user_meta: ' . print_r($meta_shipping, true));

    $chosen_shipping = $meta_shipping;
    if ( $is_address_empty($chosen_shipping, 'chosen_shipping_from_meta') && !$is_address_empty($default_shipping_address, 'chosen_shipping_from_wc_customer_fallback') ) {
        $chosen_shipping = $default_shipping_address;
        // error_log('[EAO DEBUG] Default Shipping: Using WC_Customer data as get_user_meta version was empty.');
    } else if ( !$is_address_empty($chosen_shipping, 'chosen_shipping_from_meta_final_check') ){
         // error_log('[EAO DEBUG] Default Shipping: Using get_user_meta data.');
    }

    // Ensure 'phone' is part of the standard shipping keys
    $all_shipping_keys = array('first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone');
    foreach($all_shipping_keys as $key) {
        if (!isset($chosen_shipping[$key])) {
            $chosen_shipping[$key] = '';
        }
    }

    if ( !$is_address_empty($chosen_shipping, 'default_shipping_final_check') ) {
        $chosen_shipping['label'] = __( 'Default Shipping', 'enhanced-admin-order' );
        $chosen_shipping['formatted_address'] = $countries->get_formatted_address( $chosen_shipping );
        $addresses['shipping']['default'] = $chosen_shipping;
        // error_log('[EAO DEBUG] Added Default Shipping Address. Data: ' . print_r($chosen_shipping, true));
    } else {
        // error_log('[EAO DEBUG] SKIPPED Default Shipping Address (empty after checks). Data considered: ' . print_r($chosen_shipping, true));
    }

    // ... (Keep existing logic for _wc_additional_addresses and thwma_custom_address but they also use the new $is_address_empty) ...
    // Make sure the $is_address_empty check used in the additional/TH sections is the new simplified one.

    $additional_addresses_meta = get_user_meta( $customer_id, '_wc_additional_addresses', true );
    if ( is_array( $additional_addresses_meta ) ) {
        // error_log('[EAO DEBUG] Raw _wc_additional_addresses Meta: ' . print_r($additional_addresses_meta, true));
        foreach ( $additional_addresses_meta as $key => $address_data ) {
            // Ensure all expected billing keys exist for consistent check by is_address_empty
            $temp_addr_data = $address_data; // Work with a copy
            foreach ($all_billing_keys as $b_key_expected) { 
                if (!isset($temp_addr_data[$b_key_expected])) {
                    $temp_addr_data[$b_key_expected] = '';
                }
            }
            $unique_key = 'additional_' . $key;
            // Use the new $is_address_empty
            if ( ! $is_address_empty($temp_addr_data, '_wc_additional_' . $key) && ! empty( $address_data['address_name'] ) && !isset($addresses['billing'][$unique_key]) ) {
                $address_data['label'] = esc_html( $address_data['address_name'] );
                $address_data['formatted_address'] = $countries->get_formatted_address( $address_data ); // Use original address_data for formatting
                $addresses['billing'][$unique_key] = $address_data;
                // Assuming additional addresses can be both billing and shipping unless specified otherwise
                if(!isset($addresses['shipping'][$unique_key])) { // Avoid overwriting if TH plugin added a specific shipping one with same key
                    $addresses['shipping'][$unique_key] = $address_data; 
                }
                // error_log('[EAO DEBUG] Added _wc_additional_addresses item: ' . $unique_key);
            } else {
                // error_log('[EAO DEBUG] SKIPPED _wc_additional_addresses item: ' . $unique_key . ' (empty or no name or duplicate key). Empty check result: ' . ($is_address_empty($temp_addr_data, '_wc_additional_' . $key . '_check_again') ? 'true' : 'false') . ', Name: ' . ($address_data['address_name'] ?? 'N/A'));
            }
        }
    }

    $th_addresses_meta = get_user_meta( $customer_id, 'thwma_custom_address', true );
    if ( ! empty( $th_addresses_meta ) && is_array( $th_addresses_meta ) ) {
        // error_log('[EAO DEBUG] Raw thwma_custom_address Meta: ' . print_r($th_addresses_meta, true));
        if ( isset( $th_addresses_meta['billing'] ) && is_array( $th_addresses_meta['billing'] ) ) {
            foreach ( $th_addresses_meta['billing'] as $th_key => $th_address_data_raw ) {
                $unique_key = 'th_billing_' . $th_key;
                $normalized_th_billing_data = [];
                // Using $all_billing_keys for normalization standard
                foreach ($all_billing_keys as $std_key) {
                    $th_billing_field_key = 'billing_' . $std_key; // TH usually prefixes with billing_
                    $normalized_th_billing_data[$std_key] = $th_address_data_raw[$th_billing_field_key] ?? '';
                }
                 // Use the new $is_address_empty with normalized data
                if ( !$is_address_empty($normalized_th_billing_data, 'th_billing_' . $th_key) && !isset($addresses['billing'][$unique_key]) ) { 
                    $label = !empty($th_address_data_raw['billing_address_title']) 
                             ? esc_html($th_address_data_raw['billing_address_title']) 
                             : (!empty($normalized_th_billing_data['address_1']) 
                                ? esc_html($normalized_th_billing_data['address_1']) 
                                : sprintf(__( 'Billing Address %s', 'enhanced-admin-order' ), $th_key)); // Removed (Details Missing)
                    
                    $th_display_data = $normalized_th_billing_data; // Data for display and formatting
                    $th_display_data['label'] = $label;
                    $th_display_data['formatted_address'] = $countries->get_formatted_address( $normalized_th_billing_data );
                    // Store the raw TH fields as well if needed, under a sub-key or merge if careful about overwrites
                    $th_display_data['raw_th_fields'] = $th_address_data_raw; 
                    $th_display_data['normalized_fields'] = $normalized_th_billing_data; // Also explicitly store normalized for JS
                    
                    $addresses['billing'][$unique_key] = $th_display_data;
                    // error_log('[EAO DEBUG] Added TH Billing item: ' . $unique_key . ' with Label: ' . $label);
                } else {
                    // error_log('[EAO DEBUG] SKIPPED TH Billing item: ' . $unique_key . ' (empty or duplicate key). Empty check with normalized data: ' . ($is_address_empty($normalized_th_billing_data, 'th_billing_' . $th_key . '_check_again') ? 'true' : 'false'));
                }
            }
        }
        if ( isset( $th_addresses_meta['shipping'] ) && is_array( $th_addresses_meta['shipping'] ) ) {
            foreach ( $th_addresses_meta['shipping'] as $th_key => $th_address_data_raw ) {
                $unique_key = 'th_shipping_' . $th_key;
                $normalized_th_shipping_data = [];
                // Using $all_shipping_keys for normalization standard
                foreach ($all_shipping_keys as $std_key) {
                    $th_shipping_field_key = 'shipping_' . $std_key; // TH usually prefixes with shipping_
                    $normalized_th_shipping_data[$std_key] = $th_address_data_raw[$th_shipping_field_key] ?? '';
                }
                // Use the new $is_address_empty with normalized data
                if ( !$is_address_empty($normalized_th_shipping_data, 'th_shipping_' . $th_key) && !isset($addresses['shipping'][$unique_key]) ) { 
                    $label = !empty($th_address_data_raw['shipping_address_title']) 
                             ? esc_html($th_address_data_raw['shipping_address_title']) 
                             : (!empty($normalized_th_shipping_data['address_1']) 
                                ? esc_html($normalized_th_shipping_data['address_1']) 
                                : sprintf(__( 'Shipping Address %s', 'enhanced-admin-order' ), $th_key)); // Removed (Details Missing)
                    
                    $th_display_data_shipping = $normalized_th_shipping_data;
                    $th_display_data_shipping['label'] = $label;
                    $th_display_data_shipping['formatted_address'] = $countries->get_formatted_address( $normalized_th_shipping_data );
                    $th_display_data_shipping['raw_th_fields'] = $th_address_data_raw;
                    $th_display_data_shipping['normalized_fields'] = $normalized_th_shipping_data;

                    $addresses['shipping'][$unique_key] = $th_display_data_shipping;
                    // error_log('[EAO DEBUG] Added TH Shipping item: ' . $unique_key . ' with Label: ' . $label);
                } else {
                     // error_log('[EAO DEBUG] SKIPPED TH Shipping item: ' . $unique_key . ' (empty or duplicate key). Empty check with normalized data: ' . ($is_address_empty($normalized_th_shipping_data, 'th_shipping_' . $th_key . '_check_again') ? 'true' : 'false'));
                }
            }
        }
    }
    // error_log('[EAO DEBUG] FINAL Addresses from eao_get_customer_all_addresses (after meta attempts & simplified empty check): ' . print_r($addresses, true));
    return $addresses;
}

/**
 * AJAX handler for fetching customer addresses for the EAO page.
 * @since 1.2.2
 */
add_action('wp_ajax_eao_get_customer_addresses', 'eao_ajax_get_customer_addresses_handler');
function eao_ajax_get_customer_addresses_handler() {
    // Clean any stray output before processing
    while (ob_get_level() > 0) { 
        ob_end_clean(); 
    }
    
    // error_log('[EAO DEBUG] =========== HIT: eao_ajax_get_customer_addresses_handler ===========');
    // error_log('[EAO DEBUG] RAW POST in eao_ajax_get_customer_addresses_handler: ' . print_r($_POST, true));
    check_ajax_referer('eao_get_customer_addresses_nonce', 'nonce'); // This will die if nonce is bad
    // error_log('[EAO DEBUG] Nonce check PASSED in eao_ajax_get_customer_addresses_handler.');

    $customer_id = isset( $_POST['customer_id'] ) ? intval( $_POST['customer_id'] ) : 0;
    // error_log('[EAO DEBUG] Customer ID in eao_ajax_get_customer_addresses_handler: ' . $customer_id);

    $all_addresses = eao_get_customer_all_addresses( $customer_id );
    // error_log('[EAO DEBUG] Addresses from eao_get_customer_all_addresses in handler: ' . print_r($all_addresses, true));
    
    // Use manual JSON response to avoid wp_send_json_success issues
    header('Content-Type: application/json; charset=' . get_option('blog_charset'));
    echo json_encode(array('success' => true, 'data' => array( 'addresses' => $all_addresses )));
    error_log('[EAO Customer Addresses] Success response sent manually for customer ID: ' . $customer_id);
    wp_die(); // Ensure no further output
} 

