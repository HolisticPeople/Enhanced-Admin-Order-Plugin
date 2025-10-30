<?php
/**
 * Plugin Name: Enhanced Admin Order
 * Description: Enhanced functionality for WooCommerce admin order editing
 * Version: 5.1.15
 * Author: Amnon Manneberg
 * Text Domain: enhanced-admin-order
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit; 
}

// Plugin version constant (v5.1.15: Fix points earning panel not refreshing on quantity changes; cache-bust)
define('EAO_PLUGIN_VERSION', '5.1.15');

/**
 * Check if we should load EAO functionality
 * Only load on specific admin pages and EAO AJAX requests
 */
function eao_should_load() {
    global $pagenow;
    
    // Never load on frontend pages
    if ( ! is_admin() ) {
        return false;
    }
    
    // If this is AJAX, only load for EAO AJAX actions
    if ( wp_doing_ajax() ) {
        $action = isset( $_REQUEST['action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) : '';
        // Load for any AJAX action that starts with "eao_" (simple and maintainable!)
        return strpos( $action, 'eao_' ) === 0;
    }
    
    // For non-AJAX admin pages, only load on:
    
    // 1. EAO custom order editor page
    if ( $pagenow === 'admin.php' && isset( $_GET['page'] ) && $_GET['page'] === 'eao_custom_order_editor_page' ) {
        return true;
    }
    
    // 2. EAO create new order action (from dashboard or elsewhere)
    if ( $pagenow === 'admin.php' && isset( $_GET['action'] ) && $_GET['action'] === 'eao_create_new_order' ) {
        return true;
    }
    
    // 3. WooCommerce orders list page (HPOS)
    if ( $pagenow === 'admin.php' && isset( $_GET['page'] ) && $_GET['page'] === 'wc-orders' ) {
        return true;
    }
    
    // 4. WooCommerce orders list page (Legacy)
    if ( $pagenow === 'edit.php' && isset( $_GET['post_type'] ) && $_GET['post_type'] === 'shop_order' ) {
        return true;
    }
    
    // Don't load on any other admin pages (dashboard, posts, plugins, etc.)
    return false;
}

// Admin-only AJAX handlers - register in admin context (handlers have their own permission checks)
if ( is_admin() ) {
    // AJAX: Adjust customer points for this order by a delta (+/-)
    add_action('wp_ajax_eao_adjust_points_for_order', function() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'eao_editor_nonce')) {
        wp_send_json_error(array('message' => 'Invalid nonce'));
        return;
    }
    $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    $delta    = isset($_POST['points_delta']) ? intval($_POST['points_delta']) : 0;
    if (!$order_id || 0 === $delta) {
        wp_send_json_error(array('message' => 'Missing parameters'));
        return;
    }
    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(array('message' => 'Order not found'));
        return;
    }
    $customer_id = $order->get_customer_id();
    if (!$customer_id) {
        wp_send_json_error(array('message' => 'No customer on order'));
        return;
    }

    $result = array('success' => false);
    try {
        if (function_exists('ywpar_get_customer')) {
            $cust = ywpar_get_customer($customer_id);
            if ($cust && method_exists($cust, 'update_points')) {
                $ok = $cust->update_points($delta, 'admin_adjustment', array(
                    'order_id' => $order_id,
                    'description' => sprintf('Admin adjusted %d points for Order #%d', $delta, $order_id)
                ));
                if ($ok) {
                    // Update our meta trail
                    $granted_pts = intval($order->get_meta('_eao_points_granted_points', true));
                    $revoked_pts = intval($order->get_meta('_eao_points_revoked_points', true));
                    if ($delta > 0) {
                        $order->update_meta_data('_eao_points_granted', 1);
                        $order->update_meta_data('_eao_points_granted_points', $granted_pts + $delta);
                    } else {
                        $order->update_meta_data('_eao_points_revoked', 1);
                        $order->update_meta_data('_eao_points_revoked_points', $revoked_pts + abs($delta));
                    }
                    $order->add_order_note(sprintf(__('EAO: Admin modified points by %d for this order.', 'enhanced-admin-order'), $delta));
                    $order->save();
                    $result['success'] = true;
                    $result['granted_points'] = intval($order->get_meta('_eao_points_granted_points', true));
                    $result['revoked_points'] = intval($order->get_meta('_eao_points_revoked_points', true));
                }
            }
        }
    } catch (Exception $e) {
        wp_send_json_error(array('message' => $e->getMessage()));
        return;
    }

    if ($result['success']) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error(array('message' => 'Unable to adjust points'));
    }
});

    // AST status fetch API (safe; no external calls, just WordPress AJAX endpoint)
    add_action('wp_ajax_eao_get_shipment_statuses', function(){
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Unauthorized'), 403);
    }
    $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    if (!$order_id) {
        wp_send_json_error(array('message' => 'Missing order_id'), 400);
    }
    $statuses = array();
    try {
        if (function_exists('ast_pro') && is_object(ast_pro()->ast_pro_actions) && method_exists(ast_pro()->ast_pro_actions, 'get_tracking_items')) {
            $tracking_items = ast_pro()->ast_pro_actions->get_tracking_items($order_id, true);
            if (is_array($tracking_items)) {
                foreach ($tracking_items as $ti) {
                    $tn = isset($ti['tracking_number']) ? (string)$ti['tracking_number'] : '';
                    $st = '';
                    foreach (array('status','tracking_status','ts_status','formatted_tracking_status') as $k) {
                        if (!$st && isset($ti[$k]) && $ti[$k] !== '') { $st = (string)$ti[$k]; }
                    }
                    if ($tn !== '') { $statuses[$tn] = $st; }
                }
            }
        }
    } catch (Exception $e) {}
    wp_send_json_success(array('statuses' => $statuses));
    });
} // End admin-only AJAX handlers

// Payment Mockup toggle: set to true in development only
if (!defined('EAO_PAYMENT_MOCKUP_ENABLED')) {
    define('EAO_PAYMENT_MOCKUP_ENABLED', true);
}

// Declare HPOS compatibility
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

