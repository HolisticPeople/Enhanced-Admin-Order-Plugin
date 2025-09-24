<?php
/**
 * Enhanced Admin Order - Product Functions
 *
 * @package EnhancedAdminOrder
 * @since 1.2.6
 * @version 1.5.0 - Updated version to match milestone completion of discount functionality.
 * @author Amnon Manneberg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Product related functions will go here.

// eao_get_order_items_editable_html() REMOVED as items are now rendered by JS from JSON data.

// eao_get_order_summary_html() REMOVED as summary is now rendered by JS from JSON data.

// Helper functions like eao_calculate_total_item_level_discounts and eao_calculate_products_total
// have been added directly to enhanced-admin-order-plugin.php for now.
// They can be moved here later if desired for better organization. 