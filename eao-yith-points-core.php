<?php
/**
 * YITH Points Integration Core Module
 *
 * Version: 2.2.0
 * Author: Amnon Manneberg
 * Dependencies: YITH WooCommerce Points and Rewards Premium
 * Scope: Order-related functionality only
 * 
 * This module provides integration with YITH Points & Rewards system
 * for order-specific operations including points redemption and awarding.
 * 
 * @package Enhanced Admin Order Plugin
 * @since 2.2.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * EAO YITH Points Integration Core Class
 * 
 * Provides wrapper functions for YITH Points & Rewards APIs
 * focused on order-related operations.
 */
class EAO_YITH_Points_Core {
    
    /**
     * Single instance of the class
     *
     * @var EAO_YITH_Points_Core
     */
    protected static $instance;
    
    /**
     * YITH plugin availability status
     *
     * @var bool
     */
    private $yith_available = false;
    
    /**
     * YITH plugin version compatibility
     *
     * @var bool
     */
    private $yith_compatible = false;
    
    /**
     * Get single instance of the class
     *
     * @return EAO_YITH_Points_Core
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor - Initialize YITH integration
     */
    private function __construct() {
        // Only initialize if we're in admin or doing AJAX
        if ( ! is_admin() && ! wp_doing_ajax() ) {
            return;
        }
        
        $this->check_yith_availability();
        
        if ( $this->is_yith_available() ) {
            $this->init_hooks();
        }
    }
    
    /**
     * Check if YITH Points & Rewards is available and compatible
     */
    private function check_yith_availability() {
        // Only check if YITH functions are available
        if ( ! function_exists( 'ywpar_get_customer' ) ) {
            $this->yith_available = false;
            return;
        }
        
        $this->yith_available = true;
        $this->yith_compatible = true;
    }
    
    /**
     * Initialize hooks for YITH integration
     */
    private function init_hooks() {
        // Hook into order status changes for automatic point awarding
        add_action( 'woocommerce_order_status_completed', array( $this, 'trigger_order_points_award' ), 5 );
        add_action( 'woocommerce_order_status_processing', array( $this, 'trigger_order_points_award' ), 5 );

        // Async award hook (used when status is changed via our AJAX save to avoid timeouts)
        add_action( 'eao_award_points_async', array( $this, 'award_order_points' ), 10, 1 );
    }
    
    /**
     * Check if YITH is available and compatible
     *
     * @return bool
     */
    public function is_yith_available() {
        return $this->yith_available && $this->yith_compatible;
    }
    
    /**
     * Get customer's available points
     *
     * @param int $user_id Customer user ID
     * @return int Available points or 0 if error
     */
    public function get_customer_points( $user_id ) {
        if ( ! $this->is_yith_available() || ! $user_id ) {
            return 0;
        }
        
        try {
            if ( function_exists( 'ywpar_get_customer' ) ) {
                $customer = ywpar_get_customer( $user_id );
                return $customer ? $customer->get_usable_points() : 0;
            }
        } catch ( Exception $e ) {
            error_log( '[EAO YITH] Error getting customer points: ' . $e->getMessage() );
        }
        
        return 0;
    }
    
    /**
     * Trigger point awarding for order status change
     *
     * @param int $order_id Order ID
     */
    public function trigger_order_points_award( $order_id ) {
        if ( ! $this->is_yith_available() ) {
            return;
        }
        
        // Only award points for orders created/modified through our admin interface
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        
        // Award for any order transitioned to processing/completed (no EAO-only gating)

        // If this is running during our AJAX save request, defer to background to avoid 502/timeouts
        if ( wp_doing_ajax() && isset( $_POST['action'] ) && $_POST['action'] === 'eao_save_order_details' ) {
            // Schedule a single event a few seconds later; use unique key to avoid duplicates
            if ( ! wp_next_scheduled( 'eao_award_points_async', array( (int) $order_id ) ) ) {
                wp_schedule_single_event( time() + 5, 'eao_award_points_async', array( (int) $order_id ) );
            }
            return;
        }

        // If pending points intent exists, reconcile redemption now before awarding
        try {
            $order_obj = wc_get_order( $order_id );
            if ( $order_obj && $order_obj->get_meta( '_eao_pending_points_to_redeem', true ) ) {
                $pending_pts = absint( $order_obj->get_meta( '_eao_pending_points_to_redeem', true ) );
                if ( $pending_pts > 0 && function_exists( 'eao_process_yith_points_redemption' ) ) {
                    eao_process_yith_points_redemption( $order_id, array( 'eao_points_to_redeem' => $pending_pts ) );
                    $order_obj->delete_meta_data( '_eao_pending_points_to_redeem' );
                    $order_obj->save();
                }
            }
        } catch ( Exception $e ) { /* no-op */ }

        // Prefer exact expected award saved by UI
        $expected_award = 0;
        try {
            if ( isset( $order_obj ) && is_object( $order_obj ) ) {
                $expected_award = absint( $order_obj->get_meta( '_eao_points_expected_award', true ) );
            } else {
                $order_tmp = wc_get_order( $order_id );
                if ( $order_tmp ) {
                    $expected_award = absint( $order_tmp->get_meta( '_eao_points_expected_award', true ) );
                }
            }
        } catch ( Exception $e ) { $expected_award = 0; }

        $this->award_order_points( $order_id, $expected_award > 0 ? $expected_award : null );
    }
    
