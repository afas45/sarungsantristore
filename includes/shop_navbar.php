<?php
/**
 * Kasir Ibtidaiyah - Shop Navbar Component (Mobile-First)
 * Include di semua halaman toko: shop/index.php, category.php, product.php, dll.
 *
 * Variabel dari halaman pemanggil:
 *   $storeName   (string) — nama toko
 *   $cartCount   (int)    — jumlah item keranjang
 *   $searchVal   (string) — optional, nilai search terisi
 *   $searchCatId (int)    — optional, category id untuk hidden field
 *   $showSearch  (bool)   — optional, default true
 */

$searchVal   = $searchVal   ?? '';
$searchCatId = $searchCatId ?? 0;
$showSearch  = $showSearch  ?? true;

$storeLogo = getSetting('store_logo');
$logoSrc   = $storeLogo
    ? BASE_URL . '/' . $storeLogo
    : BASE_URL . '/assets/img/logo_SSS.png';
?>
<nav class="shop-navbar" role="navigation" aria-label="Navigasi Toko">
    <div class="shop-navbar-inner">

        <!-- Hamburger toggle (mobile < 640px) -->
        <button class="shop-mobile-toggle"
                id="shopMobileToggle"
                aria-label="Buka Menu"
                aria-expanded="false"
                aria-controls="shopMobileMenu">
            <svg class="icon-menu" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <line x1="4" y1="6" x2="20" y2="6"/>
                <line x1="4" y1="12" x2="20" y2="12"/>
                <line x1="4" y1="18" x2="16" y2="18"/>
            </svg>
            <svg class="icon-close" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="display:none;">
                <line x1="18" y1="6" x2="6" y2="18"/>
                <line x1="6" y1="6" x2="18" y2="18"/>
            </svg>
        </button>

        <!-- Logo -->
        <a href="<?= BASE_URL ?>/shop/index.php" class="shop-logo">
            <img src="<?= htmlspecialchars($logoSrc) ?>"
                 alt="Logo"
                 class="shop-logo-icon"
                 width="32" height="32"
                 loading="eager"
                 style="background:none;object-fit:contain;padding:0;border-radius:4px;">
            <?= htmlspecialchars($storeName) ?>
        </a>

        <!-- Search Bar (desktop) -->
        <?php if ($showSearch): ?>
        <div class="shop-search" role="search">
            <svg class="shop-search-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="11" cy="11" r="8"/>
                <line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <form action="<?= BASE_URL ?>/shop/category.php" method="GET" style="width:100%;">
                <?php if ($searchCatId): ?>
                    <input type="hidden" name="category" value="<?= (int)$searchCatId ?>">
                <?php endif; ?>
                <input type="search" name="search" placeholder="Cari sarung atau perlengkapan..."
                       value="<?= htmlspecialchars($searchVal) ?>" autocomplete="off"
                       style="width:100%;outline:none;border:none;background:transparent;color:#fff;font-size:15px;">
            </form>
        </div>
        <?php endif; ?>

        <!-- Nav Links (desktop) -->
        <div class="shop-nav-links">

            <a href="<?= BASE_URL ?>/shop/track.php" class="shop-nav-link" title="Lacak Pesanan">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <line x1="16.5" y1="9.4" x2="7.5" y2="4.21"/>
                    <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                    <polyline points="3.27 6.96 12 12.01 20.73 6.96"/>
                    <line x1="12" y1="22.08" x2="12" y2="12"/>
                </svg>
                <span>Lacak</span>
            </a>
            <a href="<?= BASE_URL ?>/shop/cart.php" class="shop-nav-link" title="Keranjang">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/>
                    <line x1="3" y1="6" x2="21" y2="6"/>
                    <path d="M16 10a4 4 0 0 1-8 0"/>
                </svg>
                <?php if ($cartCount > 0): ?><span class="cart-badge"><?= $cartCount ?></span><?php endif; ?>
            </a>
            <?php if (isLoggedIn()): $cu = getCurrentUser(); ?>
                <div class="shop-nav-dropdown">
                    <a href="#" class="shop-nav-link" style="cursor:default;">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                            <circle cx="12" cy="7" r="4"/>
                        </svg>
                        <span><?= htmlspecialchars($cu['full_name'] ?? 'Akun') ?></span>
                    </a>
                    <div class="shop-nav-dropdown-menu">
                        <a href="<?= BASE_URL ?>/shop/account/profile.php">Kelola Profil &amp; Alamat</a>
                        <a href="<?= BASE_URL ?>/shop/account/orders.php">Riwayat Transaksi</a>
                        <div style="border-top:1px solid var(--border-color);margin:4px 0;"></div>
                        <a href="<?= BASE_URL ?>/logout.php" class="text-danger">Keluar</a>
                    </div>
                </div>
            <?php else: ?>
                <a href="<?= BASE_URL ?>/login.php" class="shop-nav-link">Masuk</a>
            <?php endif; ?>
        </div>

        <!-- Search & Cart icon mobile -->
        <div style="display: flex; align-items: center; gap: 4px;">
            <?php if ($showSearch): ?>
            <a href="#" class="shop-mobile-cart" aria-label="Pencarian" onclick="var m = document.getElementById('mobileSearchDropdown'); if(m.style.display === 'none' || m.style.display === '') { m.style.display = 'block'; document.getElementById('mobileHeaderSearchInput').focus(); } else { m.style.display = 'none'; } return false;" id="mobileSearchToggle">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="11" cy="11" r="8"/>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
            </a>
            <?php endif; ?>

            <a href="<?= BASE_URL ?>/shop/cart.php" class="shop-mobile-cart" aria-label="Keranjang (<?= $cartCount ?> item)">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/>
                    <line x1="3" y1="6" x2="21" y2="6"/>
                    <path d="M16 10a4 4 0 0 1-8 0"/>
                </svg>
                <?php if ($cartCount > 0): ?><span class="cart-badge"><?= $cartCount ?></span><?php endif; ?>
            </a>
        </div>
        
        <?php if ($showSearch): ?>
        <!-- Mobile Search Dropdown -->
        <div id="mobileSearchDropdown" style="display: none; position: absolute; right: 16px; left: 16px; top: 60px; background: #fff; box-shadow: 0 10px 20px rgba(0,0,0,0.1); border-radius: 12px; padding: 12px; z-index: 1000; border: 1px solid #e5e7eb;">
            <form action="<?= BASE_URL ?>/shop/category.php" method="GET" style="display: flex; gap: 8px; width: 100%; margin: 0;">
                <?php if ($searchCatId): ?>
                    <input type="hidden" name="category" value="<?= (int)$searchCatId ?>">
                <?php endif; ?>
                <input type="search" id="mobileHeaderSearchInput" name="search" placeholder="Cari produk..."
                       value="<?= htmlspecialchars($searchVal) ?>" autocomplete="off"
                       style="flex: 1; padding: 10px 14px; border: 1px solid #e5e7eb; border-radius: 8px; outline: none; font-size: 15px; color: #1f2937; background: #f9fafb; min-width: 0; transition: border-color 0.2s;">
                <button type="submit" style="padding: 10px 18px; border: none; border-radius: 8px; background: #059669; color: white; cursor: pointer; font-weight: 600; font-size: 14px; transition: background 0.2s;">Cari</button>
            </form>
        </div>
        <?php endif; ?>

    </div>
