<?php
/**
 * Enhanced Admin Order - Custom Notes System
 * Handles order notes meta box and related functionality - ELABORATE VERSION from v150
 * 
 * @package EnhancedAdminOrder
 * @since 1.9.1
 * @version 1.9.8 - Added Show/Hide System Notes filter checkbox in UI.
 * @author Amnon Manneberg
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Add Custom Order Notes meta box.
 * 
 * @param WC_Order $order The order object.
 * @since 1.0.0
 */
function eao_add_custom_order_notes_meta_box( $order ) {
    if ( ! is_a( $order, 'WC_Order' ) ) {
        error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] eao_add_custom_order_notes_meta_box: Invalid order object.');
        return;
    }
    $screen_id = get_current_screen()->id;
    error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] Adding Custom Order Notes meta box for screen: ' . $screen_id . ' and order ID: ' . $order->get_id());

    add_meta_box(
        'eao-custom-order-notes', // Unique ID for our meta box
        __( 'Order Notes', 'enhanced-admin-order' ), // Title CHANGED
        'eao_render_custom_order_notes_meta_box_content', // Callback function
        $screen_id, // Screen
        'side', // Context (changed from 'normal' back to 'side')
        'default', // Priority
        array( 'order' => $order ) // Arguments to pass to the callback
    );
    error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] Custom Order Notes meta box ADDED with new title.');
}

/**
 * Renders the content for the custom "Enhanced Order Notes" meta box.
 * Displays existing notes and the custom "add note" UI.
 *
 * @since 1.2.0
 * @version 1.2.1 - Removed delete note link, modified pending notes HTML structure.
 * @param mixed $post_or_order Can be WP_Post or WC_Order. We primarily use $meta_box_args.
 * @param array $meta_box_args Arguments passed from add_meta_box, including our 'order' object.
 */
function eao_render_custom_order_notes_meta_box_content( $post_or_order, $meta_box_args ) {
    $order = isset($meta_box_args['args']['order']) && is_a($meta_box_args['args']['order'], 'WC_Order') 
             ? $meta_box_args['args']['order'] 
             : null;

    if ( ! $order ) {
        // Fallback to try and get order from $post_or_order or URL
        if (is_a($post_or_order, 'WC_Order')) {
            $order = $post_or_order;
        } elseif (is_object($post_or_order) && isset($post_or_order->ID)) {
            $order_check = wc_get_order($post_or_order->ID);
            if (is_a($order_check, 'WC_Order')) $order = $order_check;
        }
        if (!$order) {
             $order_id_from_get = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
            if ($order_id_from_get) $order = wc_get_order($order_id_from_get);
        }
    }

    if ( ! $order ) {
        echo '<p>' . esc_html__( 'Error: Order context not available for Enhanced Order Notes.', 'enhanced-admin-order' ) . '</p>';
        error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] eao_render_custom_order_notes_meta_box_content: Order object not available.');
        return;
    }

    error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] Rendering eao-custom-order-notes content for order ID: ' . $order->get_id());

    // Display our custom "add note" UI (textarea, type, stage button, staged list) first
    // This function already returns HTML, so we echo it.
    $add_note_html = eao_get_custom_add_note_meta_box_content_html( $order );
    error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] eao_render_custom_order_notes_meta_box_content: Add note HTML length: ' . strlen($add_note_html)); // DEBUG
    echo $add_note_html; 

    // Display existing order notes using shared renderer to keep markup consistent across refresh paths
    echo '<div id="eao-existing-notes-list-wrapper">';
    if (function_exists('eao_get_existing_order_notes_list_html')) {
        echo eao_get_existing_order_notes_list_html($order->get_id());
    } else {
        echo '<p>' . esc_html__('Loading notes…', 'enhanced-admin-order') . '</p>';
    }
    echo '</div>';
}

/**
 * Returns HTML for existing order notes list, including edit affordances for private notes.
 *
 * @param int $order_id
 * @return string
 */
