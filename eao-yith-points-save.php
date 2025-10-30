<?php
/**
 * Enhanced Admin Order - YITH Points Save Functions
 * Handles YITH Points & Rewards redemption during order saves
 * 
 * @package EnhancedAdminOrder
 * @since 2.2.0
 * @version 2.2.6 - LOGIC SIMPLIFICATION: Simplified points adjustment function to process exact deltas. Removed confusing action_type parameter and made delta handling more direct.
 * @author Amnon Manneberg
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Process YITH points redemption during order save
 *
 * @param int   $order_id The order ID
 * @param array $request_data The POST data from the save request
 * @return array Result with success status and messages
 */
function eao_process_yith_points_redemption( $order_id, $request_data ) {
    $result = array(
        'success' => true,
        'messages' => array(),
        'errors' => array()
    );

    try {
        // Check if YITH is available
        if ( ! eao_yith_is_available() ) { return $result; }

        // Get NEW points to redeem from request
        $new_points_to_redeem = isset( $request_data['eao_points_to_redeem'] ) 
            ? absint( $request_data['eao_points_to_redeem'] ) 
            : 0;

        // Get order and validate
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            $result['success'] = false;
            $result['errors'][] = 'Invalid order for points redemption';
            return $result;
        }

        // Get customer and validate
        $customer_id = $order->get_customer_id();
        if ( ! $customer_id ) {
            $result['success'] = false;
            $result['errors'][] = 'Points redemption only available for registered customers';
            return $result;
        }

        // CRITICAL FIX: Detect EXISTING points redemption from order
        $existing_points_redeemed = 0;
        
        // Check YITH order metadata first
        $coupon_points = $order->get_meta( '_ywpar_coupon_points' );
        if ( $coupon_points ) {
            $existing_points_redeemed = intval( $coupon_points );
        } else {
            // Fallback: scan for YITH discount coupons
            $existing_coupons = $order->get_coupon_codes();
            foreach ( $existing_coupons as $coupon_code ) {
                if ( strpos( $coupon_code, 'ywpar_discount_' ) === 0 ) {
                    $coupon = new WC_Coupon( $coupon_code );
                    if ( $coupon->get_id() ) {
                        // Calculate points from discount amount (1 point = $0.10)
                        $existing_points_redeemed = intval( $coupon->get_amount() * 10 );
                        break;
                    }
                }
            }
        }

        

        // CRITICAL FIX: Calculate the DIFFERENCE (net change needed)
        $points_change = $new_points_to_redeem - $existing_points_redeemed;

        // If no change needed, return success
        if ( $points_change === 0 ) {
            $result['messages'][] = 'No points redemption changes needed';
            error_log( '[EAO YITH] No points change needed for order: ' . $order_id );
            return $result;
        }

        // Duplicate transaction protection
        $recent_processing_key = 'eao_yith_processing_' . $order_id;
        $recent_processing = get_transient( $recent_processing_key );
        
        if ( $recent_processing ) {
            $result['messages'][] = 'Points redemption already processed for this order';
            return $result;
        }
        
        // Set processing flag for 30 seconds to prevent duplicates
        set_transient( $recent_processing_key, time(), 30 );

        // Handle INCREASE in points usage (need to deduct more points)
        if ( $points_change > 0 ) {
            // Validate customer has sufficient points for the additional deduction
            $customer_points = eao_yith_get_customer_points( $customer_id );
            if ( $points_change > $customer_points ) {
                delete_transient( $recent_processing_key );
                $result['success'] = false;
                $result['errors'][] = sprintf( 
                    'Customer only has %d points available, cannot deduct additional %d points', 
                    $customer_points, 
                    $points_change 
                );
                error_log( '[EAO YITH] Insufficient points for increase - Available: ' . $customer_points . ', Additional needed: ' . $points_change );
                return $result;
            }

            // Deduct points (first time vs additional)
            $is_first_deduction = ( $existing_points_redeemed <= 0 );
            // When calling adjust, pass delta only; the function will record proper action text
            $deduction_result = eao_adjust_customer_points( $customer_id, -$points_change, $order_id, $is_first_deduction );
            
            if ( ! $deduction_result['success'] ) {
                delete_transient( $recent_processing_key );
                $result['success'] = false;
                $result['errors'] = array_merge( $result['errors'], $deduction_result['errors'] );
                return $result;
            }

            $result['messages'][] = sprintf( '%s %d points (total now: %d points)'
                , $is_first_deduction ? 'Deducted' : 'Deducted additional'
                , $points_change
                , $new_points_to_redeem
            );
            
        }
        
        // Handle DECREASE in points usage (need to refund points back)
        else if ( $points_change < 0 ) {
            $points_to_refund = abs( $points_change );
            
            // Add points back to customer
            $refund_result = eao_adjust_customer_points( $customer_id, $points_to_refund, $order_id, false );
            
            if ( ! $refund_result['success'] ) {
                delete_transient( $recent_processing_key );
                $result['success'] = false;
                $result['errors'] = array_merge( $result['errors'], $refund_result['errors'] );
                return $result;
            }

            $result['messages'][] = sprintf( 'Refunded %d points back to customer (total now: %d points)', $points_to_refund, $new_points_to_redeem );
        }

        // Update/create coupon with new total points
        if ( $new_points_to_redeem > 0 ) {
            $discount_amount = $new_points_to_redeem / 10;
            
            // CRITICAL DEBUG: Get the order total BEFORE any point discounts
            // We need to base the cap on the pre-discount total, not post-discount total
            
            // Remove any existing points discount temporarily for proper validation
            $existing_coupons = $order->get_coupon_codes();
            $removed_yith_coupon = null;
            foreach ( $existing_coupons as $existing_code ) {
                if ( strpos( $existing_code, 'ywpar_discount_' ) === 0 ) {
                    $order->remove_coupon( $existing_code );
                    $removed_yith_coupon = $existing_code;
                    
                    break;
                }
            }
            
            // Force recalculation to get the order total WITHOUT points discount
            $order->calculate_totals(false); // Calculate without saving
            $order_total_before_points = $order->get_total();
            
            // CRITICAL DEBUG: Analyze the order total components
            $items_total = 0;
            $items_total_after_discounts = 0;
            foreach ( $order->get_items() as $item_id => $item ) {
                $item_subtotal = $item->get_subtotal(); // Before discounts
                $item_total = $item->get_total(); // After discounts
                $items_total += $item_subtotal;
                $items_total_after_discounts += $item_total;
                
                // error_log( '[EAO YITH DEBUG] Item ' . $item_id . ': Subtotal: $' . $item_subtotal . ', Total (after discount): $' . $item_total );

                $items_total_after_discounts += $item_total;
            }
            
            $shipping_total = $order->get_shipping_total();
            $tax_total = $order->get_total_tax();
            $order_total_calculated_manually = $items_total_after_discounts + $shipping_total + $tax_total;
            
            // error_log( '[EAO YITH DEBUG] Order total breakdown:' );
            // error_log( 'Items total (after discounts): $' . ( $order_total_calculated_manually - $shipping_total - $order_tax_amount ) );
            // error_log( 'Shipping: $' . $shipping_total );
            // error_log( 'Tax: $' . $order_tax_amount );
            // error_log( 'Manual calculation total: $' . $order_total_calculated_manually );
            // error_log( 'WooCommerce calculated total: $' . $order_total_before_points );

            // Check if there's a mismatch
            if ( abs( $order_total_calculated_manually - $order_total_before_points ) > 0.01 ) {
                // error_log( '[EAO YITH DEBUG] ❌ MISMATCH! WooCommerce total calculation differs from manual calculation by $' . abs( $order_total_calculated_manually - $order_total_before_points ) );
            } else {
                // error_log( '[EAO YITH DEBUG] ✅ Order total calculation matches manual calculation' );
            }
            
            // Restore the removed coupon if it existed (we'll replace it properly later)
            if ( $removed_yith_coupon ) {
                $order->apply_coupon( $removed_yith_coupon );
                error_log( '[EAO YITH VALIDATION] Restored points coupon after validation: ' . $removed_yith_coupon );
            }
            
            // CRITICAL DECISION: Should we use WooCommerce's calculated total or the manually calculated total?
            // If there's a mismatch, the product discounts might not be properly applied at the WC level
            
            if ( abs( $order_total_calculated_manually - $order_total_before_points ) > 0.01 ) {
                // Use manual calculation as more reliable
                $order_total = $order_total_calculated_manually;
                
                // Adjust for points discount if any (this should be subtracted from the total)
                $points_discount_value = 0;
                foreach ( $order->get_items() as $item_id => $item ) {
                    $item_subtotal = $item->get_subtotal(); // Before discounts
                    $item_total = $item->get_total(); // After discounts
                    $item_discount = $item_subtotal - $item_total;
                    $points_discount_value += $item_discount;
                }
                
                $order_total -= $points_discount_value;
                
                // error_log( '[EAO YITH DEBUG] Using manual calculation for validation due to mismatch: $' . $order_total );
            } else {
                // WooCommerce calculation is consistent
                $order_total = $order_total_before_points - $points_discount_value;
                // error_log( '[EAO YITH DEBUG] Using WooCommerce calculation for validation: $' . $order_total );
            }
            
            // Comprehensive debug logging
            
            
            if ( $discount_amount > $order_total ) {
                $discount_amount = $order_total;
                $new_points_to_redeem = $order_total * 10;
                
                
                
                $result['messages'][] = sprintf(
                    'Discount capped at order total. Using %d points for $%.2f discount',
                    $new_points_to_redeem,
                    $discount_amount
                );
            }

            $coupon_result = eao_create_points_discount_coupon( $order, $new_points_to_redeem, $discount_amount );
            
            if ( ! $coupon_result['success'] ) {
                delete_transient( $recent_processing_key );
                $result['success'] = false;
                $result['errors'] = array_merge( $result['errors'], $coupon_result['errors'] );
                return $result;
            }
        } else {
            // Remove any existing YITH points coupons if points reduced to zero
            $existing_coupons = $order->get_coupon_codes();
            foreach ( $existing_coupons as $existing_code ) {
                if ( strpos( $existing_code, 'ywpar_discount_' ) === 0 ) {
                    $order->remove_coupon( $existing_code );
                    $existing_coupon = new WC_Coupon( $existing_code );
                    if ( $existing_coupon->get_id() ) {
                        wp_delete_post( $existing_coupon->get_id(), true );
                    }
                    error_log( '[EAO YITH] Removed YITH points coupon (points reduced to zero): ' . $existing_code );
                }
            }

            // Persist zero to order meta so future loads do not think points still exist
            $order->update_meta_data( '_ywpar_coupon_points', 0 );
            $order->update_meta_data( '_ywpar_coupon_amount', 0 );
            error_log( '[EAO YITH] Persisted zero points to order meta for order: ' . $order_id );

            // Recalculate totals without the coupon
            $order->calculate_totals( false );
            $order->save();
        }

        // Success - clear processing flag
        delete_transient( $recent_processing_key );

        // Success messages
        $result['messages'][] = sprintf(
            'Points redemption updated successfully: %d points for $%.2f discount',
            $new_points_to_redeem,
            $new_points_to_redeem / 10
        );

        error_log( '[EAO YITH] Points redemption update successful - Order: ' . $order_id . ', Change: ' . $points_change . ', Total: ' . $new_points_to_redeem );

    } catch ( Exception $e ) {
        // Clear processing flag on exception
        if ( isset( $recent_processing_key ) ) {
            delete_transient( $recent_processing_key );
        }
        
        $result['success'] = false;
        $result['errors'][] = 'Points redemption failed: ' . $e->getMessage();
        error_log( '[EAO YITH] Exception during points redemption: ' . $e->getMessage() );
    }

    return $result;
}

