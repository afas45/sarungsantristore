<?php
/**
 * Kasir Ibtidaiyah - Proxy API RajaOngkir
 * Meneruskan request cek ongkir ke RajaOngkir dari sisi server (Mendukung V2 Komerce)
 */
require_once __DIR__ . '/../config/app.php';

header('Content-Type: application/json');

// Cek apakah fitur API ongkir aktif
$enableShipping = getSetting('enable_shipping_api') === '1';
if (!$enableShipping) {
    echo json_encode(['success' => false, 'message' => 'Fitur cek ongkir tidak aktif.']);
    exit;
}

$apiKey   = getSetting('rajaongkir_api_key', '');
$action   = $_POST['action'] ?? $_GET['action'] ?? '';

if (empty($apiKey)) {
    echo json_encode(['success' => false, 'message' => 'API Key ongkir belum dikonfigurasi.']);
    exit;
}

// ---- Helper: call RajaOngkir Legacy (Untuk daftar kota di settings) ----
function rajaOngkirRequest(string $endpoint, array $postData, string $apiKey, string $tier = 'starter'): array {
    $baseUrl = $tier === 'pro' ? 'https://pro.rajaongkir.com/api' : 'https://api.rajaongkir.com/starter';
    $url = $baseUrl . $endpoint;

    $options = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'key: ' . $apiKey,
            'content-type: application/x-www-form-urlencoded',
        ],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ];

    if (!empty($postData)) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($postData);
    } else {
        $options[CURLOPT_CUSTOMREQUEST] = "GET";
    }

    $ch = curl_init();
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['success' => false, 'message' => 'Koneksi ke server ongkir gagal: ' . $curlError];
    }

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['success' => false, 'message' => 'Response API tidak valid.'];
    }

    return ['success' => true, 'data' => $data, 'http_code' => $httpCode];
}

// ---- Helper: call RajaOngkir Komerce V2 ----
function rajaOngkirKomerceRequest(string $endpoint, array $postData, string $apiKey): array {
    $baseUrl = 'https://rajaongkir.komerce.id/api/v1';
    $url = $baseUrl . $endpoint;

    $options = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'key: ' . $apiKey,
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ];

    if (!empty($postData)) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($postData);
    } else {
        $options[CURLOPT_CUSTOMREQUEST] = "GET";
    }

    $ch = curl_init();
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['success' => false, 'message' => 'Koneksi ke server ongkir gagal: ' . $curlError];
    }

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['success' => false, 'message' => 'Response API tidak valid.'];
    }

    return ['success' => true, 'data' => $data, 'http_code' => $httpCode];
}


