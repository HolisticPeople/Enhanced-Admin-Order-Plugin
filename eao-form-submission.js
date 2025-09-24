/**
 * Enhanced Admin Order Plugin - Form Submission Module
 * 
 * @package EnhancedAdminOrder
 * @since 2.4.0
 * @version 2.9.40 - Progress hint API and granular hints across refresh steps
 * @author Amnon Manneberg
 * 
 * v2.5.0: MAJOR MILESTONE - JavaScript refactor phase complete with robust form submission
 * v2.4.11: INFINITE LOOP FIX - Added emergency timeout for overlay completion system to prevent infinite page ready loops\n * v2.4.10: CONSOLE ERROR FIX - Replaced jQuery AJAX with XMLHttpRequest to completely eliminate FormData processing errors
 * v2.4.9: CRITICAL FIX - Fixed jQuery AJAX FormData processing error (settings.data.indexOf) with beforeSend handler
 * v2.4.8: CRITICAL FIX - Enhanced error handling for markFormAsSaved function availability and added module checking
 * v2.4.7: ENHANCED FIX - Made overlay restoration even more restrictive with timestamp-based age checking and narrower progress range (60-95%)
 * v2.4.6: CRITICAL FIX - Fixed stuck overlay issue by making setupSaveOverlay more conservative about when to restore overlay on page load
 * v2.4.5: CRITICAL FIX - Added safety check for order ID input to prevent "Cannot read properties of null" errors during initialization
 * v2.4.4: CRITICAL FIX - Fixed save blocking issue by using triggerCheck() return value instead of stale hasUnsavedChanges property
 * v2.4.3: NEW MODULE - Form Submission Module Extraction (Phase 2 Step 17)
 * Extracted all save functionality from template file into dedicated module.
 * Handles save button events, AJAX requests, save overlay, component refresh, and success/error handling.
 * 
 * Handles all form submission, save operations, and order update processes.
 * Includes save overlay management, progress tracking, and component refresh coordination.
 */

