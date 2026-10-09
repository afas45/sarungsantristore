<?php
/**
 * Sarung Santri Store - Import & Export Harga Produk + Manajemen Tipe Harga
 *
 * Aturan import:
 *  - Kolom harga KOSONG  → tidak diupdate (skip)
 *  - Kolom harga = 0     → disimpan sebagai 0 (update ke 0)
 *  - Kolom harga > 0     → disimpan/diupdate sesuai nilai
 */
require_once __DIR__ . '/../config/app.php';
requireRole(['Pemilik', 'Admin']);

$db = Database::conn();
$pageTitle = 'Import & Export Harga';

// =============================================
// MANAJEMEN TIPE HARGA (add / delete)
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (($_POST['action'] ?? '') === 'add_price_type') {
        $name = sanitize($_POST['price_type_name'] ?? '');
        if ($name) {
            $db->prepare("INSERT IGNORE INTO price_types (name) VALUES (?)")->execute([$name]);
            flashMessage('success', 'Tipe harga "' . htmlspecialchars($name) . '" berhasil ditambahkan.');
            logActivity('Add', 'Tipe Harga', 'Tambah tipe harga: ' . $name);
        }
        redirect(BASE_URL . '/admin/import_prices.php');
    }

    if (($_POST['action'] ?? '') === 'delete_price_type') {
        $id = (int)$_POST['price_type_id'];
        if ($id === 1 || $id === 2) {
            flashMessage('error', 'Tipe harga bawaan sistem tidak dapat dihapus.');
        } else {
            $stmt = $db->prepare("SELECT name FROM price_types WHERE id = ?");
            $stmt->execute([$id]);
            $pt = $stmt->fetch();
            if ($pt) {
                $db->prepare("UPDATE customers SET price_type_id = 1 WHERE price_type_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM product_prices WHERE price_type_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM tiered_prices WHERE price_type_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM price_types WHERE id = ?")->execute([$id]);
                flashMessage('success', 'Tipe harga "' . htmlspecialchars($pt['name']) . '" berhasil dihapus.');
                logActivity('Delete', 'Tipe Harga', 'Hapus tipe harga: ' . $pt['name']);
            }
        }
        redirect(BASE_URL . '/admin/import_prices.php');
    }
}

