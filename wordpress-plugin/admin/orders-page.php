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
// Sellers only, and only the ones who sold in the season being viewed.
//
// The filter is deliberately NOT m.role - that column is person-level and does
// not reset between seasons, so a kid who drove last year and sells this year
// still reads as 'driver' and would vanish from a dropdown that trusted it.
// ss_signups.is_driver is per signup, which is what actually changes each year.
//
// A member counts as a seller in a season if they signed up as one, OR if they
// have orders in it - several sellers have orders but no signup row, and
// dropping them would make their own orders unfilterable. Anyone whose only
// involvement in a season was driving never appears for that season.
$member_rows = $wpdb->get_results(
	"SELECT m.id, m.name, m.email, t.season_id
	   FROM {$wpdb->prefix}ss_team_members m
	   JOIN {$wpdb->prefix}ss_signups s
	     ON s.user_id = m.id AND s.status = 'active' AND s.is_driver = 0
	   JOIN {$wpdb->prefix}ss_teams t ON t.id = s.team_id
	  UNION
	 SELECT m.id, m.name, m.email, o.season_id
	   FROM {$wpdb->prefix}ss_team_members m
	   JOIN {$wpdb->prefix}ss_orders o ON o.user_id = m.id AND o.deleted = 0",
	ARRAY_A
);

// Collapse to one row per member carrying the seasons they sold in, so the
// dropdown can be re-filtered client-side when the season selector changes
// without a round trip.
$members = array();
foreach ( $member_rows as $mr ) {
	$mid = strval( $mr['id'] );
	if ( ! isset( $members[ $mid ] ) ) {
		$members[ $mid ] = array(
			'id'      => $mr['id'],
			'name'    => $mr['name'],
			'email'   => $mr['email'],
			'seasons' => array(),
		);
	}
	$members[ $mid ]['seasons'][] = intval( $mr['season_id'] );
}
usort(
	$members,
	function ( $a, $b ) {
		return strcasecmp( strval( $a['name'] ), strval( $b['name'] ) );
	}
);
$products_conf = order_sync_get_products_config();