// ---- Action: Cari Kota/Kecamatan (Local DB) ----
if ($action === 'search_city') {
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) {
        echo json_encode(['success' => true, 'data' => []]);
        exit;
    }

    $db = Database::conn();
    try {
        $hasPostalCode = false;
        $cols = $db->query("SHOW COLUMNS FROM tb_ro_cities LIKE 'postal_code'")->fetchAll();
        if (count($cols) > 0) $hasPostalCode = true;

        $qLike = '%' . str_replace([',', ' '], '%', $q) . '%';

        $sqlCities = "SELECT c.city_id as id, c.city_name as name, c.type as extra, p.province_name AS province, 'Kota/Kab' as type 
             FROM tb_ro_cities c 
             LEFT JOIN tb_ro_provinces p ON c.province_id = p.province_id
             WHERE CONCAT(c.city_name, ' ', p.province_name) LIKE :qLike";
        if ($hasPostalCode) {
             $sqlCities .= " OR c.postal_code LIKE :qCode";
        }
        
        $sqlSub = "SELECT s.subdistrict_id as id, s.subdistrict_name as name, c.city_name as extra, p.province_name AS province, 'Kecamatan' as type 
             FROM tb_ro_subdistricts s
             LEFT JOIN tb_ro_cities c ON s.city_id = c.city_id
             LEFT JOIN tb_ro_provinces p ON c.province_id = p.province_id
             WHERE CONCAT(s.subdistrict_name, ' ', c.city_name, ' ', p.province_name) LIKE :qLike";

        $stmtCities = $db->prepare($sqlCities);
        $stmtCities->bindValue(':qLike', $qLike);
        if ($hasPostalCode) $stmtCities->bindValue(':qCode', '%' . $q . '%');
        $stmtCities->execute();
        $resCities = $stmtCities->fetchAll(PDO::FETCH_ASSOC);

        $stmtSub = $db->prepare($sqlSub);
        $stmtSub->bindValue(':qLike', $qLike);
        $stmtSub->execute();
        $resSub = $stmtSub->fetchAll(PDO::FETCH_ASSOC);

        $results = array_merge($resCities, $resSub);
        // Sort results
        usort($results, function($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        $results = array_slice($results, 0, 15);
        
        $data = [];
        foreach ($results as $row) {
            if ($row['type'] === 'Kota/Kab') {
                $displayName = ($row['extra'] ? $row['extra'] . ' ' : '') . $row['name'];
            } else {
                $displayName = 'Kec. ' . $row['name'] . ', ' . $row['extra'];
            }
            
            $data[] = [
                'city_id'   => $row['id'],
                'city_name' => $displayName,
                'type'      => '',
                'province'  => $row['province']
            ];
        }
        echo json_encode(['success' => true, 'data' => $data]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Gagal mencari kota dari database.']);
    }
    exit;
}

// ---- Action: Ambil daftar provinsi (Local DB) ----
if ($action === 'provinces') {
    $db = Database::conn();
    try {
        $stmt = $db->query("SELECT province_id, province_name AS province FROM tb_ro_provinces ORDER BY province_name ASC");
        $provinces = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'provinces' => $provinces]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Gagal memuat provinsi dari database.']);
    }
    exit;
}

// ---- Action: Ambil daftar kota (Local DB) ----
if ($action === 'cities') {
    $provinceId = (int)($_GET['province'] ?? $_POST['province'] ?? 0);
    $db = Database::conn();
    try {
        if ($provinceId > 0) {
            $stmt = $db->prepare("SELECT city_id, city_name, province_id FROM tb_ro_cities WHERE province_id = ? ORDER BY city_name ASC");
            $stmt->execute([$provinceId]);
        } else {
            $stmt = $db->query("SELECT city_id, city_name, province_id FROM tb_ro_cities ORDER BY city_name ASC");
        }
        $cities = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'cities' => $cities]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Gagal memuat kota dari database.']);
    }
    exit;
}

// ---- Action: Ambil daftar kecamatan (Local DB) ----
if ($action === 'subdistricts') {
    $cityId = (int)($_GET['city'] ?? $_POST['city'] ?? 0);
    if (!$cityId) {
        echo json_encode(['success' => false, 'message' => 'Parameter city diperlukan.']);
        exit;
    }
    $db = Database::conn();
    try {
        $stmt = $db->prepare("SELECT subdistrict_id, subdistrict_name, city_id FROM tb_ro_subdistricts WHERE city_id = ? ORDER BY subdistrict_name ASC");
        $stmt->execute([$cityId]);
        $subdistricts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'subdistricts' => $subdistricts]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Gagal memuat kecamatan dari database.']);
    }
    exit;
}

// ---- Helper: Resolve local DB ID ke nama untuk V2 search ----
function resolveLocalIdToName(int $id, PDO $db): array {
    // Cek apakah ID adalah subdistrict
    try {
        $stmt = $db->prepare("SELECT s.subdistrict_name, c.city_name, c.postal_code, p.province_name 
                              FROM tb_ro_subdistricts s 
                              JOIN tb_ro_cities c ON s.city_id = c.city_id 
                              JOIN tb_ro_provinces p ON c.province_id = p.province_id
                              WHERE s.subdistrict_id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            // Hapus prefix "Kabupaten " atau "Kota " dari city_name untuk V2 search
            $cleanCity = preg_replace('/^(Kabupaten|Kota)\s+/i', '', $row['city_name']);
            return [
                'type' => 'subdistrict',
                'subdistrict_name' => $row['subdistrict_name'],
                'city_name' => $row['city_name'],
                'clean_city_name' => $cleanCity,
                'province_name' => $row['province_name'],
                'postal_code' => $row['postal_code'] ?? '',
                'search_query' => $row['subdistrict_name'] . ' ' . $cleanCity,
            ];
        }
    } catch (Exception $e) {}

    // Cek apakah ID adalah city
    try {
        $stmt = $db->prepare("SELECT c.city_name, c.postal_code, p.province_name 
                              FROM tb_ro_cities c 
                              JOIN tb_ro_provinces p ON c.province_id = p.province_id
                              WHERE c.city_id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $cleanName = preg_replace('/^(Kabupaten|Kota)\s+/i', '', $row['city_name']);
            return [
                'type' => 'city',
                'city_name' => $row['city_name'],
                'clean_city_name' => $cleanName,
                'province_name' => $row['province_name'],
                'postal_code' => $row['postal_code'] ?? '',
                'search_query' => $cleanName,
            ];
        }
    } catch (Exception $e) {}

    return [];
}

