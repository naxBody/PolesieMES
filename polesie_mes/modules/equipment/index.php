<?php
/**
 * Модуль управления оборудованием - Главная страница
 * PolesieMES - Система управления производством ОАО "Полесьеэлектромаш"
 * 
 * Контроль станков, инструментов, технического обслуживания
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

// Проверка авторизации
requireAuth();

$db = getDB();
$user = getCurrentUser();

// Получение всего оборудования с статусами
$stmt = $db->query("
    SELECT e.*, 
           c.name as category_name,
           l.name as location_name,
           CASE 
               WHEN e.status = 'operational' THEN 'normal'
               WHEN e.status = 'maintenance' THEN 'warning'
               WHEN e.status = 'broken' THEN 'critical'
               WHEN e.status = 'offline' THEN 'info'
               ELSE 'normal'
           END as status_class
    FROM equipment e
    LEFT JOIN equipment_categories c ON e.category_id = c.id
    LEFT JOIN locations l ON e.location_id = l.id
    ORDER BY 
        CASE e.status 
            WHEN 'broken' THEN 1 
            WHEN 'maintenance' THEN 2 
            WHEN 'offline' THEN 3 
            ELSE 4 
        END,
        e.name ASC
");
$equipment = $stmt->fetchAll();

// Статистика по оборудованию
$stmt = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'operational' THEN 1 ELSE 0 END) as operational,
        SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) as maintenance,
        SUM(CASE WHEN status = 'broken' THEN 1 ELSE 0 END) as broken,
        SUM(CASE WHEN status = 'offline' THEN 1 ELSE 0 END) as offline
    FROM equipment
");
$equipmentStats = $stmt->fetch();

// Оборудование требующее внимания (сломанное или на обслуживании)
$stmt = $db->query("
    SELECT e.*, c.name as category_name, 
           m.description as maintenance_description,
           m.scheduled_date, m.completed_date
    FROM equipment e
    LEFT JOIN equipment_categories c ON e.category_id = c.id
    LEFT JOIN maintenance_logs m ON e.id = m.equipment_id AND m.status = 'in_progress'
    WHERE e.status IN ('broken', 'maintenance')
    ORDER BY e.status DESC, e.name ASC
    LIMIT 10
");
$attentionEquipment = $stmt->fetchAll();

// Последние события обслуживания
$stmt = $db->query("
    SELECT ml.*, e.name as equipment_name, emp.first_name, emp.last_name
    FROM maintenance_logs ml
    LEFT JOIN equipment e ON ml.equipment_id = e.id
    LEFT JOIN employees emp ON ml.technician_id = emp.id
    ORDER BY ml.created_at DESC
    LIMIT 10
");
$recentMaintenance = $stmt->fetchAll();

// Предстоящее ТО
$stmt = $db->query("
    SELECT e.name as equipment_name, e.next_maintenance_date, c.name as category_name,
           DATEDIFF(e.next_maintenance_date, NOW()) as days_until
    FROM equipment e
    LEFT JOIN equipment_categories c ON e.category_id = c.id
    WHERE e.next_maintenance_date IS NOT NULL
    AND e.next_maintenance_date > NOW()
    AND e.next_maintenance_date <= DATE_ADD(NOW(), INTERVAL 30 DAY)
    ORDER BY e.next_maintenance_date ASC
    LIMIT 10
");
$upcomingMaintenance = $stmt->fetchAll();

// Проблемы оборудования
$equipmentIssues = [];

if ($equipmentStats['broken'] > 0) {
    $equipmentIssues[] = [
        'type' => 'critical',
        'title' => 'Неисправное оборудование',
        'count' => $equipmentStats['broken'],
        'message' => 'Оборудование требует ремонта',
        'recommendation' => 'Срочно вызвать ремонтную службу или создать заявку на ремонт'
    ];
}

if ($equipmentStats['maintenance'] > 0) {
    $equipmentIssues[] = [
        'type' => 'warning',
        'title' => 'На обслуживании',
        'count' => $equipmentStats['maintenance'],
        'message' => 'Оборудование проходит плановое ТО',
        'recommendation' => 'Контролировать сроки завершения обслуживания'
    ];
}

// Оборудование с просроченным ТО
$stmt = $db->query("
    SELECT COUNT(*) as overdue_count
    FROM equipment
    WHERE next_maintenance_date < NOW()
    AND status = 'operational'
");
$overdueMaintenance = $stmt->fetch()['overdue_count'] ?? 0;

if ($overdueMaintenance > 0) {
    $equipmentIssues[] = [
        'type' => 'warning',
        'title' => 'Просрочено ТО',
        'count' => $overdueMaintenance,
        'message' => 'Оборудование с просроченным техническим обслуживанием',
        'recommendation' => 'Запланировать внеочередное техническое обслуживание'
    ];
}

$pageTitle = 'Управление оборудованием | ' . APP_NAME;
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
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/common-style.css">
    <style>
        /* Дополнительные стили для конкретной страницы */
    </style>