    /**
     * Award points for an order
     *
     * @param int $order_id Order ID
     * @return bool Success status
     */
    public function award_order_points( $order_id, $points_to_award = null ) {
        if ( ! $this->is_yith_available() || ! $order_id ) {
            return false;
        }
        
        try {
            if ( class_exists( 'YITH_WC_Points_Rewards_Earning' ) ) {
                $earning_instance = YITH_WC_Points_Rewards_Earning::get_instance();
                if ( method_exists( $earning_instance, 'add_order_points' ) ) {
                    // If a specific points value is provided, set expected award meta so YITH can reflect it
                    if ( null !== $points_to_award && $points_to_award > 0 ) {
                        try {
                            $order = wc_get_order( $order_id );
                            if ( $order ) {
                                $order->update_meta_data( '_eao_points_expected_award', absint( $points_to_award ) );
                                $order->save();
                            }
                        } catch ( Exception $e ) { /* no-op */ }
                    }
                    $earning_instance->add_order_points( $order_id );
                    return true;
                }
            }
        } catch ( Exception $e ) {
            error_log( '[EAO YITH] Error awarding order points: ' . $e->getMessage() );
        }
        
        return false;
    }
}

/**
 * Helper Functions for YITH Points Integration
 */

/**
 * Get customer's available points
 *
 * @param int $user_id Customer user ID
 * @return int Available points
 */
function eao_yith_get_customer_points( $user_id ) {
    return EAO_YITH_Points_Core::get_instance()->get_customer_points( $user_id );
}

/**
 * Calculate the expected points a customer would earn from an order
 * This function mimics YITH's complete points calculation logic
 *
 * @param int $order_id Order ID
 * @param float|null $items_subtotal_override Optional: Items subtotal from summary (Line 1 - Gross)
 * @param float|null $products_total_override Optional: Products total from summary (Line 2 - Net)
 * @return array Result with points calculation details
 */