</nav>

<!-- Mobile Slide-Down Menu -->
<div class="shop-mobile-menu" id="shopMobileMenu" role="dialog" aria-modal="true" aria-label="Menu Navigasi">

    <a href="<?= BASE_URL ?>/shop/index.php" class="shop-mobile-nav-item">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        Beranda
    </a>
    <a href="<?= BASE_URL ?>/shop/category.php" class="shop-mobile-nav-item">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>
        Semua Produk
    </a>
    <a href="<?= BASE_URL ?>/shop/track.php" class="shop-mobile-nav-item">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><line x1="16.5" y1="9.4" x2="7.5" y2="4.21"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
        Lacak Pesanan
    </a>

    <div class="shop-mobile-divider"></div>

    <?php if (isLoggedIn()): $cu = $cu ?? getCurrentUser(); ?>
        <a href="<?= BASE_URL ?>/shop/account/profile.php" class="shop-mobile-nav-item">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            Profil &amp; Alamat
        </a>
        <a href="<?= BASE_URL ?>/shop/account/orders.php" class="shop-mobile-nav-item">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
            Riwayat Transaksi
        </a>
        <div class="shop-mobile-divider"></div>
        <a href="<?= BASE_URL ?>/logout.php" class="shop-mobile-nav-item" style="color:#ef4444;">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Keluar
        </a>
    <?php else: ?>
        <a href="<?= BASE_URL ?>/login.php" class="shop-mobile-nav-item">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
            Masuk / Login
        </a>
    <?php endif; ?>
