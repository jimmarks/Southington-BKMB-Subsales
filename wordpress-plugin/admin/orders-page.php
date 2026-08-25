<?php
/**
 * Orders Page
 * 
 * Admin page for viewing, filtering, editing, and managing orders.
 * Features:
 * - Quick search by customer name, address, or phone
 * - Advanced filtering (date range, team, member, payment method, tally status)
 * - Edit order details with audit trail
 * - Delete orders with reason tracking
 * - View order edit history
 * - Bulk tally operations
 * - AJAX-based data loading for performance
 * 
 * @package Subsales_Management
 * @since 2.2.1.154
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Initial page renders minimal markup; actual data is fetched via AJAX
global $wpdb;
$table = $wpdb->prefix . 'ss_orders';
$nonce = wp_create_nonce( 'subsales_orders_nonce' );
$ajax_url = admin_url( 'admin-ajax.php' );

// Preload teams, members and configured products for filter UI and table columns
$teams = order_sync_get_teams();
$members = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}ss_team_members ORDER BY name ASC", ARRAY_A );
$products_conf = order_sync_get_products_config();

// Get filter parameters from request
$start_date = isset( $_GET['start_date'] ) ? sanitize_text_field( $_GET['start_date'] ) : '';
$end_date = isset( $_GET['end_date'] ) ? sanitize_text_field( $_GET['end_date'] ) : '';
$filter_team = isset( $_GET['filter_team'] ) ? intval( $_GET['filter_team'] ) : 0;
$filter_member = isset( $_GET['filter_member'] ) ? sanitize_text_field( $_GET['filter_member'] ) : '';
$payment_method = isset( $_GET['payment_method'] ) ? sanitize_text_field( $_GET['payment_method'] ) : '';

// Build WHERE clauses safely
$where = array();
$params = array();

    if ( ! empty( $start_date ) ) {
        // start of day
        $where[] = "created_at >= %s";
        $params[] = $start_date . ' 00:00:00';
    }
    if ( ! empty( $end_date ) ) {
        // end of day
        $where[] = "created_at <= %s";
        $params[] = $end_date . ' 23:59:59';
    }
    if ( $filter_team ) {
        $where[] = "team_id = %d";
        $params[] = $filter_team;
    }
    if ( ! empty( $filter_member ) ) {
        // user_id column stores the entered_by identifier
        $where[] = "user_id = %s";
        $params[] = $filter_member;
    }
    if ( ! empty( $payment_method ) ) {
        // best-effort JSON match in order_data -> search for paymentMethod or checkNumber
        if ( $payment_method === 'cash' ) {
            $where[] = "order_data LIKE %s";
            $params[] = '%' . $wpdb->esc_like( '"paymentMethod"' ) . '%"cash"%';
        } elseif ( $payment_method === 'check' ) {
            $where[] = "(order_data LIKE %s OR order_data LIKE %s)";
            $params[] = '%' . $wpdb->esc_like( '"paymentMethod"' ) . '%"check"%';
            $params[] = '%' . $wpdb->esc_like( '"checkNumber"' ) . '%';
        }
    }

    // Normalize stored products option (may be JSON string or array)
    $products_conf = order_sync_get_products_config();

    // Note: initial Orders page renders an empty table; data is fetched via AJAX. Do not run server-side queries here.
    $orders = array();

    ?>
    <div class="wrap">
        <h1>Orders</h1>

        <!-- Quick Search -->
        <div style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border: 1px solid #ddd; border-radius: 4px;">
            <label for="subsales-quick-search" style="font-weight: 600; margin-right: 10px;">🔍 Quick Search:</label>
            <input type="text" id="subsales-quick-search" placeholder="Search by customer name, address, or phone..." style="width: 400px; padding: 6px 12px;" />
            <button id="subsales-clear-search" class="button" style="margin-left: 8px;">Clear</button>
            <span id="subsales-search-results" style="margin-left: 15px; color: #666; font-style: italic;"></span>
        </div>

        <form id="subsales-orders-filter" class="subsales-orders-filter" onsubmit="return false;">
            <input type="hidden" name="action" value="subsales_fetch_orders" />
            <?php wp_nonce_field( 'subsales_orders_nonce', 'subsales_orders_nonce_field' ); ?>
            <table class="form-table" style="max-width: 960px;">
                <tr>
                    <th style="width:110px">Start date</th>
                    <td><input type="date" name="start_date" /></td>
                    <th>End date</th>
                    <td><input type="date" name="end_date" /></td>
                </tr>
                <tr>
                    <th>Team</th>
                    <td>
                        <select name="team_id">
                            <option value="">All teams</option>
                            <?php foreach ( $teams as $t ) : ?>
                                <option value="<?php echo intval( $t['id'] ); ?>"><?php echo esc_html( $t['name'] ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <th>Team member</th>
                    <td>
                        <select name="entered_by_id">
                            <option value="">All members</option>
                            <?php foreach ( $members as $m ) : ?>
                                <?php $label = esc_html( $m['name'] ); $email = trim( strval( $m['email'] ?? '' ) ); if ( $email ) { $label .= ' (' . esc_html( $email ) . ')'; } ?>
                                <option value="<?php echo esc_attr( $m['id'] ); ?>"><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Payment</th>
                    <td>
                        <select name="payment_method">
                            <option value="">Any</option>
                            <option value="cash">Cash</option>
                            <option value="check">Check</option>
                            <option value="digital">Digital</option>
                        </select>
                    </td>
                    <th>Tally Status</th>
                    <td>
                        <select name="tally_status">
                            <option value="untallied">Untallied Only</option>
                            <option value="tallied">Tallied Only</option>
                            <option value="all" selected>All Orders</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Show Deleted</th>
                    <td>
                        <label>
                            <input type="checkbox" name="show_deleted" value="1" />
                            Include deleted orders
                        </label>
                    </td>
                    <th>Page size</th>
                    <td>
                        <select name="page_size">
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100" selected>100</option>
                        </select>
                        <span class="description">(max 100)</span>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <button id="subsales-filter-btn" class="button button-primary">Filter</button>
                <button id="subsales-reset-btn" type="button" class="button">Reset</button>
            </p>
        </form>

        <div style="margin-bottom: 15px;">
            <button id="subsales-bulk-tally-btn" class="button button-secondary" disabled>
                Mark Selected as Tallied
            </button>
            <span id="subsales-selected-count" style="margin-left: 10px; color: #666;"></span>
        </div>

        <div id="subsales-orders-results">
            <p id="subsales-orders-meta" style="margin-bottom:8px"></p>
            <div style="overflow-x: auto; max-width: 100%;">
            <table id="subsales-orders-table" class="widefat striped" cellspacing="0" style="table-layout: auto; min-width: 100%; width: max-content;">
                <thead>
                    <tr>
                        <th style="width: 30px;"><input type="checkbox" id="subsales-select-all" title="Select all" /></th>
                        <th style="white-space: nowrap;">Order ID</th>
                        <th style="white-space: nowrap;">Date</th>
                        <th style="white-space: nowrap;">Member</th>
                        <th style="white-space: nowrap;">Team</th>
                        <?php foreach ( $products_conf as $pcol ) : ?>
                            <th style="text-align:center; white-space: nowrap; padding: 8px 4px;" title="<?php echo esc_attr( $pcol['name'] ); ?>"><?php echo esc_html( substr( $pcol['name'], 0, 6 ) ); ?></th>
                        <?php endforeach; ?>
                        <th style="text-align:right; white-space: nowrap;">Donate</th>
                        <th style="white-space: nowrap;">Pay</th>
                        <th style="text-align:right; white-space: nowrap;">Total</th>
                        <th style="white-space: nowrap;">Tallied</th>
                        <th style="white-space: nowrap;">Actions</th>
                    </tr>
                </thead>
                <tbody id="subsales-orders-tbody">
                    <tr><td colspan="<?php echo 8 + count( $products_conf ); ?>">Use the filters above and click Filter to load orders via AJAX.</td></tr>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="5" style="text-align:right">Page totals:</td>
                        <?php foreach ( $products_conf as $pcol ) : ?>
                            <td id="subsales-page-prod-<?php echo esc_attr( $pcol['id'] ); ?>" style="text-align:center">0</td>
                        <?php endforeach; ?>
                        <td id="subsales-page-donation" style="text-align:right">$0.00</td>
                        <td></td>
                        <td id="subsales-page-total" style="text-align:right">$0.00</td>
                        <td></td>
                    </tr>
                    <tr>
                        <td colspan="5" style="text-align:right">Cash:</td>
                        <?php foreach ( $products_conf as $pcol ) : ?>
                            <td></td>
                        <?php endforeach; ?>
                        <td></td>
                        <td></td>
                        <td id="subsales-page-cash" style="text-align:right">$0.00</td>
                        <td></td>
                    </tr>
                    <tr>
                        <td colspan="5" style="text-align:right">Check:</td>
                        <?php foreach ( $products_conf as $pcol ) : ?>
                            <td></td>
                        <?php endforeach; ?>
                        <td></td>
                        <td></td>
                        <td id="subsales-page-check" style="text-align:right">$0.00</td>
                        <td></td>
                    </tr>
                    <tr>
                        <td colspan="5" style="text-align:right">Digital:</td>
                        <?php foreach ( $products_conf as $pcol ) : ?>
                            <td></td>
                        <?php endforeach; ?>
                        <td></td>
                        <td></td>
                        <td id="subsales-page-digital" style="text-align:right">$0.00</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
            </div>

            <div id="subsales-pagination" style="margin-top:12px"></div>
        </div>

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
                                    <label class="subsales-toggle-switch">
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
                    
                    <h3 style="border: 2px solid #0073aa; padding: 10px; background: #f0f6fc; margin: 15px 0 5px 0; border-radius: 4px;">Products</h3>
                    <table class="form-table" style="border: 2px solid #0073aa; margin-bottom: 15px; border-radius: 4px;">
                        <tbody id="subsales-edit-products"></tbody>
                    </table>
                    
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
        .subsales-modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 100000; }
        .subsales-modal-backdrop { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); }
        .subsales-modal-content { position: relative; max-width: 700px; margin: 40px auto; background: white; border-radius: 4px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); max-height: 90vh; display: flex; flex-direction: column; }
        .subsales-modal-header { padding: 20px 24px; border-bottom: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center; }
        .subsales-modal-header h2 { margin: 0; }
        .subsales-modal-close { background: none; border: none; font-size: 28px; cursor: pointer; padding: 0; line-height: 1; color: #666; }
        .subsales-modal-body { padding: 24px; overflow-y: auto; flex: 1; }
        .subsales-modal-footer { padding: 16px 24px; border-top: 1px solid #ddd; text-align: right; }
        .subsales-modal-footer button { margin-left: 8px; }
        
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
        const ajaxUrl = <?php echo json_encode( $ajax_url ); ?>;
        const nonce = <?php echo json_encode( $nonce ); ?>;
        const configuredProducts = <?php echo json_encode( array_values( $products_conf ) ); ?>;
        const restUrl = <?php echo json_encode( rest_url( 'order-manager/v1/orders/tally' ) ); ?>;
        const restNonce = <?php echo json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
        
        let selectedOrderIds = new Set();

        function serializeForm(form){
            const fd = new FormData();
            fd.append('action','subsales_fetch_orders');
            fd.append('nonce', nonce);
            const f = new FormData(form);
            for (const [k,v] of f.entries()){ if (v !== null) fd.append(k,v); }
            return fd;
        }
        
        function updateTallyButton(){
            const btn = document.getElementById('subsales-bulk-tally-btn');
            const countSpan = document.getElementById('subsales-selected-count');
            const count = selectedOrderIds.size;
            
            if (count > 0) {
                btn.disabled = false;
                countSpan.textContent = count;
            } else {
                btn.disabled = true;
                countSpan.textContent = '0';
            }
        }
        
        function handleCheckboxChange(orderId, checked){
            if (checked) {
                selectedOrderIds.add(orderId);
            } else {
                selectedOrderIds.delete(orderId);
            }
            updateTallyButton();
        }
        
        function handleSelectAllChange(checked){
            const checkboxes = document.querySelectorAll('.subsales-order-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = checked;
                const orderId = parseInt(cb.dataset.orderId);
                if (checked) {
                    selectedOrderIds.add(orderId);
                } else {
                    selectedOrderIds.delete(orderId);
                }
            });
            updateTallyButton();
        }
        
        async function bulkTallyOrders(){
            if (selectedOrderIds.size === 0) {
                alert('Please select at least one order to tally.');
                return;
            }
            
            if (!confirm('Mark ' + selectedOrderIds.size + ' order(s) as tallied?')) {
                return;
            }
            
            const orderIdsArray = Array.from(selectedOrderIds);
            
            try {
                const resp = await fetch(restUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': restNonce
                    },
                    body: JSON.stringify({ order_ids: orderIdsArray })
                });
                
                const data = await resp.json();
                
                if (data.success_count > 0) {
                    alert('Successfully tallied ' + data.success_count + ' order(s)');
                    selectedOrderIds.clear();
                    document.getElementById('subsales-select-all').checked = false;
                    updateTallyButton();
                    fetchPage(1); // Refresh the table
                } else {
                    alert('Failed to tally orders: ' + (data.errors ? data.errors.join(', ') : 'Unknown error'));
                }
            } catch (error) {
                console.error('Tally error:', error);
                alert('Error tallying orders: ' + error.message);
            }
        }

        function renderRows(orders){
            const tbody = document.getElementById('subsales-orders-tbody');
            tbody.innerHTML = '';
                if (!orders || orders.length === 0) {
                tbody.innerHTML = '<tr><td colspan="' + (10 + configuredProducts.length) + '">No orders found for the selected filters.</td></tr>';
                return;
            }
            for (const o of orders){
                const tr = document.createElement('tr');
                // Add class for deleted orders
                if (o.deleted) {
                    tr.className = 'subsales-deleted-order';
                }
                let html = '';
                
                // Checkbox column
                html += '<td style="text-align:center; width:30px;">';
                html += '<input type="checkbox" class="subsales-order-checkbox" data-order-id="' + o.id + '" onchange="handleCheckboxChange(' + o.id + ', this.checked)">';
                html += '</td>';
                
                html += '<td style="white-space: nowrap;">' + escapeHtml(o.order_id) + (o.edited ? ' <span class="subsales-edited-star">*</span>' : '') + '</td>';
                html += '<td style="white-space: nowrap;">' + escapeHtml(o.created_at_formatted) + '</td>';
                html += '<td style="white-space: nowrap;">' + escapeHtml(o.entered_by_name || o.user_id || '') + '</td>';
                html += '<td style="white-space: nowrap;">' + escapeHtml(o.team_name || '') + '</td>';
                // per-configured-product columns
                for (const p of configuredProducts) {
                    const pid = p.id;
                    const qty = (o.products_map && typeof o.products_map[pid] !== 'undefined') ? Number(o.products_map[pid]) : 0;
                    html += '<td style="text-align:center; white-space: nowrap; padding: 8px 4px;">' + escapeHtml(qty) + '</td>';
                }
                // Donation column
                const donationAmt = Number(o.donation_amount || 0);
                html += '<td style="text-align:right; white-space: nowrap;">' + (donationAmt > 0 ? '$' + donationAmt.toFixed(2) : '') + '</td>';
                // Items column removed; individual product columns are shown above.
                html += '<td style="white-space: nowrap;">' + escapeHtml(o.payment_display || '') + '</td>';
                html += '<td style="text-align:right; white-space: nowrap;">$' + Number(o.order_total).toFixed(2) + '</td>';
                
                // Tallied column
                html += '<td style="text-align:center; white-space: nowrap;">';
                if (o.tallied) {
                    const tallyDate = o.tallied_at ? new Date(o.tallied_at).toLocaleDateString() : '';
                    const tallyBy = o.tallied_by ? ' by ' + escapeHtml(o.tallied_by) : '';
                    html += '<span title="Tallied ' + tallyDate + tallyBy + '">✓</span>';
                } else {
                    html += '';
                }
                html += '</td>';
                
                // Actions column
                html += '<td style="white-space: nowrap;">';
                html += '<button class="subsales-action-btn subsales-action-btn-edit" onclick="SubsalesOrderEdit.editOrder(' + o.id + ',\'' + escapeHtml(o.order_id).replace(/'/g, "\\'") + '\')" title="Edit order">✏️ Edit</button>';
                html += '<button class="subsales-action-btn subsales-action-btn-delete" onclick="SubsalesOrderEdit.deleteOrder(' + o.id + ',\'' + escapeHtml(o.order_id).replace(/'/g, "\\'") + '\')" title="Delete order">🗑️ Delete</button>';
                html += '<button class="subsales-action-btn subsales-action-btn-history" onclick="SubsalesOrderEdit.viewHistory(' + o.id + ')" title="View history">📋 History</button>';
                html += '</td>';
                
                tr.innerHTML = html;
                tbody.appendChild(tr);
            }
        }

        function renderMeta(total_count, page, pages){
            const meta = document.getElementById('subsales-orders-meta');
            // Put the page meta on the left and an explanatory note on the right
            meta.innerHTML = 'Showing page ' + page + ' of ' + pages + ' — ' + total_count + ' matching orders' +
                '<span class="subsales-orders-meta-note"><span style="color: red;">*</span> indicates edited order</span>';
        }

        function renderTotals(totals){
            // totals.product_totals is expected to be a map of productId => qty for the current page
            try{
                if (totals && totals.product_totals){
                    for (const p of configuredProducts){
                        const pid = p.id;
                        const el = document.getElementById('subsales-page-prod-' + pid);
                        if (el) el.textContent = (totals.product_totals[pid] !== undefined) ? String(totals.product_totals[pid]) : '0';
                    }
                } else {
                    // clear product totals
                    for (const p of configuredProducts){ const el = document.getElementById('subsales-page-prod-' + p.id); if (el) el.textContent = '0'; }
                }
            }catch(e){ console.warn('renderTotals product totals error', e); }
            document.getElementById('subsales-page-donation').textContent = '$' + Number(totals.donations || 0).toFixed(2);
            document.getElementById('subsales-page-total').textContent = '$' + Number(totals.grand || 0).toFixed(2);
            document.getElementById('subsales-page-cash').textContent = '$' + Number(totals.cash || 0).toFixed(2);
            document.getElementById('subsales-page-check').textContent = '$' + Number(totals.check || 0).toFixed(2);
            document.getElementById('subsales-page-digital').textContent = '$' + Number(totals.digital || 0).toFixed(2);
        }

        function renderPagination(page, pages){
            const el = document.getElementById('subsales-pagination');
            el.innerHTML = '';
            if (pages <= 1) return;
            for (let p=1;p<=pages;p++){
                const btn = document.createElement('button');
                btn.className = 'button';
                btn.style.marginRight = '6px';
                btn.textContent = p;
                if (p === page) btn.disabled = true;
                btn.addEventListener('click', function(){ fetchPage(p); });
                el.appendChild(btn);
            }
        }

        function escapeHtml(s){ if (!s && s !== 0) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

        async function fetchPage(page){
            const form = document.getElementById('subsales-orders-filter');
            const fd = serializeForm(form);
            fd.append('page', page);
            // page_size is included from form
            try {
                const resp = await fetch(ajaxUrl, { method: 'POST', body: fd });
                const data = await resp.json();
                if (!data || !data.success){
                    console.error('AJAX Error:', data);
                    alert('Failed to fetch orders: ' + (data && data.data ? data.data : 'Unknown error'));
                    return;
                }
                const payload = data.data;
                renderRows(payload.orders);
                renderMeta(payload.total_count, payload.page, payload.pages);
                renderTotals(payload.totals);
                renderPagination(payload.page, payload.pages);
            } catch (error) {
                console.error('[Orders Page] Fetch error:', error);
                alert('Error loading orders: ' + error.message);
            }
        }

        document.getElementById('subsales-filter-btn').addEventListener('click', function(){ fetchPage(1); });
        document.getElementById('subsales-reset-btn').addEventListener('click', function(){
            document.getElementById('subsales-orders-filter').reset(); fetchPage(1);
        });
        
        // Select all checkbox handler
        document.getElementById('subsales-select-all').addEventListener('change', function(){
            handleSelectAllChange(this.checked);
        });
        
        // Bulk tally button handler
        document.getElementById('subsales-bulk-tally-btn').addEventListener('click', function(){
            bulkTallyOrders();
        });
        
        // Make functions globally available
        window.handleCheckboxChange = handleCheckboxChange;
        window.handleSelectAllChange = handleSelectAllChange;

        // Quick Search functionality
        let searchTimeout = null;
        const searchInput = document.getElementById('subsales-quick-search');
        const clearSearchBtn = document.getElementById('subsales-clear-search');
        const searchResults = document.getElementById('subsales-search-results');
        
        function performSearch() {
            const searchTerm = searchInput.value.trim();
            
            if (searchTerm.length === 0) {
                searchResults.textContent = '';
                fetchPage(1);
                return;
            }
            
            if (searchTerm.length < 2) {
                searchResults.textContent = 'Enter at least 2 characters to search';
                return;
            }
            
            searchResults.textContent = 'Searching...';
            
            const form = document.getElementById('subsales-orders-filter');
            const fd = serializeForm(form);
            fd.append('page', 1);
            fd.append('search_query', searchTerm);
            
            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(resp => resp.json())
                .then(data => {
                    if (data && data.success) {
                        const payload = data.data;
                        renderRows(payload.orders);
                        renderMeta(payload.total_count, payload.page, payload.pages);
                        renderTotals(payload.totals);
                        renderPagination(payload.page, payload.pages);
                        
                        const count = payload.total_count || 0;
                        searchResults.textContent = count + ' result' + (count !== 1 ? 's' : '') + ' found';
                    } else {
                        searchResults.textContent = 'Search failed';
                    }
                })
                .catch(error => {
                    console.error('Search error:', error);
                    searchResults.textContent = 'Search error';
                });
        }
        
        // Debounced search as user types
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(performSearch, 500);
        });
        
        // Search on Enter key
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                clearTimeout(searchTimeout);
                performSearch();
            }
        });
        
        // Clear search button
        clearSearchBtn.addEventListener('click', function() {
            searchInput.value = '';
            searchResults.textContent = '';
            fetchPage(1);
        });

        // Load first page on open
        fetchPage(1);
        
        // Make fetchPage available globally for refresh after edit/delete
        window.SubsalesRefreshOrders = function(){ fetchPage(1); };
        
        // Auto-open edit modal if 'edit' parameter in URL
        const urlParams = new URLSearchParams(window.location.search);
        const editOrderId = urlParams.get('edit');
        if (editOrderId) {
            // Wait for initial page load, then open edit modal
            setTimeout(function() {
                // The edit parameter is the database ID (order.id)
                // We need to find the order_id (display ID) from the loaded data
                // For now, just pass the db ID twice (editOrder will fetch the data)
                SubsalesOrderEdit.editOrder(parseInt(editOrderId), 'Loading...');
                
                // Clean up URL without reloading page
                const cleanUrl = window.location.pathname + '?page=subsales-orders';
                window.history.replaceState({}, document.title, cleanUrl);
            }, 500); // Give table time to load first
        }
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
                
                // Show modal
                document.getElementById('subsales-edit-modal').style.display = 'block';
                
                // Setup toggle switch listener (after modal is visible)
                const toggleListener = function() {
                    const teamSelect = document.getElementById('edit-team-select');
                    if (this.checked) {
                        // Team mode
                        teamSelect.style.display = 'inline-block';
                        if (!teamSelect.value) {
                            teamSelect.focus();
                        }
                    } else {
                        // Individual mode
                        teamSelect.style.display = 'none';
                        teamSelect.value = '';
                    }
                };
                
                // Remove old listener if exists, add new one
                sellingModeToggle.removeEventListener('change', toggleListener);
                sellingModeToggle.addEventListener('change', toggleListener);
            } catch (error) {
                console.error('Edit order error:', error);
                alert('Failed to load order: ' + error.message);
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
                    html += 'By: ' + this.escapeHtml(item.edited_by_name) + ' | ';
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
</div><!-- .wrap -->
