<?php
/**
 * Kasir Ibtidaiyah - Sidebar Navigation
 * Menu ditampilkan berdasarkan role pengguna
 */

$currentUser = getCurrentUser();
$currentPage = basename($_SERVER['PHP_SELF']);
$currentDir = basename(dirname($_SERVER['PHP_SELF']));

// Tentukan menu berdasarkan role
$sidebarItems = [];

// =============================================
// DYNAMIC NAVIGATION BY PERMISSIONS
// =============================================

// 1. Standalone Items (Utama & Pesanan Online)
if (hasPermission('menu_dashboard')) {
    $sidebarItems[] = [
        'type' => 'link',
        'label' => 'Dashboard',
        'url' => BASE_URL . '/admin/index.php',
        'icon' => '<i data-lucide="layout-dashboard" style="width:20px;height:20px;"></i>',
        'active' => ($currentPage === 'index.php' && $currentDir === 'admin'),
    ];
}
if (hasPermission('menu_pos')) {
    $sidebarItems[] = [
        'type' => 'link',
        'label' => 'POS Kasir',
        'url' => BASE_URL . '/pos/index.php',
        'icon' => '<i data-lucide="monitor" style="width:20px;height:20px;"></i>',
        'active' => ($currentDir === 'pos'),
    ];
}
if (hasPermission('menu_pesanan_online')) {
    $db = Database::conn();
    $pendingStmt = $db->query("SELECT COUNT(*) FROM sales WHERE sale_source = 'E-Commerce' AND status = 'Pending'");
    $pendingCount = (int)$pendingStmt->fetchColumn();
    $badgeHtml = $pendingCount > 0 ? " <span style=\"margin-left:auto; font-size:0.7rem; font-weight:700; padding:0; width:20px; height:20px; display:inline-flex; align-items:center; justify-content:center; border-radius:50%; background:linear-gradient(135deg, #f5c842, #d4a017); color:#1a5c2a; text-shadow:none; box-shadow:0 1px 3px rgba(212,160,23,0.4);\">$pendingCount</span>" : '';
    
    $sidebarItems[] = [
        'type' => 'link',
        'label' => 'Pesanan Online' . $badgeHtml,
        'url' => BASE_URL . '/admin/online_orders.php',
        'icon' => '<i data-lucide="shopping-bag" style="width:20px;height:20px;"></i>',
        'active' => ($currentPage === 'online_orders.php'),
    ];
}

// 2. Kelola Section
$kelolaItems = [];
if (hasPermission('menu_produk')) {
    $kelolaItems[] = [
        'label' => 'Produk',
        'url' => BASE_URL . '/admin/products.php',
        'icon' => '<i data-lucide="package" style="width:20px;height:20px;"></i>',
        'active' => in_array($currentPage, ['products.php', 'variations.php']),
    ];
}
if (hasPermission('menu_monitor_stok')) {
    $kelolaItems[] = [
        'label' => 'Monitor Stok',
        'url' => BASE_URL . '/admin/stock_monitor.php',
        'icon' => '<i data-lucide="activity" style="width:20px;height:20px;"></i>',
        'active' => ($currentPage === 'stock_monitor.php'),
    ];
}
if (hasPermission('menu_harga_produk')) {
    $kelolaItems[] = [
        'label' => 'Harga Produk',
        'url' => BASE_URL . '/admin/prices.php',
        'icon' => '<i data-lucide="tags" style="width:20px;height:20px;"></i>',
        'active' => ($currentPage === 'prices.php'),
    ];
}

if (hasPermission('menu_impor_harga')) {
    $kelolaItems[] = [
        'label' => 'Impor Tipe Harga',
        'url' => BASE_URL . '/admin/import_prices.php',
        'icon' => '<i data-lucide="upload-cloud" style="width:20px;height:20px;"></i>',
        'active' => ($currentPage === 'import_prices.php'),
    ];
}
if (hasPermission('menu_kategori')) {
    $kelolaItems[] = [
        'label' => 'Kategori',
        'url' => BASE_URL . '/admin/categories.php',
        'icon' => '<i data-lucide="layers" style="width:20px;height:20px;"></i>',
        'active' => ($currentPage === 'categories.php'),
    ];
}
if (hasPermission('menu_pelanggan')) {
    $kelolaItems[] = [
        'label' => 'Pelanggan',
        'url' => BASE_URL . '/admin/customers.php',
        'icon' => '<i data-lucide="users" style="width:20px;height:20px;"></i>',
        'active' => ($currentPage === 'customers.php'),
    ];
}
if (hasPermission('menu_supplier')) {
    $kelolaItems[] = [
        'label' => 'Supplier',
        'url' => BASE_URL . '/admin/suppliers.php',
        'icon' => '<i data-lucide="truck" style="width:20px;height:20px;"></i>',
        'active' => ($currentPage === 'suppliers.php'),
    ];
}