// =============================================
// EXPORT HARGA PRODUK → CSV
// =============================================
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=harga_produk_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');
    // BOM untuk Excel agar bisa baca UTF-8
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    // Urutan: Reseller dulu, Umum kedua, lalu sisanya by id
    $ptList = $db->query("
        SELECT id, name FROM price_types
        ORDER BY CASE WHEN name = 'Reseller' THEN 0 WHEN name = 'Umum' THEN 1 ELSE 2 END, id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Header baris
    $hdrRow = ['SKU Induk', 'Nama Produk', 'Kategori', 'HPP', 'Label Harga', 'Min. Qty'];
    foreach ($ptList as $pt) {
        $hdrRow[] = ($pt['name'] === 'Umum') ? 'Harga Umum' : 'Harga ' . $pt['name'];
    }
    fputcsv($out, $hdrRow);

    // Ambil semua produk
    $prods = $db->query("
        SELECT p.id, p.sku, p.name, c.name AS category, p.base_price
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        ORDER BY p.name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Ambil semua harga produk sekaligus
    $priceRows = $db->query("
        SELECT pp.product_id, pp.label, pp.min_qty, pp.price_type_id, pp.selling_price
        FROM product_prices pp
        ORDER BY pp.product_id, pp.min_qty, pp.price_type_id
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Kelompokkan harga per produk → per label+min_qty → per price_type_id
    $allPP = []; // [product_id][label_minqty] = ['label'=>.., 'min_qty'=>.., 'prices'=>[pt_id=>price]]
    foreach ($priceRows as $r) {
        $pid  = (int)$r['product_id'];
        $key  = $r['label'] . '__' . (int)$r['min_qty'];
        if (!isset($allPP[$pid][$key])) {
            $allPP[$pid][$key] = [
                'label'   => $r['label'],
                'min_qty' => (int)$r['min_qty'],
                'prices'  => [],
            ];
        }
        $allPP[$pid][$key]['prices'][(int)$r['price_type_id']] = (float)$r['selling_price'];
    }

    foreach ($prods as $p) {
        $pid  = (int)$p['id'];
        $rows = array_values($allPP[$pid] ?? []);

        // Produk tanpa harga → tampilkan 1 baris kosong sebagai template
        if (empty($rows)) {
            $rows = [['label' => 'Ecer', 'min_qty' => 1, 'prices' => []]];
        }

        // Urutkan berdasarkan min_qty
        usort($rows, fn($a, $b) => $a['min_qty'] <=> $b['min_qty']);

        foreach ($rows as $row) {
            $line = [
                $p['sku'],
                $p['name'],
                $p['category'] ?? '',
                // HPP tanpa desimal jika bulat
                (floor($p['base_price']) == $p['base_price']) ? (int)$p['base_price'] : $p['base_price'],
                $row['label'],
                $row['min_qty'],
            ];
            foreach ($ptList as $pt) {
                $ptId = (int)$pt['id'];
                // Jika ada harga (termasuk 0) → tulis angka; jika tidak ada data → tulis kosong
                if (array_key_exists($ptId, $row['prices'])) {
                    $v = $row['prices'][$ptId];
                    $line[] = (floor($v) == $v) ? (int)$v : $v;
                } else {
                    $line[] = '';
                }
            }
            fputcsv($out, $line);
        }
    }
    fclose($out);
    exit;
}

// =============================================
// IMPORT HARGA PRODUK ← CSV
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_product_prices') {

    if (empty($_FILES['csv_file_pp']['name']) || $_FILES['csv_file_pp']['error'] !== UPLOAD_ERR_OK) {
        flashMessage('error', 'Pilih file CSV yang valid.');
        redirect(BASE_URL . '/admin/import_prices.php');
    }

    $handle = fopen($_FILES['csv_file_pp']['tmp_name'], 'r');
    // Strip BOM jika ada
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($handle);

    $hdrs = fgetcsv($handle);
    if ($hdrs === false) {
        flashMessage('error', 'File CSV kosong atau format tidak valid.');
        redirect(BASE_URL . '/admin/import_prices.php');
    }

    // Trim semua header untuk antisipasi spasi ekstra
    $hdrs = array_map('trim', $hdrs);

    // Cari indeks kolom wajib
    $skuIdx   = array_search('SKU Induk', $hdrs);
    $hppIdx   = array_search('HPP', $hdrs);
    $labelIdx = array_search('Label Harga', $hdrs);
    $mqIdx    = array_search('Min. Qty', $hdrs);

    if ($skuIdx === false) {
        flashMessage('error', 'Kolom "SKU Induk" tidak ditemukan. Pastikan menggunakan template yang diekspor dari sistem ini.');
        redirect(BASE_URL . '/admin/import_prices.php');
    }

    // Mapping kolom harga → tipe harga
    $ptMap = []; // [column_index => ['id'=>.., 'name'=>..]]
    $pts   = $db->query("SELECT id, name FROM price_types")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($pts as $pt) {
        $colName = ($pt['name'] === 'Umum') ? 'Harga Umum' : 'Harga ' . $pt['name'];
        $idx = array_search($colName, $hdrs);
        if ($idx !== false) {
            $ptMap[$idx] = $pt;
        }
    }

    if (empty($ptMap)) {
        flashMessage('error', 'Tidak ada kolom harga yang dikenali. Pastikan header CSV sesuai (contoh: "Harga Umum", "Harga Reseller").');
        redirect(BASE_URL . '/admin/import_prices.php');
    }

    // Siapkan statement
    $stmtFindProd = $db->prepare("SELECT id FROM products WHERE sku = ? LIMIT 1");
    $stmtUpdateHpp = $db->prepare("UPDATE products SET last_base_price = base_price, base_price = ? WHERE id = ?");
    $stmtUpsertPrice = $db->prepare("
        INSERT INTO product_prices (product_id, label, price_type_id, min_qty, selling_price)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE selling_price = VALUES(selling_price)
    ");

    $cntUpdated = 0;
    $cntSkipped = 0;
    $cntError   = 0;
    $errLines   = [];

    $lineNum = 1; // header sudah dibaca
    while (($row = fgetcsv($handle)) !== false) {
        $lineNum++;
        $row = array_map('trim', $row);

        // Skip baris kosong
        if (empty($row[$skuIdx])) continue;

        $sku = $row[$skuIdx];

        // Cari produk berdasarkan SKU
        $stmtFindProd->execute([$sku]);
        $pid = $stmtFindProd->fetchColumn();
        if (!$pid) {
            $cntError++;
            $errLines[] = "Baris {$lineNum}: SKU \"{$sku}\" tidak ditemukan.";
            continue;
        }

        // Tentukan label dan min_qty
        $minQty = ($mqIdx !== false && isset($row[$mqIdx]) && $row[$mqIdx] !== '')
                  ? max(1, (int)$row[$mqIdx])
                  : 1;

        $label  = ($labelIdx !== false && isset($row[$labelIdx]) && $row[$labelIdx] !== '')
                  ? $row[$labelIdx]
                  : '';

        // Paksa label 'Ecer' jika min_qty = 1
        if ($minQty == 1) {
            $label = 'Ecer';
        } elseif (empty($label)) {
            $label = 'Grosir';
        }

        try {
            // Update HPP hanya jika kolom HPP tidak kosong
            if ($hppIdx !== false && isset($row[$hppIdx]) && $row[$hppIdx] !== '') {
                $hpp = (float)str_replace(['.', ','], ['', '.'], $row[$hppIdx]);
                $stmtUpdateHpp->execute([$hpp, $pid]);
            }

            // Proses setiap kolom harga
            $rowHasUpdate = false;
            foreach ($ptMap as $ci => $pt) {
                // Kolom tidak ada atau kosong → skip, tidak ubah harga
                if (!isset($row[$ci]) || $row[$ci] === '') continue;

                // Kolom berisi nilai (0 atau lebih) → simpan/update
                $price = (float)str_replace(['.', ','], ['', '.'], $row[$ci]);
                $stmtUpsertPrice->execute([$pid, $label, $pt['id'], $minQty, $price]);
                $rowHasUpdate = true;
            }

            if ($rowHasUpdate) {
                $cntUpdated++;
            } else {
                $cntSkipped++;
            }
        } catch (PDOException $e) {
            $cntError++;
            $errLines[] = "Baris {$lineNum}: SKU \"{$sku}\" — error database.";
        }
    }
    fclose($handle);

    // Pesan ringkasan
    $msg = "Import selesai: <strong>{$cntUpdated}</strong> baris harga diperbarui";
    if ($cntSkipped > 0) $msg .= ", <strong>{$cntSkipped}</strong> baris dilewati (semua kolom harga kosong)";
    if ($cntError   > 0) $msg .= ", <strong>{$cntError}</strong> baris gagal";
    $msg .= '.';

    logActivity('Impor', 'Harga Produk', "Import {$cntUpdated} baris harga dari CSV");
    flashMessage('success', $msg);

    // Simpan detail error ke session untuk ditampilkan
    if (!empty($errLines)) {
        $_SESSION['import_price_errors'] = $errLines;
    }
    redirect(BASE_URL . '/admin/import_prices.php');
}

// =============================================
// DATA UNTUK HALAMAN
// =============================================
$priceTypes = $db->query("
    SELECT * FROM price_types
    ORDER BY CASE WHEN name = 'Reseller' THEN 0 WHEN name = 'Umum' THEN 1 ELSE 2 END, id ASC
")->fetchAll();

// Ambil error detail dari session jika ada
$importDetailErrors = $_SESSION['import_price_errors'] ?? [];
unset($_SESSION['import_price_errors']);

$breadcrumbs = [
    ['label' => 'Harga Produk', 'url' => BASE_URL . '/admin/prices.php'],
    ['label' => 'Import & Export Harga'],
];
include INCLUDES_PATH . '/header.php';
?>

<style>
.ip-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(340px,100%), 1fr)); gap: 20px; }
.ip-hint { font-size: 0.8rem; color: var(--gray-500); line-height: 1.55; }
.ip-hint code { background: var(--gray-100); padding: 1px 5px; border-radius: 4px; font-size: 0.78rem; }
.pt-badge-sys { background: var(--primary-100); color: var(--primary-700); padding: 2px 7px; border-radius: 4px; font-size: 0.68rem; font-weight: 600; margin-left: 6px; }
</style>

