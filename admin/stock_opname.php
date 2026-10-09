<?php
/**
 * Kasir Ibtidaiyah - Opname Produk (Stocktaking)
 * Mencocokkan stok fisik dengan stok sistem
 */
require_once __DIR__ . '/../config/app.php';
requireRole(['Pemilik', 'Admin']);

$db = Database::conn();
$pageTitle = 'Opname Produk';
$breadcrumbs = [['label' => 'Opname Produk']];

// =============================================
// HANDLE POST: Create/Complete Opname
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_opname') {
        try {
            $db->beginTransaction();
            
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $notes = sanitize($_POST['notes'] ?? '');
            $physicalStocks = $_POST['physical_stock'] ?? [];
            $variationIds = $_POST['variation_id'] ?? [];
            $userId = $_SESSION['user_id'];
            
            if (empty($variationIds)) {
                throw new Exception('Tidak ada data produk untuk diopname.');
            }
            
            $opnameNumber = generateOpnameNumber();
            
            // Create opname header
            $stmt = $db->prepare("INSERT INTO stock_opnames (opname_number, user_id, notes, status) VALUES (?, ?, ?, 'Completed')");
            $stmt->execute([$opnameNumber, $userId, $notes]);
            $opnameId = $db->lastInsertId();
            
            $adjustmentCount = 0;
            
            foreach ($variationIds as $idx => $varId) {
                $varId = (int)$varId;
                $physicalStock = (int)($physicalStocks[$idx] ?? 0);
                
                // Get current system stock
                $stmt = $db->prepare("SELECT stock_qty FROM product_variations WHERE id = ? FOR UPDATE");
                $stmt->execute([$varId]);
                $systemStock = (int)$stmt->fetchColumn();
                
                // Insert detail
                $stmt = $db->prepare("INSERT INTO stock_opname_details (opname_id, product_variation_id, system_stock, physical_stock) VALUES (?, ?, ?, ?)");
                $stmt->execute([$opnameId, $varId, $systemStock, $physicalStock]);
                
                // Adjust stock if different
                if ($physicalStock !== $systemStock) {
                    $db->prepare("UPDATE product_variations SET stock_qty = ? WHERE id = ?")
                       ->execute([$physicalStock, $varId]);
                    $adjustmentCount++;
                }
            }
            
            $db->commit();
            flashMessage('success', "Opname $opnameNumber berhasil. $adjustmentCount item disesuaikan.");
            logActivity('Opname', 'Stock', "Opname: $opnameNumber, Adjusted: $adjustmentCount items");
            
        } catch (Exception $e) {
            $db->rollBack();
            flashMessage('error', $e->getMessage());
        }
        
        redirect(BASE_URL . '/admin/stock_opname.php');
    }
}

// =============================================
// MODE: Opname baru (show form) atau riwayat
// =============================================
$mode = $_GET['mode'] ?? 'history';
$selectedCategory = (int)($_GET['category'] ?? 0);

// Get categories for filter
$categories = $db->query("SELECT id, name FROM categories ORDER BY sort_order, name")->fetchAll();

