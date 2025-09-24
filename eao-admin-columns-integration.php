<?php
/**
 * Admin Columns Integration for Enhanced Admin Order Plugin
 * 
 * @package EnhancedAdminOrder
 * @since 2.5.34
 * @version 2.5.38 - Also hide orders page heading to avoid redundancy
 * @author Amnon Manneberg
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Initialize Admin Columns integration with Create New Order button
 * 
 * @since 2.5.34
 */
function eao_admin_columns_integration_init() {
    // Admin Columns integration (working v150 approach)
    add_action('acp/ready', 'eao_admin_columns_init_registration', 10, 1);
    
    // Hide redundant toolbar buttons on orders page (native Add order and any legacy EAO button)
    add_action('admin_footer', 'eao_admin_columns_hide_orders_toolbar_buttons');
    
    // Add CSS for our buttons
    add_action('admin_head', 'eao_admin_columns_button_styles');
    
    // Handle new order creation
    add_action('admin_init', 'eao_admin_columns_handle_creation');
}
add_action('plugins_loaded', 'eao_admin_columns_integration_init', 20);

/**
 * Initialize registration of custom column type when Admin Columns Pro is ready.
 * RESTORED FROM BACKUP V150 - This is the proven working implementation
 * 
 * @since 1.0.4
 * @modified 1.0.5 - Removed AC\AdminColumnsPro type hint.
 * @modified 1.0.6 - Corrected syntax error in error_log calls.
 * @modified 1.0.7 - Simplified class existence check, removed ACA\AdminColumnsPro check.
 * @modified 1.1.1 - Ensured log messages use current version.
 * @modified 2.5.34 - Restored from backup v150
 * @modified 2.5.36 - Renamed for architectural compliance
 */
function eao_admin_columns_init_registration($acp_instance) {
    // If acp/ready has fired, we can be reasonably sure its classes are loaded.
    // The $acp_instance parameter confirms this further.
    // We also need AC\Column to exist to extend it.
    if ($acp_instance && (class_exists('AC\AdminColumns') || class_exists('AC\AdminColumns')) && class_exists('AC\Column')) { 
        add_action('acp/column_types', 'eao_admin_columns_register_column_type');
        error_log('[EAO] Admin Columns integration initialized successfully');
    } else {
        $ac_admin_cols_exists = class_exists('AC\AdminColumns') || class_exists('AC\AdminColumns');
        $ac_column_exists = class_exists('AC\Column');
        error_log('[EAO] Admin Columns integration failed - Required classes not available. AC: ' . ($ac_admin_cols_exists ? 'yes' : 'no') . ', Column: ' . ($ac_column_exists ? 'yes' : 'no'));
    }
}

/**
 * Register custom column type - RESTORED FROM BACKUP V150
 * @since 1.0.1
 * @modified 1.1.1 - Ensured log messages use current version.
 * @modified 2.5.34 - Restored from backup v150
 * @modified 2.5.36 - Renamed for architectural compliance
 */