<div class="container">

    <!-- ===== TOOLBAR ===== -->
    <div class="toolbar">
        <div style="flex:1;"></div>

        <a href="?export=1" class="btn btn-outline" style="gap:8px; color:darkred; border-color:darkred;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            Ekspor Harga (CSV)
        </a>
    </div>

    <!-- ===== DETAIL ERROR (jika ada) ===== -->
    <?php if (!empty($importDetailErrors)): ?>
    <div class="alert alert-warning" style="margin-bottom:20px;">
        <div class="alert-icon">⚠️</div>
        <div class="alert-message">
            <strong>Detail baris gagal:</strong>
            <ul style="margin:6px 0 0 18px; padding:0;">
                <?php foreach (array_slice($importDetailErrors, 0, 15) as $err): ?>
                    <li style="font-size:0.82rem;"><?= htmlspecialchars($err) ?></li>
                <?php endforeach; ?>
                <?php if (count($importDetailErrors) > 15): ?>
                    <li style="color:var(--gray-500);">... dan <?= count($importDetailErrors) - 15 ?> baris lainnya.</li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <!-- ===== IMPORT + PANDUAN ===== -->
    <div class="ip-grid" style="margin-bottom:20px;">

        <!-- Panduan -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">📖 Panduan Import Harga</h3>
            </div>
            <div class="card-body">
                <ol class="ip-hint" style="padding-left:18px; margin:0;">
                    <li style="margin-bottom:8px;">Klik <strong>Ekspor Harga Produk (CSV)</strong> di kanan atas untuk mengunduh template CSV berisi data harga saat ini.</li>
                    <li style="margin-bottom:8px;">Buka file di Excel / Google Sheets. Kolom yang tersedia:<br>
                        <code>SKU Induk | Nama Produk | Kategori | HPP | Label Harga | Min. Qty | Harga Umum | Harga Reseller | ...</code>
                    </li>
                    <li style="margin-bottom:8px;">Edit harga yang ingin diperbarui. <strong>Aturan kolom harga:</strong>
                        <ul style="margin:4px 0 0 16px; padding:0; list-style:disc;">
                            <li><strong>Kosong</strong> → tidak diubah (aman untuk dilewati)</li>
                            <li><strong>0</strong> → harga diset ke 0</li>
                            <li><strong>Angka > 0</strong> → harga diperbarui ke nilai tersebut</li>
                        </ul>
                    </li>
                    <li style="margin-bottom:8px;">Satu SKU boleh punya beberapa baris untuk tier berbeda (Min. Qty berbeda).</li>
                    <li style="margin-bottom:8px;"><strong>Jangan ubah</strong> kolom <code>SKU Induk</code>. Simpan sebagai format <strong>CSV</strong>.</li>
                    <li>Upload file CSV di form sebelah, lalu klik <strong>Impor</strong>.</li>
                </ol>
            </div>
        </div>

        <!-- Form Import -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">📥 Impor Harga Produk</h3>
            </div>
            <div class="card-body">
                <p class="ip-hint" style="margin-bottom:16px;">
                    Upload file CSV hasil ekspor yang sudah diedit. Hanya kolom yang terisi yang akan diperbarui — kolom kosong aman dibiarkan.
                </p>
                <form method="POST" enctype="multipart/form-data" style="display:flex; flex-direction:column; gap:16px;">
                    <input type="hidden" name="action" value="import_product_prices">
                    <div class="form-group">
                        <label class="form-label">File CSV Harga Produk <span class="required">*</span></label>
                        <input type="file" name="csv_file_pp" class="form-control" accept=".csv" required style="padding:10px;">
                        <span class="form-hint">Format: hasil ekspor dari tombol "Ekspor Harga Produk (CSV)"</span>
                    </div>
                    <button type="submit" class="btn btn-primary" style="align-self:flex-start; gap:8px;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        Impor Harga Produk
                    </button>
                </form>
            </div>
        </div>

    </div>

    <!-- ===== MANAJEMEN TIPE HARGA ===== -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">🏷️ Manajemen Tipe Harga</h3>
        </div>
        <div class="card-body">
            <p class="ip-hint" style="margin-bottom:16px;">
                Tipe harga menentukan kolom harga di menu <strong>Harga Produk</strong> dan CSV ekspor. Tambah tipe baru di sini, maka kolom baru akan otomatis muncul.
            </p>
            <div style="display:flex; flex-wrap:wrap; gap:20px; align-items:flex-start;">

                <!-- Form Tambah Tipe -->
                <div style="flex:1; min-width:260px;">
                    <form method="POST" style="background:var(--gray-50); padding:16px; border-radius:8px; border:1px solid var(--border-color); display:flex; flex-direction:column; gap:12px;">
                        <input type="hidden" name="action" value="add_price_type">
                        <div class="form-group" style="margin:0;">
                            <label class="form-label">Nama Tipe Harga Baru</label>
                            <input type="text" name="price_type_name" class="form-control" required placeholder="Contoh: Reseller VIP, Shopee, TikTok">
                            <span class="form-hint">Nama ini akan menjadi kolom baru di CSV dan menu Harga Produk.</span>
                        </div>
                        <button type="submit" class="btn btn-primary" style="gap:6px;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            Tambah Tipe Harga
                        </button>
                    </form>
                </div>

                <!-- Tabel Tipe Harga -->
                <div style="flex:2; min-width:min(280px,100%); border:1px solid var(--border-color); border-radius:8px; overflow:hidden;">
                    <table class="table" style="margin:0;">
                        <thead style="background:var(--gray-50);">
                            <tr>
                                <th style="width:50px;">ID</th>
                                <th>Nama Tipe Harga</th>
                                <th style="width:80px; text-align:center;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($priceTypes as $pt): ?>
                            <tr>
                                <td style="color:var(--gray-400); font-size:0.78rem;"><?= $pt['id'] ?></td>
                                <td>
                                    <?= htmlspecialchars($pt['name']) ?>
                                    <?php if ($pt['id'] == 1 || $pt['id'] == 2): ?>
                                        <span class="pt-badge-sys">Bawaan Sistem</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:center;">
                                    <?php if ($pt['id'] != 1 && $pt['id'] != 2): ?>
                                    <form method="POST" onsubmit="return confirm('Hapus tipe harga "<?= addslashes(htmlspecialchars($pt['name'])) ?>"?\n\nSemua harga produk dengan tipe ini akan ikut terhapus, dan pelanggan terkait akan dialihkan ke tipe Umum.');" style="display:inline;">
                                        <input type="hidden" name="action" value="delete_price_type">
                                        <input type="hidden" name="price_type_id" value="<?= $pt['id'] ?>">
                                        <button type="submit" class="btn btn-sm" style="color:var(--danger); border:1px solid var(--danger); padding:3px 8px; background:none;" title="Hapus">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                                        </button>
                                    </form>
                                    <?php else: ?>
                                        <span style="color:var(--gray-300); font-size:0.75rem;">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>
    </div>

</div>

<?php include INCLUDES_PATH . '/footer.php'; ?>