// Get opname history
$opnameHistory = $db->query("
    SELECT so.*, u.full_name as user_name,
           (SELECT COUNT(*) FROM stock_opname_details WHERE opname_id = so.id) as item_count,
           (SELECT COUNT(*) FROM stock_opname_details WHERE opname_id = so.id AND difference != 0) as diff_count
    FROM stock_opnames so
    JOIN users u ON so.user_id = u.id
    ORDER BY so.created_at DESC
    LIMIT 50
")->fetchAll();

// Get products for opname form
$products = [];
if ($mode === 'new') {
    $where = "WHERE p.is_active = 1 AND pv.is_active = 1";
    $params = [];
    if ($selectedCategory > 0) {
        $where .= " AND p.category_id = ?";
        $params[] = $selectedCategory;
    }
    
    $stmt = $db->prepare("
        SELECT pv.id as variation_id, p.name as product_name, pv.variation_name, pv.sku, pv.stock_qty, 
               c.name as category_name
        FROM product_variations pv
        JOIN products p ON pv.product_id = p.id
        LEFT JOIN categories c ON p.category_id = c.id
        $where
        ORDER BY c.name, p.name, pv.variation_name
    ");
    $stmt->execute($params);
    $products = $stmt->fetchAll();
}

include INCLUDES_PATH . '/header.php';
?>

<!-- Toolbar -->
<div class="toolbar">
    <div class="filter-group">
        <a href="?mode=history" class="btn <?= $mode === 'history' ? 'btn-primary' : 'btn-outline' ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            Riwayat
        </a>
        <a href="?mode=new" class="btn <?= $mode === 'new' ? 'btn-primary' : 'btn-outline' ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Opname Baru
        </a>
        
        <?php 
        $importType = 'opname';
        $hasImport = true; 
        $hasTemplate = true;
        include INCLUDES_PATH . '/import_export_toolbar.php'; 
        ?>
    </div>
</div>

<?php if ($mode === 'new'): ?>
<!-- ========================= -->
<!-- NEW OPNAME FORM -->
<!-- ========================= -->
<div class="card" style="margin-bottom:16px;">
    <div class="card-header">
        <h3 class="card-title">Filter Kategori</h3>
    </div>
    <div class="card-body" style="display:flex; gap:8px; flex-wrap:wrap;">
        <a href="?mode=new&category=0" class="btn btn-sm <?= $selectedCategory === 0 ? 'btn-primary' : 'btn-outline' ?>">Semua</a>
        <?php foreach ($categories as $cat): ?>
            <a href="?mode=new&category=<?= $cat['id'] ?>" class="btn btn-sm <?= $selectedCategory === $cat['id'] ? 'btn-primary' : 'btn-outline' ?>">
                <?= htmlspecialchars($cat['name']) ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<?php if (!empty($products)): ?>
<form method="POST" onsubmit="return confirm('Simpan dan sesuaikan stok? Aksi ini tidak bisa dibatalkan.')">
    <input type="hidden" name="action" value="create_opname">
    <input type="hidden" name="category_id" value="<?= $selectedCategory ?>">
    
    <div class="card">
        <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
            <h3 class="card-title">Lembar Kerja Opname (<?= count($products) ?> item)</h3>
            <div class="form-group" style="margin:0; max-width:300px;">
                <input type="text" class="form-control" placeholder="Cari produk..." oninput="filterOpnameTable(this.value)">
            </div>
        </div>
        <div class="card-body" style="padding:0;">
            <div class="table-responsive">
                <table class="table" id="opnameTable">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>SKU</th>
                            <th>Produk</th>
                            <th>Kategori</th>
                            <th style="text-align:center;">Stok Sistem</th>
                            <th style="text-align:center; width:120px;">Stok Fisik</th>
                            <th style="text-align:center;">Selisih</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $idx => $prod): ?>
                            <tr data-search="<?= strtolower($prod['sku'] . ' ' . $prod['product_name']) ?>">
                                <td><?= $idx + 1 ?></td>
                                <td><code><?= htmlspecialchars($prod['sku']) ?></code></td>
                                <td>
                                    <span class="text-bold"><?= htmlspecialchars($prod['product_name']) ?></span>
                                    <?php if ($prod['variation_name']): ?>
                                        <span class="text-muted"> — <?= htmlspecialchars($prod['variation_name']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($prod['category_name'] ?? '-') ?></td>
                                <td style="text-align:center; font-weight:600;"><?= $prod['stock_qty'] ?></td>
                                <td style="text-align:center;">
                                    <input type="hidden" name="variation_id[]" value="<?= $prod['variation_id'] ?>">
                                    <input type="number" name="physical_stock[]" class="form-control opname-input" 
                                           value="<?= $prod['stock_qty'] ?>" min="0" 
                                           data-system="<?= $prod['stock_qty'] ?>"
                                           onchange="calcDiff(this)" style="width:90px; text-align:center; margin:0 auto;">
                                </td>
                                <td style="text-align:center;" class="opname-diff">0</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <div class="card" style="margin-top:16px;">
        <div class="card-body">
            <div class="form-group">
                <label class="form-label">Catatan Opname</label>
                <textarea name="notes" class="form-control" rows="2" placeholder="Catatan opsional..."></textarea>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <a href="?mode=history" class="btn btn-outline">Batal</a>
                <button type="submit" class="btn btn-primary">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    Simpan & Sesuaikan Stok
                </button>
            </div>
        </div>
    </div>
</form>
<?php else: ?>
    <div class="card">
        <div class="card-body" style="text-align:center; padding:40px; color:var(--gray-400);">
            Tidak ada produk untuk diopname.
        </div>
    </div>
<?php endif; ?>

<?php else: ?>
<!-- ========================= -->
<!-- OPNAME HISTORY -->
<!-- ========================= -->
<div class="card">
    <div class="card-body" style="padding:0;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>No. Opname</th>
                        <th>Petugas</th>
                        <th>Tanggal</th>
                        <th>Item Dicek</th>
                        <th>Item Disesuaikan</th>
                        <th>Status</th>
                        <th>Catatan</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($opnameHistory)): ?>
                        <tr><td colspan="7" class="text-center text-muted" style="padding:40px;">Belum ada riwayat opname</td></tr>
                    <?php else: ?>
                        <?php foreach ($opnameHistory as $op): ?>
                            <tr>
                                <td><span class="text-bold"><?= htmlspecialchars($op['opname_number']) ?></span></td>
                                <td><?= htmlspecialchars($op['user_name']) ?></td>
                                <td><?= formatTanggal($op['created_at'], true) ?></td>
                                <td><?= $op['item_count'] ?> item</td>
                                <td>
                                    <?php if ($op['diff_count'] > 0): ?>
                                        <span class="badge badge-warning"><?= $op['diff_count'] ?> selisih</span>
                                    <?php else: ?>
                                        <span class="badge badge-success">Sesuai</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge badge-<?= $op['status'] === 'Completed' ? 'success' : 'warning' ?>"><?= $op['status'] ?></span></td>
                                <td class="text-sm text-muted"><?= htmlspecialchars($op['notes'] ?? '-') ?></td>
                                <td>
                                    <div class="actions">
                                        <a href="<?= BASE_URL ?>/admin/print_opname.php?id=<?= $op['id'] ?>" target="_blank" class="btn btn-sm btn-outline btn-icon" title="Preview">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        </a>
                                        <a href="<?= BASE_URL ?>/admin/print_opname.php?id=<?= $op['id'] ?>&download=1" class="btn btn-sm btn-outline btn-icon" title="Download">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function calcDiff(input) {
    const system = parseInt(input.dataset.system) || 0;
    const physical = parseInt(input.value) || 0;
    const diff = physical - system;
    const td = input.closest('tr').querySelector('.opname-diff');
    
    td.textContent = diff > 0 ? `+${diff}` : diff;
    td.style.color = diff === 0 ? 'var(--gray-500)' : (diff > 0 ? 'var(--success)' : 'var(--danger)');
    td.style.fontWeight = diff !== 0 ? '700' : '400';
}

function filterOpnameTable(query) {
    const rows = document.querySelectorAll('#opnameTable tbody tr[data-search]');
    query = query.toLowerCase();
    rows.forEach(row => {
        const search = row.getAttribute('data-search');
        row.style.display = search.includes(query) ? '' : 'none';
    });
}
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