function eao_yith_calculate_order_points_preview( $order_id, $items_subtotal_override = null, $products_total_override = null ) {
    error_log('[EAO YITH] Function called - Order ID: ' . $order_id . ', Items Subtotal Override: ' . ($items_subtotal_override !== null ? '$' . $items_subtotal_override : 'NULL') . ', Products Total Override: ' . ($products_total_override !== null ? '$' . $products_total_override : 'NULL'));
    
    $result = array(
        'success' => true,
        'total_points' => 0,
        'can_earn' => true,
        'reasons' => array(),
        'breakdown' => array(),
        'messages' => array(),
        'errors' => array()
    );

    try {
        // Check if YITH is available
        if ( ! eao_yith_is_available() ) {
            error_log('[EAO YITH] YITH not available');

            $result['success'] = false;
            $result['can_earn'] = false;
            $result['errors'][] = 'YITH Points plugin not available';
            return $result;
        }

        // Get order
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            $result['success'] = false;
            $result['errors'][] = 'Invalid order';
            return $result;
        }

        // Get customer
        $customer_id = $order->get_customer_id();
        if ( ! $customer_id ) {
            $result['can_earn'] = false;
            $result['reasons'][] = 'Points earning requires registered customer';
            return $result;
        }

        // Get YITH customer object
        $customer = ywpar_get_customer( $customer_id );
        if ( ! $customer || ! $customer->is_enabled() ) {
            $result['can_earn'] = false;
            $result['reasons'][] = 'Customer not enabled for points earning';
            return $result;
        }

        // If order already has points awarded, do NOT short-circuit.
        // We still compute a fresh preview for UI breakdown while noting prior grant.
        $existing_points = $order->get_meta( '_ywpar_points_earned' );
        if ( '' !== $existing_points ) {
            $result['messages'][] = 'Points already calculated for this order: ' . intval($existing_points);
        }

        // Check if earning is disabled while redeeming
        if ( ywpar_get_option( 'disable_earning_while_reedeming', 'no' ) === 'yes' && ywpar_order_has_redeeming_coupon( $order ) ) {
            $result['can_earn'] = false;
            $result['reasons'][] = 'Points earning disabled when using reward coupons';
            return $result;
        }

        // Switch current user context to the order's customer so role-based YITH rules apply
        $prev_user_id = get_current_user_id();
        $did_switch_user = false;
        if ( (int) $prev_user_id !== (int) $customer_id && $customer_id > 0 ) {
            wp_set_current_user( $customer_id );
            $did_switch_user = true;
        }

        $currency = $order->get_currency();

        // SIMPLIFIED PATH: If summary values are provided, use them directly (v5.0.84+)
        if ( $items_subtotal_override !== null && $products_total_override !== null ) {
            error_log('[EAO YITH SIMPLIFIED] Using summary values - Items Subtotal: $' . $items_subtotal_override . ', Products Total: $' . $products_total_override);
            
            // Use provided summary values - these are already corrected and match UI display
            $items_subtotal = $items_subtotal_override;
            $products_total = $products_total_override;
            
            // Get customer earning rate (e.g., 10% for Community Plus)
            $customer_level = $customer->get_level();
            $earning_rate = 0;
            try {
                if ( function_exists('ywpar_get_option') ) {
                    $earning_rate = (float) ywpar_get_option( 'earning_conversion_rate_method_' . $customer_level, 0 );
                    if ( $earning_rate <= 0 ) {
                        $earning_rate = (float) ywpar_get_option( 'earning_conversion_rate_extrapoints_' . $customer_level, 0 );
                    }
                    if ( $earning_rate <= 0 ) {
                        $earning_rate = 0.10; // Default 10%
                    }
                }
            } catch ( Exception $e ) {
                $earning_rate = 0.10;
            }
            error_log('[EAO YITH SIMPLIFIED] Customer level: ' . $customer_level . ', Earning rate: ' . ($earning_rate * 100) . '%');
            
            // Get base conversion rate (e.g., 10 points per $1)
            $conversion = yith_points()->earning->get_conversion_option( $currency );
            $base_conversion_rate = 10.0; // Default
            if ( is_array( $conversion ) && isset( $conversion['money'], $conversion['points'] ) && (float) $conversion['money'] > 0 ) {
                $base_conversion_rate = (float) $conversion['points'] / (float) $conversion['money'];
            }
            error_log('[EAO YITH SIMPLIFIED] Base conversion rate: ' . $base_conversion_rate . ' points per $1');
            
            // Calculate: amount × earning_rate × base_conversion_rate
            // Example: $453.47 × 10% × 10 points/$ = 453 points
            $total_points_full = (int) round( $items_subtotal * $earning_rate * $base_conversion_rate );
            error_log('[EAO YITH SIMPLIFIED] Line 1 - $' . $items_subtotal . ' × ' . $earning_rate . ' × ' . $base_conversion_rate . ' = ' . $total_points_full . ' points');
            
            $total_points_discounted = (int) round( $products_total * $earning_rate * $base_conversion_rate );
            error_log('[EAO YITH SIMPLIFIED] Line 2 - $' . $products_total . ' × ' . $earning_rate . ' × ' . $base_conversion_rate . ' = ' . $total_points_discounted . ' points');
            
            $total_points = $total_points_discounted;
            $earning_base_amount = $products_total;
            
            // Coupon discount subtraction (Line 3 calculation)
            error_log('[EAO YITH SIMPLIFIED] Checking for YITH coupons...');
            if ( ywpar_get_option( 'remove_points_coupon', 'yes' ) === 'yes' && $earning_base_amount > 0 ) {
                foreach ( $order->get_coupon_codes() as $coupon_code ) {
                    error_log('[EAO YITH SIMPLIFIED] Found coupon: ' . $coupon_code);
                    $coupon = new WC_Coupon( $coupon_code );
                    if ( $coupon && $coupon->get_meta( '_ywpar_coupon', true ) ) {
                        $coupon_amount = (float) $order->get_total_discount();
                        error_log('[EAO YITH SIMPLIFIED] YITH coupon detected! Discount amount: $' . $coupon_amount);
                        if ( $coupon_amount > 0 ) {
                            // Calculate points to subtract using same formula
                            $points_from_coupon = (int) round( $coupon_amount * $earning_rate * $base_conversion_rate );
                            error_log('[EAO YITH SIMPLIFIED] Points to subtract: $' . $coupon_amount . ' × ' . $earning_rate . ' × ' . $base_conversion_rate . ' = ' . $points_from_coupon . ' points');
                            $total_points = max( 0, $total_points - $points_from_coupon );
                            error_log('[EAO YITH SIMPLIFIED] Line 3 - Final points after coupon: ' . $total_points . ' points');
                            $earning_base_amount = max( 0, $earning_base_amount - $coupon_amount );
                            break; // Only process first YITH coupon
                        }
                    }
                }
            }
            
            // Get conversion rate for reference
            $conversion = yith_points()->earning->get_conversion_option( $currency );
            $points_per_dollar = 0;
            if ( is_array( $conversion ) && isset( $conversion['money'], $conversion['points'] ) && (float) $conversion['money'] > 0 ) {
                $points_per_dollar = (float) $conversion['points'] / (float) $conversion['money'];
            }
            error_log('[EAO YITH SIMPLIFIED] Points per dollar: ' . $points_per_dollar);
            
            // Return simplified result (no per-item breakdown)
            $result['success'] = true;
            $result['total_points'] = $total_points;
            $result['total_points_full'] = $total_points_full;
            $result['total_points_discounted'] = $total_points_discounted;
            $result['earning_base_amount'] = $earning_base_amount;
            $result['points_per_dollar'] = $points_per_dollar;
            $result['calculation_method'] = 'summary_values';
            $result['breakdown'] = array(); // No item breakdown in simplified mode
            
            error_log('[EAO YITH SIMPLIFIED] Returning result - Line 1: ' . $total_points_full . ', Line 2: ' . $total_points_discounted . ', Line 3: ' . $total_points);
            
            // Restore previous user
            if ( $did_switch_user ) {
                wp_set_current_user( $prev_user_id );
            }
            
            return $result;
        }

        // LEGACY PATH: Calculate per-item (when summary values not provided)
        $total_points = 0; // discounted awarding points total (based on per-line discounted price)
        $total_points_full = 0; // full-price awarding points total (based on per-line subtotal)
        $total_points_discounted = 0; // equals $total_points, but kept separately for clarity
        $order_items = $order->get_items();
        $earning_base_amount = 0; // Sum of amounts considered for earning (before coupons)

        if ( ! empty( $order_items ) ) {
            foreach ( $order_items as $order_item ) {
                $product = $order_item->get_product();
                
                if ( ! $product ) {
                    continue;
                }

                $qty = max(1, (int) $order_item->get_quantity());
                $line_subtotal = (float) $order_item->get_subtotal(); // full price for the line
                $line_total_raw    = (float) $order_item->get_total();    // discounted price for the line
                // Guard for environments storing per-unit totals incorrectly
                $line_total = $line_total_raw;
                if ($qty > 1 && $line_total_raw > 0 && $line_subtotal >= $line_total_raw && abs(($line_total_raw * $qty) - $line_subtotal) < abs($line_total_raw - $line_subtotal)) {
                    $line_total = $line_total_raw * $qty;
                }

                // Points based on full price (entire line) - use product rules when available
                $unit_subtotal = $qty > 0 ? ($line_subtotal / $qty) : 0.0;
                $full_points_per_item = eao_yith_get_product_points( $product, $currency, $customer, array(
                    'total' => $unit_subtotal,
                    'tax'   => isset($order_item->get_data()['subtotal_tax']) ? $order_item->get_data()['subtotal_tax'] : 0,
                ) );
                if ( $full_points_per_item === false || $full_points_per_item === null ) {
                    $full_points_per_item = eao_yith_get_points_from_price( $unit_subtotal, $currency );
                }
                $line_points_full = $full_points_per_item * $qty;

                // Start from discounted unit price for awarding
                $unit_total = $qty > 0 ? ($line_total / $qty) : 0.0;
                $price = $unit_total;
                $item_points_base = eao_yith_get_points_from_price( $price, $currency );
                $item_points = $item_points_base;
                $applied_rule_detail = null;
                $calc_source = 'base_conversion';
                
                if ( $product ) {
                    // Get item data in YITH's format
                    $item_data = array(
                        'total' => $order_item->get_data()['total'],
                        'tax'   => $order_item->get_data()['total_tax'],
                    );
                    
                    // Get product points using YITH's complete calculation (includes rules)
                    $product_points = eao_yith_get_product_points( $product, $currency, $customer, $item_data );
                    
                    // Check if specific earning rules were applied
                    $debug_info = eao_yith_debug_product_rules( $product, $customer );
                    $has_specific_rules = !empty($debug_info['rules_found']);
                    
                    if ( $product_points !== false && $has_specific_rules ) {
                        // Use product rule points when specific rules are found
                        $item_points = $product_points;
                        $calc_source = 'product_rule';
                        $applied_rule_detail = eao_yith_get_applied_earning_rule_details( $product, $customer, $currency, eao_yith_get_points_from_price( $price, $currency ) );
                        // YITH override rules in order-context may compute from catalog price instead of line unit total.
                        // Rebase to actual unit_total using the rule's conversion if available to match frontend/cart behavior.
                        if ( is_array($applied_rule_detail) && isset($applied_rule_detail['meta']['type']) && $applied_rule_detail['meta']['type'] === 'override' ) {
                            $conv = isset($applied_rule_detail['meta']['conversion']) ? $applied_rule_detail['meta']['conversion'] : null;
                            if ( is_array($conv) && isset($conv['money'], $conv['points']) && (float)$conv['money'] > 0 ) {
                                $rebased = ($unit_total / (float)$conv['money']) * (float)$conv['points'];
                                $item_points = $rebased;
                                $calc_source = 'product_rule_override_rebased_unit_total';
                            }
                        }
                    } elseif ( $product_points !== false ) {
                        // Fall back to YITH's min logic only when no specific rules found
                        $item_points = $product_points < $item_points ? $product_points : $item_points;
                        // Determine rule detail anyway for display (optional)
                        $applied_rule_detail = eao_yith_get_applied_earning_rule_details( $product, $customer, $currency, eao_yith_get_points_from_price( $price, $currency ) );
                    }
                    
                    // Apply the same filter YITH uses for forcing product points
                    if ( apply_filters( 'ywpar_force_use_points_from_product', false, $product ) ) {
                        $item_points = $product_points;
                    }
                }

                $line_total_points = $item_points * $qty;
                $total_points += $line_total_points;
                $total_points_full += $line_points_full;
                $total_points_discounted += $line_total_points;

                // Accumulate earning base amount (price times qty)
                $earning_base_amount += (float)$price * (int)$order_item->get_quantity();

                // Add to breakdown with detailed information
                // Capture global conversion for deeper diagnostics
                $global_conversion = array('money' => 0, 'points' => 0);
                try {
                    if ( function_exists('yith_points') && isset(yith_points()->earning) ) {
                        $conv = yith_points()->earning->get_conversion_option( $currency );
                        if ( is_array($conv) ) {
                            $global_conversion['money'] = isset($conv['money']) ? (float)$conv['money'] : 0;
                            $global_conversion['points'] = isset($conv['points']) ? (float)$conv['points'] : 0;
                        }
                    }
                } catch ( Exception $e ) { /* no-op */ }

                // Compute what we'd expect from the rule conversion itself (diagnostic)
                $expected_points_per_item_from_rule = null;
                if ( is_array( $applied_rule_detail ) && isset( $applied_rule_detail['meta'] ) ) {
                    $meta = $applied_rule_detail['meta'];
                    if ( isset( $meta['type'] ) && $meta['type'] === 'override' && isset( $meta['conversion'] ) ) {
                        $conv = $meta['conversion'];
                        $money_c = isset($conv['money']) ? (float)$conv['money'] : 0;
                        $points_c = isset($conv['points']) ? (float)$conv['points'] : 0;
                        if ( $money_c > 0 ) {
                            $expected_points_per_item_from_rule = ($unit_total / $money_c) * $points_c;
                        }
                    }
                }

                $result['breakdown'][] = array(
                    'order_item_id' => $order_item->get_id(),
                    'product_name' => $product->get_name(),
                    'quantity' => $order_item->get_quantity(),
                    'price' => $price,
                    'base_price_points' => $full_points_per_item,
                    'product_rule_points' => $product_points,
                    'points_per_item' => $item_points,
                    'total_points' => $line_total_points,
                    'line_total_amount' => (float)$line_total,
                    'debug_info' => eao_yith_debug_product_rules( $product, $customer ),
                    'applied_rule' => $applied_rule_detail,
                    'calc_debug' => array(
                        'calc_source' => $calc_source,
                        'unit_total' => $unit_total,
                        'base_conversion_points_per_item' => $item_points_base,
                        'product_rule_points_per_item' => $product_points,
                        // Effective conversions
                        'global_conversion' => $global_conversion,
                        'earn_prices_calc_mode' => ywpar_get_option( 'earn_prices_calc', 'unit' ),
                        'product_rule_selected_type' => isset($applied_rule_detail['meta']['type']) ? $applied_rule_detail['meta']['type'] : null,
                        'product_rule_selected_conversion' => isset($applied_rule_detail['meta']['conversion']) ? $applied_rule_detail['meta']['conversion'] : null,
                        'expected_points_per_item_from_rule' => $expected_points_per_item_from_rule,
                        'raw_product_points_returned' => $product_points,
                    )
                );
            }
        }

        // Handle coupon/points discounts if enabled (deduct ONLY coupon-based amounts)
        if ( ywpar_get_option( 'remove_points_coupon', 'yes' ) === 'yes' && $earning_base_amount > 0 ) {
            // Sum coupon line discounts explicitly to avoid subtracting product price reductions
            $coupon_discount_total = 0.0;
            try {
                $coupon_items = $order->get_items( 'coupon' );
                if ( ! empty( $coupon_items ) ) {
                    foreach ( $coupon_items as $coupon_item ) {
                        if ( is_object( $coupon_item ) && method_exists( $coupon_item, 'get_discount' ) ) {
                            $coupon_discount_total += (float) $coupon_item->get_discount();
                        }
                        if ( is_object( $coupon_item ) && method_exists( $coupon_item, 'get_discount_tax' ) ) {
                            // Exclude tax from earning base by default; include here only if YITH does
                            $coupon_discount_total += 0.0; // keep tax out to avoid over-reduction
                        }
                    }
                }
            } catch ( Exception $e ) { /* no-op */ }

            if ( $coupon_discount_total > 0 ) {
                $points_per_dollar = $earning_base_amount > 0 ? ( $total_points / $earning_base_amount ) : 0;
                $discount_points   = $points_per_dollar * (float) $coupon_discount_total;
                $total_points     -= $discount_points; // Only discount the discounted points model
                // Do NOT alter $total_points_full; it must always represent pre-discount points
                $result['messages'][] = sprintf( 'Reduced %s points due to coupon discounts', number_format( $discount_points ) );
            }
        }

        // Ensure non-negative
        $total_points = max( 0, $total_points );

        // Round points according to YITH's rounding rules
        if ( function_exists( 'yith_ywpar_round_points' ) ) {
            $total_points = yith_ywpar_round_points( $total_points );
        } else {
            $total_points = round( $total_points );
        }

        // Populate result fields strictly from YITH per-line rule calculations above
        $points_full_calc = $total_points_full;
        $points_disc_calc = $total_points_discounted;
        if ( function_exists( 'yith_ywpar_round_points' ) ) {
            $points_full_calc = yith_ywpar_round_points( $points_full_calc );
            $points_disc_calc = yith_ywpar_round_points( $points_disc_calc );
        } else {
            $points_full_calc = round( $points_full_calc );
            $points_disc_calc = round( $points_disc_calc );
        }

        $result['total_points'] = $total_points;
        $result['total_points_full'] = (int) $points_full_calc;
        $result['total_points_discounted'] = (int) $points_disc_calc;
        $result['earning_base_amount'] = $earning_base_amount;
        $result['points_per_dollar'] = ($earning_base_amount > 0) ? ($total_points / $earning_base_amount) : 0;

        if ( $total_points > 0 ) {
            $result['messages'][] = sprintf( 'Customer would earn %d points from this order', $total_points );
        } else {
            $result['messages'][] = 'No points would be earned from this order';
        }

    } catch ( Exception $e ) {
        $result['success'] = false;
        $result['errors'][] = 'Error calculating points: ' . $e->getMessage();
        error_log( '[EAO YITH] Exception calculating order points preview: ' . $e->getMessage() );
    }

    // Restore previous user if we switched context
    if ( isset($did_switch_user) && $did_switch_user ) {
        wp_set_current_user( $prev_user_id );
    }

    return $result;
}

