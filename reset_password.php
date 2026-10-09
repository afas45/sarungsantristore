<?php
/**
 * Kasir Ibtidaiyah - Reset Password
 * Validasi token + form password baru dengan validasi "belum pernah digunakan"
 */
require_once __DIR__ . '/config/app.php';

// Jika sudah login, redirect
if (isLoggedIn()) {
    redirectAfterLogin();
}

$db = Database::conn();

$otpId    = $_SESSION['verified_otp_id'] ?? null;
$phone    = $_SESSION['verified_otp_phone'] ?? '';
$error    = '';
$success  = '';
$tokenOk  = false;
$resetDone = false;
$userRow   = null;

// =============================================
// Validasi token/sesi
// =============================================
if (empty($otpId) || empty($phone)) {
    $error = 'Sesi reset password tidak valid. Anda harus memverifikasi kode OTP terlebih dahulu.';
} else {
    // Verifikasi kembali status OTP di database
    $stmt = $db->prepare("SELECT * FROM otp_requests WHERE id = ? AND phone = ? AND is_used = 0 AND expires_at > NOW() LIMIT 1");
    $stmt->execute([$otpId, $phone]);
    $otpRow = $stmt->fetch();

    if (!$otpRow) {
        $error = 'Sesi reset tidak valid atau sudah kedaluwarsa. Silakan minta kode OTP baru.';
    } else {
        // Cari data user berdasarkan nomor telepon (cek users, jika tidak ada cek customers)
        // Cek users
        $stmt = $db->prepare("SELECT u.id as user_id, u.full_name, u.password_hash FROM users u WHERE u.phone = ? AND u.is_active = 1 LIMIT 1");
        $stmt->execute([$phone]);
        $userRow = $stmt->fetch();

        if (!$userRow) {
            // Cek customers
            $stmt = $db->prepare("
                SELECT u.id as user_id, u.full_name, u.password_hash 
                FROM customers c 
                JOIN users u ON u.id = c.user_id 
                WHERE c.phone = ? AND u.is_active = 1 LIMIT 1
            ");
            $stmt->execute([$phone]);
            $userRow = $stmt->fetch();
        }

        if (!$userRow) {
            $error = 'Akun tidak ditemukan atau tidak aktif.';
        } else {
            $tokenOk = true;
        }
    }
}

// =============================================
// Proses form password baru
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenOk) {
    $newPassword  = $_POST['new_password']     ?? '';
    $confPassword = $_POST['confirm_password'] ?? '';

    if (strlen($newPassword) < 8) {
        $error = 'Password minimal 8 karakter.';
    } elseif ($newPassword !== $confPassword) {
        $error = 'Konfirmasi password tidak cocok.';
    } elseif (password_verify($newPassword, $userRow['password_hash'])) {
        // Pastikan password BARU berbeda dari password LAMA
        $error = 'Password baru tidak boleh sama dengan password lama. Gunakan password yang belum pernah digunakan.';
    } else {
        // Semua validasi lulus — update password
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

        $db->beginTransaction();
        try {
            // Update users table
            $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
               ->execute([$newHash, $userRow['user_id']]);

            // Update customers table jika ada (untuk role Pelanggan)
            $db->prepare("UPDATE customers SET password = ? WHERE user_id = ?")
               ->execute([$newHash, $userRow['user_id']]);

            // Tandai OTP sebagai sudah digunakan
            $db->prepare("UPDATE otp_requests SET is_used = 1 WHERE id = ?")
               ->execute([$otpId]);

            $db->commit();
            
            // Hapus session OTP
            unset($_SESSION['verified_otp_id']);
            unset($_SESSION['verified_otp_phone']);

            $success   = 'Password berhasil diubah! Silakan login dengan password baru Anda.';
            $resetDone = true;
        } catch (Exception $e) {
            $db->rollBack();
            $error = 'Terjadi kesalahan. Silakan coba lagi.';
        }
    }
}

