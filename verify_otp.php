<?php
/**
 * Kasir Ibtidaiyah - Verifikasi OTP
 * Validasi OTP 6-digit untuk mereset password
 */
require_once __DIR__ . '/config/app.php';

// Jika sudah login, redirect
if (isLoggedIn()) {
    redirectAfterLogin();
}

$db = Database::conn();

$phone = sanitize($_GET['phone'] ?? '');
$error = '';
$success = '';

if (empty($phone)) {
    redirect(BASE_URL . '/forgot_password.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $otp = trim(sanitize($_POST['otp'] ?? ''));

    if (empty($otp) || strlen($otp) !== 6) {
        $error = 'Masukkan 6 digit kode OTP yang valid.';
    } else {
        // Validasi OTP
        $stmt = $db->prepare("
            SELECT id, otp_code, expires_at, is_used 
            FROM otp_requests 
            WHERE phone = ? AND otp_code = ? 
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute([$phone, $otp]);
        $resetRow = $stmt->fetch();

        if (!$resetRow) {
            $error = 'Kode OTP tidak valid atau salah.';
        } elseif ($resetRow['is_used'] == 1) {
            $error = 'Kode OTP sudah digunakan sebelumnya.';
        } elseif (strtotime($resetRow['expires_at']) < time()) {
            $error = 'Kode OTP sudah kedaluwarsa. Silakan minta kode baru.';
        } else {
            // OTP Valid! Redirect ke reset password dan bawa ID OTP sebagai verifikator
            $_SESSION['verified_otp_id'] = $resetRow['id'];
            $_SESSION['verified_otp_phone'] = $phone;
            
            redirect(BASE_URL . '/reset_password.php');
        }
    }
}

$storeLogo = getSetting('store_logo');
$favicon   = $storeLogo ? BASE_URL . '/' . $storeLogo : ASSETS_URL . '/img/logo_SSS.png';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi OTP - <?= APP_NAME ?></title>
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
            font-size: 1.5rem;
            letter-spacing: 4px;
            color: #1f2937;
            background: #fff;
            transition: all 0.2s;
            outline: none;
            text-align: center;
        }
        .form-input:focus { border-color: #00A058; box-shadow: 0 0 0 3px rgba(0,160,88,0.12); }
        .form-input::placeholder { color: #9ca3af; letter-spacing: normal; font-size: 0.9375rem; }
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
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                </svg>
            </div>
            <h1>Verifikasi OTP</h1>
            <p class="subtitle">Kode 6 digit telah dikirimkan ke WhatsApp Anda <strong><?= htmlspecialchars($phone) ?></strong>. Silakan masukkan kode tersebut di bawah.</p>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;">
                        <circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>
                    </svg>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div style="margin-bottom: 0;">
                    <label class="form-label" for="otp" style="text-align: center;">Kode OTP</label>
                    <div class="input-wrap">
                        <input type="text" id="otp" name="otp" class="form-input"
                               placeholder="------"
                               maxlength="6"
                               value="<?= htmlspecialchars($_POST['otp'] ?? '') ?>"
                               autocomplete="off" required autofocus>
                    </div>
                </div>
                <button type="submit" class="btn-submit">
                    Verifikasi Kode
                </button>
            </form>

            <a href="<?= BASE_URL ?>/forgot_password.php" class="back-link">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="15 18 9 12 15 6"/>
                </svg>
                Kembali
            </a>
        </div>
        <div class="card-footer">
            &copy; <?= date('Y') ?> <?= APP_NAME ?>. Semua hak dilindungi.
        </div>
    </div>
</body>
</html>
