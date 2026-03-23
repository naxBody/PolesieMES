<?php
/**
 * Модуль контроля производства - Главная страница
 * PolesieMES - Система управления производством ОАО "Полесьеэлектромаш"
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

// Проверка авторизации
requireAuth();

$db = getDB();
$user = getCurrentUser();

// Получение всех производственных заданий
$stmt = $db->query("
    SELECT pt.*, 
           p.name as product_name, p.product_code,
           ps.name as stage_name,
           e.first_name as assigned_first_name, e.last_name as assigned_last_name,
           o.order_number,
           TIMESTAMPDIFF(HOUR, pt.planned_start, pt.planned_end) as planned_hours,
           CASE 
               WHEN pt.actual_start IS NOT NULL THEN TIMESTAMPDIFF(HOUR, pt.actual_start, COALESCE(pt.actual_end, NOW()))
               ELSE NULL
           END as actual_hours
    FROM production_tasks pt
    LEFT JOIN products p ON pt.product_id = p.id
    LEFT JOIN production_stages ps ON pt.stage_id = ps.id
    LEFT JOIN employees e ON pt.assigned_to = e.id
    LEFT JOIN order_items oi ON pt.order_item_id = oi.id
    LEFT JOIN orders o ON oi.order_id = o.id
    ORDER BY pt.created_at DESC
");
$tasks = $stmt->fetchAll();

// Статистика по статусам заданий
$stmt = $db->query("
    SELECT 
        status,
        COUNT(*) as count
    FROM production_tasks
    GROUP BY status
");
$statusStats = [];
while ($row = $stmt->fetch()) {
    $statusStats[$row['status']] = $row['count'];
}

// Задания в работе
$stmt = $db->query("
    SELECT pt.*, p.name as product_name, ps.name as stage_name,
           e.first_name, e.last_name,
           o.order_number
    FROM production_tasks pt
    LEFT JOIN products p ON pt.product_id = p.id
    LEFT JOIN production_stages ps ON pt.stage_id = ps.id
    LEFT JOIN employees e ON pt.assigned_to = e.id
    LEFT JOIN order_items oi ON pt.order_item_id = oi.id
    LEFT JOIN orders o ON oi.order_id = o.id
    WHERE pt.status = 'in_progress'
    ORDER BY pt.planned_end ASC
");
$activeTasks = $stmt->fetchAll();

// Просроченные задания
$stmt = $db->query("
    SELECT pt.*, p.name as product_name, DATEDIFF(NOW(), pt.planned_end) as days_overdue
    FROM production_tasks pt
    LEFT JOIN products p ON pt.product_id = p.id
    WHERE pt.status NOT IN ('completed', 'rejected')
    AND pt.planned_end < NOW()
");
$overdueTasks = $stmt->fetchAll();

// Эффективность по этапам производства
$stmt = $db->query("
    SELECT ps.name as stage_name,
           COUNT(*) as total_tasks,
           SUM(CASE WHEN pt.status = 'completed' THEN 1 ELSE 0 END) as completed,
           ROUND(SUM(CASE WHEN pt.status = 'completed' THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 1) as efficiency
    FROM production_tasks pt
    JOIN production_stages ps ON pt.stage_id = ps.id
    GROUP BY ps.id, ps.name
    ORDER BY ps.sequence_order
");
$stageEfficiency = $stmt->fetchAll();

// Загрузка по сотрудникам
$stmt = $db->query("
    SELECT e.first_name, e.last_name,
           COUNT(*) as total_tasks,
           SUM(CASE WHEN pt.status = 'completed' THEN 1 ELSE 0 END) as completed,
           SUM(CASE WHEN pt.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress
    FROM production_tasks pt
    JOIN employees e ON pt.assigned_to = e.id
    GROUP BY e.id, e.first_name, e.last_name
    ORDER BY total_tasks DESC
    LIMIT 10
");
$employeeLoad = $stmt->fetchAll();

$pageTitle = 'Контроль производства | ' . APP_NAME;
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
            --primary-gradient-start: #FF6B6B;
            --primary-gradient-end: #FF8E53;
            --bg-dark: #0a0a0f;
            --bg-card: rgba(20, 20, 30, 0.6);
            --border: rgba(255, 255, 255, 0.1);
            --text-primary: #ffffff;
            --text-secondary: rgba(255, 255, 255, 0.7);
            --gradient-primary: linear-gradient(135deg, #FF6B6B 0%, #FF8E53 100%);
            --glass-bg: rgba(255, 255, 255, 0.03);
            --backdrop-blur: blur(20px);
            --success-color: #30d158;
            --warning-color: #ffd60a;
            --danger-color: #ff453a;
            --info-color: #5ac8fa;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Outfit', sans-serif;
            background: linear-gradient(180deg, #0a0a0f 0%, #12121a 100%);
            min-height: 100vh;
            color: var(--text-primary);
        }
        
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
            backdrop-filter: var(--backdrop-blur);
            border-bottom: 1px solid var(--border);
        }
        
        .nav-brand {
            display: flex;
            align-items: center;
            gap: 1rem;
            text-decoration: none;
            color: var(--text-primary);
        }
        
        .brand-logo {
            width: 48px;
            height: 48px;
            background: var(--gradient-primary);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .brand-name {
            font-size: 1.4rem;
            font-weight: 700;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .nav-menu {
            display: flex;
            align-items: center;
            gap: 2rem;
            list-style: none;
        }
        
        .nav-link {
            color: var(--text-secondary);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .nav-link:hover, .nav-link.active {
            color: var(--text-primary);
        }
        
        .main-content {
            padding: 6rem 2rem 2rem;
            max-width: 1600px;
            margin: 0 auto;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .page-title h1 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .page-title p {
            color: var(--text-secondary);
        }
        
        .btn-primary-custom {
            padding: 0.75rem 1.5rem;
            background: var(--gradient-primary);
            border: none;
            border-radius: 12px;
            font-weight: 600;
            color: white;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }
        
        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(255, 107, 107, 0.4);
            color: white;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 1.5rem;
            backdrop-filter: var(--backdrop-blur);
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .stat-label {
            color: var(--text-secondary);
            font-size: 0.875rem;
            margin-top: 0.25rem;
        }
        
        .card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            margin-bottom: 2rem;
        }
        
        .card-header {
            background: transparent;
            border-bottom: 1px solid var(--border);
            padding: 1.25rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-title {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 1.125rem;
            font-weight: 600;
        }
        
        .card-body {
            padding: 1.5rem;
        }
        
        .table {
            color: var(--text-primary);
            margin-bottom: 0;
        }
        
        .table thead th {
            background: var(--glass-bg);
            border-bottom: 1px solid var(--border);
            color: var(--text-secondary);
            font-weight: 600;
            font-size: 0.875rem;
            padding: 1rem;
        }
        
        .table tbody td {
            border-bottom: 1px solid var(--border);
            padding: 1rem;
            vertical-align: middle;
        }
        
        .table tbody tr:hover {
            background: var(--glass-bg);
        }
        
        .badge-status {
            padding: 0.35rem 0.75rem;
            border-radius: 8px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge-planned { background: rgba(90, 200, 250, 0.2); color: var(--info-color); }
        .badge-in_progress { background: rgba(255, 214, 10, 0.2); color: var(--warning-color); }
        .badge-paused { background: rgba(255, 142, 83, 0.2); color: #FF8E53; }
        .badge-completed { background: rgba(48, 209, 88, 0.2); color: var(--success-color); }
        .badge-rejected { background: rgba(255, 69, 58, 0.2); color: var(--danger-color); }
        
        .progress-bar-custom {
            height: 8px;
            background: var(--glass-bg);
            border-radius: 4px;
            overflow: hidden;
            margin-top: 0.5rem;
        }
        
        .progress-fill {
            height: 100%;
            background: var(--gradient-primary);
            border-radius: 4px;
            transition: width 0.3s ease;
        }
        
        .alert-warning-custom {
            background: rgba(255, 214, 10, 0.1);
            border: 1px solid rgba(255, 214, 10, 0.3);
            color: var(--warning-color);
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1rem;
        }
        
        .efficiency-good { color: var(--success-color); }
        .efficiency-medium { color: var(--warning-color); }
        .efficiency-bad { color: var(--danger-color); }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar">
        <a href="<?= APP_URL ?>/modules/dashboard/index.php" class="nav-brand">
            <div class="brand-logo">
                <svg viewBox="0 0 24 24" fill="white"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
            </div>
            <span class="brand-name">PolesieMES</span>
        </a>
        
        <ul class="nav-menu">
            <li><a href="<?= APP_URL ?>/modules/dashboard/index.php" class="nav-link"><i class="fas fa-chart-line"></i> Главная</a></li>
            <?php if (hasRole(['admin', 'manager'])): ?>
            <li><a href="<?= APP_URL ?>/modules/orders/index.php" class="nav-link"><i class="fas fa-shopping-cart"></i> Заказы</a></li>
            <?php endif; ?>
            <?php if (hasRole(['admin', 'manager', 'technologist', 'operator'])): ?>
            <li><a href="<?= APP_URL ?>/modules/production/index.php" class="nav-link active"><i class="fas fa-cogs"></i> Производство</a></li>
            <?php endif; ?>
            <?php if (hasRole(['admin', 'manager', 'warehouse_manager'])): ?>
            <li><a href="<?= APP_URL ?>/modules/warehouse/index.php" class="nav-link"><i class="fas fa-warehouse"></i> Склад</a></li>
            <?php endif; ?>
            <?php if (hasRole(['admin', 'manager', 'technologist'])): ?>
            <li><a href="<?= APP_URL ?>/modules/equipment/index.php" class="nav-link"><i class="fas fa-tools"></i> Оборудование</a></li>
            <?php endif; ?>
            <?php if (hasRole(['admin', 'manager', 'logistician'])): ?>
            <li><a href="<?= APP_URL ?>/modules/shipment/index.php" class="nav-link"><i class="fas fa-truck"></i> Отгрузка</a></li>
            <?php endif; ?>
            <?php if (hasRole(['admin', 'manager', 'technologist'])): ?>
            <li><a href="<?= APP_URL ?>/modules/gost_docs/index.php" class="nav-link"><i class="fas fa-file-contract"></i> ГОСТ Документы</a></li>
            <?php endif; ?>
            <?php if (hasRole('admin')): ?>
            <li><a href="<?= APP_URL ?>/modules/employees/index.php" class="nav-link"><i class="fas fa-users"></i> Сотрудники</a></li>
            <?php endif; ?>
        </ul>
        
        <div class="user-menu">
            <span style="color: var(--text-secondary);"><?= e(getCurrentUser()['username']) ?></span>
            <a href="<?= APP_URL ?>/modules/auth/logout.php" class="btn-logout" style="padding: 0.5rem 1rem; background: var(--glass-bg); border: 1px solid var(--border); border-radius: 8px; color: var(--text-primary); text-decoration: none;">Выход</a>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <div class="page-header">
            <div class="page-title">
                <h1><i class="fas fa-industry"></i> Контроль производства</h1>
                <p>Управление производственными заданиями и этапами</p>
            </div>
            <?php if (hasRole(['admin', 'manager', 'technologist'])): ?>
            <a href="create_task.php" class="btn-primary-custom">
                <i class="fas fa-plus"></i> Новое задание
            </a>
            <?php endif; ?>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?= count($tasks) ?></div>
                <div class="stat-label">Всего заданий</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= $statusStats['planned'] ?? 0 ?></div>
                <div class="stat-label">В плане</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= $statusStats['in_progress'] ?? 0 ?></div>
                <div class="stat-label">В работе</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= $statusStats['completed'] ?? 0 ?></div>
                <div class="stat-label">Выполнено</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--danger-color);"><?= count($overdueTasks) ?></div>
                <div class="stat-label">Просрочено</div>
            </div>
        </div>

        <!-- Overdue Tasks Alert -->
        <?php if (!empty($overdueTasks)): ?>
        <div class="alert-warning-custom">
            <i class="fas fa-exclamation-triangle"></i>
            <strong>Внимание!</strong> <?= count($overdueTasks) ?> заданий просрочено.
        </div>
        <?php endif; ?>

        <!-- Active Tasks -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-clock"></i> Активные задания
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($activeTasks)): ?>
                <p style="color: var(--text-secondary);">Нет активных заданий в работе</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>№ задания</th>
                                <th>Продукция</th>
                                <th>Этап</th>
                                <th>Заказ</th>
                                <th>Исполнитель</th>
                                <th>Плановое окончание</th>
                                <th>Статус</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activeTasks as $task): ?>
                            <tr>
                                <td><strong><?= e($task['task_number']) ?></strong></td>
                                <td><?= e($task['product_name']) ?></td>
                                <td><?= e($task['stage_name']) ?></td>
                                <td><?= e($task['order_number']) ?></td>
                                <td><?= e($task['first_name'] . ' ' . $task['last_name']) ?></td>
                                <td>
                                    <?php 
                                    $daysLeft = floor((strtotime($task['planned_end']) - time()) / 86400);
                                    if ($daysLeft < 0): ?>
                                        <span style="color: var(--danger-color);"><?= abs($daysLeft ) ?> дн. назад</span>
                                    <?php else: ?>
                                        <span style="color: var(--success-color);"><?= $daysLeft ?> дн.</span>
                                    <?php endif; ?>
                                    <br><small><?= date('d.m.Y H:i', strtotime($task['planned_end'])) ?></small>
                                </td>
                                <td>
                                    <span class="badge-status badge-<?= e($task['status']) ?>">
                                        <?= e($task['status']) ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- All Tasks -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-list"></i> Все производственные задания
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>№ задания</th>
                                <th>Продукция</th>
                                <th>Этап</th>
                                <th>Кол-во</th>
                                <th>Исполнитель</th>
                                <th>План начало</th>
                                <th>План окончание</th>
                                <th>Статус</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tasks as $task): ?>
                            <tr>
                                <td><strong><?= e($task['task_number']) ?></strong></td>
                                <td><?= e($task['product_name']) ?></td>
                                <td><?= e($task['stage_name']) ?></td>
                                <td><?= $task['quantity'] ?></td>
                                <td><?= e($task['assigned_first_name'] . ' ' . $task['assigned_last_name']) ?></td>
                                <td><?= date('d.m.Y', strtotime($task['planned_start'])) ?></td>
                                <td><?= date('d.m.Y', strtotime($task['planned_end'])) ?></td>
                                <td>
                                    <span class="badge-status badge-<?= e($task['status']) ?>">
                                        <?= e($task['status']) ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Stage Efficiency -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-chart-line"></i> Эффективность по этапам
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Этап</th>
                                <th>Всего заданий</th>
                                <th>Выполнено</th>
                                <th>Эффективность</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stageEfficiency as $stage): ?>
                            <tr>
                                <td><?= e($stage['stage_name']) ?></td>
                                <td><?= $stage['total_tasks'] ?></td>
                                <td><?= $stage['completed'] ?></td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                                        <div class="progress-bar-custom" style="flex: 1;">
                                            <div class="progress-fill" style="width: <?= $stage['efficiency'] ?>%;"></div>
                                        </div>
                                        <span class="<?= $stage['efficiency'] >= 80 ? 'efficiency-good' : ($stage['efficiency'] >= 50 ? 'efficiency-medium' : 'efficiency-bad') ?>">
                                            <?= $stage['efficiency'] ?>%
                                        </span>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