if (!empty($kelolaItems)) {
    $sidebarItems[] = [
        'type' => 'section',
        'title' => 'Kelola',
        'icon' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 12 12 17 22 12"/><polyline points="2 17 12 22 22 17"/></svg>',
        'items' => $kelolaItems
    ];
}

// 3. Inventaris Section
$inventarisItems = [];
if (hasPermission('menu_pembelian')) {
    $inventarisItems[] = [
        'label' => 'Pembelian (PO)',
        'url' => BASE_URL . '/admin/purchases.php',
        'icon' => '<i data-lucide="box" style="width:20px;height:20px;"></i>',
        'active' => ($currentPage === 'purchases.php'),
    ];
}
if (hasPermission('menu_surat_jalan')) {
    $inventarisItems[] = [
        'label' => 'Surat Jalan',
        'url' => BASE_URL . '/admin/delivery_notes.php',
        'icon' => '<i data-lucide="file-text" style="width:20px;height:20px;"></i>',
        'active' => ($currentPage === 'delivery_notes.php'),
    ];
}
if (hasPermission('menu_opname')) {
    $inventarisItems[] = [
        'label' => 'Opname Produk',
        'url' => BASE_URL . '/admin/stock_opname.php',
        'icon' => '<i data-lucide="clipboard-check" style="width:20px;height:20px;"></i>',
        'active' => ($currentPage === 'stock_opname.php'),
    ];
}

if (!empty($inventarisItems)) {
    $sidebarItems[] = [
        'type' => 'section',
        'title' => 'Inventaris',
        'icon' => '<i data-lucide="package-check" style="width:20px;height:20px;"></i>',
        'items' => $inventarisItems
    ];
}

// 4. Keuangan Section
$keuanganItems = [];
if (hasPermission('menu_laporan')) {
    $keuanganItems[] = [
        'label' => 'Laporan',
        'url' => BASE_URL . '/admin/sales_report.php',
        'icon' => '<i data-lucide="bar-chart-2" style="width:20px;height:20px;"></i>',
        'active' => ($currentPage === 'sales_report.php'),
    ];
}
if (hasPermission('menu_arus_kas')) {
    $keuanganItems[] = [
        'label' => 'Arus Kas',
        'url' => BASE_URL . '/admin/cash_flow.php',
        'icon' => '<i data-lucide="refresh-cw" style="width:20px;height:20px;"></i>',
        'active' => ($currentPage === 'cash_flow.php'),
    ];
}
if (hasPermission('menu_setoran')) {
    $keuanganItems[] = [
        'label' => 'Setoran',
        'url' => BASE_URL . '/admin/cash_settlement.php',
        'icon' => '<i data-lucide="dollar-sign" style="width:20px;height:20px;"></i>',
        'active' => ($currentPage === 'cash_settlement.php'),
    ];
    $keuanganItems[] = [
        'label' => 'Marketplace',
        'url' => BASE_URL . '/admin/marketplace_settlement.php',
        'icon' => '<i data-lucide="store" style="width:20px;height:20px;"></i>',
        'active' => ($currentPage === 'marketplace_settlement.php'),
    ];
}
if (hasPermission('menu_piutang')) {
    $keuanganItems[] = [
        'label' => 'Piutang',
        'url' => BASE_URL . '/admin/receivables.php',
        'icon' => '<i data-lucide="credit-card" style="width:20px;height:20px;"></i>',
        'active' => ($currentPage === 'receivables.php'),
    ];
}
if (hasPermission('menu_hutang')) {
    $keuanganItems[] = [
        'label' => 'Hutang',
        'url' => BASE_URL . '/admin/payables.php',
        'icon' => '<i data-lucide="wallet" style="width:20px;height:20px;"></i>',
        'active' => ($currentPage === 'payables.php'),
    ];
}