</head>
<body>
    <!-- Анимированный фон -->
    <div class="particles-container">
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
    </div>
    <div class="glow-overlay"></div>
    <div class="grid-overlay"></div>
    <!-- Navbar -->
    <nav class="navbar">
        <a href="<?= APP_URL ?>/modules/dashboard/index.php" class="nav-brand">
            <div class="brand-logo">
                <svg viewBox="0 0 24 24" fill="white"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
            </div>
            <span class="brand-name">PolesieMES</span>
        </a>
        
        <ul class="nav-menu">
            <li><a href="<?= APP_URL ?>/modules/dashboard/index.php" class="nav-link"><i class="fas fa-home"></i> Главная</a></li>
            <li><a href="<?= APP_URL ?>/modules/orders/index.php" class="nav-link"><i class="fas fa-shopping-cart"></i> Заказы</a></li>
            <li><a href="<?= APP_URL ?>/modules/production/index.php" class="nav-link"><i class="fas fa-industry"></i> Производство</a></li>
            <li><a href="<?= APP_URL ?>/modules/warehouse/index.php" class="nav-link"><i class="fas fa-warehouse"></i> Склад</a></li>
            <li><a href="<?= APP_URL ?>/modules/equipment/index.php" class="nav-link active"><i class="fas fa-tools"></i> Оборудование</a></li>
            <li><a href="<?= APP_URL ?>/modules/gost_docs/index.php" class="nav-link"><i class="fas fa-file-contract"></i> ГОСТ Документы</a></li>
        </ul>
        
        <div class="user-menu">
            <span style="color: var(--text-secondary);"><?= e(getCurrentUser()['username']) ?></span>
            <a href="<?= APP_URL ?>/modules/auth/logout.php" class="btn-logout">Выход</a>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <div class="page-header">
            <div class="page-title">
                <h1><i class="fas fa-tools"></i> Управление оборудованием</h1>
                <p>Контроль станков и технического обслуживания</p>
            </div>
            <?php if (hasRole(['admin', 'manager', 'engineer'])): ?>
            <a href="create.php" class="btn-primary-custom">
                <i class="fas fa-plus"></i> Добавить оборудование
            </a>
            <?php endif; ?>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?= $equipmentStats['total'] ?></div>
                <div class="stat-label">Всего единиц</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--success-color);"><?= $equipmentStats['operational'] ?></div>
                <div class="stat-label">В работе</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--warning-color);"><?= $equipmentStats['maintenance'] ?></div>
                <div class="stat-label">На обслуживании</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--danger-color);"><?= $equipmentStats['broken'] ?></div>
                <div class="stat-label">Неисправно</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--info-color);"><?= $equipmentStats['offline'] ?></div>
                <div class="stat-label">Отключено</div>
            </div>
        </div>

        <!-- Issues & Recommendations -->
        <?php if (!empty($equipmentIssues)): ?>
        <div class="issues-section">
            <h2 class="section-title"><i class="fas fa-exclamation-triangle"></i> Проблемы и рекомендации</h2>
            <div class="issues-grid">
                <?php foreach ($equipmentIssues as $issue): ?>
                <div class="issue-card <?= $issue['type'] ?>">
                    <div class="issue-icon <?= $issue['type'] ?>">
                        <i class="fas fa-<?= $issue['type'] == 'critical' ? 'exclamation-circle' : ($issue['type'] == 'warning' ? 'exclamation-triangle' : 'info-circle') ?>"></i>
                    </div>
                    <div class="issue-content" style="flex: 1;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                            <h4><?= $issue['title'] ?></h4>
                            <span class="issue-count"><?= $issue['count'] ?></span>
                        </div>
                        <p><?= $issue['message'] ?></p>
                        <div class="issue-recommendation">
                            <i class="fas fa-lightbulb"></i> <?= $issue['recommendation'] ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Equipment Requiring Attention -->
        <?php if (!empty($attentionEquipment)): ?>
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-triangle-exclamation"></i> Требуют внимания
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Оборудование</th>
                                <th>Категория</th>
                                <th>Статус</th>
                                <th>Проблема</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($attentionEquipment as $item): ?>
                            <tr>
                                <td>
                                    <span class="status-indicator <?= $item['status_class'] ?>"></span>
                                    <strong><?= e($item['name']) ?></strong>
                                </td>
                                <td><?= e($item['category_name'] ?? '-') ?></td>
                                <td>
                                    <span class="badge-status <?= $item['status'] ?>">
                                        <?= $item['status'] == 'operational' ? 'В работе' :
                                            ($item['status'] == 'maintenance' ? 'Обслуживание' :
                                            ($item['status'] == 'broken' ? 'Неисправно' : 'Отключено')) ?>
                                    </span>
                                </td>
                                <td><?= e($item['maintenance_description'] ?? 'Требуется внимание') ?></td>
                                <td>
                                    <a href="view.php?id=<?= $item['id'] ?>" class="btn-action">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="maintenance.php?id=<?= $item['id'] ?>" class="btn-action">
                                        <i class="fas fa-wrench"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- All Equipment -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-cogs"></i> Все оборудование
                </div>
                <div>
                    <button class="btn-action" onclick="location.reload()">
                        <i class="fas fa-sync"></i>
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Название</th>
                                <th>Категория</th>
                                <th>Расположение</th>
                                <th>Инвентарный №</th>
                                <th>Дата следующего ТО</th>
                                <th>Статус</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($equipment as $item): ?>
                            <tr>
                                <td>
                                    <span class="status-indicator <?= $item['status_class'] ?>"></span>
                                    <strong><?= e($item['name']) ?></strong>
                                </td>
                                <td><?= e($item['category_name'] ?? '-') ?></td>
                                <td><?= e($item['location_name'] ?? '-') ?></td>
                                <td><?= e($item['inventory_number'] ?? '-') ?></td>
                                <td>
                                    <?php if ($item['next_maintenance_date']): ?>
                                        <?php 
                                        $daysUntil = strtotime($item['next_maintenance_date']) - time();
                                        $daysUntil = floor($daysUntil / (60 * 60 * 24));
                                        ?>
                                        <?= date('d.m.Y', strtotime($item['next_maintenance_date'])) ?>
                                        <?php if ($daysUntil < 0): ?>
                                            <br><small style="color: var(--danger-color);">Просрочено на <?= abs($daysUntil) ?> дн.</small>
                                        <?php elseif ($daysUntil <= 7): ?>
                                            <br><small style="color: var(--warning-color);">Через <?= $daysUntil ?> дн.</small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted);">Не назначено</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge-status <?= $item['status'] ?>">
                                        <?= $item['status'] == 'operational' ? 'В работе' :
                                            ($item['status'] == 'maintenance' ? 'Обслуживание' :
                                            ($item['status'] == 'broken' ? 'Неисправно' : 'Отключено')) ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="view.php?id=<?= $item['id'] ?>" class="btn-action">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="edit.php?id=<?= $item['id'] ?>" class="btn-action">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Upcoming Maintenance -->
        <?php if (!empty($upcomingMaintenance)): ?>
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-calendar-alt"></i> Предстоящее ТО (30 дней)
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Оборудование</th>
                                <th>Категория</th>
                                <th>Дата ТО</th>
                                <th>Дней осталось</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($upcomingMaintenance as $item): ?>
                            <tr>
                                <td><strong><?= e($item['equipment_name']) ?></strong></td>
                                <td><?= e($item['category_name'] ?? '-') ?></td>
                                <td><?= date('d.m.Y', strtotime($item['next_maintenance_date'])) ?></td>
                                <td>
                                    <?php if ($item['days_until'] <= 7): ?>
                                        <span style="color: var(--warning-color);"><?= $item['days_until'] ?> дн.</span>
                                    <?php else: ?>
                                        <?= $item['days_until'] ?> дн.
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
