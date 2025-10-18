<?php
/**
 * Enhanced Admin Order - Product Management Functions
 * Handles product search, display, and order item management
 * 
 * @package EnhancedAdminOrder
 * @since 1.5.6
 * @version 2.8.2 - Add shipments meta to products data for per-shipment quantities
 * @author Amnon Manneberg
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Adds the Order Products meta box to our custom order editor page.
 *
 * @since 1.2.6
 * @param WC_Order $order The order object.
 */
function eao_add_order_products_meta_box( $order ) {
    if ( ! is_a( $order, 'WC_Order' ) ) {
        error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] eao_add_order_products_meta_box: Received invalid or no WC_Order object.');
        return;
    }

    $screen_id = get_current_screen()->id;
    add_meta_box(
        'eao_order_products_metabox',            // ID
        __( 'Order Products', 'enhanced-admin-order' ), // Title
        'eao_render_order_products_meta_box_content', // Callback
        $screen_id,                             // Screen
        'normal',                               // Context (normal, side, advanced)
        'high'                                  // Priority (high, core, default, low)
    );
    error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] eao_order_products_metabox added.');
}

/**
 * Renders the content for the Order Products meta box.
 *
 * @since 1.2.6
 * @param WC_Order $order The order object (passed by do_meta_boxes).
 * @param array    $metabox Actual meta box arguments from add_meta_box().
 */
function eao_render_order_products_meta_box_content( $order, $metabox ) {
    // Ensure $order is the WC_Order object if $post_or_order is passed by WordPress directly.
    if ( ! is_a( $order, 'WC_Order' ) ) {
        $order_id = 0;
        if (is_object($order) && isset($order->ID)) { 
            $order_id = $order->ID;
        } elseif (isset($_GET['order_id'])) { 
            $order_id = absint($_GET['order_id']);
        } else {
            echo '<p>' . esc_html__( 'Error: Could not determine order ID for products meta box.', 'enhanced-admin-order' ) . '</p>';
            return;
        }
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            echo '<p>' . esc_html__( 'Error: Could not load order for products meta box.', 'enhanced-admin-order' ) . '</p>';
            return;
        }
    }

    wp_nonce_field( 'eao_product_operations_nonce', 'eao_product_operations_nonce_field' );

    ?>
    <div id="eao-order-products-container">
        <div id="eao-product-controls-row" style="display: flex; align-items: center; margin-top: 10px; margin-bottom: 10px; padding-bottom: 0; gap: 12px;">
            <div class="eao-search-area-wrapper" style="flex: 1 1 auto; min-width: 250px; margin-right: 0; position: relative;">
                <input type="search" id="eao_product_search_term" name="eao_product_search_term" 
                       placeholder="<?php esc_attr_e( 'Search to add products by name or SKU...', 'enhanced-admin-order' ); ?>" 
                       class="widefat" style="margin-bottom: 0; width: 100%;">
                <div id="eao_product_search_results">
                    <?php // Search results will be populated by JavaScript ?>
                </div>
            </div>

            <div class="eao-controls-separator" aria-hidden="true"></div>

            <div id="eao-order-global-discount-alignment-row" class="eao-product-editor-headers" style="display: flex; flex: 0 0 auto; background-color: transparent; padding: 0; border-bottom: none; font-weight: normal; min-width: max-content;"> 
                <div class="eao-header-thumb" style="visibility: hidden; flex-shrink: 1;"></div>
                <div class="eao-header-product" style="visibility: hidden; flex-shrink: 1;"></div>
                <div class="eao-header-price" style="visibility: hidden; flex-shrink: 1;"></div>
                <div class="eao-header-exclude-discount" style="visibility: hidden; flex-shrink: 1;"></div>
                <div class="eao-header-discount" style="visibility: hidden; flex-shrink: 1;"></div> 
                
                <div class="eao-header-discounted-price eao-global-discount-cell" style="display: flex; align-items: center; justify-content: center; flex-direction: row; flex-wrap: nowrap; flex-shrink: 0; /* Don't let the cell itself shrink too much */"> 
                    <div class="eao-discount-controls" style="display: inline-flex;">
                         <?php
                         // Load saved global discount percentage from order meta
                         $saved_global_discount = $order->get_meta('_eao_global_product_discount_percent', true);
                         $global_discount_value = $saved_global_discount ? floatval($saved_global_discount) : 0;
                         ?>
                         <input type="number" id="eao_order_products_discount_percent" name="eao_order_products_discount_percent" class="eao-discount-percent-input" value="<?php echo esc_attr($global_discount_value); ?>" min="0" max="100" step="1">
                         <span class="eao-percentage-symbol">%</span>
                    </div>
                    <label for="eao_order_products_discount_percent" class="eao-gd-label" style="margin-left: 8px; font-weight: normal; display: inline-flex; flex-direction: column; line-height: 1.1; text-align: left;">
                        <span><?php esc_html_e( 'Global Product', 'enhanced-admin-order' ); ?></span>
                        <span><?php esc_html_e( 'Discount', 'enhanced-admin-order' ); ?></span>
                    </label>
                </div>

                <div class="eao-header-quantity" style="visibility: hidden; flex-shrink: 1;"></div>
                <div class="eao-header-total" style="visibility: hidden; flex-shrink: 1;"></div>
                <div class="eao-header-actions" style="visibility: hidden; flex-shrink: 1;"></div>
            </div>
        </div>
        
        <div id="eao-order-items-display-edit-section">
            <div id="eao-current-order-items-list">
                <p><?php esc_html_e( 'Loading order items...', 'enhanced-admin-order' ); ?></p>
            </div>
        </div>

        <div id="eao-order-items-summary-section" style="padding-top: 15px; margin-top: 15px; border-top: 1px solid #eee;">
            <div id="eao-current-order-items-summary">
                <p><?php esc_html_e( 'Summary will appear here.', 'enhanced-admin-order' ); ?></p>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Build shipments meta (Shipment 1, Shipment 2, ...) with product quantities per shipment
 * Requires AST PRO TPI data (products_list) when available.
 *
 * @param int $order_id
 * @return array { 'num_shipments'=>int, 'columns'=>[], 'product_qty_map'=>[product_id=>[index=>qty]] }
 */
