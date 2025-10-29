/**
 * Enhanced Admin Order - Addresses Management Module
 * Version: 2.5.0
 * Author: Amnon Manneberg
 * 
 * v2.5.0: MAJOR MILESTONE - JavaScript refactor phase complete with comprehensive address management
 * CRITICAL FIX v2.2.8: Multiple approaches to resolve jQuery context issue in clearPendingAddresses
 * - Added global eaoClearPendingAddresses() function to bypass module context issues
 * - Enhanced error handling with emergency fallbacks and vanilla DOM manipulation
 * - Improved standalone clearPendingAddresses with jQuery availability checks
 * 
 * Handles all customer address management, address field population, 
 * pending address changes, and address-related UI interactions.
 */

(function($) {
    'use strict';
    
    // Initialize the EAO namespace if not already present
    if (typeof window.EAO === 'undefined') {
        window.EAO = {};
    }

    // Address Management Module
    window.EAO.Addresses = {
        // Module state
        customerAddresses: {},
        currentCustomerId: 0,
        pendingBillingAddress: null,
        pendingShippingAddress: null,
        initialBillingAddress: {},
        initialShippingAddress: {},
        
        // jQuery selectors (will be initialized)
        $billingAddressDisplay: null,
        $shippingAddressDisplay: null,
        $billingPrevAddressDisplayContainer: null,
        $shippingPrevAddressDisplayContainer: null,
        $billingPrevAddressValue: null,
        $shippingPrevAddressValue: null,
        $billingFieldsInputWrapper: null,
        $shippingFieldsInputWrapper: null,
        $billingAddressSelect: null,
        $shippingAddressSelect: null,
        $billingSaveAddressButton: null,
        $shippingSaveAddressButton: null,

        /**
         * Extract numeric index from an address key (e.g., th_shipping_address_4 → 4)
         */
        extractIndexFromKey: function(key) {
            if (!key) return '';
            const match = String(key).match(/(\d+)$/);
            return match ? match[1] : '';
        },

        /**
         * Build dropdown label as: "<index> - <first_name> - <address_1>"
         */
        buildDropdownLabel: function(type, key, address) {
            const nf = address && address.normalized_fields ? address.normalized_fields : {};
            const first = nf.first_name || address.first_name || nf[type + '_first_name'] || address[type + '_first_name'] || '';
            const addr1 = nf.address_1 || address.address_1 || nf[type + '_address_1'] || address[type + '_address_1'] || '';
            const idx = this.extractIndexFromKey(key);
            const prefix = idx || key || (type.charAt(0).toUpperCase() + type.slice(1));
            let label = `${prefix} - ${first}`;
            if (addr1) label += ` - ${addr1}`;
            return label;
        },

        /**
         * Build address HTML with the badge/default indicator as the last line
         */
        buildAddressDisplayHtml: function(type, data, keyForBadge) {
            if (!data) return '';
            const nf = data.normalized_fields ? data.normalized_fields : {};
            const rf = data.raw_th_fields ? data.raw_th_fields : {};
            const emailVal = data.email || nf.email || nf.shipping_email || nf.billing_email || '';
            const phoneVal = data.phone
                || nf[(type + '_phone')] || nf.shipping_phone || nf.billing_phone
                || rf[(type + '_phone')] || rf.shipping_phone || rf.billing_phone || '';

            let text = `${data.first_name || ''} ${data.last_name || ''}`.trim();
            if (data.company) text += `<br>${data.company}`;
            if (data.address_1) text += `<br>${data.address_1}`;
            if (data.address_2) text += `<br>${data.address_2}`;
            if (data.city) text += `<br>${data.city}`;
            if (data.state) text += `${data.city ? ', ' : '<br>'}${data.state}`;
            if (data.postcode) text += ` ${data.postcode}`;
            if (data.country) text += `<br>${data.country}`;
            if (emailVal) text += `<br>Email: ${emailVal}`;
            if (phoneVal) text += `<br>Phone: ${phoneVal}`;

            const idx = this.extractIndexFromKey(keyForBadge);
            const isDefault = !keyForBadge || keyForBadge === 'default_meta' || keyForBadge === 'default';
            const badge = idx ? `<span class="eao-address-badge" title="Address book entry ${idx}">${idx}</span>` : '';
            const def = isDefault ? `<span class="eao-address-default-pill" title="Using default address">Default</span>` : '';
            if (badge || def) {
                text += `<br><span class=\"eao-address-key-line\">${badge}${def}</span>`;
            }
            return text;
        },
        
        /**
         * Initialize the address management module
         */
        init: function() {
            // Initialize jQuery selectors
            this.$billingAddressDisplay = $('#eao-billing-address-section .eao-current-address-display');
            this.$shippingAddressDisplay = $('#eao-shipping-address-section .eao-current-address-display');
            this.$billingPrevAddressDisplayContainer = $('#eao-billing-address-section .eao-previous-address-display');
            this.$shippingPrevAddressDisplayContainer = $('#eao-shipping-address-section .eao-previous-address-display');
            this.$billingPrevAddressValue = $('#eao-billing-address-section .eao-previous-address-value');
            this.$shippingPrevAddressValue = $('#eao-shipping-address-section .eao-previous-address-value');
            this.$billingFieldsInputWrapper = $('#eao-billing-fields-inputs');
            this.$shippingFieldsInputWrapper = $('#eao-shipping-fields-inputs');
            this.$billingAddressSelect = $('#eao_billing_address_select');
            this.$shippingAddressSelect = $('#eao_shipping_address_select');
            this.$billingSaveAddressButton = $('.eao-save-address-button[data-address-type="billing"]');
            this.$shippingSaveAddressButton = $('.eao-save-address-button[data-address-type="shipping"]');
            // Initialize address-book checkbox defaults and globals (default true if not present)
            window.pendingBillingUpdateAddressBook = $('#eao_billing_update_address_book').length ? $('#eao_billing_update_address_book').is(':checked') : true;
            window.pendingShippingUpdateAddressBook = $('#eao_shipping_update_address_book').length ? $('#eao_shipping_update_address_book').is(':checked') : true;
            
            // Initialize state from global variables
            this.customerAddresses = window.eaoEditorParams ? (window.eaoEditorParams.customer_addresses || { billing: {}, shipping: {} }) : { billing: {}, shipping: {} };
            this.currentCustomerId = window.eaoEditorParams ? (window.eaoEditorParams.initial_customer_id || 0) : 0;
            
            // Expose state globally for template access
            window.pendingBillingAddress = this.pendingBillingAddress;
            window.pendingShippingAddress = this.pendingShippingAddress;
            
            // Bind event handlers
            this.bindEventHandlers();
            
            // Initialize dropdowns if customer exists
            if (this.currentCustomerId) {
                this.populateAddressDropdown('billing', this.customerAddresses.billing || {});
                this.populateAddressDropdown('shipping', this.customerAddresses.shipping || {});
            }
            // Ensure initial badges/Default pill appear on first load using injected keys or hidden inputs
            this.applyInitialBadges();
            // Safety recheck in case template scripts populate keys slightly after init
            setTimeout(this.applyInitialBadges.bind(this), 150);
            
            // Debug initial keys (disabled)
            try {
                const billHidden = document.getElementById('eao_billing_address_key');
                const shipHidden = document.getElementById('eao_shipping_address_key');
                // console.log('[EAO Address DEBUG][init] hidden.bill=', billHidden ? billHidden.value : 'none', 'hidden.ship=', shipHidden ? shipHidden.value : 'none', 'params.bill=', (window.eaoEditorParams && window.eaoEditorParams.initial_billing_address_key) || 'empty', 'params.ship=', (window.eaoEditorParams && window.eaoEditorParams.initial_shipping_address_key) || 'empty');
            } catch(e) {}
        },

        /**
         * Append badge/default pill on initial page load based on injected initial keys
         */
        applyInitialBadges: function() {
            try {
                let billKey = (window.eaoEditorParams && window.eaoEditorParams.initial_billing_address_key) ? window.eaoEditorParams.initial_billing_address_key : '';
                let shipKey = (window.eaoEditorParams && window.eaoEditorParams.initial_shipping_address_key) ? window.eaoEditorParams.initial_shipping_address_key : '';
                // Fallback to hidden inputs rendered by template (most reliable on first load)
                const billHidden = document.getElementById('eao_billing_address_key');
                const shipHidden = document.getElementById('eao_shipping_address_key');
                if (!billKey && billHidden && billHidden.value) billKey = billHidden.value;
                if (!shipKey && shipHidden && shipHidden.value) shipKey = shipHidden.value;
                // Backfill globals for consistency
                if (window.eaoEditorParams) {
                    if (!window.eaoEditorParams.initial_billing_address_key && billKey) window.eaoEditorParams.initial_billing_address_key = billKey;
                    if (!window.eaoEditorParams.initial_shipping_address_key && shipKey) window.eaoEditorParams.initial_shipping_address_key = shipKey;
                }

                // Billing
                if (this.$billingAddressDisplay && this.$billingAddressDisplay.length) {
                    const already = this.$billingAddressDisplay.find('.eao-address-badge, .eao-address-default-pill').length > 0;
                    if (!already) {
                        const idxB = this.extractIndexFromKey(billKey);
                        const pillB = (!billKey || billKey === 'default_meta' || billKey === 'default');
                        const tailB = pillB
                            ? '<br><span class="eao-address-key-line"><span class="eao-address-default-pill" title="Using default address">Default</span></span>'
                            : (idxB ? '<br><span class="eao-address-key-line"><span class="eao-address-badge" title="Address book entry ' + idxB + '">' + idxB + '</span></span>' : '');
                        if (tailB) {
                            this.$billingAddressDisplay.append(tailB);
                        }
                    }
                }

                // Shipping
                if (this.$shippingAddressDisplay && this.$shippingAddressDisplay.length) {
                    const alreadyS = this.$shippingAddressDisplay.find('.eao-address-badge, .eao-address-default-pill').length > 0;
                    if (!alreadyS) {
                        const idxS = this.extractIndexFromKey(shipKey);
                        const pillS = (!shipKey || shipKey === 'default_meta' || shipKey === 'default');
                        const tailS = pillS
                            ? '<br><span class="eao-address-key-line"><span class="eao-address-default-pill" title="Using default address">Default</span></span>'
                            : (idxS ? '<br><span class="eao-address-key-line"><span class="eao-address-badge" title="Address book entry ' + idxS + '">' + idxS + '</span></span>' : '');
                        if (tailS) {
                            this.$shippingAddressDisplay.append(tailS);
                        }
                    }
                }
                // console.log('[EAO Address DEBUG][applyInitialBadges] billKey=', billKey || 'empty', 'shipKey=', shipKey || 'empty');
            } catch (e) {
                // swallow
            }
        },
        
        /**
         * Populate address dropdown with available addresses
         */
        populateAddressDropdown: function(type, addressesForType) {
            const $select = (type === 'billing') ? this.$billingAddressSelect : this.$shippingAddressSelect;
            $select.empty().append('<option value="">Choose an address...</option>');
            if (addressesForType) {
                const self = this;
                $.each(addressesForType, function(key, address) {
                    const label = self.buildDropdownLabel(type, key, address);
                    $select.append('<option value="' + key + '">' + label + '</option>');
                });
            }
            $select.trigger('change.select2'); // Update Select2 if used
        },
        
        /**
         * Populate address fields with data
         */
        populateAddressFields: function(addressType, addressData) {
            // If addressData comes from a ThemeHigh source, the actual fields might be in a nested 'normalized_fields' object,
            // or the keys might be prefixed (e.g., billing_first_name). Default WC addresses have direct, non-prefixed keys.
            const fieldsToUse = addressData && addressData.normalized_fields ? addressData.normalized_fields : (addressData || {});
            const rawFields = addressData && addressData.raw_th_fields ? addressData.raw_th_fields : {};

            // Populate all fields we have values for
            for (const key in fieldsToUse) {
                if (!Object.prototype.hasOwnProperty.call(fieldsToUse, key)) continue;
                // Special handling for country/state: these inputs use WC IDs without the eao_ prefix
                if (key === 'country') {
                    const countryVal = fieldsToUse[key] || '';
                    const $country = $('#' + addressType + '_country');
                    const $countryAlt = $('#eao_' + addressType + '_country'); // fallback if present in older markup
                    if ($country && $country.length) {
                        $country.val(countryVal).trigger('change');
                    } else if ($countryAlt && $countryAlt.length) {
                        $countryAlt.val(countryVal).trigger('change');
                    }
                    continue;
                }
                if (key === 'state') {
                    const stateVal = fieldsToUse[key] || '';
                    // Delay to allow country change handlers (wc_country_select) to rebuild state options
                    setTimeout(function(){
                        const $state = $('#' + addressType + '_state');
                        const $stateAlt = $('#eao_' + addressType + '_state');
                        if ($state && $state.length) {
                            $state.val(stateVal).trigger('change');
                        } else if ($stateAlt && $stateAlt.length) {
                            $stateAlt.val(stateVal).trigger('change');
                        }
                    }, 50);
                    continue;
                }

                const $inputField = $('#eao_' + addressType + '_' + key);
                if ($inputField && $inputField.length) {
                    if (key === 'phone') {
                        // Phone should reflect ONLY the selected address entry (no default fallback)
                        const phoneVal = fieldsToUse.phone
                            || fieldsToUse[addressType + '_phone']
                            || rawFields[addressType + '_phone']
                            || rawFields.shipping_phone
                            || rawFields.billing_phone
                            || '';
                        $inputField.val(phoneVal);
                    } else {
                        $inputField.val(fieldsToUse[key]);
                    }
                }
            }
        },

        /**
         * Fetch customer addresses from server
         */
        fetchCustomerAddresses: function(customerIdToFetch, updateFieldsIfVisible = false) {
            const self = this;
            $.ajax({
                url: window.eaoEditorParams.ajax_url,
                type: 'POST',
                data: {
                    action: 'eao_get_customer_addresses',
                    nonce: window.eaoEditorParams.get_addresses_nonce,
                    customer_id: customerIdToFetch
                },
                dataType: 'json',
                success: function(response) {
                    if (response && response.success && response.data && response.data.addresses) {
                        self.customerAddresses = response.data.addresses; // Store all addresses (billing & shipping)
                        try {
                            const billHidden0 = document.getElementById('eao_billing_address_key');
                            const shipHidden0 = document.getElementById('eao_shipping_address_key');
                            // console.log('[EAO Address DEBUG][fetch] got addresses. keys:', {
                            //     billingKeys: Object.keys(self.customerAddresses.billing || {}),
                            //     shippingKeys: Object.keys(self.customerAddresses.shipping || {})
                            // }, 'hidden.bill=', billHidden0 ? billHidden0.value : 'none', 'hidden.ship=', shipHidden0 ? shipHidden0.value : 'none', 'params.bill=', (window.eaoEditorParams && window.eaoEditorParams.initial_billing_address_key) || 'empty', 'params.ship=', (window.eaoEditorParams && window.eaoEditorParams.initial_shipping_address_key) || 'empty');
                        } catch(e) {}
                        
                        // Populate dropdowns
                        self.populateAddressDropdown('billing', self.customerAddresses.billing || {});
                        self.populateAddressDropdown('shipping', self.customerAddresses.shipping || {});

                        // Update the main display areas and populate input fields
                        const defaultBilling = self.customerAddresses.billing && self.customerAddresses.billing.default ? self.customerAddresses.billing.default : null;
                        const defaultShipping = self.customerAddresses.shipping && self.customerAddresses.shipping.default ? self.customerAddresses.shipping.default : null;

                        // Resolve initial keys from params or hidden inputs
                        const initBillKey = (window.eaoEditorParams && window.eaoEditorParams.initial_billing_address_key) ? window.eaoEditorParams.initial_billing_address_key : (document.getElementById('eao_billing_address_key') ? document.getElementById('eao_billing_address_key').value : '');
                        const initShipKey = (window.eaoEditorParams && window.eaoEditorParams.initial_shipping_address_key) ? window.eaoEditorParams.initial_shipping_address_key : (document.getElementById('eao_shipping_address_key') ? document.getElementById('eao_shipping_address_key').value : '');

                        // Pick the address entry matching the key if available; otherwise fallback to default
                        const billingEntry = (initBillKey && self.customerAddresses.billing && self.customerAddresses.billing[initBillKey]) ? self.customerAddresses.billing[initBillKey] : defaultBilling;
                        const shippingEntry = (initShipKey && self.customerAddresses.shipping && self.customerAddresses.shipping[initShipKey]) ? self.customerAddresses.shipping[initShipKey] : defaultShipping;
                        // console.log('[EAO Address DEBUG][render] initBillKey=', initBillKey || 'empty', 'foundBilling=', !!billingEntry, 'initShipKey=', initShipKey || 'empty', 'foundShipping=', !!shippingEntry);

                        // --- Process Billing Address ---
                        if (billingEntry) {
                            const keyForBadgeB = initBillKey || 'default';
                            const htmlB = self.buildAddressDisplayHtml('billing', billingEntry, keyForBadgeB);
                            self.$billingAddressDisplay.html(htmlB || (window.eaoEditorParams.i18n.no_address_found || 'N/A'));
                            self.populateAddressFields('billing', billingEntry || {});
                            // Ensure hidden input reflects the active key for first render
                            try { $('#eao_billing_address_key').val(keyForBadgeB || 'default_meta'); /* console.log('[EAO Address DEBUG][render] set hidden bill key ->', keyForBadgeB || 'default_meta'); */ } catch(e) {}
                        } else {
                            self.$billingAddressDisplay.html('<p>' + (window.eaoEditorParams.i18n.no_address_found || 'No address found') + '</p>');
                            self.populateAddressFields('billing', {});
                        }

                        // --- Process Shipping Address ---
                        if (shippingEntry) {
                            const keyForBadgeS = initShipKey || 'default';
                            const htmlS = self.buildAddressDisplayHtml('shipping', shippingEntry, keyForBadgeS);
                            self.$shippingAddressDisplay.html(htmlS || (window.eaoEditorParams.i18n.no_address_found || 'N/A'));
                            self.populateAddressFields('shipping', shippingEntry || {});
                            // Ensure hidden input reflects the active key for first render
                            try { $('#eao_shipping_address_key').val(keyForBadgeS || 'default_meta'); /* console.log('[EAO Address DEBUG][render] set hidden ship key ->', keyForBadgeS || 'default_meta'); */ } catch(e) {}
                        } else {
                            self.$shippingAddressDisplay.html('<p>' + (window.eaoEditorParams.i18n.no_address_found || 'No address found') + '</p>');
                            self.populateAddressFields('shipping', {});
                        }
                    } else if (response && !response.success) {
                        alert('Error fetching customer addresses: ' + (response.data && response.data.message ? response.data.message : 'Unknown server error.'));
                        // Clear displays and dropdowns as data is problematic
                        self.$billingAddressDisplay.html('<p class="eao-error-text">' + (window.eaoEditorParams.i18n.no_address_found || 'No address found') + ' (server error)</p>');
                        self.$shippingAddressDisplay.html('<p class="eao-error-text">' + (window.eaoEditorParams.i18n.no_address_found || 'No address found') + ' (server error)</p>');
                        self.populateAddressDropdown('billing', {});
                        self.populateAddressDropdown('shipping', {});
                    } else {
                        alert('Unexpected response format from server when fetching addresses.');
                        // Clear displays and dropdowns
                        self.$billingAddressDisplay.html('<p class="eao-error-text">' + (window.eaoEditorParams.i18n.no_address_found || 'No address found') + ' (format error)</p>');
                        self.$shippingAddressDisplay.html('<p class="eao-error-text">' + (window.eaoEditorParams.i18n.no_address_found || 'No address found') + ' (format error)</p>');
                        self.populateAddressDropdown('billing', {});
                        self.populateAddressDropdown('shipping', {});
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    alert('Failed to fetch customer addresses. Please try again. Details: ' + textStatus + ' - ' + errorThrown);
                    // Clear displays and dropdowns
                    self.$billingAddressDisplay.html('<p class="eao-error-text">' + (window.eaoEditorParams.i18n.no_address_found || 'No address found') + ' (AJAX error)</p>');
                    self.$shippingAddressDisplay.html('<p class="eao-error-text">' + (window.eaoEditorParams.i18n.no_address_found || 'No address found') + ' (AJAX error)</p>');
                    self.populateAddressDropdown('billing', {});
                    self.populateAddressDropdown('shipping', {});
                },
                complete: function() {
                    // Potentially hide a global loading indicator if you have one
                }
            });
        },
        
        /**
         * Update customer addresses when customer changes
         */
        updateCustomerAddresses: function(newCustomerId) {
            this.currentCustomerId = newCustomerId;
            
            // Clear current displays
            this.$billingAddressDisplay.html(window.eaoEditorParams.i18n.loading_address || 'Loading address...');
            this.$shippingAddressDisplay.html(window.eaoEditorParams.i18n.loading_address || 'Loading address...');
            this.populateAddressFields('billing', {}); 
            this.populateAddressFields('shipping', {}); 
            this.$billingPrevAddressDisplayContainer.hide().find('.eao-previous-address-value').empty();
            this.$shippingPrevAddressDisplayContainer.hide().find('.eao-previous-address-value').empty();
            this.pendingBillingAddress = null;
            this.pendingShippingAddress = null;
            this.populateAddressDropdown('billing', {}); 
            this.populateAddressDropdown('shipping', {});

            // Fetch new addresses
            this.fetchCustomerAddresses(newCustomerId, true);
        },
        
        /**
         * Bind event handlers for address functionality
         */
        bindEventHandlers: function() {
            const self = this;
            // Edit Address Button Click
            $('.eao-edit-address-button').on('click', function() {
                const $button = $(this);
                const type = $button.data('address-type');

                if (!type) {
                    return;
                }

                const $fieldsWrapper = (type === 'billing') ? self.$billingFieldsInputWrapper : self.$shippingFieldsInputWrapper;
                const $saveButton = (type === 'billing') ? self.$billingSaveAddressButton : self.$shippingSaveAddressButton;
                
                $fieldsWrapper.toggle();
                // Explicitly control Save button visibility to avoid edge cases where it stays hidden
                if ($fieldsWrapper.is(':visible')) {
                    $saveButton.show();
                } else {
                    $saveButton.hide();
                }

                const editText = (window.eaoEditorParams.i18n && window.eaoEditorParams.i18n.edit) ? window.eaoEditorParams.i18n.edit : 'Edit';
                const cancelEditText = (window.eaoEditorParams.i18n && window.eaoEditorParams.i18n.cancel_edit) ? window.eaoEditorParams.i18n.cancel_edit : 'Cancel Edit';

                if ($fieldsWrapper && $fieldsWrapper.length > 0 && $fieldsWrapper.is(':visible')) {
                    // Ensure address-book checkbox exists (fallback injection if template cached)
                    (function(){
                        const checkboxId = (type === 'billing') ? 'eao_billing_update_address_book' : 'eao_shipping_update_address_book';
                        if ($('#' + checkboxId).length === 0) {
                            const label = 'Update customer address book';
                            const html = `<p style="margin:6px 0 10px 0;">
                                <label style=\"display:inline-flex;align-items:center;gap:6px;\">\n                                    <input type=\"checkbox\" id=\"${checkboxId}\" name=\"${checkboxId}\" value=\"yes\" checked>\n                                    <span>${label}</span>\n                                </label>\n                            </p>`;
                            $fieldsWrapper.prepend(html);
                        }
                    })();
                    $button.text(cancelEditText);
                    // If opening the editor and there's a pending address, load it.
                    // Otherwise, the fields will show the current order address (or be blank if new)
                    if (type === 'billing' && self.pendingBillingAddress) {
                        self.populateAddressFields('billing', self.pendingBillingAddress);
                    } else if (type === 'shipping' && self.pendingShippingAddress) {
                        self.populateAddressFields('shipping', self.pendingShippingAddress);
                    } else {
                        // When opening for the first time (no pending address for this session yet), populate with initial data
                        // This ensures that if the main order data was reverted by "Cancel Changes", these fields also revert.
                        if (window.EAO && window.EAO.ChangeDetection && window.EAO.ChangeDetection.initialOrderData) {
                            const addrToPopulate = (type === 'billing') ? window.EAO.ChangeDetection.initialOrderData.billing_address_fields : window.EAO.ChangeDetection.initialOrderData.shipping_address_fields;
                            self.populateAddressFields(type, addrToPopulate);
                        }
                    }
                } else {
                    $button.text(editText);
                    // If cancelling, revert fields to the currently displayed order address (not pending)
                    // This might need adjustment if we want to revert to *initial* order address
                    if (window.EAO && window.EAO.ChangeDetection && window.EAO.ChangeDetection.initialOrderData) {
                        const currentOrderAddress = (type === 'billing') ? window.EAO.ChangeDetection.initialOrderData.billing_address : window.EAO.ChangeDetection.initialOrderData.shipping_address;
                        self.populateAddressFields(type, currentOrderAddress); 
                    }
                    // Also clear any 'changed' styling from individual fields if cancelling
                    const fieldsContainerId = (type === 'billing') ? 'eao-billing-fields-inputs' : 'eao-shipping-fields-inputs';
                    $('#' + fieldsContainerId + ' .form-field input, #' + fieldsContainerId + ' .form-field select, #' + fieldsContainerId + ' .form-field textarea').each(function() {
                        $(this).removeClass('eao-field-changed');
                        $(this).closest('.form-field').find('.eao-previous-value').hide().text('');
                    });
                }
                if (window.EAO && window.EAO.ChangeDetection) {
                    window.EAO.ChangeDetection.triggerCheck(); // Re-evaluate as visibility of changed fields might alter overall "changed" state conceptually
                }
            });

            // Address dropdown change handler
            $('.eao-address-select').on('change', function() {
                const type = $(this).data('address-type');
                const selectedAddressKey = $(this).val();
                const $addressDisplay = (type === 'billing') ? self.$billingAddressDisplay : self.$shippingAddressDisplay;
                const $prevAddressDisplayContainer = (type === 'billing') ? self.$billingPrevAddressDisplayContainer : self.$shippingPrevAddressDisplayContainer;
                const $prevAddressValue = (type === 'billing') ? self.$billingPrevAddressValue : self.$shippingPrevAddressValue;

                if (selectedAddressKey && self.customerAddresses[type] && self.customerAddresses[type][selectedAddressKey]) {
                    const selectedAddressData = self.customerAddresses[type][selectedAddressKey];
                    
                    // Store current address as "previous"
                    const oldFormattedAddress = $addressDisplay.html();
                    $prevAddressValue.html(oldFormattedAddress);
                    $prevAddressDisplayContainer.show();

                    // Update displayed address with new formatted address
                    if (selectedAddressData.formatted_address) {
                        // CRITICAL: formatted_address from WC typically excludes email/phone.
                        // Append them when available (checking normalized_fields fallbacks too).
                        const nf = selectedAddressData.normalized_fields ? selectedAddressData.normalized_fields : {};
                        const rf = selectedAddressData.raw_th_fields ? selectedAddressData.raw_th_fields : {};
                        // Align with fluent multi-address: prefer type-specific phone when present
                        const emailVal = selectedAddressData.email || nf.email || nf.shipping_email || nf.billing_email || '';
                        // Show phone ONLY if present on the selected entry; do not fallback to defaults
                        const phoneVal = selectedAddressData.phone
                            || nf[(type + '_phone')] || nf.shipping_phone || nf.billing_phone
                            || rf[(type + '_phone')] || rf.shipping_phone || rf.billing_phone || '';
                        const composed = self.buildAddressDisplayHtml(type, selectedAddressData, selectedAddressKey || 'default');
                        $addressDisplay.html(composed);
                    } else {
                        // Fallback: generate a more detailed display string if formatted_address is not available
                        const fallbackHtml = self.buildAddressDisplayHtml(type, selectedAddressData, selectedAddressKey || 'default');
                        $addressDisplay.html(fallbackHtml || 'Address details could not be fully displayed.');
                    }

                    self.populateAddressFields(type, selectedAddressData); // This updates the hidden input fields
                    
                    // Set pending address when dropdown selection changes
                    if (type === 'billing') {
                        self.pendingBillingAddress = selectedAddressData.normalized_fields ? selectedAddressData.normalized_fields : selectedAddressData; 
                        window.pendingBillingAddress = self.pendingBillingAddress; // Sync global reference
                    } else if (type === 'shipping') {
                        self.pendingShippingAddress = selectedAddressData.normalized_fields ? selectedAddressData.normalized_fields : selectedAddressData;
                        window.pendingShippingAddress = self.pendingShippingAddress; // Sync global reference
                    }
                    
                    // If input fields are visible, ensure they reflect the new selection and remove field-specific "Was:"
                    const $fieldsWrapper = (type === 'billing') ? self.$billingFieldsInputWrapper : self.$shippingFieldsInputWrapper;
                if ($fieldsWrapper && $fieldsWrapper.length > 0 && $fieldsWrapper.is(':visible')) {
                    // Ensure address-book checkbox exists (fallback injection if template cached)
                    const checkboxId = (type === 'billing') ? 'eao_billing_update_address_book' : 'eao_shipping_update_address_book';
                    if ($('#' + checkboxId).length === 0) {
                        const label = 'Update customer address book';
                        const html = `<p style="margin:6px 0 10px 0;">
                            <label style="display:inline-flex;align-items:center;gap:6px;">
                                <input type="checkbox" id="${checkboxId}" name="${checkboxId}" value="yes" checked>
                                <span>${label}</span>
                            </label>
                        </p>`;
                        $fieldsWrapper.prepend(html);
                    }
                        $fieldsWrapper.find('.eao-address-input').removeClass('eao-field-changed');
                        $fieldsWrapper.find('.eao-previous-value').hide().text('');
                    }
                // Ensure the small Save button becomes visible when we enter edit mode via dropdown change (if open)
                try {
                    const $saveBtn = (type === 'billing') ? self.$billingSaveAddressButton : self.$shippingSaveAddressButton;
                    if ($fieldsWrapper && $fieldsWrapper.is(':visible')) { $saveBtn.show(); }
                } catch(e) {}

                // Mirror update-address-book checkbox into globals for submission
                try {
                    if (type === 'billing') {
                        window.pendingBillingUpdateAddressBook = $('#eao_billing_update_address_book').is(':checked');
                    } else {
                        window.pendingShippingUpdateAddressBook = $('#eao_shipping_update_address_book').is(':checked');
                    }
                } catch(e) {}

                // Persist current key in hidden inputs and update globals so edit form knows we are editing a saved address
                if (type === 'billing') {
                        $('#eao_billing_address_key').val(selectedAddressKey || 'default_meta');
                    window.pendingBillingAddressKey = selectedAddressKey || '';
                    } else if (type === 'shipping') {
                        $('#eao_shipping_address_key').val(selectedAddressKey || 'default_meta');
                    window.pendingShippingAddressKey = selectedAddressKey || '';
                    }

                } else if (selectedAddressKey === "") { // "Choose an address..." selected
                    // Optionally revert to original order address if "Choose an address" is selected
                    // For now, do nothing, let user edit fields or save.
                }
                if (window.EAO && window.EAO.ChangeDetection) {
                    window.EAO.ChangeDetection.triggerCheck();
                }
            });

            // Save Address (Local/Pending) button handler
            $('.eao-save-address-button').off('click').on('click', function() {
                const $button = $(this);
                const type = $button.data('address-type');

                if (!type) {
                    return;
                }

                const $fieldsWrapper = (type === 'billing') ? self.$billingFieldsInputWrapper : self.$shippingFieldsInputWrapper;
                const $editButton = $('.eao-edit-address-button[data-address-type="' + type + '"]');
                const $addressDisplay = (type === 'billing') ? self.$billingAddressDisplay : self.$shippingAddressDisplay;
                const $prevAddressDisplayContainer = (type === 'billing') ? self.$billingPrevAddressDisplayContainer : self.$shippingPrevAddressDisplayContainer;
                const $prevAddressDisplay = (type === 'billing') ? self.$billingPrevAddressValue : self.$shippingPrevAddressValue;

                // 1. Get current field values
                let currentFieldValues = {};
                if (window.EAO && window.EAO.ChangeDetection) {
                    currentFieldValues = window.EAO.ChangeDetection.getAddressFieldsData(type);
                }

                // 2. Store them in the respective pending variable
                if (type === 'billing') {
                    const originalForWas = $addressDisplay.html(); 
                    $prevAddressDisplay.html(originalForWas);
                    $prevAddressDisplayContainer.show();

                    self.pendingBillingAddress = currentFieldValues;
                    window.pendingBillingAddress = self.pendingBillingAddress; // Sync global reference
                    window.pendingBillingAddressKey = $('#eao_billing_address_key').val() || '';
                    window.pendingBillingUpdateAddressBook = ($('#eao_billing_update_address_book').length ? $('#eao_billing_update_address_book').is(':checked') : true);
                    
                    let displayStrBilling = `${currentFieldValues.first_name || ''} ${currentFieldValues.last_name || ''}`.trim();
                    if (currentFieldValues.company) displayStrBilling += `<br>${currentFieldValues.company}`;
                    if (currentFieldValues.address_1) displayStrBilling += `<br>${currentFieldValues.address_1}`;
                    if (currentFieldValues.address_2) displayStrBilling += `<br>${currentFieldValues.address_2}`;
                    if (currentFieldValues.city) displayStrBilling += `<br>${currentFieldValues.city}`;
                    
                    let stateDisplayBilling = currentFieldValues.state || '';
                    let countryDisplayBilling = currentFieldValues.country || '';
                    if (window.eaoEditorParams.wc_countries && window.eaoEditorParams.wc_countries[currentFieldValues.country]) {
                        countryDisplayBilling = window.eaoEditorParams.wc_countries[currentFieldValues.country];
                    }

                    if (stateDisplayBilling) {
                        displayStrBilling += `${currentFieldValues.city ? ', ' : '<br>'}${stateDisplayBilling}`;
                    }
                    if (currentFieldValues.postcode) displayStrBilling += ` ${currentFieldValues.postcode}`;
                    if (countryDisplayBilling) displayStrBilling += `<br>${countryDisplayBilling}`;
                    if (currentFieldValues.email) displayStrBilling += `<br>Email: ${currentFieldValues.email}`;
                    if (currentFieldValues.phone) displayStrBilling += `<br>Phone: ${currentFieldValues.phone}`;
                    const badgeIdxB = self.extractIndexFromKey(window.pendingBillingAddressKey || $('#eao_billing_address_key').val());
                    if (badgeIdxB) {
                        displayStrBilling = `<div class=\"eao-address-key-line\"><span class=\"eao-address-badge\" title=\"Address book entry ${badgeIdxB}\">${badgeIdxB}</span></div>` + displayStrBilling;
                    }
                    $addressDisplay.html(displayStrBilling.replace(/^<br>|<br>$/g,'') || 'Address details entered.');

                } else if (type === 'shipping') {
                    const originalForWas = $addressDisplay.html();
                    $prevAddressDisplay.html(originalForWas);
                    $prevAddressDisplayContainer.show();

                    self.pendingShippingAddress = currentFieldValues;
                    window.pendingShippingAddress = self.pendingShippingAddress; // Sync global reference
                    window.pendingShippingAddressKey = $('#eao_shipping_address_key').val() || '';
                    window.pendingShippingUpdateAddressBook = ($('#eao_shipping_update_address_book').length ? $('#eao_shipping_update_address_book').is(':checked') : true);

                    let displayStrShipping = `${currentFieldValues.first_name || ''} ${currentFieldValues.last_name || ''}`.trim();
                    if (currentFieldValues.company) displayStrShipping += `<br>${currentFieldValues.company}`;
                    if (currentFieldValues.address_1) displayStrShipping += `<br>${currentFieldValues.address_1}`;
                    if (currentFieldValues.address_2) displayStrShipping += `<br>${currentFieldValues.address_2}`;
                    if (currentFieldValues.city) displayStrShipping += `<br>${currentFieldValues.city}`;

                    let stateDisplayShipping = currentFieldValues.state || '';
                    let countryDisplayShipping = currentFieldValues.country || '';
                    if (window.eaoEditorParams.wc_countries && window.eaoEditorParams.wc_countries[currentFieldValues.country]) {
                        countryDisplayShipping = window.eaoEditorParams.wc_countries[currentFieldValues.country];
                    }

                    if (stateDisplayShipping) {
                        displayStrShipping += `${currentFieldValues.city ? ', ' : '<br>'}${stateDisplayShipping}`;
                    }
                    if (currentFieldValues.postcode) displayStrShipping += ` ${currentFieldValues.postcode}`;
                    if (countryDisplayShipping) displayStrShipping += `<br>${countryDisplayShipping}`;
                    if (currentFieldValues.email) displayStrShipping += `<br>Email: ${currentFieldValues.email}`;
                    if (currentFieldValues.phone) displayStrShipping += `<br>Phone: ${currentFieldValues.phone}`;
                    const badgeIdxS3 = self.extractIndexFromKey(window.pendingShippingAddressKey || $('#eao_shipping_address_key').val());
                    if (badgeIdxS3) {
                        displayStrShipping = `<div class=\"eao-address-key-line\"><span class=\"eao-address-badge\" title=\"Address book entry ${badgeIdxS3}\">${badgeIdxS3}</span></div>` + displayStrShipping;
                    }
                    $addressDisplay.html(displayStrShipping.replace(/^<br>|<br>$/g,'') || 'Address details entered.');
                }

                // 3. Hide input fields, hide self (Save Address button)
                $fieldsWrapper.hide();
                $button.hide();

                // 4. Change "Cancel Edit" button back to "Edit"
                const editText = (window.eaoEditorParams.i18n && window.eaoEditorParams.i18n.edit) ? window.eaoEditorParams.i18n.edit : 'Edit';
                $editButton.text(editText);
                
                // 5. Clear any 'changed' styling from individual fields since they are now "saved" to pending
                const fieldsContainerId = (type === 'billing') ? 'eao-billing-fields-inputs' : 'eao-shipping-fields-inputs';
                $('#' + fieldsContainerId + ' .form-field input, #' + fieldsContainerId + ' .form-field select, #' + fieldsContainerId + ' .form-field textarea').each(function() {
                    $(this).removeClass('eao-field-changed');
                    $(this).closest('.form-field').find('.eao-previous-value').hide().text('');
                });

                // 6. Re-run change detection
                if (window.EAO && window.EAO.ChangeDetection) {
                    window.EAO.ChangeDetection.triggerCheck();
                }
            });
            
            // Initialize Select2 for address dropdowns if needed
            if ($.fn.select2) {
                $('.eao-address-select').select2({
                    width: '100%'
                });
            }
        },
        
        /**
         * Refresh selectors (in case DOM changed)
         */
        refreshSelectors: function() {
            // CRITICAL FIX: Ensure jQuery is properly scoped
            const $ = jQuery;
            
            this.$billingAddressDisplay = $('#eao-billing-address-section .eao-current-address-display');
            this.$shippingAddressDisplay = $('#eao-shipping-address-section .eao-current-address-display');
            this.$billingPrevAddressDisplayContainer = $('#eao-billing-address-section .eao-previous-address-display');
            this.$shippingPrevAddressDisplayContainer = $('#eao-shipping-address-section .eao-previous-address-display');
            this.$billingPrevAddressValue = $('#eao-billing-address-section .eao-previous-address-value');
            this.$shippingPrevAddressValue = $('#eao-shipping-address-section .eao-previous-address-value');
            this.$billingFieldsInputWrapper = $('#eao-billing-fields-inputs');
            this.$shippingFieldsInputWrapper = $('#eao-shipping-fields-inputs');
            this.$billingAddressSelect = $('#eao_billing_address_select');
            this.$shippingAddressSelect = $('#eao_shipping_address_select');
        },
        
        /**
         * Clear pending addresses
         */
        clearPendingAddresses: function() {
            // console.log('[EAO Addresses] clearPendingAddresses called');
            
            try {
                // CRITICAL: Ensure jQuery is properly scoped
                const $ = jQuery;
                
                // Reset pending addresses and UI elements
                this.pendingBillingAddress = null;
                this.pendingShippingAddress = null;
                window.pendingBillingAddress = null;
                window.pendingShippingAddress = null;
                
                // Hide previous address display containers
                if (this.$billingPrevAddressDisplayContainer) {
                    this.$billingPrevAddressDisplayContainer.hide();
                }
                if (this.$shippingPrevAddressDisplayContainer) {
                    this.$shippingPrevAddressDisplayContainer.hide();
                }
                
                // Clear previous address values
                if (this.$billingPrevAddressValue) {
                    this.$billingPrevAddressValue.text('');
                }
                if (this.$shippingPrevAddressValue) {
                    this.$shippingPrevAddressValue.text('');
                }
                
            } catch (err) {
                // console.error('[EAO Addresses] Error in clearPendingAddresses:', err);
            }
        },
        
        /**
         * Reset addresses to initial state
         */
        resetToInitialAddresses: function() {
            if (window.EAO && window.EAO.ChangeDetection && window.EAO.ChangeDetection.initialOrderData) {
                const initialData = window.EAO.ChangeDetection.initialOrderData;
                
                // Revert address fields and display
                this.$billingAddressDisplay.html(initialData.billing_address_formatted_html);
                this.$shippingAddressDisplay.html(initialData.shipping_address_formatted_html);
                this.$billingPrevAddressDisplayContainer.hide();
                this.$shippingPrevAddressDisplayContainer.hide();

                this.populateAddressFields('billing', initialData.billing_address_fields);
                this.populateAddressFields('shipping', initialData.shipping_address_fields);

                // Hide input field wrappers
                this.$billingFieldsInputWrapper.hide();
                this.$shippingFieldsInputWrapper.hide();
                $('.eao-edit-address-button[data-address-type="billing"]').text('Edit');
                $('.eao-edit-address-button[data-address-type="shipping"]').text('Edit');
                
                // Clear pending addresses
                this.clearPendingAddresses();
            }
        }
    };

    // Backward compatibility - expose functions globally as they were before
    window.populateAddressDropdown = function(type, addressesForType) {
        if (window.EAO && window.EAO.Addresses) {
            return window.EAO.Addresses.populateAddressDropdown(type, addressesForType);
        }
        console.error('[EAO Addresses] Module not available for populateAddressDropdown');
    };

    window.populateAddressFields = function(addressType, addressData) {
        if (window.EAO && window.EAO.Addresses) {
            return window.EAO.Addresses.populateAddressFields(addressType, addressData);
        }
        console.error('[EAO Addresses] Module not available for populateAddressFields');
    };

    window.fetchCustomerAddresses = function(customerIdToFetch, updateFieldsIfVisible = false) {
        if (window.EAO && window.EAO.Addresses) {
            return window.EAO.Addresses.fetchCustomerAddresses(customerIdToFetch, updateFieldsIfVisible);
        }
        console.error('[EAO Addresses] Module not available for fetchCustomerAddresses');
    };

    // CRITICAL FIX: Expose clearPendingAddresses as global function to avoid context issues
    window.eaoClearPendingAddresses = function() {
        // console.log('[EAO Global] eaoClearPendingAddresses called');
        
        try {
            // Always clear global variables first
            window.pendingBillingAddress = null;
            window.pendingShippingAddress = null;
            
            // Check if jQuery is available in current context
            if (typeof jQuery !== 'undefined') {
                const $ = jQuery;
                // console.log('[EAO Global] jQuery available, clearing UI elements');
                
                // DEBUG: Check if elements exist before trying to hide them
                const $billingPrevDisplay = $('#eao-billing-address-section .eao-previous-address-display');
                const $shippingPrevDisplay = $('#eao-shipping-address-section .eao-previous-address-display');
                const $billingButton = $('.eao-edit-address-button[data-address-type="billing"]');
                const $shippingButton = $('.eao-edit-address-button[data-address-type="shipping"]');
                
                // console.log('[EAO Global] Element search results:', $billingPrevDisplay.length, $shippingPrevDisplay.length, $billingButton.length, $shippingButton.length);
                
                // Check if any are currently visible
                // console.log('[EAO Global] Current visibility:', $billingPrevDisplay.is(':visible'), $shippingPrevDisplay.is(':visible'));
                
                // Clear UI elements using jQuery
                $billingPrevDisplay.hide();
                $shippingPrevDisplay.hide();
                $billingButton.text('Edit');
                $shippingButton.text('Edit');
                
                // Verify they were hidden
                // console.log('[EAO Global] After hiding:', $billingPrevDisplay.is(':visible'), $shippingPrevDisplay.is(':visible'));
                
                // console.log('[EAO Global] UI clearing completed successfully');
            } else {
                // console.log('[EAO Global] jQuery not available, using vanilla DOM');
                
                // Fallback to vanilla DOM
                const billingContainer = document.querySelector('#eao-billing-address-section .eao-previous-address-display');
                const shippingContainer = document.querySelector('#eao-shipping-address-section .eao-previous-address-display');
                
                // console.log('[EAO Global] Vanilla DOM search results:', !!billingContainer, !!shippingContainer);
                
                if (billingContainer) {
                    // console.log('  - Billing container current display:', billingContainer.style.display || 'default');
                    billingContainer.style.display = 'none';
                    // console.log('  - Billing container after hide:', billingContainer.style.display);
                }
                if (shippingContainer) {
                    // console.log('  - Shipping container current display:', shippingContainer.style.display || 'default');
                    shippingContainer.style.display = 'none';
                    // console.log('  - Shipping container after hide:', shippingContainer.style.display);
                }
                
                const billingButton = document.querySelector('.eao-edit-address-button[data-address-type="billing"]');
                const shippingButton = document.querySelector('.eao-edit-address-button[data-address-type="shipping"]');
                
                if (billingButton) billingButton.textContent = 'Edit';
                if (shippingButton) shippingButton.textContent = 'Edit';
                
                // console.log('[EAO Global] Vanilla DOM clearing completed');
            }
            
            // Clear module instance if available
            if (window.EAO && window.EAO.Addresses) {
                window.EAO.Addresses.pendingBillingAddress = null;
                window.EAO.Addresses.pendingShippingAddress = null;
            }
            
            // console.log('[EAO Global] All pending addresses cleared successfully');
            return true; // CRITICAL FIX: Return success value for template
            
        } catch (error) {
            // console.error('[EAO Global] Error in eaoClearPendingAddresses:', error);
            
            // Ultimate minimal fallback
            window.pendingBillingAddress = null;
            window.pendingShippingAddress = null;
            // console.log('[EAO Global] Minimal clearing completed despite error');
            return false; // Return failure for template error handling
        }
    };

    // console.log('[EAO Addresses] Module loaded successfully');
    // console.log('[EAO Addresses] Global function created:', typeof window.eaoClearPendingAddresses);
})(jQuery); 