// Define plugin constants
define( 'EAO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EAO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * REFACTORING STATUS - Version 1.5.6
 * =============================================================================
 * 
 * CURRENT STATE: Modular refactoring in progress - STEP 8: SAVE FUNCTION MODULARIZATION PHASE 2
 * 
 * COMPLETED:
 * âœ… Step 1: Preparation (v1.5.1)
 * âœ… Step 2: Order Calculation Utilities (v1.5.2) â†’ eao-utility-functions.php
 * âœ… Step 3: ShipStation Utilities (v1.5.3) â†’ eao-shipstation-utils.php
 * âœ… Step 3.5: Complete AJAX Audit (v1.5.4) - All wp_send_json converted to manual JSON
 * âœ… Step 4: Customer Management Functions (v1.5.5) â†’ eao-customer-functions.php
 * âœ… Step 5: Product Management Functions (v1.5.6) â†’ eao-product-management.php
 * âœ… Step 6: Custom Notes System (v1.9.1) â†’ eao-custom-notes.php
 * âœ… Step 7: ShipStation Core Functions (v1.8.13) â†’ eao-shipstation-core.php
 * âœ… Step 8 Phase 1: Notes Processor Extraction (v1.9.9) â†’ eao-ajax-save-core.php
 * âœ… Step 8 Phase 2: Items Processor Extraction (v1.9.10) â†’ eao-ajax-save-core.php
 * 
 * ACHIEVEMENT: MAJOR SAVE FUNCTION MODULE EXTRACTED! (~180 lines reduced)
 * 
 * NEXT STEPS:
 * - Step 8 Phase 3: Extract shipping processor (~120 lines)
 * - Step 8 Phase 4: Extract address processor (~80 lines)
 * - Step 8 Phase 5: Extract basics processor (~40 lines)
 * - Step 8 Phase 6: Create main save coordinator (~15 lines)
 * 
 * =============================================================================
 */

// Admin-only module loading - prevent frontend overhead
if ( eao_should_load() ) {
    // Include order calculation utilities (Step 2: v1.5.2)
    require_once EAO_PLUGIN_DIR . 'eao-utility-functions.php';

    // Include ShipStation utility functions (Step 3: v1.5.3)
    require_once EAO_PLUGIN_DIR . 'eao-shipstation-utils.php';

    // Include customer management functions (Step 4: v1.5.5)
    require_once EAO_PLUGIN_DIR . 'eao-customer-functions.php';

    // Include product management functions (Step 5: v1.5.6)
    require_once EAO_PLUGIN_DIR . 'eao-product-management.php';

    // Include custom notes system (Step 6: v1.9.1)
    require_once EAO_PLUGIN_DIR . 'eao-custom-notes.php';

    // Include AJAX save core functions (Step 8: v1.9.9)
    require_once EAO_PLUGIN_DIR . 'eao-ajax-save-core.php';

    // Include ShipStation core functionality
    require_once EAO_PLUGIN_DIR . 'eao-shipstation-core.php';

    // Include Reorder utilities (v3.1.3)
    if ( file_exists( EAO_PLUGIN_DIR . 'eao-reorder.php' ) ) {
        require_once EAO_PLUGIN_DIR . 'eao-reorder.php';
    }

    // Include YITH Points integration core (Step 9: v2.2.0)
    if ( file_exists( EAO_PLUGIN_DIR . 'eao-yith-points-core.php' ) ) {
        require_once EAO_PLUGIN_DIR . 'eao-yith-points-core.php';
    }

    // Include YITH Points save module (Step 10: v2.2.1)
    if ( file_exists( EAO_PLUGIN_DIR . 'eao-yith-points-save.php' ) ) {
        require_once EAO_PLUGIN_DIR . 'eao-yith-points-save.php';
    }

    // Include Admin Columns integration (v2.5.27)
    if ( file_exists( EAO_PLUGIN_DIR . 'eao-admin-columns-integration.php' ) ) {
        require_once EAO_PLUGIN_DIR . 'eao-admin-columns-integration.php';
    }

    // Include Fluent Support integration (v2.6.0)
    if ( file_exists( EAO_PLUGIN_DIR . 'eao-fluent-support-integration.php' ) ) {
        require_once EAO_PLUGIN_DIR . 'eao-fluent-support-integration.php';
    }

    // Include new Payment Processing (real)
    if ( file_exists( EAO_PLUGIN_DIR . 'eao-payment.php' ) ) {
        require_once EAO_PLUGIN_DIR . 'eao-payment.php';
        add_action('add_meta_boxes', 'eao_add_payment_processing_metabox', 40);
    }
} // End admin-only module loading

/**
 * Enable enhanced order editing by hijacking the default order screen.
 * Adds custom javascript and CSS to transform the interface.
 *
 * @since 1.0.0
 */
add_action( 'admin_init', 'eao_check_order_screen_and_enhance' );

function eao_check_order_screen_and_enhance() {
    global $pagenow;
    
    // First check if we're on admin pages that process orders
    if ( ! is_admin() ) {
        return;
    }

    // Check if we're on the target order editing page with the order_id parameter
    if ( $pagenow === 'admin.php' && isset( $_GET['page'] ) && $_GET['page'] === 'eao_custom_order_editor_page' && isset( $_GET['order_id'] ) ) {
        // This is our custom order editing page, enable the enhancements
        add_action( 'admin_enqueue_scripts', 'eao_enqueue_admin_assets' );
        
        // Only remove specific conflicting metaboxes, not all WooCommerce functionality
        add_action( 'add_meta_boxes', 'eao_remove_woocommerce_order_metaboxes', 35 );
    }
}

/**
 * Enqueue admin assets for enhanced order editing.
 *
 * @since 1.0.0
 */
function eao_enqueue_admin_assets($hook_suffix) {
    $plugin_name = 'enhanced-admin-order';
    
    // Check if asset files exist, otherwise skip versioning to prevent errors
    $css_file = EAO_PLUGIN_DIR . 'admin-styles.css';
    $js_file = EAO_PLUGIN_DIR . 'admin-script.js';
    
    $style_version = file_exists($css_file) ? EAO_PLUGIN_VERSION . '-' . filemtime($css_file) : EAO_PLUGIN_VERSION;
    $script_version = file_exists($js_file) ? EAO_PLUGIN_VERSION . '-' . filemtime($js_file) : EAO_PLUGIN_VERSION;

    // Styles for WC orders list and our editor page
    $is_shop_order_list = ($hook_suffix === 'edit.php' && isset($_GET['post_type']) && $_GET['post_type'] === 'shop_order');
    $is_hpos_order_list = ($hook_suffix === 'woocommerce_page_wc-orders');
    
    $current_screen = get_current_screen();
    $is_eao_editor_page = ( $current_screen && ($current_screen->id === 'toplevel_page_eao_custom_order_editor_page' || $current_screen->id === 'admin_page_eao_custom_order_editor_page') );

    if ( $is_shop_order_list || $is_hpos_order_list || $is_eao_editor_page ) {
         if (file_exists($css_file)) {
         wp_enqueue_style( $plugin_name, EAO_PLUGIN_URL . 'admin-styles.css', array(), $style_version, 'all' );
         }
    }

    // Scripts only for our editor page
    if ( $is_eao_editor_page ) {
        // Ensure core WordPress scripts are loaded first
        wp_enqueue_script( 'jquery' ); // Force jQuery to load first
        wp_enqueue_script( 'wc-admin-meta-boxes' ); 
        wp_enqueue_script( 'postbox' ); 
        
        // CRITICAL: Global initialization script - MUST load first to prevent undefined arrays
        wp_enqueue_script('eao-global-init', plugin_dir_url(__FILE__) . 'eao-global-init.js', array('jquery'), EAO_PLUGIN_VERSION, true);
        
        // Module files (in dependency order) - all depend on jquery and global init
        wp_enqueue_script('eao-core', plugin_dir_url(__FILE__) . 'eao-core.js', array('jquery', 'eao-global-init'), EAO_PLUGIN_VERSION, true);
        wp_enqueue_script('eao-change-detection', plugin_dir_url(__FILE__) . 'eao-change-detection.js', array('jquery', 'eao-core'), EAO_PLUGIN_VERSION, true);
        // New pure helpers (used by Tabulator and modules)
        wp_enqueue_script('eao-products-helpers', plugin_dir_url(__FILE__) . 'eao-products-helpers.js', array('jquery', 'eao-core'), EAO_PLUGIN_VERSION, true);
        wp_enqueue_script('eao-products', plugin_dir_url(__FILE__) . 'eao-products.js', array('jquery', 'eao-core'), EAO_PLUGIN_VERSION, true);
        wp_enqueue_script('eao-addresses', plugin_dir_url(__FILE__) . 'eao-addresses.js', array('jquery', 'eao-core'), EAO_PLUGIN_VERSION, true);
        wp_enqueue_script('eao-customers', plugin_dir_url(__FILE__) . 'eao-customers.js', array('jquery', 'eao-core'), EAO_PLUGIN_VERSION, true);
        wp_enqueue_script('eao-order-notes', plugin_dir_url(__FILE__) . 'eao-order-notes.js', array('jquery', 'eao-core'), EAO_PLUGIN_VERSION, true);
        wp_enqueue_script('eao-yith-points', plugin_dir_url(__FILE__) . 'eao-yith-points.js', array('jquery', 'eao-core'), EAO_PLUGIN_VERSION, true);
        wp_enqueue_script('eao-fluent-support', plugin_dir_url(__FILE__) . 'eao-fluent-support.js', 
            array('jquery', 'eao-core'), EAO_PLUGIN_VERSION, true);
        wp_enqueue_script('eao-fluent-crm', plugin_dir_url(__FILE__) . 'eao-fluent-crm.js', 
            array('jquery', 'eao-core'), EAO_PLUGIN_VERSION, true);
        wp_enqueue_script('eao-form-submission', plugin_dir_url(__FILE__) . 'eao-form-submission.js', array('jquery', 'eao-core', 'eao-change-detection'), EAO_PLUGIN_VERSION, true);
        // Tabulator (CDN) + wrapper for products table
        // Tabulator 6.x for latest fixes; we disabled resizing/moving in config
        wp_enqueue_style('tabulator-css', 'https://unpkg.com/tabulator-tables@6.3.0/dist/css/tabulator.min.css', array(), '6.3.0');
        wp_enqueue_script('tabulator-js', 'https://unpkg.com/tabulator-tables@6.3.0/dist/js/tabulator.min.js', array(), '6.3.0', true);
        wp_enqueue_script('eao-products-tabulator', plugin_dir_url(__FILE__) . 'eao-products-tabulator.js', array('jquery','tabulator-js'), EAO_PLUGIN_VERSION, true);
        
        // Payment Processing (real) assets
        if ( file_exists( EAO_PLUGIN_DIR . 'eao-payment.js' ) ) {
            wp_enqueue_script('eao-payment', plugin_dir_url(__FILE__) . 'eao-payment.js', array('jquery'), EAO_PLUGIN_VERSION, true);
            wp_localize_script('eao-payment', 'eao_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('eao_payment_mockup')
            ));
        }

        // Always enqueue ShipStation metabox JavaScript (unrelated to Payment Mockup)
        if ( file_exists( EAO_PLUGIN_DIR . 'eao-shipstation-metabox.js' ) ) {
            wp_enqueue_script( 'eao-shipstation-metabox', plugin_dir_url( __FILE__ ) . 'eao-shipstation-metabox.js', 
                array('jquery'), EAO_PLUGIN_VERSION, true );
            wp_localize_script('eao-shipstation-metabox', 'eaoShipStationParams', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('eao_shipstation_v2_nonce'),
                'currencySymbol' => get_woocommerce_currency_symbol(),
                'orderId' => isset($_GET['order_id']) ? absint($_GET['order_id']) : 0
            ));
        }
        
        // Ensure WP editor assets are available for Fluent Support rich editor (live envs may not auto-load)
        if ( function_exists('wp_enqueue_editor') ) { wp_enqueue_editor(); }

        // Main Coordinator Module
        wp_enqueue_script('eao-main-coordinator', plugin_dir_url(__FILE__) . 'eao-main-coordinator.js', array('jquery', 'eao-core', 'eao-change-detection', 'eao-products', 'eao-addresses', 'eao-customers', 'eao-order-notes', 'eao-yith-points', 'eao-fluent-support', 'eao-fluent-crm', 'eao-form-submission'), EAO_PLUGIN_VERSION, true);
        
        // Main coordinator script (loads after all modules)
        wp_enqueue_script('eao-admin-script', plugin_dir_url(__FILE__) . 'admin-script.js', array('jquery', 'eao-main-coordinator'), EAO_PLUGIN_VERSION, true);
        
        // Prepare addresses for JS
        $customer_addresses = array(
            'billing' => array(),
            'shipping' => array()
        );
        $customer_id_for_addresses = 0;

        $initial_billing_key_js = '';
        $initial_shipping_key_js = '';
        if (isset($_GET['order_id'])) {
            $order_id_for_js = absint($_GET['order_id']);
            $order_for_js = wc_get_order($order_id_for_js);
            if ($order_for_js) {
                $customer_id_for_addresses = $order_for_js->get_customer_id();
                if ($customer_id_for_addresses && function_exists('eao_get_customer_all_addresses')) {
                    $customer_addresses = eao_get_customer_all_addresses($customer_id_for_addresses);
                }

                // Derive initial address keys for JS (from order meta, post meta, or explicit GET fallback)
                $initial_billing_key_js = $order_for_js->get_meta('_eao_billing_address_key', true);
                if ($initial_billing_key_js === '' || $initial_billing_key_js === null) {
                    $initial_billing_key_js = get_post_meta($order_id_for_js, '_eao_billing_address_key', true);
                }
                if (( $initial_billing_key_js === '' || $initial_billing_key_js === null ) && isset($_GET['bill_key'])) {
                    $initial_billing_key_js = sanitize_text_field( wp_unslash( $_GET['bill_key'] ) );
                }

                $initial_shipping_key_js = $order_for_js->get_meta('_eao_shipping_address_key', true);
                if ($initial_shipping_key_js === '' || $initial_shipping_key_js === null) {
                    $initial_shipping_key_js = get_post_meta($order_id_for_js, '_eao_shipping_address_key', true);
                }
                if (( $initial_shipping_key_js === '' || $initial_shipping_key_js === null ) && isset($_GET['ship_key'])) {
                    $initial_shipping_key_js = sanitize_text_field( wp_unslash( $_GET['ship_key'] ) );
                }
            }
        }

        $countries = class_exists('WC_Countries') ? WC()->countries->get_countries() : array();

        // Localize scripts with AJAX data - using jquery instead of eao-admin-script to ensure it loads
        wp_localize_script( 'eao-admin-script', 'eao_ajax', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'search_products_nonce' => wp_create_nonce('eao_search_products_for_admin_order_nonce'),
            'save_order_nonce' => wp_create_nonce('eao_save_order_details'),
            'refresh_notes_nonce' => wp_create_nonce('eao_refresh_notes_nonce'),
            'mock_payment_nonce' => wp_create_nonce('eao_payment_mockup'),
            'mock_refund_nonce' => wp_create_nonce('eao_payment_mockup'),
        ));
        
        // Also localize ajaxurl for backward compatibility
        wp_localize_script( 'eao-admin-script', 'ajaxurl', admin_url( 'admin-ajax.php' ) );

        // Localize Fluent Support data if available
        if (class_exists('EAO_Fluent_Support_Integration')) {
            $fs_integration = EAO_Fluent_Support_Integration::get_instance();
            if ($fs_integration && $fs_integration->is_available()) {
                wp_localize_script('eao-fluent-support', 'eaoFluentSupport', array(
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('eao_fluent_support_nonce'),
                    'strings' => array(
                        'creating' => __('Creating ticket...', 'enhanced-admin-order'),
                        'success' => __('Ticket created successfully!', 'enhanced-admin-order'),
                        'error' => __('Error creating ticket. Please try again.', 'enhanced-admin-order'),
                        'loadingTickets' => __('Loading tickets...', 'enhanced-admin-order'),
                        'noTickets' => __('No tickets found for this customer.', 'enhanced-admin-order'),
                        'networkError' => __('Network error. Please check your connection.', 'enhanced-admin-order')
                    )
                ));
                                 // error_log('[EAO DEBUG] Fluent Support localization added to main script loading');
            }
        }
        
        // Localize our script - using the main coordinator script handle
                 // error_log('[EAO DEBUG] Localizing eaoEditorParams for script: eao-admin-script');
        // Resolve current order context for meta prefill
        $localized_order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $localized_order     = $localized_order_id ? wc_get_order($localized_order_id) : null;
        $override_enabled    = $localized_order ? ($localized_order->get_meta('_eao_points_grant_override_enabled', true) === 'yes') : false;
        $override_points     = $localized_order ? intval($localized_order->get_meta('_eao_points_grant_override_points', true)) : 0;
        $order_status_text   = $localized_order ? $localized_order->get_status() : '';

        wp_localize_script( 'eao-admin-script', 'eaoEditorParams', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'order_id' => $localized_order_id,
            // Provide real customer id so coordinator + integrations work immediately
            'order_customer_id' => $customer_id_for_addresses,
             'nonce' => wp_create_nonce( 'eao_editor_nonce' ), // General editor nonce
            'search_customers_nonce'    => wp_create_nonce( 'eao_search_customers_nonce' ),
            'save_order_nonce'          => wp_create_nonce( 'eao_save_order_details' ),
                'add_custom_note_nonce'     => wp_create_nonce( 'eao_add_custom_order_note_action' ),
                'customer_addresses'        => $customer_addresses,
                'initial_customer_id'       => $customer_id_for_addresses,
                'initial_billing_address_key' => $initial_billing_key_js,
                'initial_shipping_address_key' => $initial_shipping_key_js,
                'get_addresses_nonce'       => wp_create_nonce('eao_get_customer_addresses_nonce'),
                'wc_countries'              => $countries,
                                 'eao_product_operations_nonce' => wp_create_nonce('eao_product_operations_nonce'),
                'search_products_nonce'     => wp_create_nonce('eao_search_products_for_admin_order_nonce'),
                'placeholder_image_url'     => wc_placeholder_img_src(),
                'currency_symbol'           => get_woocommerce_currency_symbol(),
                'currency_pos'              => get_option('woocommerce_currency_pos'),
                'price_decimals'            => wc_get_price_decimals(),
                'price_decimal_sep'         => wc_get_price_decimal_separator(),
                'price_thousand_sep'        => wc_get_price_thousand_separator(),
                // Base URL for shipment tracking page (frontend)
                'tracking_base'             => home_url( '/ts-shipment-tracking/' ),
                // Points configuration and override initial state
                'points_dollar_rate'        => apply_filters('eao_points_dollar_rate', 10),
                'points_grant_override_enabled' => $override_enabled ? 'yes' : 'no',
                'points_grant_override_points'  => $override_points,
                'order_status'                  => $order_status_text,
                'i18n'                      => array(
                'edit'          => esc_html__( 'Edit', 'enhanced-admin-order' ),
                'cancel_edit'   => esc_html__( 'Cancel Edit', 'enhanced-admin-order' ),
                'no_address_found' => esc_html__( 'No address found', 'enhanced-admin-order' ),
                'products_header' => esc_html__( 'Products', 'enhanced-admin-order' ),
                'price_header' => esc_html__( 'Price', 'enhanced-admin-order' ),
                'exclude_gd_tooltip' => esc_html__( 'Exclude from Global Discount', 'enhanced-admin-order' ),
                'exclude_gd_header' => esc_html__( 'Excl. GD', 'enhanced-admin-order' ),
                'discount_tooltip' => esc_html__( 'Discount Percentage', 'enhanced-admin-order' ),
                'discount_header' => esc_html__( 'Discount %', 'enhanced-admin-order' ),
                'discounted_price_tooltip' => esc_html__( 'Discounted Price', 'enhanced-admin-order' ),
                'discounted_price_header' => esc_html__( 'Disc. Price', 'enhanced-admin-order' ),
                'quantity_header' => esc_html__( 'Qty', 'enhanced-admin-order' ),
                'total_header' => esc_html__( 'Total', 'enhanced-admin-order' ),
                'no_items_in_order' => esc_html__( 'There are no items in this order.', 'enhanced-admin-order' ),
                'item_total_gross' => esc_html__( 'Items Total (Gross):', 'enhanced-admin-order' ),
                'total_product_discount' => esc_html__( 'Total Product Discount:', 'enhanced-admin-order' ),
                'products_total_net' => esc_html__( 'Products Total (Net):', 'enhanced-admin-order' ),
                'tax' => esc_html__( 'Tax:', 'enhanced-admin-order' ),
                'grand_total' => esc_html__( 'Grand Total:', 'enhanced-admin-order' ),
                'was' => esc_html__( 'Was:', 'enhanced-admin-order' )
            )
        ));
    }
}

/**
 * AJAX handler for saving order details from the custom editor.
 * Modular coordinator function that delegates to specialized processing modules.
 *
 * @since 1.0.0
 * @version 2.9.31 - Restored missing save function
 */
