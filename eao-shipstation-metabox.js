/**
 * Enhanced Admin Order Plugin - ShipStation Metabox JavaScript
 * Extracted from eao-shipstation-core.php for reliable loading
 * 
 * @package EnhancedAdminOrder
 * @version 2.7.73
 * @author Amnon Manneberg
 */

(function($) {
    'use strict';

    // Ensure EAO namespace exists
    window.EAO = window.EAO || {};

    /**
     * ShipStation Metabox Manager
     */
    window.EAO.ShipStationMetabox = {
        
        initialized: false,
        
        /**
         * Initialize the ShipStation metabox functionality
         */
        init: function() {
            // Prevent multiple initializations
            if (this.initialized) {
                if (window.eaoDebugSS) { console.log('[EAO ShipStation Debug] Already initialized, skipping...'); }
                return;
            }
            
            if (window.eaoDebugSS) { console.log('%cEAO DEBUG: ShipStation Metabox Initializing...', 'color: #0073aa; font-weight: bold;'); }
            
            // Debug: Check if buttons exist and jQuery is working
            if (window.eaoDebugSS) {
                console.log('[EAO ShipStation Debug] Button count check:');
                console.log('  .eao-shipstation-get-rates-action:', jQuery('.eao-shipstation-get-rates-action').length);
                console.log('  .eao-shipstation-test-connection:', jQuery('.eao-shipstation-test-connection').length);
                console.log('  .eao-apply-custom-shipping-rate:', jQuery('.eao-apply-custom-shipping-rate').length);
                console.log('  jQuery version:', jQuery ? 'Available' : 'NOT AVAILABLE');
            }

            // Check if metabox exists using the correct WordPress metabox ID
            if (jQuery('#eao_shipstation_api_rates_metabox').length === 0) {
                if (window.eaoDebugSS) { console.log('[EAO ShipStation Debug] ShipStation metabox not found on page - will retry in 500ms'); }
                // Retry once after a short delay in case metabox is still loading
                setTimeout(function() {
                    if (jQuery('#eao_shipstation_api_rates_metabox').length === 0) {
                        if (window.eaoDebugSS) { console.log('[EAO ShipStation Debug] ShipStation metabox still not found after retry - page may not have ShipStation metabox'); }
                        return;
                    }
                    if (window.eaoDebugSS) { console.log('[EAO ShipStation Debug] ShipStation metabox found on retry - initializing handlers'); }
                    window.EAO.ShipStationMetabox.completeInitialization();
                }, 500);
                return;
            }
            if (window.eaoDebugSS) { console.log('[EAO ShipStation Debug] ShipStation metabox found - initializing handlers'); }
            this.completeInitialization();
        },

        /**
         * Complete the initialization process
         */
        completeInitialization: function() {
            if (this.initialized) {
                if (window.eaoDebugSS) { console.log('[EAO ShipStation Debug] Initialization already completed'); }
                return;
            }

            // Initialize global variables
            this.initializeGlobals();
            
            // Bind event handlers
            this.bindEvents();
            
            // Auto-test connection if credentials exist
            this.autoTestConnection();

            // Mark as initialized
            this.initialized = true;
            if (window.eaoDebugSS) { console.log('[EAO ShipStation Debug] ShipStation metabox initialization complete'); }
        },

        /**
         * Initialize global variables
         */
        initializeGlobals: function() {
            window.eaoPendingShipstationRate = null;
        },

        /**
         * Bind all event handlers
         */
        bindEvents: function() {
            if (window.eaoDebugSS) { console.log('[EAO ShipStation Debug] Binding event handlers...'); }

            // Custom shipping rate functionality
            if (window.eaoDebugSS) { console.log('[EAO ShipStation Debug] Binding custom rate handler to', jQuery('.eao-apply-custom-shipping-rate').length, 'elements'); }
            jQuery(document).off('click', '.eao-apply-custom-shipping-rate').on('click', '.eao-apply-custom-shipping-rate', this.handleCustomShippingRate.bind(this));

            // Get ShipStation rates functionality
            if (window.eaoDebugSS) { console.log('[EAO ShipStation Debug] Binding get rates handler to', jQuery('.eao-shipstation-get-rates-action').length, 'elements'); }
            jQuery(document).off('click', '.eao-shipstation-get-rates-action').on('click', '.eao-shipstation-get-rates-action', this.handleGetRates.bind(this));

            // Test connection functionality
            if (window.eaoDebugSS) { console.log('[EAO ShipStation Debug] Binding test connection handler to', jQuery('.eao-shipstation-test-connection').length, 'elements'); }
            jQuery(document).off('click', '.eao-shipstation-test-connection').on('click', '.eao-shipstation-test-connection', this.handleTestConnection.bind(this));

            // ShipStation connection functionality
            jQuery(document).off('click', '.eao-shipstation-connect').on('click', '.eao-shipstation-connect', this.handleConnect.bind(this));

            // Settings toggle functionality (cog icon)
            jQuery(document).off('click', '.eao-shipstation-settings-toggle').on('click', '.eao-shipstation-settings-toggle', this.handleSettingsToggle.bind(this));
            
            // Cancel edit functionality
            jQuery(document).off('click', '.eao-shipstation-cancel-edit').on('click', '.eao-shipstation-cancel-edit', this.handleCancelEdit.bind(this));
            
            // Update credentials functionality
            jQuery(document).off('click', '.eao-shipstation-update-credentials').on('click', '.eao-shipstation-update-credentials', this.handleUpdateCredentials.bind(this));

            // Apply rate functionality
            jQuery(document).off('click', '.eao-shipstation-apply-rate').on('click', '.eao-shipstation-apply-rate', this.handleApplyRate.bind(this));

            console.log('[EAO ShipStation Debug] Event handlers bound successfully');
        },

        /**
         * Handle custom shipping rate application
         */
        handleCustomShippingRate: function() {
            if (window.eaoDebugSS) { console.log('[EAO ShipStation Debug] Custom shipping rate button clicked!'); }
            
            var amount = parseFloat(jQuery('#eao_custom_shipping_rate_amount').val()) || 0;
            
            // Get currency symbol from localized data or fallback
            var currencySymbol = (typeof eaoShipStationParams !== 'undefined' && eaoShipStationParams.currencySymbol) ? eaoShipStationParams.currencySymbol : '$';
            
            // Create currency formatting function
            function eaoFormatPrice(price) {
                var numPrice = parseFloat(price);
                if (isNaN(numPrice)) return '';
                return currencySymbol + numPrice.toFixed(2);
            }
            
            // Create custom rate data with same structure as ShipStation rates
            window.eaoPendingShipstationRate = {
                is_custom_applied: true, // Mark as custom rate
                shipping_amount_raw: amount,
                shipping_amount_formatted: eaoFormatPrice(amount),
                service_name: 'Custom Rate',
                adjustedAmountRaw: amount,
                adjustedAmountFormatted: eaoFormatPrice(amount),
                originalAmountRaw: amount,
                originalAmountFormatted: eaoFormatPrice(amount),
                adjustmentType: 'no_adjustment',
                adjustmentValue: 0,
                rate_id: 'custom_shipping_rate',
                method_title: 'Custom Rate',
                carrier_code: 'manual',
                service_code: 'custom'
            };
            
            if (window.eaoDebugSS) { console.log('Applying custom rate:', window.eaoPendingShipstationRate); }
            
            // Trigger the same event as ShipStation rates to enable Update Order button
            jQuery(document).trigger('eaoShippingRateApplied');
        },

        /**
         * Handle get rates button click
         */
        handleGetRates: function(event) {
            if (window.eaoDebugSS) { console.log('[EAO ShipStation Debug] Get rates button clicked!'); }
            
            var $button = jQuery(event.currentTarget);
            var orderId = $button.data('order-id');
            var nonce = $button.data('nonce');
            
            if (window.eaoDebugSS) { console.log('[EAO ShipStation Debug] Order ID:', orderId, 'Nonce present:', !!nonce); }
            
            // Show rates container and loading state
            jQuery('.eao-shipstation-rates-container').show();
            jQuery('.eao-shipstation-rates-loading').show();
            jQuery('.eao-shipstation-rates-list').empty();
            jQuery('.eao-shipstation-rates-error').hide();
            
            // Disable button and show loading state
            jQuery('.eao-shipstation-get-rates-action').prop('disabled', true).text('Getting Rates...');
            
            // Get AJAX URL from localized data or fallback to global
            var ajaxUrl = (typeof eaoShipStationParams !== 'undefined' && eaoShipStationParams.ajaxUrl) ? 
                         eaoShipStationParams.ajaxUrl : 
                         (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php');
            
            // Collect current shipping address from the editor (do not require save)
            var shipCountry = jQuery('#shipping_country').val() || jQuery('#eao_shipping_country').val() || '';
            var shipPostcode = jQuery('#eao_shipping_postcode').val() || '';
            var shipCity = jQuery('#eao_shipping_city').val() || '';
            var shipState = jQuery('#shipping_state').val() || jQuery('#eao_shipping_state').val() || '';
            var shipAddr1 = jQuery('#eao_shipping_address_1').val() || '';
            var shipAddr2 = jQuery('#eao_shipping_address_2').val() || '';

            // Make AJAX request (include transient shipping fields so server can compute without a save)
            jQuery.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'eao_shipstation_v2_get_rates',
                    order_id: orderId,
                    eao_nonce: nonce,
                    ship_country: shipCountry,
                    ship_postcode: shipPostcode,
                    ship_city: shipCity,
                    ship_state: shipState,
                    ship_address_1: shipAddr1,
                    ship_address_2: shipAddr2
                },
                timeout: 30000,
                success: function(response) {
                    if (window.eaoDebugSS) { console.log('[EAO ShipStation Debug] Get rates response:', response); }
                    jQuery('.eao-shipstation-rates-loading').hide();
                    
                    if (response.success && response.data.rates && response.data.rates.length > 0) {
                        // Sort rates by price (lowest first)
                        var rates = response.data.rates.sort(function(a, b) {
                            return parseFloat(a.shipping_amount_raw || a.rate || 0) - parseFloat(b.shipping_amount_raw || b.rate || 0);
                        });
                        
                        var ratesHtml = '<div style="padding: 8px;">';
                        
                        jQuery.each(rates, function(index, rate) {
                            // Handle both new format (shipping_amount_raw) and old format (rate)
                            var rateAmount = parseFloat(rate.shipping_amount_raw || rate.rate || 0);
                            var rateFormatted = rate.shipping_amount || ('$' + rateAmount.toFixed(2));
                            var serviceName = rate.service_name || rate.title || 'Unknown Service';
                            var carrierName = rate.carrier_name || 'Unknown Carrier';
                            var rateId = rate.rate_id || ('rate_' + index);
                            var carrierCode = rate.carrier_code || '';
                            var serviceCode = rate.service_code || '';
                            
                            // Format service name for compact display
                            function escapeAttribute(str) {
                                if (typeof str !== 'string') return '';
                                return str.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
                            }
                            
                            function eaoFormatRateForDisplay(serviceName) {
                                if (typeof serviceName !== 'string') return 'N/A';
                                serviceName = serviceName.replace('®', '').replace('™', '');
                                if (serviceName.toLowerCase().endsWith(' - package')) {
                                    serviceName = serviceName.substring(0, serviceName.length - ' - package'.length);
                                }
                                return serviceName.trim();
                            }
                            
                            var displayServiceName = eaoFormatRateForDisplay(serviceName);
                            
                            // Apply carrier styling
                            var carrierStyle = '';
                            if (carrierCode && carrierCode.includes('ups')) {
                                carrierStyle = 'border-left: 2px solid #7b5e2e;';
                            } else if (carrierCode && carrierCode.includes('stamps_com')) {
                                carrierStyle = 'border-left: 2px solid #004b87;';
                            }
                            
                            ratesHtml += '<div class="eao-shipstation-rate" style="padding: 3px 2px 3px 6px; margin-bottom: 2px; line-height: 1.2; ' + carrierStyle + '">' +
                                        '<input type="radio" name="eao_shipstation_rate" id="eao_rate_' + rateId + '" ' +
                                        'value="' + rateId + '" ' +
                                        'data-service-name="' + escapeAttribute(serviceName) + '" ' +
                                        'data-amount-raw="' + rateAmount + '" ' +
                                        'data-carrier-code="' + escapeAttribute(carrierCode) + '" ' +
                                        'data-service-code="' + escapeAttribute(serviceCode) + '" ' +
                                        (index === 0 ? 'checked' : '') + '>' +
                                        '<label for="eao_rate_' + rateId + '" style="margin-left: 3px; display: inline; font-size: 12px;">' +
                                        '<strong>' + rateFormatted + '</strong> ' + displayServiceName +
                                        '</label>' +
                                        '</div>';
                        });
                        
                        ratesHtml += '</div>';
                        jQuery('.eao-shipstation-rates-list').html(ratesHtml);
                        
                        // Trigger change event for first selected rate
                        if (jQuery('input[name="eao_shipstation_rate"]:checked').length) {
                            jQuery('input[name="eao_shipstation_rate"]:checked').trigger('change');
                        }
                        
                        // Show warning if partial errors
                        if (response.data.partial_error) {
                            jQuery('.eao-shipstation-rates-list').prepend('<div style="color: orange; padding: 10px; background: #fff3cd; border: 1px solid #ffeaa7; margin-bottom: 10px;"><strong>Warning:</strong> ' + response.data.partial_error + '</div>');
                        }
                        
                        if (window.eaoDebugSS) { console.log('[EAO ShipStation Debug] ' + rates.length + ' rates displayed successfully'); }
                    } else {
                        var errorMsg = response.data && response.data.message ? response.data.message : 'No rates available or unknown error.';
                        jQuery('.eao-shipstation-rates-error').text(errorMsg).show();
                        
                        if (response.data && response.data.error_details) {
                            jQuery('.eao-shipstation-rates-error').html(errorMsg + '<br><br><strong>Details:</strong><br>' + response.data.error_details);
                        }
                        
                        if (window.eaoDebugSS) { console.error('[EAO ShipStation Debug] No rates to display:', errorMsg); }
                    }
                },
                error: function(xhr, status, error) {
                    if (window.eaoDebugSS) { console.error('[EAO ShipStation Debug] Get rates AJAX error:', status, error); }
                    jQuery('.eao-shipstation-rates-loading').hide();
                    jQuery('.eao-shipstation-rates-error').text('Network error while getting rates: ' + error).show();
                },
                complete: function() {
                    // Re-enable button
                    jQuery('.eao-shipstation-get-rates-action').prop('disabled', false).text('Get Shipping Rates from ShipStation');
                }
            });
        },

        /**
         * Handle test connection button click
         */
        handleTestConnection: function() {
            if (window.eaoDebugSS) { console.log('[EAO ShipStation Debug] Test connection button clicked!'); }
            this.testShipStationConnection(false);
        },

        /**
         * Handle connect button click
         */
        handleConnect: function() {
            if (window.eaoDebugSS) { console.log('[EAO ShipStation Debug] Connect button clicked!'); }
            
            var apiKey = jQuery('#eao_shipstation_api_key').val().trim();
            var apiSecret = jQuery('#eao_shipstation_api_secret').val().trim();
            var nonce = jQuery(this).data('nonce');
            
            if (!apiKey || !apiSecret) {
                alert('Please enter both API Key and Secret.');
                return;
            }
            
            jQuery(this).prop('disabled', true).text('Connecting...');
            
            // Get AJAX URL
            var ajaxUrl = (typeof eaoShipStationParams !== 'undefined' && eaoShipStationParams.ajaxUrl) ? 
                         eaoShipStationParams.ajaxUrl : 
                         (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php');
            
            jQuery.post(ajaxUrl, {
                action: 'eao_shipstation_v2_save_credentials',
                api_key: apiKey,
                api_secret: apiSecret,
                eao_nonce: nonce
            }, function(response) {
                if (response.success) {
                    alert(response.data.message);
                    location.reload(); // Reload to show the rates interface
                } else {
                    alert('Error: ' + response.data.message);
                }
            }).always(function() {
                jQuery('.eao-shipstation-connect').prop('disabled', false).text('Connect to ShipStation');
            });
        },

        /**
         * Handle settings toggle (cog icon)
         */
        handleSettingsToggle: function() {
            if (window.eaoDebugSS) { console.log('[EAO ShipStation Debug] Settings toggle clicked!'); }
            jQuery('#eao-shipstation-credentials-form').slideToggle(300);
        },

        /**
         * Handle cancel edit
         */
        handleCancelEdit: function() {
            if (window.eaoDebugSS) { console.log('[EAO ShipStation Debug] Cancel edit clicked!'); }
            jQuery('#eao-shipstation-credentials-form').slideUp(300);
            // Reset form values would need the original values from localized data
        },

        /**
         * Handle update credentials
         */
        handleUpdateCredentials: function() {
            if (window.eaoDebugSS) { console.log('[EAO ShipStation Debug] Update credentials clicked!'); }
            
            var apiKey = jQuery('#eao_shipstation_api_key_edit').val().trim();
            var apiSecret = jQuery('#eao_shipstation_api_secret_edit').val().trim();
            var nonce = jQuery(this).data('nonce');
            
            if (!apiKey || !apiSecret) {
                alert('Please enter both API Key and Secret.');
                return;
            }
            
            jQuery(this).prop('disabled', true).text('Updating...');
            
            // Get AJAX URL
            var ajaxUrl = (typeof eaoShipStationParams !== 'undefined' && eaoShipStationParams.ajaxUrl) ? 
                         eaoShipStationParams.ajaxUrl : 
                         (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php');
            
            jQuery.post(ajaxUrl, {
                action: 'eao_shipstation_v2_save_credentials',
                api_key: apiKey,
                api_secret: apiSecret,
                eao_nonce: nonce
            }, function(response) {
                if (response.success) {
                    alert(response.data.message);
                    jQuery('#eao-shipstation-credentials-form').slideUp(300);
                    // Reset connection status to re-test with new credentials
                    jQuery('#eao-connection-status-text').text('Not verified').css('color', '');
                    jQuery('#eao-shipstation-connection-debug').hide().empty();
                } else {
                    alert('Error: ' + response.data.message);
                }
            }).fail(function() {
                alert('Network error occurred while updating credentials.');
            }).always(function() {
                jQuery('.eao-shipstation-update-credentials').prop('disabled', false).text('Update Credentials');
            });
        },

        /**
         * Handle apply rate
         */
        handleApplyRate: function() {
            if (window.eaoDebugSS) { console.log('[EAO ShipStation Debug] Apply rate button clicked!'); }
            
            var $selectedRate = jQuery('input[name="eao_shipstation_rate"]:checked');
            if (!$selectedRate.length) {
                alert('Please select a ShipStation rate first.');
                return;
            }

            var finalAdjustedData = this.calculateAdjustedRate();
            var serviceName = $selectedRate.data('service-name');
            var rateId = $selectedRate.val();
            var carrierCode = $selectedRate.data('carrier-code');
            var serviceCode = $selectedRate.data('service-code');
            var selectedRateRawAmountEAO = parseFloat($selectedRate.data('amount-raw'));

            // Create currency formatting function
            function eaoFormatPrice(price) {
                var numPrice = parseFloat(price);
                if (isNaN(numPrice)) return '';
                var currencySymbol = (typeof eaoShipStationParams !== 'undefined' && eaoShipStationParams.currencySymbol) ? 
                                   eaoShipStationParams.currencySymbol : '$';
                return currencySymbol + numPrice.toFixed(2);
            }

            // Format service name for display
            function eaoFormatRateForDisplay(serviceName) {
                if (typeof serviceName !== 'string') return 'N/A';
                serviceName = serviceName.replace('®', '').replace('™', '');
                if (serviceName.toLowerCase().endsWith(' - package')) {
                    serviceName = serviceName.substring(0, serviceName.length - ' - package'.length);
                }
                return serviceName.trim();
            }

            window.eaoPendingShipstationRate = {
                is_custom_applied: false,
                shipping_amount_raw: selectedRateRawAmountEAO,
                shipping_amount_formatted: eaoFormatPrice(selectedRateRawAmountEAO),
                service_name: serviceName,
                adjustedAmountRaw: finalAdjustedData.final,
                adjustedAmountFormatted: eaoFormatPrice(finalAdjustedData.final),
                originalAmountRaw: selectedRateRawAmountEAO,
                originalAmountFormatted: eaoFormatPrice(selectedRateRawAmountEAO),
                adjustmentType: finalAdjustedData.type,
                adjustmentValue: finalAdjustedData.value,
                rate_id: rateId,
                method_title: eaoFormatRateForDisplay(serviceName),
                carrier_code: carrierCode,
                service_code: serviceCode
            };

            if (window.eaoDebugSS) { console.log('[EAO ShipStation Debug] Creating eaoPendingShipstationRate:', JSON.parse(JSON.stringify(window.eaoPendingShipstationRate))); }
            
            // Trigger the shipping rate applied event to integrate with change detection system
            jQuery(document).trigger('eaoShippingRateApplied');
        },

        /**
         * Test ShipStation connection with detailed diagnostics
         */
        testShipStationConnection: function(isAutomatic = false) {
            var $button = jQuery('.eao-shipstation-test-connection');
            var $statusText = jQuery('#eao-connection-status-text');
            var $debugBox = jQuery('#eao-shipstation-connection-debug');
            var $errorDetails = jQuery('#eao-shipstation-error-details');
            
            if (window.eaoDebugSS) { console.log('[EAO ShipStation Debug] Starting connection test, isAutomatic:', isAutomatic); }
            
            if (!isAutomatic) {
                $button.prop('disabled', true).text('Testing...');
            }
            
            $errorDetails.hide();
            $debugBox.hide();
            
            if (window.eaoDebugSS) { console.log('[EAO ShipStation Debug] Making AJAX request to eao_shipstation_v2_test_connection'); }
            
            // Get AJAX URL
            var ajaxUrl = (typeof eaoShipStationParams !== 'undefined' && eaoShipStationParams.ajaxUrl) ? 
                         eaoShipStationParams.ajaxUrl : 
                         (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php');
            
            jQuery.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'eao_shipstation_v2_test_connection',
                    eao_nonce: (typeof eaoShipStationParams !== 'undefined' && eaoShipStationParams.nonce) ? eaoShipStationParams.nonce : ''
                },
                timeout: 30000,
                beforeSend: function() {
                    if (window.eaoDebugSS) { console.log('[EAO ShipStation Debug] AJAX request starting...'); }
                },
                success: function(response) {
                    if (window.eaoDebugSS) { console.log('[EAO ShipStation Debug] Connection test response:', response); }
                    
                    if (response.success) {
                        $statusText.text('Connected').css('color', 'green');
                        if (window.eaoDebugSS) { console.log('[EAO ShipStation Debug] Connection test successful'); }
                    } else {
                        $statusText.text('Connection failed').css('color', 'red');
                        if (window.eaoDebugSS) { console.error('[EAO ShipStation Debug] Connection test failed:', response.data); }
                        
                        // Show error details if available
                        if (response.data && response.data.message) {
                            $debugBox.html('<p style="color: red;"><strong>Error:</strong> ' + response.data.message + '</p>').show();
                        }
                    }
                },
                error: function(xhr, status, error) {
                    if (window.eaoDebugSS) { console.error('[EAO ShipStation Debug] Connection test AJAX error:', status, error); }
                    $statusText.text('Network error').css('color', 'red');
                    $debugBox.html('<p style="color: red;"><strong>Network Error:</strong> ' + error + '</p>').show();
                },
                complete: function() {
                    if (!isAutomatic) {
                        $button.prop('disabled', false).text('Test Connection');
                    }
                }
            });
        },

        /**
         * Auto-test connection on page load if credentials exist
         */
        autoTestConnection: function() {
            // Only auto-test if we have indication that credentials exist
            if (jQuery('.eao-shipstation-test-connection').length > 0) {
                if (window.eaoDebugSS) { console.log('[EAO ShipStation Debug] Auto-testing connection...'); }
                setTimeout(() => {
                    this.testShipStationConnection(true);
                }, 1000);
            }
        },

        /**
         * Calculate adjusted rate based on adjustment type and values
         */
        calculateAdjustedRate: function() {
            var adjustmentType = jQuery('input[name="eao_adjustment_type"]:checked').val();
            var selectedRate = jQuery('input[name="eao_shipstation_rate"]:checked');
            
            if (!selectedRate.length) return;
            
            var baseAmount = parseFloat(selectedRate.data('amount-raw')) || 0;
            var finalAmount = baseAmount;
            var adjustmentValue = 0;

            if (adjustmentType === 'percentage_discount') {
                adjustmentValue = parseFloat(jQuery('#eao-adjustment-percentage-value').val()) || 0;
                finalAmount = baseAmount * (1 - (Math.max(0, Math.min(100, adjustmentValue)) / 100));
            } else if (adjustmentType === 'fixed_discount') {
                adjustmentValue = parseFloat(jQuery('#eao-adjustment-fixed-value').val()) || 0;
                finalAmount = baseAmount - Math.max(0, adjustmentValue);
            }

            finalAmount = Math.max(0, finalAmount);
            jQuery('#eao-shipstation-final-rate-display').text('$' + finalAmount.toFixed(2));

            return {
                final: finalAmount,
                type: adjustmentType,
                value: adjustmentValue
            };
        }
    };

    // Initialize when document is ready
    jQuery(document).ready(function() {
        if (window.eaoDebugSS) { console.log('[EAO ShipStation Debug] Document ready - initializing ShipStation metabox'); }
        
        // Small delay to ensure all page elements are fully loaded
        setTimeout(function() {
            window.EAO.ShipStationMetabox.init();
        }, 100);
        
        // Add rate selection functionality
        jQuery(document).on('change', 'input[name="eao_shipstation_rate"]', function() {
            var $selected = jQuery(this);
            if ($selected.is(':checked')) {
                var selectedRateRawAmountEAO = parseFloat($selected.data('amount-raw'));
                jQuery('.eao-shipstation-rate-adjustment-section, .eao-shipstation-rates-apply').show();
                jQuery('input[name="eao_adjustment_type"][value="no_adjustment"]').prop('checked', true).trigger('change');
                
                // Store current rate data
                window.currentAppliedRate = {
                    rate: selectedRateRawAmountEAO,
                    title: $selected.data('service-name'),
                    carrier: $selected.data('carrier-code'),
                    service: $selected.data('service-code')
                };
                
                // Calculate adjusted rate
                window.EAO.ShipStationMetabox.calculateAdjustedRate();
            }
        });
        
        // Rate adjustment functionality
        jQuery('input[name="eao_adjustment_type"]').on('change', function() {
            var type = jQuery(this).val();
            
            jQuery('#eao-adjustment-input-percentage, #eao-adjustment-input-fixed').hide();
            
            if (type === 'percentage_discount') {
                jQuery('#eao-adjustment-input-percentage').show();
            } else if (type === 'fixed_discount') {
                jQuery('#eao-adjustment-input-fixed').show();
            }
            
            window.EAO.ShipStationMetabox.calculateAdjustedRate();
        });

        jQuery('#eao-adjustment-percentage-value, #eao-adjustment-fixed-value').on('input', function() {
            window.EAO.ShipStationMetabox.calculateAdjustedRate();
        });
    });

})(jQuery); 