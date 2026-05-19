<?php
/**
 * Компонент навигации для системы PolesieMES
 * Используется на всех страницах для единообразия
 * Навигация как на главной странице - без переносов, с горизонтальной прокруткой на маленьких экранах
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
            <a href="<?= APP_URL ?>/modules/dashboard/index.php" class="nav-link <?= ($currentPage ?? '') == 'dashboard' || ($currentModule ?? '') == 'dashboard' ? 'active' : '' ?>">
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
            <a href="<?= APP_URL ?>/modules/warehouse/warehouse_dashboard.php" class="nav-link <?= ($currentModule ?? '') == 'warehouse' ? 'active' : '' ?>">
                <i class="fas fa-warehouse"></i>
                Склад
            </a>
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
            <span class="user-name"><?= e($_SESSION['full_name'] ?? 'Пользователь') ?></span>
            <span class="user-role"><?= e(getRoleName($_SESSION['role'] ?? '')) ?></span>
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

<style>
/* Стили навигации как на dashboard */
.navbar {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 1000;
    padding: 1rem 2rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: rgba(10, 10, 15, 0.8);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    transition: all 0.3s ease;
}

.navbar.scrolled {
    padding: 0.75rem 2rem;
    background: rgba(10, 10, 15, 0.95);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
}

.nav-brand {
    display: flex;
    align-items: center;
    gap: 1rem;
    text-decoration: none;
    color: #ffffff;
}

.brand-logo {
    width: 48px;
    height: 48px;
    background: linear-gradient(135deg, #FF6B6B 0%, #FF8E53 100%);
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 0 30px rgba(255, 107, 107, 0.4);
    transition: all 0.3s ease;
}

.brand-logo:hover {
    transform: scale(1.05);
}

.brand-logo svg {
    width: 28px;
    height: 28px;
    fill: white;
}

.brand-name {
    font-size: 1.4rem;
    font-weight: 700;
    letter-spacing: -0.5px;
    background: linear-gradient(135deg, #FF6B6B 0%, #FF8E53 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.nav-menu {
    display: flex;
    align-items: center;
    gap: 2rem;
    list-style: none;
    flex-wrap: nowrap;
    overflow-x: auto;
    scrollbar-width: none;
    -ms-overflow-style: none;
}

.nav-menu::-webkit-scrollbar {
    display: none;
}

.nav-link {
    color: rgba(255, 255, 255, 0.7);
    text-decoration: none;
    font-weight: 500;
    font-size: 0.95rem;
    transition: all 0.3s ease;
    position: relative;
    padding: 0.5rem 0;
    display: flex;
    align-items: center;
    gap: 0.4rem;
    white-space: nowrap;
}

.nav-link::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    width: 0;
    height: 2px;
    background: linear-gradient(135deg, #FF6B6B 0%, #FF8E53 100%);
    transition: width 0.3s ease;
}

.nav-link:hover,
.nav-link.active {
    color: #ffffff;
}

.nav-link:hover::after,
.nav-link.active::after {
    width: 100%;
}

.nav-link i {
    font-size: 1rem;
}

.user-menu {
    display: flex;
    align-items: center;
    gap: 1rem;
    flex-wrap: nowrap;
}

.user-avatar {
    width: 36px;
    height: 36px;
    background: linear-gradient(135deg, #FF6B6B 0%, #FF8E53 100%);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 0 30px rgba(255, 107, 107, 0.4);
    flex-shrink: 0;
}

.user-avatar svg {
    width: 20px;
    height: 20px;
    fill: white;
}

.user-info {
    display: flex;
    flex-direction: column;
    white-space: nowrap;
    min-width: 0;
}

.user-name {
    font-weight: 600;
    font-size: 0.85rem;
    color: #ffffff;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.user-role {
    font-size: 0.7rem;
    color: rgba(255, 255, 255, 0.7);
    background: rgba(255, 255, 255, 0.03);
    padding: 0.15rem 0.5rem;
    border-radius: 6px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    white-space: nowrap;
    display: inline-block;
}

.btn-logout {
    padding: 0.5rem 1rem;
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 10px;
    font-size: 0.8rem;
    font-weight: 600;
    font-family: inherit;
    color: #ffffff;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.3s ease;
    backdrop-filter: blur(10px);
    display: flex;
    align-items: center;
    gap: 0.4rem;
    white-space: nowrap;
    flex-shrink: 0;
}

.btn-logout:hover {
    background: rgba(255, 107, 107, 0.2);
    border-color: rgba(255, 107, 107, 0.3);
    transform: translateY(-2px);
}

.mobile-menu-btn {
    display: none;
    background: none;
    border: none;
    cursor: pointer;
    padding: 0.5rem;
}

.mobile-menu-btn span {
    display: block;
    width: 24px;
    height: 2px;
    background: #ffffff;
    margin: 5px 0;
    transition: all 0.3s ease;
    border-radius: 2px;
}

/* Адаптивность для маленьких экранов */
@media (max-width: 1200px) {
    .nav-menu {
        gap: 1.5rem;
    }
    
    .nav-link {
        font-size: 0.875rem;
    }
}

@media (max-width: 992px) {
    .navbar {
        padding: 1rem;
    }
    
    .nav-menu {
        gap: 1rem;
    }
    
    .user-info {
        display: none;
    }
    
    .btn-logout span {
        display: none;
    }
}

@media (max-width: 768px) {
    .nav-menu {
        display: none;
    }
    
    .mobile-menu-btn {
        display: block;
    }
    
    .nav-menu.active {
        display: flex;
        flex-direction: column;
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: rgba(10, 10, 15, 0.98);
        padding: 1rem;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
    }
}
</style>

<script>
function toggleMobileMenu() {
    const navMenu = document.querySelector('.nav-menu');
    const mobileMenuBtn = document.querySelector('.mobile-menu-btn');
    
    navMenu.classList.toggle('active');
    
    if (navMenu.classList.contains('active')) {
        mobileMenuBtn.classList.add('active');
    } else {
        mobileMenuBtn.classList.remove('active');
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