// ---- Helper: Search Komerce V2 destination ID by name ----
function searchV2DestinationId(string $searchQuery, string $apiKey, string $filterCity = '', string $filterProvince = ''): int {
    $searchUrl = '/destination/domestic-destination?search=' . urlencode($searchQuery) . '&limit=10&offset=0';
    $res = rajaOngkirKomerceRequest($searchUrl, [], $apiKey);
    $destinations = $res['data']['data'] ?? [];
    
    if (empty($destinations)) return 0;
    
    // Jika ada filter, coba cari yang paling cocok
    if ($filterCity || $filterProvince) {
        $filterCityClean = strtoupper(preg_replace('/^(Kabupaten|Kota)\s+/i', '', $filterCity));
        $filterProvUpper = strtoupper($filterProvince);
        
        foreach ($destinations as $dest) {
            $destCity = strtoupper($dest['city_name'] ?? '');
            $destProv = strtoupper($dest['province_name'] ?? '');
            
            // Exact match on city
            if ($filterCityClean && $destCity === $filterCityClean) {
                return (int)$dest['id'];
            }
            // Match on city contains
            if ($filterCityClean && strpos($destCity, $filterCityClean) !== false) {
                return (int)$dest['id'];
            }
        }
        
        // Jika filter city tidak cocok, coba filter province
        if ($filterProvUpper) {
            foreach ($destinations as $dest) {
                $destProv = strtoupper($dest['province_name'] ?? '');
                if (strpos($destProv, $filterProvUpper) !== false || strpos($filterProvUpper, $destProv) !== false) {
                    return (int)$dest['id'];
                }
            }
        }
    }
    
    // Default: ambil hasil pertama
    return (int)($destinations[0]['id'] ?? 0);
}

