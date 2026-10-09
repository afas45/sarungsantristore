<?php
/**
 * Kasir Ibtidaiyah - Authentication & RBAC
 */

/**
 * Login pengguna
 */
function login($username, $password) {
    $db = Database::conn();
    
    // 1. Coba cari di tabel users (staff)
    $stmt = $db->prepare("
        SELECT u.*, r.role_name, c.id AS customer_id, pt.name AS price_type_name
        FROM users u 
        JOIN roles r ON u.role_id = r.id 
        LEFT JOIN customers c ON c.user_id = u.id
        LEFT JOIN price_types pt ON c.price_type_id = pt.id
        WHERE (u.username = ? OR u.email = ? OR u.phone = ?) AND u.is_active = 1
    ");
    $stmt->execute([$username, $username, $username]);
    $user = $stmt->fetch();
    
    if ($user) {
        if (!password_verify($password, $user['password_hash'])) {
            return ['success' => false, 'message' => 'Password salah.'];
        }
        
        // Prevent Session Fixation
        session_regenerate_id(true);
        
        // Set session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role_id'] = $user['role_id'];
        $_SESSION['role_name'] = $user['role_name'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['avatar'] = $user['avatar'];
        $_SESSION['login_time'] = time();
        if ($user['customer_id']) {
            $_SESSION['customer_id'] = $user['customer_id'];
            $_SESSION['price_type_name'] = $user['price_type_name'] ?: 'Umum';
        } else {
            $_SESSION['price_type_name'] = 'Umum';
        }
        
        // Update last login
        $stmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $stmt->execute([$user['id']]);
        
        logActivity('Login', 'Auth', 'Login berhasil: ' . $user['username']);
        
        return ['success' => true, 'user' => $user];
    }
    
    // 2. Coba cari di tabel customers (pelanggan)
    $stmt = $db->prepare("
        SELECT c.*, pt.name as price_type_name
        FROM customers c
        LEFT JOIN price_types pt ON c.price_type_id = pt.id
        WHERE (c.email = ? OR c.phone = ?) AND c.password IS NOT NULL
    ");
    $stmt->execute([$username, $username]);
    $customer = $stmt->fetch();
    
    if ($customer) {
        if (!password_verify($password, $customer['password'])) {
            return ['success' => false, 'message' => 'Password salah.'];
        }
        
        // Prevent Session Fixation
        session_regenerate_id(true);
        
        // Set session Pelanggan
        $_SESSION['customer_id'] = $customer['id'];
        $_SESSION['role_id'] = ROLE_PELANGGAN;
        $_SESSION['role_name'] = 'Pelanggan';
        $_SESSION['price_type_name'] = $customer['price_type_name'] ?: 'Umum';
        $_SESSION['username'] = $customer['email'] ?: $customer['phone'];
        $_SESSION['full_name'] = $customer['name'];
        $_SESSION['login_time'] = time();
        
        logActivity('Login', 'Auth', 'Pelanggan login berhasil: ' . $customer['name']);
        
        return ['success' => true, 'user' => [
            'id' => 'c_' . $customer['id'],
            'role_id' => ROLE_PELANGGAN,
            'role_name' => 'Pelanggan',
            'full_name' => $customer['name']
        ]];
    }
    
    return ['success' => false, 'message' => 'Username/Email tidak ditemukan atau akun nonaktif.'];
}

/**
 * Logout pengguna
 */
function logout() {
    if (isset($_SESSION['user_id'])) {
        logActivity('Logout', 'Auth', 'Logout: ' . ($_SESSION['username'] ?? ''));
    }
    
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}

/**
 * Cek apakah user sudah login
 */
function isLoggedIn() {
    return (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) || (isset($_SESSION['customer_id']) && !empty($_SESSION['customer_id']));
}

/**
 * Ambil data user saat ini dari session
 */
function getCurrentUser() {
    if (!isLoggedIn()) return null;
    
    if (isset($_SESSION['customer_id'])) {
        return [
            'id' => 'c_' . $_SESSION['customer_id'],
            'is_customer' => true,
            'role_id' => ROLE_PELANGGAN,
            'role_name' => 'Ranting',
            'username' => $_SESSION['username'] ?? '',
            'full_name' => $_SESSION['full_name'] ?? 'Ranting',
            'avatar' => null,
        ];
    }
    
    return [
        'id' => $_SESSION['user_id'],
        'role_id' => $_SESSION['role_id'],
        'role_name' => $_SESSION['role_name'],
        'username' => $_SESSION['username'],
        'full_name' => $_SESSION['full_name'],
        'avatar' => $_SESSION['avatar'] ?? null,
    ];
}

/**
 * Cek apakah user memiliki role tertentu
 * @param string|array $roles Nama role atau array nama role
 */
function hasRole($roles) {
    if (!isLoggedIn()) return false;
    
    // Pemilik always has access
    if (isOwner()) return true;
    
    // Map current script to permission key
    $currentPage = basename($_SERVER['SCRIPT_NAME']);
    $currentDir = basename(dirname($_SERVER['SCRIPT_NAME']));
    
    $permission = '';
    
    if ($currentPage === 'index.php' && $currentDir === 'admin') {
        $permission = 'menu_dashboard';
    } elseif ($currentDir === 'pos' || ($currentPage === 'index.php' && $currentDir === 'pos')) {
        $permission = 'menu_pos';
    } elseif ($currentPage === 'users.php') {
        $permission = 'menu_pengguna';
    } elseif ($currentPage === 'customers.php') {
        $permission = 'menu_pelanggan';
    } elseif ($currentPage === 'settings.php') {
        $permission = 'menu_pengaturan';
    } elseif ($currentPage === 'sales_report.php' || $currentPage === 'print_sales_report.php') {
        $permission = 'menu_laporan';
    } elseif ($currentPage === 'cash_settlement.php' || $currentPage === 'print_settlement.php') {
        $permission = 'menu_setoran';
    } elseif ($currentPage === 'online_orders.php' || $currentPage === 'print_shipping_label.php') {
        $permission = 'menu_pesanan_online';
    } elseif ($currentPage === 'print_invoice.php') {
        return hasPermission('action_print_receipt') || hasPermission('menu_laporan');
    } elseif ($currentPage === 'products.php' || $currentPage === 'variations.php') {
        return hasPermission('menu_produk') || hasPermission('action_edit_prices') || hasPermission('action_view_stock');
    } elseif ($currentPage === 'prices.php') {
        $permission = 'menu_harga_produk';
    } elseif ($currentPage === 'stock_monitor.php') {
        $permission = 'menu_monitor_stok';
    } elseif ($currentPage === 'import_prices.php') {
        $permission = 'menu_impor_harga';
    } elseif ($currentPage === 'categories.php') {
        $permission = 'menu_kategori';
    } elseif ($currentPage === 'suppliers.php') {
        $permission = 'menu_supplier';
    } elseif ($currentPage === 'purchases.php' || $currentPage === 'print_po_receipt.php' || $currentPage === 'api_purchase_details.php') {
        $permission = 'menu_pembelian';
    } elseif ($currentPage === 'delivery_notes.php' || $currentPage === 'print_dn.php') {
        $permission = 'menu_surat_jalan';
    } elseif ($currentPage === 'stock_opname.php' || $currentPage === 'print_opname.php') {
        $permission = 'menu_opname';
    } elseif ($currentPage === 'import.php') {
        $type = $_GET['type'] ?? $_POST['type'] ?? '';
        switch ($type) {
            case 'categories': $permission = 'menu_kategori'; break;
            case 'customers': $permission = 'menu_pelanggan'; break;
            case 'suppliers': $permission = 'menu_supplier'; break;
            case 'purchases': $permission = 'menu_pembelian'; break;
            case 'opname': $permission = 'menu_opname'; break;
            case 'users': $permission = 'menu_pengguna'; break;
            default: $permission = 'menu_produk'; break;
        }
    } elseif ($currentPage === 'receivables.php' || $currentPage === 'customer_transactions.php' || $currentPage === 'receivable_details.php') {
        $permission = 'menu_piutang';
    } elseif ($currentPage === 'payables.php' || $currentPage === 'payable_details.php') {
        $permission = 'menu_hutang';
    } elseif ($currentPage === 'cash_flow.php') {
        $permission = 'menu_arus_kas';
    }
    
    if ($permission) {
        return hasPermission($permission);
    }
    
    // Fallback to default check
    if (is_string($roles)) {
        $roles = [$roles];
    }
    
    $user = getCurrentUser();
    $roleName = $user['role_name'] ?? '';
    return in_array($roleName, $roles);
}

/**
 * Check if the current user has a specific permission
 */
function hasPermission($permission) {
    if (!isLoggedIn()) return false;
    
    // Pemilik has ALL permissions
    if (isOwner()) return true;
    
    $user = getCurrentUser();
    $roleId = $user['role_id'] ?? null;
    if (!$roleId) return false;
    
    static $userPermsCache = [];
    if (!isset($userPermsCache[$roleId])) {
        $db = Database::conn();
        try {
            $stmt = $db->prepare("SELECT permission_key FROM role_permissions WHERE role_id = ?");
            $stmt->execute([$roleId]);
            $userPermsCache[$roleId] = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            $userPermsCache[$roleId] = [];
        }
    }
    
    return in_array($permission, $userPermsCache[$roleId]);
}

/**
 * Middleware: Wajib memiliki hak akses tertentu
 */
function requirePermission($permission) {
    requireLogin();
    
    if (!hasPermission($permission)) {
        flashMessage('error', 'Anda tidak memiliki akses ke fitur ini.');
        
        // Ambil halaman fallback berdasarkan role — hindari redirect ke halaman yang sama
        $currentUri = $_SERVER['REQUEST_URI'] ?? '';
        switch ($_SESSION['role_id']) {
            case ROLE_PEMILIK:
            case ROLE_ADMIN:
                $target = BASE_URL . '/admin/index.php';
                break;
            case ROLE_KASIR:
                $target = BASE_URL . '/pos/index.php';
                break;
            case ROLE_PELANGGAN:
                $target = BASE_URL . '/shop/index.php';
                break;
            default:
                $target = BASE_URL . '/login.php';
        }
        // Safeguard: jangan redirect ke halaman yang sama (infinite loop)
        $targetPath = parse_url($target, PHP_URL_PATH);
        $currentPath = parse_url($currentUri, PHP_URL_PATH);
        if ($targetPath === $currentPath) {
            // Jika sudah di halaman tujuan, logout untuk memutus sesi bermasalah
            logout();
            redirect(BASE_URL . '/login.php?error=no_permission');
        }
        redirect($target);
    }
}

/**
 * Cek apakah user memiliki role_id tertentu
 * @param int|array $roleIds ID role atau array ID role
 */
function hasRoleId($roleIds) {
    if (!isLoggedIn()) return false;
    
    if (is_int($roleIds)) {
        $roleIds = [$roleIds];
    }
    
    $user = getCurrentUser();
    return in_array($user['role_id'] ?? null, $roleIds);
}

/**
 * Middleware: Wajib login, redirect ke login jika belum
 */
function requireLogin() {
    if (!isLoggedIn()) {
        flashMessage('warning', 'Silakan login terlebih dahulu.');
        redirect(BASE_URL . '/login.php');
    }
}

/**
 * Middleware: Wajib role tertentu
 * @param array $allowedRoles Array nama role yang diizinkan
 */
function requireRole($allowedRoles) {
    requireLogin();
    
    if (!hasRole($allowedRoles)) {
        flashMessage('error', 'Anda tidak memiliki akses ke halaman ini.');
        
        // Ambil halaman fallback berdasarkan role — hindari redirect ke halaman yang sama
        $currentUri = $_SERVER['REQUEST_URI'] ?? '';
        switch ($_SESSION['role_id']) {
            case ROLE_PEMILIK:
            case ROLE_ADMIN:
                $target = BASE_URL . '/admin/index.php';
                break;
            case ROLE_KASIR:
                $target = BASE_URL . '/pos/index.php';
                break;
            case ROLE_PELANGGAN:
                $target = BASE_URL . '/shop/index.php';
                break;
            default:
                $target = BASE_URL . '/login.php';
        }
        // Safeguard: jangan redirect ke halaman yang sama (infinite loop)
        $targetPath = parse_url($target, PHP_URL_PATH);
        $currentPath = parse_url($currentUri, PHP_URL_PATH);
        if ($targetPath === $currentPath) {
            // Jika sudah di halaman tujuan, logout untuk memutus sesi bermasalah
            logout();
            redirect(BASE_URL . '/login.php?error=no_permission');
        }
        redirect($target);
    }
}

/**
 * Redirect user setelah login berdasarkan role
 */
function redirectAfterLogin() {
    switch ($_SESSION['role_id']) {
        case ROLE_PEMILIK:
        case ROLE_ADMIN:
            redirect(BASE_URL . '/admin/index.php');
            break;
        case ROLE_KASIR:
            redirect(BASE_URL . '/pos/index.php');
            break;
        case ROLE_PELANGGAN:
            redirect(BASE_URL . '/shop/index.php');
            break;
        default:
            redirect(BASE_URL . '/login.php');
    }
}

/**
 * Hash password dengan bcrypt
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
}

/**
 * Cek apakah user pemilik
 */
function isOwner() {
    return hasRoleId(ROLE_PEMILIK);
}

/**
 * Cek apakah user admin atau pemilik
 */
function isAdminOrOwner() {
    return hasRoleId([ROLE_PEMILIK, ROLE_ADMIN]);
}

/**
 * Cek apakah user kasir
 */
function isCashier() {
    return hasRoleId(ROLE_KASIR);
}

/**
 * Cek apakah user pelanggan
 */
function isCustomer() {
    return hasRoleId(ROLE_PELANGGAN);
}