/**
 * Create or update a discount coupon for points redemption
 * Following YITH's native coupon creation patterns
 *
 * @param WC_Order $order The order object
 * @param int      $points_redeemed Number of points redeemed
 * @param float    $discount_amount Discount amount in currency
 * @return array Result with success status and messages
 */
function eao_create_points_discount_coupon( $order, $points_redeemed, $discount_amount ) {
    $result = array(
        'success' => true,
        'messages' => array(),
        'errors' => array()
    );

    try {
        $order_id = $order->get_id();
        
        // Use YITH's coupon naming pattern: ywpar_discount_{timestamp}
        $coupon_code = 'ywpar_discount_' . time();

        // Check if order already has a YITH points discount coupon
        $existing_coupons = $order->get_coupon_codes();
        $yith_coupon_found = false;

        foreach ( $existing_coupons as $existing_code ) {
            if ( strpos( $existing_code, 'ywpar_discount_' ) === 0 ) {
                // Remove existing YITH points coupon
                $order->remove_coupon( $existing_code );
                
                // Delete the old coupon post
                $existing_coupon = new WC_Coupon( $existing_code );
                if ( $existing_coupon->get_id() ) {
                    wp_delete_post( $existing_coupon->get_id(), true );
                }
                
                $yith_coupon_found = true;
                error_log( '[EAO YITH] Removed existing YITH points coupon: ' . $existing_code );
                break;
            }
        }

        // Create new coupon following YITH patterns
        $coupon = new WC_Coupon();
        $coupon->set_code( $coupon_code );
        $coupon->set_description( sprintf( 'Points discount: %d points redeemed', $points_redeemed ) );
        $coupon->set_discount_type( 'fixed_cart' );
        $coupon->set_amount( $discount_amount );
        $coupon->set_usage_limit( 1 );
        $coupon->set_usage_limit_per_user( 1 );
        $coupon->set_individual_use( false );
        $coupon->set_exclude_sale_items( false );
        $coupon->set_minimum_amount( 0 );
        $coupon->set_maximum_amount( 0 );
        $coupon->set_email_restrictions( array( $order->get_billing_email() ) );
        
        // Add YITH's coupon metadata to mark it as a points coupon
        $coupon->add_meta_data( 'ywpar_coupon', 1 );
        
        // Save coupon
        $coupon_id = $coupon->save();
        
        if ( ! $coupon_id ) {
            $result['success'] = false;
            $result['errors'][] = 'Failed to create YITH points discount coupon';
            return $result;
        }

        // Apply coupon to order
        $order->apply_coupon( $coupon_code );
        
        // Store YITH's standard order metadata
        $order->update_meta_data( '_ywpar_coupon_points', $points_redeemed );
        $order->update_meta_data( '_ywpar_coupon_amount', $discount_amount );
        
        // Recalculate order totals
        $order->calculate_totals();
        $order->save();

        $result['messages'][] = sprintf( 'Created YITH points discount coupon: %s', $coupon_code );
        

        } catch ( Exception $e ) {
        $result['success'] = false;
        $result['errors'][] = 'Failed to create discount coupon: ' . $e->getMessage();
    }

    return $result;
}