// ---- Action: Cek ongkos kirim berdasarkan ID (V2 / Legacy) ----
if ($action === 'cost') {
    $origin      = (int)($_POST['origin']      ?? getSetting('rajaongkir_origin_city_id', 0));
    $destination = (int)($_POST['destination'] ?? 0);
    $subdistrict = (int)($_POST['subdistrict'] ?? 0);
    $weight      = (int)($_POST['weight']      ?? 1000); // gram
    $courier     = strtolower(trim($_POST['courier'] ?? ''));

    if (!$origin || (!$destination && !$subdistrict) || !$courier) {
        echo json_encode(['success' => false, 'message' => 'Parameter tidak lengkap (origin/destination/courier).']);
        exit;
    }

    $db = Database::conn();

    // === STRATEGI 1: Resolve nama dari DB lokal, lalu cari ID V2 via search ===
    // Resolve origin (local ID -> nama -> V2 ID)
    $originInfo = resolveLocalIdToName($origin, $db);
    $v2OriginId = 0;
    if (!empty($originInfo)) {
        $v2OriginId = searchV2DestinationId(
            $originInfo['search_query'], $apiKey,
            $originInfo['city_name'] ?? '', $originInfo['province_name'] ?? ''
        );
    }
    if (!$v2OriginId) {
        // Fallback: coba gunakan origin ID langsung (mungkin sudah V2 ID)
        $v2OriginId = $origin;
    }

    // Resolve destination (local ID -> nama -> V2 ID)
    $targetLocalId = $subdistrict ?: $destination;
    $destInfo = resolveLocalIdToName($targetLocalId, $db);
    $v2DestId = 0;
    if (!empty($destInfo)) {
        $v2DestId = searchV2DestinationId(
            $destInfo['search_query'], $apiKey,
            $destInfo['city_name'] ?? '', $destInfo['province_name'] ?? ''
        );
        
        // Jika search dengan kecamatan+kota tidak ditemukan, coba kota saja
        if (!$v2DestId && $destInfo['type'] === 'subdistrict') {
            $v2DestId = searchV2DestinationId(
                $destInfo['clean_city_name'] ?? '', $apiKey,
                $destInfo['city_name'] ?? '', $destInfo['province_name'] ?? ''
            );
        }
    }
    if (!$v2DestId) {
        // Fallback: coba gunakan destination ID langsung
        $v2DestId = $targetLocalId;
    }

    // Hitung ongkir via Komerce V2 dengan ID yang sudah di-resolve
    $postDataV2 = [
        'origin'      => $v2OriginId,
        'destination' => $v2DestId,
        'weight'      => max(1, $weight),
        'courier'     => $courier,
    ];
    $resV2 = rajaOngkirKomerceRequest('/calculate/domestic-cost', $postDataV2, $apiKey);
    
    if ($resV2['success'] && ($resV2['data']['meta']['code'] ?? 0) == 200) {
        $services = $resV2['data']['data'] ?? [];
        echo json_encode(['success' => true, 'services' => $services]);
        exit;
    }

    // === STRATEGI 2: Fallback ke Legacy RajaOngkir (jika V2 gagal) ===
    $originType = 'city';
    try {
        $stmtType = $db->prepare("SELECT subdistrict_id FROM tb_ro_subdistricts WHERE subdistrict_id = ?");
        $stmtType->execute([$origin]);
        if ($stmtType->fetchColumn()) {
            $originType = 'subdistrict';
        }
    } catch (Exception $e) {}

    $result = null;
    if ($subdistrict) {
        $postDataPro = [
            'origin'          => $origin,
            'originType'      => $originType,
            'destination'     => $subdistrict,
            'destinationType' => 'subdistrict',
            'weight'          => max(1, $weight),
            'courier'         => $courier,
        ];
        $result = rajaOngkirRequest('/cost', $postDataPro, $apiKey, 'pro');
        if (!$result['success'] || ($result['http_code'] == 400)) {
            $result = null; 
        }
    }

    if (empty($result)) {
        $postDataStarter = [
            'origin'      => $origin,
            'destination' => $destination,
            'weight'      => max(1, $weight),
            'courier'     => $courier,
        ];
        $result = rajaOngkirRequest('/cost', $postDataStarter, $apiKey, 'starter');
    }

    if (!$result['success']) {
        echo json_encode($result);
        exit;
    }

    $ro = $result['data']['rajaongkir'] ?? [];
    if (($ro['status']['code'] ?? 0) != 200) {
        $msg = $ro['status']['description'] ?? 'Gagal menghitung ongkos kirim.';
        echo json_encode(['success' => false, 'message' => $msg]);
        exit;
    }

    $services = $ro['results'][0]['costs'] ?? [];
    echo json_encode(['success' => true, 'services' => $services]);
    exit;
}