function eao_get_existing_order_notes_list_html( $order_id ) {
    $order_id = absint( $order_id );
    if ( ! $order_id ) { return '<p>' . esc_html__( 'There are no notes yet.', 'enhanced-admin-order' ) . '</p>'; }

    $notes = wc_get_order_notes( array(
        'order_id' => $order_id,
        'order_by' => 'date_created',
        'order'    => 'DESC',
    ) );

    ob_start();
    // Checkout customer note (entered during checkout)
    $checkout_note = '';
    $order_obj = wc_get_order( $order_id );
    if ( $order_obj && method_exists( $order_obj, 'get_customer_note' ) ) {
        $checkout_note = trim( (string) $order_obj->get_customer_note() );
    }

    $has_any = ( $checkout_note !== '' ) || ( ! empty( $notes ) );
    if ( $has_any ) {
        echo '<ul class="order_notes">';
        // Render checkout note first if it exists
        if ( $checkout_note !== '' ) {
            try {
                $created_ts = ( $order_obj && method_exists( $order_obj, 'get_date_created' ) && $order_obj->get_date_created() ) ? intval( $order_obj->get_date_created()->getTimestamp() ) : current_time( 'timestamp' );
                $date_time = sprintf( esc_html__( '%1$s at %2$s', 'woocommerce' ), esc_html( date_i18n( wc_date_format(), $created_ts ) ), esc_html( date_i18n( wc_time_format(), $created_ts ) ) );
                echo '<li class="note from-customer-note">';
                echo '<div class="note_content">' . wpautop( wptexturize( wp_kses_post( $checkout_note ) ) ) . '</div>';
                echo '<p class="meta">';
                printf( '<abbr class="exact-date" title="%s">%s — %s</abbr>', esc_attr( date_i18n( 'y-m-d H:i:s', $created_ts ) ), esc_html__( 'From customer (checkout)', 'enhanced-admin-order' ), $date_time );
                echo '</p>';
                echo '</li>';
            } catch (Throwable $t) {
                if ( function_exists( 'error_log' ) ) { error_log( '[EAO Notes] Checkout note render exception: ' . $t->getMessage() ); }
                echo '<li class="note from-customer-note"><div class="note_content">' . wpautop( wp_kses_post( $checkout_note ) ) . '</div></li>';
            }
        }
        foreach ( $notes as $note ) {
            try {
                // Normalize fields defensively
                $note_id = isset($note->id) ? absint($note->id) : ( isset($note->comment_ID) ? absint($note->comment_ID) : 0 );
                $content = isset($note->content) ? $note->content : ( isset($note->note_content) ? $note->note_content : '' );
                $customer_note = !empty($note->customer_note);
                $added_by_raw = isset($note->added_by) ? $note->added_by : ( isset($note->author) ? $note->author : '' );
                $added_by = $added_by_raw && 'system' !== strtolower($added_by_raw) ? esc_html( $added_by_raw ) : esc_html__( 'System', 'enhanced-admin-order' );

                // Resolve timestamp
                $ts = current_time('timestamp');
                if ( isset($note->date_created) ) {
                    if ( is_object($note->date_created) && method_exists($note->date_created, 'getTimestamp') ) {
                        $ts = intval( $note->date_created->getTimestamp() );
                    } elseif ( is_string($note->date_created) && $note->date_created !== '' ) {
                        $maybe = strtotime($note->date_created);
                        if ($maybe) { $ts = $maybe; }
                    }
                } elseif ( isset($note->date) ) {
                    $maybe = strtotime($note->date);
                    if ($maybe) { $ts = $maybe; }
                }
                $date_time = sprintf( esc_html__( '%1$s at %2$s', 'woocommerce' ), esc_html( date_i18n( wc_date_format(), $ts ) ), esc_html( date_i18n( wc_time_format(), $ts ) ) );
                $note_type_text = $customer_note ? esc_html__( 'note to customer', 'enhanced-admin-order' ) : esc_html__( 'private note', 'enhanced-admin-order' );

                // Build classes
                $note_classes = array( 'note' );
                if ( $customer_note ) { $note_classes[] = 'customer-note'; }
                if ( $added_by_raw && 'system' !== strtolower($added_by_raw) ) { $note_classes[] = 'user-' . sanitize_html_class( $added_by_raw ); }
                else { $note_classes[] = 'system-note'; }

                $can_edit_private = current_user_can( 'edit_shop_orders' ) && ! $customer_note;
                $edited_by = $note_id ? get_comment_meta( $note_id, '_eao_last_edited_by', true ) : '';
                $edited_at = $note_id ? get_comment_meta( $note_id, '_eao_last_edited_at', true ) : '';

                echo '<li rel="' . $note_id . '" class="' . esc_attr( implode( ' ', $note_classes ) ) . '">';
                echo '<div class="note_content">' . wpautop( wptexturize( wp_kses_post( $content ) ) ) . '</div>';
                echo '<p class="meta">';
                printf( '<abbr class="exact-date" title="%s">%s on %s, %s</abbr>', esc_attr( date_i18n('y-m-d H:i:s', $ts) ), $added_by, $date_time, $note_type_text );
                if ( $can_edit_private && $note_id ) {
                    echo ' <a href="#" class="eao-edit-note dashicons dashicons-edit" aria-label="' . esc_attr__( 'Edit note', 'enhanced-admin-order' ) . '" data-note-id="' . $note_id . '" data-order-id="' . absint( $order_id ) . '" style="text-decoration:none;vertical-align:middle;"></a>';
                }
                echo '</p>';
                if ( $edited_by && $edited_at ) {
                    $user = get_user_by( 'id', $edited_by );
                    $by_name = $user ? $user->display_name : (string) $edited_by;
                    $when = date_i18n( wc_date_format() . ' ' . wc_time_format(), intval( $edited_at ) );
                    echo '<p class="meta eao-note-edit-meta">' . sprintf( esc_html__( 'Edited by %1$s on %2$s', 'enhanced-admin-order' ), esc_html( $by_name ), esc_html( $when ) ) . '</p>';
                }
                echo '</li>';
            } catch (Throwable $t) {
                if (function_exists('error_log')) { error_log('[EAO Notes] Renderer exception: ' . $t->getMessage()); }
                // Fallback minimal rendering
                $fallback_content = isset($note->content) ? $note->content : '';
                echo '<li class="note"><div class="note_content">' . wpautop( wp_kses_post( $fallback_content ) ) . '</div></li>';
            }
        }
        echo '</ul>';
    } else {
        echo '<p>' . esc_html__( 'There are no notes yet.', 'enhanced-admin-order' ) . '</p>';
    }
    return ob_get_clean();
}

