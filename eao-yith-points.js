/**
 * Enhanced Admin Order - YITH Points Integration
 * 
 * @package EnhancedAdminOrder
 * @since 2.5.10
 * @version 2.7.23 - Removed debug logs for production deployment
 * @author Amnon Manneberg
 */

(function($) {
'use strict';

// Ensure EAO namespace exists
window.EAO = window.EAO || {};

/**
 * YITH Points Integration Module
 */
window.EAO.YithPoints = {
    
    isUpdating: false, // Prevent infinite loops
    userIsChangingPoints: false, // Prevent overriding user input with automatic calculations
    
    /**
     * Initialize YITH Points integration with proper hooks
     */
    init: function() {
        this.setupSummaryHooks();
        this.setupRealTimeUpdates();
        // DISABLED: Old initial points calculation - now handled by unified system
        // this.checkInitialPointsValue();

        // Ensure points UI persists after a full save/refresh cycle
        const self = this;
        $(document).on('eao_save_complete', function(){
            try {
                let posted = null;
                if (window.EAO && window.EAO.FormSubmission && typeof window.EAO.FormSubmission.lastPostedPoints === 'number') {
                    posted = window.EAO.FormSubmission.lastPostedPoints;
                } else if (window.eaoStagedPointsDiscount && typeof window.eaoStagedPointsDiscount.points === 'number') {
                    posted = parseInt(window.eaoStagedPointsDiscount.points, 10);
                }
                if (posted !== null) {
                    self.isUpdating = true;
                    self.synchronizePointsInputs(posted, null);
                    self.updateGrandTotalOnly(posted);
                    self.isUpdating = false;
                }
            } catch (e) {}
        });
    },

    /**
     * Hook into the centralized summary system
     */
    setupSummaryHooks: function() {
        const self = this;
        
        // Listen for summary updates from the centralized system
        $(document).off('eaoSummaryUpdated.eaoPoints').on('eaoSummaryUpdated.eaoPoints', function(event, summaryData, options) {
            // Prevent infinite loops
            if (self.isUpdating) {
                return;
            }
            // Ignore full_refresh and summary_only cycles to prevent render loops
            try {
                if (options && (options.context === 'full_refresh' || options.context === 'summary_only' || options.context === 'full_refresh_with_all_data')) {
                    return;
                }
            } catch(_) {}
            // Short lock window after user or programmatic set to avoid jitter
            try { if (window.eaoPointsLockUntil && Date.now() < window.eaoPointsLockUntil) { return; } } catch(_) {}
            
            // Refresh points max/limits on normal cycles; ignore explicit yith_update to prevent loops
            if (options && options.context !== 'yith_update' && options.trigger_hooks !== false) {
                self.updatePointsMaxCalculation();
            }
            
            // Re-assert the last posted points (including 0) after summary render,
            // but only if user is not actively changing the slider/input
            let postedValue = null;
            try {
                if (window.EAO && window.EAO.FormSubmission && typeof window.EAO.FormSubmission.lastPostedPoints === 'number') {
                    postedValue = window.EAO.FormSubmission.lastPostedPoints;
                } else if (window.eaoStagedPointsDiscount && typeof window.eaoStagedPointsDiscount.points === 'number') {
                    postedValue = parseInt(window.eaoStagedPointsDiscount.points, 10);
                } else if (window.eaoCurrentPointsDiscount && typeof window.eaoCurrentPointsDiscount.points !== 'undefined') {
                    postedValue = parseInt(window.eaoCurrentPointsDiscount.points, 10) || 0;
                } else if (typeof window.pendingPointsToRedeem !== 'undefined') {
                    postedValue = parseInt(window.pendingPointsToRedeem, 10) || 0;
                }
            } catch (e) { /* ignore */ }
            // Throttle identical full_refresh values to prevent loops
            try {
                window.eaoPointsSummaryLastTs = window.eaoPointsSummaryLastTs || 0;
                window.eaoPointsSummaryLastValue = (typeof window.eaoPointsSummaryLastValue !== 'undefined') ? window.eaoPointsSummaryLastValue : null;
                if (options && options.context === 'full_refresh' && window.eaoPointsSummaryLastValue === postedValue && (Date.now() - window.eaoPointsSummaryLastTs) < 700) {
                    return;
                }
            } catch(_) {}
            
            if (postedValue !== null && !self.userIsChangingPoints) {
                // If UI already has a non-zero value and postedValue is 0, do not downgrade
                try {
                    var $f1 = $('#eao_points_to_redeem_inline');
                    var $f2 = $('#eao_points_to_redeem');
                    var currentUiVal = 0;
                    if ($f1 && $f1.length) { currentUiVal = Math.max(currentUiVal, (parseInt($f1.val() || '0', 10) || 0)); }
                    if ($f2 && $f2.length) { currentUiVal = Math.max(currentUiVal, (parseInt($f2.val() || '0', 10) || 0)); }
                    if (postedValue === currentUiVal) { return; }
                    if (postedValue === 0 && currentUiVal > 0) { return; }
                } catch(_) {}
                setTimeout(function() {
                    self.isUpdating = true;
                    self.synchronizePointsInputs(postedValue, null);
                    self.updateGrandTotalOnly(postedValue);
                    self.isUpdating = false;
                    try { window.eaoPointsSummaryLastTs = Date.now(); window.eaoPointsSummaryLastValue = postedValue; } catch(_) {}
                }, 0);
            }
        });
    },

    /**
     * Setup real-time updates for points slider/input
     */
    setupRealTimeUpdates: function() {
        const self = this;
        
        // Use comprehensive selector for all possible points fields (both metabox and inline)
        const pointsSelector = '#eao_points_slider, #eao_points_to_redeem, #eao_points_slider_inline, #eao_points_to_redeem_inline, .eao-points-slider, .eao-inline-slider, .eao-points-input-inline';
        
        // Listen for points slider/input changes with improved coordination
        let changeTimeout;
        let lastChangedElement = null;
        let releaseFocusTimer;

        // Make bindings idempotent to avoid duplicates on repeated renders
        $(document).off('input.eaoPointsRt change.eaoPointsRt click.eaoPointsRt', pointsSelector);
        $(document).on('input.eaoPointsRt change.eaoPointsRt click.eaoPointsRt', pointsSelector, function(event) {
            const $this = $(this);
            const value = parseInt($this.val()) || 0;
            const elementId = $this.attr('id') || $this.attr('class');
            const isInline = $this.is('#eao_points_slider_inline, .eao-inline-slider, .eao-points-input-inline');
            // Guard: if a post-release lock is active, ignore only programmatic updates (not user clicks)
            try {
                const isUserEvent = !!(event && ((event.originalEvent && event.originalEvent.isTrusted) || event.isTrusted === true));
                if (!isInline && window.eaoSuppressPointsSyncUntil && Date.now() < window.eaoSuppressPointsSyncUntil && !isUserEvent) {
                    return;
                }
            } catch(_) {}
            
            // Skip if this is a programmatic update from synchronization
            if (self.isUpdating) {
                return;
            }
            
            // Reduce console spam - only log every 10th change
            if (!window.eaoPointsChangeCount) window.eaoPointsChangeCount = 0;
            window.eaoPointsChangeCount++;
            if (window.eaoPointsChangeCount % 10 === 0) {
    
            }
            
            // For inline slider/input: keep it lightweight while dragging
            if (isInline) {
                // During input on inline, only sync the paired inline fields and stage the value
                if (event.type === 'input') {
                    // While dragging, block other modules from firing full renders
                    try {
                        window.eaoInputFocusLock = true;
                        if (releaseFocusTimer) { clearTimeout(releaseFocusTimer); }
                        releaseFocusTimer = setTimeout(function(){ window.eaoInputFocusLock = false; }, 260);
                        // Prevent eaoSummaryUpdated from reasserting values while dragging
                        window.eaoPointsLockUntil = Date.now() + 900;
                    } catch(_) {}
                    // Mark user interaction to suppress max recalculation in hooks
                    self.userIsChangingPoints = true;
                    setTimeout(function(){ self.userIsChangingPoints = false; }, 300);
                    self.isUpdating = true;
                    self.synchronizePointsInputs(value, $this.attr('id'));
                    self.isUpdating = false;
                    // Stage value for downstream calculations
                    if (!window.eaoStagedPointsDiscount) { window.eaoStagedPointsDiscount = {}; }
                    const rr = (window.pointsRedeemRate && window.pointsRedeemRate > 0) ? window.pointsRedeemRate : 10;
                    window.eaoStagedPointsDiscount.points = value;
                    window.eaoStagedPointsDiscount.amount = (value / rr).toFixed(2);
                    // Special case: when value is explicitly 0, immediately zero the monetary cell
                    if (value === 0) {
                        try {
                            const $rowVal = $('.eao-summary-points-discount .eao-summary-monetary-value');
                            if ($rowVal && $rowVal.length) { $rowVal.text('$0.00'); }
                            // Also clear any stale current discount object to avoid fallbacks
                            if (window.eaoCurrentPointsDiscount) { window.eaoCurrentPointsDiscount.points = 0; window.eaoCurrentPointsDiscount.amount = 0; }
                        } catch(_) {}
                    }
                    // Let MainCoordinator's input handler update the three lines; avoid heavy UI work here
                    return; // abort further processing on input for inline controls
                }
                // On change for inline controls, MainCoordinator will do the full refresh; avoid duplicate work
                if (event.type === 'change') {
                    self.isUpdating = true;
                    self.synchronizePointsInputs(value, $this.attr('id'));
                    self.isUpdating = false;
                    // Ensure summary is allowed to repaint immediately after slider release
                    try { window.eaoInputFocusLock = false; } catch(_) {}
                    const rr = (window.pointsRedeemRate && window.pointsRedeemRate > 0) ? window.pointsRedeemRate : 10;
                    if (!window.eaoStagedPointsDiscount) { window.eaoStagedPointsDiscount = {}; }
                    window.eaoStagedPointsDiscount.points = value;
                    window.eaoStagedPointsDiscount.amount = (value / rr).toFixed(2);
                    // Persist as lastPostedPoints so subsequent summary renders don't revert slider
                    if (window.EAO && window.EAO.FormSubmission) {
                        window.EAO.FormSubmission.lastPostedPoints = value;
                    }
                    // Short lock to ignore late eaoSummaryUpdated while the refresh propagates
                    try { window.eaoPointsLockUntil = Date.now() + 1200; } catch(_) {}
                    // Trigger a targeted summary-only update so totals change immediately
                    try {
                        if (window.EAO && window.EAO.MainCoordinator) {
                            const items = window.currentOrderItems || [];
                            const summary = window.EAO.MainCoordinator.calculateUnifiedSummaryData(items);
                            window.EAO.MainCoordinator.refreshSummaryOnly(items, summary);
                        }
                    } catch(_) {}
                    // Keep a short suppression window so template recalcs won't bounce the value
                    window.eaoSuppressPointsSyncUntil = Date.now() + 1800;
                    // Bump refresh generation to ignore stale async responses
                    try { window.eaoPointsRefreshGen = (window.eaoPointsRefreshGen || 0) + 1; } catch(_) {}
                    return; // avoid duplicate full refresh here
                }
            }

            // Set flag to prevent feedback loops during synchronization AND prevent max recalculation
            self.isUpdating = true;
            self.userIsChangingPoints = true; // NEW: Flag to prevent automatic recalculation
            lastChangedElement = elementId;

            // Synchronize all fields immediately for responsive UI
            self.synchronizePointsInputs(value, $this.attr('id'));

            // Reset updating flag after synchronization (but keep userIsChangingPoints until template call completes)
            self.isUpdating = false;
            
            // STAGING MODE: Just update visual display, don't call YITH template function
            // Store the staged value for summary calculation (staging mode like other changes)
            if (!window.eaoStagedPointsDiscount) {
                window.eaoStagedPointsDiscount = {};
            }
            window.eaoStagedPointsDiscount.points = value;
            var redeemRate = (window.pointsRedeemRate && window.pointsRedeemRate > 0) ? window.pointsRedeemRate : 10; // points per $
            window.eaoStagedPointsDiscount.amount = (value / redeemRate).toFixed(2);

            // Persist the latest user-selected value to the global FormSubmission state
            // so re-renders (eaoSummaryUpdated) re-assert this exact value and do not jump
            if (window.EAO && window.EAO.FormSubmission) {
                window.EAO.FormSubmission.lastPostedPoints = value;
            }
            
            // Update the dollar amount display immediately for responsive feedback
            const $pointsRow = $('.eao-summary-points-discount');
            if ($pointsRow.length > 0) {
                // Find the monetary value cell in the new structure
                const $amountCell = $pointsRow.find('.eao-summary-monetary-value');
                if ($amountCell.length > 0) {
                    const rr = (window.pointsRedeemRate && window.pointsRedeemRate > 0) ? window.pointsRedeemRate : 10;
                    const formattedAmount = value > 0 ? '-$' + (value / rr).toFixed(2) : '$0.00';
                    $amountCell.text(formattedAmount);
        
                } else {
                    console.warn('[EAO YITH Points] Could not find monetary value cell to update');
                }
            } else {
                console.warn('[EAO YITH Points] Could not find points discount row');
            }
            
            // CRITICAL: Integrate with change detection only for non-inline sources to avoid jitter while dragging
            if (!isInline) {
                if (window.EAO && window.EAO.ChangeDetection && typeof window.EAO.ChangeDetection.triggerCheck === 'function') {
                    window.EAO.ChangeDetection.triggerCheck();
                }
            }
            
            // OPTIMIZED: For non-inline sources, update Grand Total directly on click/change; inline handled by coordinator
            if (!isInline || event.type === 'click') {
                self.updateGrandTotalOnly(value);
            }
            
            // Clear timeout to prevent multiple rapid updates
            if (changeTimeout) {
                clearTimeout(changeTimeout);
            }
            
            // Debounce the Grand Total update for smoother operation (non-inline only)
            if (!isInline) {
                changeTimeout = setTimeout(() => {
                    self.updateGrandTotalOnly(value);
                }, 100);
            }
            
            // Clear the user changing flag immediately since we're not calling external functions
            setTimeout(() => {
                self.userIsChangingPoints = false;
            }, 50); // Short delay to ensure all sync operations complete
            

        });
        

    },

    /**
     * Update YITH Points max calculation (called after summary rendering)
     * This is for non-user-initiated updates (like initial load)
     */
    updatePointsMaxCalculation: function() {
        // Prevent infinite loops
        if (this.isUpdating) {
            return;
        }
        
        // CRITICAL: Don't override user's active point changes
        if (this.userIsChangingPoints) {

            return;
        }

        // If we have a staged points value, prefer keeping it and avoid calling
        // the template recalculation that may override the slider/input value.
        if (window.eaoStagedPointsDiscount && typeof window.eaoStagedPointsDiscount.points === 'number') {
            const staged = parseInt(window.eaoStagedPointsDiscount.points, 10) || 0;
            this.isUpdating = true;
            this.synchronizePointsInputs(staged, null);
            this.updateGrandTotalOnly(staged);
            this.isUpdating = false;
            return;
        }
        
        // Only call if we're not in a two-phase rendering process
        if (window.eaoTwoPhaseRenderingInProgress) {

            return;
        }
        
        // Check if template function exists and call it
        if (typeof window.updateOrderSummaryWithPointsDiscount === 'function') {
            try {
                this.isUpdating = true;
                window.updateOrderSummaryWithPointsDiscount();
    
            } catch (error) {
                console.error('[EAO YITH Points] Error updating max calculation:', error);
            } finally {
                this.isUpdating = false;
            }
        } else {
            console.warn('[EAO YITH Points] Template function updateOrderSummaryWithPointsDiscount not available');
        }
    },

    /**
     * Handle points input/slider value synchronization
     */
    synchronizePointsInputs: function(value, sourceElementId) {
        // Find all possible points input fields and sync them (both metabox and inline)
        const $allFields = $('#eao_points_slider, #eao_points_to_redeem, #eao_points_slider_inline, #eao_points_to_redeem_inline, .eao-inline-slider, .eao-inline-input, .eao-points-input-inline');
        
        if ($allFields.length > 0) {
            $allFields.each(function() {
                const $field = $(this);
                const fieldId = $field.attr('id');
                
                // Skip the field that triggered the change to avoid feedback loops
                if (fieldId === sourceElementId) {
                    return;
                }
                
                if (parseInt($field.val()) !== parseInt(value)) {
                    $field.val(value);
    
                }
            });
        }
    },

    /**
     * Update inline points label for summary slider (triggers summary re-render)
     * NOTE: This is no longer used with the simplified structure where the input field 
     * in the label gets updated directly by synchronizePointsInputs()
     */
    updateInlinePointsLabel: function(value) {
        // Deprecated - the input field in the label gets updated automatically
        // by synchronizePointsInputs() when any field changes
    },

    /**
     * Update max values for all sliders (both metabox and inline)
     */
    updatePointsMaxValues: function(maxPoints) {
        if (maxPoints > 0) {
            const $allSliders = $('#eao_points_slider, #eao_points_to_redeem, #eao_points_slider_inline, #eao_points_to_redeem_inline, .eao-inline-slider, .eao-inline-input, .eao-points-input-inline');
            $allSliders.attr('max', maxPoints);
            
            // Update the max label for inline slider
            $('.eao-slider-max-label').text(maxPoints);
            
            // Update max attribute for inline input field in label
            $('.eao-points-input-inline').attr('max', maxPoints);
            

        }
    },

    /**
     * Efficiently update only the Grand Total without full summary recalculation
     */
    updateGrandTotalOnly: function(pointsValue) {
        try {
            // Calculate the points discount amount
            const pointsDiscountAmount = pointsValue > 0 ? (pointsValue / 10) : 0;
            
            // Get current summary values: use products net from the dedicated row instead of gross
            const $productsNetRow = $('.eao-summary-subtotal-line .eao-summary-monetary-value');
            const $itemsTotal = $productsNetRow.length ? $productsNetRow : $('.eao-summary-row').find('.eao-summary-monetary-value').first();
            const $grandTotal = $('.eao-grand-total-line .eao-summary-monetary-value, .eao-grand-total-value');
            const $shippingRow = $('.eao-summary-current-shipping .eao-summary-monetary-value');
            const shippingVal = $shippingRow.length ? parseFloat($shippingRow.text().replace(/[$,]/g, '')) || 0 : 0;
            
            if ($itemsTotal.length > 0 && $grandTotal.length > 0) {
                // Extract numeric values (remove $ and commas)
                const itemsTotalText = $itemsTotal.text().replace(/[$,]/g, '');
                const itemsTotal = parseFloat(itemsTotalText) || 0;
                
                // Calculate new grand total (net products + shipping - points)
                const newGrandTotal = itemsTotal + shippingVal - pointsDiscountAmount;
                
                // Format and update the Grand Total display
                if (typeof eaoFormatPrice === 'function') {
                    $grandTotal.text(eaoFormatPrice(newGrandTotal));
                } else {
                    $grandTotal.text('$' + newGrandTotal.toFixed(2));
                }
                
    
            }
        } catch (error) {
            console.error('[EAO YITH Points] Error updating Grand Total:', error);
        }
    },

    /**
     * Find points input fields using multiple selectors (both metabox and inline)
     */
    findPointsFields: function() {
        const possibleSelectors = [
            '#eao_points_slider, #eao_points_to_redeem, #eao_points_slider_inline, #eao_points_to_redeem_inline',  // All EAO fields
            '.eao-inline-slider, .eao-inline-input',     // Class-based inline fields
            '#yith_points_slider, #yith_points_input',    // Alternative YITH selectors
            'input[type="range"][name*="points"], input[type="number"][name*="points"]',  // Generic points fields
            '.eao-points-slider, .eao-points-input'      // Class-based selectors
        ];
        
        for (let selector of possibleSelectors) {
            const $fields = $(selector);
            if ($fields.length > 0) {
    
                return $fields;
            }
        }
        
        console.warn('[EAO YITH Points] No points fields found with any selector');
        return $();
    }
};

// Initialize when DOM is ready
$(document).ready(function() {
    window.EAO.YithPoints.init();
    try {
        // Initialize to saved points from either current or existing redemption (template-injected)
        var initPts = 0;
        if (window.eaoCurrentPointsDiscount && typeof window.eaoCurrentPointsDiscount.points !== 'undefined') {
            initPts = parseInt(window.eaoCurrentPointsDiscount.points, 10) || 0;
        } else if (typeof window.existingPointsRedeemed !== 'undefined') {
            initPts = parseInt(window.existingPointsRedeemed, 10) || 0;
            if (initPts > 0) {
                window.eaoCurrentPointsDiscount = { points: initPts, amount: (initPts / 10) };
            }
        }
        try { console.log('[EAO][PointsInit][Module] Derived initPts:', initPts, 'eaoCurrentPointsDiscount:', window.eaoCurrentPointsDiscount, 'existingPointsRedeemed:', window.existingPointsRedeemed, 'pending:', window.pendingPointsToRedeem); } catch(_) {}
        if (initPts >= 0) {
            window.EAO.YithPoints.synchronizePointsInputs(initPts, null);
        }
    } catch(_) {}
});

})(jQuery); 