function eao_ajax_save_order_details() {
    // Comprehensive output buffer cleanup to prevent JSON corruption
    while (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();
    
    // Suppress all notices, warnings and output that could corrupt JSON response
    $old_error_reporting = error_reporting(0);
    $old_display_errors = ini_get('display_errors');
    ini_set('display_errors', 0);
    
    // Suppress WordPress debug output during AJAX
    add_filter('wp_debug_log_max_files', '__return_zero');
    
    error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] eao_ajax_save_order_details: START');

    if ( ! isset( $_POST['eao_order_details_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['eao_order_details_nonce'] ) ), 'eao_save_order_details' ) ) {
        error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] eao_ajax_save_order_details: Nonce check FAILED');
        
        // Clean any captured output before sending error
        if (ob_get_length()) {
            ob_end_clean();
        }
        wp_send_json_error( array( 'message' => __( 'Nonce verification failed.', 'enhanced-admin-order' ) ) );
        return;
    }

    $order_id = isset( $_POST['eao_order_id'] ) ? absint( $_POST['eao_order_id'] ) : 0;

    if ( ! $order_id ) {
        error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] eao_ajax_save_order_details: Error - No Order ID provided');
        
        if (ob_get_length()) {
            ob_end_clean();
        }
        wp_send_json_error( array( 'message' => __( 'Error: No Order ID provided.', 'enhanced-admin-order' ) ) );
        return;
    }

    $order = wc_get_order( $order_id );

    if ( ! $order ) {
        error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] eao_ajax_save_order_details: Error - Could not load order with ID: ' . $order_id);
        
        if (ob_get_length()) {
            ob_end_clean();
        }
        wp_send_json_error( array( 'message' => sprintf( __( 'Error: Could not load order with ID %d.', 'enhanced-admin-order' ), $order_id ) ) );
        return;
    }

    // Initialize processing flag
    $items_processed_flag = false;

    try {
        $save_start = microtime(true);
        error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] SAVE PIPELINE: start');
        // --- Process Basic Order Details (EXTRACTED TO MODULE) ---
        error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] SAVE PIPELINE: before basics');
        $basics_result = eao_process_basic_order_details($order, $_POST);
        error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] SAVE PIPELINE: after basics');
        $global_order_discount_percent = $basics_result['global_discount_percent'];
        if ($basics_result['basics_updated']) {
            $items_processed_flag = true;
        }

        // --- Process Order Items (EXTRACTED TO MODULE) ---
        error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] SAVE PIPELINE: before items');
        $items_result = eao_process_order_items($order, $_POST);
        error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] SAVE PIPELINE: after items');
        if ($items_result['items_added_or_modified'] || $items_result['items_deleted'] || $items_result['existing_items_updated']) {
            $items_processed_flag = true;
        }

        // --- Process Pending ShipStation Rate (EXTRACTED TO MODULE) ---
        $shipping_result = eao_process_shipping_rates($order, $_POST);
        if ($shipping_result['shipping_processed']) {
            $items_processed_flag = true;
        }

        // --- Process Address Updates (EXTRACTED TO MODULE) ---
        $address_result = eao_process_address_updates($order, $_POST);
        $address_updated = $address_result['address_updated'];
        if ($address_updated) {
            $items_processed_flag = true;
        }

        // --- Process YITH Points Redemption ---
        $points_result = array( 'success' => true, 'messages' => array(), 'errors' => array() );
        if ( function_exists( 'eao_process_yith_points_redemption' ) ) {
            $status_now = method_exists($order, 'get_status') ? $order->get_status() : '';
            $posted_points = isset($_POST['eao_points_to_redeem']) ? absint($_POST['eao_points_to_redeem']) : null;
            if ( in_array( $status_now, array('processing','completed','shipped'), true ) ) {
                error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] SAVE PIPELINE: points redemption (status=processing/completed/shipped)');
                $points_result = eao_process_yith_points_redemption( $order_id, $_POST );
                if ( ! $points_result['success'] ) {
                    error_log( '[EAO YITH] Points processing failed: ' . implode( ', ', $points_result['errors'] ) );
                } else if ( ! empty( $points_result['messages'] ) ) {
                    error_log( '[EAO YITH] Points processing success: ' . implode( ', ', $points_result['messages'] ) );
                }
                // Persist a snapshot of current discount for UI rehydrate
                if ( $posted_points !== null ) {
                    $order->update_meta_data( '_eao_current_points_discount', array(
                        'points' => absint( $posted_points ),
                        'amount' => wc_format_decimal( ( $posted_points * 0.10 ), 2 ),
                    ) );
                }
            } else if ( $posted_points !== null ) {
                // Pending payment: store intent only and remove any applied YITH coupon
                $order->update_meta_data('_eao_pending_points_to_redeem', $posted_points);
                $existing_codes = $order->get_coupon_codes();
                foreach ($existing_codes as $code) {
                    if (strpos($code, 'ywpar_discount_') === 0) {
                        $order->remove_coupon($code);
                    }
                }
                error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . "] SAVE PIPELINE: stored pending points (no balance change), points=" . $posted_points);
                try { $order->calculate_totals(false); } catch (Throwable $t) { $order->calculate_totals(); }
                // Persist snapshot so the UI remembers selection across refresh
                $order->update_meta_data( '_eao_current_points_discount', array(
                    'points' => absint( $posted_points ),
                    'amount' => wc_format_decimal( ( $posted_points * 0.10 ), 2 ),
                ) );
            }
        }

        // --- Persist Points Grant Override Meta (NEW) ---
        try {
            $override_enabled = isset($_POST['eao_points_grant_override_enabled']) && ('yes' === sanitize_text_field(wp_unslash($_POST['eao_points_grant_override_enabled'])));
            $override_points  = isset($_POST['eao_points_grant_override_points']) ? intval($_POST['eao_points_grant_override_points']) : 0;
            if ($override_enabled) {
                $order->update_meta_data('_eao_points_grant_override_enabled', 'yes');
                $order->update_meta_data('_eao_points_grant_override_points', max(0, $override_points));
            } else {
                $order->update_meta_data('_eao_points_grant_override_enabled', 'no');
                $order->delete_meta_data('_eao_points_grant_override_points');
            }
        } catch ( Exception $e ) {}

        // --- Process Staged Notes (EXTRACTED TO MODULE) ---
        error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] SAVE PIPELINE: before notes');
        $notes_result = eao_process_order_notes($order, $_POST);
        error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] SAVE PIPELINE: after notes');

        // Persist expected award from UI (used to award exact line-3 value on processing)
        if (isset($_POST['eao_expected_points_award'])) {
            $order->update_meta_data('_eao_points_expected_award', absint($_POST['eao_expected_points_award']));
        }

        // Save the order (with recalculation if items were processed)
        if ($items_processed_flag) {
            error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] SAVE PIPELINE: before calculate_totals');
            $order->calculate_totals(); // Recalculate order totals
            error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] SAVE PIPELINE: after calculate_totals');
        }
        error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] SAVE PIPELINE: before order->save');
        $order_save_result = $order->save();
        error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] SAVE PIPELINE: after order->save (took ' . round((microtime(true)-$save_start)*1000) . ' ms)');
        
        // Derive YITH points redemption coupon amounts for accurate post-save display
        $existing_points_redeemed = 0;
        $existing_discount_amount = 0.0;
        try {
            $coupon_points_meta  = $order->get_meta( '_ywpar_coupon_points', true );
            $coupon_amount_meta  = $order->get_meta( '_ywpar_coupon_amount', true );
            if ( ! empty( $coupon_points_meta ) && is_numeric( $coupon_points_meta ) ) {
                $existing_points_redeemed = intval( $coupon_points_meta );
                $existing_discount_amount = ! empty( $coupon_amount_meta ) ? floatval( $coupon_amount_meta ) : ( $existing_points_redeemed * 0.10 );
            }
            if ( $existing_points_redeemed === 0 ) {
                foreach ( $order->get_items( 'coupon' ) as $coupon_item ) {
                    $code = method_exists( $coupon_item, 'get_code' ) ? $coupon_item->get_code() : '';
                    if ( $code && strpos( $code, 'ywpar_discount_' ) === 0 ) {
                        $existing_discount_amount = abs( (float) $coupon_item->get_discount() );
                        $existing_points_redeemed = intval( round( $existing_discount_amount * 10 ) );
                        break;
                    }
                }
            }
        } catch ( Exception $e ) { /* no-op */ }

        // Basic response data
        $updated_order_data = array(
            'order_id' => $order->get_id(),
            'status' => $order->get_status(),
            'customer_id' => $order->get_customer_id(),
            'order_customer_id' => $order->get_customer_id(),
            'points_discount_amount' => $existing_discount_amount,
            'points_redeemed' => $existing_points_redeemed,
            'date_created' => $order->get_date_created()->date('Y-m-d H:i:s'),
            'items_processed' => $items_processed_flag,
            'address_updated' => $address_updated,
            'global_discount_percent' => $global_order_discount_percent,
            'shipstation_rate_processed' => isset( $_POST['eao_pending_shipstation_rate'] ) && ! empty( $_POST['eao_pending_shipstation_rate'] ),
            'notes_processed' => $notes_result['notes_processed'],
            'notes_success' => $notes_result['success'],
            'points_processed' => !empty($points_result['messages']),
            'points_success' => $points_result['success'],
            'points_messages' => $points_result['messages'],
            'points_errors' => $points_result['errors'],
            'items_added_count' => $items_result['items_added_count'],
            'items_deleted_count' => $items_result['items_deleted_count'],
            'items_updated_count' => $items_result['items_updated_count'],
            'items_success' => $items_result['success'],
            'debug_staged_notes_received' => isset( $_POST['eao_staged_notes'] ) ? 'YES' : 'NO',
            'debug_staged_notes_content' => isset( $_POST['eao_staged_notes'] ) ? $_POST['eao_staged_notes'] : 'NOT_FOUND',
            'basics_updated' => $basics_result['basics_updated'],
            'customer_updated' => $basics_result['customer_updated']
        );

        // Check for any critical errors that should prevent save completion
        $critical_errors = array();
        
        if ( ! $points_result['success'] && ! empty( $points_result['errors'] ) ) {
            $critical_errors = array_merge( $critical_errors, $points_result['errors'] );
        }
        
        if ( ! $items_result['success'] && ! empty( $items_result['items_errors'] ) ) {
            $critical_errors = array_merge( $critical_errors, $items_result['items_errors'] );
        }
        
        if ( ! $notes_result['success'] && ! empty( $notes_result['notes_errors'] ) ) {
            $critical_errors = array_merge( $critical_errors, $notes_result['notes_errors'] );
        }
        
        // If there are critical errors, return them
        if ( ! empty( $critical_errors ) ) {
            error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] Critical errors during save: ' . implode( ', ', $critical_errors ));
            
            // Clean any captured output before sending error response
            if (ob_get_length()) {
                ob_end_clean();
            }
            
            // Restore error reporting
            error_reporting($old_error_reporting);
            ini_set('display_errors', $old_display_errors);
            remove_filter('wp_debug_log_max_files', '__return_zero');
            
            wp_send_json_error( array( 
                'message' => 'Order save completed with errors: ' . implode( ', ', $critical_errors ),
                'errors' => $critical_errors,
                'data' => $updated_order_data
            ) );
            return;
        }

        // Clean any captured output before sending success response
        if (ob_get_length()) {
            ob_end_clean();
        }
        
        // Restore error reporting
        error_reporting($old_error_reporting);
        ini_set('display_errors', $old_display_errors);
        remove_filter('wp_debug_log_max_files', '__return_zero');

        error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] eao_ajax_save_order_details: SUCCESS');
        wp_send_json_success( $updated_order_data );

    } catch ( Exception $e ) {
        error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] eao_ajax_save_order_details: Exception: ' . $e->getMessage());
        
        // Clean any captured output before sending error response
        if (ob_get_length()) {
            ob_end_clean();
        }
        
        // Restore error reporting
        error_reporting($old_error_reporting);
        ini_set('display_errors', $old_display_errors);
        remove_filter('wp_debug_log_max_files', '__return_zero');
        
        wp_send_json_error( array( 'message' => __( 'Error: ' . $e->getMessage(), 'enhanced-admin-order' ) ) );
    }
}

/**
 * Grant points once order becomes paid (processing/completed)
 */
function eao_points_handle_status_change( $order_id, $from_status, $to_status, $order ) {
    if (!function_exists('eao_yith_is_available') || !eao_yith_is_available()) { return; }
    $to_status = strtolower($to_status);
    if (in_array($to_status, array('processing','completed'), true)) {
        eao_points_grant_if_needed($order_id);
    }
}
// Admin-only: Only process order status changes from admin area (not frontend AJAX)
if ( eao_should_load() ) {
    add_action('woocommerce_order_status_changed', 'eao_points_handle_status_change', 10, 4);
}

// Lightweight debug helper for points operations
if (!function_exists('eao_points_debug_log')) {
    function eao_points_debug_log($message, $order_id = 0) {
        error_log('[EAO Points] ' . ($order_id ? ('#' . $order_id . ' ') : '') . $message);
    }
}

