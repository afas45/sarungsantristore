<?php
/**
 * Kasir Ibtidaiyah - Arus Kas (Cash Flow)
 */
require_once __DIR__ . '/../config/app.php';
requireRole(['Pemilik', 'Admin']);
if (!hasPermission('menu_arus_kas')) {
    die("Akses ditolak.");
}

$db = Database::conn();
$pageTitle = 'Arus Kas';
$breadcrumbs = [['label' => 'Arus Kas']];

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_cashflow') {
        $id = (int)($_POST['id'] ?? 0);
        $type = sanitize($_POST['type'] ?? 'Income');
        $date = $_POST['date'] ?? date('Y-m-d');
        $amount = (float)str_replace(['Rp', '.', ' '], '', $_POST['amount'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        
        $category = $type === 'Transfer' ? 'Pindah Saldo' : sanitize($_POST['category'] ?? '');
        $account_from = in_array($type, ['Expense', 'Transfer']) ? sanitize($_POST['account_from'] ?? '') : null;
        $account_to = in_array($type, ['Income', 'Transfer']) ? sanitize($_POST['account_to'] ?? '') : null;
        
        try {
            $db->beginTransaction();
            
            if ($type === 'Transfer' && $account_from === $account_to) {
                throw new Exception("Akun asal dan tujuan tidak boleh sama.");
            }
            
            if ($id > 0) {
                $stmt = $db->prepare("UPDATE cash_flow SET date=?, type=?, category=?, account_from=?, account_to=?, amount=?, description=? WHERE id=?");
                $stmt->execute([$date, $type, $category, $account_from, $account_to, $amount, $description, $id]);
                $msg = "Data arus kas berhasil diperbarui.";
                logActivity('Ubah', 'Arus Kas', "ID $id diperbarui: $type");
            } else {
                $stmt = $db->prepare("INSERT INTO cash_flow (date, type, category, account_from, account_to, amount, description, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$date, $type, $category, $account_from, $account_to, $amount, $description, $_SESSION['user_id']]);
                $msg = "Data arus kas berhasil dicatat.";
                logActivity('Tambah', 'Arus Kas', "Catat $type: $category (Rp " . number_format($amount, 0, ',', '.') . ")");
            }
            
            $db->commit();
            flashMessage('success', $msg);
        } catch (Exception $e) {
            $db->rollBack();
            flashMessage('error', 'Gagal: ' . $e->getMessage());
        }
        
        header("Location: " . BASE_URL . "/admin/cash_flow.php");
        exit;
    }
    
    if ($action === 'delete_cashflow') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $db->prepare("DELETE FROM cash_flow WHERE id = ?")->execute([$id]);
            flashMessage('success', 'Data arus kas berhasil dihapus.');
            logActivity('Hapus', 'Arus Kas', "ID $id dihapus.");
        } catch (Exception $e) {
            flashMessage('error', 'Gagal menghapus data: ' . $e->getMessage());
        }
        header("Location: " . BASE_URL . "/admin/cash_flow.php");
        exit;
    }
}

// Filters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$search = sanitize($_GET['search'] ?? '');

