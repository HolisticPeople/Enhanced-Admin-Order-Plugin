<?php
/**
 * Enhanced Admin Order - ShipStation Utility Functions
 * ShipStation helper utilities extracted from main plugin file
 * 
 * @package EnhancedAdminOrder
 * @since 1.8.8
 * @version 2.4.209 - Add quick rate option to prevent persistent ShipStation shipments (ghost orders)
 * @author Amnon Manneberg
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Calculate the total weight of an order in pounds.
 */
function eao_get_order_weight($order) {
    if ( ! is_a($order, 'WC_Order') ) return 1.0; // Default weight
    $weight = 0;
    foreach ($order->get_items() as $item) {
        if ($item instanceof WC_Order_Item_Product) {
            $product = $item->get_product();
            if ($product && $product->needs_shipping()) { // Check if $product is valid before calling methods
                $product_weight = $product->get_weight();
                if ($product_weight !== '' && is_numeric($product_weight)) { // Ensure weight is not empty and numeric
                    $weight += (floatval($product_weight) * $item->get_quantity());
                } else {
                    error_log('[EAO ShipStation] Product ID ' . $product->get_id() . ' has non-numeric or empty weight: ' . $product_weight);
                }
            }
        }
    }
    if ($weight <= 0) $weight = 1.0; // Default to 1 if weight is zero or less
    return wc_get_weight($weight, 'lb'); // Convert to pounds
}

/**
 * Get dimensions for the order in inches.
 */
function eao_get_order_dimensions($order) {
    if ( ! is_a($order, 'WC_Order') ) return array('length' => 12, 'width' => 12, 'height' => 6); // Default dimensions

    $default_dimensions = array('length' => 12, 'width' => 12, 'height' => 6); // Default
    $max_length = 0; $max_width = 0; $max_height = 0;
    $has_dimensions = false;

    foreach ($order->get_items() as $item) {
        if ($item instanceof WC_Order_Item_Product) {
            $product = $item->get_product();
            if ($product && $product->needs_shipping()) { // Check if $product is valid
                $length = $product->get_length();
                $width = $product->get_width();
                $height = $product->get_height();

                // Check if dimensions are not empty and numeric
                if ($length !== '' && is_numeric($length) && 
                    $width !== '' && is_numeric($width) && 
                    $height !== '' && is_numeric($height)) {
                    $has_dimensions = true;
                    $max_length = max($max_length, wc_get_dimension(floatval($length), 'in'));
                    $max_width = max($max_width, wc_get_dimension(floatval($width), 'in'));
                    $max_height = max($max_height, wc_get_dimension(floatval($height), 'in'));
                } else {
                    error_log('[EAO ShipStation] Product ID ' . $product->get_id() . ' has non-numeric or empty dimensions. L: ' . $length . ', W: ' . $width . ', H: ' . $height);
                }
            }
        }
    }
    
    if ($has_dimensions) {
        return array(
            'length' => max(1, round(floatval($max_length), 2)),
            'width' => max(1, round(floatval($max_width), 2)),
            'height' => max(1, round(floatval($max_height), 2))
        );
    }
    return $default_dimensions;
}

/**
 * Test ShipStation API credentials.
 * Enhanced diagnostics for debugging connection issues.
 * 
 * @since 1.8.0
 * @version 2.4.203 - Enhanced error diagnostics for HTML responses
 * @author Amnon Manneberg
 */