$storeLogo = getSetting('store_logo');
$favicon = $storeLogo ? BASE_URL . '/' . $storeLogo : ASSETS_URL . '/img/logo_s.png';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - <?= APP_NAME ?></title>
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
            position: relative; z-index: 1;
            background: rgba(255,255,255,0.97);
            border-radius: 20px;
            box-shadow: 0 25px 50px rgba(0,0,0,0.2);
            width: 100%; max-width: 440px;
            margin: 20px;
            overflow: hidden;
        }
        .accent-bar { height: 4px; background: linear-gradient(90deg, #F7D674, #D39408, #F7D674); }
        .card-body { padding: 36px 36px 28px; }
        .icon-wrap {
            width: 68px; height: 68px;
            margin: 0 auto 20px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
        }
        .icon-wrap.green  { background: linear-gradient(135deg, #d1fae5, #a7f3d0); }
        .icon-wrap.blue   { background: linear-gradient(135deg, #dbeafe, #bfdbfe); }
        .icon-wrap.red    { background: linear-gradient(135deg, #fee2e2, #fecaca); }
        .icon-wrap.check  { background: linear-gradient(135deg, #d1fae5, #6ee7b7); }
        h1 { font-size: 1.375rem; font-weight: 800; color: #03371C; text-align: center; margin-bottom: 8px; }
        .subtitle { font-size: 0.875rem; color: #6b7280; text-align: center; margin-bottom: 28px; line-height: 1.5; }
        .form-label { display: block; font-size: 0.8125rem; font-weight: 600; color: #374151; margin-bottom: 6px; }
        .form-group { margin-bottom: 16px; }
        .input-wrap { position: relative; }
        .input-icon { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #9ca3af; pointer-events: none; }
        .form-input {
            width: 100%;
            padding: 12px 44px 12px 44px;
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
        .password-toggle {
            position: absolute; right: 14px; top: 50%; transform: translateY(-50%);
            background: none; border: none; cursor: pointer; color: #9ca3af; padding: 4px;
            transition: color 0.2s;
        }
        .password-toggle:hover { color: #374151; }

        /* Password strength meter */
        .strength-bar {
            height: 4px; border-radius: 2px; margin-top: 8px;
            background: #e5e7eb; overflow: hidden;
        }
        .strength-fill {
            height: 100%; border-radius: 2px;
            transition: width 0.3s, background 0.3s;
            width: 0%;
        }
        .strength-label { font-size: 0.75rem; color: #6b7280; margin-top: 4px; }

        /* Password rules checklist */
        .rules-list { list-style: none; margin-top: 10px; display: flex; flex-direction: column; gap: 4px; }
        .rules-list li {
            font-size: 0.75rem; color: #9ca3af;
            display: flex; align-items: center; gap: 6px;
            transition: color 0.2s;
        }
        .rules-list li.ok { color: #059669; }
        .rules-list li svg { flex-shrink: 0; }

        .btn-submit {
            width: 100%; margin-top: 20px; padding: 13px; border: none; border-radius: 10px;
            background: linear-gradient(135deg, #00A058, #03371C);
            color: #fff; font-family: 'Inter', sans-serif; font-size: 1rem; font-weight: 700;
            cursor: pointer; transition: all 0.25s;
            box-shadow: 0 4px 14px rgba(0,160,88,0.3);
        }
        .btn-submit:hover { background: linear-gradient(135deg, #008f4f, #022412); transform: translateY(-1px); }
        .btn-submit:active { transform: translateY(0); }
        .btn-submit:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
        .alert {
            padding: 12px 16px; border-radius: 10px; font-size: 0.8125rem;
            display: flex; align-items: flex-start; gap: 10px; margin-bottom: 20px;
        }
        .alert-error   { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
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
        .btn-login-go {
            display: flex; align-items: center; justify-content: center; gap: 8px;
            width: 100%; margin-top: 16px; padding: 13px; border: none; border-radius: 10px;
            background: linear-gradient(135deg, #00A058, #03371C);
            color: #fff; font-family: 'Inter', sans-serif; font-size: 1rem; font-weight: 700;
            text-decoration: none; transition: all 0.25s;
            box-shadow: 0 4px 14px rgba(0,160,88,0.3);
        }
        .btn-login-go:hover { background: linear-gradient(135deg, #008f4f, #022412); transform: translateY(-1px); }
    </style>
</head>
<body>
    <div class="card">
        <div class="accent-bar"></div>
        <div class="card-body">

            <?php if (!$tokenOk && !$resetDone): ?>
                <!-- Token tidak valid -->
                <div class="icon-wrap red" style="margin: 0 auto 20px;">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.2">
                        <circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>
                    </svg>
                </div>
                <h1>Tautan Tidak Valid</h1>
                <p class="subtitle"><?= htmlspecialchars($error) ?></p>
                <a href="<?= BASE_URL ?>/forgot_password.php" class="btn-login-go">
                    Minta Tautan Reset Baru
                </a>
                <a href="<?= BASE_URL ?>/login.php" class="back-link">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="15 18 9 12 15 6"/>
                    </svg>
                    Kembali ke Login
                </a>

            <?php elseif ($resetDone): ?>
                <!-- Reset berhasil -->
                <div class="icon-wrap check" style="margin: 0 auto 20px;">
                    <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2.5">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                        <polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                </div>
                <h1>Password Berhasil Diubah!</h1>
                <p class="subtitle">Password Anda telah berhasil diperbarui. Silakan login menggunakan password baru Anda.</p>
                <a href="<?= BASE_URL ?>/login.php" class="btn-login-go">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
                        <polyline points="10 17 15 12 10 7"/>
                        <line x1="15" y1="12" x2="3" y2="12"/>
                    </svg>
                    Login Sekarang
                </a>

            <?php else: ?>
                <!-- Form reset password -->
                <div class="icon-wrap blue" style="margin: 0 auto 20px;">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.2">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                    </svg>
                </div>
                <h1>Buat Password Baru</h1>
                <p class="subtitle">
                    Halo, <strong><?= htmlspecialchars($userRow['full_name']) ?></strong>! 
                    Masukkan password baru yang <strong>belum pernah digunakan</strong> sebelumnya dan konfirmasikan untuk menyelesaikan proses.
                </p>

                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;">
                            <circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>
                        </svg>
                        <span><?= htmlspecialchars($error) ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" id="resetForm">
                    <div class="form-group">
                        <label class="form-label" for="new_password">Password Baru</label>
                        <div class="input-wrap">
                            <input type="password" id="new_password" name="new_password"
                                   class="form-input" placeholder="Minimal 8 karakter"
                                   autocomplete="new-password" required
                                   oninput="checkStrength(this.value); checkRules(this.value);">
                            <svg class="input-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                            </svg>
                            <button type="button" class="password-toggle" onclick="togglePw('new_password','eye1')" tabindex="-1">
                                <svg id="eye1" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                                </svg>
                            </button>
                        </div>
                        <!-- Strength meter -->
                        <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
                        <div class="strength-label" id="strengthLabel"></div>
                        <!-- Rules checklist -->
                        <ul class="rules-list" id="rulesList">
                            <li id="rule-len">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/></svg>
                                Minimal 8 karakter
                            </li>
                            <li id="rule-upper">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/></svg>
                                Mengandung huruf kapital (A-Z)
                            </li>
                            <li id="rule-num">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/></svg>
                                Mengandung angka (0-9)
                            </li>
                        </ul>
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label" for="confirm_password">Konfirmasi Password</label>
                        <div class="input-wrap">
                            <input type="password" id="confirm_password" name="confirm_password"
                                   class="form-input" placeholder="Ulangi password baru"
                                   autocomplete="new-password" required
                                   oninput="checkMatch()">
                            <svg class="input-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                            </svg>
                            <button type="button" class="password-toggle" onclick="togglePw('confirm_password','eye2')" tabindex="-1">
                                <svg id="eye2" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                                </svg>
                            </button>
                        </div>
                        <div id="matchMsg" style="font-size:0.75rem; margin-top:5px;"></div>
                    </div>

                    <button type="submit" class="btn-submit" id="btnSubmit">
                        Simpan Password Baru
                    </button>
                </form>

                <a href="<?= BASE_URL ?>/login.php" class="back-link">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="15 18 9 12 15 6"/>
                    </svg>
                    Kembali ke Login
                </a>
            <?php endif; ?>

        </div>
        <div class="card-footer">
            &copy; <?= date('Y') ?> <?= APP_NAME ?>. Semua hak dilindungi.
        </div>
    </div>

    <script>
    // =============================================
    // TOGGLE PASSWORD VISIBILITY
    // =============================================
    function togglePw(inputId, iconId) {
        const input = document.getElementById(inputId);
        const icon  = document.getElementById(iconId);
        if (input.type === 'password') {
            input.type = 'text';
            icon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
        } else {
            input.type = 'password';
            icon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
        }
    }

    // =============================================
    // STRENGTH METER
    // =============================================
    function checkStrength(pw) {
        const fill  = document.getElementById('strengthFill');
        const label = document.getElementById('strengthLabel');
        if (!fill) return;

        let score = 0;
        if (pw.length >= 8)  score++;
        if (pw.length >= 12) score++;
        if (/[A-Z]/.test(pw)) score++;
        if (/[0-9]/.test(pw)) score++;
        if (/[^A-Za-z0-9]/.test(pw)) score++;

        const levels = [
            { pct: '0%',   bg: '#e5e7eb', txt: '' },
            { pct: '20%',  bg: '#ef4444', txt: 'Sangat Lemah' },
            { pct: '40%',  bg: '#f97316', txt: 'Lemah' },
            { pct: '60%',  bg: '#eab308', txt: 'Sedang' },
            { pct: '80%',  bg: '#22c55e', txt: 'Kuat' },
            { pct: '100%', bg: '#16a34a', txt: 'Sangat Kuat' },
        ];
        const lvl = levels[Math.min(score, 5)];
        fill.style.width      = lvl.pct;
        fill.style.background = lvl.bg;
        label.textContent     = lvl.txt;
        label.style.color     = lvl.bg;
    }

    // =============================================
    // RULES CHECKLIST
    // =============================================
    function checkRules(pw) {
        setRule('rule-len',   pw.length >= 8);
        setRule('rule-upper', /[A-Z]/.test(pw));
        setRule('rule-num',   /[0-9]/.test(pw));
        validateForm();
    }

    function setRule(id, ok) {
        const li  = document.getElementById(id);
        if (!li) return;
        li.classList.toggle('ok', ok);
        li.querySelector('svg').innerHTML = ok
            ? '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>'
            : '<circle cx="12" cy="12" r="10"/>';
    }

    // =============================================
    // MATCH CHECK
    // =============================================
    function checkMatch() {
        const pw   = document.getElementById('new_password').value;
        const conf = document.getElementById('confirm_password').value;
        const msg  = document.getElementById('matchMsg');
        if (!msg) return;
        if (conf === '') {
            msg.textContent = '';
        } else if (pw === conf) {
            msg.textContent = '✅ Password cocok';
            msg.style.color = '#059669';
        } else {
            msg.textContent = '❌ Password tidak cocok';
            msg.style.color = '#dc2626';
        }
        validateForm();
    }

    function validateForm() {
        const pw   = document.getElementById('new_password')?.value    || '';
        const conf = document.getElementById('confirm_password')?.value || '';
        const btn  = document.getElementById('btnSubmit');
        if (!btn) return;
        const valid = pw.length >= 8 && /[A-Z]/.test(pw) && /[0-9]/.test(pw) && pw === conf;
        btn.disabled = !valid;
    }

    // Init
    validateForm();
    </script>
</body>
</html>
