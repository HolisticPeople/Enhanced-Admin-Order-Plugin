<?php
/**
 * Enhanced Admin Order - Utility Functions
 * Shared utility functions for order calculations and data processing
 * 
 * @package EnhancedAdminOrder
 * @since 1.5.2
 * @version 2.0.0 - Updated for v2.0.0 release with modular architecture.
 * @author Amnon Manneberg
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Calculate the total item-level discounts applied to an order.
 * This includes individual item discounts, but excludes global order-level discounts.
 *
 * @param WC_Order $order The WooCommerce order object.
 * @return float The total item-level discount amount.
 */
function eao_calculate_total_item_level_discounts(WC_Order $order) {
    $total_item_discount = 0;
    
    foreach ($order->get_items() as $item) {
        $item_total = $item->get_total();
        $item_subtotal = $item->get_subtotal();
        $item_discount = $item_subtotal - $item_total;
        
        // DEBUG: Log each item calculation
        error_log('[EAO Utility] Item ' . $item->get_name() . ': Subtotal=' . $item_subtotal . ', Total=' . $item_total . ', Discount=' . $item_discount);
        
        $total_item_discount += $item_discount;
    }
    
    error_log('[EAO Utility] Total calculated item discount: ' . $total_item_discount);
    return $total_item_discount;
}

/**
 * Calculate the products total (items subtotal minus item-level discounts).
 * This gives the final products total before shipping, tax, and order-level discounts.
 *
 * @param WC_Order $order The WooCommerce order object.
 * @return float The products total amount.
 */
function eao_calculate_products_total(WC_Order $order) {
    $items_subtotal = 0;
    foreach ($order->get_items() as $item) {
        $items_subtotal += $item->get_subtotal();
    }
    
    $total_item_discount = eao_calculate_total_item_level_discounts($order);
    return $items_subtotal - $total_item_discount;
}

/**
 * Safely decode JSON with centralized error handling and logging.
 * Eliminates redundant json_decode() calls throughout the save function.
 *
 * @param string $json_string The JSON string to decode.
 * @param string $context Descriptive context for error logging.
 * @return array|null Returns decoded array on success, null on failure.
 * @since 1.9.8
 */
function eao_decode_json_safely($json_string, $context = '') {
    // Handle empty or non-string input
    if (empty($json_string) || !is_string($json_string)) {
        error_log('[EAO Save] JSON decode error in ' . $context . ': Empty or non-string input provided');
        return null;
    }
    
    $decoded = json_decode($json_string, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('[EAO Save] JSON decode error in ' . $context . ': ' . json_last_error_msg());
        return null;
    }
    
    if (!is_array($decoded)) {
        error_log('[EAO Save] JSON decoded to non-array in ' . $context);
        return null;
    }
    
    return $decoded;
}

/**
 * Get a cached validation result for WooCommerce order setter methods.
 * Eliminates repeated method_exists() checks during address processing.
 *
 * @param string $method_name The setter method name to check.
 * @return bool True if method exists on WC_Order, false otherwise.
 * @since 1.9.8
 */
function eao_validate_order_setter_cached($method_name) {
    static $validation_cache = array();
    
    if (!isset($validation_cache[$method_name])) {
        $validation_cache[$method_name] = method_exists('WC_Order', $method_name);
    }
    
    return $validation_cache[$method_name];
}

/**
 * Generate standardized previous value container HTML.
 * Standardizes the "Was:" display format across all fields.
 *
 * @param string $field_name The field name for the container ID.
 * @param string $container_type The HTML container type ('span', 'address', 'div').
 * @return string The HTML container element.
 * @since 1.9.8
 */
function eao_render_previous_value_container($field_name, $container_type = 'span') {
    $allowed_types = array('span', 'address', 'div');
    $container_tag = in_array($container_type, $allowed_types) ? $container_type : 'span';
    $css_class = $container_type === 'address' ? 'eao-previous-address-value' : 'eao-previous-value';
    
    return sprintf(
        '<%s class="%s" id="eao_previous_%s" style="display: none;"></%s>',
        $container_tag,
        $css_class,
        esc_attr($field_name),
        $container_tag
    );
}

/**
 * Log save operation with standardized format.
 * Centralizes logging format for all save operations.
 *
 * @param string $operation The operation being performed.
 * @param string $details Additional details about the operation.
 * @param string $level Log level ('info', 'error', 'debug').
 * @since 1.9.8
 */
function eao_log_save_operation($operation, $details = '', $level = 'info') {
    $prefix = '[EAO Save v' . (defined('EAO_PLUGIN_VERSION') ? EAO_PLUGIN_VERSION : '1.9.8') . ']';
    $message = $prefix . ' ' . $operation;
    
    if (!empty($details)) {
        $message .= ': ' . $details;
    }
    
    error_log($message);
} 