function eao_points_grant_if_needed( $order_id ) {
    $order = wc_get_order($order_id);
    if (!$order) { return; }
    // Skip if already granted and not revoked; allow re-grant after revoke
    $already_granted = (bool)$order->get_meta('_eao_points_granted', true);
    $was_revoked     = (bool)$order->get_meta('_eao_points_revoked', true);
    if ($already_granted && !$was_revoked) { return; }
    $customer_id = $order->get_customer_id();
    if (!$customer_id) { return; }

    // Decide points: override, expected award from UI, or out-of-pocket calculation (products only)
    $override_enabled = ('yes' === $order->get_meta('_eao_points_grant_override_enabled', true));
    $override_points  = intval($order->get_meta('_eao_points_grant_override_points', true));
    $points_to_grant  = 0;
    if ($override_enabled && $override_points > 0) {
        $points_to_grant = $override_points;
        eao_points_debug_log('Grant using override: ' . $points_to_grant, $order_id);
    } else {
        // Prefer exact expected award saved during last save/render
        $expected_award_meta = intval($order->get_meta('_eao_points_expected_award', true));
        if ($expected_award_meta > 0) {
            $points_to_grant = $expected_award_meta;
            eao_points_debug_log('Grant using expected_award meta: ' . $points_to_grant, $order_id);
        } else {
        // 1) Resolve earning rate (points per $) â€“ prefer YITH preview's points_per_dollar, else derive from full price
        $earn_rate = 0.0;
        if (function_exists('eao_yith_calculate_order_points_preview')) {
            $calc = eao_yith_calculate_order_points_preview($order_id);
            if (is_array($calc)) {
                if (!empty($calc['points_per_dollar'])) {
                    $earn_rate = (float) $calc['points_per_dollar'];
                } elseif (!empty($calc['total_points']) && isset($calc['earning_base_amount']) && $calc['earning_base_amount'] > 0) {
                    $earn_rate = ((float)$calc['total_points']) / (float)$calc['earning_base_amount'];
                }
            }
        }
        if ($earn_rate <= 0) { $earn_rate = 1.0; }

        // 2) Compute products total (after item discounts) and points-discount dollars
        $products_net = 0.0;
        foreach ($order->get_items() as $item_id => $item) {
            if ($item instanceof WC_Order_Item_Product) {
                $products_net += (float) $item->get_total();
            }
        }
        // Points discount amount from meta or coupon line items
        $points_discount_amount = (float) $order->get_meta('_ywpar_coupon_amount', true);
        if ($points_discount_amount <= 0) {
            $points_discount_amount = 0.0;
            foreach ($order->get_items('coupon') as $coupon_item) {
                $code = method_exists($coupon_item, 'get_code') ? $coupon_item->get_code() : '';
                if (strpos($code, 'ywpar_discount_') === 0) {
                    $points_discount_amount += abs((float)$coupon_item->get_discount());
                }
            }
        }

        // 3) Out-of-pocket amount for products and final points to grant
        // If a fresh points value was posted in the same save, prefer it (from FormSubmission)
        $posted_points = isset($_POST['eao_points_to_redeem']) ? intval($_POST['eao_points_to_redeem']) : null;
        if ($posted_points !== null && $posted_points >= 0) {
            $points_discount_amount = $posted_points / max(1.0, (float) get_option('ywpar_points_conversion_rate', 10));
            eao_points_debug_log('Grant calc using POSTed points: posted=' . $posted_points . ' â‡’ $' . number_format($points_discount_amount,2), $order_id);
        }

        $oop = max(0.0, $products_net - $points_discount_amount);
        $points_to_grant = (int) floor($oop * $earn_rate);
        eao_points_debug_log(sprintf('Computed grant: earn_rate=%s, products_net=%s, points_discount=$%s, oop=$%s => points=%d',
            number_format($earn_rate, 6), number_format($products_net, 2), number_format($points_discount_amount, 2), number_format($oop, 2), $points_to_grant), $order_id);
        }
    }

    if ($points_to_grant <= 0) { return; }

    // Prefer our YITH wrapper which respects plugin options and records YITH meta
    $granted_ok = false;
    if (function_exists('eao_yith_award_order_points')) {
        eao_points_debug_log('Calling eao_yith_award_order_points with ' . $points_to_grant . ' pts for user ' . $customer_id, $order_id);
        $award = eao_yith_award_order_points($order_id, $points_to_grant);
        $granted_ok = is_array($award) ? !empty($award['success']) : (bool)$award;
        eao_points_debug_log('eao_yith_award_order_points result: ' . var_export($award, true), $order_id);
    }
    // Fallback to direct YITH functions if wrapper not available
    if (!$granted_ok) {
        if (function_exists('ywpar_increase_points')) {
            eao_points_debug_log('Fallback ywpar_increase_points with ' . $points_to_grant . ' pts', $order_id);
            ywpar_increase_points($customer_id, $points_to_grant, sprintf(__('Granted for Order #%d (admin backend)', 'enhanced-admin-order'), $order_id), $order_id);
            $granted_ok = true;
        } elseif (function_exists('ywpar_get_customer')) {
            $cust = ywpar_get_customer($customer_id);
            if ($cust && method_exists($cust, 'update_points')) {
                eao_points_debug_log('Fallback customer->update_points with ' . $points_to_grant . ' pts', $order_id);
                $granted_ok = (bool)$cust->update_points($points_to_grant, 'order_completed', array('order_id' => $order_id, 'description' => sprintf('Points earned from Order #%d', $order_id)));
            }
        }
    }

    if ($granted_ok) {
        $order->update_meta_data('_eao_points_granted', 1);
        $order->update_meta_data('_eao_points_granted_points', intval($points_to_grant));
        $order->update_meta_data('_eao_points_granted_ts', time());
        // Clear any previous revoke marker to allow future re-grants to be controlled by state
        $order->delete_meta_data('_eao_points_revoked');
        $order->delete_meta_data('_eao_points_revoked_points');
        // Add order note trail
        $order->add_order_note(sprintf(__('EAO: Granted %d points to customer on status change.', 'enhanced-admin-order'), intval($points_to_grant)));
        $order->save();
        eao_points_debug_log('Grant recorded successfully: ' . $points_to_grant . ' pts', $order_id);
    }
}

/**
 * Revoke granted points on refund (simple proportional strategy)
 */
function eao_points_handle_refund( $order_id, $refund_id ) {
    if (!function_exists('eao_yith_is_available') || !eao_yith_is_available()) { return; }
    $order = wc_get_order($order_id);
    if (!$order) return;
    $granted = (bool)$order->get_meta('_eao_points_granted', true);
    $revoked = (bool)$order->get_meta('_eao_points_revoked', true);
    $granted_pts = intval($order->get_meta('_eao_points_granted_points', true));
    if (!$granted || $revoked || $granted_pts <= 0) { return; }

    $refund = wc_get_order($refund_id);
    if (!$refund) return;
    $refund_amount = abs($refund->get_total());
    $order_total   = max(0.01, $order->get_total());
    $proportion    = min(1.0, $refund_amount / $order_total);
    $revoke_pts    = max(1, intval(round($granted_pts * $proportion)));

    if (function_exists('ywpar_decrease_points')) {
        ywpar_decrease_points($order->get_customer_id(), $revoke_pts, sprintf(__('Refund revoke for Order #%d', 'enhanced-admin-order'), $order_id), $order_id);
    } elseif (function_exists('ywpar_increase_points')) {
        ywpar_increase_points($order->get_customer_id(), -1 * $revoke_pts, sprintf(__('Refund revoke for Order #%d', 'enhanced-admin-order'), $order_id), $order_id);
    }
    $order->update_meta_data('_eao_points_revoked', 1);
    $order->update_meta_data('_eao_points_revoked_points', $revoke_pts);
    // Trail
    $order->add_order_note(sprintf(__('EAO: Revoked %d points due to refund.', 'enhanced-admin-order'), $revoke_pts));
    $order->save();
}
// Admin-only: Only process refunds from admin area (not frontend AJAX)
if ( eao_should_load() ) {
    add_action('woocommerce_order_refunded', 'eao_points_handle_refund', 10, 2);
}


/**
 * Add the custom admin page.
 * This page will be hidden from the main menu but accessible via direct URL or button click.
 *
 * @since 1.0.0
 * @modified 1.1.0 - Changed menu slug for uniqueness.
 * @modified 1.1.1 - Changed capability to 'manage_options' for debugging.
 */
add_action( 'admin_menu', 'eao_add_admin_page' );
function eao_add_admin_page() {
    add_menu_page(
        __( 'Enhanced Order Editor', 'enhanced-admin-order' ),
        __( 'Enhanced Order Editor', 'enhanced-admin-order' ),
        'manage_options',
        'eao_custom_order_editor_page',
        'eao_display_admin_page',
        null,
        null
    );
}

/**
 * Display the content of the custom admin page.
 *
 * @since 1.0.0
 * @modified 1.0.9 - Temporarily commented out capability check for debugging access issue.
 * @modified 1.1.0 - Added immediate die() for testing if function is called.
 * @modified 1.1.1 - Ensured log messages use current version.
 * @modified 1.1.2 - Removed die() and reinstated capability check with 'manage_options'.
 * @modified 1.1.3 - Load order editor template.
 */
function eao_display_admin_page() {
    error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] eao_display_admin_page called. Current user ID: ' . get_current_user_id());
    
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'You do not have sufficient permissions to access this page.', 'enhanced-admin-order' ) );
    }

    $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
    if ( ! $order_id ) {
        // If no order is specified, redirect to WooCommerce Orders list
        $target = admin_url('admin.php?page=wc-orders');
        if (!headers_sent()) {
            wp_safe_redirect($target);
            exit;
        }
        echo '<script>window.location.href=' . json_encode($target) . ';</script>';
        return;
    }

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        wp_die( sprintf( __( 'Error: Could not load order with ID %d.', 'enhanced-admin-order' ), $order_id ) );
        return;
    }

    // Set up the screen for meta boxes with WooCommerce-compatible screen context
    $screen = get_current_screen();
    if ( ! $screen ) {
        global $hook_suffix;
        if ($hook_suffix) {
            set_current_screen($hook_suffix);
            $screen = get_current_screen(); 
        }
    }

    if (!$screen) {
        wp_die( __( 'Error: Could not get current screen object after attempting to set it.', 'enhanced-admin-order' ) );
        return;
    }

    // Ensure FluentCRM recognizes this as a proper order context
    global $post, $pagenow, $typenow;
    $original_post = $post;
    $original_pagenow = $pagenow;
    $original_typenow = $typenow;
    
    // Set up environment to mimic WooCommerce order edit screen
    $pagenow = 'post.php';
    $typenow = 'shop_order';
    
    // HPOS compatibility: Get post object if available, otherwise create mock post object
    $hpos_active = false;
    if ( class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && method_exists('Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled') ) {
        $hpos_active = Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }
    
    if ( ! $hpos_active ) {
        // Traditional posts table - use the actual post
        $order_post = get_post( $order->get_id() );
        if ( $order_post ) {
            $post = $order_post;
            setup_postdata( $post );
        }
    } else {
        // HPOS active - create a mock post object for compatibility
        $post = (object) array(
            'ID' => $order->get_id(),
            'post_type' => 'shop_order',
            'post_status' => $order->get_status(),
            'post_title' => sprintf( __( 'Order #%s', 'enhanced-admin-order' ), $order->get_order_number() ),
            'post_date' => $order->get_date_created()->date('Y-m-d H:i:s'),
        );
    }

    $screen_id = $screen->id;
    error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] Current screen ID in eao_display_admin_page: ' . $screen_id . ', HPOS active: ' . ($hpos_active ? 'yes' : 'no'));

    // Add FluentCRM meta box first (right below order actions, above shipping rates)
    error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] BEFORE direct call to eao_add_fluentcrm_profile_meta_box_direct.');
    if (function_exists('eao_add_fluentcrm_profile_meta_box_direct')) {
        eao_add_fluentcrm_profile_meta_box_direct($order);
    }
    error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] AFTER direct call to eao_add_fluentcrm_profile_meta_box_direct.');

    // Add shipping rates meta box (after FluentCRM)
    error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] BEFORE direct call to eao_add_shipstation_api_rates_meta_box.');
    if (function_exists('eao_add_shipstation_api_rates_meta_box')) {
        eao_add_shipstation_api_rates_meta_box($order);
    }
    error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] AFTER direct call to eao_add_shipstation_api_rates_meta_box.');

    // (Payment mockup moved below Support metabox)

    // Add Fluent Support meta box (after payment mockup, before custom notes)
    error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] BEFORE direct call to add Fluent Support metabox.');
    if (class_exists('EAO_Fluent_Support_Integration')) {
        error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] EAO_Fluent_Support_Integration class exists.');
        $fs_integration = EAO_Fluent_Support_Integration::get_instance();
        error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] FS Integration instance: ' . ($fs_integration ? 'EXISTS' : 'NULL'));
        if ($fs_integration && $fs_integration->is_available()) {
            error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] FS Integration is available. Calling add_fluent_support_metabox().');
            $fs_integration->add_fluent_support_metabox();
        } else {
            error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] FS Integration NOT available or NULL.');
        }
    } else {
        error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] EAO_Fluent_Support_Integration class does NOT exist.');
    }
    error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] AFTER direct call to add Fluent Support metabox.');

    // Add AST/TrackShip Shipment Tracking meta box into the EAO sidebar (right pane)
    try {
        if ( function_exists('ast_pro') && is_object( ast_pro()->ast_pro_actions ) && method_exists( ast_pro()->ast_pro_actions, 'meta_box' ) ) {
            add_meta_box(
                'woocommerce-advanced-shipment-tracking',
                __('Shipment Tracking','enhanced-admin-order'),
                function() use ($order) {
                    // Reuse AST Pro renderer for consistency
                    ast_pro()->ast_pro_actions->meta_box( $order );
                },
                $screen_id,
                'side',
                'low'
            );
            error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] AST Pro meta box added to EAO sidebar.');
        } elseif ( function_exists('trackship_for_woocommerce') && is_object( trackship_for_woocommerce()->admin ) && method_exists( trackship_for_woocommerce()->admin, 'trackship_metabox_cb' ) ) {
            add_meta_box(
                'trackship',
                'TrackShip',
                function() use ($order) {
                    trackship_for_woocommerce()->admin->trackship_metabox_cb( $order );
                },
                $screen_id,
                'side',
                'low'
            );
            error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] TrackShip meta box added to EAO sidebar.');
        } else {
            error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] Neither AST Pro nor TrackShip meta box callback available.');
        }
    } catch ( \Throwable $e ) {
        error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] Error adding AST/TrackShip meta box: ' . $e->getMessage());
    }

    // Add PDF (Invoices/Packing Slips) meta box above Order Notes if plugin is active
    if ( class_exists('WPO\\IPS\\Admin') && function_exists('wcpdf_get_document') ) {
        add_meta_box(
            'eao_wcpdf_actions',
            __('Create PDF','woocommerce-pdf-invoices-packing-slips'),
            function() use ($order) {
                // Reuse the plugin's renderer for consistency
                try {
                    $admin = \WPO\IPS\Admin::instance();
                    if ( $admin && method_exists($admin,'pdf_actions_meta_box') ) {
                        $admin->pdf_actions_meta_box($order);
                    } else {
                        echo '<p>' . esc_html__('PDF plugin not fully available.', 'enhanced-admin-order') . '</p>';
                    }
                } catch ( \Throwable $e ) {
                    echo '<p>' . esc_html__('PDF actions unavailable.', 'enhanced-admin-order') . '</p>';
                }
            },
            $screen_id,
            'side',
            'default'
        );
    }

    // Add new Payment Processing metabox (real) after Fluent Support
    if (function_exists('eao_render_payment_processing_metabox')) {
        add_meta_box(
            'eao-payment-processing-metabox',
            __('Payment Processing', 'enhanced-admin-order'),
            'eao_render_payment_processing_metabox',
            $screen_id,
            'normal',
            'low',
            array('order' => $order)
        );
    }

    // Add custom notes meta box
    error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] BEFORE direct call to eao_add_custom_order_notes_meta_box.');
    if (function_exists('eao_add_custom_order_notes_meta_box')) {
        eao_add_custom_order_notes_meta_box($order);
    }
    error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] AFTER direct call to eao_add_custom_order_notes_meta_box.');

    // Add order products meta box
    error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] BEFORE direct call to eao_add_order_products_meta_box.');
    if (function_exists('eao_add_order_products_meta_box')) {
        eao_add_order_products_meta_box($order);
    }
    error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] AFTER direct call to eao_add_order_products_meta_box.');

    // Fire the generic 'add_meta_boxes' hook for other plugins
    error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] BEFORE do_action for generic add_meta_boxes.');
    do_action( 'add_meta_boxes', $screen_id, $order );
    error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] AFTER do_action for generic add_meta_boxes.');

    // Load the template which will call do_meta_boxes()
    $template_path = EAO_PLUGIN_DIR . 'eao-order-editor-page-template.php'; // Fixed PHP compatibility issues
    error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] Template path: ' . $template_path . ', exists: ' . (file_exists($template_path) ? 'yes' : 'no'));
    
    if ( file_exists( $template_path ) ) {
        error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] Loading template file...');
        ob_start();
        include $template_path;
        $template_output = ob_get_contents();
        ob_end_flush();
        error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] Template output length: ' . strlen($template_output));
        if (empty($template_output)) {
            error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] Template produced no output! Falling back to simple template.');
            // Simple fallback template
            echo '<div class="wrap"><h1>Enhanced Order Editor - Debug Mode</h1>';
            echo '<p>Order ID: ' . esc_html($order->get_id()) . '</p>';
            echo '<p>Order Status: ' . esc_html($order->get_status()) . '</p>';
            echo '</div>';
        }
    } else {
        // Create a basic fallback template inline (non-sortable to match baseline)
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Enhanced Order Editor', 'enhanced-admin-order' ); ?></h1>
            <div id="poststuff">
                <div id="post-body" class="metabox-holder columns-2">
                    <div id="post-body-content">
                        <div class="meta-box-container">
                            <?php do_meta_boxes( $screen_id, 'normal', $order ); ?>
                        </div>
                    </div>
                    <div id="postbox-container-1" class="postbox-container">
                        <div class="meta-box-container">
                            <?php do_meta_boxes( $screen_id, 'side', $order ); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    // Restore original environment
    $post = $original_post;
    $pagenow = $original_pagenow;  
    $typenow = $original_typenow;
    
    if ( $original_post ) {
        setup_postdata( $original_post );
    } else {
        wp_reset_postdata();
    }
    
    error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] Environment restored after template load.');
}

