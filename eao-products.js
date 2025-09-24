/**
 * Products Module - Handles product search, addition, removal, discount calculations
 * 
 * @package EnhancedAdminOrder
 * @since 2.4.0
 * @version 2.9.12 - COMPLETE BULK FIX: Enhanced to process ALL items with visible Apply Admin Settings buttons
 * @author Amnon Manneberg
 */

(function($) {
    'use strict';
    
    // Ensure EAO namespace exists
    window.EAO = window.EAO || {};

    /**
     * Products Management Module
     */
    window.EAO.Products = {
        
        // State variables
        currentOrderItems: [],
        stagedOrderItems: [],
        itemsPendingDeletion: [],
        productSearchRequest: null,
        currentOrderSummaryData: {},
        
        // jQuery elements (will be initialized)
        $currentOrderItemsList: null,
        $currentOrderItemsSummary: null,
        $productSearchInput: null,
        $productSearchResults: null,
        $globalOrderDiscountInput: null,
        
        /**
         * Initialize the products module
         */
        init: function() {
            // Initialize jQuery elements
            this.$currentOrderItemsList = $('#eao-current-order-items-list');
            this.$currentOrderItemsSummary = $('#eao-current-order-items-summary');
            this.$productSearchInput = $('#eao_product_search_term');
            this.$productSearchResults = $('#eao_product_search_results');
            this.$globalOrderDiscountInput = $('#eao_order_products_discount_percent');
            // Apply Cost button handler: set exclude GD and discounted price = cost
            $(document).on('click', '.eao-apply-cost-btn', (e) => {
                e.preventDefault();
                const $btn = $(e.currentTarget);
                // Primary: within legacy/tabs cell wrapper
                let $itemRow = $btn.closest('.eao-product-editor-item');
                // Fallback: Tabulator layout – not wrapped; use nearest row container
                const $tabRow = $btn.closest('.tabulator-row');
                const productIdAttr = $btn.attr('data-product_id') || '';
                const itemIdAttr = $btn.attr('data-item_id') || '';
                const productId = String(($itemRow.data && $itemRow.data('product_id')) || productIdAttr || '');
                const itemId = String(($itemRow.data && $itemRow.data('item_id')) || itemIdAttr || '');
                // Get cost text: from sibling span when no wrapper
                let costText = '';
                if ($itemRow && $itemRow.length) {
                    costText = $itemRow.find('.eao-cost-display').text();
                } else {
                    costText = $btn.prev('.eao-cost-display').text();
                }
                const costVal = parseFloat((costText || '').toString().replace(/[^0-9.\-]/g, ''));
                if (isNaN(costVal)) return;
                // Cost source debug removed for production

                const itemInCurrent = (window.currentOrderItems || []).find(item =>
                    (itemId && String(item.item_id) === itemId) ||
                    (!itemId && String(item.product_id) === productId && item.isPending && !item.isExisting)
                );
                if (!itemInCurrent) return;

                // Ensure excluded flag automatically so the percent/price fields are enabled
                itemInCurrent.exclude_gd = true;
                // Try to check the checkbox in the same row (Tabulator)
                let $cbx = $();
                if ($tabRow && $tabRow.length) {
                    $cbx = $tabRow.find('.eao-item-exclude-gd-cbx').first();
                }
                if ((!$cbx || !$cbx.length) && $itemRow && $itemRow.length) {
                    $cbx = $itemRow.find('.eao-item-exclude-gd-cbx').first();
                }
                if (!$cbx || !$cbx.length) {
                    // Global fallback by data attributes
                    $cbx = $(
                        `.eao-item-exclude-gd-cbx[data-item_id="${itemId}"]` +
                        `, .eao-item-exclude-gd-cbx[data-product_id="${productId}"]`
                    ).first();
                }
                if ($cbx && $cbx.length) {
                    // Set checked without triggering change to avoid external toggle handlers reversing it
                    $cbx.prop('checked', true);
                }

                // Apply cost as discounted price using helpers for consistency with staging
                const basePrice = (itemInCurrent && typeof itemInCurrent.price_raw !== 'undefined')
                    ? parseFloat(itemInCurrent.price_raw) || 0
                    : ( ($itemRow && $itemRow.length) ? (parseFloat($itemRow.data('base_price')) || 0) : 0 );
                const priceDecimals = parseInt(eaoEditorParams.price_decimals || 2, 10);
                let percent = 0;
                try {
                    const gd = parseFloat($('#eao_order_products_discount_percent').val() || 0) || 0;
                    if (window.EAO && window.EAO.ProductsHelpers) {
                        window.EAO.ProductsHelpers.applyExcludeChange(itemInCurrent, true, gd);
                        window.EAO.ProductsHelpers.applyDiscountedPrice(itemInCurrent, costVal);
                    } else {
                        itemInCurrent.is_markup = false;
                        itemInCurrent.discounted_price_fixed = costVal;
                        if (basePrice > 0) {
                            percent = Math.max(0, Math.min(100, ((basePrice - costVal) / basePrice) * 100));
                        }
                        itemInCurrent.discount_percent = percent;
                    }
                } catch(_) {
                    itemInCurrent.is_markup = false;
                    itemInCurrent.discounted_price_fixed = costVal;
                    if (basePrice > 0) {
                        percent = Math.max(0, Math.min(100, ((basePrice - costVal) / basePrice) * 100));
                    }
                    itemInCurrent.discount_percent = percent;
                }
                // Recompute percent for UI from current state
                if (basePrice > 0) {
                    percent = Math.max(0, Math.min(100, ((basePrice - (parseFloat(itemInCurrent.discounted_price_fixed)||0)) / basePrice) * 100));
                } else { percent = 0; }

                // Update inputs
                $itemRow.find('.eao-item-discounted-price-input').val(costVal.toFixed(priceDecimals)).prop('disabled', false);
                $itemRow.find('.eao-item-discount-percent-input').val(percent.toFixed(1)).prop('disabled', false);

                // Update WC staging fields and re-render summary
                if (window.EAO && window.EAO.Utils && window.EAO.Utils.updateItemWCStagingFields) {
                    const globalDiscount = parseFloat($('#eao_order_products_discount_percent').val() || 0);
                    window.EAO.Utils.updateItemWCStagingFields(itemInCurrent, globalDiscount);
                }
                if (window.EAO && window.EAO.MainCoordinator) {
                    const summaryData = window.EAO.MainCoordinator.calculateUnifiedSummaryData(window.currentOrderItems);
                    window.EAO.MainCoordinator.renderAllOrderItemsAndSummary(window.currentOrderItems, summaryData);
                }
                if (window.EAO && window.EAO.ChangeDetection) {
                    window.EAO.ChangeDetection.triggerCheck();
                }

                // Post-render UI assertion: ensure the Exclude GD checkbox is visually checked
                setTimeout(function(){
                    try {
                        const $cb = $(
                            `.eao-item-exclude-gd-cbx[data-item_id="${itemId}"]` +
                            `, .eao-item-exclude-gd-cbx[data-product_id="${productId}"]`
                        ).first();
                        if ($cb && $cb.length) { $cb.prop('checked', true); }
                        // Also ensure price/percent inputs reflect applied values and are enabled
                        const $dp = $(`.eao-item-discounted-price-input[data-item_id="${itemId}"] , .eao-item-discounted-price-input[data-product_id="${productId}"]`).first();
                        if ($dp && $dp.length) { $dp.val((parseFloat(itemInCurrent.discounted_price_fixed)||0).toFixed(priceDecimals)).prop('disabled', false); }
                        const $pp = $(`.eao-item-discount-percent-input[data-item_id="${itemId}"] , .eao-item-discount-percent-input[data-product_id="${productId}"]`).first();
                        if ($pp && $pp.length) { $pp.val(percent.toFixed(1)).prop('disabled', false); }
                        try { console.log('[EAO ApplyCost] enforced UI state', { itemId, productId, exclude_gd: true, discounted_price_fixed: itemInCurrent.discounted_price_fixed, discount_percent: itemInCurrent.discount_percent }); } catch(_e) {}
                    } catch(_err) {}
                }, 80);
            });

            // Points details popup (supports both legacy markup and Tabulator cells)
            $(document).on('click', '.eao-points-details-btn', (e) => {
                e.preventDefault();
                const $btn = $(e.currentTarget);
                let itemId = $btn.attr('data_item_id') || $btn.attr('data-item_id') || $btn.attr('data-item-id') || '';
                if (!itemId) {
                const $row = $btn.closest('.eao-product-editor-item');
                    itemId = String($row.data('item_id') || '');
                }
                if (!itemId) { return; }
                if (window.EAO && window.EAO.MainCoordinator && typeof window.EAO.MainCoordinator.showPointsDetailsForItem === 'function') {
                    window.EAO.MainCoordinator.showPointsDetailsForItem(itemId);
                }
            });
            
            // Initialize arrays if they don't exist
            if (!window.currentOrderItems) window.currentOrderItems = [];
            if (!window.stagedOrderItems) window.stagedOrderItems = [];
            if (!window.itemsPendingDeletion) window.itemsPendingDeletion = [];
            
            // Sync with global state
            this.currentOrderItems = window.currentOrderItems;
            this.stagedOrderItems = window.stagedOrderItems;
            this.itemsPendingDeletion = window.itemsPendingDeletion;
            
            // Set up event handlers
            this.bindProductEvents();

            // Tabs: delegation for switching panels (UI only; safe to keep)
            $(document).off('click.eaoTabs', '.eao-item-tabs-nav .eao-tab-btn').on('click.eaoTabs', '.eao-item-tabs-nav .eao-tab-btn', function() {
                const $btn = $(this);
                const tab = $btn.data('tab');
                const $tabs = $btn.closest('.eao-item-tabs');
                // Toggle active button state
                $tabs.find('.eao-tab-btn').removeClass('is-active');
                $btn.addClass('is-active');
                // Toggle panels
                $tabs.find('.eao-item-tab-panel').removeClass('is-active');
                $tabs.find('.eao-item-tab-panel[data-tab-panel="' + tab + '"]').addClass('is-active');
            });

            // Helper to set default tab per row after render
            function setDefaultTabForRow($row) {
                const orderStatus = (eaoEditorParams.order_status || '').toLowerCase();
                const sm = (window.eaoShipmentsMeta || {});
                const numShipments = parseInt(sm.num_shipments || 0, 10) || 0;
                const hideShip = (orderStatus === 'pending' || orderStatus === 'processing' || numShipments < 2);
                const $tabs = $row.find('.eao-item-tabs');
                if (!$tabs.length) return;
                // Default: pricing active
                let defaultTab = 'pricing';
                if (!hideShip && (orderStatus === 'shipped' || orderStatus === 'partially-shipped' || orderStatus === 'delivered')) {
                    defaultTab = 'shipments';
                }
                // Activate
                $tabs.find('.eao-tab-btn').removeClass('is-active');
                $tabs.find('.eao-item-tab-panel').removeClass('is-active');
                $tabs.find('.eao-tab-btn[data-tab="' + defaultTab + '"]').addClass('is-active');
                $tabs.find('.eao-item-tab-panel[data-tab-panel="' + defaultTab + '"]').addClass('is-active');
                // Hide Shipments button/panel if not allowed
                if (hideShip) {
                    $tabs.find('.eao-tab-btn[data-tab="shipments"]').hide();
                    $tabs.find('.eao-item-tab-panel[data-tab-panel="shipments"]').remove();
                }
            }

            // Expose for coordinator to call after rows insert
            window.EAO = window.EAO || {}; window.EAO.setDefaultTabForRow = setDefaultTabForRow;
        },
        
        /**
         * Bind product-related event handlers
         */
        bindProductEvents: function() {
            const $ = jQuery;
            const self = this;
            
            // Legacy per-row handlers are removed in Tabulator-only path
            
            // Inconsistency banner and bulk "Apply Admin Settings" have been retired.
            
            // Legacy handlers removed: discount percent

            // Legacy handlers removed: discounted price

            // Legacy handlers removed: quantity
        },
        
        /**
         * Calculate summary data from current items
         */
        
        /**
         * PHASE 3: Calculate item totals in WooCommerce-compatible format
         * This replaces percentage-based calculations with WC native field calculations
         * 
         * @param {Object} item Order item object
         * @returns {Object} WC-compatible totals
         */
        calculateItemTotalsInWCFormat: function(item) {
            const quantity = parseInt(item.quantity, 10) || 0;
            const basePrice = parseFloat(item.price_raw) || 0;
            const discountPercentRaw = item.discount_percent;
            const discountPercent = (typeof discountPercentRaw === 'number') ? discountPercentRaw : parseFloat(discountPercentRaw) || 0;
            const excludeGd = item.exclude_gd || false;
            const globalDiscount = window.globalOrderDiscountPercent || 0;
            
            // Determine effective discount percentage based on configuration
            let effectiveDiscountPercent = 0;
            if (excludeGd) {
                // Item-specific configuration; if markup, ignore percent and use fixed price
                if (item.is_markup && typeof item.discounted_price_fixed !== 'undefined') {
                    const line_subtotal_m = basePrice * quantity;
                    const line_total_m = Math.max(0, parseFloat(item.discounted_price_fixed) || 0) * quantity;
                    return {
                        _line_subtotal: line_subtotal_m,
                        _line_total: line_total_m,
                        _line_discount: 0,
                        discount_percent: 0,
                        exclude_gd: excludeGd,
                        effective_unit_price: line_total_m / quantity,
                        display_total: line_total_m
                    };
                } else {
                    effectiveDiscountPercent = discountPercent;
                }
            } else {
                // Global discount applies
                effectiveDiscountPercent = globalDiscount;
            }
            
            // Calculate in WooCommerce format during staging
            const line_subtotal = basePrice * quantity;
            const discount_amount = (line_subtotal * effectiveDiscountPercent) / 100;
            const line_total = line_subtotal - discount_amount;
            
            // Store in WC-compatible format in staging object
            return {
                _line_subtotal: line_subtotal,
                _line_total: line_total,
                _line_discount: discount_amount,
                
                // Also maintain configuration for form
                discount_percent: effectiveDiscountPercent,
                exclude_gd: excludeGd,
                
                // Display values
                effective_unit_price: line_total / quantity,
                display_total: line_total
            };
        },

        /**
         * PHASE 3: Calculate configured total helper for configuration-based calculations
         * This helps with inconsistency detection and admin configuration authority
         * 
         * @param {Object} item Order item object  
         * @returns {number} Total based on admin configuration
         */
        calculateConfiguredTotal: function(item) {
            const quantity = parseInt(item.quantity, 10) || 0;
            const basePrice = parseFloat(item.price_raw) || 0;
            const configuredDiscountPercent = parseFloat(item.discount_percent) || 0;
            const excludeGd = item.exclude_gd || false;
            const globalDiscount = window.globalOrderDiscountPercent || 0;
            
            // Determine discount based on admin configuration
            let discountToApply = 0;
            if (excludeGd) {
                discountToApply = configuredDiscountPercent;
            } else {
                discountToApply = globalDiscount;
            }
            
            // Allow negative discount (markup) for excluded items when discounted price > base
            discountToApply = Math.max(-100, Math.min(100, discountToApply));
            const discountedPrice = basePrice * (1 - (discountToApply / 100));
            return discountedPrice * quantity;
        },

        /**
         * Calculate current summary data from items array
         * PHASE 3 ENHANCEMENT: Updated to use WC format calculations
         */
        calculateCurrentSummaryData: function(items) {
            let itemsSubtotal = 0;
            let totalItemLevelDiscount = 0;
            let productsTotal = 0;
            
            if (items && items.length > 0) {
                const self = this;
                items.forEach(function(item) {
                    if (item.isPendingDeletion) return; // Skip items pending deletion
                    
                    // PHASE 3 ENHANCEMENT: Use WC-format data from staging calculations
                    const wcData = self.calculateItemTotalsInWCFormat(item);
                    
                    itemsSubtotal += wcData._line_subtotal;
                    if (!item.is_markup) {
                        totalItemLevelDiscount += wcData._line_discount;
                    }
                    productsTotal += wcData._line_total;
                });
            }
            
            return {
                items_subtotal_raw: itemsSubtotal,
                items_subtotal_formatted_html: eaoFormatPrice(itemsSubtotal),
                total_item_level_discount_raw: totalItemLevelDiscount,
                total_item_level_discount_formatted_html: eaoFormatPrice(totalItemLevelDiscount),
                products_total_raw: productsTotal,
                products_total_formatted_html: eaoFormatPrice(productsTotal),
                // Keep original shipping and tax data (with fallbacks)
                shipping_total_raw: this.currentOrderSummaryData ? this.currentOrderSummaryData.shipping_total_raw : 0,
                shipping_method_title: this.currentOrderSummaryData ? this.currentOrderSummaryData.shipping_method_title : 'Shipping',
                order_tax_raw: this.currentOrderSummaryData ? this.currentOrderSummaryData.order_tax_raw : 0,
                order_tax_formatted_html: this.currentOrderSummaryData ? this.currentOrderSummaryData.order_tax_formatted_html : eaoFormatPrice(0),
                order_total_raw: this.currentOrderSummaryData ? this.currentOrderSummaryData.order_total_raw : productsTotal
            };
        },
        
        /**
         * Create a summary row HTML string
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
         * Generate HTML for a single order item
         */
        generateOrderItemHtml: function(itemDetail, currentGlobalDiscountPercent) {
            const isPending = itemDetail.isPending || false;
            const isExisting = itemDetail.isExisting || false;
            const isPendingDeletion = itemDetail.isPendingDeletion || false;
            const priceDecimals = parseInt(eaoEditorParams.price_decimals || 2, 10); // Get price decimals

            let itemClass = 'eao-product-editor-item';
            if (isPending && !isExisting) itemClass += ' is-pending'; // Purely new item
            if (isPendingDeletion) itemClass += ' is-pending-deletion'; // Existing item marked for deletion

            const productId = itemDetail.product_id;
            const variationId = itemDetail.variation_id || 0;
            const itemId = itemDetail.item_id || ''; // Empty for new items
            
            // Use order snapshot unit price for stability (do not depend on current catalog price)
            const basePrice = parseFloat(itemDetail.price_raw) || 0;
            let quantity = parseInt(itemDetail.quantity, 10) || 0;
            const excludeGd = itemDetail.exclude_gd || false; // Renamed for clarity
            let itemSpecificDiscountPercent = (typeof itemDetail.discount_percent === 'number')
                ? itemDetail.discount_percent
                : (parseFloat(itemDetail.discount_percent) || 0);

            let displayDiscountPercentValue;
            let calculatedDiscountedPriceValue;
            let effectiveDiscountPercentForCalc;

            if (excludeGd) { // Item is excluded from global discount
                if (itemDetail.is_markup && typeof itemDetail.discounted_price_fixed !== 'undefined') {
                    displayDiscountPercentValue = '';
                    effectiveDiscountPercentForCalc = 0;
                } else {
                    displayDiscountPercentValue = itemSpecificDiscountPercent;
                    effectiveDiscountPercentForCalc = itemSpecificDiscountPercent;
                }
            } else { // Item is NOT excluded, global discount applies
                displayDiscountPercentValue = currentGlobalDiscountPercent;
                effectiveDiscountPercentForCalc = currentGlobalDiscountPercent;
            }

            if (excludeGd && itemDetail.is_markup && typeof itemDetail.discounted_price_fixed !== 'undefined') {
                calculatedDiscountedPriceValue = Math.max(0, parseFloat(itemDetail.discounted_price_fixed) || 0);
            } else {
                calculatedDiscountedPriceValue = basePrice * (1 - (effectiveDiscountPercentForCalc / 100));
                calculatedDiscountedPriceValue = Math.max(0, calculatedDiscountedPriceValue); // Ensure not negative
            }

            let lineTotalValue = calculatedDiscountedPriceValue * quantity;

            let html = `<div class="${itemClass}" data-product_id="${productId}" data-variation_id="${variationId}" data-item_id="${itemId}" data-base_price="${basePrice}" data-cost_source="${escapeAttribute(itemDetail.cost_source || '')}">`; // Added data-base_price and cost source

            // Thumbnail
            html += `<div class="eao-item-thumb">`;
            html += `<img src="${itemDetail.thumbnail_url || eaoEditorParams.placeholder_image_url}" alt="${escapeAttribute(itemDetail.name)}">`;
            html += `</div>`;

            // Product Content (Name, SKU, Meta)
            html += `<div class="eao-item-content">`;
            const productPublicUrl = (itemDetail.product_permalink) ? itemDetail.product_permalink : '#';
            html += `<div class="eao-item-title"><a href="${productPublicUrl}" target="_blank" rel="noopener noreferrer">${itemDetail.name}</a></div>`;
            
            // PHASE 2: Add inconsistency warning UI
            if (itemDetail.has_inconsistency) {
                html += `<div class="eao-inconsistency-warning">`;
                html += `<span class="eao-warning-icon">⚠️</span>`;
                html += `<span class="eao-warning-text">`;
                
                if (itemDetail.inconsistency_type === 'percentage_mismatch') {
                    const configDiscount = parseFloat(itemDetail.discount_percent || 0);
                    const wcDiscount = parseFloat(itemDetail.wc_calculated_discount || 0);
                    const globalDiscount = window.globalOrderDiscountPercent || 0;
                    
                    if (itemDetail.exclude_gd) {
                        html += `Admin set ${configDiscount.toFixed(1)}% item discount, but WC shows ${wcDiscount.toFixed(1)}%`;
                    } else {
                        html += `Admin set ${globalDiscount.toFixed(1)}% global discount, but WC shows ${wcDiscount.toFixed(1)}%`;
                    }
                } else if (itemDetail.inconsistency_type === 'global_exclude_mismatch') {
                    html += `Admin discount settings don't match WC database`;
                } else {
                    html += `Admin pricing doesn't match WC database`;
                }
                
                html += `</span>`;
                html += `<button type="button" class="eao-fix-inconsistency-btn" data-item_id="${itemId}" data-product_id="${productId}">Apply Admin Settings</button>`;
                html += `</div>`;
            }
            
            let metaHtml = '<div class="eao-item-meta">';
            if (itemDetail.sku) {
                metaHtml += `<span class="eao-item-sku">SKU: ${itemDetail.sku}</span>`;
            }
            if (itemDetail.formatted_meta_data) { // Display variation info etc.
                metaHtml += `<div class="eao-item-variation-meta">${itemDetail.formatted_meta_data}</div>`;
            }
            metaHtml += '</div>';
            html += metaHtml;
            html += `</div>`;

            // Build tabs container for pricing + shipments
            let tabsHtml = '<div class="eao-item-tabs">';
            // Tabs headers
            tabsHtml += '<div class="eao-item-tabs-nav">'
                + '<button type="button" class="button button-small eao-tab-btn" data-tab="pricing">' + (eaoEditorParams.i18n.pricing_tab || 'Pricing') + '</button>';
            // Determine shipments visibility
            let hasShipments = false;
            try {
                const sm = (window.eaoShipmentsMeta || {});
                const numShipments = parseInt(sm.num_shipments, 10) || 0;
                const orderStatus = (eaoEditorParams.order_status || '').toLowerCase();
                const hideForStatus = (orderStatus === 'pending' || orderStatus === 'processing');
                hasShipments = (!hideForStatus && numShipments >= 2);
            } catch(e) {}
            if (hasShipments) {
                tabsHtml += '<button type="button" class="button button-small eao-tab-btn" data-tab="shipments">' + (eaoEditorParams.i18n.shipments_tab || 'Shipments') + '</button>';
            }
            tabsHtml += '</div>';

            // Tabs content - Pricing
            tabsHtml += '<div class="eao-item-tab-panel" data-tab-panel="pricing">';
            // Points
            tabsHtml += `<div class="eao-item-points">`;
            const pointsEarned2 = (typeof itemDetail.points_earning === 'number') ? itemDetail.points_earning : 0;
            tabsHtml += `<div class="eao-points-display">${pointsEarned2}</div>`;
            tabsHtml += `<button type="button" class="button eao-points-details-btn" data-item_id="${itemId}" data_product_id="${productId}">${eaoEditorParams.i18n.points_details || 'Details'}</button>`;
            tabsHtml += `</div>`;
            // Cost
            tabsHtml += `<div class="eao-item-cost">`;
            const costValue2 = (itemDetail.cost_price !== null && itemDetail.cost_price !== undefined) ? parseFloat(itemDetail.cost_price) : null;
            const costDisplay2 = (costValue2 !== null) ? eaoFormatPrice(costValue2) : (eaoEditorParams.i18n.na || 'N/A');
            tabsHtml += `<span class="eao-cost-display">${costDisplay2}</span>`;
            const applyDisabled2 = (costValue2 === null || costValue2 <= 0) ? 'disabled' : '';
            tabsHtml += ` <button type="button" class="button eao-apply-cost-btn" data-product_id="${productId}" data-item_id="${itemId}" ${applyDisabled2}>${eaoEditorParams.i18n.apply_cost || 'Apply'}</button>`;
            tabsHtml += `</div>`;
            // Price
            tabsHtml += `<div class="eao-item-price">` + `<span class="eao-price-display">${eaoFormatPrice(itemDetail.price_raw)}</span>` + `</div>`;
            // Exclude GD
            tabsHtml += `<div class="eao-item-exclude-discount">`;
            const excludeNameAttr2 = isExisting ? `item_meta[${itemId}][_eao_exclude_global_discount]` : `new_item_meta[${productId}][_eao_exclude_global_discount]`;
            const excludeClass2 = 'eao-item-exclude-gd-cbx';
            tabsHtml += `<input type="checkbox" class="${excludeClass2}" name="${excludeNameAttr2}" data-product_id="${productId}" data-item_id="${itemId}" ${excludeGd ? 'checked' : ''}>`;
            tabsHtml += `</div>`;
            // Discount %
            tabsHtml += `<div class="eao-item-discount"><div class="eao-discount-controls">`;
            const discountNameAttr2 = isExisting ? `item_discount_percent[${itemId}]` : `new_item_discount_percent[${productId}]`;
            const discountInputDisabled2 = !excludeGd ? 'disabled="disabled"' : '';
            const discountPercentValueAttr2 = (displayDiscountPercentValue === '' ? '' : Number(displayDiscountPercentValue).toFixed(1));
            tabsHtml += `<input type="number" class="eao-item-discount-percent-input eao-discount-percent-input" name="${discountNameAttr2}" value="${discountPercentValueAttr2}" min="0" max="100" step="0.1" data-product_id="${productId}" data-item_id="${itemId}" ${discountInputDisabled2}>`;
            tabsHtml += `<span class="eao-percentage-symbol">%</span>`;
            tabsHtml += `</div></div>`;
            // Discounted Price
            tabsHtml += `<div class="eao-item-discounted-price"><div class="eao-input-align-wrapper eao-input-align-wrapper-right">`;
            const discountedPriceInputDisabled2 = !excludeGd ? 'disabled="disabled"' : '';
            const discountedPriceNameAttr2 = isExisting ? `item_discounted_price[${itemId}]` : `new_item_discounted_price[${productId}]`;
            tabsHtml += `<input type="number" class="eao-item-discounted-price-input" name="${discountedPriceNameAttr2}" value="${calculatedDiscountedPriceValue.toFixed(priceDecimals)}" min="0" step="0.01" data-product_id="${productId}" data-item_id="${itemId}" ${discountedPriceInputDisabled2}>`;
            tabsHtml += `</div>`;
            const singleItemMonetaryDiscount2 = basePrice - calculatedDiscountedPriceValue;
            if (singleItemMonetaryDiscount2 > 0.001) {
                tabsHtml += `<div class="eao-item-monetary-discount-display">-${eaoEditorParams.currency_symbol}${singleItemMonetaryDiscount2.toFixed(priceDecimals)}</div>`;
            }
            tabsHtml += `</div>`;
            tabsHtml += '</div>'; // end pricing panel

            // Tabs content - Shipments
            if (hasShipments) {
                tabsHtml += '<div class="eao-item-tab-panel" data-tab-panel="shipments">';
                try {
                    const sm = (window.eaoShipmentsMeta || {});
                    const numShipments = parseInt(sm.num_shipments, 10) || 0;
                    if (numShipments >= 2) {
                        const pid2 = parseInt(productId, 10) || 0;
                        for (let i = 1; i <= numShipments; i++) {
                            let q2 = 0;
                            if (sm.product_qty_map && sm.product_qty_map[pid2] && typeof sm.product_qty_map[pid2][i] !== 'undefined') {
                                q2 = sm.product_qty_map[pid2][i];
                            }
                            tabsHtml += `<div class="eao-item-shipment eao-item-shipment-${i}"><span class="eao-shipment-qty">${q2}</span></div>`;
                        }
                    }
                } catch(err) {}
                tabsHtml += '</div>';
            }
            tabsHtml += '</div>'; // end tabs container

            // Insert tabs immediately before Total column
            // Activate default classes so layout isn't empty before JS sets state
            html += tabsHtml.replace(/eao-item-tab-panel"/g, 'eao-item-tab-panel"');

            // Total (after item-specific AND global discount if applicable)
            html += `<div class="eao-item-total">`;
            html += `<span class="eao-line-total-display">${eaoFormatPrice(lineTotalValue)}</span>`;
            html += `</div>`;

            // Actions (Remove button)
            html += `<div class="eao-item-actions">`;
            if (isPendingDeletion) {
                html += `<button type="button" class="button button-link-undo eao-remove-item-button eao-undo-delete-button" data-product_id="${productId}" data-item_id="${itemId}" title="${eaoEditorParams.i18n.undo_remove_title || 'Undo remove'}"><span class="dashicons dashicons-undo"></span></button>`;
            } else {
                html += `<button type="button" class="button button-link-delete eao-remove-item-button" data-product_id="${productId}" data-item_id="${itemId}" title="${eaoEditorParams.i18n.remove_item_title || 'Remove item'}"><span class="dashicons dashicons-trash"></span></button>`;
            }
            html += `</div>`;

            html += `</div>`;
            return html;
        },
        
        /**
         * Fetch and display order products from server
         */
        fetchAndDisplayOrderProducts: function(callback) {
            const $ = jQuery;
            const orderId = $('#eao-order-form').find('input[name=\"eao_order_id\"]').val();
            if (!orderId) {
                console.error('EAO: Order ID not found for fetching products.');
                // Clear previous content and show error
                if (this.$currentOrderItemsList) {
                    this.$currentOrderItemsList.html('<p class=\"eao-error-message\">Could not load products: Order ID missing.</p>');
                }
                // Use centralized summary update function
                if (window.EAO && window.EAO.MainCoordinator) {
                    window.EAO.MainCoordinator.updateSummaryDisplay([], {}, {
                        error_message: 'Could not load summary: Order ID missing.'
                    });
                }
                // Call callback with error
                if (typeof callback === 'function') {
                    callback(new Error('Order ID missing'));
                }
                return;
            }

            // Re-initialize DOM elements if needed
            if (!this.$currentOrderItemsList || !this.$currentOrderItemsList.length) {
                this.$currentOrderItemsList = $('#eao-current-order-items-list');
            }
            if (!this.$currentOrderItemsSummary || !this.$currentOrderItemsSummary.length) {
                this.$currentOrderItemsSummary = $('#eao-current-order-items-summary');
            }

            // Check if DOM elements exist
            if (!this.$currentOrderItemsList || !this.$currentOrderItemsList.length) {
                console.error('EAO: Could not find order items list element (#eao-current-order-items-list)');
                if (typeof callback === 'function') {
                    callback(new Error('DOM elements missing'));
                }
                return;
            }
            if (!this.$currentOrderItemsSummary || !this.$currentOrderItemsSummary.length) {
                console.error('EAO: Could not find order items summary element (#eao-current-order-items-summary)');
                if (typeof callback === 'function') {
                    callback(new Error('DOM elements missing'));
                }
                return;
            }

            // Show initial loading message
            const loadingHtml = `
                <div id=\"eao-items-loading-placeholder\" style=\"padding: 20px; text-align: center;\">
                    <p class=\"eao-loading-message\" style=\"font-size: 1.1em; color: #666;\">Loading order items...</p>
                    <div class=\"spinner is-active\" style=\"margin: 10px auto; float: none;\"></div>
                </div>
            `;
            this.$currentOrderItemsList.html(loadingHtml);
            // Use centralized summary update function for loading state
            if (window.EAO && window.EAO.MainCoordinator) {
                window.EAO.MainCoordinator.updateSummaryDisplay([], {}, {loading: true});
            }

            const self = this;
            $.ajax({
                url: eaoEditorParams.ajax_url,
                type: 'POST',
                data: {
                    action: 'eao_get_order_products_data',
                    nonce: eaoEditorParams.eao_product_operations_nonce,
                    order_id: orderId
                },
                dataType: 'json',
                success: function(response) {
                    
                    // CRITICAL: Remove loading spinner immediately
                    $('#eao-items-loading-placeholder').remove();
                    $('.spinner.is-active', self.$currentOrderItemsList).removeClass('is-active');
                    
                    if (response.success && response.data) {
                        self.currentOrderItems = response.data.items.map(item => ({
                            ...item,
                            isExisting: true, // Mark these as existing items from the order
                            isPending: false, // Not pending from search
                            isPendingDeletion: self.itemsPendingDeletion.includes(String(item.item_id)) // Preserve pending deletion status
                        }));
                        self.stagedOrderItems = []; // Clear purely staged items as we have refreshed from server
                        
                        // Sync with global variables
                        window.currentOrderItems = self.currentOrderItems;
                        window.stagedOrderItems = self.stagedOrderItems;
                        
                        // Update global discount from summary if available (after save)
                        if (response.data.summary && typeof response.data.summary.global_order_discount_percent !== 'undefined') {
                            window.globalOrderDiscountPercent = parseFloat(response.data.summary.global_order_discount_percent) || 0;
                            if (self.$globalOrderDiscountInput && self.$globalOrderDiscountInput.length) {
                                self.$globalOrderDiscountInput.val(window.globalOrderDiscountPercent);
                            }
                        }
                        
                        // Use two-phase rendering system for proper YITH Points preservation
                        // Store shipments meta if provided (for dynamic columns)
                        if (response.data && response.data.shipments) {
                            window.eaoShipmentsMeta = response.data.shipments;
                            try { console.log('[EAO Shipments] meta loaded from server:', window.eaoShipmentsMeta); } catch(_){ }
                        } else {
                            window.eaoShipmentsMeta = { num_shipments: 0, columns: [], product_qty_map: {} };
                            try { console.log('[EAO Shipments] meta missing; defaulting to empty'); } catch(_){ }
                        }

                        if (window.EAO && window.EAO.MainCoordinator) {
                            window.EAO.MainCoordinator.renderAllOrderItemsAndSummary(self.currentOrderItems, response.data.summary);
                            
                            // Call callback after rendering is complete
                            if (typeof callback === 'function') {
                                setTimeout(() => callback(null), 100); // Small delay to ensure rendering is complete
                            }
                        } else if (typeof window.renderAllOrderItemsAndSummary === 'function') {
                            window.renderAllOrderItemsAndSummary(self.currentOrderItems, response.data.summary);
                            
                            // Call callback after rendering is complete
                            if (typeof callback === 'function') {
                                setTimeout(() => callback(null), 100); // Small delay to ensure rendering is complete
                            }
                        } else {
                            console.error('[EAO Products] renderAllOrderItemsAndSummary function not available!');
                            // Retry after a short delay
                            setTimeout(function() {
                                // Use two-phase rendering system for retry
                                if (window.EAO && window.EAO.MainCoordinator) {
                                    window.EAO.MainCoordinator.renderAllOrderItemsAndSummary(self.currentOrderItems, response.data.summary);
                                    if (typeof callback === 'function') {
                                        callback(null);
                                    }
                                } else if (typeof window.renderAllOrderItemsAndSummary === 'function') {
                                    window.renderAllOrderItemsAndSummary(self.currentOrderItems, response.data.summary);
                                    if (typeof callback === 'function') {
                                        callback(null);
                                    }
                                } else {
                                    console.error('[EAO Products] renderAllOrderItemsAndSummary still not available after retry');
                                    // Show the items in a basic way as fallback
                                    self.$currentOrderItemsList.html('<div class="eao-error">Order items loaded but display function not available. Please refresh the page.</div>');
                                    if (typeof callback === 'function') {
                                        callback(new Error('Display function not available'));
                                    }
                                }
                            }, 500);
                        }
                        
                        // Store initial data after products are fully loaded
                        if (window.EAO && window.EAO.ChangeDetection) {
                            // Use refreshInitialData to store data and check for changes properly
                            window.EAO.ChangeDetection.refreshInitialData();
                        }
                        
                        // Call change detection after fresh data is loaded to properly update button state
                        if (window.isAfterAjaxSave && window.EAO && window.EAO.ChangeDetection) {
                            window.EAO.ChangeDetection.markAfterAjaxSave();
                            window.EAO.ChangeDetection.triggerCheck();
                        }
                        
                    } else {
                        console.error('[EAO Products] AJAX response indicates failure:', response);
                        const errorMsg = response.data && response.data.message ? response.data.message : 'Error loading order items.';
                        self.$currentOrderItemsList.html('<p class="eao-error-message">'+ errorMsg +'</p>');
                        // Use centralized summary update function for errors
                        if (window.EAO && window.EAO.MainCoordinator) {
                            window.EAO.MainCoordinator.updateSummaryDisplay([], {}, {
                                error_message: errorMsg
                            });
                        }
                        
                        // Call callback with error
                        if (typeof callback === 'function') {
                            callback(new Error(errorMsg));
                        }
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    // CRITICAL: Remove loading spinner immediately
                    $('#eao-items-loading-placeholder').remove();
                    $('.spinner.is-active', self.$currentOrderItemsList).removeClass('is-active');
                    
                    console.error('[EAO Products] AJAX error fetching order products data:', textStatus, errorThrown);
                    console.error('[EAO Products] AJAX error details:', {
                        status: jqXHR.status,
                        statusText: jqXHR.statusText,
                        responseText: jqXHR.responseText
                    });
                    self.$currentOrderItemsList.html('<p class="eao-error-message">AJAX Error: Could not load order items.</p>');
                    // Use centralized summary update function for AJAX errors
                    if (window.EAO && window.EAO.MainCoordinator) {
                        window.EAO.MainCoordinator.updateSummaryDisplay([], {}, {
                            error_message: 'AJAX Error: Could not load summary.'
                        });
                    }
                    
                    // Call callback with error
                    if (typeof callback === 'function') {
                        callback(new Error(`AJAX Error: ${textStatus}`));
                    }
                },
                complete: function() { 
                    console.log('[EAO Products] AJAX request completed, initializing product search');
                    self.initializeProductSearch();
                    
                    // Note: callback is called in success/error handlers, not here,
                    // because we need to wait for the rendering to complete
                }
            });
        },
        
        /**
         * Initialize product search - placeholder (actual function still in main file)
         */
        initializeProductSearch: function() {
            // Call the global function that's still in main file
            if (typeof window.initializeProductSearch === 'function') {
                window.initializeProductSearch();
            } else {
                console.warn('[EAO Products] initializeProductSearch not available globally');
            }
        },
        
        /**
         * Placeholder methods - will be implemented in subsequent phases
         */
        renderAllItems: function() {
            console.log('[EAO Products] renderAllItems - to be implemented');
        },
        
        addProduct: function() {
            console.log('[EAO Products] addProduct - to be implemented');
        },
        
        removeProduct: function() {
            console.log('[EAO Products] removeProduct - to be implemented');
        },
        
        calculateSummary: function() {
            console.log('[EAO Products] calculateSummary - to be implemented');
        }
    };

    // Backward compatibility - expose functions globally as they were before
    window.calculateCurrentSummaryData = function(items) {
        if (window.EAO && window.EAO.Products) {
            return window.EAO.Products.calculateCurrentSummaryData(items);
        }
        console.error('[EAO Products] Module not available for calculateCurrentSummaryData');
        return {};
    };

    window.createSummaryRow = function(label, value, isMonetary, additionalClass = '') {
        if (window.EAO && window.EAO.Products) {
            return window.EAO.Products.createSummaryRow(label, value, isMonetary, additionalClass);
        }
        console.error('[EAO Products] Module not available for createSummaryRow');
        return '';
    };

    window.generateOrderItemHtml = function(itemDetail, currentGlobalDiscountPercent) {
        if (window.EAO && window.EAO.Products) {
            return window.EAO.Products.generateOrderItemHtml(itemDetail, currentGlobalDiscountPercent);
        }
        console.error('[EAO Products] Module not available for generateOrderItemHtml');
        return '';
    };

    // Global wrapper function for backward compatibility
    window.fetchAndDisplayOrderProducts = function(callback) {
        if (window.EAO && window.EAO.Products) {
            return window.EAO.Products.fetchAndDisplayOrderProducts(callback);
        }
        console.error('[EAO Products] Module not available for fetchAndDisplayOrderProducts');
    };
})(jQuery); 