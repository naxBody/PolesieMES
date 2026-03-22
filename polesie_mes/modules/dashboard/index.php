<?php
/**
 * Панель управления (Dashboard) системы PolesieMES
 * Современный дизайн 2026 - Glassmorphism с оранжево-коралловым градиентом
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

// Проверка авторизации
requireAuth();

$db = getDB();
$user = getCurrentUser();

// Получение имени пользователя для приветствия
$userFirstName = !empty($user['full_name']) ? explode(' ', $user['full_name'])[0] : 'Пользователь';
$userRole = $user['role'] ?? 'user';

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

// Эффективность производства (за текущий месяц)
$stmt = $db->query("
    SELECT 
        COUNT(*) as total_completed,
        AVG(DATEDIFF(completed_at, created_at)) as avg_completion_days
    FROM orders
    WHERE status = 'completed'
    AND MONTH(created_at) = MONTH(CURRENT_DATE())
    AND YEAR(created_at) = YEAR(CURRENT_DATE())
");
$efficiency = $stmt->fetch();

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
            /* Industrial 2026 Color Palette */
            --primary: #0a84ff;
            --primary-dark: #0066cc;
            --secondary: #5e5ce6;
            --accent: #30d158;
            --warning: #ffd60a;
            --danger: #ff453a;
            
            /* Neutral tones */
            --bg-dark: #0d1117;
            --bg-card: #161b22;
            --bg-input: #0d1117;
            --border: #30363d;
            --text-primary: #f0f6fc;
            --text-secondary: #8b949e;
            --text-muted: #6e7681;
            
            /* Gradients */
            --gradient-primary: linear-gradient(135deg, #0a84ff 0%, #5e5ce6 100%);
            --gradient-bg: linear-gradient(180deg, #0d1117 0%, #161b22 100%);
            --gradient-glow: radial-gradient(ellipse at center, rgba(10, 132, 255, 0.15) 0%, transparent 70%);
            --gradient-nav: linear-gradient(135deg, #0a84ff 0%, #5e5ce6 100%);
            
            /* Shadows */
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.3);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.4);
            --shadow-lg: 0 12px 24px rgba(0, 0, 0, 0.5);
            --glow-primary: 0 0 20px rgba(10, 132, 255, 0.3);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--gradient-bg);
            min-height: 100vh;
            color: var(--text-primary);
            overflow-x: hidden;
            position: relative;
        }
        
        /* Animated grid background */
        .grid-bg {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: 
                linear-gradient(rgba(48, 54, 61, 0.3) 1px, transparent 1px),
                linear-gradient(90deg, rgba(48, 54, 61, 0.3) 1px, transparent 1px);
            background-size: 60px 60px;
            animation: gridMove 20s linear infinite;
            z-index: 0;
            pointer-events: none;
        }
        
        @keyframes gridMove {
            0% { transform: translate(0, 0); }
            100% { transform: translate(60px, 60px); }
        }
        
        /* Glow effect */
        .glow-overlay {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 800px;
            height: 800px;
            background: var(--gradient-glow);
            border-radius: 50%;
            z-index: 0;
            animation: pulse 4s ease-in-out infinite;
            pointer-events: none;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 0.5; transform: translate(-50%, -50%) scale(1); }
            50% { opacity: 0.8; transform: translate(-50%, -50%) scale(1.1); }
        }
        
        /* Navbar */
        .navbar {
            background: rgba(22, 27, 34, 0.95) !important;
            backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--border);
            padding: 0.75rem 1.5rem;
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        
        .navbar-brand {
            font-weight: 700;
            font-size: 1.4rem;
            letter-spacing: -0.5px;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .brand-logo {
            width: 42px;
            height: 42px;
            background: var(--gradient-primary);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--glow-primary);
        }
        
        .brand-logo svg {
            width: 24px;
            height: 24px;
            fill: white;
        }
        
        .nav-link {
            font-weight: 500;
            padding: 0.5rem 1rem !important;
            border-radius: 8px;
            transition: all 0.3s ease;
            margin: 0 0.25rem;
            color: var(--text-secondary) !important;
        }
        
        .nav-link:hover {
            background: rgba(10, 132, 255, 0.15);
            color: var(--text-primary) !important;
        }
        
        .nav-link.active {
            background: var(--gradient-primary);
            color: white !important;
            box-shadow: var(--glow-primary);
        }
        
        .dropdown-menu {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 12px;
            box-shadow: var(--shadow-lg);
            padding: 0.5rem 0;
        }
        
        .dropdown-item {
            padding: 0.6rem 1.25rem;
            transition: all 0.2s ease;
            color: var(--text-secondary);
        }
        
        .dropdown-item:hover {
            background: rgba(10, 132, 255, 0.15);
            color: var(--text-primary);
        }
        
        .dropdown-item i {
            width: 20px;
            text-align: center;
            margin-right: 0.5rem;
        }
        
        /* Main Content */
        .main-content {
            padding: 2rem;
            max-width: 1400px;
            margin: 0 auto;
            position: relative;
            z-index: 10;
        }
        
        .page-header {
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .page-title {
            font-size: 1.75rem;
            font-weight: 700;
            background: linear-gradient(135deg, #f0f6fc 0%, #8b949e 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 0.25rem;
        }
        
        .page-subtitle {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }
        
        .quick-actions {
            display: flex;
            gap: 0.75rem;
        }
        
        .btn-quick {
            padding: 0.6rem 1.25rem;
            background: rgba(48, 54, 61, 0.5);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text-primary);
            font-weight: 500;
            font-size: 0.875rem;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn-quick:hover {
            background: rgba(10, 132, 255, 0.2);
            border-color: var(--primary);
            transform: translateY(-2px);
        }
        
        /* Stat Cards */
        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
            position: relative;
            transition: all 0.3s ease;
            height: 100%;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary);
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
            opacity: 0.1;
        }
        
        .stat-value {
            font-size: 2.25rem;
            font-weight: 800;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
        }
        
        .stat-label {
            color: var(--text-secondary);
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
            color: var(--text-muted);
        }
        
        .stat-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.5rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .stat-badge.new { background: rgba(10, 132, 255, 0.2); color: #5ac8fa; }
        .stat-badge.production { background: rgba(255, 214, 10, 0.2); color: #ffd60a; }
        .stat-badge.completed { background: rgba(48, 209, 88, 0.2); color: #30d158; }
        .stat-badge.danger { background: rgba(255, 69, 58, 0.2); color: #ff453a; }
        
        /* Cards */
        .card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: var(--shadow-md);
            transition: all 0.3s ease;
            overflow: hidden;
        }
        
        .card:hover {
            box-shadow: var(--shadow-lg);
            border-color: rgba(10, 132, 255, 0.3);
        }
        
        .card-header {
            background: rgba(48, 54, 61, 0.3);
            border-bottom: 1px solid var(--border);
            padding: 1.25rem 1.5rem;
            font-weight: 700;
            font-size: 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: var(--text-primary);
        }
        
        .card-header i, .card-header svg {
            margin-right: 0.5rem;
            fill: var(--primary);
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
            background: rgba(48, 54, 61, 0.5);
            border: none;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.7rem;
            letter-spacing: 0.5px;
            padding: 1rem 1.25rem;
            color: var(--text-secondary);
        }
        
        .table tbody tr {
            transition: all 0.2s ease;
            border-bottom: 1px solid var(--border);
        }
        
        .table tbody tr:hover {
            background: rgba(48, 54, 61, 0.3);
        }
        
        .table tbody td {
            padding: 1rem 1.25rem;
            vertical-align: middle;
            font-size: 0.9rem;
            color: var(--text-primary);
        }
        
        /* Badges */
        .badge {
            font-weight: 600;
            padding: 0.4rem 0.75rem;
            border-radius: 8px;
            font-size: 0.75rem;
        }
        
        .badge.bg-primary { background: rgba(10, 132, 255, 0.2); color: #5ac8fa; }
        .badge.bg-success { background: rgba(48, 209, 88, 0.2); color: #30d158; }
        .badge.bg-warning { background: rgba(255, 214, 10, 0.2); color: #ffd60a; }
        .badge.bg-danger { background: rgba(255, 69, 58, 0.2); color: #ff453a; }
        .badge.bg-secondary { background: rgba(48, 54, 61, 0.5); color: var(--text-secondary); }
        
        /* Buttons */
        .btn {
            font-weight: 600;
            padding: 0.5rem 1.25rem;
            border-radius: 10px;
            transition: all 0.3s ease;
            border: none;
        }
        
        .btn-outline-primary {
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
        }
        
        .btn-outline-primary:hover {
            background: var(--gradient-primary);
            border-color: transparent;
            color: white;
            transform: translateY(-2px);
            box-shadow: var(--glow-primary);
        }
        
        /* List Groups */
        .list-group-item {
            background: transparent;
            border: none;
            border-bottom: 1px solid var(--border);
            padding: 1rem 1.25rem;
            transition: all 0.2s ease;
            color: var(--text-secondary);
        }
        
        .list-group-item:hover {
            background: rgba(48, 54, 61, 0.3);
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
        
        /* User dropdown */
        .user-avatar {
            width: 32px;
            height: 32px;
            background: var(--gradient-primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 0.5rem;
            box-shadow: var(--glow-primary);
        }
        
        .user-avatar svg {
            width: 18px;
            height: 18px;
            fill: white;
        }
        
        /* Progress bars */
        .progress {
            background: rgba(48, 54, 61, 0.5);
            border-radius: 10px;
            height: 8px;
        }
        
        .progress-bar {
            background: var(--gradient-primary);
            border-radius: 10px;
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
                background: var(--bg-card);
                padding: 1rem;
                border-radius: 12px;
                margin-top: 1rem;
                border: 1px solid var(--border);
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <!-- Background elements -->
    <div class="grid-bg"></div>
    <div class="glow-overlay"></div>
    
    <!-- Навигация -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center" href="<?= APP_URL ?>">
                <div class="brand-logo">
                    <svg viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                </div>
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
                            <div class="user-avatar">
                                <svg viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                            </div>
                            <span><?= e($_SESSION['full_name']) ?></span>
                            <span class="badge bg-secondary ms-2"><?= e(getRoleName($_SESSION['role'])) ?></span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="<?= APP_URL ?>/modules/auth/profile.php"><i class="fas fa-user me-2"></i>Профиль</a></li>
                            <li><hr class="dropdown-divider" style="border-color: var(--border);"></li>
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
            <h1 class="page-title">Добро пожаловать, <?= e($userFirstName) ?>!</h1>
            <p class="page-subtitle">Обзор производства на <?= date('d.m.Y') ?></p>
        </div>
        
        <!-- Статистические карточки -->
        <div class="row g-4 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card">
                    <div class="card-body">
                        <div class="stat-icon"><i class="fas fa-shopping-cart"></i></div>
                        <div class="stat-value"><?= $stats['orders']['total'] ?? 0 ?></div>
                        <div class="stat-label">Всего заказов</div>
                        <div class="stat-details">
                            <span class="stat-badge new"><i class="fas fa-plus-circle me-1"></i>Новые: <?= $stats['orders']['new_orders'] ?? 0 ?></span>
                            <span class="stat-badge production"><i class="fas fa-cog me-1"></i>В пр-ве: <?= $stats['orders']['production_orders'] ?? 0 ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card">
                    <div class="card-body">
                        <div class="stat-icon"><i class="fas fa-ruble-sign"></i></div>
                        <div class="stat-value"><?= formatCurrency($stats['revenue']) ?></div>
                        <div class="stat-label">Выручка за месяц</div>
                        <div class="stat-details">
                            <span class="stat-badge completed"><i class="fas fa-check-circle me-1"></i>Завершено</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card">
                    <div class="card-body">
                        <div class="stat-icon"><i class="fas fa-tasks"></i></div>
                        <div class="stat-value"><?= $stats['tasks']['total_tasks'] ?? 0 ?></div>
                        <div class="stat-label">Заданий</div>
                        <div class="stat-details">
                            <span class="stat-badge production"><i class="fas fa-play me-1"></i>В работе: <?= $stats['tasks']['in_progress'] ?? 0 ?></span>
                            <span class="stat-badge new"><i class="fas fa-clock me-1"></i>План: <?= $stats['tasks']['planned'] ?? 0 ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card">
                    <div class="card-body">
                        <div class="stat-icon"><i class="fas fa-industry"></i></div>
                        <div class="stat-value"><?= $stats['equipment']['operational'] ?? 0 ?>/<?= $stats['equipment']['total_equipment'] ?? 0 ?></div>
                        <div class="stat-label">Оборудование</div>
                        <div class="stat-details">
                            <?php if (($stats['equipment']['broken'] ?? 0) > 0): ?>
                            <span class="stat-badge danger"><i class="fas fa-exclamation-triangle me-1"></i><?= $stats['equipment']['broken'] ?></span>
                            <?php else: ?>
                            <span class="stat-badge completed"><i class="fas fa-check-circle me-1"></i>Все работает</span>
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