function eao_test_shipstation_api_credentials($api_key, $api_secret) {
    $url = 'https://ssapi.shipstation.com/carriers';
    
    // Use standardized authentication headers
    $test_credentials = array('api_key' => $api_key, 'api_secret' => $api_secret);
    $headers = eao_get_shipstation_auth_headers($test_credentials);
    
    if (!$headers) {
        return array(
            'success' => false,
            'message' => __('Failed to create authentication headers.', 'enhanced-admin-order'),
            'debug_info' => array(
                'error_type' => 'Header Creation',
                'api_key_length' => strlen($api_key),
                'api_secret_length' => strlen($api_secret)
            )
        );
    }
    
    $response = wp_remote_get($url, array(
        'headers' => $headers,
        'timeout' => 30 // 30 second timeout for reliable API connection
    ));
    
    if (is_wp_error($response)) {
        return array(
            'success' => false,
            'message' => sprintf(__( 'WP Error: %s', 'enhanced-admin-order' ), $response->get_error_message()),
            'debug_info' => array(
                'error_type' => 'WordPress Request Error',
                'wp_error_code' => $response->get_error_code(),
                'wp_error_data' => $response->get_error_data()
            )
        );
    }
    
    $response_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    
    if ($response_code !== 200) {
        // Enhanced debugging for non-200 responses
        $debug_info = array(
            'error_type' => 'HTTP Error',
            'http_status' => $response_code,
            'response_length' => strlen($body),
            'content_type' => wp_remote_retrieve_header($response, 'content-type'),
            'server' => wp_remote_retrieve_header($response, 'server')
        );
        
        // Check if response is HTML (common cause of JSON parsing errors)
        $is_html = (stripos($body, '<html') !== false || stripos($body, '<!doctype') !== false);
        $debug_info['is_html_response'] = $is_html;
        
        if ($is_html) {
            // Extract meaningful content from HTML response
            if (preg_match('/<title>(.*?)<\/title>/is', $body, $matches)) {
                $debug_info['html_title'] = trim($matches[1]);
            }
            if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $body, $matches)) {
                $debug_info['html_heading'] = trim(strip_tags($matches[1]));
            }
            // Show first 200 chars of body for context
            $debug_info['html_snippet'] = substr(strip_tags($body), 0, 200) . '...';
        } else {
            // Try to decode JSON for API error message
            $decoded_body = json_decode($body, true);
            if (is_array($decoded_body) && isset($decoded_body['message'])) {
                $debug_info['api_error_message'] = $decoded_body['message'];
            } else {
                $debug_info['raw_response_snippet'] = substr($body, 0, 200) . '...';
            }
        }
        
        $error_message_detail = '';
        if ($is_html && isset($debug_info['html_title'])) {
            $error_message_detail = $debug_info['html_title'];
        } elseif (is_array($decoded_body) && isset($decoded_body['message'])) {
            $error_message_detail = $decoded_body['message'];
        } else {
            $error_message_detail = __('Server returned HTML page instead of JSON API response', 'enhanced-admin-order');
        }
        
        $error_message_string = sprintf(__( 'HTTP Error: %s - %s', 'enhanced-admin-order' ), $response_code, $error_message_detail);
        
        return array(
            'success' => false,
            'message' => $error_message_string,
            'debug_info' => $debug_info
        );
    }
    
    // Successful response - validate JSON
    $decoded_body = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return array(
            'success' => false,
            'message' => sprintf(__('JSON parsing error: %s', 'enhanced-admin-order'), json_last_error_msg()),
            'debug_info' => array(
                'error_type' => 'JSON Parse Error',
                'json_error_code' => json_last_error(),
                'response_length' => strlen($body),
                'response_snippet' => substr($body, 0, 200) . '...',
                'is_html_response' => (stripos($body, '<html') !== false || stripos($body, '<!doctype') !== false)
            )
        );
    }
    
    return array(
        'success' => true,
        'message' => __( 'Connection successful', 'enhanced-admin-order' ),
        'debug_info' => array(
            'carriers_count' => is_array($decoded_body) ? count($decoded_body) : 0,
            'response_size' => strlen($body)
        )
    );
}

/**
 * Build the request data for ShipStation rates API.
 *
 * @param WC_Order $order The order object.
 * @return array|false The request data array or false on error.
 */
