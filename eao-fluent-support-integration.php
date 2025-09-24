<?php
/**
 * Enhanced Admin Order - Fluent Support Integration
 * 
 * Provides integration with Fluent Support plugin for ticket creation and management
 * directly from WooCommerce order edit pages.
 * 
 * @package EnhancedAdminOrder
 * @since 2.6.0
 * @version 2.7.1 - Preserve rich formatting; stop auto-appending order info to ticket body
 * @author Amnon Manneberg
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fluent Support Integration Class
 * 
 * Handles all Fluent Support functionality including ticket creation,
 * customer linking, and ticket management from order pages.
 */
class EAO_Fluent_Support_Integration {
    
    /**
     * Instance of this class
     */
    private static $instance = null;
    
    /**
     * Whether Fluent Support is available
     */
    private $fluent_support_available = false;
    
    /**
     * Fluent Support version
     */
    private $fluent_support_version = null;
    
    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->fluent_support_available = $this->check_fluent_support_availability();
        $this->init_hooks();
    }
    
    /**
     * Public method to check if Fluent Support is available
     */
    public function is_available() {
        return $this->fluent_support_available;
    }

    /**
     * Check if Fluent Support is available
     */
    private function check_fluent_support_availability() {
        error_log('[EAO Fluent Support] Starting availability check...');
        
        // Check for Fluent Support constant
        $version_constant = defined('FLUENT_SUPPORT_VERSION');
        
        // Build detection summary
        $detection_methods = array();
        if ($version_constant) {
            $detection_methods[] = 'FLUENT_SUPPORT_VERSION constant found: ' . FLUENT_SUPPORT_VERSION;
            $this->fluent_support_version = FLUENT_SUPPORT_VERSION;
        }
        
        // Check for function existence
        if (function_exists('FluentSupportApi')) {
            $detection_methods[] = 'FluentSupportApi function found';
        }
        
        // Check for class existence
        if (class_exists('\\FluentSupport\\App\\Services\\Helper')) {
            $detection_methods[] = 'FluentSupport Helper class found';
        }
        
        if (class_exists('\\FluentSupport\\Framework\\Foundation\\App')) {
            $detection_methods[] = 'FluentSupport App class found';
        }
        
        // Check if plugins are active
        $active_plugins = get_option('active_plugins', array());
        foreach ($active_plugins as $plugin) {
            if (strpos($plugin, 'fluent-support') !== false) {
                $detection_methods[] = 'Found in active plugins: ' . $plugin;
            }
        }
        
        error_log('[EAO Fluent Support] Detection results: ' . implode('; ', $detection_methods));
        
        if (empty($detection_methods)) {
            error_log('[EAO Fluent Support] No Fluent Support detected');
            return false;
        }
        
        // Check for required classes
        $required_classes = array(
            '\\FluentSupport\\App\\Models\\Customer',
            '\\FluentSupport\\App\\Models\\Ticket',
            '\\FluentSupport\\App\\Models\\MailBox',
            '\\FluentSupport\\App\\Models\\Conversation',
            '\\FluentSupport\\App\\Services\\Helper'
        );
        
        $missing_classes = array();
        foreach ($required_classes as $class) {
            if (!class_exists($class)) {
                $missing_classes[] = $class;
            }
        }
        
        if (!empty($missing_classes)) {
            // Only log if there's an actual error
            error_log('[EAO Fluent Support] Missing required classes: ' . implode(', ', $missing_classes));
            return false;
        }
        
        // error_log('[EAO Fluent Support] Integration available! Version: ' . $this->fluent_support_version);
        return true;
    }
    
    /**
     * Initialize hooks and filters
     */
    private function init_hooks() {
        if (!$this->fluent_support_available) {
            return;
        }
        
        // Admin hooks - metabox registration moved to direct call in main plugin file for consistency
        
        // AJAX hooks
        add_action('wp_ajax_eao_create_fluent_support_ticket', array($this, 'ajax_create_ticket'));
        add_action('wp_ajax_eao_get_customer_tickets', array($this, 'ajax_get_customer_tickets'));
        add_action('wp_ajax_eao_get_customer_email_by_id', array($this, 'ajax_get_customer_email_by_id'));
        add_action('wp_ajax_eao_get_fluent_support_status', array($this, 'ajax_get_status'));
        add_action('wp_ajax_eao_final_cleanup_trigger', array($this, 'ajax_final_cleanup_trigger'));
        add_action('wp_ajax_nopriv_eao_final_cleanup_trigger', array($this, 'ajax_final_cleanup_trigger'));
        
        // Enqueue scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Add action for delayed order selection
        add_action('eao_set_fluent_support_order', array($this, 'delayed_order_selection'), 10, 2);
        
        // Add action for final conversation cleanup
        add_action('eao_final_conversation_cleanup', array($this, 'final_conversation_cleanup'), 10, 2);
        
        // Admin notice debugging removed for production
        
        // Debug hook to ensure WooCommerce integration is loaded
        add_action('admin_init', array($this, 'debug_custom_field_types'), 999);
        
        // Override Fluent Support Pro WooCommerce URLs to point to Enhanced Admin Order editor
        // Use very early hook to ensure we override before Fluent Support Pro registers its filters
        add_action('plugins_loaded', array($this, 'register_field_overrides'), 1);
        add_action('init', array($this, 'register_field_overrides_late'), 999);
    }
    
    /**
     * Add Fluent Support metabox to EAO order page
     */
    public function add_fluent_support_metabox() {
        // error_log('[EAO Fluent Support] add_fluent_support_metabox called');
        // error_log('[EAO Fluent Support] Available: ' . ($this->fluent_support_available ? 'YES' : 'NO'));
        
        if (!$this->fluent_support_available) {
            error_log('[EAO Fluent Support] Not available, exiting');
            return;
        }
        
        global $current_screen, $post;
        
        // error_log('[EAO Fluent Support] Current screen: ' . ($current_screen ? $current_screen->id : 'none'));
        // error_log('[EAO Fluent Support] Post: ' . ($post ? $post->post_type . ' ID:' . $post->ID : 'none'));
        // error_log('[EAO Fluent Support] GET order_id: ' . (isset($_GET['order_id']) ? $_GET['order_id'] : 'none'));
        
        // Check if we're on EAO page
        $is_eao_page = false;
        if ($current_screen && $current_screen->id === 'toplevel_page_eao_custom_order_editor_page') {
            $is_eao_page = true;
        }
        
        // error_log('[EAO Fluent Support] Is EAO page: ' . ($is_eao_page ? 'YES' : 'NO'));
        
        // Check if we have order context
        $has_order_context = false;
        if ($post && $post->post_type === 'shop_order') {
            // error_log('[EAO Fluent Support] Order context: shop_order post');
            $has_order_context = true;
        } elseif (isset($_GET['order_id']) && is_numeric($_GET['order_id'])) {
            // error_log('[EAO Fluent Support] Order context: EAO page with order_id');
            $has_order_context = true;
        }
        
        if (!$has_order_context) {
            // error_log('[EAO Fluent Support] Not in order context, exiting');
            return;
        }
        
        // Check user permissions
        if (!current_user_can('manage_woocommerce')) {
            // error_log('[EAO Fluent Support] User lacks permissions, exiting');
            return;
        }
        
        // Determine screen ID for metabox registration
        $screen_id = $current_screen ? $current_screen->id : 'shop_order';
        
        // error_log('[EAO Fluent Support] Screen ID for metabox: ' . $screen_id);
        
        add_meta_box(
            'eao-fluent-support',
            'Customer Support Tickets',
            array($this, 'render_fluent_support_metabox'),
            $screen_id,
            'normal',
            'low'
        );
        
        // error_log('[EAO Fluent Support] Metabox registered successfully');
    }
    
    /**
     * Render Fluent Support metabox content
     */
    public function render_fluent_support_metabox($post) {
        // error_log('[EAO Fluent Support] render_fluent_support_metabox called with post ID: ' . ($post ? $post->ID : 'none'));
        
        // Handle case where we might be on EAO page with order_id in GET (like v2.7.0)
        $order_id = null;
        if ($post && isset($post->ID)) {
            $order_id = $post->ID;
        } elseif (isset($_GET['order_id'])) {
            $order_id = absint($_GET['order_id']);
        }
        
        // error_log('[EAO Fluent Support] Using order ID: ' . $order_id);
        
        if (!$order_id) {
            echo '<p>No order context found.</p>';
            return;
        }
        
        // Get order object
        $order = wc_get_order($order_id);
        if (!$order) {
            echo '<p>Invalid order ID.</p>';
            return;
        }
        
        // Get customer email
        $customer_email = $order->get_billing_email();
        $customer_name = trim($order->get_formatted_billing_full_name());
        
        if (empty($customer_name)) {
            $customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
        }
        
        if (empty($customer_name)) {
            $customer_name = 'Customer';
        }
        
        echo '<div id="eao-fluent-support-container">';
        echo '<div id="eao-fs-create-ticket-section">';
        echo '<h4>Create New Ticket</h4>';
        echo '<div class="eao-fs-form-group">';
        echo '<label for="eao-fs-ticket-subject">Subject:</label>';
        echo '<input type="text" id="eao-fs-ticket-subject" class="widefat" />';
        echo '</div>';
        echo '<div class="eao-fs-form-group">';
        echo '<label for="eao-fs-ticket-content">Content:</label>';
        // Pre-fill rich content with a WYSIWYG editor (standard WordPress approach)
        $tracking_url = $this->get_order_tracking_url($order);
        $prefill = sprintf('Dear %s,<br><br><br>', esc_html($customer_name));
        if (!empty($tracking_url)) {
            $prefill .= sprintf('To track your order click <a href="%s" target="_blank" rel="noopener noreferrer">here</a>', esc_url($tracking_url));
        }
        if (function_exists('wp_editor')) {
            // Ensure editor assets are available and force rich editor even if user preference disables it (live site variance)
            if (function_exists('wp_enqueue_editor')) { wp_enqueue_editor(); }
            add_filter('user_can_richedit', '__return_true', 50);
            // Force-load core editor dependencies if TinyMCE didn't boot automatically
            wp_enqueue_script('editor');
            wp_enqueue_script('quicktags');
            wp_enqueue_style('editor-buttons');
            wp_editor(
                $prefill,
                'eao-fs-ticket-content',
                array(
                    'textarea_name' => 'eao_fs_ticket_content',
                    'media_buttons' => false,
                    'teeny' => true,
                    'quicktags' => true,
                    'tinymce' => true,
                    'editor_height' => 180
                )
            );
            remove_filter('user_can_richedit', '__return_true', 50);
            // Force default to Visual mode
            echo '<script>jQuery(function(){ try { if (window.switchEditors) { switchEditors.go("eao-fs-ticket-content","tmce"); } if (window.tinyMCE && !tinyMCE.get("eao-fs-ticket-content")) { setTimeout(function(){ try { if (window.switchEditors) { switchEditors.go("eao-fs-ticket-content","tmce"); } } catch(e){} }, 300); } } catch(e){} });</script>';
        } else {
            echo '<textarea id="eao-fs-ticket-content" class="widefat" rows="6">' . $prefill . '</textarea>';
        }
        echo '</div>';
        echo '<div class="eao-fs-form-group">';
        echo '<label for="eao-fs-ticket-priority">Priority:</label>';
        echo '<select id="eao-fs-ticket-priority" class="widefat">';
        echo '<option value="normal">Normal</option>';
        echo '<option value="medium">Medium</option>';
        echo '<option value="high">High</option>';
        echo '<option value="critical">Critical</option>';
        echo '</select>';
        echo '</div>';
        echo '<button type="button" id="eao-fs-create-ticket-btn" class="button button-primary" data-order-id="' . esc_attr($order_id) . '">Create Ticket</button>';
        echo '<span id="eao-fs-create-loading" class="spinner" style="display: none;"></span>';
        echo '<div id="eao-fs-create-result" style="display: none;"></div>';
        echo '</div>';
        
        // Get customer email for ticket loading
        $customer_email = '';
        if ($order_id) {
            $order = wc_get_order($order_id);
            if ($order) {
                $customer_email = $order->get_billing_email();
            }
        }
        
        echo '<div id="eao-fs-existing-tickets-section">';
        echo '<h4>Customer Tickets ';
        echo '<button type="button" id="eao-fs-refresh-tickets" class="button button-small" data-customer-email="' . esc_attr($customer_email) . '">Refresh</button>';
        echo '</h4>';
        echo '<div id="eao-fs-tickets-loading" style="display:none;">Loading tickets...</div>';
        echo '<div id="eao-fs-tickets-list">Loading tickets...</div>';
        echo '</div>';
        
        echo '</div>';
        
        // Add additional data for JavaScript (supplements wp_localize_script from main plugin)
        echo '<script>';
        echo 'window.eaoFluentSupport = window.eaoFluentSupport || {};';
        echo 'window.eaoFluentSupport.orderId = ' . json_encode($order_id) . ';';
        echo 'window.eaoFluentSupport.customerEmail = ' . json_encode($customer_email) . ';';
        echo 'window.eaoFluentSupport.customerName = ' . json_encode($customer_name) . ';';
        echo 'if (!window.eaoFluentSupport.ajaxUrl) window.eaoFluentSupport.ajaxUrl = ' . json_encode(admin_url('admin-ajax.php')) . ';';
        echo 'if (!window.eaoFluentSupport.nonce) window.eaoFluentSupport.nonce = ' . json_encode(wp_create_nonce('eao_fluent_support_nonce')) . ';';
        echo '</script>';
        
        // Load existing tickets via AJAX after page load
        echo '<script>';
        echo 'jQuery(document).ready(function($) {';
        echo '    console.log("[EAO Fluent Support] Inline script running. EAO object:", typeof window.EAO);';
        echo '    if (typeof window.EAO !== "undefined") {';
        echo '        console.log("[EAO Fluent Support] EAO exists. FluentSupport:", typeof window.EAO.FluentSupport);';
        echo '        if (typeof window.EAO.FluentSupport !== "undefined") {';
        echo '            console.log("[EAO Fluent Support] Calling autoLoadTickets from PHP");';
        echo '            window.EAO.FluentSupport.autoLoadTickets();';
        echo '        } else {';
        echo '            console.error("[EAO Fluent Support] EAO.FluentSupport not found, retrying in 1 second...");';
        echo '            setTimeout(function() {';
        echo '                if (typeof window.EAO !== "undefined" && typeof window.EAO.FluentSupport !== "undefined") {';
        echo '                    console.log("[EAO Fluent Support] Retry successful - calling autoLoadTickets");';
        echo '                    window.EAO.FluentSupport.autoLoadTickets();';
        echo '                } else {';
        echo '                    console.error("[EAO Fluent Support] Retry failed - EAO.FluentSupport still not available");';
        echo '                }';
        echo '            }, 1000);';
        echo '        }';
        echo '    } else {';
        echo '        console.error("[EAO Fluent Support] EAO object not found at all");';
        echo '    }';
        echo '});';
        echo '</script>';
        
        // Add CSS styling for ticket display
        echo '<style>';
        echo '.eao-fs-ticket-item {';
        echo '    padding: 12px;';
        echo '    border: 1px solid #ddd;';
        echo '    border-radius: 4px;';
        echo '    margin-bottom: 10px;';
        echo '    background: #fff;';
        echo '    transition: box-shadow 0.2s ease;';
        echo '}';
        echo '.eao-fs-ticket-item:hover {';
        echo '    box-shadow: 0 2px 5px rgba(0,0,0,0.1);';
        echo '}';
        echo '.eao-fs-ticket-item h5 {';
        echo '    margin: 0 0 8px 0;';
        echo '    font-size: 14px;';
        echo '    line-height: 1.4;';
        echo '}';
        echo '.eao-fs-ticket-item h5 a {';
        echo '    text-decoration: none;';
        echo '    color: #0073aa;';
        echo '}';
        echo '.eao-fs-ticket-item h5 a:hover {';
        echo '    color: #005177;';
        echo '    text-decoration: underline;';
        echo '}';
        echo '.eao-fs-ticket-meta {';
        echo '    font-size: 11px;';
        echo '    color: #666;';
        echo '}';
        echo '.eao-fs-ticket-status {';
        echo '    display: inline-block;';
        echo '    padding: 2px 6px;';
        echo '    border-radius: 3px;';
        echo '    font-size: 10px;';
        echo '    text-transform: uppercase;';
        echo '    font-weight: bold;';
        echo '}';
        echo '.eao-fs-ticket-status.new { background: #e3f2fd; color: #1976d2; }';
        echo '.eao-fs-ticket-status.active { background: #fff3e0; color: #f57c00; }';
        echo '.eao-fs-ticket-status.waiting { background: #fff8e1; color: #f9a825; }';
        echo '.eao-fs-ticket-status.closed { background: #e8f5e8; color: #388e3c; }';
        echo '.eao-fs-loading {';
        echo '    text-align: center;';
        echo '    padding: 20px;';
        echo '    color: #666;';
        echo '}';
        echo '.eao-fs-no-tickets {';
        echo '    text-align: center;';
        echo '    color: #666;';
        echo '    font-style: italic;';
        echo '    padding: 20px;';
        echo '}';
        echo '</style>';
    }
    
    /**
     * Enqueue admin scripts for Fluent Support integration
     */
    public function enqueue_admin_scripts($hook) {
        // error_log('[EAO Fluent Support] enqueue_admin_scripts called - Hook: ' . $hook);
        
        if (!$this->fluent_support_available) {
            // error_log('[EAO Fluent Support] Fluent Support not available, skipping script enqueue');
            return;
        }
        
        // Check if we're on a relevant admin page
        $is_order_page = false;
        
        // Check for standard WooCommerce order pages
        if (strpos($hook, 'post.php') !== false || strpos($hook, 'post-new.php') !== false) {
            global $post;
            if ($post && $post->post_type === 'shop_order') {
            $is_order_page = true;
                // error_log('[EAO Fluent Support] Standard WC order page detected');
            }
        }
        
        // Check for Enhanced Admin Order page
        if ($hook === 'toplevel_page_eao_custom_order_editor_page') {
            $is_order_page = true;
            // error_log('[EAO Fluent Support] Enhanced Admin Order page detected');
        }
        
        if (!$is_order_page) {
            // error_log('[EAO Fluent Support] Not an order page, skipping script enqueue');
            return;
        }
        
        // error_log('[EAO Fluent Support] Script enqueuing handled by main plugin file - no action needed');
    }
    
    /**
     * AJAX handler for creating tickets
     */
    public function ajax_create_ticket() {
        // CRITICAL: Clean output buffer to prevent HTML before JSON response
        if (ob_get_level()) {
            ob_clean();
        }
        
        // Start fresh output buffer
        ob_start();
        
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'eao_fluent_support_nonce')) {
            ob_end_clean();
            wp_send_json_error(array('message' => 'Nonce verification failed.'));
            return;
        }
        
        // Check capabilities
        if (!current_user_can('edit_shop_orders')) {
            ob_end_clean();
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
            return;
        }
        
        if (!$this->fluent_support_available) {
            ob_end_clean();
            wp_send_json_error(array('message' => 'Fluent Support not available.'));
            return;
        }
        
        try {
            $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
            $subject = isset($_POST['subject']) ? sanitize_text_field(wp_unslash($_POST['subject'])) : '';
            // Preserve WYSIWYG formatting from editor while keeping it safe
            $content = isset($_POST['content']) ? wp_kses_post(wp_unslash($_POST['content'])) : '';
            // Preserve WYSIWYG formatting from editor while keeping it safe
            $content = isset($_POST['content']) ? wp_kses_post(wp_unslash($_POST['content'])) : '';
            $priority = isset($_POST['priority']) ? sanitize_text_field(wp_unslash($_POST['priority'])) : 'normal';
            
            if (!$order_id || !$subject || !$content) {
                ob_end_clean();
                wp_send_json_error(array('message' => 'Missing required fields.'));
                return;
            }
            
            $result = $this->create_ticket_for_order($order_id, $subject, $content, $priority);
            
            if (is_wp_error($result)) {
                ob_end_clean();
                wp_send_json_error(array('message' => $result->get_error_message()));
                return;
            }
            
            // Clean buffer before success response
            ob_end_clean();
            wp_send_json_success(array(
                'message' => 'Ticket created successfully!',
                'ticket_id' => $result['ticket_id'],
                'ticket_url' => $result['ticket_url']
            ));
            
        } catch (Exception $e) {
            eao_log_save_operation('Fluent Support ticket creation error', $e->getMessage());
            ob_end_clean();
            wp_send_json_error(array('message' => 'An error occurred while creating the ticket.'));
        }
    }
    
    /**
     * AJAX handler for getting customer tickets
     */
    public function ajax_get_customer_tickets() {
        // CRITICAL: Clean output buffer to prevent HTML before JSON response
        if (ob_get_level()) {
            ob_clean();
        }
        
        // Start fresh output buffer
        ob_start();
        
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'eao_fluent_support_nonce')) {
            ob_end_clean();
            wp_send_json_error(array('message' => 'Nonce verification failed.'));
            return;
        }
        
        // Check capabilities
        if (!current_user_can('edit_shop_orders')) {
            ob_end_clean();
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
            return;
        }
        
        if (!$this->fluent_support_available) {
            ob_end_clean();
            wp_send_json_error(array('message' => 'Fluent Support not available.'));
            return;
        }
        
        try {
            $customer_email = isset($_POST['customer_email']) ? sanitize_email(wp_unslash($_POST['customer_email'])) : '';
            $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
            
            if (!$customer_email) {
                ob_end_clean();
                wp_send_json_error(array('message' => 'Customer email required.'));
                return;
            }
            
            $tickets = $this->get_customer_tickets($customer_email, $order_id);
            
            // Clean buffer before success response
            ob_end_clean();
            wp_send_json_success(array(
                'tickets' => $tickets,
                'count' => count($tickets)
            ));
            
        } catch (Exception $e) {
            eao_log_save_operation('Fluent Support get tickets error', $e->getMessage());
            ob_end_clean();
            wp_send_json_error(array('message' => 'An error occurred while fetching tickets.'));
        }
    }
    
    /**
     * AJAX handler for getting customer email by ID (for real-time ticket updates)
     */
    public function ajax_get_customer_email_by_id() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'eao_fluent_support_nonce')) {
            wp_send_json_error(array('message' => 'Nonce verification failed.'));
            return;
        }
        
        // Clean output buffer
        if (ob_get_level()) {
            ob_clean();
        }
        
        try {
            $customer_id = isset($_POST['customer_id']) ? absint($_POST['customer_id']) : 0;
            
            if (!$customer_id) {
                ob_end_clean();
                wp_send_json_error(array('message' => 'Invalid customer ID.'));
                return;
            }
            
            // Get customer email
            $user = get_user_by('ID', $customer_id);
            if (!$user) {
                ob_end_clean();
                wp_send_json_error(array('message' => 'Customer not found.'));
                return;
            }
            
            ob_end_clean();
            wp_send_json_success(array(
                'email' => $user->user_email,
                'customer_id' => $customer_id
            ));
            
        } catch (Exception $e) {
            eao_log_save_operation('Fluent Support get customer email error', $e->getMessage());
            ob_end_clean();
            wp_send_json_error(array('message' => 'An error occurred while fetching customer email.'));
        }
    }
    
    /**
     * AJAX handler for getting integration status
     */
    public function ajax_get_status() {
        // CRITICAL: Clean output buffer to prevent HTML before JSON response
        if (ob_get_level()) {
            ob_clean();
        }
        
        // Start fresh output buffer
        ob_start();
        
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'eao_fluent_support_nonce')) {
            ob_end_clean();
            wp_send_json_error(array('message' => 'Nonce verification failed.'));
            return;
        }
        
        // Clean buffer before success response
        ob_end_clean();
        wp_send_json_success(array(
            'available' => $this->fluent_support_available,
            'version' => $this->fluent_support_version,
            'status' => $this->fluent_support_available ? 'connected' : 'not_available'
        ));
    }

    /**
     * AJAX handler for final cleanup trigger
     */
    public function ajax_final_cleanup_trigger() {
        // Clean any output buffers to prevent HTML before JSON
        if (ob_get_level()) {
            ob_clean();
        }
        
        $ticket_id = isset($_POST['ticket_id']) ? absint($_POST['ticket_id']) : 0;
        $agent_id = isset($_POST['agent_id']) ? absint($_POST['agent_id']) : 0;
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        
        // Verify nonce
        if (!wp_verify_nonce($nonce, 'eao_final_cleanup_' . $ticket_id)) {
            wp_send_json_error(array('message' => 'Security check failed.'));
            return;
        }
        
        if (!$ticket_id || !$agent_id) {
            wp_send_json_error(array('message' => 'Invalid parameters.'));
            return;
        }
        
        // Wait a moment to let any database triggers complete
        sleep(2);
        
        // Run the final cleanup
        $this->final_conversation_cleanup($ticket_id, $agent_id);
        
        wp_send_json_success(array('message' => 'Cleanup completed.'));
    }
    
    /**
     * Create a ticket for a specific order using proper Fluent Support APIs
     */
    private function create_ticket_for_order($order_id, $subject, $content, $priority = 'normal') {
        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('invalid_order', 'Order not found.');
        }
        
        // Get or create customer in Fluent Support
        $customer_email = $order->get_billing_email();
        $customer_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        
        $fs_customer = $this->get_or_create_fluent_support_customer($customer_email, $customer_name, $order);
        if (is_wp_error($fs_customer)) {
            return $fs_customer;
        }
        
        // Use the content as provided by the agent without auto-appending order info
        $enhanced_content = $content;
        
        // Create ticket using proper Fluent Support API
        try {
            // Get current admin user as agent first
            $agent = \FluentSupport\App\Services\Helper::getAgentByUserId(get_current_user_id());
            if (!$agent) {
                // If current user is not an agent, try to get any agent
                $agent = \FluentSupport\App\Models\Agent::first();
            }
            
            // Build ticket data following Fluent Support's structure
            $ticket_data = array(
                'title' => $subject,
                'content' => $enhanced_content,
                'customer_id' => $fs_customer->id,
                'agent_id' => $agent ? $agent->id : null, // Assign agent immediately
                'priority' => $priority,
                'status' => 'new',
                'source' => 'web',
                'mailbox_id' => $this->get_default_mailbox_id(),
                'product_source' => 'woocommerce',
                'privacy' => 'private'
            );
            
            // Create ticket with customer association but as agent-initiated
            $ticket = \FluentSupport\App\Models\Ticket::create([
                'title' => $subject,
                'content' => '', // Explicitly set empty content to prevent default conversation
                'customer_id' => $fs_customer->id, // Restore customer association for proper functionality
                'agent_id' => $agent ? $agent->id : null,
                'priority' => $priority,
                'status' => 'active', // Start as active since admin is responding
                'source' => 'agent', // Set as agent source to indicate agent initiation
                'mailbox_id' => $this->get_default_mailbox_id(),
                'product_source' => 'woocommerce',
                'privacy' => 'private',
                'hash' => wp_generate_uuid4(),
                'last_agent_response' => current_time('mysql'),
                'response_count' => 1,
                'created_by' => get_current_user_id(), // Mark as created by current admin user
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ]);
            
            if (!$ticket) {
                throw new Exception('Ticket creation failed');
            }
            
            // Create agent conversation as initial message (not response) to make agent the conversation starter
            $conversation = \FluentSupport\App\Models\Conversation::create([
                'ticket_id' => $ticket->id,
                'person_id' => $agent->id,
                'conversation_type' => 'initial', // Changed from 'response' to 'initial' to make agent the starter
                'content' => $enhanced_content,
                'source' => 'agent', // Changed to agent source for consistency
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ]);
            
            if (!$conversation) {
                throw new Exception('Agent conversation creation failed');
            }
            
            // Customer is now properly associated via customer_id field
            // Additional Data will be populated by add_order_to_additional_data method
            
            eao_log_save_operation('Created ticket with single agent conversation', "Ticket: {$ticket->id}, Conversation: {$conversation->id}");
            
            // Clean up any unwanted conversations (remove empty customer messages)
            $this->cleanup_unwanted_conversations($ticket, $agent);
            
            // Run final cleanup immediately after a short delay using wp_remote_post to trigger it
            wp_remote_post(admin_url('admin-ajax.php'), array(
                'timeout' => 1,
                'blocking' => false,
                'body' => array(
                    'action' => 'eao_final_cleanup_trigger',
                    'ticket_id' => $ticket->id,
                    'agent_id' => $agent->id,
                    'nonce' => wp_create_nonce('eao_final_cleanup_' . $ticket->id)
                )
            ));
            
            // Add order reference as ticket meta and Additional Data
            $this->add_order_reference_to_ticket($ticket, $order);
            $this->add_order_to_additional_data($ticket, $order);
            
            eao_log_save_operation('Fluent Support ticket created', 
                sprintf('Ticket ID: %d, Order: #%s, Customer: %s', 
                    $ticket->id, $order->get_order_number(), $customer_email));
            
            return array(
                'ticket_id' => $ticket->id,
                'ticket_url' => $this->get_ticket_admin_url($ticket->id),
                'ticket_hash' => $ticket->hash
            );
            
        } catch (Exception $e) {
            eao_log_save_operation('Fluent Support ticket creation failed', $e->getMessage());
            return new WP_Error('ticket_creation_failed', $e->getMessage());
        }
    }
    
    /**
     * Get or create Fluent Support customer using proper API
     */
    private function get_or_create_fluent_support_customer($email, $name, $order) {
        try {
            // Prepare customer data for Fluent Support's maybeCreateCustomer method
            $name_parts = explode(' ', trim($name), 2);
            $customer_data = array(
                'email' => $email,
                'first_name' => $name_parts[0] ?: '',
                'last_name' => isset($name_parts[1]) ? $name_parts[1] : ''
            );
            
            // Add WordPress user ID if customer has an account
            $wp_user = get_user_by('email', $email);
            if ($wp_user) {
                $customer_data['user_id'] = $wp_user->ID;
            }
            
            // Add additional data from order if available
            if ($order) {
                $customer_data['phone'] = $order->get_billing_phone();
                $customer_data['address_line_1'] = $order->get_billing_address_1();
                $customer_data['address_line_2'] = $order->get_billing_address_2();
                $customer_data['city'] = $order->get_billing_city();
                $customer_data['state'] = $order->get_billing_state();
                $customer_data['zip'] = $order->get_billing_postcode();
                $customer_data['country'] = $order->get_billing_country();
                $customer_data['last_ip_address'] = \FluentSupport\App\Services\Helper::getIp();
            }
            
            // Use Fluent Support's maybeCreateCustomer method (creates or updates existing)
            $customer = \FluentSupport\App\Models\Customer::maybeCreateCustomer($customer_data);
            
            if (!$customer) {
                throw new Exception('Customer creation/retrieval failed');
            }
            
            return $customer;
            
        } catch (Exception $e) {
            eao_log_save_operation('Fluent Support customer creation failed', $e->getMessage());
            return new WP_Error('customer_creation_failed', $e->getMessage());
        }
    }
    
    // build_ticket_content removed as we no longer auto-append order information to message body

    /**
     * Build TrackShip tracking URL for this order
     * @param WC_Order $order
     * @return string URL
     */
    private function get_order_tracking_url($order) {
        // Only build TrackShip URL when tracking number exists; otherwise return empty string
        $tracking_number = $order->get_meta('_ppcp_tracking_number');
        if (!empty($tracking_number)) {
            $base = home_url('/ts-shipment-tracking/');
            return esc_url(add_query_arg('tracking', rawurlencode($tracking_number), $base));
        }
        return '';
    }
    
    /**
     * Get order items summary for ticket content
     */
    private function get_order_items_summary($order) {
        $items = array();
        foreach ($order->get_items() as $item) {
            $items[] = sprintf('- %s (x%d)', $item->get_name(), $item->get_quantity());
        }
        return implode("\n", $items);
    }
    
    /**
     * Clean up any unwanted conversations (remove empty customer messages)
     */
    private function cleanup_unwanted_conversations($ticket, $agent) {
        try {
            // Delete any conversations that are not from the agent
            $deleted_count = \FluentSupport\App\Models\Conversation::where('ticket_id', $ticket->id)
                ->where('person_id', '!=', $agent->id)
                ->delete();
                
            if ($deleted_count > 0) {
                eao_log_save_operation('Cleaned up unwanted conversations', "Deleted: {$deleted_count} customer conversations");
            }
            
            // Also delete any empty conversations
            $empty_deleted = \FluentSupport\App\Models\Conversation::where('ticket_id', $ticket->id)
                ->where(function($query) {
                    $query->whereNull('content')
                          ->orWhere('content', '')
                          ->orWhere('content', 'like', '%started the conversation%');
                })
                ->delete();
                
            if ($empty_deleted > 0) {
                eao_log_save_operation('Cleaned up empty conversations', "Deleted: {$empty_deleted} empty conversations");
            }
            
        } catch (Exception $e) {
            eao_log_save_operation('Failed to cleanup conversations', $e->getMessage());
        }
    }

    /**
     * Final conversation cleanup (called by scheduled action)
     */
    public function final_conversation_cleanup($ticket_id, $agent_id) {
        try {
            $ticket = \FluentSupport\App\Models\Ticket::find($ticket_id);
            $agent = \FluentSupport\App\Models\Agent::find($agent_id);
            
            if (!$ticket || !$agent) {
                return;
            }
            
            // Get all conversations for debugging
            $all_conversations = \FluentSupport\App\Models\Conversation::where('ticket_id', $ticket->id)->get();
            
            // Clean up any unwanted conversations
            $deleted_count = 0;
            $all_conversations = \FluentSupport\App\Models\Conversation::where('ticket_id', $ticket->id)->get();
            
            foreach ($all_conversations as $conversation) {
                // Delete ANY conversation that is not from our agent OR has empty/null content OR contains "started" text
                if ($conversation->person_id != $agent->id || 
                    (!in_array($conversation->conversation_type, ['response', 'initial'])) ||
                    empty(trim($conversation->content)) ||
                    stripos($conversation->content ?? '', 'started the conversation') !== false) {
                    
                    $conversation->delete();
                    $deleted_count++;
                }
            }
            
            if ($deleted_count > 0) {
                eao_log_save_operation('Final cleanup completed', "Deleted {$deleted_count} unwanted conversations");
            }
            
        } catch (Exception $e) {
            eao_log_save_operation('Failed final conversation cleanup', $e->getMessage());
        }
    }

    // Conversation replacement function removed - now creating tickets with single agent message from start

    /**
     * Add order reference to ticket
     */
    private function add_order_reference_to_ticket($ticket, $order) {
        try {
            // Add as ticket meta for tracking using proper Meta model
            \FluentSupport\App\Models\Meta::updateOrCreate([
                'object_type' => 'ticket_meta',
                'object_id' => $ticket->id,
                'key' => 'wc_order_id'
            ], ['value' => $order->get_id()]);
            
            \FluentSupport\App\Models\Meta::updateOrCreate([
                'object_type' => 'ticket_meta',
                'object_id' => $ticket->id,
                'key' => 'wc_order_number'
            ], ['value' => $order->get_order_number()]);
            
        } catch (Exception $e) {
            eao_log_save_operation('Failed to add order reference to ticket', $e->getMessage());
        }
    }

    /**
     * Add order data to Additional Data section using Fluent Support Pro custom fields
     */
    private function add_order_to_additional_data($ticket, $order) {
        try {
            // Get configured custom fields for tickets
            $ticket_custom_fields = apply_filters('fluent_support/ticket_custom_fields', []);
            
            if (empty($ticket_custom_fields)) {
                eao_log_save_operation('No custom fields configured for Additional Data', 'Skipping automatic population');
                return;
            }
            
            // Prepare order data for configured custom fields
            $custom_data = array();
            
            // Map order information to available custom fields
            foreach ($ticket_custom_fields as $field_slug => $field_config) {
                $field_type = isset($field_config['type']) ? $field_config['type'] : '';
                
                // For WooCommerce order field types, set the current order
                if ($field_type === 'woo_orders') {
                    $custom_data[$field_slug] = strval($order->get_id());
                    eao_log_save_operation('Added WooCommerce order to custom field', "Field: {$field_slug}, Order ID: {$order->get_id()}");
                }
                
                // Product field functionality commented out due to rendering complexity
                // Only order reference is populated for now
                /*
                else if ($field_type === 'woo_products') {
                    $product_ids = array();
                    foreach ($order->get_items() as $item) {
                        $product_id = $item->get_product_id();
                        if ($product_id) {
                            $product_ids[] = strval($product_id);
                        }
                    }
                    if (!empty($product_ids)) {
                        $custom_data[$field_slug] = $product_ids; // Array for multiple selection
                        eao_log_save_operation('Added WooCommerce products to custom field', "Field: {$field_slug}, Products: " . implode(', ', $product_ids));
                    }
                }
                */
                
                // For other text-based fields that might be order-related
                else if (stripos($field_slug, 'order') !== false || stripos($field_slug, 'wc_') !== false) {
                    // Auto-populate order-related text fields with order number
                    $custom_data[$field_slug] = sprintf('#%s', $order->get_order_number());
                    eao_log_save_operation('Added order number to text field', "Field: {$field_slug}, Value: #{$order->get_order_number()}");
                }
            }
            
            // Sync the custom fields data using Fluent Support's built-in method
            if (!empty($custom_data)) {
                $ticket->syncCustomFields($custom_data);
                eao_log_save_operation('Successfully synced custom fields', 'Fields: ' . implode(', ', array_keys($custom_data)));
            } else {
                eao_log_save_operation('No matching custom fields found for order data', 'Available fields: ' . implode(', ', array_keys($ticket_custom_fields)));
            }
            
        } catch (Exception $e) {
            eao_log_save_operation('Failed to add order to additional data', $e->getMessage());
        }
    }


    
    /**
     * Set current order as selected in existing Fluent Support Pro WooCommerce integration
     * This method is now deprecated in favor of the unified add_order_to_additional_data method
     */
    private function set_current_order_selection($ticket, $order) {
        // This method is now handled by add_order_to_additional_data
        // Keep this stub for backward compatibility
        eao_log_save_operation('set_current_order_selection called', 'Delegating to add_order_to_additional_data method');
    }

    /**
     * Get customer tickets
     */
    private function get_customer_tickets($customer_email, $order_id = 0) {
        try {
            // Find customer
            $customer = \FluentSupport\App\Models\Customer::where('email', $customer_email)->first();
            if (!$customer) {
                return array();
            }
            
            // Get tickets for customer
            $tickets_query = \FluentSupport\App\Models\Ticket::where('customer_id', $customer->id)
                ->orderBy('created_at', 'desc')
                ->limit(10);
            
            $tickets = $tickets_query->get();
            
            $formatted_tickets = array();
            foreach ($tickets as $ticket) {
                $formatted_tickets[] = array(
                    'id' => $ticket->id,
                    'title' => $ticket->title,
                    'status' => $ticket->status,
                    'priority' => $ticket->priority,
                    'created_at' => $ticket->created_at,
                    'updated_at' => $ticket->updated_at,
                    'url' => $this->get_ticket_admin_url($ticket->id),
                    'is_order_related' => $this->is_ticket_order_related($ticket, $order_id)
                );
            }
            
            return $formatted_tickets;
            
        } catch (Exception $e) {
            eao_log_save_operation('Failed to get customer tickets', $e->getMessage());
            return array();
        }
    }
    
    /**
     * Check if ticket is related to specific order
     */
    private function is_ticket_order_related($ticket, $order_id) {
        if (!$order_id) {
            return false;
        }
        
        try {
            $ticket_order_id = $ticket->getMeta('wc_order_id');
            return $ticket_order_id == $order_id;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Get default mailbox ID using Fluent Support Helper
     */
    private function get_default_mailbox_id() {
        try {
            // Use Fluent Support's Helper to get default mailbox
            $mailbox = \FluentSupport\App\Services\Helper::getDefaultMailBox();
            if ($mailbox) {
                return $mailbox->id;
            }
            
            // Fallback to first web-type mailbox
            $mailbox = \FluentSupport\App\Models\MailBox::where('box_type', 'web')->first();
            if ($mailbox) {
                return $mailbox->id;
            }
            
            // Final fallback to any mailbox
            $mailbox = \FluentSupport\App\Models\MailBox::first();
            return $mailbox ? $mailbox->id : 1;
            
        } catch (Exception $e) {
            eao_log_save_operation('Failed to get default mailbox', $e->getMessage());
            return 1; // Default fallback
        }
    }
    
    /**
     * Get ticket admin URL with /view suffix
     */
    private function get_ticket_admin_url($ticket_id) {
        return admin_url('admin.php?page=fluent-support#/tickets/' . $ticket_id . '/view');
    }
    
    /**
     * Get Fluent Support version
     */
    public function get_version() {
        return $this->fluent_support_version;
    }
    
    /**
     * Debug method to check if custom field types are available
     */
    public function debug_custom_field_types() {
        // Debug functionality removed for production
        return;
    }

    /**
     * Register field overrides with high priority to ensure they take precedence
     */
    public function register_field_overrides() {
        // Add our overrides with very high priority
        add_filter('fluent_support/custom_field_render_woo_orders', array($this, 'override_order_link'), PHP_INT_MAX, 2);
        add_filter('fluent_support/custom_field_render_woo_products', array($this, 'override_product_link'), PHP_INT_MAX, 1);
    }

    /**
     * Late registration to forcibly remove existing filters and replace them
     */
    public function register_field_overrides_late() {
        global $wp_filter;
        
        // Forcibly remove all existing filters for these hooks
        if (isset($wp_filter['fluent_support/custom_field_render_woo_orders'])) {
            unset($wp_filter['fluent_support/custom_field_render_woo_orders']);
        }
        if (isset($wp_filter['fluent_support/custom_field_render_woo_products'])) {
            unset($wp_filter['fluent_support/custom_field_render_woo_products']);
        }
        
        // Re-add only our filters (order link override only - product field disabled)
        add_filter('fluent_support/custom_field_render_woo_orders', array($this, 'override_order_link'), 10, 2);
    }

    /**
     * Override Fluent Support Pro WooCommerce order links to point to Enhanced Admin Order editor
     */
    public function override_order_link($value, $scope) {
        if (!is_numeric($value)) {
            return $value;
        }

        $orderId = absint($value);

        if ($scope == 'admin') {
            // Use Enhanced Admin Order editor instead of native WooCommerce admin
            $eao_url = admin_url('admin.php?page=eao_custom_order_editor_page&order_id=' . $orderId);
            return '<a target="_blank" rel="nofollow" href="' . $eao_url . '">' . sprintf(__('Order #%d', 'fluent-support-pro'), $orderId) . '</a>';
        }

        // For non-admin scope (customer portal), use the original WooCommerce customer view
        $order = wc_get_order($orderId);
        if (!$order) {
            return 'Order #' . $orderId;
        }

        $url = $order->get_view_order_url();
        return '<a target="_blank" rel="nofollow" href="' . $url . '">' . sprintf(__('Order #%d', 'fluent-support-pro'), $orderId) . '</a>';
    }

    // Debug functions removed for production

    // Product link override removed - functionality disabled for production
}

// Initialize the integration
function eao_fluent_support_integration() {
    error_log('[EAO Fluent Support] eao_fluent_support_integration function called');
    return EAO_Fluent_Support_Integration::get_instance();
}

// Hook into WordPress
add_action('plugins_loaded', 'eao_fluent_support_integration', 20);

// Test admin notice removed for production

// Add debug info to admin (temporarily disabled to prevent errors)
// add_action('admin_notices', function() {
//     if (isset($_GET['page']) && $_GET['page'] === 'eao_custom_order_editor_page' && isset($_GET['order_id'])) {
//         $integration = EAO_Fluent_Support_Integration::get_instance();
//         if (!$integration->is_available()) {
//             echo '<div class="notice notice-warning"><p><strong>EAO Fluent Support Debug:</strong> Fluent Support integration not available. Check error logs for details.</p></div>';
//         } else {
//             echo '<div class="notice notice-info"><p><strong>EAO Fluent Support Debug:</strong> Integration available (v' . $integration->get_version() . ')</p></div>';
//         }
//     }
// }); 