function eao_get_shipments_meta_for_order( $order_id ) {
    $result = array(
        'num_shipments' => 0,
        'columns' => array(),
        'product_qty_map' => array(),
    );
    $order_obj = wc_get_order( $order_id );
    $order_status = $order_obj ? strtolower( $order_obj->get_status() ) : '';

    // Prefer AST PRO if available (has TPI products_list)
    try {
        if ( function_exists('ast_pro') && is_object( ast_pro()->ast_pro_actions ) && method_exists( ast_pro()->ast_pro_actions, 'get_tracking_items' ) ) {
            $tracking_items = ast_pro()->ast_pro_actions->get_tracking_items( $order_id, true );
            if ( is_array( $tracking_items ) && ! empty( $tracking_items ) ) {
                $shipment_index = 0;
                foreach ( $tracking_items as $ti ) {
                    // Only count shipments that have an explicit products_list to avoid misleading extra columns
                    $products_list = isset($ti['products_list']) ? $ti['products_list'] : array();
                    if ( empty($products_list) ) {
                        continue;
                    }
                    $shipment_index++;
                    $label = 'Shipment ' . $shipment_index;
                    // Extract status from AST/TrackShip structures where available
                    $status_raw = '';
                    if ( isset($ti['status']) ) { $status_raw = $ti['status']; }
                    if ( empty($status_raw) && isset($ti['tracking_status']) ) { $status_raw = $ti['tracking_status']; }
                    if ( empty($status_raw) && isset($ti['ts_status']) ) { $status_raw = $ti['ts_status']; }
                    if ( empty($status_raw) && isset($ti['formatted_tracking_status']) ) { $status_raw = $ti['formatted_tracking_status']; }
                    $status_text = is_string($status_raw) ? $status_raw : '';
                    $status_slug = strtolower( preg_replace('/[^a-z]+/i', '_', $status_text ) );
                    // Fallback when tracking item carries no explicit status: infer from order status
                    if ( empty( $status_slug ) ) {
                        if ( strpos( $order_status, 'partial' ) !== false ) {
                            $status_text = 'Partial Shipped';
                            $status_slug = 'partial_shipped';
                        } elseif ( $order_status === 'delivered' || $order_status === 'completed' ) {
                            $status_text = 'Delivered';
                            $status_slug = 'delivered';
                        } elseif ( $order_status === 'shipped' ) {
                            $status_text = 'Shipped';
                            $status_slug = 'shipped';
                        }
                    }
                    $result['columns'][] = array(
                        'index' => $shipment_index,
                        'label' => $label,
                        'tracking_number' => isset($ti['tracking_number']) ? $ti['tracking_number'] : '',
                        'tracking_provider' => isset($ti['formatted_tracking_provider']) ? $ti['formatted_tracking_provider'] : ( isset($ti['tracking_provider']) ? $ti['tracking_provider'] : '' ),
                        'status_text' => $status_text,
                        'status_slug' => $status_slug,
                    );

                    // Normalize list to array
                    if ( is_object( $products_list ) ) {
                        $products_list = (array) $products_list;
                    }
                    foreach ( (array) $products_list as $product_entry ) {
                        if ( is_object( $product_entry ) ) { $product_entry = (array) $product_entry; }
                        $pid = isset($product_entry['product']) ? intval($product_entry['product']) : ( isset($product_entry['product_id']) ? intval($product_entry['product_id']) : 0 );
                        $qty = isset($product_entry['qty']) ? floatval($product_entry['qty']) : ( isset($product_entry['quantity']) ? floatval($product_entry['quantity']) : 0 );
                        if ( $pid > 0 ) {
                            if ( ! isset( $result['product_qty_map'][ $pid ] ) ) {
                                $result['product_qty_map'][ $pid ] = array();
                            }
                            $result['product_qty_map'][ $pid ][ $shipment_index ] = $qty;
                        }
                    }
                }
                $result['num_shipments'] = $shipment_index;
            }
        }
    } catch ( Exception $e ) {
        // Fail silently and return empty structure
    }

    return $result;
}

/**
 * AJAX handler for fetching the initial HTML for order items and summary.
 * @since 1.2.6
 * MODIFIED: Now fetches structured data instead of HTML.
 * @since 1.2.9 (Refactor for JSON data)
 */
// add_action('wp_ajax_eao_get_order_products_html', 'eao_ajax_get_order_products_html_handler'); // OLD ACTION
add_action('wp_ajax_eao_get_order_products_data', 'eao_ajax_get_order_products_data_handler'); // NEW ACTION

