/**
 * Enhanced Admin Order - Change Detection Module
 * Version: 2.9.7
 * Author: Amnon Manneberg
 * 
 * CRITICAL MODULE: Manages form change detection and save button state.
 * This module determines when the "Update Order" button is enabled/disabled.
 * FIX: Enhanced product change detection to properly clear isPending flag when reverting changes.
 * v2.4.182: CRITICAL INITIALIZATION PROTECTION - Added comprehensive safety checks to prevent null reference errors during initialization
 * Form availability checks, delayed data operations, immediate button disable, and enhanced error handling throughout initialization sequence
 * v2.4.181: INITIALIZATION TIMING FIX - Fixed buttons enabled on initial load by implementing proper timing sequence
 * Added refreshInitialData() method and improved initialization timing to ensure buttons are disabled until data loads
 * v2.4.180: ULTIMATE NULL PROTECTION - Fixed both instances of null reference errors in getAddressFieldsData() and getCurrentFormData()
 * Enhanced element iteration with try/catch blocks and safe fallbacks for all .val() calls
 * v2.4.179: CRITICAL FIX - Added comprehensive safety checks to getCurrentFormData function to prevent null reference errors when reading form field values
 * v2.4.178: Fixed "Cannot read properties of null" error by adding safety check for globalOrderDiscountPercent initialization
 * v2.5.0: MAJOR MILESTONE - JavaScript refactor phase complete with robust change detection
 */