if (!empty($keuanganItems)) {
    $sidebarItems[] = [
        'type' => 'section',
        'title' => hasRoleId(ROLE_KASIR) ? 'Kasir' : 'Keuangan',
        'icon' => '<i data-lucide="briefcase" style="width:20px;height:20px;"></i>',
        'items' => $keuanganItems
    ];
}

// 5. Sistem Section
$systemItems = [];
if (hasPermission('menu_pengguna')) {
    $systemItems[] = [
        'label' => 'Kelola Pengguna',
        'url' => BASE_URL . '/admin/users.php',
        'icon' => '<i data-lucide="user-check" style="width:20px;height:20px;"></i>',
        'active' => ($currentPage === 'users.php'),
    ];
}
if (hasPermission('menu_pengaturan')) {
    $systemItems[] = [
        'label' => 'Pengaturan',
        'url' => BASE_URL . '/admin/settings.php',
        'icon' => '<i data-lucide="settings" style="width:20px;height:20px;"></i>',
        'active' => ($currentPage === 'settings.php'),
    ];
}

if (!empty($systemItems)) {
    $sidebarItems[] = [
        'type' => 'section',
        'title' => 'Sistem',
        'icon' => '<i data-lucide="settings-2" style="width:20px;height:20px;"></i>',
        'items' => $systemItems
    ];
}
?>

<!-- Sidebar Navigation -->
<aside class="sidebar" id="sidebar">
    <!-- Brand -->
    <div class="sidebar-brand">
        <div class="sidebar-brand-logo" style="display:flex; align-items:center; justify-content:center; width:42px; height:42px; border-radius:10px; box-shadow: 0 4px 12px rgba(188, 57, 8, 0.35); flex-shrink:0; overflow:hidden;">
            <img src="<?= BASE_URL ?>/assets/img/sidebar-logo.png" alt="Logo" style="width:100%; height:100%; object-fit:cover;">
        </div>
        <div class="sidebar-brand-text">
            <span class="sidebar-brand-name"><?= APP_NAME ?></span>
            <span class="sidebar-brand-tagline"><?= APP_TAGLINE ?></span>
        </div>
    </div>
    
    <!-- Navigation -->
    <nav class="sidebar-nav">
        <?php $primaryLabelShown = false; ?>
        <?php foreach ($sidebarItems as $section): ?>
            <?php if ($section['type'] === 'link'): ?>
                <div class="nav-section" style="margin-bottom: 2px;">
                    <?php if (!$primaryLabelShown): ?>
                        <div class="nav-section-title">Utama</div>
                        <?php $primaryLabelShown = true; ?>
                    <?php endif; ?>
                    <a href="<?= $section['url'] ?>" class="nav-item<?= $section['active'] ? ' active' : '' ?>" style="margin-bottom: 0;">
                        <span class="nav-icon"><?= $section['icon'] ?></span>
                        <span style="flex:1; text-align:left; display:flex; justify-content:space-between; align-items:center;"><?= $section['label'] ?></span>
                    </a>
                </div>
            <?php else: ?>
                <div class="nav-section" style="margin-bottom: 5px;">
                    <div class="nav-section-title"><?= $section['title'] ?></div>
                    <div class="nav-section-items">
                        <?php foreach ($section['items'] as $item): ?>
                            <a href="<?= $item['url'] ?>" class="nav-item<?= $item['active'] ? ' active' : '' ?>">
                                <span class="nav-icon"><?= $item['icon'] ?></span>
                                <span style="flex:1; text-align:left; display:flex; justify-content:space-between; align-items:center;"><?= $item['label'] ?></span>
                                <?php if (isset($item['badge'])): ?>
                                    <span class="nav-badge"><?= $item['badge'] ?></span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    
    <style>
    .nav-section-items {
        display: flex;
        flex-direction: column;
        gap: 1px;
    }
    </style>
    
    <!-- Sidebar Footer: User Info -->
    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="sidebar-user-avatar">
                <?= strtoupper(substr($currentUser['full_name'] ?? 'U', 0, 1)) ?>
            </div>
            <div class="sidebar-user-info">
                <div class="sidebar-user-name"><?= htmlspecialchars($currentUser['full_name'] ?? 'User') ?></div>
                <div class="sidebar-user-role"><?= htmlspecialchars($currentUser['role_name'] ?? '') ?></div>
            </div>
        </div>
    </div>
</aside>
