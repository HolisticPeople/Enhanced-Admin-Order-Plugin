<?php
/**
 * Enhanced Admin Order Plugin - AJAX Save Core Functions
 * Extracted modular save functions from main plugin file
 * 
 * @package EnhancedAdminOrder
 * @since 1.9.9
 * @version 2.9.12 - Address Book Save: Added THWMA class resolver + lazy include; namespaced support; precise keyed updates
 * @author Amnon Manneberg
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Resolve ThemeHigh Multi-Address Utils class (namespaced or global) at runtime.
 * Attempts lazy include from our bundled copy if not already loaded.
 *
 * @return string Fully qualified class name when available, otherwise empty string
 */
function eao_get_thwma_utils_class() {
    static $resolved_class = null;
    if ($resolved_class !== null) {
        return $resolved_class;
    }

    // 1) Global (some builds expose a global alias)
    if (class_exists('THWMA_Utils')) {
        $resolved_class = 'THWMA_Utils';
        return $resolved_class;
    }

    // 2) Namespaced class from the bundled plugin source
    $fqcn = '\\Themehigh\\WoocommerceMultipleAddressesPro\\includes\\utils\\THWMA_Utils';
    if (class_exists($fqcn)) {
        $resolved_class = $fqcn;
        return $resolved_class;
    }

    // 3) Try including the file from our bundled copy, then re-check
    $maybe_path = defined('EAO_PLUGIN_DIR')
        ? EAO_PLUGIN_DIR . 'woocommerce-multiple-addresses-pro/src/includes/utils/THWMA_Utils.php'
        : '';
    if ($maybe_path && file_exists($maybe_path)) {
        require_once $maybe_path;
    }

    if (class_exists('THWMA_Utils')) {
        $resolved_class = 'THWMA_Utils';
        return $resolved_class;
    }
    if (class_exists($fqcn)) {
        $resolved_class = $fqcn;
        return $resolved_class;
    }

    // Not available
    $resolved_class = '';
    return $resolved_class;
}

/**
 * Prepare address array for ThemeHigh utils: ensure type-prefixed keys and skip empty values.
 *
 * @param array $fields Unprefixed or mixed keys (e.g., first_name, address_1, shipping_phone)
 * @param string $type 'billing' | 'shipping'
 * @return array Prefixed keys suitable for THWMA_Utils::update_address_to_user
 */
function eao_prepare_thwma_address_array($fields, $type) {
    $prepared = array();
    if (!is_array($fields)) { return $prepared; }
    $prefix = $type . '_';
    foreach ($fields as $key => $value) {
        $san_key = is_string($key) ? sanitize_key($key) : '';
        $san_val = is_string($value) ? sanitize_text_field($value) : $value;
        // Skip empties to avoid unintentionally wiping existing saved values
        if ($san_val === '' || $san_val === null) { continue; }
        if (strpos($san_key, $prefix) === 0) {
            $prepared[$san_key] = $san_val;
        } else {
            $prepared[$prefix . $san_key] = $san_val;
        }
    }
    return $prepared;
}

/**
 * Merge prepared fields into an existing ThemeHigh address entry.
 *
 * @param int $customer_id
 * @param string $type 'billing'|'shipping'
 * @param string $norm_key normalized like 'address_2'
 * @param array $prepared_fields prefixed, non-empty fields
 * @return array merged entry (existing + prepared_fields)
 */
function eao_merge_thwma_entry($customer_id, $type, $norm_key, $prepared_fields) {
    $thwma_class = eao_get_thwma_utils_class();
    $existing = array();
    if ($thwma_class && method_exists($thwma_class, 'get_custom_addresses')) {
        $existing = $thwma_class::get_custom_addresses($customer_id, $type, $norm_key);
        if (!is_array($existing)) { $existing = array(); }
    }
    // Overlay new values onto existing to avoid wiping untouched keys
    return array_merge($existing, $prepared_fields);
}

/**
 * Process staged order notes during save operation.
 * Handles addition of both customer and private notes.
 *
 * @param WC_Order $order The WooCommerce order object.
 * @param array $post_data The POST data from the save request.
 * @return array Result data with notes_processed count and any errors.
 * @since 1.9.9
 */
function eao_process_order_notes($order, $post_data) {
    $result = array(
        'notes_processed' => 0,
        'notes_errors' => array(),
        'success' => true
    );

    eao_log_save_operation('Checking for staged notes', 'POST data inspection');
    
    if (!isset($post_data['eao_staged_notes'])) {
        eao_log_save_operation('No staged notes found', 'eao_staged_notes not in POST');
        return $result;
    }

    // IMPORTANT: Do not sanitize JSON with sanitize_text_field as it corrupts quotes
    $staged_notes_json = isset($post_data['eao_staged_notes']) ? wp_unslash($post_data['eao_staged_notes']) : '[]';
    eao_log_save_operation('Processing staged notes JSON', substr($staged_notes_json, 0, 100) . '...');
    
    // Use our new utility function for JSON processing
    $staged_notes = eao_decode_json_safely($staged_notes_json, 'staged_notes');
    
    if ($staged_notes === null) {
        $result['success'] = false;
        $result['notes_errors'][] = 'Failed to decode staged notes JSON';
        return $result;
    }

    if (empty($staged_notes)) {
        eao_log_save_operation('Staged notes array is empty', 'No notes to process');
        return $result;
    }

    eao_log_save_operation('Processing staged notes', count($staged_notes) . ' notes found');
    
    foreach ($staged_notes as $note_data) {
        if (!isset($note_data['note_content']) || empty(trim($note_data['note_content']))) {
            continue;
        }

        $note_content = wp_kses_post($note_data['note_content']);
        $is_customer_note = isset($note_data['is_customer_note']) && $note_data['is_customer_note'] == 1;
        
        try {
            $note_id = $order->add_order_note($note_content, $is_customer_note, true);
            if ($note_id) {
                $note_type = $is_customer_note ? 'customer' : 'private';
                eao_log_save_operation('Added ' . $note_type . ' note', 'ID: ' . $note_id . ', Content: ' . substr($note_content, 0, 50) . '...');
                $result['notes_processed']++;
            } else {
                $error_msg = 'Failed to add note: ' . substr($note_content, 0, 50) . '...';
                eao_log_save_operation($error_msg, 'add_order_note returned false');
                $result['notes_errors'][] = $error_msg;
            }
        } catch (Exception $e) {
            $error_msg = 'Exception adding note: ' . $e->getMessage();
            eao_log_save_operation($error_msg, 'Note content: ' . substr($note_content, 0, 50) . '...');
            $result['notes_errors'][] = $error_msg;
        }
    }

    eao_log_save_operation('Notes processing complete', $result['notes_processed'] . ' notes added');
    // Clear client-side staged notes by signaling in response meta
    $order->update_meta_data('_eao_notes_last_processed', time());
    return $result;
}

/**
 * Helper function to apply admin configuration to WooCommerce native fields for an order item.
 * Used for both new items and existing items to ensure consistency.
 *
 * @param WC_Order_Item_Product $item The order item to update.
 * @param array $item_config Configuration data for the item.
 * @param array $post_data The full POST data (for global discount).
 * @return bool Whether any changes were made.
 * @since 2.8.5
 */
function eao_apply_admin_config_to_wc_fields($item, $item_config, $post_data) {
    $product = $item->get_product();
    if (!$product) {
        return false;
    }
    
    $changed = false;
    $quantity = max(1, (int) $item->get_quantity());
    // DEBUG: Log incoming config
    if (function_exists('error_log')) {
        error_log('[EAO SAVE] eao_apply_admin_config_to_wc_fields: item_id=' . $item->get_id() . ' cfg=' . wp_json_encode($item_config));
    }
    // Baselines
    // IMPORTANT: Base unit price (subtotal basis) must NEVER change. Always use catalog/client base price.
    $price_from_client = isset($item_config['price_raw']) ? floatval($item_config['price_raw']) : 0.0;
    $base_unit_price = $price_from_client > 0 ? $price_from_client : (float) $product->get_price();
    
    // Incoming exclude/markup intent
    $exclude_global_flag = isset($item_config['exclude_gd']) ? wc_string_to_bool($item_config['exclude_gd']) : false;
    $is_markup_flag = isset($item_config['is_markup']) ? (is_bool($item_config['is_markup']) ? $item_config['is_markup'] : wc_string_to_bool($item_config['is_markup'])) : false;
    $has_fixed_price = isset($item_config['discounted_price_fixed']) && $item_config['discounted_price_fixed'] !== '' && $item_config['discounted_price_fixed'] !== null;
    
    // STAGING FIELDS PRIORITY: If staging fields exist, use them directly instead of recalculating
    if (isset($item_config['_has_wc_staging']) && $item_config['_has_wc_staging'] === 'yes') {
        // Use staging field values directly (from Apply Admin Settings)
        $line_subtotal = isset($item_config['_line_subtotal_staging']) ? floatval($item_config['_line_subtotal_staging']) : ($product_price * $quantity);
        $line_total = isset($item_config['_line_total_staging']) ? floatval($item_config['_line_total_staging']) : $line_subtotal;
        
        eao_log_save_operation('Using staging field values', 'Item ID ' . $item->get_id() . ', staging subtotal: ' . $line_subtotal . ', staging total: ' . $line_total);
    } else {
        // MARKUP BRANCH: Excluded from global discount AND explicit fixed unit price provided
        if ($exclude_global_flag && $is_markup_flag && $has_fixed_price) {
            $fixed_unit = max(0, floatval($item_config['discounted_price_fixed']));
            // For markup, base subtotal must remain the original base (catalog/client) price.
            $line_subtotal = $base_unit_price * $quantity;
            $line_total = $fixed_unit * $quantity;
            eao_log_save_operation('Using MARKUP fixed unit price', 'Item ID ' . $item->get_id() . ', base_unit: ' . $base_unit_price . ', fixed_unit: ' . $fixed_unit . ', qty: ' . $quantity . ', subtotal: ' . $line_subtotal . ', total: ' . $line_total);
        } else {
        // Original logic: Calculate from admin configuration
        // Determine effective discount based on admin configuration
        $effective_discount_percent = 0;
        $exclude_global = $exclude_global_flag;
        
        if ($exclude_global) {
            // Use item-specific discount
            $effective_discount_percent = isset($item_config['discount_percent']) ? floatval($item_config['discount_percent']) : 0;
        } else {
            // Use global discount
            $global_discount = 0;
            if (isset($post_data['eao_order_products_discount_percent'])) {
                $global_discount = floatval($post_data['eao_order_products_discount_percent']);
            }
            $effective_discount_percent = $global_discount;
        }
        
        // Calculate WC native field values based on admin configuration
        $line_subtotal = $base_unit_price * $quantity; // base price never changes
        $discount_amount = ($line_subtotal * $effective_discount_percent) / 100;
        $line_total = $line_subtotal - $discount_amount;
        // Preserve existing TOTAL for excluded items when no explicit percent provided; keep subtotal as base
        if ($exclude_global && !isset($item_config['discount_percent'])) {
            $line_total = (float) $item->get_total();
        }
        
        eao_log_save_operation('Using calculated values', 'Item ID ' . $item->get_id() . ', base_unit=' . $base_unit_price . ', subtotal=' . $line_subtotal . ', total=' . $line_total . ', discount=' . $effective_discount_percent . '%');
        }
    }
    
    // Apply to WooCommerce native fields
    if ((float)$item->get_subtotal() !== (float)wc_format_decimal($line_subtotal, wc_get_price_decimals())) {
        $item->set_subtotal(wc_format_decimal($line_subtotal, wc_get_price_decimals()));
        $changed = true;
        eao_log_save_operation('Updated WC subtotal', 'Item ID ' . $item->get_id() . ', subtotal: ' . $line_subtotal);
    }
    
    if ((float)$item->get_total() !== (float)wc_format_decimal($line_total, wc_get_price_decimals())) {
        $item->set_total(wc_format_decimal($line_total, wc_get_price_decimals()));
        $changed = true;
        eao_log_save_operation('Updated WC total', 'Item ID ' . $item->get_id() . ', total: ' . $line_total . ', discount: ' . $effective_discount_percent . '%');
    }
    
    // Set subtotal_tax and total_tax to 0 for simplicity (can be enhanced later)
    if ($item->get_subtotal_tax() !== 0) {
        $item->set_subtotal_tax(0);
        $changed = true;
    }
    if ($item->get_total_tax() !== 0) {
        $item->set_total_tax(0);
        $changed = true;
    }
    
    return $changed;
}