// AJAX: Edit a private order note
add_action( 'wp_ajax_eao_edit_order_note', 'eao_ajax_edit_order_note' );
function eao_ajax_edit_order_note() {
    $nonce = isset($_POST['nonce']) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
    $ok = false;
    if ($nonce) {
        if (wp_verify_nonce($nonce, 'eao_save_order_details')) { $ok = true; }
        if (!$ok && wp_verify_nonce($nonce, 'eao_editor_nonce')) { $ok = true; }
        if (!$ok && wp_verify_nonce($nonce, 'eao_refresh_notes_nonce')) { $ok = true; }
    }
    if (!$ok) {
        wp_send_json_error( array( 'message' => __( 'Invalid nonce.', 'enhanced-admin-order' ) ), 403 );
    }
    $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
    $note_id  = isset( $_POST['note_id'] ) ? absint( $_POST['note_id'] ) : 0;
    $content  = isset( $_POST['content'] ) ? wp_kses_post( wp_unslash( $_POST['content'] ) ) : '';
    if ( ! $order_id || ! $note_id || $content === '' ) {
        wp_send_json_error( array( 'message' => __( 'Missing parameters.', 'enhanced-admin-order' ) ), 400 );
    }
    if ( ! current_user_can( 'edit_shop_order', $order_id ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'enhanced-admin-order' ) ), 403 );
    }
    // Validate note belongs to order and is private
    $note = wc_get_order_note( $note_id );
    if ( ! $note || intval( $note->order_id ) !== $order_id ) {
        wp_send_json_error( array( 'message' => __( 'Note not found for this order.', 'enhanced-admin-order' ) ), 404 );
    }
    if ( $note->customer_note ) {
        wp_send_json_error( array( 'message' => __( 'Only private notes can be edited.', 'enhanced-admin-order' ) ), 400 );
    }
    // Update content
    wp_update_comment( array( 'comment_ID' => $note_id, 'comment_content' => $content ) );
    update_comment_meta( $note_id, '_eao_last_edited_by', get_current_user_id() );
    update_comment_meta( $note_id, '_eao_last_edited_at', time() );

    // Return refreshed HTML
    $html = eao_get_existing_order_notes_list_html( $order_id );
    wp_send_json_success( array( 'notes_html' => $html ) );
}

/**
 * Returns the HTML content for the custom "add note" section of the meta box.
 *
 * @since 1.1.6
 * @version 1.2.1 - Added initially hidden container for pending notes, removed "no notes" p tag.
 * @param WC_Order $order The order object.
 * @return string HTML content for the custom add note UI.
 */