// function eao_ajax_get_order_products_html_handler() { // OLD NAME
function eao_ajax_get_order_products_data_handler() { // NEW NAME
    // error_log('[EAO AJAX DEBUG] eao_ajax_get_order_products_data_handler called - Step 3 debugging');
    
    // Nonce verification - use the correct nonce for product operations
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'eao_product_operations_nonce')) {
        eao_log_save_operation('Nonce verification FAILED in product data handler', 'Security check failed');
        wp_send_json_error(array('message' => 'Nonce verification failed.'));
        return;
    }
    
    // eao_log_save_operation('Nonce verification PASSED', 'Security check passed for product data handler');
    // error_log('[EAO Get Products Data AJAX] Nonce verification PASSED.');
    
    // Start output buffering to capture any unwanted output
    ob_start();
    
    try {
        // error_log('[EAO Get Products Data AJAX DEBUG] TRY BLOCK ENTERED');
        
        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        // error_log('[EAO Get Products Data AJAX DEBUG] Order ID from POST: ' . $order_id);
        
        if (!$order_id) {
            $captured_output = ob_get_clean();
            if (!empty($captured_output)) {
                error_log('[EAO AJAX] Captured unexpected output: ' . json_encode($captured_output));
            }
            wp_send_json_error(array('message' => 'Order ID is required.'));
            return;
        }
        
        // Load order object
        $order = wc_get_order($order_id);
        // error_log('[EAO Get Products Data AJAX DEBUG] wc_get_order result: ' . (is_object($order) ? get_class($order) : gettype($order)));
        
        if (!$order) {
            $captured_output = ob_get_clean();
            if (!empty($captured_output)) {
                error_log('[EAO AJAX] Captured unexpected output: ' . json_encode($captured_output));
            }
            wp_send_json_error(array('message' => 'Invalid order ID.'));
            return;
        }
        
        // error_log('[EAO Get Products Data AJAX DEBUG] Order object successfully loaded. Proceeding with data extraction.');
        
        // PHASE 1 ENHANCEMENT: Get global discount for inconsistency detection
        $configured_global_discount = floatval($order->get_meta('_eao_global_product_discount_percent', true));
        
        $items_data = array();
        foreach ( $order->get_items() as $item_id => $item ) {
            if ( !$item instanceof WC_Order_Item_Product ) continue;
            
            $product = $item->get_product();
            // Use WooCommerce's pre-discount unit price for display (order snapshot)
            $price_raw = $order->get_item_subtotal( $item, false, false );
            $line_subtotal = $item->get_subtotal();
            $line_total = $item->get_total();
            $qty = max(1, (int) $item->get_quantity());
            $wc_unit_total = $line_total / $qty;
            // $price_raw remains base display price; do not derive from stored subtotal to avoid display doubling

            // PHASE 1 ENHANCEMENT: Configuration Authority Data Loading
            // CONFIGURATION DATA (User Intent)
            $configured_discount_percent = floatval($item->get_meta('_eao_item_discount_percent', true));
            $configured_exclude_gd = wc_string_to_bool($item->get_meta('_eao_exclude_global_discount', true));
            // Establish catalog/base unit price for consistent calculations
            $catalog_unit_price = $product ? floatval( wc_get_price_to_display( $product ) ) : floatval( $price_raw );
            // If no explicit config exists and item is NOT excluded, infer percent from WC vs catalog price for display accuracy
            if ($configured_discount_percent == 0 && !$configured_exclude_gd) {
                $wc_unit_subtotal = $qty > 0 ? ($line_subtotal / $qty) : 0;
                if ($catalog_unit_price > 0 && $wc_unit_subtotal < $catalog_unit_price - 0.0001) {
                    $configured_discount_percent = round((($catalog_unit_price - $wc_unit_subtotal) / $catalog_unit_price) * 100, 2);
                }
            }

            // MARKUP/FIXED-PRICE RESOLUTION (coupon-proof)
            // Use subtotal-based unit (pre-coupon) to recover the admin-configured fixed unit for excluded items.
            $is_markup = false;
            $discounted_price_fixed = null;
            if ($configured_exclude_gd) {
                // If subtotal == total (per-line) for excluded item, treat as fixed/markup by default
                $admin_fixed_unit = $qty > 0 ? ($line_subtotal / $qty) : 0;
                if ($configured_discount_percent > 0) {
                    $is_markup = false;
                    // Compute fixed discounted unit from explicit percent for excluded items
                    $discounted_price_fixed = round($catalog_unit_price * (1 - ($configured_discount_percent / 100)), wc_get_price_decimals());
                } else if (abs($line_subtotal - $line_total) < 0.01) {
                    $is_markup = true;
                    $discounted_price_fixed = $admin_fixed_unit;
                } else {
                    // Non-zero discount difference but excluded with no explicit percent – don't flag as markup, keep percent 0
                    $is_markup = false;
                }
            }
            
            // WOOCOMMERCE REALITY DATA
            // Use WC getters as the single source of truth; avoid relying on stored meta that can be 0 or stale
            $wc_line_subtotal = $line_subtotal; // $item->get_subtotal()
            $wc_line_total    = $line_total;    // $item->get_total()
            $wc_line_discount = $wc_line_subtotal - $wc_line_total;
            
            // CALCULATE WHAT DISCOUNT SHOULD BE (based on configuration)
            $expected_discount_percent = $configured_discount_percent;
            if (!$configured_exclude_gd && $configured_global_discount > 0 && $configured_discount_percent == 0) {
                $expected_discount_percent = $configured_global_discount;
            }
            
            // CALCULATE WHAT DISCOUNT ACTUALLY IS (from WC fields)
            $actual_discount_percent = 0;
            if ($wc_line_subtotal > 0) {
                $actual_discount_percent = round((($wc_line_discount) / $wc_line_subtotal) * 100, 2);
            }
            
            // DETECT INCONSISTENCY (conservative)
            // Rule:
            // - Never flag non-excluded items here (global discount often not stored per-line by WC)
            // - For excluded items:
            //   - If markup was detected, never flag (subtotal==total by design)
            //   - If a per-item percent was explicitly configured (meta), compare expected vs actual
            //   - If NO explicit per-item percent exists, treat WC totals as authoritative and do NOT flag
            if ($configured_exclude_gd) {
                // Excluded lines: if subtotal == total, treat as fixed and do not flag, regardless of stray percent meta
                if (abs($wc_line_subtotal - $wc_line_total) < 0.01) {
                    $has_inconsistency = false;
                    // Keep explicit per-item percent if it exists; otherwise, treat as fixed/markup
                    if ($configured_discount_percent <= 0) {
                        $is_markup = true;
                        $discounted_price_fixed = ($qty > 0) ? ($wc_line_total / $qty) : 0;
                    } else {
                        $is_markup = false;
                        $discounted_price_fixed = null;
                    }
                } else if ($configured_discount_percent > 0) {
                    $has_inconsistency = (abs($expected_discount_percent - $actual_discount_percent) > 0.1);
                } else {
                    $has_inconsistency = false;
                }
            } else {
                // Non-excluded: if WC totals (unit subtotal vs total) already reflect a line discount
                // and we have no explicit per-item percent configured, do not flag.
                // This prevents the false banner on duplicates where WC holds a fixed discounted price.
                if ($configured_discount_percent <= 0) {
                    // WC already holds discounted unit or not; treat WC as source of truth, do not flag
                    $has_inconsistency = false;
                } else {
                    $has_inconsistency = (abs($expected_discount_percent - $actual_discount_percent) > 0.1);
                }
            }
            
            // BACKWARD COMPATIBILITY: Use configuration values for display (Configuration Authority)
            // Treat global-discount-only as 0% on the item to avoid false banner; WC will apply global discount in total math
            // If WC already holds a fixed discounted unit for non-excluded items and no explicit per-item percent exists,
            // also surface 0 so the UI does not show a mismatch banner.
            // For excluded items, prefer explicit per-item percent when present;
            // do NOT zero it out on rehydrate. For non-excluded items, keep 0 (global applies at summary).
            $item_discount_percent_to_send = $configured_exclude_gd ? $configured_discount_percent : 0;
            
            $product_id = $item->get_product_id();
            $variation_id = $item->get_variation_id();
            $thumbnail_url = $product ? esc_url(wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' )) : esc_url(wc_placeholder_img_src());
            if (!$thumbnail_url && $product && $product->is_type('variation')) {
                $parent_product = wc_get_product($product->get_parent_id());
                if ($parent_product && $parent_product->get_image_id()) {
                     $thumbnail_url = esc_url(wp_get_attachment_image_url( $parent_product->get_image_id(), 'thumbnail' ));
                }
            }
            if (!$thumbnail_url) { // Final fallback if nothing found
                $thumbnail_url = esc_url(wc_placeholder_img_src());
            }

            // PHASE 1 ENHANCEMENT: Use configured exclude value (Configuration Authority)
            $exclude_from_global_discount = $configured_exclude_gd;

            // Generate stock HTML for existing items
            $stock_html = '';
            $stock_status_class = '';

            if ($product) {
                if ($product->managing_stock()) {
                    $stock_quantity_val = $product->get_stock_quantity();
                    if ($stock_quantity_val === null || $stock_quantity_val === '') {
                        $stock_html = esc_html__('Stock N/A', 'enhanced-admin-order');
                        // No specific class for N/A, default text color will apply
                    } else if ($stock_quantity_val <= 0 && !$product->is_in_stock() && !$product->is_on_backorder() ) {
                        $stock_html_text = esc_html__('Out of stock', 'enhanced-admin-order');
                        $stock_status_class = 'eao-stock-insufficient'; // Use insufficient for out of stock
                        $stock_html = sprintf('<span class="%s">%s</span>', $stock_status_class, $stock_html_text);
                    } else {
                        $stock_html_text = sprintf(esc_html__('Stock: %s', 'enhanced-admin-order'), $stock_quantity_val);
                        if ($product->is_on_backorder() && $stock_quantity_val > 0) {
                            $stock_html_text .= ' (' . esc_html__('on backorder', 'enhanced-admin-order') . ')';
                            $stock_status_class = 'eao-stock-exact'; // Use exact (orange/yellow) for on backorder with some stock
                        } else if ($product->is_on_backorder()){
                            $stock_html_text = esc_html__('On backorder', 'enhanced-admin-order');
                            $stock_status_class = 'eao-stock-exact'; // Use exact for on backorder
                        } else if ($stock_quantity_val > 0) {
                            $stock_status_class = 'eao-stock-sufficient'; // Sufficient if positive stock and not backorder special case
                        }
                        $stock_html = sprintf('<span class="%s">%s</span>', $stock_status_class, $stock_html_text);
                    }
                } else { // Not managing stock, rely on stock status
                    $stock_status = $product->get_stock_status();
                    if ($stock_status === 'instock') {
                        $stock_html_text = esc_html__('In stock', 'enhanced-admin-order');
                        $stock_status_class = 'eao-stock-sufficient';
                    } elseif ($stock_status === 'outofstock') {
                        $stock_html_text = esc_html__('Out of stock', 'enhanced-admin-order');
                        $stock_status_class = 'eao-stock-insufficient';
                    } elseif ($stock_status === 'onbackorder') {
                        $stock_html_text = esc_html__('On backorder', 'enhanced-admin-order');
                        $stock_status_class = 'eao-stock-exact';
                    } else {
                        $stock_html_text = esc_html(ucfirst($stock_status)); // Fallback for unknown status
                    }
                    $stock_html = sprintf('<span class="%s">%s</span>', $stock_status_class, $stock_html_text);
                }
            }

            // Resolve product cost (COGS only):
            // 1) Primary: product meta '_cogs_total_value' (unit COGS used in product grid)
            // If not present, default to 0 (no other fallbacks)
            $resolved_cost = null;
            $resolved_cost_source = null;
            if ($product) {
                // Try variation first, then parent for COGS keys
                $candidate_ids = array();
                $candidate_ids[] = $product->get_id();
                if ($product->is_type('variation')) {
                    $parent_id = $product->get_parent_id();
                    if ($parent_id) { $candidate_ids[] = $parent_id; }
                }

                foreach ($candidate_ids as $pid) {
                    $val = get_post_meta($pid, '_cogs_total_value', true);
                    if ($val !== '' && $val !== null && is_numeric($val)) {
                        $resolved_cost = wc_format_decimal($val, wc_get_price_decimals());
                        $resolved_cost_source = '_cogs_total_value';
                        break;
                    }
                }
            }
            if ($resolved_cost === null) {
                // Default to 0 when no COGS value exists
                $resolved_cost = wc_format_decimal(0, wc_get_price_decimals());
                $resolved_cost_source = 'none';
            }

            // FINAL: We no longer surface the inconsistency banner in the UI
            $has_inconsistency = false;
            $expected_discount_percent = 0;

            $items_data[] = array(
                // EXISTING FIELDS (maintained for backward compatibility)
                'item_id' => $item_id,
                'product_id' => $product_id,
                'variation_id' => $variation_id,
                'name' => $item->get_name(),
                'sku' => $product ? $product->get_sku() : '',
                'price_raw' => wc_format_decimal( $price_raw, wc_get_price_decimals() ),
                // Provide catalog/base unit price for display-only purposes so UI shows true base price
                // Keep only order snapshot price for UI stability
                'product_permalink' => $product ? $product->get_permalink() : get_permalink($product_id),
                'cost_price' => wc_format_decimal($resolved_cost, wc_get_price_decimals()),
                'cost_source' => $resolved_cost_source,
                // Points earning (preview) per line; initialize to 0 and override below when YITH calc is available
                'points_earning' => 0,
                'quantity' => $item->get_quantity(),
                'line_subtotal' => wc_format_decimal( $line_subtotal, wc_get_price_decimals() ),
                'line_total' => wc_format_decimal( $line_total, wc_get_price_decimals() ),
                'thumbnail_url' => $thumbnail_url,
                'formatted_meta_data' => wc_display_item_meta($item, array('echo' => false, 'before' => '', 'separator' => '<br>', 'after' => '', 'label_before' => '<strong class="wc-item-meta-label">', 'label_after' => ':</strong> ')),
                'stock_html' => $stock_html,
                'manages_stock' => $product ? $product->managing_stock() : false,
                'stock_quantity' => $product ? ($product->managing_stock() ? $product->get_stock_quantity() : null) : null,
                
                // CONFIGURATION DATA (for form controls) - Configuration Authority
                'discount_percent' => floatval( $configured_discount_percent ),
                'exclude_gd' => $configured_exclude_gd,
                // MARKUP PERSISTENCE (derived from WC totals)
                'is_markup' => $is_markup,
                'discounted_price_fixed' => $discounted_price_fixed,
                
                // PHASE 1 ENHANCEMENT: WC NATIVE FIELDS (for single source of truth)
                '_line_subtotal' => wc_format_decimal( $wc_line_subtotal, wc_get_price_decimals() ),
                '_line_total' => wc_format_decimal( $wc_line_total, wc_get_price_decimals() ),
                '_line_discount' => wc_format_decimal( $wc_line_discount, wc_get_price_decimals() ),
                
                // PHASE 1 ENHANCEMENT: INCONSISTENCY DETECTION DATA
                'expected_discount_percent' => wc_format_decimal( 0, 2 ),
                'actual_discount_percent' => wc_format_decimal( $actual_discount_percent, 2 ),
                'has_inconsistency' => false,
                'inconsistency_type' => null,
                'recommended_action' => null
            );
        }

        // Attach shipments meta (if available via AST PRO)
        $shipments_meta = eao_get_shipments_meta_for_order( $order->get_id() );
        // error_log('[EAO Get Products Data AJAX DEBUG] About to call utility functions - eao_calculate_total_item_level_discounts and eao_calculate_products_total');

        $item_level_discount_total = eao_calculate_total_item_level_discounts($order);
        $products_subtotal = eao_calculate_products_total($order);

        // NEW: Also get order totals summary for display
        $order_subtotal = $order->get_subtotal();
        $order_total_discount = $order->get_total_discount();
        $order_shipping_total = $order->get_shipping_total();
        $order_total_tax = $order->get_total_tax();
        $order_total = $order->get_total();

        // Get shipping method title from order
        $shipping_methods = $order->get_shipping_methods();
        $shipping_method_title = 'Shipping';
        if (!empty($shipping_methods)) {
            $first_shipping_method = reset($shipping_methods);
            $shipping_method_title = $first_shipping_method->get_method_title();
            // error_log('[EAO DEBUG] Found shipping method: ' . $shipping_method_title . ' with cost: ' . $order_shipping_total);
        } else {
            // error_log('[EAO DEBUG] No shipping methods found, using default title. Shipping cost: ' . $order_shipping_total);
        }

        // Build summary data FIRST (before points calculation)
        $summary_data = array(
            'items_subtotal_raw'      => wc_format_decimal($order->get_subtotal(), wc_get_price_decimals()),
            'items_subtotal_formatted'=> $order->get_subtotal_to_display(),
            'cart_discount_raw'       => wc_format_decimal($order->get_total_discount(), wc_get_price_decimals()), 
            'cart_discount_formatted' => $order->get_discount_to_display(),
            'shipping_total_raw'      => wc_format_decimal($order->get_shipping_total(), wc_get_price_decimals()),
            'shipping_total_formatted'=> $order->get_shipping_to_display(),
            'shipping_method_title'   => $order->get_shipping_method(), 
            'shipping_total_formatted_html' => $order->get_shipping_to_display(), 
            'order_tax_raw'           => wc_format_decimal($order->get_total_tax(), wc_get_price_decimals()),
            'order_tax_formatted'     => wc_price($order->get_total_tax(), array('currency' => $order->get_currency())),
            'order_total_raw'         => wc_format_decimal($order->get_total(), wc_get_price_decimals()),
            'order_total_formatted'   => $order->get_formatted_order_total(),
            'currency_symbol'         => get_woocommerce_currency_symbol($order->get_currency()),
            'total_skus'              => count($order->get_items()),
            'total_quantity'          => $order->get_item_count(),
            'total_item_level_discount_raw' => wc_format_decimal(eao_calculate_total_item_level_discounts($order), wc_get_price_decimals()),
            'products_total_raw'      => wc_format_decimal(eao_calculate_products_total($order), wc_get_price_decimals()),
        );
        $summary_data['total_item_level_discount_formatted'] = wc_price($summary_data['total_item_level_discount_raw'], array('currency' => $order->get_currency()));
        $summary_data['products_total_formatted'] = wc_price($summary_data['products_total_raw'], array('currency' => $order->get_currency()));

        // Attach points grant state for UI (granted/revoked markers)
        try {
            $summary_data['points_grant_state'] = array(
                'granted'  => (bool) $order->get_meta('_eao_points_granted', true),
                'granted_points' => intval($order->get_meta('_eao_points_granted_points', true)),
                'revoked'  => (bool) $order->get_meta('_eao_points_revoked', true),
                'revoked_points' => intval($order->get_meta('_eao_points_revoked_points', true)),
            );
        } catch ( Exception $e ) {
            $summary_data['points_grant_state'] = array(
                'granted' => false,
                'granted_points' => 0,
                'revoked' => false,
                'revoked_points' => 0,
            );
        }

        // Add global discount data like in baseline
        $saved_global_discount_percent = $order->get_meta('_eao_global_product_discount_percent', true);
        $summary_data['global_order_discount_percent'] = $saved_global_discount_percent ? floatval($saved_global_discount_percent) : 0;
        
        // DEBUG: Check global discount
        // error_log('[EAO DEBUG] Global discount percent from meta: ' . $saved_global_discount_percent);
        // error_log('[EAO DEBUG] Global discount percent in summary: ' . $summary_data['global_order_discount_percent']);
        
        // TEMP FIX: If there's a global discount but no item discounts, calculate what the item discounts should be
        if ($summary_data['global_order_discount_percent'] > 0 && $summary_data['total_item_level_discount_raw'] == 0) {
            // Calculate discount only on non-excluded items
            $discountable_subtotal = 0;
            $expected_discount = 0;
            
            foreach ($order->get_items() as $item_id => $item) {
                $item_subtotal = $item->get_subtotal();
                $exclude_from_global = boolval($item->get_meta('_eao_exclude_global_discount', true)) || 
                                      boolval($item->get_meta('_eao_exclude_from_global_discount', true));
                
                if (!$exclude_from_global) {
                    $discountable_subtotal += $item_subtotal;
                    $item_discount = ($item_subtotal * $summary_data['global_order_discount_percent']) / 100;
                    $expected_discount += $item_discount;
                                    // error_log('[EAO DEBUG] Item ' . $item->get_name() . ': Subtotal=' . $item_subtotal . ', Discount=' . $item_discount . ', Excluded=No');
            } else {
                // error_log('[EAO DEBUG] Item ' . $item->get_name() . ': Subtotal=' . $item_subtotal . ', Discount=0, Excluded=Yes');
                }
            }
            
            $summary_data['total_item_level_discount_raw'] = wc_format_decimal($expected_discount, wc_get_price_decimals());
            $summary_data['total_item_level_discount_formatted'] = wc_price($expected_discount, array('currency' => $order->get_currency()));
            $summary_data['products_total_raw'] = wc_format_decimal($summary_data['items_subtotal_raw'] - $expected_discount, wc_get_price_decimals());
            $summary_data['products_total_formatted'] = wc_price($summary_data['items_subtotal_raw'] - $expected_discount, array('currency' => $order->get_currency()));
            
            // error_log('[EAO DEBUG] Applied global discount fix: Discountable subtotal=' . $discountable_subtotal . ', Expected discount=' . $expected_discount);
        }

        // NOW calculate points using the corrected summary values
        $points_award_summary_tmp = null;
        if ( function_exists('eao_yith_calculate_order_points_preview') ) {
            // Pass the corrected summary values to ensure consistency
            error_log('[EAO Product Management] Calling points preview with Items Subtotal: $' . $summary_data['items_subtotal_raw'] . ', Products Total: $' . $summary_data['products_total_raw']);
            $calc = eao_yith_calculate_order_points_preview( 
                $order->get_id(), 
                (float) $summary_data['items_subtotal_raw'], 
                (float) $summary_data['products_total_raw'] 
            );
            error_log('[EAO Product Management] Points calculation result: ' . print_r($calc, true));
            if ( ! empty( $calc['breakdown'] ) && is_array($calc['breakdown']) ) {
                $points_map = array();
                foreach ( $calc['breakdown'] as $bd_item ) {
                    if ( isset( $bd_item['order_item_id'] ) ) {
                        $points_map[ (string) $bd_item['order_item_id'] ] = isset($bd_item['total_points']) ? intval($bd_item['total_points']) : 0;
                    }
                }
                foreach ( $items_data as &$it ) {
                    $key = isset($it['item_id']) ? (string)$it['item_id'] : '';
                    if ( $key !== '' && isset($points_map[$key]) ) {
                        $it['points_earning'] = $points_map[$key];
                    }
                }
                unset($it);
            }
            // Always propagate summary rates when available (even if breakdown empty)
            error_log('[EAO Product Management] Checking calc keys - total_points_full: ' . (isset($calc['total_points_full']) ? $calc['total_points_full'] : 'NOT SET') . ', total_points_discounted: ' . (isset($calc['total_points_discounted']) ? $calc['total_points_discounted'] : 'NOT SET') . ', points_per_dollar: ' . (isset($calc['points_per_dollar']) ? $calc['points_per_dollar'] : 'NOT SET'));
            if ( isset($calc['total_points_full']) || isset($calc['total_points_discounted']) || isset($calc['points_per_dollar']) ) {
                $points_award_summary_tmp = array(
                    'points_full'       => isset($calc['total_points_full']) ? intval($calc['total_points_full']) : 0,
                    'points_discounted' => isset($calc['total_points_discounted']) ? intval($calc['total_points_discounted']) : 0,
                    'points_per_dollar' => isset($calc['points_per_dollar']) ? floatval($calc['points_per_dollar']) : 0,
                );
                error_log('[EAO Product Management] Created points_award_summary_tmp: ' . print_r($points_award_summary_tmp, true));
            } else {
                error_log('[EAO Product Management] WARNING: No calc keys found for points summary!');
            }
        }

        // Attach points award summary, if available
        if ( ! empty( $points_award_summary_tmp ) ) {
            $summary_data['points_award_summary'] = $points_award_summary_tmp;
            error_log('[EAO Product Management] Attached points_award_summary: ' . print_r($points_award_summary_tmp, true));
        } else {
            error_log('[EAO Product Management] WARNING: points_award_summary_tmp is empty!');
        }

        // DEBUG: Log individual item discount calculations
        // error_log('[EAO Get Products Data AJAX DEBUG] Individual item discount breakdown:');
        foreach ( $order->get_items() as $item_id => $item ) {
            $item_subtotal = $item->get_subtotal();
            $item_total = $item->get_total();
            $item_discount = $item_subtotal - $item_total;
            error_log('  Item ID ' . $item_id . ': Subtotal=' . $item_subtotal . ', Total=' . $item_total . ', Discount=' . $item_discount);
            error_log('  Item ID ' . $item_id . ': Name=' . $item->get_name() . ', Quantity=' . $item->get_quantity());
            error_log('  Item ID ' . $item_id . ': Line Subtotal=' . $item->get_subtotal() . ', Line Total=' . $item->get_total());
            
            // Check if there are any meta values for discounts
            $item_meta_discount = $item->get_meta('_eao_item_discount_percent', true);
            error_log('  Item ID ' . $item_id . ': Meta discount percent=' . $item_meta_discount);
            
            // Check all meta data
            $all_meta = $item->get_meta_data();
            foreach ($all_meta as $meta) {
                error_log('  Item ID ' . $item_id . ': Meta key=' . $meta->key . ', value=' . $meta->value);
            }
        }
        
        // DEBUG: Log the summary data to see what's being sent
        // error_log('[EAO Get Products Data AJAX DEBUG] Summary data being sent: ' . json_encode($summary_data));
        // error_log('[EAO Get Products Data AJAX DEBUG] Item level discount total: ' . $item_level_discount_total);
        // error_log('[EAO Get Products Data AJAX DEBUG] Shipping total raw: ' . $order_shipping_total);
        // error_log('[EAO Get Products Data AJAX DEBUG] Shipping method title: ' . $shipping_method_title);
        // error_log('[EAO Get Products Data AJAX DEBUG] Order subtotal: ' . $order->get_subtotal());
        // error_log('[EAO Get Products Data AJAX DEBUG] Order discount: ' . $order->get_total_discount());
        
        // error_log('[EAO Get Products Data AJAX DEBUG] Data successfully prepared. Sending response.');

        // Clear any remaining output
        if (ob_get_level() > 0) { 
            $captured_output = ob_get_contents();
            if (!empty($captured_output)) {
                error_log('[EAO AJAX DEBUG] Captured unexpected output: ' . json_encode($captured_output));
            }
            ob_end_clean(); 
        }
        
        // Ensure clean response
        while (ob_get_level() > 0) { 
            ob_end_clean(); 
        }
        
        // Send clean JSON response
        wp_send_json_success(array(
            'items' => $items_data,
            'summary' => $summary_data,
            'shipments' => $shipments_meta,
        ));
        
        error_log('[EAO Get Products Data AJAX] Success response sent with ' . count($items_data) . ' items');

    } catch (Exception $e) {
        error_log('[EAO Get Products Data AJAX] Exception caught: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in file ' . $e->getFile());
        if (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/json; charset=' . get_option('blog_charset'));
        echo json_encode(array('success' => false, 'data' => array(
            'message' => __('An error occurred while loading products.', 'enhanced-admin-order'),
            'items' => [], 
            'summary' => ['error' => __('Error loading data.', 'enhanced-admin-order')]
        )));
        wp_die();
    } finally {
        // Restore error settings
        ini_set('display_errors', $original_display_errors);
        error_reporting($original_error_reporting);
    }
}

/**
 * AJAX handler for searching products for the admin order editor.
 * @since 1.2.7 (Re-integration, with enhanced search logic)
 */
add_action('wp_ajax_eao_search_products_for_admin_order', 'eao_ajax_search_products_for_admin_order');
function eao_ajax_search_products_for_admin_order() {
    // Clean any stray output before processing
    while (ob_get_level() > 0) { 
        ob_end_clean(); 
    }
    
    global $wpdb;
    check_ajax_referer('eao_search_products_for_admin_order_nonce', 'nonce');
    // error_log('[EAO Product Search] AJAX handler eao_ajax_search_products_for_admin_order started.');

    $search_term = isset($_POST['search_term']) ? sanitize_text_field(wp_unslash($_POST['search_term'])) : '';
    // error_log('[EAO Product Search] Received search_term: "' . $search_term . '"');

    if (empty($search_term) || strlen($search_term) < 2) { // Changed to 2 for quicker testing, can revert to 3
        // error_log('[EAO Product Search] Search term too short or empty. Term: "' . $search_term . '". Sending empty success response.');
        header('Content-Type: application/json; charset=' . get_option('blog_charset'));
        echo json_encode(array('success' => true, 'data' => array()));
        error_log('[EAO Product Search] Empty search response sent manually');
        wp_die();
    }

    $products_with_scores = array();
    $searched_product_ids = array(); // Keep track of IDs to avoid duplicates and for exclusion
    $search_term_lower = strtolower($search_term);
    $search_words = array_filter(explode(' ', $search_term_lower));
    $limit_per_query = 20;

    // 1. Search in Product Titles (Highest Priority)
    $title_query = $wpdb->prepare(
        "SELECT ID, post_title FROM {$wpdb->posts}
         WHERE post_type IN ('product', 'product_variation') AND post_status = 'publish'
         AND post_title LIKE %s
         ORDER BY CASE
            WHEN post_title LIKE %s THEN 1 -- Exact match
            WHEN post_title LIKE %s THEN 2 -- Starts with
            ELSE 3
         END, post_title ASC
         LIMIT %d",
        '%' . $wpdb->esc_like($search_term) . '%',
        $wpdb->esc_like($search_term),
        $wpdb->esc_like($search_term) . '%',
        $limit_per_query
    );
    $title_matches = $wpdb->get_results($title_query);

    foreach ($title_matches as $product_post) {
        $product_title_lower = strtolower($product_post->post_title);
        $score = 0;
        if ($product_title_lower === $search_term_lower) {
            $score = 100;
        } elseif (strpos($product_title_lower, $search_term_lower) === 0) {
            $score = 90;
        } else {
            $score = 70; // Contains
            // Multi-word bonus (simple version)
            $match_count = 0;
            foreach ($search_words as $word) {
                if (strpos($product_title_lower, $word) !== false) {
                    $match_count++;
                }
            }
            if (count($search_words) > 1 && $match_count === count($search_words)) {
                $score += 10; // Bonus if all words are present
            }
        }
        $products_with_scores[$product_post->ID] = array('id' => $product_post->ID, 'score' => $score, 'match_type' => 'title');
        $searched_product_ids[] = $product_post->ID;
    }

    // 2. Search in SKU (High Priority)
    if (count($searched_product_ids) < $limit_per_query) {
        $sku_query_args = array(
            "SELECT p.ID, p.post_title, pm.meta_value as sku
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type IN ('product', 'product_variation') AND p.post_status = 'publish'
             AND pm.meta_key = '_sku' AND pm.meta_value LIKE %s"
        );
        $prepared_values = array('%' . $wpdb->esc_like($search_term) . '%');

        if (!empty($searched_product_ids)) {
            $placeholders = implode(',', array_fill(0, count($searched_product_ids), '%d'));
            $sku_query_args[] = "AND p.ID NOT IN ({$placeholders})";
            $prepared_values = array_merge($prepared_values, $searched_product_ids);
        }
        $sku_query_args[] = "ORDER BY CASE
                                WHEN pm.meta_value LIKE %s THEN 1
                                WHEN pm.meta_value LIKE %s THEN 2
                                ELSE 3
                             END, p.post_title ASC
                             LIMIT %d";
        $prepared_values[] = $wpdb->esc_like($search_term);
        $prepared_values[] = $wpdb->esc_like($search_term) . '%';                        
        $prepared_values[] = $limit_per_query - count($searched_product_ids);

        $sku_query = $wpdb->prepare(implode(' ', $sku_query_args), $prepared_values);
        $sku_matches = $wpdb->get_results($sku_query);

        foreach ($sku_matches as $product_post) {
            $sku_lower = strtolower($product_post->sku);
            $score = 0;
            if ($sku_lower === $search_term_lower) {
                $score = 95;
            } else {
                $score = 80; // Contains
            }

            if (isset($products_with_scores[$product_post->ID])) {
                $products_with_scores[$product_post->ID]['score'] = max($products_with_scores[$product_post->ID]['score'], $score + 5); // Boost if already found by title
                $products_with_scores[$product_post->ID]['match_type'] .= ', sku';
            } else {
                $products_with_scores[$product_post->ID] = array('id' => $product_post->ID, 'score' => $score, 'match_type' => 'sku');
                $searched_product_ids[] = $product_post->ID;
            }
        }
    }
    
    // 3. Search in Product Content / Excerpt (Lower Priority) - Optional, can be intensive
    // For now, we'll skip content search to keep it faster, focusing on title and SKU.
    // If needed, it can be added here, similar to the reference snippet.

    // Sort products by score descending
    uasort($products_with_scores, function ($a, $b) {
        return $b['score'] - $a['score'];
    });

    $final_products_data = array();
    $final_ids_to_fetch = array_map(function($item){ return $item['id']; }, array_slice($products_with_scores, 0, $limit_per_query, true));

    if (!empty($final_ids_to_fetch)) {
        foreach ($final_ids_to_fetch as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product) {
                // error_log('[EAO Product Search] Could not get product object for ID: ' . $product_id);
                continue;
            }

            // Prepare stock HTML (copied from previous version of this function)
            $stock_html = '';
            $stock_status_class = ''; // To hold the specific stock class

            if ($product->managing_stock()) {
                $stock_quantity_val = $product->get_stock_quantity();
                if ($stock_quantity_val === null || $stock_quantity_val === '') {
                     $stock_html = esc_html__('Stock N/A', 'enhanced-admin-order');
                     // No specific class for N/A, default text color will apply
                } else if ($stock_quantity_val <= 0 && !$product->is_in_stock() && !$product->is_on_backorder() ) {
                    $stock_html_text = esc_html__('Out of stock', 'enhanced-admin-order');
                    $stock_status_class = 'eao-stock-insufficient'; // Use insufficient for out of stock
                    $stock_html = sprintf('<span class="%s">%s</span>', $stock_status_class, $stock_html_text);
                } else {
                    $stock_html_text = sprintf(esc_html__('Stock: %s', 'enhanced-admin-order'), $stock_quantity_val);
                    if ($product->is_on_backorder() && $stock_quantity_val > 0) {
                        $stock_html_text .= ' (' . esc_html__('on backorder', 'enhanced-admin-order') . ')';
                        $stock_status_class = 'eao-stock-exact'; // Use exact (orange/yellow) for on backorder with some stock
                    } else if ($product->is_on_backorder()){
                        $stock_html_text = esc_html__('On backorder', 'enhanced-admin-order');
                        $stock_status_class = 'eao-stock-exact'; // Use exact for on backorder
                    } else if ($stock_quantity_val > 0) {
                        $stock_status_class = 'eao-stock-sufficient'; // Sufficient if positive stock and not backorder special case
                    }
                    $stock_html = sprintf('<span class="%s">%s</span>', $stock_status_class, $stock_html_text);
                }
            } else { // Not managing stock, rely on stock status
                $stock_status = $product->get_stock_status();
                if ($stock_status === 'instock') {
                    $stock_html_text = esc_html__('In stock', 'enhanced-admin-order');
                    $stock_status_class = 'eao-stock-sufficient';
                } elseif ($stock_status === 'outofstock') {
                    $stock_html_text = esc_html__('Out of stock', 'enhanced-admin-order');
                    $stock_status_class = 'eao-stock-insufficient';
                } elseif ($stock_status === 'onbackorder') {
                    $stock_html_text = esc_html__('On backorder', 'enhanced-admin-order');
                    $stock_status_class = 'eao-stock-exact';
                } else {
                    $stock_html_text = esc_html(ucfirst($stock_status)); // Fallback for unknown status
                }
                $stock_html = sprintf('<span class="%s">%s</span>', $stock_status_class, $stock_html_text);
            }
            
            $thumbnail_url = wp_get_attachment_image_url($product->get_image_id(), 'thumbnail');
            if (!$thumbnail_url && $product->get_parent_id()) { // Try parent for variation image
                $parent_product = wc_get_product($product->get_parent_id());
                if ($parent_product) {
                     $thumbnail_url = wp_get_attachment_image_url($parent_product->get_image_id(), 'thumbnail');
                }
            }

            $final_products_data[] = array(
                'id'            => $product->get_id(),
                'name'          => $product->get_formatted_name(),
                'sku'           => $product->get_sku(),
                'price_html'    => $product->get_price_html(),
                'price_raw'     => wc_get_price_to_display($product),
                'thumbnail_url' => $thumbnail_url ?: wc_placeholder_img_src('thumbnail'),
                'stock_html'    => $stock_html,
                'manages_stock' => $product->managing_stock(), // ADDED
                'stock_quantity'=> $product->managing_stock() ? $product->get_stock_quantity() : null, // ADDED
            );
        }
    }
    // error_log('[EAO Product Search] Products found to send: ' . print_r($final_products_data, true));
    // Use manual JSON response to avoid wp_send_json_success issues
    header('Content-Type: application/json; charset=' . get_option('blog_charset'));
    echo json_encode(array('success' => true, 'data' => $final_products_data));
    error_log('[EAO Product Search] Success response sent manually with ' . count($final_products_data) . ' products');
    wp_die();
}

