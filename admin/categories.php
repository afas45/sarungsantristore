<?php
/**
 * Kasir Ibtidaiyah - Manajemen Kategori (Flat/Tunggal)
 */
require_once __DIR__ . '/../config/app.php';
requireRole(['Pemilik', 'Admin']);

$db = Database::conn();
$pageTitle = 'Kategori';
$breadcrumbs = [['label' => 'Kategori']];

// =============================================
// HANDLE ACTIONS
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $name = sanitize($_POST['name'] ?? '');
        $slug = createSlug($name);
        
        if (empty($name)) {
            flashMessage('error', 'Nama kategori harus diisi.');
        } else {
            $online = isset($_POST['online_visibility']) ? 1 : 0;
            $sort_order = isset($_POST['sort_order']) ? (int)$_POST['sort_order'] : 0;
            $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
            $icon = null;
            if (isset($_FILES['icon']) && $_FILES['icon']['error'] === UPLOAD_ERR_OK) {
                $upload = uploadImage($_FILES['icon'], 'categories');
                if ($upload['success']) {
                    $icon = $upload['path'];
                }
            }
            $stmt = $db->prepare("INSERT INTO categories (name, slug, parent_id, online_visibility, sort_order, icon) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $slug, $parent_id, $online, $sort_order, $icon]);
            flashMessage('success', 'Kategori berhasil ditambahkan.');
            logActivity('Tambah', 'Kategori', 'Kategori: ' . $name);
        }
    }
    
    if ($action === 'edit') {
        $id = (int)$_POST['id'];
        $name = sanitize($_POST['name'] ?? '');
        $slug = createSlug($name);
        
        if (empty($name)) {
            flashMessage('error', 'Nama kategori harus diisi.');
        } else {
            $online = isset($_POST['online_visibility']) ? 1 : 0;
            $sort_order = isset($_POST['sort_order']) ? (int)$_POST['sort_order'] : 0;
            $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
            
            // Prevent setting itself as parent
            if ($parent_id === $id) $parent_id = null;
            
            $iconSql = "";
            $params = [$name, $slug, $parent_id, $online, $sort_order];
            
            if (isset($_FILES['icon']) && $_FILES['icon']['error'] === UPLOAD_ERR_OK) {
                $upload = uploadImage($_FILES['icon'], 'categories');
                if ($upload['success']) {
                    $icon = $upload['path'];
                    $iconSql = ", icon = ?";
                    $params[] = $icon;
                }
            }
            $params[] = $id;
            
            $stmt = $db->prepare("UPDATE categories SET name = ?, slug = ?, parent_id = ?, online_visibility = ?, sort_order = ? $iconSql WHERE id = ?");
            $stmt->execute($params);
            flashMessage('success', 'Kategori berhasil diperbarui.');
            logActivity('Edit', 'Kategori', 'Kategori ID: ' . $id);
        }
    }
    
    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        // Update products referencing this category
        $db->prepare("UPDATE products SET category_id = NULL WHERE category_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM categories WHERE id = ?")->execute([$id]);
        flashMessage('success', 'Kategori berhasil dihapus.');
        logActivity('Hapus', 'Kategori', 'Kategori ID: ' . $id);
    }
    
    redirect(BASE_URL . '/admin/categories.php');
}

