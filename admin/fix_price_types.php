<?php
/**
 * Helper: Hapus tipe harga & lihat daftar
 * Akses sekali via browser: /admin/fix_price_types.php
 * DELETE FILE INI setelah selesai!
 */
require_once __DIR__ . '/../config/app.php';
requireRole(['Pemilik', 'Admin']);
$db = Database::conn();

$deleted = [];
$msg = '';

// Hapus "Eceran" dan "Shopee" / "Shoppe" (case-insensitive)
if (isset($_GET['do_delete'])) {
    $toDelete = ['Eceran', 'Shopee', 'Shoppe', 'Ecer']; // nama-nama yang mau dihapus
    foreach ($toDelete as $name) {
        // Jangan hapus id 1 atau 2 (bawaan sistem)
        $stmt = $db->prepare("SELECT id, name FROM price_types WHERE name = ? AND id NOT IN (1,2)");
        $stmt->execute([$name]);
        $pt = $stmt->fetch();
        if ($pt) {
            $db->prepare("UPDATE customers SET price_type_id = 1 WHERE price_type_id = ?")->execute([$pt['id']]);
            $db->prepare("DELETE FROM product_prices WHERE price_type_id = ?")->execute([$pt['id']]);
            $db->prepare("DELETE FROM tiered_prices WHERE price_type_id = ?")->execute([$pt['id']]);
            $db->prepare("DELETE FROM price_types WHERE id = ?")->execute([$pt['id']]);
            $deleted[] = $pt['name'] . ' (ID: ' . $pt['id'] . ')';
        }
    }
    $msg = empty($deleted) ? 'Tidak ada yang dihapus (tidak ditemukan / dilindungi).' : 'Berhasil dihapus: ' . implode(', ', $deleted);
}

$rows = $db->query("SELECT id, name FROM price_types ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html><html><head><meta charset="utf-8"><title>Fix Price Types</title>
<style>body{font-family:sans-serif;padding:30px;max-width:600px;margin:auto;}
table{width:100%;border-collapse:collapse;margin:16px 0;}
th,td{border:1px solid #ddd;padding:8px 12px;text-align:left;}
th{background:#f5f5f5;} .msg{background:#d4edda;padding:12px;border-radius:6px;margin-bottom:16px;}
.btn{display:inline-block;padding:10px 20px;background:#c0392b;color:#fff;text-decoration:none;border-radius:6px;}
.warn{background:#fff3cd;padding:12px;border-radius:6px;margin-bottom:16px;font-size:0.9rem;}
</style></head><body>
<h2>🏷️ Tipe Harga Saat Ini</h2>

<?php if ($msg): ?><div class="msg"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div class="warn">⚠️ Script ini akan menghapus tipe harga "Eceran" dan "Shopee" dari database beserta semua harga produk terkait. <b>Hapus file ini setelah selesai!</b></div>

<table>
<tr><th>ID</th><th>Nama</th></tr>
<?php foreach ($rows as $r): ?>
<tr><td><?= $r['id'] ?></td><td><?= htmlspecialchars($r['name']) ?></td></tr>
<?php endforeach; ?>
</table>

<?php if (!isset($_GET['do_delete'])): ?>
<a href="?do_delete=1" class="btn" onclick="return confirm('Yakin hapus Eceran dan Shopee/Shoppe?')">🗑️ Hapus Eceran & Shopee sekarang</a>
<?php else: ?>
<p><a href="/admin/import_prices.php">← Kembali ke Import Harga</a> | <b>Segera hapus file fix_price_types.php ini!</b></p>
<?php endif; ?>
</body></html>