/**
 * Adjust customer points by delta amount with proper YITH transaction
 *
 * @param int $customer_id Customer user ID  
 * @param int $points_delta Points delta (positive = add points, negative = deduct points)
 * @param int $order_id Order ID for reference
 * @return array Result with success status and messages
 */
function eao_adjust_customer_points( $customer_id, $points_delta, $order_id, $is_first_deduction = false ) {
    $result = array(
        'success' => true,
        'messages' => array(),
        'errors' => array()
    );

    try {
        // Determine YITH action and description based on delta sign
        if ( $points_delta > 0 ) {
            // POSITIVE DELTA = REFUND points back to customer
            $yith_action = 'admin_action';  // YITH's generic admin adjustment action
            $description = sprintf( 'Refunded points from Order #%d reduction', $order_id );
            $log_message = sprintf( 'Refunded %d points to customer %d (order reduction)', $points_delta, $customer_id );
        } else if ( $points_delta < 0 ) {
            // NEGATIVE DELTA = DEDUCT additional points from customer
            $yith_action = 'redeemed_points';  // YITH's standard redemption action
            $description = $is_first_deduction
                ? sprintf( 'Redeemed points for Order #%d', $order_id )
                : sprintf( 'Additional redemption for Order #%d', $order_id );
            $log_message = sprintf( 'Deducted additional %d points from customer %d', abs($points_delta), $customer_id );
        } else {
            // Zero delta - should not happen but handle gracefully
            $result['messages'][] = 'No points adjustment needed (zero delta)';
            return $result;
        }

        

        // Use YITH's API to adjust points with proper transaction recording
        if ( function_exists( 'ywpar_get_customer' ) ) {
            $customer = ywpar_get_customer( $customer_id );
            
            if ( $customer && method_exists( $customer, 'update_points' ) ) {
                // Use YITH's native transaction system with the exact delta
                $success = $customer->update_points(
                    $points_delta,         // The exact delta amount (positive or negative)
                    $yith_action,          // YITH action type
                    array( 
                        'order_id' => $order_id,
                        'description' => $description
                    )
                );
                
                if ( $success ) {
                    $result['messages'][] = $log_message . ' using YITH native transaction';
                    return $result;
                }
            }
        }

        // Fallback: Direct user meta update (less preferred - no transaction record)
        $current_points = get_user_meta( $customer_id, '_ywpar_user_total_points', true );
        $current_points = is_numeric( $current_points ) ? intval( $current_points ) : 0;
        
        $new_points = max( 0, $current_points + $points_delta );
        update_user_meta( $customer_id, '_ywpar_user_total_points', $new_points );
        
        $result['messages'][] = $log_message . ' using fallback method (no transaction record)';

    } catch ( Exception $e ) {
        $result['success'] = false;
        $result['errors'][] = 'Failed to adjust customer points: ' . $e->getMessage();
        
    }

    return $result;
}

