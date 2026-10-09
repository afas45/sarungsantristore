<?php
$content = file_get_contents('login.php');

$fetchLogic = <<<'PHP'
// Fetch Data for Landing Page
$db = Database::conn();
$stmtProd = $db->query("SELECT p.id, p.name, p.image, pv.base_price FROM products p LEFT JOIN product_variations pv ON p.id = pv.product_id WHERE p.is_active = 1 GROUP BY p.id ORDER BY p.id DESC LIMIT 4");
$featuredProducts = $stmtProd->fetchAll(PDO::FETCH_ASSOC);

$stmtCat = $db->query("SELECT * FROM categories LIMIT 4");
$categories = $stmtCat->fetchAll(PDO::FETCH_ASSOC);

PHP;

if (strpos($content, '$featuredProducts =') === false) {
    $content = str_replace('?>', $fetchLogic . "\n?>", $content);
}

$cssOld1 = 'body {
            font-family: \'Inter\', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #BC3908 0%, #220901 100%);
            background-attachment: fixed;
            background-size: cover;
            position: relative;
            overflow-x: hidden;
            overflow-y: auto;
        }';
        
$cssNew1 = <<<'CSS'
body {
            font-family: 'Inter', sans-serif;
            background: #f8f9fa;
            position: relative;
            overflow-x: hidden;
            overflow-y: auto;
        }
        .hero-section {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #BC3908 0%, #220901 100%);
            background-attachment: fixed;
            background-size: cover;
            position: relative;
            padding: 40px 20px;
        }
        .featured-section {
            padding: 80px 20px;
            background: #fff;
        }
        .featured-section:nth-of-type(even) {
            background: #f8f9fa;
        }
        .section-title {
            text-align: center;
            font-size: 2.2rem;
            font-weight: 800;
            color: #220901;
            margin-bottom: 50px;
        }
        .grid-container {
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 30px;
        }
        .product-card {
            background: #fff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.05);
            transition: transform 0.3s, box-shadow 0.3s;
            text-align: center;
            padding: 20px;
            border: 1px solid #eee;
        }
        .product-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0,0,0,0.1);
        }
        .product-img {
            width: 100%;
            height: 200px;
            object-fit: cover;
            margin-bottom: 15px;
            border-radius: 8px;
        }
        .product-title {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 10px;
            color: #333;
        }
        .product-price {
            font-size: 1.25rem;
            font-weight: 800;
            color: #BC3908;
            margin-bottom: 15px;
        }
        .btn-view {
            display: inline-block;
            padding: 10px 24px;
            background: #220901;
            color: #fff;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: background 0.3s;
        }
        .btn-view:hover {
            background: #BC3908;
        }
        
        .promo-banner {
            background: linear-gradient(135deg, #621708 0%, #220901 100%);
            padding: 80px 20px;
            color: #fff;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .promo-banner h2 {
            font-size: 2.8rem;
            font-weight: 800;
            margin-bottom: 15px;
            color: #fff;
        }
        .promo-banner p {
            font-size: 1.1rem;
            max-width: 600px;
            margin-bottom: 30px;
            color: rgba(255,255,255,0.8);
        }
        
        .category-card {
            background: #fff;
            border-radius: 12px;
            padding: 30px 20px;
            text-align: center;
            text-decoration: none;
            color: #333;
            font-weight: 700;
            font-size: 1.1rem;
            transition: all 0.3s;
            border: 1px solid #eee;
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
        }
        .category-card:hover {
            background: #BC3908;
            color: #fff;
            border-color: #BC3908;
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(188, 57, 8, 0.2);
        }
        .category-card svg {
            width: 48px;
            height: 48px;
            margin-bottom: 15px;
            color: inherit;
        }
CSS;

$content = str_replace(trim($cssOld1), trim($cssNew1), $content);

$htmlOld1 = '<body>
    <!-- Background decorations -->
    <div class="decoration decoration-1"></div>
    <div class="decoration decoration-2"></div>
    <div class="decoration decoration-3"></div>
    <div class="pattern-overlay"></div>
    
    <div class="split-container">';

$htmlNew1 = '<body>
<div class="hero-section">
    <!-- Background decorations -->
    <div class="decoration decoration-1"></div>
    <div class="decoration decoration-2"></div>
    <div class="decoration decoration-3"></div>
    <div class="pattern-overlay"></div>
    
    <div class="split-container">';
$content = str_replace($htmlOld1, $htmlNew1, $content);

$htmlOld2 = '    <script>
        // Auto-hide alert after 3 seconds';
        
$htmlNew2 = <<<'HTML'
        </div>
    </div>
</div> <!-- /hero-section -->

<!-- Featured Products Section -->
<section class="featured-section">
    <h2 class="section-title">Produk Terlaris</h2>
    <div class="grid-container">
        <?php foreach($featuredProducts as $fp): ?>
        <div class="product-card">
            <img src="<?= BASE_URL . '/' . ($fp['image'] ?: 'assets/img/no-image.png') ?>" alt="<?= htmlspecialchars($fp['name']) ?>" class="product-img">
            <h3 class="product-title"><?= htmlspecialchars(mb_substr($fp['name'], 0, 30)) ?>...</h3>
            <div class="product-price">Rp <?= number_format((float)($fp['base_price'] ?? 0), 0, ',', '.') ?></div>
            <a href="<?= BASE_URL ?>/shop/product.php?id=<?= $fp['id'] ?>" class="btn-view">Lihat Produk</a>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- Promo Banner Section -->
<section class="promo-banner">
    <h2>Tingkatkan Gaya Anda</h2>
    <p>Temukan koleksi sarung dan fashion muslim modern untuk menunjang penampilan serta kenyamanan beribadah.</p>
    <a href="<?= BASE_URL ?>/shop/index.php" class="btn-shop-large" style="background: #fff; color: #BC3908;">Belanja Sekarang</a>
</section>

<!-- Categories Section -->
<section class="featured-section">
    <h2 class="section-title">Kategori Terpopuler</h2>
    <div class="grid-container">
        <?php foreach($categories as $cat): ?>
        <a href="<?= BASE_URL ?>/shop/index.php?category=<?= $cat['id'] ?>" class="category-card">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg><br>
            <?= htmlspecialchars($cat['name']) ?>
        </a>
        <?php endforeach; ?>
    </div>
</section>

    <script>
        // Auto-hide alert after 3 seconds
HTML;

$content = str_replace($htmlOld2, $htmlNew2, $content);

file_put_contents('login.php', $content);
echo "Update complete.";

