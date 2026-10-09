<?php
require_once '../../config/database.php';
require_once '../../config/app.php';
require_once '../../includes/helpers.php';
session_start();

if (isset($_SESSION['customer_id'])) {
    redirect(APP_URL . '/shop/account/profile.php');
}

$error = '';
$register_success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = Database::getInstance()->getConnection();
    $action = $_POST['action'] ?? '';
    
    if ($action === 'login') {
        $email = sanitize($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        
        $stmt = $db->prepare("
            SELECT c.id, c.name, c.password, pt.name as price_type_name 
            FROM customers c 
            LEFT JOIN price_types pt ON c.price_type_id = pt.id 
            WHERE c.email = ?
        ");
        $stmt->execute([$email]);
        $customer = $stmt->fetch();
        
        if ($customer && password_verify($password, $customer['password'])) {
            $_SESSION['customer_id'] = $customer['id'];
            $_SESSION['customer_name'] = $customer['name'];
            $_SESSION['price_type_name'] = $customer['price_type_name'] ?: 'Umum';
            redirect(APP_URL . '/shop/account/profile.php');
        } else {
            $error = "Email atau password salah.";
        }
    } elseif ($action === 'register') {
        $name = sanitize($_POST['name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $phone = formatPhoneNumber(sanitize($_POST['phone'] ?? ''));
        $address = sanitize($_POST['address'] ?? '');
        
        // Check if email exists in customers
        $stmt = $db->prepare("SELECT id FROM customers WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = "Email sudah terdaftar.";
        } else {
            try {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                // Insert into customers, setting default price_type_id to 2 (Ranting)
                $stmt = $db->prepare("INSERT INTO customers (name, phone, email, address, password, price_type_id) VALUES (?, ?, ?, ?, ?, 2)");
                $stmt->execute([$name, $phone, $email, $address, $hashed_password]);
                
                $register_success = "Pendaftaran berhasil. Silakan login.";
            } catch (Exception $e) {
                $error = "Gagal mendaftar: " . $e->getMessage();
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
    <title>Login / Register - <?= APP_NAME ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/ecommerce.css">
    <style>
        .auth-container {
            display: flex;
            justify-content: center;
            gap: 2rem;
            padding: 4rem 2rem;
            max-width: 1000px;
            margin: 0 auto;
        }
        .auth-card {
            background: white;
            border-radius: 8px;
            padding: 2rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            width: 100%;
            max-width: 450px;
        }
        @media (max-width: 768px) {
            .auth-container {
                flex-direction: column;
                align-items: center;
            }
        }
    </style>
</head>
<body>

<?php include '../../includes/header.php'; // Or a separate shop header ?>

<div class="container">
    <div class="auth-container">
        <!-- Login Form -->
        <div class="auth-card">
            <h2>Login</h2>
            <?php if ($error && $_POST['action'] === 'login'): ?>
                <div class="alert alert-danger"><?= $error ?></div>
            <?php endif; ?>
            <form method="POST">
                <input type="hidden" name="action" value="login">
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Login</button>
            </form>
        </div>
        
        <!-- Register Form -->
        <div class="auth-card">
            <h2>Daftar Akun</h2>
            <?php if ($error && $_POST['action'] === 'register'): ?>
                <div class="alert alert-danger"><?= $error ?></div>
            <?php endif; ?>
            <?php if ($register_success): ?>
                <div class="alert alert-success"><?= $register_success ?></div>
            <?php endif; ?>
            <form method="POST">
                <input type="hidden" name="action" value="register">
                <div class="form-group">
                    <label>Nama Lengkap</label>
                    <input type="text" name="name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>No. HP</label>
                    <input type="text" name="phone" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Alamat</label>
                    <textarea name="address" class="form-control" rows="3" required></textarea>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-secondary w-100">Daftar</button>
            </form>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
</body>
</html>