/**
 * Validate points redemption request
 *
 * @param array $request_data Request data from save operation
 * @param int   $order_id Order ID
 * @return array Validation result
 */
function eao_validate_points_redemption_request( $request_data, $order_id ) {
    $result = array(
        'valid' => true,
        'errors' => array()
    );

    // Basic validation
    if ( ! isset( $request_data['eao_points_to_redeem'] ) ) {
        return $result; // No points data, skip validation
    }

    $points_to_redeem = absint( $request_data['eao_points_to_redeem'] );
    
    if ( $points_to_redeem <= 0 ) {
        return $result; // Zero points, valid but no action needed
    }

    // Validate order
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        $result['valid'] = false;
        $result['errors'][] = 'Invalid order for points validation';
        return $result;
    }

    // Validate customer
    $customer_id = $order->get_customer_id();
    if ( ! $customer_id ) {
        $result['valid'] = false;
        $result['errors'][] = 'Points redemption requires registered customer';
        return $result;
    }

    // Validate YITH availability
    if ( ! eao_yith_is_available() ) {
        $result['valid'] = false;
        $result['errors'][] = 'YITH Points plugin not available';
        return $result;
    }

    // Validate customer points balance
    $customer_points = eao_yith_get_customer_points( $customer_id );
    if ( $points_to_redeem > $customer_points ) {
        $result['valid'] = false;
        $result['errors'][] = sprintf( 
            'Insufficient points: customer has %d, requested %d', 
            $customer_points, 
            $points_to_redeem 
        );
        return $result;
    }

    return $result;
} 