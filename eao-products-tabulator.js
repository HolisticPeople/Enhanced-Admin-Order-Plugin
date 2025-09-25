/**
 * Enhanced Admin Order - Products Table (Tabulator wrapper)
 * @version 1.0.3 - Debounced redraw to reduce reflow hotspots; minor stability tweaks
 * Author: Amnon Manneberg
 */
(function(window, $) {
    if (!window.EAO) { window.EAO = {}; }

	// Suppress noisy shipment logs by default; set window.eaoDebugShipments=true to re-enable
	try {
		if (!window._EAO_SHIP_LOG_WRAPPED) {
			window._EAO_SHIP_LOG_WRAPPED = true;
			var __eao_orig_log = (typeof console !== 'undefined' && console.log) ? console.log.bind(console) : function(){};
			console.log = function(){
				try {
					if (arguments && arguments[0] && typeof arguments[0] === 'string' && arguments[0].indexOf('[EAO Shipments]') === 0) {
						if (window.eaoDebugShipments) { return __eao_orig_log.apply(console, arguments); }
						return; // suppress
					}
				} catch(_) {}
				return __eao_orig_log.apply(console, arguments);
			};
		}
	} catch(_) {}

    const ProductsTable = {
        _table: null,
        _container: null,
        _showShipments: false,
        _shipmentsEnabled: false,
        _lastDataCount: 0,
        _lastColumnsKey: '',
        _isPainting: false,
        _lastHeaderSkuCount: null,
        _lastHeaderQtyCount: null,
        _pendingItems: null,
        _built: false,
        _postBuildGraceUntil: 0,
        _summaryRefreshTimer: null,
        _batchedUpdates: null,
        _batchTimer: null,
        _pendingRowUpdates: null,
        _pendingRetryTimer: null,
        _redrawDebounceTimer: null,
        requestRedraw: function(delayMs){
            try {
                if (this._redrawDebounceTimer) { return; }
                const d = (typeof delayMs === 'number' && delayMs >= 0) ? delayMs : 80;
                this._redrawDebounceTimer = setTimeout(() => {
                    this._redrawDebounceTimer = null;
                    this._safeRedrawAndLog();
                }, d);
            } catch(_) {}
        },
        _rowExists: function(id){
            try {
                const exists = !!(this._table && this._table.getRow && this._table.getRow(String(id)));
                return exists;
            } catch(e) { return false; }
        },
        _safeUpdateDataSingle: function(updateObj){
            try {
                if (!updateObj || !updateObj.id) { return; }
                // Normalize id to string to match Tabulator's strict id matching
                updateObj.id = String(updateObj.id);
                // Global stabilization guard (after update/save or initial rehydrate)
                if (window.eaoDebugPostSaveUntil && Date.now() < window.eaoDebugPostSaveUntil) {
                    if (!this._pendingRowUpdates) { this._pendingRowUpdates = []; }
                    this._pendingRowUpdates.push(updateObj);
                    if (!this._pendingRetryTimer) {
                        this._pendingRetryTimer = setTimeout(() => { this._pendingRetryTimer = null; this._flushPendingRowUpdates(); }, 120);
                    }
                    return;
                }
                // If not built, or row not available yet, queue and retry shortly
                if (!this._built || !this._rowExists(updateObj.id)) {
                    
                    if (!this._pendingRowUpdates) { this._pendingRowUpdates = []; }
                    this._pendingRowUpdates.push(updateObj);
                    if (!this._pendingRetryTimer) {
                        this._pendingRetryTimer = setTimeout(() => { this._pendingRetryTimer = null; this._flushPendingRowUpdates(); }, 100);
                    }
                    return;
                }
                // Built and row exists
                if (this._table) {
                    try {
                        const p = this._table.updateData([updateObj]);
                        if (p && typeof p.catch === 'function') {
                            p.catch((e3) => {
                                if (!this._pendingRowUpdates) { this._pendingRowUpdates = []; }
                                this._pendingRowUpdates.push(updateObj);
                                if (!this._pendingRetryTimer) {
                                    this._pendingRetryTimer = setTimeout(() => { this._pendingRetryTimer = null; this._flushPendingRowUpdates(); }, 160);
                                }
                            });
                        }
                    } catch(e3) {
                        // If Tabulator is in a reflow/rebuild, retry shortly with queue
                        if (!this._pendingRowUpdates) { this._pendingRowUpdates = []; }
                        this._pendingRowUpdates.push(updateObj);
                        if (!this._pendingRetryTimer) {
                            this._pendingRetryTimer = setTimeout(() => { this._pendingRetryTimer = null; this._flushPendingRowUpdates(); }, 160);
                        }
                    }
                }
            } catch(_) {}
        },
        _flushPendingRowUpdates: function(){
            try {
                const ups = this._pendingRowUpdates || [];
                this._pendingRowUpdates = null;
                if (!ups.length || !this._table) { return; }
                const ready = [];
                for (let i=0;i<ups.length;i++) { try { if (this._rowExists(ups[i].id)) { ready.push(ups[i]); } } catch(_) {} }
                if (ready.length) {
                    try {
                        const p = this._table.updateData(ready);
                        if (p && typeof p.catch === 'function') {
                            p.catch((e2) => {
                                // Re-queue and try once more shortly
                                this._pendingRowUpdates = (this._pendingRowUpdates || []).concat(ready);
                                if (!this._pendingRetryTimer) {
                                    this._pendingRetryTimer = setTimeout(() => { this._pendingRetryTimer = null; this._flushPendingRowUpdates(); }, 200);
                                }
                            });
                        }
                    } catch(e2) { /* silent */ }
                }
            } catch(_) {}
        },
        _queueUpdateData: function(updateObj){
            try {
                if (!this._table || !updateObj) return;
                // If table not yet built, queue until after tableBuilt
                if (!this._built) {
                    if (!this._pendingRowUpdates) { this._pendingRowUpdates = []; }
                    this._pendingRowUpdates.push(updateObj);
                    return;
                }
                if (!this._batchedUpdates) { this._batchedUpdates = []; }
                this._batchedUpdates.push(updateObj);
                if (this._batchTimer) return;
                const flush = () => {
                    try {
                        const payload = this._batchedUpdates || [];
                        this._batchedUpdates = null;
                        this._batchTimer = null;
                        if (payload.length > 0) {
                            try {
                                const p = this._table.updateData(payload);
                                if (p && typeof p.catch === 'function') {
                                    p.catch((e2) => {
                                        try { console.error('[EAO][ProductsGrid][Tabulator] updateData batch/rejected', payload, e2); } catch(_) {}
                                        this._pendingRowUpdates = (this._pendingRowUpdates || []).concat(payload);
                                        if (!this._pendingRetryTimer) {
                                            this._pendingRetryTimer = setTimeout(() => { this._pendingRetryTimer = null; this._flushPendingRowUpdates(); }, 200);
                                        }
                                    });
                                }
                            } catch(e2) {
                                try { console.error('[EAO][ProductsGrid][Tabulator] updateData batch failed', payload, e2); } catch(_) {}
                                this._pendingRowUpdates = (this._pendingRowUpdates || []).concat(payload);
                                if (!this._pendingRetryTimer) {
                                    this._pendingRetryTimer = setTimeout(() => { this._pendingRetryTimer = null; this._flushPendingRowUpdates(); }, 200);
                                }
                            }
                        }
                    } catch(_) { this._batchedUpdates = null; this._batchTimer = null; }
                };
                if (typeof window.requestAnimationFrame === 'function') {
                    this._batchTimer = requestAnimationFrame(flush);
                } else {
                    this._batchTimer = setTimeout(flush, 0);
                }
            } catch(_) {}
        },
        _scheduleSummaryRefresh: function(items){
            const self = this;
            if (self._summaryRefreshTimer) { clearTimeout(self._summaryRefreshTimer); }
            const doRefresh = function(){
                try {
                    if (window.EAO && window.EAO.MainCoordinator) {
                        const summary = window.EAO.MainCoordinator.calculateUnifiedSummaryData(items || (window.currentOrderItems||[]));
                        window.EAO.MainCoordinator.refreshSummaryOnly(items || (window.currentOrderItems||[]), summary);
                    }
                } catch(_) {}
            };
            // Use rAF when available to coalesce with next frame, else fallback to timeout
            if (typeof window.requestAnimationFrame === 'function') {
                self._summaryRefreshTimer = setTimeout(() => requestAnimationFrame(doRefresh), 50);
            } else {
                self._summaryRefreshTimer = setTimeout(doRefresh, 60);
            }
        },
        /**
         * Apply a new global discount percent to all non-excluded items and update only
         * the affected cells (discount, disc_price, total). This avoids any full grid
         * re-render and keeps interactions smooth.
         */
        applyGlobalDiscountToGrid: function(globalPercent){
            try {
                window.globalOrderDiscountPercent = parseFloat(globalPercent || 0) || 0;
                const list = (window.currentOrderItems || []);
                const table = this._table;
                if (!table || !list || list.length === 0) { return; }
                const updates = [];
                for (let i = 0; i < list.length; i++) {
                    const item = list[i];
                    const id = item.item_id || item._client_uid;
                    if (!id) { continue; }
                    // Skip excluded items - their discount UI is independent
                    const discountHtml = this._renderDiscount(item);
                    const discPriceHtml = this._renderDiscPrice(item, window.globalOrderDiscountPercent);
                    const priceRaw = parseFloat(item.price_raw || 0) || 0;
                    const unit = item.exclude_gd ? (typeof item.discounted_price_fixed==='number'? item.discounted_price_fixed : priceRaw) : (priceRaw * (1 - (window.globalOrderDiscountPercent/100)));
                    const totalHtml = `<span class="eao-line-total-display">${typeof eaoFormatPrice==='function' ? eaoFormatPrice(unit * (parseInt(item.quantity,10)||0)) : ('$'+(unit*(parseInt(item.quantity,10)||0)).toFixed(2))}</span>`;
                    if (window.eaoDebugPostSaveUntil && Date.now() < window.eaoDebugPostSaveUntil) {
                        // skip during stabilization window
                    } else {
                        this._safeUpdateDataSingle({ id, discount: discountHtml, disc_price: discPriceHtml, total: totalHtml });
                    }
                }
                // Removed dead bulk update path; per-row safe updater handles retries
                // Update header counters and summary only
                this.updateHeaderCounters(list);
                if (window.EAO && window.EAO.MainCoordinator) {
                    const summary = window.EAO.MainCoordinator.calculateUnifiedSummaryData(list);
                    window.EAO.MainCoordinator.refreshSummaryOnly(list, summary);
                }
            } catch(_) {}
        },
        render: function(items, opts) {
            this._container = opts && opts.container ? opts.container : document.getElementById('eao-current-order-items-list');
            try {
                const dbg = (window.eaoDebugPostSaveUntil && Date.now() < window.eaoDebugPostSaveUntil);
                if (dbg) {
                    
                }
            } catch(e) {}
            if (!this._container) { return; }
            try { this._container.style.minHeight = this._container.style.minHeight || '160px'; } catch(_) {}

            // Ensure Tabulator is present (Tabulator-only path; no legacy fallback)
            if (typeof Tabulator === 'undefined') { return; }

            // Build columns (include live counters)
            this._headerCounts = (window.EAO && window.EAO.MainCoordinator && typeof window.EAO.MainCoordinator.calculateProductListHeader === 'function')
                ? window.EAO.MainCoordinator.calculateProductListHeader(items || [])
                : { totalSkus: (items||[]).length, totalQuantity: (items||[]).reduce((s,i)=>s + (parseInt(i.quantity,10)||0), 0) };
            const columns = this._buildColumns();
            
            const colKey = this._columnsKey();
            if (this._isPainting) {
                this._pendingItems = { items: (items || []).slice(), colKey };
                return;
            }
            this._isPainting = true;

            // If a previous instance exists but its DOM was cleared (e.g., loader .html() replaced container),
            // re-initialize instead of trying to update phantom elements.
            try {
                if (this._table && this._table.element) {
                    const isConnected = !!(this._table.element.isConnected || (document.body && document.body.contains && document.body.contains(this._table.element)));
                    if (!isConnected) {
                        try { this._table.destroy(); } catch(e) {}
                        this._table = null;
                        
                    }
                }
            } catch(_) {}

            // Create or update Tabulator (no try/catch; if it blows up we want the error)
            if (!this._table) {
                const mapped = this._mapItems(items || []);
                
                this._built = false;
                this._table = new Tabulator(this._container, {
                    data: mapped,
                    layout: "fitColumns",
                    columnHeaderVertAlign: "middle",
                    headerVisible: true,
                    columnDefaults: { resizable: false, headerSort: false },
                    movableColumns: false,
                    reactiveData: false,
                    rowFormatter: (row) => this._applyRowStateClasses(row),
                    columns: columns,
                    placeholder: eaoEditorParams.i18n.no_items_in_order || 'There are no items in this order.'
                });
                try {
                    this._table.on('tableBuilt', () => {
                        this._built = true;
                        // Slightly longer grace to avoid updateData during Tabulator internal reflow
                        this._postBuildGraceUntil = Date.now() + 900;
                        this.requestRedraw(180);
                        // Flush any queued row updates that arrived before build
                        try {
                            if (this._pendingRowUpdates && this._pendingRowUpdates.length) {
                                const ready = [];
                                for (let i=0;i<this._pendingRowUpdates.length;i++) {
                                    const u = this._pendingRowUpdates[i];
                                    try { if (this._rowExists(u.id)) { ready.push(u); } } catch(_) {}
                                }
                                if (ready.length) {
                                    try {
                                        const p = this._table.updateData(ready);
                                        if (p && typeof p.catch === 'function') {
                                            p.catch((e4) => {
                                                
                                                this._pendingRowUpdates = (this._pendingRowUpdates || []).concat(ready);
                                                if (!this._pendingRetryTimer) {
                                                    this._pendingRetryTimer = setTimeout(() => { this._pendingRetryTimer = null; this._flushPendingRowUpdates(); }, 200);
                                                }
                                            });
                                        }
                                    } catch(e4) { }
                                }
                            }
                        } catch(_) {}
                        this._pendingRowUpdates = null;
                    });
                } catch(_) {}
                this._installResizeReleasePatch();
                // Bind immediate effects for exclude/discount/quantity changes (delegated on container)
                try {
                    const container = this._container;
                    if (container && !container._eaoBound) {
                        container.addEventListener('change', (e) => {
                            const t = e.target;
                            if (!t) return;
                            // Quantity change → use helpers; update cells only; refresh summary
                            if (t.classList.contains('eao-quantity-input')) {
                                try {
                                    // Set a short global guard to prevent full product list re-render requests
                                    // that might be triggered elsewhere during inline edits
                                    try { window.eaoAvoidProductRepaintUntil = Date.now() + 900; } catch(_) {}
                                    // If table is not fully ready or within grace, avoid any redraw-triggering ops
                                    if (!this._built || (this._postBuildGraceUntil && Date.now() < this._postBuildGraceUntil)) {
                                        const pidEarly = parseInt(t.getAttribute('data-product_id')||'0',10);
                                        const itemIdEarly = t.getAttribute('data_item_id')||t.getAttribute('data-item_id')||'';
                                        const valEarly = parseInt(t.value||'0',10) || 0;
                                        const listEarly = (window.currentOrderItems||[]);
                                        for (let i=0;i<listEarly.length;i++) {
                                            const it = listEarly[i];
                                            if ((itemIdEarly && String(it.item_id)===String(itemIdEarly)) || (!itemIdEarly && parseInt(it.product_id,10)===pidEarly)) {
                                                if (window.EAO && window.EAO.ProductsHelpers) { window.EAO.ProductsHelpers.applyQuantityChange(it, valEarly); } else { it.quantity = valEarly; }
                                                break;
                                            }
                                        }
                                        this.updateHeaderCounters(window.currentOrderItems || []);
                                        this._scheduleSummaryRefresh(window.currentOrderItems || []);
                                        return;
                                    }
                                    // Update the corresponding item quantity in state via helpers
                                    const pid = parseInt(t.getAttribute('data-product_id')||'0',10);
                                    const itemId = t.getAttribute('data_item_id')||t.getAttribute('data-item_id')||'';
                                    const val = parseInt(t.value||'0',10) || 0;
                                    const list = (window.currentOrderItems||[]);
                                    let targetItem = null;
                                    for (let i=0;i<list.length;i++) {
                                        const it = list[i];
                                        if ((itemId && String(it.item_id)===String(itemId)) || (!itemId && parseInt(it.product_id,10)===pid)) {
                                            if (window.EAO && window.EAO.ProductsHelpers) {
                                                window.EAO.ProductsHelpers.applyQuantityChange(it, val);
                                            } else {
                                                it.quantity = val;
                                            }
                                            targetItem = it;
                                            break;
                                        }
                                    }
                                    // Reflect new quantity in the input immediately (avoid waiting for full re-render)
                                    try { t.value = String(val); } catch(_) {}
                                    // Update per-row cells using updateData
                                    try {
                                        const table = this._table;
                                        if (table && targetItem) {
                                            const clientId = String(targetItem.item_id || targetItem._client_uid);
                                            const priceRaw = parseFloat(targetItem.price_raw || 0) || 0;
                                            const gd = parseFloat(window.globalOrderDiscountPercent || 0) || 0;
                                            const effectiveUnit = (targetItem.exclude_gd)
                                                ? (typeof targetItem.discounted_price_fixed === 'number' ? targetItem.discounted_price_fixed : priceRaw)
                                                : (priceRaw * (1 - (gd / 100)));
                                            const totalHtml = `<span class="eao-line-total-display">${typeof eaoFormatPrice==='function' ? eaoFormatPrice(effectiveUnit * val) : ('$'+(effectiveUnit*val).toFixed(2))}</span>`;
                                            const qtyHtml = this._renderQty(targetItem);
                                            if (window.eaoDebugPostSaveUntil && Date.now() < window.eaoDebugPostSaveUntil) {
                                                // During immediate post-refresh stabilization, skip direct row update (avoid Tabulator race)
                                            } else {
                                                this._safeUpdateDataSingle({ id: clientId, qty: qtyHtml, total: totalHtml });
                                            }
                                        }
                                    } catch(_) {}
                                    // Update header counters and summary only; also set a short guard against full refresh
                                    this.updateHeaderCounters(window.currentOrderItems || []);
                                    // Ensure points selection survives first product interaction
                                    try {
                                        const staged = (window.eaoStagedPointsDiscount && typeof window.eaoStagedPointsDiscount.points !== 'undefined') ? parseInt(window.eaoStagedPointsDiscount.points,10)||0 : null;
                                        const ptsSource = (staged !== null) ? staged : (window.eaoCurrentPointsDiscount && typeof window.eaoCurrentPointsDiscount.points !== 'undefined' ? parseInt(window.eaoCurrentPointsDiscount.points||0,10)||0 : 0);
                                        if (ptsSource >= 0) {
                                            const pts = ptsSource;
                                            window.eaoStagedPointsDiscount = { points: pts, amount: (pts / 10).toFixed ? (pts / 10).toFixed(2) : (pts / 10) };
                                            if (window.EAO && window.EAO.FormSubmission) { window.EAO.FormSubmission.lastPostedPoints = pts; }
                                            try { window.eaoPointsLockUntil = Date.now() + 800; } catch(_) {}
                                        }
                                    } catch(_) {}
                                    this._scheduleSummaryRefresh(window.currentOrderItems || []);
                                    try { window.eaoAvoidProductRepaintUntil = Date.now() + 900; } catch(_) {}
                                } catch(_) {}
                                return; // stop here; no grid redraw
                            }
                            // Exclude/discount/price changes → use helpers; update cells; refresh summary
                            if (t.classList.contains('eao-item-exclude-gd-cbx') || t.classList.contains('eao-item-discount-percent-input') || t.classList.contains('eao-item-discounted-price-input')) {
                                try {
                                    // Guard against unnecessary full re-renders during inline edits
                                    try { window.eaoAvoidProductRepaintUntil = Date.now() + 900; } catch(_) {}
                                    // Update row UI immediately for exclude/discount edits
                                    try {
                                        const pid = parseInt(t.getAttribute('data-product_id')||'0',10);
                                        const itemId = t.getAttribute('data_item_id')||t.getAttribute('data-item_id')||'';
                                        const item = (window.currentOrderItems||[]).find(it => (itemId && String(it.item_id)===String(itemId)) || (!itemId && parseInt(it.product_id,10)===pid));
                                        if (item && this._table) {
                                            const id = String(item.item_id || item._client_uid);
                                            if (!this._built || (this._postBuildGraceUntil && Date.now() < this._postBuildGraceUntil)) {
                                                // Apply to model only and schedule summary; skip cell updates to avoid redraw churn
                                                if (t.classList.contains('eao-item-exclude-gd-cbx')) {
                                                    const gd0 = parseFloat(window.globalOrderDiscountPercent||0)||0;
                                                    if (window.EAO && window.EAO.ProductsHelpers) { window.EAO.ProductsHelpers.applyExcludeChange(item, t.checked, gd0); }
                                                } else if (t.classList.contains('eao-item-discount-percent-input')) {
                                                    const v0 = parseFloat(t.value||'0')||0; if (window.EAO && window.EAO.ProductsHelpers) { window.EAO.ProductsHelpers.applyDiscountPercent(item, v0); }
                                                } else if (t.classList.contains('eao-item-discounted-price-input')) {
                                                    const v1 = parseFloat(t.value||'0')||0; if (window.EAO && window.EAO.ProductsHelpers) { window.EAO.ProductsHelpers.applyDiscountedPrice(item, v1); }
                                                }
                                                this.updateHeaderCounters(window.currentOrderItems || []);
                                                this._scheduleSummaryRefresh(window.currentOrderItems || []);
                                                return;
                                            }
                                            // Apply model changes via helpers
                                            if (t.classList.contains('eao-item-exclude-gd-cbx')) {
                                                const gd = parseFloat(window.globalOrderDiscountPercent||0)||0;
                                                if (window.EAO && window.EAO.ProductsHelpers) { window.EAO.ProductsHelpers.applyExcludeChange(item, t.checked, gd); }
                                            } else if (t.classList.contains('eao-item-discount-percent-input')) {
                                                const v = parseFloat(t.value||'0')||0;
                                                if (window.EAO && window.EAO.ProductsHelpers) { window.EAO.ProductsHelpers.applyDiscountPercent(item, v); }
                                            } else if (t.classList.contains('eao-item-discounted-price-input')) {
                                                const v2 = parseFloat(t.value||'0')||0;
                                                if (window.EAO && window.EAO.ProductsHelpers) { window.EAO.ProductsHelpers.applyDiscountedPrice(item, v2); }
                                            }
                                            // Rebuild only affected cells
                                            const gd = parseFloat(window.globalOrderDiscountPercent||0)||0;
                                            const discountHtml = this._renderDiscount(item);
                                            const discPriceHtml = this._renderDiscPrice(item, gd);
                                            const priceRaw = parseFloat(item.price_raw || 0) || 0;
                                            const unit = item.exclude_gd ? (typeof item.discounted_price_fixed==='number'? item.discounted_price_fixed : priceRaw) : (priceRaw * (1 - (gd/100)));
                                            const totalHtml = `<span class="eao-line-total-display">${typeof eaoFormatPrice==='function' ? eaoFormatPrice(unit * (parseInt(item.quantity,10)||0)) : ('$'+(unit*(parseInt(item.quantity,10)||0)).toFixed(2))}</span>`;
                                            if (window.eaoDebugPostSaveUntil && Date.now() < window.eaoDebugPostSaveUntil) {
                                                // skip during stabilization window
                                            } else {
                                                this._safeUpdateDataSingle({ id, discount: discountHtml, disc_price: discPriceHtml, total: totalHtml });
                                            }
                                        }
                                    } catch(_) {}

                                    this._scheduleSummaryRefresh(window.currentOrderItems || []);
                                } catch(_) {}
                            }
                        }, true);
                        // Points details popup (delegated)
                        container.addEventListener('click', (e) => {
                            const btn = e.target && (e.target.closest ? e.target.closest('.eao-points-details-btn') : null);
                            if (!btn) return;
                            e.preventDefault();
                            try {
                                // Read legacy attribute that existed in the working implementation
                                const itemId = btn.getAttribute('data_item_id') || btn.getAttribute('data-item_id') || '';
                                if (itemId && window.EAO && window.EAO.MainCoordinator && typeof window.EAO.MainCoordinator.showPointsDetailsForItem === 'function') {
                                    window.EAO.MainCoordinator.showPointsDetailsForItem(itemId);
                                }
                            } catch(_) {}
                        }, true);

                        // Click handlers for delete/undo buttons (no full grid redraw)
                        container.addEventListener('click', (e) => {
                            const btn = e.target && (e.target.closest ? e.target.closest('.eao-remove-item-button') : null);
                            if (!btn) return;
                            e.preventDefault();
                            try {
                                const pid = parseInt(btn.getAttribute('data-product_id')||'0',10);
                                const itemId = btn.getAttribute('data_item_id')||btn.getAttribute('data-item_id')||'';
                                const isUndo = btn.classList.contains('eao-undo-delete-button');
                                const list = (window.currentOrderItems||[]);
                                const idx = list.findIndex(it => (itemId && String(it.item_id)===String(itemId)) || (!itemId && parseInt(it.product_id,10)===pid));
                                if (idx >= 0) {
                                    const it = list[idx];
                                    if (isUndo) {
                                        it.isPendingDeletion = false;
                                        if (this._table) {
                                            const id = String(it.item_id || it._client_uid);
                                            // Re-render actions and enable inputs, and clear pending flag for rowFormatter
                                            const actionsHtml = this._renderActions(it);
                                            this._safeUpdateDataSingle({ id, actions: actionsHtml, _pendingDeletion: false });
                                            const row = this._table.getRow(id); if (row) { row.reformat(); }
                                        }
                                    } else {
                                        if (it.item_id) {
                                            // Existing item -> mark pending deletion (red state)
                                            it.isPendingDeletion = true;
                                            if (this._table) {
                                                const id = String(it.item_id || it._client_uid);
                                                const actionsHtml = this._renderActions(it);
                                                // Disable editable cells by re-rendering with disabled state
                                                const discountHtml = this._renderDiscount(it);
                                                const discPriceHtml = this._renderDiscPrice(it, parseFloat(window.globalOrderDiscountPercent||0)||0);
                                                this._safeUpdateDataSingle({ id, actions: actionsHtml, discount: discountHtml, disc_price: discPriceHtml, _pendingDeletion: true });
                                                const row = this._table.getRow(id); if (row) { row.reformat(); }
                                            }
                                        } else {
                                            // New staged item -> remove entirely
                                            list.splice(idx, 1);
                                            if (this._table) {
                                                const id = String(it.item_id || it._client_uid);
                                                try { this._table.deleteRow(id); } catch(_) {}
                                            }
                                        }
                                    }
                                    // Update summary and header counters
                                    this.updateHeaderCounters(window.currentOrderItems || []);
                                    if (window.EAO && window.EAO.MainCoordinator) {
                                        const summary = window.EAO.MainCoordinator.calculateUnifiedSummaryData(window.currentOrderItems || []);
                                        window.EAO.MainCoordinator.refreshSummaryOnly(window.currentOrderItems || [], summary);
                                    }
                                }
                            } catch(_) {}
                        }, true);
                        // Lightweight live updates while typing (no grid redraw)
                        container.addEventListener('input', (e) => {
                            const t = e.target;
                            if (!t) return;
                            const list = (window.currentOrderItems||[]);
                            if (t.classList && (t.classList.contains('eao-quantity-input') || t.classList.contains('eao-item-discount-percent-input') || t.classList.contains('eao-item-discounted-price-input'))) {
                                try {
                                    const pid = parseInt(t.getAttribute('data-product_id')||'0',10);
                                    const itemId = t.getAttribute('data_item_id')||t.getAttribute('data-item_id')||'';
                                    for (let i=0;i<list.length;i++) {
                                        const it = list[i];
                                        const match = (itemId && String(it.item_id)===String(itemId)) || (!itemId && parseInt(it.product_id,10)===pid);
                                        if (!match) continue;
                                        if (t.classList.contains('eao-quantity-input')) {
                                            it.quantity = parseInt(t.value||'0',10) || 0;
                                            // Update the cell UI immediately
                                            try { if (this._table) { const id = it.item_id || it._client_uid; const qtyHtml = this._renderQty(it); this._safeUpdateDataSingle({ id, qty: qtyHtml }); } } catch(_){ }
                                            // mark recent input on element to suppress immediate change duplicate
                                            try { t._eaoLastInputTs = Date.now(); t._eaoLastValue = parseInt(t.value||'0',10) || 0; } catch(_) {}
                                        } else if (t.classList.contains('eao-item-discount-percent-input')) {
                                            const v = parseFloat(t.value||'0')||0;
                                            if (it.exclude_gd) { it.discount_percent = v; }
                                        } else if (t.classList.contains('eao-item-discounted-price-input')) {
                                            const v2 = parseFloat(t.value||'0')||0;
                                            if (it.exclude_gd) { it.discounted_price_fixed = v2; }
                                        }
                                        break;
                                    }
                                    this._scheduleSummaryRefresh(window.currentOrderItems || []);
                                } catch(_) {}
                            }
                        }, true);
                        // Suppress duplicate 'change' after recent 'input'
                        container.addEventListener('change', (e) => {
                            const t = e.target;
                            if (t && t.classList && t.classList.contains('eao-quantity-input')) {
                                try {
                                    const now = Date.now();
                                    const lastTs = t._eaoLastInputTs || 0;
                                    const recent = (now - lastTs) < 800; // wider window after rehydrate
                                    const valNow = parseInt(t.value||'0',10) || 0;
                                    const sameVal = (typeof t._eaoLastValue !== 'undefined') && (valNow === t._eaoLastValue);
                                    if (recent && sameVal) { e.stopPropagation(); return; }
                                } catch(_) {}
                            }
                        }, true);
                        // Suppress any coordination-level full refresh during a short window after inline edits
                        container.addEventListener('input', (e) => {
                            const t = e.target;
                            if (t && t.classList && (t.classList.contains('eao-quantity-input') || t.classList.contains('eao-item-discount-percent-input') || t.classList.contains('eao-item-discounted-price-input'))) {
                                try { window.eaoAvoidProductRepaintUntil = Date.now() + 900; } catch(_) {}
                            }
                        }, true);
                        container._eaoBound = true;
                    }
                } catch(_) {}
                this._lastColumnsKey = colKey;
                // Ensure we complete the paint cycle and release the lock
                this.requestRedraw();
            } else {
                // If a full restore was requested, rebuild once and skip intermediate updates
                if (window.eaoPostSavePreferredRestorer === 'template') {
                    try { this._table.destroy(); } catch(_) {}
                    this._container.innerHTML = '';
                    const mapped = this._mapItems(items || []);
                    this._table = new Tabulator(this._container, {
                        data: mapped,
                        layout: "fitColumns",
                        columnHeaderVertAlign: "middle",
                        headerVisible: true,
                        columnDefaults: { resizable: false, headerSort: false },
                        movableColumns: false,
                        reactiveData: false,
                        rowFormatter: (row) => this._applyRowStateClasses(row),
                        columns: columns,
                        placeholder: eaoEditorParams.i18n.no_items_in_order || 'There are no items in this order.'
                    });
                this._lastColumnsKey = colKey;
                // Wait for build, then mark grace window before redraw
                    try {
                        this._built = false;
                        this._table.on('tableBuilt', () => {
                            this._built = true;
                            this._postBuildGraceUntil = Date.now() + 500;
                            setTimeout(() => {
                                try { this._applyShipmentVisualClasses(); } catch(_){ }
                            this.requestRedraw();
                            try { if (window.EAO && window.EAO.FormSubmission && window.EAO.FormSubmission.hintProgress && window.eaoDebugProgress) { window.EAO.FormSubmission.hintProgress(83, 'grid:built'); } } catch(_) {}
                            }, 180);
                        });
                    } catch(_) {}
                    return;
                }
                // In Tabulator, column groups don't have a field key; update the whole
                // column definition to refresh shipment columns rather than updating by id
                const mapped = this._mapItems(items || []);
                
                const newKey = colKey;
                // If transitioning from 0 -> >0, rebuild to avoid any stale placeholder/height state
                if (this._lastDataCount === 0 && mapped.length > 0) {
                    try { this._table.destroy(); } catch(e) {}
                    this._container.innerHTML = '';
                    this._built = false;
                    this._table = new Tabulator(this._container, {
                        data: mapped,
                        layout: "fitColumns",
                        columnHeaderVertAlign: "middle",
                        headerVisible: true,
                        columnDefaults: { resizable: false, headerSort: false },
                        movableColumns: false,
                        reactiveData: false,
                        rowFormatter: (row) => this._applyRowStateClasses(row),
                        columns: columns,
                        placeholder: eaoEditorParams.i18n.no_items_in_order || 'There are no items in this order.'
                    });
                    try {
                        this._table.on('tableBuilt', () => {
                            this._built = true;
                            this._postBuildGraceUntil = Date.now() + 500;
                            this.requestRedraw(180);
                            try { if (window.EAO && window.EAO.FormSubmission && window.EAO.FormSubmission.hintProgress && window.eaoDebugProgress) { window.EAO.FormSubmission.hintProgress(85, 'grid:rebuilt'); } } catch(_) {}
                        });
                    } catch(_) {}
                    this._lastColumnsKey = newKey;
                    // Finalize paint
                    // Wait for tableBuilt -> _safeRedrawAndLog will run then
                } else {
                    // Only update columns if signature changed (e.g., shipments count)
                    if (this._lastColumnsKey !== newKey) {
                        this._table.setColumns(columns, true);
                        this._lastColumnsKey = newKey;
                        this._table.setData(mapped);
                        try { this._applyShipmentVisualClasses(); } catch(_){ }
                    } else {
                        // If row count changed (add/remove), refresh dataset once
                        try {
                            const currentLen = (this._table.getData && this._table.getData().length) || 0;
                            if (currentLen !== mapped.length) {
                                if (typeof this._table.replaceData === 'function') { this._table.replaceData(mapped); } else { this._table.setData(mapped); }
                                try { if (window.EAO && window.EAO.FormSubmission && window.EAO.FormSubmission.hintProgress && window.eaoDebugProgress) { window.EAO.FormSubmission.hintProgress(86, 'grid:data'); } } catch(_) {}
                            } else if (mapped.length > 0) {
                                // Row count identical: incremental update only for changed rows
                                // Avoid calling updateData during immediate stabilization window
                                if ((this._postBuildGraceUntil && Date.now() < this._postBuildGraceUntil) || (window.eaoDebugPostSaveUntil && Date.now() < window.eaoDebugPostSaveUntil)) {
                                    // Defer to per-row safe updater on next interactions
                                } else {
                                const updates = [];
                                const curr = this._table.getData();
                                const byId = {};
                                for (let i=0;i<curr.length;i++){ byId[String(curr[i].id)] = curr[i]; }
                                for (let j=0;j<mapped.length;j++){
                                    const row = mapped[j];
                                    const ex = byId[String(row.id)];
                                    if (!ex) continue;
                                    // Compare a few key fields; if different, enqueue update
                                    if (ex.qty !== row.qty || ex.discount !== row.discount || ex.disc_price !== row.disc_price || ex.total !== row.total || ex.actions !== row.actions) {
                                        updates.push({ id: row.id, qty: row.qty, discount: row.discount, disc_price: row.disc_price, total: row.total, actions: row.actions });
                                    }
                                }
                                // Use safe single-row updater to benefit from row-exists checks and retries
                                if (updates.length) {
                                    for (let k=0;k<updates.length;k++) { this._safeUpdateDataSingle(updates[k]); }
                                }
                                }
                            }
                        } catch(_) {}
                        // Otherwise, per-row handlers update cells; no action here
                    }
                }
                // Only redraw when table is fully built
                if (this._built) { this.requestRedraw(); try { if (window.EAO && window.EAO.FormSubmission && window.EAO.FormSubmission.hintProgress && window.eaoDebugProgress) { window.EAO.FormSubmission.hintProgress(88, 'grid:redraw'); } } catch(_) {} }
            }

            // No default view toggling; both groups visible
        },

        _getOrderStatusSlug: function(){
            try {
                const $sel = jQuery('#order_status');
                if ($sel && $sel.length) {
                    const val = String($sel.val() || '').trim();
                    if (val) { return val.replace('wc-',''); }
                }
            } catch(_e){}
            try {
                if (window.eaoEditorParams && window.eaoEditorParams.order_status) {
                    return String(window.eaoEditorParams.order_status);
                }
            } catch(_e){}
            return '';
        },

        _shouldDisplayShipments: function(){
            try {
                const sm = (window.eaoShipmentsMeta || {});
                const num = parseInt(sm.num_shipments || 0, 10) || 0;
                // If we have any shipment columns with qty mapping, prefer showing
                if (num > 0) {
                    try {
                        const hasAnyQty = !!Object.keys(sm.product_qty_map || {}).length;
                        if (hasAnyQty) { return true; }
                    } catch(_) {}
                }
                const raw = (this._getOrderStatusSlug() || '').toLowerCase();
                const status = raw.replace(/_/g,'-');
                try { console.log('[EAO Shipments] _shouldDisplayShipments status=%s num=%s meta=', status, num, sm); } catch(_){ }
                if (status === 'partially-shipped' || status === 'partially-shipped-order' || status === 'partially_shipped') {
                    const res = (num >= 1);
                    try { console.log('[EAO Shipments] decision: partially-shipped →', res); } catch(_){ }
                    return res; // always show when partially shipped
                }
                if (status === 'shipped' || status === 'delivered' || status === 'completed') {
                    // Show only when there are more than one shipment (per spec)
                    const res = num > 1;
                    try { console.log('[EAO Shipments] decision: shipped/delivered/completed, num=%s → %s', num, res); } catch(_){ }
                    return res;
                }
                try { console.log('[EAO Shipments] decision: status not eligible → false'); } catch(_){ }
                return false;
            } catch(e) { return false; }
        },

        _columnsKey: function() {
            try {
                const sm = (window.eaoShipmentsMeta || {});
                const num = parseInt(sm.num_shipments || 0, 10) || 0;
                const show = this._shouldDisplayShipments() ? 1 : 0;
                return 'base-v1|ship:' + num + '|show:' + show;
            } catch(e) { return 'base-v1|ship:0|show:0'; }
        },

        _applyRowStateClasses: function(row) {
            try {
                const data = row.getData ? row.getData() : null;
                if (!data) return;
                const el = row.getElement();
                if (!el) return;
                el.classList.remove('eao-staged','eao-pending-deletion');
                if (data._pendingDeletion) { el.classList.add('eao-pending-deletion'); }
                if (data._isPending && !data._isExisting) { el.classList.add('eao-staged'); }
            } catch(_) {}
        },

        _safeRedrawAndLog: function() {
            if (!this._table || !this._built) { return; }
            const tryRedraw = (retries) => {
                let w = 0, h = 0;
                try {
                    const rect = this._container.getBoundingClientRect();
                    w = rect ? rect.width : this._container.offsetWidth;
                    h = rect ? rect.height : this._container.offsetHeight;
                } catch(_) {}
                const visible = this._container && document.body.contains(this._container) && w > 0 && h > 0;
                if (!visible && retries < 10) {
                    
                    return setTimeout(() => tryRedraw(retries + 1), 140);
                }
                try {
                    if (this._table && typeof this._table.redraw === 'function') {
                        // Guard: skip redraw if container is still invisible to avoid offsetWidth null errors inside Tabulator
                        if (this._container && (this._container.offsetWidth === 0 || this._container.offsetHeight === 0)) {
                            throw new Error('container-not-visible');
                        }
                        // Defer heavy redraw to next frame to avoid forced reflow in call stack
                        const self = this;
                        requestAnimationFrame(function(){ try { self._table.redraw(true); } catch(__){} });
                    }
                } catch (e) {
                    if (String(e && e.message).indexOf('container-not-visible') >= 0) {
                        
                    } else {
                        console.warn('[EAO][ProductsGrid][Tabulator] redraw/error', e);
                    }
                }

                // Post metrics after paint
                setTimeout(() => {
                    const rows = (this._table && this._table.getRows) ? this._table.getRows().length : 'n/a';
                    const dataCount = (this._table && this._table.getDataCount) ? this._table.getDataCount() : (this._table && this._table.getData ? this._table.getData().length : 'n/a');
                    const phEl = this._container ? this._container.querySelector('.tabulator-placeholder') : null;
                    let placeholderVisible = !!(phEl && window.getComputedStyle(phEl).display !== 'none');
                    if (Number(dataCount) > 0 && phEl) {
                        try { phEl.style.display = 'none'; phEl.remove(); placeholderVisible = false; } catch(e) {}
                    }
                    
                    this._lastDataCount = Number(dataCount) || 0;
                    this._isPainting = false;
                    if (this._pendingItems) {
                        const pending = this._pendingItems; this._pendingItems = null;
                        requestAnimationFrame(() => this.render(pending.items, { container: this._container }));
                    }
                    // Removed safety second redraw to eliminate double repaint after interactions.
                    try { this._applyShipmentVisualClasses(); console.log('[EAO Shipments][apply] via redraw'); } catch(_) {}
                }, 40);
            };
            tryRedraw(0);
        },

        applyDefaultView: function() { /* no-op; both groups visible */ },

        updateHeaderCounters: function(items) {
            try {
                if (!this._table) return;
                const arr = items || (window.currentOrderItems || []);
                const totalSkus = (arr || []).length;
                const totalQty = (arr || []).reduce((s,i)=> s + (parseInt(i.quantity,10)||0), 0);
                // Avoid unnecessary DOM updates
                if (this._lastHeaderSkuCount === totalSkus && this._lastHeaderQtyCount === totalQty) { return; }
                const prodCol = this._table.getColumn('product');
                const qtyCol = this._table.getColumn('qty');
                if (prodCol && prodCol.getElement) {
                    const el = prodCol.getElement().querySelector('.tabulator-col-title');
                    if (el) { el.textContent = 'Products (' + totalSkus + ')'; }
                }
                if (qtyCol && qtyCol.getElement) {
                    const el2 = qtyCol.getElement().querySelector('.tabulator-col-title');
                    if (el2) { el2.textContent = 'Qty (' + totalQty + ')'; }
                }
                this._lastHeaderSkuCount = totalSkus; this._lastHeaderQtyCount = totalQty;
            } catch(_) {}
        },

        toggleShipments: function(show) {
            if (!this._table) return;
            if (!this._shipmentsEnabled) return;
            const shipCols = this._getShipmentFieldNames();
            const pricingCols = ["points","cost","price","exclude","discount","disc_price"]; // keys used below
            // Add graceful fade by toggling a helper class on group headers, then applying visibility
            try {
                const headerEl = this._container.querySelector('.tabulator-headers');
                if (headerEl) {
                    const pricingGroupHeader = headerEl.querySelector('.tabulator-col-group[role="columnheader"]:has(.tabulator-col-title:contains("Pricing"))');
                    const shipmentsGroupHeader = headerEl.querySelector('.tabulator-col-group[role="columnheader"]:has(.tabulator-col-title:contains("Shipments"))');
                    if (pricingGroupHeader) pricingGroupHeader.classList.toggle('eao-hide-group', !!show);
                    if (shipmentsGroupHeader) shipmentsGroupHeader.classList.toggle('eao-hide-group', !show);
                }
            } catch(e) {}
            pricingCols.forEach(f => this._table.getColumn(f) && (show ? this._table.hideColumn(f) : this._table.showColumn(f)));
            shipCols.forEach(f => this._table.getColumn(f) && (show ? this._table.showColumn(f) : this._table.hideColumn(f)));
            this._showShipments = !!show;
        },

        _buildColumns: function() {
            const counts = this._headerCounts || { totalSkus: 0, totalQuantity: 0 };
            const showShip = this._shouldDisplayShipments();
            this._shipmentsEnabled = !!showShip;
            try { console.log('[EAO Shipments] _buildColumns showShip=%s enabled=%s key=%s', showShip, this._shipmentsEnabled, this._columnsKey()); } catch(_){ }
            // Request AST status map once per view (safe internal AJAX)
            try {
                if (showShip && window.eaoEditorParams && window.eaoEditorParams.order_id) {
                    if (!window.eaoAstStatusMapRequested) {
                        window.eaoAstStatusMapRequested = true;
                        jQuery.post(eaoEditorParams.ajax_url, { action:'eao_get_shipment_statuses', order_id: window.eaoEditorParams.order_id }, function(resp){
                            if (resp && resp.success && resp.data && resp.data.statuses) {
                                window.eaoAstStatusMap = resp.data.statuses || {};
                                try { console.log('[EAO Shipments][ts-map]', { orderId: window.eaoEditorParams.order_id, statuses: window.eaoAstStatusMap }); } catch(_) {}
                                window.eaoAstStatusMapReady = true;
                                try { setTimeout(function(){ if (window.EAO && window.EAO.ProductsTable && typeof window.EAO.ProductsTable._applyShipmentVisualClasses==='function'){ window.EAO.ProductsTable._applyShipmentVisualClasses(); }}, 50); } catch(_) {}
                            }
                            else { try { console.warn('[EAO Shipments][ts-map] empty or error', resp); } catch(_) {} }
                        });
                    }
                }
            } catch(_) {}
            const i18n = (window.eaoEditorParams && window.eaoEditorParams.i18n) || {};
            const excludeTooltip = i18n.exclude_gd_tooltip || 'Exclude from Global Discount';
            const discountTooltip = i18n.discount_tooltip || 'Discount Percentage';
            const discPriceTooltip = i18n.discounted_price_tooltip || 'Discounted Price';
            const pricingGroup = {
                title: "Pricing",
                resizable: false,
                columns: [
                    { title: "Points", field: "points", visible: true, width: 50, widthGrow: 0, hozAlign: "center", formatter: (cell) => cell.getValue() },
                    { title: "Cost", field: "cost", visible: true, width: 90, widthGrow: 0, hozAlign: "center", formatter: (cell) => cell.getValue() },
                    { title: "Price", field: "price", visible: true, width: 62, widthGrow: 0, hozAlign: "center", formatter: (cell) => cell.getValue() },
                    { title: "Excl. GD", field: "exclude", width: 36, widthGrow: 0, hozAlign: "center", headerTooltip: excludeTooltip, formatter: (cell) => cell.getValue() },
                    { title: "Discount %", field: "discount", width: 62, widthGrow: 0, hozAlign: "center", headerTooltip: discountTooltip, formatter: (cell) => cell.getValue() },
                    { title: "Disc. Price", field: "disc_price", width: 62, widthGrow: 0, hozAlign: "center", headerTooltip: discPriceTooltip, formatter: (cell) => cell.getValue() },
                ]
            };

            const shipGroup = {
                title: "Shipments",
                field: "_ship_group",
                resizable: false,
                columns: this._buildShipmentColumns()
            };

            const allColumns = [
                { title: "", field: "thumb", width: 42, widthGrow: 0, headerSort: false, formatter: (cell) => cell.getValue() },
                { title: "Products (" + counts.totalSkus + ")", field: "product", minWidth: 360, widthGrow: 1, headerSort: false, formatter: (cell) => cell.getValue() },
                { title: "Qty (" + counts.totalQuantity + ")", field: "qty", width: 80, widthGrow: 0, headerSort: false, hozAlign: "center", formatter: (cell) => cell.getValue() },
                pricingGroup,
            ];
            if (showShip) { allColumns.push(shipGroup); }
            allColumns.push(
                { title: "Total", field: "total", width: 62, widthGrow: 0, headerSort: false, hozAlign: "center", formatter: (cell) => cell.getValue() },
                { title: "", field: "actions", width: 40, widthGrow: 0, headerSort: false, hozAlign: "center", formatter: (cell) => cell.getValue() },
            );
            
            return allColumns;
        },

        _applyShipmentVisualClasses: function(){
            try {
                if (!this._table) return;
                const sm = (window.eaoShipmentsMeta || {});
                const num = parseInt(sm.num_shipments, 10) || 0;
                try { console.log('[EAO Shipments][apply] start', { num, columns: (sm.columns||[])}); } catch(_) {}
                if (num <= 0) return;
                for (let i=1;i<=num;i++){
                    const col = this._table.getColumn('ship_' + i);
                    if (!col || !col.getElement) continue;
                    const el = col.getElement();
                    const headerEl = el.querySelector('.tabulator-col-content') || el;
                    // ensure column element carries the status class
                    const smCol = (sm.columns || []).find(c => parseInt(c.index,10) === i) || {};
                    const vis = this._deriveShipmentVisual(smCol);
                    const status = vis.status;
                    const colClass = vis.colClass;
                    // Human friendly status text
                    const friendly = (status || '').replace('_',' ').replace('_',' ');
                    const provider = smCol.formatted_tracking_provider || smCol.tracking_provider || '';
                    el.classList.remove('eao-shipcol-delivered','eao-shipcol-intransit','eao-shipcol-amber','eao-shipcol-exception','eao-shipcol-pending');
                    el.classList.add(colClass, 'eao-shipcol-bg');
                    headerEl.classList.add(colClass);
                    try { headerEl.setAttribute('title', (provider? (provider + ' • ') : '') + friendly); } catch(_) {}
                    // Click on header opens tracking page for this shipment if tracking number is known
                    try {
                        const tn = String(smCol.tracking_number || '').trim();
                        if (tn) {
                            headerEl.style.cursor = 'pointer';
                            headerEl.addEventListener('click', function(e){
                                e.preventDefault();
                                e.stopPropagation();
                                let url = '';
                                try {
                                    const $a = jQuery('a').filter(function(){ return jQuery(this).text().indexOf(tn) !== -1; }).first();
                                    if ($a && $a.length && $a.attr('href')) { url = String($a.attr('href')); }
                                } catch(_e) {}
                                if (!url) {
                                    const base = (window.eaoEditorParams && window.eaoEditorParams.tracking_base) ? String(window.eaoEditorParams.tracking_base) : '';
                                    if (base) {
                                        url = base.replace(/\/$/, '/') + '?tracking=' + encodeURIComponent(tn);
                                    } else {
                                        url = '/ts-shipment-tracking/?tracking=' + encodeURIComponent(tn);
                                    }
                                }
                                try { window.open(url, '_blank', 'noopener'); } catch(_) { window.location.href = url; }
                            }, { once: true });
                        }
                    } catch(_ee) {}
                    // Apply class and tooltip to all cells in this column so the entire column is tinted
                    try {
                        const cells = (typeof col.getCells === 'function') ? col.getCells() : [];
                        if (cells && cells.length) {
                            for (let k=0;k<cells.length;k++) {
                                const ce = cells[k].getElement ? cells[k].getElement() : null;
                                if (!ce) continue;
                                ce.classList.remove('eao-shipcol-delivered','eao-shipcol-intransit','eao-shipcol-amber','eao-shipcol-exception','eao-shipcol-pending');
                                ce.classList.add(colClass);
                                ce.setAttribute('title', (provider? (provider + ' • ') : '') + friendly);
                                // Click to open tracking page in new tab
                                try {
                                    const tn = String(smCol.tracking_number || '').trim();
                                    if (tn) {
                                        ce.style.cursor = 'pointer';
                                        ce.addEventListener('click', function(e){
                                            e.preventDefault();
                                            e.stopPropagation();
                                            let url = '';
                                            // Prefer AST metabox anchor href if present for this tracking number
                                            try {
                                                const $a = jQuery('a').filter(function(){ return jQuery(this).text().indexOf(tn) !== -1; }).first();
                                                if ($a && $a.length && $a.attr('href')) { url = String($a.attr('href')); }
                                            } catch(_e) {}
                                            if (!url) {
                                                const base = (window.eaoEditorParams && window.eaoEditorParams.tracking_base) ? String(window.eaoEditorParams.tracking_base) : '';
                                                if (base) {
                                                    // Ensure trailing slash then append query
                                                    url = base.replace(/\/$/, '/') + '?tracking=' + encodeURIComponent(tn);
                                                } else {
                                                    url = '/ts-shipment-tracking/?tracking=' + encodeURIComponent(tn);
                                                }
                                            }
                                            try { window.open(url, '_blank', 'noopener'); } catch(_) { window.location.href = url; }
                                        }, { once: true });
                                    }
                                } catch(_ee) {}
                            }
                        }
                    } catch(_) {}
                    try { console.log('[EAO Shipments][applied]', { index: i, tracking: smCol.tracking_number || '', metaStatus: (smCol.status_slug||''), astStatus: this._getAstStatusForTracking(smCol.tracking_number||''), finalStatus: status, cssClass: colClass }); } catch(_) {}
                }
            } catch(_) {}
        },

        _deriveShipmentVisual: function(smCol){
            const orderStatus = String(this._getOrderStatusSlug() || '').toLowerCase();
            let status = String(smCol && smCol.status_slug ? smCol.status_slug : '').toLowerCase();
            try {
                // Prefer server-provided statuses map if available
                if (window.eaoAstStatusMapReady && window.eaoAstStatusMap && smCol.tracking_number && window.eaoAstStatusMap[smCol.tracking_number]) {
                    status = String(window.eaoAstStatusMap[smCol.tracking_number]).toLowerCase();
                } else {
                    const ast = this._getAstStatusForTracking(smCol.tracking_number || '');
                    if (ast) { status = ast; }
                }
            } catch(_) {}
            if (status.indexOf('delivered') >= 0 && !(orderStatus === 'delivered' || orderStatus === 'completed')) {
                status = 'in_transit';
            }
            let colClass = 'eao-shipcol-pending';
            if (status.indexOf('delivered') >= 0) colClass = 'eao-shipcol-delivered';
            else if (status.indexOf('in_transit') >= 0 || status.indexOf('pre_transit') >= 0 || status.indexOf('out_for_delivery') >= 0) colClass = 'eao-shipcol-intransit';
            else if (status.indexOf('on_hold') >= 0 || status.indexOf('available_for_pickup') >= 0 || status.indexOf('shipped') >= 0 || status.indexOf('partial') >= 0) colClass = 'eao-shipcol-amber';
            else if (status.indexOf('exception') >= 0 || status.indexOf('failure') >= 0 || status.indexOf('return_to_sender') >= 0) colClass = 'eao-shipcol-exception';
            try { console.log('[EAO Shipments][derive]', { tracking: smCol.tracking_number || '', metaStatus: (smCol.status_slug||''), finalStatus: status, cssClass: colClass }); } catch(_) {}
            return { status, colClass };
        },

        _getAstStatusForTracking: function(trackingNumber){
            try {
                if (!trackingNumber) return '';
                let txt = '';
                // 1) Prefer explicit shipment card containers that contain this tracking number
                try {
                    const $card = jQuery('.shipment-content, .tracking-content, .enhanced_tracking_detail, .tracking_number_wrap, .col.tracking-detail')
                        .filter(function(){ return jQuery(this).text().indexOf(trackingNumber) !== -1; })
                        .first();
                    if ($card && $card.length) {
                        txt = String($card.text() || '').toLowerCase();
                    }
                } catch(_e) {}
                // 2) Fallback: anchor search
                if (!txt) {
                    const $a = jQuery('a').filter(function(){ return jQuery(this).text().indexOf(trackingNumber) !== -1; }).first();
                    if ($a && $a.length) {
                        const $scope = $a.closest('.tracking-content, .tracking_number_wrap, .enhanced_tracking_detail, .shipment-content, .col.tracking-detail, li, div, .inside, .postbox');
                        txt = String(($scope && $scope.length ? $scope.text() : '') || '').toLowerCase();
                    }
                }
                if (!txt || txt.length < 10) {
                    try {
                        const all = String(document.body.innerText || '').toLowerCase();
                        const i = all.indexOf(String(trackingNumber).toLowerCase());
                        if (i >= 0) {
                            txt = all.substring(Math.max(0,i-100), Math.min(all.length, i+400));
                        }
                    } catch(_) {}
                }
                try { console.log('[EAO Shipments][ast-dom]', { tracking: trackingNumber, snippet: txt ? txt.substring(0,160) : '' }); } catch(_) {}
                // Prioritize transient states over delivered when both appear
                if (txt.indexOf('pre transit') >= 0) return 'pre_transit';
                if (txt.indexOf('in transit') >= 0) return 'in_transit';
                if (txt.indexOf('out for delivery') >= 0) return 'out_for_delivery';
                if (txt.indexOf('available for pickup') >= 0) return 'available_for_pickup';
                if (txt.indexOf('on hold') >= 0) return 'on_hold';
                if (txt.indexOf('exception') >= 0 || txt.indexOf('failure') >= 0) return 'exception';
                if (txt.indexOf('delivered') >= 0) return 'delivered';
                return '';
            } catch(_) { return ''; }
        },

        _buildShipmentColumns: function() {
            const cols = [];
            const sm = (window.eaoShipmentsMeta || {});
            let num = parseInt(sm.num_shipments, 10) || 0;
            // Guard against misalignment: if num is 1 but qty map references two shipments, adjust
            try {
                const indices = {};
                Object.values(sm.product_qty_map || {}).forEach(map => {
                    Object.keys(map || {}).forEach(k => { indices[k] = true; });
                });
                const inferred = Object.keys(indices).length;
                if (inferred > num) { num = inferred; }
            } catch(_) {}
            const showShip = this._shouldDisplayShipments();
            if (!showShip || num <= 0) { return cols; }
            for (let i = 1; i <= num; i++) {
                // Determine status for this shipment index (if provided)
                let qtyClass = '', colClass = 'eao-shipcol-pending';
                try {
                    const col = (sm.columns || []).find(c => parseInt(c.index,10) === i) || {};
                    const vis = this._deriveShipmentVisual(col);
                    let status = vis.status;
                    if (status) {
                        if (status.indexOf('delivered') >= 0) { qtyClass = ' eao-ship-delivered'; colClass = 'eao-shipcol-delivered'; }
                        else if (status.indexOf('in_transit') >= 0 || status.indexOf('in-transit') >= 0 || status.indexOf('transit') >= 0 || status.indexOf('pre_transit') >= 0 || status.indexOf('pre-transit') >= 0 || status.indexOf('out_for_delivery') >= 0) { qtyClass = ' eao-ship-intransit'; colClass = 'eao-shipcol-intransit'; }
                        else if (status.indexOf('on_hold') >= 0 || status.indexOf('available_for_pickup') >= 0 || status.indexOf('shipped') >= 0 || status.indexOf('partial') >= 0) { qtyClass = ' eao-ship-hold'; colClass = 'eao-shipcol-amber'; }
                        else if (
                            status.indexOf('exception') >= 0 || status.indexOf('failure') >= 0 || status.indexOf('return_to_sender') >= 0 ||
                            status.indexOf('invalid_tracking') >= 0 || status.indexOf('invalid_carrier') >= 0 || status.indexOf('carrier_unsupported') >= 0 ||
                            status.indexOf('label_cancelled') >= 0 || status.indexOf('expired') >= 0 || status.indexOf('connection_issue') >= 0 || status.indexOf('insufficient_balance') >= 0
                        ) { qtyClass = ' eao-ship-exception'; colClass = 'eao-shipcol-exception'; }
                        else if (status.indexOf('pending_trackship') >= 0 || status.indexOf('pending') >= 0 || status.indexOf('unknown') >= 0) { qtyClass = ' eao-ship-pending'; colClass = 'eao-shipcol-pending'; }
                    }
                    try { console.log('[EAO Shipments][build]', { index: i, tracking: col.tracking_number || '', status, cssClass: colClass }); } catch(_) {}
                } catch(_) {}
                cols.push({
                    title: String(i),
                    field: 'ship_' + i,
                    headerSort: false,
                    hozAlign: 'center',
                    visible: true,
                    cssClass: colClass + ' eao-shipcol-bg',
                    titleFormatter: function(cell){ return '<div class="eao-shipcol-title ' + colClass + '">' + i + '</div>'; },
                    formatter: function(cell){
                        const v = cell.getValue();
                        return `<span class="eao-shipment-qty${qtyClass}">${v}</span>`;
                    },
                    width: 40,
                    minWidth: 36
                });
            }
            return cols;
        },

        _getShipmentFieldNames: function() {
            const sm = (window.eaoShipmentsMeta || {});
            const num = parseInt(sm.num_shipments, 10) || 0;
            const names = [];
            for (let i = 1; i <= num; i++) names.push('ship_' + i);
            return names;
        },

        _installResizeReleasePatch: function() {
            const container = this._container;
            if (!container) return;
            // 1) Force-release on any click of the resize handle
            container.addEventListener('click', function(e) {
                if (e.target && e.target.classList && e.target.classList.contains('tabulator-col-resize-handle')) {
                    try { document.dispatchEvent(new MouseEvent('mouseup', {bubbles:true,cancelable:true})); } catch(err) {}
                }
            }, true);
            // 2) Global mouseup anywhere should end any drag
            document.addEventListener('mouseup', function() {
                try { const evt = new Event('tabulator-force-resize-release'); container.dispatchEvent(evt); } catch(err) {}
            }, true);
            // 3) Escape key also cancels drag
            document.addEventListener('keydown', function(e){
                if (e.key === 'Escape') {
                    try { document.dispatchEvent(new MouseEvent('mouseup', {bubbles:true,cancelable:true})); } catch(err) {}
                }
            }, true);
        },

        _mapItems: function(items) {
            const sm = (window.eaoShipmentsMeta || {});
            const num = parseInt(sm.num_shipments, 10) || 0;
            const map = [];
            (items || []).forEach((it) => {
                const pid = parseInt(it.product_id, 10) || 0;
                const itemId = it.item_id || '';
                // Stable client-side id for unsaved items so Tabulator rows do not recycle on re-render
                const clientId = itemId || it._client_uid || (it._client_uid = ('new_' + pid + '_' + Date.now() + '_' + Math.random().toString(36).slice(2,6)));
                const globalDisc = parseFloat(window.globalOrderDiscountPercent || 0) || 0;
                const priceRaw = parseFloat(it.price_raw || 0) || 0;
                // Effective unit price: if excluded, use fixed/price_raw; otherwise apply global discount
                const effectiveUnit = (it.exclude_gd)
                    ? (typeof it.discounted_price_fixed === 'number' ? it.discounted_price_fixed : priceRaw)
                    : (priceRaw * (1 - (globalDisc / 100)));
                const row = {
                    id: String(clientId),
                    _pendingDeletion: !!it.isPendingDeletion,
                    _isPending: !!it.isPending,
                    _isExisting: !!it.item_id,
                    thumb: `<div class="eao-item-thumb"><img src="${it.thumbnail_url || eaoEditorParams.placeholder_image_url}" alt=""></div>`,
                    product: this._renderProductBlock(it),
                    qty: this._renderQty(it),
                    // Use legacy data attribute names that our existing handlers expect
                    points: `<div class=\"eao-item-points\"><div class=\"eao-points-display\">${(it.points_earning||0)}</div><button type=\"button\" class=\"button eao-points-details-btn eao-icon-button-info\" title=\"Info\" data-item_id=\"${itemId}\" data_product_id=\"${pid}\"><span class=\"dashicons dashicons-info\"></span></button></div>`,
                    cost: (() => {
                        const num = (typeof it.cost_price === 'number') ? it.cost_price : (parseFloat(it.cost_price) || 0);
                        const disabled = (!num || num <= 0) ? 'disabled' : '';
                        const icon = '<span class="dashicons dashicons-arrow-right-alt2"></span>';
                        return `<span class=\"eao-cost-display\">${eaoFormatPrice(num)}</span>`
                             + ` <button type=\"button\" class=\"button eao-apply-cost-btn eao-apply-cost-icon eao-apply-right\" title=\"Apply cost to Disc. Price\" data-product_id=\"${pid}\" data-item_id=\"${itemId}\" ${disabled}>${icon}</button>`;
                    })(),
                    price: `<span class="eao-price-display">${eaoFormatPrice(priceRaw)}</span>`,
                    exclude: this._renderExclude(it),
                    discount: this._renderDiscount(it),
                    disc_price: this._renderDiscPrice(it, globalDisc),
                    total: `<span class="eao-line-total-display">${eaoFormatPrice(effectiveUnit * (parseInt(it.quantity,10)||0))}</span>`,
                    actions: this._renderActions(it)
                };
                for (let i = 1; i <= num; i++) {
                    let q = 0;
                    if (sm.product_qty_map && sm.product_qty_map[pid] && typeof sm.product_qty_map[pid][i] !== 'undefined') q = sm.product_qty_map[pid][i];
                    row['ship_' + i] = `<span class="eao-shipment-qty">${q}</span>`;
                }
                map.push(row);
            });
            return map;
        },

        _renderProductBlock: function(it) {
            const url = it.product_permalink ? it.product_permalink : '#';
            const meta = it.formatted_meta_data ? `<div class="eao-item-variation-meta">${it.formatted_meta_data}</div>` : '';
            const sku = it.sku ? `<span class="eao-item-sku">SKU: ${it.sku}</span>` : '';
            const manages = it.manages_stock; const stock = it.stock_quantity;
            const isManaged = (String(manages) === 'true' || manages === true || manages === 1 || String(manages) === '1' || manages === 'yes');
            let stockHtml = '';
            if (isManaged && (stock !== null && typeof stock !== 'undefined')) {
                const s = parseInt(stock, 10);
                const q = parseInt(it.quantity || 0, 10) || 0;
                let cls = 'eao-stock-sufficient';
                if (isNaN(s)) {
                    cls = 'eao-stock-sufficient';
                } else if (s <= 0 && q > 0) {
                    cls = 'eao-stock-insufficient';
                } else if (q > s) {
                    cls = 'eao-stock-insufficient';
                } else if (q > 0 && s === q) {
                    cls = 'eao-stock-exact';
                }
                stockHtml = `<span class="eao-item-stock-indicator ${cls}">Stock: ${isNaN(s) ? 0 : s}</span>`;
            }
            return `<div class="eao-item-content">
                <div class="eao-product-content-row">
                    <div class="eao-item-info">
                        <div class="eao-item-title"><a href="${url}" target="_blank" rel="noopener">${it.name}</a></div>
                        <div class="eao-item-meta">${sku}${meta}</div>
                    </div>
                    <div class="eao-product-stock">${stockHtml}</div>
                </div>
            </div>`;
        },

        _renderQty: function(it) {
            const pid = it.product_id; const itemId = it.item_id || '';
            const manages = it.manages_stock; const stock = it.stock_quantity;
            const nameAttr = itemId ? `item_quantity[${itemId}]` : `new_item_quantity[${pid}]`;
            const isManaged = (String(manages) === 'true' || manages === true || manages === 1 || String(manages) === '1' || manages === 'yes');
            // Initial background class based on quantity vs stock
            let qtyCls = '';
            try {
                if (isManaged && stock !== null && typeof stock !== 'undefined' && stock !== 'null') {
                    const s = parseInt(stock, 10);
                    const q = parseInt(it.quantity || 0, 10) || 0;
                    if (!isNaN(s)) {
                        if ((s === 0 && q > 0) || (q > s)) { qtyCls = ' eao-qty-exceeds-stock'; }
                        else if (q === s && s > 0) { qtyCls = ' eao-qty-exact-stock'; }
                        else if (q < s && s > 0) { qtyCls = ' eao-qty-sufficient-stock'; }
                    }
                }
            } catch(_) {}
            const hint = '';
            return `<div class="eao-product-editor-item eao-item-quantity" data-product_id="${pid}" data-item_id="${itemId}" data-base_price="${parseFloat(it.price_raw||0)}">
                <div class="eao-input-align-wrapper eao-input-align-wrapper-center">
                    <input type="number" class="eao-quantity-input${qtyCls}" name="${nameAttr}" value="${parseInt(it.quantity,10)||0}" min="0" data-product_id="${pid}" data-item_id="${itemId}" data-manages_stock="${manages}" data-stock_quantity="${stock}">
                </div>
                ${hint}
            </div>`;
        },

        _renderExclude: function(it) {
            const pid = it.product_id; const itemId = it.item_id || '';
            const name = itemId ? `item_meta[${itemId}][_eao_exclude_global_discount]` : `new_item_meta[${pid}][_eao_exclude_global_discount]`;
            const checked = it.exclude_gd ? 'checked' : '';
            const disabled = it.isPendingDeletion ? 'disabled="disabled"' : '';
            return `<div class="eao-product-editor-item" data-product_id="${pid}" data-item_id="${itemId}" data-base_price="${parseFloat(it.price_raw||0)}"><input type="checkbox" class="eao-item-exclude-gd-cbx" name="${name}" data-product_id="${pid}" data-item_id="${itemId}" ${checked} ${disabled}></div>`;
        },

        _renderDiscount: function(it) {
            const pid = it.product_id; const itemId = it.item_id || '';
            const name = itemId ? `item_discount_percent[${itemId}]` : `new_item_discount_percent[${pid}]`;
            const disabled = (it.exclude_gd ? '' : 'disabled="disabled"') + (it.isPendingDeletion ? ' disabled="disabled"' : '');
            let percentVal = 0;
            if (it.exclude_gd) {
                // Robust: accept numeric string or number from loader
                if (typeof it.discount_percent === 'number') {
                    percentVal = it.discount_percent;
                } else if (typeof it.discount_percent === 'string' && it.discount_percent.trim() !== '') {
                    const pv = parseFloat(it.discount_percent);
                    percentVal = isNaN(pv) ? 0 : pv;
                } else {
                    percentVal = 0;
                }
            } else {
                percentVal = parseFloat(window.globalOrderDiscountPercent || 0) || 0;
            }
            const val = percentVal.toFixed(1);
            return `<div class="eao-product-editor-item eao-item-discount" data-product_id="${pid}" data-item_id="${itemId}" data-base_price="${parseFloat(it.price_raw||0)}"><div class="eao-discount-controls"><input type="number" class="eao-item-discount-percent-input eao-discount-percent-input" name="${name}" value="${val}" min="0" max="100" step="0.1" data-product_id="${pid}" data-item_id="${itemId}" ${disabled}><span class="eao-percentage-symbol">%</span></div></div>`;
        },

        _renderDiscPrice: function(it, globalDisc) {
            const pid = it.product_id; const itemId = it.item_id || '';
            const name = itemId ? `item_discounted_price[${itemId}]` : `new_item_discounted_price[${pid}]`;
            const disabled = (it.exclude_gd ? '' : 'disabled="disabled"') + (it.isPendingDeletion ? ' disabled="disabled"' : '');
            const priceRaw = parseFloat(it.price_raw || 0) || 0;
            const base = it.exclude_gd ? (parseFloat(it.discounted_price_fixed || priceRaw) || 0) : (priceRaw * (1 - ((parseFloat(globalDisc)||0)/100)));
            return `<div class="eao-product-editor-item eao-item-discounted-price" data-product_id="${pid}" data-item_id="${itemId}" data-base_price="${parseFloat(it.price_raw||0)}"><div class="eao-input-align-wrapper eao-input-align-wrapper-right"><input type="number" class="eao-item-discounted-price-input" name="${name}" value="${base.toFixed(parseInt(eaoEditorParams.price_decimals||2,10))}" min="0" step="0.01" data-product_id="${pid}" data-item_id="${itemId}" ${disabled}></div></div>`;
        },

        _renderActions: function(it) {
            const pid = it.product_id; const itemId = it.item_id || '';
            if (it.isPendingDeletion) {
                return `<div class="eao-product-editor-item eao-item-actions" data-product_id="${pid}" data-item_id="${itemId}"><button type="button" class="button button-link-undo eao-remove-item-button eao-undo-delete-button" data-product_id="${pid}" data-item_id="${itemId}"><span class="dashicons dashicons-undo"></span></button></div>`;
            }
            return `<div class="eao-product-editor-item eao-item-actions" data-product_id="${pid}" data-item_id="${itemId}"><button type="button" class="button button-link-delete eao-remove-item-button" data-product_id="${pid}" data-item_id="${itemId}"><span class="dashicons dashicons-trash"></span></button></div>`;
        }
    };

    window.EAO.ProductsTable = ProductsTable;
})(window, jQuery);