function eao_admin_columns_register_column_type($list_screen) {
    if ($list_screen) {
        $list_screen_class = get_class($list_screen);
        $list_screen_key = method_exists($list_screen, 'get_key') ? $list_screen->get_key() : '[key_not_available]';
        
        $list_screen_post_type = (method_exists($list_screen, 'get_post_type') && $list_screen->get_post_type()) ? $list_screen->get_post_type() : '[post_type_not_available_or_empty]';

        error_log('[EAO] Checking list screen - Class: ' . $list_screen_class . ', Key: ' . $list_screen_key . ', Post Type: ' . $list_screen_post_type);
    } else {
        error_log('[EAO] list_screen is null - cannot proceed');
        return; // Cannot proceed if $list_screen is null
    }

    // Updated conditions for identifying WC Order screens (including HPOS) - EXACT FROM V150
    $is_wc_hpos_order_screen_class = ($list_screen_class === 'ACA\WC\ListScreen\Order');
    $is_wc_hpos_order_screen_key = ($list_screen_key === 'wc_order');
    
    // Keep the traditional post type check for non-HPOS if needed, though HPOS is primary target now
    $is_shop_order_post_type = ($list_screen_class === 'AC\ListScreen\Post' && $list_screen_post_type === 'shop_order');

    // We want to register our column if it's the HPOS order screen (identified by class or key) 
    // OR the traditional shop_order post type screen.
    if ($is_wc_hpos_order_screen_class || $is_wc_hpos_order_screen_key || $is_shop_order_post_type) {
        error_log('[EAO] WooCommerce orders screen detected - registering Enhanced Edit column');
        
        if (!class_exists('EAO_Enhanced_Edit_Column')) {
            /**
             * EAO_Enhanced_Edit_Column Class for Admin Columns Pro.
             * RESTORED FROM BACKUP V150 - This is the exact working implementation
             *
             * @since 1.0.1
             */
            class EAO_Enhanced_Edit_Column extends \AC\Column {

                public function __construct() {
                    $this->set_type('eao_actions'); // RESTORED FROM V150 - Original working type
                    $this->set_label(__('Enhanced Edit', 'enhanced-admin-order'));
                    $this->set_group('woocommerce'); // Group it with other WooCommerce columns
                }

                public function get_value($id) {
                    $order_id = 0;
                    $order = wc_get_order($id);

                    if ($order) {
                        $order_id = $order->get_id();
                    }

                    if ($order_id) {
                        $edit_url = add_query_arg(
                            array(
                                'page'     => 'eao_custom_order_editor_page',
                                'order_id' => $order_id,
                            ),
                            admin_url('admin.php')
                        );
                        return sprintf(
                            '<a href="%s" class="button eao-edit-button" title="%s" target="_blank"><span class="dashicons dashicons-edit"></span></a>',
                            esc_url($edit_url),
                            esc_attr__('Edit with Enhanced Admin Order', 'enhanced-admin-order')
                        );
                    }
                    return '-'; // Return a dash if no order ID
                }
                
                // Optional: Define if the column is editable, sortable, filterable if needed later via ACP UI
            }
        }
        
        $list_screen->register_column_type(new EAO_Enhanced_Edit_Column());
        error_log('[EAO] Enhanced Edit column registered successfully for: ' . $list_screen_class);
        
    } else {
        error_log('[EAO] Not a WooCommerce orders screen - skipping column registration');
    }
}

/**
 * Add "Create New Order" button to WooCommerce orders page
 * 
 * @since 2.5.27
 * @modified 2.5.36 - Renamed for architectural compliance
 */
function eao_admin_columns_add_create_button() {
    $screen = get_current_screen();
    
    // Check if we're on WooCommerce orders page (both HPOS and legacy)
    $is_hpos_orders = $screen && $screen->id === 'woocommerce_page_wc-orders';
    $is_legacy_orders = $screen && $screen->id === 'edit-shop_order';
    
    if (!$is_hpos_orders && !$is_legacy_orders) {
        return;
    }
    
    // Only add button if user has proper permissions
    if (!current_user_can('edit_shop_orders')) {
        return;
    }
    
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Create the "Create New Order" button
        var createButton = '<a href="#" id="eao-create-new-order" class="page-title-action eao-create-new-order-button" title="Create a new order using Enhanced Admin Order editor">Create New Order</a>';
        
        // Find the best location to insert the button
        var inserted = false;
        
        // Try to insert next to existing "Add order" button
        var existingAddButton = $('.page-title-action').first();
        if (existingAddButton.length > 0) {
            existingAddButton.after(createButton);
            inserted = true;
        }
        
        // Fallback: Insert after page title
        if (!inserted) {
            var pageTitle = $('.wp-heading-inline, h1.wp-heading-inline').first();
            if (pageTitle.length > 0) {
                pageTitle.after(createButton);
                inserted = true;
            }
        }
        
        // Final fallback: Insert at top of content area
        if (!inserted) {
            $('.wrap h1').first().after(createButton);
        }
        
        // Handle button click
        $('#eao-create-new-order').on('click', function(e) {
            e.preventDefault();
            
            // Show loading state
            $(this).text('Creating...').prop('disabled', true);
            
            // Redirect to create new order
            window.location.href = '<?php echo esc_url(add_query_arg(array('action' => 'eao_create_new_order'), admin_url('admin.php'))); ?>';
        });
    });
    </script>
    <?php
}

/**
 * Handle new order creation
 * 
 * @since 2.5.27
 * @modified 2.5.36 - Renamed for architectural compliance
 */
