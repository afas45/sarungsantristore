<?php
/**
 * Kasir Ibtidaiyah - Profil Pengguna
 */
require_once __DIR__ . '/../../config/app.php';
requireLogin();

$db = Database::conn();
$storeName = getSetting('store_name', APP_NAME);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = sanitize($_POST['full_name']);
    $phone = formatPhoneNumber(sanitize($_POST['phone']));
    $email = sanitize($_POST['email']);
    $address = sanitize($_POST['address'] ?? '');
    $password = $_POST['password'];
    
    try {
        if (isCustomer()) {
            if ($password) {
                $stmt = $db->prepare("UPDATE customers SET name = ?, phone = ?, email = ?, address = ?, password = ? WHERE id = ?");
                $stmt->execute([$fullName, $phone, $email, $address, hashPassword($password), $_SESSION['customer_id']]);
            } else {
                $stmt = $db->prepare("UPDATE customers SET name = ?, phone = ?, email = ?, address = ? WHERE id = ?");
                $stmt->execute([$fullName, $phone, $email, $address, $_SESSION['customer_id']]);
            }
        } else {
            if ($password) {
                $stmt = $db->prepare("UPDATE users SET full_name = ?, phone = ?, email = ?, password_hash = ? WHERE id = ?");
                $stmt->execute([$fullName, $phone, $email, hashPassword($password), $_SESSION['user_id']]);
            } else {
                $stmt = $db->prepare("UPDATE users SET full_name = ?, phone = ?, email = ? WHERE id = ?");
                $stmt->execute([$fullName, $phone, $email, $_SESSION['user_id']]);
            }
        }
        
        $_SESSION['full_name'] = $fullName;
        
        flashMessage('success', 'Profil berhasil diperbarui!');
    } catch (Exception $e) {
        flashMessage('error', 'Terjadi kesalahan: ' . $e->getMessage());
    }
    
    redirect(BASE_URL . '/shop/account/profile.php');
}

if (isCustomer()) {
    $stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$_SESSION['customer_id'] ?? 0]);
    $user = $stmt->fetch();
    if ($user) {
        $user['username'] = $user['email'] ?: $user['phone'];
        $user['full_name'] = $user['name'];
    }
} else {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id'] ?? 0]);
    $user = $stmt->fetch();
}

if (!$user) {
    // If user not found in DB, force logout
    logout();
    redirect(BASE_URL . '/login.php');
}

$cartCount = isset($_SESSION['shop_cart']) ? array_sum(array_column($_SESSION['shop_cart'], 'qty')) : 0;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil Saya - <?= $storeName ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= ASSETS_URL ?>/css/main.css?v=<?= APP_VERSION ?>">
    <link rel="stylesheet" href="<?= ASSETS_URL ?>/css/ecommerce.css?v=<?= APP_VERSION ?>">
</head>
<body>
    <div class="shop-layout">
        <?php
        $showSearch = false;
        $searchVal  = '';
        $cartCount  = array_sum(array_column($_SESSION['shop_cart'] ?? [], 'qty'));
        include INCLUDES_PATH . '/shop_navbar.php';
        ?>
        
        <div class="shop-content">
            <?= renderFlashMessage() ?>
            <h1 style="font-size: 1.5rem; font-weight: 800; margin-bottom: 24px; display: flex; align-items: center; gap: 8px;">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: var(--primary-600);"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                Profil Saya
            </h1>
            
            <div class="card" style="max-width: 600px; margin: 0 auto;">
                <div class="card-body">
                    <form method="POST">
                        <div class="form-group">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($user['username'] ?? '') ?>" disabled>
                            <div class="text-xs text-muted mt-4">Username tidak dapat diubah.</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Nama Lengkap</label>
                            <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($user['full_name'] ?? '') ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">No. Telepon / WhatsApp</label>
                            <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email'] ?? '') ?>">
                        </div>
                        <?php if (isCustomer()): ?>
                        <div class="form-group">
                            <label class="form-label">Alamat Lengkap</label>
                            <textarea name="address" class="form-control" rows="3"><?= htmlspecialchars($user['address'] ?? '') ?></textarea>
                            <div class="text-xs text-muted mt-2">Alamat ini akan menjadi alamat utama Anda saat berbelanja.</div>
                        </div>
                        <?php endif; ?>
                        <div class="form-group">
                            <label class="form-label">Password Baru</label>
                            <input type="password" name="password" class="form-control" placeholder="Kosongkan jika tidak ingin mengubah">
                        </div>
                        <button type="submit" class="btn btn-primary" style="width: 100%;">Simpan Perubahan</button>
                    </form>
                </div>
            </div>
        </div>
        
        <footer class="shop-footer">
            <div class="shop-footer-bottom">&copy; <?= date('Y') ?> <?= $storeName ?></div>
        </footer>
    </div>
</body>
</html>
