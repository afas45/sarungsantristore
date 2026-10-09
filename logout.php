<?php
/**
 * Kasir Ibtidaiyah - Logout
 */
require_once __DIR__ . '/config/app.php';

logout();
redirect(BASE_URL . '/login.php');