/**
 * Add ShipStation API Rates meta box.
 *
// eao_add_shipstation_api_rates_meta_box() function moved to eao-shipstation-core.php

// eao_get_shipstation_api_credentials() function moved to eao-shipstation-core.php

// eao_render_shipstation_api_rates_meta_box_content() function moved to eao-shipstation-core.php

// Orphaned meta box content removed - function is now in eao-shipstation-core.php

/**
 * Add Custom Order Notes meta box.
    $api_key_present = !empty($credentials['api_key']);
    $api_secret_present = !empty($credentials['api_secret']);
    $order_id = $order->get_id();
    $nonce = wp_create_nonce('eao_shipstation_v2_nonce');

    if ( ! $order->has_shipping_address() && $order->needs_shipping_address()) {
        echo '<p>' . esc_html__( 'No shipping address found. Please add a shipping address to the order.', 'enhanced-admin-order' ) . '</p>';
        return;
    }

    $currency_symbol = get_woocommerce_currency_symbol();
    ?>
    <div class="eao-meta-box-content">

        <!-- Section 1: Enter Custom Shipping Rate -->
        <div class="eao-custom-rate-section" style="padding-bottom:15px;">
            <h4><?php esc_html_e( 'Enter Custom Shipping Rate', 'enhanced-admin-order' ); ?></h4>
            <div style="display: flex; align-items: center; gap: 10px;">
                <span style="font-size: 1.2em;"><?php echo $currency_symbol; ?></span>
                <input type="number" id="eao_custom_shipping_rate_amount" name="eao_custom_shipping_rate_amount" 
                       value="0.00" 
                       step="0.1" class="short wc_input_price" style="width:100px;" placeholder="0.00" />
                
                <button type="button" class="button eao-apply-custom-shipping-rate">
                    <?php esc_html_e( 'Apply Custom Rate', 'enhanced-admin-order' ); ?>
                </button>
                </div>
            </div>

        <!-- Section 2: Get Live ShipStation Rates -->
        <div class="eao-shipstation-live-rates-section" style="margin-top: 20px;">
            <h4><?php esc_html_e( 'Get Live ShipStation Rates', 'enhanced-admin-order' ); ?></h4>
            
            <?php if ( !$api_key_present || !$api_secret_present ) : ?>
                <div style="margin-bottom: 10px; color: #d63638;">
                    <p><strong><?php esc_html_e( 'API credentials missing!', 'enhanced-admin-order' ); ?></strong></p>
                    <p><?php esc_html_e( 'Please add ShipStation API credentials to proceed.', 'enhanced-admin-order' ); ?></p>
                </div>
                
                <div style="margin-bottom: 10px;">
                    <label for="eao_shipstation_api_key" style="display:block; margin-bottom: 5px;"><?php esc_html_e( 'API Key:', 'enhanced-admin-order' ); ?></label>
            <input type="text" id="eao_shipstation_api_key" style="width: 100%; margin-bottom: 5px;">
                    <label for="eao_shipstation_api_secret" style="display:block; margin-bottom: 5px;"><?php esc_html_e( 'API Secret:', 'enhanced-admin-order' ); ?></label>
            <input type="password" id="eao_shipstation_api_secret" style="width: 100%; margin-bottom: 5px;">
            </div>

                <button type="button" class="button eao-shipstation-connect" data-nonce="<?php echo esc_attr( $nonce ); ?>">
                    <?php esc_html_e( 'Connect to ShipStation', 'enhanced-admin-order' ); ?>
                </button>
            <?php else : ?>
                <div id="eao-shipstation-connection-status" style="margin-bottom: 10px;">
                    <p>
                        <strong><?php esc_html_e( 'Status:', 'enhanced-admin-order' ); ?></strong> 
                        <span id="eao-connection-status-text" style="font-weight: bold;"><?php esc_html_e( 'Not verified', 'enhanced-admin-order' ); ?></span>
                         <button type="button" class="button eao-shipstation-test-connection" style="margin-left: 10px;"><?php esc_html_e( 'Test Connection', 'enhanced-admin-order' ); ?></button>
                    </p>
            </div>
                
                <div id="eao-shipstation-connection-debug" style="display:none; margin-bottom:10px; font-size:12px; background:#f8f8f8; border:1px solid #eee; padding:8px; border-radius:4px; color:#333;"></div>
                
                <div>
                     <button type="button" class="button button-primary eao-shipstation-get-rates-action" data-order-id="<?php echo esc_attr( $order_id ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
                        <?php esc_html_e( 'Get Shipping Rates from ShipStation', 'enhanced-admin-order' ); ?>
                    </button>
                    </div>
        
                <div class="eao-shipstation-rates-container" style="display: none; margin-top: 15px; border: 1px solid #ddd; border-radius: 4px;">
                    <div class="eao-shipstation-rates-loading" style="text-align: center; display: none; padding: 15px;">
                        <p><em><?php esc_html_e( 'Loading rates...', 'enhanced-admin-order' ); ?></em></p>
                </div>

            <div class="eao-shipstation-rates-list" style="max-height: 250px; overflow-y: auto;"></div>
            
                    <div class="eao-shipstation-rates-error" style="color: red; display: none; padding: 15px;"></div>
            </div>

                <!-- Adjustment section -->
                <div class="eao-shipstation-rate-adjustment-section" style="display: none; margin-top: 15px; padding:15px; background-color:#f0f5fa; border:1px solid #c9d8e4; border-radius: 4px;">
                    <h4><?php esc_html_e( 'Adjust Selected Rate', 'enhanced-admin-order' ); ?></h4>
                    <div class="eao-adjustment-options">
                        <label><input type="radio" name="eao_adjustment_type" value="no_adjustment" checked> <?php esc_html_e( 'None', 'enhanced-admin-order' ); ?></label>
                        <label style="margin-left: 10px;"><input type="radio" name="eao_adjustment_type" value="percentage_discount"> <?php esc_html_e( 'Percent', 'enhanced-admin-order' ); ?></label>
                        <label style="margin-left: 10px;"><input type="radio" name="eao_adjustment_type" value="fixed_discount"> <?php esc_html_e( 'Fixed', 'enhanced-admin-order' ); ?></label>
        </div>
        
                    <div id="eao-adjustment-input-percentage" style="display: none; margin-top: 10px;">
                        <input type="number" id="eao-adjustment-percentage-value" min="0" max="100" step="0.1" style="width: 60px;"> %
            </div>
                    <div id="eao-adjustment-input-fixed" style="display: none; margin-top: 10px;">
                        <?php echo $currency_symbol; ?><input type="number" id="eao-adjustment-fixed-value" min="0" step="0.1" style="width: 80px;">
    </div>
                    <p style="margin-top: 15px;">
                        <strong><?php esc_html_e( 'Final Rate:', 'enhanced-admin-order' ); ?></strong> 
                        <span id="eao-shipstation-final-rate-display" style="font-weight: bold;"></span>
                    </p>
        </div>

                <!-- Apply button -->
                <div class="eao-shipstation-rates-apply" style="margin-top: 15px; display: none;">
                <button type="button" class="button button-primary eao-shipstation-apply-rate"
                      data-order-id="<?php echo esc_attr( $order_id ); ?>"
                      data-nonce="<?php echo esc_attr( $nonce ); ?>">
                          <?php esc_html_e( 'Apply Selected ShipStation Rate', 'enhanced-admin-order' ); ?>
                    </button>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <script type="text/javascript">
        jQuery(document).ready(function($){
            

            window.eaoPendingShipstationRate = null;
            
            function escapeAttribute(str) {
                if (typeof str !== 'string') return '';
                return str.replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/&/g, '&amp;');
            }

            var initialShippingTotal = <?php echo (float) $order->get_shipping_total('edit'); ?>;
            var initialShippingMethod = <?php 
                $methods = $order->get_shipping_methods();
                $method_title = !empty($methods) ? reset($methods)->get_name() : 'Shipping';
                echo json_encode($method_title); 
            ?>;

            
            
            window.eaoOriginalShippingRateBeforeSessionApply = {
                amountRaw: initialShippingTotal || 0,
                serviceName: initialShippingMethod,
                method_title: initialShippingMethod,
            };

            

            var selectedRateRawAmountEAO = 0;
            var currencySymbolJS = '<?php echo $currency_symbol; ?>';
            var priceDecimalsJS = <?php echo wc_get_price_decimals(); ?>;

            function eaoFormatPrice(price) {
                const numPrice = parseFloat(price);
                if (isNaN(numPrice)) return '';
                return currencySymbolJS + numPrice.toFixed(priceDecimalsJS);
            }

            function eaoFormatRateForDisplay(serviceName) {
                if (typeof serviceName !== 'string') return 'N/A';
                serviceName = serviceName.replace('Â®', '').replace('â„¢', '');
                if (serviceName.toLowerCase().endsWith(' - package')) {
                    serviceName = serviceName.substring(0, serviceName.length - ' - package'.length);
                }
                return serviceName.trim();
            }

            function calculateAdjustedRate() {
                var adjustmentType = $('input[name="eao_adjustment_type"]:checked').val();
                var baseAmount = selectedRateRawAmountEAO;
                var finalAmount = baseAmount;
                var adjustmentValue = 0;

                if (adjustmentType === 'percentage_discount') {
                    adjustmentValue = parseFloat($('#eao-adjustment-percentage-value').val()) || 0;
                    finalAmount = baseAmount * (1 - (Math.max(0, Math.min(100, adjustmentValue)) / 100));
                } else if (adjustmentType === 'fixed_discount') {
                    adjustmentValue = parseFloat($('#eao-adjustment-fixed-value').val()) || 0;
                    finalAmount = baseAmount - Math.max(0, adjustmentValue);
                }

                finalAmount = Math.max(0, finalAmount);
                $('#eao-shipstation-final-rate-display').html(eaoFormatPrice(finalAmount));

                return {
                    final: finalAmount,
                    type: adjustmentType,
                    value: adjustmentValue
                };
            }
            
            $('input[name="eao_adjustment_type"]').on('change', function() {
                var type = $(this).val();
                if (type === 'percentage_discount') {
                    $('#eao-adjustment-input-percentage').show();
                    $('#eao-adjustment-input-fixed').hide();
                } else if (type === 'fixed_discount') {
                    $('#eao-adjustment-input-percentage').hide();
                    $('#eao-adjustment-input-fixed').show();
                    } else {
                    $('#eao-adjustment-input-percentage').hide();
                    $('#eao-adjustment-input-fixed').hide();
                }
                calculateAdjustedRate();
            });

            $('#eao-adjustment-percentage-value, #eao-adjustment-fixed-value').on('input', function() {
                calculateAdjustedRate();
            });

            $('.eao-shipstation-connect').on('click', function(){
                var $button = $(this);
                var nonce = $button.data('nonce');
                var apiKey = $('#eao_shipstation_api_key').val().trim();
                var apiSecret = $('#eao_shipstation_api_secret').val().trim();

                if (!apiKey || !apiSecret) {
                    alert('<?php echo esc_js(__( 'Please enter both API Key and Secret.', 'enhanced-admin-order' )); ?>');
                    return;
                }

                $button.prop('disabled', true).text('<?php echo esc_js(__( 'Connecting...', 'enhanced-admin-order' )); ?>');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    dataType: 'json',
                    data: { action: 'eao_shipstation_v2_save_credentials', api_key: apiKey, api_secret: apiSecret, eao_nonce: nonce },
                    success: function(response) {
                        if (response.success) {
                            alert('<?php echo esc_js(__( 'Connected successfully! Page will reload.', 'enhanced-admin-order' )); ?>');
                            location.reload();
                        } else {
                            var errorMsg = response.data && response.data.message ? response.data.message : '<?php echo esc_js(__( 'Connection failed.', 'enhanced-admin-order' )); ?>';
                            alert(errorMsg);
                        }
                    },
                    error: function() {
                        alert('<?php echo esc_js(__( 'Error connecting to server.', 'enhanced-admin-order' )); ?>');
                    },
                    complete: function() {
                        $button.prop('disabled', false).text('<?php echo esc_js(__( 'Connect to ShipStation', 'enhanced-admin-order' )); ?>');
                    }
                });
            });

            $('.eao-shipstation-test-connection').on('click', function(){
                var $button = $(this);
                var $statusText = $('#eao-connection-status-text');
                var $debugBox = $('#eao-shipstation-connection-debug');

                $button.prop('disabled', true).text('<?php echo esc_js(__( 'Testing...', 'enhanced-admin-order' )); ?>');
                $statusText.text('<?php echo esc_js(__( 'Testing...', 'enhanced-admin-order' )); ?>').css('color', '#999');
                $debugBox.hide().empty();

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    dataType: 'json',
                    data: { action: 'eao_shipstation_v2_test_connection', eao_nonce: '<?php echo esc_js($nonce); ?>' },
                    success: function(response) {
                        if (response.success) {
                            $statusText.text('<?php echo esc_js(__( 'Connected', 'enhanced-admin-order' )); ?>').css('color', '#00a32a');
        } else {
                            $statusText.text('<?php echo esc_js(__( 'Failed', 'enhanced-admin-order' )); ?>').css('color', '#d63638');
                            var errorMsg = response.data && response.data.message ? response.data.message : '<?php echo esc_js(__( 'An unknown error occurred.', 'enhanced-admin-order' )); ?>';
                            $debugBox.html('<p style="color:red;">' + errorMsg + '</p>').show();
                        }
                    },
                    error: function() {
                        $statusText.text('<?php echo esc_js(__( 'Error', 'enhanced-admin-order' )); ?>').css('color', '#d63638');
                        $debugBox.html('<p style="color:red;"><?php echo esc_js(__( 'Server communication error.', 'enhanced-admin-order' )); ?></p>').show();
                    },
                    complete: function() {
                            $button.prop('disabled', false).text('<?php echo esc_js(__( 'Test Connection', 'enhanced-admin-order' )); ?>');
                    }
                });
            });

            $('.eao-apply-custom-shipping-rate').on('click', function(){
                var customAmountRaw = parseFloat($('#eao_custom_shipping_rate_amount').val());
                
                if (isNaN(customAmountRaw) || customAmountRaw < 0) {
                    alert('<?php echo esc_js(__( 'Please enter a valid, non-negative custom rate amount.', 'enhanced-admin-order' )); ?>');
                    return;
                }

                var formattedCustomAmount = eaoFormatPrice(customAmountRaw);

                window.eaoPendingShipstationRate = {
                    is_custom_applied: true,
                    shipping_amount_raw: customAmountRaw,
                    shipping_amount_formatted: formattedCustomAmount,
                    service_name: '<?php echo esc_js(__("Custom Rate", "enhanced-admin-order")); ?>',
                    adjustedAmountRaw: customAmountRaw,
                    adjustedAmountFormatted: formattedCustomAmount,
                    originalAmountRaw: customAmountRaw,
                    originalAmountFormatted: formattedCustomAmount,
                    adjustmentType: 'none',
                    adjustmentValue: null,
                    rate_id: 'custom_' + Date.now(),
                    carrier_code: 'custom',
                    service_code: 'custom',
                    method_title: '<?php echo esc_js(__("Custom Rate", "enhanced-admin-order")); ?>'
                };
                
                
                
                $(document).trigger('eaoShippingRateApplied'); 
            });

            $(document).on('change', 'input[name="eao_shipstation_rate"]', function() {
                var $selected = $(this);
                if ($selected.is(':checked')) {
                    selectedRateRawAmountEAO = parseFloat($selected.data('amount-raw'));
                    $('.eao-shipstation-rate-adjustment-section, .eao-shipstation-rates-apply').show();
                    $('input[name="eao_adjustment_type"][value="no_adjustment"]').prop('checked', true).trigger('change');
                }
            });

            $('.eao-shipstation-get-rates-action').on('click', function(){
                var $button = $(this);
                var orderId = $button.data('order-id');
                var nonce = $button.data('nonce');
                var $ratesContainer = $('.eao-shipstation-rates-container');
                var $ratesList = $('.eao-shipstation-rates-list');
                var $ratesLoading = $('.eao-shipstation-rates-loading');
                var $ratesError = $('.eao-shipstation-rates-error');
                
                $ratesList.empty();
                $ratesError.hide().empty();
                $ratesContainer.show();
                $ratesLoading.show();
                $('.eao-shipstation-rate-adjustment-section, .eao-shipstation-rates-apply').hide();

                $button.prop('disabled', true).text('<?php echo esc_js(__( 'Loading...', 'enhanced-admin-order' )); ?>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    dataType: 'json',
                    data: { action: 'eao_shipstation_v2_get_rates', order_id: orderId, eao_nonce: nonce },
                    success: function(response) {
                        $ratesLoading.hide();
                        if (response.success && response.data && response.data.rates && response.data.rates.length > 0) {
                            var rates = response.data.rates.sort((a, b) => parseFloat(a.shipping_amount_raw) - parseFloat(b.shipping_amount_raw));
                            var ratesHtml = '';
                            $.each(rates, function(index, rate) {
                                var carrierStyle = '';
                                if (rate.carrier_code && (rate.carrier_code.includes('ups'))) carrierStyle = 'border-left: 3px solid #7b5e2e;';
                                else if (rate.carrier_code && rate.carrier_code.includes('stamps_com')) carrierStyle = 'border-left: 3px solid #004b87;';
                                
                                var displayServiceName = eaoFormatRateForDisplay(rate.service_name);

                                ratesHtml += `<div class="eao-shipstation-rate" style="padding: 8px; margin-bottom: 5px; ${carrierStyle}">
                                        <input type="radio" name="eao_shipstation_rate" id="eao_rate_${rate.rate_id}" 
                                        value="${rate.rate_id}" 
                                        data-service-name="${escapeAttribute(rate.service_name)}" 
                                        data-amount-raw="${rate.shipping_amount_raw}"
                                        data-carrier-code="${escapeAttribute(rate.carrier_code)}"
                                        data-service-code="${escapeAttribute(rate.service_code)}"
                                        ${index === 0 ? 'checked' : ''}>
                                        <label for="eao_rate_${rate.rate_id}" style="margin-left: 5px;">
                                            <strong>${rate.shipping_amount}</strong> ${displayServiceName}
                                        </label>
                                    </div>`;
                            });
                            $ratesList.html(ratesHtml);
                            if ($('input[name="eao_shipstation_rate"]:checked').length) {
                                $('input[name="eao_shipstation_rate"]:checked').trigger('change');
                            }
                } else {
                            var errorMsg = (response.data && response.data.message) ? response.data.message : '<?php echo esc_js(__( 'No rates returned.', 'enhanced-admin-order' )); ?>';
                            $ratesError.html(`<p>${errorMsg}</p>`).show();
                        }
                    },
                    error: function() {
                        $ratesLoading.hide();
                        $ratesError.html('<p><?php echo esc_js(__( 'Error connecting to server.', 'enhanced-admin-order' )); ?></p>').show();
                    },
                    complete: function() {
                        $button.prop('disabled', false).text('<?php echo esc_js(__( 'Get Shipping Rates from ShipStation', 'enhanced-admin-order' )); ?>');
                    }
                });
            });

            $('.eao-shipstation-apply-rate').on('click', function() {
                var $selectedRate = $('input[name="eao_shipstation_rate"]:checked');
                if (!$selectedRate.length) {
                    alert('<?php echo esc_js(__( 'Please select a ShipStation rate first.', 'enhanced-admin-order' )); ?>');
                    return;
                }

                var finalAdjustedData = calculateAdjustedRate();
                var serviceName = $selectedRate.data('service-name');
                var rateId = $selectedRate.val();
                var carrierCode = $selectedRate.data('carrier-code');
                var serviceCode = $selectedRate.data('service-code');

                window.eaoPendingShipstationRate = {
                    is_custom_applied: false,
                    shipping_amount_raw: selectedRateRawAmountEAO,
                    shipping_amount_formatted: eaoFormatPrice(selectedRateRawAmountEAO),
                    service_name: serviceName,
                    adjustedAmountRaw: finalAdjustedData.final,
                    adjustedAmountFormatted: eaoFormatPrice(finalAdjustedData.final),
                    originalAmountRaw: selectedRateRawAmountEAO,
                    originalAmountFormatted: eaoFormatPrice(selectedRateRawAmountEAO),
                    adjustmentType: finalAdjustedData.type,
                    adjustmentValue: finalAdjustedData.value,
                    rate_id: rateId,
                    method_title: eaoFormatRateForDisplay(serviceName),
                    carrier_code: carrierCode,
                    service_code: serviceCode
                };

                
                
                $(document).trigger('eaoShippingRateApplied');
            });

            // Auto-run connection test on page load if credentials exist
            if ($('.eao-shipstation-test-connection').length > 0) {
                 $('.eao-shipstation-test-connection').trigger('click');
            }

        });
    </script>
    <?php
}

/**
 * Add Custom Order Notes meta box.
 *
 * @param WC_Order $order The order object.
 */

