/**
 * Enhanced Admin Order - Order Notes Module
 * 
 * @package EnhancedAdminOrder
 * @since 2.3.0
 * @version 2.5.1 - Added Show/Hide System Notes filter with persistence
 * @author Amnon Manneberg
 */

(function($) {
    'use strict';

    window.EAO = window.EAO || {};

    window.EAO.OrderNotes = {
        
        // Module state
        nextStagedNoteId: 0,
        initialized: false,
        
        /**
         * Initialize the Order Notes module
         */
        init: function() {
            if (this.initialized) {
                console.warn('[EAO Order Notes] Module already initialized');
                return;
            }

            console.log('[EAO Order Notes] Initializing Order Notes module');
            
            // Initialize staged notes if not already done
            if (!window.stagedNotes) {
                window.stagedNotes = [];
            }
            
            // Initialize the UI
            this.initializeUI();

            // Apply notes visibility preference and bind filter control
            this.initializeSystemNotesFilter();
            
            this.initialized = true;
            console.log('[EAO Order Notes] Order Notes module initialized successfully');
        },

        /**
         * Initialize the order notes UI
         */
        initializeUI: function() {
            const $initialNotesContainer = $('#eao-custom-order-notes .inside');
            if ($initialNotesContainer.length) {
                this.initializeCustomNoteUI($initialNotesContainer);
            } else {
                console.warn('[EAO Order Notes] Notes container not found');
            }
        },

        /**
         * Update staged notes display
         * @param {jQuery} $container - The notes container element
         */
        updateStagedNotesDisplay: function($container) {
            if (!$container || !$container.length) {
                console.warn('[EAO Order Notes] Invalid container for updateStagedNotesDisplay');
                return;
            }
            
            const $stagedNotesContainer = $container.find('#eao-staged-notes-container');
            const $list = $container.find('#eao-staged-notes-list');
            
            if (!$stagedNotesContainer.length) {
                console.warn('[EAO Order Notes] Staged notes container not found');
                return;
            }
            if (!$list.length) {
                $stagedNotesContainer.hide();
                return;
            }

            $list.empty();
            if (!window.stagedNotes || window.stagedNotes.length === 0) {
                $stagedNotesContainer.hide();
                return;
            }
            $stagedNotesContainer.show();

            const self = this;
            window.stagedNotes.forEach(function(note) {
                const noteTypeText = note.is_customer_note ? 'customer' : 'private';
                const $itemWrapper = $('<li>').addClass('eao-pending-note-wrapper');
                const balloonClasses = ['eao-pending-note-item'];
                if (note.is_customer_note) {
                    balloonClasses.push('eao-pending-note-customer');
                } else {
                    balloonClasses.push('eao-pending-note-private');
                }
                const $balloonItem = $('<div>')
                    .addClass(balloonClasses.join(' ')) 
                    .attr('data-staged-note-id', note.id);
                const $contentDiv = $('<div>').addClass('eao-note-content-display').html($('<div/>').text(note.note_content).html());
                $balloonItem.append($contentDiv);
                const $metaActionWrapper = $('<div>').addClass('eao-pending-meta-action-wrapper');
                const $metaP = $('<p>').addClass('eao-pending-note-meta-outside').text('Pending ' + noteTypeText + ' note');
                const $removeButton = $('<button type="button" class="button button-link-delete eao-remove-staged-note">Remove</button>');
                $removeButton.attr('data-staged-note-id', note.id);
                $metaActionWrapper.append($metaP).append($removeButton);
                $itemWrapper.append($balloonItem).append($metaActionWrapper);
                $list.append($itemWrapper);
            });
        },

        /**
         * Initialize custom note UI for a container
         * @param {jQuery} $notesContainer - The notes container element
         */
        initializeCustomNoteUI: function($notesContainer) {
            if (!$notesContainer || !$notesContainer.length) {
                console.warn('[EAO Order Notes] Invalid container for initializeCustomNoteUI');
                return;
            }
            
            const $submitButton = $notesContainer.find('#eao-submit-custom-note');
            const $stagedNotesListElement = $notesContainer.find('#eao-staged-notes-list'); 
            const $noteContentFieldElement = $notesContainer.find('#eao-order-note-content'); 
            const $noteTypeRadioElements = $notesContainer.find('input[name="eao_order_note_type_radio"]');

            // Validate required elements exist
            if (!$submitButton.length) {
                console.warn('[EAO Order Notes] Submit button not found');
            }
            if (!$stagedNotesListElement.length) { 
                console.warn('[EAO Order Notes] Staged notes list not found');
            }
            if (!$noteContentFieldElement.length) {
                console.warn('[EAO Order Notes] Note content field not found');
            }
            if (!$noteTypeRadioElements.length) {
                console.warn('[EAO Order Notes] Note type radio elements not found');
            }

            const self = this;

            // Submit button handler
            $submitButton.off('click.eaoSubmitNote').on('click.eaoSubmitNote', function() {
                const $currentNoteContentField = $notesContainer.find('#eao-order-note-content');
                const noteTypeVal = $notesContainer.find('input[name="eao_order_note_type_radio"]:checked').val();
                const noteContent = $currentNoteContentField.val();

                if (!noteContent.trim()) {
                    $currentNoteContentField.focus();
                    return;
                }
                
                const isCustomerNote = (noteTypeVal === 'customer') ? 1 : 0;
                
                // Initialize staged notes if not exists
                if (!window.stagedNotes) {
                    window.stagedNotes = [];
                }
                
                window.stagedNotes.push({
                    id: self.nextStagedNoteId++,
                    note_content: noteContent,
                    is_customer_note: isCustomerNote
                });

                // Clear form and reset to private
                $currentNoteContentField.val('');
                $notesContainer.find('input[name="eao_order_note_type_radio"][value="private"]').prop('checked', true);
                
                // Update display
                self.updateStagedNotesDisplay($notesContainer);
                
                // Trigger change detection
                if (window.EAO && window.EAO.ChangeDetection) {
                    window.EAO.ChangeDetection.triggerCheck();
                }
            });

            // Remove note handler (delegated)
            if ($stagedNotesListElement.length) {
                $stagedNotesListElement.off('click.eaoRemoveNote').on('click.eaoRemoveNote', 'button.eao-remove-staged-note', function() {
                    const noteIdToRemove = $(this).data('staged-note-id'); 
                    
                    if (!window.stagedNotes) {
                        window.stagedNotes = [];
                    }
                    
                    window.stagedNotes = window.stagedNotes.filter(note => note.id !== noteIdToRemove);
                    
                    if (window.stagedNotes.length === 0) {
                        self.nextStagedNoteId = 0;
                    } else {
                        self.nextStagedNoteId = Math.max(...window.stagedNotes.map(n => n.id)) + 1; 
                    }
                    
                    // Update display
                    self.updateStagedNotesDisplay($notesContainer);
                    
                    // Trigger change detection
                    if (window.EAO && window.EAO.ChangeDetection) {
                        window.EAO.ChangeDetection.triggerCheck();
                    } 
                });
            } else {
                console.warn('[EAO Order Notes] Staged notes list element not found for event binding');
            }
            
            // Initial display update
            this.updateStagedNotesDisplay($notesContainer);

            // Editing handlers for private notes (reuse existing `self` defined above)
            $notesContainer.off('click.eaoEditNote').on('click.eaoEditNote', '.eao-edit-note', function(e){
                e.preventDefault();
                var $a = jQuery(this);
                var noteId = parseInt($a.attr('data-note-id'), 10);
                var orderId = parseInt($a.attr('data-order-id'), 10);
                var $li = $a.closest('li');
                var $content = $li.find('.note_content');
                if ($li.data('editing')) { return; }
                $li.data('editing', true);
                var originalHtml = $content.html();
                var plain = jQuery('<div>').html(originalHtml).text();
                var editor = '<textarea class="eao-note-edit-text" style="width:100%" rows="4">'+ plain +'</textarea>'+
                             '<p style="margin-top:6px">'+
                             '<button type="button" class="button button-primary eao-save-note-edit" data-note-id="'+noteId+'" data-order-id="'+orderId+'">Save</button> '+
                             '<button type="button" class="button eao-cancel-note-edit">Cancel</button></p>';
                $content.data('original-html', originalHtml).html(editor);
            });

            $notesContainer.off('click.eaoCancelEdit').on('click.eaoCancelEdit', '.eao-cancel-note-edit', function(){
                var $li = jQuery(this).closest('li');
                var $content = $li.find('.note_content');
                var orig = $content.data('original-html');
                if (orig !== undefined) { $content.html(orig); }
                $li.data('editing', false);
            });

            $notesContainer.off('click.eaoSaveEdit').on('click.eaoSaveEdit', '.eao-save-note-edit', function(){
                var $btn = jQuery(this);
                var noteId = parseInt($btn.attr('data-note-id'), 10);
                var orderId = parseInt($btn.attr('data-order-id'), 10);
                var $li = $btn.closest('li');
                var newContent = $li.find('.eao-note-edit-text').val();
                if (!newContent || !newContent.trim()) { return; }
                $btn.prop('disabled', true).text('Saving...');
                jQuery.post(eao_ajax.ajax_url, {
                    action: 'eao_edit_order_note',
                    nonce: (eao_ajax && eao_ajax.save_order_nonce) ? eao_ajax.save_order_nonce : '',
                    order_id: orderId,
                    note_id: noteId,
                    content: newContent
                }).done(function(resp){
                    if (resp && resp.success && resp.data && resp.data.notes_html) {
                        var $wrap = jQuery('#eao-existing-notes-list-wrapper');
                        if ($wrap.length) { $wrap.html(resp.data.notes_html); }
                        // Re-bind editing handlers after refresh
                        var $container = jQuery('#eao-custom-order-notes .inside');
                        if ($container.length) { self.initializeCustomNoteUI($container); }
                    }
                }).always(function(){
                    $li.data('editing', false);
                    $btn.prop('disabled', false).text('Save');
                });
            });
        },

        /**
         * Initialize System Notes filter checkbox behavior and persistence
         */
        initializeSystemNotesFilter: function() {
            const self = this;
            const storageKey = 'eao_notes_show_system';

            // Resolve checkbox within our meta box
            const $container = jQuery('#eao-custom-order-notes .inside');
            const $checkbox = $container.find('#eao-notes-show-system');

            // Helper to apply state to DOM
            function applyState(showSystem) {
                const $wrap = jQuery('#eao-existing-notes-list-wrapper');
                if (!$wrap.length) { return; }
                const $notes = $wrap.find('li.system-note');
                if (showSystem) {
                    $notes.show();
                } else {
                    $notes.hide();
                }
            }

            // Load persisted preference (default: show)
            let showSystem = true;
            try {
                const v = window.localStorage.getItem(storageKey);
                if (v === '0') { showSystem = false; }
            } catch (e) {}

            // Set initial checkbox and apply
            if ($checkbox.length) {
                $checkbox.prop('checked', !!showSystem);
                applyState(!!showSystem);

                // Change handler
                $checkbox.off('change.eaoToggleSystemNotes').on('change.eaoToggleSystemNotes', function() {
                    const checked = jQuery(this).is(':checked');
                    try { window.localStorage.setItem(storageKey, checked ? '1' : '0'); } catch (e) {}
                    applyState(checked);
                });
            } else {
                // Even if checkbox missing, still apply preference to current list
                applyState(!!showSystem);
            }

            // Observe notes wrapper for HTML refresh and re-apply
            const target = document.getElementById('eao-existing-notes-list-wrapper');
            if (target && !this._notesObserver) {
                this._notesObserver = new MutationObserver(function() {
                    // Re-bind checkbox (it may have been re-rendered) and apply state
                    const $cb = jQuery('#eao-notes-show-system');
                    if ($cb.length) {
                        $cb.prop('checked', !!showSystem);
                        $cb.off('change.eaoToggleSystemNotes').on('change.eaoToggleSystemNotes', function(){
                            const checked = jQuery(this).is(':checked');
                            try { window.localStorage.setItem(storageKey, checked ? '1' : '0'); } catch (e) {}
                            applyState(checked);
                        });
                    }
                    applyState(!!(jQuery('#eao-notes-show-system').is(':checked') || showSystem));
                });
                this._notesObserver.observe(target, { childList: true, subtree: true });
            }
        },

        /**
         * Add a new staged note programmatically
         * @param {string} noteContent - The note content
         * @param {boolean} isCustomerNote - Whether this is a customer note
         * @returns {Object} The created note object
         */
        addStagedNote: function(noteContent, isCustomerNote = false) {
            if (!noteContent || !noteContent.trim()) {
                console.warn('[EAO Order Notes] Cannot add empty note');
                return null;
            }

            if (!window.stagedNotes) {
                window.stagedNotes = [];
            }

            const note = {
                id: this.nextStagedNoteId++,
                note_content: noteContent.trim(),
                is_customer_note: isCustomerNote ? 1 : 0
            };

            window.stagedNotes.push(note);

            // Update display if container is available
            const $notesContainer = $('#eao-custom-order-notes .inside');
            if ($notesContainer.length) {
                this.updateStagedNotesDisplay($notesContainer);
            }

            // Trigger change detection
            if (window.EAO && window.EAO.ChangeDetection) {
                window.EAO.ChangeDetection.triggerCheck();
            }

            return note;
        },

        /**
         * Remove a staged note by ID
         * @param {number} noteId - The note ID to remove
         * @returns {boolean} Whether the note was removed
         */
        removeStagedNote: function(noteId) {
            if (!window.stagedNotes) {
                return false;
            }

            const originalLength = window.stagedNotes.length;
            window.stagedNotes = window.stagedNotes.filter(note => note.id !== noteId);

            if (window.stagedNotes.length < originalLength) {
                // Reset counter if no notes left
                if (window.stagedNotes.length === 0) {
                    this.nextStagedNoteId = 0;
                } else {
                    this.nextStagedNoteId = Math.max(...window.stagedNotes.map(n => n.id)) + 1; 
                }

                // Update display
                const $notesContainer = $('#eao-custom-order-notes .inside');
                if ($notesContainer.length) {
                    this.updateStagedNotesDisplay($notesContainer);
                }

                // Trigger change detection
                if (window.EAO && window.EAO.ChangeDetection) {
                    window.EAO.ChangeDetection.triggerCheck();
                }

                return true;
            }

            return false;
        },

        /**
         * Clear all staged notes
         */
        clearStagedNotes: function() {
            window.stagedNotes = [];
            this.nextStagedNoteId = 0;

            // Update display
            const $notesContainer = $('#eao-custom-order-notes .inside');
            if ($notesContainer.length) {
                this.updateStagedNotesDisplay($notesContainer);
            }

            // Trigger change detection
            if (window.EAO && window.EAO.ChangeDetection) {
                window.EAO.ChangeDetection.triggerCheck();
            }
        },

        /**
         * Get all staged notes
         * @returns {Array} Array of staged note objects
         */
        getStagedNotes: function() {
            return window.stagedNotes || [];
        },

        /**
         * Reset to initial state (used by cancel operations)
         */
        resetToInitialState: function() {
            this.clearStagedNotes();
            
            // Reset form if available
            const $notesContainer = $('#eao-custom-order-notes .inside');
            if ($notesContainer.length) {
                const $noteContentField = $notesContainer.find('#eao-order-note-content');
                if ($noteContentField.length) {
                    $noteContentField.val('');
                }
                $notesContainer.find('input[name="eao_order_note_type_radio"][value="private"]').prop('checked', true);
            }
        }
    };

    // Backward compatibility - expose functions globally as they were before
    window.updateStagedNotesDisplay = function($container) {
        if (window.EAO && window.EAO.OrderNotes) {
            return window.EAO.OrderNotes.updateStagedNotesDisplay($container);
        }
        console.error('[EAO Order Notes] Module not available for updateStagedNotesDisplay');
    };

    window.initializeCustomNoteUI = function($notesContainer) {
        if (window.EAO && window.EAO.OrderNotes) {
            return window.EAO.OrderNotes.initializeCustomNoteUI($notesContainer);
        }
        console.error('[EAO Order Notes] Module not available for initializeCustomNoteUI');
    };

})(jQuery); 