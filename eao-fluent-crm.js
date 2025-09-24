/**
 * Enhanced Admin Order Plugin - FluentCRM Integration Module
 * Handles real-time FluentCRM profile updates when customer changes
 * 
 * @package EnhancedAdminOrder
 * @since 2.9.55
 * @version 2.9.56 - Fix nonce to use eao_editor_nonce and ensure refresh reliability
 * @author Amnon Manneberg
 */

(function($) {
    'use strict';
    
    // Initialize EAO namespace
    window.EAO = window.EAO || {};
    
    /**
     * FluentCRM Integration Module
     */
    window.EAO.FluentCRM = {
        
        /**
         * Initialize the FluentCRM module
         */
        init: function() {
            console.log('[EAO FluentCRM] Module initialized');
            // No event binding needed - updates are triggered externally by customer module
        },
        
        /**
         * Update customer profile when customer changes (real-time, mirrors address pattern)
         */
        updateCustomerProfile: function(newCustomerId) {
            console.log('[EAO FluentCRM] Updating profile for customer ID:', newCustomerId, 'Type:', typeof newCustomerId);
            
            // Find FluentCRM metabox
            var $metaboxContainer = $('#fluentcrm_woo_order_widget_eao .inside');
            if ($metaboxContainer.length === 0) {
                console.warn('[EAO FluentCRM] FluentCRM metabox container not found. Available containers:', $('#fluentcrm_woo_order_widget_eao').length > 0 ? 'Found outer container' : 'No outer container');
                return;
            }
            
            // Normalize customer ID
            var customerId = newCustomerId;
            if (typeof customerId === 'string') {
                customerId = customerId === '' ? 0 : parseInt(customerId, 10);
            }
            if (isNaN(customerId)) {
                customerId = 0;
            }
            console.log('[EAO FluentCRM] Normalized customer ID:', customerId);
            
            // Show loading state
            $metaboxContainer.html('<div class="eao-fluentcrm-loading"><p>Loading customer profile...</p></div>');
            
            if (!customerId || customerId === 0) {
                // Guest customer - show appropriate message
                console.log('[EAO FluentCRM] Guest customer or no customer - showing no selection message');
                $metaboxContainer.html('<p>No customer selected</p>');
                return;
            }
            
            // Fetch new profile via AJAX
            var self = this;
            // Use the general editor nonce expected by the server handler (eao_editor_nonce)
            var selectedNonce = (window.eaoEditorParams && window.eaoEditorParams.nonce)
                ? window.eaoEditorParams.nonce
                : ((window.eao_ajax && (window.eao_ajax.refresh_notes_nonce || window.eao_ajax.save_order_nonce))
                    ? (window.eao_ajax.refresh_notes_nonce || window.eao_ajax.save_order_nonce)
                    : '');
            console.log('[EAO FluentCRM] Using editor nonce:', (selectedNonce || '').toString().substring(0, 8) + '..., src:', (window.eaoEditorParams && window.eaoEditorParams.nonce) ? 'eaoEditorParams.nonce' : 'fallback');
            $.ajax({
                url: window.eao_ajax && window.eao_ajax.ajax_url ? window.eao_ajax.ajax_url : ajaxurl, // Use EAO AJAX URL
                type: 'POST',
                data: {
                    action: 'eao_get_fluentcrm_profile',
                    customer_id: customerId,
                    nonce: selectedNonce
                },
                success: function(response) {
                    console.log('[EAO FluentCRM] AJAX Response:', response);
                    if (response.success && response.data && response.data.profile_html) {
                        console.log('[EAO FluentCRM] Got customer profile HTML, length: ' + response.data.profile_html.length);
                        $metaboxContainer.html(response.data.profile_html);
                    } else {
                        console.warn('[EAO FluentCRM] Could not get customer profile:', response);
                        var errorMsg = response.data && response.data.message ? response.data.message : 'Could not load customer profile';
                        $metaboxContainer.html('<p>' + errorMsg + '</p>');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[EAO FluentCRM] AJAX Error - Status:', status, 'Error:', error, 'Response:', xhr.responseText);
                    $metaboxContainer.html('<p>Error loading customer profile: ' + error + '</p>');
                }
            });
        }
    };
    
    // Auto-initialize when DOM is ready
    $(document).ready(function() {
        if ($('#fluentcrm_woo_order_widget_eao').length > 0) {
            window.EAO.FluentCRM.init();
        }
    });
    
})(jQuery);
