/**
 * Enhanced Admin Order - Fluent Support JavaScript Module
 * 
 * Handles frontend functionality for Fluent Support integration including
 * ticket creation, customer ticket retrieval, and UI interactions.
 * 
 * @package EnhancedAdminOrder
 * @since 2.6.0
 * @version 2.6.33 - Fixed field overrides with PHP_INT_MAX priority and added show more functionality
 * @author Amnon Manneberg
 */

(function($) {
    'use strict';
    
    // Ensure EAO namespace exists
    if (typeof window.EAO === 'undefined') {
        window.EAO = {};
    }
    
    /**
     * Fluent Support Module
     */
    window.EAO.FluentSupport = {
        
        /**
         * Module initialization
         */
        init: function() {
            // Check if localized data is available
            if (typeof eaoFluentSupport === 'undefined') {
                console.error('[EAO Fluent Support] eaoFluentSupport object not found - localization failed');
                this.showFallbackError();
                return;
            }
            
            console.log('[EAO Fluent Support] Localized data found:', eaoFluentSupport);
            
            this.bindEvents();
            this.initializeUI();
            
            console.log('[EAO Fluent Support] Module initialized');
        },
        
        /**
         * Show error when localization fails
         */
        showFallbackError: function() {
            var $container = $('#eao-fluent-support-container');
            if ($container.length > 0) {
                $container.html('<div class="notice notice-error"><p><strong>Fluent Support Integration Error:</strong> Script localization failed. Please refresh the page.</p></div>');
            }
        },
        
        /**
         * Bind event handlers
         */
        bindEvents: function() {
            var self = this;
            
            // Create ticket button
            $(document).on('click', '#eao-fs-create-ticket-btn', function(e) {
                e.preventDefault();
                self.createTicket();
            });
            
            // Refresh tickets button
            $(document).on('click', '#eao-fs-refresh-tickets', function(e) {
                e.preventDefault();
                var customerEmail = $(this).data('customer-email');
                self.loadCustomerTickets(customerEmail);
            });
            
            // Form validation on input
            $(document).on('input', '#eao-fs-ticket-subject, #eao-fs-ticket-content', function() {
                self.validateForm();
            });
            
            // Auto-load tickets when metabox is first opened
            $(document).ready(function() {
                // Small delay to ensure everything is loaded
                setTimeout(function() {
                    self.autoLoadTickets();
                }, 500);
            });
        },
        
        /**
         * Initialize UI components
         */
        initializeUI: function() {
            this.validateForm();
            this.setupTooltips();
        },
        
        /**
         * Auto-load tickets if customer email is available
         */
        autoLoadTickets: function() {
            var customerEmail = $('#eao-fs-refresh-tickets').data('customer-email');
            if (customerEmail && customerEmail.length > 0) {
                this.loadCustomerTickets(customerEmail);
            }
        },
        
        /**
         * Update customer tickets when customer changes (real-time, mirrors address pattern)
         */
        updateCustomerTickets: function(newCustomerId) {
            console.log('[EAO Fluent Support] Updating tickets for customer ID:', newCustomerId);
            
            // Clear current ticket display
            $('#eao-fs-tickets-list').html('Loading tickets for new customer...');
            
            if (!newCustomerId || newCustomerId == '0') {
                // Guest customer - clear tickets and update data attribute
                $('#eao-fs-tickets-list').html('<p>No customer selected</p>');
                $('#eao-fs-refresh-tickets').data('customer-email', '').attr('data-customer-email', '');
                return;
            }
            
            // Fetch customer email via AJAX and then load tickets
            var self = this;
            $.ajax({
                url: eaoFluentSupport.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'eao_get_customer_email_by_id',
                    customer_id: newCustomerId,
                    nonce: eaoFluentSupport.nonce
                },
                success: function(response) {
                    if (response.success && response.data.email) {
                        console.log('[EAO Fluent Support] Got customer email:', response.data.email);
                        // CRITICAL FIX: Update both data() and attr() to ensure persistence through page operations
                        $('#eao-fs-refresh-tickets').data('customer-email', response.data.email).attr('data-customer-email', response.data.email);
                        console.log('[EAO Fluent Support] Updated refresh button data-customer-email to:', response.data.email);
                        // Load tickets for new customer
                        self.loadCustomerTickets(response.data.email);
                    } else {
                        console.warn('[EAO Fluent Support] Could not get customer email:', response);
                        $('#eao-fs-tickets-list').html('<p>Could not load customer data</p>');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[EAO Fluent Support] Error getting customer email:', error);
                    $('#eao-fs-tickets-list').html('<p>Error loading customer data</p>');
                }
            });
        },
        
        /**
         * Create support ticket
         */
        createTicket: function() {
            var self = this;
            var $form = $('#eao-fluent-support-container');
            var $button = $('#eao-fs-create-ticket-btn');
            var $loading = $('#eao-fs-create-loading');
            var $result = $('#eao-fs-create-result');
            
            // Check if localized data is available
            if (typeof eaoFluentSupport === 'undefined') {
                console.error('[EAO Fluent Support] eaoFluentSupport not available during ticket creation');
                this.showResult('error', 'Script configuration error. Please refresh the page.');
                return;
            }
            
            // Get form data
            // Prefer WYSIWYG value if present; fallback to textarea
            var editorField = window.tinyMCE && tinyMCE.get('eao-fs-ticket-content');
            var contentVal = editorField && !editorField.isHidden() ? editorField.getContent() : $('#eao-fs-ticket-content').val();
            var formData = {
                action: 'eao_create_fluent_support_ticket',
                nonce: eaoFluentSupport.nonce,
                order_id: $button.data('order-id'),
                subject: $('#eao-fs-ticket-subject').val().trim(),
                content: contentVal ? contentVal.trim() : '',
                priority: $('#eao-fs-ticket-priority').val()
            };
            
            // Validate required fields
            if (!formData.subject || !formData.content) {
                this.showResult('error', 'Please fill in all required fields.');
                return;
            }
            
            // Show loading state
            $button.prop('disabled', true);
            $loading.show();
            $result.hide();
            
            // Make AJAX request
            $.ajax({
                url: eaoFluentSupport.ajaxUrl,
                type: 'POST',
                data: formData,
                timeout: 30000,
                success: function(response) {
                    if (response.success) {
                        console.log('[EAO Fluent Support] TICKET CREATED SUCCESSFULLY!', response);
                        
                        // Show simple success message inline with button
                        self.showInlineResult('success', 'Ticket created successfully!');
                        self.clearForm();
                        
                        // Refresh tickets list
                        var customerEmail = $('#eao-fs-refresh-tickets').data('customer-email');
                        if (customerEmail) {
                            setTimeout(function() {
                                console.log('[EAO Fluent Support] Refreshing tickets list after 1 second...');
                                self.loadCustomerTickets(customerEmail);
                            }, 1000);
                        }
                        
                    } else {
                        console.error('[EAO Fluent Support] TICKET CREATION FAILED:', response);
                        self.showResult('error', response.data.message || eaoFluentSupport.strings.error);
                    }
                },
                error: function(xhr, textStatus, errorThrown) {
                    console.error('[EAO Fluent Support] Create ticket error:', textStatus, errorThrown);
                    
                    var errorMessage = eaoFluentSupport.strings.networkError;
                    if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        errorMessage = xhr.responseJSON.data.message;
                    }
                    
                    self.showResult('error', errorMessage);
                },
                complete: function() {
                    $button.prop('disabled', false);
                    $loading.hide();
                }
            });
        },
        
        /**
         * Load customer tickets
         */
        loadCustomerTickets: function(customerEmail) {
            var self = this;
            var $ticketsList = $('#eao-fs-tickets-list');
            var $loading = $('#eao-fs-tickets-loading');
            var $refreshBtn = $('#eao-fs-refresh-tickets');
            
            // Check if localized data is available
            if (typeof eaoFluentSupport === 'undefined') {
                console.error('[EAO Fluent Support] eaoFluentSupport not available during loadCustomerTickets');
                $ticketsList.html('<p class="eao-fs-no-tickets">Script configuration error. Please refresh the page.</p>');
                return;
            }
            
            if (!customerEmail) {
                $ticketsList.html('<p class="eao-fs-no-tickets">No customer email available.</p>');
                return;
            }
            
            // Show loading state
            $loading.show();
            $ticketsList.hide();
            $refreshBtn.prop('disabled', true);
            
            // Get order ID for context
            var orderId = $('#eao-fs-create-ticket-btn').data('order-id');
            
            // Make AJAX request
            $.ajax({
                url: eaoFluentSupport.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'eao_get_customer_tickets',
                    nonce: eaoFluentSupport.nonce,
                    customer_email: customerEmail,
                    order_id: orderId
                },
                timeout: 30000,
                success: function(response) {
                    if (response.success) {
                        self.displayTickets(response.data.tickets);
                    } else {
                        $ticketsList.html('<p class="eao-fs-no-tickets">Error loading tickets: ' + 
                            (response.data.message || 'Unknown error') + '</p>');
                    }
                },
                error: function(xhr, textStatus, errorThrown) {
                    console.error('[EAO Fluent Support] Load tickets error:', textStatus, errorThrown);
                    
                    var errorMessage = 'Error loading tickets.';
                    if (textStatus === 'timeout') {
                        errorMessage = 'Request timed out. Please try again.';
                    } else if (textStatus === 'error') {
                        errorMessage = 'Network error. Please check your connection.';
                    } else if (xhr.status === 403) {
                        errorMessage = 'Permission denied. Please refresh the page.';
                    }
                    
                    $ticketsList.html('<p class="eao-fs-no-tickets">' + errorMessage + '</p>');
                },
                complete: function() {
                    $loading.hide();
                    $ticketsList.show();
                    $refreshBtn.prop('disabled', false);
                }
            });
        },
        
        /**
         * Display tickets in the UI with show more functionality
         */
        displayTickets: function(tickets) {
            var $ticketsList = $('#eao-fs-tickets-list');
            
            if (!tickets || tickets.length === 0) {
                $ticketsList.html('<p class="eao-fs-no-tickets">' + 
                    eaoFluentSupport.strings.noTickets + '</p>');
                return;
            }
            
            var self = this;
            var showLimit = 3;
            var totalTickets = tickets.length;
            var html = '';
            
            // Show first 3 tickets
            for (var i = 0; i < Math.min(showLimit, totalTickets); i++) {
                var ticket = tickets[i];
                html += this.buildTicketHtml(ticket);
            }
            
            // Add hidden tickets if there are more than 3
            if (totalTickets > showLimit) {
                var hiddenHtml = '';
                for (var i = showLimit; i < totalTickets; i++) {
                    var ticket = tickets[i];
                    hiddenHtml += this.buildTicketHtml(ticket);
                }
                
                var remainingCount = totalTickets - showLimit;
                html += '<div id="eao-fs-hidden-tickets" style="display: none;">' + hiddenHtml + '</div>';
                html += '<div id="eao-fs-show-more-container" style="text-align: center; margin: 15px 0;">' +
                       '<button type="button" id="eao-fs-show-more-btn" class="button button-secondary">' +
                       'Show more (' + remainingCount + ')' +
                       '</button></div>';
            }
            
            $ticketsList.html(html);
            
            // Bind show more button click
            $('#eao-fs-show-more-btn').on('click', function() {
                $('#eao-fs-hidden-tickets').slideDown();
                $('#eao-fs-show-more-container').hide();
            });
        },
        
        /**
         * Build HTML for a single ticket
         */
        buildTicketHtml: function(ticket) {
            var statusClass = 'eao-fs-ticket-status ' + ticket.status;
            var isOrderRelated = ticket.is_order_related ? ' <strong>(This Order)</strong>' : '';
            
            // Handle date formatting more reliably
            var createdDate = 'Unknown Date';
            if (ticket.created_at) {
                // Check if it's already a Date object
                if (ticket.created_at instanceof Date) {
                    createdDate = ticket.created_at.toLocaleDateString();
                } else if (typeof ticket.created_at === 'object') {
                    // If it's an object but not a Date, try to extract date properties
                    if (ticket.created_at.date) {
                        // Laravel-style date object
                        var dateObj = new Date(ticket.created_at.date);
                        if (!isNaN(dateObj.getTime())) {
                            createdDate = dateObj.toLocaleDateString();
                        } else {
                            createdDate = 'Invalid Date Format';
                        }
                    } else {
                        createdDate = 'Invalid Date Object';
                    }
                } else {
                    // It's a string or other primitive
                    var dateObj = new Date(ticket.created_at);
                    if (!isNaN(dateObj.getTime())) {
                        createdDate = dateObj.toLocaleDateString();
                    } else if (typeof ticket.created_at === 'string') {
                        // Try parsing as ISO format
                        var isoDate = ticket.created_at.replace(' ', 'T');
                        dateObj = new Date(isoDate);
                        if (!isNaN(dateObj.getTime())) {
                            createdDate = dateObj.toLocaleDateString();
                        } else {
                            createdDate = ticket.created_at; // Show raw string
                        }
                    } else {
                        createdDate = ticket.created_at.toString();
                    }
                }
            }
            
            return '<div class="eao-fs-ticket-item">' +
                '<h5><a href="' + ticket.url + '" target="_blank">' + 
                this.escapeHtml(ticket.title) + '</a>' + isOrderRelated + '</h5>' +
                '<div class="eao-fs-ticket-meta">' +
                '<span class="' + statusClass + '">' + ticket.status + '</span> | ' +
                'Priority: ' + ticket.priority + ' | ' +
                'Created: ' + createdDate +
                '</div>' +
                '</div>';
        },
        
        /**
         * Show result message
         */
        showResult: function(type, message) {
            var $result = $('#eao-fs-create-result');
            $result.removeClass('success error')
                   .addClass(type)
                   .html(this.escapeHtml(message))
                   .show();
            
            // Auto-hide success messages
            if (type === 'success') {
                setTimeout(function() {
                    $result.fadeOut();
                }, 5000);
            }
        },

        /**
         * Show inline result message next to button
         */
        showInlineResult: function(type, message) {
            var $button = $('#eao-fs-create-ticket-btn');
            var $existing = $('.eao-fs-inline-message');
            
            // Remove any existing inline message
            $existing.remove();
            
            // Create inline message
            var $inlineMsg = $('<span class="eao-fs-inline-message ' + type + '" style="margin-left: 10px; font-weight: 500;">' + 
                this.escapeHtml(message) + '</span>');
            
            // Add colors
            if (type === 'success') {
                $inlineMsg.css('color', '#2e7d32');
            } else {
                $inlineMsg.css('color', '#c62828');
            }
            
            // Insert after button
            $button.after($inlineMsg);
            
            // Auto-hide after 3 seconds
            setTimeout(function() {
                $inlineMsg.fadeOut();
            }, 3000);
        },
        
        /**
         * Clear the ticket creation form
         */
        clearForm: function() {
            $('#eao-fs-ticket-content').val('');
            $('#eao-fs-ticket-priority').val('normal');
            
            // Don't clear subject as it contains the default order reference
            this.validateForm();
        },
        
        /**
         * Validate form and update button state
         */
        validateForm: function() {
            var subject = $('#eao-fs-ticket-subject').val().trim();
            var content;
            var editor = window.tinyMCE && tinyMCE.get('eao-fs-ticket-content');
            if (editor && !editor.isHidden()) {
                content = editor.getContent({ format: 'raw' }).trim();
            } else {
                content = $('#eao-fs-ticket-content').val().trim();
            }
            var $button = $('#eao-fs-create-ticket-btn');
            
            var isValid = subject.length > 0 && content.length > 0;
            $button.prop('disabled', !isValid);
            
            return isValid;
        },
        
        /**
         * Setup tooltips and help text
         */
        setupTooltips: function() {
            // Add any tooltip initialization here if needed
            // For now, keep it simple without external tooltip libraries
        },
        
        /**
         * Escape HTML to prevent XSS
         */
        escapeHtml: function(text) {
            if (!text) return '';
            
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            
            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
        },
        
        /**
         * Utility function to get order ID from current page
         */
        getCurrentOrderId: function() {
            return $('#eao-fs-create-ticket-btn').data('order-id') || 0;
        },
        
        /**
         * Utility function to get customer email
         */
        getCustomerEmail: function() {
            return $('#eao-fs-refresh-tickets').data('customer-email') || '';
        },
        
        /**
         * Handle integration errors gracefully
         */
        handleError: function(context, error) {
            console.error('[EAO Fluent Support] Error in ' + context + ':', error);
            
            // You could add error reporting to server here if needed
            // this.reportError(context, error);
        },
        
        /**
         * Check if integration is working
         */
        checkStatus: function() {
            var self = this;
            
            $.ajax({
                url: eaoFluentSupport.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'eao_get_fluent_support_status',
                    nonce: eaoFluentSupport.nonce
                },
                success: function(response) {
                    if (response.success) {
                        console.log('[EAO Fluent Support] Status check:', response.data);
                        self.updateStatusDisplay(response.data);
                    }
                },
                error: function(xhr, textStatus, errorThrown) {
                    console.error('[EAO Fluent Support] Status check failed:', textStatus);
                }
            });
        },
        
        /**
         * Update status display in UI (removed since status is now in title)
         */
        updateStatusDisplay: function(statusData) {
            // Status is now displayed in metabox title, no longer needed here
            console.log('[EAO Fluent Support] Status update:', statusData);
        }
    };
    
    // Initialize when document is ready
    $(document).ready(function() {
        console.log('[EAO Fluent Support] Document ready, checking for metabox...');
        console.log('[EAO Fluent Support] Looking for #eao-fluent-support-container');
        console.log('[EAO Fluent Support] Container elements found:', $('#eao-fluent-support-container').length);
        console.log('[EAO Fluent Support] Any eao-fluent elements:', $('[id*="eao-fluent"]').length);
        console.log('[EAO Fluent Support] Current page URL:', window.location.href);
        
        // Check if we're on an order edit page with Fluent Support metabox
        if ($('#eao-fluent-support-container').length > 0) {
            console.log('[EAO Fluent Support] Metabox found! Initializing module...');
            window.EAO.FluentSupport.init();
        } else {
            console.log('[EAO Fluent Support] No metabox found, checking reasons...');
            
            // Debug what metaboxes are available
            console.log('[EAO Fluent Support] Available metaboxes (' + $('.postbox').length + ' total):');
            $('.postbox').each(function(index, element) {
                var title = $(element).find('h2, .hndle').text().trim();
                console.log(' - "' + title + '" (ID: ' + element.id + ')');
            });
            
            // Check for any fluent-related elements
            console.log('[EAO Fluent Support] Any fluent-related elements:');
            $('[id*="fluent"], [class*="fluent"]').each(function(index, element) {
                console.log(' - ' + element.tagName + ' (ID: ' + element.id + ', Class: ' + element.className + ')');
            });
            
            // Try delayed initialization in case metabox loads later
            setTimeout(function() {
                console.log('[EAO Fluent Support] Delayed check (1s) - Container elements:', $('#eao-fluent-support-container').length);
                if ($('#eao-fluent-support-container').length > 0) {
                    console.log('[EAO Fluent Support] Found metabox on delayed check, initializing...');
                    window.EAO.FluentSupport.init();
                }
            }, 1000);
        }
    });
    
})(jQuery); 