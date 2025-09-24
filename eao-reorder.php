<?php
/**
 * Reorder utilities for Enhanced Admin Order Editor
 *
 * @package EnhancedAdminOrder
 * @since 3.1.3
 * @version 3.1.3 - Add Reorder action to clone order to pending payment with same customer, addresses, items and discounts
 * @author Amnon Manneberg
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Handle reorder action: create a new order cloned from a source order
 * - Same customer
 * - Same billing/shipping addresses
 * - Same products, quantities and item totals (including markups/discounts)
 * - Copies exclusion/discount meta and global discount percent
 * - New order status: pending payment
 */
function eao_handle_reorder_request() {
	if (!isset($_GET['action']) || $_GET['action'] !== 'eao_reorder') {
		return;
	}

	$source_order_id = isset($_GET['source_order_id']) ? absint($_GET['source_order_id']) : 0;
	if (!$source_order_id) {
		wp_die(__('Invalid source order ID.', 'enhanced-admin-order'));
	}

	if (!current_user_can('edit_shop_orders')) {
		wp_die(__('You do not have permission to reorder.', 'enhanced-admin-order'));
	}

	if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'eao_reorder_' . $source_order_id)) {
		wp_die(__('Security check failed.', 'enhanced-admin-order'));
	}

	$source_order = wc_get_order($source_order_id);
	if (!$source_order) {
		wp_die(__('Source order not found.', 'enhanced-admin-order'));
	}

	try {
		// Create new order in pending status
		$new_order = wc_create_order(array(
			'status'      => 'pending',
			'created_via' => 'enhanced-admin-order-reorder',
		));
		if (is_wp_error($new_order)) {
			wp_die(__('Failed to create new order: ', 'enhanced-admin-order') . $new_order->get_error_message());
		}

		// Customer
		$customer_id = $source_order->get_customer_id();
		if ($customer_id) {
			$new_order->set_customer_id($customer_id);
		}

		// Addresses (billing & shipping)
		$billing  = $source_order->get_address('billing');
		$shipping = $source_order->get_address('shipping');
		if (is_array($billing)) {
			$new_order->set_address($billing, 'billing');
		}
		if (is_array($shipping)) {
			$new_order->set_address($shipping, 'shipping');
		}

		// Persist selected multi-address keys if our editor stored them
		$billing_key  = $source_order->get_meta('_eao_billing_address_key', true);
		$shipping_key = $source_order->get_meta('_eao_shipping_address_key', true);
		// Fallback to post meta (covers HPOS vs legacy storage)
		if ($billing_key === '' || $billing_key === null) {
			$billing_key = get_post_meta($source_order_id, '_eao_billing_address_key', true);
		}
		if ($shipping_key === '' || $shipping_key === null) {
			$shipping_key = get_post_meta($source_order_id, '_eao_shipping_address_key', true);
		}
		// error_log('[EAO REORDER] Source keys bill=' . (string)$billing_key . ' ship=' . (string)$shipping_key);
		if (!empty($billing_key)) {
			$new_order->update_meta_data('_eao_billing_address_key', $billing_key);
		}
		if (!empty($shipping_key)) {
			$new_order->update_meta_data('_eao_shipping_address_key', $shipping_key);
		}
		// Save early so keys are available immediately on the new order record
		$new_order->save();

		// Copy global product discount percent (editor configuration)
		$global_discount = $source_order->get_meta('_eao_global_product_discount_percent', true);
		if ($global_discount !== '') {
			$new_order->update_meta_data('_eao_global_product_discount_percent', $global_discount);
		}

		// Copy line items with exact totals and relevant meta
		$item_meta_keys_to_copy = array(
			'_eao_item_discount_percent',
			'_eao_exclude_global_discount',
			'_eao_exclude_from_global_discount',
		);

		foreach ($source_order->get_items('line_item') as $item) {
			$product      = $item->get_product();
			$product_id   = $item->get_product_id();
			$variation_id = method_exists($item, 'get_variation_id') ? $item->get_variation_id() : 0;

			$new_item = new WC_Order_Item_Product();
			if ($product) {
				$new_item->set_product($product);
			} else {
				$new_item->set_product_id($product_id);
				if ($variation_id) {
					$new_item->set_variation_id($variation_id);
				}
			}
			$new_item->set_name($item->get_name());
			$new_item->set_quantity($item->get_quantity());
			$new_item->set_subtotal($item->get_subtotal());
			$new_item->set_total($item->get_total());
			$new_item->set_subtotal_tax($item->get_subtotal_tax());
			$new_item->set_total_tax($item->get_total_tax());
			$new_item->set_taxes($item->get_taxes());

			// If source line is excluded from global discount and effectively fixed-price (subtotal == total),
			// do not carry over any per‑item percent meta to avoid fresh banner on the new order
			$src_excluded = wc_string_to_bool( $item->get_meta('_eao_exclude_global_discount', true) );
			if ( $src_excluded && abs( (float)$item->get_subtotal() - (float)$item->get_total() ) < 0.01 ) {
				// Ensure the new item does not retain a percent meta
				$new_item->delete_meta_data('_eao_item_discount_percent');
			}

			// Copy selected meta critical for our editor pricing semantics
			foreach ($item_meta_keys_to_copy as $meta_key) {
				$val = $item->get_meta($meta_key, true);
				if ($val !== '' && $val !== null) {
					$new_item->update_meta_data($meta_key, $val);
				}
			}

			// Copy public (non-underscore) meta like variation attributes
			$all_meta = $item->get_meta_data();
			if (is_array($all_meta)) {
				foreach ($all_meta as $meta_obj) {
					$key = $meta_obj->get_data()['key'];
					$val = $meta_obj->get_data()['value'];
					if (!is_string($key) || $key === '') { continue; }
					if ($key[0] === '_') { continue; }
					$new_item->update_meta_data($key, $val);
				}
			}

			$new_order->add_item($new_item);
		}

		// Persist and compute order totals without mutating item totals
		$new_order->calculate_totals(false);
		// Normalize YITH/EAO points state on the fresh duplicate to prevent stale banners/notes
		$new_order->delete_meta_data('_ywpar_coupon_points');
		$new_order->delete_meta_data('_ywpar_coupon_amount');
		$new_order->delete_meta_data('_eao_points_granted');
		$new_order->delete_meta_data('_eao_points_granted_points');
		$new_order->delete_meta_data('_eao_points_revoked');
		$new_order->delete_meta_data('_eao_points_revoked_points');
		$new_order->save();

		// Hard-persist address keys as post meta as well to ensure template reads them on first load
		if (!empty($billing_key)) {
			update_post_meta($new_order->get_id(), '_eao_billing_address_key', $billing_key);
		}
		if (!empty($shipping_key)) {
			update_post_meta($new_order->get_id(), '_eao_shipping_address_key', $shipping_key);
		}
		// Verify (disabled)
		// $verify_bill = get_post_meta($new_order->get_id(), '_eao_billing_address_key', true);
		// $verify_ship = get_post_meta($new_order->get_id(), '_eao_shipping_address_key', true);
		// error_log('[EAO REORDER] New order ' . $new_order->get_id() . ' saved keys bill=' . (string)$verify_bill . ' ship=' . (string)$verify_ship);

		$new_order_id = $new_order->get_id();

		// Redirect to Enhanced editor for the new order
		$edit_args = array(
			'page'     => 'eao_custom_order_editor_page',
			'order_id' => $new_order_id,
		);
		if (!empty($billing_key)) { $edit_args['bill_key'] = $billing_key; }
		if (!empty($shipping_key)) { $edit_args['ship_key'] = $shipping_key; }
		$edit_url = add_query_arg($edit_args, admin_url('admin.php'));

		wp_redirect($edit_url);
		exit;
	} catch (Exception $e) {
		wp_die(__('Failed to reorder: ', 'enhanced-admin-order') . $e->getMessage());
	}
}

add_action('admin_init', 'eao_handle_reorder_request');