/**
 * Calculate points from price using YITH's conversion rate
 *
 * @param float $price Price amount
 * @param string $currency Currency code
 * @return int Points earned
 */
function eao_yith_get_points_from_price( $price, $currency = '' ) {
    if ( ! function_exists( 'yith_points' ) ) {
        return 0;
    }

    try {
        return yith_points()->earning->get_points_earned_from_price( $price, $currency, true );
    } catch ( Exception $e ) {
        error_log( '[EAO YITH] Error calculating points from price: ' . $e->getMessage() );
        return 0;
    }
}

/**
 * Calculate product points using YITH's product rules
 *
 * @param WC_Product $product Product object
 * @param string $currency Currency code
 * @param YITH_WC_Points_Rewards_Customer $customer Customer object
 * @param array $item_data Item data
 * @return int|false Points earned or false
 */
function eao_yith_get_product_points( $product, $currency, $customer, $item_data ) {
    if ( ! function_exists( 'yith_points' ) ) {
        return false;
    }

    try {
        // Use YITH's calculation method
        $calculation_mode = ywpar_get_option( 'earn_prices_calc', 'unit' );
        
        if ( 'subtotal' === $calculation_mode && ! empty( $item_data ) ) {
            // Calculate by subtotal
            $user = $customer ? $customer->get_wc_customer() : null;
            return yith_points()->earning->get_product_points( $product, $currency, true, $user, $item_data );
        } else {
            // Calculate per unit
            $user = $customer ? $customer->get_wc_customer() : null;
            return yith_points()->earning->calculate_product_points( $product, $currency, true, $user );
        }
    } catch ( Exception $e ) {
        error_log( '[EAO YITH] Error calculating product points: ' . $e->getMessage() );
        return false;
    }
}