(function($) {
    'use strict';

    // Module namespace
    window.EAO = window.EAO || {};

    window.EAO.FormSubmission = {
        // Module properties
        $form: null,
        $saveButton: null,
        isCurrentlySaving: false,
        orderId: null,
        currentProgress: 0,
        _progressIndeterminateTimer: null,
        // External hint API: other modules can suggest progress
        hintProgress: function(percent, label){
            try {
                const p = Math.min(100, Math.max(0, parseInt(percent,10)||0));
                const txt = label || '';
                this.animateProgressTo(p, txt || '');
            } catch(_) {}
        },
        
        // Save overlay elements
        $saveOverlay: null,
        $saveProgress: null,
        $saveStage: null,
        // Debug/state: remember last points value posted to server to sync UI after save
        lastPostedPoints: null,

        /**
         * Initialize the Form Submission module
         */
        init: function() {
            if (window.eaoDebug) { console.log('[EAO Form Submission] Starting initialization...'); }
            
            this.$form = $('#eao-order-form');
            if (!this.$form || this.$form.length === 0) {
                if (window.eaoDebug) { console.error('[EAO Form Submission] CRITICAL: Form element #eao-order-form not found! Cannot initialize.'); }
                return;
            }
            
            this.$saveButton = $('#eao-save-order');
            if (!this.$saveButton || this.$saveButton.length === 0) {
                if (window.eaoDebug) { console.error('[EAO Form Submission] CRITICAL: Save button #eao-save-order not found! Cannot initialize.'); }
                return;
            }
            
            // Safety check for order ID input
            const $orderIdInput = this.$form.find('input[name="eao_order_id"]');
            if ($orderIdInput.length > 0) {
                try {
                    this.orderId = $orderIdInput.val();
                } catch (error) {
                    if (window.eaoDebug) { console.error('[EAO Form Submission] Error reading order ID:', error); }
                    this.orderId = eaoEditorParams.order_id || null;
                }
            } else {
                if (window.eaoDebug) { console.warn('[EAO Form Submission] Order ID input not found, using fallback'); }
                this.orderId = eaoEditorParams.order_id || null;
            }
            
            if (!this.orderId) {
                if (window.eaoDebug) { console.error('[EAO Form Submission] CRITICAL: No order ID available! Cannot initialize.'); }
                return;
            }
            
            // Initialize save overlay elements
            this.$saveOverlay = $('#eao-save-overlay');
            this.$saveProgress = $('#eao-save-progress');
            this.$saveStage = $('#eao-save-stage');
            this.currentProgress = 0;
            this._progressIndeterminateTimer = null;
            
            this.setupEventHandlers();
            this.setupSaveOverlay();
            
            if (window.eaoDebug) { console.log('[EAO Form Submission] Module initialized successfully'); }
        },

        /**
         * Set up event handlers
         */
        setupEventHandlers: function() {
            const self = this;
            
            // Main save button handler
            this.$saveButton.on('click', function(e) {
                self.handleSaveOrder(e);
            });
            
            if (window.eaoDebug) { console.log('[EAO Form Submission] Event handlers set up'); }
        },

        /**
         * Setup save overlay functionality
         */
        setupSaveOverlay: function() {
            // Clear session storage immediately on normal page load to prevent overlay restoration
            const currentTime = Date.now();
            const overlayWasVisible = sessionStorage.getItem('eao_save_overlay_visible') === 'true';
            const savedProgress = parseInt(sessionStorage.getItem('eao_save_progress'), 10) || 0;
            const saveTimestamp = parseInt(sessionStorage.getItem('eao_save_timestamp'), 10) || 0;
            
            // Only restore overlay if:
            // 1. Overlay was explicitly visible during save
            // 2. Progress is in active save range (60-95% - only during actual server processing)
            // 3. Save was initiated within last 30 seconds (to prevent stale restores)
            const isRecentSave = (currentTime - saveTimestamp) < 30000; // 30 seconds
            const isActiveSaveRange = savedProgress >= 60 && savedProgress <= 95;
            
            if (overlayWasVisible && isActiveSaveRange && isRecentSave) {
                if (window.eaoDebugProgress) { console.log('[EAO Progress] Restoring recent save overlay - progress:', savedProgress, 'age:', (currentTime - saveTimestamp) + 'ms'); }
                this.showSaveOverlay();
                $('#eao-save-title').text('Completing Save Operation...');
                $('#eao-save-message').text('Please wait while we finish updating your order.');
                // Monotonic restore
                this.updateProgress(Math.max(this.currentProgress, savedProgress), 'Restoring save operation...');
                
                // Shorter timeout for legitimate restore scenarios
                setTimeout(() => {
                    if (window.eaoDebugProgress) { console.warn('[EAO Progress] EMERGENCY TIMEOUT: Force closing stuck overlay'); }
                    this.hideSaveOverlay();
                    this.isCurrentlySaving = false;
                    this.clearSessionStorage();
                }, 3000); // 3 second timeout for genuine restores
            } else {
                // Always clear session storage for normal page loads
                if (window.eaoDebugProgress) { console.log('[EAO Progress] Normal page load - clearing any stale session storage'); }
                this.clearSessionStorage();
            }
        },
        
        /**
         * Clear all session storage related to save overlay
         */
        clearSessionStorage: function() {
            sessionStorage.removeItem('eao_save_overlay_visible');
            sessionStorage.removeItem('eao_save_stage');
            sessionStorage.removeItem('eao_save_progress');
            sessionStorage.removeItem('eao_save_progress_text');
            sessionStorage.removeItem('eao_save_timestamp');
        },

        /**
         * Main save order handler
         */
        handleSaveOrder: function(e) {
            e.preventDefault();
            
            if (window.eaoDebugProgress) {
                try { window.eaoProgressStartTs = Date.now(); } catch(_) {}
                console.log('[EAO Progress] Save button clicked');
            }
            
            // Check if save should proceed
            if (this.isCurrentlySaving) {
                if (window.eaoDebugProgress) { console.log('[EAO Progress] Save already in progress - blocking'); }
                return;
            }
            
            // Check for changes using change detection module - get real-time result
            let hasUnsavedChanges = false;
            if (window.EAO && window.EAO.ChangeDetection) {
                hasUnsavedChanges = window.EAO.ChangeDetection.triggerCheck();
                if (window.eaoDebug) { console.log('[EAO Form Submission] Change detection result:', hasUnsavedChanges); }
            }
            
            if (!hasUnsavedChanges) {
                if (window.eaoDebugProgress) { console.log('[EAO Progress] No changes to save - blocking'); }
                return;
            }
            
            if (window.eaoDebugProgress) { console.log('[EAO Progress] Starting save process t=' + Date.now()); }
            this.isCurrentlySaving = true;
            this.updateButtonStates();
            
            // Show loading overlay
            this.showSaveOverlay();
            
            // Clear previous messages
            $('.notice').remove();
            
            // Collect and send form data
            this.collectAndSendFormData();
        },

        /**
         * Collect all form data and send AJAX request
         */
        collectAndSendFormData: function() {
            // Collect all form data including nonce
            const formData = new FormData(this.$form[0]);
            formData.append('action', 'eao_save_order_details');
            formData.append('eao_order_id', this.orderId);
            
            // Update save stage
            this.updateSaveStage('Collecting data...');
            if (window.eaoDebugProgress) { console.log('[EAO Progress] Phase=collect t=' + Date.now()); this.hintProgress(12, 'collect'); }
            this.animateProgressTo(15, 'Collecting form data...', 240);
            
            // Add ShipStation rate data if it exists
            if (typeof window.eaoPendingShipstationRate !== 'undefined' && window.eaoPendingShipstationRate !== null) {
                formData.append('eao_pending_shipstation_rate', JSON.stringify(window.eaoPendingShipstationRate));
                if (window.eaoDebug) { console.log('[EAO Form Submission] Including ShipStation rate data'); }
            }
            
            // Add staged order items (new products to add)
            if (typeof window.stagedOrderItems !== 'undefined' && window.stagedOrderItems.length > 0) {
                formData.append('eao_staged_items', JSON.stringify(window.stagedOrderItems));
                if (window.eaoDebug) { console.log('[EAO Form Submission] Including staged order items:', window.stagedOrderItems.length, 'items'); }
            }
            
            // Add items pending deletion
            if (typeof window.itemsPendingDeletion !== 'undefined' && window.itemsPendingDeletion.length > 0) {
                formData.append('eao_items_pending_deletion', JSON.stringify(window.itemsPendingDeletion));
                if (window.eaoDebug) { console.log('[EAO Form Submission] Including items pending deletion:', window.itemsPendingDeletion.length, 'items'); }
            }
            
            // Add global order discount percentage
            if (typeof window.globalOrderDiscountPercent !== 'undefined') {
                formData.append('eao_order_products_discount_percent', window.globalOrderDiscountPercent);
                if (window.eaoDebug) { console.log('[EAO Form Submission] Including global discount percentage:', window.globalOrderDiscountPercent); }
            } else {
                // Fallback to form field if available
                const discountField = document.getElementById('eao_order_products_discount_percent');
                if (discountField && discountField.value) {
                    formData.append('eao_order_products_discount_percent', discountField.value);
                    if (window.eaoDebug) { console.log('[EAO Form Submission] Including global discount from form field:', discountField.value); }
                }
            }
            
            // Add existing order items data (for quantity/discount updates)
            if (typeof window.currentOrderItems !== 'undefined' && window.currentOrderItems.length > 0) {
                formData.append('eao_order_items_data', JSON.stringify(window.currentOrderItems));
                if (window.eaoDebug) { console.log('[EAO Form Submission] Including current order items data:', window.currentOrderItems.length, 'items'); }
            }
            
            // Add staged points redemption (from inline input or legacy field)
            try {
                const inline = document.getElementById('eao_points_to_redeem_inline');
                const legacy = document.getElementById('eao_points_to_redeem');
                let source = 'none';
                let pointsValueStr = '';
                // Prefer staged value from YithPoints module
                if (window.eaoStagedPointsDiscount && typeof window.eaoStagedPointsDiscount.points !== 'undefined') {
                    pointsValueStr = String(window.eaoStagedPointsDiscount.points);
                    source = 'staged';
                }
                // Fallback to visible inline/legacy inputs (treat 0 as valid)
                if (pointsValueStr === '' || pointsValueStr === null || typeof pointsValueStr === 'undefined') {
                    if (inline && inline.value !== undefined) { pointsValueStr = inline.value; source = 'inline'; }
                    else if (legacy && legacy.value !== undefined) { pointsValueStr = legacy.value; source = 'legacy'; }
                }
                // Normalize and always append a number (default 0 when any field exists)
                let pointsInt;
                if (pointsValueStr === '' || pointsValueStr === null || typeof pointsValueStr === 'undefined') {
                    // If points UI exists, explicitly post 0 to avoid stale server state
                    if (inline || legacy) { pointsInt = 0; source = source + '|default0'; }
                } else {
                    pointsInt = parseInt(pointsValueStr, 10);
                    if (isNaN(pointsInt)) { pointsInt = 0; source = source + '|nan->0'; }
                }
                if (typeof pointsInt === 'number') {
                    formData.append('eao_points_to_redeem', pointsInt);
                    this.lastPostedPoints = pointsInt;
                    if (window.eaoDebug) { console.log('[EAO Form Submission] Including points to redeem:', pointsInt, 'source:', source); }
                }
            } catch (e) { if (window.eaoDebug) { console.warn('[EAO Form Submission] Points append error:', e); } }

            // Add grant override state if staged
            try {
                if (window.eaoPointsGrantOverride && typeof window.eaoPointsGrantOverride === 'object') {
                    formData.append('eao_points_grant_override_enabled', window.eaoPointsGrantOverride.enabled ? 'yes' : 'no');
                    if (window.eaoPointsGrantOverride.enabled) {
                        const ovPts = parseInt(window.eaoPointsGrantOverride.points || 0, 10);
                        formData.append('eao_points_grant_override_points', isNaN(ovPts) ? 0 : ovPts);
                    }
                }
            } catch (e) { if (window.eaoDebug) { console.warn('[EAO Form Submission] Grant override append error:', e); } }

            // Add staged notes
            if (typeof window.stagedNotes !== 'undefined' && window.stagedNotes.length > 0) {
                formData.append('eao_staged_notes', JSON.stringify(window.stagedNotes));
            }
            
            // Add pending addresses
            if (typeof window.pendingBillingAddress !== 'undefined' && window.pendingBillingAddress !== null) {
                formData.append('eao_pending_billing_address', JSON.stringify(window.pendingBillingAddress));
                if (typeof window.pendingBillingAddressKey !== 'undefined' && window.pendingBillingAddressKey) {
                    formData.append('eao_pending_billing_address_key', window.pendingBillingAddressKey);
                }
                if (typeof window.pendingBillingUpdateAddressBook !== 'undefined') {
                    formData.append('eao_pending_billing_update_address_book', window.pendingBillingUpdateAddressBook ? 'yes' : 'no');
                }
            }
            
            if (typeof window.pendingShippingAddress !== 'undefined' && window.pendingShippingAddress !== null) {
                formData.append('eao_pending_shipping_address', JSON.stringify(window.pendingShippingAddress));
                if (typeof window.pendingShippingAddressKey !== 'undefined' && window.pendingShippingAddressKey) {
                    formData.append('eao_pending_shipping_address_key', window.pendingShippingAddressKey);
                }
                if (typeof window.pendingShippingUpdateAddressBook !== 'undefined') {
                    formData.append('eao_pending_shipping_update_address_book', window.pendingShippingUpdateAddressBook ? 'yes' : 'no');
                }
            }
            // Always include current selected keys for individual-fields path
            try {
                var billKey = document.getElementById('eao_billing_address_key');
                if (billKey && billKey.value) { formData.append('eao_billing_address_key', billKey.value); }
                var shipKey = document.getElementById('eao_shipping_address_key');
                if (shipKey && shipKey.value) { formData.append('eao_shipping_address_key', shipKey.value); }
                var billUpd = document.getElementById('eao_billing_update_address_book');
                if (billUpd) { formData.append('eao_billing_update_address_book', billUpd.checked ? 'yes' : 'no'); }
                var shipUpd = document.getElementById('eao_shipping_update_address_book');
                if (shipUpd) { formData.append('eao_shipping_update_address_book', shipUpd.checked ? 'yes' : 'no'); }
            } catch (e) {}
            
            if (window.eaoDebugProgress) { console.log('[EAO Progress] Form data prepared, sending AJAX request...'); }
            
            // Send AJAX request
            this.sendAjaxRequest(formData);
        },

        /**
         * Send AJAX request to save order
         */
        sendAjaxRequest: function(formData) {
            const self = this;
            
            // Update save stage
            this.updateSaveStage('Sending to server...');
            if (window.eaoDebugProgress) { console.log('[EAO Progress] Phase=send t=' + Date.now()); this.hintProgress(24, 'send'); }
            this.animateProgressTo(35, 'Transmitting data to server...', 320);
            // Begin slow indeterminate advance up to 58% while waiting for server
            this.startIndeterminateProgress(58, 1200);
            
            // Create XMLHttpRequest directly to avoid jQuery FormData processing issues
            const xhr = new XMLHttpRequest();
            
            xhr.open('POST', eao_ajax.ajax_url, true);
            xhr.timeout = 30000; // 30 second timeout
            
            // Track server phase boundaries for adaptive weighting
            try { window.eaoServerPhaseStartTs = Date.now(); } catch(_) {}
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    try {
                        if (xhr.status === 200) {
                            const response = JSON.parse(xhr.responseText);
                            try { window.eaoServerPhaseEndTs = Date.now(); } catch(_) {}
                            self.handleSaveSuccess(response);
                        } else {
                            self.handleSaveError(xhr, 'http_error', 'HTTP Error: ' + xhr.status);
                        }
                    } catch (e) {
                        self.handleSaveError(xhr, 'parse_error', 'Failed to parse response: ' + e.message);
                    }
                }
            };
            
            xhr.onerror = function() {
                self.handleSaveError(xhr, 'network_error', 'Network error occurred');
            };
            
            xhr.ontimeout = function() {
                self.handleSaveError(xhr, 'timeout', 'Request timed out');
            };
            
            // Send the FormData directly - no jQuery processing
            xhr.send(formData);
        },

        /**
         * Handle successful save response
         */
        handleSaveSuccess: function(response) {
            if (window.eaoDebug) { console.log('[EAO Form Submission] AJAX Success response:', response); }
            
            this.isCurrentlySaving = false;
            
            if (response && response.success) {
                // Update progress for server response
                this.stopIndeterminateProgress();
                if (window.eaoDebugProgress) { console.log('[EAO Progress] Phase=server_done t=' + Date.now()); this.hintProgress(40, 'server_done'); }
                this.animateProgressTo(40, 'Server processing completed', 380);
                
                // Update save stage for success
            this.updateSaveStage('Order saved successfully!');
            this.showSaveSuccess();
                
                // Show success message
                const successMessage = response.data?.message || 'Order updated successfully!';
                $('<div class="notice notice-success is-dismissible"><p>' + successMessage + '</p></div>')
                    .insertAfter('.wrap h1')
                    .delay(3000)
                    .fadeOut();
                
                // Mark form as saved - this will disable buttons until next change
                this.markFormAsSaved();
                
                // Clear pending addresses after successful save
                this.clearPendingAddresses();
                
                // Note: Customer-dependent components (FluentCRM, Fluent Support) update in real-time during customer selection
                // No post-save refresh needed - they're already updated when customer changes

                // If notes were processed, clear staged notes immediately and refresh list
                try {
                    var notesProcessed = (response && response.data && typeof response.data.notes_processed !== 'undefined') ? parseInt(response.data.notes_processed, 10) : 0;
                    if (notesProcessed > 0) {
                        if (window.EAO && window.EAO.OrderNotes && typeof window.EAO.OrderNotes.clearStagedNotes === 'function') {
                            window.EAO.OrderNotes.clearStagedNotes();
                        } else {
                            window.stagedNotes = [];
                            var $notesContainer = jQuery('#eao-custom-order-notes .inside');
                            if ($notesContainer.length && typeof window.updateStagedNotesDisplay === 'function') {
                                window.updateStagedNotesDisplay($notesContainer);
                            }
                        }
                        // Immediate refresh for visual confirmation
                        var n = (eao_ajax.save_order_nonce || eao_ajax.refresh_notes_nonce || '');
                        jQuery.post(eao_ajax.ajax_url, { action: 'eao_refresh_order_notes', nonce: n, order_id: this.orderId })
                            .done(function(resp){
                                if (resp && resp.success && resp.data && resp.data.notes_html) {
                                    var $wrap = jQuery('#eao-existing-notes-list-wrapper');
                                    if ($wrap.length) { $wrap.html(resp.data.notes_html); }
                                }
                            });
                    }
                } catch(_){ }
                
                // Start component refresh process
                // Sync points UI immediately to the value we just posted to prevent visual reversion
                try {
                    if (typeof this.lastPostedPoints === 'number') {
                        // Clear staged points to avoid re-posting stale values on next save
                        if (window.eaoStagedPointsDiscount) {
                            window.eaoStagedPointsDiscount.points = this.lastPostedPoints;
                            window.eaoStagedPointsDiscount.amount = (this.lastPostedPoints / 10).toFixed(2);
                        }
                        if (window.EAO && window.EAO.YithPoints && typeof window.EAO.YithPoints.synchronizePointsInputs === 'function') {
                            window.EAO.YithPoints.synchronizePointsInputs(this.lastPostedPoints, null);
                        } else {
                            const fields = document.querySelectorAll('#eao_points_slider, #eao_points_to_redeem, #eao_points_slider_inline, #eao_points_to_redeem_inline, .eao-inline-slider, .eao-inline-input, .eao-points-input-inline');
                            fields.forEach(f => { try { f.value = this.lastPostedPoints; } catch(_){} });
                        }
                if (window.eaoDebug) { console.log('[EAO Form Submission] Synced points UI to posted value:', this.lastPostedPoints); }
                    }
                } catch(syncErr) { console.warn('[EAO Form Submission] Points UI sync error:', syncErr); }

                // Proceed with the normal refresh process
                this.startComponentRefresh();

                // Ensure products grid is visible/repainted after save + refresh
                // If template handles post-save restoration, skip this early render to avoid double full redraw
                try {
                    if (window.eaoPostSavePreferredRestorer === 'template') {
                        // no-op; template will fetch and render once
                    } else if (window.EAO && window.EAO.MainCoordinator && window.EAO.ProductsTable && typeof window.EAO.ProductsTable.render === 'function') {
                        const listEl = jQuery('#eao-current-order-items-list')[0];
                        window.EAO.ProductsTable.render(window.currentOrderItems || [], { container: listEl });
                    }
                } catch(_) {}
                
                // Also trigger a targeted notes refresh immediately to ensure new notes appear even if steps skip
                try {
                    var orderId = this.orderId;
                    if (orderId && window.eao_ajax && window.eao_ajax.ajax_url) {
                        var n = (eao_ajax.save_order_nonce || eao_ajax.refresh_notes_nonce || '');
                        jQuery.post(eao_ajax.ajax_url, { action: 'eao_refresh_order_notes', nonce: n, order_id: orderId })
                            .done(function(resp){
                                if (resp && resp.success && resp.data && resp.data.notes_html) {
                                    var $wrap = jQuery('#eao-existing-notes-list-wrapper');
                                    if ($wrap.length) { $wrap.html(resp.data.notes_html); }
                                }
                            });
                    }
                } catch(_){ }
                
            } else {
                // Handle server-side errors
                const errorMessage = response?.data?.message || 'Failed to save order';
                this.handleSaveError(null, 'server_error', errorMessage);
            }
        },

        /**
         * Handle save error
         */
        handleSaveError: function(jqXHR, textStatus, errorThrown) {
            console.error('[EAO Form Submission] AJAX Error:', textStatus, errorThrown);
            
            // Enhanced debugging for 400 errors
            if (jqXHR && jqXHR.status === 400) {
                console.error('[EAO Form Submission] 400 Bad Request Details:', {
                    status: jqXHR.status,
                    statusText: jqXHR.statusText,
                    responseText: jqXHR.responseText,
                    responseHeaders: jqXHR.getAllResponseHeaders(),
                    readyState: jqXHR.readyState
                });
            }
            
            this.isCurrentlySaving = false;
            this.updateButtonStates();
            
            let errorMessage = 'Failed to save order';
            if (textStatus === 'timeout') {
                errorMessage = 'Save request timed out. Please try again.';
            } else if (textStatus === 'server_error') {
                errorMessage = errorThrown || 'Server error occurred';
            } else if (jqXHR && jqXHR.responseText) {
                try {
                    const errorResponse = JSON.parse(jqXHR.responseText);
                    errorMessage = errorResponse.data?.message || errorMessage;
                } catch (e) {
                    // Use default error message
                }
            }
            
            // Hide save overlay and show error
            this.hideSaveOverlay();
            
            // Show error message
            $('<div class="notice notice-error is-dismissible"><p>' + errorMessage + '</p></div>')
                .insertAfter('.wrap h1');
            
            console.error('[EAO Form Submission] Save failed:', errorMessage);
        },

        /**
         * Clear pending addresses after successful save
         */
        clearPendingAddresses: function() {
            console.log('[EAO Form Submission] Clearing pending addresses after successful save...');
            
            try {
                // Use global function if available
                if (typeof window.eaoClearPendingAddresses === 'function') {
                    console.log('[EAO Form Submission] Using global clearPendingAddresses function...');
                    const success = window.eaoClearPendingAddresses();
                    if (!success) {
                        throw new Error('Global function failed');
                    }
                } else if (window.EAO && window.EAO.Addresses && typeof window.EAO.Addresses.clearPendingAddresses === 'function') {
                    console.log('[EAO Form Submission] Using module clearPendingAddresses function...');
                    window.EAO.Addresses.clearPendingAddresses();
                } else {
                    throw new Error('No clearPendingAddresses function available');
                }
            } catch (addressClearError) {
                console.error('[EAO Form Submission] Error clearing pending addresses:', addressClearError);
                // Continue with save process even if address clearing fails
            }
        },

        /**
         * Start component refresh process
         */
        startComponentRefresh: function() {
            if (window.eaoDebugProgress) { console.log('[EAO Progress] Starting component refresh process...'); }
            
            if (window.eaoDebugProgress) { console.log('[EAO Progress] Phase=refresh_components t=' + Date.now()); this.hintProgress(50, 'refresh:start'); }
            // Adaptive: if server step took long, give refresh a bit less visual range; else give it more
            try {
                const nowTs = Date.now();
                const serverDur = (typeof window.eaoServerPhaseStartTs === 'number' && typeof window.eaoServerPhaseEndTs === 'number') ? (window.eaoServerPhaseEndTs - window.eaoServerPhaseStartTs) : 0;
                // Distribute remainder to reach 95 by end of refresh; never jump above 95 here
                // Spread refresh to roughly 50-80/86 window depending on server
                const refreshTarget = Math.min(90, serverDur > 3000 ? 70 : 80);
                this.animateProgressTo(refreshTarget, 'Refreshing order components...', 640);
                if (window.eaoDebugProgress) { this.hintProgress(refreshTarget, 'refresh:adaptive'); }
            } catch(_) {
                this.animateProgressTo(90, 'Refreshing order components...', 640);
            }
            
            // CRITICAL FIX: Set emergency timeout to prevent infinite loops
            const emergencyTimeout = setTimeout(() => {
                if (window.eaoDebugProgress) { console.warn('[EAO Progress] EMERGENCY TIMEOUT: Component refresh taking too long, forcing completion'); }
                this.hideSaveOverlay();
                this.clearSessionStorage();
            }, 12000); // 12 second emergency timeout
            
            // Store timeout ID globally so it can be cleared by refresh system
            window.eaoEmergencyTimeout = emergencyTimeout;
            
            // Use existing refresh system
            if (typeof window.refreshOrderComponents === 'function') {
                try {
                    window.refreshOrderComponents();
                } catch (refreshError) {
                    console.error('[EAO Form Submission] Error in refreshOrderComponents:', refreshError);
                    clearTimeout(emergencyTimeout);
                    // Minimal fallback: rebuild address summaries
                    try { if (typeof window.refreshFormValues === 'function') { window.refreshFormValues(); } } catch(e) {}
                    this.completeSaveProcess();
                }
            } else {
                // Fallback: complete save process immediately
                if (window.eaoDebug) { console.warn('[EAO Form Submission] refreshOrderComponents not available, completing save process'); }
                clearTimeout(emergencyTimeout);
                try { if (typeof window.refreshFormValues === 'function') { window.refreshFormValues(); } } catch(e) {}
                this.completeSaveProcess();
            }
        },

        /**
         * Complete save process (called after component refresh)
         */
        completeSaveProcess: function() {
            if (window.eaoDebugProgress) { console.log('[EAO Progress] Completing save process...'); }
            
            if (window.eaoDebugProgress) { console.log('[EAO Progress] Phase=finalize t=' + Date.now()); this.hintProgress(95, 'finalize:start'); }
            this.animateProgressTo(100, 'All components updated!', 520);
            // Debug summary
            try {
                if (window.eaoDebugProgress && typeof window.eaoProgressStartTs === 'number') {
                    var nowT = Date.now();
                    var serverDur = (typeof window.eaoServerPhaseStartTs === 'number' && typeof window.eaoServerPhaseEndTs === 'number') ? (window.eaoServerPhaseEndTs - window.eaoServerPhaseStartTs) : null;
                    console.log('[EAO Progress Summary] total=' + (nowT - window.eaoProgressStartTs) + 'ms' + (serverDur!==null? (' server=' + serverDur + 'ms') : ''));
                }
            } catch(_) {}
            
            // Trigger save completion event for other modules to listen to
            $(document).trigger('eao_save_complete', [{
                success: true,
                timestamp: Date.now(),
                stage: 'complete'
            }]);
            
            if (window.eaoDebugProgress) { console.log('[EAO Progress] Save completion event triggered'); }
            
            // Hide overlay after a short delay
            setTimeout(() => {
                this.hideSaveOverlay();
                // FINAL repaint after overlay closes to ensure visibility
                try {
                    // Enable verbose post-save debug for a short window
                    window.eaoDebugPostSaveUntil = Date.now() + 15000;
                    if (window.eaoDebug) { console.log('[EAO][PostSave] Overlay hidden, starting grid restoration'); }
                    // If template is the preferred restorer, avoid duplicate heavy restore here
                    if (window.eaoPostSavePreferredRestorer === 'template') {
                        if (window.eaoDebug) { console.log('[EAO][PostSave] Skipping heavy restore in FormSubmission (template preferred)'); }
                        return;
                    }
                    const $container = jQuery('#eao-order-products-container');
                    if ($container && $container.length) {
                        $container.css('min-height', '160px');
                        if (window.eaoDebug) { console.log('[EAO][PostSave] Products container present:', $container.length); }
                    }

                    // Ensure a fresh list DOM exists (replace if missing or corrupted)
                    (function ensureFreshListDom(){
                        try {
                            const wrap = document.getElementById('eao-order-items-display-edit-section');
                            if (!wrap) { console.warn('[EAO][PostSave] Missing #eao-order-items-display-edit-section'); return; }
                            let listEl = document.getElementById('eao-current-order-items-list');
                            if (!listEl) {
                                wrap.innerHTML = '';
                                listEl = document.createElement('div');
                                listEl.id = 'eao-current-order-items-list';
                                wrap.appendChild(listEl);
                                if (window.eaoDebug) { console.log('[EAO][PostSave] Recreated #eao-current-order-items-list'); }
                            } else {
                                // Reset any transient content/styles that could keep it collapsed
                                listEl.style.display = '';
                                listEl.style.minHeight = '100px';
                                listEl.innerHTML = '';
                                if (window.eaoDebug) { console.log('[EAO][PostSave] Reset existing #eao-current-order-items-list'); }
                            }
                            // Log container geometry
                            try { if (window.eaoDebug) { console.log('[EAO][PostSave] list size:', listEl.offsetWidth, listEl.offsetHeight); } } catch(_) {}
                        } catch(_) {}
                    })();

                    // Strong restoration: if we can fetch products, do a fresh fetch to fully rehydrate state and grid
                    if (typeof window.fetchAndDisplayOrderProducts === 'function') {
                        if (window.eaoDebug) { console.log('[EAO][PostSave] Calling fetchAndDisplayOrderProducts for rehydrate'); }
                        window.fetchAndDisplayOrderProducts(function(){
                            if (window.eaoDebug) { console.log('[EAO][PostSave] fetchAndDisplayOrderProducts callback fired'); }
                            try {
                                if (window.EAO && window.EAO.ProductsTable && typeof window.EAO.ProductsTable.render === 'function') {
                                    const listEl = jQuery('#eao-current-order-items-list')[0];
                                    if (window.eaoDebug) { console.log('[EAO][PostSave] Rendering grid after fetch. items:', (window.currentOrderItems||[]).length, 'list exists?', !!listEl); }
                                    window.EAO.ProductsTable.render(window.currentOrderItems || [], { container: listEl });
                                }
                            } catch(_) {}
                        });
                    } else if (window.EAO && window.EAO.ProductsTable && typeof window.EAO.ProductsTable.render === 'function') {
                        const listEl = jQuery('#eao-current-order-items-list')[0];
                        if (window.eaoDebug) { console.log('[EAO][PostSave] Rendering grid without fetch. items:', (window.currentOrderItems||[]).length, 'list exists?', !!listEl); }
                        window.EAO.ProductsTable.render(window.currentOrderItems || [], { container: listEl });
                    }

                    // Extra safety repaint after DOM settles
                    setTimeout(() => {
                        try {
                            const listEl2 = jQuery('#eao-current-order-items-list')[0];
                            if (window.EAO && window.EAO.ProductsTable && typeof window.EAO.ProductsTable.render === 'function') {
                                if (window.eaoDebug) { console.log('[EAO][PostSave] Follow-up repaint'); }
                                window.EAO.ProductsTable.render(window.currentOrderItems || [], { container: listEl2 });
                            }
                        } catch(_) {}
                    }, 350);
                } catch(_) {}
            }, 600);
        },

        /**
         * Mark form as saved (reset change detection)
         */
        markFormAsSaved: function() {
            console.log('[EAO Form Submission] Marking form as saved...');
            
            // Reset change detection module
            if (window.EAO && window.EAO.ChangeDetection && typeof window.EAO.ChangeDetection.markFormAsSaved === 'function') {
                console.log('[EAO Form Submission] Calling change detection markFormAsSaved...');
                window.EAO.ChangeDetection.markFormAsSaved();
            } else {
                console.error('[EAO Form Submission] Change detection module or markFormAsSaved function not available!');
                console.error('[EAO Form Submission] Available modules:', window.EAO ? Object.keys(window.EAO) : 'EAO namespace not found');
                
                // Fallback: try global function
                if (typeof window.markFormAsSaved === 'function') {
                    console.log('[EAO Form Submission] Using global markFormAsSaved fallback...');
                    window.markFormAsSaved();
                } else {
                    console.error('[EAO Form Submission] No markFormAsSaved function available - form state may not reset properly');
                }
            }
            
            // Update button states
            this.updateButtonStates();
        },

        /**
         * Update button states based on current status
         */
        updateButtonStates: function() {
            if (this.isCurrentlySaving) {
                this.$saveButton.prop('disabled', true).text('Saving...');
            } else {
                // Let change detection module handle button states
                if (window.EAO && window.EAO.ChangeDetection) {
                    window.EAO.ChangeDetection.triggerCheck();
                }
            }
        },

        /**
         * Show save overlay
         */
        showSaveOverlay: function() {
            $('#eao-save-title').text('Saving Order...');
            $('#eao-save-message').text('Please wait while we update your order details.');
            
            try {
                this.$saveOverlay.addClass('is-visible').css('display','block');
                // Ensure legacy overlay is visible too so user sees the bar immediately
                try { jQuery('#eao-save-overlay').show(); } catch(_) {}
            } catch(_) { this.$saveOverlay.show(); }
            this.updateProgress(10, 'Initializing...');
            
            // Store overlay state with timestamp
            sessionStorage.setItem('eao_save_overlay_visible', 'true');
            sessionStorage.setItem('eao_save_timestamp', Date.now().toString());
        },

        /**
         * Hide save overlay
         */
        hideSaveOverlay: function() {
            try {
                var el = this.$saveOverlay.removeClass('is-visible');
                // Hide after transition if any
                setTimeout(function(){ try { el.css('display','none'); } catch(_){ el.hide(); } }, 220);
                try { jQuery('#eao-save-overlay').hide(); } catch(_) {}
            } catch(_) { this.$saveOverlay.hide(); }
            
            // Clear all stored state using helper function
            this.clearSessionStorage();
        },

        /**
         * Clear all session storage data related to save overlay
         */
        clearSessionStorage: function() {
            console.log('[EAO Form Submission] Clearing all session storage data...');
            sessionStorage.removeItem('eao_save_overlay_visible');
            sessionStorage.removeItem('eao_save_timestamp');
            sessionStorage.removeItem('eao_save_stage');
            sessionStorage.removeItem('eao_save_progress');
            sessionStorage.removeItem('eao_save_progress_text');
            sessionStorage.removeItem('eao_save_overlay_active');
        },

        /**
         * Show save success state
         */
        showSaveSuccess: function() {
            $('#eao-save-title').text('Success!');
            $('#eao-save-message').text('Your order has been updated successfully.');
            this.updateProgress(70, 'Save completed successfully');
        },

        /**
         * Update save stage text
         */
        updateSaveStage: function(stage) {
            this.$saveStage.text(stage);
            sessionStorage.setItem('eao_save_stage', stage);
        },

        /**
         * Update progress bar and text
         */
        updateProgress: function(percent, text) {
            // Monotonic progress: never regress
            try {
                if (typeof this.currentProgress !== 'number') { this.currentProgress = 0; }
                percent = Math.max(this.currentProgress, Math.min(100, parseInt(percent,10)||0));
                this.currentProgress = percent;
            } catch(_) {}
            this.$saveProgress.css('width', percent + '%');
            $('#eao-save-progress-text').text(text);
            
            // Store progress state
            sessionStorage.setItem('eao_save_progress', percent);
            sessionStorage.setItem('eao_save_progress_text', text);
        },
        
        animateProgressTo: function(target, text, durationMs) {
            try {
                target = Math.max(this.currentProgress, Math.min(100, parseInt(target,10)||0));
                const start = this.currentProgress;
                const dist = target - start;
                if (dist <= 0) { return this.updateProgress(target, text || ''); }
                const startTs = performance.now();
                const run = (nowTs) => {
                    const t = Math.min(1, (nowTs - startTs) / (Math.max(100, durationMs||300)));
                    const eased = 1 - Math.pow(1 - t, 3);
                    const val = start + (dist * eased);
                    this.updateProgress(val, text || '');
                    if (t < 1) { requestAnimationFrame(run); }
                };
                requestAnimationFrame(run);
            } catch(_) { this.updateProgress(target, text || ''); }
        },

        startIndeterminateProgress: function(capPercent, stepMs) {
            try { this.stopIndeterminateProgress(); } catch(_) {}
            const self = this;
            const cap = Math.min(100, Math.max(this.currentProgress, parseInt(capPercent,10)||60));
            const minStep = Math.max(200, parseInt(stepMs,10)||1200);
            let lastTs = performance.now();
            const tick = function(now){
                const dt = now - lastTs;
                if (dt >= (minStep/6)) { // smaller, smoother bumps
                    const bump = Math.max(0.12, (cap - self.currentProgress) * 0.012);
                    if (self.currentProgress < cap - 0.1) {
                        self.updateProgress(self.currentProgress + bump, 'Waiting for server...');
                    } else {
                        self.updateProgress(cap, 'Waiting for server...');
                    }
                    lastTs = now;
                }
                self._progressIndeterminateRafId = requestAnimationFrame(tick);
            };
            this._progressIndeterminateRafId = requestAnimationFrame(tick);
        },

        stopIndeterminateProgress: function() {
            if (this._progressIndeterminateRafId) {
                cancelAnimationFrame(this._progressIndeterminateRafId);
                this._progressIndeterminateRafId = null;
            }
            if (this._progressIndeterminateTimer) {
                clearInterval(this._progressIndeterminateTimer);
                this._progressIndeterminateTimer = null;
            }
        },

    };

    // Expose module globally
    console.log('[EAO Form Submission] Module defined and ready for initialization');

})(jQuery); 