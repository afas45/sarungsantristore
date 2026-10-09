<?php
/**
 * Kasir Ibtidaiyah - Helper Functions
 * Fungsi utilitas umum yang digunakan di seluruh aplikasi
 */

/**
 * Format angka ke mata uang Rupiah
 */
function formatRupiah($amount, $withSymbol = true) {
    $formatted = number_format((float)$amount, 0, ',', '.');
    return $withSymbol ? 'Rp ' . $formatted : $formatted;
}

/**
 * Format angka ke desimal standar
 */
function formatDecimal($amount) {
    return number_format((float)$amount, 2, ',', '.');
}

/**
 * Format angka ribuan biasa tanpa desimal/Rp
 */
function formatNumber($amount) {
    return number_format((float)$amount, 0, ',', '.');
}

/**
 * Generate nomor invoice unik

 * Format: INV-YYYYMMDD-XXXX
 */
function generateInvoiceNumber() {
    $db = Database::conn();
    $prefix = 'INV-' . date('Ymd') . '-';
    
    $stmt = $db->prepare("SELECT invoice_number FROM sales WHERE invoice_number LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    
    if ($last) {
        $lastNum = (int)substr($last, -4);
        $newNum = $lastNum + 1;
    } else {
        $newNum = 1;
    }
    
    return $prefix . str_pad($newNum, 4, '0', STR_PAD_LEFT);
}

/**
 * Generate nomor PO unik
 * Format: PO-YYYYMMDD-XXXX
 */
function generatePONumber() {
    $db = Database::conn();
    $prefix = 'PO-' . date('Ymd') . '-';
    
    $stmt = $db->prepare("SELECT po_number FROM purchases WHERE po_number LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    
    if ($last) {
        $lastNum = (int)substr($last, -4);
        $newNum = $lastNum + 1;
    } else {
        $newNum = 1;
    }
    
    return $prefix . str_pad($newNum, 4, '0', STR_PAD_LEFT);
}

/**
 * Generate nomor Retur unik
 * Format: RET-YYYYMMDD-XXXX
 */
function generateReturnNumber() {
    $db = Database::conn();
    $prefix = 'RET-' . date('Ymd') . '-';
    
    $stmt = $db->prepare("SELECT return_number FROM returns WHERE return_number LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    
    if ($last) {
        $lastNum = (int)substr($last, -4);
        $newNum = $lastNum + 1;
    } else {
        $newNum = 1;
    }
    
    return $prefix . str_pad($newNum, 4, '0', STR_PAD_LEFT);
}

/**
 * Generate nomor Opname unik
 * Format: OPN-YYYYMMDD-XXXX
 */
function generateOpnameNumber() {
    $db = Database::conn();
    $prefix = 'OPN-' . date('Ymd') . '-';
    
    $stmt = $db->prepare("SELECT opname_number FROM stock_opnames WHERE opname_number LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    
    if ($last) {
        $lastNum = (int)substr($last, -4);
        $newNum = $lastNum + 1;
    } else {
        $newNum = 1;
    }
    
    return $prefix . str_pad($newNum, 4, '0', STR_PAD_LEFT);
}

/**
 * Sanitasi input pengguna
 */
function sanitize($input) {
    if (is_array($input)) {
        return array_map('sanitize', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Format nomor telepon ke format +62
 */
function formatPhoneNumber($phone) {
    // Hapus semua karakter selain angka dan +
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    
    if (empty($phone)) return '';
    
    if (strpos($phone, '+') === 0) {
        $phone = substr($phone, 1);
    }

    if (substr($phone, 0, 1) === '0') {
        return '+62' . substr($phone, 1);
    }
    
    if (substr($phone, 0, 2) === '62') {
        return '+' . $phone;
    }
    
    return '+62' . $phone;
}

/**
 * Redirect ke URL
 */
function redirect($url) {
    header("Location: $url");
    exit;
}

/**
 * Set flash message ke session
 */
function flashMessage($type, $message) {
    $_SESSION['flash'] = [
        'type' => $type,    // success, error, warning, info
        'message' => $message
    ];
}

/**
 * Ambil & hapus flash message
 */
function getFlashMessage() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Render flash message sebagai HTML
 */
function renderFlashMessage() {
    $flash = getFlashMessage();
    if (!$flash) return '';
    
    $icons = [
        'success' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
        'error'   => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
        'warning' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        'info'    => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
    ];
    
    $icon = $icons[$flash['type']] ?? $icons['info'];
    
    return '<div class="alert alert-' . $flash['type'] . '" id="flashAlert">
        <span class="alert-icon">' . $icon . '</span>
        <span class="alert-message">' . $flash['message'] . '</span>
        <button type="button" class="alert-close" onclick="this.parentElement.remove()">&times;</button>
    </div>';
}

/**
 * Format waktu relatif (time ago)
 */
function timeAgo($datetime) {
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);
    
    if ($diff->y > 0) return $diff->y . ' tahun lalu';
    if ($diff->m > 0) return $diff->m . ' bulan lalu';
    if ($diff->d > 0) return $diff->d . ' hari lalu';
    if ($diff->h > 0) return $diff->h . ' jam lalu';
    if ($diff->i > 0) return $diff->i . ' menit lalu';
    return 'Baru saja';
}

/**
 * Format tanggal Indonesia
 */
function formatTanggal($date, $withTime = false) {
    $bulan = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];
    
    $d = new DateTime($date);
    $result = $d->format('d') . ' ' . $bulan[(int)$d->format('m')] . ' ' . $d->format('Y');
    
    if ($withTime) {
        $result .= ' ' . $d->format('H:i');
    }
    
    return $result;
}

/**
 * Generate slug dari teks
 */
function createSlug($text) {
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9-]/', '-', $text);
    $text = preg_replace('/-+/', '-', $text);
    return trim($text, '-');
}

/**
 * Cek apakah request adalah AJAX
 */
function isAjax() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/**
 * Kirim JSON response
 */
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Sinkronisasi format SKU Induk berdasarkan jumlah varian.
 * Jika varian > 0, suffix -{count}V akan ditambahkan ke SKU Induk.
 */
function syncParentSku($productId) {
    // Dipertahankan untuk kompatibilitas; SKU induk tidak lagi diubah otomatis.
    return true;
}

/**
 * Sinkronisasi stok produk dari jumlah stok semua varian aktif.
 * SKU induk tidak lagi diubah berdasarkan jumlah varian.
 */
function syncProductStock($productId) {
    return true;
}

/**
 * Get product_id from variation_id
 */
function getProductIdFromVariation($variationId) {
    $db = Database::conn();
    $stmt = $db->prepare("SELECT product_id FROM product_variations WHERE id = ?");
    $stmt->execute([$variationId]);
    return $stmt->fetchColumn();
}

/**
 * Upload file gambar (selalu dikonversi ke format WebP untuk kompresi maksimal)
 * 
 * Optimasi kompresi:
 * - Konversi otomatis ke WebP (30-50% lebih kecil dari JPEG, 70-80% lebih kecil dari PNG)
 * - Resize otomatis: max 800px untuk produk/varian, 1200px untuk settings/banner
 * - Kualitas WebP 72 (keseimbangan optimal ukuran vs kualitas visual)
 * - Strip metadata EXIF untuk ukuran lebih kecil
 */
function uploadImage($file, $directory = 'products') {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Gagal mengupload file.'];
    }
    
    if ($file['size'] > MAX_UPLOAD_SIZE) {
        return ['success' => false, 'message' => 'Ukuran file terlalu besar (maks 10MB).'];
    }
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mimeType, ALLOWED_IMAGE_TYPES)) {
        return ['success' => false, 'message' => 'Format file tidak diizinkan.'];
    }
    
    $uploadDir = UPLOADS_PATH . '/' . $directory;
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Tentukan ekstensi default untuk fallback
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
        'image/bmp'  => 'bmp'
    ];
    $ext = $extensions[$mimeType] ?? 'png';

    // Selalu simpan sebagai WebP jika GD mendukung WebP
    $isGdLoaded = extension_loaded('gd') && function_exists('imagecreatefromjpeg') && function_exists('imagewebp');
    $filename = uniqid() . '_' . time() . ($isGdLoaded ? '.webp' : '.' . $ext);
    $filepath = $uploadDir . '/' . $filename;
    
    if ($isGdLoaded) {
        // Baca sumber gambar sesuai mime type
        $image = false;
        if ($mimeType == 'image/jpeg') {
            $image = @imagecreatefromjpeg($file['tmp_name']);
        } elseif ($mimeType == 'image/png') {
            $image = @imagecreatefrompng($file['tmp_name']);
        } elseif ($mimeType == 'image/webp') {
            $image = @imagecreatefromwebp($file['tmp_name']);
        } elseif ($mimeType == 'image/gif') {
            $image = @imagecreatefromgif($file['tmp_name']);
        } elseif ($mimeType == 'image/bmp') {
            $image = @imagecreatefrombmp($file['tmp_name']);
        }
        
        if ($image !== false) {
            $width  = imagesx($image);
            $height = imagesy($image);
            
            // Max dimension tergantung tipe konten:
            // - Settings/banners: 1200px (butuh lebih besar untuk tampilan full-width)
            // - Produk/varian: 800px (cukup untuk thumbnail & detail produk)
            $maxDim = ($directory === 'settings') ? 1200 : 800;
            
            if ($width > $maxDim || $height > $maxDim) {
                $ratio = $width / $height;
                if ($ratio > 1) {
                    $newWidth  = $maxDim;
                    $newHeight = (int)($maxDim / $ratio);
                } else {
                    $newWidth  = (int)($maxDim * $ratio);
                    $newHeight = $maxDim;
                }
                $newImage = imagecreatetruecolor($newWidth, $newHeight);
                // Pertahankan transparansi
                imagecolortransparent($newImage, imagecolorallocatealpha($newImage, 0, 0, 0, 127));
                imagealphablending($newImage, false);
                imagesavealpha($newImage, true);
                imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                imagedestroy($image);
                $image = $newImage;
            } else {
                // Buat kanvas baru agar transparansi terjaga
                $newImage = imagecreatetruecolor($width, $height);
                imagecolortransparent($newImage, imagecolorallocatealpha($newImage, 0, 0, 0, 127));
                imagealphablending($newImage, false);
                imagesavealpha($newImage, true);
                imagecopy($newImage, $image, 0, 0, 0, 0, $width, $height);
                imagedestroy($image);
                $image = $newImage;
            }
            
            // Kualitas WebP 72: keseimbangan optimal antara ukuran file & kualitas visual
            // Quality 72 menghasilkan file ~20-30% lebih kecil dari quality 80
            // dengan perbedaan visual yang nyaris tidak terlihat pada foto produk
            $webpQuality = 72;
            $success = imagewebp($image, $filepath, $webpQuality);
            imagedestroy($image);
            
            if ($success) {
                $sizeKb = round(filesize($filepath) / 1024, 1);
                return [
                    'success'  => true,
                    'filename' => $filename,
                    'path'     => 'uploads/' . $directory . '/' . $filename,
                    'size_kb'  => $sizeKb
                ];
            }
            // Jika gagal simpan WebP, lanjut ke fallback di bawah
        }
    }
    
    // Fallback: langsung pindah file jika GD gagal membaca atau tidak aktif
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return [
            'success'  => true,
            'filename' => $filename,
            'path'     => 'uploads/' . $directory . '/' . $filename
        ];
    }
    
    return ['success' => false, 'message' => 'Gagal menyimpan file.'];
}