// =============================================
// FETCH DATA
// =============================================
$stmt = $db->query("
    SELECT c.*, p.name as parent_name,
    (SELECT COUNT(*) FROM products WHERE category_id = c.id) as product_count
    FROM categories c 
    LEFT JOIN categories p ON c.parent_id = p.id
    ORDER BY COALESCE(c.parent_id, c.id) ASC, (c.parent_id IS NOT NULL) ASC, c.id ASC
");
$categories = $stmt->fetchAll();

$stmtParent = $db->query("SELECT id, name FROM categories WHERE parent_id IS NULL ORDER BY name");
$parentCategories = $stmtParent->fetchAll();

include INCLUDES_PATH . '/header.php';
?>

<!-- Toolbar -->
<div class="toolbar">
    <form method="GET" style="display:flex; gap:8px; align-items:center; flex:1;">
        <div class="search-box" style="flex:1; max-width:400px; margin-right:8px;">
            <svg class="search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input type="text" class="form-control" placeholder="Cari kategori..." id="searchCategory" oninput="filterTable(this.value)">
        </div>
    </form>
    <div class="filter-group">
        <button class="btn btn-primary" onclick="openModal('modalCategory')">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            Tambah Kategori
        </button>
        
        <?php 
        $importType = 'categories';
        $hasImport = true;
        $hasExport = true;
        $hasTemplate = true;
        include INCLUDES_PATH . '/import_export_toolbar.php'; 
        ?>
    </div>
</div>

<!-- Data Table -->
<div class="card">
    <div class="card-body" style="padding: 0;">
        <div class="table-responsive">
            <table class="table" id="categoryTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Icon</th>
                        <th>Nama Kategori</th>
                        <th>Induk Kategori</th>
                        <th>Jumlah Produk</th>
                        <th>Online</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($categories)): ?>
                        <tr><td colspan="7" class="text-center text-muted" style="padding:40px;">Belum ada kategori</td></tr>
                    <?php else: ?>
                        <?php foreach ($categories as $idx => $cat): ?>
                            <tr data-name="<?= strtolower($cat['name']) ?>" style="<?= $cat['parent_id'] ? 'background:#fafafa;' : '' ?>">
                                <td><?= $cat['id'] ?></td>
                                <td>
                                    <?php if ($cat['icon']): ?>
                                        <img src="<?= BASE_URL ?>/assets/uploads/categories/<?= htmlspecialchars($cat['icon']) ?>" alt="Icon" style="width:40px; height:40px; object-fit:cover; border-radius:4px; border:1px solid #ddd;">
                                    <?php else: ?>
                                        <div style="width:40px; height:40px; background:#f0f0f0; border-radius:4px; display:flex; align-items:center; justify-content:center; color:#999; font-size:12px;">-</div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($cat['parent_id']): ?>
                                        <span style="display:inline-flex; align-items:center; gap:6px; padding-left:16px;">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#aaa" stroke-width="2" style="flex-shrink:0;"><polyline points="9 18 15 12 9 6" transform="rotate(90,12,12)"/></svg>
                                            <span class="text-bold" style="color:var(--gray-700);"><?= htmlspecialchars($cat['name']) ?></span>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-bold"><?= htmlspecialchars($cat['name']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($cat['parent_id']): ?>
                                        <span class="badge badge-outline"><?= htmlspecialchars($cat['parent_name']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-primary"><?= $cat['product_count'] ?></span>
                                </td>
                                <td>
                                    <?= $cat['online_visibility'] ? '<span class="badge badge-success">Ya</span>' : '<span class="badge badge-warning">Tidak</span>' ?>
                                </td>
                                <td>
                                    <div class="actions">
                                        <button class="btn btn-sm btn-outline btn-icon" title="Edit"
                                            onclick="editCategory(<?= $cat['id'] ?>, '<?= htmlspecialchars($cat['name'], ENT_QUOTES) ?>', <?= $cat['parent_id'] ?: 'null' ?>, <?= $cat['online_visibility'] ?? 1 ?>, <?= $cat['sort_order'] ?? 0 ?>)">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                            </svg>
                                        </button>
                                        <form method="POST" style="display:inline" onsubmit="return confirm('Yakin hapus kategori ini?')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline btn-icon" title="Hapus" style="color: var(--danger);">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                                                </svg>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Tambah/Edit Kategori -->
<div class="modal-overlay" id="modalCategory">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title" id="modalCategoryTitle">Tambah Kategori</h3>
            <button class="modal-close" onclick="closeModal('modalCategory')">&times;</button>
        </div>
        <form method="POST" id="categoryForm" enctype="multipart/form-data">
            <input type="hidden" name="action" id="categoryAction" value="add">
            <input type="hidden" name="id" id="categoryId">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Nama Kategori <span class="required">*</span></label>
                    <input type="text" name="name" id="categoryName" class="form-control" placeholder="Nama kategori" required>
                </div>
                <div class="form-group" style="margin-top:10px;">
                    <label class="form-label">Kategori Induk (Opsional)</label>
                    <select name="parent_id" id="categoryParent" class="form-control">
                        <option value="">-- Jadikan Kategori Utama --</option>
                        <?php foreach ($parentCategories as $pCat): ?>
                            <option value="<?= $pCat['id'] ?>"><?= htmlspecialchars($pCat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-top:10px;">
                    <label class="form-label">Urutan</label>
                    <input type="number" name="sort_order" id="categorySortOrder" class="form-control" value="0">
                </div>
                <div class="form-group" style="margin-top:10px;">
                    <label class="form-label">Icon (Foto/PNG)</label>
                    <input type="file" name="icon" id="categoryIcon" class="form-control" accept="image/*">
                </div>
                <div class="form-group" style="margin-top:10px;">
                    <label class="form-label" style="display:flex; align-items:center; gap:8px;">
                        <input type="checkbox" name="online_visibility" id="categoryOnline" value="1" checked>
                        Tampil di Online
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('modalCategory')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>

<script>
function editCategory(id, name, parent_id, online, sort_order) {
    document.getElementById('modalCategoryTitle').textContent = 'Edit Kategori';
    document.getElementById('categoryAction').value = 'edit';
    document.getElementById('categoryId').value = id;
    document.getElementById('categoryName').value = name;
    document.getElementById('categoryParent').value = parent_id || '';
    document.getElementById('categorySortOrder').value = sort_order;
    document.getElementById('categoryOnline').checked = online == 1;
    openModal('modalCategory');
}

// Reset form when opening for new category
document.querySelector('[onclick="openModal(\'modalCategory\')"]').addEventListener('click', function() {
    document.getElementById('modalCategoryTitle').textContent = 'Tambah Kategori';
    document.getElementById('categoryAction').value = 'add';
    document.getElementById('categoryId').value = '';
    document.getElementById('categoryName').value = '';
    document.getElementById('categoryParent').value = '';
    document.getElementById('categorySortOrder').value = '0';
    document.getElementById('categoryIcon').value = '';
    document.getElementById('categoryOnline').checked = true;
});

function filterTable(query) {
    const rows = document.querySelectorAll('#categoryTable tbody tr[data-name]');
    query = query.toLowerCase();
    rows.forEach(row => {
        const name = row.getAttribute('data-name');
        row.style.display = name.includes(query) ? '' : 'none';
    });
}
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
