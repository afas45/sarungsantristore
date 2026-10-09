<?php
/**
 * Kasir Ibtidaiyah - Database Configuration
 * Koneksi PDO MySQL dengan Singleton Pattern
 */

class Database {
    private static $instance = null;
    private $pdo;

    // Konfigurasi Database
    private $host = 'localhost';
    private $dbname = 'nqditksf_sss';
    private $username = 'nqditksf_afas';
    private $password = 'Fatoni1455!';
    private $charset = 'utf8mb4';

    private function __construct() {
        try {
            $dsn = "mysql:host={$this->host};dbname={$this->dbname};charset={$this->charset}";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$this->charset}",
            ];
            $this->pdo = new PDO($dsn, $this->username, $this->password, $options);
        } catch (PDOException $e) {
            die('<div style="font-family:sans-serif;padding:40px;text-align:center;">
                <h2 style="color:#dc3545;">Koneksi Database Gagal</h2>
                <p>Pastikan MySQL sudah berjalan dan database <strong>' . $this->dbname . '</strong> sudah dibuat.</p>
                <p style="color:#666;font-size:13px;">Error: ' . $e->getMessage() . '</p>
            </div>');
        }
    }

    /**
     * Mendapatkan instance koneksi (Singleton)
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Mendapatkan objek PDO
     */
    public function getConnection() {
        return $this->pdo;
    }

    /**
     * Shortcut: Mendapatkan PDO langsung
     */
    public static function conn() {
        return self::getInstance()->getConnection();
    }

    // Cegah cloning & unserialization
    private function __clone() {}
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}