// eao_ajax_shipstation_v2_save_credentials_handler() function and AJAX hook moved to eao-shipstation-core.php

// eao_ajax_shipstation_v2_test_connection_handler() function and AJAX hook moved to eao-shipstation-core.php

// eao_test_shipstation_api_credentials function moved to eao-shipstation-utils.php

// eao_ajax_shipstation_v2_get_rates_handler() function and AJAX hook moved to eao-shipstation-core.php

// eao_build_shipstation_rates_request function moved to eao-shipstation-utils.php

// eao_get_order_weight function moved to eao-shipstation-utils.php

// eao_get_order_dimensions function moved to eao-shipstation-utils.php

// eao_get_shipstation_carrier_rates function moved to eao-shipstation-utils.php

// eao_format_shipstation_rates_response function moved to eao-shipstation-utils.php

/**
 * Initialize the Enhanced Admin Order Plugin
 * 
 * This function sets up the core plugin functionality and ensures all components are properly initialized.
 * It serves as the central entry point for the plugin's initialization process.
 * 
 * @since 1.7.8
 * @return void
 */
function run_enhanced_admin_order_plugin() {
    // Check if WooCommerce is active
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', 'eao_woocommerce_required_notice' );
        return;
    }

    // Initialize the plugin
    error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] Starting Enhanced Admin Order Plugin initialization');
    
    // Register main AJAX handlers
    add_action('wp_ajax_eao_refresh_order_notes', 'eao_ajax_refresh_order_notes');
    
    // Explicitly call AJAX registration function to ensure all handlers are registered
    eao_register_ajax_handlers();

    // Register AC hooks if Admin Columns is active
    if ( class_exists( 'AC\ListScreen' ) ) {
        // Admin Columns is active, integration will be handled by dedicated file
    }
    
    // Initialize Payment Mockup system AJAX handlers (Phase 0: v2.7.8)
    if ( function_exists( 'eao_initialize_mockup_payments' ) ) {
        eao_initialize_mockup_payments();
    }
    
    error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] Enhanced Admin Order Plugin initialized successfully');
}

/**
 * Remove WooCommerce default order metaboxes that might conflict with our custom interface
 * 
 * @since 1.8.6
 */
function eao_remove_woocommerce_order_metaboxes() {
    $screen = get_current_screen();
    if ( ! $screen || $screen->id !== 'shop_order' ) {
        return;
    }
    
    // Only remove the order items metabox that conflicts with our custom interface
    // Leave all other WooCommerce functionality intact
    remove_meta_box( 'woocommerce-order-items', 'shop_order', 'normal' );
    error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] Removed only woocommerce-order-items metabox to prevent conflict');
}



/**
 * Add meta boxes to order edit screen
 * 
 * @since 1.7.8
 */
