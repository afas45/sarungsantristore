<?php
/**
 * Kasir Ibtidaiyah - Router / Index
 * Redirect berdasarkan status login & role
 */
require_once __DIR__ . '/config/app.php';

if (isLoggedIn()) {
    redirectAfterLogin();
} else {
    // Tampilkan halaman toko online sebagai default untuk pengunjung
    // atau redirect ke login
    redirect(BASE_URL . '/login.php');
}