/**
 * Hapus file upload
 */
function deleteUploadedFile($path) {
    $fullPath = ROOT_PATH . '/' . $path;
    if (file_exists($fullPath)) {
        unlink($fullPath);
        return true;
    }
    return false;
}

/**
 * Dapatkan harga berdasarkan qty dan tipe harga (tiered pricing)
 * @param int $variationId ID varian produk
 * @param int $qty Jumlah yang dibeli
 * @param string $priceType 'Umum' atau 'Ranting'
 * @return float Harga jual
 */
function getSellingPrice($variationId, $qty, $priceType = 'Umum') {
    $db = Database::conn();
    
    // Coba cari harga sesuai price_type yang diminta
    $stmt = $db->prepare("
        SELECT pp.selling_price 
        FROM product_prices pp 
        JOIN price_types pt ON pp.price_type_id = pt.id
        JOIN product_variations pv ON pp.product_id = pv.product_id
        WHERE pv.id = ? AND pp.min_qty <= ? AND pt.name = ?
        ORDER BY pp.min_qty DESC 
        LIMIT 1
    ");
    $stmt->execute([$variationId, $qty, $priceType]);
    $price = $stmt->fetchColumn();
    
    // Jika tipe non-'Umum' tidak ada, fallback ke 'Umum'
    if (!$price && $priceType !== 'Umum') {
        $stmt = $db->prepare("
            SELECT pp.selling_price 
            FROM product_prices pp 
            JOIN price_types pt ON pp.price_type_id = pt.id
            JOIN product_variations pv ON pp.product_id = pv.product_id
            WHERE pv.id = ? AND pp.min_qty <= ? AND pt.name = 'Umum'
            ORDER BY pp.min_qty DESC 
            LIMIT 1
        ");
        $stmt->execute([$variationId, $qty]);
        $price = $stmt->fetchColumn();
    }
    
    if (!$price) {
        // Fallback ke base_price jika tidak ada tiered price sama sekali
        $stmt = $db->prepare("SELECT base_price FROM product_variations WHERE id = ?");
        $stmt->execute([$variationId]);
        $price = $stmt->fetchColumn();
    }
    
    // Terapkan diskon jika tipe harga 'Umum'
    if ($priceType === 'Umum') {
        $stmtDisc = $db->prepare("
            SELECT p.discount_percent 
            FROM products p 
            JOIN product_variations pv ON p.id = pv.product_id 
            WHERE pv.id = ?
        ");
        $stmtDisc->execute([$variationId]);
        $discount = (int)$stmtDisc->fetchColumn();
        
        if ($discount > 0) {
            $price = $price - ($price * $discount / 100);
        }
    }
    
    return (float)$price;
}

/**
 * Pagination helper
 */
function getPagination($totalItems, $currentPage, $perPage = ITEMS_PER_PAGE) {
    if (isset($_GET['limit'])) {
        $limit = (int)$_GET['limit'];
        if (in_array($limit, [10, 25, 50, 100])) {
            $perPage = $limit;
        }
    }

    $totalPages = ceil($totalItems / $perPage);
    $currentPage = max(1, min($currentPage, $totalPages));
    $offset = ($currentPage - 1) * $perPage;
    
    return [
        'total_items' => $totalItems,
        'total_pages' => $totalPages,
        'current_page' => $currentPage,
        'per_page' => $perPage,
        'offset' => $offset,
        'has_prev' => $currentPage > 1,
        'has_next' => $currentPage < $totalPages,
    ];
}

/**
 * Render pagination HTML
 */
function renderPagination($pagination, $baseUrl) {
    if ($pagination['total_items'] <= 0) return '';
    
    $params = $_GET;
    unset($params['page']);
    $params['limit'] = $pagination['per_page'];
    $queryString = '&' . http_build_query($params);
    
    $html = '<div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:15px; margin-top:20px;">';
    
    $html .= '<div class="pagination" style="margin:0;">';
    
    if ($pagination['total_pages'] > 1) {
        // First Page
        if ($pagination['current_page'] > 1) {
            $html .= '<a href="' . $baseUrl . '?page=1' . $queryString . '" class="pagination-btn" title="Halaman Pertama"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="11 17 6 12 11 7"/><polyline points="18 17 13 12 18 7"/></svg></a>';
        }
        
        // Previous
        if ($pagination['has_prev']) {
            $html .= '<a href="' . $baseUrl . '?page=' . ($pagination['current_page'] - 1) . $queryString . '" class="pagination-btn" title="Sebelumnya"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg></a>';
        }
        
        // Page numbers
        $start = max(1, $pagination['current_page'] - 2);
        $end = min($pagination['total_pages'], $pagination['current_page'] + 2);
        
        if ($start > 1) {
            $html .= '<a href="' . $baseUrl . '?page=1' . $queryString . '" class="pagination-btn">1</a>';
            if ($start > 2) $html .= '<span class="pagination-dots">...</span>';
        }
        
        for ($i = $start; $i <= $end; $i++) {
            $active = $i === $pagination['current_page'] ? ' active' : '';
            $html .= '<a href="' . $baseUrl . '?page=' . $i . $queryString . '" class="pagination-btn' . $active . '">' . $i . '</a>';
        }
        
        if ($end < $pagination['total_pages']) {
            if ($end < $pagination['total_pages'] - 1) $html .= '<span class="pagination-dots">...</span>';
            $html .= '<a href="' . $baseUrl . '?page=' . $pagination['total_pages'] . $queryString . '" class="pagination-btn">' . $pagination['total_pages'] . '</a>';
        }
        
        // Next
        if ($pagination['has_next']) {
            $html .= '<a href="' . $baseUrl . '?page=' . ($pagination['current_page'] + 1) . $queryString . '" class="pagination-btn" title="Berikutnya"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg></a>';
        }
        
        // Last Page
        if ($pagination['current_page'] < $pagination['total_pages']) {
            $html .= '<a href="' . $baseUrl . '?page=' . $pagination['total_pages'] . $queryString . '" class="pagination-btn" title="Halaman Terakhir"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="13 17 18 12 13 7"/><polyline points="6 17 11 12 6 7"/></svg></a>';
        }
    }
    
    $html .= '</div>';
    
    // Limit selector removed based on user request
    
    $html .= '</div>';
    
    return $html;
}

/**
 * Log aktivitas pengguna
 */
function logActivity($action, $module, $description = '') {
    try {
        $db = Database::conn();
        $userId = $_SESSION['user_id'] ?? null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        
        $stmt = $db->prepare("INSERT INTO activity_logs (user_id, action, module, description, ip_address) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $action, $module, $description, $ip]);
    } catch (Exception $e) {
        // Jangan biarkan error logging mengganggu operasi utama
    }
}

/**
 * Ambil pengaturan aplikasi dari database
 */
function getSetting($key, $default = '') {
    try {
        $db = Database::conn();
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value !== false ? $value : $default;
    } catch (Exception $e) {
        return $default;
    }
}

/**
 * Update pengaturan aplikasi
 */
function updateSetting($key, $value) {
    $db = Database::conn();
    $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->execute([$key, $value, $value]);
}

// =============================================
// IMAGE COMPRESSION & WEBP CONVERSION
// =============================================

/**
 * Upload gambar dengan kompresi otomatis ke format WebP.
 * Membutuhkan ekstensi php_gd aktif di XAMPP (extension=gd di php.ini).
 *
 * @param array  $file      Array file dari $_FILES['field']
 * @param string $targetDir Path direktori tujuan (absolute)
 * @param int    $maxWidth  Lebar maksimal gambar (default 800px)
 * @param int    $quality   Kualitas WebP 0-100 (default 82)
 * @return array ['success', 'filename'|'message', 'size_kb']
 */
function uploadAndCompressImage(array $file, string $targetDir, int $maxWidth = 800, int $quality = 72): array {
    $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'File upload gagal atau tidak valid.'];
    }

    // Cek ekstensi GD
    if (!function_exists('imagecreatefromjpeg')) {
        return ['success' => false, 'message' => 'Ekstensi GD PHP belum aktif. Aktifkan extension=gd di php.ini.'];
    }

    $detectedType = mime_content_type($file['tmp_name']);
    if (!in_array($detectedType, $allowedTypes)) {
        return ['success' => false, 'message' => 'Tipe file tidak diizinkan. Gunakan JPG, PNG, WebP, atau GIF.'];
    }

    // Buat direktori jika belum ada
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }

    // Buat nama file unik, selalu simpan sebagai WebP
    $filename  = 'img_' . uniqid() . '_' . time() . '.webp';
    $targetPath = rtrim($targetDir, '/') . '/' . $filename;

    // Load gambar sumber berdasarkan MIME type asli
    $image = null;
    switch ($detectedType) {
        case 'image/jpeg': $image = imagecreatefromjpeg($file['tmp_name']); break;
        case 'image/png':  $image = imagecreatefrompng($file['tmp_name']); break;
        case 'image/webp': $image = imagecreatefromwebp($file['tmp_name']); break;
        case 'image/gif':  $image = imagecreatefromgif($file['tmp_name']); break;
    }

    if (!$image) {
        return ['success' => false, 'message' => 'Gagal memproses gambar. File mungkin rusak.'];
    }

    // Resize proporsional jika lebih lebar dari maxWidth
    $origW = imagesx($image);
    $origH = imagesy($image);

    if ($origW > $maxWidth) {
        $ratio   = $maxWidth / $origW;
        $newW    = $maxWidth;
        $newH    = (int)round($origH * $ratio);
        $resized = imagecreatetruecolor($newW, $newH);

        // Preserve transparansi (PNG & GIF)
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefilledrectangle($resized, 0, 0, $newW, $newH, $transparent);

        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
        imagedestroy($image);
        $image = $resized;
    }

    // Simpan sebagai WebP
    if (!imagewebp($image, $targetPath, $quality)) {
        imagedestroy($image);
        return ['success' => false, 'message' => 'Gagal menyimpan gambar WebP. Periksa permission folder.'];
    }

    imagedestroy($image);

    return [
        'success'  => true,
        'filename' => $filename,
        'path'     => $targetPath,
        'size_kb'  => round(filesize($targetPath) / 1024, 1),
    ];
}

/**
 * Cek apakah ekstensi GD tersedia untuk kompresi gambar.
 */
function isGdAvailable(): bool {
    return function_exists('imagecreatefromjpeg') && function_exists('imagewebp');
}

/**
 * Kirim pesan WhatsApp menggunakan API pihak ketiga (contoh menggunakan Fonnte)
 * Silakan sesuaikan Endpoint URL dan Token dengan API Gateway yang Anda gunakan.
 */
function sendWhatsAppMessage($phone, $message) {
    // Normalisasi nomor telepon
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (substr($phone, 0, 1) === '0') {
        $phone = '62' . substr($phone, 1);
    }

    // Ambil token dari database (jika ada) atau hardcode di sini
    $token = getSetting('wa_api_token', 'TOKEN_ANDA_DISINI'); 
    
    // Contoh implementasi menggunakan Fonnte API
    $url = 'https://api.fonnte.com/send';

    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => array(
            'target' => $phone,
            'message' => $message,
            'countryCode' => '62',
        ),
        CURLOPT_HTTPHEADER => array(
            "Authorization: $token"
        ),
    ));

    $response = curl_exec($curl);
    $error = curl_error($curl);
    curl_close($curl);
    
    if ($error) {
        return ['success' => false, 'message' => 'Gagal mengirim WA: ' . $error];
    }
    
    $res = json_decode($response, true);
    if (isset($res['status']) && $res['status'] === true) {
        return ['success' => true, 'message' => 'WhatsApp berhasil dikirim'];
    }
    
    return ['success' => false, 'message' => 'Gagal mengirim WA. Periksa Token atau limit layanan.'];
}

/**
 * Generate CSRF Token and store in session
 */
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF Token from POST request
 */
function verify_csrf($token) {
    if (!isset($_SESSION['csrf_token']) || $token !== $_SESSION['csrf_token']) {
        return false;
    }
    return true;
}

