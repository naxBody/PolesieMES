<?php
/**
 * Панель управления (Dashboard) системы PolesieMES
 * Современный дизайн
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

// Проверка авторизации
requireAuth();

$db = getDB();
$user = getCurrentUser();

// Получение статистики
$stats = [];

// Количество заказов по статусам
$stmt = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) as new_orders,
        SUM(CASE WHEN status = 'in_production' THEN 1 ELSE 0 END) as production_orders,
        SUM(CASE WHEN status = 'quality_check' THEN 1 ELSE 0 END) as qc_orders,
        SUM(CASE WHEN status = 'ready' THEN 1 ELSE 0 END) as ready_orders,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_orders
    FROM orders
");
$stats['orders'] = $stmt->fetch();

// Общая сумма завершенных заказов за месяц
$stmt = $db->query("
    SELECT COALESCE(SUM(total_amount), 0) as monthly_revenue
    FROM orders
    WHERE status = 'completed'
    AND MONTH(created_at) = MONTH(CURRENT_DATE())
    AND YEAR(created_at) = YEAR(CURRENT_DATE())
");
$stats['revenue'] = $stmt->fetch()['monthly_revenue'];

// Количество производственных заданий
$stmt = $db->query("
    SELECT 
        COUNT(*) as total_tasks,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'planned' THEN 1 ELSE 0 END) as planned,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
    FROM production_tasks
");
$stats['tasks'] = $stmt->fetch();

// Оборудование в работе/неисправное
$stmt = $db->query("
    SELECT 
        COUNT(*) as total_equipment,
        SUM(CASE WHEN status = 'operational' THEN 1 ELSE 0 END) as operational,
        SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) as maintenance,
        SUM(CASE WHEN status = 'broken' THEN 1 ELSE 0 END) as broken
    FROM equipment
");
$stats['equipment'] = $stmt->fetch();

// Последние заказы
$stmt = $db->query("
    SELECT o.*, c.name as customer_name
    FROM orders o
    LEFT JOIN customers c ON o.customer_id = c.id
    ORDER BY o.created_at DESC
    LIMIT 5
");
$recentOrders = $stmt->fetchAll();

// Производственные задания требующие внимания
$stmt = $db->query("
    SELECT pt.*, p.name as product_name, ps.name as stage_name, e.first_name, e.last_name
    FROM production_tasks pt
    LEFT JOIN products p ON pt.product_id = p.id
    LEFT JOIN production_stages ps ON pt.stage_id = ps.id
    LEFT JOIN employees e ON pt.assigned_to = e.id
    WHERE pt.status IN ('in_progress', 'paused')
    ORDER BY pt.planned_end ASC
    LIMIT 5
");
$activeTasks = $stmt->fetchAll();

// Проблемы с материалами (ниже минимального запаса)
$stmt = $db->query("
    SELECT * FROM materials
    WHERE current_stock < min_stock
    ORDER BY (min_stock - current_stock) DESC
    LIMIT 5
");
$lowStockMaterials = $stmt->fetchAll();

$pageTitle = 'Панель управления | ' . APP_NAME;
$currentPage = 'dashboard';
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --secondary: #ec4899;
            --accent: #06b6d4;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --dark: #0f172a;
            --light: #f8fafc;
            --gradient-primary: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
            --gradient-success: linear-gradient(135deg, #10b981 0%, #059669 100%);
            --gradient-warning: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            --gradient-info: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
            --gradient-danger: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            --gradient-nav: linear-gradient(135deg, #6366f1 0%, #ec4899 100%);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Outfit', sans-serif;
            background: #f1f5f9;
            color: var(--dark);
        }
        
        /* Navbar */
        .navbar {
            background: var(--gradient-nav) !important;
            box-shadow: 0 4px 20px rgba(99, 102, 241, 0.3);
            padding: 0.75rem 1.5rem;
        }
        
        .navbar-brand {
            font-weight: 800;
            font-size: 1.4rem;
            letter-spacing: -0.5px;
        }
        
        .nav-link {
            font-weight: 500;
            padding: 0.5rem 1rem !important;
            border-radius: 8px;
            transition: all 0.3s ease;
            margin: 0 0.25rem;
        }
        
        .nav-link:hover {
            background: rgba(255, 255, 255, 0.15);
        }
        
        .nav-link.active {
            background: rgba(255, 255, 255, 0.25);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        
        .dropdown-menu {
            border: none;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            padding: 0.5rem 0;
        }
        
        .dropdown-item {
            padding: 0.6rem 1.25rem;
            transition: all 0.2s ease;
        }
        
        .dropdown-item:hover {
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            color: var(--primary);
        }
        
        /* Main Content */
        .main-content {
            padding: 2rem;
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .page-header {
            margin-bottom: 2rem;
        }
        
        .page-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }
        
        .page-subtitle {
            color: #64748b;
            font-size: 0.9rem;
        }
        
        /* Stat Cards */
        .stat-card {
            border: none;
            border-radius: 16px;
            overflow: hidden;
            position: relative;
            transition: all 0.3s ease;
            height: 100%;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }
        
        .stat-card.bg-primary {
            background: var(--gradient-primary) !important;
        }
        
        .stat-card.bg-success {
            background: var(--gradient-success) !important;
        }
        
        .stat-card.bg-warning {
            background: var(--gradient-warning) !important;
        }
        
        .stat-card.bg-info {
            background: var(--gradient-info) !important;
        }
        
        .stat-card .card-body {
            padding: 1.5rem;
            position: relative;
            z-index: 1;
        }
        
        .stat-icon {
            position: absolute;
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 3.5rem;
            opacity: 0.15;
        }
        
        .stat-value {
            font-size: 2.25rem;
            font-weight: 800;
            color: white;
            margin-bottom: 0.25rem;
        }
        
        .stat-label {
            color: rgba(255, 255, 255, 0.85);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        
        .stat-details {
            margin-top: 1rem;
            display: flex;
            gap: 1rem;
            font-size: 0.8rem;
            color: rgba(255, 255, 255, 0.9);
        }
        
        /* Cards */
        .card {
            border: none;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            overflow: hidden;
        }
        
        .card:hover {
            box-shadow: 0 10px 35px rgba(0, 0, 0, 0.12);
        }
        
        .card-header {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border-bottom: 1px solid #e2e8f0;
            padding: 1.25rem 1.5rem;
            font-weight: 700;
            font-size: 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .card-header i {
            margin-right: 0.5rem;
            color: var(--primary);
        }
        
        .card-body {
            padding: 1.5rem;
        }
        
        /* Tables */
        .table-responsive {
            border-radius: 12px;
            overflow: hidden;
        }
        
        .table {
            margin-bottom: 0;
        }
        
        .table thead th {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border: none;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.7rem;
            letter-spacing: 0.5px;
            padding: 1rem 1.25rem;
            color: #475569;
        }
        
        .table tbody tr {
            transition: all 0.2s ease;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .table tbody tr:hover {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
        }
        
        .table tbody td {
            padding: 1rem 1.25rem;
            vertical-align: middle;
            font-size: 0.9rem;
        }
        
        /* Badges */
        .badge {
            font-weight: 600;
            padding: 0.4rem 0.75rem;
            border-radius: 8px;
            font-size: 0.75rem;
        }
        
        .badge.bg-primary {
            background: var(--gradient-primary) !important;
        }
        
        .badge.bg-success {
            background: var(--gradient-success) !important;
        }
        
        .badge.bg-warning {
            background: var(--gradient-warning) !important;
        }
        
        .badge.bg-danger {
            background: var(--gradient-danger) !important;
        }
        
        /* Buttons */
        .btn {
            font-weight: 600;
            padding: 0.5rem 1.25rem;
            border-radius: 10px;
            transition: all 0.3s ease;
            border: none;
        }
        
        .btn-outline-primary {
            color: var(--primary);
            border: 2px solid var(--primary);
        }
        
        .btn-outline-primary:hover {
            background: var(--gradient-primary);
            border-color: transparent;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(99, 102, 241, 0.4);
        }
        
        /* List Groups */
        .list-group-item {
            border: none;
            border-bottom: 1px solid #f1f5f9;
            padding: 1rem 1.25rem;
            transition: all 0.2s ease;
        }
        
        .list-group-item:hover {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
        }
        
        .list-group-item:last-child {
            border-bottom: none;
        }
        
        /* Alerts */
        .alert {
            border: none;
            border-radius: 12px;
            padding: 1rem 1.25rem;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }
            
            .stat-value {
                font-size: 1.75rem;
            }
            
            .navbar-collapse {
                background: var(--gradient-nav);
                padding: 1rem;
                border-radius: 12px;
                margin-top: 1rem;
            }
        }
    </style>
</head>
<body>
    <!-- Навигация -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center" href="<?= APP_URL ?>">
                <i class="fas fa-bolt me-2"></i>
                <span>PolesieMES</span>
            </a>
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link <?= ($currentPage ?? '') == 'dashboard' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/dashboard/index.php">
                            <i class="fas fa-chart-line me-1"></i>
                            Главная
                        </a>
                    </li>
                    
                    <?php if (hasRole(['admin', 'manager'])): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-shopping-cart me-1"></i>
                            Заказы
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="<?= APP_URL ?>/modules/orders/index.php"><i class="fas fa-list me-2"></i>Все заказы</a></li>
                            <li><a class="dropdown-item" href="<?= APP_URL ?>/modules/orders/create.php"><i class="fas fa-plus me-2"></i>Создать заказ</a></li>
                        </ul>
                    </li>
                    <?php endif; ?>
                    
                    <?php if (hasRole(['admin', 'manager', 'technologist', 'operator'])): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= APP_URL ?>/modules/production/index.php">
                            <i class="fas fa-cogs me-1"></i>
                            Производство
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <?php if (hasRole('admin')): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= APP_URL ?>/modules/employees/index.php">
                            <i class="fas fa-users me-1"></i>
                            Сотрудники
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
                
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" role="button" data-bs-toggle="dropdown">
                            <div style="width: 32px; height: 32px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 0.5rem;">
                                <i class="fas fa-user"></i>
                            </div>
                            <span><?= e($_SESSION['full_name']) ?></span>
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
    <div class="main-content">
        <div class="page-header">
            <h1 class="page-title">Добро пожаловать, <?= e($user['first_name']) ?>!</h1>
            <p class="page-subtitle">Обзор производства на <?= date('d.m.Y') ?></p>
        </div>
        
        <!-- Статистические карточки -->
        <div class="row g-4 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card bg-primary">
                    <div class="card-body">
                        <div class="stat-icon"><i class="fas fa-shopping-cart"></i></div>
                        <div class="stat-value"><?= $stats['orders']['total'] ?? 0 ?></div>
                        <div class="stat-label">Всего заказов</div>
                        <div class="stat-details">
                            <span><i class="fas fa-plus-circle me-1"></i>Новые: <?= $stats['orders']['new_orders'] ?? 0 ?></span>
                            <span><i class="fas fa-cog me-1"></i>В пр-ве: <?= $stats['orders']['production_orders'] ?? 0 ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card bg-success">
                    <div class="card-body">
                        <div class="stat-icon"><i class="fas fa-ruble-sign"></i></div>
                        <div class="stat-value"><?= formatCurrency($stats['revenue']) ?></div>
                        <div class="stat-label">Выручка за месяц</div>
                        <div class="stat-details">
                            <span><i class="fas fa-check-circle me-1"></i>Завершено</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card bg-warning">
                    <div class="card-body">
                        <div class="stat-icon"><i class="fas fa-tasks"></i></div>
                        <div class="stat-value"><?= $stats['tasks']['total_tasks'] ?? 0 ?></div>
                        <div class="stat-label">Заданий</div>
                        <div class="stat-details">
                            <span><i class="fas fa-play me-1"></i>В работе: <?= $stats['tasks']['in_progress'] ?? 0 ?></span>
                            <span><i class="fas fa-clock me-1"></i>План: <?= $stats['tasks']['planned'] ?? 0 ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card bg-info">
                    <div class="card-body">
                        <div class="stat-icon"><i class="fas fa-industry"></i></div>
                        <div class="stat-value"><?= $stats['equipment']['operational'] ?? 0 ?>/<?= $stats['equipment']['total_equipment'] ?? 0 ?></div>
                        <div class="stat-label">Оборудование</div>
                        <div class="stat-details">
                            <?php if ($stats['equipment']['broken'] > 0): ?>
                            <span class="badge bg-white text-danger"><i class="fas fa-exclamation-triangle me-1"></i><?= $stats['equipment']['broken'] ?></span>
                            <?php else: ?>
                            <span><i class="fas fa-check-circle me-1"></i>Все работает</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Основной контент -->
        <div class="row g-4">
            <div class="col-lg-8">
                <div class="card h-100">
                    <div class="card-header">
                        <span><i class="fas fa-shopping-cart me-2"></i>Последние заказы</span>
                        <a href="<?= APP_URL ?>/modules/orders/index.php" class="btn btn-sm btn-outline-primary">Все заказы</a>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>№ заказа</th>
                                        <th>Клиент</th>
                                        <th>Сумма</th>
                                        <th>Статус</th>
                                        <th>Дата</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentOrders as $order): ?>
                                    <tr>
                                        <td>
                                            <a href="<?= APP_URL ?>/modules/orders/view.php?id=<?= $order['id'] ?>" class="text-decoration-none fw-semibold">
                                                <?= e($order['order_number']) ?>
                                            </a>
                                        </td>
                                        <td><?= e($order['customer_name']) ?></td>
                                        <td><?= formatCurrency($order['total_amount']) ?></td>
                                        <td>
                                            <span class="badge <?= getOrderStatusClass($order['status']) ?>">
                                                <?= getOrderStatusName($order['status']) ?>
                                            </span>
                                        </td>
                                        <td><?= formatDate($order['order_date']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-4">
                <div class="card mb-4">
                    <div class="card-header">
                        <i class="fas fa-exclamation-triangle me-2"></i>Требуют внимания
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <?php foreach ($activeTasks as $task): ?>
                            <div class="list-group-item">
                                <div class="d-flex w-100 justify-content-between">
                                    <h6 class="mb-1 small fw-semibold"><?= e($task['task_number']) ?></h6>
                                    <small class="text-muted"><?= formatDate($task['planned_end']) ?></small>
                                </div>
                                <p class="mb-1 small text-muted"><?= e($task['product_name']) ?></p>
                                <small class="text-primary"><?= e($task['stage_name']) ?></small>
                            </div>
                            <?php endforeach; ?>
                            <?php if (empty($activeTasks)): ?>
                            <div class="p-3 text-center text-muted">
                                <i class="fas fa-check-circle fa-2x mb-2"></i>
                                <p class="mb-0">Все задания в норме</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header text-danger">
                        <i class="fas fa-triangle-exclamation me-2"></i>Заканчиваются материалы
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <?php foreach ($lowStockMaterials as $material): ?>
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between align-items-center">
                                    <small><?= e($material['name']) ?></small>
                                    <span class="badge bg-danger"><?= $material['current_stock'] ?> / <?= $material['min_stock'] ?></span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php if (empty($lowStockMaterials)): ?>
                            <div class="p-3 text-center text-muted">
                                <i class="fas fa-check-circle fa-2x mb-2"></i>
                                <p class="mb-0 small">Запасы в норме</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