function eao_admin_columns_handle_creation() {
    if (!isset($_GET['action']) || $_GET['action'] !== 'eao_create_new_order') {
        return;
    }
    
    if (!current_user_can('edit_shop_orders')) {
        wp_die(__('You do not have permission to create orders.', 'enhanced-admin-order'));
    }
    
    try {
        // Create a new draft order
        $order = wc_create_order(array(
            'status' => 'draft',
            'created_via' => 'enhanced-admin-order'
        ));
        
        if (is_wp_error($order)) {
            wp_die(__('Failed to create new order: ', 'enhanced-admin-order') . $order->get_error_message());
        }
        
        $order_id = $order->get_id();
        
        // Add a note about creation
        $order->add_order_note(__('Order created via Enhanced Admin Order plugin.', 'enhanced-admin-order'));
        
        // Redirect to our enhanced editor
        $edit_url = add_query_arg(
            array(
                'page' => 'eao_custom_order_editor_page',
                'order_id' => $order_id,
            ),
            admin_url('admin.php')
        );
        
        wp_redirect($edit_url);
        exit;
        
    } catch (Exception $e) {
        wp_die(__('Failed to create new order: ', 'enhanced-admin-order') . $e->getMessage());
    }
}

/**
 * Add CSS styles for our buttons
 * 
 * @since 2.5.27
 */
function eao_admin_columns_button_styles() {
    $screen = get_current_screen();
    
    // Only add styles on WooCommerce orders pages
    $is_hpos_orders = $screen && $screen->id === 'woocommerce_page_wc-orders';
    $is_legacy_orders = $screen && $screen->id === 'edit-shop_order';
    
    if (!$is_hpos_orders && !$is_legacy_orders) {
        return;
    }
    
    ?>
    <style type="text/css">
    /* Enhanced Edit button styling */
    .eao-edit-button {
        background-color: #0073aa !important;
        border-color: #0073aa !important;
        color: white !important;
        padding: 4px 8px !important;
        min-height: auto !important;
        line-height: 1 !important;
        text-decoration: none !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
    }
    
    .eao-edit-button:hover {
        background-color: #005a87 !important;
        border-color: #005a87 !important;
        color: white !important;
    }
    
    .eao-edit-button .dashicons {
        width: 16px !important;
        height: 16px !important;
        font-size: 16px !important;
        line-height: 1 !important;
        margin: 0 !important;
    }
    
    /* Create New Order button styling */
    .eao-create-new-order-button {
        background-color: #00a32a !important;
        border-color: #00a32a !important;
        color: white !important;
        margin-left: 10px !important;
    }
    
    .eao-create-new-order-button:hover {
        background-color: #008a20 !important;
        border-color: #008a20 !important;
        color: white !important;
    }

    /* Hide native Add order button and any legacy in-page EAO create button */
    .wrap .page-title-action[href*="post-new.php?post_type=shop_order"],
    .eao-create-new-order-button { display: none !important; }

    /* Hide the redundant page heading on the orders list */
    .wrap .wp-heading-inline { display: none !important; }
    
    /* Responsive adjustments */
    @media (max-width: 782px) {
        .eao-create-new-order-button {
            margin-left: 0 !important;
            margin-top: 10px !important;
            display: block !important;
        }
    }
    </style>
    <?php
}

/**
 * Hide toolbar buttons on orders screen via JS fallback
 * Ensures coverage if selectors differ (e.g., HPOS variants)
 * 
 * @since 2.5.37
 */
function eao_admin_columns_hide_orders_toolbar_buttons() {
    $screen = get_current_screen();
    $is_hpos_orders = $screen && $screen->id === 'woocommerce_page_wc-orders';
    $is_legacy_orders = $screen && $screen->id === 'edit-shop_order';
    if (!$is_hpos_orders && !$is_legacy_orders) {
        return;
    }
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($){
        // Hide native Add order by href or by text label
        $(
            '.wrap .page-title-action[href*="post-new.php?post_type=shop_order"],' +
            '.eao-create-new-order-button'
        ).hide();

        $('.wrap .page-title-action').filter(function(){
            return $(this).text().trim().toLowerCase() === 'add order';
        }).hide();

        // Hide the orders page heading if present
        $('.wrap .wp-heading-inline').hide();
    });
    </script>
    <?php
}


