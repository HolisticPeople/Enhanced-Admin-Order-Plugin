/**
 * Enhanced Admin Order - Products Helpers (Pure business logic)
 * Version: 1.0.0 - Initial helpers extraction
 * Author: Amnon Manneberg
 *
 * IMPORTANT: No DOM/Tabulator calls here. These helpers only mutate the item model
 * and return a lightweight "changes" descriptor that UI layers may use.
 */
(function(window){
  'use strict';

  if (!window.EAO) { window.EAO = {}; }
  const ns = window.EAO.ProductsHelpers = {};

  function toNumber(n){ var v = parseFloat(n); return isNaN(v) ? 0 : v; }
  function toInt(n){ var v = parseInt(n, 10); return isNaN(v) ? 0 : v; }

  function computeDiscountedFromPercent(base, percent){
    base = toNumber(base); percent = toNumber(percent);
    percent = Math.max(0, Math.min(100, percent));
    return Math.max(0, base * (1 - (percent / 100)));
  }

  // Best-effort WC-compatible totals using legacy calc if available
  function recalcWCTotals(item){
    try {
      if (window.EAO && window.EAO.Products && typeof window.EAO.Products.calculateItemTotalsInWCFormat === 'function') {
        return window.EAO.Products.calculateItemTotalsInWCFormat(item);
      }
    } catch(_) {}
    // Fallback minimal calculation
    const qty = toInt(item.quantity);
    const base = toNumber(item.price_raw);
    let unit;
    if (item.exclude_gd) {
      if (item.is_markup && typeof item.discounted_price_fixed === 'number') {
        unit = toNumber(item.discounted_price_fixed);
      } else if (typeof item.discounted_price_fixed === 'number') {
        unit = toNumber(item.discounted_price_fixed);
      } else {
        unit = base;
      }
    } else {
      const gd = toNumber(window.globalOrderDiscountPercent || 0);
      unit = base * (1 - (gd / 100));
    }
    const lineSubtotal = base * qty;
    const lineTotal = unit * qty;
    const lineDiscount = Math.max(0, lineSubtotal - lineTotal);
    return {
      _line_subtotal: lineSubtotal,
      _line_total: lineTotal,
      _line_discount: lineDiscount
    };
  }

  function recalcWCStaging(item){
    try {
      if (window.EAO && window.EAO.Utils && typeof window.EAO.Utils.updateItemWCStagingFields === 'function') {
        const gd = toNumber(window.globalOrderDiscountPercent || 0);
        window.EAO.Utils.updateItemWCStagingFields(item, gd);
      }
    } catch(_) {}
    const wc = recalcWCTotals(item);
    item._line_subtotal_staging = wc._line_subtotal;
    item._line_total_staging = wc._line_total;
    item._line_discount_staging = wc._line_discount;
    item.wc_calculated_total = wc._line_total;
  }

  ns.applyQuantityChange = function(item, newQty){
    item.quantity = toInt(newQty);
    recalcWCStaging(item);
    return { quantity: item.quantity };
  };

  ns.applyExcludeChange = function(item, isExcluded, globalDiscount){
    const base = toNumber(item.price_raw);
    item.exclude_gd = !!isExcluded;
    if (item.exclude_gd) {
      item.discount_percent = 0;
      item.discounted_price_fixed = base;
      item.is_markup = false;
    } else {
      const gd = toNumber(globalDiscount);
      item.discount_percent = gd;
      item.discounted_price_fixed = computeDiscountedFromPercent(base, gd);
      item.is_markup = false;
    }
    recalcWCStaging(item);
    return {
      exclude_gd: item.exclude_gd,
      discount_percent: item.discount_percent,
      discounted_price_fixed: item.discounted_price_fixed,
      is_markup: !!item.is_markup
    };
  };

  ns.applyDiscountPercent = function(item, percent){
    const base = toNumber(item.price_raw);
    percent = toNumber(percent);
    if (!item.exclude_gd) { // percent only applies when excluded
      return { ignored: true };
    }
    item.discount_percent = Math.max(0, Math.min(100, percent));
    item.discounted_price_fixed = computeDiscountedFromPercent(base, item.discount_percent);
    item.is_markup = false;
    recalcWCStaging(item);
    return { discount_percent: item.discount_percent, discounted_price_fixed: item.discounted_price_fixed, is_markup: false };
  };

  ns.applyDiscountedPrice = function(item, fixedPrice){
    const base = toNumber(item.price_raw);
    let fixed = toNumber(fixedPrice);
    fixed = Math.max(0, fixed);
    if (!item.exclude_gd) { // when not excluded, fixed price is derived from GD; ignore direct set
      return { ignored: true };
    }
    if (fixed > base) {
      // Markup mode
      item.is_markup = true;
      item.discount_percent = null;
      item.discounted_price_fixed = fixed;
    } else {
      item.is_markup = false;
      item.discounted_price_fixed = fixed;
      let percent = 0;
      if (base > 0) { percent = ((base - fixed) / base) * 100; }
      item.discount_percent = Math.max(0, Math.min(100, percent));
    }
    recalcWCStaging(item);
    return { is_markup: !!item.is_markup, discount_percent: item.discount_percent, discounted_price_fixed: item.discounted_price_fixed };
  };

  ns.applyDelete = function(item){
    if (item.item_id) {
      item.isPendingDeletion = true;
      return { pendingDeletion: true, removeRow: false };
    }
    return { pendingDeletion: false, removeRow: true };
  };

  ns.applyUndoDelete = function(item){
    item.isPendingDeletion = false;
    return { pendingDeletion: false };
  };

})(window);


