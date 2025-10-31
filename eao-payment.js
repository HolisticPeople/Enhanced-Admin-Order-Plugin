(function($){
    'use strict';
    // Load Stripe.js dynamically if not present
    function loadStripeJs(cb){
        if (window.Stripe) { cb(); return; }
        var s = document.createElement('script');
        s.src = 'https://js.stripe.com/v3/';
        s.onload = cb; document.head.appendChild(s);
    }
    $(document).ready(function(){
        var $container = $('#eao-payment-processing-container');
        if (!$container.length) return;
        var $gatewaySummary = $("#eao-refunds-gateway-summary");
        var $gatewayLabel = $("#eao-refunds-gateway-label-text");
        var gatewayState = null;

        function clearGatewaySummary(){
            if (!$gatewaySummary.length) { return; }
            if ($gatewayLabel.length) { $gatewayLabel.text(''); }
            $gatewaySummary.hide();
            gatewayState = null;
        }

        function renderGatewayState(){
            if (!$gatewaySummary.length) { return; }
            if (!gatewayState || !gatewayState.label) {
                clearGatewaySummary();
                return;
            }
            if ($gatewayLabel.length) { $gatewayLabel.text(gatewayState.label); }
            $gatewaySummary.show();
        }

        function applyGatewaySummary(gw){
            if (!$gatewaySummary.length) { return; }
            if (!gw || (!gw.label && !gw.message && !gw.id)) {
                renderGatewayState();
                return;
            }
            var label = (gw.label || '').trim();
            if (!label && gw.id) {
                label = String(gw.id).replace(/_/g, ' ').replace(/\b\w/g, function(ch){ return ch.toUpperCase(); });
            }
            if (!label && gw.message) {
                label = String(gw.message).trim();
            }
            if (label) {
                var prefix = 'gateway for this order:';
                var lower = label.toLowerCase();
                if (lower.indexOf(prefix) === 0) {
                    label = label.substring(prefix.length).trim();
                    var dot = label.indexOf('.');
                    if (dot > -1) {
                        label = label.substring(0, dot).trim();
                    }
                }
            }
            if (!label) {
                clearGatewaySummary();
                return;
            }
            gatewayState = { label: label };
            renderGatewayState();
        }

        var initialGatewayState = {
            label: $gatewayLabel.length ? $.trim($gatewayLabel.text()) : ''
        };
        if (initialGatewayState.label) {
            gatewayState = initialGatewayState;
            renderGatewayState();
        }

        $('#eao-pp-gateway-settings').on('click', function(){
            $('#eao-pp-settings-panel').slideToggle(150);
        });

        $('#eao-pp-save-stripe').on('click', function(){
            var $btn = $(this);
            var $msg = $('#eao-pp-messages');
            $btn.prop('disabled', true).text('Saving...');
            $.post((window.eao_ajax && eao_ajax.ajax_url) || window.ajaxurl, {
                action: 'eao_payment_stripe_save_settings',
                nonce: $('#eao_payment_mockup_nonce').val(),
                test_secret: $('#eao-pp-stripe-test-secret').val(),
                test_publishable: $('#eao-pp-stripe-test-publishable').val(),
                live_secret: $('#eao-pp-stripe-live-secret').val(),
                live_publishable: $('#eao-pp-stripe-live-publishable').val()
            }, function(resp){
                if (resp && resp.success) {
                    $msg.html('<div class="notice notice-success"><p>Stripe keys saved.</p></div>');
                } else {
                    $msg.html('<div class="notice notice-error"><p>' + (resp && resp.data && resp.data.message ? resp.data.message : 'Error saving') + '</p></div>');
                }
            }, 'json').always(function(){
                $btn.prop('disabled', false).text('Save Stripe Keys');
            });
        });

        $('#eao-pp-save-paypal').on('click', function(){
            var $btn = $(this);
            var $msg = $('#eao-pp-messages');
            $btn.prop('disabled', true).text('Saving...');
            $.post((window.eao_ajax && eao_ajax.ajax_url) || window.ajaxurl, {
                action: 'eao_payment_paypal_save_settings',
                nonce: $('#eao_payment_mockup_nonce').val(),
                live_client_id: $('#eao-pp-paypal-live-client-id').val(),
                live_secret: $('#eao-pp-paypal-live-secret').val(),
                webhook_id_live: $('#eao-pp-paypal-webhook-id').val()
            }, function(resp){
                if (resp && resp.success) {
                    $msg.html('<div class="notice notice-success"><p>PayPal settings saved.</p></div>');
                } else {
                    $msg.html('<div class="notice notice-error"><p>' + (resp && resp.data && resp.data.message ? resp.data.message : 'Error saving') + '</p></div>');
                }
            }, 'json').always(function(){
                $btn.prop('disabled', false).text('Save PayPal Settings');
            });
        });

        $('#eao-pp-save-webhooks').on('click', function(){
            var $btn = $(this);
            var $msg = $('#eao-pp-messages');
            $btn.prop('disabled', true).text('Saving...');
            $.post((window.eao_ajax && eao_ajax.ajax_url) || window.ajaxurl, {
                action: 'eao_payment_webhooks_save_settings',
                nonce: $('#eao_payment_mockup_nonce').val(),
                stripe_webhook_secret: $('#eao-pp-stripe-webhook-secret').val(),
                paypal_webhook_id_live: $('#eao-pp-paypal-webhook-id').val()
            }, function(resp){
                if (resp && resp.success) {
                    $msg.html('<div class="notice notice-success"><p>Webhook settings saved.</p></div>');
                } else {
                    $msg.html('<div class="notice notice-error"><p>' + (resp && resp.data && resp.data.message ? resp.data.message : 'Error saving') + '</p></div>');
                }
            }, 'json').always(function(){
                $btn.prop('disabled', false).text('Save Webhook Settings');
            });
        });

        var stripeInstance = null;
        var elementsInstance = null;
        var cardElement = null;

        function mountElements(publishableKey){
            if (!window.Stripe) return;
            // Ensure we use the correct Stripe instance and Elements
            if (!stripeInstance || stripeInstance._apiKey !== publishableKey) {
                stripeInstance = window.Stripe(publishableKey);
                stripeInstance._apiKey = publishableKey;
                elementsInstance = stripeInstance.elements();
            } else if (!elementsInstance) {
                elementsInstance = stripeInstance.elements();
            }
            // Always start from a fresh card Element to keep it interactive
            try { if (cardElement) { cardElement.destroy(); } } catch(_){ }
            cardElement = elementsInstance.create('card', {hidePostalCode: true});
            cardElement.mount('#eao-pp-card-element');
            cardElement.on('change', function(event){
                var $err = $('#eao-pp-card-errors');
                if (event.error) { $err.text(event.error.message); } else { $err.text(''); }
            });
        }

        function unmountElements(){
            try { if (cardElement) { cardElement.destroy(); } } catch(_){ }
            cardElement = null;
        }

        function toggleCardForm(){
            var gw = $('#eao-pp-gateway').val();
            if (gw === 'stripe_test' || gw === 'stripe_live') {
                $('#eao-pp-card-form').slideDown(100);
                if (gw === 'stripe_test') {
                    // Show manual inputs for test mode, hide Elements row
                    $('#eao-pp-card-element-row').hide();
                    $('#eao-pp-row-card-number, #eao-pp-row-expiry, #eao-pp-row-cvv').show();
                    unmountElements();
                    if (!$('#eao-pp-card-number').val()) $('#eao-pp-card-number').val('4242 4242 4242 4242');
                    if (!$('#eao-pp-cvv').val()) $('#eao-pp-cvv').val('123');
                    if (!$('#eao-pp-expiry').val()) {
                        var year = new Date().getFullYear()+2; var yy = String(year).slice(-2);
                        $('#eao-pp-expiry').val('12/'+yy);
                    }
                } else if (gw === 'stripe_live') {
                    // Live: use Stripe Elements instead of manual inputs (hide redundant fields)
                    $('#eao-pp-row-card-number, #eao-pp-row-expiry, #eao-pp-row-cvv').hide();
                    $('#eao-pp-card-element-row').show();
                    // Mount immediately using saved publishable key if available, so field is functional
                    loadStripeJs(function(){
                        var pub = $('#eao-pp-stripe-live-publishable').val();
                        if (pub) { mountElements(pub); }
                    });
                }
            } else {
                $('#eao-pp-card-form').slideUp(100);
            }
        }
        toggleCardForm();
        $('#eao-pp-gateway').on('change', toggleCardForm);

        // Manual copy action â€“ always correct
        $('#eao-pp-copy-gt').on('click', function(){
            try {
                var s = window.currentOrderSummaryData || {};
                var amt = (typeof s.grand_total !== 'undefined') ? parseFloat(s.grand_total) : parseFloat(s.grand_total_raw);
                if (isNaN(amt)) {
                    // DOM fallback
                    var txt = $('.eao-grand-total-line .eao-summary-monetary-value, .eao-grand-total-value, #eao-grand-total-value, .grand-total, [data-field="grand_total"]').first().text() || '';
                    var num = parseFloat(String(txt).replace(/[^0-9.\-]/g,''));
                    if (!isNaN(num)) { amt = num; }
                }
                if (!isNaN(amt)) { $('#eao-pp-amount').val(amt.toFixed(2)); }
                // Update request button labels if available
                try { if (typeof eaoUpdateRequestButtons === 'function') { eaoUpdateRequestButtons(); } } catch(_){ }
            } catch(_){ }
        });

        function clearPaymentForm(){
            try { if (cardElement && typeof cardElement.clear === 'function') { cardElement.clear(); } } catch(_){ }
            try { $('#eao-pp-card-number').val(''); $('#eao-pp-cvv').val(''); $('#eao-pp-expiry').val(''); } catch(_){ }
        }

        $('#eao-pp-process').on('click', function(){
            var gateway = $('#eao-pp-gateway').val();
            var amount = parseFloat($('#eao-pp-amount').val() || '0');
            var orderId = $('#eao-pp-order-id').val();
            var changeStatus = $('#eao-pp-change-status').is(':checked');
            var $msg = $('#eao-pp-messages');

            function getGrandTotal(){
                try {
                    var s = window.currentOrderSummaryData || {};
                    var gt = (typeof s.grand_total !== 'undefined') ? parseFloat(s.grand_total) : parseFloat(s.grand_total_raw);
                    if (!isNaN(gt)) return gt;
                } catch(_){ }
                try {
                    var txt = $('.eao-grand-total-line .eao-summary-monetary-value, .eao-grand-total-value, #eao-grand-total-value, .grand-total, [data-field="grand_total"]').first().text() || '';
                    var num = parseFloat(String(txt).replace(/[^0-9.\-]/g,''));
                    if (!isNaN(num)) return num;
                } catch(_){ }
                return NaN;
            }

            function doProcess(withAmount){
                $msg.html('<div class="notice notice-info"><p>Processing...</p></div>');
                if (gateway === 'stripe_test' || gateway === 'stripe_live') {
                // Create intent server-side, then confirm with Stripe.js
                $.post((window.eao_ajax && eao_ajax.ajax_url) || window.ajaxurl, {
                    action: 'eao_payment_stripe_create_intent',
                    nonce: $('#eao_payment_mockup_nonce').val(),
                    order_id: orderId,
                    amount: withAmount,
                    gateway: gateway
                }, function(resp){
                    if (!resp || !resp.success) {
                        var err = (resp && resp.data && resp.data.message) ? resp.data.message : 'Failed to create PaymentIntent';
                        $msg.html('<div class="notice notice-error"><p>'+err+'</p></div>');
                        return;
                    }
                    loadStripeJs(function(){
                        var clientSecret = resp.data.client_secret;
                        var cardholderName = ($('#eao-pp-first-name').val()||'') + ' ' + ($('#eao-pp-last-name').val()||'');

                        function confirmWithPayload(payload){
                            return stripeInstance.confirmCardPayment(clientSecret, payload);
                        }

                        // For Live, use Elements; for Test, use a test token
                        var confirmPromise;
                        if (gateway === 'stripe_live') {
                            // Ensure Elements is mounted using the same publishable key
                            $('#eao-pp-card-element-row').show();
                            // If no Element yet, mount using the publishable key from the PI; otherwise keep existing to preserve input
                            if (!cardElement) {
                                mountElements(resp.data.publishable);
                            }
                            // Wait until Element is ready before confirming to avoid "retrieve data" errors
                            var payload = {
                                payment_method: {
                                    card: cardElement,
                                    billing_details: { name: cardholderName }
                                }
                            };
                            confirmPromise = new Promise(function(resolve){
                                var fired = false;
                                function go(){ if (fired) return; fired = true; confirmWithPayload(payload).then(resolve); }
                                try { if (cardElement && cardElement.on) { cardElement.on('ready', go); } } catch(_){ }
                                // Fallback in case ready doesn't fire (older versions)
                                setTimeout(go, 250);
                            });
                        } else {
                            // Ensure we use the same Stripe instance (create without mounting Elements)
                            stripeInstance = window.Stripe(resp.data.publishable);
                            confirmPromise = confirmWithPayload({
                                payment_method: {
                                    card: { token: 'tok_visa' },
                                    billing_details: { name: cardholderName }
                                }
                            });
                        }

                        confirmPromise.then(function(result){
                            if (result.error) {
                                $msg.html('<div class="notice notice-error"><p>'+result.error.message+'</p></div>');
                            } else if (result.paymentIntent) {
                                $msg.html('<div class="notice notice-success"><p>Success!</p></div>');
                                // Persist amount used for note when server summarizes to 0
                                $('#eao-pp-amount').data('last-paid-amount', (parseFloat(resp.data.amount_cents||0)/100).toFixed(2));
                                // Clear sensitive inputs after a successful charge
                                clearPaymentForm();
                                // If status checkbox checked, change to Processing and trigger a save/update
                                if (changeStatus && window.EAO && window.EAO.MainCoordinator && window.EAO.MainCoordinator.elements && window.EAO.MainCoordinator.elements.$orderStatusField) {
                                    try {
                                        window.EAO_Payment_AutoSettingStatus = true;
                                        window.EAO.MainCoordinator.elements.$orderStatusField.val('wc-processing').trigger('change');
                                        setTimeout(function(){ window.EAO_Payment_AutoSettingStatus = false; }, 1200);
                                        if (window.EAO && window.EAO.FormSubmission) {
                                            setTimeout(function(){
                                                // Trigger full save/update so notes reload naturally
                                                $('#eao-save-order').trigger('click');
                                            }, 250);
                                        }
                                    } catch(_){ }
                                } else {
                                    // Otherwise, refresh notes immediately to show the payment note
                                    $.post((window.eao_ajax && eao_ajax.ajax_url) || window.ajaxurl, {
                                        action: 'eao_refresh_order_notes',
                                        nonce: (window.eaoEditorParams && eaoEditorParams.nonce) || $('#eao_payment_mockup_nonce').val(),
                                        order_id: orderId
                                    }, function(nr){
                                        if (nr && nr.success && nr.data && nr.data.notes_html) {
                                            var $wrap = $('#eao-existing-notes-list-wrapper');
                                            if ($wrap.length) { $wrap.html(nr.data.notes_html); }
                                        }
                                    }, 'json');
                                }
                                // Mark that we have a processed payment in this session
                                window.EAO = window.EAO || {};
                                window.EAO.Payment = window.EAO.Payment || {};
                                window.EAO.Payment.hasProcessedPayment = true;
                            } else {
                                $msg.html('<div class="notice notice-error"><p>Unexpected Stripe response</p></div>');
                            }
                            // Record an order note with the result (success/failure) including a pre-constructed message amount
                            var preAmount = $('#eao-pp-amount').data('last-paid-amount') || $('#eao-pp-amount').val();
                            $.post((window.eao_ajax && eao_ajax.ajax_url) || window.ajaxurl, {
                                action: 'eao_payment_record_result',
                                nonce: $('#eao_payment_mockup_nonce').val(),
                                order_id: orderId,
                                gateway: gateway,
                                success: result && result.paymentIntent && result.paymentIntent.status === 'succeeded',
                                details: JSON.stringify(result && result.paymentIntent ? result.paymentIntent : (result && result.error ? result.error : {})),
                                amount_hint: preAmount
                            });
                        });
                    });
                }, 'json');
            } else {
                $msg.html('<div class="notice notice-warning"><p>' + gateway + ' is not yet implemented.</p></div>');
            }
            }

            // Test gateway explicit confirmation
            if (gateway === 'stripe_test') {
                var okTest = window.confirm('You are using a test gateway! No real payment will be processed.\nAre you sure you want to continue?');
                if (!okTest) { return; }
            }

            // Check amount vs grand total
            var gt = getGrandTotal();
            if (!isNaN(gt) && !isNaN(amount) && Math.abs(amount - gt) > 0.009) {
                // Show custom confirmation with Yes / Cancel / Fix
                var html = ''+
                  '<div class="notice notice-warning"><p>Payment amount ($'+amount.toFixed(2)+') differs from Grand Total ($'+gt.toFixed(2)+').</p>'+
                  '<p>Are you sure?</p>'+
                  '<p><button type="button" class="button button-primary eao-pp-confirm-yes">Yes</button> '+
                  '<button type="button" class="button eao-pp-confirm-cancel">Cancel</button> '+
                  '<button type="button" class="button eao-pp-confirm-fix">Fix</button></p></div>';
                $msg.html(html);
                $msg.off('click.eaoConfirm')
                    .on('click.eaoConfirm', '.eao-pp-confirm-yes', function(){ $msg.empty(); doProcess(amount); })
                    .on('click.eaoConfirm', '.eao-pp-confirm-cancel', function(){ $msg.empty(); })
                    .on('click.eaoConfirm', '.eao-pp-confirm-fix', function(){ $('#eao-pp-amount').val(gt.toFixed(2)); $msg.empty(); doProcess(gt); });
                return;
            }

            doProcess(amount);
        });

        // Auto-insert slash when typing expiry in MM/YY for test mode convenience
        $('#eao-pp-expiry').on('input', function(){
            var val = $(this).val().replace(/[^0-9]/g, '');
            if (val.length >= 3) { val = val.slice(0,2) + '/' + val.slice(2,4); }
            $(this).val(val);
        });

        // Refunds UI
        // Render existing refunds both above panel and within panel (once)
        function renderExistingRefunds(list){
            var $box = $('#eao-refunds-existing');
            var $top = $('#eao-refunds-existing-top');
            if (!list || !list.length) { $box.html('').hide(); $top.html(''); renderGatewayState(); return; }
            var html = '<strong>Existing refunds:</strong><ul style="margin:6px 0 0 16px;">';
            list.forEach(function(r){
                // Show simplified: DATE - $XX - N points
                var pretty = (r.date||'') + ' - $' + r.amount + (r.points ? (' - ' + r.points + ' points') : '');
                html += '<li>'+ pretty +'</li>';
            });
            html += '</ul>';
            // Only render in the top area to avoid duplicate text in the panel
            $box.html('').hide();
            $top.html(html);
            renderGatewayState();

        }

        function sumRefundTotals(){
            var money = 0, points = 0;
            $('#eao-refunds-table tbody tr').each(function(){
                money += parseFloat($(this).find('.eao-r-money').val() || '0');
                points += parseInt($(this).find('.eao-r-points').val() || '0', 10);
            });
            $('#eao-refund-total-money').val(money.toFixed(2));
            $('#eao-refund-total-points').val(points);
        }

        function loadRefundData(){
            var orderId = $('#eao-pp-order-id').val();
            $('#eao-refunds-panel').show();
            $('#eao-refunds-initial').hide(); // hide the top button once panel is open
            // console.debug('[EAO Payment] Loading refund data...', {orderId: orderId});
            $.post((window.eao_ajax && eao_ajax.ajax_url) || window.ajaxurl, {
                action: 'eao_payment_get_refund_data',
                nonce: $('#eao_payment_mockup_nonce').val(),
                order_id: orderId
            }, function(resp){
                if (!resp || !resp.success) { console.error('[EAO Payment] Failed to load refund data:', resp); $('#eao-refunds-messages').html('<div class="notice notice-error"><p>Failed to load refund data.</p></div>'); return; }
                var rows = '';
                (resp.data.items||[]).forEach(function(it){
                    var img = it.image ? '<img src="'+it.image+'" style="width:28px;height:28px;object-fit:contain;margin-right:6px;" />' : '';
                    var prod = img + '<strong>'+ (it.name||'') +'</strong>' + (it.sku? ('<br/><small>SKU: '+it.sku+'</small>') : '');
                    var remaining = parseFloat(it.remaining || it.paid);
                    var paidCell = (remaining < parseFloat(it.paid))
                        ? '<span style="text-decoration:line-through;opacity:0.65;">'+it.paid+'</span> '+remaining.toFixed(2)
                        : it.paid;
                    var pts = parseInt(it.points,10)||0;
                    var ptsInitial = parseInt(it.points_initial,10)||pts;
                    var ptsCell = (pts < ptsInitial) ? '<span style="text-decoration:line-through;opacity:0.65;">'+ptsInitial+'</span> '+pts : pts;
                    rows += '<tr data-item-id="'+it.item_id+'" data-paid="'+it.paid+'" data-remaining="'+remaining.toFixed(2)+'">'+
                        '<td>'+prod+'</td>'+
                        '<td>'+it.qty+'</td>'+
                        '<td class="eao-points-cell">'+ptsCell+'</td>'+
                        '<td class="eao-paid-cell">'+paidCell+'</td>'+
                        '<td><input type="checkbox" class="eao-r-full" /></td>'+
                        '<td><input type="number" class="eao-r-money" step="0.01" value="0.00" style="width:65px" /></td>'+
                        '<td><input type="number" class="eao-r-points" step="1" value="0" style="width:55px" /></td>'+
                    '</tr>';
                });
                $('#eao-refunds-table tbody').html(rows);
                renderExistingRefunds(resp.data.refunds||[]);

                applyGatewaySummary((resp.data && resp.data.gateway) ? resp.data.gateway : null);
                sumRefundTotals();
            }, 'json');
        }

        // Load existing refunds immediately on page load into the top area
        (function initialRefundSummary(){
            // Only fetch summary to show in the top area, keep panel hidden
            var orderId = $('#eao-pp-order-id').val();
            $.post((window.eao_ajax && eao_ajax.ajax_url) || window.ajaxurl, {
                action: 'eao_payment_get_refund_data',
                nonce: $('#eao_payment_mockup_nonce').val(),
                order_id: orderId
            }, function(resp){
                if (resp && resp.success) {
                    renderExistingRefunds(resp.data.refunds||[]);

                    applyGatewaySummary((resp.data && resp.data.gateway) ? resp.data.gateway : null);
                }
            }, 'json');
            $('#eao-refunds-panel').hide();
        })();
        $('#eao-pp-refunds-open').on('click', function(){

            renderGatewayState();
            loadRefundData();
        });

        $container.on('change keyup', '.eao-r-money, .eao-r-points', sumRefundTotals);
        $container.on('change', '.eao-r-full', function(){
            var $tr = $(this).closest('tr');
            var paid = parseFloat($tr.data('remaining') || $tr.data('paid') || '0');
            // Points cell may contain strike-through HTML, read final remaining points (text after strike)
            var ptsText = $tr.find('.eao-points-cell').text() || $tr.find('td').eq(2).text();
            var pts = parseInt(ptsText.replace(/[^0-9\-]/g,'') || '0', 10);
            if ($(this).is(':checked')) {
                $tr.find('.eao-r-money').val(paid.toFixed(2));
                $tr.find('.eao-r-points').val(pts);
            } else {
                $tr.find('.eao-r-money').val('0.00');
                $tr.find('.eao-r-points').val('0');
            }
            sumRefundTotals();
        });

        $('#eao-pp-refund-process').on('click', function(){
            var $btn = $(this);
            var lines = [];
            $('#eao-refunds-table tbody tr').each(function(){
                var money = parseFloat($(this).find('.eao-r-money').val() || '0');
                var points = parseInt($(this).find('.eao-r-points').val() || '0', 10);
                if (money > 0 || points > 0) {
                    lines.push({
                        item_id: parseInt($(this).data('item-id'), 10),
                        money: money,
                        points: points
                    });
                }
            });
            var orderId = $('#eao-pp-order-id').val();
            var $msg = $('#eao-refunds-messages');
            $msg.html('<div class="notice notice-info"><p>Processing refund...</p></div>');
            $btn.prop('disabled', true);
            $.ajax({
                url: (window.eao_ajax && eao_ajax.ajax_url) || window.ajaxurl,
                method: 'POST',
                dataType: 'json',
                timeout: 45000,
                data: {
                    action: 'eao_payment_process_refund',
                    nonce: $('#eao_payment_mockup_nonce').val(),
                    order_id: orderId,
                    lines: JSON.stringify(lines),
                    reason: $('#eao-refund-reason').val() || ''
                }
            }).done(function(resp){
                if (resp && resp.success) {
                    $msg.html('<div class="notice notice-success"><p>Refund created #' + resp.data.refund_id + ' for $' + resp.data.amount + '.</p></div>');
                    // Reload only the table contents and summaries to avoid duplicating header/button
                    $.ajax({
                        url: (window.eao_ajax && eao_ajax.ajax_url) || window.ajaxurl,
                        method: 'POST',
                        dataType: 'json',
                        data: { action: 'eao_payment_get_refund_data', nonce: $('#eao_payment_mockup_nonce').val(), order_id: orderId }
                    }).done(function(r2){
                        if (r2 && r2.success) {
                            var rows = '';
                            (r2.data.items||[]).forEach(function(it){
                                var img = it.image ? '<img src="'+it.image+'" style="width:28px;height:28px;object-fit:contain;margin-right:6px;" />' : '';
                                var prod = img + '<strong>'+ (it.name||'') +'</strong>' + (it.sku? ('<br/><small>SKU: '+it.sku+'</small>') : '');
                                var remaining = parseFloat(it.remaining || it.paid);
                                var paidCell = (remaining < parseFloat(it.paid)) ? '<span style="text-decoration:line-through;opacity:0.65;">'+it.paid+'</span> '+remaining.toFixed(2) : it.paid;
                                var pts = parseInt(it.points,10)||0;
                                var ptsInitial = parseInt(it.points_initial,10)||pts;
                                var ptsCell = (pts < ptsInitial) ? '<span style="text-decoration:line-through;opacity:0.65;">'+ptsInitial+'</span> '+pts : pts;
                                rows += '<tr data-item-id="'+it.item_id+'" data-paid="'+it.paid+'" data-remaining="'+remaining.toFixed(2)+'">'+
                                    '<td>'+prod+'</td>'+
                                    '<td>'+it.qty+'</td>'+
                                    '<td class="eao-points-cell">'+ptsCell+'</td>'+
                                    '<td class="eao-paid-cell">'+paidCell+'</td>'+
                                    '<td><input type="checkbox" class="eao-r-full" /></td>'+
                                    '<td><input type="number" class="eao-r-money" step="0.01" value="0.00" style="width:65px" /></td>'+
                                    '<td><input type="number" class="eao-r-points" step="1" value="0" style="width:55px" /></td>'+
                                '</tr>';
                            });
                            $('#eao-refunds-table tbody').html(rows);
                            renderExistingRefunds(r2.data.refunds||[]);
                            applyGatewaySummary((r2.data && r2.data.gateway) ? r2.data.gateway : null);
                            $('#eao-refunds-initial').hide();
                            sumRefundTotals();
                            $.post((window.eao_ajax && eao_ajax.ajax_url) || window.ajaxurl, {
                                action: 'eao_refresh_order_notes',
                                nonce: (window.eaoEditorParams && eaoEditorParams.nonce) || $('#eao_payment_mockup_nonce').val(),
                                order_id: orderId
                            }, function(nr){
                                if (nr && nr.success && nr.data && nr.data.notes_html) {
                                    var $wrap = $('#eao-existing-notes-list-wrapper');
                                    if ($wrap.length) { $wrap.html(nr.data.notes_html); }
                                }
                            }, 'json');
                        } else {
                            $msg.html('<div class="notice notice-error"><p>Refund processed but failed to refresh data.</p></div>');
                        }
                    }).fail(function(jq, text){
                        $msg.html('<div class="notice notice-error"><p>Refund processed but refresh failed: '+(text||'error')+'</p></div>');
                    });
                } else {
                    var err = 'Refund failed';
                    if (resp && resp.data && resp.data.message) err = resp.data.message;
                    if (resp && resp.data && resp.data.stripe && resp.data.stripe.error && resp.data.stripe.error.message) err += ': ' + resp.data.stripe.error.message;
                    $msg.html('<div class="notice notice-error"><p>' + err + '</p></div>');
                }
            }).fail(function(jqXHR, textStatus){
                var detail = (jqXHR && jqXHR.responseText) ? jqXHR.responseText.slice(0,180) : textStatus;
                $msg.html('<div class="notice notice-error"><p>Refund request failed: '+ detail +'</p></div>');
            }).always(function(){
                $btn.prop('disabled', false);
            });
        });

        // --- Payment Request (Invoice) ---
        function eaoUpdateRequestButtons(){
            var $btnStripe = $('#eao-pp-send-stripe');
            var $btnPayPal = $('#eao-pp-send-paypal');
            var stripeActive = false, paypalActive = false;
            try {
                var sId = $('#eao-pp-invoice-id').val()||'';
                var sSt = String($('#eao-pp-invoice-status').val()||'').toLowerCase();
                stripeActive = !!(sId && sSt !== 'void');
            } catch(_){}
            try {
                var pId = $('#eao-pp-paypal-invoice-id').val()||'';
                var pSt = String($('#eao-pp-paypal-invoice-status').val()||'').toLowerCase();
                paypalActive = !!(pId && pSt !== 'void' && pSt !== 'cancelled');
            } catch(_){}
            $btnStripe.prop('disabled', stripeActive || paypalActive);
            $btnPayPal.prop('disabled', stripeActive || paypalActive);
            $('#eao-pp-void-stripe').toggle(!!stripeActive);
            $('#eao-pp-void-paypal').toggle(!!paypalActive);

            function getGrandTotal(){
                try {
                    var s = window.currentOrderSummaryData || {};
                    var gt = (typeof s.grand_total !== 'undefined') ? parseFloat(s.grand_total) : parseFloat(s.grand_total_raw);
                    if (!isNaN(gt)) return gt;
                } catch(_){ }
                try {
                    var txt = $('.eao-grand-total-line .eao-summary-monetary-value, .eao-grand-total-value, #eao-grand-total-value, .grand-total, [data-field="grand_total"]').first().text() || '';
                    var num = parseFloat(String(txt).replace(/[^0-9.\-]/g,''));
                    if (!isNaN(num)) return num;
                } catch(_){ }
                return NaN;
            }
            var val = getGrandTotal();
            if (!isNaN(val)) {
                if ($btnStripe.length) $btnStripe.text('Send Stripe Payment Request ($' + val.toFixed(2) + ')');
                if ($btnPayPal.length) $btnPayPal.text('Send PayPal Payment Request ($' + val.toFixed(2) + ')');
            }
        }
        eaoUpdateRequestButtons();
        $(document).on('change keyup', '#eao-pp-amount', eaoUpdateRequestButtons);
        $(document).on('click', '#eao-pp-copy-gt', eaoUpdateRequestButtons);
        $(document).on('change', 'input[name="eao-pp-line-mode"], #eao-pp-request-email', eaoUpdateRequestButtons);
        setTimeout(eaoUpdateRequestButtons, 600);
        setTimeout(eaoUpdateRequestButtons, 1500);

        $('#eao-pp-send-stripe').on('click', function(){
            var orderId = $('#eao-pp-order-id').val();
            var email = $('#eao-pp-request-email').val() || '';
            var mode = $('input[name="eao-pp-line-mode"]:checked').val() || 'grand';
            var gateway = 'stripe_live';
            var $msg = $('#eao-pp-request-messages');
            function getGrandTotal(){
                try {
                    var s = window.currentOrderSummaryData || {};
                    var gt = (typeof s.grand_total !== 'undefined') ? parseFloat(s.grand_total) : parseFloat(s.grand_total_raw);
                    if (!isNaN(gt)) return gt;
                } catch(_){ }
                try {
                    var txt = $('.eao-grand-total-line .eao-summary-monetary-value, .eao-grand-total-value, #eao-grand-total-value, .grand-total, [data-field="grand_total"]').first().text() || '';
                    var num = parseFloat(String(txt).replace(/[^0-9.\-]/g,''));
                    if (!isNaN(num)) return num;
                } catch(_){ }
                return NaN;
            }
            var amountOverride = getGrandTotal();
            $msg.html('<div class="notice notice-info"><p>Sending payment request...</p></div>');
            $.post((window.eao_ajax && eao_ajax.ajax_url) || window.ajaxurl, {
                action: 'eao_stripe_send_payment_request',
                nonce: $('#eao_payment_mockup_nonce').val(),
                order_id: orderId,
                email: email,
                mode: mode,
                gateway: gateway,
                amount_override: isNaN(amountOverride) ? '' : amountOverride
            }, function(resp){
                if (resp && resp.success) {
                    var url = resp.data && resp.data.url ? resp.data.url : '';
                    var html = '<div class="notice notice-success"><p>Payment request sent.' + (url ? ' <a target=\"_blank\" href=\"'+url+'\">Open invoice</a>' : '') + '</p></div>';
                    $msg.html(html);
                    if (resp.data && resp.data.invoice_id) {
                        $('#eao-pp-invoice-id').val(resp.data.invoice_id);
                        $('#eao-pp-invoice-status').val(resp.data.status || 'open');
                        $('#eao-pp-void-stripe').show();
                        eaoUpdateRequestButtons();
                    }
                } else {
                    var err = (resp && resp.data && resp.data.message) ? resp.data.message : 'Failed to send payment request';
                    try {
                        if (resp && resp.data && resp.data.stripe && resp.data.stripe.error && resp.data.stripe.error.message) {
                            err += ': ' + resp.data.stripe.error.message;
                        }
                    } catch(_){ }
                    $msg.html('<div class=\"notice notice-error\"><p>'+err+'</p></div>');
                }
            }, 'json');
        });

        $('#eao-pp-send-paypal').on('click', function(){
            var orderId = $('#eao-pp-order-id').val();
            var email = $('#eao-pp-request-email').val() || '';
            var mode = $('input[name="eao-pp-line-mode"]:checked').val() || 'grand';
            var $msg = $('#eao-pp-request-messages');
            function getGrandTotal(){
                try {
                    var s = window.currentOrderSummaryData || {};
                    var gt = (typeof s.grand_total !== 'undefined') ? parseFloat(s.grand_total) : parseFloat(s.grand_total_raw);
                    if (!isNaN(gt)) return gt;
                } catch(_) { }
                try {
                    var txt = $('.eao-grand-total-line .eao-summary-monetary-value, .eao-grand-total-value, #eao-grand-total-value, .grand-total, [data-field="grand_total"]').first().text() || '';
                    var num = parseFloat(String(txt).replace(/[^0-9.\-]/g,''));
                    if (!isNaN(num)) return num;
                } catch(_) { }
                return NaN;
            }
            var amountOverride = getGrandTotal();
            $msg.html('<div class="notice notice-info"><p>Sending payment request...</p></div>');
            $.post((window.eao_ajax && eao_ajax.ajax_url) || window.ajaxurl, {
                action: 'eao_paypal_send_payment_request',
                nonce: $('#eao_payment_mockup_nonce').val(),
                order_id: orderId,
                email: email,
                mode: mode,
                amount_override: isNaN(amountOverride) ? '' : amountOverride
            }, function(resp){
                if (resp && resp.success) {
                    var url = resp.data && resp.data.url ? resp.data.url : '';
                    var html = '<div class="notice notice-success"><p>Payment request sent.' + (url ? ' <a target=\"_blank\" href=\"'+url+'\">Open invoice</a>' : '') + '</p></div>';
                    $msg.html(html);
                    if (resp.data && resp.data.invoice_id) {
                        $('#eao-pp-paypal-invoice-id').val(resp.data.invoice_id);
                        $('#eao-pp-paypal-invoice-status').val(resp.data.status || 'SENT');
                        $('#eao-pp-void-paypal').show();
                        eaoUpdateRequestButtons();
                    }
                } else {
                    var err = (resp && resp.data && resp.data.message) ? resp.data.message : 'Failed to send payment request';
                    $msg.html('<div class=\"notice notice-error\"><p>'+err+'</p></div>');
                }
            }, 'json');
        });

        $('#eao-pp-void-stripe').on('click', function(){
            var orderId = $('#eao-pp-order-id').val();
            var invoiceId = $('#eao-pp-invoice-id').val();
            var $msg = $('#eao-pp-request-messages');
            if (!invoiceId) { $msg.html('<div class="notice notice-warning"><p>No existing invoice to void.</p></div>'); return; }
            if (!window.confirm('Void the existing payment request?')) { return; }
            $msg.html('<div class="notice notice-info"><p>Voiding invoice...</p></div>');
            // Immediately re-enable send button; if server fails, we'll revert below
            var prevId = $('#eao-pp-invoice-id').val()||'';
            var prevStatus = $('#eao-pp-invoice-status').val()||'';
            $('#eao-pp-void-stripe').hide();
            $('#eao-pp-invoice-id').val('');
            $('#eao-pp-invoice-status').val('void');
            eaoUpdateRequestButtons();
            $.post((window.eao_ajax && eao_ajax.ajax_url) || window.ajaxurl, {
                action: 'eao_stripe_void_invoice',
                nonce: $('#eao_payment_mockup_nonce').val(),
                order_id: orderId,
                invoice_id: invoiceId,
                gateway: 'stripe_live'
            }, function(resp){
                if (resp && resp.success) {
                    $msg.html('<div class="notice notice-success"><p>Payment request voided.</p></div>');
                } else {
                    var err = (resp && resp.data && resp.data.message) ? resp.data.message : 'Failed to void payment request';
                    $msg.html('<div class="notice notice-error"><p>'+err+'</p></div>');
                    // Revert UI to previous state since server reported failure
                    $('#eao-pp-void-stripe').show();
                    $('#eao-pp-invoice-id').val(prevId);
                    $('#eao-pp-invoice-status').val(prevStatus);
                    eaoUpdateRequestButtons();
                }
            }, 'json');
        });

        $('#eao-pp-void-paypal').on('click', function(){
            var orderId = $('#eao-pp-order-id').val();
            var invoiceId = $('#eao-pp-paypal-invoice-id').val();
            var $msg = $('#eao-pp-request-messages');
            if (!invoiceId) { $msg.html('<div class="notice notice-warning"><p>No existing PayPal invoice to void.</p></div>'); return; }
            if (!window.confirm('Void the existing PayPal payment request?')) { return; }
            $msg.html('<div class="notice notice-info"><p>Voiding PayPal invoice...</p></div>');
            var prevId = $('#eao-pp-paypal-invoice-id').val()||'';
            var prevStatus = $('#eao-pp-paypal-invoice-status').val()||'';
            $('#eao-pp-void-paypal').hide();
            $('#eao-pp-paypal-invoice-id').val('');
            $('#eao-pp-paypal-invoice-status').val('cancelled');
            eaoUpdateRequestButtons();
            $.post((window.eao_ajax && eao_ajax.ajax_url) || window.ajaxurl, {
                action: 'eao_paypal_void_invoice',
                nonce: $('#eao_payment_mockup_nonce').val(),
                order_id: orderId,
                invoice_id: invoiceId
            }, function(resp){
                if (resp && resp.success) {
                    $msg.html('<div class="notice notice-success"><p>Payment request voided.</p></div>');
                } else {
                    var err = (resp && resp.data && resp.data.message) ? resp.data.message : 'Failed to void payment request';
                    $msg.html('<div class="notice notice-error"><p>'+err+'</p></div>');
                    $('#eao-pp-void-paypal').show();
                    $('#eao-pp-paypal-invoice-id').val(prevId);
                    $('#eao-pp-paypal-invoice-status').val(prevStatus);
                    eaoUpdateRequestButtons();
                }
            }, 'json');
        });

        $('#eao-pp-refresh-status').on('click', function(){
            var orderId = $('#eao-pp-order-id').val();
            var stripeId = $('#eao-pp-invoice-id').val() || '';
            var stripeSt = String($('#eao-pp-invoice-status').val()||'').toLowerCase();
            var ppId = $('#eao-pp-paypal-invoice-id').val() || '';
            var ppSt = String($('#eao-pp-paypal-invoice-status').val()||'').toLowerCase();
            var $msg = $('#eao-pp-request-messages');
            if (!stripeId && !ppId) {
                $msg.html('<div class="notice notice-warning"><p>No active payment request to refresh.</p></div>');
                return;
            }
            $msg.html('<div class="notice notice-info"><p>Refreshing status...</p></div>');
            if (stripeId && stripeSt !== 'void') {
                $.post((window.eao_ajax && eao_ajax.ajax_url) || window.ajaxurl, {
                    action: 'eao_stripe_get_invoice_status',
                    nonce: $('#eao_payment_mockup_nonce').val(),
                    order_id: orderId,
                    invoice_id: stripeId
                }, function(resp){
                    if (resp && resp.success) {
                        if (resp.data && resp.data.status) {
                            $('#eao-pp-invoice-status').val(String(resp.data.status));
                        }
                        if (resp.data && resp.data.status === 'paid') {
                            $msg.html('<div class="notice notice-success"><p>Stripe invoice is PAID.</p></div>');
                        } else {
                            $msg.html('<div class="notice notice-info"><p>Stripe invoice status: '+ (resp.data && resp.data.status ? resp.data.status : 'unknown') +'</p></div>');
                        }
                        eaoUpdateRequestButtons();
                    } else {
                        var err = (resp && resp.data && resp.data.message) ? resp.data.message : 'Failed to refresh Stripe status';
                        $msg.html('<div class="notice notice-error"><p>'+err+'</p></div>');
                    }
                }, 'json');
                return;
            }
            if (ppId && ppSt !== 'void' && ppSt !== 'cancelled') {
                $.post((window.eao_ajax && eao_ajax.ajax_url) || window.ajaxurl, {
                    action: 'eao_paypal_get_invoice_status',
                    nonce: $('#eao_payment_mockup_nonce').val(),
                    order_id: orderId,
                    invoice_id: ppId
                }, function(resp){
                    if (resp && resp.success) {
                        if (resp.data && resp.data.status) {
                            $('#eao-pp-paypal-invoice-status').val(String(resp.data.status));
                        }
                        if (resp.data && String(resp.data.status).toUpperCase() === 'PAID') {
                            $msg.html('<div class="notice notice-success"><p>PayPal invoice is PAID.</p></div>');
                        } else {
                            $msg.html('<div class="notice notice-info"><p>PayPal invoice status: '+ (resp.data && resp.data.status ? resp.data.status : 'unknown') +'</p></div>');
                        }
                        eaoUpdateRequestButtons();
                    } else {
                        var err = (resp && resp.data && resp.data.message) ? resp.data.message : 'Failed to refresh PayPal status';
                        $msg.html('<div class="notice notice-error"><p>'+err+'</p></div>');
                    }
                }, 'json');
                return;
            }
        });
    });
})(jQuery);


