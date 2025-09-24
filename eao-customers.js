/**
 * Enhanced Admin Order - Customer Management Module
 * Version: 2.5.0
 * Author: Amnon Manneberg
 * 
 * v2.5.0: MAJOR MILESTONE - JavaScript refactor phase complete with full customer management
 * REFACTOR STEP 15: Customer Management Module Extraction
 * Extracted customer search, selection, and management functionality from admin-script.js.
 * Provides clean API for customer operations while maintaining single source of truth architecture.
 */

(function($) {
    'use strict';
    
    // Ensure EAO namespace exists
    window.EAO = window.EAO || {};

    window.EAO.Customers = {
        // Module properties
        initialized: false,
        
        // Customer state variables
        originalCustomerIdBeforeSearch: '',
        originalCustomerDisplayHTMLBeforeSearch: '',
        currentCustomerId: 0,
        searchTimeout: null,
        
        // jQuery elements (cached on init)
        $form: null,
        $customerHiddenInput: null,
        $customerDisplayName: null,
        $customerSearchUI: null,
        $customerSearchInput: null,
        $customerSearchResults: null,
        $triggerChangeCustomerButton: null,
        $cancelChangeCustomerButton: null,
        
        /**
         * Initialize the customer management module
         */
        init: function() {
            if (this.initialized) {
                console.warn('[EAO Customers] Module already initialized');
                return;
            }
            
            console.log('[EAO Customers] Initializing customer management module...');
            
            // Cache jQuery elements
            this.cacheElements();
            
            // Initialize state
            this.initializeState();
            
            // Bind events
            this.bindEvents();
            
            this.initialized = true;
            console.log('[EAO Customers] Customer management module initialized successfully');
        },
        
        /**
         * Cache frequently used jQuery elements
         */
        cacheElements: function() {
            this.$form = $('#eao-order-form');
            this.$customerHiddenInput = this.$form.find('#eao_customer_id_hidden');
            this.$customerDisplayName = $('#eao_customer_display_name');
            this.$customerSearchUI = $('#eao_customer_search_ui');
            this.$customerSearchInput = $('#eao_customer_search_term');
            this.$customerSearchResults = $('#eao_customer_search_results');
            this.$triggerChangeCustomerButton = $('#eao_trigger_change_customer');
            this.$cancelChangeCustomerButton = $('#eao_cancel_change_customer');
            
            // Validate that required elements exist
            if (!this.$customerHiddenInput.length) {
                console.error('[EAO Customers] Customer hidden input not found');
            }
            if (!this.$customerDisplayName.length) {
                console.error('[EAO Customers] Customer display name element not found');
            }
            if (!this.$cancelChangeCustomerButton.length) {
                console.error('[EAO Customers] Cancel change customer button not found - selector: #eao_cancel_change_customer');
            } // else {
                // console.log('[EAO Customers] Cancel change customer button found successfully');
            // }
        },
        
        /**
         * Initialize customer state from page data
         */
        initializeState: function() {
            this.currentCustomerId = window.eaoEditorParams ? 
                (window.eaoEditorParams.initial_customer_id || 0) : 0;
                
            console.log('[EAO Customers] Initial customer ID:', this.currentCustomerId);
        },
        
        /**
         * Bind all customer-related event handlers
         */
        bindEvents: function() {
            // Trigger customer change
            this.$triggerChangeCustomerButton.on('click.eaoCustomers', this.handleTriggerCustomerChange.bind(this));
            
            // Cancel customer change
            // console.log('[EAO Customers] Binding cancel button click handler. Button exists:', this.$cancelChangeCustomerButton.length > 0);
            
            // Use multiple binding approaches to ensure our handler gets priority
            this.$cancelChangeCustomerButton.off('click.eaoCustomers').on('click.eaoCustomers', this.handleCancelCustomerChange.bind(this));
            
            // Also bind directly without namespace as backup (with capture = true for higher priority)
            if (this.$cancelChangeCustomerButton.length > 0) {
                this.$cancelChangeCustomerButton.get(0).addEventListener('click', this.handleCancelCustomerChange.bind(this), true);
            }
            
            // console.log('[EAO Customers] Cancel button handler bound successfully with multiple approaches');
            
            // Customer search input
            this.$customerSearchInput.on('keyup.eaoCustomers', this.handleCustomerSearchInput.bind(this));
            
            // Customer search result selection
            this.$customerSearchResults.on('click.eaoCustomers', 'li.eao-customer-search-item', this.handleCustomerSelection.bind(this));
        },
        
        /**
         * Handle trigger customer change button click
         */
        handleTriggerCustomerChange: function() {
            console.log('[EAO Customers] Triggering customer change');
            
            // Store original state for potential rollback
            this.originalCustomerIdBeforeSearch = this.$customerHiddenInput.val();
            this.originalCustomerDisplayHTMLBeforeSearch = this.$customerDisplayName.html();
            this.currentCustomerId = this.originalCustomerIdBeforeSearch;
            
            // Show search UI
            this.$customerSearchUI.show();
            this.$customerSearchInput.val('').focus();
            this.$customerSearchResults.empty();
            this.$triggerChangeCustomerButton.hide();
            this.$cancelChangeCustomerButton.show();
        },
        
        /**
         * Handle cancel customer change button click
         */
        handleCancelCustomerChange: function(event) {
            // console.log('[EAO Customers] *** CANCEL BUTTON CLICKED - STARTING CANCEL OPERATION ***');
            console.log('[EAO Customers] Canceling customer change');
            
            // Prevent any other handlers from interfering
            if (event) {
                event.preventDefault();
                event.stopPropagation();
                event.stopImmediatePropagation();
            }
            
            // Hide search UI
            this.$customerSearchUI.hide();
            
            // CRITICAL FIX: Use saved initial data instead of just "before search" data
            // Get the true initial (saved) customer data from Change Detection module
            var initialData = window.EAO && window.EAO.ChangeDetection ? window.EAO.ChangeDetection.initialOrderData : {};
            // console.log('[EAO Customers] Reverting to saved initial customer data:', initialData);
            
            if (initialData && (initialData.customer_id !== undefined)) {
                // Revert to the truly saved customer data (not just before search)
                var savedCustomerId = initialData.customer_id;
                var savedCustomerDisplayHTML = initialData.customer_display_html || '';
                
                // console.log('[EAO Customers] Reverting to saved customer ID:', savedCustomerId, 'Type:', typeof savedCustomerId);
                // console.log('[EAO Customers] Current customer ID:', this.$customerHiddenInput.val());
                
                // Update form and state
                this.$customerHiddenInput.val(savedCustomerId).trigger('change');
                this.$customerDisplayName.html(savedCustomerDisplayHTML);
                this.currentCustomerId = savedCustomerId;
                
                // Clear pending addresses (they belong to the changed customer)
                if (window.pendingBillingAddress !== undefined) {
                    window.pendingBillingAddress = null;
                }
                if (window.pendingShippingAddress !== undefined) {
                    window.pendingShippingAddress = null;
                }
                
                // Normalize customer ID for module calls
                var revertToCustomerId = savedCustomerId;
                if (typeof revertToCustomerId === 'string') {
                    revertToCustomerId = revertToCustomerId === '' ? 0 : parseInt(revertToCustomerId, 10);
                }
                // console.log('[EAO Customers] Normalized revert customer ID:', revertToCustomerId);
                
                // Use setTimeout to ensure form field update is processed before real-time updates
                setTimeout(function() {
                    // console.log('[EAO Customers] Triggering real-time updates after form field update');
                    
                    // Update customer addresses using address module
                    if (window.EAO && window.EAO.Addresses) {
                        // console.log('[EAO Customers] Updating addresses for reverted customer:', revertToCustomerId);
                        window.EAO.Addresses.updateCustomerAddresses(revertToCustomerId);
                    }
                    
                    // Update FluentCRM profile back to saved customer (real-time)
                    if (window.EAO && window.EAO.FluentCRM && typeof window.EAO.FluentCRM.updateCustomerProfile === 'function') {
                        // console.log('[EAO Customers] Reverting FluentCRM profile to saved customer:', revertToCustomerId);
                        window.EAO.FluentCRM.updateCustomerProfile(revertToCustomerId);
                    }
                    
                    // Update Fluent Support tickets back to saved customer (real-time)
                    if (window.EAO && window.EAO.FluentSupport && typeof window.EAO.FluentSupport.updateCustomerTickets === 'function') {
                        // console.log('[EAO Customers] Reverting Fluent Support tickets to saved customer:', revertToCustomerId);
                        window.EAO.FluentSupport.updateCustomerTickets(revertToCustomerId);
                    }
                }, 50); // Small delay to ensure form field change is processed
            } else {
                console.warn('[EAO Customers] No saved initial customer data available for revert - falling back to before-search data');
                // Fallback to the old logic if initial data is not available
                if (this.$customerHiddenInput.val() !== this.originalCustomerIdBeforeSearch) {
                    this.$customerHiddenInput.val(this.originalCustomerIdBeforeSearch).trigger('change');
                    this.$customerDisplayName.html(this.originalCustomerDisplayHTMLBeforeSearch);
                    this.currentCustomerId = this.originalCustomerIdBeforeSearch;
                    
                    var revertToCustomerId = this.originalCustomerIdBeforeSearch;
                    if (typeof revertToCustomerId === 'string') {
                        revertToCustomerId = revertToCustomerId === '' ? 0 : parseInt(revertToCustomerId, 10);
                    }
                    
                    // Use setTimeout for fallback case too
                    setTimeout(function() {
                        // console.log('[EAO Customers] Fallback: Triggering real-time updates after form field update');
                        
                        if (window.EAO && window.EAO.Addresses) {
                            window.EAO.Addresses.updateCustomerAddresses(revertToCustomerId);
                        }
                        if (window.EAO && window.EAO.FluentCRM && typeof window.EAO.FluentCRM.updateCustomerProfile === 'function') {
                            window.EAO.FluentCRM.updateCustomerProfile(revertToCustomerId);
                        }
                        if (window.EAO && window.EAO.FluentSupport && typeof window.EAO.FluentSupport.updateCustomerTickets === 'function') {
                            window.EAO.FluentSupport.updateCustomerTickets(revertToCustomerId);
                        }
                    }, 50);
                }
            }
            
            // Reset UI state
            this.$customerSearchResults.empty();
            this.$triggerChangeCustomerButton.show();
            this.$cancelChangeCustomerButton.hide();
            
            // Trigger change detection
            if (window.EAO && window.EAO.ChangeDetection) {
                // console.log('[EAO Customers] Triggering change detection after cancel');
                window.EAO.ChangeDetection.triggerCheck();
            } else {
                console.warn('[EAO Customers] Change Detection module not available');
                // Fallback to global function
                if (typeof window.checkForChanges === 'function') {
                    // console.log('[EAO Customers] Using fallback checkForChanges function');
                    window.checkForChanges();
                }
            }
        },
        
        /**
         * Handle customer search input
         */
        handleCustomerSearchInput: function() {
            clearTimeout(this.searchTimeout);
            const searchTerm = this.$customerSearchInput.val();
            
            // Clear results if search term is too short
            if (searchTerm.length < 3 && searchTerm.length !== 0) {
                this.$customerSearchResults.empty();
                return;
            }
            if (searchTerm.length === 0) {
                this.$customerSearchResults.empty();
                return;
            }
            
            // Debounce search
            this.searchTimeout = setTimeout(() => {
                this.performCustomerSearch(searchTerm);
            }, 500);
        },
        
        /**
         * Perform AJAX customer search
         */
        performCustomerSearch: function(searchTerm) {
            console.log('[EAO Customers] Performing customer search for:', searchTerm);
            
            this.$customerSearchResults.html('<em><span class="spinner is-active" style="float:none; vertical-align: middle;"></span> Searching...</em>');
            
            $.ajax({
                url: window.eaoEditorParams.ajax_url,
                type: 'POST',
                data: {
                    action: 'eao_search_customers',
                    nonce: window.eaoEditorParams.search_customers_nonce,
                    search_term: searchTerm
                },
                success: (response) => {
                    this.handleCustomerSearchResponse(response);
                },
                error: (jqXHR, textStatus, errorThrown) => {
                    // CRITICAL: Remove loading spinner immediately
                    $('.spinner.is-active', this.$customerSearchResults).removeClass('is-active');
                    
                    console.error('[EAO Customers] AJAX error during customer search:', textStatus, errorThrown);
                    this.$customerSearchResults.html('<p>AJAX error: ' + textStatus + '</p>');
                }
            });
        },
        
        /**
         * Handle customer search AJAX response
         */
        handleCustomerSearchResponse: function(response) {
            // CRITICAL: Remove loading spinner immediately
            $('.spinner.is-active', this.$customerSearchResults).removeClass('is-active');
            
            this.$customerSearchResults.empty();
            
            if (response.success && response.data.length > 0) {
                const ul = $('<ul>');
                response.data.forEach((user) => {
                    const li = $('<li>');
                    li.addClass('eao-customer-search-item');
                    li.attr('data-id', user.id)
                        .attr('data-display-name', user.display_name)
                        .attr('data-full-text', user.text)
                        .text(user.text);
                    ul.append(li);
                });
                this.$customerSearchResults.append(ul);
                console.log('[EAO Customers] Found', response.data.length, 'customers');
            } else if (response.success && response.data.length === 0) {
                this.$customerSearchResults.html('<p>No customers found.</p>');
                console.log('[EAO Customers] No customers found for search');
            } else {
                let errorMessage = 'Error searching for customers.';
                if (response.data && response.data.message) {
                    errorMessage = response.data.message;
                }
                this.$customerSearchResults.html('<p>' + errorMessage + '</p>');
                console.error('[EAO Customers] Server error during customer search:', errorMessage);
            }
        },
        
        /**
         * Handle customer selection from search results
         */
        handleCustomerSelection: function(event) {
            const $selectedItem = $(event.currentTarget);
            const newCustomerId = $selectedItem.data('id');
            const newCustomerFullText = $selectedItem.data('full-text');
            
            console.log('[EAO Customers] Customer selected:', newCustomerId, newCustomerFullText);
            
            // Build customer display HTML with link
            const adminBaseUrl = window.eaoEditorParams.ajax_url.replace('/admin-ajax.php', '');
            const customerLink = adminBaseUrl + '/user-edit.php?user_id=' + newCustomerId;
            const newCustomerDisplayHTML = '<a href="' + customerLink + '" target="_blank">' + newCustomerFullText + '</a>';
            
            // Warn if order already processed/paid
            try {
                var statusVal = '';
                try { statusVal = String(jQuery('#order_status').val()||''); } catch(_){ }
                if (!statusVal && window.eaoEditorParams && window.eaoEditorParams.order_status) {
                    statusVal = 'wc-' + String(window.eaoEditorParams.order_status||'');
                }
                var normalized = (statusVal||'').replace(/^wc-/,'').toLowerCase();
                var isPaidOrProcessed = (normalized === 'processing' || normalized === 'completed' || normalized === 'shipped');
                if (isPaidOrProcessed) {
                    var proceed = window.confirm('Warning: This order is already processed. Changing the customer may be unsafe and points might have been deducted. Do you want to proceed?');
                    if (!proceed) { return; }
                }
            } catch(_e) { }
            
            // Update customer data
            // Single source of truth: always update hidden input; do not maintain eaoEditorParams here
            this.$customerHiddenInput.val(newCustomerId).trigger('change');
            this.$customerDisplayName.html(newCustomerDisplayHTML);
            this.currentCustomerId = newCustomerId;
            
            // Update customer addresses using address module
            if (window.EAO && window.EAO.Addresses) {
                window.EAO.Addresses.updateCustomerAddresses(newCustomerId);
            }
            
            // Update FluentCRM profile for new customer (deferred to idle/next tick)
            (window.requestIdleCallback || function(cb){ return setTimeout(cb,50); })(function(){
                try {
                    if (window.EAO && window.EAO.FluentCRM && typeof window.EAO.FluentCRM.updateCustomerProfile === 'function') {
                        console.log('[EAO Customers] Updating FluentCRM profile for customer (deferred):', newCustomerId);
                        window.EAO.FluentCRM.updateCustomerProfile(newCustomerId);
                    }
                } catch(_){}
            });

            // ALWAYS refresh YITH globals for the new customer so max points reflects user balance (deferred)
            (window.requestIdleCallback || function(cb){ return setTimeout(cb,50); })(function(){
                try {
                    const oid = (window.eaoEditorParams && window.eaoEditorParams.order_id) ? window.eaoEditorParams.order_id : null;
                    const ajaxUrl = (window.eaoEditorParams && window.eaoEditorParams.ajax_url) ? window.eaoEditorParams.ajax_url : (window.ajaxurl || null);
                    const nonce = (window.eaoEditorParams && window.eaoEditorParams.nonce) ? window.eaoEditorParams.nonce : null;
                    if (oid && ajaxUrl && nonce) {
                        console.log('[EAO Customers] Fetching YITH globals for customer change (deferred)...');
                        jQuery.post(ajaxUrl, { action: 'eao_get_yith_points_globals', nonce: nonce, order_id: oid, customer_id: newCustomerId }, function(resp){
                            try {
                                if (resp && resp.success && resp.data) {
                                    window.existingPointsRedeemed = parseInt(resp.data.existingPointsRedeemed||0,10);
                                    window.existingDiscountAmount = parseFloat(resp.data.existingDiscountAmount||0);
                                    window.totalAvailablePoints = parseInt(resp.data.totalAvailablePoints||0,10);
                                    window.customerCurrentPoints = parseInt(resp.data.customerCurrentPoints||0,10);
                                    console.log('[EAO Customers] Updated YITH globals after customer change:', {
                                        totalAvailablePoints: window.totalAvailablePoints,
                                        customerCurrentPoints: window.customerCurrentPoints,
                                        existingPointsRedeemed: window.existingPointsRedeemed,
                                        existingDiscountAmount: window.existingDiscountAmount
                                    });
                                    if (window.EAO && window.EAO.MainCoordinator) {
                                        try {
                                            const summary = window.EAO.MainCoordinator.calculateUnifiedSummaryData(window.currentOrderItems || []);
                                            window.EAO.MainCoordinator.refreshSummaryOnly(window.currentOrderItems || [], summary);
                                        } catch(_){ }
                                    }
                                } else {
                                    console.warn('[EAO Customers] Failed to refresh YITH globals for new customer', resp);
                                }
                            } catch(errY){ console.warn('[EAO Customers] Error applying YITH globals:', errY); }
                        }, 'json');
                    }
                } catch(_fg) { }
            });

            // Trigger a points max recalculation so the summary slider reflects the new user's balance
            try {
                if (window.EAO && window.EAO.YithPoints && typeof window.EAO.YithPoints.updatePointsMaxCalculation === 'function') {
                    window.EAO.YithPoints.updatePointsMaxCalculation();
                } else if (typeof window.updateOrderSummaryWithPointsDiscount === 'function') {
                    window.updateOrderSummaryWithPointsDiscount();
                }
            } catch(e) { /* non-fatal */ }
            
            // Enforce clamping to the new customer's max (including zero) on unpaid orders
            try {
                var status2 = '';
                try { status2 = String(jQuery('#order_status').val()||''); } catch(_){ }
                if (!status2 && window.eaoEditorParams && window.eaoEditorParams.order_status) {
                    status2 = 'wc-' + String(window.eaoEditorParams.order_status||'');
                }
                var norm2 = (status2||'').replace(/^wc-/,'').toLowerCase();
                var isUnpaid = !(norm2 === 'processing' || norm2 === 'completed' || norm2 === 'shipped');
                if (isUnpaid) {
                    // Use existing max calculation to clamp staged/current values
                    var rr = (window.pointsRedeemRate && window.pointsRedeemRate > 0) ? window.pointsRedeemRate : 10;
                    var stagedPts = (window.eaoStagedPointsDiscount && typeof window.eaoStagedPointsDiscount.points !== 'undefined') ? parseInt(window.eaoStagedPointsDiscount.points,10)||0 : null;
                    var currPts = (window.eaoCurrentPointsDiscount && typeof window.eaoCurrentPointsDiscount.points !== 'undefined') ? parseInt(window.eaoCurrentPointsDiscount.points,10)||0 : null;
                    var clampWith = null;
                    if (window.EAO && window.EAO.MainCoordinator && typeof window.EAO.MainCoordinator.calculateMaxPoints === 'function') {
                        // IMPORTANT: request the hard cap by passing 0; passing a probe would bias upward
                        clampWith = window.EAO.MainCoordinator.calculateMaxPoints(0);
                    }
                    try { console.log('[EAO Points Clamp][customer-change] staged=%s current=%s maxAllowed=%s', stagedPts, currPts, clampWith); } catch(_) {}
                    if (typeof clampWith === 'number') {
                        if (stagedPts !== null && stagedPts > clampWith) {
                            if (window.eaoStagedPointsDiscount) { window.eaoStagedPointsDiscount.points = clampWith; window.eaoStagedPointsDiscount.amount = (clampWith / rr); }
                            try { console.log('[EAO Points Clamp][customer-change] CLAMPED staged to %s', clampWith); } catch(_) {}
                        }
                        if (currPts !== null && currPts > clampWith) {
                            if (window.eaoCurrentPointsDiscount) { window.eaoCurrentPointsDiscount.points = clampWith; window.eaoCurrentPointsDiscount.amount = (clampWith / rr); }
                            try { console.log('[EAO Points Clamp][customer-change] CLAMPED current to %s', clampWith); } catch(_) {}
                        }
                        if ((stagedPts !== null && stagedPts > clampWith) || (currPts !== null && currPts > clampWith)) {
                            if (window.EAO && window.EAO.MainCoordinator) {
                                const summary = window.EAO.MainCoordinator.calculateUnifiedSummaryData(window.currentOrderItems || []);
                                window.EAO.MainCoordinator.refreshSummaryOnly(window.currentOrderItems || [], summary);
                            }
                        }
                    }
                }
            } catch(_z) { }
            
            // Update Fluent Support tickets for new customer (real-time, same as addresses)
            if (window.EAO && window.EAO.FluentSupport && typeof window.EAO.FluentSupport.updateCustomerTickets === 'function') {
                console.log('[EAO Customers] Updating Fluent Support tickets for customer:', newCustomerId);
                window.EAO.FluentSupport.updateCustomerTickets(newCustomerId);
            }
            
            // Hide search UI
            this.$customerSearchUI.hide();
            this.$triggerChangeCustomerButton.show();
            this.$cancelChangeCustomerButton.hide();
            this.$customerSearchResults.empty();
            
            // Note: checkForChanges() will be called due to .trigger('change') on the hidden input
            console.log('[EAO Customers] Customer selection completed');
        },
        
        /**
         * Update customer display after save (called from save success handler)
         */
        updateCustomerDisplayAfterSave: function(serverData) {
            if (!serverData) return;
            
            console.log('[EAO Customers] Updating customer display after save');
            
            // Update current customer ID
            this.currentCustomerId = serverData.customer_id;
            
            // Update customer display
            let newCustomerDisplayHTML = 'Guest';
            if (serverData.customer_id && serverData.customer_id != 0) {
                const adminBaseUrl = window.eaoEditorParams.ajax_url.replace('/admin-ajax.php', '');
                const customerLink = adminBaseUrl + '/user-edit.php?user_id=' + serverData.customer_id;
                newCustomerDisplayHTML = '<a href="' + customerLink + '" target="_blank">' + serverData.customer_name + '</a>';
            } else if (serverData.customer_name) {
                newCustomerDisplayHTML = serverData.customer_name + ' (Guest)';
            }
            
            this.$customerDisplayName.html(newCustomerDisplayHTML);
            console.log('[EAO Customers] Customer display updated after save');
        },
        
        /**
         * Get current customer data for change detection
         */
        getCurrentCustomerData: function() {
            return {
                customer_id: this.$customerHiddenInput.val()
            };
        },
        
        /**
         * Store initial customer data (called after save)
         */
        storeInitialCustomerData: function() {
            // This will be called by the change detection module
            // Customer data is already stored in the main initial data object
            console.log('[EAO Customers] Initial customer data stored');
        },
        
        /**
         * Reset customer to initial state (called on cancel)
         */
        resetToInitialCustomer: function(initialData) {
            if (!initialData) return;
            
            // console.log('[EAO Customers] *** resetToInitialCustomer called - THIS IS THE MAIN COORDINATOR CANCEL, NOT OUR CUSTOMER CANCEL ***');
            console.log('[EAO Customers] Resetting customer to initial state');
            
            // Reset form fields
            this.$customerHiddenInput.val(initialData.customer_id);
            this.$customerDisplayName.html(initialData.customer_display_html);
            this.currentCustomerId = initialData.customer_id;
            
            // Clear any pending addresses
            if (window.pendingBillingAddress !== undefined) {
                window.pendingBillingAddress = null;
            }
            if (window.pendingShippingAddress !== undefined) {
                window.pendingShippingAddress = null;
            }
            
            // Normalize customer ID for module calls
            var revertToCustomerId = this.currentCustomerId;
            if (typeof revertToCustomerId === 'string') {
                revertToCustomerId = revertToCustomerId === '' ? 0 : parseInt(revertToCustomerId, 10);
            }
            // console.log('[EAO Customers] Normalized revert customer ID for resetToInitialCustomer:', revertToCustomerId);
            
            // Use setTimeout to ensure form field update is processed before real-time updates
            setTimeout(function() {
                // console.log('[EAO Customers] Triggering real-time updates from resetToInitialCustomer');
                
                // Update customer addresses using address module
                if (window.EAO && window.EAO.Addresses) {
                    // console.log('[EAO Customers] Updating addresses for reverted customer:', revertToCustomerId);
                    window.EAO.Addresses.updateCustomerAddresses(revertToCustomerId);
                }
                
                // Update FluentCRM profile back to saved customer (real-time)
                if (window.EAO && window.EAO.FluentCRM && typeof window.EAO.FluentCRM.updateCustomerProfile === 'function') {
                    // console.log('[EAO Customers] Reverting FluentCRM profile to saved customer via resetToInitialCustomer:', revertToCustomerId);
                    window.EAO.FluentCRM.updateCustomerProfile(revertToCustomerId);
                }
                
                // Update Fluent Support tickets back to saved customer (real-time)
                if (window.EAO && window.EAO.FluentSupport && typeof window.EAO.FluentSupport.updateCustomerTickets === 'function') {
                    // console.log('[EAO Customers] Reverting Fluent Support tickets to saved customer via resetToInitialCustomer:', revertToCustomerId);
                    window.EAO.FluentSupport.updateCustomerTickets(revertToCustomerId);
                }
            }, 50); // Small delay to ensure form field change is processed
        },
        
        /**
         * Get current customer ID
         */
        getCurrentCustomerId: function() {
            return this.currentCustomerId;
        },
        
        /**
         * Cleanup method for module destruction
         */
        destroy: function() {
            if (!this.initialized) return;
            
            console.log('[EAO Customers] Destroying customer management module...');
            
            // Unbind events
            this.$triggerChangeCustomerButton.off('.eaoCustomers');
            this.$cancelChangeCustomerButton.off('.eaoCustomers');
            this.$customerSearchInput.off('.eaoCustomers');
            this.$customerSearchResults.off('.eaoCustomers');
            
            // Clear timeouts
            clearTimeout(this.searchTimeout);
            
            // Reset state
            this.initialized = false;
            this.originalCustomerIdBeforeSearch = '';
            this.originalCustomerDisplayHTMLBeforeSearch = '';
            this.currentCustomerId = 0;
            this.searchTimeout = null;
            
            console.log('[EAO Customers] Customer management module destroyed');
        }
    };

    // Auto-initialize when DOM is ready (if not already initialized by main script)
    jQuery(document).ready(function($) {
        // Only initialize if we're on the correct page and haven't been initialized yet
        if ((window.pagenow === 'toplevel_page_eao_custom_order_editor_page' || 
             window.pagenow === 'admin_page_eao_custom_order_editor_page') && 
            !window.EAO.Customers.initialized) {
            
            // Small delay to ensure other modules are loaded
            setTimeout(function() {
                if (!window.EAO.Customers.initialized) {
                    window.EAO.Customers.init();
                }
            }, 100);
        }
    });
})(jQuery); 