(function($) {
    'use strict';
    
    // Change Detection module loaded
    
    // Ensure EAO namespace exists
    window.EAO = window.EAO || {};

    /**
     * Change Detection Module
     */
    window.EAO.ChangeDetection = {
        
        // State variables
        initialOrderData: {},
        initialOrderItemsData: [],
        isAfterAjaxSave: false,
        
        // jQuery elements (will be initialized)
        $form: null,
        $updateButton: null,
        $cancelButton: null,
        $orderDateField: null,
        $orderTimeField: null,
        $orderStatusField: null,
        $customerHiddenInput: null,
        $prevDateTimeDisplay: null,
        $prevStatusDisplay: null,
        $prevCustomerDisplay: null,
        $billingPrevAddressDisplayContainer: null,
        $shippingPrevAddressDisplayContainer: null,
        $billingFieldsInputWrapper: null,
        $shippingFieldsInputWrapper: null,
        $globalOrderDiscountInput: null,
        
        /**
         * Initialize the change detection module
         */
        init: function() {
            if (window.eaoDebug) { console.log('[EAO Change Detection] Starting initialization...'); }
            
            // Initialize jQuery elements with safety checks
            this.$form = $('#eao-order-form');
            if (!this.$form || this.$form.length === 0) {
                console.error('[EAO Change Detection] CRITICAL: Form element #eao-order-form not found! Cannot initialize.');
                return;
            }
            
            this.$updateButton = $('#eao-save-order');
            this.$cancelButton = $('#eao-cancel-changes');
            this.$orderDateField = this.$form.find('#eao_order_date');
            this.$orderTimeField = this.$form.find('#eao_order_time');
            this.$orderStatusField = this.$form.find('#eao_order_status');
            this.$customerHiddenInput = this.$form.find('#eao_customer_id_hidden');
            this.$prevDateTimeDisplay = $('#eao_previous_order_datetime');
            this.$prevStatusDisplay = $('#eao_previous_order_status');
            this.$prevCustomerDisplay = $('#eao_previous_customer');
            this.$billingPrevAddressDisplayContainer = $('#eao-billing-address-section .eao-previous-address-display');
            this.$shippingPrevAddressDisplayContainer = $('#eao-shipping-address-section .eao-previous-address-display');
            this.$billingFieldsInputWrapper = $('#eao-billing-fields-inputs');
            this.$shippingFieldsInputWrapper = $('#eao-shipping-fields-inputs');
            this.$globalOrderDiscountInput = $('#eao_order_products_discount_percent');
            
            if (window.eaoDebug) { console.log('[EAO Change Detection] Elements initialized:', {
                form: this.$form.length,
                updateButton: this.$updateButton.length,
                cancelButton: this.$cancelButton.length,
                orderDateField: this.$orderDateField.length,
                orderTimeField: this.$orderTimeField.length,
                orderStatusField: this.$orderStatusField.length,
                customerHiddenInput: this.$customerHiddenInput.length,
                globalOrderDiscountInput: this.$globalOrderDiscountInput.length
            }); }
            
            // Force disable buttons immediately to prevent initial enabled state
            if (this.$updateButton && this.$updateButton.length > 0) {
                this.$updateButton.prop('disabled', true).text('Update Order');
                if (window.eaoDebug) { console.log('[EAO Change Detection] Update button disabled immediately'); }
            }
            if (this.$cancelButton && this.$cancelButton.length > 0) {
                this.$cancelButton.prop('disabled', true);
                if (window.eaoDebug) { console.log('[EAO Change Detection] Cancel button disabled immediately'); }
            }
            
            // DELAY all data operations until form is stable
            const self = this;
            setTimeout(function() {
                if (window.eaoDebug) { console.log('[EAO Change Detection] Starting delayed initialization...'); }
                
                try {
                    // Store initial data
                    self.storeInitialData();
                    
                    // Set up event listeners
                    self.setupEventListeners();
                    
                    if (window.eaoDebug) { console.log('[EAO Change Detection] Initial setup completed successfully'); }
                } catch (error) {
                    console.error('[EAO Change Detection] Error during delayed initialization:', error);
                }
                
                // Additional delay for final check
                setTimeout(function() {
                    try {
                        self.checkForChanges();
                        if (window.eaoDebug) { console.log('[EAO Change Detection] Initial change check completed'); }
                    } catch (error) {
                        console.error('[EAO Change Detection] Error during initial change check:', error);
                    }
                }, 300); // Extra delay for stability
            }, 200); // Initial delay to let form stabilize
            
            if (window.eaoDebug) { console.log('[EAO Change Detection] Module initialized successfully'); }
        },
        
        /**
         * Get current address field data
         */
        getAddressFieldsData: function(type) {
            const fields = {};
            const wc_prefix = type + '_'; // e.g., billing_ or shipping_
            
            // Safety check for form availability
            if (!this.$form || this.$form.length === 0) {
                console.warn('[EAO Change Detection] Form not available for getAddressFieldsData, returning empty fields');
                return fields;
            }
            
            try {
                this.$form.find(`input[name^="${wc_prefix}"], select[name^="${wc_prefix}"], textarea[name^="${wc_prefix}"]`).each(function() {
                    const $element = $(this);
                    const name_attr = $element.attr('name');
                    if (name_attr && $element.length > 0) {
                        const key_without_type_prefix = name_attr.replace(wc_prefix, '');
                        try {
                            fields[key_without_type_prefix] = $element.val() || '';
                        } catch (error) {
                            console.warn('[EAO Change Detection] Error reading value for field:', name_attr, error);
                            fields[key_without_type_prefix] = '';
                        }
                    }
                });
                // Ensure shipping phone is captured even if template names it differently
                if (type === 'shipping' && typeof fields.phone === 'undefined') {
                    const $shipPhone = $("input[name='shipping_phone'], #eao_shipping_phone");
                    if ($shipPhone && $shipPhone.length > 0) {
                        fields.phone = $shipPhone.val() || '';
                    }
                }
            } catch (error) {
                console.error('[EAO Change Detection] Error in getAddressFieldsData for type:', type, error);
            }
            
            return fields;
        },
        
        /**
         * Store initial form data for comparison
         */
        storeInitialData: function() {
            const formData = this.getCurrentFormData();
            
            this.initialOrderData = {
                order_date: formData.order_date,
                order_time: formData.order_time,
                order_status: formData.order_status,
                order_status_text: this.$orderStatusField && this.$orderStatusField.length > 0 ? this.$orderStatusField.find('option:selected').text() : '',
                customer_id: formData.customer_id,
                customer_display_html: $('#eao_customer_display_name').length > 0 ? $('#eao_customer_display_name').html() : '',
                billing_address_fields: formData.billing_address_fields,
                shipping_address_fields: formData.shipping_address_fields,
                global_order_discount_percent: formData.global_order_discount_percent,
                points_to_redeem: formData.points_to_redeem
            };
            
            // Store a deep copy of current order items data for comparison
            if (window.currentOrderItems) {
                this.initialOrderItemsData = window.currentOrderItems.map(item => ({
                    item_id: item.item_id,
                    product_id: item.product_id,
                    quantity: parseInt(item.quantity, 10),
                    discount_percent: parseFloat(item.discount_percent) || 0,
                    exclude_gd: item.exclude_gd || false
                }));
            } else {
                this.initialOrderItemsData = []; // Initialize as empty array if no items yet
            }
            
            if (window.eaoDebug) { console.log('[EAO Change Detection] Initial data stored:', this.initialOrderData); }
        },
        
        /**
         * Get current form data with safety checks for all field access
         */
        getCurrentFormData: function() {
            // Add safety checks for all field access to prevent null reference errors
            
            try {
                let data = {
                    billing_address_fields: {},
                    shipping_address_fields: {},
                    billing_address_formatted_html: '',
                    shipping_address_formatted_html: '',
                    global_order_discount_percent: 0,
                    points_to_redeem: 0
                };
                
                // Order date with enhanced error handling
                try {
                    if (this.$orderDateField && this.$orderDateField.length > 0) {
                        const dateVal = this.$orderDateField.val();
                        data.order_date = dateVal || '';
                    } else {
                        data.order_date = '';
                    }
                } catch (dateError) {
                    console.error('[EAO Change Detection] Error reading order date:', dateError);
                    data.order_date = '';
                }
                
                // Order time with enhanced error handling
                try {
                    if (this.$orderTimeField && this.$orderTimeField.length > 0) {
                        const timeVal = this.$orderTimeField.val();
                        data.order_time = timeVal || '';
                    } else {
                        data.order_time = '';
                    }
                } catch (timeError) {
                    console.error('[EAO Change Detection] Error reading order time:', timeError);
                    data.order_time = '';
                }
                
                // Order status with enhanced error handling
                try {
                    if (this.$orderStatusField && this.$orderStatusField.length > 0) {
                        const statusVal = this.$orderStatusField.val();
                        data.order_status = statusVal || '';
                        data.order_status_text = this.$orderStatusField.find('option:selected').text() || '';
                    } else {
                        data.order_status = '';
                        data.order_status_text = '';
                    }
                } catch (statusError) {
                    console.error('[EAO Change Detection] Error reading order status:', statusError);
                    data.order_status = '';
                    data.order_status_text = '';
                }
                
                // Customer ID with enhanced error handling
                try {
                    if (this.$customerHiddenInput && this.$customerHiddenInput.length > 0) {
                        const customerVal = this.$customerHiddenInput.val();
                        data.customer_id = customerVal || '';
                    } else {
                        data.customer_id = '';
                    }
                } catch (customerError) {
                    console.error('[EAO Change Detection] Error reading customer ID:', customerError);
                    data.customer_id = '';
                }
                
                // Customer display HTML
                try {
                    const $customerDisplay = $('#eao_customer_display_name');
                    if ($customerDisplay.length > 0) {
                        data.customer_display_html = $customerDisplay.html() || '';
                    } else {
                        data.customer_display_html = '';
                    }
                } catch (customerDisplayError) {
                    console.error('[EAO Change Detection] Error reading customer display:', customerDisplayError);
                    data.customer_display_html = '';
                }
                
                // Address fields
                try {
                    data.billing_address_fields = this.getAddressFieldsData('billing');
                } catch (billingError) {
                    console.error('[EAO Change Detection] Error reading billing address:', billingError);
                    data.billing_address_fields = {};
                }
                
                try {
                    data.shipping_address_fields = this.getAddressFieldsData('shipping');
                } catch (shippingError) {
                    console.error('[EAO Change Detection] Error reading shipping address:', shippingError);
                    data.shipping_address_fields = {};
                }
                
                // Address display HTML
                try {
                    const $billingDisplay = $('#eao-billing-address-section .eao-current-address-display');
                    data.billing_address_formatted_html = $billingDisplay.length > 0 ? ($billingDisplay.html() || '') : '';
                } catch (billingDisplayError) {
                    console.error('[EAO Change Detection] Error reading billing display:', billingDisplayError);
                    data.billing_address_formatted_html = '';
                }
                
                try {
                    const $shippingDisplay = $('#eao-shipping-address-section .eao-current-address-display');
                    data.shipping_address_formatted_html = $shippingDisplay.length > 0 ? ($shippingDisplay.html() || '') : '';
                } catch (shippingDisplayError) {
                    console.error('[EAO Change Detection] Error reading shipping display:', shippingDisplayError);
                    data.shipping_address_formatted_html = '';
                }
                
                // Global discount with enhanced error handling
                try {
                    if (this.$globalOrderDiscountInput && this.$globalOrderDiscountInput.length > 0) {
                        const discountVal = this.$globalOrderDiscountInput.val();
                        data.global_order_discount_percent = parseFloat(discountVal) || 0;
                    } else {
                        data.global_order_discount_percent = 0;
                    }
                } catch (discountError) {
                    console.error('[EAO Change Detection] Error reading global discount:', discountError);
                    data.global_order_discount_percent = 0;
                }
                
                // Points to redeem - check both staged and inline input
                try {
                    let pointsToRedeem = 0;
                    
                    // Priority 1: Check staged points (from inline slider)
                    if (window.eaoStagedPointsDiscount && window.eaoStagedPointsDiscount.points) {
                        pointsToRedeem = parseInt(window.eaoStagedPointsDiscount.points) || 0;
                    } else {
                        // Priority 2: Check inline input field
                        const $inlinePointsInput = $('#eao_points_to_redeem_inline');
                        if ($inlinePointsInput.length > 0) {
                            pointsToRedeem = parseInt($inlinePointsInput.val()) || 0;
                        } else {
                            // Priority 3: Check original points input field
                            const $pointsInput = $('#eao_points_to_redeem');
                            if ($pointsInput.length > 0) {
                                pointsToRedeem = parseInt($pointsInput.val()) || 0;
                            }
                        }
                    }
                    
                    data.points_to_redeem = pointsToRedeem;
                } catch (pointsError) {
                    console.error('[EAO Change Detection] Error reading points:', pointsError);
                    data.points_to_redeem = 0;
                }
                
                return data;
            } catch (error) {
                console.error('[EAO Change Detection] CRITICAL ERROR getting current form data:', error);
                
                // Return safe fallback data
                return {
                    order_date: '',
                    order_time: '',
                    order_status: '',
                    order_status_text: '',
                    customer_id: '',
                    customer_display_html: '',
                    billing_address_fields: {},
                    shipping_address_fields: {},
                    billing_address_formatted_html: '',
                    shipping_address_formatted_html: '',
                    global_order_discount_percent: 0,
                    points_to_redeem: 0
                };
            }
        },
        
        /**
         * Compare two address objects
         */
        compareAddresses: function(initialAddrFields, currentAddrFields) {
            const initialKeys = Object.keys(initialAddrFields || {});
            const currentKeys = Object.keys(currentAddrFields || {});

            if (initialKeys.length !== currentKeys.length) {
                return true; // Different number of properties
            }

            for (const key of initialKeys) {
                if (!currentAddrFields.hasOwnProperty(key) || String(initialAddrFields[key]) !== String(currentAddrFields[key])) {
                    return true; // Key missing in current or value difference
                }
            }
            return false; // No differences
        },
        
        /**
         * Re-store initial data after products have been loaded
         */
        refreshInitialData: function() {
            if (window.eaoDebug) { console.log('[EAO Change Detection] Refreshing initial data after products loaded'); }
            this.storeInitialData();
            // Immediately check for changes to ensure proper button state
            this.checkForChanges();
        },
        
        /**
         * Main change detection function - CRITICAL
         */
        checkForChanges: function() {
            const currentData = this.getCurrentFormData();
            let changed = false;
            let changeReasons = []; // DEBUG: Track what's causing changes
            
            if (this.isAfterAjaxSave) {
                // Force hide address "Was:" containers after AJAX save
                this.$billingPrevAddressDisplayContainer.css('display', 'none').find('.eao-previous-address-value').empty();
                this.$shippingPrevAddressDisplayContainer.css('display', 'none').find('.eao-previous-address-value').empty();
                this.isAfterAjaxSave = false;
            }

            // Date/Time changes
            if (this.initialOrderData.order_date !== currentData.order_date || this.initialOrderData.order_time !== currentData.order_time) {
                changed = true;
                changeReasons.push(`Date/Time: ${this.initialOrderData.order_date} ${this.initialOrderData.order_time} → ${currentData.order_date} ${currentData.order_time}`);
                if (this.$orderDateField && this.$orderDateField.length > 0) {
                    this.$orderDateField.addClass('eao-field-changed');
                }
                if (this.$orderTimeField && this.$orderTimeField.length > 0) {
                    this.$orderTimeField.addClass('eao-field-changed');
                }
                if (this.$prevDateTimeDisplay && this.$prevDateTimeDisplay.length > 0) {
                    this.$prevDateTimeDisplay.text('Was: ' + this.initialOrderData.order_date + ' ' + this.initialOrderData.order_time).show();
                }
            } else {
                if (this.$orderDateField && this.$orderDateField.length > 0) {
                    this.$orderDateField.removeClass('eao-field-changed');
                }
                if (this.$orderTimeField && this.$orderTimeField.length > 0) {
                    this.$orderTimeField.removeClass('eao-field-changed');
                }
                if (this.$prevDateTimeDisplay && this.$prevDateTimeDisplay.length > 0) {
                    this.$prevDateTimeDisplay.hide().text('');
                }
            }

            // Status changes
            if (this.initialOrderData.order_status !== currentData.order_status) {
                changed = true;
                if (this.$orderStatusField && this.$orderStatusField.length > 0) {
                    this.$orderStatusField.addClass('eao-field-changed');
                    this.$orderStatusField.closest('.select2-container').addClass('eao-field-changed');
                }
                if (this.$prevStatusDisplay && this.$prevStatusDisplay.length > 0) {
                    this.$prevStatusDisplay.text('Was: ' + this.initialOrderData.order_status_text).show();
                }
            } else {
                if (this.$orderStatusField && this.$orderStatusField.length > 0) {
                    this.$orderStatusField.removeClass('eao-field-changed');
                    this.$orderStatusField.closest('.select2-container').removeClass('eao-field-changed');
                }
                if (this.$prevStatusDisplay && this.$prevStatusDisplay.length > 0) {
                    this.$prevStatusDisplay.hide().text('');
                }
            }

            // Customer changes
            if (this.initialOrderData.customer_id !== currentData.customer_id) {
                changed = true;
                if (this.$prevCustomerDisplay && this.$prevCustomerDisplay.length > 0) {
                    this.$prevCustomerDisplay.html('Was: ' + this.initialOrderData.customer_display_html).show();
                }
            } else {
                if (this.$prevCustomerDisplay && this.$prevCustomerDisplay.length > 0) {
                    this.$prevCustomerDisplay.hide().html('');
                }
            }

            // Billing Address changes
            if ((this.$billingPrevAddressDisplayContainer && this.$billingPrevAddressDisplayContainer.length > 0 && this.$billingPrevAddressDisplayContainer.is(':visible')) || this.compareAddresses(this.initialOrderData.billing_address_fields, currentData.billing_address_fields)) {
                changed = true;
                if (this.$billingFieldsInputWrapper && this.$billingFieldsInputWrapper.length > 0 && this.$billingFieldsInputWrapper.is(':visible')) {
                    $('#eao-billing-fields-inputs .eao-address-input').each((i, el) => {
                        const $el = $(el);
                        const fieldKey = $el.attr('name').replace('eao_billing_', '');
                        if (this.initialOrderData.billing_address_fields[fieldKey] !== currentData.billing_address_fields[fieldKey]) {
                            $el.addClass('eao-field-changed');
                            $('#eao_previous_billing_' + fieldKey).text('Was: ' + this.initialOrderData.billing_address_fields[fieldKey]).show();
                        } else {
                            $el.removeClass('eao-field-changed');
                            $('#eao_previous_billing_' + fieldKey).hide().text('');
                        }
                    });
                }
            } else {
                $('#eao-billing-fields-inputs .eao-address-input').removeClass('eao-field-changed');
                $('#eao-billing-fields-inputs .eao-previous-value').hide().text('');
                if (this.$billingPrevAddressDisplayContainer && this.$billingPrevAddressDisplayContainer.length > 0) {
                    this.$billingPrevAddressDisplayContainer.hide().find('.eao-previous-address-value').empty();
                }
            }

            // Shipping Address changes
            if ((this.$shippingPrevAddressDisplayContainer && this.$shippingPrevAddressDisplayContainer.length > 0 && this.$shippingPrevAddressDisplayContainer.is(':visible')) || this.compareAddresses(this.initialOrderData.shipping_address_fields, currentData.shipping_address_fields)) {
                changed = true;
                if (this.$shippingFieldsInputWrapper && this.$shippingFieldsInputWrapper.length > 0 && this.$shippingFieldsInputWrapper.is(':visible')) {
                    $('#eao-shipping-fields-inputs .eao-address-input').each((i, el) => {
                        const $el = $(el);
                        const fieldKey = $el.attr('name').replace('eao_shipping_', '');
                        if (this.initialOrderData.shipping_address_fields[fieldKey] !== currentData.shipping_address_fields[fieldKey]) {
                            $el.addClass('eao-field-changed');
                            $('#eao_previous_shipping_' + fieldKey).text('Was: ' + this.initialOrderData.shipping_address_fields[fieldKey]).show();
                        } else {
                            $el.removeClass('eao-field-changed');
                            $('#eao_previous_shipping_' + fieldKey).hide().text('');
                        }
                    });
                }
            } else {
                $('#eao-shipping-fields-inputs .eao-address-input').removeClass('eao-field-changed');
                $('#eao-shipping-fields-inputs .eao-previous-value').hide().text('');
                if (this.$shippingPrevAddressDisplayContainer && this.$shippingPrevAddressDisplayContainer.length > 0) {
                    this.$shippingPrevAddressDisplayContainer.hide().find('.eao-previous-address-value').empty();
                }
            }

            // Check for staged notes
            if (window.stagedNotes && window.stagedNotes.length > 0) {
                changed = true;
            }

            // Check for staged product items
            if (window.stagedOrderItems && window.stagedOrderItems.length > 0) {
                changed = true;
            }

            // Check for items pending deletion
            if (window.itemsPendingDeletion && window.itemsPendingDeletion.length > 0) {
                changed = true;
            }

            // Check for changes in existing order items - ENHANCED LOGIC
            let hasItemChanges = false;
            
            if (window.currentOrderItems) {
                // First pass: Compare all items with initial data and update isPending flags
                for (let i = 0; i < window.currentOrderItems.length; i++) {
                    const currentItem = window.currentOrderItems[i];
                    
                    // Skip new items that aren't in the initial data
                    if (!currentItem.isExisting) {
                        continue;
                    }
                    
                    const initialItem = this.initialOrderItemsData.find(initItem => 
                        (initItem.item_id && initItem.item_id === currentItem.item_id) || 
                        (!initItem.item_id && initItem.product_id === currentItem.product_id)
                    );

                    if (initialItem) {
                        // Check if current values match initial values
                        const quantityMatches = parseInt(currentItem.quantity, 10) === initialItem.quantity;
                        const discountMatches = (parseFloat(currentItem.discount_percent) || 0) === initialItem.discount_percent;
                        const excludeGdMatches = (currentItem.exclude_gd || false) === initialItem.exclude_gd;
                        
                        // Check for staging field changes (WC fields updated but not saved yet)
                        const hasStagingChanges = currentItem._has_wc_staging === 'yes' || 
                                                currentItem._line_subtotal_staging || 
                                                currentItem._line_total_staging || 
                                                currentItem._effective_discount_staging;
                        
                        if (quantityMatches && discountMatches && excludeGdMatches && !hasStagingChanges) {
                            // Values match initial AND no staging changes - clear pending flag
                            currentItem.isPending = false;
                        } else {
                            // Values don't match initial OR have staging changes - mark as pending
                            currentItem.isPending = true;
                            hasItemChanges = true;
                        }
                    } else {
                        // Item not found in initial data - it's a change
                        hasItemChanges = true;
                    }
                }
                
                // Second pass: Check if any items are still marked as pending
                if (!hasItemChanges) {
                    for (let i = 0; i < window.currentOrderItems.length; i++) {
                        const currentItem = window.currentOrderItems[i];
                        if (currentItem.isPending) {
                            hasItemChanges = true;
                            break;
                        }
                    }
                }
                
                // Check if the number of items changed
                if (!hasItemChanges && window.currentOrderItems.length !== this.initialOrderItemsData.length) {
                    hasItemChanges = true; 
                }
            }
            
            if (hasItemChanges) {
                changed = true; 
            }

            // Check for global discount change
            if (this.initialOrderData.global_order_discount_percent !== currentData.global_order_discount_percent) {
                changed = true;
                if (this.$globalOrderDiscountInput && this.$globalOrderDiscountInput.length > 0) {
                    this.$globalOrderDiscountInput.addClass('eao-field-changed');
                }
            } else {
                if (this.$globalOrderDiscountInput && this.$globalOrderDiscountInput.length > 0) {
                    this.$globalOrderDiscountInput.removeClass('eao-field-changed');
                }
            }

            // Check for points redemption change
            if (this.initialOrderData.points_to_redeem !== currentData.points_to_redeem) {
                changed = true;
                const $pointsInput = $('#eao_points_to_redeem');
                if ($pointsInput && $pointsInput.length > 0) {
                    $pointsInput.addClass('eao-field-changed');
                }
            } else {
                const $pointsInput = $('#eao_points_to_redeem');
                if ($pointsInput && $pointsInput.length > 0) {
                    $pointsInput.removeClass('eao-field-changed');
                }
            }

            // Check for a pending ShipStation rate
            if (window.eaoPendingShipstationRate && 
                typeof window.eaoPendingShipstationRate === 'object' && 
                (window.eaoPendingShipstationRate.service_name || window.eaoPendingShipstationRate.method_title)) {
                changed = true;
            }

            // Check for payment processing changes
            if (window.eaoPaymentProcessed && window.eaoPaymentChangeData && 
                typeof window.eaoPaymentChangeData === 'object' && 
                window.eaoPaymentChangeData.processed) {
                changed = true;
                changeReasons.push(`Payment processed: ${window.eaoPaymentChangeData.gateway} - ${window.eaoPaymentChangeData.amount}`);
            }

            // Update button states
            if (changed) {
                if (this.$updateButton && this.$updateButton.length > 0) {
                    this.$updateButton.prop('disabled', false);
                }
                if (this.$cancelButton && this.$cancelButton.length > 0) {
                    this.$cancelButton.prop('disabled', false);
                }
            } else {
                if (this.$updateButton && this.$updateButton.length > 0) {
                    this.$updateButton.prop('disabled', true);
                }
                if (this.$cancelButton && this.$cancelButton.length > 0) {
                    this.$cancelButton.prop('disabled', true);
                }
            }
            
            return changed;
        },
        
        /**
         * Set up event listeners for form changes
         */
        setupEventListeners: function() {
            const self = this;
            
            // Form field changes
            this.$form.on('change input', '#eao_order_date, #eao_order_time, #eao_order_status, #eao_customer_id_hidden', function() {
                self.checkForChanges();
            });

            // Global discount input changes
            this.$globalOrderDiscountInput.on('change input', function() {
                window.globalOrderDiscountPercent = parseFloat($(this).val()) || 0;
                self.checkForChanges();
            });

            // Points field changes
            $(document).on('change input', '#eao_points_to_redeem, #eao_points_slider', function() {
                self.checkForChanges();
            });
        },
        
        /**
         * Mark that an AJAX save just completed
         */
        markAfterAjaxSave: function() {
            this.isAfterAjaxSave = true;
        },
        
        /**
         * Mark form as saved (reset initial data after successful save)
         */
        markFormAsSaved: function() {
            if (window.eaoDebug) { console.log('[EAO Change Detection] Marking form as saved, updating initial data'); }
            this.storeInitialData();
            this.clearPaymentChanges(); // Clear payment processing flags
            this.checkForChanges(); // Update button states
        },
        
        /**
         * Clear payment processing change flags after successful save
         */
        clearPaymentChanges: function() {
            if (window.eaoPaymentProcessed) {
                if (window.eaoDebug) { console.log('[EAO Change Detection] Clearing payment processing flags after save'); }
                window.eaoPaymentProcessed = false;
                window.eaoPaymentChangeData = null;
            }
        },
        
        /**
         * External API for other modules to trigger change detection
         */
        triggerCheck: function() {
            return this.checkForChanges();
        }
    };

    // Backward compatibility - expose functions globally as they were before
    window.storeInitialData = function() {
        if (window.EAO && window.EAO.ChangeDetection) {
            window.EAO.ChangeDetection.storeInitialData();
        }
    };

    window.getCurrentFormData = function() {
        if (window.EAO && window.EAO.ChangeDetection) {
            return window.EAO.ChangeDetection.getCurrentFormData();
        }
        return {};
    };

    window.compareAddresses = function(initial, current) {
        if (window.EAO && window.EAO.ChangeDetection) {
            return window.EAO.ChangeDetection.compareAddresses(initial, current);
        }
        return false;
    };

    window.checkForChanges = function() {
        if (window.EAO && window.EAO.ChangeDetection) {
            return window.EAO.ChangeDetection.checkForChanges();
        }
        return false;
    };

    window.markFormAsSaved = function() {
        if (window.EAO && window.EAO.ChangeDetection) {
            window.EAO.ChangeDetection.markFormAsSaved();
        }
    };

    if (window.eaoDebug) { console.log('[EAO Change Detection] Module v2.9.6 loaded - Enhanced with staging field change detection'); }
})(jQuery); 