function eao_add_order_meta_boxes() {
    $screen = wc_get_container()->get( Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
        ? wc_get_page_screen_id( 'shop-order' )
        : 'shop_order';
        
    add_meta_box(
        'eao-shipstation-rates',
        __('ShipStation Rates', 'enhanced-admin-order'),
        'eao_render_shipstation_api_rates_meta_box_content',
        $screen,
        'side',
        'default'
    );
    
    add_meta_box(
        'eao-custom-notes',
        __('Custom Order Notes', 'enhanced-admin-order'),
        'eao_render_custom_order_notes_meta_box_content',
        $screen,
        'side',
        'default'
    );
}

/**
 * Register additional AJAX handlers for centralized initialization
 * 
 * @since 1.7.8
 * @version 2.4.192 - Added explicit ShipStation and other AJAX handler registration
 */
function eao_register_ajax_handlers() {
    error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] Registering AJAX handlers...');
    
    // Register core save order details handler
    add_action('wp_ajax_eao_save_order_details', 'eao_ajax_save_order_details');
    error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] Registered eao_save_order_details handler');
    
    // Register order note handler (RESTORED from v2.7.0)
    add_action('wp_ajax_eao_add_order_note', 'eao_ajax_add_custom_note_handler');
    error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] Registered eao_add_order_note handler');
    
    // Register refresh order notes handler  
    add_action('wp_ajax_eao_refresh_order_notes', 'eao_ajax_refresh_order_notes');
    error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] Registered eao_refresh_order_notes handler');

    // Register YITH points globals provider (for new orders / missing globals)
    add_action('wp_ajax_eao_get_yith_points_globals', 'eao_ajax_get_yith_points_globals');

    // Points earning: register refresh handler for inline summary panel
    add_action('wp_ajax_eao_refresh_points_earning', 'eao_ajax_refresh_points_earning');
    // Lightweight endpoint for per-item points details popup
    add_action('wp_ajax_eao_get_points_details_for_item', 'eao_ajax_get_points_details_for_item');

    // Register points revoke handler
    add_action('wp_ajax_eao_revoke_points_for_order', 'eao_ajax_revoke_points_for_order');

    // Register roles fetch for dynamic customer roles line
    add_action('wp_ajax_eao_get_customer_roles', 'eao_ajax_get_customer_roles');
    
    // Register FluentCRM profile refresh handler (for real-time updates)
    add_action('wp_ajax_eao_get_fluentcrm_profile', 'eao_ajax_get_fluentcrm_profile');
    // error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] Registered eao_get_fluentcrm_profile handler');
    
    // Note: ShipStation AJAX handlers are registered inline in eao-shipstation-core.php
    // Checking availability for debugging purposes
    if (function_exists('eao_ajax_shipstation_v2_save_credentials_handler')) {
        // error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] ShipStation save credentials handler available');
    } else {
        // error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] WARNING: ShipStation save credentials handler not found');
    }
    if (function_exists('eao_ajax_shipstation_v2_test_connection_handler')) {
        error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] ShipStation test connection handler available');
    } else {
        error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] WARNING: ShipStation test connection handler not found');
    }
    if (function_exists('eao_ajax_shipstation_v2_get_rates_handler')) {
        error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] ShipStation get rates handler available');
    } else {
        error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] WARNING: ShipStation get rates handler not found');
    }
    
    // Check other AJAX handlers availability (these are registered inline in their respective files)
    if (function_exists('eao_ajax_search_products_for_admin_order')) {
        error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] Product search handler available');
    } else {
        error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] WARNING: Product search handler not found');
    }
    if (function_exists('eao_ajax_search_customers')) {
        error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] Customer search handler available');
    } else {
        error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] WARNING: Customer search handler not found');
    }
    if (function_exists('eao_ajax_add_custom_order_note')) {
        error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] Custom order note handler available');
    } else {
        error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] WARNING: Custom order note handler not found');
    }
    
    error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] AJAX handlers registration completed');
}

// AJAX: return display names of roles for a given customer id
function eao_ajax_get_customer_roles() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'eao_save_order_details')) {
        wp_send_json_error(array('message' => 'Invalid nonce'));
        return;
    }
    $customer_id = isset($_POST['customer_id']) ? absint($_POST['customer_id']) : 0;
    if (!$customer_id) { wp_send_json_error(array('message' => 'Invalid customer ID')); return; }
    $user = get_user_by('id', $customer_id);
    if (!$user) { wp_send_json_error(array('message' => 'User not found')); return; }
    $role_names_map = function_exists('wp_roles') ? wp_roles()->get_names() : array();
    $role_display_names = array();
    if (is_array($user->roles)) {
        foreach ($user->roles as $role_slug) {
            if (isset($role_names_map[$role_slug])) { $role_display_names[] = translate_user_role($role_names_map[$role_slug]); }
            else { $role_display_names[] = $role_slug; }
        }
    }
    wp_send_json_success(array('roles' => $role_display_names));
}

/**
 * Display admin notice if WooCommerce is not active
 * 
 * @since 1.7.8
 */
function eao_woocommerce_required_notice() {
    ?>
    <div class="error">
        <p><?php esc_html_e('Enhanced Admin Order Plugin requires WooCommerce to be installed and active.', 'enhanced-admin-order'); ?></p>
    </div>
    <?php
}

// Admin-only: Initialize the plugin only in admin context (not frontend AJAX)
if ( eao_should_load() ) {
    add_action('init', 'run_enhanced_admin_order_plugin');
}

/**
 * Add FluentCRM Profile meta box directly.
 * 
 * @since 1.8.0
 * @param WC_Order $order The order object.
 */
function eao_add_fluentcrm_profile_meta_box_direct( $order ) {
    error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] TOP OF eao_add_fluentcrm_profile_meta_box_direct REACHED.');

    if ( ! is_a( $order, 'WC_Order' ) ) {
        error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] eao_add_fluentcrm_profile_meta_box_direct: Received invalid or no WC_Order object. Object type: ' . (is_object($order) ? get_class($order) : gettype($order)));
        // Let's try to get the order_id from GET params if $order is not valid, as a fallback.
        $order_id_from_get = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
        if ($order_id_from_get) {
            $order_check = wc_get_order($order_id_from_get);
            if (is_a($order_check, 'WC_Order')) {
                $order = $order_check;
                error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] eao_add_fluentcrm_profile_meta_box_direct: Fallback to fetching order via GET param. Order ID: ' . $order->get_id());
                } else {
                 error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] eao_add_fluentcrm_profile_meta_box_direct: Fallback failed. Could not get valid order.');
                return;
            }
        } else {
            return;
        }
    }

    error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] eao_add_fluentcrm_profile_meta_box_direct called for order ID: ' . $order->get_id() . '. Attempting to add fluentcrm_woo_order_widget with direct rendering.');

    add_meta_box(
        'fluentcrm_woo_order_widget_eao', // Use a slightly different ID for our instance to avoid conflicts if FluentCRM did somehow add its own.
        __( 'FluentCRM Profile', 'enhanced-admin-order' ), 
        'eao_render_fluentcrm_actual_profile_html', // Our new direct rendering function
        get_current_screen()->id,                    
        'side',                                      
        'high', // Use 'high' priority to position it first and make it less movable
        array( 'order' => $order ) // Pass the order object to our callback
    );
}

/**
 * Renders the FluentCRM profile HTML by calling FluentCRM's helper function.
 *
 * @since 1.8.0
 * @param mixed $post_or_order Usually WP_Post, but we'll get $order from $meta_box_args.
 * @param array $meta_box_args Arguments passed to the meta box, including our custom 'order' arg.
 */
function eao_render_fluentcrm_actual_profile_html( $post_or_order, $meta_box_args ) {
    $order = isset($meta_box_args['args']['order']) && is_a($meta_box_args['args']['order'], 'WC_Order') 
             ? $meta_box_args['args']['order'] 
             : null;

    if ( ! $order ) {
        // Fallback if $order wasn't in args, try $post_or_order if it's an order
        if (is_a($post_or_order, 'WC_Order')) {
            $order = $post_or_order;
        } elseif (is_object($post_or_order) && isset($post_or_order->ID) && get_post_type($post_or_order->ID) === 'shop_order') {
            // If it's a WP_Post object for a shop_order
            $order = wc_get_order($post_or_order->ID);
        } else {
             // Final fallback: try to get order_id from URL if not available otherwise
            $order_id_from_get = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
            if ($order_id_from_get) {
                $order = wc_get_order($order_id_from_get);
            }
        }
    }
    
    if ( ! $order ) {
        echo '<p>' . __( 'Error: Order context not available for FluentCRM Profile.', 'enhanced-admin-order' ) . '</p>';
        error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] eao_render_fluentcrm_actual_profile_html: Order object not available or invalid.');
        return;
    }

    error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] eao_render_fluentcrm_actual_profile_html called for order ID: ' . $order->get_id());

    if ( ! function_exists( 'fluentcrm_get_crm_profile_html' ) ) {
        echo '<p>' . __( 'Error: FluentCRM function <code>fluentcrm_get_crm_profile_html</code> not found. Is FluentCRM active?', 'enhanced-admin-order' ) . '</p>';
        error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] eao_render_fluentcrm_actual_profile_html: fluentcrm_get_crm_profile_html() function does not exist.');
        return;
    }

    $user_id_or_email = $order->get_user_id();
    if ( ! $user_id_or_email ) {
        $user_id_or_email = $order->get_billing_email();
    }

    if ( ! $user_id_or_email ) {
        echo '<p>' . __( 'No customer ID or email associated with this order to display FluentCRM profile.', 'enhanced-admin-order' ) . '</p>';
        error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] eao_render_fluentcrm_actual_profile_html: No user ID or email for order ID: ' . $order->get_id());
        return;
    }
    
    error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] eao_render_fluentcrm_actual_profile_html: Fetching profile for user/email: ' . $user_id_or_email);
    
    global $post;
    $original_post = $post; 
    $order_post_object = null;
    $hpos_active = false;

    // More robust HPOS check
    if ( class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && method_exists('Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled') ) {
        // WooCommerce 6.0+ way to check HPOS
        $hpos_active = Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] HPOS check via OrderUtil: ' . ($hpos_active ? 'Active' : 'Inactive'));
    } else if (function_exists('wc_get_container') && wc_get_container()->get_definition( 'Automattic\WooCommerce\Internal\Features\FeaturesController' )->feature_is_enabled('custom_order_tables')) {
        // Alternative for some WC versions to check if COT feature is enabled
        $hpos_active = true;
        error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] HPOS check via FeaturesController: Active');
        } else {
        // Fallback to option based check (less direct, but good as a last resort)
        $hpos_option = get_option( 'woocommerce_custom_orders_table_enabled' );
        $hpos_active = ( $hpos_option === 'yes' );
        error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] HPOS check via option: ' . ($hpos_active ? 'Active' : 'Inactive') . ' (Option value: ' . $hpos_option . ')');
    }

    if ( ! $hpos_active ) {
        // Traditional post store for orders, or HPOS check failed/indeterminate so assume traditional for safety.
        $order_post_object = get_post( $order->get_id() );
        if ($order_post_object) {
            $post = $order_post_object;
            setup_postdata( $post ); 
            error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] Set global $post for traditional order ID: ' . $order->get_id());
        }
    } else {
        error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] HPOS is active, not setting global $post from order ID.');
    }

    $profile_html = fluentcrm_get_crm_profile_html( $user_id_or_email, false );

    if ($profile_html) {
        // Ensure CRM links open in a new tab and do not navigate away from order editor
        $profile_html = preg_replace(
            '#<a\s+([^>]*href=\"[^\"]+\"[^>]*)>#i',
            '<a $1 target=\"_blank\" rel=\"noopener noreferrer\">',
            $profile_html
        );
        echo $profile_html;
        // Extra safety: delegate click handling to always open in new tab
        echo '<script type="text/javascript">jQuery(function($){var $box=$("#fluentcrm_woo_order_widget_eao");$box.on("click","a",function(e){var href=$(this).attr("href");if(href){e.preventDefault();window.open(href,"_blank");}});});</script>';
    } else {
        echo '<p>' . __( 'No FluentCRM profile found for this customer, or profile is empty.', 'enhanced-admin-order' ) . '</p>';
        error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] eao_render_fluentcrm_actual_profile_html: fluentcrm_get_crm_profile_html() returned empty for: ' . $user_id_or_email . ' on order ID: ' . $order->get_id());
    }

    if ( $post === $order_post_object && $original_post !== $order_post_object ) { // Restore only if we changed it and original was different
       $post = $original_post;
        if ($post) {
            setup_postdata($post); 
    } else {
            wp_reset_postdata(); 
    }
        error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] Restored global $post.');
    }
}

/**
 * AJAX handler for refreshing order notes list after save operation.
 * Returns the updated HTML content of the existing notes list wrapper.
 * 
 * @since 2.4.1
 */
function eao_ajax_refresh_order_notes() {
    try {
        // Verify nonce (accept multiple for backward compatibility)
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        $nonce_ok = false;
        if ($nonce) {
            if (wp_verify_nonce($nonce, 'eao_save_order_details')) { $nonce_ok = true; }
            if (!$nonce_ok && wp_verify_nonce($nonce, 'eao_editor_nonce')) { $nonce_ok = true; }
            if (!$nonce_ok && wp_verify_nonce($nonce, 'eao_refresh_notes_nonce')) { $nonce_ok = true; }
            if (!$nonce_ok && wp_verify_nonce($nonce, 'eao_refresh_notes')) { $nonce_ok = true; }
        }
        if (!$nonce_ok) {
            wp_send_json_error(array('message' => 'Invalid nonce.'));
        }

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        if (!$order_id) {
            wp_send_json_error(array('message' => 'Invalid order ID.'));
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => 'Order not found.'));
        }

        // Build HTML via shared renderer; also compute count safely
        $notes_html = function_exists('eao_get_existing_order_notes_list_html')
            ? eao_get_existing_order_notes_list_html($order_id)
            : '<p>' . esc_html__('No notes found for this order.', 'enhanced-admin-order') . '</p>';

        $notes = wc_get_order_notes(array('order_id' => $order_id));
        $count = is_array($notes) ? count($notes) : 0;

        wp_send_json_success(array(
            'notes_html' => $notes_html,
            'notes_count' => $count
        ));
    } catch (Throwable $t) {
        if (function_exists('error_log')) { error_log('[EAO Notes] AJAX refresh error: ' . $t->getMessage()); }
        wp_send_json_error(array('message' => 'Refresh failed: ' . $t->getMessage()));
    }
}

/**
 * AJAX: Return YITH points globals for the given order/customer so the inline slider can render.
 */
