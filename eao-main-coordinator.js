/**
 * Enhanced Admin Order Plugin - Main Coordinator Module
 * 
 * @package EnhancedAdminOrder
 * @since 2.4.17
 * @version 2.8.10 - Smart flag system: Early init, poll only until first value, direct updates after
 * @author Amnon Manneberg
 * 
 * Main coordinator for initialization, DOM management, rendering coordination, and event setup.
 * Follows established architectural patterns and maintains clean separation of concerns.
 * 
 * Author: Amnon Manneberg
 * 
 * STEP 18: Main Coordinator Module Extraction COMPLETE
 * Fixed console errors: jQuery scope issues, missing global functions for template compatibility.
 * All template functions now properly exposed globally with fallback mechanisms.
 * 
 * v2.4.200: Fixed critical jQuery scoping issue by adding proper IIFE wrapper
 * v2.5.0: MAJOR MILESTONE - JavaScript refactor phase complete with full modular coordination
 * v2.5.1: ARCHITECTURAL FIX - YITH points slider handler properly placed using refreshSummaryOnly()
 * v2.5.15: UNIFIED SUMMARY - Single source of truth for all summary calculations, eliminates competing systems
 * v2.5.18: INITIAL POINTS FIX - Proper conditional YITH Points calculation after rendering when no data exists
 * v2.5.19: TWO-PHASE RENDERING - Products first, then summary with all data ready - no artificial delays
 * v2.5.22: Debug logging removed, production ready
 * v2.7.1: FEATURE: Added inline YITH Points slider in summary section with real-time max calculation
 * v2.7.2: FIX: Always show points line when available + proper global variable access
 * v2.7.3: UX: Points count in label + dollar amount matches table formatting
 * v2.7.4: FIX: Slider spacing, alignment, unique IDs, enhanced usability
 * v2.7.5: FIX: Centered slider layout with min/max labels + proper event binding
 * v2.7.6: UX: Removed old metabox + bidirectional input field in label + improved alignment
 */

//

