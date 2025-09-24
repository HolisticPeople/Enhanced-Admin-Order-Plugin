/**
 * Enhanced Admin Order - Core Utilities
 * Version: 2.8.6
 * Author: Amnon Manneberg
 * 
 * v2.8.6: Added WC staging fields calculation for real-time discount/quantity updates
 * v2.5.0: MAJOR MILESTONE - JavaScript refactor phase complete with stable core utilities
 * 
 * Foundation module containing pure utility functions with no dependencies.
 * This module provides shared utilities for all other EAO modules.
 */

// Ensure EAO namespace exists
window.EAO = window.EAO || {};

/**
 * Utility Functions Namespace
 */
window.EAO.Utils = {
    
    /**
     * Escape HTML attributes for safe output
     * @param {string} str - String to escape
     * @returns {string} - Escaped string
     */
    escapeAttribute: function(str) {
        if (typeof str !== 'string') {
            return '';
        }
        return str.replace(/"/g, '&quot;')
                  .replace(/'/g, '&#39;')
                  .replace(/</g, '&lt;')
                  .replace(/>/g, '&gt;')
                  .replace(/&/g, '&amp;');
    },

    /**
     * Format price according to WooCommerce settings
     * @param {number|string} price - Price to format
     * @returns {string} - Formatted price string
     */
    formatPrice: function(price) {
        const numPrice = parseFloat(price);
        if (isNaN(numPrice)) return '';

        const decimals = parseInt(eaoEditorParams.price_decimals || 2, 10);
        const decimalSep = eaoEditorParams.price_decimal_sep || '.';
        const thousandSep = eaoEditorParams.price_thousand_sep || ',';
        const symbol = eaoEditorParams.currency_symbol || '$';
        
        // Basic formatting
        let formattedPrice = numPrice.toFixed(decimals).replace('.', decimalSep);
        
        // Add thousands separator
        const parts = formattedPrice.split(decimalSep);
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thousandSep);
        formattedPrice = parts.join(decimalSep);
        
        // Add currency symbol based on position
        let currencyPos = eaoEditorParams.currency_pos || 'left';
        switch (currencyPos) {
            case 'left': return symbol + formattedPrice;
            case 'right': return formattedPrice + symbol;
            case 'left_space': return symbol + ' ' + formattedPrice;
            case 'right_space': return formattedPrice + ' ' + symbol;
            default: return symbol + formattedPrice;
        }
    },

    /**
     * Clean shipping title for display
     * @param {string} title - Raw shipping title
     * @returns {string} - Cleaned title
     */
    cleanShippingTitle: function(title) {
        if (!title || typeof title !== 'string') return 'Shipping'; // Default
        let cleaned = title;

        // Remove "ShipStation: provider_code - " prefix
        // e.g., "ShipStation: stamps_com - USPS Ground Advantage - Package" -> "USPS Ground Advantage - Package"
        cleaned = cleaned.replace(/^ShipStation: [a-zA-Z0-9_]+ - /i, '');

        // Remove "Carrier (Walleted) - " prefix or similar
        // e.g., "UPS (Walleted) - UPS Next Day Air" -> "UPS Next Day Air"
        cleaned = cleaned.replace(/^[a-zA-Z0-9_ ]+\([a-zA-Z0-9_ ]+\) - /i, '');

        // If " - Package" is at the end, remove it (case-insensitive)
        if (cleaned.toLowerCase().endsWith(' - package')) {
            cleaned = cleaned.substring(0, cleaned.length - ' - package'.length);
        }
        
        // If the title still contains "via " (often seen in WC formatted shipping), remove it and text before it.
        // e.g., "Flat rate via Some Carrier Service" -> "Some Carrier Service"
        const viaIndex = cleaned.toLowerCase().indexOf('via ');
        if (viaIndex !== -1) {
            cleaned = cleaned.substring(viaIndex + 'via '.length);
        }

        // Some titles might be "Carrier Name - Service Name", try to get "Service Name"
        // Or if after above cleanups, it's still complex.
        const parts = cleaned.split(' - ');
        if (parts.length > 1) {
            // Common pattern: "Provider - Service". Take "Service".
            // Avoid taking just a provider code if it's the last part and a more descriptive part exists before it.
            let lastPart = parts[parts.length - 1].trim();
            let firstPart = parts[0].trim();

            if (parts.length === 2 && (firstPart.toLowerCase() === 'ups' || firstPart.toLowerCase() === 'usps' || firstPart.toLowerCase() === 'fedex')) {
                // Handles "UPS - Ground", "USPS - Priority Mail" -> "Ground", "Priority Mail"
                cleaned = lastPart;
            } else if (parts.length > 1) {
                 // Fallback to last part if more than 2 parts or first part isn't a simple carrier
                cleaned = lastPart;
            }
            // If lastPart itself is a generic carrier name and there were more parts, this might need more refinement,
            // but for now, this simplification will handle many cases.
        }
        
        // Trim any leading/trailing colons or extra spaces
        cleaned = cleaned.replace(/^[:\s]+|[:\s]+$/g, '').trim();

        return cleaned || 'Shipping'; // Ensure not empty, fallback to 'Shipping'
    },

    /**
     * Format carrier name for display
     * @param {string} carrierCode - Carrier code to format
     * @returns {string} - Formatted carrier name
     */
    formatCarrierName: function(carrierCode) {
        // Simple mapping for display names, can be expanded
        const names = {
            fedex: 'FedEx',
            ups: 'UPS',
            ups_walleted: 'UPS (Walleted)',
            usps: 'USPS',
            // Add more as needed
        };
        if (carrierCode && names[carrierCode]) { // Check if carrierCode is truthy and exists in names
            return names[carrierCode];
        }
        if (typeof carrierCode === 'string' && carrierCode.length > 0) { // Check if it's a non-empty string
            return carrierCode.replace(/_/g, ' ').split(' ').map(word => word.charAt(0).toUpperCase() + word.slice(1)).join(' ');
        }
        return 'Unknown Carrier'; // Fallback for undefined, null, or empty string
    },

    /**
     * Calculate WooCommerce native field values based on admin configuration
     * This mirrors the backend logic in eao_apply_admin_config_to_wc_fields()
     * 
     * @param {Object} item - Order item object
     * @param {number} globalDiscountPercent - Global discount percentage
     * @returns {Object} - Object with WC field values {_line_subtotal, _line_total, effective_discount}
     */
    calculateWCFieldsFromAdminConfig: function(item, globalDiscountPercent) {
        if (!item || !item.price_raw) {
            return { _line_subtotal: 0, _line_total: 0, effective_discount: 0 };
        }
        
        const quantity = parseInt(item.quantity || 1);
        const productPrice = parseFloat(item.price_raw || 0);
        
        // Determine effective discount based on admin configuration
        let effectiveDiscountPercent = 0;
        const excludeGlobal = item.exclude_gd || false;

        // Special handling: markup mode for excluded items with fixed price
        if (excludeGlobal && item && item.is_markup && typeof item.discounted_price_fixed !== 'undefined') {
            const lineSubtotalM = productPrice * quantity;
            const fixedUnit = Math.max(0, parseFloat(item.discounted_price_fixed) || 0);
            const lineTotalM = fixedUnit * quantity;
            return {
                _line_subtotal: lineSubtotalM,
                _line_total: lineTotalM,
                effective_discount: 0
            };
        }

        if (excludeGlobal) {
            // Use item-specific discount
            effectiveDiscountPercent = parseFloat(item.discount_percent || 0);
        } else {
            // Use global discount
            effectiveDiscountPercent = parseFloat(globalDiscountPercent || 0);
        }

        // Calculate WC native field values
        const lineSubtotal = productPrice * quantity;
        const discountAmount = (lineSubtotal * effectiveDiscountPercent) / 100;
        const lineTotal = lineSubtotal - discountAmount;

        return {
            _line_subtotal: lineSubtotal,
            _line_total: lineTotal,
            effective_discount: effectiveDiscountPercent
        };
    },

    /**
     * Update item object with WC staging fields
     * This adds staging WC fields to the item for proper total calculations
     * 
     * @param {Object} item - Order item object to update
     * @param {number} globalDiscountPercent - Global discount percentage
     * @returns {void} - Modifies item object in place
     */
    updateItemWCStagingFields: function(item, globalDiscountPercent) {
        const wcFields = this.calculateWCFieldsFromAdminConfig(item, globalDiscountPercent);
        
        // Add staging WC fields to the item
        item._line_subtotal_staging = wcFields._line_subtotal;
        item._line_total_staging = wcFields._line_total;
        item._effective_discount_staging = wcFields.effective_discount;
        
        // Mark that this item has staged WC fields
        item._has_wc_staging = true;
    },

    /**
     * Initialize WordPress editor features
     * @returns {void}
     */
    initializeEditor: function() {
        if (jQuery('.select2-initialized').length === 0) {
            jQuery('#eao_customer_user').select2({
                minimumResultsForSearch: Infinity
            });
        }
    }
};

// Backward compatibility - expose functions globally as they were before
window.escapeAttribute = window.EAO.Utils.escapeAttribute;
window.eaoFormatPrice = window.EAO.Utils.formatPrice;
window.eaoCleanShippingTitle = window.EAO.Utils.cleanShippingTitle;
window.eaoFormatCarrierName = window.EAO.Utils.formatCarrierName;

// Initialize when DOM is ready
jQuery(document).ready(function() {
    // Only initialize on our custom order editor page
    if (window.pagenow === 'toplevel_page_eao_custom_order_editor_page' || 
        window.pagenow === 'admin_page_eao_custom_order_editor_page') {
        window.EAO.Utils.initializeEditor();
    }
});

console.log('[EAO Core] Utilities module loaded successfully'); 