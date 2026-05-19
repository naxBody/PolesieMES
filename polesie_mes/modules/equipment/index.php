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
    SELECT i.*, 
           d.name as category_name,
           d2.name as location_name,
           CASE 
               WHEN i.status = 'operational' THEN 'normal'
               WHEN i.status = 'maintenance' THEN 'warning'
               WHEN i.status = 'broken' THEN 'critical'
               WHEN i.status = 'offline' THEN 'info'
               ELSE 'normal'
           END as status_class
    FROM items i
    LEFT JOIN dictionaries d ON i.category_id = d.id AND d.dict_type = 'category'
    LEFT JOIN dictionaries d2 ON i.location_id = d2.id AND d2.dict_type = 'location'
    WHERE i.item_type = 'equipment'
    ORDER BY 
        CASE i.status 
            WHEN 'broken' THEN 1 
            WHEN 'maintenance' THEN 2 
            WHEN 'offline' THEN 3 
            ELSE 4 
        END,
        i.name ASC
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
    FROM items
    WHERE item_type = 'equipment'
");
$equipmentStats = $stmt->fetch();

// Оборудование требующее внимания (сломанное или на обслуживании)
$stmt = $db->query("
    SELECT i.*, d.name as category_name, 
           j.description as maintenance_description,
           j.scheduled_date, j.completed_date
    FROM items i
    LEFT JOIN dictionaries d ON i.category_id = d.id AND d.dict_type = 'category'
    LEFT JOIN journal j ON i.id = j.item_id AND j.journal_type = 'maintenance' AND j.maintenance_status = 'in_progress'
    WHERE i.item_type = 'equipment' AND i.status IN ('broken', 'maintenance')
    ORDER BY i.status DESC, i.name ASC
    LIMIT 10
");
$attentionEquipment = $stmt->fetchAll();

// Последние события обслуживания
$stmt = $db->query("
    SELECT j.*, i.name as equipment_name, s.first_name, s.last_name
    FROM journal j
    LEFT JOIN items i ON j.item_id = i.id
    LEFT JOIN staff s ON j.technician_id = s.id
    WHERE j.journal_type = 'maintenance'
    ORDER BY j.created_at DESC
    LIMIT 10
");
$recentMaintenance = $stmt->fetchAll();

// Предстоящее ТО
$stmt = $db->query("
    SELECT i.name as equipment_name, d.name as category_name,
           30 as days_until
    FROM items i
    LEFT JOIN dictionaries d ON i.category_id = d.id AND d.dict_type = 'category'
    WHERE i.item_type = 'equipment' AND i.status = 'operational'
    ORDER BY i.name ASC
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

// Оборудование с просроченным ТО (временно отключено, т.к. колонка next_maintenance_date отсутствует)
$overdueMaintenance = 0;

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
        /* Стили для страницы оборудования */
        .equipment-filters {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            backdrop-filter: var(--backdrop-blur);
        }
        
        .filter-row {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .filter-group {
            flex: 1;
            min-width: 200px;
        }
        
        .filter-input {
            width: 100%;
            padding: 0.75rem 1rem;
            background: var(--bg-input);
            border: 1px solid var(--border);
            border-radius: 12px;
            color: var(--text-primary);
            font-family: inherit;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }
        
        .filter-input:focus {
            outline: none;
            border-color: var(--border-glow);
            box-shadow: 0 0 20px rgba(255, 107, 107, 0.2);
        }
        
        .filter-select {
            width: 100%;
            padding: 0.75rem 1rem;
            background: var(--bg-input);
            border: 1px solid var(--border);
            border-radius: 12px;
            color: var(--text-primary);
            font-family: inherit;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .filter-select:focus {
            outline: none;
            border-color: var(--border-glow);
        }
        
        .equipment-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .equipment-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 1.5rem;
            backdrop-filter: var(--backdrop-blur);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .equipment-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--gradient-primary);
            transform: scaleX(0);
            transition: transform 0.3s ease;
        }
        
        .equipment-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg), 0 0 30px rgba(255, 107, 107, 0.15);
            border-color: var(--border-glow);
        }
        
        .equipment-card:hover::before {
            transform: scaleX(1);
        }
        
        .equipment-card.status-broken::before {
            background: var(--danger-color);
        }
        
        .equipment-card.status-maintenance::before {
            background: var(--warning-color);
        }
        
        .equipment-card.status-operational::before {
            background: var(--success-color);
        }
        
        .equipment-card.status-offline::before {
            background: var(--info-color);
        }
        
        .equipment-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }
        
        .equipment-name {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-primary);
            margin: 0;
        }
        
        .equipment-status {
            padding: 0.35rem 0.75rem;
            border-radius: 8px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .equipment-status.operational {
            background: rgba(48, 209, 88, 0.2);
            color: var(--success-color);
        }
        
        .equipment-status.maintenance {
            background: rgba(255, 214, 10, 0.2);
            color: var(--warning-color);
        }
        
        .equipment-status.broken {
            background: rgba(255, 69, 58, 0.2);
            color: var(--danger-color);
        }
        
        .equipment-status.offline {
            background: rgba(90, 200, 250, 0.2);
            color: var(--info-color);
        }
        
        .equipment-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }
        
        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        
        .detail-label {
            font-size: 0.75rem;
            color: var(--text-muted);
            text-transform: uppercase;
        }
        
        .detail-value {
            font-size: 0.9rem;
            color: var(--text-secondary);
            font-weight: 500;
        }
        
        .equipment-actions {
            display: flex;
            gap: 0.5rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border);
        }
        
        .btn-action-sm {
            padding: 0.5rem 0.75rem;
            background: var(--glass-bg);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text-secondary);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-size: 0.85rem;
            transition: all 0.3s ease;
            flex: 1;
        }
        
        .btn-action-sm:hover {
            background: rgba(255, 107, 107, 0.2);
            border-color: var(--border-glow);
            color: var(--text-primary);
        }
        
        .status-indicator-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 0.5rem;
        }
        
        .status-indicator-dot.normal { background: var(--success-color); }
        .status-indicator-dot.warning { background: var(--warning-color); }
        .status-indicator-dot.critical { background: var(--danger-color); }
        .status-indicator-dot.info { background: var(--info-color); }
        
        .status-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 0.5rem;
        }
        
        .status-indicator.normal { background: var(--success-color); box-shadow: 0 0 10px rgba(48, 209, 88, 0.5); }
        .status-indicator.warning { background: var(--warning-color); box-shadow: 0 0 10px rgba(255, 214, 10, 0.5); }
        .status-indicator.critical { background: var(--danger-color); box-shadow: 0 0 10px rgba(255, 69, 58, 0.5); }
        .status-indicator.info { background: var(--info-color); box-shadow: 0 0 10px rgba(90, 200, 250, 0.5); }
        
        .chart-container {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            backdrop-filter: var(--backdrop-blur);
            position: relative;
            min-height: 320px;
        }
        
        .chart-container canvas {
            max-height: 250px;
        }
        
        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 200px;
            text-align: center;
        }
        
        .empty-state i {
            font-size: 3rem;
            color: var(--text-muted);
            opacity: 0.5;
            margin-bottom: 1rem;
        }
        
        .empty-state p {
            color: var(--text-muted);
            font-size: 1rem;
        }
        
        .charts-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .view-toggle {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        
        .view-btn {
            padding: 0.5rem 1rem;
            background: var(--glass-bg);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .view-btn.active {
            background: rgba(255, 107, 107, 0.2);
            border-color: var(--border-glow);
            color: var(--text-primary);
        }
        
        .table-view, .grid-view {
            display: none;
        }
        
        .table-view.active, .grid-view.active {
            display: block;
        }
        
        @media (max-width: 768px) {
            .equipment-grid {
                grid-template-columns: 1fr;
            }
            
            .charts-row {
                grid-template-columns: 1fr;
            }
            
            .filter-row {
                flex-direction: column;
            }
            
            .filter-group {
                width: 100%;
            }
        }
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
    <nav class="navbar" id="navbar">
        <a href="<?= APP_URL ?>" class="nav-brand">
            <div class="brand-logo">
                <svg viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
            </div>
            <span class="brand-name">PolesieMES</span>
        </a>

        <ul class="nav-menu">
            <li>
                <a href="<?= APP_URL ?>/modules/dashboard/index.php" class="nav-link">
                    <i class="fas fa-chart-line"></i>
                    Главная
                </a>
            </li>

            <?php if (hasRole(['admin', 'manager'])): ?>
            <li>
                <a href="<?= APP_URL ?>/modules/orders/index.php" class="nav-link">
                    <i class="fas fa-shopping-cart"></i>
                    Заказы
                </a>
            </li>
            <?php endif; ?>

            <?php if (hasRole(['admin', 'manager', 'operator', 'warehouse_keeper'])): ?>
            <li>
                <a href="<?= APP_URL ?>/modules/production/index.php" class="nav-link">
                    <i class="fas fa-cogs"></i>
                    Производство
                </a>
            </li>
            <?php endif; ?>

            <?php if (hasRole(['admin', 'manager', 'warehouse_keeper'])): ?>
            <li>
                <a href="<?= APP_URL ?>/modules/warehouse/warehouse_dashboard.php" class="nav-link">
                    <i class="fas fa-warehouse"></i>
                    Склад
                </a>
            </li>
            <?php endif; ?>

            <?php if (hasRole(['admin', 'manager', 'operator', 'warehouse_keeper'])): ?>
            <li>
                <a href="<?= APP_URL ?>/modules/equipment/index.php" class="nav-link active">
                    <i class="fas fa-tools"></i>
                    Оборудование
                </a>
            </li>
            <?php endif; ?>

            <?php if (hasRole(['admin', 'manager', 'warehouse_keeper'])): ?>
            <li>
                <a href="<?= APP_URL ?>/modules/shipment/index.php" class="nav-link">
                    <i class="fas fa-truck-loading"></i>
                    Отгрузка
                </a>
            </li>
            <?php endif; ?>

            <?php if (hasRole(['admin', 'manager', 'technologist'])): ?>
            <li>
                <a href="<?= APP_URL ?>/modules/documents/index.php" class="nav-link">
                    <i class="fas fa-file-alt"></i>
                    Документы
                </a>
            </li>
            <?php endif; ?>

            <?php if (hasRole('admin')): ?>
            <li>
                <a href="<?= APP_URL ?>/modules/employees/index.php" class="nav-link">
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

        <!-- Charts Row -->
        <div class="charts-row">
            <div class="chart-container">
                <h3 style="margin-bottom: 1rem; font-size: 1.1rem;"><i class="fas fa-chart-pie"></i> Статус оборудования</h3>
                <?php if ($equipmentStats['total'] > 0): ?>
                <div style="height: 250px; position: relative;">
                    <canvas id="statusChart"></canvas>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>Нет данных для отображения</p>
                </div>
                <?php endif; ?>
            </div>
            <div class="chart-container">
                <h3 style="margin-bottom: 1rem; font-size: 1.1rem;"><i class="fas fa-chart-bar"></i> Оборудование по категориям</h3>
                <?php 
                $displayCategoryCount = [];
                foreach ($equipment as $item) {
                    $catName = $item['category_name'] ?? 'Без категории';
                    $displayCategoryCount[$catName] = ($displayCategoryCount[$catName] ?? 0) + 1;
                }
                if (!empty($displayCategoryCount)): 
                ?>
                <div style="height: 250px; position: relative;">
                    <canvas id="categoryChart"></canvas>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>Нет данных для отображения</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Filters -->
        <div class="equipment-filters">
            <div class="filter-row">
                <div class="filter-group">
                    <input type="text" id="searchInput" class="filter-input" placeholder="Поиск оборудования..." onkeyup="filterEquipment()">
                </div>
                <div class="filter-group">
                    <select id="statusFilter" class="filter-select" onchange="filterEquipment()">
                        <option value="">Все статусы</option>
                        <option value="operational">В работе</option>
                        <option value="maintenance">На обслуживании</option>
                        <option value="broken">Неисправно</option>
                        <option value="offline">Отключено</option>
                    </select>
                </div>
                <div class="filter-group">
                    <select id="categoryFilter" class="filter-select" onchange="filterEquipment()">
                        <option value="">Все категории</option>
                        <?php 
                        $categories = [];
                        foreach ($equipment as $item) {
                            if (!empty($item['category_name']) && !isset($categories[$item['category_name']])) {
                                $categories[$item['category_name']] = true;
                                echo '<option value="' . e($item['category_name']) . '">' . e($item['category_name']) . '</option>';
                            }
                        }
                        ?>
                    </select>
                </div>
                <div class="view-toggle">
                    <button class="view-btn active" onclick="switchView('table')"><i class="fas fa-list"></i></button>
                    <button class="view-btn" onclick="switchView('grid')"><i class="fas fa-th-large"></i></button>
                </div>
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
                                    <span class="status-indicator <?= $item['status_class'] ?? 'normal' ?>"></span>
                                    <strong><?= e($item['name']) ?></strong>
                                </td>
                                <td><?= e($item['category_name'] ?? '-') ?></td>
                                <td>
                                    <span class="badge-status badge-<?= $item['status'] ?? 'operational' ?>">
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
                <!-- Table View -->
                <div class="table-view active" id="equipmentTable">
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
                                <tr data-status="<?= e($item['status']) ?>" data-category="<?= e($item['category_name'] ?? '') ?>">
                                    <td>
                                        <span class="status-indicator-dot <?= $item['status_class'] ?? 'normal' ?>"></span>
                                        <strong><?= e($item['name']) ?></strong>
                                    </td>
                                    <td><?= e($item['category_name'] ?? '-') ?></td>
                                    <td><?= e($item['location_name'] ?? '-') ?></td>
                                    <td><?= e($item['item_code'] ?? '-') ?></td>
                                    <td>
                                        <span style="color: var(--text-muted);">Не назначено</span>
                                    </td>
                                    <td>
                                        <span class="badge-status badge-<?= $item['status'] ?? 'operational' ?>">
                                            <?= $item['status'] == 'operational' ? 'В работе' :
                                                ($item['status'] == 'maintenance' ? 'Обслуживание' :
                                                ($item['status'] == 'broken' ? 'Неисправно' : 'Отключено')) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="view.php?id=<?= $item['id'] ?>" class="btn-action-sm">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="edit.php?id=<?= $item['id'] ?>" class="btn-action-sm">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Grid View -->
                <div class="grid-view" id="equipmentGrid">
                    <div class="equipment-grid">
                        <?php foreach ($equipment as $item): ?>
                        <div class="equipment-card status-<?= e($item['status']) ?>" data-status="<?= e($item['status']) ?>" data-category="<?= e($item['category_name'] ?? '') ?>">
                            <div class="equipment-header">
                                <h3 class="equipment-name"><?= e($item['name']) ?></h3>
                                <span class="equipment-status <?= e($item['status']) ?>">
                                    <?= $item['status'] == 'operational' ? 'В работе' :
                                        ($item['status'] == 'maintenance' ? 'Обслуживание' :
                                        ($item['status'] == 'broken' ? 'Неисправно' : 'Отключено')) ?>
                                </span>
                            </div>
                            <div class="equipment-details">
                                <div class="detail-item">
                                    <span class="detail-label">Категория</span>
                                    <span class="detail-value"><?= e($item['category_name'] ?? '-') ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Расположение</span>
                                    <span class="detail-value"><?= e($item['location_name'] ?? '-') ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Код</span>
                                    <span class="detail-value"><?= e($item['item_code'] ?? '-') ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Следующее ТО</span>
                                    <span class="detail-value">Не назначено</span>
                                </div>
                            </div>
                            <div class="equipment-actions">
                                <a href="view.php?id=<?= $item['id'] ?>" class="btn-action-sm">
                                    <i class="fas fa-eye"></i> Просмотр
                                </a>
                                <a href="edit.php?id=<?= $item['id'] ?>" class="btn-action-sm">
                                    <i class="fas fa-edit"></i> Редактировать
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
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
                                <th>Статус</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($upcomingMaintenance as $item): ?>
                            <tr>
                                <td><strong><?= e($item['equipment_name']) ?></strong></td>
                                <td><?= e($item['category_name'] ?? '-') ?></td>
                                <td>
                                    <span class="badge-status badge-operational">В работе</span>
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
    <script>
        // Mobile menu toggle
        function toggleMobileMenu() {
            const navMenu = document.querySelector('.nav-menu');
            navMenu.style.display = navMenu.style.display === 'flex' ? 'none' : 'flex';
        }

        // Scroll effect for navbar
        window.addEventListener('scroll', () => {
            const navbar = document.getElementById('navbar');
            if (window.scrollY > 50) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
        });

        // View switcher
        function switchView(view) {
            const tableView = document.querySelector('.table-view');
            const gridView = document.querySelector('.grid-view');
            const btns = document.querySelectorAll('.view-btn');
            
            btns.forEach(btn => btn.classList.remove('active'));
            
            if (view === 'table') {
                tableView.classList.add('active');
                gridView.classList.remove('active');
                btns[0].classList.add('active');
            } else {
                tableView.classList.remove('active');
                gridView.classList.add('active');
                btns[1].classList.add('active');
            }
        }

        // Filter equipment
        function filterEquipment() {
            const searchInput = document.getElementById('searchInput').value.toLowerCase();
            const statusFilter = document.getElementById('statusFilter').value;
            const categoryFilter = document.getElementById('categoryFilter').value;
            
            // Filter table rows
            const tableRows = document.querySelectorAll('#equipmentTable tbody tr');
            tableRows.forEach(row => {
                const name = row.cells[0].textContent.toLowerCase();
                const category = row.dataset.category || '';
                const status = row.dataset.status || '';
                
                let showRow = true;
                
                if (searchInput && !name.includes(searchInput)) {
                    showRow = false;
                }
                
                if (statusFilter && status !== statusFilter) {
                    showRow = false;
                }
                
                if (categoryFilter && category !== categoryFilter) {
                    showRow = false;
                }
                
                row.style.display = showRow ? '' : 'none';
            });
            
            // Filter grid cards
            const gridCards = document.querySelectorAll('.equipment-card');
            gridCards.forEach(card => {
                const name = card.querySelector('.equipment-name').textContent.toLowerCase();
                const category = card.dataset.category || '';
                const status = card.dataset.status || '';
                
                let showCard = true;
                
                if (searchInput && !name.includes(searchInput)) {
                    showCard = false;
                }
                
                if (statusFilter && status !== statusFilter) {
                    showCard = false;
                }
                
                if (categoryFilter && category !== categoryFilter) {
                    showCard = false;
                }
                
                card.style.display = showCard ? '' : 'none';
            });
        }

        // Initialize Charts
        document.addEventListener('DOMContentLoaded', function() {
            // Status Chart - only if data exists
            const statusCanvas = document.getElementById('statusChart');
            if (statusCanvas && <?= $equipmentStats['total'] > 0 ? 'true' : 'false' ?>) {
                const statusCtx = statusCanvas.getContext('2d');
                new Chart(statusCtx, {
                    type: 'doughnut',
                    data: {
                        labels: ['В работе', 'На обслуживании', 'Неисправно', 'Отключено'],
                        datasets: [{
                            data: [<?= $equipmentStats['operational'] ?? 0 ?>, <?= $equipmentStats['maintenance'] ?? 0 ?>, <?= $equipmentStats['broken'] ?? 0 ?>, <?= $equipmentStats['offline'] ?? 0 ?>],
                            backgroundColor: [
                                '#30d158',
                                '#ffd60a',
                                '#ff453a',
                                '#5ac8fa'
                            ],
                            borderWidth: 0
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    color: 'rgba(255, 255, 255, 0.8)',
                                    font: {
                                        family: 'Outfit',
                                        size: 12
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // Category Chart - only if data exists
            const categoryCanvas = document.getElementById('categoryChart');
            <?php if (!empty($displayCategoryCount)): ?>
            if (categoryCanvas) {
                const categoryCtx = categoryCanvas.getContext('2d');
                
                new Chart(categoryCtx, {
                    type: 'bar',
                    data: {
                        labels: <?= json_encode(array_keys($displayCategoryCount)) ?>,
                        datasets: [{
                            label: 'Количество',
                            data: <?= json_encode(array_values($displayCategoryCount)) ?>,
                            backgroundColor: 'rgba(255, 107, 107, 0.7)',
                            borderColor: 'rgba(255, 107, 107, 1)',
                            borderWidth: 1,
                            borderRadius: 8
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    color: 'rgba(255, 255, 255, 0.6)',
                                    stepSize: 1
                                },
                                grid: {
                                    color: 'rgba(255, 255, 255, 0.05)'
                                }
                            },
                            x: {
                                ticks: {
                                    color: 'rgba(255, 255, 255, 0.6)'
                                },
                                grid: {
                                    display: false
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                display: false
                            }
                        }
                    }
                });
            }
            <?php endif; ?>
        });
    </script>
</body>
</html>
