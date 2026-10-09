<?php
/**
 * Kasir Ibtidaiyah - Import/Export Toolbar Component
 * 
 * Variabel yang dibutuhkan sebelum include:
 * @var string $importType (Contoh: 'products', 'categories', 'customers', dsb.)
 * @var bool $hasImport
 * @var bool $hasExport
 * @var bool $hasTemplate
 */
$hasImport = $hasImport ?? false;
$hasExport = $hasExport ?? false;
$hasTemplate = $hasTemplate ?? false;
$importType = $importType ?? '';
?>
    <!-- Template dipindahkan ke dalam Modal Import -->
    <?php if ($hasExport): ?>
        <!-- Form untuk export -->
        <form method="GET" action="<?= BASE_URL ?>/api/import.php" style="display: inline-block;" id="exportForm_<?= $importType ?>">
            <input type="hidden" name="action" value="export_csv">
            <input type="hidden" name="type" value="<?= $importType ?>">
            <?php if (isset($_GET['product_id'])): ?>
            <input type="hidden" name="product_id" value="<?= (int)$_GET['product_id'] ?>">
            <?php endif; ?>
            <button type="submit" class="btn btn-outline btn-sm btn-icon" title="Export CSV" style="color: darkred; border-color: darkred;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                <span class="btn-text-mobile-only" style="display: none;">Export</span>
            </button>
        </form>
    <?php endif; ?>

    <?php if ($hasImport): ?>
        <button class="btn btn-outline btn-sm btn-icon" onclick="openModal('modalImport_<?= $importType ?>')" title="Import CSV" style="color: darkgreen; border-color: darkgreen;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            <span class="btn-text-mobile-only" style="display: none;">Import</span>
        </button>

        <!-- Modal Import -->
        <div class="modal-overlay" id="modalImport_<?= $importType ?>">
            <div class="modal">
                <div class="modal-header">
                    <h3 class="modal-title">Import Data <?= ucfirst($importType) ?></h3>
                    <button type="button" class="modal-close" onclick="closeModal('modalImport_<?= $importType ?>')">&times;</button>
                </div>
                <form action="<?= BASE_URL ?>/api/import.php" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_csv">
                    <input type="hidden" name="type" value="<?= $importType ?>">
                    <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                    <?php if (isset($_GET['product_id'])): ?>
                    <input type="hidden" name="product_id" value="<?= (int)$_GET['product_id'] ?>">
                    <?php endif; ?>
                    <div class="modal-body">
                        <?php if ($hasTemplate): ?>
                        <div class="form-group mb-16" style="background:var(--gray-50); padding:12px; border-radius:6px; border:1px solid var(--border-color);">
                            <label class="form-label mb-8">Belum punya template?</label>
                            <a href="<?= BASE_URL ?>/api/import.php?action=download_template&type=<?= $importType ?><?= isset($_GET['product_id']) ? '&product_id=' . (int)$_GET['product_id'] : '' ?>" class="btn btn-outline btn-sm">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                Download Template CSV
                            </a>
                        </div>
                        <?php endif; ?>
                        <div class="form-group">
                            <label class="form-label">Pilih File CSV (.csv) <span class="required">*</span></label>
                            <input type="file" name="csv_file" class="form-control" accept=".csv" required>
                            <span class="form-hint" style="display:block; margin-top:8px;">Pastikan kolom sesuai dengan Template CSV.</span>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline" onclick="closeModal('modalImport_<?= $importType ?>')">Batal</button>
                        <button type="submit" class="btn btn-primary">Mulai Import</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
