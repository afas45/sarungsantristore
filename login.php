<?php
/**
 * Kasir Ibtidaiyah - Halaman Login
 * Login untuk semua role: Pemilik, Admin, Kasir, Pelanggan
 */
require_once __DIR__ . '/config/app.php';

// Jika sudah login, redirect
if (isLoggedIn()) {
    redirectAfterLogin();
}

// Fetch 5 produk terlaris (parent SKU)
$db = Database::conn();
$topProducts = $db->query("
    SELECT 
        p.id, p.sku, p.name, p.image, p.base_price, p.discount_percent,
        c.name AS category_name,
        COALESCE(
            (SELECT pp2.selling_price FROM product_prices pp2 
             JOIN price_types pt2 ON pp2.price_type_id = pt2.id 
             WHERE pp2.product_id = p.id AND pp2.min_qty = 1 AND pt2.name = 'Umum' LIMIT 1),
            p.base_price
        ) AS selling_price,
        COALESCE((SELECT SUM(sd.qty) FROM sale_details sd 
                  JOIN product_variations pv ON sd.product_variation_id = pv.id 
                  WHERE pv.product_id = p.id), 0) AS total_sold
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.is_active = 1 AND p.is_best_seller = 1
    ORDER BY p.created_at DESC
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

$error = '';
$success = '';
$activeTab = 'login';

// Cek error dari URL
if (isset($_GET['error']) && $_GET['error'] === 'no_permission') {
    $error = 'Sesi Anda tidak valid atau Anda tidak memiliki izin. Silakan login kembali.';
}

// Proses Form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Sesi telah kedaluwarsa atau permintaan tidak valid. Silakan muat ulang halaman.';
    } else {
        $action = $_POST['action'] ?? 'login';
        $activeTab = $action;
        
        if ($action === 'login') {
            $username = sanitize($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            
            if (empty($username) || empty($password)) {
                $error = 'Username dan password harus diisi.';
            } else {
                $result = login($username, $password);
                if ($result['success']) {
                    redirectAfterLogin();
                } else {
                    $error = $result['message'];
                }
            }
        } elseif ($action === 'register') {
            $name = sanitize($_POST['name'] ?? '');
            $email = sanitize($_POST['email'] ?? '');
            $address = sanitize($_POST['address'] ?? '');
            $username = sanitize($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $password_confirm = $_POST['password_confirm'] ?? '';
            
            if (empty($name) || empty($email) || empty($password) || empty($username)) {
                $error = 'Nama, Username, Email, dan Password harus diisi.';
            } elseif ($password !== $password_confirm) {
                $error = 'Password tidak cocok.';
            } else {
                $db = Database::conn();
                $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
                $stmt->execute([$email]);
                $stmt3 = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
                $stmt3->execute([$username]);
                
                if ($stmt->fetchColumn() > 0) {
                    $error = 'Email sudah terdaftar. Silakan login.';
                } elseif ($stmt3->fetchColumn() > 0) {
                    $error = 'Username sudah digunakan. Silakan pilih username lain.';
                } else {
                    try {
                        $db->beginTransaction();
                        $roleId = $db->query("SELECT id FROM roles WHERE role_name = 'Pelanggan'")->fetchColumn();
                        
                        if (!$roleId) {
                            throw new Exception("Role Pelanggan belum tersedia.");
                        }
                        
                        
                        $hashed_pw = password_hash($password, PASSWORD_DEFAULT);
                        
                        $stmtUser = $db->prepare("INSERT INTO users (role_id, username, password_hash, full_name, email, is_active) VALUES (?, ?, ?, ?, ?, 1)");
                        $stmtUser->execute([$roleId, $username, $hashed_pw, $name, $email]);
                        $newUserId = $db->lastInsertId();
                        
                        $stmtCust = $db->prepare("INSERT INTO customers (user_id, name, email, address, price_type_id, password) VALUES (?, ?, ?, ?, 1, ?)");
                        $stmtCust->execute([$newUserId, $name, $email, $address, $hashed_pw]);
                        
                        $db->commit();
                        $success = 'Pendaftaran berhasil! Silakan Login dengan username: <strong>' . htmlspecialchars($username) . '</strong>';
                        $activeTab = 'login';
                    } catch (Exception $e) {
                        $db->rollBack();
                        $error = 'Gagal mendaftar: ' . $e->getMessage();
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Login - SarungSantriStore">
    <title>Login - <?= APP_NAME ?></title>
    <?php
    $storeLogo = getSetting('store_logo');
    $favicon = $storeLogo ? BASE_URL . '/' . $storeLogo : ASSETS_URL . '/img/logo_s.png';   
    $loginBg = getSetting('login_bg_image');
    $showFormClass = ($error || $success) ? 'visible' : '';
    ?>
    <link rel="icon" href="<?= $favicon ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Caveat:wght@700&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --bg-dark: #1A0A04; /* Sangat gelap, mendekati hitam tapi hint coklat (mirip gambar) */
            --bg-darker: #0D0502; /* Untuk feature bar & footer */
            --primary: #F6AA1C; /* Kuning tema aplikasi */
            --secondary: #BC3908; /* Orange tema aplikasi */
            --text-light: #F3F4F6;
            --text-dark: #111827;
            --gray: #9CA3AF;
        }
        
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        html { font-size: 16px; scroll-behavior: smooth; }
        body { font-family: 'Inter', sans-serif; background: #fff; color: var(--text-dark); overflow-x: hidden; }
        a { text-decoration: none; color: inherit; }
        
        /* Navbar */
        .navbar {
            position: absolute; top: 0; left: 0; right: 0;
            padding: 24px 60px;
            display: flex; justify-content: space-between; align-items: center;
            z-index: 100;
        }
        .nav-logo { display: flex; align-items: center; gap: 6px; font-weight: 900; font-size: 1.5rem; color: var(--primary); letter-spacing:-0.5px;}
        .nav-logo img { height: 36px; }
        .nav-links { display: flex; gap: 32px; font-weight: 600; font-size: 0.95rem; color: #d1d5db; }
        .nav-links a:hover { color: var(--primary); }
        .nav-actions { display: flex; align-items: center; gap: 20px; }
        .nav-btn-outline { 
            background: transparent; color: var(--primary); border: 2px solid var(--primary); 
            padding: 8px 24px; border-radius: 30px; font-weight: 700; transition: all 0.3s; 
        }
        .nav-btn-outline:hover { background: var(--primary); color: var(--bg-dark); }

        /* Hero Section */
        .hero {
            padding: 160px 60px 160px;
            display: flex;
            align-items: center;
            position: relative;
        }
        .pattern-overlay {
            position: absolute; inset: 0; pointer-events: none;
            background-image: radial-gradient(#bc3908 1px, transparent 1px);
            background-size: 20px 20px;
            opacity: 0.15;
            z-index: 1;
        }
        
        .hero-container {
            max-width: 1300px;
            margin: 0 auto;
            width: 100%;
            display: flex;
            gap: 60px;
            align-items: center;
            position: relative;
            z-index: 10;
        }
        
        .hero-left {
            flex: 1.1;
        }
        .hero-title {
            font-size: 4rem;
            font-weight: 900;
            color: var(--text-light);
            line-height: 1.1;
            margin-bottom: 24px;
            letter-spacing: -1px;
        }
        .hero-title span { color: var(--primary); }
        .hero-desc {
            font-size: 1.1rem;
            color: var(--gray);
            line-height: 1.6;
            margin-bottom: 40px;
            max-width: 500px;
        }
        
        .features-bar {
            background: var(--bg-darker);
            padding: 16px 40px;
            display: flex;
            justify-content: center;
            gap: 40px;
            border-top: 1px solid rgba(255,255,255,0.05);
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        .feature-item {
            display: flex; align-items: center; gap: 12px;
            color: var(--text-light); font-weight: 600; font-size: 0.95rem;
        }
        .feature-icon {
            width: 40px; height: 40px; border-radius: 50%;
            background: rgba(246, 170, 28, 0.1); color: var(--primary);
            display: flex; align-items: center; justify-content: center;
        }
        
        /* Promo Section */
        .promo-section {
            background: var(--primary);
            padding: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .promo-container {
            max-width: 1200px; width: 100%;
            display: flex; gap: 60px; align-items: center;
        }
        .promo-left { flex: 1; }
        .promo-tag { font-weight: 800; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 2px; margin-bottom: 12px; opacity: 0.8; color: var(--bg-dark); }
        .promo-title { font-size: 2.8rem; font-weight: 900; line-height: 1.1; margin-bottom: 20px; color: var(--bg-dark); letter-spacing:-1px;}
        .promo-desc { font-size: 1.05rem; line-height: 1.6; color: rgba(0,0,0,0.7); margin-bottom: 30px; font-weight: 500; }
        .promo-right { flex: 1; display: flex; gap: 20px; }
        .promo-img-box {
            background: rgba(0,0,0,0.05); border-radius: 20px; overflow: hidden;
            flex: 1; height: 350px;
        }
        .promo-img-box img { width: 100%; height: 100%; object-fit: cover; mix-blend-mode: multiply; }
        
        /* Products Section */
        .products-section { padding: 80px 60px; background: #fff; text-align: center; }
        .section-title { font-size: 2.5rem; font-weight: 900; color: var(--bg-dark); margin-bottom: 40px; }
        
        .masonry-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 20px;
            max-width: 1200px; margin: 0 auto;
        }
        .product-card {
            background: var(--primary); border-radius: 16px; overflow: hidden;
            text-align: left; transition: transform 0.3s; border: 1px solid #e5e7eb;
            display: flex; flex-direction: column;
        }
        .product-card:hover { transform: translateY(-5px); box-shadow: 0 10px 25px rgba(0,0,0,0.05); }
        .product-img { width: 100%; aspect-ratio: 1; object-fit: cover; background: #f3f4f6; }
        .product-info { padding: 16px; flex: 1; display:flex; flex-direction:column; }
        .product-cat { font-size: 0.75rem; color: var(--secondary); font-weight: 800; text-transform: uppercase; margin-bottom: 4px; }
        .product-name { font-weight: 700; color: var(--text-dark); margin-bottom: 12px; font-size: 0.95rem; line-height: 1.4; flex:1;}
        .product-price { font-weight: 800; font-size: 1.15rem; color: #dc2626; }
        
        /* Footer */
        .footer { background: var(--bg-darker); color: var(--gray); padding: 40px 20px 20px; text-align: center; }
        .footer-title { font-size: 1.4rem; font-weight: 800; color: var(--text-light); margin-bottom: 8px; }
        .footer-desc { margin-bottom: 20px; font-size: 0.85rem; white-space: nowrap; }
        
        /* Login Form Styles embedded in Hero Right */
        .hero-right {
            flex: 0.9;
            display: none;
            justify-content: flex-end;
            opacity: 0;
            transform: translateY(40px) scale(0.96);
        }
        .hero-right.visible {
            display: flex;
            animation: loginFloatIn 0.55s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }
        @keyframes loginFloatIn {
            0%   { opacity: 0; transform: translateY(40px) scale(0.96); }
            55%  { opacity: 1; transform: translateY(-6px) scale(1.015); }
            80%  { transform: translateY(3px) scale(0.998); }
            100% { opacity: 1; transform: translateY(0) scale(1); }
        }
        .login-card {
            background: rgba(255,255,255,0.97); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
            width: 100%; max-width: 440px; border-radius: 24px;
            padding: 36px 40px; box-shadow: 0 25px 60px rgba(0,0,0,0.5), 0 0 0 1px rgba(255,255,255,0.1);
            position: relative;
            transition: box-shadow 0.3s ease;
        }
        .login-card:hover { box-shadow: 0 30px 70px rgba(0,0,0,0.55), 0 0 0 1px rgba(255,255,255,0.15); }
        
        .form-tabs { display: flex; background: #f3f4f6; border-radius: 12px; padding: 4px; margin-bottom: 24px; }
        .tab-btn { flex: 1; padding: 10px; border: none; background: transparent; color: #6b7280; font-weight: 700; border-radius: 8px; cursor: pointer; transition: all 0.2s; }
        .tab-btn.active { background: var(--secondary); color: white; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        
        .form-group { margin-bottom: 16px; text-align: left; }
        .form-input {
            width: 100%; padding: 14px 14px 14px 44px; border: 1.5px solid #e5e7eb;
            border-radius: 12px; font-family: 'Inter', sans-serif; font-size: 0.95rem;
            color: #1f2937; background: #fff; outline: none; transition: all 0.2s;
        }
        .form-input:focus { border-color: var(--secondary); box-shadow: 0 0 0 3px rgba(188, 57, 8, 0.1); }
        .input-wrapper { position: relative; }
        .input-icon { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: #9ca3af; pointer-events: none; }
        .form-input:focus ~ .input-icon { color: var(--secondary); }
        
        .btn-login {
            width: 100%; padding: 14px; border: none; border-radius: 12px;
            background: var(--primary); color: var(--bg-dark); font-weight: 800; font-size: 1rem;
            cursor: pointer; transition: all 0.2s;
        }
        .btn-login:hover { background: #e59a18; transform: translateY(-2px); }
        
        .password-toggle {
            position: absolute; right: 14px; top: 50%; transform: translateY(-50%);
            background: none; border: none; cursor: pointer; color: #9ca3af;
        }
        
        .alert-box { padding: 12px; border-radius: 10px; font-size: 0.85rem; margin-bottom: 16px; display: flex; gap: 8px; align-items: center; }
        .alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .alert-success { background: #fdf2ef; color: #92400e; border: 1px solid #f6aa1c; }
        
        @media (max-width: 1024px) {
            .hero-container { flex-direction: column; text-align: center; gap: 40px; }
            .hero-title { font-size: 3rem; }
            .hero-desc { margin: 0 auto 30px; }
            .social-icons-hero { justify-content: center !important; }
            .hero-right { justify-content: center; width: 100%; }
            .features-bar { flex-wrap: wrap; justify-content: center; }
            .promo-container { flex-direction: column; text-align: center; }
            .navbar { padding: 20px; }
            .hero { padding: 120px 20px 60px; }
            .masonry-grid { grid-template-columns: repeat(3, 1fr); }
            .footer-desc { white-space: normal; padding: 0 20px; }
        }
        @media (max-width: 768px) {
            .nav-links { display: none; }
            .navbar { flex-wrap: wrap; justify-content: center; gap: 16px; padding: 16px; }
            .nav-actions { width: 100%; justify-content: center; flex-wrap: wrap; gap: 10px; }
            .nav-btn-outline { font-size: 0.85rem; padding: 8px 16px; }
            .hero-title { font-size: 2.2rem; }
            .login-card { padding: 24px; }
            .hero { padding: 160px 16px 40px; }
            .features-bar { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; padding: 30px 20px; }
            .feature-item { flex-direction: column; text-align: center; font-size: 0.85rem; justify-content: flex-start; }
            .feature-icon { margin-bottom: 8px; }
            .masonry-grid { grid-template-columns: repeat(2, 1fr); gap: 12px; }
            .products-section { padding: 40px 16px; }
            .section-title { font-size: 1.6rem; margin-bottom: 24px; }
            .product-info { padding: 10px; }
            .product-cat { font-size: 0.65rem; }
            .product-name { font-size: 0.8rem; margin-bottom: 6px; }
            .product-price { font-size: 0.9rem; }
            .promo-section { padding: 40px 20px; }
            .promo-title { font-size: 1.8rem; }
            .promo-desc { font-size: 0.9rem; }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="nav-logo"></div>
        <div class="nav-actions">
            <button onclick="showLoginForm('login')" class="nav-btn-outline" style="cursor:pointer;">Login</button>
            <button onclick="showLoginForm('register')" class="nav-btn-outline" style="cursor:pointer; background: var(--primary); color: var(--bg-dark);">Register</button>
            <a href="<?= BASE_URL ?>/shop/index.php" class="nav-btn-outline">Jelajahi Toko</a>
        </div>
    </nav>
    
    <section class="hero" <?= $loginBg ? 'style="background: linear-gradient(to bottom, rgba(34, 9, 1, 0.8) 0%, rgba(34, 9, 1, 0.95) 100%), url(\'' . BASE_URL . '/' . $loginBg . '\') center center / cover no-repeat;"' : 'style="background: linear-gradient(135deg, #621708 0%, #220901 100%);"' ?>>
        <div class="pattern-overlay"></div>
        <div class="hero-container">
            <div class="hero-left">
                <img src="<?= $storeLogo ? BASE_URL . '/' . $storeLogo : BASE_URL . '/assets/img/logo_s.png' ?>" alt="Logo" style="height: 110px; margin-bottom: 10px;">
                <div style="font-size: 1.35rem; font-weight: 800; color: var(--primary); margin-bottom: 8px;">SarungSantriStore</div>
                <h1 class="hero-title">Pusat Sarung &<br><span>Fashion Santri</span></h1>
                <p class="hero-desc">
                    Temukan berbagai koleksi sarung, baju koko, peci, dan fashion muslim lainnya. 
                    Belanja kebutuhan fashion Anda yang elegan, nyaman, dan berkualitas!
                </p>
                <div class="social-icons-hero" style="display: flex; gap: 16px; align-items: center; flex-wrap: wrap; justify-content: flex-start;">
                    <a href="https://wa.me/6281511481192" style="width: 44px; height: 44px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; transition: all 0.3s;" onmouseover="this.style.background='rgba(255,255,255,0.1)';" onmouseout="this.style.background='rgba(255,255,255,0.05)';"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path></svg></a>
                    <a href="https://www.instagram.com/sarungsantristore.id" style="width: 44px; height: 44px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; transition: all 0.3s;" onmouseover="this.style.background='rgba(255,255,255,0.1)';" onmouseout="this.style.background='rgba(255,255,255,0.05)';"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"></rect><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"></path><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"></line></svg></a>
                    <a href="https://www.tiktok.com/@sarungsantristore?_r=1&_t=ZS-98sm4Pbr4Z2" style="width: 44px; height: 44px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; transition: all 0.3s;" onmouseover="this.style.background='rgba(255,255,255,0.1)';" onmouseout="this.style.background='rgba(255,255,255,0.05)';"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12a4 4 0 1 0 4 4V4a5 5 0 0 0 5 5"></path></svg></a>
                </div>
            </div>
            
            <div class="hero-right <?= $showFormClass ?>" id="loginCardWrapper">
                <div class="login-card">
                    <div style="text-align: center; margin-bottom: 24px;">
                        <h2 style="font-size: 1.5rem; font-weight: 800; color: var(--bg-dark);">Selamat Datang</h2>
                        <p style="font-size: 0.9rem; color: var(--gray);">Silakan masuk ke akun Anda</p>
                    </div>
                    
                    <div class="form-tabs">
                        <button type="button" class="tab-btn <?= $activeTab === 'login' ? 'active' : '' ?>" onclick="switchTab('login')">Login</button>
                        <button type="button" class="tab-btn <?= $activeTab === 'register' ? 'active' : '' ?>" onclick="switchTab('register')">Register</button>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert-box alert-error" id="loginAlert">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                            <?= htmlspecialchars($error) ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($success): ?>
                        <div class="alert-box alert-success" id="successAlert">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                            <?= $success ?>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Login Form -->
                    <form method="POST" action="" id="loginForm" style="display: <?= $activeTab === 'login' ? 'block' : 'none' ?>;">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="action" value="login">
                        
                        <div class="form-group">
                            <label class="form-label" style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:6px; color:#374151;">Username / Email</label>
                            <div class="input-wrapper">
                                <input type="text" name="username" class="form-input" placeholder="Masukkan Username/Email" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
                                <svg class="input-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:6px; color:#374151;">Password</label>
                            <div class="input-wrapper">
                                <input type="password" id="password" name="password" class="form-input" placeholder="Masukkan password" required>
                                <svg class="input-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                <button type="button" class="password-toggle" onclick="togglePassword('password', 'eyeIcon1')">
                                    <svg id="eyeIcon1" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                </button>
                            </div>
                        </div>
                        
                        <div style="text-align: right; margin-bottom: 24px; font-size: 0.85rem;">
                            <a href="#" style="color: var(--secondary); font-weight: 600;">Lupa password?</a>
                        </div>
                        
                        <button type="submit" class="btn-login">Masuk Sekarang</button>
                    </form>
                    
                    <!-- Register Form -->
                    <form method="POST" action="" id="registerForm" style="display: <?= $activeTab === 'register' ? 'block' : 'none' ?>;">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="action" value="register">
                        
                        <div class="form-group">
                            <div class="input-wrapper">
                                <input type="text" name="name" class="form-input" placeholder="Nama Lengkap" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
                                <svg class="input-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <div class="input-wrapper">
                                <input type="text" name="username" class="form-input" placeholder="Username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
                                <svg class="input-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <div class="input-wrapper">
                                <input type="email" name="email" class="form-input" placeholder="Email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                                <svg class="input-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <div class="input-wrapper">
                                <input type="password" id="reg_password" name="password" class="form-input" placeholder="Password" required>
                                <svg class="input-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                <button type="button" class="password-toggle" onclick="togglePassword('reg_password', 'eyeIcon2')">
                                    <svg id="eyeIcon2" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                </button>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <div class="input-wrapper">
                                <input type="password" id="reg_password_confirm" name="password_confirm" class="form-input" placeholder="Ulangi Password" required>
                                <svg class="input-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn-login" style="margin-top: 10px;">Daftar Akun</button>
                    </form>
                </div>
            </div>
        </div>
    </section>
    
    <section class="features-bar">
        <div class="feature-item">
            <div class="feature-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg></div>
            Produk Original
        </div>
        <div class="feature-item">
            <div class="feature-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></div>
            Transaksi Aman
        </div>
        <div class="feature-item">
            <div class="feature-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg></div>
            Diskon Spesial
        </div>
        <div class="feature-item">
            <div class="feature-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="M12 5l7 7-7 7"/></svg></div>
            Gratis Ongkir
        </div>
    </section>
    
    <section class="promo-section">
        <div class="promo-container">
            <div class="promo-left">
                <div class="promo-tag">Koleksi Spesial</div>
                <h2 class="promo-title">Tingkatkan Gaya<br>Santri Terbaikmu</h2>
                <p class="promo-desc">
                    Perkuat kepercayaan dirimu dengan busana muslim pilihan. Dibuat dengan bahan berkualitas dan desain modern yang tetap menjaga nilai-nilai kesantrian.
                </p>
                <a href="<?= BASE_URL ?>/shop/index.php" style="display:inline-block; padding:14px 32px; background:var(--bg-dark); color:var(--text-light); font-weight:800; border-radius:30px; box-shadow:0 10px 20px rgba(0,0,0,0.15); transition:all 0.3s;" onmouseover="this.style.transform='translateY(-2px)';" onmouseout="this.style.transform='translateY(0)';">Jelajahi Sekarang</a>
            </div>
            <div class="promo-right">
                <div class="promo-img-box">
                    <img src="<?= $loginBg ? BASE_URL . '/' . $loginBg : BASE_URL . '/assets/img/hero-bg.jpg' ?>" alt="Promo" onerror="this.src='https://images.unsplash.com/photo-1590332832044-672199b1a556?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80'">
                </div>
            </div>
        </div>
    </section>
    
    <?php if (getSetting('show_best_seller_login', '1') == '1' && !empty($topProducts)): ?>
    <section class="products-section">
        <h2 class="section-title">BEST SELLER</h2>
        <div class="masonry-grid">
            <?php foreach ($topProducts as $tp): 
                $tpPrice = (float)($tp['selling_price'] ?? $tp['base_price'] ?? 0);
                $tpDiscount = (int)($tp['discount_percent'] ?? 0);
                $tpFinalPrice = $tpDiscount > 0 ? $tpPrice - ($tpPrice * $tpDiscount / 100) : $tpPrice;
            ?>
            <a href="<?= BASE_URL ?>/shop/product.php?sku=<?= urlencode($tp['sku']) ?>" class="product-card">
                <?php if (!empty($tp['image'])): ?>
                    <img src="<?= BASE_URL . '/' . $tp['image'] ?>" alt="<?= htmlspecialchars($tp['name']) ?>" class="product-img">
                <?php else: ?>
                    <div class="product-img" style="display:flex; align-items:center; justify-content:center; color:#ccc;">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                    </div>
                <?php endif; ?>
                <div class="product-info">
                    <div class="product-cat"><?= htmlspecialchars($tp['category_name'] ?? 'Umum') ?></div>
                    <div class="product-name"><?= htmlspecialchars($tp['name']) ?></div>
                    <div class="product-price">
                        <?php if ($tpDiscount > 0): ?>
                            <span style="text-decoration:line-through; color:var(--gray); font-size:0.85rem; font-weight:500;">Rp <?= number_format($tpPrice, 0, ',', '.') ?></span>
                        <?php endif; ?>
                        Rp <?= number_format($tpFinalPrice, 0, ',', '.') ?>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
    
    <footer class="footer">
        <h2 class="footer-title">SarungSantriStore</h2>
        <p class="footer-desc">Pusat busana muslim dan perlengkapan santri terlengkap, amanah, dan terpercaya.</p>
        <p style="font-size: 0.9rem; color: #4b5563;">&copy; <?= date('Y') ?> <?= APP_NAME ?>. Semua hak dilindungi.</p>
    </footer>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert-box');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    alert.style.opacity = '0';
                    alert.style.transform = 'translateY(-10px)';
                    setTimeout(() => alert.remove(), 500);
                }, 4000);
            });
        });

        function showLoginForm(tab) {
            const wrapper = document.getElementById('loginCardWrapper');
            if (!wrapper) return;
            // Remove visible class and reset animation
            wrapper.classList.remove('visible');
            wrapper.style.display = 'none';
            // Force reflow so browser resets animation
            void wrapper.offsetWidth;
            // Clear inline style so CSS class can control display
            wrapper.style.display = '';
            wrapper.classList.add('visible');
            switchTab(tab);
            // Smooth scroll to form after short delay
            setTimeout(() => {
                wrapper.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }, 120);
        }

        function switchTab(tab) {
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelector(`.tab-btn[onclick="switchTab('${tab}')"]`).classList.add('active');
            
            if (tab === 'login') {
                document.getElementById('loginForm').style.display = 'block';
                document.getElementById('registerForm').style.display = 'none';
            } else {
                document.getElementById('loginForm').style.display = 'none';
                document.getElementById('registerForm').style.display = 'block';
            }
        }

        function togglePassword(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            if (input.type === 'password') {
                input.type = 'text';
                icon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
            } else {
                input.type = 'password';
                icon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
            }
        }
    </script>
</body>
</html>
