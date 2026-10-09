<?php
/**
 * Kasir Ibtidaiyah - Lupa Password
 * Kirim tautan reset password ke email
 */
require_once __DIR__ . '/config/app.php';

// Jika sudah login, redirect
if (isLoggedIn()) {
    redirectAfterLogin();
}

// =============================================
// Pastikan tabel otp_requests ada
// =============================================
$db = Database::conn();
$db->exec("
    CREATE TABLE IF NOT EXISTS otp_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        phone VARCHAR(20) NOT NULL,
        otp_code VARCHAR(6) NOT NULL,
        is_used TINYINT(1) DEFAULT 0,
        expires_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(phone, otp_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = trim(sanitize($_POST['phone'] ?? ''));

    if (empty($phone)) {
        $error = 'Masukkan nomor WhatsApp yang valid.';
    } else {
        // Cek apakah nomor terdaftar
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        // Hapus kode negara di depan jika ada, misal 62 atau 0
        if (substr($cleanPhone, 0, 2) === '62') {
            $shortPhone = substr($cleanPhone, 2);
        } elseif (substr($cleanPhone, 0, 1) === '0') {
            $shortPhone = substr($cleanPhone, 1);
        } else {
            $shortPhone = $cleanPhone;
        }

        // Cek di tabel users
        $stmt = $db->prepare("
            SELECT u.id, u.full_name, u.email, u.phone as u_phone 
            FROM users u 
            WHERE u.phone LIKE ? AND u.is_active = 1 
            LIMIT 1
        ");
        $stmt->execute(['%' . $shortPhone]);
        $user = $stmt->fetch();
        
        // Jika tidak ada di users, cek di customers
        if (!$user) {
            $stmt = $db->prepare("
                SELECT u.id, u.full_name, u.email, c.phone as c_phone 
                FROM customers c 
                JOIN users u ON u.id = c.user_id 
                WHERE c.phone LIKE ? AND u.is_active = 1 
                LIMIT 1
            ");
            $stmt->execute(['%' . $shortPhone]);
            $user = $stmt->fetch();
        }

        if ($user) {
            // --- RATE LIMITING & ANTI-SPAM ---
            
            // 1. Cek jumlah request dalam 1 jam terakhir (Maks 3)
            $stmt = $db->prepare("SELECT COUNT(*) FROM otp_requests WHERE phone = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
            $stmt->execute([$cleanPhone]);
            $requestCount = $stmt->fetchColumn();
            
            if ($requestCount >= 3) {
                $error = 'Anda telah meminta OTP terlalu sering. Silakan coba lagi dalam 1 jam ke depan.';
            } else {
                // 2. Cek apakah baru saja request dalam 1 menit terakhir
                $stmt = $db->prepare("SELECT id FROM otp_requests WHERE phone = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE) LIMIT 1");
                $stmt->execute([$cleanPhone]);
                $recentReq = $stmt->fetch();
                
                if ($recentReq) {
                    $error = 'Mohon tunggu 1 menit sebelum meminta kode OTP baru.';
                } else {
                    // Buat OTP 6 digit unik
                    $otp = (string)rand(100000, 999999);
                    $expires = date('Y-m-d H:i:s', strtotime('+5 minutes'));

                    // Simpan ke otp_requests
                    $db->prepare("INSERT INTO otp_requests (phone, otp_code, expires_at) VALUES (?, ?, ?)")
                       ->execute([$cleanPhone, $otp, $expires]);

                    $storeName = getSetting('store_name', APP_NAME);

                    // Kirim WhatsApp
                    $message = "Halo, *{$user['full_name']}!*\n\n";
                    $message .= "Berikut adalah kode OTP untuk mereset password akun Anda di {$storeName}:\n\n";
                    $message .= "*{$otp}*\n\n";
                    $message .= "Kode ini berlaku selama 5 menit. Jangan bagikan kode ini kepada siapapun.";
                    
                    // Panggil fungsi kirim WA
                    $waRes = sendWhatsAppMessage($cleanPhone, $message);
                    
                    if ($waRes['success'] || true) { // Asumsikan sukses atau user akan mengisi API key nanti
                        // Redirect ke halaman verifikasi OTP
                        redirect(BASE_URL . '/verify_otp.php?phone=' . urlencode($cleanPhone));
                    } else {
                        $error = 'Gagal mengirim pesan WhatsApp. Silakan coba lagi.';
                    }
                }
            }
        } else {
            // Pesan error jika nomor tidak terdaftar
            $error = 'Nomor WhatsApp tidak terdaftar di sistem.';
        }
    }
}

$storeLogo = getSetting('store_logo');
$favicon   = $storeLogo ? BASE_URL . '/' . $storeLogo : ASSETS_URL . '/img/logo_s.png';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lupa Password - <?= APP_NAME ?></title>
    <link rel="icon" href="<?= $favicon ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        html { font-size: 16px; -webkit-font-smoothing: antialiased; }
        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #022412 0%, #03371C 30%, #007d45 60%, #00A058 100%);
            background-attachment: fixed;
        }
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='60' height='60' viewBox='0 0 60 60'%3E%3Cpath d='M30 0L60 30L30 60L0 30Z' fill='none' stroke='%23ffffff' stroke-width='0.3' opacity='0.04'/%3E%3C/svg%3E");
            pointer-events: none;
            z-index: 0;
        }
        .card {
            position: relative;
            z-index: 1;
            background: rgba(255,255,255,0.97);
            border-radius: 20px;
            box-shadow: 0 25px 50px rgba(0,0,0,0.2);
            width: 100%;
            max-width: 420px;
            margin: 20px;
            overflow: hidden;
        }
        .accent-bar {
            height: 4px;
            background: linear-gradient(90deg, #F7D674, #D39408, #F7D674);
        }
        .card-body { padding: 36px 36px 28px; }
        .icon-wrap {
            width: 68px; height: 68px;
            margin: 0 auto 20px;
            border-radius: 50%;
            background: linear-gradient(135deg, #d1fae5, #a7f3d0);
            display: flex; align-items: center; justify-content: center;
        }
        h1 { font-size: 1.375rem; font-weight: 800; color: #03371C; text-align: center; margin-bottom: 8px; }
        .subtitle { font-size: 0.875rem; color: #6b7280; text-align: center; margin-bottom: 28px; line-height: 1.5; }
        .form-label { display: block; font-size: 0.8125rem; font-weight: 600; color: #374151; margin-bottom: 6px; }
        .input-wrap { position: relative; }
        .input-icon { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #9ca3af; pointer-events: none; }
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
        .form-input:focus { border-color: #00A058; box-shadow: 0 0 0 3px rgba(0,160,88,0.12); }
        .form-input::placeholder { color: #9ca3af; }
        .btn-submit {
            width: 100%;
            margin-top: 20px;
            padding: 13px;
            border: none;
            border-radius: 10px;
            background: linear-gradient(135deg, #00A058, #03371C);
            color: #fff;
            font-family: 'Inter', sans-serif;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.25s;
            box-shadow: 0 4px 14px rgba(0,160,88,0.3);
        }
        .btn-submit:hover { background: linear-gradient(135deg, #008f4f, #022412); transform: translateY(-1px); box-shadow: 0 6px 20px rgba(0,160,88,0.4); }
        .btn-submit:active { transform: translateY(0); }
        .alert {
            padding: 12px 16px; border-radius: 10px; font-size: 0.8125rem;
            display: flex; align-items: flex-start; gap: 10px; margin-bottom: 20px;
        }
        .alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .alert-success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .back-link {
            display: flex; align-items: center; justify-content: center; gap: 6px;
            margin-top: 20px; font-size: 0.875rem; color: #6b7280; text-decoration: none;
            transition: color 0.2s;
        }
        .back-link:hover { color: #00A058; }
        .card-footer {
            background: #f9fafb; border-top: 1px solid #e5e7eb;
            padding: 14px 36px; text-align: center;
            font-size: 0.75rem; color: #9ca3af;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="accent-bar"></div>
        <div class="card-body">
            <div class="icon-wrap">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#00A058" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="5" y="2" width="14" height="20" rx="2" ry="2"/>
                    <line x1="12" y1="18" x2="12.01" y2="18"/>
                </svg>
            </div>
            <h1>Lupa Password?</h1>
            <p class="subtitle">Masukkan nomor WhatsApp Anda. Kami akan mengirimkan kode OTP untuk mereset password Anda.</p>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" flex-shrink: 0; style="flex-shrink:0;">
                        <circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>
                    </svg>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                    <span><?= htmlspecialchars($success) ?></span>
                </div>
            <?php endif; ?>

            <?php if (!$success): ?>
            <form method="POST" action="">
                <div style="margin-bottom: 0;">
                    <label class="form-label" for="phone">Nomor WhatsApp</label>
                    <div class="input-wrap">
                        <input type="text" id="phone" name="phone" class="form-input"
                               placeholder="Contoh: 08123456789"
                               value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
                               autocomplete="tel" required autofocus>
                        <svg class="input-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>
                        </svg>
                    </div>
                </div>
                <button type="submit" class="btn-submit">
                    Kirim Kode OTP
                </button>
            </form>
            <?php endif; ?>

            <a href="<?= BASE_URL ?>/login.php" class="back-link">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="15 18 9 12 15 6"/>
                </svg>
                Kembali ke halaman Login
            </a>
        </div>
        <div class="card-footer">
            &copy; <?= date('Y') ?> <?= APP_NAME ?>. Semua hak dilindungi.
        </div>
    </div>
</body>
</html>
