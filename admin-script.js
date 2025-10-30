/**
 * Enhanced Admin Order Plugin - Legacy Admin Script Handlers
 * 
 * @package EnhancedAdminOrder
 * @since 2.0.0
 * @version 2.8.6 - WC staging fields update in real-time during quantity changes
 * @author Amnon Manneberg
 */

jQuery(document).ready(function($) {

    console.log('[EAO Admin Script] Legacy handlers loading...');

    // Handler for product removal and undo - handles both states
    $(document).on('click', '.eao-remove-item-button', function() {
        const $button = $(this);
        const $itemRow = $button.closest('.eao-product-editor-item');
        const productId = String($itemRow.data('product_id'));
        const itemId = String($itemRow.data('item_id'));
        const isUndo = $button.hasClass('eao-undo-delete-button');

        // Ensure global arrays exist
        if (!window.currentOrderItems) window.currentOrderItems = [];
        if (!window.itemsPendingDeletion) window.itemsPendingDeletion = [];

        // Find the item in currentOrderItems
        const itemInCurrent = window.currentOrderItems.find(item => 
            (itemId && String(item.item_id) === itemId) || 
            (!itemId && String(item.product_id) === productId && item.isPending && !item.isExisting)
        );

        if (itemInCurrent) {
            if (isUndo) {
                // Undo deletion - restore the item
                if (itemInCurrent.isExisting) {
                    const delIndex = window.itemsPendingDeletion.indexOf(itemId);
                    if (delIndex > -1) {
                        window.itemsPendingDeletion.splice(delIndex, 1);
                    }
                    itemInCurrent.isPendingDeletion = false;
                }
            } else {
                // Regular removal
                if (itemInCurrent.isExisting) {
                    // Existing item: mark for deletion
                    if (!window.itemsPendingDeletion.includes(itemId)) {
                        window.itemsPendingDeletion.push(itemId);
                    }
                    itemInCurrent.isPendingDeletion = true;
                } else {
                    // New/staged item: remove completely from array
                    const index = window.currentOrderItems.indexOf(itemInCurrent);
                    if (index > -1) {
                        window.currentOrderItems.splice(index, 1);
                    }
                    
                    // Also remove from stagedOrderItems if present
                    if (window.stagedOrderItems) {
                        const stagedIndex = window.stagedOrderItems.findIndex(item => String(item.product_id) === productId);
                        if (stagedIndex > -1) {
                            window.stagedOrderItems.splice(stagedIndex, 1);
                        }
                    }
                }
            }
        }
        
        // Use two-phase rendering system for proper YITH Points preservation
        if (window.EAO && window.EAO.MainCoordinator) {
            window.EAO.MainCoordinator.renderAllOrderItemsAndSummary(window.currentOrderItems, window.currentOrderSummaryData, { has_changes: true });
        } else {
            // Fallback to direct call
            const summaryData = (typeof window.calculateCurrentSummaryData === 'function') ? 
                window.calculateCurrentSummaryData(window.currentOrderItems) : 
                (window.currentOrderSummaryData || {});
            window.renderAllOrderItemsAndSummary(window.currentOrderItems, summaryData, { has_changes: true });
        }
    });

    // Handler for changing quantity of ANY item (restored from backup v150)
    $(document).on('change input', '.eao-quantity-input', function() {
        const $input = $(this);
        const productId = String($input.data('product_id'));
        const itemId = String($input.data('item_id'));
        let newQuantity = parseInt($input.val(), 10);

        // Ensure global arrays exist
        if (!window.currentOrderItems) window.currentOrderItems = [];
        if (!window.itemsPendingDeletion) window.itemsPendingDeletion = [];

        const itemInCurrent = window.currentOrderItems.find(item => 
            (itemId && String(item.item_id) === itemId) || 
            (!itemId && String(item.product_id) === productId && !item.item_id) // Match new item by product_id if no item_id
        );

        if (itemInCurrent) {
            if (isNaN(newQuantity) || newQuantity < (itemInCurrent.isExisting ? 0 : 1)) { // Allow 0 for existing, min 1 for new
                newQuantity = itemInCurrent.isExisting ? 0 : 1;
                $input.val(newQuantity);
            }
            itemInCurrent.quantity = newQuantity;
            itemInCurrent.isPending = true; // Mark as changed

            // Update WC staging fields for quantity changes
            if (window.EAO && window.EAO.Utils && window.EAO.Utils.updateItemWCStagingFields) {
                const globalDiscount = parseFloat($('#eao_order_products_discount_percent').val() || 0);
                window.EAO.Utils.updateItemWCStagingFields(itemInCurrent, globalDiscount);
            }

            if (itemInCurrent.isExisting && newQuantity === 0) {
                if (!window.itemsPendingDeletion.includes(itemId)) window.itemsPendingDeletion.push(itemId);
                itemInCurrent.isPendingDeletion = true;
            } else if (itemInCurrent.isExisting && newQuantity > 0) {
                const delIndex = window.itemsPendingDeletion.indexOf(itemId);
                if (delIndex > -1) window.itemsPendingDeletion.splice(delIndex, 1);
                itemInCurrent.isPendingDeletion = false;
            }
            
            // Use two-phase rendering system for proper YITH Points preservation
            if (window.EAO && window.EAO.MainCoordinator) {
                window.EAO.MainCoordinator.renderAllOrderItemsAndSummary(window.currentOrderItems, window.currentOrderSummaryData, { has_changes: true });
            } else {
                // Fallback to direct call
                const calculatedSummary = window.calculateCurrentSummaryData(window.currentOrderItems);
                window.renderAllOrderItemsAndSummary(window.currentOrderItems, calculatedSummary, { has_changes: true });
            }
        }
    });

    
    // Product search initialization function (restored from backup)
    if (typeof window.initializeProductSearch === 'undefined') {
        window.initializeProductSearch = function() {
            console.log('[EAO Admin Script] Initializing product search functionality');
            
            let searchTimeoutProduct;
            let productSearchRequest;
            const $searchInputProduct = $('#eao_product_search_term');
            const $searchResultsContainerProduct = $('#eao_product_search_results');
            
            // Unbind previous namespaced handlers to prevent multiple executions
            $searchInputProduct.off('keyup.eaoProductSearch focus.eaoProductSearch');
            $searchResultsContainerProduct.off('click.eaoProductSearch', 'li.eao-product-search-item');
            
            $searchInputProduct.on('keyup.eaoProductSearch focus.eaoProductSearch', function(event) {
                clearTimeout(searchTimeoutProduct);
                const searchTerm = $(this).val();
                
                if (searchTerm.length < 3) {
                    if (event.type === 'keyup') $searchResultsContainerProduct.empty().hide(); // Clear on keyup if less than 3 chars
                    // On focus, don't clear immediately if less than 3, wait for typing
                    return;
                }
                
                searchTimeoutProduct = setTimeout(function() {
                    if (productSearchRequest) {
                        productSearchRequest.abort();
                    }
                    $searchResultsContainerProduct.html('<ul><li class="eao-searching-placeholder"><span class="spinner is-active"></span> Searching...</li></ul>').show();
                    
                    productSearchRequest = $.ajax({
                        url: eaoEditorParams.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'eao_search_products_for_admin_order',
                            nonce: eaoEditorParams.search_products_nonce,
                            search_term: searchTerm,
                            order_id: $('input[name="eao_order_id"]').val() // Updated for modular compatibility
                        },
                        dataType: 'json',
                        success: function(response) {
                            $searchResultsContainerProduct.empty();
                            if (response.success && response.data && response.data.length > 0) {
                                const $ul = $('<ul>');
                                response.data.forEach(function(product) {
                                    let productHtml = `<li class="eao-product-search-item" data-product_id="${product.id}" data-product_name="${escapeAttribute(product.name)}" data-product_price_raw="${product.price_raw}" data-product_sku="${escapeAttribute(product.sku)}" data-thumbnail_url="${product.thumbnail_url}" data-manages_stock="${product.manages_stock}" data-stock_quantity="${product.stock_quantity}">`
                                    productHtml += `<div class="eao-search-item-thumb"><img src="${product.thumbnail_url || eaoEditorParams.placeholder_image_url}" alt="${escapeAttribute(product.name)}"></div>`
                                    productHtml += `<div class="eao-search-item-details">`
                                    productHtml += `<div class="eao-search-item-name">${product.name}</div>`
                                    if (product.sku) {
                                        productHtml += `<div class="eao-search-item-sku">SKU: ${product.sku}</div>`
                                    }
                                    if (product.stock_html) {
                                        productHtml += `<div class="eao-search-item-stock">${product.stock_html}</div>`
                                    }
                                    productHtml += `</div>`
                                    // Add quantity input before the price
                                    productHtml += `<div class="eao-search-item-actions" style="display: flex; align-items: center; margin-left: auto;">`
                                    productHtml += `<input type="number" class="eao-search-item-qty" value="1" min="1" style="width: 50px; text-align: center; margin-right: 10px; height: 28px; padding: 2px 4px;">`
                                    productHtml += `<div class="eao-search-item-price">${product.price_html}</div>`
                                    productHtml += `</div>`
                                    productHtml += `</li>`
                                    $ul.append(productHtml);
                                });
                                $searchResultsContainerProduct.append($ul);
                                // Initialize background for newly added quantity inputs in search results
                                $ul.find('li.eao-product-search-item').each(function() {
                                    const $listItem = $(this);
                                    const $qtyInput = $listItem.find('.eao-search-item-qty');
                                    if ($qtyInput.length) {
                                        updateQuantityInputBackground($qtyInput, $listItem.data('manages_stock'), $listItem.data('stock_quantity'));
                                    }
                                });
                            } else if (response.success && response.data && response.data.length === 0) {
                                $searchResultsContainerProduct.html('<ul><li class="eao-no-results-placeholder">No products found.</li></ul>').show();
                            } else {
                                $searchResultsContainerProduct.html('<ul><li class="eao-error-placeholder">Error: ' + (response.data.message || 'Could not retrieve products.') + '</li></ul>').show();
                            }
                        },
                        error: function(jqXHR, textStatus, errorThrown) {
                            if (textStatus !== 'abort') {
                                $searchResultsContainerProduct.html('<ul><li class="eao-error-placeholder">AJAX Error: Could not search products.</li></ul>').show();
                            }
                        },
                        complete: function() {
                            productSearchRequest = null;
                        }
                    });
                }, 500);
            });
            
            // Hide search results when clicking outside
            $(document).on('click', function(event) {
                if (!$(event.target).closest('#eao_product_search_term, #eao_product_search_results').length) {
                    $searchResultsContainerProduct.empty().hide();
                }
            });
            
            // Delegated input event for quantity fields in search results
            $searchResultsContainerProduct.off('input.eaoSearchQtyCheck', '.eao-search-item-qty').on('input.eaoSearchQtyCheck', '.eao-search-item-qty', function() {
                const $qtyInput = $(this);
                const $listItem = $qtyInput.closest('li.eao-product-search-item');
                // Pass the list item (which has the stock data) to the helper
                updateQuantityInputBackground($qtyInput, $listItem.data('manages_stock'), $listItem.data('stock_quantity'));
            });
            
            // Click handler for product search results
            $searchResultsContainerProduct.on('click.eaoProductSearch', 'li.eao-product-search-item', function(e) {
                // If the click was on the quantity input itself, don't treat it as adding the item.
                if ($(e.target).is('.eao-search-item-qty')) {
                    return;
                }
                
                const $clickedItem = $(this);
                const productId = String($clickedItem.data('product_id')); // Ensure string comparison
                const productName = $clickedItem.data('product_name');
                const productPriceRaw = $clickedItem.data('product_price_raw');
                const productSku = $clickedItem.data('product_sku');
                const productThumbnailUrl = $clickedItem.data('thumbnail_url');
                const quantityToAdd = parseInt($clickedItem.find('.eao-search-item-qty').val(), 10) || 1;
                const stockHtmlFromSearch = $clickedItem.find('.eao-search-item-stock').html() || '';
                // Stock data for staged item (ensures Products column stock label renders)
                const managesStockAttr = $clickedItem.data('manages_stock');
                const stockQtyAttr = $clickedItem.data('stock_quantity');
                const managesStock = (String(managesStockAttr) === 'true' || managesStockAttr === true || managesStockAttr === 1 || String(managesStockAttr) === '1' || managesStockAttr === 'yes');
                const stockQuantity = (stockQtyAttr !== null && typeof stockQtyAttr !== 'undefined' && stockQtyAttr !== 'null') ? parseInt(stockQtyAttr, 10) : null;
                
                // --- BEGIN MERGE LOGIC (Adapting for currentOrderItems) ---
                // 1. Check if item already exists in currentOrderItems (either as existing or already staged and not pending deletion)
                const existingItemInCurrentList = window.currentOrderItems ? window.currentOrderItems.find(item => 
                    String(item.product_id) === productId && 
                    !(window.itemsPendingDeletion && window.itemsPendingDeletion.includes(String(item.item_id))) // Don't merge with an item marked for deletion
                ) : null;
                
                if (existingItemInCurrentList) {
                    existingItemInCurrentList.quantity += quantityToAdd;
                    if (existingItemInCurrentList.isExisting && !existingItemInCurrentList.isPending) {
                        existingItemInCurrentList.isPending = true; // Mark existing item as changed
                    }
                } else {
                    // 2. Add as new staged item to currentOrderItems
                    const newItemForCurrentList = {
                        product_id: parseInt(productId, 10), 
                        name: productName,
                        price: productPriceRaw, // Keep for backward compatibility
                        price_raw: productPriceRaw, // This is what calculations expect
                        quantity: quantityToAdd,
                        sku: productSku,
                        thumbnail_url: productThumbnailUrl,
                        stock_html: stockHtmlFromSearch,
                        manages_stock: managesStock,
                        stock_quantity: stockQuantity,
                        isExisting: false,
                        isPending: true, // Not saved yet
                        isPendingDeletion: false,
                        exclude_gd: false,
                        discount_percent: 0,
                        discounted_price_fixed: productPriceRaw
                    };
                    
                    if (!window.currentOrderItems) window.currentOrderItems = [];
                    window.currentOrderItems.push(newItemForCurrentList);
                }
                
                // 3. Clear search UI, unlock focus, and refresh display
                $searchInputProduct.val('');
                $searchResultsContainerProduct.empty().hide();
                // Release focus lock so rendering is not blocked by our search-input guard
                try { window.eaoInputFocusLock = false; } catch(_) {}
                // Proactively blur the search input so browser keeps UX consistent
                $searchInputProduct.blur();

                // Use two-phase rendering system for proper YITH Points preservation
                setTimeout(function(){
                    if (window.EAO && window.EAO.MainCoordinator) {
                        window.EAO.MainCoordinator.renderAllOrderItemsAndSummary(window.currentOrderItems, window.currentOrderSummaryData, { has_changes: true });
                    } else {
                        // Fallback to direct call
                        const summaryData = (typeof window.calculateCurrentSummaryData === 'function') ? 
                            window.calculateCurrentSummaryData(window.currentOrderItems) : 
                            (window.currentOrderSummaryData || {});
                        window.renderAllOrderItemsAndSummary(window.currentOrderItems, summaryData, { has_changes: true });
                    }
                }, 0);
            });
        }; // End of initializeProductSearch definition
    }

    // Function for updating quantity input backgrounds based on stock
    if (typeof window.updateQuantityInputBackground === 'undefined') {
        window.updateQuantityInputBackground = updateQuantityInputBackground;
    }

    function updateQuantityInputBackground($inputElement, managesStockAttr, stockQuantityAttr) {
        const currentVal = parseInt($inputElement.val(), 10);
        let managesStock, stockQuantity;

        if (typeof managesStockAttr !== 'undefined') {
            managesStock = String(managesStockAttr) === 'true' || managesStockAttr === true || managesStockAttr === 1 || String(managesStockAttr) === '1';
            stockQuantity = stockQuantityAttr !== null && typeof stockQuantityAttr !== 'undefined' && stockQuantityAttr !== 'null' ? parseInt(stockQuantityAttr, 10) : null;
        } else {
            managesStock = String($inputElement.data('manages_stock')) === 'true' || $inputElement.data('manages_stock') === true;
            stockQuantity = $inputElement.data('stock_quantity') !== null && typeof $inputElement.data('stock_quantity') !== 'undefined' && $inputElement.data('stock_quantity') !== 'null' ? parseInt($inputElement.data('stock_quantity'), 10) : null;
        }

        // Remove all stock-related classes first
        $inputElement.removeClass('eao-qty-exact-stock eao-qty-exceeds-stock eao-qty-sufficient-stock');

        if (managesStock && stockQuantity !== null && !isNaN(stockQuantity)) {
            if (currentVal === stockQuantity && stockQuantity > 0) { 
                // Exact match and positive stock - YELLOW background
                $inputElement.addClass('eao-qty-exact-stock');
            } else if (currentVal > stockQuantity) { 
                // Exceeds stock - RED background
                $inputElement.addClass('eao-qty-exceeds-stock');
            } else if (stockQuantity === 0 && currentVal > 0) { 
                // Trying to order when stock is 0 - RED background
                $inputElement.addClass('eao-qty-exceeds-stock');
            } else if (stockQuantity === 0 && currentVal === 0) { 
                // Qty is 0 and stock is 0 - YELLOW background
                $inputElement.addClass('eao-qty-exact-stock');
            } else if (currentVal < stockQuantity && stockQuantity > 0) {
                // Sufficient stock available - GREEN background
                $inputElement.addClass('eao-qty-sufficient-stock');
            }
            // No class added for other cases - background will be transparent
        }
        // Not managing stock or invalid stockQuantity - no specific class, transparent background
    }
    
    // Helper function for escaping HTML attributes
    function escapeAttribute(str) {
        if (!str) return '';
        return String(str).replace(/[&<>"']/g, match => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[match]));
    }
    
    // Legacy function for adding products (kept for backwards compatibility)
    function addProductToOrderLegacy(productData) {
        if (!window.currentOrderItems) window.currentOrderItems = [];
        
        const newItem = {
            product_id: productData.id,
            name: productData.name,
            price: productData.price,
            price_raw: productData.price, // Add price_raw for calculation consistency
            quantity: productData.quantity || 1,
            sku: productData.sku || '',
            thumbnail_url: productData.thumbnail_url || '',
            isExisting: false,
            isPending: true,
            isPendingDeletion: false,
            exclude_gd: false,
            discount_percent: 0,
            discounted_price_fixed: productData.price
        };
        
        window.currentOrderItems.push(newItem);
        
        // Use two-phase rendering system for proper YITH Points preservation
        if (window.EAO && window.EAO.MainCoordinator) {
            window.EAO.MainCoordinator.renderAllOrderItemsAndSummary(window.currentOrderItems, window.currentOrderSummaryData);
        } else {
            // Fallback to direct call
            const summaryData = (typeof window.calculateCurrentSummaryData === 'function') ? 
                window.calculateCurrentSummaryData(window.currentOrderItems) : 
                (window.currentOrderSummaryData || {});
            window.renderAllOrderItemsAndSummary(window.currentOrderItems, summaryData);
        }
    }
    
    // Expose legacy function globally
    window.addProductToOrderLegacy = addProductToOrderLegacy;
}); 