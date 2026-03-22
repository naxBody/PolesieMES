        <?php if (isLoggedIn()): ?>
    </div>
    </main>
    
    <?php if (isset($showSidebar) && $showSidebar): ?>
    </div>
    <?php endif; ?>
    
    <!-- Footer -->
    <footer class="footer mt-auto py-3 bg-light">
        <div class="container-fluid">
            <div class="row">
                <div class="col-md-6 text-center text-md-start">
                    <span class="text-muted">
                        &copy; <?= date('Y') ?> ОАО "Полесьеэлектромаш". Система PolesieMES v<?= APP_VERSION ?>
                    </span>
                </div>
                <div class="col-md-6 text-center text-md-end">
                    <span class="text-muted">
                        <i class="fas fa-clock me-1"></i><?= date('d.m.Y H:i') ?>
                    </span>
                </div>
            </div>
        </div>
    </footer>
    <?php endif; ?>
    
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <!-- Custom JS -->
    <script src="<?= APP_URL ?>/assets/js/main.js"></script>
    
    <?php if (isset($additionalJS)): ?>
        <?php foreach ($additionalJS as $js): ?>
            <script src="<?= e($js) ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
    
    <?php if (isset($pageScript)): ?>
    <script>
        <?= $pageScript ?>
    </script>
    <?php endif; ?>
</body>
</html>