</div>

<!-- Mobile Bottom Navigation Bar -->
<nav class="shop-bottom-nav" id="shopBottomNav" aria-label="Navigasi Bawah">
    <div class="shop-bottom-nav-inner">
        <a href="<?= BASE_URL ?>/shop/index.php" class="shop-bottom-nav-item <?= (basename($_SERVER['PHP_SELF']) === 'index.php' && strpos($_SERVER['REQUEST_URI'], '/shop/') !== false) ? 'active' : '' ?>" aria-label="Beranda">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            <span>Beranda</span>
        </a>
        <a href="<?= BASE_URL ?>/shop/category.php" class="shop-bottom-nav-item <?= basename($_SERVER['PHP_SELF']) === 'category.php' ? 'active' : '' ?>" aria-label="Produk">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>
            <span>Produk</span>
        </a>
        <a href="<?= BASE_URL ?>/shop/cart.php" class="shop-bottom-nav-item <?= basename($_SERVER['PHP_SELF']) === 'cart.php' ? 'active' : '' ?>" aria-label="Keranjang">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
            <?php if ($cartCount > 0): ?><span class="bnav-badge"><?= $cartCount ?></span><?php endif; ?>
            <span>Keranjang</span>
        </a>
        <a href="<?= BASE_URL ?>/shop/track.php" class="shop-bottom-nav-item <?= basename($_SERVER['PHP_SELF']) === 'track.php' ? 'active' : '' ?>" aria-label="Lacak">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><line x1="16.5" y1="9.4" x2="7.5" y2="4.21"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
            <span>Lacak</span>
        </a>
        <a href="<?= isLoggedIn() ? BASE_URL . '/shop/account/profile.php' : BASE_URL . '/login.php' ?>" class="shop-bottom-nav-item <?= (strpos($_SERVER['REQUEST_URI'], '/account/') !== false || basename($_SERVER['PHP_SELF']) === 'login.php') ? 'active' : '' ?>" aria-label="Akun">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <span><?= isLoggedIn() ? 'Akun' : 'Masuk' ?></span>
        </a>
    </div>
</nav>

<script>
// Menutup dropdown pencarian jika diklik di luar area dropdown
document.addEventListener('click', function(e) {
    var mobileSearchToggle = document.getElementById('mobileSearchToggle');
    var mobileSearchDropdown = document.getElementById('mobileSearchDropdown');
    if (mobileSearchDropdown && mobileSearchDropdown.style.display === 'block') {
        if (!mobileSearchDropdown.contains(e.target) && (!mobileSearchToggle || !mobileSearchToggle.contains(e.target))) {
            mobileSearchDropdown.style.display = 'none';
        }
    }
});
</script>