/**
 * Determine which earning rule was applied for a product (by YITH precedence)
 * and compute the resulting per-item points produced by that rule.
 *
 * @param WC_Product $product
 * @param YITH_WC_Points_Rewards_Customer $customer
 * @param string $currency
 * @param int $base_points Points from base conversion (before rules)
 * @return array|null { id, name, points } or null if no rule applied
 */
function eao_yith_get_applied_earning_rule_details( $product, $customer, $currency, $base_points ) {
    if ( ! class_exists( 'YITH_WC_Points_Rewards_Helper' ) ) {
        return null;
    }

    try {
        $user        = $customer ? $customer->get_wc_customer() : null;
        $valid_rules = YITH_WC_Points_Rewards_Helper::get_earning_rules_valid_for_product( $product, $user );

        if ( empty( $valid_rules ) ) {
            return null;
        }

        $product_rules    = array();
        $on_sale_rules    = array();
        $categories_rules = array();
        $tags_rules       = array();
        $general_rules    = array();

        foreach ( $valid_rules as $valid_rule ) {
            switch ( $valid_rule->get_apply_to() ) {
                case 'selected_products':
                    $product_rules[] = $valid_rule; break;
                case 'on_sale_products':
                    $on_sale_rules[] = $valid_rule; break;
                case 'selected_categories':
                    $categories_rules[] = $valid_rule; break;
                case 'selected_tags':
                    $tags_rules[] = $valid_rule; break;
                default:
                    $general_rules[] = $valid_rule; break;
            }
        }

        $selected = null;
        if ( ! empty( $product_rules ) ) {
            $selected = $product_rules[0];
        } elseif ( ! empty( $on_sale_rules ) ) {
            $selected = $on_sale_rules[0];
        } elseif ( ! empty( $categories_rules ) ) {
            $selected = $categories_rules[0];
        } elseif ( ! empty( $tags_rules ) ) {
            $selected = $tags_rules[0];
        } elseif ( ! empty( $general_rules ) ) {
            $selected = $general_rules[0];
        }

        if ( ! $selected || ! method_exists( $selected, 'calculate_points' ) ) {
            return null;
        }

        $applied_points = $selected->calculate_points( $product, $base_points, $currency );

        $rule_name = method_exists( $selected, 'get_name' ) ? $selected->get_name() : '';
        $rule_id   = method_exists( $selected, 'get_id' ) ? $selected->get_id() : 0;

        // Collect additional diagnostics about the rule itself
        $rule_type = method_exists( $selected, 'get_points_type_conversion' ) ? $selected->get_points_type_conversion() : '';
        $rule_details = array('type' => $rule_type);
        try {
            if ( 'override' === $rule_type && method_exists( $selected, 'get_earn_points_conversion_rate' ) ) {
                $conv = $selected->get_earn_points_conversion_rate();
                $conv = is_array($conv) && isset($conv[$currency]) ? $conv[$currency] : $conv;
                if ( is_array($conv) ) {
                    $rule_details['conversion'] = array(
                        'money' => isset($conv['money']) ? (float)$conv['money'] : null,
                        'points' => isset($conv['points']) ? (float)$conv['points'] : null,
                    );
                }
            } elseif ( 'percentage' === $rule_type && method_exists( $selected, 'get_percentage_points_to_earn' ) ) {
                $rule_details['percentage'] = (float) $selected->get_percentage_points_to_earn();
            } elseif ( 'fixed' === $rule_type && method_exists( $selected, 'get_fixed_points_to_earn' ) ) {
                $rule_details['fixed_points'] = (float) $selected->get_fixed_points_to_earn();
            }
        } catch ( Exception $e ) { /* no-op */ }

        return array(
            'id'     => $rule_id,
            'name'   => $rule_name,
            'points' => $applied_points,
            'meta'   => $rule_details,
        );
    } catch ( Exception $e ) {
        return null;
    }
}