// ---- Action: Cek ongkos kirim berdasarkan nama (Untuk checkout) ----
if ($action === 'cost_by_name') {
    $origin      = (int)($_POST['origin'] ?? getSetting('rajaongkir_origin_city_id', 0));
    $destName    = trim($_POST['destination_name'] ?? '');
    $subName     = trim($_POST['subdistrict_name'] ?? '');
    $weight      = (int)($_POST['weight'] ?? 1000); // gram
    $courier     = strtolower(trim($_POST['courier'] ?? ''));

    if (!$origin) {
        echo json_encode(['success' => false, 'message' => 'Kota asal pengiriman belum diatur di Pengaturan Sistem.']);
        exit;
    }
    if (!$destName || !$courier) {
        echo json_encode(['success' => false, 'message' => 'Parameter tujuan atau kurir tidak lengkap.']);
        exit;
    }

    // 1. Cari Destinasi via Direct Search Komerce V2
    // Menggunakan nama kecamatan lalu kota untuk hasil lebih akurat
    $searchQuery = $subName ? $subName . ' ' . $destName : $destName;
    $searchUrl = '/destination/domestic-destination?search=' . urlencode($searchQuery) . '&limit=5&offset=0';
    $searchRes = rajaOngkirKomerceRequest($searchUrl, [], $apiKey);

    $destinations = $searchRes['data']['data'] ?? [];
    
    // Fallback jika tidak ditemukan (mungkin kecamatannya tidak match) -> cari kota saja
    if (empty($destinations) && $subName) {
        $searchUrl = '/destination/domestic-destination?search=' . urlencode($destName) . '&limit=5&offset=0';
        $searchRes = rajaOngkirKomerceRequest($searchUrl, [], $apiKey);
        $destinations = $searchRes['data']['data'] ?? [];
    }

    $destId = $destinations[0]['id'] ?? 0;

    // 2. Jika V2 berhasil mendapatkan destId, hitung ongkir
    if ($destId) {
        $postDataV2 = [
            'origin'      => $origin,
            'destination' => $destId,
            'weight'      => max(1, $weight),
            'courier'     => $courier,
        ];
        
        $costRes = rajaOngkirKomerceRequest('/calculate/domestic-cost', $postDataV2, $apiKey);
        
        $meta = $costRes['data']['meta'] ?? [];
        if (($meta['code'] ?? 0) == 200 && ($meta['status'] ?? '') === 'success') {
            // Komerce V2 returns data as flat array: [{name, code, service, description, cost, etd}, ...]
            $services = $costRes['data']['data'] ?? [];
            echo json_encode(['success' => true, 'services' => $services]);
            exit;
        }
    }

    // 3. Fallback ke Legacy RajaOngkir jika V2 gagal
    $destNameLower = strtolower($destName);
    $subNameLower  = strtolower($subName);
    
    $cityRes = rajaOngkirRequest('/city', [], $apiKey);
    if (!$cityRes['success']) {
        echo json_encode(['success' => false, 'message' => 'Gagal mengambil data kota dari RajaOngkir.']);
        exit;
    }

    $legacyDestId = 0;
    foreach ($cityRes['data']['rajaongkir']['results'] as $c) {
        $roName = strtolower($c['type'] . ' ' . $c['city_name']);
        if ($roName === $destNameLower || strtolower($c['city_name']) === $destNameLower) {
            $legacyDestId = $c['city_id'];
            break;
        }
        if (str_replace('kota administrasi ', 'kota ', $destNameLower) === $roName) {
            $legacyDestId = $c['city_id'];
            break;
        }
    }

    if (!$legacyDestId) {
        foreach ($cityRes['data']['rajaongkir']['results'] as $c) {
            if (strpos($destNameLower, strtolower($c['city_name'])) !== false) {
                $legacyDestId = $c['city_id'];
                break;
            }
        }
    }

    if (!$legacyDestId) {
        echo json_encode(['success' => false, 'message' => 'Kota tujuan "' . htmlspecialchars(strtoupper($destName)) . '" tidak didukung oleh sistem kurir.']);
        exit;
    }

    $legacySubId = 0;
    if ($subNameLower) {
        $subRes = rajaOngkirRequest('/subdistrict?city=' . $legacyDestId, [], $apiKey, 'pro');
        if ($subRes['success'] && $subRes['http_code'] == 200) {
            $subs = $subRes['data']['rajaongkir']['results'] ?? [];
            foreach ($subs as $s) {
                if (strtolower($s['subdistrict_name']) === $subNameLower) {
                    $legacySubId = $s['subdistrict_id'];
                    break;
                }
            }
        }
    }

    $result = null;
    if ($legacySubId) {
        $postDataPro = [
            'origin'          => $origin,
            'originType'      => 'city',
            'destination'     => $legacySubId,
            'destinationType' => 'subdistrict',
            'weight'          => max(1, $weight),
            'courier'         => $courier,
        ];
        $result = rajaOngkirRequest('/cost', $postDataPro, $apiKey, 'pro');
        if (!$result['success'] || $result['http_code'] == 400) {
            $result = null;
        }
    }

    if (empty($result)) {
        $postDataStarter = [
            'origin'      => $origin,
            'destination' => $legacyDestId,
            'weight'      => max(1, $weight),
            'courier'     => $courier,
        ];
        $result = rajaOngkirRequest('/cost', $postDataStarter, $apiKey, 'starter');
    }

    if (!$result['success']) {
        echo json_encode($result);
        exit;
    }

    $ro = $result['data']['rajaongkir'] ?? [];
    if (($ro['status']['code'] ?? 0) != 200) {
        $msg = $ro['status']['description'] ?? 'Gagal menghitung ongkos kirim.';
        echo json_encode(['success' => false, 'message' => $msg]);
        exit;
    }

    $services = $ro['results'][0]['costs'] ?? [];
    echo json_encode(['success' => true, 'services' => $services]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Action tidak dikenal.']);