function eao_build_shipstation_rates_request($order) {
    if ( ! is_a($order, 'WC_Order') ) return false;

    $store_postcode = get_option('woocommerce_store_postcode');
    $store_city = get_option('woocommerce_store_city');
    $store_country_code = WC()->countries && method_exists(WC()->countries, 'get_base_country') ? WC()->countries->get_base_country() : 'US'; // Default to US if WC_Countries not available
    $store_state_code = WC()->countries && method_exists(WC()->countries, 'get_base_state') ? WC()->countries->get_base_state() : '';
    
    $shipping_country_code = $order->get_shipping_country();
    $shipping_postcode = $order->get_shipping_postcode();
    $shipping_city = $order->get_shipping_city();
    $shipping_state_code = $order->get_shipping_state();
    $shipping_address_1 = $order->get_shipping_address_1();
    $shipping_address_2 = $order->get_shipping_address_2();
    
    // Ensure all address components are strings to prevent errors with ShipStation API or other functions
    $store_postcode = is_scalar($store_postcode) ? (string) $store_postcode : '';
    $store_city = is_scalar($store_city) ? (string) $store_city : '';
    $store_country_code = is_scalar($store_country_code) ? (string) $store_country_code : 'US';
    $store_state_code = is_scalar($store_state_code) ? (string) $store_state_code : '';

    $shipping_country_code = is_scalar($shipping_country_code) ? (string) $shipping_country_code : '';
    $shipping_postcode = is_scalar($shipping_postcode) ? (string) $shipping_postcode : '';
    $shipping_city = is_scalar($shipping_city) ? (string) $shipping_city : '';
    $shipping_state_code = is_scalar($shipping_state_code) ? (string) $shipping_state_code : '';
    $shipping_address_1 = is_scalar($shipping_address_1) ? (string) $shipping_address_1 : '';
    $shipping_address_2 = is_scalar($shipping_address_2) ? (string) $shipping_address_2 : '';

    if (empty($store_postcode) || empty($shipping_country_code) || empty($shipping_postcode)) {
        error_log('[EAO ShipStation] Missing essential address data for rates request. Store Zip: '.esc_html($store_postcode).', Dest Country: '.esc_html($shipping_country_code).', Dest Zip: '.esc_html($shipping_postcode));
        return false;
    }
    
    $raw_weight_lbs = eao_get_order_weight($order);
    $rounded_weight = round(floatval($raw_weight_lbs), 2);
    $dimensions = eao_get_order_dimensions($order);
    
    $request = array(
        'carrierCode' => null,
        'serviceCode' => null,
        'packageCode' => null, 
        'fromPostalCode' => $store_postcode,
        'fromCity' => $store_city,
        'fromState' => $store_state_code,
        'fromCountry' => $store_country_code,
        'toCountry' => $shipping_country_code,
        'toPostalCode' => $shipping_postcode,
        'toCity' => $shipping_city,
        'toState' => $shipping_state_code,
        'toStreet1' => $shipping_address_1,
        'toStreet2' => $shipping_address_2,
        'weight' => array(
            'value' => $rounded_weight,
            'units' => 'pounds'
        ),
        'dimensions' => array(
            'units' => 'inches',
            'length' => $dimensions['length'],
            'width' => $dimensions['width'],
            'height' => $dimensions['height']
        ),
        'confirmation' => 'none', 
        'residential' => true 
    );
    
    // Request Quick Rates to avoid creating persistent shipments or ghost orders in ShipStation
    // Include both snake_case (ShipEngine style) and camelCase (ShipStation style) for maximum compatibility
    $request['rate_options'] = array(
        'rate_type' => 'quick'
    );
    $request['rateOptions'] = array(
        'rateType' => 'quick'
    );
    
    return $request; 
}

/**
 * Get shipping rates for a specific carrier from ShipStation.
 */
function eao_get_shipstation_carrier_rates($request_data) {
    if (!is_array($request_data)) {
        error_log('[EAO ShipStation] eao_get_shipstation_carrier_rates: request_data is not an array.');
        return array('success' => false, 'message' => __('Internal error: Request data is not an array.', 'enhanced-admin-order'));
    }

    // Use standardized authentication headers
    $headers = eao_get_shipstation_auth_headers();
    if (!$headers) {
        return array('success' => false, 'message' => __('API credentials not configured.', 'enhanced-admin-order'));
    }

    $url = 'https://ssapi.shipstation.com/shipments/getrates';
    
    $encoded_body = wp_json_encode($request_data);
    if (!$encoded_body) {
        return array('success' => false, 'message' => __('Failed to encode request data for ShipStation.', 'enhanced-admin-order'));
    }
    
    // UPS request body logging temporarily disabled
    
    $response = wp_remote_post($url, array(
        'headers' => $headers,
        'body' => $encoded_body,
        'timeout' => 45 // Increased timeout
    ));
    
    if (is_wp_error($response)) {
        return array('success' => false, 'message' => sprintf(__('WP Error: %s', 'enhanced-admin-order'), $response->get_error_message()));
    }
    
    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);
    
    if ($response_code !== 200) {
        $error_message = sprintf(__('HTTP Error: %s', 'enhanced-admin-order'), $response_code);
        $decoded_body = json_decode($response_body, true);
        if (is_array($decoded_body) && isset($decoded_body['message'])) { // Check if $decoded_body is an array
            $error_message .= ' - ' . $decoded_body['message'];
        }
        // Debug logging temporarily disabled for UPS troubleshooting
        return array('success' => false, 'message' => $error_message, 'response_body' => $response_body);
    }
    
    $decoded_data = json_decode($response_body, true);
    // Ensure $decoded_data is an array. ShipStation returns [] for no rates, which is fine.
    // json_decode returns null on error.
    if (!is_array($decoded_data)) { 
        error_log('[EAO ShipStation] Failed to decode rates response or response is not an array. Body: ' . $response_body);
        return array('success' => false, 'message' => __('Failed to decode rates response from ShipStation or unexpected format.', 'enhanced-admin-order'), 'response_body' => $response_body);
    }
    
    // ShipStation returns an empty array [] if no rates found for a specific carrier, which is a valid scenario for the API.
    // The calling function (eao_ajax_shipstation_v2_get_rates_handler) handles the case where $decoded_data is empty.
    
    return array('success' => true, 'rates' => $decoded_data);
}