/**
 * Award points to customer for completed order
 * This function follows YITH's awarding logic and restrictions
 *
 * @param int $order_id Order ID
 * @param int $points_to_award Optional: specific points to award (uses calculated if not provided)
 * @return array Result with success status and messages
 */
function eao_yith_award_order_points( $order_id, $points_to_award = null ) {
    $result = array(
        'success' => true,
        'messages' => array(),
        'errors' => array()
    );

    try {
        // Check if YITH is available
        if ( ! eao_yith_is_available() ) {
            $result['success'] = false;
            $result['errors'][] = 'YITH Points plugin not available';
            return $result;
        }

        // Get order
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            $result['success'] = false;
            $result['errors'][] = 'Invalid order';
            return $result;
        }

        // Check if points already awarded
        $existing_points = $order->get_meta( '_ywpar_points_earned' );
        if ( '' !== $existing_points ) {
            $result['messages'][] = 'Points already awarded for this order: ' . $existing_points;
            return $result;
        }

        // Calculate points if not provided - prefer UI line-3 out-of-pocket total if present
        if ( null === $points_to_award ) {
            $points_to_award = absint( $order->get_meta( '_eao_points_expected_award', true ) );
            if ( $points_to_award <= 0 ) {
                $points_calculation = eao_yith_calculate_order_points_preview( $order_id );
                if ( ! $points_calculation['success'] || ! $points_calculation['can_earn'] ) {
                    $result['success'] = false;
                    $result['errors'] = array_merge( $result['errors'], $points_calculation['errors'] );
                    $result['errors'] = array_merge( $result['errors'], $points_calculation['reasons'] );
                    return $result;
                }
                $points_to_award = $points_calculation['total_points'];
            }
        }

        if ( $points_to_award <= 0 ) {
            $result['messages'][] = 'No points to award for this order';
            return $result;
        }

        // Get customer
        $customer_id = $order->get_customer_id();
        $customer = ywpar_get_customer( $customer_id );
        
        if ( ! $customer ) {
            $result['success'] = false;
            $result['errors'][] = 'Customer not found';
            return $result;
        }

        // Award points using YITH's transaction system
        if ( function_exists( 'ywpar_get_customer' ) && method_exists( $customer, 'update_points' ) ) {
            $success = $customer->update_points(
                $points_to_award,
                'order_completed',  // YITH's standard action for order completion
                array(
                    'order_id' => $order_id,
                    'description' => sprintf( 'Points earned from Order #%d', $order_id )
                )
            );

            if ( $success ) {
                // Update order metadata
                $order->update_meta_data( '_ywpar_points_earned', $points_to_award );
                $conversion_rate = yith_points()->earning->get_conversion_option( $order->get_currency(), $order );
                $order->update_meta_data( '_ywpar_conversion_points', $conversion_rate );
                $order->save();

                $result['messages'][] = sprintf( 'Successfully awarded %d points to customer %d', $points_to_award, $customer_id );
                error_log( '[EAO YITH] Points awarded - Order: ' . $order_id . ', Customer: ' . $customer_id . ', Points: ' . $points_to_award );
            } else {
                $result['success'] = false;
                $result['errors'][] = 'Failed to award points using YITH transaction system';
            }
        } else {
            $result['success'] = false;
            $result['errors'][] = 'YITH points awarding functions not available';
        }

    } catch ( Exception $e ) {
        $result['success'] = false;
        $result['errors'][] = 'Error awarding points: ' . $e->getMessage();
        error_log( '[EAO YITH] Exception awarding order points: ' . $e->getMessage() );
    }

    return $result;
}

