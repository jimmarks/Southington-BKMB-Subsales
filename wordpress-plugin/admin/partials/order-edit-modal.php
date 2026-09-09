<?php
/**
 * The Edit Order dialog, shared by the Orders screen and the Text Messages screen.
 *
 * It lived inside orders-page.php, which meant answering "I need to change my
 * order" started by leaving the conversation. Now both screens include this and
 * there is one edit form, not a copy that drifts.
 *
 * Expects $teams and $products_conf to be in scope, as both callers already have
 * them. Everything the script needs is declared here rather than borrowed from
 * the Orders page, except fetchPage(), which only exists there - the call is
 * guarded so the dialog works on a screen with no order table to refresh.
 *
 * @package Subsales_Management
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Guard against both screens somehow rendering it twice on one page.
if ( defined( 'SUBSALES_ORDER_EDIT_MODAL_RENDERED' ) ) {
	return;
}
define( 'SUBSALES_ORDER_EDIT_MODAL_RENDERED', true );
?>
    <!-- Edit Order Modal -->
    <div id="subsales-edit-modal" class="subsales-modal" style="display:none">
        <div class="subsales-modal-backdrop"></div>
        <div class="subsales-modal-content">
            <div class="subsales-modal-header">
                <h2>Edit Order</h2>
                <button class="subsales-modal-close" onclick="SubsalesOrderEdit.closeEditModal()">&times;</button>
            </div>
            <div class="subsales-modal-body">
                <form id="subsales-edit-form">
                    <input type="hidden" name="order_db_id" />
                    <input type="hidden" name="order_id" />
                    
                    <table class="form-table">
                        <tr>
                            <th><label>Selling Mode</label></th>
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                                    <label class="subsales-toggle-switch subsales-edit-mode-toggle">
                                        <input type="checkbox" id="edit-selling-mode-toggle" />
                                        <span class="subsales-toggle-slider"></span>
                                        <span class="subsales-toggle-label-left">Individual</span>
                                        <span class="subsales-toggle-label-right">Team</span>
                                    </label>
                                    <select name="team_id" id="edit-team-select" class="regular-text" style="display: none;">
                                        <option value="">Select Team</option>
                                        <?php foreach ( $teams as $t ) : ?>
                                            <option value="<?php echo intval( $t['id'] ); ?>"><?php echo esc_html( $t['name'] ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Customer Name</label></th>
                            <td><input type="text" name="customer" class="regular-text" required /></td>
                        </tr>
                        <tr>
                            <th><label>Address</label></th>
                            <td><input type="text" name="address" class="regular-text" required /></td>
                        </tr>
                        <tr>
                            <th><label>Unit / Floor / Apt</label></th>
                            <td><input type="text" name="unitFloorApt" class="regular-text" placeholder="Optional" /></td>
                        </tr>
                        <tr>
                            <th><label>Cell Number</label></th>
                            <td><input type="tel" name="cellNumber" class="regular-text" placeholder="Optional" /></td>
                        </tr>
                    </table>
                    
                    <h3 class="subsales-products-heading">Products</h3>
                    <div class="subsales-products-group">
                        <table class="form-table">
                            <tbody id="subsales-edit-products"></tbody>
                        </table>
                    </div>
                    
                    <table class="form-table">
                        <tr>
                            <th><label>Donation Amount (USD)</label></th>
                            <td><input type="number" name="donationAmount" class="regular-text" min="0" step="0.01" placeholder="$0.00" /></td>
                        </tr>
                        <tr>
                            <th><label>Payment Method</label></th>
                            <td>
                                <select name="paymentMethod">
                                    <option value="cash">Cash</option>
                                    <option value="check">Check</option>
                                    <option value="digital">Digital</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Check Number</label></th>
                            <td><input type="text" name="checkNumber" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th><label>Delivery Instructions</label></th>
                            <td><textarea name="notes" class="large-text" rows="3" placeholder="House color, long driveway, etc."></textarea></td>
                        </tr>
                        <tr>
                            <th><label>Edit Reason</label> <span style="color:red">*</span></th>
                            <td><textarea name="_edit_reason" class="large-text" rows="2" placeholder="Explain why this order is being edited..." required></textarea></td>
                        </tr>
                    </table>
                </form>
            </div>
            <div class="subsales-modal-footer">
                <button id="subsales-refund-btn"
                        class="button button-large subsales-refund-btn"
                        style="display:none"
                        onclick="SubsalesOrderEdit.refundOrder()"
                        title="Cancel this order and refund the card in full">
                    Cancel &amp; Refund Card
                </button>
                <span class="subsales-modal-footer-spacer"></span>
                <button class="button button-large" onclick="SubsalesOrderEdit.closeEditModal()">Cancel</button>
                <button class="button button-primary button-large" onclick="SubsalesOrderEdit.saveOrder()">Save Changes</button>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="subsales-delete-modal" class="subsales-modal" style="display:none">
        <div class="subsales-modal-backdrop"></div>
        <div class="subsales-modal-content" style="max-width:500px">
            <div class="subsales-modal-header">
                <h2>Delete Order</h2>
                <button class="subsales-modal-close" onclick="SubsalesOrderEdit.closeDeleteModal()">&times;</button>
            </div>
            <div class="subsales-modal-body">
                <p><strong>Are you sure you want to delete this order?</strong></p>
                <p id="subsales-delete-order-info"></p>
                <form id="subsales-delete-form">
                    <input type="hidden" name="order_db_id" />
                    <input type="hidden" name="order_id" />
                    <table class="form-table">
                        <tr>
                            <th><label>Delete Reason</label> <span style="color:red">*</span></th>
                            <td><textarea name="delete_reason" class="large-text" rows="3" placeholder="Explain why this order is being deleted..." required></textarea></td>
                        </tr>
                    </table>
                </form>
            </div>
            <div class="subsales-modal-footer">
                <button class="button button-large" onclick="SubsalesOrderEdit.closeDeleteModal()">Cancel</button>
                <button class="button button-primary button-large" style="background:red;border-color:darkred" onclick="SubsalesOrderEdit.confirmDelete()">Delete Order</button>
            </div>
        </div>
    </div>

    <!-- History Panel -->
    <div id="subsales-history-panel" class="subsales-history-panel" style="display:none">
        <div class="subsales-history-header">
            <h3>Order Edit History</h3>
            <button class="button" onclick="SubsalesOrderEdit.closeHistoryPanel()">&times; Close</button>
        </div>
        <div id="subsales-history-content" class="subsales-history-content">
            <p>Loading...</p>
        </div>
    </div>

    <style>
        /* This dialog puts the long label ("Individual") on the LEFT, where the
           shared control reserves 31px for a short one like "Team" - so it
           rendered as "Individ" tucked under the switch. Fixed with a modifier
           on this dialog only: the dashboard's toggles use the same class and
           are already right, and changing the shared rules to suit this one
           breaks them. */
        .subsales-edit-mode-toggle { grid-template-columns: auto 56px auto; }
        .subsales-edit-mode-toggle .subsales-toggle-slider {
            position: relative;
            grid-column: 2;
            left: auto; right: auto; top: auto; bottom: auto;
            width: 56px;
            height: 26px;
        }
        .subsales-edit-mode-toggle .subsales-toggle-label-left,
        .subsales-edit-mode-toggle .subsales-toggle-label-right { white-space: nowrap; }

        /* Who they were when they made the change. Colour separates the three
           so the answer to "did a seller change their own order" is visible
           without reading names. */
        .hist-role{
            display:inline-block; font-size:10px; font-weight:700; letter-spacing:.04em;
            text-transform:uppercase; padding:1px 7px; border-radius:9px; vertical-align:1px;
        }
        .hist-role-admin{ background:#e9e3fb; color:#4c2fa8; }
        .hist-role-driver{ background:#fff1d6; color:#8a5a00; }
        .hist-role-seller{ background:#e2f0ff; color:#12557f; }

        .subsales-modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 100000; }
        .subsales-modal-backdrop { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); }
        .subsales-modal-content { position: relative; max-width: 700px; margin: 40px auto; background: white; border-radius: 4px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); max-height: 90vh; display: flex; flex-direction: column; }
        .subsales-modal-header { padding: 20px 24px; border-bottom: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center; }
        .subsales-modal-header h2 { margin: 0; }
        .subsales-modal-close { background: none; border: none; font-size: 28px; cursor: pointer; padding: 0; line-height: 1; color: #666; }
        .subsales-modal-body { padding: 20px 24px; overflow-y: auto; flex: 1; }

        /* WordPress's .form-table is sized for a full-width settings page: a
           200px label column, 20px row padding, and inputs that stretch to the
           container. Inside a 700px modal that reads as enormous gaps and
           over-wide fields. These rules are scoped to the modal only, so the
           filter form on the page itself is untouched. */
        .subsales-modal-body .form-table { margin: 0; }
        .subsales-modal-body .form-table th {
            width: 150px;
            padding: 10px 12px 10px 0;
            font-size: 13px;
            font-weight: 600;
            line-height: 1.4;
            vertical-align: middle;
        }
        .subsales-modal-body .form-table th label { font-weight: 600; }
        .subsales-modal-body .form-table td {
            padding: 8px 0;
            vertical-align: middle;
        }
        /* One consistent field width instead of .regular-text (25em) fighting
           .large-text (100%) - the mix is what made the column look ragged. */
        .subsales-modal-body .form-table td input[type="text"],
        .subsales-modal-body .form-table td input[type="tel"],
        .subsales-modal-body .form-table td input[type="number"],
        .subsales-modal-body .form-table td select,
        .subsales-modal-body .form-table td textarea {
            width: 100%;
            max-width: 380px;
            box-sizing: border-box;
        }
        .subsales-modal-body .form-table td textarea { min-height: 60px; }
        /* Quantity boxes are small numbers; a 380px field for "4" is absurd. */
        .subsales-modal-body #subsales-edit-products input[type="number"] { max-width: 90px; text-align: center; }
        .subsales-modal-body #subsales-edit-products td { padding: 5px 0; }
        .subsales-modal-body #subsales-edit-products th { width: 150px; font-weight: 500; }
        /* The products block is a grouped sub-table - give it a quiet frame
           rather than the heavy blue outline it inherits when focused. */
        .subsales-modal-body .subsales-products-heading {
            font-size: 13px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #50575e;
            margin: 18px 0 6px;
        }
        .subsales-modal-body .subsales-products-group {
            border: 1px solid #dcdcde;
            border-radius: 4px;
            padding: 4px 12px;
            margin: 2px 0;
            background: #f6f7f7;
        }
        /* Flex rather than text-align:right so the destructive action can be
           pushed to the far left by .subsales-modal-footer-spacer while Cancel
           and Save stay right-aligned. Wraps on narrow screens instead of
           overflowing the modal. */
        .subsales-modal-footer { padding: 16px 24px; border-top: 1px solid #ddd; display: flex; align-items: center; justify-content: flex-end; gap: 8px; flex-wrap: wrap; }
        .subsales-modal-footer button { margin-left: 0; }
        
        .subsales-history-panel { position: fixed; top: 0; right: 0; width: 500px; height: 100%; background: white; box-shadow: -2px 0 10px rgba(0,0,0,0.3); z-index: 100001; overflow-y: auto; }
        .subsales-history-header { padding: 20px; border-bottom: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; background: white; }
        .subsales-history-content { padding: 20px; }
        .subsales-history-item { border: 1px solid #ddd; border-radius: 4px; padding: 16px; margin-bottom: 16px; background: #f9f9f9; }
        .subsales-history-item h4 { margin: 0 0 8px 0; color: #2271b1; }
        .subsales-history-item .meta { color: #666; font-size: 0.9em; margin-bottom: 8px; }
        .subsales-history-item .summary { margin-bottom: 12px; }
        .subsales-history-changes { background: white; border: 1px solid #ddd; padding: 12px; border-radius: 3px; font-size: 0.9em; }
        .subsales-history-change { margin-bottom: 8px; padding: 8px; background: #f5f5f5; border-left: 3px solid #2271b1; }
        .subsales-history-change strong { display: inline-block; min-width: 120px; }
        .subsales-change-before { color: #d63638; text-decoration: line-through; }
        .subsales-change-after { color: #00a32a; font-weight: 600; }
        
        .subsales-orders-meta-note { float: right; color: #666; font-size: 0.9em; }
        .subsales-edited-star { color: red; font-weight: bold; }
        /* Refund is destructive and irreversible, so it reads as a warning and
           sits hard left - away from Save Changes, which is where the cursor
           naturally goes. The spacer is what pushes them apart. */
        .subsales-modal-footer-spacer { flex: 1 1 auto; }
        .subsales-refund-btn {
            color: #b32d2e;
            border-color: #b32d2e;
            background: #fff;
        }
        .subsales-refund-btn:hover:not(:disabled) {
            background: #b32d2e;
            border-color: #b32d2e;
            color: #fff;
        }
        .subsales-refund-btn:disabled { opacity: 0.6; cursor: progress; }
        /* Second line of the order cell. Deliberately narrow and truncating: the
           column must not widen enough to push the product columns off screen,
           and a wrapped 3-line address would break the row rhythm that makes an
           out-of-place street name easy to spot when scanning. Full value is in
           the title attribute. */
        .subsales-order-address {
            font-size: 11px;
            line-height: 1.3;
            color: #646970;
            margin-top: 2px;
            max-width: 220px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .subsales-order-address em { color: #b32d2e; font-style: normal; }
        /* Filtered-set totals: the row you actually reconcile against, so it
           reads heavier than the per-page row above it. */
        .subsales-filtered-totals td { font-weight: 700; border-top: 2px solid #2271b1; background: #f6f7f7; }
        .subsales-action-btn { 
            padding: 6px 12px; 
            font-size: 12px; 
            margin-right: 4px; 
            border-radius: 3px;
            cursor: pointer;
            transition: all 0.2s ease;
            border: 1px solid #ddd;
            background: white;
        }
        .subsales-action-btn:hover { 
            background: #f0f0f1;
            border-color: #2271b1;
            color: #2271b1;
        }
        .subsales-action-btn-edit { 
            background: #2271b1;
            color: white;
            border-color: #2271b1;
        }
        .subsales-action-btn-edit:hover { 
            background: #135e96;
        }
        .subsales-action-btn-delete { 
            background: #d63638;
            color: white;
            border-color: #d63638;
        }
        .subsales-action-btn-delete:hover { 
            background: #b32d2e;
        }
        .subsales-action-btn-history {
            background: #dba617;
            color: white;
            border-color: #dba617;
        }
        .subsales-action-btn-history:hover {
            background: #c29500;
        }
    </style>

<script>
(function(){
    // Declared here so the dialog does not depend on the Orders page having
    // defined them first.
    if (typeof window.ajaxUrl === 'undefined') { window.ajaxUrl = <?php echo json_encode( admin_url( 'admin-ajax.php' ) ); ?>; }
    if (typeof window.nonce === 'undefined') { window.nonce = <?php echo json_encode( wp_create_nonce( 'subsales_orders_nonce' ) ); ?>; }
    if (typeof window.restNonce === 'undefined') { window.restNonce = <?php echo json_encode( wp_create_nonce( 'wp_rest' ) ); ?>; }
    if (typeof window.configuredProducts === 'undefined') { window.configuredProducts = <?php echo json_encode( array_values( $products_conf ) ); ?>; }
    if (typeof window.refundUrlBase === 'undefined') { window.refundUrlBase = <?php echo json_encode( rest_url( 'order-manager/v1/orders/' ) ); ?>; }
    if (typeof window.refundUrlFor !== 'function') {
        window.refundUrlFor = function(id){ return window.refundUrlBase + encodeURIComponent(id) + '/refund'; };
    }
    if (typeof window.escapeHtml !== 'function') {
        window.escapeHtml = function(s){
            if (s === null || s === undefined) { return ''; }
            return String(s).replace(/[&<>"']/g, function(c){
                return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
            });
        };
    }
    // Only the Orders screen has a table to redraw after a save.
    if (typeof window.fetchPage !== 'function') { window.fetchPage = function(){}; }
})();

    // Order Edit/Delete/History Manager
    window.SubsalesOrderEdit = {
        currentOrder: null,
        
        async editOrder(orderDbId, orderId) {
            try {
                // Fetch full order data using database ID
                const resp = await fetch('<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>?action=subsales_get_order_by_db_id&id=' + orderDbId + '&nonce=<?php echo wp_create_nonce( 'wp_rest' ); ?>');
                const result = await resp.json();
                
                console.log('Edit order response:', result);
                
                if (!result || !result.success || !result.data) {
                    alert('Failed to load order: ' + (result && result.data ? result.data : 'Unknown error'));
                    return;
                }
                
                const order = result.data;
                console.log('Order object:', order);
                console.log('Order data:', order.order_data);
                
                if (!order || !order.order_data) {
                    alert('Failed to load order: Invalid data structure');
                    return;
                }
                
                this.currentOrder = order;
                const data = order.order_data;
                
                console.log('Parsed data:', data);
                console.log('customer:', data.customer);
                
                // Populate form - match PWA field structure
                const form = document.getElementById('subsales-edit-form');
                form.elements['order_db_id'].value = order.id || '';
                form.elements['order_id'].value = order.order_id || '';
                form.elements['customer'].value = data.customer || data.customerName || '';
                form.elements['address'].value = data.address || '';
                form.elements['unitFloorApt'].value = data.unitFloorApt || '';
                form.elements['cellNumber'].value = data.cellNumber || data.phone || '';
                form.elements['donationAmount'].value = data.donationAmount || '';
                form.elements['paymentMethod'].value = data.paymentMethod || 'cash';
                form.elements['checkNumber'].value = data.checkNumber || '';
                form.elements['notes'].value = data.notes || '';
                form.elements['_edit_reason'].value = '';
                
                // Populate products
                const productsContainer = document.getElementById('subsales-edit-products');
                productsContainer.innerHTML = '';
                const products = data.products || [];
                const configuredProducts = <?php echo json_encode( array_values( $products_conf ) ); ?>;
                
                console.log('Products from order:', products);
                console.log('Configured products:', configuredProducts);
                
                for (const p of configuredProducts) {
                    const existing = products.find(pr => pr.id === p.id);
                    const qty = existing ? existing.qty : 0;
                    
                    const tr = document.createElement('tr');
                    tr.innerHTML = '<th><label>' + p.name + '</label></th>' +
                        '<td><input type="number" name="product_' + p.id + '" min="0" value="' + qty + '" /></td>';
                    productsContainer.appendChild(tr);
                }
                
                // Set up team selector
                const teamId = order.team_id ? parseInt(order.team_id) : -1;
                const sellingModeToggle = document.getElementById('edit-selling-mode-toggle');
                const teamSelect = document.getElementById('edit-team-select');
                
                if (teamId === -1) {
                    // Individual mode
                    sellingModeToggle.checked = false;
                    teamSelect.style.display = 'none';
                    teamSelect.value = '';
                } else {
                    // Team mode
                    sellingModeToggle.checked = true;
                    teamSelect.style.display = 'inline-block';
                    teamSelect.value = teamId;
                    teamSelect.dataset.lastTeam = teamId;
                }
                
                // Check if someone else is currently editing
                const editWarning = this.checkEditingStatus(data);
                if (editWarning) {
                    const proceed = confirm(editWarning);
                    if (!proceed) {
                        return; // User cancelled, don't open modal
                    }
                }
                
                // Claim edit lock before showing modal
                await this.claimEditLock(orderDbId);
                
                // The refund button only exists for card-paid orders. This is the
                // only rollback path for one - sellers cannot reach it at all.
                const refundBtn = document.getElementById('subsales-refund-btn');
                if (refundBtn) {
                    // order.paid_amount comes from ss_payment_attempts, not from
                    // order_data - the seller's device says paymentMethod, but
                    // only our own record says money was actually captured.
                    const alreadyRefunded = !!order.refund_id;
                    const paid = !!order.paid_amount && !alreadyRefunded;
                    refundBtn.style.display = paid ? '' : 'none';
                    refundBtn.dataset.orderDbId = orderDbId;
                    refundBtn.dataset.amount = order.paid_amount || '';
                    if (alreadyRefunded) {
                        console.log('Order already refunded:', order.refund_id);
                    }
                }

                // Show modal
                document.getElementById('subsales-edit-modal').style.display = 'block';
                
                // Setup toggle switch listener (after modal is visible)
                // Attached once for the life of the page. The previous code built a
                // fresh closure on every open and called removeEventListener() with
                // it, which never matched the one already bound - so handlers piled
                // up, one more per order opened.
                if (!sellingModeToggle.dataset.listenerBound) {
                    sellingModeToggle.dataset.listenerBound = '1';
                    sellingModeToggle.addEventListener('change', function() {
                        const teamSelect = document.getElementById('edit-team-select');
                        if (!teamSelect) { return; }
                        if (this.checked) {
                            teamSelect.style.display = 'inline-block';
                            // Restore what was selected before the switch to
                            // Individual, so a mis-tap does not silently drop the
                            // team and leave the order unassigned on save.
                            if (!teamSelect.value && teamSelect.dataset.lastTeam) {
                                teamSelect.value = teamSelect.dataset.lastTeam;
                            }
                            if (!teamSelect.value) { teamSelect.focus(); }
                        } else {
                            if (teamSelect.value) {
                                teamSelect.dataset.lastTeam = teamSelect.value;
                            }
                            teamSelect.style.display = 'none';
                            teamSelect.value = '';
                        }
                    });
                }
            } catch (error) {
                console.error('Edit order error:', error);
                alert('Failed to load order: ' + error.message);
            }
        },
        
        // The only rollback path for a card-paid order. Two deliberate gates:
        // the fee warning (a refund costs the club money that a cash refund
        // would not), and a typed reason that lands in the order's history.
        async refundOrder() {
            const btn = document.getElementById('subsales-refund-btn');
            if (!btn) { return; }
            const orderDbId = btn.dataset.orderDbId;
            const amount = parseFloat(btn.dataset.amount || '0');
            if (!orderDbId || !(amount > 0)) {
                alert('This order has no captured card payment to refund.');
                return;
            }

            // Square keeps its processing fee on a refund, so the club eats it.
            const fee = (amount * 0.026) + 0.10;
            const warning =
                'Refund $' + amount.toFixed(2) + ' to the customer\u2019s card?\n\n' +
                'A CASH REFUND IS PREFERRED.\n' +
                'Square keeps its processing fee on a card refund, so this one costs the ' +
                'band roughly $' + fee.toFixed(2) + ' that a cash refund would not.\n\n' +
                'This also cancels the order, and the money takes 2-7 business days to ' +
                'reach the card.\n\nContinue with the card refund?';
            if (!confirm(warning)) { return; }

            const reason = prompt('Why is this order being refunded? (recorded in the order history)');
            if (reason === null) { return; }
            if (!reason.trim()) { alert('A reason is required.'); return; }

            btn.disabled = true;
            const original = btn.textContent;
            btn.textContent = 'Refunding\u2026';
            try {
                const resp = await fetch(refundUrlFor(orderDbId), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': restNonce },
                    body: JSON.stringify({ reason: reason.trim() })
                });
                const data = await resp.json();
                if (resp.ok && data.success) {
                    alert(data.message);
                    this.closeEditModal();
                    fetchPage(1);
                } else {
                    alert('Refund failed: ' + (data.message || 'unknown error'));
                }
            } catch (e) {
                alert('Refund failed: ' + e.message);
            } finally {
                btn.disabled = false;
                btn.textContent = original;
            }
        },

        closeEditModal() {
            // Release edit lock if we have a current order
            if (this.currentOrder && this.currentOrder.id) {
                this.releaseEditLock(this.currentOrder.id);
            }
            document.getElementById('subsales-edit-modal').style.display = 'none';
            this.currentOrder = null;
        },
        
        async saveOrder() {
            const form = document.getElementById('subsales-edit-form');
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }
            
            const orderDbId = form.elements['order_db_id'].value;
            const orderId = form.elements['order_id'].value;
            
            // Get team_id from form
            const sellingModeToggle = document.getElementById('edit-selling-mode-toggle');
            const teamSelect = document.getElementById('edit-team-select');
            const teamId = sellingModeToggle.checked && teamSelect.value ? parseInt(teamSelect.value) : -1;
            
            // Build updated order data - preserve existing metadata from original order
            const originalData = this.currentOrder.order_data || {};
            const data = {
                order_id: orderId,
                user_id: this.currentOrder.user_id,
                team_id: teamId,
                customer: form.elements['customer'].value,
                address: form.elements['address'].value,
                unitFloorApt: form.elements['unitFloorApt'].value,
                cellNumber: form.elements['cellNumber'].value,
                donationAmount: parseFloat(form.elements['donationAmount'].value) || 0,
                paymentMethod: form.elements['paymentMethod'].value,
                checkNumber: form.elements['checkNumber'].value,
                notes: form.elements['notes'].value,
                _edit_reason: form.elements['_edit_reason'].value,
                products: [],
                // Preserve metadata from original order
                createdAt: originalData.createdAt || new Date().toISOString(),
                entered_by_id: originalData.entered_by_id || '',
                entered_by_name: originalData.entered_by_name || '',
                team_name: originalData.team_name || '',
                team_code: originalData.team_code || '',
                geo: originalData.geo || null
            };
            
            // Collect products - PRESERVE ORIGINAL PRICES from price_snapshot or products array
            const configuredProducts = <?php echo json_encode( array_values( $products_conf ) ); ?>;
            const originalProducts = originalData.products || [];
            const priceSnapshot = originalData.price_snapshot || {};
            
            for (const p of configuredProducts) {
                const qty = parseInt(form.elements['product_' + p.id].value) || 0;
                
                // Price priority: 1) price_snapshot, 2) original product, 3) current config
                let price = p.price; // Default to current config
                if (priceSnapshot[p.id] !== undefined) {
                    price = priceSnapshot[p.id]; // Best: use price snapshot
                } else {
                    const originalProduct = originalProducts.find(op => String(op.id) === String(p.id));
                    if (originalProduct) {
                        price = originalProduct.price; // Fallback: use original product price
                    }
                }
                
                data.products.push({
                    id: p.id,
                    name: p.name,
                    price: price,
                    qty: qty
                });
            }
            
            // Preserve price_snapshot for future edits
            if (Object.keys(priceSnapshot).length > 0) {
                data.price_snapshot = priceSnapshot;
            }
            
            try {
                const resp = await fetch('<?php echo esc_js( rest_url( 'order-manager/v1/orders/' ) ); ?>' + orderId, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>'
                    },
                    body: JSON.stringify(data)
                });
                
                const result = await resp.json();
                
                if (resp.ok) {
                    alert('Order updated successfully!');
                    this.closeEditModal();
                    window.SubsalesRefreshOrders();
                } else {
                    alert('Failed to update order: ' + (result.message || 'Unknown error'));
                }
            } catch (error) {
                console.error('Save order error:', error);
                alert('Failed to save order: ' + error.message);
            }
        },
        
        deleteOrder(orderDbId, orderId) {
            this.currentOrder = { id: orderDbId, order_id: orderId };
            document.getElementById('subsales-delete-form').elements['order_db_id'].value = orderDbId;
            document.getElementById('subsales-delete-form').elements['order_id'].value = orderId;
            document.getElementById('subsales-delete-order-info').textContent = 'Order: ' + orderId;
            document.getElementById('subsales-delete-form').elements['delete_reason'].value = '';
            document.getElementById('subsales-delete-modal').style.display = 'block';
        },
        
        closeDeleteModal() {
            document.getElementById('subsales-delete-modal').style.display = 'none';
            this.currentOrder = null;
        },
        
        async confirmDelete() {
            const form = document.getElementById('subsales-delete-form');
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }
            
            const orderId = form.elements['order_id'].value;
            const deleteReason = form.elements['delete_reason'].value;
            
            try {
                const resp = await fetch('<?php echo esc_js( rest_url( 'order-manager/v1/orders/' ) ); ?>' + orderId, {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>'
                    },
                    body: JSON.stringify({ delete_reason: deleteReason })
                });
                
                const result = await resp.json();
                
                if (resp.ok) {
                    alert('Order deleted successfully!');
                    this.closeDeleteModal();
                    window.SubsalesRefreshOrders();
                } else {
                    alert('Failed to delete order: ' + (result.message || 'Unknown error'));
                }
            } catch (error) {
                console.error('Delete order error:', error);
                alert('Failed to delete order: ' + error.message);
            }
        },
        
        async viewHistory(orderDbId) {
            document.getElementById('subsales-history-panel').style.display = 'block';
            document.getElementById('subsales-history-content').innerHTML = '<p>Loading history...</p>';
            
            try {
                const resp = await fetch('<?php echo esc_js( rest_url( 'order-manager/v1/orders/' ) ); ?>' + orderDbId + '/history', {
                    headers: {
                        'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>'
                    }
                });
                const data = await resp.json();
                
                if (!resp.ok || !data.history) {
                    document.getElementById('subsales-history-content').innerHTML = '<p>Failed to load history</p>';
                    return;
                }
                
                if (data.history.length === 0) {
                    document.getElementById('subsales-history-content').innerHTML = '<p>No edit history for this order.</p>';
                    return;
                }
                
                // Render history
                let html = '<div class="subsales-history-items">';
                for (const item of data.history) {
                    html += '<div class="subsales-history-item">';
                    html += '<h4>' + this.escapeHtml(item.edit_type.toUpperCase()) + '</h4>';
                    html += '<div class="meta">';
                    // Say what they were, not just who. "Daddy Marks" means
                    // nothing to somebody checking whether a seller changed
                    // their own order after the fact.
                    var roleLabels = { admin: 'Admin', driver: 'Driver', seller: 'Seller' };
                    var role = roleLabels[item.edited_by_role] || '';
                    html += 'By: ' + this.escapeHtml(item.edited_by_name);
                    if (role) { html += ' <span class="hist-role hist-role-' + this.escapeHtml(item.edited_by_role) + '">' + role + '</span>'; }
                    html += ' | ';
                    html += 'Date: ' + this.escapeHtml(item.edited_at) + '</div>';
                    html += '<div class="summary"><strong>Summary:</strong> ' + this.escapeHtml(item.changes_summary) + '</div>';
                    
                    if (item.edit_reason) {
                        html += '<div><strong>Reason:</strong> ' + this.escapeHtml(item.edit_reason) + '</div>';
                    }
                    
                    // Show detailed changes
                    if (item.changes_detail && item.changes_detail.changes) {
                        html += '<details style="margin-top:12px"><summary style="cursor:pointer;color:#2271b1"><strong>View Detailed Changes</strong></summary>';
                        html += '<div class="subsales-history-changes">';
                        for (const change of item.changes_detail.changes) {
                            html += '<div class="subsales-history-change">';
                            html += '<strong>' + this.escapeHtml(change.label) + ':</strong> ';
                            
                            if (change.field === 'products') {
                                html += '<br/><span class="subsales-change-before">' + this.renderProducts(change.before) + '</span>';
                                html += '<br/><span class="subsales-change-after">' + this.renderProducts(change.after) + '</span>';
                            } else {
                                html += '<span class="subsales-change-before">' + this.escapeHtml(change.before) + '</span> → ';
                                html += '<span class="subsales-change-after">' + this.escapeHtml(change.after) + '</span>';
                            }
                            html += '</div>';
                        }
                        html += '</div></details>';
                    }
                    
                    html += '</div>';
                }
                html += '</div>';
                
                document.getElementById('subsales-history-content').innerHTML = html;
            } catch (error) {
                console.error('View history error:', error);
                document.getElementById('subsales-history-content').innerHTML = '<p>Error loading history: ' + error.message + '</p>';
            }
        },
        
        closeHistoryPanel() {
            document.getElementById('subsales-history-panel').style.display = 'none';
        },
        
        escapeHtml(s) {
            if (!s && s !== 0) return '';
            return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        },
        
        renderProducts(products) {
            if (!products || products.length === 0) return '(none)';
            return products.map(p => p.name + ' ×' + p.qty).join(', ');
        },
        
        /**
         * Check if someone else is currently editing this order
         * Returns warning message or null if safe to edit
         */
        checkEditingStatus(orderData) {
            const editingBy = orderData.editing_by;
            const editingSince = orderData.editing_since;
            
            if (!editingBy || !editingSince) {
                return null; // No one is editing
            }
            
            // Calculate how long ago the edit started
            const editStart = new Date(editingSince);
            const now = new Date();
            const minutesAgo = Math.floor((now - editStart) / 60000);
            
            // If older than 5 minutes, consider it stale
            if (minutesAgo > 5) {
                return null;
            }
            
            return `⚠️ ${editingBy} opened this order ${minutesAgo} minute(s) ago.\n\nDo you want to continue editing anyway?\n\n(Changes may conflict if you both edit simultaneously)`;
        },
        
        /**
         * Claim edit lock on an order
         */
        async claimEditLock(orderDbId) {
            try {
                const resp = await fetch('<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        action: 'subsales_claim_edit_lock',
                        order_id: orderDbId,
                        user: '<?php echo esc_js( wp_get_current_user()->display_name ); ?>',
                        nonce: '<?php echo wp_create_nonce( 'wp_rest' ); ?>'
                    })
                });
                const result = await resp.json();
                if (!result.success) {
                    console.error('Failed to claim edit lock:', result.data);
                }
            } catch (error) {
                console.error('Error claiming edit lock:', error);
            }
        },
        
        /**
         * Release edit lock on an order
         */
        async releaseEditLock(orderDbId) {
            try {
                const resp = await fetch('<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        action: 'subsales_release_edit_lock',
                        order_id: orderDbId,
                        nonce: '<?php echo wp_create_nonce( 'wp_rest' ); ?>'
                    })
                });
                const result = await resp.json();
                if (!result.success) {
                    console.error('Failed to release edit lock:', result.data);
                }
            } catch (error) {
                console.error('Error releasing edit lock:', error);
            }
        }
    };
</script>
