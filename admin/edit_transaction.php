<?php
require_once __DIR__ . '/../config/app.php';
requireRole(['Pemilik', 'Admin', 'Kasir']);
$pageTitle = 'Edit Transaksi';
$breadcrumbs = [['label' => 'Edit Transaksi']];
$isPopup = isset($_GET['popup']);

if (!$isPopup) {
    include INCLUDES_PATH . '/header.php';
} else {
    echo '<!DOCTYPE html><html><head><link rel="stylesheet" href="'.ASSETS_URL.'/css/main.css?v='.APP_VERSION.'"><style>body { background: #fff; padding: 20px; }</style></head><body>';
}
?>
<div class="card" style="max-width:900px; margin:0 auto;">
    <div class="card-header"><h3 class="card-title">Edit Transaksi</h3></div>
    <div class="card-body">
        <div style="display:flex; gap:8px; margin-bottom:16px;">
            <input id="invoiceSearch" class="form-control" placeholder="Nomor invoice">
            <button class="btn btn-primary" onclick="loadTransaction()">Cari</button>
        </div>
        <div id="transactionArea" style="display:none;">
            <div id="transactionInfo" style="background:var(--gray-50); padding:12px; border-radius:8px; margin-bottom:16px;"></div>
            <h4 style="margin-bottom:8px;">Retur Produk</h4>
            <div id="returnItems"></div>
            <h4 style="margin:18px 0 8px;">Tambah Produk</h4>
            <div style="display:flex; gap:8px; margin-bottom:8px;">
                <input id="productSearch" class="form-control" placeholder="Cari nama produk atau SKU" oninput="searchProducts()">
            </div>
            <div id="productResults" style="display:flex; flex-direction:column; gap:6px; margin-bottom:16px;"></div>
            <div id="addedItems"></div>
            <label class="form-label" style="margin-top:16px;">Catatan edit</label>
            <textarea id="editReason" class="form-control" rows="2" placeholder="Alasan retur / tambahan produk"></textarea>
            <div id="editSummary" style="margin-top:16px; border-top:1px dashed var(--border-color); padding-top:12px;"></div>
            <button class="btn btn-primary" style="width:100%; margin-top:16px;" onclick="submitEdit()">Simpan Edit Transaksi</button>
        </div>
    </div>
</div>
<script>
let sale = null;
let saleItems = [];
let addedItems = [];
const baseUrl = <?= json_encode(BASE_URL) ?>;
const rupiah = value => 'Rp ' + Math.round(value || 0).toLocaleString('id-ID');

