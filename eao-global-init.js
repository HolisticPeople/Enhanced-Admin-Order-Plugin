/**
 * Enhanced Admin Order - Global Initialization and Error Protection
 * 
 * This file MUST load first before all other EAO scripts.
 * It establishes the global namespace, initializes critical arrays,
 * and provides protection against third-party plugin errors.
 * 
 * @package EnhancedAdminOrder
 * @since 2.4.219
 * @version 2.7.67 - Disable debug flags by default after troubleshooting session
 * @author Amnon Manneberg
 */

(function($) {
    'use strict';

    // Create global EAO namespace if it doesn't exist
    if (typeof window.EAO === 'undefined') {
        window.EAO = {};
        console.log('[EAO Global Init] EAO namespace created');
    }

    // Initialize core arrays that modules depend on
    if (typeof window.stagedOrderItems === 'undefined') {
        window.stagedOrderItems = [];
    }
    if (typeof window.currentOrderItems === 'undefined') {
        window.currentOrderItems = [];
    }
    if (typeof window.itemsPendingDeletion === 'undefined') {
        window.itemsPendingDeletion = [];
    }
    if (typeof window.stagedNotes === 'undefined') {
        window.stagedNotes = [];
    }

    // Critical global variables
    if (typeof window.hasUnsavedChanges === 'undefined') {
        window.hasUnsavedChanges = false;
    }
    if (typeof window.isCurrentlySaving === 'undefined') {
        window.isCurrentlySaving = false;
    }

    console.log('[EAO Global Init] Core arrays and variables initialized');

    // Lightweight debounce helper (no external dep). Returns a wrapped function.
    if (!window.EAO.debounce) {
        window.EAO.debounce = function(fn, wait) {
            var timer = null;
            var w = (typeof wait === 'number' && wait >= 0) ? wait : 120;
            return function() {
                var ctx = this, args = arguments;
                if (timer) { clearTimeout(timer); }
                timer = setTimeout(function(){ timer = null; try { fn.apply(ctx, args); } catch(_){} }, w);
            };
        };
    }

    // === ERROR PROTECTION AGAINST THIRD-PARTY PLUGINS ===
    
    /**
     * Add protective error handling for common third-party plugin issues
     */
    function addErrorProtection() {
        console.log('[EAO Global Init] Setting up error protection against third-party plugins...');
        
        // 1. Protect against ELEX indexOf errors
        const originalAddEventListener = Element.prototype.addEventListener;
        Element.prototype.addEventListener = function(type, listener, options) {
            if (typeof listener === 'function') {
                const protectedListener = function(event) {
                    try {
                        return listener.call(this, event);
                    } catch (error) {
                        // Check if this is an ELEX-related error
                        if (error.message && 
                            (error.message.includes('indexOf') || 
                             error.message.includes('undefined')) &&
                            error.stack && 
                            (error.stack.includes('elex') || 
                             error.stack.includes('script.js?ver=2.0.0'))) {
                            
                            console.warn('[EAO Error Protection] Prevented third-party plugin error:', {
                                message: error.message,
                                source: 'Third-party plugin (likely ELEX)',
                                event: type,
                                target: this
                            });
                            
                            // Don't let the error propagate
                            return false;
                        }
                        // Re-throw if it's not a known third-party error
                        throw error;
                    }
                };
                // Mark wheel/touch listeners as passive when safe to reduce violations
                try {
                    const passiveTypes = ['wheel','mousewheel','touchstart','touchmove'];
                    if (passiveTypes.indexOf(String(type)) >= 0) {
                        const opts = (typeof options === 'object' && options) ? options : {};
                        if (typeof opts.passive === 'undefined') { opts.passive = true; }
                        return originalAddEventListener.call(this, type, protectedListener, opts);
                    }
                } catch(_) {}
                return originalAddEventListener.call(this, type, protectedListener, options);
            }
            return originalAddEventListener.call(this, type, listener, options);
        };
        
        // 2. Global error handler for uncaught third-party errors
        const originalErrorHandler = window.onerror;
        window.onerror = function(message, source, lineno, colno, error) {
            // Check if this is a third-party plugin error that we should suppress
            if (message && source && 
                (message.includes('indexOf') || message.includes('Cannot read properties of undefined')) &&
                (source.includes('script.js?ver=2.0.0') || 
                 source.includes('elex') ||
                 source.includes('eh-authorize-net'))) {
                
                console.warn('[EAO Error Protection] Suppressed third-party plugin error:', {
                    message: message,
                    source: source,
                    line: lineno,
                    column: colno
                });
                
                // Return true to prevent default error handling
                return true;
            }
            
            // Call original handler for other errors
            if (originalErrorHandler) {
                return originalErrorHandler.call(this, message, source, lineno, colno, error);
            }
            
            return false;
        };
        
        // 3. Unhandled promise rejection protection
        const originalUnhandledRejection = window.onunhandledpromiserejection;
        window.onunhandledpromiserejection = function(event) {
            if (event.reason && event.reason.message && 
                (event.reason.message.includes('indexOf') || 
                 event.reason.message.includes('undefined')) &&
                event.reason.stack &&
                (event.reason.stack.includes('elex') || 
                 event.reason.stack.includes('script.js?ver=2.0.0'))) {
                
                console.warn('[EAO Error Protection] Prevented unhandled promise rejection from third-party plugin:', event.reason);
                event.preventDefault();
                return;
            }
            
            if (originalUnhandledRejection) {
                return originalUnhandledRejection.call(this, event);
            }
        };
        
        console.log('[EAO Global Init] Error protection systems activated');
    }

    // === JQUERY PROTECTION ===
    
    /**
     * Ensure jQuery is properly scoped for all EAO modules
     */
    function ensureJQueryAccess() {
        // Ensure $ is available globally for EAO modules
        if (typeof window.$ === 'undefined' && typeof jQuery !== 'undefined') {
            window.$ = jQuery;
        }
        
        // Create protected jQuery reference for EAO modules
        window.EAO.$ = jQuery;
        
        console.log('[EAO Global Init] jQuery protection established');
    }

    // Quiet recurring jQuery Deferred exception (third-party causes). Keep normal errors.
    function suppressJQueryDeferredNoise() {
        try {
            if (window.jQuery && jQuery.Deferred && jQuery.Deferred.exceptionHook) {
                var __eao_orig_exceptionHook = jQuery.Deferred.exceptionHook;
                var detectPluginFromStack = function(err, st){
                    try {
                        var s = '' + (st || (err && err.stack) || '');
                        var m = s.match(/\/plugins\/([^\/]+)\//i);
                        if (m && m[1]) { return m[1]; }
                        if (/jquery-migrate/i.test(s)) { return 'jquery-migrate (core)'; }
                        if (/wp-admin|wp-includes/i.test(s)) { return 'wordpress-core'; }
                    } catch(_) {}
                    return 'unknown';
                };
                jQuery.Deferred.exceptionHook = function(error, stack) {
                    // Production default: swallow migrate Deferred exceptions entirely unless debug is enabled
                    if (!window.eaoDebug) { return; }
                    try {
                        var src = detectPluginFromStack(error, stack);
                        var url = '';
                        try { url = (String(stack||'').match(/https?:[^\s)]+/i)||[])[0] || ''; } catch(_) {}
                        console.info('[EAO Suppressed Deferred] source=' + src + (url? (' url=' + url) : ''));
                    } catch(_) {}
                    try { return __eao_orig_exceptionHook.apply(this, arguments); } catch(__) { }
                };
                // Mute migrate if present
                try { if (typeof jQuery.migrateMute !== 'undefined') { jQuery.migrateMute = true; } } catch(_) {}
            }
        } catch(_) {}
    }

    // Install hook ASAP and retry a few times in case jQuery/migrate attach later
    try { suppressJQueryDeferredNoise(); } catch(_) {}
    try {
        var __eao_hook_retries = 0;
        (function retryHook(){
            __eao_hook_retries++;
            try { suppressJQueryDeferredNoise(); } catch(_) {}
            if (__eao_hook_retries < 5) { setTimeout(retryHook, 50); }
        })();
    } catch(_) {}

    // Default: disable debug logs (can be toggled via console at runtime)
    try {
        if (typeof window.eaoDebug === 'undefined') { window.eaoDebug = false; }
        if (typeof window.eaoDebugShipments === 'undefined') { window.eaoDebugShipments = false; }
        if (typeof window.eaoDebugSS === 'undefined') { window.eaoDebugSS = false; }
        if (typeof window.eaoDebugFluent === 'undefined') { window.eaoDebugFluent = false; }
        if (typeof window.eaoDebugProgress === 'undefined') { window.eaoDebugProgress = false; }
    } catch(_) {}

    // Central console.log wrapper for EAO logs (suppress unless flag enabled)
    try {
        if (!window._EAO_WRAP_LOG) {
            window._EAO_WRAP_LOG = true;
            var __eao_orig_log = (typeof console !== 'undefined' && console.log) ? console.log.bind(console) : function(){};
            var __eao_orig_warn = (typeof console !== 'undefined' && console.warn) ? console.warn.bind(console) : function(){};
            var __eao_orig_err = (typeof console !== 'undefined' && console.error) ? console.error.bind(console) : function(){};
            console.log = function(){
                try {
                    var first = arguments && arguments[0];
                    if (first && typeof first === 'string' && first.indexOf('[EAO') === 0) {
                        var isGatewayLog = first.indexOf('[EAO Gateway]') === 0;
                        if (isGatewayLog) {
                            if (!window.eaoDebugGateway) { return; }
                        } else {
                            // Fine-grained filters
                            if (first.indexOf('[EAO Shipments]') === 0 && !window.eaoDebugShipments) { return; }
                            if ((first.indexOf('[EAO ShipStation') === 0 || first.indexOf('[EAO SS') === 0) && !window.eaoDebugSS) { return; }
                            if ((first.indexOf('[EAO Fluent') === 0 || first.indexOf('[EAO FluentCRM') === 0 || first.indexOf('[EAO Fluent Support') === 0) && !window.eaoDebugFluent) { return; }
                            if ((first.indexOf('[EAO Progress') === 0) && !window.eaoDebugProgress) { return; }
                            if (!window.eaoDebug && !window.eaoDebugProgress) { return; }
                        }
                        // Auto-prefix timestamp for all EAO logs when any debug is enabled
                        try {
                            var nowTs = Date.now();
                            var iso = new Date(nowTs).toISOString();
                            var hhmmss = iso.slice(11,19) + '.' + String(nowTs % 1000).padStart(3,'0');
                            var dlt = (typeof window.eaoProgressStartTs === 'number') ? (' +' + (nowTs - window.eaoProgressStartTs) + 'ms') : '';
                            arguments[0] = '[t ' + hhmmss + dlt + '] ' + arguments[0];
                        } catch(__) {}
                        // If this is a progress hint, also show current numeric percent if available
                        try {
                            if (first.indexOf('[EAO Progress Hint]') === 0) {
                                // Already includes percentage
                            }
                        } catch(__) {}
                    } else if (first && typeof first === 'string') {
                        // Legacy debug prefixes
        if ((first.indexOf('EAO DEBUG:') === 0 || first.indexOf('[PHASE 1 DEBUG]') === 0) && !window.eaoDebug) { return; }
                    }
                } catch(_) {}
                return __eao_orig_log.apply(console, arguments);
            };
            console.warn = function(){
                try {
                    var first = arguments && arguments[0];
                    if (first && typeof first === 'string') {
                        if (first.indexOf('[EAO Gateway]') === 0) {
                            if (!window.eaoDebugGateway) { return; }
                        } else {
                            if (first.indexOf('JQMIGRATE:') === 0) { return; }
                            if (first.indexOf('[EAO') === 0 && !window.eaoDebug) { return; }
                            if (first.indexOf('EAO DEBUG:') === 0 && !window.eaoDebug) { return; }
                        }
                    }
                } catch(_) {}
                return __eao_orig_warn.apply(console, arguments);
            };
            console.error = function(){
                try {
                    var first = arguments && arguments[0];
                    if (first && typeof first === 'string') {
                        if (first.indexOf('[EAO Gateway]') === 0) {
                            if (!window.eaoDebugGateway) { return; }
                        } else {
                            if (first.indexOf('[EAO') === 0 && !window.eaoDebug) { return; }
                        }
                        var low = first.toLowerCase();
                        if (low.indexOf('jquery.deferred exception') === 0) {
                            // Swallow common third-party Deferred exceptions entirely
                            return;
                        }
                        if (low.indexOf("reading 'length'") >= 0 || low.indexOf('reading "length"') >= 0) { return; }
                        if (low.indexOf('invalid regular expression') >= 0) { return; }
                    }
                    // Also inspect other args for Error-like objects or migrate traces
                    for (var i=0;i<arguments.length;i++) {
                        var a = arguments[i];
                        if (!a) continue;
                        try {
                            if (a instanceof Error) {
                                var msg2 = String(a && a.message || '');
                                var low2 = msg2.toLowerCase();
                                if (low2.indexOf("reading 'length'") >= 0 || low2.indexOf('reading "length"') >= 0) { return; }
                            }
                            var s = String(a);
                            if (s.indexOf('jquery-migrate') >= 0 && s.toLowerCase().indexOf('deferred') >= 0) { return; }
                        } catch(_) {}
                    }
                } catch(_) {}
                return __eao_orig_err.apply(console, arguments);
            };
        }
    } catch(_) {}

    // Initialize when DOM is ready
    $(document).ready(function() {
        addErrorProtection();
        ensureJQueryAccess();
        suppressJQueryDeferredNoise();
        
        console.log('[EAO Global Init] v2.7.64 - Initialization complete with error protection');
        try {
            // Default: collapse AST/TrackShip metabox and place it at bottom by priority (already low); force collapsed UI
            var $ast = jQuery('#woocommerce-advanced-shipment-tracking, #trackship');
            if ($ast && $ast.length) {
                $ast.addClass('closed');
                $ast.find('.handlediv').attr('aria-expanded','false');
                $ast.find('.postbox-header').attr('aria-expanded','false');
            }
        } catch(_) {}
    });

    // Debug function for staged items (legacy compatibility)
    window.eaoDebugStagedItems = function() {
        console.log('[EAO Debug] Current staged items:', {
            stagedOrderItems: window.stagedOrderItems,
            currentOrderItems: window.currentOrderItems,
            itemsPendingDeletion: window.itemsPendingDeletion,
            stagedNotes: window.stagedNotes,
            hasUnsavedChanges: window.hasUnsavedChanges,
            isCurrentlySaving: window.isCurrentlySaving
        });
    };

    // Broader onerror suppression for non-EAO sources of common noisy errors
    try {
        var __eao_prev_onerror = window.onerror;
        window.onerror = function(message, source, lineno, colno, error) {
            try {
                var msg = String(message||''); var src = String(source||'');
                var isEAO = /enhanced-admin-order-plugin/i.test(src);
                if (!isEAO && (msg.indexOf('Cannot read properties of undefined (reading\'length\')') >= 0 || msg.indexOf("Cannot read properties of undefined (reading 'length')") >= 0)) {
                    // Suppress third-party noise
                    if (window.eaoDebug) {
                        console.warn('[EAO Error Protection] Suppressed non-EAO length error', { message: msg, source: src, line: lineno, col: colno });
                    }
                    return true;
                }
                if (!isEAO && msg.toLowerCase().indexOf('invalid regular expression') >= 0) {
                    // Suppress noisy regex errors from third-party admin scripts
                    if (window.eaoDebug) {
                        console.warn('[EAO Error Protection] Suppressed non-EAO invalid RegExp error', { message: msg, source: src, line: lineno, col: colno });
                    }
                    return true;
                }
            } catch(_) {}
            if (typeof __eao_prev_onerror === 'function') { return __eao_prev_onerror.apply(this, arguments); }
            return false;
        };
    } catch(_) {}

})(jQuery); 