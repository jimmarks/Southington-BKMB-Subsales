<?php
/**
 * Products / pricing editor - reusable partial.
 *
 * Extracted verbatim (behaviour-wise) from admin/settings-page.php so the
 * Season Setup wizard can show the same editor without duplicating it.
 *
 * Optional variables the caller may set before including this file:
 *   $products_editor_prefix     string  DOM-id prefix. Required when a second
 *                                       copy can exist on the same page - the
 *                                       Settings Products panel is always in
 *                                       the DOM, so the wizard MUST pass one.
 *   $products_editor_in_wizard  bool    true  -> form is submitted by the
 *                                       wizard over AJAX (data-op="products")
 *                                       false -> plain POST back to Settings.
 *
 * Storage: option `order_sync_products`, a JSON string of
 * [{id, name, price (string, 2dp), visible (0|1)}]. Max 10 products.
 *
 * @package Subsales_Management
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once SUBSALES_PLUGIN_PATH . 'includes/class-season-setup.php';

$products_editor_prefix    = isset( $products_editor_prefix ) ? preg_replace( '/[^a-z0-9_]/', '', (string) $products_editor_prefix ) : '';
$products_editor_in_wizard = ! empty( $products_editor_in_wizard );

// Own save handler + own nonce, mirroring admin/address-management-dashboard.php.
// Skipped while doing AJAX: the wizard calls Subsales_Season_Setup::save_products()
// itself and then re-renders this partial, which would otherwise save twice.
if ( isset( $_POST['save_products'] ) && ! wp_doing_ajax() ) {
    check_admin_referer( 'order_sync_settings_nonce' );
    $saved_count = Subsales_Season_Setup::save_products( $_POST );
    echo '<div class="notice notice-success"><p>Products saved! (' . intval( $saved_count ) . ')</p></div>';
}

$configured_products = order_sync_get_products_config();
$pe                  = $products_editor_prefix;
?>
<?php
// Posting to "#tab-products" keeps the hash, so the Settings page reopens this
// panel after saving and the notice above is actually visible. (The wizard
// intercepts the submit, so it needs no action.)
?>
<form method="post"<?php echo $products_editor_in_wizard ? ' data-op="products"' : ' action="#tab-products"'; ?>>
    <?php wp_nonce_field( 'order_sync_settings_nonce' ); ?>
    <input type="hidden" name="step" value="4" />
    <table class="form-table">
        <tr>
            <td>
                <div id="<?php echo esc_attr( $pe . 'products_repeatable' ); ?>">
                    <table id="<?php echo esc_attr( $pe . 'products_table' ); ?>" class="widefat subsales-products-table">
                        <thead><tr><th class="col-name">Name</th><th class="col-price">Price (USD)</th><th class="col-visible">Visible</th><th class="col-actions">Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ( (array) $configured_products as $idx => $p ) : ?>
                            <tr data-index="<?php echo intval( $idx ); ?>">
                                <td>
                                    <input type="text" name="product_name[]" class="regular-text product-name" value="<?php echo esc_attr( $p['name'] ?? '' ); ?>" />
                                    <input type="hidden" name="product_id[]" class="product-id" value="<?php echo esc_attr( $p['id'] ?? '' ); ?>" />
                                </td>
                                <td><input type="text" name="product_price[]" class="regular-text product-price" value="<?php echo esc_attr( $p['price'] ?? '0.00' ); ?>" /></td>
                                <td class="col-center"><input type="checkbox" name="product_visible[]" value="<?php echo esc_attr( $p['id'] ?? $idx ); ?>" <?php checked( 1, intval( $p['visible'] ?? 0 ) ); ?> /></td>
                                <td class="col-center"><button type="button" class="button button-link remove-product">Remove</button></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p><button type="button" id="<?php echo esc_attr( $pe . 'add_product_btn' ); ?>" class="button">Add product</button> <span class="description">Max 10 products.</span></p>
                </div>
                <script>
                (function(){
                    var maxProducts = 10;
                    var tableId = <?php echo wp_json_encode( $pe . 'products_table' ); ?>;
                    var addBtn = document.getElementById(<?php echo wp_json_encode( $pe . 'add_product_btn' ); ?>);
                    var table = document.getElementById(tableId);
                    if (!addBtn || !table) return;
                    function slugify(s){ return String(s||'').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/(^-|-$)/g,'').substr(0,60); }
                    function wireRemove(btn){ btn.addEventListener('click', function(){ var tr = btn.closest('tr'); if (tr) tr.parentNode.removeChild(tr); }); }
                    function createRow(name, price, id, visible){
                        var tbody = table.querySelector('tbody');
                        if (!tbody) return null;
                        if (tbody.querySelectorAll('tr').length >= maxProducts) { alert('Maximum products reached ('+maxProducts+')'); return null; }
                        var tr = document.createElement('tr');
                        var tdName = document.createElement('td');
                        var nameInput = document.createElement('input'); nameInput.type='text'; nameInput.name='product_name[]'; nameInput.className='regular-text product-name'; nameInput.value = name || '';
                        var idInput = document.createElement('input'); idInput.type='hidden'; idInput.name='product_id[]'; idInput.className='product-id'; idInput.value = id || '';
                        tdName.appendChild(nameInput); tdName.appendChild(idInput);

                        var tdPrice = document.createElement('td');
                        var priceInput = document.createElement('input'); priceInput.type='text'; priceInput.name='product_price[]'; priceInput.className='regular-text product-price'; priceInput.value = price || '0.00';
                        tdPrice.appendChild(priceInput);

                        var tdVis = document.createElement('td'); tdVis.className='col-center';
                        var visInput = document.createElement('input'); visInput.type='checkbox'; visInput.name='product_visible[]'; visInput.checked = !!visible; visInput.value = id || '';
                        tdVis.appendChild(visInput);

                        var tdAct = document.createElement('td'); tdAct.className='col-center';
                        var remBtn = document.createElement('button'); remBtn.type='button'; remBtn.className='button button-link remove-product'; remBtn.textContent='Remove';
                        tdAct.appendChild(remBtn);

                        tr.appendChild(tdName); tr.appendChild(tdPrice); tr.appendChild(tdVis); tr.appendChild(tdAct);
                        nameInput.addEventListener('input', function(){
                            var slug = slugify(nameInput.value) || ('p'+Date.now());
                            idInput.value = slug;
                            visInput.value = slug;
                        });
                        wireRemove(remBtn);
                        tbody.appendChild(tr);
                        return tr;
                    }
                    addBtn.addEventListener('click', function(){ createRow('', '0.00', 'p' + Date.now(), true); });
                    table.querySelectorAll('.remove-product').forEach(wireRemove);
                })();
                </script>
            </td>
        </tr>
    </table>
    <p class="submit"><?php submit_button( 'Save Products', 'primary', 'save_products', false ); ?></p>
</form>
