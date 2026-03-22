<?php
/**
 * Панель управления (Dashboard) системы PolesieMES
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

<?php include __DIR__ . '/../../includes/header.php'; ?>

<div class="row mb-4">
    <div class="col-12">
        <h2 class="h4 mb-1">Добро пожаловать, <?= e($user['full_name']) ?>!</h2>
        <p class="text-muted">Обзор состояния производства на <?= formatDate(date('Y-m-d')) ?></p>
    </div>
</div>

<!-- Статистические карточки -->
<div class="row g-4 mb-4">
    <!-- Заказы -->
    <div class="col-xl-3 col-md-6">
        <div class="card stat-card bg-primary text-white h-100">
            <div class="card-body">
                <div class="stat-icon"><i class="fas fa-shopping-cart"></i></div>
                <div class="stat-value"><?= $stats['orders']['total'] ?? 0 ?></div>
                <div class="stat-label text-white-50">Всего заказов</div>
                <div class="mt-3 d-flex justify-content-between small">
                    <span><i class="fas fa-plus me-1"></i>Новые: <?= $stats['orders']['new_orders'] ?? 0 ?></span>
                    <span><i class="fas fa-cog me-1"></i>В пр-ве: <?= $stats['orders']['production_orders'] ?? 0 ?></span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Выручка за месяц -->
    <div class="col-xl-3 col-md-6">
        <div class="card stat-card bg-success text-white h-100">
            <div class="card-body">
                <div class="stat-icon"><i class="fas fa-ruble-sign"></i></div>
                <div class="stat-value"><?= formatCurrency($stats['revenue']) ?></div>
                <div class="stat-label text-white-50">Выручка за месяц</div>
                <div class="mt-3 small text-white-50">
                    <i class="fas fa-check-circle me-1"></i>Завершенные заказы
                </div>
            </div>
        </div>
    </div>
    
    <!-- Производственные задания -->
    <div class="col-xl-3 col-md-6">
        <div class="card stat-card bg-warning text-dark h-100">
            <div class="card-body">
                <div class="stat-icon"><i class="fas fa-tasks"></i></div>
                <div class="stat-value"><?= $stats['tasks']['total_tasks'] ?? 0 ?></div>
                <div class="stat-label text-muted">Производственных заданий</div>
                <div class="mt-3 d-flex justify-content-between small">
                    <span><i class="fas fa-play me-1"></i>В работе: <?= $stats['tasks']['in_progress'] ?? 0 ?></span>
                    <span><i class="fas fa-clock me-1"></i>Заплан.: <?= $stats['tasks']['planned'] ?? 0 ?></span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Оборудование -->
    <div class="col-xl-3 col-md-6">
        <div class="card stat-card bg-info text-white h-100">
            <div class="card-body">
                <div class="stat-icon"><i class="fas fa-industry"></i></div>
                <div class="stat-value"><?= $stats['equipment']['operational'] ?? 0 ?>/<?= $stats['equipment']['total_equipment'] ?? 0 ?></div>
                <div class="stat-label text-white-50">Оборудование в работе</div>
                <div class="mt-3 small">
                    <?php if ($stats['equipment']['broken'] > 0): ?>
                    <span class="badge bg-danger"><i class="fas fa-exclamation-triangle me-1"></i><?= $stats['equipment']['broken'] ?> неисправно</span>
                    <?php else: ?>
                    <span class="text-white-50"><i class="fas fa-check me-1"></i>Все исправно</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Основной контент -->
<div class="row g-4">
    <!-- Последние заказы -->
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-shopping-cart me-2"></i>Последние заказы</span>
                <a href="<?= APP_URL ?>/modules/orders/index.php" class="btn btn-sm btn-outline-primary">Все заказы</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
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
                                    <a href="<?= APP_URL ?>/modules/orders/view.php?id=<?= $order['id'] ?>" class="text-decoration-none">
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
    
    <!-- Активные задания и проблемы -->
    <div class="col-lg-4">
        <!-- Активные производственные задания -->
        <div class="card mb-4">
            <div class="card-header">
                <i class="fas fa-cogs me-2"></i>Требуют внимания
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush">
                    <?php foreach ($activeTasks as $task): ?>
                    <div class="list-group-item px-3 py-3">
                        <div class="d-flex w-100 justify-content-between">
                            <h6 class="mb-1 small"><?= e($task['task_number']) ?></h6>
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
        
        <!-- Материалы с низким запасом -->
        <div class="card">
            <div class="card-header text-danger">
                <i class="fas fa-exclamation-triangle me-2"></i>Заканчиваются материалы
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush">
                    <?php foreach ($lowStockMaterials as $material): ?>
                    <div class="list-group-item px-3 py-2">
                        <div class="d-flex justify-content-between">
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

<?php include __DIR__ . '/../../includes/footer.php'; ?>