// 1. Get all active accounts based on transactions and settings
$stmt = $db->query("
    SELECT DISTINCT account FROM (
        SELECT CAST(payment_method AS CHAR) as account FROM payments
        UNION
        SELECT CAST(account_from AS CHAR) as account FROM cash_flow WHERE account_from IS NOT NULL
        UNION
        SELECT CAST(account_to AS CHAR) as account FROM cash_flow WHERE account_to IS NOT NULL
    ) as all_accounts
    WHERE account IS NOT NULL AND account != ''
");
$allAccounts = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Also add default configured accounts if they are not yet in the DB
$configAccounts = ['Tunai'];
$banks = ['bca' => 'BCA', 'mandiri' => 'Mandiri', 'bni' => 'BNI', 'bri' => 'BRI', 'bsi' => 'BSI', 'emaal' => 'Emaal'];
foreach ($banks as $bk => $bn) {
    if (getSetting('payment_bank_'.$bk.'_account')) $configAccounts[] = 'Bank ' . $bn;
}
$ewallets = ['dana' => 'Dana', 'ovo' => 'OVO', 'gopay' => 'Gopay', 'shopeepay' => 'Shopeepay', 'linkaja' => 'LinkAja'];
foreach ($ewallets as $ew => $en) {
    if (getSetting('payment_ewallet_'.$ew.'_account')) $configAccounts[] = 'E-Wallet ' . $en;
}
if (getSetting('payment_qris_image')) $configAccounts[] = 'QRIS';

$allAccounts = array_unique(array_merge($allAccounts, $configAccounts));
sort($allAccounts); // Sort alphabetically, but keep Tunai first if possible
$tunaiIndex = array_search('Tunai', $allAccounts);
if ($tunaiIndex !== false) {
    unset($allAccounts[$tunaiIndex]);
    array_unshift($allAccounts, 'Tunai');
}

// 2. Calculate Total Income, Expense, Balance
// Alur: penjualan TIDAK langsung masuk arus kas.
// Pemasukan = setoran shift (actual amounts) + entri manual income
// Pengeluaran = pembayaran pembelian (outgoing) + entri manual expense
$totalIncome = 0;
$totalExpense = 0;

// Entri manual cash_flow
$stmt = $db->query("SELECT type, SUM(amount) as total FROM cash_flow GROUP BY type");
while ($row = $stmt->fetch()) {
    if ($row['type'] === 'Income') {
        $totalIncome += (float)$row['total'];
    } elseif ($row['type'] === 'Expense') {
        $totalExpense += (float)$row['total'];
    }
}

// Pembayaran pembelian (bukan dari penjualan) sebagai pengeluaran
$stmt = $db->query("SELECT COALESCE(SUM(amount), 0) as total_out FROM payments WHERE payment_type = 'Outgoing' AND purchase_id IS NOT NULL");
if ($row = $stmt->fetch()) {
    $totalExpense += (float)$row['total_out'];
}

// Setoran shift (actual_cash + actual_transfer + actual_emaal + actual_bsi) sebagai PEMASUKAN
$stmt = $db->query("SELECT 
    COALESCE(SUM(actual_cash), 0) as total_cash,
    COALESCE(SUM(actual_transfer), 0) as total_transfer,
    COALESCE(SUM(actual_emaal), 0) as total_emaal,
    COALESCE(SUM(actual_bsi), 0) as total_bsi
    FROM cash_settlements WHERE status = 'Closed'");
if ($row = $stmt->fetch()) {
    $totalIncome += (float)$row['total_cash'] + (float)$row['total_transfer'] + (float)$row['total_emaal'] + (float)$row['total_bsi'];
}

$sisaKas = $totalIncome - $totalExpense;


// 3. Fetch Ledger (History)
$stmt = $db->prepare("
    SELECT * FROM (
        -- Cash Flow Manual
        SELECT 
            id, date as tx_date, created_at, 
            CAST(type AS CHAR) as type, 
            CAST(category AS CHAR) as category, 
            CAST(account_from AS CHAR) as account_from, 
            CAST(account_to AS CHAR) as account_to, 
            amount, 
            CAST(description AS CHAR) as description, 
            'manual' as source
        FROM cash_flow
        
        UNION ALL
        
        -- Pembayaran Pembelian (Outgoing dari payments - bukan dari penjualan)
        SELECT 
            pm.id, DATE(pm.payment_date) as tx_date, pm.payment_date as created_at, 
            'Expense' as type,
            'Pembelian' as category,
            CAST(pm.payment_method AS CHAR) as account_from,
            NULL as account_to,
            pm.amount,
            CAST(COALESCE((SELECT surat_jalan_number FROM delivery_notes WHERE purchase_id = pm.purchase_id LIMIT 1), (SELECT po_number FROM purchases WHERE id = pm.purchase_id), 'Pembayaran Pembelian') AS CHAR) as description,
            'system' as source
        FROM payments pm
        WHERE pm.payment_type = 'Outgoing' AND pm.purchase_id IS NOT NULL

        UNION ALL

        -- Setoran Tunai dari Tutup Shift (actual_cash > 0)
        SELECT
            cs.id, DATE(cs.shift_end) as tx_date, cs.shift_end as created_at,
            'Income' as type,
            'Setoran Shift' as category,
            NULL as account_from,
            'Tunai' as account_to,
            cs.actual_cash as amount,
            CAST(CONCAT('Setoran Tunai - Shift #', cs.id, ' (', COALESCE(u2.full_name, 'Kasir'), ')') AS CHAR) as description,
            'system' as source
        FROM cash_settlements cs
        LEFT JOIN users u2 ON cs.cashier_id = u2.id
        WHERE cs.status = 'Closed' AND cs.actual_cash > 0

        UNION ALL

        -- Setoran Non-Tunai Transfer Bank dari Tutup Shift (actual_transfer > 0)
        SELECT
            cs.id + 10000000, DATE(cs.shift_end) as tx_date, cs.shift_end as created_at,
            'Income' as type,
            'Setoran Shift' as category,
            NULL as account_from,
            'Transfer Bank' as account_to,
            cs.actual_transfer as amount,
            CAST(CONCAT('Setoran Transfer Bank - Shift #', cs.id, ' (', COALESCE(u2.full_name, 'Kasir'), ')') AS CHAR) as description,
            'system' as source
        FROM cash_settlements cs
        LEFT JOIN users u2 ON cs.cashier_id = u2.id
        WHERE cs.status = 'Closed' AND cs.actual_transfer > 0

        UNION ALL

        -- Setoran Non-Tunai E-Wallet/Emaal dari Tutup Shift (actual_emaal > 0)
        SELECT
            cs.id + 20000000, DATE(cs.shift_end) as tx_date, cs.shift_end as created_at,
            'Income' as type,
            'Setoran Shift' as category,
            NULL as account_from,
            'E-Wallet / Emaal' as account_to,
            cs.actual_emaal as amount,
            CAST(CONCAT('Setoran E-Wallet/Emaal - Shift #', cs.id, ' (', COALESCE(u2.full_name, 'Kasir'), ')') AS CHAR) as description,
            'system' as source
        FROM cash_settlements cs
        LEFT JOIN users u2 ON cs.cashier_id = u2.id
        WHERE cs.status = 'Closed' AND cs.actual_emaal > 0

        UNION ALL

        -- Setoran Non-Tunai QRIS/BSI dari Tutup Shift (actual_bsi > 0)
        SELECT
            cs.id + 30000000, DATE(cs.shift_end) as tx_date, cs.shift_end as created_at,
            'Income' as type,
            'Setoran Shift' as category,
            NULL as account_from,
            'QRIS / BSI' as account_to,
            cs.actual_bsi as amount,
            CAST(CONCAT('Setoran QRIS/BSI - Shift #', cs.id, ' (', COALESCE(u2.full_name, 'Kasir'), ')') AS CHAR) as description,
            'system' as source
        FROM cash_settlements cs
        LEFT JOIN users u2 ON cs.cashier_id = u2.id
        WHERE cs.status = 'Closed' AND cs.actual_bsi > 0
    ) AS ledger
    WHERE tx_date BETWEEN ? AND ?" . ($search ? " AND (category LIKE ? OR account_from LIKE ? OR account_to LIKE ? OR description LIKE ?)" : "") . "
    ORDER BY created_at DESC, id DESC
");

$params = [$start_date, $end_date];
if ($search) {
    $searchWild = "%$search%";
    $params = array_merge($params, [$searchWild, $searchWild, $searchWild, $searchWild]);
}
$stmt->execute($params);
$ledger = $stmt->fetchAll();

// Handle Export Excel
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename=arus_kas_' . $start_date . '_sd_' . $end_date . '.xls');
    
    echo '<table border="1">';
    // 1. Calculate Summary
    $summary = [];
    foreach ($ledger as $tx) {
        if ($tx['type'] === 'Income' && $tx['account_to']) {
            $acc = $tx['account_to'];
            if (!isset($summary[$acc])) $summary[$acc] = ['in' => 0, 'out' => 0];
            $summary[$acc]['in'] += $tx['amount'];
        } elseif ($tx['type'] === 'Expense' && $tx['account_from']) {
            $acc = $tx['account_from'];
            if (!isset($summary[$acc])) $summary[$acc] = ['in' => 0, 'out' => 0];
            $summary[$acc]['out'] += $tx['amount'];
        } elseif ($tx['type'] === 'Transfer') {
            if ($tx['account_from']) {
                $acc_from = $tx['account_from'];
                if (!isset($summary[$acc_from])) $summary[$acc_from] = ['in' => 0, 'out' => 0];
                $summary[$acc_from]['out'] += $tx['amount'];
            }
            if ($tx['account_to']) {
                $acc_to = $tx['account_to'];
                if (!isset($summary[$acc_to])) $summary[$acc_to] = ['in' => 0, 'out' => 0];
                $summary[$acc_to]['in'] += $tx['amount'];
            }
        }
    }
    
    // 2. Output Summary
    echo '<tr><th colspan="4" style="background:#f0f0f0;">RINGKASAN METODE PEMBAYARAN</th></tr>';
    echo '<tr><th style="background:#f8f9fa;">Rekening</th><th style="background:#f8f9fa;">Pemasukan</th><th style="background:#f8f9fa;">Pengeluaran</th><th style="background:#f8f9fa;">Total (Sisa)</th></tr>';
    ksort($summary);
    foreach ($summary as $acc => $totals) {
        $net = $totals['in'] - $totals['out'];
        echo '<tr>';
        echo '<td>' . htmlspecialchars($acc) . '</td>';
        echo '<td>' . $totals['in'] . '</td>';
        echo '<td>' . $totals['out'] . '</td>';
        echo '<td>' . $net . '</td>';
        echo '</tr>';
    }
    
    // Empty row separator
    echo '<tr><td colspan="4"></td></tr>';
    
    echo '<tr><th colspan="6" style="background:#f0f0f0;">DETAIL TRANSAKSI</th></tr>';
    echo '<tr><th style="background:#f8f9fa;">Waktu</th><th style="background:#f8f9fa;">Kategori</th><th style="background:#f8f9fa;">Tipe</th><th style="background:#f8f9fa;">Kas / Rekening</th><th style="background:#f8f9fa;">Keterangan</th><th style="background:#f8f9fa;">Nominal</th></tr>';
    
    foreach ($ledger as $tx) {
        if ($tx['type'] === 'Income') {
            $tipe = 'Pemasukan';
            $akun = $tx['account_to'];
            $nom = '+' . $tx['amount'];
        } elseif ($tx['type'] === 'Expense') {
            $tipe = 'Pengeluaran';
            $akun = $tx['account_from'];
            $nom = '-' . $tx['amount'];
        } else {
            $tipe = 'Pindah Saldo';
            $akun = $tx['account_from'] . ' -> ' . $tx['account_to'];
            $nom = $tx['amount'];
        }
        
        echo '<tr>';
        echo '<td>' . date('d/m/Y H:i', strtotime($tx['created_at'])) . '</td>';
        echo '<td>' . htmlspecialchars($tx['category'] . ($tx['source'] === 'system' ? ' (Sistem)' : '')) . '</td>';
        echo '<td>' . htmlspecialchars($tipe) . '</td>';
        echo '<td>' . htmlspecialchars($akun) . '</td>';
        echo '<td>' . htmlspecialchars($tx['description']) . '</td>';
        echo '<td>' . $nom . '</td>';
        echo '</tr>';
    }
    
    echo '</table>';
    exit;
}

include INCLUDES_PATH . '/header.php';
?>

<style>
@media (max-width: 768px) {
    /* Kartu Arus Kas jadi 3 Baris & Persegi Panjang (Horizontal) */
    .stats-grid {
        grid-template-columns: 1fr !important;
    }
    .stats-grid .stat-card {
        flex-direction: row !important;
        align-items: center !important;
        justify-content: flex-start !important;
        padding: 20px !important;
        aspect-ratio: auto !important;
        min-height: auto !important;
    }
    .stats-grid .stat-icon {
        margin-bottom: 0 !important;
        margin-right: 0 !important;
        width: 48px !important;
        height: 48px !important;
    }
    .stats-grid .stat-icon svg,
    .stats-grid .stat-icon i {
        width: 24px !important;
        height: 24px !important;
    }
    .stats-grid .stat-info {
        text-align: left !important;
        display: flex;
        flex-direction: column;
        align-items: flex-start !important;
    }
    .stats-grid .stat-label,
    .stats-grid .stat-value,
    .stats-grid .stat-change {
        text-align: left !important;
        width: 100%;
    }
    
    /* Rapikan Filter & Input */
    .cf-filter-inputs {
        flex-wrap: wrap !important;
    }
    .cf-search-group {
        min-width: 100% !important;
        flex: 1 1 100% !important;
    }
    .cf-date-group {
        min-width: calc(50% - 6px) !important;
        flex: 1 1 calc(50% - 6px) !important;
    }
    .cf-filter-actions {
        width: 100% !important;
        flex-direction: row !important;
    }
    .cf-filter-actions .btn {
        width: calc(50% - 6px) !important;
        justify-content: center !important;
        padding: 10px 0 !important;
        font-size: 0.85rem !important;
    }
}
</style>

<!-- BALANCES CARDS -->
<div class="stats-grid" style="margin-bottom: 24px;">
    
    <!-- Total Pendapatan -->
    <div class="stat-card success">
        <div class="stat-icon success">
            <i data-lucide="arrow-down-left" style="width:24px;height:24px; color: currentColor;"></i>
        </div>
        <div class="stat-info">
            <div class="stat-label">Total Pendapatan</div>
            <div class="stat-value"><?= formatRupiah($totalIncome) ?></div>
            <div class="stat-change">Pemasukan</div>
        </div>
    </div>

    <!-- Total Pengeluaran -->
    <div class="stat-card warning">
        <div class="stat-icon warning">
            <i data-lucide="arrow-up-right" style="width:24px;height:24px; color: currentColor;"></i>
        </div>
        <div class="stat-info">
            <div class="stat-label">Total Pengeluaran</div>
            <div class="stat-value"><?= formatRupiah($totalExpense) ?></div>
            <div class="stat-change">Pengeluaran</div>
        </div>
    </div>

    <!-- Sisa (Balance) -->
    <div class="stat-card primary">
        <div class="stat-icon primary">
            <i data-lucide="wallet" style="width:24px;height:24px; color: currentColor;"></i>
        </div>
        <div class="stat-info">
            <div class="stat-label">Sisa Kas</div>
            <div class="stat-value"><?= formatRupiah($sisaKas) ?></div>
            <div class="stat-change">Saldo Saat Ini</div>
        </div>
    </div>

</div>

<!-- ACTIONS & FILTERS -->
<div class="card" style="margin-bottom: 24px;">
    <div class="card-body" style="padding: 16px; display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end; justify-content: space-between;">
        <form method="GET" style="display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; flex: 1; width: 100%;">
            <div class="cf-filter-inputs" style="display: flex; gap: 12px; flex-wrap: nowrap; max-width: 600px; width: 100%;">
                <div class="form-group cf-search-group" style="margin-bottom: 0; flex: 1;">
                    <label class="form-label" style="font-size: 0.75rem;">Cari (Kategori/Keterangan)</label>
                    <input type="text" name="search" class="form-control" style="width: 100%; padding: 8px;" placeholder="Ketik lalu tunggu sebentar..." value="<?= htmlspecialchars($search) ?>" oninput="clearTimeout(this.timer); this.timer = setTimeout(() => { this.form.submit(); }, 600);">
                </div>
                <div class="form-group cf-date-group" style="margin-bottom: 0; flex: 1;">
                    <label class="form-label" style="font-size: 0.75rem;">Dari Tanggal</label>
                    <input type="date" name="start_date" class="form-control" style="width: 100%; padding: 8px;" value="<?= htmlspecialchars($start_date) ?>" onchange="this.form.submit()">
                </div>
                <div class="form-group cf-date-group" style="margin-bottom: 0; flex: 1;">
                    <label class="form-label" style="font-size: 0.75rem;">Sampai Tanggal</label>
                    <input type="date" name="end_date" class="form-control" style="width: 100%; padding: 8px;" value="<?= htmlspecialchars($end_date) ?>" onchange="this.form.submit()">
                </div>
            </div>
        </form>
        <div class="cf-filter-actions" style="display: flex; gap: 12px; flex-wrap: wrap;">
            <a href="?search=<?= urlencode($search) ?>&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>&export=excel" class="btn btn-outline" style="font-weight: 600; padding: 10px 20px; white-space: nowrap; margin-bottom: 1px;">
                <i data-lucide="download" style="width:20px;height:20px; margin-right:8px;"></i>
            </a>
            <button class="btn btn-primary" onclick="openCashflowModal()" style="font-weight: 600; padding: 10px 20px; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3); white-space: nowrap; margin-bottom: 1px;">
                <i data-lucide="plus" style="width:20px;height:20px; margin-right:8px;"></i> Tambah Transaksi
            </button>
        </div>
    </div>
</div>

<!-- LEDGER TABLE -->
<div class="card">
    <div class="card-body" style="padding:0;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Waktu</th>
                        <th>Kategori</th>
                        <th>Tipe</th>
                        <th>Kas / Rekening</th>
                        <th>Keterangan</th>
                        <th>Nominal</th>
                        <th class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($ledger)): ?>
                        <tr><td colspan="7" class="text-center text-muted" style="padding: 40px;">Tidak ada riwayat transaksi pada rentang tanggal ini.</td></tr>
                    <?php endif; ?>
                    
                    <?php foreach ($ledger as $tx): ?>
                    <tr>
                        <td>
                            <div class="text-bold"><?= date('d/m/Y', strtotime($tx['tx_date'])) ?></div>
                            <div class="text-xs text-muted"><?= date('H:i', strtotime($tx['created_at'])) ?></div>
                        </td>
                        <td>
                            <?= htmlspecialchars($tx['category']) ?>
                            <?php if ($tx['source'] === 'system'): ?>
                                <span class="badge badge-gray" style="font-size:0.6rem; padding: 2px 4px; margin-left: 4px;">Sistem</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($tx['type'] === 'Income'): ?>
                                <span class="badge badge-success">Pemasukan</span>
                            <?php elseif ($tx['type'] === 'Expense'): ?>
                                <span class="badge badge-danger">Pengeluaran</span>
                            <?php else: ?>
                                <span class="badge badge-primary">Pindah Saldo</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($tx['type'] === 'Income'): ?>
                                <strong style="color:var(--gray-900)"><?= htmlspecialchars($tx['account_to']) ?></strong>
                            <?php elseif ($tx['type'] === 'Expense'): ?>
                                <strong style="color:var(--gray-900)"><?= htmlspecialchars($tx['account_from']) ?></strong>
                            <?php else: ?>
                                <?= htmlspecialchars($tx['account_from']) ?> &rarr; <?= htmlspecialchars($tx['account_to']) ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-sm text-muted">
                            <?= nl2br(htmlspecialchars($tx['description'])) ?>
                        </td>
                        <td class="text-bold <?= $tx['type'] === 'Income' ? 'text-success' : ($tx['type'] === 'Expense' ? 'text-danger' : 'text-primary') ?>">
                            <?= $tx['type'] === 'Income' ? '+' : ($tx['type'] === 'Expense' ? '-' : '') ?>
                            <?= formatRupiah($tx['amount']) ?>
                        </td>
                        <td class="text-center">
                            <?php if ($tx['source'] === 'manual'): ?>
                                <div class="actions justify-center" style="display: flex; gap: 8px; justify-content: center;">
                                    <button class="btn btn-sm btn-outline btn-icon" title="Edit" onclick='editCashflow(<?= json_encode($tx) ?>)'>
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    </button>
                                    <form method="POST" style="display:inline; margin:0;" onsubmit="return confirm('Hapus transaksi ini permanen?')">
                                        <input type="hidden" name="action" value="delete_cashflow">
                                        <input type="hidden" name="id" value="<?= $tx['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline btn-icon" title="Hapus" style="color:var(--danger);">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                        </button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <span class="text-xs text-muted">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- MODAL: TRANSAKSI ARUS KAS -->
<div class="modal-overlay" id="modalCashflow">
    <div class="modal" style="max-width:500px;">
        <div class="modal-header">
            <h3 class="modal-title" id="cfModalTitle">Tambah Transaksi Arus Kas</h3>
            <button class="modal-close" onclick="closeModal('modalCashflow')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="save_cashflow">
            <input type="hidden" name="id" id="cfId" value="">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Jenis Transaksi</label>
                    <select name="type" id="cfType" class="form-control" required onchange="toggleCfFields()">
                        <option value="Income">Pemasukan</option>
                        <option value="Expense">Pengeluaran</option>
                        <option value="Transfer">Pindah Saldo</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Tanggal</label>
                    <input type="date" name="date" id="cfDate" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                
                <div class="form-group" id="wrapCategory">
                    <label class="form-label">Kategori</label>
                    <input type="text" name="category" id="cfCategory" class="form-control" placeholder="Cth: Tambahan Modal, Biaya Listrik">
                </div>
                
                <div class="form-group" id="wrapAccountFrom">
                    <label class="form-label">Keluar dari Kas/Rekening</label>
                    <select name="account_from" id="cfAccountFrom" class="form-control">
                        <option value="">-- Pilih --</option>
                        <?php foreach ($configAccounts as $acc): ?>
                        <option value="<?= htmlspecialchars($acc) ?>"><?= htmlspecialchars($acc) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" id="wrapAccountTo">
                    <label class="form-label">Masuk ke Kas/Rekening</label>
                    <select name="account_to" id="cfAccountTo" class="form-control">
                        <option value="">-- Pilih --</option>
                        <?php foreach ($configAccounts as $acc): ?>
                        <option value="<?= htmlspecialchars($acc) ?>"><?= htmlspecialchars($acc) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Nominal</label>
                    <div class="input-group">
                        <span class="input-group-text">Rp</span>
                        <input type="text" name="amount" id="cfAmount" class="form-control rupiah-input" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Keterangan (Opsional)</label>
                    <textarea name="description" id="cfDescription" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('modalCashflow')">Batal</button>
                <button type="submit" class="btn btn-primary" id="cfBtnSubmit">Simpan Transaksi</button>
            </div>
        </form>
    </div>
</div>

<script>
function openCashflowModal() {
    document.getElementById('cfModalTitle').innerText = 'Tambah Transaksi Arus Kas';
    document.getElementById('cfId').value = '';
    document.getElementById('cfType').value = 'Income';
    document.getElementById('cfDate').value = '<?= date('Y-m-d') ?>';
    document.getElementById('cfCategory').value = '';
    document.getElementById('cfAccountFrom').value = '';
    document.getElementById('cfAccountTo').value = 'Tunai';
    document.getElementById('cfAmount').value = '';
    document.getElementById('cfDescription').value = '';
    document.getElementById('cfBtnSubmit').innerText = 'Simpan Transaksi';
    
    toggleCfFields();
    openModal('modalCashflow');
}

function editCashflow(tx) {
    document.getElementById('cfModalTitle').innerText = 'Edit Transaksi Arus Kas';
    document.getElementById('cfId').value = tx.id;
    document.getElementById('cfType').value = tx.type;
    document.getElementById('cfDate').value = tx.tx_date;
    document.getElementById('cfCategory').value = tx.category;
    document.getElementById('cfAccountFrom').value = tx.account_from || '';
    document.getElementById('cfAccountTo').value = tx.account_to || '';
    
    // Format amount
    let val = parseFloat(tx.amount).toString();
    let sisa = val.length % 3;
    let rupiah = val.substr(0, sisa);
    let ribuan = val.substr(sisa).match(/\d{3}/gi);
    if (ribuan) {
        let separator = sisa ? '.' : '';
        rupiah += separator + ribuan.join('.');
    }
    document.getElementById('cfAmount').value = rupiah;
    
    document.getElementById('cfDescription').value = tx.description;
    document.getElementById('cfBtnSubmit').innerText = 'Simpan Perubahan';
    
    toggleCfFields();
    openModal('modalCashflow');
}

function toggleCfFields() {
    const type = document.getElementById('cfType').value;
    const wrapCat = document.getElementById('wrapCategory');
    const wrapFrom = document.getElementById('wrapAccountFrom');
    const wrapTo = document.getElementById('wrapAccountTo');
    
    const inputCat = document.getElementById('cfCategory');
    const inputFrom = document.getElementById('cfAccountFrom');
    const inputTo = document.getElementById('cfAccountTo');

    if (type === 'Income') {
        wrapCat.style.display = 'block';
        wrapFrom.style.display = 'none';
        wrapTo.style.display = 'block';
        
        inputCat.required = true;
        inputFrom.required = false;
        inputTo.required = true;
    } else if (type === 'Expense') {
        wrapCat.style.display = 'block';
        wrapFrom.style.display = 'block';
        wrapTo.style.display = 'none';
        
        inputCat.required = true;
        inputFrom.required = true;
        inputTo.required = false;
    } else if (type === 'Transfer') {
        wrapCat.style.display = 'none';
        wrapFrom.style.display = 'block';
        wrapTo.style.display = 'block';
        
        inputCat.required = false;
        inputFrom.required = true;
        inputTo.required = true;
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const rupiahInputs = document.querySelectorAll('.rupiah-input');
    rupiahInputs.forEach(input => {
        input.addEventListener('keyup', function(e) {
            let val = this.value.replace(/[^,\d]/g, '').toString();
            let split = val.split(',');
            let sisa = split[0].length % 3;
            let rupiah = split[0].substr(0, sisa);
            let ribuan = split[0].substr(sisa).match(/\d{3}/gi);

            if (ribuan) {
                let separator = sisa ? '.' : '';
                rupiah += separator + ribuan.join('.');
            }

            rupiah = split[1] != undefined ? rupiah + ',' + split[1] : rupiah;
            this.value = rupiah;
        });
    });
});
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
