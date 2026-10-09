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
    ?>
    <link rel="icon" href="<?= $favicon ?>">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Amiri:wght@400;700&display=swap" rel="stylesheet">
    
    <style>
        *, *::before, *::after {
            margin: 0; padding: 0; box-sizing: border-box;
        }
        
        html { font-size: 16px; -webkit-font-smoothing: antialiased; }
        
        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #BC3908 0%, #220901 100%);
            background-attachment: fixed;
            background-size: cover;
            position: relative;
            overflow-x: hidden;
            overflow-y: auto;
        }
        
        body::before {
            content: '';
            position: fixed;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            z-index: -2;
            background: 
                radial-gradient(ellipse at 20% 50%, rgba(255, 255, 255, 0.15) 0%, transparent 50%),
                radial-gradient(ellipse at 80% 20%, rgba(255, 255, 255, 0.1) 0%, transparent 50%),
                radial-gradient(ellipse at 50% 80%, rgba(0, 0, 0, 0.1) 0%, transparent 50%);
            animation: bgFloat 20s ease-in-out infinite;
        }
        
        @keyframes bgFloat {
            0%, 100% { transform: translate(0, 0) rotate(0deg); }
            33% { transform: translate(30px, -20px) rotate(2deg); }
            66% { transform: translate(-20px, 20px) rotate(-1deg); }
        }
        
        /* Geometric decorations */
        .decoration {
            position: fixed;
            border-radius: 50%;
            opacity: 0.06;
            background: #BC3908; /* Brand Orange-Red */
            z-index: -1;
        }
        
        .decoration-1 {
            width: 400px; height: 400px;
            top: -100px; right: -100px;
        }
        
        .decoration-2 {
            width: 300px; height: 300px;
            bottom: -80px; left: -80px;
            background: #941B0C; /* Dark Crimson */
        }
        
        .decoration-3 {
            width: 150px; height: 150px;
            top: 40%; left: 10%;
            background: #F6AA1C; /* Brand Amber */
            opacity: 0.04;
        }
        
        /* Islamic geometric pattern overlay */
        .pattern-overlay {
            position: fixed;
            inset: 0;
            z-index: -1;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='60' height='60' viewBox='0 0 60 60'%3E%3Cpath d='M30 0L60 30L30 60L0 30Z' fill='none' stroke='%23BC3908' stroke-width='0.3' opacity='0.08'/%3E%3C/svg%3E");
            pointer-events: none;
        }
        
        .split-container {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 1000px;
            padding: 20px;
        }
        
        .split-layout {
            display: flex;
            background: rgba(255, 255, 255, 0.97);
            border-radius: 20px;
            box-shadow: 
                0 25px 50px rgba(0, 0, 0, 0.15),
                0 0 0 1px rgba(255, 255, 255, 0.1);
            overflow: hidden;
            backdrop-filter: blur(20px);
            min-height: 680px;
        }
        
        .split-left {
            flex: 1;
            display: flex;
            flex-direction: column;
            position: relative;
            width: 50%;
            padding: 40px 50px;
            justify-content: center;
            box-sizing: border-box;
        }
        
        .split-right {
            flex: 1;
            background: linear-gradient(135deg, #621708 0%, #220901 100%);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 40px 50px;
            text-align: center;
            color: white;
            position: relative;
            width: 50%;
            box-sizing: border-box;
            background-size: cover;
            background-position: bottom;
            background-repeat: no-repeat;
        }
        
        .split-right::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='60' height='60' viewBox='0 0 60 60'%3E%3Cpath d='M30 0L60 30L30 60L0 30Z' fill='none' stroke='%23ffffff' stroke-width='0.5' opacity='0.05'/%3E%3C/svg%3E");
            z-index: 0;
        }
        
        .split-right-content {
            position: relative;
            z-index: 1;
        }
        
        .shop-title {
            font-size: 1.35rem;
            font-weight: 800;
            margin-bottom: 12px;
            color: #F6AA1C;
        }
        
        .shop-desc {
            font-size: 0.95rem;
            color: rgba(255, 255, 255, 0.85);
            margin-bottom: 30px;
            line-height: 1.5;
        }
        
        .btn-shop-large {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 14px 28px;
            border-radius: 12px;
            background: #F6AA1C;
            color: #220901;
            font-family: 'Inter', sans-serif;
            font-size: 1rem;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.3s;
            box-shadow: 0 8px 20px rgba(246, 170, 28, 0.2);
        }
        
        .btn-shop-large:hover {
            background: #fff;
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(255, 255, 255, 0.2);
        }
        
        /* Gold accent bar */
        .login-accent {
            height: 4px;
            background: linear-gradient(90deg, #F6AA1C, #BC3908, #F6AA1C);
        }
        
        .login-header {
            text-align: center;
            padding: 28px 32px 14px;
        }
        
        .login-logo {
            width: 80px;
            height: 80px;
            margin: 0 auto 16px;
            background: none;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
            font-weight: 800;
            color: #fff;
            position: relative;
        }
        

        
        .login-title {
            font-size: 1.5rem;
            font-weight: 800;
            color: #220901; /* Very Dark Red-Brown */
            margin-bottom: 2px;
        }
        
        .login-subtitle {
            font-size: 0.875rem;
            color: #6b7280;
            font-weight: 400;
        }
        
        .login-bismillah {
            font-family: 'Amiri', serif;
            font-size: 1.25rem;
            color: #BC3908; /* Orange-Red */
            margin-top: 8px;
            opacity: 0.7;
        }
        
        .login-body {
            padding: 6px 32px 24px;
        }
        
        .form-group {
            margin-bottom: 14px;
        }
        
        .form-label {
            display: block;
            font-size: 0.8125rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 6px;
        }
        
        .input-wrapper {
            position: relative;
        }
        
        .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            pointer-events: none;
            transition: color 0.2s;
        }
        
        .form-input {
            width: 100%;
            padding: 12px 14px 12px 44px;
            border: 1.5px solid #e5e7eb;
            border-radius: 10px;
            font-family: 'Inter', sans-serif;
            font-size: 0.9375rem;
            color: #1f2937;
            background: #fff;
            transition: all 0.2s;
            outline: none;
        }
        
        .form-input:focus {
            border-color: #BC3908;
            box-shadow: 0 0 0 3px rgba(188, 57, 8, 0.12);
        }
        
        .form-input:focus + .input-icon-focus,
        .form-input:focus ~ .input-icon {
            color: #BC3908;
        }
        
        .form-input::placeholder {
            color: #9ca3af;
        }
        
        .password-toggle {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: #9ca3af;
            padding: 4px;
            transition: color 0.2s;
        }
        
        .password-toggle:hover {
            color: #374151;
        }
        
        .error-message {
            background: #fef2f2;
            color: #991b1b;
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 0.8125rem;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
            border: 1px solid #fecaca;
            animation: shake 0.5s ease-in-out;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20% { transform: translateX(-8px); }
            40% { transform: translateX(8px); }
            60% { transform: translateX(-4px); }
            80% { transform: translateX(4px); }
        }
        
        .btn-login {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 10px;
            background: linear-gradient(135deg, #BC3908, #621708); /* Red to Dark Red */
            color: #fff;
            font-family: 'Inter', sans-serif;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.25s;
            box-shadow: 0 4px 14px rgba(188, 57, 8, 0.3);
            position: relative;
            overflow: hidden;
        }
        
        .btn-login:hover {
            background: linear-gradient(135deg, #941B0C, #3d1205);
            box-shadow: 0 6px 20px rgba(188, 57, 8, 0.4);
            transform: translateY(-1px);
        }
        
        .btn-login:active {
            transform: translateY(0);
        }
        
        .btn-login::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.15), transparent);
            transition: left 0.5s;
        }
        
        .btn-login:hover::after {
            left: 100%;
        }
        
        .login-footer {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            text-align: center;
            padding: 0 32px 20px;
            font-size: 0.75rem;
            color: #9ca3af;
        }
        
        .login-footer a {
            color: #BC3908;
            text-decoration: none;
            font-weight: 600;
        }
        
        .login-footer a:hover {
            text-decoration: underline;
        }
        
        .login-divider {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 14px 0;
            color: #d1d5db;
            font-size: 0.75rem;
        }
        
        .login-divider::before,
        .login-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #e5e7eb;
        }
        
        .btn-shop {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 10px;
            border: 1.5px solid #e5e7eb;
            border-radius: 10px;
            background: #fff;
            color: #374151;
            font-family: 'Inter', sans-serif;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }
        
        .btn-shop:hover {
            border-color: #BC3908;
            color: #BC3908;
            background: #fdf2ef;
        }
        
        /* Form Tabs */
        .form-tabs {
            display: flex;
            background: #f3f4f6;
            border-radius: 12px;
            padding: 4px;
            margin-bottom: 24px;
        }
        
        .tab-btn {
            flex: 1;
            padding: 10px;
            border: none;
            background: transparent;
            color: #6b7280;
            font-family: 'Inter', sans-serif;
            font-size: 0.875rem;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .tab-btn.active {
            background: #BC3908;
            color: white;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .tab-btn:not(.active):hover {
            color: #374151;
            background: #e5e7eb;
        }

        .success-message {
            background: #fdf2ef;
            color: #92400e;
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 0.8125rem;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
            border: 1px solid #f6aa1c;
        }
        
        /* Demo credentials box */
        .demo-box {
            margin-top: 20px;
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 12px;
            padding: 16px;
            color: rgba(255, 255, 255, 0.8);
            font-size: 0.75rem;
        }
        
        .demo-box-title {
            font-weight: 700;
            color: #fbbf24;
            margin-bottom: 8px;
            font-size: 0.8125rem;
        }
        
        .demo-box table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .demo-box td {
            padding: 3px 0;
            font-family: 'JetBrains Mono', 'Fira Code', monospace;
            font-size: 0.75rem;
        }
        
        .demo-box td:first-child {
            color: rgba(255, 255, 255, 0.5);
            width: 70px;
        }
        
        .login-logo-img {
            object-fit: contain; 
            width: 100px; 
            height: 100px; 
            margin: 0 auto 10px; 
            display: block;
        }

        .shop-brand {
            margin: 0 0 2px; 
            font-size: 1.75rem; 
            font-weight: 800; 
            background: linear-gradient(to right, #f97316, #fde047);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .shop-subtitle {
            font-size: 0.95rem; 
            font-weight: 500; 
            color: rgba(255,255,255,0.9); 
            margin: 0 0 30px;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .split-container {
                padding: 0;
                max-width: 100%;
                min-height: 100vh;
                display: flex;
            }
            .split-layout {
                flex-direction: column;
                border-radius: 0;
                box-shadow: none;
                min-height: 100vh;
            }
            .split-left, .split-right {
                width: 100%;
            }
            .split-right {
                padding: 60px 20px;
                min-height: 92vh;
            }
            .split-left {
                padding: 40px 20px;
            }
            .login-header { padding: 28px 24px 16px; }
            .login-body { padding: 8px 24px 24px; }
            .login-footer { padding: 0 24px 20px; }
            .login-title { font-size: 1.25rem; }
        }
    </style>
</head>
<body>
    <!-- Background decorations -->
    <div class="decoration decoration-1"></div>
    <div class="decoration decoration-2"></div>
    <div class="decoration decoration-3"></div>
    <div class="pattern-overlay"></div>
    
    <div class="split-container">
        <div class="split-layout">
            <!-- Left Side: Online Shop Promotion -->
            <div class="split-right" <?= $loginBg ? 'style="background: linear-gradient(to bottom, #220901 0%, #220901 40%, rgba(34, 9, 1, 0.3) 100%), url(\'' . BASE_URL . '/' . $loginBg . '\') bottom center / cover no-repeat;"' : '' ?>>
                <div class="split-right-content">
                    <img src="<?= $storeLogo ? BASE_URL . '/' . $storeLogo : BASE_URL . '/assets/img/logo_s.png' ?>" alt="Logo" class="login-logo login-logo-img">
                    <h3 class="shop-brand">SarungSantriStore</h3>
                    <p class="shop-subtitle">Pusat Sarung & Fashion Santri</p>
                    <h2 class="shop-title">Jelajahi Koleksi Kami</h2>
                    <p class="shop-desc">
                        Temukan berbagai koleksi sarung, baju koko, peci, dan fashion muslim lainnya. 
                        Belanja kebutuhan Fashion Anda yang elegan, nyaman, dan berkualitas!
                    </p>
                    <a href="<?= BASE_URL ?>/shop/index.php" class="btn-shop-large">
                        Belanja Sekarang
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>
                        </svg>
                    </a>
                    
                    <div style="margin-top: 24px; display: flex; gap: 16px; justify-content: center;">
                        <a href="https://wa.me/6281511481192" target="_blank" style="color: rgba(255,255,255,0.7); transition: all 0.2s; display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 50%; background: rgba(255,255,255,0.1);" onmouseover="this.style.color='#25D366'; this.style.background='rgba(255,255,255,0.2)';" onmouseout="this.style.color='rgba(255,255,255,0.7)'; this.style.background='rgba(255,255,255,0.1)';">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path></svg>
                        </a>
                        <a href="https://www.instagram.com/sarungsantripasuruan?igsh=MW80OTd0dGdmd2F3MQ==" target="_blank" style="color: rgba(255,255,255,0.7); transition: all 0.2s; display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 50%; background: rgba(255,255,255,0.1);" onmouseover="this.style.color='#E1306C'; this.style.background='rgba(255,255,255,0.2)';" onmouseout="this.style.color='rgba(255,255,255,0.7)'; this.style.background='rgba(255,255,255,0.1)';">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"></rect><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"></path><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"></line></svg>
                        </a>
                        <a href="https://www.tiktok.com/@sarungsantristore?_r=1&_t=ZS-98sm4Pbr4Z2" target="_blank" style="color: rgba(255,255,255,0.7); transition: all 0.2s; display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 50%; background: rgba(255,255,255,0.1);" onmouseover="this.style.color='#fff'; this.style.background='rgba(255,255,255,0.2)';" onmouseout="this.style.color='rgba(255,255,255,0.7)'; this.style.background='rgba(255,255,255,0.1)';">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12a4 4 0 1 0 4 4V4a5 5 0 0 0 5 5"></path></svg>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Right Side: Login Form -->
            <div class="split-left">
                <div class="login-accent" style="position: absolute; top: 0; left: 0; width: 100%;"></div>
                
                <div class="login-body">
                    <div class="form-tabs">
                        <button type="button" class="tab-btn <?= $activeTab === 'login' ? 'active' : '' ?>" onclick="switchTab('login')">Login</button>
                        <button type="button" class="tab-btn <?= $activeTab === 'register' ? 'active' : '' ?>" onclick="switchTab('register')">Register</button>
                    </div>

                    <?php if ($error): ?>
                        <div class="error-message" id="loginAlert">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>
                            </svg>
                            <?= htmlspecialchars($error) ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($success): ?>
                        <div class="success-message" id="successAlert">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
                            </svg>
                            <?= $success ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" action="" id="loginForm" style="display: <?= $activeTab === 'login' ? 'block' : 'none' ?>;">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="action" value="login">
                        <div class="form-group">
                            <label class="form-label" for="username">Username/Email/No.HP</label>
                            <div class="input-wrapper">
                                <input type="text" id="username" name="username" class="form-input" placeholder="Masukkan Username / Email" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" autocomplete="username" required>
                                <svg class="input-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="password">Password</label>
                            <div class="input-wrapper">
                                <input type="password" id="password" name="password" class="form-input" placeholder="Masukkan password" autocomplete="current-password" required>
                                <svg class="input-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                <button type="button" class="password-toggle" onclick="togglePassword('password', 'eyeIconLogin')" tabindex="-1">
                                    <svg id="eyeIconLogin" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                </button>
                            </div>
                        </div>
                        <div style="text-align: left; margin-bottom: 20px; font-size: 0.8125rem;">
                            <a href="#" onclick="showDevNotice(); return false;" style="color: #f97316; text-decoration: none; font-weight: 600;">Lupa password?</a>
                        </div>
                        <button type="submit" class="btn-login" style="margin-bottom: 12px;">Masuk</button>

                    </form>
                    
                    <form method="POST" action="" id="registerForm" style="display: <?= $activeTab === 'register' ? 'block' : 'none' ?>;">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="action" value="register">
                        <div class="form-group">
                            <div class="input-wrapper">
                                <input type="text" id="reg_name" name="name" class="form-input" placeholder="Nama lengkap" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <div class="input-wrapper">
                                <input type="text" id="reg_username" name="username" class="form-input" placeholder="Username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <div class="input-wrapper">
                                <input type="email" id="reg_email" name="email" class="form-input" placeholder="Masukkan Email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <div class="input-wrapper">
                                <textarea id="reg_address" name="address" class="form-input" placeholder="Alamat lengkap" rows="2"><?= htmlspecialchars($_POST['address'] ?? '') ?></textarea>
                            </div>
                        </div>
                        <div class="form-group">
                            <div class="input-wrapper">
                                <input type="password" id="reg_password" name="password" class="form-input" placeholder="Masukkan Password" required>
                                <button type="button" class="password-toggle" onclick="togglePassword('reg_password', 'eyeIconReg1')" tabindex="-1">
                                    <svg id="eyeIconReg1" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                </button>
                            </div>
                        </div>
                        <div class="form-group">
                            <div class="input-wrapper">
                                <input type="password" id="reg_password_confirm" name="password_confirm" class="form-input" placeholder="Ulangi password" required>
                                <button type="button" class="password-toggle" onclick="togglePassword('reg_password_confirm', 'eyeIconReg2')" tabindex="-1">
                                    <svg id="eyeIconReg2" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                </button>
                            </div>
                        </div>
                        <button type="submit" class="btn-login" style="margin-top: 10px; margin-bottom: 12px;">Daftar</button>
                        <div style="text-align: center; font-size: 0.8125rem; color: #6b7280;">
                            Sudah punya akun? <a href="#" onclick="switchTab('login')" style="color: #f97316; text-decoration: none; font-weight: 600;">Login sekarang</a>
                        </div>
                    </form>
                    
                    <div style="text-align: center; margin-top: 14px; font-size: 0.8125rem; color: var(--gray-600);">
                        Butuh Bantuan? <a href="https://wa.me/6281216331212" target="_blank" style="color: #f97316; font-weight: 600; text-decoration: none;">Klik disini</a>
                    </div>
                </div>
                
                <div class="login-footer">
                    &copy; <?= date('Y') ?> <?= APP_NAME ?>. Semua hak dilindungi.
                </div>
            </div>
            </div>
        </div>
        
    <script>
        // Auto-hide alert after 3 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alert = document.getElementById('loginAlert');
            if (alert) {
                setTimeout(() => {
                    alert.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    alert.style.opacity = '0';
                    alert.style.transform = 'translateY(-10px)';
                    setTimeout(() => alert.remove(), 500);
                }, 3000);
            }
        });

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

        function showDevNotice() {
            const oldModal = document.getElementById('devModalOverlay');
            if (oldModal) oldModal.remove();
            
            const overlay = document.createElement('div');
            overlay.id = 'devModalOverlay';
            overlay.style.position = 'fixed';
            overlay.style.top = '0';
            overlay.style.left = '0';
            overlay.style.width = '100%';
            overlay.style.height = '100%';
            overlay.style.background = 'rgba(0, 0, 0, 0.5)';
            overlay.style.display = 'flex';
            overlay.style.alignItems = 'center';
            overlay.style.justifyContent = 'center';
            overlay.style.zIndex = '9999';
            overlay.style.opacity = '0';
            overlay.style.transition = 'opacity 0.3s ease';
            
            const modal = document.createElement('div');
            modal.style.background = '#fff';
            modal.style.borderRadius = '16px';
            modal.style.padding = '30px';
            modal.style.width = '90%';
            modal.style.maxWidth = '400px';
            modal.style.textAlign = 'center';
            modal.style.boxShadow = '0 20px 40px rgba(0,0,0,0.2)';
            modal.style.transform = 'scale(0.9)';
            modal.style.transition = 'transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275)';
            
            modal.innerHTML = `
                <div style="width: 64px; height: 64px; background: #fef2f2; border-radius: 50%; margin: 0 auto 20px; display: flex; align-items: center; justify-content: center;">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                </div>
                <h3 style="margin: 0 0 10px; color: #1f2937; font-size: 1.25rem; font-weight: 700; font-family: 'Inter', sans-serif;">Pemberitahuan</h3>
                <p style="color: #6b7280; font-size: 0.95rem; line-height: 1.5; margin: 0 0 24px; font-family: 'Inter', sans-serif;">Fitur Lupa Password masih dalam tahap pengembangan. Silakan hubungi admin jika Anda kesulitan login.</p>
                <button onclick="closeDevNotice()" style="background: #ef4444; color: #fff; border: none; padding: 12px 24px; border-radius: 8px; font-weight: 600; cursor: pointer; width: 100%; transition: background 0.2s; font-family: 'Inter', sans-serif;" onmouseover="this.style.background='#dc2626'" onmouseout="this.style.background='#ef4444'">Mengerti</button>
            `;
            
            overlay.appendChild(modal);
            document.body.appendChild(overlay);
            
            // Animasi masuk
            requestAnimationFrame(() => {
                overlay.style.opacity = '1';
                modal.style.transform = 'scale(1)';
            });
            
            // Tutup jika klik di luar modal
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) closeDevNotice();
            });
        }

        function closeDevNotice() {
            const overlay = document.getElementById('devModalOverlay');
            if (overlay) {
                overlay.style.opacity = '0';
                overlay.children[0].style.transform = 'scale(0.9)';
                setTimeout(() => overlay.remove(), 300);
            }
        }
    </script>
</body>
</html>