/**
 * Check if YITH integration is available
 *
 * @return bool YITH availability status
 */
function eao_yith_is_available() {
    return EAO_YITH_Points_Core::get_instance()->is_yith_available();
}

/**
 * Debug function to check what earning rules are available for a product
 *
 * @param WC_Product $product Product object
 * @param YITH_WC_Points_Rewards_Customer $customer Customer object
 * @return array Debug information about rules
 */
function eao_yith_debug_product_rules( $product, $customer ) {
    $debug_info = array(
        'product_id' => $product->get_id(),
        'product_name' => $product->get_name(),
        'rules_found' => array(),
        'total_rules' => 0,
        'customer_valid' => false
    );

    if ( ! function_exists( 'ywpar_get_customer' ) ) {
        $debug_info['error'] = 'YITH functions not available';
        return $debug_info;
    }

    try {
        // Check customer validity
        $debug_info['customer_valid'] = $customer && $customer->is_enabled();
        
        // Get user object
        $user = $customer ? $customer->get_wc_customer() : null;
        
        // Get valid earning rules using YITH's helper
        if ( class_exists( 'YITH_WC_Points_Rewards_Helper' ) ) {
            $valid_rules = YITH_WC_Points_Rewards_Helper::get_earning_rules_valid_for_product( $product, $user );
            
            $debug_info['total_rules'] = count( $valid_rules );
            
            if ( $valid_rules ) {
                foreach ( $valid_rules as $rule ) {
                    $rule_info = array(
                        'apply_to' => method_exists( $rule, 'get_apply_to' ) ? $rule->get_apply_to() : 'unknown',
                        'rule_id' => method_exists( $rule, 'get_id' ) ? $rule->get_id() : 'unknown',
                        'priority' => method_exists( $rule, 'get_priority' ) ? $rule->get_priority() : 'unknown'
                    );
                    
                    if ( method_exists( $rule, 'get_points_type_conversion' ) ) {
                        $rule_info['conversion_type'] = $rule->get_points_type_conversion();
                    }
                    
                    $debug_info['rules_found'][] = $rule_info;
                }
            }
        }
        
        // Also check global earning rules
        if ( class_exists( 'YITH_WC_Points_Rewards_Helper' ) ) {
            $all_valid_rules = YITH_WC_Points_Rewards_Helper::get_valid_earning_rules( $user );
            $debug_info['total_global_rules'] = count( $all_valid_rules );
        }
        
    } catch ( Exception $e ) {
        $debug_info['error'] = $e->getMessage();
    }

    return $debug_info;
}

// Initialize the YITH Points integration
add_action( 'init', function() {
    EAO_YITH_Points_Core::get_instance();
}, 10 ); 