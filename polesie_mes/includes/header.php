<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? APP_TITLE) ?></title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="<?= APP_URL ?>/assets/css/style.css" rel="stylesheet">
    
    <?php if (isset($additionalCSS)): ?>
        <?php foreach ($additionalCSS as $css): ?>
            <link href="<?= e($css) ?>" rel="stylesheet">
        <?php endforeach; ?>
    <?php endif; ?>
</head>
<body class="<?= e($bodyClass ?? '') ?>">
    <?php if (isLoggedIn()): ?>
    <!-- Верхняя навигационная панель -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary fixed-top shadow-sm">
        <div class="container-fluid px-3">
            <a class="navbar-brand d-flex align-items-center" href="<?= APP_URL ?>">
                <i class="fas fa-industry me-2"></i>
                <span class="fw-bold">PolesieMES</span>
            </a>
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <?php 
                    // Проверка роли администратора
                    $currentRole = $_SESSION['role'] ?? '';
                    $isAdmin = ($currentRole === 'admin'); 
                    ?>
                    
                    <li class="nav-item">
                        <a class="nav-link <?= ($currentPage ?? '') == 'dashboard' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/dashboard/index.php">
                            <i class="fas fa-tachometer-alt me-1"></i>
                            <span class="d-none d-lg-inline">Панель управления</span>
                        </a>
                    </li>
                    
                    <?php if ($isAdmin || hasRole(['manager'])): ?>
                    <!-- Заказы - только админ и менеджеры -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?= ($currentModule ?? '') == 'orders' ? 'active' : '' ?>" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-shopping-cart me-1"></i>
                            <span class="d-none d-lg-inline">Заказы</span>
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="<?= APP_URL ?>/modules/orders/index.php"><i class="fas fa-list me-2"></i>Все заказы</a></li>
                            <li><a class="dropdown-item" href="<?= APP_URL ?>/modules/orders/create.php"><i class="fas fa-plus me-2"></i>Создать заказ</a></li>
                        </ul>
                    </li>
                    <?php endif; ?>
                    
                    <?php if ($isAdmin || hasRole(['manager', 'operator', 'warehouse_keeper'])): ?>
                    <!-- Производство - все кроме quality_inspector -->
                    <li class="nav-item">
                        <a class="nav-link <?= ($currentPage ?? '') == 'production' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/production/index.php">
                            <i class="fas fa-cogs me-1"></i>
                            <span class="d-none d-lg-inline">Производство</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <?php if ($isAdmin || hasRole(['manager', 'quality_inspector'])): ?>
                    <!-- Контроль качества -->
                    <li class="nav-item">
                        <a class="nav-link <?= ($currentPage ?? '') == 'quality' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/quality/index.php">
                            <i class="fas fa-check-circle me-1"></i>
                            <span class="d-none d-lg-inline">Контроль качества</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <?php if ($isAdmin || hasRole(['manager', 'warehouse_keeper'])): ?>
                    <!-- Склад -->
                    <li class="nav-item">
                        <a class="nav-link <?= ($currentPage ?? '') == 'warehouse' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/warehouse/warehouse_dashboard.php">
                            <i class="fas fa-warehouse me-1"></i>
                            <span class="d-none d-lg-inline">Склад</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <?php if ($isAdmin || hasRole(['manager', 'operator', 'warehouse_keeper'])): ?>
                    <!-- Оборудование -->
                    <li class="nav-item">
                        <a class="nav-link <?= ($currentPage ?? '') == 'equipment' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/equipment/index.php">
                            <i class="fas fa-tools me-1"></i>
                            <span class="d-none d-lg-inline">Оборудование</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <?php if ($isAdmin || hasRole(['manager', 'warehouse_keeper'])): ?>
                    <!-- Отгрузка -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?= ($currentModule ?? '') == 'shipment' ? 'active' : '' ?>" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-truck-loading me-1"></i>
                            <span class="d-none d-lg-inline">Отгрузка</span>
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="<?= APP_URL ?>/modules/shipment/index.php"><i class="fas fa-list me-2"></i>Все отгрузки</a></li>
                            <li><a class="dropdown-item" href="<?= APP_URL ?>/modules/shipment/ship.php"><i class="fas fa-shipping-fast me-2"></i>Отгрузить</a></li>
                            <li><a class="dropdown-item" href="<?= APP_URL ?>/modules/shipment/complete.php"><i class="fas fa-check-double me-2"></i>Завершить отгрузку</a></li>
                        </ul>
                    </li>
                    <?php endif; ?>
                    
                    <?php if ($isAdmin || hasRole(['manager', 'technologist'])): ?>
                    <!-- Документы -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?= ($currentModule ?? '') == 'documents' ? 'active' : '' ?>" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-file-alt me-1"></i>
                            <span class="d-none d-lg-inline">Документы</span>
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="<?= APP_URL ?>/modules/documents/index.php"><i class="fas fa-folder me-2"></i>Все документы</a></li>
                            <li><a class="dropdown-item" href="<?= APP_URL ?>/modules/gost_docs/index.php"><i class="fas fa-book me-2"></i>ГОСТы</a></li>
                        </ul>
                    </li>
                    <?php endif; ?>
                    
                    <?php if ($isAdmin || hasRole(['manager'])): ?>
                    <!-- Отчеты -->
                    <li class="nav-item">
                        <a class="nav-link <?= ($currentPage ?? '') == 'reports' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/reports/index.php">
                            <i class="fas fa-chart-bar me-1"></i>
                            <span class="d-none d-lg-inline">Отчеты</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <?php if ($isAdmin): ?>
                    <!-- Администрирование -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-users-cog me-1"></i>
                            <span class="d-none d-lg-inline">Админ</span>
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="<?= APP_URL ?>/modules/employees/index.php"><i class="fas fa-user-tie me-2"></i>Сотрудники</a></li>
                            <li><a class="dropdown-item" href="<?= APP_URL ?>/modules/auth/users.php"><i class="fas fa-users me-2"></i>Пользователи</a></li>
                        </ul>
                    </li>
                    <?php endif; ?>
                </ul>
                
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user-circle me-1 fa-lg"></i>
                            <span class="ms-1 d-none d-lg-inline"><?= e($_SESSION['full_name']) ?></span>
                            <span class="badge bg-light text-dark ms-2"><?= e(getRoleName($_SESSION['role'])) ?></span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="<?= APP_URL ?>/modules/auth/profile.php"><i class="fas fa-user me-2"></i>Профиль</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="<?= APP_URL ?>/modules/auth/logout.php"><i class="fas fa-sign-out-alt me-2"></i>Выход</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    
    <!-- Основной контент -->
    <main class="main-content-full">
        <div class="container-fluid py-3">
            <?php
            $flash = getFlashMessage();
            if ($flash):
            ?>
            <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show py-2" role="alert">
                <i class="fas fa-<?= $flash['type'] == 'success' ? 'check-circle' : ($flash['type'] == 'error' ? 'exclamation-circle' : 'info-circle') ?> me-2"></i>
                <?= e($flash['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <?php if (isset($pageHeader)): ?>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h1 class="h4 mb-0"><?= e($pageHeader) ?></h1>
                <?php if (isset($pageActions)): ?>
                <div class="btn-group">
                    <?= $pageActions ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <?php endif; // isLoggedIn() ?>