// Seasons for the scope selector. The page defaults to the current season so
// next season's reconciliation queue does not quietly include last season's
// unchecked orders; "All seasons" stays available for looking back on purpose.
$seasons_list       = $wpdb->get_results( "SELECT id, label FROM {$wpdb->prefix}ss_seasons ORDER BY id DESC", ARRAY_A );
$current_season_id  = Subsales_Database::current_season_id();

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
                                <?php
                                $label = esc_html( $m['name'] );
                                $email = trim( strval( $m['email'] ?? '' ) );
                                if ( $email ) { $label .= ' (' . esc_html( $email ) . ')'; }
                                $seasons_attr = implode( ',', array_unique( $m['seasons'] ) );
                                $in_season    = in_array( $current_season_id, $m['seasons'], true );
                                ?>
                                <option value="<?php echo esc_attr( $m['id'] ); ?>" data-seasons="<?php echo esc_attr( $seasons_attr ); ?>" <?php echo $in_season ? '' : 'hidden'; ?>><?php echo $label; ?></option>
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
                    <th>Season</th>
                    <td>
                        <select name="season_id">
                            <?php foreach ( $seasons_list as $s ) : ?>
                                <option value="<?php echo intval( $s['id'] ); ?>" <?php selected( intval( $s['id'] ), $current_season_id ); ?>>
                                    <?php echo esc_html( $s['label'] ); ?><?php echo intval( $s['id'] ) === $current_season_id ? ' (current)' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                            <option value="0">All seasons</option>
                        </select>
                    </td>
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
            <button id="subsales-bulk-untally-btn" class="button button-secondary" disabled title="Return the selected orders to untallied - use if a batch was checked off in error">
                Return Selected to Untallied
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
                    <tr class="subsales-filtered-totals">
                        <td colspan="5" style="text-align:right">
                            All <span id="subsales-ft-count">0</span> matching orders &mdash;
                            Cash <span id="subsales-ft-cash">$0.00</span> &middot;
                            Check <span id="subsales-ft-check">$0.00</span> &middot;
                            Digital <span id="subsales-ft-digital">$0.00</span>
                        </td>
                        <?php foreach ( $products_conf as $pcol ) : ?>
                            <td id="subsales-ft-prod-<?php echo esc_attr( $pcol['id'] ); ?>" style="text-align:center">0</td>
                        <?php endforeach; ?>
                        <td id="subsales-ft-donation" style="text-align:right">$0.00</td>
                        <td></td>
                        <td id="subsales-ft-total" style="text-align:right">$0.00</td>
                        <td></td>
                    </tr>
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

    <?php include SUBSALES_PLUGIN_PATH . 'admin/partials/order-edit-modal.php'; ?>

    <script>
    (function(){
        const ajaxUrl = <?php echo json_encode( $ajax_url ); ?>;
        const nonce = <?php echo json_encode( $nonce ); ?>;
        const configuredProducts = <?php echo json_encode( array_values( $products_conf ) ); ?>;
        const restUrl = <?php echo json_encode( rest_url( 'order-manager/v1/orders/tally' ) ); ?>;
        const untallyUrl = <?php echo json_encode( rest_url( 'order-manager/v1/orders/untally' ) ); ?>;
        const restNonce = <?php echo json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
        const refundUrlBase = <?php echo json_encode( rest_url( 'order-manager/v1/orders/' ) ); ?>;
        function refundUrlFor(id){ return refundUrlBase + encodeURIComponent(id) + '/refund'; }
        
        let selectedOrderIds = new Set();

        // The Team member list follows the season selector: a kid who sold last
        // season is not a choice while you are looking at this one. Options
        // carry the seasons they sold in, so no round trip is needed.
        function syncMemberOptions(){
            const seasonSel = document.querySelector('select[name="season_id"]');
            const memberSel = document.querySelector('select[name="entered_by_id"]');
            if (!seasonSel || !memberSel) return;
            const season = String(seasonSel.value || '');
            let selectedHidden = false;
            Array.prototype.forEach.call(memberSel.options, function(opt){
                if (!opt.value) return;                       // keep "All members"
                const seasons = (opt.dataset.seasons || '').split(',').filter(Boolean);
                // "0" is All seasons, where everyone who ever sold is fair game.
                const show = (season === '0') || seasons.indexOf(season) !== -1;
                opt.hidden = !show;
                opt.disabled = !show;
                if (!show && opt.selected) selectedHidden = true;
            });
            // Never leave a hidden option selected - the filter would silently
            // apply a member you cannot see in the list.
            if (selectedHidden) memberSel.value = '';
        }

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
            const unBtn = document.getElementById('subsales-bulk-untally-btn');
            const countSpan = document.getElementById('subsales-selected-count');
            const count = selectedOrderIds.size;
            
            if (count > 0) {
                btn.disabled = false;
                if (unBtn) unBtn.disabled = false;
                countSpan.textContent = count;
            } else {
                btn.disabled = true;
                if (unBtn) unBtn.disabled = true;
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
        
        // Shared by both buttons - tallying and reversing a tally differ only in
        // the endpoint and the wording, so they run through one path.
        async function bulkSetTally(untally){
            const verb = untally ? 'return to untallied' : 'tally';
            if (selectedOrderIds.size === 0) {
                alert('Please select at least one order to ' + verb + '.');
                return;
            }

            const question = untally
                ? 'Return ' + selectedOrderIds.size + ' order(s) to untallied?'
                : 'Mark ' + selectedOrderIds.size + ' order(s) as tallied?';
            if (!confirm(question)) {
                return;
            }

            const orderIdsArray = Array.from(selectedOrderIds);

            try {
                const resp = await fetch(untally ? untallyUrl : restUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': restNonce
                    },
                    body: JSON.stringify({ order_ids: orderIdsArray })
                });

                const data = await resp.json();

                if (data.success_count > 0) {
                    alert(data.message || ('Updated ' + data.success_count + ' order(s)'));
                    selectedOrderIds.clear();
                    document.getElementById('subsales-select-all').checked = false;
                    updateTallyButton();
                    fetchPage(1); // Refresh the table
                } else {
                    alert('Failed to ' + verb + ': ' + (data.errors ? data.errors.join(', ') : 'Unknown error'));
                }
            } catch (error) {
                console.error('Tally error:', error);
                alert('Error trying to ' + verb + ': ' + error.message);
            }
        }

        async function bulkTallyOrders(){ return bulkSetTally(false); }
        async function bulkUntallyOrders(){ return bulkSetTally(true); }

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
                
                // Order id on line one, the saved address on line two. Scanning a
                // day sorted by entry time, an address that breaks the street run
                // (191, 203, 207 Wild St ... 181 Franklin Rd ... 213 Wild St) is
                // the tell that it was recorded wrong.
                html += '<td>' + escapeHtml(o.order_id) + (o.edited ? ' <span class="subsales-edited-star">*</span>' : '');
                html += '<div class="subsales-order-address" title="' + escapeHtml(o.address || '') + '">' + (o.address ? escapeHtml(o.address) : '<em>no address</em>') + '</div>';
                html += '</td>';
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

        // Totals for every order matching the current filter, not just the page.
        // This is the figure to reconcile a sale day against - the page row above
        // resets every 100 rows, and a big day runs to seven or eight pages.
        function renderFilteredTotals(ft){
            if (!ft) ft = {};
            const money = (v) => '$' + Number(v || 0).toFixed(2);
            const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
            set('subsales-ft-count', String(ft.order_count || 0));
            set('subsales-ft-cash', money(ft.cash));
            set('subsales-ft-check', money(ft.check));
            set('subsales-ft-digital', money(ft.digital));
            set('subsales-ft-donation', money(ft.donations));
            set('subsales-ft-total', money(ft.grand));
            try{
                for (const p of configuredProducts){
                    const el = document.getElementById('subsales-ft-prod-' + p.id);
                    if (el) el.textContent = (ft.product_totals && ft.product_totals[p.id] !== undefined) ? String(ft.product_totals[p.id]) : '0';
                }
            }catch(e){ console.warn('renderFilteredTotals product totals error', e); }
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
                renderFilteredTotals(payload.filtered_totals);
                renderPagination(payload.page, payload.pages);
            } catch (error) {
                console.error('[Orders Page] Fetch error:', error);
                alert('Error loading orders: ' + error.message);
            }
        }

        document.getElementById('subsales-filter-btn').addEventListener('click', function(){ fetchPage(1); });
        document.getElementById('subsales-reset-btn').addEventListener('click', function(){
            document.getElementById('subsales-orders-filter').reset();
            syncMemberOptions();
            fetchPage(1);
        });

        (function(){
            const seasonSel = document.querySelector('select[name="season_id"]');
            if (seasonSel) seasonSel.addEventListener('change', syncMemberOptions);
            syncMemberOptions();
        })();
        
        // Select all checkbox handler
        document.getElementById('subsales-select-all').addEventListener('change', function(){
            handleSelectAllChange(this.checked);
        });
        
        // Bulk tally button handler
        document.getElementById('subsales-bulk-tally-btn').addEventListener('click', function(){
            bulkTallyOrders();
        });

        document.getElementById('subsales-bulk-untally-btn').addEventListener('click', function(){
            bulkUntallyOrders();
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
                        renderFilteredTotals(payload.filtered_totals);
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
    
    // The Edit Order dialog now lives in admin/partials/order-edit-modal.php,
    // included below, so the Text Messages screen can open the same one.
    </script>
</div><!-- .wrap -->
