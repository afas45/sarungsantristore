<?php
/**
 * Kasir Ibtidaiyah - API Wilayah Indonesia
 * Mengambil data Provinsi, Kota, Kecamatan, Kelurahan dari database lokal.
 *
 * Struktur tabel yang diharapkan:
 *   t_provinsi  : id_prov, nama_prov
 *   t_kota      : id_kota, id_prov, nama_kota
 *   t_kecamatan : id_kec,  id_kota, nama_kec
 *   t_kelurahan : id_kel,  id_kec,  nama_kel
 *
 * Cara pakai (GET):
 *   ?type=provinsi
 *   ?type=kota&id=<id_prov>
 *   ?type=kecamatan&id=<id_kota>
 *   ?type=kelurahan&id=<id_kec>
 */

require_once __DIR__ . '/../config/app.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: max-age=3600'); // Cache 1 jam di browser

$db   = Database::conn();
$type = trim($_GET['type'] ?? '');
$id   = trim($_GET['id']   ?? '');

try {
    switch ($type) {

        // ── PROVINSI ──────────────────────────────────────────────
        case 'provinsi':
            $stmt = $db->query(
                "SELECT id_prov AS id, nama_prov AS nama
                   FROM t_provinsi
                  ORDER BY nama_prov ASC"
            );
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $rows]);
            break;

        // ── KOTA / KABUPATEN ──────────────────────────────────────
        case 'kota':
            if (empty($id)) {
                echo json_encode(['success' => false, 'message' => 'Parameter id (id_prov) wajib diisi.']);
                break;
            }
            $stmt = $db->prepare(
                "SELECT id_kota AS id, nama_kota AS nama
                   FROM t_kota
                  WHERE id_prov = ?
                  ORDER BY nama_kota ASC"
            );
            $stmt->execute([$id]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $rows]);
            break;

        // ── KECAMATAN ─────────────────────────────────────────────
        case 'kecamatan':
            if (empty($id)) {
                echo json_encode(['success' => false, 'message' => 'Parameter id (id_kota) wajib diisi.']);
                break;
            }
            $stmt = $db->prepare(
                "SELECT id_kec AS id, nama_kec AS nama
                   FROM t_kecamatan
                  WHERE id_kota = ?
                  ORDER BY nama_kec ASC"
            );
            $stmt->execute([$id]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $rows]);
            break;

        // ── KELURAHAN / DESA ──────────────────────────────────────
        case 'kelurahan':
            if (empty($id)) {
                echo json_encode(['success' => false, 'message' => 'Parameter id (id_kec) wajib diisi.']);
                break;
            }
            $stmt = $db->prepare(
                "SELECT id_kel AS id, nama_kel AS nama
                   FROM t_kelurahan
                  WHERE id_kec = ?
                  ORDER BY nama_kel ASC"
            );
            $stmt->execute([$id]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $rows]);
            break;

        // ── TIDAK DIKENAL ─────────────────────────────────────────
        default:
            echo json_encode([
                'success' => false,
                'message' => 'Parameter type tidak valid. Gunakan: provinsi, kota, kecamatan, atau kelurahan.'
            ]);
            break;
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan database: ' . $e->getMessage()
    ]);
}