(function($) {
    'use strict';
    
    
    
    window.EAO = window.EAO || {};

window.EAO.MainCoordinator = {
    
    // Module state
    initialized: false,
    
    // DOM references - centralized management
    elements: {
        $form: null,
        $updateButton: null,
        $cancelButton: null,
        $orderDateField: null,
        $orderTimeField: null,
        $orderStatusField: null,
        $customerHiddenInput: null,
        $customerDisplayName: null,
        $globalOrderDiscountInput: null,
        $currentOrderItemsList: null,
        $currentOrderItemsSummary: null,
        $productSearchInput: null,
        $productSearchResults: null
    },

    // Legacy renderer removed – Tabulator-only path
    
    // Global state management
    state: {
        globalOrderDiscountPercent: 0,
        currentOrderSummaryData: {},
        productSearchRequest: null,
        isAfterAjaxSave: false
    },
    
    /**
     * Initialize the Main Coordinator Module
     */
    init: function() {
        console.log('[EAO Main Coordinator v2.9.15] *** INIT FUNCTION CALLED ***');
        
        if (this.initialized) {
            console.warn('[EAO Main Coordinator] Already initialized, skipping');
            return;
        }
        
        console.log('[EAO Main Coordinator v2.9.15] Initializing Main Coordinator Module');
        
        // 1. Initialize DOM elements
        this.initializeDOMElements();
        
        // 2. Initialize global variables and state
        this.initializeGlobalState();
        
        // 3. Set up core event handlers
        this.setupCoreEventHandlers();
        
        // 4. Initialize all other modules
        this.initializeAllModules();
        
        // 5. Set up WordPress postbox toggles
        this.initializePostboxToggles();
        
        // 6. Initial product load
        this.performInitialLoad();
        
        // 7. Final initialization tasks
        this.performFinalInitialization();
        
        // Ensure Roles line is present/updated on init
        try {
            const cid = parseInt(jQuery('#eao_customer_id_hidden').val() || '0', 10);
            if (cid > 0) { this.updateCustomerRolesLine(cid); }
        } catch(_){ }

        this.initialized = true;
        console.log('[EAO Main Coordinator v2.9.15] Main Coordinator Module initialization complete');
    },
    
    /**
     * Initialize DOM element references
     */
    initializeDOMElements: function() {
        console.log('[EAO Main Coordinator] Initializing DOM elements...');
        
        // Form and core buttons
        this.elements.$form = $('#eao-order-form');
        this.elements.$updateButton = $('#eao-save-order');
        this.elements.$cancelButton = $('#eao-cancel-changes');
        
        // Form fields
        this.elements.$orderDateField = this.elements.$form.find('#eao_order_date');
        this.elements.$orderTimeField = this.elements.$form.find('#eao_order_time');
        this.elements.$orderStatusField = this.elements.$form.find('#eao_order_status');
        this.elements.$customerHiddenInput = this.elements.$form.find('#eao_customer_id_hidden');
        this.elements.$customerDisplayName = $('#eao_customer_display_name');
        
        // Product-related elements
        this.elements.$globalOrderDiscountInput = $('#eao_order_products_discount_percent');
        // Global Discount change → update model via helpers, update affected cells via Tabulator, refresh summary
        try {
            const self = this;
            this.elements.$globalOrderDiscountInput.off('change.eaoGD').on('change.eaoGD', function(){
                const gd = parseFloat($(this).val() || 0) || 0;
                window.globalOrderDiscountPercent = gd;
                try {
                    const items = window.currentOrderItems || [];
                    // Apply to non-excluded items only
                    items.forEach(function(it){
                        if (!it.exclude_gd && window.EAO && window.EAO.ProductsHelpers) {
                            // Re-derive discounted price from GD percent
                            const base = parseFloat(it.price_raw||0) || 0;
                            it.discount_percent = gd;
                            it.discounted_price_fixed = Math.max(0, base * (1 - (gd/100)));
                            it.is_markup = false;
                            window.EAO.ProductsHelpers.applyQuantityChange(it, parseInt(it.quantity,10)||0); // keeps WC staging fresh
                        }
                    });
                } catch(_) {}
                // Update Tabulator cells if present
                try {
                    if (window.EAO && window.EAO.ProductsTable && window.EAO.ProductsTable._table) {
                        const table = window.EAO.ProductsTable._table;
                        const gdNow = window.globalOrderDiscountPercent || 0;
                        const partial = (window.currentOrderItems||[]).map(function(it){
                            const id = it.item_id || it._client_uid;
                            if (!id) return null;
                            if (it.exclude_gd) {
                                // Excluded rows unchanged here
                                return { id: id };
                            }
                            const discountHtml = window.EAO.ProductsTable._renderDiscount(it);
                            const discPriceHtml = window.EAO.ProductsTable._renderDiscPrice(it, gdNow);
                            const priceRaw = parseFloat(it.price_raw || 0) || 0;
                            const unit = priceRaw * (1 - (gdNow/100));
                            const totalHtml = '<span class="eao-line-total-display">' + (typeof eaoFormatPrice==='function' ? eaoFormatPrice(unit * (parseInt(it.quantity,10)||0)) : ('$'+(unit*(parseInt(it.quantity,10)||0)).toFixed(2))) + '</span>';
                            return { id: id, discount: discountHtml, disc_price: discPriceHtml, total: totalHtml };
                        }).filter(Boolean);
                        if (partial.length) { table.updateData(partial); }
                        // Update header counters and refresh summary
                        window.EAO.ProductsTable.updateHeaderCounters(window.currentOrderItems||[]);
                    }
                } catch(_) {}
                // Summary only
                const summary = self.calculateUnifiedSummaryData(window.currentOrderItems || []);
                self.refreshSummaryOnly(window.currentOrderItems || [], summary);
            });
        } catch(_){ }
        this.elements.$currentOrderItemsList = $('#eao-current-order-items-list');
        this.elements.$currentOrderItemsSummary = $('#eao-current-order-items-summary');
        this.elements.$productSearchInput = $('#eao_product_search_term');
        this.elements.$productSearchResults = $('#eao_product_search_results');
        // Harden: mark when free-text search fields are focused to avoid accidental re-renders
        try {
            const self = this;
            $(document)
                .off('focusin.eaoFocusLock focusout.eaoFocusLock')
                .on('focusin.eaoFocusLock', '#eao_product_search_term, #eao_customer_search_term', function(){
                    window.eaoInputFocusLock = true;
                })
                .on('focusout.eaoFocusLock', '#eao_product_search_term, #eao_customer_search_term', function(){
                    // small delay so browser autofill/select can complete before we re-enable renders
                    setTimeout(function(){ window.eaoInputFocusLock = false; }, 150);
                });
        } catch(_) {}
        
        // Log element availability for debugging
        console.log('[EAO Main Coordinator] DOM elements found:', {
            form: this.elements.$form.length > 0,
            updateButton: this.elements.$updateButton.length > 0,
            cancelButton: this.elements.$cancelButton.length > 0,
            orderDateField: this.elements.$orderDateField.length > 0,
            orderTimeField: this.elements.$orderTimeField.length > 0,
            orderStatusField: this.elements.$orderStatusField.length > 0,
            customerHiddenInput: this.elements.$customerHiddenInput.length > 0,
            globalOrderDiscountInput: this.elements.$globalOrderDiscountInput.length > 0
        });

        // Warn when manually changing status to Processing without a payment
        try {
            const self = this;
            this.elements.$orderStatusField.off('change.eaoStatusWarn').on('change.eaoStatusWarn', function(){
                // Ignore programmatic change right after successful payment
                if (window.EAO && window.EAO_Payment_AutoSettingStatus) { return; }
                const raw = String($(this).val() || '').trim();
                const val = raw.replace(/^wc-/, '');
                if ((val === 'processing') && !(window.EAO && window.EAO.Payment && window.EAO.Payment.hasProcessedPayment)) {
                    const ok = window.confirm('Are you sure you want to process the order without a payment?');
                    if (!ok) {
                        try { $(this).val('wc-pending').trigger('change.select2'); } catch(_){ $(this).val('wc-pending'); }
                    }
                }
            });
        } catch(_){ }

        // Ensure any existing fields have identifiers to avoid browser issues panel noise
        try {
            this.ensureFormFieldIdentifiers(this.elements.$form || $(document));
        } catch(_){ }

        // Start a lightweight MutationObserver to assign id/name to dynamically added inputs (TinyMCE dialogs, etc.)
        try { this.startGlobalFieldObserver(); } catch(_){ }
    },
    // Ensure inputs/textareas/selects have id or name; assign autogenerated ids when missing
    ensureFormFieldIdentifiers: function($root) {
        var $ctx = $root && $root.length ? $root : $(document);
        if (!window.eaoAutoIdCounter) { window.eaoAutoIdCounter = 1; }
        $ctx.find('input, textarea, select').each(function(){
            var $el = jQuery(this);
            var hasId = !!($el.attr('id'));
            var hasName = !!($el.attr('name'));
            if (!hasId && !hasName) {
                var newId = 'eao_auto_field_' + (window.eaoAutoIdCounter++);
                $el.attr('id', newId);
                $el.attr('name', newId);
            }
        });
    },

    // Observe new DOM nodes globally and apply identifiers to inputs without id/name
    startGlobalFieldObserver: function() {
        if (window.eaoFieldObserverStarted) { return; }
        if (typeof MutationObserver === 'undefined') { return; }
        var self = this;
        var observer = new MutationObserver(function(mutations){
            try {
                mutations.forEach(function(m){
                    if (!m.addedNodes || !m.addedNodes.length) { return; }
                    m.addedNodes.forEach(function(node){
                        try {
                            if (node.nodeType !== 1) { return; }
                            var $node = jQuery(node);
                            // Skip address editor sections to avoid interrupting user typing/autocomplete
                            if ($node.closest && ($node.closest('#eao-billing-address-section').length || $node.closest('#eao-shipping-address-section').length)) {
                                return;
                            }
                            if ($node.is('input, textarea, select')) {
                                self.ensureFormFieldIdentifiers($node);
                            } else if ($node.find) {
                                if ($node.closest && ($node.closest('#eao-billing-address-section').length || $node.closest('#eao-shipping-address-section').length)) {
                                    return;
                                }
                                self.ensureFormFieldIdentifiers($node);
                            }
                        } catch(_){ }
                    });
                });
            } catch(_){ }
        });
        observer.observe(document.body, { childList: true, subtree: true });
        window.eaoFieldObserverStarted = true;
    },

    // Inject or update the Roles line under the customer display dynamically
    updateCustomerRolesLine: function(customerId) {
        if (!customerId) return;
        const $containerRef = jQuery('#eao_customer_display_name').closest('.eao-details-line');
        if (!$containerRef.length) return;
        let $roles = jQuery('.eao-customer-roles-line');
        if (!$roles.length) {
            $roles = jQuery('<div class="eao-customer-roles-line" style="margin-top:4px;"><label class="eao-inline-label">Roles:</label> <span class="eao-inline-block">…</span></div>');
            $containerRef.after($roles);
        }
        jQuery.post(eaoEditorParams.ajax_url, { action: 'eao_get_customer_roles', nonce: eaoEditorParams.nonce, customer_id: customerId })
            .done(function(resp){
                if (resp && resp.success && resp.data) {
                    const names = (resp.data.roles && resp.data.roles.length) ? resp.data.roles.join(', ') : 'None';
                    $roles.find('.eao-inline-block').text(names);
                }
        });
    },
    
    /**
     * Initialize global state and variables
     */
    initializeGlobalState: function() {
        console.log('[EAO Main Coordinator] Initializing global state...');
        
        // Initialize grand total flag system early
        window.eaoGrandTotalUpdated = false;
        window.eaoCurrentGrandTotal = 0;
        console.log('[EAO Main Coordinator] Grand total flag system reset on page init');
        // Ensure points redemption conversion rate is globally available (points per $)
        try {
            // Redemption rate (points per $ used to pay) – default 10 pts = $1
            if (typeof window.pointsRedeemRate === 'undefined' || !window.pointsRedeemRate) {
                window.pointsRedeemRate = (window.eaoEditorParams && parseFloat(window.eaoEditorParams.points_dollar_rate) > 0)
                    ? parseFloat(window.eaoEditorParams.points_dollar_rate)
                    : 10;
            }
            // Earning rate (points awarded per $ out-of-pocket). Will be hydrated from server during render.
            if (typeof window.pointsEarnRate === 'undefined' || !window.pointsEarnRate) {
                window.pointsEarnRate = 0; // set later from server summary
            }
        } catch(_) {
            window.pointsRedeemRate = 10;
            window.pointsEarnRate = 0;
        }
        
        // Initialize global order discount
        this.initializeGlobalDiscount();
        
        // Initialize global product arrays
        window.currentOrderItems = window.currentOrderItems || [];
        window.stagedOrderItems = window.stagedOrderItems || [];
        window.itemsPendingDeletion = window.itemsPendingDeletion || [];
        window.stagedNotes = window.stagedNotes || [];
        
        // Initialize pending address variables
        window.pendingBillingAddress = null;
        window.pendingShippingAddress = null;
        
        console.log('[EAO Main Coordinator] Global state initialized');
    },
    
    /**
     * Initialize global discount value safely
     */
    initializeGlobalDiscount: function() {
        console.log('[EAO Main Coordinator] Initializing global discount...');
        
        if (this.elements.$globalOrderDiscountInput && this.elements.$globalOrderDiscountInput.length > 0) {
            try {
                const rawValue = this.elements.$globalOrderDiscountInput.val();
                if (rawValue !== null && rawValue !== undefined) {
                    this.state.globalOrderDiscountPercent = parseFloat(rawValue) || 0;
                    console.log('[EAO Main Coordinator] Global discount value read successfully:', this.state.globalOrderDiscountPercent);
                } else {
                    console.warn('[EAO Main Coordinator] Global discount value is null/undefined');
                    this.state.globalOrderDiscountPercent = 0;
                }
            } catch (error) {
                console.error('[EAO Main Coordinator] Error reading global discount value:', error);
                this.state.globalOrderDiscountPercent = 0;
            }
        } else {
            console.warn('[EAO Main Coordinator] Global discount input not found during initialization');
            this.state.globalOrderDiscountPercent = 0;
        }
        
        // Expose globally for template access
        window.globalOrderDiscountPercent = this.state.globalOrderDiscountPercent;
    },
    
    /**
     * Set up core event handlers that don't belong to specific modules
     */
    setupCoreEventHandlers: function() {
        console.log('[EAO Main Coordinator] Setting up core event handlers...');
        
        // Global discount input handler (needs re-rendering)
        this.setupGlobalDiscountHandler();
        
        // Cancel button handler (coordinates multiple modules)
        this.setupCancelButtonHandler();
        
        // Optimization event handlers for summary-only updates
        this.setupOptimizationEventHandlers();
        
        // Initialize event handlers for the Main Coordinator
        this.bindEvents();
        
        console.log('[EAO Main Coordinator] Core event handlers set up');
    },
    
    /**
     * Set up global discount input change handler (needs full re-rendering)
     */
    setupGlobalDiscountHandler: function() {
        const self = this;
        
        this.elements.$globalOrderDiscountInput.on('change input', function() {
            try {
                const rawValue = $(this).val();
                self.state.globalOrderDiscountPercent = parseFloat(rawValue) || 0;
                window.globalOrderDiscountPercent = self.state.globalOrderDiscountPercent;
                // Fast path: update WC staging fields and Tabulator cells only; avoid full repaint
                if (window.currentOrderItems && window.currentOrderItems.length) {
                    if (window.EAO && window.EAO.Utils && window.EAO.Utils.updateItemWCStagingFields) {
                        window.currentOrderItems.forEach(function(item) {
                            window.EAO.Utils.updateItemWCStagingFields(item, self.state.globalOrderDiscountPercent);
                        });
                    }
                    if (window.EAO && window.EAO.ProductsTable && typeof window.EAO.ProductsTable.applyGlobalDiscountToGrid === 'function') {
                        window.EAO.ProductsTable.applyGlobalDiscountToGrid(self.state.globalOrderDiscountPercent);
                        return; // summary-only refresh already handled in grid method
                    }
                }
                // Fallback to two-phase render if grid fast path not available
                self.renderAllOrderItemsAndSummary(window.currentOrderItems, window.currentOrderSummaryData);
                
            } catch (error) {
                console.error('[EAO Two-Phase] Error in global discount handler:', error);
            }
        });
    },
    
    /**
     * Set up cancel button event handler (coordinates multiple modules)
     */
    setupCancelButtonHandler: function() {
        const self = this;
        
        this.elements.$cancelButton.on('click', function() {
            console.log('[EAO Cancel] Cancel button clicked');
            
            if (confirm('Are you sure you want to cancel all changes?')) {
                console.log('[EAO Cancel] User confirmed cancel operation');
                self.performCancelOperation();
            } else {
                console.log('[EAO Cancel] User cancelled the cancel operation');
            }
        });
    },
    
    /**
     * Perform cancel operation by coordinating all modules
     */
    performCancelOperation: function() {
        console.log('[EAO Cancel] Starting cancel operation...');
        
        // Get initial data from Change Detection module
        const initialData = window.EAO && window.EAO.ChangeDetection ? window.EAO.ChangeDetection.initialOrderData : {};
        console.log('[EAO Cancel] Initial data available:', initialData);
        
        try {
            // Restore basic form fields
            this.restoreBasicFormFields(initialData);
            
            // Restore customer via module
            this.restoreCustomer(initialData);
            
            // Restore addresses via module
            this.restoreAddresses();
            
            // Restore global discount
            this.restoreGlobalDiscount(initialData);
            
            // Restore points
            this.restorePoints(initialData);
            
            // Restore notes via module
            this.restoreNotes();
            
            // Clear pending addresses
            this.clearPendingAddresses();
            
            // Trigger change detection
            this.triggerChangeDetection();
            
            console.log('[EAO Cancel] Cancel operation completed successfully');
        } catch (error) {
            console.error('[EAO Cancel] Error during cancel operation:', error);
        }
    },
    
    /**
     * Restore basic form fields during cancel
     */
    restoreBasicFormFields: function(initialData) {
        console.log('[EAO Cancel] Restoring basic form fields...');
        
        try {
            if (this.elements.$orderDateField.length > 0) {
                this.elements.$orderDateField.val(initialData.order_date);
            }
            if (this.elements.$orderTimeField.length > 0) {
                this.elements.$orderTimeField.val(initialData.order_time);
            }
            if (this.elements.$orderStatusField.length > 0) {
                this.elements.$orderStatusField.val(initialData.order_status).trigger('change.select2');
            }
            console.log('[EAO Cancel] Basic form fields restored');
        } catch (error) {
            console.error('[EAO Cancel] Error restoring basic form fields:', error);
        }
    },
    
    /**
     * Restore customer during cancel
     */
    restoreCustomer: function(initialData) {
        console.log('[EAO Cancel] Restoring customer...');
        
        if (window.EAO && window.EAO.Customers) {
            window.EAO.Customers.resetToInitialCustomer(initialData);
        } else {
            // Fallback
            try {
                if (this.elements.$customerHiddenInput.length > 0) {
                    this.elements.$customerHiddenInput.val(initialData.customer_id);
                }
                if (this.elements.$customerDisplayName.length > 0) {
                    this.elements.$customerDisplayName.html(initialData.customer_display_html);
                }
            } catch (error) {
                console.error('[EAO Cancel] Error in customer fallback:', error);
            }
        }
    },
    
    /**
     * Restore addresses during cancel
     */
    restoreAddresses: function() {
        console.log('[EAO Cancel] Restoring addresses...');
        
        if (window.EAO && window.EAO.Addresses) {
            window.EAO.Addresses.resetToInitialAddresses();
        }
    },
    
    /**
     * Restore global discount during cancel
     */
    restoreGlobalDiscount: function(initialData) {
        console.log('[EAO Cancel] Restoring global discount...');
        
        try {
            if (this.elements.$globalOrderDiscountInput.length > 0) {
                this.elements.$globalOrderDiscountInput.val(initialData.global_order_discount_percent);
                this.state.globalOrderDiscountPercent = initialData.global_order_discount_percent;
                window.globalOrderDiscountPercent = this.state.globalOrderDiscountPercent;
            }
        } catch (error) {
            console.error('[EAO Cancel] Error restoring global discount:', error);
        }
    },
    
    /**
     * Restore points during cancel
     */
    restorePoints: function(initialData) {

        
        try {
            const $pointsRedeemInput = $('#eao_points_to_redeem');
            const $pointsSlider = $('#eao_points_slider');
            
            if ($pointsRedeemInput.length > 0) {
                $pointsRedeemInput.val(initialData.points_to_redeem || 0);
            }
            if ($pointsSlider.length > 0) {
                $pointsSlider.val(initialData.points_to_redeem || 0);
            }
            window.eaoCurrentPointsDiscount = null;
        } catch (error) {
            console.error('[EAO Cancel] Error restoring points:', error);
        }
    },
    
    /**
     * Restore notes during cancel
     */
    restoreNotes: function() {
        console.log('[EAO Cancel] Restoring notes...');
        
        if (window.EAO && window.EAO.OrderNotes) {
            window.EAO.OrderNotes.resetToInitialState();
        } else {
            // Fallback
            window.stagedNotes = [];
        }
    },
    
    /**
     * Clear pending addresses during cancel
     */
    clearPendingAddresses: function() {
        console.log('[EAO Cancel] Clearing pending addresses...');
        
        if (window.EAO && window.EAO.Addresses) {
            window.EAO.Addresses.clearPendingAddresses();
        }
    },
    
    /**
     * Trigger change detection after cancel
     */
    triggerChangeDetection: function() {
        console.log('[EAO Cancel] Triggering change detection...');
        
        if (window.EAO && window.EAO.ChangeDetection) {
            window.EAO.ChangeDetection.triggerCheck();
        }
    },
    
    /**
     * Set up optimization event handlers for summary-only updates
     */
    setupOptimizationEventHandlers: function() {
        const self = this;
        
        // OPTIMIZATION: Centralized event handlers for summary-only updates
        $(document).on('eaoPointsDiscountChanged eaoTaxUpdated', function() {
            // For events that only affect summary calculations
            const calculatedSummary = self.calculateUnifiedSummaryData(window.currentOrderItems);
            self.refreshSummaryOnly(window.currentOrderItems, calculatedSummary);
        });

        // OPTIMIZATION: Handler for combined shipping + summary updates
        $(document).on('eaoShippingAndSummaryUpdate', function() {
            // For when shipping data and other summary elements change together
            const calculatedSummary = self.calculateUnifiedSummaryData(window.currentOrderItems);
            self.refreshSummaryOnly(window.currentOrderItems, calculatedSummary);
        });
        
        // UPDATED: Event handler for shipping rate changes - use two-phase rendering for YITH Points preservation
        $(document).on('eaoShippingRateApplied', function() {

            
            // Use two-phase rendering system to preserve YITH Points and other summary components
            self.renderAllOrderItemsAndSummary(window.currentOrderItems, window.currentOrderSummaryData);
        });
    },
    
    /**
     * Initialize all sub-modules in proper order
     */
    initializeAllModules: function() {
        console.log('[EAO Main Coordinator] Initializing all modules...');
        
        // Initialize Change Detection module first (foundation)
        if (window.EAO && window.EAO.ChangeDetection) {
            console.log('[EAO Main Coordinator] Initializing Change Detection module...');
            window.EAO.ChangeDetection.init();
        } else {
            console.error('[EAO Main Coordinator] Change Detection module not loaded!');
        }
        
        // Initialize Products module
        if (window.EAO && window.EAO.Products) {
            console.log('[EAO Main Coordinator] Initializing Products module...');
            window.EAO.Products.init();
        } else {
            console.error('[EAO Main Coordinator] Products module not loaded!');
        }
        
        // Initialize Addresses module
        if (window.EAO && window.EAO.Addresses) {
            console.log('[EAO Main Coordinator] Initializing Addresses module...');
            window.EAO.Addresses.init();
        } else {
            console.error('[EAO Main Coordinator] Addresses module not loaded!');
        }
        
        // Initialize Customers module
        if (window.EAO && window.EAO.Customers) {
            console.log('[EAO Main Coordinator] Initializing Customers module...');
            window.EAO.Customers.init();
        } else {
            console.error('[EAO Main Coordinator] Customers module not loaded!');
        }
        
        // Initialize Order Notes module
        if (window.EAO && window.EAO.OrderNotes) {
            console.log('[EAO Main Coordinator] Initializing Order Notes module...');
            window.EAO.OrderNotes.init();
        } else {
            console.error('[EAO Main Coordinator] Order Notes module not loaded!');
        }
        
        // Initialize YITH Points module
        if (window.EAO && window.EAO.YITHPoints) {

            window.EAO.YITHPoints.init();
        } else {
            console.warn('[EAO Main Coordinator] YITH Points module not loaded (optional).');
        }
        
        // Initialize Form Submission module
        if (window.EAO && window.EAO.FormSubmission) {
            console.log('[EAO Main Coordinator] Initializing Form Submission module...');
            window.EAO.FormSubmission.init();
        } else {
            console.error('[EAO Main Coordinator] Form Submission module not loaded!');
        }
        
        // Apply Select2 if available
        if ($.fn.select2) {
            $('.wc-enhanced-select').select2({
                minimumResultsForSearch: Infinity
            });
        }
        
        console.log('[EAO Main Coordinator] All modules initialized');
    },
    
    /**
     * Initialize WordPress postbox toggles
     */
    initializePostboxToggles: function() {
        console.log('[EAO Main Coordinator] Initializing postbox toggles...');
        
        if (typeof postboxes !== 'undefined' && typeof pagenow !== 'undefined') {
            postboxes.add_postbox_toggles(pagenow);
        } else {
            console.warn('[EAO Main Coordinator] Postboxes or pagenow undefined, cannot initialize toggles');
        }
    },
    
    /**
     * Perform initial load of products and data
     */
    performInitialLoad: function() {
        console.log('[EAO Main Coordinator] Performing initial load...');
        
        // Initial call to load product items and summary
        if (typeof fetchAndDisplayOrderProducts === 'function') {
            fetchAndDisplayOrderProducts();
        } else {
            console.warn('[EAO Main Coordinator] fetchAndDisplayOrderProducts not available yet');
        }
    },
    
    /**
     * Perform final initialization tasks
     */
    performFinalInitialization: function() {
        const self = this;
        
        console.log('[EAO Main Coordinator] Performing final initialization...');
        
        // Ensure button states are correct after all data is loaded
        setTimeout(function() {
            if (window.EAO && window.EAO.ChangeDetection) {
                console.log('[EAO Main Coordinator] Final initialization - checking button states');
                window.EAO.ChangeDetection.triggerCheck();
            }
            
            // Emergency overlay cleanup
            self.performEmergencyOverlayCleanup();
        }, 100);
    },
    
    /**
     * Emergency overlay cleanup during initialization
     */
    performEmergencyOverlayCleanup: function() {
        setTimeout(function() {
            const $overlay = $('#eao-save-overlay');
            if ($overlay.is(':visible')) {
                console.warn('[EAO Main Coordinator] EMERGENCY: Overlay still visible after initialization, force closing');
                $overlay.fadeOut(300);
                sessionStorage.removeItem('eao_save_overlay_visible');
                sessionStorage.removeItem('eao_save_stage');
                sessionStorage.removeItem('eao_save_progress');
                sessionStorage.removeItem('eao_save_progress_text');
            }
        }, 5000);
    },
    
    // =============================================================================
    // RENDERING COORDINATION FUNCTIONS
    // =============================================================================
    
    /**
     * Safety wrapper for calculateCurrentSummaryData with fallback
     */
    safeCalculateCurrentSummaryData: function(itemsToRender) {
        try {
            // Use unified calculation system directly
            return this.calculateUnifiedSummaryData(itemsToRender);
        } catch (error) {
            console.warn('[EAO Main Coordinator] Error in unified calculation, using basic fallback:', error);
            return {
                items_subtotal_raw: 0,
                items_subtotal_formatted_html: '$0.00',
                products_total_raw: 0,
                products_total_formatted_html: '$0.00',
                total_item_level_discount_raw: 0,
                total_item_level_discount_formatted_html: '$0.00'
            };
        }
    },
    
    /**
     * Calculate header statistics for product list
     */
    calculateProductListHeader: function(itemsToRender) {
        let headerTotalSkus = 0;
        let headerTotalQuantity = 0;
        
        if (itemsToRender && itemsToRender.length > 0) {
            const uniqueProductIds = new Set();
            itemsToRender.forEach(function(item) {
                if (!item.isPendingDeletion) {
                    uniqueProductIds.add(item.product_id + '_' + (item.variation_id || '0'));
                    headerTotalQuantity += parseInt(item.quantity, 10) || 0;
                }
            });
            headerTotalSkus = uniqueProductIds.size;
        }
        
        return {
            totalSkus: headerTotalSkus,
            totalQuantity: headerTotalQuantity
        };
    },
    
    /**
     * Render the product list with header
     */
    renderProductList: function(itemsToRender) {
        // When Tabulator grid is active, avoid clearing the container between rapid
        // two‑phase renders. Clearing while the grid is mid‑paint can leave the
        // table blank until the next cycle. We let the grid update its rows instead.
        if (!(window.EAO && window.EAO.ProductsTable && typeof window.EAO.ProductsTable.render === 'function')) {
            this.elements.$currentOrderItemsList.empty();
        }
        // Ensure cached element points to the current DOM node
        try {
            const el = document.getElementById('eao-current-order-items-list');
            if (!this.elements.$currentOrderItemsList || !this.elements.$currentOrderItemsList.length || (this.elements.$currentOrderItemsList[0] !== el)) {
                this.elements.$currentOrderItemsList = jQuery('#eao-current-order-items-list');
            }
        } catch(_) {}
        // Tabulator-only path (no fallback) for focused debugging
        if (window.EAO && window.EAO.ProductsTable && typeof window.EAO.ProductsTable.render === 'function') {
            try {
                console.log('[EAO][ProductsGrid][Main] renderProductList items:', (itemsToRender && itemsToRender.length) || 0);
                if (itemsToRender && itemsToRender.length) {
                    const first = itemsToRender[0];
                    console.log('[EAO][ProductsGrid][Main] first item snapshot:', {
                        product_id: first && first.product_id,
                        item_id: first && first.item_id,
                        name: first && first.name,
                        quantity: first && first.quantity
                    });
                }
            } catch(e) {}
            window.EAO.ProductsTable.render(itemsToRender, { container: this.elements.$currentOrderItemsList[0] });
            return;
        }
        console.error('[EAO] ProductsTable.render not available.');
    },

    /**
     * Show Points Earning details popup for a specific item
     * Leverages existing backend preview to fetch breakdown and renders a simple modal
     */
    showPointsDetailsForItem: function(orderItemId) {
        let orderId = jQuery('#eao-order-form').find('input[name="eao_order_id"]').val();
        if (!orderId && window.eaoEditorParams && window.eaoEditorParams.order_id) {
            orderId = window.eaoEditorParams.order_id;
        }
        if (!orderId || !orderItemId) return;
        const $ = jQuery;
        // Close any existing modal to prevent duplicate overlays
        try { jQuery('.eao-modal-backdrop').remove(); } catch(_) {}
        const $modal = jQuery('<div class="eao-modal-backdrop" style="position:fixed;left:0;top:0;right:0;bottom:0;background:rgba(0,0,0,0.35);z-index:100000;display:flex;align-items:center;justify-content:center;"></div>');
        const $content = jQuery('<div class="eao-modal-content" style="background:#fff;max-width:640px;width:90%;padding:16px;border-radius:4px;box-shadow:0 6px 24px rgba(0,0,0,.2);"></div>');
        $content.append('<h2 style="margin-top:0;">Points Earning Details</h2>');
        $content.append('<div class="eao-modal-body"><p>Loading...</p></div>');
        const $close = jQuery('<button type="button" class="button">Close</button>').on('click', () => $modal.remove());
        $content.append(jQuery('<div style="text-align:right;margin-top:12px;"></div>').append($close));
        $modal.append($content);
        jQuery('body').append($modal);

        // Fetch details for a specific item via dedicated endpoint
        jQuery.ajax({
            url: eaoEditorParams.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: { action: 'eao_get_points_details_for_item', order_id: orderId, order_item_id: orderItemId, nonce: eaoEditorParams.nonce },
        }).done(function(resp){
            try {
                // Be tolerant to various WP AJAX response shapes
                let payload = resp;
                if (typeof payload === 'string') {
                    try { payload = JSON.parse(payload); } catch(_){ payload = { data: null }; }
                }
                const item = (payload && payload.data) ? payload.data : null;
                if (!item || (payload && payload.success === false)) { $content.find('.eao-modal-body').html('<p>Failed to load details.</p>'); return; }
                let html = '';
                html += '<div>';
                html += '<p><strong>Product:</strong> ' + (item.product_name || '') + '</p>';
                const qtyVal = parseInt(item.quantity || 0, 10) || 0;
                const totalPts = item.total_points || 0;
                const perItemPts = qtyVal > 0 ? (totalPts / qtyVal) : 0;
                html += '<p><strong>Quantity:</strong> ' + qtyVal + '</p>';
                html += '<p><strong>Points per item (after discounts):</strong> ' + (Math.round(perItemPts * 100) / 100) + '</p>';
                const totalPts2 = totalPts;
                const lineAmount = parseFloat(item.line_total_amount || 0);
                // pointsDollarRate = points per $; if missing treat 10 pts = $1 default for display
                const rate = (window.pointsDollarRate && window.pointsDollarRate > 0) ? window.pointsDollarRate : 10;
                const ptsValueDollars = totalPts / rate;
                const percentOfLine = (lineAmount > 0) ? ((ptsValueDollars / lineAmount) * 100) : 0;
                // Show dollar equivalent using redemption conversion (10 pts = $1 default)
                const redeemRateShow = (window.pointsRedeemRate && window.pointsRedeemRate > 0) ? window.pointsRedeemRate : 10;
                const previewDollarValue = (totalPts / redeemRateShow);
                html += '<p><strong>Total points:</strong> ' + totalPts2 + ' <span style="color:#555;">($' + previewDollarValue.toFixed(2) + ')</span></p>';
                if (item.applied_rule) {
                    const r = item.applied_rule;
                    html += '<p><strong>Applied rule:</strong> ' + (r.name || r.rule_name || 'Rule') + '</p>';
                }
                $content.find('.eao-modal-body').html(html + '</div>');
            } catch(_e) {
                $content.find('.eao-modal-body').html('<p>Could not parse details.</p>');
            }
        }).fail(function(){
            $content.find('.eao-modal-body').html('<p>Failed to load details.</p>');
        });
    },
    
    /**
     * SINGLE SOURCE OF TRUTH: The ONLY function allowed to manipulate summary DOM
     * All other summary functions MUST call this function
     */
    updateSummaryDisplay: function(itemsToRender, summaryData, options = {}) {
        options = options || {};
        // Re-entrancy guard to prevent render → refresh → render loops
        if (window.eaoSummaryUpdateInProgress === true) {
            return;
        }
        window.eaoSummaryUpdateInProgress = true;
        try {
        
        // Handle error states
        if (options.error_message) {
            this.elements.$currentOrderItemsSummary.html(`<p class="eao-error-message">${options.error_message}</p>`);
            return;
        }
        
        // Handle loading states
        if (options.loading) {
            this.elements.$currentOrderItemsSummary.html('<p style="text-align: center; color: #666;">Loading summary...</p>');
            return;
        }
        
        // Normal summary rendering - delegate to existing renderOrderSummary
        this.renderOrderSummary(itemsToRender, summaryData);
        
        // Execute post-render tasks for full refreshes
        if (options.context === 'full_refresh') {
            this.executePostRenderTasks();
        }
        
        // Always trigger change detection after summary updates
        if (window.EAO && window.EAO.ChangeDetection) {
            window.EAO.ChangeDetection.triggerCheck();
        }
        
        // Optional: Trigger hooks for extensions (like YITH points max calculation)
        if (options.trigger_hooks !== false) {
            // Tag this as 'yith_update' so YITH listeners don't re-render on regular summary cycles
            const hookOptions = $.extend ? $.extend({}, options, { context: 'yith_update' }) : options;
            $(document).trigger('eaoSummaryUpdated', [summaryData, hookOptions]);
        }

        // Refresh YITH Points Earning box only if container exists
        // Avoid polling entirely: refresh once on initial full render, and on explicit changes only
        try {
            var hasLegacyYithBox = (jQuery('.eao-yith-earning-section .eao-points-earning-container').length > 0);
            if (!hasLegacyYithBox) { return; }
            if (window.eaoEditorParams && window.eaoEditorParams.order_id && window.eaoEditorParams.ajax_url) {
                var shouldRefresh = false;
                // One-time refresh after first full render
                if (options && options.context && String(options.context).indexOf('full_refresh') === 0) {
                    if (!window.eaoPointsInitialRefreshed) {
                        shouldRefresh = true;
                        window.eaoPointsInitialRefreshed = true;
                    }
                }
                // Explicit refresh only when caller marks there are changes (e.g., product qty/discount)
                if (options && options.has_changes === true) { shouldRefresh = true; }
                if (!shouldRefresh) { return; }
                if (window.eaoInputFocusLock) { return; }
                // Throttle requests to avoid floods during rapid re-renders
                var nowTs = Date.now();
                if (typeof window.eaoPointsRefreshLastTs === 'number' && (nowTs - window.eaoPointsRefreshLastTs) < 700) {
                    // Too soon since the last refresh; skip this cycle
                    return;
                }
                var stagedPointsAmount = 0;
                try {
                    // Prefer DOM (single source of truth for current staged value)
                    var $pointsRow = $('.eao-summary-points-discount .eao-summary-monetary-value');
                    var domVal = null;
                    if ($pointsRow.length) {
                        domVal = parseFloat(($pointsRow.text() || '0').replace(/[$,\s-]/g, ''));
                        if (!isNaN(domVal)) { stagedPointsAmount = domVal; }
                    }
                    // Fallback to global snapshot only when DOM is unavailable
                    if ((domVal === null || isNaN(domVal)) && window.eaoStagedPointsDiscount && typeof window.eaoStagedPointsDiscount.amount !== 'undefined') {
                        stagedPointsAmount = parseFloat(window.eaoStagedPointsDiscount.amount) || 0;
                    }
                    // If DOM shows zero, clear stale snapshot to avoid phantom deductions
                    if (!isNaN(domVal) && domVal === 0 && window.eaoStagedPointsDiscount) {
                        try { window.eaoStagedPointsDiscount.amount = 0; } catch(_) {}
                    }
                } catch(e) {}
                // Track latest refresh so late responses don't overwrite current state
                window.eaoPointsRefreshGen = (window.eaoPointsRefreshGen || 0) + 1;
                const refreshGen = window.eaoPointsRefreshGen;
                window.eaoPointsRefreshLastTs = nowTs;
                $.post(window.eaoEditorParams.ajax_url, {
                    action: 'eao_refresh_points_earning',
                    nonce: window.eaoEditorParams.nonce,
                    order_id: window.eaoEditorParams.order_id,
                    staged_points_amount: stagedPointsAmount
                }, function(resp){
                    // Ignore stale responses or when a short lock is active just after user interaction
                    if ((window.eaoPointsRefreshGen && refreshGen !== window.eaoPointsRefreshGen) ||
                        (window.eaoPointsLockUntil && Date.now() < window.eaoPointsLockUntil)) {
                        return;
                    }
                    // Update last timestamp to now to gate subsequent refreshes triggered by DOM updates
                    window.eaoPointsRefreshLastTs = Date.now();
                    if (resp && resp.success && resp.data && resp.data.html) {
                        // Update only if one container exists; avoid duplicate sections
                        var $containers = $('.eao-yith-earning-section .eao-points-earning-container');
                        if ($containers && $containers.length === 1) {
                            $containers.eq(0).html(resp.data.html);
                        } else if ($containers && $containers.length > 1) {
                            // Consolidate to the first instance and clear others
                            $containers.eq(0).html(resp.data.html);
                            for (var i = 1; i < $containers.length; i++) {
                                jQuery($containers[i]).empty();
                            }
                        }
                        // Propagate updated points-per-dollar to client state only; avoid overwriting the panel
                        try {
                            if (resp.data.points_per_dollar) {
                                window.currentOrderSummaryData = window.currentOrderSummaryData || {};
                                var pas = (window.currentOrderSummaryData.points_award_summary || {});
                                pas.points_per_dollar = parseFloat(resp.data.points_per_dollar);
                                window.currentOrderSummaryData.points_award_summary = pas;
                                if (!isNaN(pas.points_per_dollar) && pas.points_per_dollar > 0) {
                                    window.pointsEarnRate = pas.points_per_dollar;
                                }
                            }
                        } catch(e) { /* no-op */ }
                    }
                });
            }
        } catch (e) {}
        
        } finally {
            window.eaoSummaryUpdateInProgress = false;
        }
    },

    /**
     * Render order summary section
     */
    renderOrderSummary: function(itemsToRender, summaryData) {
        // Avoid repainting while user is focusing a free-text search field
        if (window.eaoInputFocusLock) { return; }
        this.elements.$currentOrderItemsSummary.empty();
        
        if (!summaryData) {
            return;
        }
        
        this.state.currentOrderSummaryData = summaryData;

        // Pre-clamp points BEFORE calculating client summary so grand total reflects new cap
        try {
            // Prevent re-entrant pre-clamp if we are already inside a summary update
            if (!window.eaoSummaryUpdateInProgress) {
                const $sel = jQuery('#order_status');
                const rawStatus = ($sel && $sel.length) ? String($sel.val()||'') : (window.eaoEditorParams ? ('wc-' + String(window.eaoEditorParams.order_status||'')) : '');
                const normalizedStatus = String(rawStatus||'').replace(/^wc-/,'').toLowerCase();
                const isUnpaidOrder = !(normalizedStatus === 'processing' || normalizedStatus === 'completed' || normalizedStatus === 'shipped');
                if (isUnpaidOrder) {
                    const totalAvail = parseInt(window.totalAvailablePoints||0,10) || 0;
                    const redeemRate = (window.pointsRedeemRate && window.pointsRedeemRate > 0) ? window.pointsRedeemRate : 10;
                    const hardCapPoints = this.calculateMaxPoints(0);
                    let stagedPts = (window.eaoStagedPointsDiscount && typeof window.eaoStagedPointsDiscount.points !== 'undefined') ? parseInt(window.eaoStagedPointsDiscount.points,10)||0 : null;
                    let currentPts = (window.eaoCurrentPointsDiscount && typeof window.eaoCurrentPointsDiscount.points !== 'undefined') ? parseInt(window.eaoCurrentPointsDiscount.points,10)||0 : null;
                    if (totalAvail <= 0 || (!isNaN(hardCapPoints) && hardCapPoints <= 0)) {
                        try { if (window.eaoStagedPointsDiscount) { window.eaoStagedPointsDiscount.points = 0; window.eaoStagedPointsDiscount.amount = 0; } } catch(_){}
                        try { if (window.eaoCurrentPointsDiscount) { window.eaoCurrentPointsDiscount.points = 0; window.eaoCurrentPointsDiscount.amount = 0; } } catch(_){}
                    } else if (!isNaN(hardCapPoints)) {
                        if (stagedPts !== null && stagedPts > hardCapPoints) {
                            try { window.eaoStagedPointsDiscount.points = hardCapPoints; window.eaoStagedPointsDiscount.amount = (hardCapPoints / redeemRate); } catch(_){}
                        }
                        if (currentPts !== null && currentPts > hardCapPoints) {
                            try { window.eaoCurrentPointsDiscount.points = hardCapPoints; window.eaoCurrentPointsDiscount.amount = (hardCapPoints / redeemRate); } catch(_){}
                        }
                    }
                }
            }
        } catch(_preClampErr) { }

        // Calculate current summary data from items to ensure accuracy (uses clamped values)
        const clientCalculatedSummary = this.safeCalculateCurrentSummaryData(itemsToRender);
        
        // Build order summary table (left column content)
        let summaryHtml = '<table class="eao-summary-table">';

        // Row 1: Items Total (Gross)
        summaryHtml += this.createSummaryRow(
            eaoEditorParams.i18n.item_total_gross || 'Items Total (Gross):',
            clientCalculatedSummary.items_subtotal_formatted_html || eaoFormatPrice(clientCalculatedSummary.items_subtotal_raw || 0),
            true
        );

        // Total Item Level Discount
        if (parseFloat(clientCalculatedSummary.total_item_level_discount_raw || 0) > 0) {
            summaryHtml += this.createSummaryRow(
                eaoEditorParams.i18n.total_product_discount || 'Total Product Discount:',
                '-' + (clientCalculatedSummary.total_item_level_discount_formatted_html || eaoFormatPrice(clientCalculatedSummary.total_item_level_discount_raw || 0)),
                true,
                'eao-total-product-discount-row'
            );
        }

        // Products Total (Net)
        summaryHtml += this.createSummaryRow(
            eaoEditorParams.i18n.products_total_net || 'Products Total (Net):',
            clientCalculatedSummary.products_total_formatted_html || eaoFormatPrice(clientCalculatedSummary.products_total_raw || 0),
            true,
            'eao-summary-subtotal-line'
        );

        // We will build the right points panel after finishing the full left table

        // YITH Points Discount Section - Inline when editable, fixed on paid orders
        let pointsDiscountForGrandTotalCalc = 0;
        
        // Check if we have YITH points data available for inline slider
        const hasYithData = (typeof window.totalAvailablePoints !== 'undefined' && 
                           typeof window.customerCurrentPoints !== 'undefined' && 
                           typeof window.existingPointsRedeemed !== 'undefined');
        
		// NEW: Also check if YITH is available but data is loading
		// Determine if order has a customer using hidden input fallback (most reliable),
		// then fall back to localized params (order_customer_id or initial_customer_id)
		const hasOrderCustomer = (function(){
			try {
				let id = 0;
				const $hid = jQuery('#eao_customer_id_hidden');
				if ($hid && $hid.length) {
					id = parseInt($hid.val()||'0', 10) || 0;
				}
				return id > 0;
			} catch(_){ return false; }
		})();
        

        
        // Detect order status for editability
        function getOrderStatusSlug(){
            try {
                const $sel = jQuery('#order_status');
                if ($sel && $sel.length) {
                    const val = String($sel.val() || '').trim();
                    if (val) { return val.replace('wc-',''); }
                }
            } catch(_e){}
            if (typeof window.eaoEditorParams !== 'undefined' && window.eaoEditorParams.order_status) {
                return String(window.eaoEditorParams.order_status);
            }
            return '';
        }
        const statusForDiscount = getOrderStatusSlug();
        // Persist points discount on paid/shipped orders so item-level edits don't zero it
        try {
            const isPaid = (statusForDiscount === 'processing' || statusForDiscount === 'completed' || statusForDiscount === 'shipped');
            if (isPaid && (typeof window.eaoPersistedPointsDiscount === 'undefined')) {
                let amt = 0, pts = 0;
                if (window.eaoCurrentPointsDiscount && parseFloat(window.eaoCurrentPointsDiscount.amount||0) > 0) {
                    amt = parseFloat(window.eaoCurrentPointsDiscount.amount)||0;
                    pts = parseInt(window.eaoCurrentPointsDiscount.points||0,10)||0;
                } else if (typeof window.existingDiscountAmount !== 'undefined') {
                    amt = parseFloat(window.existingDiscountAmount||0)||0;
                    pts = parseInt(window.existingPointsRedeemed||0,10)||0;
                }
                window.eaoPersistedPointsDiscount = amt;
                window.eaoPersistedPointsPoints = pts;
            }
        } catch(_e) {}
        
        if (hasYithData) {
            // If no available points for the customer on an unpaid order, force zero discount and show $0 line
            try {
                const $sel = jQuery('#order_status');
                const rawStatus = ($sel && $sel.length) ? String($sel.val()||'') : (window.eaoEditorParams ? ('wc-' + String(window.eaoEditorParams.order_status||'')) : '');
                const normalizedStatus = String(rawStatus||'').replace(/^wc-/,'').toLowerCase();
                const isUnpaid = !(normalizedStatus === 'processing' || normalizedStatus === 'completed' || normalizedStatus === 'shipped');
                const hasZeroAvailable = (parseInt(window.totalAvailablePoints||0,10) === 0);
                if (isUnpaid && hasZeroAvailable) {
                    try { if (window.eaoStagedPointsDiscount) { window.eaoStagedPointsDiscount.points = 0; window.eaoStagedPointsDiscount.amount = 0; } } catch(_){ }
                    try { if (window.eaoCurrentPointsDiscount) { window.eaoCurrentPointsDiscount.points = 0; window.eaoCurrentPointsDiscount.amount = 0; } } catch(_){ }
                    // Ensure grand total uses zero points in this render
                    pointsDiscountForGrandTotalCalc = 0;
                    try { console.log('[EAO Points Clamp][render-zeroBalance] Forced pointsDiscountForGrandTotalCalc=0'); } catch(_) {}
                }
            } catch(_){ }

            if (window.totalAvailablePoints > 0) {

            // ALWAYS show points line when points are available
            // Check for staged points discount first (user is changing), then current discount
            // IMPORTANT: if an inline input exists and is 0, force both flags to false to avoid showing stale discount
            const $liveInp = jQuery('#eao_points_to_redeem_inline');
            const liveInputZero = $liveInp.length ? (parseInt($liveInp.val() || '0', 10) === 0) : false;
            const hasStagedPoints = !liveInputZero && window.eaoStagedPointsDiscount && window.eaoStagedPointsDiscount.points > 0;
            const hasCurrentPoints = !liveInputZero && window.eaoCurrentPointsDiscount && window.eaoCurrentPointsDiscount.amount > 0;
            
            if (hasStagedPoints || hasCurrentPoints) {
                // Use staged values if user is changing, otherwise use current values
                let pointsData, currentPoints;
                if (hasStagedPoints) {
                    pointsData = window.eaoStagedPointsDiscount;
                    currentPoints = pointsData.points;
                    pointsDiscountForGrandTotalCalc = parseFloat(pointsData.amount);

                } else {
                    pointsData = window.eaoCurrentPointsDiscount;
                    currentPoints = pointsData.points || 0;
                    pointsDiscountForGrandTotalCalc = parseFloat(pointsData.amount);

                }
                // Clamp to max points for the new customer (unpaid orders only)
                try {
                    const isPaid = (statusForDiscount === 'processing' || statusForDiscount === 'completed' || statusForDiscount === 'shipped');
                    if (!isPaid) {
                        // IMPORTANT: Pass 0 to get the true cap; passing current would return at least current
                        const maxPointsAllowed = this.calculateMaxPoints(0);
                        try { console.log('[EAO Points Clamp][render-hasDiscount] currentPoints=%s maxAllowed=%s staged?%s', currentPoints, maxPointsAllowed, !!window.eaoStagedPointsDiscount); } catch(_) {}
                        if (!isNaN(maxPointsAllowed) && currentPoints > maxPointsAllowed) {
                            currentPoints = maxPointsAllowed;
                            const rr = (window.pointsRedeemRate && window.pointsRedeemRate > 0) ? window.pointsRedeemRate : 10;
                            pointsDiscountForGrandTotalCalc = (currentPoints / rr);
                            try { console.log('[EAO Points Clamp][render-hasDiscount] CLAMPED to points=%s amount=%s', currentPoints, pointsDiscountForGrandTotalCalc); } catch(_) {}
                            try { if (window.eaoStagedPointsDiscount) { window.eaoStagedPointsDiscount.points = currentPoints; window.eaoStagedPointsDiscount.amount = pointsDiscountForGrandTotalCalc; } } catch(_){ }
                            try { if (window.eaoCurrentPointsDiscount) { window.eaoCurrentPointsDiscount.points = currentPoints; window.eaoCurrentPointsDiscount.amount = pointsDiscountForGrandTotalCalc; } } catch(_){ }
                        }
                    }
                } catch(_){ }
                if (statusForDiscount === 'processing' || statusForDiscount === 'completed' || statusForDiscount === 'shipped') {
                    if (typeof window.eaoPersistedPointsDiscount !== 'undefined') {
                        pointsDiscountForGrandTotalCalc = parseFloat(window.eaoPersistedPointsDiscount)||pointsDiscountForGrandTotalCalc;
                    }
                    // Read-only on paid orders
                    summaryHtml += this.createSummaryRow(
                        'Points Discount:',
                        '-' + eaoFormatPrice(pointsDiscountForGrandTotalCalc),
                        true,
                        'eao-summary-points-discount'
                    );
                } else {
                    // Editable with input + slider
                const maxPoints = this.calculateMaxPoints(currentPoints);
                const labelWithInput = `Points Discount (<input type="number" id="eao_points_to_redeem_inline" name="eao_points_to_redeem" class="eao-points-input eao-points-input-inline" min="0" max="${maxPoints}" value="${currentPoints}" step="1"> points):`;
                summaryHtml += this.createSummaryRowWithControl(
                    labelWithInput,
                    '-' + eaoFormatPrice(pointsDiscountForGrandTotalCalc),
                    this.createInlinePointsSlider(currentPoints),
                    true,
                    'eao-summary-points-discount'
                );
                }
            } else {
                // No active discount - show zero amount with slider but check for staged values
                let currentPoints = 0;
                if (!liveInputZero && window.eaoStagedPointsDiscount && window.eaoStagedPointsDiscount.points > 0) {
                    currentPoints = window.eaoStagedPointsDiscount.points;
                    pointsDiscountForGrandTotalCalc = parseFloat(window.eaoStagedPointsDiscount.amount);

                }
                // Clamp staged points to max for unpaid orders
                try {
                    const isPaid = (statusForDiscount === 'processing' || statusForDiscount === 'completed' || statusForDiscount === 'shipped');
                    if (!isPaid && currentPoints > 0) {
                        // IMPORTANT: Pass 0 to get the true cap; passing current would return at least current
                        const maxPts = this.calculateMaxPoints(0);
                        try { console.log('[EAO Points Clamp][render-noDiscount] stagedPoints=%s maxAllowed=%s', currentPoints, maxPts); } catch(_) {}
                        if (!isNaN(maxPts) && currentPoints > maxPts) {
                            currentPoints = maxPts;
                            const rr = (window.pointsRedeemRate && window.pointsRedeemRate > 0) ? window.pointsRedeemRate : 10;
                            pointsDiscountForGrandTotalCalc = (currentPoints / rr);
                            try { console.log('[EAO Points Clamp][render-noDiscount] CLAMPED to points=%s amount=%s', currentPoints, pointsDiscountForGrandTotalCalc); } catch(_) {}
                            try { if (window.eaoStagedPointsDiscount) { window.eaoStagedPointsDiscount.points = currentPoints; window.eaoStagedPointsDiscount.amount = pointsDiscountForGrandTotalCalc; } } catch(_){ }
                        }
                    }
                } catch(_){ }
                if (statusForDiscount === 'processing' || statusForDiscount === 'completed' || statusForDiscount === 'shipped') {
                    // Paid orders: prefer existing coupon values if no staged/current data present
                    if ((currentPoints === 0) && (parseFloat(pointsDiscountForGrandTotalCalc || 0) === 0)) {
                        const existingPts = parseInt(window.existingPointsRedeemed || 0, 10);
                        const existingAmt = parseFloat(window.existingDiscountAmount || 0);
                        if (!isNaN(existingPts) && (existingPts > 0 || existingAmt > 0)) {
                            currentPoints = existingPts;
                            pointsDiscountForGrandTotalCalc = isNaN(existingAmt) ? 0 : existingAmt;
                        }
                        // If we have a persisted amount, use it as the authoritative value
                        if (typeof window.eaoPersistedPointsDiscount !== 'undefined') {
                            pointsDiscountForGrandTotalCalc = parseFloat(window.eaoPersistedPointsDiscount)||pointsDiscountForGrandTotalCalc;
                            if (!currentPoints && typeof window.eaoPersistedPointsPoints !== 'undefined') {
                                currentPoints = parseInt(window.eaoPersistedPointsPoints||0,10)||currentPoints;
                            }
                        }
                    }
                    const displayAmount = (currentPoints > 0 || pointsDiscountForGrandTotalCalc > 0) ? ('-' + eaoFormatPrice(pointsDiscountForGrandTotalCalc)) : eaoFormatPrice(0);
                    summaryHtml += this.createSummaryRow(
                        'Points Discount:',
                        displayAmount,
                        true,
                        'eao-summary-points-discount'
                    );
                } else {
                const maxPoints = this.calculateMaxPoints(currentPoints);
                const labelWithInput = `Points Discount (<input type="number" id="eao_points_to_redeem_inline" name="eao_points_to_redeem" class="eao-points-input eao-points-input-inline" min="0" max="${maxPoints}" value="${currentPoints}" step="1"> points):`;
                const displayAmount = currentPoints > 0 ? '-' + eaoFormatPrice(pointsDiscountForGrandTotalCalc) : eaoFormatPrice(0);
                summaryHtml += this.createSummaryRowWithControl(
                    labelWithInput,
                    displayAmount,
                    this.createInlinePointsSlider(currentPoints),
                    true,
                    'eao-summary-points-discount'
                );
                }
            }
            } else if (statusForDiscount === 'processing' || statusForDiscount === 'completed' || statusForDiscount === 'shipped') {
                // Paid orders: keep existing display logic handled below
            }
        } else if (hasOrderCustomer) {

            // FALLBACK: Show basic points line for customers even if data isn't fully loaded
            if (window.eaoCurrentPointsDiscount && window.eaoCurrentPointsDiscount.amount > 0) {
                // Show existing discount without slider
                pointsDiscountForGrandTotalCalc = parseFloat(window.eaoCurrentPointsDiscount.amount);
                summaryHtml += this.createSummaryRow(
                    'Points Discount (' + (window.eaoCurrentPointsDiscount.points || 0) + ' points):',
                    '-' + eaoFormatPrice(pointsDiscountForGrandTotalCalc),
                    true,
                    'eao-summary-points-discount'
                );
            } else {
                // Show zero points line indicating availability
                summaryHtml += this.createSummaryRow(
                    'Points Discount (0 points):',
                    eaoFormatPrice(0),
                    true,
                    'eao-summary-points-discount'
                );
            }

            // If globals are missing, fetch them once and re-render
            if (typeof window.totalAvailablePoints === 'undefined' || typeof window.customerCurrentPoints === 'undefined' || typeof window.existingPointsRedeemed === 'undefined') {
                try {
                    const oid = (window.eaoEditorParams && window.eaoEditorParams.order_id) ? window.eaoEditorParams.order_id : null;
                    const ajaxUrl = (window.eaoEditorParams && window.eaoEditorParams.ajax_url) ? window.eaoEditorParams.ajax_url : (window.ajaxurl || null);
                    if (oid && ajaxUrl && window.eaoEditorParams && window.eaoEditorParams.nonce && !window._eaoFetchingYithGlobals) {
                        window._eaoFetchingYithGlobals = true;
                        jQuery.post(ajaxUrl, {
                            action: 'eao_get_yith_points_globals',
                            nonce: window.eaoEditorParams.nonce,
                            order_id: oid
                        }, (resp) => {
                            window._eaoFetchingYithGlobals = false;
                            if (resp && resp.success && resp.data) {
                                window.existingPointsRedeemed = parseInt(resp.data.existingPointsRedeemed||0,10);
                                window.existingDiscountAmount = parseFloat(resp.data.existingDiscountAmount||0);
                                window.totalAvailablePoints = parseInt(resp.data.totalAvailablePoints||0,10);
                                window.customerCurrentPoints = parseInt(resp.data.customerCurrentPoints||0,10);
                                // Re-render to show slider now that globals exist
                                if (window.EAO && window.EAO.MainCoordinator) {
                                    const summary = window.EAO.MainCoordinator.calculateUnifiedSummaryData(window.currentOrderItems || []);
                                    window.EAO.MainCoordinator.renderAllOrderItemsAndSummary(window.currentOrderItems || [], summary);
                                }
                            }
                        }, 'json');
                    }
                } catch(_e){}
            }
        } else if (window.eaoCurrentPointsDiscount && window.eaoCurrentPointsDiscount.amount > 0) {

            // Final fallback: Show standard row when YITH data unavailable but discount exists
            pointsDiscountForGrandTotalCalc = parseFloat(window.eaoCurrentPointsDiscount.amount);
            summaryHtml += this.createSummaryRow(
                'Points Discount (' + (window.eaoCurrentPointsDiscount.points || 0) + ' points):',
                '-' + eaoFormatPrice(pointsDiscountForGrandTotalCalc),
                true,
                'eao-summary-points-discount'
            );
        } else {

        }

        // If inline slider is present and value is 0, force discount dollars to 0 and clear any stale current discount
        try {
            const $inpPts = jQuery('#eao_points_to_redeem_inline');
            if ($inpPts && $inpPts.length) {
                const val = parseInt($inpPts.val() || '0', 10);
                if (!isNaN(val) && val === 0) {
                    // User explicitly set 0 → force zero and clear stale sources
                    pointsDiscountForGrandTotalCalc = 0;
                    if (window.eaoCurrentPointsDiscount) { window.eaoCurrentPointsDiscount.points = 0; window.eaoCurrentPointsDiscount.amount = 0; }
                    if (window.eaoStagedPointsDiscount)  { window.eaoStagedPointsDiscount.points = 0; window.eaoStagedPointsDiscount.amount = 0; }
                }
            }
            // If there is no points UI or no staged/current points object, ensure zero discount on first load
            // BUT keep coupon-derived amount visible for paid orders
            if ((!window.eaoStagedPointsDiscount && !window.eaoCurrentPointsDiscount)) {
                if (!(statusForDiscount === 'processing' || statusForDiscount === 'completed' || statusForDiscount === 'shipped')) {
                pointsDiscountForGrandTotalCalc = 0;
                }
            }
        } catch(_){}

        // Shipping Section (supports both server and client-calculated summary shapes)
        let shippingForGrandTotalCalc = 0;
        if (window.eaoPendingShipstationRate) {
            const pendingRate = window.eaoPendingShipstationRate;
            summaryHtml += this.createSummaryRow(
                (pendingRate.method_title || 'Shipping') + ':',
                eaoFormatPrice(pendingRate.adjustedAmountRaw || 0),
                true,
                'eao-summary-current-shipping'
            );
            shippingForGrandTotalCalc = parseFloat(pendingRate.adjustedAmountRaw || 0);
        } else {
            // Fallbacks: server summary uses shipping_total_raw + shipping_method_title
            // client unified calc uses shipping_cost + shipping_method
            const hasServerShipping = (summaryData && typeof summaryData.shipping_total_raw !== 'undefined');
            const hasClientShipping = (summaryData && typeof summaryData.shipping_cost !== 'undefined');
            const amount = hasServerShipping
                ? parseFloat(summaryData.shipping_total_raw || 0)
                : (hasClientShipping ? parseFloat(summaryData.shipping_cost || 0) : 0);
            const title = hasServerShipping
                ? (summaryData.shipping_method_title || 'Shipping')
                : (hasClientShipping ? (summaryData.shipping_method || 'Shipping') : null);
            if (title !== null) {
            summaryHtml += this.createSummaryRow(
                    title + ':',
                    eaoFormatPrice(amount),
                true,
                'eao-summary-current-shipping'
            );
                shippingForGrandTotalCalc = amount;
            }
        }

        // "Was:" shipping line if different
        if (window.eaoPendingShipstationRate && window.eaoOriginalShippingRateBeforeSessionApply) {
            const pendingRate = window.eaoPendingShipstationRate;
            const originalRate = window.eaoOriginalShippingRateBeforeSessionApply;

            const isDifferentAmount = Math.abs(parseFloat(pendingRate.adjustedAmountRaw) - parseFloat(originalRate.amountRaw)) > 0.001;
            const isDifferentMethod = (pendingRate.method_title || 'Shipping') !== originalRate.method_title;

            if (isDifferentAmount || isDifferentMethod) {
                summaryHtml += this.createSummaryRow(
                    (eaoEditorParams.i18n.was || 'Was:') + ' ' + escapeAttribute(originalRate.method_title) + ':',
                    eaoFormatPrice(originalRate.amountRaw),
                    true,
                    'eao-summary-shipping-retired'
                );
            }
        }

        // Taxes
        let taxForGrandTotalCalc = 0;
        if (summaryData.order_tax_formatted_html && parseFloat(summaryData.order_tax_raw || 0) > 0) { 
             summaryHtml += this.createSummaryRow(
                eaoEditorParams.i18n.tax || 'Tax:',
                summaryData.order_tax_formatted_html || eaoFormatPrice(summaryData.order_tax_raw || 0),
                true
            );
            taxForGrandTotalCalc = parseFloat(summaryData.order_tax_raw || 0);
        }
        
        // Separator before Grand Total if needed
        if (shippingForGrandTotalCalc > 0 || taxForGrandTotalCalc > 0) {
            summaryHtml += '<tr class="eao-summary-row eao-summary-separator-before-total"><td colspan="2" style="border-top: 1px solid #eee; padding-top: 5px;"></td></tr>';
        }

        // Grand Total
        let productsTotalForCalc = parseFloat(clientCalculatedSummary.products_total_raw || 0);
        let grandTotalToDisplay = productsTotalForCalc + shippingForGrandTotalCalc + taxForGrandTotalCalc - pointsDiscountForGrandTotalCalc;
        
        if (summaryData.order_total_raw !== undefined || productsTotalForCalc > 0 || pointsDiscountForGrandTotalCalc > 0) {
             summaryHtml += this.createSummaryRow(
                eaoEditorParams.i18n.grand_total || 'Grand Total:',
                eaoFormatPrice(grandTotalToDisplay),
                true,
                'eao-grand-total-line' 
            );
        }
        
        summaryHtml += '</table>';
        // Place left table into a wrapper with a right-side container
        const wrapper = '<div class="eao-summary-wrapper" style="display:flex; gap:24px; align-items:flex-start; justify-content:center; width:100%;">'
          + '<div class="eao-summary-left" id="eao-points-summary-panel" style="flex:1 1 50%; max-width:50%; font-size:14px; line-height:1.6;"></div>'
          + '<div class="eao-summary-right" style="flex:1 1 50%; max-width:50%;">' + summaryHtml + '</div>'
          + '</div>';
        this.elements.$currentOrderItemsSummary.html(wrapper);
        // Preserve points slider value across re-renders using staged/current points
        (function syncInlinePointsFromState(){
            const rate = (window.pointsDollarRate && window.pointsDollarRate > 0) ? window.pointsDollarRate : 10;
            let pts = 0;
            if (window.eaoStagedPointsDiscount && typeof window.eaoStagedPointsDiscount.points !== 'undefined') {
                pts = parseFloat(window.eaoStagedPointsDiscount.points || 0);
            } else if (window.eaoCurrentPointsDiscount && typeof window.eaoCurrentPointsDiscount.points !== 'undefined') {
                pts = parseFloat(window.eaoCurrentPointsDiscount.points || 0);
            } else if (window.eaoStagedPointsDiscount && typeof window.eaoStagedPointsDiscount.amount !== 'undefined') {
                // Convert dollars to points using points-per-dollar
                pts = parseFloat(window.eaoStagedPointsDiscount.amount || 0) * rate;
            }
            if (!isNaN(pts) && pts > 0) {
                const $inp = jQuery('#eao_points_to_redeem_inline');
                if ($inp.length) $inp.val(pts);
            }
        })();
        // Initial render of points panel to adhere to architecture: compute from current state
        // Prevent immediate re-entrant updates from external hooks before UI stabilizes
        setTimeout(function(){
            $(document).trigger('eaoSummaryUpdated', [window.currentOrderSummaryData || summaryData || {}, { context: 'full_refresh', trigger_hooks: true }]);
        }, 0);
        
        // Render points summary into right panel (always show)
        try {
            const $right = jQuery('#eao-points-summary-panel');
            if ($right.length) {
                const rate = (window.pointsDollarRate && window.pointsDollarRate > 0) ? window.pointsDollarRate : 10;
                const gross = parseFloat(clientCalculatedSummary.items_subtotal_raw || 0);
                const net   = parseFloat(clientCalculatedSummary.products_total_raw || 0);
                // Compute points-discount dollars strictly from the inline input only (single source of truth)
                let pointsDiscountDollars = 0;
                const $inlinePointsInput = jQuery('#eao_points_to_redeem_inline');
                const livePoints = $inlinePointsInput.length ? parseFloat($inlinePointsInput.val() || 0) : 0;
                if (!isNaN(livePoints) && livePoints > 0) {
                    const redeemRate = (window.pointsRedeemRate && window.pointsRedeemRate > 0) ? window.pointsRedeemRate : 10;
                    pointsDiscountDollars = livePoints / redeemRate;
                }

                // Snap very small discount dollars to 0 to avoid 39.999 → 39 flooring
                if (Math.abs(pointsDiscountDollars) < 0.005) { pointsDiscountDollars = 0; }

                // Single source of truth: use backend-calculated award summary as baseline
                // Persist points_award_summary globally for client-side recalculations
                if (summaryData && summaryData.points_award_summary) {
                    window.eaoPointsAwardSummary = summaryData.points_award_summary;
                    // Also persist the initial gross total to calculate rate
                    window.eaoInitialGross = gross;
                }
                const pas = window.eaoPointsAwardSummary || {};
                const initialFullPts = (typeof pas.points_full !== 'undefined') ? parseInt(pas.points_full) : 0;
                const initialGross = window.eaoInitialGross || gross;
                // Calculate points per dollar using live rate if available, else derive from initial snapshot
                const ptsPerDollar = (window.pointsDollarRate && window.pointsDollarRate > 0)
                    ? window.pointsDollarRate
                    : ((initialFullPts > 0 && initialGross > 0) ? (initialFullPts / initialGross) : 0);
                // Line 1: Recalculate from current Gross total (responds to quantity changes)
                const fullPts = Math.max(0, Math.round(gross * ptsPerDollar));
                // Line 2: Recalculate from current Net total (responds to discount changes in UI)
                const discPts = Math.max(0, Math.round(net * ptsPerDollar));
                // Use redemption rate to convert staged points to dollars precisely
                const redeemRateLive = (window.pointsRedeemRate && window.pointsRedeemRate > 0) ? window.pointsRedeemRate : 10;
                const pointsDollarsForGrant = (function(){
                    const $inp = jQuery('#eao_points_to_redeem_inline');
                    if ($inp && $inp.length) {
                        const pts = parseFloat($inp.val()||0);
                    return isNaN(pts) ? 0 : (pts / redeemRateLive);
                    }
                    // Fallback to currently applied/staged discount dollars when input is not present
                    try {
                        // Source of truth on paid orders: use existing coupon discount amount only
                        const statusForTruth = (function(){
                            try { const v = String(jQuery('#order_status').val()||'').replace('wc-',''); return v; } catch(_){ return (window.eaoEditorParams && window.eaoEditorParams.order_status) ? String(window.eaoEditorParams.order_status) : ''; }
                })();
                        if (statusForTruth === 'processing' || statusForTruth === 'completed') {
                            if (typeof window.existingDiscountAmount !== 'undefined') {
                                return parseFloat(window.existingDiscountAmount) || 0;
                            }
                        }
                        if (window.eaoStagedPointsDiscount && typeof window.eaoStagedPointsDiscount.amount !== 'undefined') {
                            return parseFloat(window.eaoStagedPointsDiscount.amount) || 0;
                        }
                        if (window.eaoCurrentPointsDiscount && typeof window.eaoCurrentPointsDiscount.amount !== 'undefined') {
                            return parseFloat(window.eaoCurrentPointsDiscount.amount) || 0;
                        }
                    } catch(_e) {}
                    return 0;
                })();
                // Derive points-per-dollar strictly from backend award summary
                const perDollarPts = (ptsPerDollar > 0) ? ptsPerDollar : 0;
                // If no points are used, match the discounted line; otherwise compute from OOP dollars
                const outOfPocketPts = (pointsDollarsForGrant <= 0.0000001)
                    ? discPts
                    : Math.max(0, Math.round(((Math.max(0, net - pointsDollarsForGrant) * perDollarPts) + 1e-6)));
                const grantState = (summaryData && summaryData.points_grant_state) ? summaryData.points_grant_state : {granted:false, granted_points:0, revoked:false, revoked_points:0};
                // Persist server-provided conversion rate globally so subsequent renders can read it (no fallback usage)
                if (!isNaN(ptsPerDollar) && ptsPerDollar > 0) { window.pointsDollarRate = ptsPerDollar; }
                const showRevoke = (grantState.granted && !grantState.revoked);
                // Persist expected award (line 3) to order meta via hidden input so backend can award exact value
                try {
                    const $hiddenAward = jQuery('#eao_expected_points_award');
                    if ($hiddenAward.length) { $hiddenAward.val(outOfPocketPts); }
                } catch(_){ }

                // Compute net granted so far (granted minus revoked)
                const netGranted = Math.max(0, (parseInt(grantState.granted_points||0,10) - parseInt(grantState.revoked_points||0,10)));

                const rightBlock = [
                    '<div class="eao-points-earning-title" style="font-weight:600;margin:0 0 8px 0; font-size:16px;">Points earning</div>',
                    '<div class="eao-points-grid" style="display:grid; grid-template-columns: 1fr 48px; column-gap:6px; row-gap:6px; max-width:520px;">',
                        '<div class="eao-summary-row" style="font-size:14px;">Points for products full price:</div>',
                        '<div class="eao-summary-row" style="font-size:14px; text-align:right; font-weight:600;">' + fullPts + '</div>',
                        '<div class="eao-summary-row" style="font-size:14px;">Points for products discounted:</div>',
                        '<div class="eao-summary-row" style="font-size:14px; text-align:right; font-weight:600;">' + discPts + '</div>',
                        '<div class="eao-summary-row" style="font-size:14px;">Points for order paid with points:</div>',
                        '<div class="eao-summary-row" style="font-size:14px; text-align:right; font-weight:600;">' + outOfPocketPts + '</div>',
                    '</div>',
                    '<div class="eao-override-grant-row" style="margin:6px 0 0 0; font-size:14px;">',
                    '  Modify points for this order by <input type="number" class="eao-override-grant-input" style="width:90px;" step="1"> points <span class="eao-override-grant-dollars" style="margin-left:6px; color:#666;"></span> <button type="button" class="button eao-apply-points-adjust">Apply</button>',
                    '</div>',
                    '<div class="eao-summary-row eao-grant-note" style="font-size:13px; color:#666; margin-top:4px;"></div>',
                    '<div class="eao-summary-row" style="margin-top:8px;">',
                    '  <button type="button" class="button button-secondary eao-revoke-points-btn" ' + (showRevoke ? '' : 'style="display:none;" disabled') + '>Revoke points</button>',
                    '  <span class="eao-revoke-status" style="margin-left:8px;color:#666;">' + 'Granted so far: <strong>' + netGranted + ' pts</strong>' + '</span>',
                    '</div>'
                ].join('');
                $right.html(rightBlock);
                // Ensure hidden expected-award field exists (picked up by save) and always set
                if (!jQuery('#eao_expected_points_award').length) {
                    jQuery('<input type="hidden" id="eao_expected_points_award" name="eao_expected_points_award"/>').appendTo('#eao-order-form');
                }
                try { jQuery('#eao_expected_points_award').val(outOfPocketPts); } catch(_){ }
                // Initialize grant override UI
                (function initGrantOverride(){
                    const $row = jQuery('.eao-override-grant-row');
                    const $inp = jQuery('.eao-override-grant-input');
                    const $dlr = jQuery('.eao-override-grant-dollars');
                    // Use redemption conversion (points per $) for the $ preview next to override
                    const rate = (window.pointsRedeemRate && window.pointsRedeemRate > 0) ? window.pointsRedeemRate : 10; // redemption conversion
                    function refreshDollars(){
                        const pts = parseInt($inp.val() || '0', 10);
                        const dollars = (pts / rate).toFixed(2);
                        $dlr.text('($' + dollars + ')');
                    }
                    // Seed initial state from runtime override (if any) or localized editor params
                    try {
                        const seeded = (typeof window.eaoPointsGrantOverride === 'object')
                            ? window.eaoPointsGrantOverride
                            : {
                                points: 0
                            };
                            $row.show();
                            $inp.val(isNaN(seeded.points) ? 0 : seeded.points);
                        // Persist to global for save payload
                        window.eaoPointsGrantOverride = {
                            points: isNaN(seeded.points) ? 0 : seeded.points
                        };
                        refreshDollars();
                    } catch(_e) {}
                    $inp.on('input change', function(){
                        window.eaoPointsGrantOverride = window.eaoPointsGrantOverride || { points:0 };
                        window.eaoPointsGrantOverride.points = parseInt(jQuery(this).val() || '0', 10);
                        refreshDollars();
                    });

                    // Prevent UI re-renders while editing the modify-points input
                    $inp.on('focus', function(){
                        try {
                            window.eaoInputFocusLock = true; // global lock respected by refreshers
                        } catch(_){ }
                    });
                    $inp.on('blur', function(){
                        try {
                            window.eaoInputFocusLock = false;
                            // small grace period to ignore late refresh responses after leaving the field
                            window.eaoPointsLockUntil = Date.now() + 800;
                        } catch(_){ }
                    });
                    jQuery('.eao-apply-points-adjust').on('click', function(){
                        const delta = parseInt($inp.val() || '0', 10);
                        if (!delta) { return; }
                        if (!window.eaoEditorParams || !window.eaoEditorParams.ajax_url) { return; }
                        jQuery.post(window.eaoEditorParams.ajax_url, {
                            action: 'eao_adjust_points_for_order',
                            nonce: window.eaoEditorParams.nonce,
                            order_id: window.eaoEditorParams.order_id,
                            points_delta: delta
                        }, function(resp){
                            if (resp && resp.success) {
                                try {
                                    const granted = parseInt((resp.data && resp.data.granted_points) || 0, 10);
                                    const revoked = parseInt((resp.data && resp.data.revoked_points) || 0, 10);
                                    jQuery('.eao-revoke-status').html('Granted so far: <strong>' + Math.max(0, granted - revoked) + ' pts</strong>');
                                } catch(_){ }
                            }
                        });
                    });
                    // Set initial note using live status from DOM with fallbacks
                    function getOrderStatusSlug(){
                        try {
                            const $sel = jQuery('#order_status');
                            if ($sel && $sel.length) {
                                const val = String($sel.val() || '').trim();
                                if (val) { return val.replace('wc-',''); }
                            }
                        } catch(_e){}
                        if (window.eaoEditorParams && window.eaoEditorParams.order_status) {
                            return String(window.eaoEditorParams.order_status);
                        }
                        return '';
                    }
                    const status = getOrderStatusSlug();
                    let note = '';
                    if (grantState.revoked) {
                        note = 'Points were revoked for this order.';
                    } else if (grantState.granted) {
                        note = (grantState.granted_points) + ' points were granted to the user.';
                    } else if (status === 'pending' || status === 'on-hold' || status === '') {
                        // Only show preview before payment
                        const grantPreview = Math.max(0, Math.floor(outOfPocketPts));
                        note = grantPreview + ' points will be granted to the user when this order is paid (status changes to processing).';
                    } else if (status === 'processing' || status === 'completed') {
                        note = 'Points not granted yet for this order';
                    }
                    jQuery('.eao-grant-note').text(note);

                    // When order is already paid/processing, freeze the points discount input & slider
                    try {
                        if (status === 'processing' || status === 'completed' || status === 'shipped') {
                            jQuery('#eao_points_to_redeem_inline').prop('disabled', true).addClass('disabled');
                            jQuery('.eao-summary-points-discount .eao-slider').prop('disabled', true).addClass('disabled');
                            // Also disable YITH metabox controls to enforce read-only state after payment
                            jQuery('#eao_points_to_redeem, #eao_points_slider').prop('disabled', true).addClass('disabled');
                        }
                    } catch(_){ }
                })();
            }
        } catch(e) {}

        // NEW: Set global flag to indicate grand total has been updated
        window.eaoGrandTotalUpdated = true;
        window.eaoCurrentGrandTotal = grandTotalToDisplay;
        
        // NEW: Trigger custom event for components that need to respond to grand total changes
        $(document).trigger('eaoGrandTotalUpdated', {
            grandTotal: grandTotalToDisplay,
            summaryData: summaryData || window.currentOrderSummaryData
        });

        // Single source of truth for customer id is the hidden input; do not overwrite from summary

        // Also notify points panel to refresh when summary updates (use latest staged data)
        try {
            const latest = window.currentOrderSummaryData || summaryData || {};
            if (typeof latest.points_discount_amount !== 'undefined') {
                window.eaoCurrentPointsDiscount = {
                    points: parseInt(latest.points_redeemed || 0, 10),
                    amount: parseFloat(latest.points_discount_amount || 0)
                };
            }
        } catch(_){ }
        $(document).trigger('eaoSummaryUpdated', [window.currentOrderSummaryData || summaryData || {}, { context: 'full_refresh', trigger_hooks: true }]);

        // Quiet Chrome Issues panel by ensuring dynamic fields get identifiers after each render
        try { this.ensureFormFieldIdentifiers(this.elements.$form || $(document)); } catch(_){ }
    },
    
    /**
     * Create a summary row for the order summary table
     */
    createSummaryRow: function(label, value, isMonetary, additionalClass = '') {
        let rowClass = 'eao-summary-row';
        if (additionalClass) {
            rowClass += ' ' + additionalClass;
        }
        let valueClass = 'eao-summary-value';
        if (isMonetary) {
            valueClass += ' eao-summary-monetary-value';
        }

        let html = '';
        html += `<tr class="${escapeAttribute(rowClass)}">`;
        html += `  <td class="eao-summary-label">${label}</td>`;
        html += `  <td class="${escapeAttribute(valueClass)}">${value}</td>`;
        html += '</tr>';
        return html;
    },
    
    /**
     * Create a summary row with embedded control (like slider)
     * Inline structure with slider right after the label text
     */
    createSummaryRowWithControl: function(label, value, controlHtml, isMonetary, additionalClass = '') {
        let rowClass = 'eao-summary-row eao-summary-row-with-control';
        if (additionalClass) {
            rowClass += ' ' + additionalClass;
        }
        let valueClass = 'eao-summary-value';
        if (isMonetary) {
            valueClass += ' eao-summary-monetary-value';
        }

        let html = '';
        // Inline structure: label with slider inline, properly aligned amount
        html += `<tr class="${escapeAttribute(rowClass)}">`;
        html += `  <td class="eao-summary-label eao-summary-label-with-control">`;
        html += `    <span class="eao-summary-label-text">${label}</span>`;
        html += `    <span class="eao-summary-control-container">${controlHtml}</span>`;
        html += `  </td>`;
        html += `  <td class="${escapeAttribute(valueClass)}">${value}</td>`;
        html += '</tr>';
        return html;
    },
    
    /**
     * Calculate maximum points for sliders and input fields
     */
    calculateMaxPoints: function(currentValue) {
        let maxPoints = 0;
        const totalAvailable = parseInt(window.totalAvailablePoints || 0, 10) || 0; // USER BALANCE (including existing redemption per server)
        
        if (totalAvailable > 0) {
            // Calculate product total equivalent in points (10 points = $1)
            // Use live calculation from current order items for accuracy
            let productsTotalRaw = 0;
            
            // First try to get live calculated total from unified summary
            if (window.currentOrderItems && window.currentOrderItems.length > 0) {
                const liveCalculation = this.calculateUnifiedSummaryData(window.currentOrderItems);
                if (liveCalculation && liveCalculation.products_total_raw) {
                    productsTotalRaw = parseFloat(liveCalculation.products_total_raw);
    
                }
            }
            
            // Fallback to summary data if live calculation not available
            if (productsTotalRaw <= 0 && window.currentOrderSummaryData && window.currentOrderSummaryData.products_total_raw) {
                productsTotalRaw = parseFloat(window.currentOrderSummaryData.products_total_raw);

            }
            
            // Use redemption conversion (points per $) and guard with epsilon to avoid off-by-one caps
            const redeemRate = (window.pointsRedeemRate && window.pointsRedeemRate > 0) ? window.pointsRedeemRate : 10;
            const pointsEquivalentToProductsTotal = Math.round((productsTotalRaw * redeemRate) + 1e-6); // PRODUCTS NET CAP

            
            // Smart max: minimum of available points and product total equivalent
            maxPoints = Math.min(totalAvailable, pointsEquivalentToProductsTotal);
            try { console.log('[EAO Points][calcMax] totalAvailable=%s productsPtsCap=%s => maxPoints=%s', totalAvailable, pointsEquivalentToProductsTotal, maxPoints); } catch(_) {}
            
            // Ensure at least some reasonable minimum if we have available points
            if (maxPoints <= 0 && totalAvailable > 0) {
                maxPoints = Math.min(totalAvailable, 1000); // Conservative fallback
            }
        }
        
        // Ensure max covers current value
        return Math.max(maxPoints, currentValue || 0);
    },

    /**
     * Create inline points slider HTML for summary section
     */
    createInlinePointsSlider: function(currentValue) {
        const displayValue = currentValue || 0;
        const maxValue = this.calculateMaxPoints(displayValue);
        
        let html = '';
        html += `<div class="eao-inline-points-slider-container">`;
        html += `  <div class="eao-slider-labels">`;
        html += `    <span class="eao-slider-min-label">0</span>`;
        html += `    <input type="range" `;
        html += `           id="eao_points_slider_inline" `;
        html += `           class="eao-points-slider eao-inline-slider" `;
        html += `           min="0" `;
        html += `           max="${maxValue}" `;
        html += `           value="${displayValue}" `;
        html += `           step="1" `;
        html += `           data-max-label="${maxValue}">`;
        html += `    <span class="eao-slider-max-label">${maxValue}</span>`;
        html += `  </div>`;
        html += `</div>`;
        
        return html;
    },
    
    /**
     * Post-render tasks after any display update
     */
    executePostRenderTasks: function() {
        if (window.EAO && window.EAO.ChangeDetection) {
            window.EAO.ChangeDetection.triggerCheck();
        }
        
        // Apply stock-based styling to quantity inputs
        this.elements.$currentOrderItemsList.find('.eao-quantity-input').each(function() {
            if (typeof updateQuantityInputBackground === 'function') {
                updateQuantityInputBackground($(this));
            }
        });
        
        // Ensure YITH Points event handlers are set up for new inline slider
        if (window.EAO && window.EAO.YithPoints && typeof window.EAO.YithPoints.setupRealTimeUpdates === 'function') {
            // Re-initialize event handlers to catch any newly added sliders
            window.EAO.YithPoints.setupRealTimeUpdates();
        }
        // Also attach a light listener for real-time points slider movements
        // IMPORTANT: While dragging, update ONLY the Points Discount line.
        // All other recalculations (earning lines, grand total, etc.) happen on change/release.
        $(document)
            .off('input.eaoPoints', '#eao_points_slider_inline')
            .on('input.eaoPoints', '#eao_points_slider_inline', function() {
                try {
                    const val = parseInt($(this).val() || '0', 10);
                    const redeemRate = (window.pointsRedeemRate && window.pointsRedeemRate > 0) ? window.pointsRedeemRate : 10;
                    const amount = val > 0 ? (val / redeemRate) : 0;
                    // Sync staged points so calculations immediately consider live value
                    try {
                        if (!window.eaoStagedPointsDiscount) { window.eaoStagedPointsDiscount = {}; }
                        window.eaoStagedPointsDiscount.points = isNaN(val) ? 0 : val;
                        window.eaoStagedPointsDiscount.amount = isNaN(amount) ? 0 : amount;
                        const $num = jQuery('#eao_points_to_redeem_inline');
                        if ($num && $num.length) { $num.val(val); }
                    } catch(_) {}
                    const $amountCell = $('.eao-summary-points-discount .eao-summary-monetary-value');
                    if ($amountCell && $amountCell.length) {
                        if (typeof eaoFormatPrice === 'function') {
                            $amountCell.text(val > 0 ? ('-' + eaoFormatPrice(amount)) : eaoFormatPrice(0));
                        } else {
                            $amountCell.text(val > 0 ? ('-$' + amount.toFixed(2)) : '$0.00');
                        }
                    }
                    // Notify listeners and throttle-refresh the earning panel while dragging
                    try { jQuery(document).trigger('eaoPointsDiscountChanged'); } catch(_) {}
                    try {
                        window._eaoPointsLiveLastInputTs = Date.now();
                        if (!window._eaoPointsLiveTimer) {
                            window._eaoPointsLiveTimer = setTimeout(function(){
                                window._eaoPointsLiveTimer = null;
                                if (window.EAO && window.EAO.MainCoordinator) {
                                    const items = window.currentOrderItems || [];
                                    const summary = window.EAO.MainCoordinator.calculateUnifiedSummaryData(items);
                                    window.EAO.MainCoordinator.refreshSummaryOnly(items, summary);
                                }
                            }, 300);
                        }
                    } catch(_) {}
                } catch(_) {}
            })
            .off('change.eaoPoints', '#eao_points_slider_inline')
            .on('change.eaoPoints', '#eao_points_slider_inline', function() {
                // On release: update staged/current to exactly 0 if slider stopped at 0 to prevent fallbacks
                try {
                    const val = parseInt(jQuery(this).val() || '0', 10);
                    // Short post-change lock to ignore late async refresh overwrites
                    window.eaoPointsLockUntil = Date.now() + 1500;
                    // Also suppress external points sync (template/metabox) for a short window to avoid jitter
                    window.eaoSuppressPointsSyncUntil = Date.now() + 1500;
                    // Ensure staged points mirror final slider position
                    try {
                        const rate = (window.pointsRedeemRate && window.pointsRedeemRate > 0) ? window.pointsRedeemRate : 10;
                        const amount = isNaN(val) ? 0 : (val / rate);
                        if (!window.eaoStagedPointsDiscount) { window.eaoStagedPointsDiscount = {}; }
                        window.eaoStagedPointsDiscount.points = isNaN(val) ? 0 : val;
                        window.eaoStagedPointsDiscount.amount = isNaN(amount) ? 0 : amount;
                        const $num = jQuery('#eao_points_to_redeem_inline'); if ($num && $num.length) { $num.val(val); }
                    } catch(_) {}
                    if (!isNaN(val) && val === 0) {
                        if (!window.eaoStagedPointsDiscount) { window.eaoStagedPointsDiscount = {}; }
                        window.eaoStagedPointsDiscount.points = 0; window.eaoStagedPointsDiscount.amount = 0;
                        if (window.eaoCurrentPointsDiscount) { window.eaoCurrentPointsDiscount.points = 0; window.eaoCurrentPointsDiscount.amount = 0; }
                    }
                } catch(_){}
                // Perform full coordinator refresh to keep architecture consistent
                if (window.EAO && window.EAO.MainCoordinator) {
                    const items = window.currentOrderItems || [];
                    const summary = window.EAO.MainCoordinator.calculateUnifiedSummaryData(items);
                    window.EAO.MainCoordinator.refreshSummaryOnly(items, summary);
                }
            });

        // Persist override in save payload via global state (eao-form-submission will pick it up)

        // Revoke points button handler
        $(document)
            .off('click.eaoRevoke', '.eao-revoke-points-btn')
            .on('click.eaoRevoke', '.eao-revoke-points-btn', function(){
                const orderId = jQuery('#eao-order-form').find('input[name="eao_order_id"]').val();
                if (!orderId) return;
                const $btn = jQuery(this).prop('disabled', true).text('Revoking...');
                jQuery.post(eaoEditorParams.ajax_url, {
                    action: 'eao_revoke_points_for_order',
                    nonce: eaoEditorParams.nonce,
                    order_id: orderId
                }).done(function(resp){
                    if (resp && resp.success) {
                        // Refresh summary to update panel state
                        if (window.EAO && window.EAO.MainCoordinator) {
                            const summary = window.EAO.MainCoordinator.calculateUnifiedSummaryData(window.currentOrderItems || []);
                            window.EAO.MainCoordinator.renderAllOrderItemsAndSummary(window.currentOrderItems || [], summary);
                        }
                    } else {
                        alert(resp && resp.data && resp.data.message ? resp.data.message : 'Failed to revoke points');
                        $btn.prop('disabled', false).text('Revoke points');
                    }
                }).fail(function(){
                    alert('Failed to revoke points');
                    $btn.prop('disabled', false).text('Revoke points');
                });
            });
    },
    
    /**
     * Refresh ONLY the summary section (optimized for shipping/tax changes)
     */
    refreshSummaryOnly: function(itemsToRender, summaryData) {
        // Mark this as a change so YITH earning panel recomputes full/discounted/out-of-pocket
        try { if (window.eaoAvoidProductRepaintUntil && Date.now() < window.eaoAvoidProductRepaintUntil) { /* summary-only is fine */ } } catch(_) {}
        // Preserve backend-calculated points award summary across client-only refresh cycles
        try {
            const prev = window.currentOrderSummaryData || {};
            if (prev.points_award_summary && (!summaryData || !summaryData.points_award_summary)) {
                summaryData = summaryData || {};
                summaryData.points_award_summary = prev.points_award_summary;
            }
        } catch(_) {}
        this.updateSummaryDisplay(itemsToRender, summaryData, {
            context: 'summary_only',
            trigger_hooks: true,
            has_changes: true
        });
    },
    
    /**
     * Main coordinator function for refreshing FULL product display
     */
    refreshProductDisplay: function(itemsToRender, summaryData) {
        if (window.eaoInputFocusLock) { return; }
        try { if (window.eaoAvoidProductRepaintUntil && Date.now() < window.eaoAvoidProductRepaintUntil) { return; } } catch(_) {}
        // Ensure grid paints after items updated
        this.renderProductList(itemsToRender);
        this.updateSummaryDisplay(itemsToRender, summaryData, {
            context: 'full_refresh',
            trigger_hooks: true
        });
    },
    
    /**
     * Two-phase rendering: products first, then summary with all data ready
     */
    renderAllOrderItemsAndSummary: function(itemsToRender, summaryData, options) {
        try {
            if (window.eaoAvoidProductRepaintUntil && Date.now() < window.eaoAvoidProductRepaintUntil) {
                const items = itemsToRender || (window.currentOrderItems || []);
                const summary = summaryData || this.calculateUnifiedSummaryData(items);
                this.refreshSummaryOnly(items, summary);
                return;
            }
        } catch(_) {}
        // Set flag to indicate two-phase rendering is in progress
        window.eaoTwoPhaseRenderingInProgress = true;
        
        // Set global variables needed by all systems
        window.currentOrderItems = itemsToRender || window.currentOrderItems || [];
        // Preserve existing shipping fields if the incoming summary omits them (e.g., client-only calc)
        (function preserveShippingBetweenRenders(){
            const prev = window.currentOrderSummaryData || {};
            const next = summaryData || {};
            if (typeof next.shipping_total_raw === 'undefined' && typeof prev.shipping_total_raw !== 'undefined') {
                next.shipping_total_raw = prev.shipping_total_raw;
            }
            if (typeof next.shipping_method_title === 'undefined' && typeof prev.shipping_method_title !== 'undefined') {
                next.shipping_method_title = prev.shipping_method_title;
            }
            // Also preserve legacy client fields
            if (typeof next.shipping_cost === 'undefined' && typeof prev.shipping_cost !== 'undefined') {
                next.shipping_cost = prev.shipping_cost;
            }
            if (typeof next.shipping_method === 'undefined' && typeof prev.shipping_method !== 'undefined') {
                next.shipping_method = prev.shipping_method;
            }
            // Preserve backend points award summary for the earning panel
            if (typeof next.points_award_summary === 'undefined' && typeof prev.points_award_summary !== 'undefined') {
                next.points_award_summary = prev.points_award_summary;
            }
            window.currentOrderSummaryData = next;
        })();
        
        // PHASE 1: Render products list immediately
        if (window.eaoInputFocusLock) { return; }
        this.renderProductList(itemsToRender);
        // Set default global view once
        try {
            const sm = (window.eaoShipmentsMeta || {}); const num = parseInt(sm.num_shipments||0,10)||0;
            const status = (eaoEditorParams.order_status||'').toLowerCase();
            const list = jQuery('#eao-current-order-items-list');
            // hide shipments tab if ineligible
            const $shipTab = jQuery('#eao-products-table-tabs .eao-table-tab[data-tab="shipments"]');
            if (num < 2 || ['pending','processing'].indexOf(status) >= 0) { $shipTab.closest('.eao-table-tab').hide(); } else { $shipTab.closest('.eao-table-tab').show(); }
            // set CSS var for number of shipment columns
            try { const cols = parseInt((window.eaoShipmentsMeta && window.eaoShipmentsMeta.num_shipments) || 0, 10); if (cols >= 2) { list[0].style.setProperty('--eao-ship-col-count', cols); } } catch(e) {}
            if (!list.hasClass('eao-view-pricing') && !list.hasClass('eao-view-shipments')) {
                if (num >= 2 && ['shipped','partially-shipped','delivered'].indexOf(status)>=0) {
                    list.addClass('eao-view-shipments');
                    jQuery('#eao-products-table-tabs .eao-table-tab').removeClass('is-active');
                    jQuery('#eao-products-table-tabs .eao-table-tab[data-tab="shipments"]').addClass('is-active');
                } else {
                    list.addClass('eao-view-pricing');
                    jQuery('#eao-products-table-tabs .eao-table-tab').removeClass('is-active');
                    jQuery('#eao-products-table-tabs .eao-table-tab[data-tab="pricing"]').addClass('is-active');
                }
            }
        } catch(e) {}
        
        // PHASE 2: Calculate all data and render summary when ready
        this.renderSummaryWhenReady(itemsToRender, summaryData, options);
    },
    
    /**
     * Render summary only after ensuring all external data is calculated
     */
    renderSummaryWhenReady: function(itemsToRender, summaryData, options) {
        const self = this;
        
        // First, trigger YITH Points calculation if needed
        if (!window.eaoCurrentPointsDiscount || !window.eaoCurrentPointsDiscount.amount) {
            if (typeof window.updateOrderSummaryWithPointsDiscount === 'function') {
                try {
                    // Call YITH calculation but don't let it render the summary
                    window.updateOrderSummaryWithPointsDiscount();
                    
                    // Wait a moment for the calculation to complete, then render our summary
                    setTimeout(() => {
                        self.renderSummaryWithAllData(itemsToRender, summaryData, options);
                    }, 50); // Short delay for calculation to complete
                    
                } catch (error) {
                    // Render summary anyway, but without points
                    self.renderSummaryWithAllData(itemsToRender, summaryData, options);
                }
            } else {
                self.renderSummaryWithAllData(itemsToRender, summaryData, options);
            }
        } else {
            self.renderSummaryWithAllData(itemsToRender, summaryData, options);
        }
    },
    
    /**
     * Render summary with all data guaranteed to be ready
     */
    renderSummaryWithAllData: function(itemsToRender, summaryData, options) {
        // Use the centralized summary update with all data ready
        var opts = { context: 'full_refresh_with_all_data', trigger_hooks: false };
        try {
            if (options && typeof options === 'object') {
                for (var k in options) {
                    if (Object.prototype.hasOwnProperty.call(options, k)) { opts[k] = options[k]; }
                }
            }
        } catch(_) {}
        this.updateSummaryDisplay(itemsToRender, summaryData, opts);
        
        // Execute post-render tasks
        this.executePostRenderTasks();
        
        // Clear the two-phase rendering flag
        window.eaoTwoPhaseRenderingInProgress = false;
        // Note: Post-save products grid restoration is handled exclusively by the template finalize step.
    },
    
    /**
     * Initialize event handlers for the Main Coordinator
     */
    bindEvents: function() {
        // Main Coordinator handles coordination-level events only
        // Feature-specific handlers belong in their respective modules:
        // - Product events: eao-products.js
        // - Address events: eao-addresses.js  
        // - Customer events: eao-customers.js
        // - YITH Points events: eao-yith-points.js
        
        const self = this;
        jQuery(document).on('click', '.eao-table-tab', function(){
            const $btn = jQuery(this);
            const tab = $btn.data('tab');
            $btn.closest('.eao-table-tabs').find('.eao-table-tab').removeClass('is-active');
            $btn.addClass('is-active');
            // Toggle a class on the list container for CSS to act on
            const $list = jQuery('#eao-current-order-items-list');
            $list.removeClass('eao-view-pricing eao-view-shipments');
            $list.addClass('eao-view-' + tab);

            // If switching to shipments, inject header labels
            if (tab === 'shipments') {
                try {
                    const cols = parseInt((window.eaoShipmentsMeta && window.eaoShipmentsMeta.num_shipments) || 0, 10);
                    if (cols >= 2) {
                        const $headerTabs = jQuery('.eao-product-editor-headers .eao-header-tabs');
                        let labels = '';
                        for (let i=1;i<=cols;i++) { labels += '<span>Shipment ' + i + '</span>'; }
                        $headerTabs.html(labels);
                        // ensure CSS var set
                        if ($list[0]) { $list[0].style.setProperty('--eao-ship-col-count', cols); }
                    }
                } catch(e) {}
            }

            // Tabulator integration: show/hide columns instead of relying only on CSS
            try {
                if (window.EAO && window.EAO.ProductsTable && typeof window.EAO.ProductsTable.toggleShipments === 'function') {
                    // Only toggle if shipments feature enabled for this order status
                    const shouldShow = (function(){
                        try {
                            const $sel = jQuery('#order_status');
                            const raw = ($sel && $sel.length) ? String($sel.val()||'') : (window.eaoEditorParams ? String(window.eaoEditorParams.order_status||'') : '');
                            const status = String(raw).replace('wc-','').replace(/_/g,'-').toLowerCase();
                            const sm = (window.eaoShipmentsMeta || {});
                            const num = parseInt(sm.num_shipments || 0, 10) || 0;
                            try { console.log('[EAO Shipments] tab toggle click; tab=%s status=%s num=%s', tab, status, num); } catch(_){ }
                            if (status === 'partially-shipped' || status === 'partially-shipped-order') { return num >= 1 && tab === 'shipments'; }
                            if (status === 'shipped' || status === 'delivered') { return num > 1 && tab === 'shipments'; }
                            return false;
                        } catch(e) { return false; }
                    })();
                    try { console.log('[EAO Shipments] calling toggleShipments(%s)', shouldShow); } catch(_){ }
                    window.EAO.ProductsTable.toggleShipments(shouldShow);
                }
            } catch (e) { }
        });
    },

    // CRITICAL: Expose loadOrderData function needed by refreshOrderComponents
    loadOrderData: function() {
        if (window.EAO && window.EAO.ChangeDetection) {
            window.EAO.ChangeDetection.refreshInitialData();
        } else {
            console.warn('[EAO Main Coordinator] loadOrderData: Change Detection module not available');
        }
    },

    // CRITICAL: Expose updateButtonStates function needed by template
    updateButtonStates: function() {
        if (window.EAO && window.EAO.ChangeDetection && typeof window.EAO.ChangeDetection.updateButtonStates === 'function') {
            window.EAO.ChangeDetection.updateButtonStates();
        } else {
            console.warn('[EAO Main Coordinator] updateButtonStates: Change Detection module not available');
        }
    },

    // CRITICAL: Expose checkForChanges function needed by template
    checkForChanges: function() {
        if (window.EAO && window.EAO.ChangeDetection && typeof window.EAO.ChangeDetection.triggerCheck === 'function') {
            return window.EAO.ChangeDetection.triggerCheck();
        } else {
            console.warn('[EAO Main Coordinator] checkForChanges: Change Detection module not available');
            return false;
        }
    },

    // CRITICAL: Expose storeInitialData function needed by template
    storeInitialData: function() {
        if (window.EAO && window.EAO.ChangeDetection && typeof window.EAO.ChangeDetection.storeInitialData === 'function') {
            window.EAO.ChangeDetection.storeInitialData();
        } else {
            console.warn('[EAO Main Coordinator] storeInitialData: Change Detection module not available');
        }
    },

    /**
     * UNIFIED SUMMARY CALCULATION - SINGLE SOURCE OF TRUTH
     * This replaces all competing calculation systems with one comprehensive function
     */
    calculateUnifiedSummaryData: function(items) {
        // Start with base product calculations (from Products module logic)
        let itemsSubtotal = 0;
        let totalItemLevelDiscount = 0;
        let productsTotalSum = 0; // Sum of actual line totals (markup-aware)
        
        if (items && items.length > 0) {
            items.forEach(function(item, index) {
                if (item.isPendingDeletion) {
                    return; // Skip items pending deletion
                }
                
                const quantity = parseInt(item.quantity, 10) || 0;
                const basePrice = parseFloat(item.price_raw) || 0;
                // Markup-aware calculations
                const lineSubtotal = basePrice * quantity;
                let lineTotal;
                let lineDiscount;
                if (item.exclude_gd && item.is_markup && typeof item.discounted_price_fixed !== 'undefined') {
                    const fixedUnit = Math.max(0, parseFloat(item.discounted_price_fixed) || 0);
                    lineTotal = fixedUnit * quantity;
                    lineDiscount = 0; // Markups do not count as discount
                } else {
                let finalDiscountPercent = 0;
                if (item.exclude_gd) {
                    finalDiscountPercent = parseFloat(item.discount_percent) || 0;
                } else {
                        // Robust global discount resolution: prefer state, then DOM, then summary data
                        let gd = (typeof window.globalOrderDiscountPercent !== 'undefined' && window.globalOrderDiscountPercent !== null)
                            ? window.globalOrderDiscountPercent
                            : 0;
                        if (!gd) {
                            const $inp = jQuery('#eao_order_products_discount_percent');
                            if ($inp && $inp.length) {
                                gd = parseFloat($inp.val()) || 0;
                            }
                        }
                        if (!gd && window.currentOrderSummaryData && typeof window.currentOrderSummaryData.global_order_discount_percent !== 'undefined') {
                            gd = parseFloat(window.currentOrderSummaryData.global_order_discount_percent) || 0;
                        }
                        finalDiscountPercent = gd;
                    }
                const discountedPrice = basePrice * (1 - (finalDiscountPercent / 100));
                    lineTotal = discountedPrice * quantity;
                    lineDiscount = lineSubtotal - lineTotal;
                }
                
                // For markup items, gross should reflect the marked-up price (no discount path)
                const grossContribution = (item.exclude_gd && item.is_markup && typeof item.discounted_price_fixed !== 'undefined')
                    ? lineTotal
                    : lineSubtotal;
                itemsSubtotal += grossContribution;
                totalItemLevelDiscount += lineDiscount;
                productsTotalSum += lineTotal;
            });
        }
        
        // IMPORTANT: Use sum of line totals for products total so markups increase totals correctly
        const productsTotal = productsTotalSum;
        
        // Get shipping data (preserve existing server shipping when no pending rate)
        let shippingCost = 0;
        let shippingMethod = 'Shipping';
        if (window.eaoPendingShipstationRate) {
            const pr = window.eaoPendingShipstationRate;
            // Normalize amount across known shapes
            const normalizedAmount = (pr.adjustedAmountRaw ?? pr.shipping_amount_raw ?? pr.amountRaw ?? pr.cost ?? 0);
            shippingCost = parseFloat(normalizedAmount) || 0;
            shippingMethod = pr.method_title || pr.method || 'Shipping';
        } else if (window.currentOrderSummaryData && typeof window.currentOrderSummaryData.shipping_total_raw !== 'undefined') {
            shippingCost = parseFloat(window.currentOrderSummaryData.shipping_total_raw || 0);
            shippingMethod = window.currentOrderSummaryData.shipping_method_title || 'Shipping';
        }
        
        // Get Points discount with staged override (staging wins to preserve user interaction fidelity)
        let finalPointsDiscount = 0;
        try {
            if (window.eaoStagedPointsDiscount && window.eaoStagedPointsDiscount.amount !== undefined) {
                finalPointsDiscount = parseFloat(window.eaoStagedPointsDiscount.amount) || 0;
            } else if (window.eaoCurrentPointsDiscount) {
                if (typeof window.eaoCurrentPointsDiscount === 'object') {
                    if (window.eaoCurrentPointsDiscount.amount !== null && window.eaoCurrentPointsDiscount.amount !== undefined) {
                        finalPointsDiscount = parseFloat(window.eaoCurrentPointsDiscount.amount);
                    } else if (window.eaoCurrentPointsDiscount.discount_amount !== null && window.eaoCurrentPointsDiscount.discount_amount !== undefined) {
                        finalPointsDiscount = parseFloat(window.eaoCurrentPointsDiscount.discount_amount);
                    } else {
                        const possibleAmountFields = ['value', 'discount_value', 'points_discount', 'amount_value'];
                        for (let field of possibleAmountFields) {
                            if (window.eaoCurrentPointsDiscount[field] !== null && window.eaoCurrentPointsDiscount[field] !== undefined) {
                                finalPointsDiscount = parseFloat(window.eaoCurrentPointsDiscount[field]);
                                break;
                            }
                        }
                    }
                } else if (typeof window.eaoCurrentPointsDiscount === 'number') {
                    finalPointsDiscount = window.eaoCurrentPointsDiscount;
                }
            }
        } catch(_) {}
        
        // Clamp points discount so it never exceeds products total (prevents negative grand totals)
        try {
            const maxDiscountDollars = Math.max(0, productsTotal);
            if (finalPointsDiscount > maxDiscountDollars) {
                finalPointsDiscount = maxDiscountDollars;
                // Normalize staged/current points to match the new cap
                const redeemRateCap = (window.pointsRedeemRate && window.pointsRedeemRate > 0) ? window.pointsRedeemRate : 10;
                const clampedPoints = Math.floor((finalPointsDiscount * redeemRateCap) + 1e-6);
                try {
                    window.eaoStagedPointsDiscount = { points: clampedPoints, amount: finalPointsDiscount };
                    if (window.EAO && window.EAO.FormSubmission) { window.EAO.FormSubmission.lastPostedPoints = clampedPoints; }
                    // Short lock to avoid immediate UI overwrite by async listeners
                    window.eaoPointsLockUntil = Date.now() + 800;
                } catch(_) {}
            }
        } catch(_) {}

        // Calculate final totals
        const grandTotal = productsTotal + parseFloat(shippingCost) - finalPointsDiscount;
        
        const result = {
            items_subtotal_raw: itemsSubtotal,
            total_item_level_discount_raw: totalItemLevelDiscount,
            products_total_raw: productsTotal,
            shipping_cost: shippingCost,
            shipping_method: shippingMethod,
            points_discount: finalPointsDiscount,
            grand_total: grandTotal,
            // Add formatted versions for display
            items_subtotal: '$' + itemsSubtotal.toFixed(2),
            total_item_level_discount: '$' + totalItemLevelDiscount.toFixed(2),
            products_total: '$' + productsTotal.toFixed(2),
            shipping_cost_formatted: '$' + parseFloat(shippingCost).toFixed(2),
            points_discount_formatted: '$' + finalPointsDiscount.toFixed(2),
            grand_total_formatted: '$' + grandTotal.toFixed(2),
            // Add HTML formatted versions required by renderOrderSummary
            items_subtotal_formatted_html: typeof eaoFormatPrice === 'function' ? eaoFormatPrice(itemsSubtotal) : '$' + itemsSubtotal.toFixed(2),
            total_item_level_discount_formatted_html: typeof eaoFormatPrice === 'function' ? eaoFormatPrice(totalItemLevelDiscount) : '$' + totalItemLevelDiscount.toFixed(2),
            products_total_formatted_html: typeof eaoFormatPrice === 'function' ? eaoFormatPrice(productsTotal) : '$' + productsTotal.toFixed(2)
        };
        
        return result;
    }
};

// Expose rendering functions globally for backward compatibility and module access
window.renderAllOrderItemsAndSummary = function(itemsToRender, summaryData, options) {
    // Set global variables needed by YITH Points and other systems FIRST
    window.currentOrderItems = itemsToRender || window.currentOrderItems || [];
    window.currentOrderSummaryData = summaryData || window.currentOrderSummaryData || {};
    
    if (window.EAO && window.EAO.MainCoordinator) {
        window.EAO.MainCoordinator.renderAllOrderItemsAndSummary(itemsToRender, summaryData, options);
    }
};

// CRITICAL: Replace old calculation system with unified one
window.calculateCurrentSummaryData = function(items) {
    if (window.EAO && window.EAO.MainCoordinator) {
        return window.EAO.MainCoordinator.calculateUnifiedSummaryData(items);
    }
    console.warn('[EAO Main Coordinator] Main Coordinator not available for calculation!');
    return {};
};

window.refreshProductDisplay = function(itemsToRender, summaryData) {
    if (window.EAO && window.EAO.MainCoordinator) {
        window.EAO.MainCoordinator.refreshProductDisplay(itemsToRender, summaryData);
    }
};

window.refreshSummaryOnly = function(itemsToRender, summaryData) {
    if (window.EAO && window.EAO.MainCoordinator) {
        window.EAO.MainCoordinator.refreshSummaryOnly(itemsToRender, summaryData);
    }
};

window.renderProductList = function(itemsToRender) {
    if (window.EAO && window.EAO.MainCoordinator) {
        window.EAO.MainCoordinator.renderProductList(itemsToRender);
    }
};

window.renderOrderSummary = function(itemsToRender, summaryData) {
    if (window.EAO && window.EAO.MainCoordinator) {
        window.EAO.MainCoordinator.renderOrderSummary(itemsToRender, summaryData);
    }
};

// CRITICAL: Expose functions needed by refreshOrderComponents
window.loadOrderData = function() {
    if (window.EAO && window.EAO.MainCoordinator) {
        window.EAO.MainCoordinator.loadOrderData();
    }
};

window.updateButtonStates = function() {
    if (window.EAO && window.EAO.MainCoordinator) {
        window.EAO.MainCoordinator.updateButtonStates();
    }
};

window.checkForChanges = function() {
    if (window.EAO && window.EAO.MainCoordinator) {
        return window.EAO.MainCoordinator.checkForChanges();
    }
    return false;
};

window.storeInitialData = function() {
    if (window.EAO && window.EAO.MainCoordinator) {
        window.EAO.MainCoordinator.storeInitialData();
    }
};

 

// Initialize the Main Coordinator when DOM is ready
jQuery(document).ready(function() {
    
    if (window.EAO && window.EAO.MainCoordinator) {
        
        try {
    window.EAO.MainCoordinator.init();
            
        } catch (error) {
            
        }
    } else {
        
    }
});

/**
 * OPTIMIZATION GUIDE FOR MODULE DEVELOPERS:
 * 
 * Use refreshSummaryOnly() for changes that ONLY affect summary calculations:
 * - YITH Points redemption changes: $(document).trigger('eaoPointsDiscountChanged')
 * - Shipping rate changes (already optimized)
 * - Tax updates that don't affect line items
 * - Currency/display format changes
 * 
 * Use refreshProductDisplay() or renderAllOrderItemsAndSummary() for:
 * - Product additions/removals
 * - Quantity changes
 * - Price/discount changes
 * - Global discount changes
 * - Any change affecting product line items
 * 
 * Call pattern: refreshSummaryOnly(window.currentOrderItems, calculatedSummary)
 */

})(jQuery); 