/**
 * Format ShipStation API rates response for display.
 */
function eao_format_shipstation_rates_response($all_api_rates) {
    $formatted_rates = array();
    if (!is_array($all_api_rates) || empty($all_api_rates)) {
        return array('rates' => array());
    }
    
    foreach ($all_api_rates as $idx => $rate) {
        // Ensure $rate is an array and all expected keys are set before accessing them
        if (is_array($rate) && 
            isset($rate['carrierCode'], $rate['serviceCode'], $rate['serviceName'], $rate['shipmentCost'])) {
            
            $delivery_days_text = __('N/A', 'enhanced-admin-order');
            if (!empty($rate['deliveryDays']) && is_numeric($rate['deliveryDays'])){
                $delivery_days_text = sprintf(_n('%d day', '%d days', intval($rate['deliveryDays']), 'enhanced-admin-order'), intval($rate['deliveryDays']));
            }

            $formatted_rates[] = array(
                'rate_id'             => isset($rate['rateID']) ? (string)$rate['rateID'] : ($rate['carrierCode'] . '_' . $rate['serviceCode'] . '_' . $idx), // Use rateID if available, ensure string
                'carrier_code'        => (string)$rate['carrierCode'],
                'service_code'        => (string)$rate['serviceCode'],
                'service_name'        => (string)$rate['serviceName'],
                'shipping_amount'     => wc_price(floatval($rate['shipmentCost'])), // Ensure floatval
                'shipping_amount_raw' => floatval($rate['shipmentCost']),
                'other_cost'          => wc_price(isset($rate['otherCost']) ? floatval($rate['otherCost']) : 0), // Ensure floatval
                'estimated_delivery'  => $delivery_days_text,
            );
        } else {
            error_log('[EAO ShipStation] Skipping rate due to missing keys or invalid format: ' . print_r($rate, true));
        }
    }
    return array('rates' => $formatted_rates);
}

/**
 * Get a display-friendly name for a ShipStation carrier code.
 */
function eao_get_shipstation_carrier_display_name($carrier_code) {
    $carrier_names = array(
        'stamps_com'      => __('USPS', 'enhanced-admin-order'),
        'ups_walleted'    => __('UPS', 'enhanced-admin-order'),
        'fedex_walleted'  => __('FedEx', 'enhanced-admin-order'),
        'dhl_express'     => __('DHL Express', 'enhanced-admin-order'),
        // Add more as needed
    );
    return isset($carrier_names[$carrier_code]) ? $carrier_names[$carrier_code] : ucwords(str_replace('_', ' ', $carrier_code));
}

/**
 * Add UPS-specific parameters to the request data for ShipStation.
 */
function eao_customize_ups_request($request_data) {
    // Ensure $request_data is an array before proceeding
    if (!is_array($request_data)) {
        error_log('[EAO ShipStation] eao_customize_ups_request: request_data is not an array.');
        return $request_data; // Return original data if not an array
    }

    $ups_request = $request_data; // Make a copy
    
    // Ensure string values for address components, default to 'Unknown' if empty or not scalar
    $ups_request['toStreet1'] = (!empty($ups_request['toStreet1']) && is_scalar($ups_request['toStreet1'])) ? (string)$ups_request['toStreet1'] : 'Unknown';
    $ups_request['toCity']    = (!empty($ups_request['toCity']) && is_scalar($ups_request['toCity'])) ? (string)$ups_request['toCity'] : 'Unknown';
    
    $ups_request['residential'] = true; // Often defaults to true for UPS rates
    $ups_request['packageCode'] = 'package'; // Using a more generic code for customer-supplied package with dimensions
                                       // Using '02' for "Customer Supplied Package" from UPS docs.

    if (isset($ups_request['dimensions']) && is_array($ups_request['dimensions'])) {
        $ups_request['dimensions']['length'] = isset($ups_request['dimensions']['length']) ? max(1, floatval($ups_request['dimensions']['length'])) : 1;
        $ups_request['dimensions']['width']  = isset($ups_request['dimensions']['width']) ? max(1, floatval($ups_request['dimensions']['width'])) : 1;
        $ups_request['dimensions']['height'] = isset($ups_request['dimensions']['height']) ? max(1, floatval($ups_request['dimensions']['height'])) : 1;
    } else {
        // Initialize dimensions if not set or not an array
        $ups_request['dimensions'] = array('length' => 1, 'width' => 1, 'height' => 1, 'units' => 'inches');
        if(isset($request_data['dimensions']['units'])) $ups_request['dimensions']['units'] = $request_data['dimensions']['units']; // Preserve units if possible
        error_log('[EAO ShipStation] eao_customize_ups_request: dimensions key missing or not an array in request_data. Initialized to default.');
    }

    if (isset($ups_request['weight']) && is_array($ups_request['weight'])) {
        $ups_request['weight']['value'] = isset($ups_request['weight']['value']) ? max(0.1, floatval($ups_request['weight']['value'])) : 0.1;
    } else {
        // Initialize weight if not set or not an array
        $ups_request['weight'] = array('value' => 0.1, 'units' => 'pounds');
        if(isset($request_data['weight']['units'])) $ups_request['weight']['units'] = $request_data['weight']['units']; // Preserve units if possible
        error_log('[EAO ShipStation] eao_customize_ups_request: weight key missing or not an array in request_data. Initialized to default.');
    }
    return $ups_request;
}

