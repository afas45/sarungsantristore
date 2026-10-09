            </main>
        </div><!-- .main-content -->
    </div><!-- .admin-layout -->

    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>

    <!-- Core JavaScript -->
    <script src="<?= ASSETS_URL ?>/js/app.js?v=<?= time() ?>"></script>
    
    <?php if (isset($extraJs)): ?>
        <?php foreach ((array)$extraJs as $js): ?>
            <script src="<?= ASSETS_URL ?>/js/<?= $js ?>?v=<?= time() ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
    
    <?php if (isset($inlineJs)): ?>
        <script><?= $inlineJs ?></script>
    <?php endif; ?>
    
    <script>
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
    </script>
</body>
</html>