async function loadTransaction() {
    const invoice = document.getElementById('invoiceSearch').value.trim();
    if (!invoice) return alert('Masukkan nomor invoice.');
    const res = await fetch(baseUrl + '/api/sales.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'search_invoice', invoice_number:invoice})});
    const result = await res.json();
    if (!result.success) return alert(result.message || 'Invoice tidak ditemukan.');
    sale = result.data.sale;
    saleItems = result.data.items;
    addedItems = [];
    document.getElementById('transactionInfo').innerHTML = '<strong>' + sale.invoice_number + '</strong> &middot; ' + new Date(sale.created_at).toLocaleString('id-ID') + ' &middot; Total awal: <strong>' + rupiah(sale.grand_total) + '</strong>';
    renderReturns(); renderAdded(); updateSummary();
    document.getElementById('transactionArea').style.display = 'block';
}

function renderReturns() {
    document.getElementById('returnItems').innerHTML = saleItems.map(item => '<div style="display:flex; align-items:center; justify-content:space-between; gap:8px; padding:8px 0; border-bottom:1px solid var(--gray-200);"><span style="flex:1;">' + item.product_name + (item.variation_name ? ' - ' + item.variation_name : '') + '<small style="display:block; color:var(--gray-500);">' + item.qty + ' x ' + rupiah(item.unit_price) + ' (tersisa ' + item.qty_returnable + ')</small></span><input type="number" class="returnQty form-control" style="width:70px;" min="0" max="' + item.qty_returnable + '" value="0" data-id="' + item.sale_detail_id + '" data-price="' + item.unit_price + '" onchange="updateSummary()"></div>').join('');
}

async function searchProducts() {
    const query = document.getElementById('productSearch').value.trim();
    if (query.length < 2) { document.getElementById('productResults').innerHTML = ''; return; }
    const res = await fetch(baseUrl + '/api/products.php?action=search&q=' + encodeURIComponent(query) + '&limit=10&price_type=Umum');
    const result = await res.json();
    document.getElementById('productResults').innerHTML = (result.data || []).map(item => '<button type="button" class="btn btn-outline" style="text-align:left;" onclick="addProduct(' + item.variation_id + ',' + JSON.stringify(item.product_name).replace(/"/g,\'&quot;\') + ',' + JSON.stringify(item.variation_name || '').replace(/"/g,\'&quot;\') + ',' + item.selling_price + ')">' + item.product_name + (item.variation_name ? ' - ' + item.variation_name : '') + ' <span style="float:right;">' + rupiah(item.selling_price) + '</span></button>').join('');
}

function addProduct(id, name, variation, price) {
    const existing = addedItems.find(item => item.variation_id === id);
    if (existing) existing.qty++;
    else addedItems.push({variation_id:id, product_name:name, variation_name:variation, unit_price:Number(price), qty:1});
    document.getElementById('productSearch').value = ''; document.getElementById('productResults').innerHTML = ''; renderAdded(); updateSummary();
}
function renderAdded() {
    document.getElementById('addedItems').innerHTML = addedItems.map((item,index) => '<div style="display:flex; align-items:center; gap:8px; padding:8px; background:var(--gray-50); margin-bottom:6px; border-radius:6px;"><span style="flex:1;">' + item.product_name + (item.variation_name ? ' - ' + item.variation_name : '') + '<small style="display:block; color:var(--gray-500);">' + rupiah(item.unit_price) + '</small></span><input type="number" min="1" class="form-control" style="width:70px;" value="' + item.qty + '" onchange="addedItems[' + index + '].qty=Math.max(1,parseInt(this.value)||1); updateSummary()"><button type="button" class="btn btn-outline" onclick="addedItems.splice(' + index + ',1); renderAdded(); updateSummary();">Hapus</button></div>').join('');
}
function updateSummary() {
    let returned = 0, added = 0;
    document.querySelectorAll('.returnQty').forEach(input => returned += (parseInt(input.value)||0) * Number(input.dataset.price));
    addedItems.forEach(item => added += item.qty * item.unit_price);
    document.getElementById('editSummary').innerHTML = '<div style="display:flex; justify-content:space-between;"><span>Total Tambahan</span><strong>' + rupiah(added) + '</strong></div><div style="display:flex; justify-content:space-between;"><span>Total Retur</span><strong style="color:var(--danger);">- ' + rupiah(returned) + '</strong></div><div style="display:flex; justify-content:space-between; margin-top:6px; font-weight:700;"><span>Total Selisih</span><strong>' + rupiah(added-returned) + '</strong></div>';
}
async function submitEdit() {
    if (!sale) return;
    const returnItems = Array.from(document.querySelectorAll('.returnQty')).filter(input => Number(input.value) > 0).map(input => ({sale_detail_id:Number(input.dataset.id), qty:Number(input.value)}));
    if (!returnItems.length && !addedItems.length) return alert('Tambahkan produk atau pilih produk untuk diretur.');
    const res = await fetch(baseUrl + '/api/sales.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'edit_sale', sale_id:sale.id, reason:document.getElementById('editReason').value || 'Edit transaksi', return_items:returnItems, add_items:addedItems})});
    const result = await res.json();
    if (!result.success) return alert(result.message || 'Gagal menyimpan edit transaksi.');
    alert('Transaksi berhasil diedit. Total selisih: ' + rupiah(result.data.total_difference));
    window.location.href = baseUrl + '/admin/print_invoice.php?id=' + sale.id + '&type=thermal';
}
const invoiceParam = new URLSearchParams(location.search).get('invoice');
if (invoiceParam) { document.getElementById('invoiceSearch').value = invoiceParam; loadTransaction(); }
</script>
<?php
if (!$isPopup) {
    include INCLUDES_PATH . '/footer.php';
} else {
    echo '</body></html>';
}
?>