function eao_get_custom_add_note_meta_box_content_html( $order ) {
    if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
        return '<p>' . esc_html__( 'Order context not found for custom note form.', 'enhanced-admin-order' ) . '</p>';
    }

    ob_start(); // Start output buffering to capture HTML

    // Nonce for our custom add note action (still needed for JS, even if not used by this PHP directly)
    wp_nonce_field( 'eao_add_custom_order_note_action', 'eao_add_custom_order_note_nonce' );
    ?>
    <div id="eao-add-note-wrapper" class="eao-custom-add-note-ui">
        <p style="margin-top: 0;">
            <label for="eao-order-note-content"><?php esc_html_e( 'New note text:', 'enhanced-admin-order' ); ?></label>
            <textarea id="eao-order-note-content" name="eao_order_note_content" class="widefat" rows="3"></textarea>
        </p>
        <p class="eao-note-type-radios">
            <label><?php esc_html_e( 'Type:', 'enhanced-admin-order' ); ?></label>
            <input type="radio" id="eao-note-type-private" name="eao_order_note_type_radio" value="private" checked="checked" style="margin-left: 10px;">
            <label for="eao-note-type-private" style="margin-right: 15px;"><?php esc_html_e( 'Private', 'enhanced-admin-order' ); ?></label>
            <input type="radio" id="eao-note-type-customer" name="eao_order_note_type_radio" value="customer">
            <label for="eao-note-type-customer"><?php esc_html_e( 'To customer', 'enhanced-admin-order' ); ?></label>
        </p>
        <p>
            <button type="button" id="eao-submit-custom-note" class="button button-primary">
                <?php esc_html_e( 'Add Custom Note', 'enhanced-admin-order' ); ?>
            </button>
            <span class="spinner" style="float: none; margin-left: 5px;"></span>
        </p>
        <p class="eao-notes-filter-row" style="margin-top: 8px;">
            <label for="eao-notes-show-system">
                <input type="checkbox" id="eao-notes-show-system" checked="checked" />
                <?php esc_html_e( 'Show system notes', 'enhanced-admin-order' ); ?>
            </label>
        </p>
        
        <div id="eao-staged-notes-container" style="margin-top: 15px; display: none;">
            <h4><?php esc_html_e( 'Pending Notes:', 'enhanced-admin-order' ); ?></h4>
            <ul id="eao-staged-notes-list">
                <!-- Staged notes will be listed here by JavaScript -->
            </ul>
        </div>
    </div>
    <?php
    error_log('[EAO Plugin v' . EAO_PLUGIN_VERSION . '] Rendered custom add note meta box for order ID: ' . $order->get_id());
    return ob_get_clean(); // Return buffered HTML
}

/**
 * AJAX handler for adding order notes.
 * 
 * @since 1.9.1
 */
function eao_ajax_add_custom_note_handler() {
    // Verify nonce
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( $_POST['nonce'] ), 'eao_add_custom_order_note_action' ) ) {
        wp_send_json_error( array( 'message' => __( 'Nonce verification failed.', 'enhanced-admin-order' ) ), 403 );
        return;
    }

    // Check user capabilities - typically 'edit_shop_orders' or similar
    if ( ! current_user_can( 'edit_shop_order', absint( $_POST['order_id'] ) ) ) {
        wp_send_json_error( array( 'message' => __( 'You do not have permission to add notes to this order.', 'enhanced-admin-order' ) ), 403 );
        return;
    }

    $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
    $note_content = isset( $_POST['note_content'] ) ? wp_kses_post( wp_unslash( $_POST['note_content'] ) ) : '';
    $is_customer_note = isset( $_POST['is_customer_note'] ) && $_POST['is_customer_note'] == '1'; // Check as string '1'

    if ( ! $order_id ) {
        wp_send_json_error( array( 'message' => __( 'Order ID is missing.', 'enhanced-admin-order' ) ), 400 );
        return;
    }

    if ( empty( $note_content ) ) {
        wp_send_json_error( array( 'message' => __( 'Note content cannot be empty.', 'enhanced-admin-order' ) ), 400 );
        return;
    }

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        wp_send_json_error( array( 'message' => __( 'Invalid Order ID.', 'enhanced-admin-order' ) ), 404 );
        return;
    }

    // Add the note, ensuring $added_by_user is true
    $note_id = $order->add_order_note( $note_content, $is_customer_note, true ); 

    if ( ! $note_id ) {
        wp_send_json_error( array( 'message' => __( 'Failed to add order note.', 'enhanced-admin-order' ) ), 500 );
        return;
    }
    
    // Optional: Get the rendered note to send back if we want to dynamically add it without page reload
    // For now, just success is fine as JS will alert to reload.
    wp_send_json_success( array( 
        'message' => __( 'Note added successfully.', 'enhanced-admin-order' ),
        'note_id' => $note_id
    ) );
} 