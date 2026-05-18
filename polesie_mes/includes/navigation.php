<?php
/**
 * Компонент навигации для системы PolesieMES
 * Используется на всех страницах для единообразия
 */

// Проверка, что переменные инициализированы
if (!isset($currentPage)) {
    $currentPage = '';
}
if (!isset($currentModule)) {
    $currentModule = '';
}
?>

<!-- Навигация -->
<nav class="navbar" id="navbar">
    <a href="<?= APP_URL ?>" class="nav-brand">
        <div class="brand-logo">
            <svg viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
        </div>
        <span class="brand-name">PolesieMES</span>
    </a>

    <ul class="nav-menu">
        <li>
            <a href="<?= APP_URL ?>/modules/dashboard/index.php" class="nav-link <?= ($currentPage ?? '') == 'dashboard' ? 'active' : '' ?>">
                <i class="fas fa-chart-line"></i>
                Главная
            </a>
        </li>

        <?php if (hasRole(['admin', 'director', 'manager'])): ?>
        <!-- Заказы - только админ, директор и менеджеры -->
        <li>
            <a href="<?= APP_URL ?>/modules/orders/index.php" class="nav-link <?= ($currentModule ?? '') == 'orders' ? 'active' : '' ?>">
                <i class="fas fa-shopping-cart"></i>
                Заказы
            </a>
        </li>
        <?php endif; ?>

        <?php if (hasRole(['admin', 'director', 'manager', 'technologist', 'operator', 'warehouse_keeper'])): ?>
        <!-- Производство - все основные роли -->
        <li>
            <a href="<?= APP_URL ?>/modules/production/index.php" class="nav-link <?= ($currentModule ?? '') == 'production' ? 'active' : '' ?>">
                <i class="fas fa-cogs"></i>
                Производство
            </a>
        </li>
        <?php endif; ?>

        <?php if (hasRole(['admin', 'director', 'manager', 'warehouse_keeper'])): ?>
        <!-- Склад -->
        <li>
            <?php if (hasRole(['director', 'admin'])): ?>
            <a href="<?= APP_URL ?>/modules/director/dashboard.php#warehouse-section" class="nav-link <?= ($currentModule ?? '') == 'warehouse' ? 'active' : '' ?>">
                <i class="fas fa-warehouse"></i>
                Склад
            </a>
            <?php else: ?>
            <a href="<?= APP_URL ?>/modules/warehouse/warehouse_dashboard.php" class="nav-link <?= ($currentModule ?? '') == 'warehouse' ? 'active' : '' ?>">
                <i class="fas fa-warehouse"></i>
                Склад
            </a>
            <?php endif; ?>
        </li>
        <?php endif; ?>

        <?php if (hasRole(['admin', 'director', 'manager', 'technologist', 'operator', 'warehouse_keeper'])): ?>
        <!-- Оборудование - расширенный доступ -->
        <li>
            <a href="<?= APP_URL ?>/modules/equipment/index.php" class="nav-link <?= ($currentModule ?? '') == 'equipment' ? 'active' : '' ?>">
                <i class="fas fa-tools"></i>
                Оборудование
            </a>
        </li>
        <?php endif; ?>

        <?php if (hasRole(['admin', 'director', 'manager', 'logistician', 'warehouse_keeper'])): ?>
        <!-- Отгрузка -->
        <li>
            <a href="<?= APP_URL ?>/modules/shipment/index.php" class="nav-link <?= ($currentModule ?? '') == 'shipment' ? 'active' : '' ?>">
                <i class="fas fa-truck"></i>
                Отгрузка
            </a>
        </li>
        <?php endif; ?>

        <?php if (hasRole(['admin', 'director', 'manager', 'technologist', 'warehouse_keeper'])): ?>
        <!-- Документы -->
        <li>
            <a href="<?= APP_URL ?>/modules/documents/index.php" class="nav-link <?= ($currentModule ?? '') == 'documents' ? 'active' : '' ?>">
                <i class="fas fa-file-contract"></i>
                Документы
            </a>
        </li>
        <?php endif; ?>

        <?php if (hasRole(['admin', 'director'])): ?>
        <!-- Сотрудники - только админ и директор -->
        <li>
            <a href="<?= APP_URL ?>/modules/employees/index.php" class="nav-link <?= ($currentModule ?? '') == 'employees' ? 'active' : '' ?>">
                <i class="fas fa-users"></i>
                Сотрудники
            </a>
        </li>
        <?php endif; ?>
    </ul>

    <div class="user-menu">
        <div class="user-avatar">
            <svg viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
        </div>
        <div class="user-info">
            <span class="user-name"><?= e($_SESSION['full_name']) ?></span>
            <span class="user-role"><?= e(getRoleName($_SESSION['role'])) ?></span>
        </div>
        <a href="<?= APP_URL ?>/modules/auth/logout.php" class="btn-logout">
            <i class="fas fa-sign-out-alt"></i>
            Выход
        </a>
    </div>

    <button class="mobile-menu-btn" onclick="toggleMobileMenu()">
        <span></span>
        <span></span>
        <span></span>
    </button>
</nav>

<script>
function toggleMobileMenu() {
    const navMenu = document.querySelector('.nav-menu');
    const mobileMenuBtn = document.querySelector('.mobile-menu-btn');
    
    if (navMenu.style.display === 'flex') {
        navMenu.style.display = 'none';
        mobileMenuBtn.classList.remove('active');
    } else {
        navMenu.style.display = 'flex';
        navMenu.style.flexDirection = 'column';
        navMenu.style.position = 'absolute';
        navMenu.style.top = '100%';
        navMenu.style.left = '0';
        navMenu.style.right = '0';
        navMenu.style.background = 'rgba(10, 10, 15, 0.98)';
        navMenu.style.padding = '1rem';
        navMenu.style.boxShadow = '0 8px 24px rgba(0, 0, 0, 0.4)';
        mobileMenuBtn.classList.add('active');
    }
}

// Scroll effect for navbar
window.addEventListener('scroll', function() {
    const navbar = document.getElementById('navbar');
    if (window.scrollY > 50) {
        navbar.classList.add('scrolled');
    } else {
        navbar.classList.remove('scrolled');
    }
});
</script>