/**
 * Process order items during save operation.
 * Handles staged items (add), item deletion, and existing item updates.
 *
 * @param WC_Order $order The WooCommerce order object.
 * @param array $post_data The POST data from the save request.
 * @return array Result data with items processing results.
 * @since 1.9.10
 */
function eao_process_order_items($order, $post_data) {
    $result = array(
        'items_added_or_modified' => false,
        'items_deleted' => false,
        'existing_items_updated' => false,
        'items_processed_flag' => false,
        'items_added_count' => 0,
        'items_deleted_count' => 0,
        'items_updated_count' => 0,
        'items_errors' => array(),
        'success' => true
    );

    // --- Process Staged Order Items (ADD NEW PRODUCTS WITH CONSOLIDATION) ---
    if (isset($post_data['eao_staged_items'])) {
        // JSON must not be sanitized as text; keep structure intact
        $staged_items_json = isset($post_data['eao_staged_items']) ? wp_unslash($post_data['eao_staged_items']) : '[]';
        $staged_items = eao_decode_json_safely($staged_items_json, 'staged_items');
        
        if ($staged_items !== null && is_array($staged_items)) {
            eao_log_save_operation('Processing staged items', count($staged_items) . ' items found');
            
            // Group staged items by product_id to consolidate quantities
            $consolidated_staged_items = array();
            foreach ($staged_items as $item_to_add) {
                $product_id = isset($item_to_add['product_id']) ? absint($item_to_add['product_id']) : 0;
                $quantity = isset($item_to_add['quantity']) ? absint($item_to_add['quantity']) : 0;
                
                if ($product_id > 0 && $quantity > 0) {
                    if (isset($consolidated_staged_items[$product_id])) {
                        // Add to existing quantity
                        $consolidated_staged_items[$product_id]['quantity'] += $quantity;
                    } else {
                        // First occurrence of this product
                        $consolidated_staged_items[$product_id] = $item_to_add;
                    }
                }
            }
            
            foreach ($consolidated_staged_items as $product_id => $item_to_add) {
                $quantity = $item_to_add['quantity'];
                
                // Check if this product already exists in the order
                $existing_item_found = false;
                foreach ($order->get_items() as $existing_item_id => $existing_item) {
                    if ($existing_item->get_product_id() == $product_id) {
                        // Product already exists, update quantity
                        $new_quantity = $existing_item->get_quantity() + $quantity;
                        $existing_item->set_quantity($new_quantity);
                        $existing_item->save();
                        $result['items_added_or_modified'] = true;
                        $existing_item_found = true;
                        eao_log_save_operation('Consolidated product', 'ID ' . $product_id . ' - updated existing item ID ' . $existing_item_id . ' to quantity ' . $new_quantity);
                        
                        // Update item-specific metadata if provided
                        if (isset($item_to_add['discount_percent'])) {
                            wc_update_order_item_meta($existing_item_id, '_eao_item_discount_percent', wc_format_decimal(floatval($item_to_add['discount_percent']), 2));
                        }
                        if (isset($item_to_add['exclude_gd'])) {
                            wc_update_order_item_meta($existing_item_id, '_eao_exclude_global_discount', wc_string_to_bool($item_to_add['exclude_gd']));
                        }
                        break;
                    }
                }
                
                if (!$existing_item_found) {
                    // Product doesn't exist, add as new item
                    $product_to_add = wc_get_product($product_id);
                    if ($product_to_add) {
                        $item_id = $order->add_product($product_to_add, $quantity);
                        if ($item_id) {
                            $result['items_added_or_modified'] = true;
                            $result['items_added_count']++;
                            eao_log_save_operation('Added new product', 'ID ' . $product_id . ' quantity ' . $quantity);

                            // Save item-specific discount
                            if (isset($item_to_add['discount_percent'])) {
                                wc_update_order_item_meta($item_id, '_eao_item_discount_percent', wc_format_decimal(floatval($item_to_add['discount_percent']), 2));
                            }

                            // Save exclude global discount flag
                            if (isset($item_to_add['exclude_gd'])) {
                                wc_update_order_item_meta($item_id, '_eao_exclude_global_discount', wc_string_to_bool($item_to_add['exclude_gd']));
                            }
                            
                            // PHASE 4: Apply admin configuration to WC native fields for staged items
                            $item_obj = $order->get_item($item_id);
                            if ($item_obj) {
                                $wc_changed = eao_apply_admin_config_to_wc_fields($item_obj, $item_to_add, $post_data);
                                if ($wc_changed) {
                                    $item_obj->save();
                                    eao_log_save_operation('Applied WC native fields to staged item', 'Item ID: ' . $item_id);
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    // --- Process Items Marked for Deletion (REMOVE PRODUCTS) ---
    if (isset($post_data['eao_items_pending_deletion'])) {
        $items_pending_deletion_json = isset($post_data['eao_items_pending_deletion']) ? wp_unslash($post_data['eao_items_pending_deletion']) : '[]';
        $items_to_delete_ids = eao_decode_json_safely($items_pending_deletion_json, 'items_pending_deletion');
        
        if ($items_to_delete_ids !== null && is_array($items_to_delete_ids) && !empty($items_to_delete_ids)) {
            eao_log_save_operation('Processing item deletions', count($items_to_delete_ids) . ' items marked for deletion');
            foreach ($items_to_delete_ids as $item_id_to_delete_raw) {
                $item_id_to_delete = absint($item_id_to_delete_raw);
                if ($order->get_item($item_id_to_delete)) {
                    $order->remove_item($item_id_to_delete);
                    $result['items_deleted'] = true;
                    $result['items_deleted_count']++;
                    eao_log_save_operation('Deleted item', 'Item ID ' . $item_id_to_delete);
                }
            }
        }
    }

    // --- Process New Items from currentOrderItems (ADD NEW PRODUCTS FROM SEARCH) ---
    if (isset($post_data['eao_order_items_data'])) {
        $order_items_data_json = isset($post_data['eao_order_items_data']) ? wp_unslash($post_data['eao_order_items_data']) : '[]';
        $order_items_data = eao_decode_json_safely($order_items_data_json, 'order_items_data');
        
        if ($order_items_data !== null && is_array($order_items_data)) {
            eao_log_save_operation('DEBUG: Order items data received', count($order_items_data) . ' items total');
            
            // First pass: Add new items (items without item_id)
            $new_items_to_add = array();
            $new_candidates_count = 0;
            foreach ($order_items_data as $client_item) {
                $no_item_id = (!isset($client_item['item_id']) || empty($client_item['item_id']));
                $has_product_id = isset($client_item['product_id']);
                $not_pending_deletion = (!isset($client_item['isPendingDeletion']) || !$client_item['isPendingDeletion']);
                if ($no_item_id && $has_product_id && $not_pending_deletion) {
                    $new_candidates_count++;
                    $product_id = absint($client_item['product_id']);
                    $quantity = isset($client_item['quantity']) ? absint($client_item['quantity']) : 1;
                    if ($product_id > 0 && $quantity > 0) {
                        // Group by product_id to consolidate quantities
                        if (isset($new_items_to_add[$product_id])) {
                            $new_items_to_add[$product_id]['quantity'] += $quantity;
                        } else {
                            $new_items_to_add[$product_id] = $client_item;
                            $new_items_to_add[$product_id]['quantity'] = $quantity;
                        }
                    }
                }
            }
            eao_log_save_operation('NEW-ITEM DETECT', 'Client items without item_id: ' . $new_candidates_count . '; unique to add: ' . count($new_items_to_add));
            
            // Add the consolidated new items
            if (!empty($new_items_to_add)) {
                eao_log_save_operation('Processing new items from currentOrderItems', count($new_items_to_add) . ' unique products to add');
                foreach ($new_items_to_add as $product_id => $item_to_add) {
                    $quantity = $item_to_add['quantity'];
                    
                    // Check if this product already exists in the order
                    $existing_item_found = false;
                    foreach ($order->get_items() as $existing_item_id => $existing_item) {
                        if ($existing_item->get_product_id() == $product_id) {
                            // Product already exists, update quantity
                            $new_quantity = $existing_item->get_quantity() + $quantity;
                            $existing_item->set_quantity($new_quantity);
                            $existing_item->save();
                            $result['items_added_or_modified'] = true;
                            $existing_item_found = true;
                            eao_log_save_operation('Consolidated new product with existing', 'ID ' . $product_id . ' - updated existing item ID ' . $existing_item_id . ' to quantity ' . $new_quantity);
                            break;
                        }
                    }
                    
                    if (!$existing_item_found) {
                        // Product doesn't exist, add as new item
                        $product_to_add = wc_get_product($product_id);
                        if ($product_to_add) {
                            $item_id = $order->add_product($product_to_add, $quantity);
                            if ($item_id) {
                                $result['items_added_or_modified'] = true;
                                $result['items_added_count']++;
                                eao_log_save_operation('Added new product from search', 'ID ' . $product_id . ' quantity ' . $quantity . ', item_id: ' . $item_id);

                                // Save item-specific discount if provided
                                if (isset($item_to_add['discount_percent']) && $item_to_add['discount_percent'] > 0) {
                                    wc_update_order_item_meta($item_id, '_eao_item_discount_percent', wc_format_decimal(floatval($item_to_add['discount_percent']), 2));
                                }

                                // Save exclude global discount flag if provided
                                if (isset($item_to_add['exclude_gd']) && $item_to_add['exclude_gd']) {
                                    wc_update_order_item_meta($item_id, '_eao_exclude_global_discount', wc_string_to_bool($item_to_add['exclude_gd']));
                                }
                                
                                // PHASE 4: Apply admin configuration to WC native fields for new search items
                                $item_obj = $order->get_item($item_id);
                                if ($item_obj) {
                                    $wc_changed = eao_apply_admin_config_to_wc_fields($item_obj, $item_to_add, $post_data);
                                    if ($wc_changed) {
                                        $item_obj->save();
                                        eao_log_save_operation('Applied WC native fields to new search item', 'Item ID: ' . $item_id);
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    // --- Process Existing Item Changes (QUANTITY/DISCOUNT UPDATES) ---
    if (isset($post_data['eao_order_items_data'])) {
        $order_items_data_json = isset($post_data['eao_order_items_data']) ? wp_unslash($post_data['eao_order_items_data']) : '[]';
        $order_items_data = eao_decode_json_safely($order_items_data_json, 'order_items_data');
        
        if ($order_items_data !== null && is_array($order_items_data)) {
            eao_log_save_operation('Processing existing item updates', count($order_items_data) . ' items to update');
            foreach ($order_items_data as $client_item) {
                if (isset($client_item['item_id']) && $client_item['item_id']) {
                    $item_id = absint($client_item['item_id']);
                    $item = $order->get_item($item_id);
                    if ($item) {
                        $changed = false;
                        
                        // Update quantity
                        if (isset($client_item['quantity'])) {
                            $new_quantity = absint($client_item['quantity']);
                            if ($item->get_quantity() !== $new_quantity) {
                                $item->set_quantity($new_quantity);
                                $changed = true;
                            }
                        }
                        
                        // Update discount percentage (skip when markup fixed price is active)
                        if (isset($client_item['discount_percent'])) {
                            $is_markup_flag_u = isset($client_item['is_markup']) ? (is_bool($client_item['is_markup']) ? $client_item['is_markup'] : wc_string_to_bool($client_item['is_markup'])) : false;
                            $has_fixed_price_u = isset($client_item['discounted_price_fixed']) && $client_item['discounted_price_fixed'] !== '' && $client_item['discounted_price_fixed'] !== null;
                            if (!($is_markup_flag_u && $has_fixed_price_u && (isset($client_item['exclude_gd']) ? wc_string_to_bool($client_item['exclude_gd']) : false))) {
                                $item->update_meta_data('_eao_item_discount_percent', wc_format_decimal(floatval($client_item['discount_percent']), 2));
                                $changed = true;
                            } else {
                                // Ensure percent meta removed for markup items to avoid re-capping by third parties
                                $item->delete_meta_data('_eao_item_discount_percent');
                            }
                        }
                        
                        // Update exclude global discount
                        if (isset($client_item['exclude_gd'])) {
                            $item->update_meta_data('_eao_exclude_global_discount', wc_string_to_bool($client_item['exclude_gd']));
                            $changed = true;
                        }
                        
                        // PHASE 4: Apply admin configuration to WooCommerce native fields (markup-aware)
                        // Apply only when: markup intent present, staging totals present, discount percent explicitly provided (numeric),
                        // or exclude flag actually changed vs existing meta.
                        $should_apply_wc_fields = false;
                        $incoming_exclude = isset($client_item['exclude_gd']) ? wc_string_to_bool($client_item['exclude_gd']) : false;
                        $incoming_is_markup = $incoming_exclude && (
                            isset($client_item['is_markup']) && (is_bool($client_item['is_markup']) ? $client_item['is_markup'] : wc_string_to_bool($client_item['is_markup']))
                        ) && isset($client_item['discounted_price_fixed']) && $client_item['discounted_price_fixed'] !== '' && $client_item['discounted_price_fixed'] !== null;

                        // Detect if discount_percent is explicitly provided as a number
                        $has_explicit_percent = array_key_exists('discount_percent', $client_item) && is_numeric($client_item['discount_percent']);
                        // Detect exclude change vs existing meta
                        $existing_exclude = wc_string_to_bool($item->get_meta('_eao_exclude_global_discount', true));
                        $exclude_changed = array_key_exists('exclude_gd', $client_item) && ($incoming_exclude !== $existing_exclude);
                        // Detect presence of global discount intent in POST
                        $has_global_in_post = isset($post_data['eao_order_products_discount_percent']) && is_numeric($post_data['eao_order_products_discount_percent']);

                        if ($incoming_is_markup) {
                            $should_apply_wc_fields = true; // must persist markup totals
                        } elseif (isset($client_item['_has_wc_staging']) && $client_item['_has_wc_staging'] === 'yes') {
                            $should_apply_wc_fields = true; // staging totals present
                            eao_log_save_operation('Staging fields detected', 'Item ID ' . $item_id . ' has staging fields, applying to WC');
                            eao_log_save_operation('DEBUG: Staging field values', '_line_subtotal_staging: ' . ($client_item['_line_subtotal_staging'] ?? 'not set') . ', _line_total_staging: ' . ($client_item['_line_total_staging'] ?? 'not set') . ', _effective_discount_staging: ' . ($client_item['_effective_discount_staging'] ?? 'not set'));
                        } elseif ($has_explicit_percent || $exclude_changed || ($has_global_in_post && !$incoming_is_markup)) {
                            $should_apply_wc_fields = true; // explicit user-driven change
                        } else {
                            // Do NOT apply for unrelated saves to avoid wiping markup
                            eao_log_save_operation('Skipping WC field apply (no staging/markup/explicit change)', 'Item ID ' . $item_id);
                        }
                        
                        if ($should_apply_wc_fields) {
                            $wc_changed = eao_apply_admin_config_to_wc_fields($item, $client_item, $post_data);
                            if ($wc_changed) {
                                $changed = true;
                                eao_log_save_operation('WC fields updated from staging', 'Item ID ' . $item_id);
                            }
                            
                            // Clear staging fields after applying to WC
                            if (isset($client_item['_has_wc_staging'])) {
                                $item->delete_meta_data('_line_subtotal_staging');
                                $item->delete_meta_data('_line_total_staging');
                                $item->delete_meta_data('_effective_discount_staging');
                                $item->delete_meta_data('_has_wc_staging');
                                eao_log_save_operation('Cleared staging fields', 'Item ID ' . $item_id . ' staging fields removed after WC update');
                            }
                        }
                        
                        if ($changed) {
                            $item->save();
                            $result['existing_items_updated'] = true;
                            $result['items_updated_count']++;
                            eao_log_save_operation('Updated existing item with WC native fields', 'Item ID ' . $item_id);
                        }
                    }
                }
            }
        }
    }

    // --- FINALIZATION PASS: Conservative alignment for items explicitly changed ---
    // Only touch items that were present in the client payload, or items affected by a posted global discount and not excluded.
    try {
        $client_map = array();
        if (!empty($order_items_data) && is_array($order_items_data)) {
            foreach ($order_items_data as $ci) {
                if (!empty($ci['item_id'])) {
                    $client_map[absint($ci['item_id'])] = $ci;
                }
            }
        }

        $has_global_percent_posted = isset($post_data['eao_order_products_discount_percent']) && is_numeric($post_data['eao_order_products_discount_percent']);

        foreach ($order->get_items() as $existing_item_id => $existing_item) {
            if (!$existing_item instanceof WC_Order_Item_Product) { continue; }
            // Skip items not explicitly changed unless global percent is posted and item is not excluded
            $cfg = array();
            if (isset($client_map[$existing_item_id])) {
                $cfg = $client_map[$existing_item_id];
                eao_log_save_operation('FINALIZE: processing client item', 'Item ID ' . $existing_item_id . ' cfg=' . wp_json_encode($cfg));
            } else {
                // If no client data, only apply when global discount posted AND item is not excluded
                if (!$has_global_percent_posted) { continue; }
                $is_excluded_meta = wc_string_to_bool($existing_item->get_meta('_eao_exclude_global_discount', true));
                if ($is_excluded_meta) { continue; }
                // Provide minimal cfg to allow global path to run
                $cfg['exclude_gd'] = false;
                eao_log_save_operation('FINALIZE: applying global discount only', 'Item ID ' . $existing_item_id);
            }

            // If exclude flag absent in cfg, derive from meta
            if (!isset($cfg['exclude_gd'])) {
                $cfg['exclude_gd'] = wc_string_to_bool($existing_item->get_meta('_eao_exclude_global_discount', true));
            }

            $wc_changed_final = eao_apply_admin_config_to_wc_fields($existing_item, $cfg, $post_data);
            if ($wc_changed_final) {
                $existing_item->save();
                $result['existing_items_updated'] = true;
                $result['items_updated_count']++;
                eao_log_save_operation('FINALIZE: item saved', 'Item ID ' . $existing_item_id);
            }
        }
    } catch (Exception $e) {
        $result['items_errors'][] = 'Finalization pass error: ' . $e->getMessage();
        eao_log_save_operation('FINALIZE: exception', $e->getMessage());
    }

    // Set overall processing flag
    $result['items_processed_flag'] = $result['items_added_or_modified'] || $result['items_deleted'] || $result['existing_items_updated'];

    // PHASE 4: Recalculate order totals after updating WC native fields
    if ($result['items_processed_flag']) {
        eao_log_save_operation('Recalculating order totals', 'WC native fields updated, calling calculate_totals()');
        // Calculate totals WITHOUT triggering coupon recalculations that might clamp item totals
        try { $order->calculate_totals(false); } catch (Throwable $t) { $order->calculate_totals(); }
        eao_log_save_operation('Order totals recalculated', 'New order total: ' . $order->get_total());
        // Persist global discount percent for consistency
        if (isset($post_data['eao_order_products_discount_percent'])) {
            $order->update_meta_data('_eao_global_product_discount_percent', wc_format_decimal(floatval($post_data['eao_order_products_discount_percent']), 2));
        }
    }

    // PHASE 4B: Process points redemption ONLY when order is processing/completed.
    if (isset($post_data['eao_points_to_redeem'])) {
        $posted_points = absint($post_data['eao_points_to_redeem']);
        $status_now = method_exists($order, 'get_status') ? $order->get_status() : '';
        if (in_array($status_now, array('processing','completed'), true) && function_exists('eao_process_yith_points_redemption')) {
            eao_log_save_operation('Processing YITH points redemption (status=processing/completed)', 'Value posted: ' . $posted_points);
            eao_process_yith_points_redemption($order->get_id(), $post_data);
            try { $order->calculate_totals(false); } catch (Throwable $t) { $order->calculate_totals(); }
        } else {
            // Save intent only; do not touch user balance while pending
            $order->update_meta_data('_eao_pending_points_to_redeem', $posted_points);
            eao_log_save_operation('Stored pending points to redeem (no deduction in pending)', 'Pending points: ' . $posted_points . ', status: ' . $status_now);
            // Remove any applied YITH points coupons during pending state to avoid accidental balance changes by other systems
            $existing_codes = $order->get_coupon_codes();
            foreach ($existing_codes as $code) {
                if (strpos($code, 'ywpar_discount_') === 0) {
                    $order->remove_coupon($code);
                }
            }
            try { $order->calculate_totals(false); } catch (Throwable $t) { $order->calculate_totals(); }
        }
    }

    eao_log_save_operation('Items processing complete', 'Added: ' . $result['items_added_count'] . ', Deleted: ' . $result['items_deleted_count'] . ', Updated: ' . $result['items_updated_count']);
    return $result;
}

/**
 * Process pending shipping rates during save operation.
 * Handles ShipStation rates with multiple format support and meta data management.
 *
 * @param WC_Order $order The WooCommerce order object.
 * @param array $post_data The POST data from the save request.
 * @return array Result data with shipping processing results.
 * @since 1.9.13
 */
function eao_process_shipping_rates($order, $post_data) {
    $result = array(
        'shipping_processed' => false,
        'shipping_method_updated' => false,
        'shipping_amount_updated' => false,
        'shipping_errors' => array(),
        'success' => true
    );

    eao_log_save_operation('Checking for pending shipping rate', 'POST data inspection');
    
    if (!isset($post_data['eao_pending_shipstation_rate']) || empty($post_data['eao_pending_shipstation_rate'])) {
        eao_log_save_operation('No pending shipping rate found', 'eao_pending_shipstation_rate not in POST or empty');
        return $result;
    }

    $pending_shipping_rate_json = wp_unslash($post_data['eao_pending_shipstation_rate']);
    $pending_shipping_rate_data = eao_decode_json_safely($pending_shipping_rate_json, 'pending_shipstation_rate');
    
    if ($pending_shipping_rate_data === null) {
        $result['success'] = false;
        $result['shipping_errors'][] = 'Failed to decode shipping rate JSON: ' . json_last_error_msg();
        eao_log_save_operation('Shipping rate JSON decode failed', json_last_error_msg() . '. Data: ' . substr($pending_shipping_rate_json, 0, 200));
        return $result;
    }

    eao_log_save_operation('Processing pending ShipStation rate', print_r($pending_shipping_rate_data, true));

    // Check for detailed object structure (current expected format)
    if (isset($pending_shipping_rate_data['carrier_code'], 
              $pending_shipping_rate_data['service_code'], 
              $pending_shipping_rate_data['service_name'],
              $pending_shipping_rate_data['originalAmountRaw'], 
              $pending_shipping_rate_data['adjustedAmountRaw'])) {
        
        eao_log_save_operation('Processing detailed ShipStation rate format', 'Full structure detected');
        
        // Remove all existing shipping lines
        $removed_items = 0;
        foreach ($order->get_items('shipping') as $item_id => $item) {
            $order->remove_item($item_id);
            $removed_items++;
            eao_log_save_operation('Removed existing shipping item', 'ID: ' . $item_id);
        }

        // Add the new shipping rate
        $shipping_item = new WC_Order_Item_Shipping();
        
        // Get rate information
        $raw_rate_id = $pending_shipping_rate_data['rate_id'] ?? uniqid('c_');
        $method_title = !empty($pending_shipping_rate_data['service_name']) 
                            ? $pending_shipping_rate_data['service_name'] 
                            : (!empty($pending_shipping_rate_data['service_code']) 
                                ? $pending_shipping_rate_data['service_code'] 
                                : ($pending_shipping_rate_data['carrier_code'] ?? 'Shipping'));

        // Construct standardized method_id
        $carrier_code_raw = $pending_shipping_rate_data['carrier_code'] ?? '';
        $service_code_raw = $pending_shipping_rate_data['service_code'] ?? '';
        $standardized_method_id = '';

        if ($carrier_code_raw === 'stamps_com') {
            $standardized_method_id = 'usps';
            if (!empty($service_code_raw)) {
                $standardized_method_id .= ':' . sanitize_key($service_code_raw);
            }
        } elseif ($carrier_code_raw === 'ups_walleted' || $carrier_code_raw === 'ups') {
            $standardized_method_id = 'ups';
            if (!empty($service_code_raw)) {
                $standardized_method_id .= ':' . sanitize_key($service_code_raw);
            }
        } elseif ($carrier_code_raw === 'fedex_walleted' || $carrier_code_raw === 'fedex') {
            $standardized_method_id = 'fedex';
             if (!empty($service_code_raw)) {
                $standardized_method_id .= ':' . sanitize_key($service_code_raw);
            }
        } else if (!empty($carrier_code_raw)) {
            $standardized_method_id = sanitize_key($carrier_code_raw);
            if (!empty($service_code_raw)) {
                $standardized_method_id .= ':' . sanitize_key($service_code_raw);
            }
        } else {
            $standardized_method_id = 'shipping';
        }
        
        $instance_id_for_shipping = 'eao_ss_' . sanitize_key($raw_rate_id);

        // Build method title with discount information if applied
        $display_method_title = $method_title;
        if (isset($pending_shipping_rate_data['adjustmentType']) && $pending_shipping_rate_data['adjustmentType'] !== 'no_adjustment') {
            $original_amount = floatval($pending_shipping_rate_data['originalAmountRaw']);
            $adjusted_amount = floatval($pending_shipping_rate_data['adjustedAmountRaw']);
            $adjustment_type = $pending_shipping_rate_data['adjustmentType'];
            $adjustment_value = $pending_shipping_rate_data['adjustmentValue'] ?? 0;
            
            if ($adjustment_type === 'percentage_discount' && $adjustment_value > 0) {
                $display_method_title .= sprintf(' (%s%% discount applied)', $adjustment_value);
            } elseif ($adjustment_type === 'fixed_discount' && $adjustment_value > 0) {
                $display_method_title .= sprintf(' (%s discount applied)', wc_price($adjustment_value));
            }
        }
        
        $shipping_item->set_method_title($display_method_title);
        $shipping_item->set_method_id($standardized_method_id);
        $shipping_item->set_instance_id($instance_id_for_shipping);
        $shipping_item->set_total(floatval($pending_shipping_rate_data['adjustedAmountRaw']));
        
        // Add detailed ShipStation info as meta
        $shipping_item->add_meta_data('_eao_shipstation_rate_id', $raw_rate_id, true);
        $shipping_item->add_meta_data('_eao_shipstation_carrier_code', $carrier_code_raw, true);
        $shipping_item->add_meta_data('_eao_shipstation_service_code', $service_code_raw, true);
        $shipping_item->add_meta_data('_eao_shipstation_service_name', $pending_shipping_rate_data['service_name'] ?? '', true);
        $shipping_item->add_meta_data('_eao_shipstation_original_amount', floatval($pending_shipping_rate_data['originalAmountRaw']), true);
        $shipping_item->add_meta_data('_eao_shipstation_adjusted_amount', floatval($pending_shipping_rate_data['adjustedAmountRaw']), true);
        
        if (isset($pending_shipping_rate_data['adjustmentType']) && $pending_shipping_rate_data['adjustmentType'] !== 'no_adjustment') {
            $shipping_item->add_meta_data('_eao_shipstation_adjustment_type', sanitize_text_field($pending_shipping_rate_data['adjustmentType']), true);
            if (isset($pending_shipping_rate_data['adjustmentValue'])) { 
                $shipping_item->add_meta_data('_eao_shipstation_adjustment_value', sanitize_text_field($pending_shipping_rate_data['adjustmentValue']), true);
            }
        }

        $order->add_item($shipping_item);
        eao_log_save_operation('Added ShipStation shipping', 'Method: ' . $method_title . ', ID: ' . $standardized_method_id . ', Amount: ' . $pending_shipping_rate_data['adjustedAmountRaw']);
        
        $result['shipping_processed'] = true;
        $result['shipping_method_updated'] = true;
        $result['shipping_amount_updated'] = true;

    } elseif (is_array($pending_shipping_rate_data)) {
        // Handle alternative/simpler ShipStation rate format
        eao_log_save_operation('Processing alternative ShipStation rate format', 'Simplified structure detected');
        
        $method_title = $pending_shipping_rate_data['method_title'] ?? 
                       $pending_shipping_rate_data['service_name'] ?? 
                       $pending_shipping_rate_data['title'] ?? 'Shipping';
                       
        $rate_amount = $pending_shipping_rate_data['adjustedAmountRaw'] ?? 
                      $pending_shipping_rate_data['amount'] ?? 
                      $pending_shipping_rate_data['cost'] ?? 0;
        
        if ($rate_amount > 0) {
            // Remove existing shipping items
            $removed_items = 0;
            foreach ($order->get_items('shipping') as $item_id => $item) {
                $order->remove_item($item_id);
                $removed_items++;
                eao_log_save_operation('Removed existing shipping item', 'ID: ' . $item_id);
            }
            
            // Add new shipping rate with simpler format
            $shipping_item = new WC_Order_Item_Shipping();
            $shipping_item->set_method_title($method_title);
            $shipping_item->set_method_id('shipstation_rate');
            $shipping_item->set_total(wc_format_decimal($rate_amount, 2));
            
            // Add available metadata
            if (isset($pending_shipping_rate_data['method_title'])) {
                $shipping_item->add_meta_data('_eao_shipstation_method_title', sanitize_text_field($pending_shipping_rate_data['method_title']), true);
            }
            if (isset($pending_shipping_rate_data['carrier'])) {
                $shipping_item->add_meta_data('_eao_shipstation_carrier', sanitize_text_field($pending_shipping_rate_data['carrier']), true);
            }
            
            $order->add_item($shipping_item);
            eao_log_save_operation('Added ShipStation shipping', 'Simple format - Method: ' . $method_title . ', Amount: ' . $rate_amount);
            
            $result['shipping_processed'] = true;
            $result['shipping_method_updated'] = true;
            $result['shipping_amount_updated'] = true;
            
        } else {
            $result['success'] = false;
            $result['shipping_errors'][] = 'Alternative ShipStation rate format has no valid amount';
            eao_log_save_operation('Alternative ShipStation rate format error', 'No valid amount found: ' . print_r($pending_shipping_rate_data, true));
        }
    }

    return $result;
}

/**
 * Process address changes during save operation.
 * Handles both JSON pending addresses and individual field updates.
 *
 * @param WC_Order $order The WooCommerce order object.
 * @param array $post_data The POST data from the save request.
 * @return array Result data with address processing results.
 * @since 1.9.14
 */
function eao_process_address_updates($order, $post_data) {
    // Normalize ThemeHigh address keys to 'address_N'
    if (!function_exists('eao_normalize_thwma_key')) {
        function eao_normalize_thwma_key($raw_key) {
            if (!is_string($raw_key) || $raw_key === '') { return ''; }
            // Match 'th_shipping_address_4', 'th_billing_address_3' or 'address_2'
            if (preg_match('/(?:th_(?:shipping|billing)_address_|address_)(\d+)/', $raw_key, $m)) {
                return 'address_' . $m[1];
            }
            // selected_address should not be used as a writable key
            if (strpos($raw_key, 'selected_address') !== false) { return ''; }
            return $raw_key;
        }
    }
    $result = array(
        'address_updated' => false,
        'billing_updated' => false,
        'shipping_updated' => false,
        'billing_address_key' => null,
        'shipping_address_key' => null,
        'pending_billing_processed' => false,
        'pending_shipping_processed' => false,
        'individual_fields_processed' => 0,
        'address_errors' => array(),
        'success' => true
    );

    eao_log_save_operation('Starting address processing', 'Checking for pending addresses and individual fields');
    // Snapshot incoming keys and update flags for diagnostics
    $snap_pending_bill_key = isset($post_data['eao_pending_billing_address_key']) ? sanitize_text_field(wp_unslash($post_data['eao_pending_billing_address_key'])) : '';
    $snap_pending_ship_key = isset($post_data['eao_pending_shipping_address_key']) ? sanitize_text_field(wp_unslash($post_data['eao_pending_shipping_address_key'])) : '';
    $snap_selected_bill_key = isset($post_data['eao_billing_address_key']) ? sanitize_text_field(wp_unslash($post_data['eao_billing_address_key'])) : '';
    $snap_selected_ship_key = isset($post_data['eao_shipping_address_key']) ? sanitize_text_field(wp_unslash($post_data['eao_shipping_address_key'])) : '';
    $snap_bill_update = (isset($post_data['eao_pending_billing_update_address_book']) && $post_data['eao_pending_billing_update_address_book'] === 'yes') || (isset($post_data['eao_billing_update_address_book']) && $post_data['eao_billing_update_address_book'] === 'yes');
    $snap_ship_update = (isset($post_data['eao_pending_shipping_update_address_book']) && $post_data['eao_pending_shipping_update_address_book'] === 'yes') || (isset($post_data['eao_shipping_update_address_book']) && $post_data['eao_shipping_update_address_book'] === 'yes');
    eao_log_save_operation('Address key snapshot', sprintf('pending_bill=%s, selected_bill=%s, bill_update=%s | pending_ship=%s, selected_ship=%s, ship_update=%s',
        $snap_pending_bill_key ?: 'none',
        $snap_selected_bill_key ?: 'none',
        $snap_bill_update ? 'yes' : 'no',
        $snap_pending_ship_key ?: 'none',
        $snap_selected_ship_key ?: 'none',
        $snap_ship_update ? 'yes' : 'no'
    ));

    // --- Process Pending Billing Address (JSON format) ---
    if (isset($post_data['eao_pending_billing_address']) && !empty($post_data['eao_pending_billing_address'])) {
        $pending_billing_address_json = sanitize_text_field(wp_unslash($post_data['eao_pending_billing_address']));
        $pending_billing_address_data = eao_decode_json_safely($pending_billing_address_json, 'pending_billing_address');

        if ($pending_billing_address_data !== null && is_array($pending_billing_address_data)) {
            eao_log_save_operation('Processing pending billing address', 'Found ' . count($pending_billing_address_data) . ' fields');
            
            // If a THWMA address key provided, update that entry directly
            if (isset($post_data['eao_pending_billing_address_key'])) {
                $raw_key = sanitize_text_field(wp_unslash($post_data['eao_pending_billing_address_key']));
                $norm_key = eao_normalize_thwma_key($raw_key);
                $result['billing_address_key'] = $raw_key;
                update_post_meta($order->get_id(), '_eao_billing_address_key', $raw_key);
                // eao_log_save_operation('Persisted _eao_billing_address_key', 'key=' . ($raw_key ?: 'empty'));
                $is_thwma_key_billing = ($norm_key && strpos($norm_key, 'address_') === 0);
                if ($is_thwma_key_billing) {
                    $customer_id = $order->get_customer_id();
                    $thwma_class = eao_get_thwma_utils_class();
                    if ($customer_id && $thwma_class) {
                        // Prepare address in TH format (billing_*) and skip empties; merge with existing entry
                        $prepared = eao_prepare_thwma_address_array($pending_billing_address_data, 'billing');
                        $address_to_save = eao_merge_thwma_entry($customer_id, 'billing', $norm_key, $prepared);
                        $should_update_book = (isset($post_data['eao_pending_billing_update_address_book']) && $post_data['eao_pending_billing_update_address_book'] === 'yes');
                        eao_log_save_operation('THWMA Billing Update', 'raw_key=' . $raw_key . ' norm_key=' . $norm_key . ' update_book=' . ($should_update_book ? 'yes' : 'no'));
                        if ($should_update_book) {
                            $thwma_class::update_address_to_user($customer_id, $address_to_save, 'billing', $norm_key);
                            // Read-back for verification if available
                            if (method_exists($thwma_class, 'get_custom_addresses')) {
                                $saved_addr = $thwma_class::get_custom_addresses($customer_id, 'billing', $norm_key);
                                eao_log_save_operation('THWMA Billing read-back', is_array($saved_addr) ? json_encode($saved_addr) : 'no data');
                            }
                        }
                        // Always apply to order fields as well
                        foreach ($address_to_save as $akey => $aval) {
                            // Convert type-prefixed key for order setter
                            $plain_key = preg_replace('/^billing_/', '', $akey);
                            $setter = 'set_billing_' . $plain_key;
                            if (method_exists($order, $setter)) {
                                try { $order->{$setter}($aval); } catch (Exception $e) {}
                            }
                        }
                        $result['address_updated'] = true;
                        $result['billing_updated'] = true;
                        $result['pending_billing_processed'] = true;
                        eao_log_save_operation('Updated THWMA billing entry', 'Key ' . $result['billing_address_key']);
                    } else {
                        eao_log_save_operation('THWMA Billing Update skipped', 'customer_id=' . ($customer_id ?: 'none') . ' thwma_class=' . ($thwma_class ? 'yes' : 'no'));
                    }
                } else {
                    // Fallback to WC setters when default_meta selected
                    foreach ($pending_billing_address_data as $key => $value) {
                        $setter = 'set_billing_' . $key;
                        if (method_exists($order, $setter)) {
                            try {
                                $new_value = sanitize_text_field($value);
                                if ($new_value === '' || $new_value === null) { continue; }
                                $old_value = $order->{'get_billing_' . $key}();
                                if ($old_value !== $new_value) {
                                    $order->{$setter}($new_value);
                                    $result['address_updated'] = true;
                                    $result['billing_updated'] = true;
                                    $result['pending_billing_processed'] = true;
                                    eao_log_save_operation('Updated billing_' . $key, 'From "' . $old_value . '" to "' . $new_value . '"');
                                }
                            } catch (WC_Data_Exception $e) {
                                $error_msg = 'WC_Data_Exception setting billing ' . $key + ': ' . $e->getMessage();
                                eao_log_save_operation($error_msg, 'JSON processing error');
                                $result['address_errors'][] = $error_msg;
                            }
                        }
                    }
                }
            } else {
                // No key provided: fallback to WC setters
                foreach ($pending_billing_address_data as $key => $value) {
                    $setter = 'set_billing_' . $key;
                    if (method_exists($order, $setter)) {
                        try {
                            $new_value = sanitize_text_field($value);
                            if ($new_value === '' || $new_value === null) { continue; }
                            $old_value = $order->{'get_billing_' . $key}();
                            if ($old_value !== $new_value) {
                                $order->{$setter}($new_value);
                                $result['address_updated'] = true;
                                $result['billing_updated'] = true;
                                $result['pending_billing_processed'] = true;
                                eao_log_save_operation('Updated billing_' . $key, 'From "' . $old_value . '" to "' . $new_value . '"');
                            }
                        } catch (WC_Data_Exception $e) {
                            $error_msg = 'WC_Data_Exception setting billing ' . $key . ': ' . $e->getMessage();
                            eao_log_save_operation($error_msg, 'JSON processing error');
                            $result['address_errors'][] = $error_msg;
                        }
                    }
                }
                // Fallback to selected key if not processed and address book update is requested
                if (!$result['pending_billing_processed']) {
                    $raw_key_fb = isset($post_data['eao_billing_address_key']) ? sanitize_text_field(wp_unslash($post_data['eao_billing_address_key'])) : '';
                    $norm_key_fb = eao_normalize_thwma_key($raw_key_fb);
                    $is_thwma_key_fb = ($norm_key_fb && strpos($norm_key_fb, 'address_') === 0);
                    $should_update_book_fb = (isset($post_data['eao_pending_billing_update_address_book']) && $post_data['eao_pending_billing_update_address_book'] === 'yes')
                        || (isset($post_data['eao_billing_update_address_book']) && $post_data['eao_billing_update_address_book'] === 'yes');
                    eao_log_save_operation('Billing pending key missing - evaluating fallback', 'selected_raw=' . ($raw_key_fb ?: 'none') . ' norm=' . ($norm_key_fb ?: 'none') . ' update_book=' . ($should_update_book_fb ? 'yes' : 'no'));
                    if ($is_thwma_key_fb && $should_update_book_fb) {
                        $customer_id = $order->get_customer_id();
                        $thwma_class = eao_get_thwma_utils_class();
                        if ($customer_id && $thwma_class) {
                            $prepared = eao_prepare_thwma_address_array($pending_billing_address_data, 'billing');
                            $address_to_save = eao_merge_thwma_entry($customer_id, 'billing', $norm_key_fb, $prepared);
                            $result['billing_address_key'] = $raw_key_fb;
                            update_post_meta($order->get_id(), '_eao_billing_address_key', $raw_key_fb);
                            // eao_log_save_operation('Persisted _eao_billing_address_key (fallback)', 'key=' . ($raw_key_fb ?: 'empty'));
                            eao_log_save_operation('THWMA Billing Update (fallback to selected key)', 'raw_key=' . $raw_key_fb . ' norm_key=' . $norm_key_fb . ' update_book=yes');
                            $thwma_class::update_address_to_user($customer_id, $address_to_save, 'billing', $norm_key_fb);
                            // Apply to order fields
                            foreach ($address_to_save as $akey => $aval) {
                                $plain_key2 = preg_replace('/^billing_/', '', $akey);
                                $setter2 = 'set_billing_' . $plain_key2;
                                if (method_exists($order, $setter2)) {
                                    try { $order->{$setter2}($aval); } catch (Exception $e) {}
                                }
                            }
                            $result['address_updated'] = true;
                            $result['billing_updated'] = true;
                            $result['pending_billing_processed'] = true;
                            // prevent duplicate processing
                            $pending_billing_address_data = array();
                        }
                    }
                }
            }
        } else {
            $error_msg = 'Error decoding pending_billing_address_json: ' . json_last_error_msg();
            eao_log_save_operation($error_msg, 'JSON: ' . substr($pending_billing_address_json, 0, 100) . '...');
            $result['address_errors'][] = $error_msg;
        }
    }

    // --- Process Pending Shipping Address (JSON format) ---
    if (isset($post_data['eao_pending_shipping_address']) && !empty($post_data['eao_pending_shipping_address'])) {
        $pending_shipping_address_json = sanitize_text_field(wp_unslash($post_data['eao_pending_shipping_address']));
        $pending_shipping_address_data = eao_decode_json_safely($pending_shipping_address_json, 'pending_shipping_address');

        if ($pending_shipping_address_data !== null && is_array($pending_shipping_address_data)) {
            eao_log_save_operation('Processing pending shipping address', 'Found ' . count($pending_shipping_address_data) . ' fields');
            // If a THWMA key provided for shipping, update that entry directly
            if (isset($post_data['eao_pending_shipping_address_key'])) {
                $raw_key = sanitize_text_field(wp_unslash($post_data['eao_pending_shipping_address_key']));
                $norm_key = eao_normalize_thwma_key($raw_key);
                $result['shipping_address_key'] = $raw_key;
                update_post_meta($order->get_id(), '_eao_shipping_address_key', $raw_key);
                // eao_log_save_operation('Persisted _eao_shipping_address_key', 'key=' . ($raw_key ?: 'empty'));
                $is_thwma_key_shipping = ($norm_key && strpos($norm_key, 'address_') === 0);
                if ($is_thwma_key_shipping) {
                    $customer_id = $order->get_customer_id();
                    $thwma_class = eao_get_thwma_utils_class();
                    if ($customer_id && $thwma_class) {
                        // Prepare address in TH format (shipping_*) and skip empties; merge with existing entry
                        $prepared = eao_prepare_thwma_address_array($pending_shipping_address_data, 'shipping');
                        $address_to_save = eao_merge_thwma_entry($customer_id, 'shipping', $norm_key, $prepared);
                        $should_update_book = (isset($post_data['eao_pending_shipping_update_address_book']) && $post_data['eao_pending_shipping_update_address_book'] === 'yes');
                        eao_log_save_operation('THWMA Shipping Update', 'raw_key=' . $raw_key . ' norm_key=' . $norm_key . ' update_book=' . ($should_update_book ? 'yes' : 'no'));
                        if ($should_update_book) {
                            $thwma_class::update_address_to_user($customer_id, $address_to_save, 'shipping', $norm_key);
                            // Read-back for verification if available
                            if (method_exists($thwma_class, 'get_custom_addresses')) {
                                $saved_addr = $thwma_class::get_custom_addresses($customer_id, 'shipping', $norm_key);
                                eao_log_save_operation('THWMA Shipping read-back', is_array($saved_addr) ? json_encode($saved_addr) : 'no data');
                            }
                        }
                        // Always apply to order fields as well
                        foreach ($address_to_save as $akey => $aval) {
                            $plain_key = preg_replace('/^shipping_/', '', $akey);
                            $setter = 'set_shipping_' . $plain_key;
                            if (method_exists($order, $setter)) {
                                try { $order->{$setter}($aval); } catch (Exception $e) {}
                            }
                        }
                        $result['address_updated'] = true;
                        $result['shipping_updated'] = true;
                        $result['pending_shipping_processed'] = true;
                        eao_log_save_operation('Updated THWMA shipping entry', 'Key ' . $result['shipping_address_key']);
                        // Do not sync to customer default meta here; keyed saves are scoped to the selected entry
                        // Skip WC setters path in this branch
                        $pending_shipping_address_data = array();
                    } else {
                        eao_log_save_operation('THWMA Shipping Update skipped', 'customer_id=' . ($customer_id ?: 'none') . ' thwma_class=' . ($thwma_class ? 'yes' : 'no'));
                    }
                }
            }

            foreach ($pending_shipping_address_data as $key => $value) {
                $setter = 'set_shipping_' . $key;
                $new_value = sanitize_text_field($value);

                if (method_exists($order, $setter)) {
                    try {
                        $old_value = $order->{'get_shipping_' . $key}();
                        if ($old_value !== $new_value) {
                            $order->{$setter}($new_value);
                            $result['address_updated'] = true;
                            $result['shipping_updated'] = true;
                            $result['pending_shipping_processed'] = true;
                            eao_log_save_operation('Updated shipping_' . $key, 'From "' . $old_value . '" to "' . $new_value . '"');
                        }
                    } catch (WC_Data_Exception $e) {
                        $error_msg = 'WC_Data_Exception setting shipping ' . $key . ': ' . $e->getMessage();
                        eao_log_save_operation($error_msg, 'JSON processing error');
                        $result['address_errors'][] = $error_msg;
                    }
                    // Do not sync to customer default meta here; keyed updates handled separately
                } else {
                    // No WC setter for this field; keyed updates handled above, otherwise skip
                    if ($key !== 'phone') {
                        $error_msg = 'Shipping setter method ' . $setter . ' does not exist';
                        eao_log_save_operation($error_msg, 'Method validation error');
                        $result['address_errors'][] = $error_msg;
                    }
                }
            }
            // If we didn't process via THWMA and we have a selected key + update request, apply fallback
            if (!$result['pending_shipping_processed']) {
                $raw_key_fb = isset($post_data['eao_shipping_address_key']) ? sanitize_text_field(wp_unslash($post_data['eao_shipping_address_key'])) : '';
                $norm_key_fb = eao_normalize_thwma_key($raw_key_fb);
                $is_thwma_key_fb = ($norm_key_fb && strpos($norm_key_fb, 'address_') === 0);
                $should_update_book_fb = (isset($post_data['eao_pending_shipping_update_address_book']) && $post_data['eao_pending_shipping_update_address_book'] === 'yes')
                    || (isset($post_data['eao_shipping_update_address_book']) && $post_data['eao_shipping_update_address_book'] === 'yes');
                eao_log_save_operation('Shipping pending key missing - evaluating fallback', 'selected_raw=' . ($raw_key_fb ?: 'none') . ' norm=' . ($norm_key_fb ?: 'none') . ' update_book=' . ($should_update_book_fb ? 'yes' : 'no'));
                if ($is_thwma_key_fb && $should_update_book_fb) {
                    $customer_id = $order->get_customer_id();
                    $thwma_class = eao_get_thwma_utils_class();
                    if ($customer_id && $thwma_class) {
                        $prepared = eao_prepare_thwma_address_array($pending_shipping_address_data, 'shipping');
                        $address_to_save = eao_merge_thwma_entry($customer_id, 'shipping', $norm_key_fb, $prepared);
                        $result['shipping_address_key'] = $raw_key_fb;
                        update_post_meta($order->get_id(), '_eao_shipping_address_key', $raw_key_fb);
                        // eao_log_save_operation('Persisted _eao_shipping_address_key (fallback)', 'key=' . ($raw_key_fb ?: 'empty'));
                        eao_log_save_operation('THWMA Shipping Update (fallback to selected key)', 'raw_key=' . $raw_key_fb . ' norm_key=' . $norm_key_fb . ' update_book=yes');
                        $thwma_class::update_address_to_user($customer_id, $address_to_save, 'shipping', $norm_key_fb);
                        // Apply to order fields
                        foreach ($address_to_save as $akey => $aval) {
                            $plain_key2 = preg_replace('/^shipping_/', '', $akey);
                            $setter2 = 'set_shipping_' . $plain_key2;
                            if (method_exists($order, $setter2)) {
                                try { $order->{$setter2}($aval); } catch (Exception $e) {}
                            }
                        }
                        $result['address_updated'] = true;
                        $result['shipping_updated'] = true;
                        $result['pending_shipping_processed'] = true;
                        $pending_shipping_address_data = array();
                    }
                }
            }
        } else {
            $error_msg = 'Error decoding pending_shipping_address_json: ' . json_last_error_msg();
            eao_log_save_operation($error_msg, 'JSON: ' . substr($pending_shipping_address_json, 0, 100) . '...');
            $result['address_errors'][] = $error_msg;
        }
    }

    // --- Persist address keys from individual fields submission if present ---
    if (isset($post_data['eao_billing_address_key'])) {
        $result['billing_address_key'] = sanitize_text_field(wp_unslash($post_data['eao_billing_address_key']));
        update_post_meta($order->get_id(), '_eao_billing_address_key', $result['billing_address_key']);
        // eao_log_save_operation('Persisted _eao_billing_address_key (individual fields)', 'key=' . ($result['billing_address_key'] ?: 'empty'));
    }
    if (isset($post_data['eao_shipping_address_key'])) {
        $result['shipping_address_key'] = sanitize_text_field(wp_unslash($post_data['eao_shipping_address_key']));
        update_post_meta($order->get_id(), '_eao_shipping_address_key', $result['shipping_address_key']);
        // eao_log_save_operation('Persisted _eao_shipping_address_key (individual fields)', 'key=' . ($result['shipping_address_key'] ?: 'empty'));
    }

    // --- Process Individual Billing Fields ---
    $active_billing_key_raw = isset($result['billing_address_key']) ? $result['billing_address_key'] : '';
    $active_billing_key = eao_normalize_thwma_key($active_billing_key_raw);
    $is_thwma_billing_key = (!empty($active_billing_key) && strpos($active_billing_key, 'address_') === 0);
    $billing_update_book = (isset($post_data['eao_billing_update_address_book']) && 'yes' === $post_data['eao_billing_update_address_book']);
    $billing_updates_for_thwma = array();
    foreach ($post_data as $post_key => $post_value) {
        if (strpos($post_key, 'eao_billing_') === 0 || strpos($post_key, 'billing_') === 0) {
            $field_key = strpos($post_key, 'eao_billing_') === 0
                ? substr($post_key, strlen('eao_billing_'))
                : substr($post_key, strlen('billing_'));
            $setter = 'set_billing_' . $field_key;
            if ($is_thwma_billing_key && class_exists('THWMA_Utils')) {
                $billing_updates_for_thwma[$field_key] = sanitize_text_field(wp_unslash($post_value));
                $result['address_updated'] = true;
                $result['billing_updated'] = true;
                $result['individual_fields_processed']++;
            } elseif (method_exists($order, $setter)) {
                try {
                    $new_value = sanitize_text_field(wp_unslash($post_value));
                    if ($new_value === '' || $new_value === null) { continue; }
                    $old_value = $order->{'get_billing_' . $field_key}();
                    if ($old_value !== $new_value) {
                        $order->{$setter}($new_value);
                        $result['address_updated'] = true;
                        $result['billing_updated'] = true;
                        $result['individual_fields_processed']++;
                        eao_log_save_operation('Updated billing_' . $field_key, 'From "' . $old_value . '" to "' . $new_value . '"');
                    }
                } catch (WC_Data_Exception $e) {
                    $error_msg = 'WC_Data_Exception setting billing ' . $field_key . ': ' . $e->getMessage();
                    eao_log_save_operation($error_msg, 'Individual field processing error');
                    $result['address_errors'][] = $error_msg;
                }
            }
        }
    }
    if (!empty($billing_updates_for_thwma) && $is_thwma_billing_key) {
        $customer_id = $order->get_customer_id();
        $thwma_class = eao_get_thwma_utils_class();
        if ($customer_id && $billing_update_book && $thwma_class) {
            $prepared = eao_prepare_thwma_address_array($billing_updates_for_thwma, 'billing');
            $merged = eao_merge_thwma_entry($customer_id, 'billing', $active_billing_key, $prepared);
            $thwma_class::update_address_to_user($customer_id, $merged, 'billing', $active_billing_key);
            eao_log_save_operation('Applied THWMA billing updates from individual fields', 'Key ' . $active_billing_key);
        }
    }

    // --- Process Individual Shipping Fields ---
    $active_shipping_key_raw = isset($result['shipping_address_key']) ? $result['shipping_address_key'] : '';
    $active_shipping_key = eao_normalize_thwma_key($active_shipping_key_raw);
    $is_thwma_shipping_key = (!empty($active_shipping_key) && strpos($active_shipping_key, 'address_') === 0);
    $shipping_update_book = (isset($post_data['eao_shipping_update_address_book']) && 'yes' === $post_data['eao_shipping_update_address_book']);
    $shipping_updates_for_thwma = array();
    foreach ($post_data as $post_key => $post_value) {
        if (strpos($post_key, 'eao_shipping_') === 0 || strpos($post_key, 'shipping_') === 0) {
            $field_key = strpos($post_key, 'eao_shipping_') === 0
                ? substr($post_key, strlen('eao_shipping_'))
                : substr($post_key, strlen('shipping_'));
            $setter = 'set_shipping_' . $field_key;
            $new_value = sanitize_text_field(wp_unslash($post_value));
            if ($new_value === '' || $new_value === null) { continue; }

            if ($is_thwma_shipping_key && class_exists('THWMA_Utils')) {
                $shipping_updates_for_thwma[$field_key] = $new_value;
                $result['address_updated'] = true;
                $result['shipping_updated'] = true;
                $result['individual_fields_processed']++;
            } elseif (method_exists($order, $setter)) {
                try {
                    $old_value = $order->{'get_shipping_' . $field_key}();
                    if ($old_value !== $new_value) {
                        $order->{$setter}($new_value);
                        $result['address_updated'] = true;
                        $result['shipping_updated'] = true;
                        $result['individual_fields_processed']++;
                        eao_log_save_operation('Updated shipping_' . $field_key, 'From "' . $old_value . '" to "' . $new_value . '"');
                    }
                } catch (WC_Data_Exception $e) {
                    $error_msg = 'WC_Data_Exception setting shipping ' . $field_key . ': ' . $e->getMessage();
                    eao_log_save_operation($error_msg, 'Individual field processing error');
                    $result['address_errors'][] = $error_msg;
                }
                // Ensure single source of truth for phone (only when not using THWMA key)
                if ($field_key === 'phone' && !$is_thwma_shipping_key) {
                    $customer_id = $order->get_customer_id();
                    if ($customer_id) {
                        $old_um = get_user_meta($customer_id, 'shipping_phone', true);
                        if ($old_um !== $new_value) {
                            update_user_meta($customer_id, 'shipping_phone', $new_value);
                            eao_log_save_operation('Synced user_meta shipping_phone (after setter)', 'From "' . $old_um . '" to "' . $new_value . '"');
                        }
                    }
                }
            } else if ($field_key === 'phone') {
                // Do not update customer default phone when no key provided; apply only to this order
                // Note: WC setter branch above handles writing to order fields already
            }
        }
    }
    if (!empty($shipping_updates_for_thwma) && $is_thwma_shipping_key) {
        $customer_id = $order->get_customer_id();
        $thwma_class = eao_get_thwma_utils_class();
        if ($customer_id && $shipping_update_book && $thwma_class) {
            $prepared = eao_prepare_thwma_address_array($shipping_updates_for_thwma, 'shipping');
            $merged = eao_merge_thwma_entry($customer_id, 'shipping', $active_shipping_key, $prepared);
            $thwma_class::update_address_to_user($customer_id, $merged, 'shipping', $active_shipping_key);
            eao_log_save_operation('Applied THWMA shipping updates from individual fields', 'Key ' . $active_shipping_key);
        }
    }

    if ($result['address_updated']) {
        eao_log_save_operation('Address processing complete', 
            sprintf(
                'Billing: %s, Shipping: %s, Individual fields: %d',
                $result['billing_updated'] ? 'updated' : 'unchanged',
                $result['shipping_updated'] ? 'updated' : 'unchanged', 
                $result['individual_fields_processed']
            )
        );
    } else {
        eao_log_save_operation('Address processing complete', 'No address changes detected');
    }

    return $result;
}

/**
 * Process basic order details during save operation.
 * Handles order date, time, status, customer ID, and global discount processing.
 *
 * @param WC_Order $order The WooCommerce order object.
 * @param array $post_data The POST data from the save request.
 * @return array Result data with basics processing results.
 * @since 1.9.15
 */
function eao_process_basic_order_details($order, $post_data) {
    $result = array(
        'basics_updated' => false,
        'date_updated' => false,
        'status_updated' => false,
        'customer_updated' => false,
        'discount_updated' => false,
        'global_discount_percent' => 0,
        'basics_errors' => array(),
        'success' => true
    );

    eao_log_save_operation('Starting basic order details processing', 'Processing date, status, customer, and discount');

    // Extract basic order details from POST data
    $order_date_str = isset($post_data['eao_order_date']) ? sanitize_text_field(wp_unslash($post_data['eao_order_date'])) : '';
    $order_time_str = isset($post_data['eao_order_time']) ? sanitize_text_field(wp_unslash($post_data['eao_order_time'])) : '';
    $order_status = isset($post_data['eao_order_status']) ? sanitize_text_field(wp_unslash($post_data['eao_order_status'])) : '';
    $customer_id = isset($post_data['eao_customer_id']) ? absint($post_data['eao_customer_id']) : 0;
    
    // Global discount processing - check correct field name
    $global_order_discount_percent = 0;
    if (isset($post_data['eao_order_products_discount_percent'])) {
        $global_order_discount_percent = floatval($post_data['eao_order_products_discount_percent']);
    } elseif (isset($post_data['global_order_discount_percent'])) {
        // Fallback for legacy field name
        $global_order_discount_percent = floatval($post_data['global_order_discount_percent']);
    }
    $result['global_discount_percent'] = $global_order_discount_percent;

    try {
        // --- Update Order Date ---
        if (!empty($order_date_str) && !empty($order_time_str)) {
            $datetime_str = $order_date_str . ' ' . $order_time_str;
            $wp_timezone_str = wp_timezone_string();
            
            try {
                $datetime_obj = new DateTime($datetime_str, new DateTimeZone($wp_timezone_str));
                if ($datetime_obj) {
                    $old_date = $order->get_date_created();
                    $new_timestamp = $datetime_obj->getTimestamp();
                    
                    if (!$old_date || $old_date->getTimestamp() !== $new_timestamp) {
                        $order->set_date_created($new_timestamp);
                        $result['basics_updated'] = true;
                        $result['date_updated'] = true;
                        eao_log_save_operation('Updated order date', 
                            'From: ' . ($old_date ? $old_date->date('Y-m-d H:i:s') : 'none') . 
                            ' To: ' . $datetime_obj->format('Y-m-d H:i:s')
                        );
                    }
                }
            } catch (Exception $e) {
                $error_msg = 'Error creating DateTime object: ' . $e->getMessage();
                eao_log_save_operation($error_msg, 'Date/time: ' . $datetime_str);
                $result['basics_errors'][] = $error_msg;
            }
        }

        // --- Update Order Status ---
        if (!empty($order_status)) {
            $old_status = $order->get_status();
            if ($old_status !== $order_status) {
                $order->set_status($order_status);
                $result['basics_updated'] = true;
                $result['status_updated'] = true;
                eao_log_save_operation('Updated order status', 'From: ' . $old_status . ' To: ' . $order_status);
            }
        }

        // --- Update Customer ID ---
        if ($customer_id && $order->get_customer_id() !== $customer_id) {
            $old_customer_id = $order->get_customer_id();
            $order->set_customer_id($customer_id);
            $result['basics_updated'] = true;
            $result['customer_updated'] = true;
            eao_log_save_operation('Updated customer ID', 'From: ' . $old_customer_id . ' To: ' . $customer_id);
        }

        // --- Ensure billing email is populated when saving ---
    try {
        $current_email = method_exists($order, 'get_billing_email') ? (string) $order->get_billing_email() : '';
            if ($current_email === '') {
                $candidate_id = $order->get_customer_id() ? $order->get_customer_id() : $customer_id;
                if ($candidate_id) {
                    $u = get_user_by('id', $candidate_id);
                    if ($u && !empty($u->user_email)) {
                        if (method_exists($order, 'set_billing_email')) {
                            $order->set_billing_email($u->user_email);
                            $result['basics_updated'] = true;
                        }
                    }
                }
                // Fallback to existing order meta if present
                if ($current_email === '' && method_exists($order, 'get_meta')) {
                    $meta_email = (string) $order->get_meta('_billing_email', true);
                    if ($meta_email !== '' && method_exists($order, 'set_billing_email')) {
                        $order->set_billing_email($meta_email);
                        $result['basics_updated'] = true;
                    }
                }
        }
        } catch (Exception $e) { /* no-op */ }

        // --- Process Global Discount Percentage ---
        if ($global_order_discount_percent > 0 && $global_order_discount_percent <= 100) {
            $old_discount = $order->get_meta('_eao_global_product_discount_percent');
            $new_discount = wc_format_decimal($global_order_discount_percent, 2);
            
            if ($old_discount !== $new_discount) {
                $order->update_meta_data('_eao_global_product_discount_percent', $new_discount);
                $result['basics_updated'] = true;
                $result['discount_updated'] = true;
                eao_log_save_operation('Updated global discount percentage', 
                    'From: ' . ($old_discount ?: '0') . '% To: ' . $global_order_discount_percent . '%'
                );
            }
        } else {
            // Remove global discount meta if invalid or zero
            if ($order->get_meta('_eao_global_product_discount_percent')) {
                $order->delete_meta_data('_eao_global_product_discount_percent');
                $result['basics_updated'] = true;
                $result['discount_updated'] = true;
                
                if ($global_order_discount_percent != 0) {
                    $error_msg = 'Invalid global discount percentage (' . $global_order_discount_percent . '%), removed from order meta';
                    eao_log_save_operation($error_msg, 'Discount validation failed');
                    $result['basics_errors'][] = $error_msg;
                } else {
                    eao_log_save_operation('Removed global discount meta', 'Discount set to zero');
                }
            }
        }

    } catch (Exception $e) {
        $error_msg = 'Exception in basic order details processing: ' . $e->getMessage();
        eao_log_save_operation($error_msg, 'Basics processing error');
        $result['basics_errors'][] = $error_msg;
        $result['success'] = false;
    }

    if ($result['basics_updated']) {
        eao_log_save_operation('Basic order details processing complete', 
            sprintf(
                'Date: %s, Status: %s, Customer: %s, Discount: %s',
                $result['date_updated'] ? 'updated' : 'unchanged',
                $result['status_updated'] ? 'updated' : 'unchanged',
                $result['customer_updated'] ? 'updated' : 'unchanged',
                $result['discount_updated'] ? 'updated' : 'unchanged'
            )
        );
    } else {
        eao_log_save_operation('Basic order details processing complete', 'No basic details changes detected');
    }

    return $result;
}

/**
 * Process payment data during save operation.
 * Handles payment information from the mockup payment system.
 *
 * @param WC_Order $order The WooCommerce order object.
 * @param array $post_data The POST data from the save request.
 * @return array Result data with payment processing results.
 * @since 2.7.33
 */
function eao_process_payment_data($order, $post_data) {
    $result = array(
        'payment_processed' => false,
        'payment_gateway' => '',
        'payment_amount' => 0,
        'payment_status' => '',
        'transaction_id' => '',
        'payment_errors' => array(),
        'success' => true
    );

    eao_log_save_operation('Starting payment data processing', 'Checking for payment information');

    try {
        // Check if payment data exists
        $payment_gateway = isset($post_data['eao_payment_payment_gateway']) ? sanitize_text_field(wp_unslash($post_data['eao_payment_payment_gateway'])) : '';
        $payment_amount = isset($post_data['eao_payment_payment_amount']) ? floatval($post_data['eao_payment_payment_amount']) : 0;
        $payment_status = isset($post_data['eao_payment_payment_status']) ? sanitize_text_field(wp_unslash($post_data['eao_payment_payment_status'])) : '';
        $transaction_id = isset($post_data['eao_payment_transaction_id']) ? sanitize_text_field(wp_unslash($post_data['eao_payment_transaction_id'])) : '';
        $payment_processed_at = isset($post_data['eao_payment_payment_processed_at']) ? sanitize_text_field(wp_unslash($post_data['eao_payment_payment_processed_at'])) : '';
        
        // Extract card details for display
        $card_number = isset($post_data['eao_payment_card_number']) ? sanitize_text_field(wp_unslash($post_data['eao_payment_card_number'])) : '';
        $card_last_four = '';
        
        // Debug logging for card number extraction
        eao_log_save_operation('Card number extraction debug', 'Card number received: ' . ($card_number ? 'YES (' . substr($card_number, 0, 4) . '****)' : 'NO'));
        
        if ($card_number) {
            // Extract last 4 digits from card number (remove spaces/formatting)
            $clean_card = preg_replace('/[^0-9]/', '', $card_number);
            if (strlen($clean_card) >= 4) {
                $card_last_four = substr($clean_card, -4);
                eao_log_save_operation('Card last four extracted', 'Last 4 digits: ' . $card_last_four);
            } else {
                eao_log_save_operation('Card number too short', 'Clean card length: ' . strlen($clean_card));
            }
        } else {
            eao_log_save_operation('No card number found', 'Field eao_payment_card_number not in POST data');
        }
        
        // Generate mock authorization code
        $auth_code = 'AUTH' . rand(100000, 999999);
        
        if (empty($payment_gateway) || $payment_amount <= 0) {
            eao_log_save_operation('No payment data found', 'No payment processing required');
            return $result;
        }
        
        eao_log_save_operation('Processing payment data', sprintf(
            'Gateway: %s, Amount: %s, Status: %s, Transaction: %s',
            $payment_gateway,
            $payment_amount,
            $payment_status,
            $transaction_id
        ));
        
        // Store payment data in order meta (individual fields)
        $order->update_meta_data('_eao_payment_gateway', $payment_gateway);
        $order->update_meta_data('_eao_payment_amount', wc_format_decimal($payment_amount, 2));
        $order->update_meta_data('_eao_payment_transaction_id', $transaction_id);
        $order->update_meta_data('_eao_payment_processed_at', $payment_processed_at);
        $order->update_meta_data('_eao_payment_card_last_four', $card_last_four);
        $order->update_meta_data('_eao_payment_auth_code', $auth_code);
        
        // Also store as combined data for easy checking
        $payment_data = array(
            'gateway' => $payment_gateway,
            'amount' => wc_format_decimal($payment_amount, 2),
            'transaction_id' => $transaction_id,
            'processed_at' => $payment_processed_at,
            'status' => $payment_status,
            'card_last_four' => $card_last_four,
            'auth_code' => $auth_code
        );
        $order->update_meta_data('_eao_payment_data', $payment_data);
        
        // Set payment method on order
        $order->set_payment_method($payment_gateway);
        $order->set_payment_method_title(ucfirst(str_replace('_', ' ', $payment_gateway)));
        
        // Add transaction ID if available
        if (!empty($transaction_id)) {
            $order->set_transaction_id($transaction_id);
        }
        
        // Update order status if payment was successful
        if ($payment_status === 'processing' || $payment_status === 'completed') {
            $order->set_status($payment_status);
            
            // Add order note for payment
            $order->add_order_note(sprintf(
                'Payment processed via %s. Amount: %s. Transaction ID: %s',
                ucfirst(str_replace('_', ' ', $payment_gateway)),
                wc_price($payment_amount),
                $transaction_id
            ), false, true);
            
            eao_log_save_operation('Payment processed successfully', 'Order status updated and note added');
        }
        
        // Set result data
        $result['payment_processed'] = true;
        $result['payment_gateway'] = $payment_gateway;
        $result['payment_amount'] = $payment_amount;
        $result['payment_status'] = $payment_status;
        $result['transaction_id'] = $transaction_id;
        
    } catch (WC_Data_Exception $e) {
        $error_msg = 'WC_Data_Exception in payment processing: ' . $e->getMessage();
        eao_log_save_operation($error_msg, 'Payment data validation failed');
        $result['payment_errors'][] = $error_msg;
        $result['success'] = false;
    } catch (Exception $e) {
        $error_msg = 'Exception in payment processing: ' . $e->getMessage();
        eao_log_save_operation($error_msg, 'Payment processing error');
        $result['payment_errors'][] = $error_msg;
        $result['success'] = false;
    }

    if ($result['payment_processed']) {
        eao_log_save_operation('Payment data processing complete', 'Payment information saved to order');
    } else {
        eao_log_save_operation('Payment data processing complete', 'No payment information processed');
    }

    return $result;
}

// REMOVED: eao_ajax_apply_admin_config_to_item() function
// Apply Admin Settings is now purely frontend operation (staging system compliance)
// No backend AJAX endpoint needed - staging fields updated in JavaScript only 