/**
 * PHASE 1 ENHANCEMENT: Inconsistency Detection Helper Functions
 * These functions implement the "Configuration Authority with WC Reality Check" strategy
 * @since 2.8.0
 */

/**
 * Detect type of inconsistency between configured and actual discounts
 * 
 * @param float $expected Expected discount percentage based on configuration
 * @param float $actual Actual discount percentage from WC fields
 * @param bool $exclude_gd Whether item is excluded from global discount
 * @return string Type of inconsistency
 */
function eao_detect_inconsistency_type($expected, $actual, $exclude_gd) {
    if ($expected == 0 && $actual > 0) {
        return 'unexpected_discount'; // WC has discount but config doesn't
    } elseif ($expected > 0 && $actual == 0) {
        return 'missing_discount'; // Config has discount but WC doesn't
    } elseif (abs($expected - $actual) > 0.1) {
        return 'percentage_mismatch'; // Different discount percentages
    }
    return 'unknown';
}

/**
 * Recommend resolution action for inconsistency
 * 
 * @param float $expected Expected discount percentage based on configuration
 * @param float $actual Actual discount percentage from WC fields
 * @return string Recommended action
 */
function eao_recommend_resolution_action($expected, $actual) {
    if ($expected == 0 && $actual > 0) {
        return 'remove_wc_discount'; // Clear WC discount to match config
    } elseif ($expected > 0 && $actual == 0) {
        return 'apply_configured_discount'; // Apply config discount to WC
    } elseif ($expected != $actual) {
        return 'sync_to_configuration'; // Update WC to match configuration
    }
    return 'no_action';
}

/**
 * Calculate expected discount percentage based on configuration
 * 
 * @param array $item_meta Item meta data
 * @param float $global_discount Global discount percentage
 * @return float Expected discount percentage
 */
function eao_calculate_expected_discount($item_meta, $global_discount) {
    $configured_discount_percent = floatval($item_meta['_eao_item_discount_percent'] ?? 0);
    $configured_exclude_gd = wc_string_to_bool($item_meta['_eao_exclude_global_discount'] ?? false);
    
    // If item has specific discount, use that
    if ($configured_discount_percent > 0) {
        return $configured_discount_percent;
    }
    
    // If not excluded and global discount exists, use global
    if (!$configured_exclude_gd && $global_discount > 0) {
        return $global_discount;
    }
    
    // No discount expected
    return 0;
} 