/**
 * Get standardized ShipStation API authentication headers.
 * 
 * @since 2.4.188
 * @param array $credentials Array with api_key and api_secret
 * @return array|false HTTP headers array or false if credentials invalid
 */
function eao_get_shipstation_auth_headers($credentials = null) {
    if (is_null($credentials)) {
        $credentials = eao_get_shipstation_api_credentials();
    }
    
    if (empty($credentials['api_key']) || empty($credentials['api_secret'])) {
        error_log('[EAO ShipStation] Authentication failed: Missing API credentials');
        return false;
    }
    
    // Ensure credentials are strings and properly formatted
    $api_key = trim((string) $credentials['api_key']);
    $api_secret = trim((string) $credentials['api_secret']);
    
    if (empty($api_key) || empty($api_secret)) {
        error_log('[EAO ShipStation] Authentication failed: Empty API credentials after sanitization');
        return false;
    }
    
    // Standardized base64 encoding - consistent across all API calls
    $auth_string = $api_key . ':' . $api_secret;
    $auth_header = 'Basic ' . base64_encode($auth_string);
    
    return array(
        'Authorization' => $auth_header,
        'Content-Type' => 'application/json',
        'Cache-Control' => 'no-cache'
    );
}

/**
 * Validate ShipStation API credentials format and content.
 * 
 * @since 2.4.188
 * @param array $credentials Credentials to validate
 * @return array Validation result with success status and details
 */
function eao_validate_shipstation_credentials($credentials = null) {
    if (is_null($credentials)) {
        $credentials = eao_get_shipstation_api_credentials();
    }
    
    $result = array(
        'valid' => false,
        'issues' => array(),
        'source' => isset($credentials['source']) ? $credentials['source'] : 'unknown'
    );
    
    // Check if credentials array exists
    if (!is_array($credentials)) {
        $result['issues'][] = 'Credentials is not an array';
        return $result;
    }
    
    // Check API key
    if (empty($credentials['api_key'])) {
        $result['issues'][] = 'API key is empty or missing';
    } elseif (!is_string($credentials['api_key'])) {
        $result['issues'][] = 'API key is not a string';
    } elseif (strlen(trim($credentials['api_key'])) < 10) {
        $result['issues'][] = 'API key appears too short (less than 10 characters)';
    }
    
    // Check API secret
    if (empty($credentials['api_secret'])) {
        $result['issues'][] = 'API secret is empty or missing';
    } elseif (!is_string($credentials['api_secret'])) {
        $result['issues'][] = 'API secret is not a string';
    } elseif (strlen(trim($credentials['api_secret'])) < 10) {
        $result['issues'][] = 'API secret appears too short (less than 10 characters)';
    }
    
    // Check for common formatting issues
    if (!empty($credentials['api_key'])) {
        $api_key = trim($credentials['api_key']);
        if ($api_key !== $credentials['api_key']) {
            $result['issues'][] = 'API key has leading/trailing whitespace';
        }
        if (strpos($api_key, ' ') !== false) {
            $result['issues'][] = 'API key contains spaces';
        }
    }
    
    if (!empty($credentials['api_secret'])) {
        $api_secret = trim($credentials['api_secret']);
        if ($api_secret !== $credentials['api_secret']) {
            $result['issues'][] = 'API secret has leading/trailing whitespace';
        }
        if (strpos($api_secret, ' ') !== false) {
            $result['issues'][] = 'API secret contains spaces';
        }
    }
    
    $result['valid'] = empty($result['issues']);
    return $result;
}