function eao_ajax_get_yith_points_globals() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'eao_editor_nonce')) {
        wp_send_json_error(array('message' => 'Invalid nonce'));
        return;
    }
    $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    $order = $order_id ? wc_get_order($order_id) : null;
    if (!$order) { wp_send_json_error(array('message' => 'Order not found')); return; }
    $customer_id_override = isset($_POST['customer_id']) ? absint($_POST['customer_id']) : 0;
    $customer_id = $customer_id_override > 0 ? $customer_id_override : (int) $order->get_customer_id();
    if ($customer_id <= 0) { wp_send_json_error(array('message' => 'Guest order')); return; }

    if (!function_exists('eao_yith_is_available') || !eao_yith_is_available()) {
        wp_send_json_error(array('message' => 'YITH not available'));
        return;
    }
    if (!function_exists('eao_yith_get_customer_points')) {
        wp_send_json_error(array('message' => 'YITH points API missing'));
        return;
    }
    $customer_points = (int) eao_yith_get_customer_points($customer_id);

    // Detect existing redemption on the order (only if not previewing a different customer)
    $existing_points_redeemed = 0;
    $existing_discount_amount = 0.0;
    if ($customer_id_override <= 0) {
        $coupon_points = $order->get_meta('_ywpar_coupon_points', true);
        $coupon_amount = $order->get_meta('_ywpar_coupon_amount', true);
        if (!empty($coupon_points) && is_numeric($coupon_points)) {
            $existing_points_redeemed = (int) $coupon_points;
            $existing_discount_amount = !empty($coupon_amount) ? (float) $coupon_amount : ($existing_points_redeemed / 10.0);
        } else {
            foreach ($order->get_items('coupon') as $coupon_item) {
                $code = method_exists($coupon_item, 'get_code') ? $coupon_item->get_code() : '';
                if ($code && strpos($code, 'ywpar_discount_') === 0) {
                    $existing_discount_amount = abs((float) $coupon_item->get_discount());
                    $existing_points_redeemed = (int) round($existing_discount_amount * 10);
                    break;
                }
            }
        }
    }

    $total_available_points = (int) ($customer_points + $existing_points_redeemed);

    wp_send_json_success(array(
        'existingPointsRedeemed' => (int) $existing_points_redeemed,
        'existingDiscountAmount' => (float) $existing_discount_amount,
        'totalAvailablePoints' => (int) $total_available_points,
        'customerCurrentPoints' => (int) $customer_points
    ));
}

/**
 * Simple AJAX debug test function
 * 
 * @since 2.9.33
 */




/**
 * AJAX handler for getting FluentCRM profile HTML (for real-time updates)
 */
function eao_ajax_get_fluentcrm_profile() {
    // Clean output buffer
    if (ob_get_level()) { ob_clean(); }
    
    // Verify nonce  
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'eao_editor_nonce')) {
        wp_send_json_error(array('message' => 'Nonce verification failed.'));
        return;
    }

    try {
        $customer_id = isset($_POST['customer_id']) ? absint($_POST['customer_id']) : 0;
        if (!$customer_id) {
            wp_send_json_error(array('message' => 'Invalid customer ID.'));
        return;
    }

        // Check if FluentCRM is available
        if (!function_exists('fluentcrm_get_crm_profile_html')) {
            wp_send_json_error(array('message' => 'FluentCRM not available.'));
        return;
    }

        // Get customer user object
        $user = get_user_by('ID', $customer_id);
        if (!$user) {
            wp_send_json_error(array('message' => 'Customer not found.'));
        return;
    }

        error_log('[EAO FluentCRM AJAX] Getting profile for customer ID: ' . $customer_id . ', email: ' . $user->user_email);
        
        // Get profile HTML using FluentCRM function (use customer_id directly, then fallback to email)
        $profile_html = fluentcrm_get_crm_profile_html($customer_id, false);
        
        // If ID didn't work, try with email
        if (!$profile_html) {
            error_log('[EAO FluentCRM AJAX] Trying with email instead of ID');
            $profile_html = fluentcrm_get_crm_profile_html($user->user_email, false);
        }
        
        if ($profile_html && trim($profile_html) !== '') {
            error_log('[EAO FluentCRM AJAX] Profile HTML found, length: ' . strlen($profile_html));
            wp_send_json_success(array('profile_html' => $profile_html));
        } else {
            error_log('[EAO FluentCRM AJAX] No profile HTML returned');
            wp_send_json_success(array('profile_html' => '<p>No FluentCRM profile found for this customer, or profile is empty.</p>'));
        }
        
    } catch (Exception $e) {
        error_log('[EAO FluentCRM AJAX] Exception: ' . $e->getMessage());
        wp_send_json_error(array('message' => 'An error occurred while fetching FluentCRM profile.'));
    }
}

/**
 * AJAX: Refresh YITH Points Earning summary HTML for inline panel
 */
function eao_ajax_refresh_points_earning() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'eao_editor_nonce')) {
        wp_send_json_error(array('message' => 'Invalid nonce.'));
        return;
    }

    $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    if (!$order_id) {
        wp_send_json_error(array('message' => 'Invalid order ID.'));
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(array('message' => 'Order not found.'));
        return;
    }

    // Gracefully handle cases where YITH is not available or guest order
    if (!function_exists('eao_yith_is_available') || !eao_yith_is_available() || $order->get_customer_id() == 0) {
        wp_send_json_success(array('html' => '<div class="eao-earning-guest-notice"><em style="color:#666;">' . esc_html__('Points earning is not available for this order.', 'enhanced-admin-order') . '</em></div>'));
        return;
    }

    $calc = eao_yith_calculate_order_points_preview($order_id);

    // If UI passed a staged points discount amount, subtract equivalent points from preview
    $staged_amount = isset($_POST['staged_points_amount']) ? floatval($_POST['staged_points_amount']) : 0;
    if ($staged_amount > 0 && isset($calc['total_points'])) {
        $ppd = isset($calc['points_per_dollar']) ? floatval($calc['points_per_dollar']) : 0;
        if ($ppd <= 0) { $ppd = 1.0; }
        $deduct_points = $ppd * $staged_amount;
        $calc['total_points'] = max(0, intval($calc['total_points']) - intval($deduct_points));
        $calc['messages'][] = sprintf('Reduced %s points due to staged points/coupon discount', number_format($deduct_points));
    }

    ob_start();
    if (!empty($calc['success']) && !empty($calc['can_earn'])) {
        $expected_points = isset($calc['total_points']) ? intval($calc['total_points']) : 0;
        echo '<div class="eao-earning-summary">';
        if ($expected_points > 0) {
            echo '<span class="eao-earning-points"><strong>' . esc_html(number_format($expected_points)) . '</strong> ' . esc_html__('points', 'enhanced-admin-order') . '</span>';
            echo '<span class="eao-earning-text"> ' . esc_html__('will be awarded to the customer based on YITH earning rules â€” not considering admin discounts; when points/coupons are used, YITH options may reduce earning accordingly', 'enhanced-admin-order') . '</span>';
        } else {
            echo '<span class="eao-earning-text" style="color:#666;">' . esc_html__('No points will be earned (0 points)', 'enhanced-admin-order') . '</span>';
        }
        echo '</div>';

        if (!empty($calc['breakdown']) && is_array($calc['breakdown']) && count($calc['breakdown']) > 1) {
            echo '<details class="eao-points-breakdown" style="margin-top:12px;">';
            echo '<summary style="cursor:pointer;font-size:12px;color:#666;padding:5px 0;">' . esc_html__('Show detailed breakdown', 'enhanced-admin-order') . '</summary>';
            echo '<div class="eao-breakdown-content" style="margin-top:8px;font-size:12px;background:#f9f9f9;padding:12px;border-radius:4px;border-left:3px solid #0073aa;">';
            foreach ($calc['breakdown'] as $item) {
                echo '<div class="eao-breakdown-item" style="margin-bottom:10px;padding-bottom:8px;border-bottom:1px solid #eee;">';
                echo '<div style="font-weight:600;color:#333;">' . esc_html($item['product_name']) . '</div>';
                echo '<div style="margin-left:15px;margin-top:4px;line-height:1.4;">';
                if (isset($item['applied_rule']) && is_array($item['applied_rule'])) {
                    $r = $item['applied_rule'];
                    $rn = isset($r['name']) && $r['name'] !== '' ? $r['name'] : 'Unnamed rule';
                    if (isset($r['points'])) {
                        echo '<div style="margin-top:4px;color:#444;">' . sprintf(__('Applied rule: %s â†’ %s pts', 'enhanced-admin-order'), '<strong>' . esc_html($rn) . '</strong>', esc_html(number_format($r['points']))) . '</div>';
                    } else {
                        echo '<div style="margin-top:4px;color:#444;">' . sprintf(__('Applied rule: %s', 'enhanced-admin-order'), '<strong>' . esc_html($rn) . '</strong>') . '</div>';
                    }
                }
                echo '<div style="font-weight:500;">' . esc_html__('Final:', 'enhanced-admin-order') . ' ' . esc_html(number_format($item['points_per_item'])) . ' pts Ã— ' . esc_html($item['quantity']) . ' = <strong>' . esc_html(number_format($item['total_points'])) . ' pts</strong></div>';
                echo '</div></div>';
            }
            echo '</div></details>';
        }
    } else {
        $msg = !empty($calc['reasons']) ? implode('; ', $calc['reasons']) : __('Unable to calculate points earning', 'enhanced-admin-order');
        echo '<div class="eao-earning-summary"><span class="eao-earning-text" style="color:#666;">' . esc_html($msg) . '</span></div>';
    }
    $html = ob_get_clean();

    $payload = array('html' => $html);
    if (isset($calc) && is_array($calc)) {
        if (isset($calc['breakdown'])) { $payload['breakdown'] = $calc['breakdown']; }
        if (isset($calc['points_per_dollar'])) { $payload['points_per_dollar'] = $calc['points_per_dollar']; }
        if (isset($calc['total_points'])) { $payload['total_points'] = $calc['total_points']; }
    }

    wp_send_json_success($payload);
}

// Points earning details endpoint
function eao_ajax_get_points_details_for_item() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'eao_editor_nonce')) {
        wp_send_json_error(array('message' => 'Invalid nonce'));
        return;
    }
    $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    $order_item_id = isset($_POST['order_item_id']) ? absint($_POST['order_item_id']) : 0;
    if (!$order_id || !$order_item_id) {
        wp_send_json_error(array('message' => 'Invalid parameters'));
        return;
    }
    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(array('message' => 'Order not found'));
        return;
    }
    if (!function_exists('eao_yith_calculate_order_points_preview')) {
        wp_send_json_error(array('message' => 'YITH not available'));
        return;
    }
    $calc = eao_yith_calculate_order_points_preview($order_id);
    if (empty($calc['breakdown']) || !is_array($calc['breakdown'])) {
        wp_send_json_error(array('message' => 'No breakdown found'));
        return;
    }
    foreach ($calc['breakdown'] as $bd) {
        if ((int)$bd['order_item_id'] === (int)$order_item_id) {
            wp_send_json_success($bd);
            return;
        }
    }
    wp_send_json_error(array('message' => 'Item not found in breakdown'));
}

/**
 * AJAX: Revoke points for an order (admin action)
 */
function eao_ajax_revoke_points_for_order() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'eao_editor_nonce')) {
        wp_send_json_error(array('message' => 'Invalid nonce'));
        return;
    }
    $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    if (!$order_id) {
        wp_send_json_error(array('message' => 'Invalid order ID'));
        return;
    }
    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(array('message' => 'Order not found'));
        return;
    }

    if (!function_exists('eao_yith_is_available') || !eao_yith_is_available()) {
        wp_send_json_error(array('message' => 'YITH Points not available'));
            return;
        }

    // Only revoke if we previously granted and not already revoked
    $granted = (bool) $order->get_meta('_eao_points_granted', true);
    $revoked = (bool) $order->get_meta('_eao_points_revoked', true);
    $granted_pts = intval($order->get_meta('_eao_points_granted_points', true));
    if (!$granted || $revoked || $granted_pts <= 0) {
        // Nothing to revoke; respond success to clear UI quietly
        wp_send_json_success(array('message' => 'Nothing to revoke'));
        return;
    }

    // Ensure we have a customer to operate on
    $customer_id = (int) $order->get_customer_id();
    if ($customer_id <= 0) {
        wp_send_json_error(array('message' => 'Cannot revoke: order has no customer.'));
        return;
    }

    try {
        // Revoke using YITH API
        $did_revoke = false;
        if (function_exists('ywpar_decrease_points')) {
            eao_points_debug_log('Revoke via ywpar_decrease_points: ' . $granted_pts . ' pts', $order_id);
            ywpar_decrease_points($customer_id, $granted_pts, sprintf(__('Revoked for Order #%d (admin)', 'enhanced-admin-order'), $order_id), $order_id);
            $did_revoke = true;
        } elseif (function_exists('ywpar_increase_points')) {
            eao_points_debug_log('Revoke via negative ywpar_increase_points: ' . $granted_pts . ' pts', $order_id);
            ywpar_increase_points($customer_id, -1 * $granted_pts, sprintf(__('Revoked for Order #%d (admin)', 'enhanced-admin-order'), $order_id), $order_id);
            $did_revoke = true;
        }
        if (!$did_revoke && function_exists('ywpar_get_customer')) {
            $cust = ywpar_get_customer($customer_id);
            if ($cust && method_exists($cust, 'update_points')) {
                eao_points_debug_log('Revoke fallback via customer->update_points(-X)', $order_id);
                $cust->update_points(-1 * $granted_pts, 'order_manually_revoked', array('order_id' => $order_id, 'description' => 'Manual revoke from Enhanced Admin Order'));
                $did_revoke = true;
            }
        }

        // Update our metas so UI reflects revoke state and allows re-grant later
        $order->update_meta_data('_eao_points_revoked', 1);
        $order->update_meta_data('_eao_points_revoked_points', $granted_pts);
        $order->update_meta_data('_eao_points_granted', 0);
        $order->update_meta_data('_eao_points_granted_points', 0);
        $order->add_order_note(sprintf(__('EAO: Revoked %d points (admin action).', 'enhanced-admin-order'), $granted_pts));

        // Clear YITH order-level award markers so future re-grants are allowed
        try {
            $meta_items = $order->get_meta_data();
            foreach ($meta_items as $meta_item) {
                if (!is_object($meta_item)) { continue; }
                $data = method_exists($meta_item, 'get_data') ? $meta_item->get_data() : null;
                if (!is_array($data) || empty($data['key'])) { continue; }
                $key = (string)$data['key'];
                if (strpos($key, '_ywpar_') === 0) {
                    $order->delete_meta_data($key);
                    eao_points_debug_log('Removed YITH meta on revoke: ' . $key, $order_id);
                }
            }
        } catch (Exception $e) {
            eao_points_debug_log('Error clearing YITH metas on revoke: ' . $e->getMessage(), $order_id);
        }

        $order->save();

        wp_send_json_success(array('message' => 'Points revoked'));
    } catch (Exception $e) {
        wp_send_json_error(array('message' => $e->getMessage()));
    }
}


