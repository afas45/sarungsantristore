<?php
/**
 * Kasir Ibtidaiyah - Application Configuration
 */

// =============================================
// HTTP SECURITY HEADERS
// =============================================
header("X-Frame-Options: SAMEORIGIN"); // Prevent Clickjacking
header("X-Content-Type-Options: nosniff"); // Prevent MIME sniffing
header("X-XSS-Protection: 1; mode=block"); // Enforce XSS protection

// =============================================
// ENVIRONMENT & ERROR REPORTING
// =============================================
define('APP_ENV', 'production'); // Ubah ke 'production' saat sudah di hosting live

if (APP_ENV === 'production') {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(0);
} else {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
}

// =============================================
// TIMEZONE & LOCALE
// =============================================
date_default_timezone_set('Asia/Jakarta');
setlocale(LC_ALL, 'id_ID.UTF-8', 'id_ID', 'Indonesian');

// =============================================
// SESSION (start jika belum aktif)
// =============================================
if (session_status() === PHP_SESSION_NONE) {
    // Set durasi session menjadi 24 jam (86400 detik)
    ini_set('session.gc_maxlifetime', 86400);
    
    // Set secure cookie params (HttpOnly and SameSite=Lax for CSRF protection)
    $cookieParams = session_get_cookie_params();
    $isSecure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || (APP_ENV === 'production');
    
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 86400,
            'path' => $cookieParams['path'],
            'domain' => $cookieParams['domain'],
            'secure' => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    } else {
        session_set_cookie_params(86400, $cookieParams['path'] . '; samesite=Lax', $cookieParams['domain'], $isSecure, true);
    }
    
    session_start();
}

// =============================================
// APPLICATION CONSTANTS
// =============================================
define('APP_NAME', 'SarungSantriStore');
define('APP_VERSION', '1.0.12');
define('APP_TAGLINE', 'Pusat Sarung & Fashion Santri');

// Auto-detect base URL (localhost & hosting compatible)
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
// Auto-detect subdirectory (e.g. /kasiribtidaiyah) from the project root
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$rootDir = str_replace('\\', '/', dirname(__DIR__));
$docRoot = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? '');
$basePath = '/' . trim(str_replace($docRoot, '', $rootDir), '/');
if ($basePath === '/') $basePath = '';
define('BASE_URL', $protocol . '://' . $host . $basePath);
define('ASSETS_URL', BASE_URL . '/assets');
// =============================================
// FILE PATHS
// =============================================
define('ROOT_PATH', dirname(__DIR__));
define('CONFIG_PATH', ROOT_PATH . '/config');
define('INCLUDES_PATH', ROOT_PATH . '/includes');
define('ASSETS_PATH', ROOT_PATH . '/assets');
define('UPLOADS_PATH', ROOT_PATH . '/uploads');

// =============================================
// UPLOAD SETTINGS
// =============================================
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024); // 10MB
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/webp', 'image/gif']);

// =============================================
// PAGINATION
// =============================================
define('ITEMS_PER_PAGE', 10);

// =============================================
// ROLES CONSTANTS
// =============================================
define('ROLE_PEMILIK', 1);
define('ROLE_ADMIN', 2);
define('ROLE_KASIR', 3);
define('ROLE_PELANGGAN', 4);

// =============================================
// INCLUDE CORE FILES
// =============================================
require_once CONFIG_PATH . '/database.php';
require_once INCLUDES_PATH . '/helpers.php';
require_once INCLUDES_PATH . '/auth.php';
