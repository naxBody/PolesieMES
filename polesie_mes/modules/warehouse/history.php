<?php
/**
 * Склад - История движений
 * PolesieMES - Система управления производством ОАО "Полесьеэлектромаш"
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireAuth();
if (!hasRole(['admin', 'manager', 'warehouse_keeper'])) {
    redirectWithMessage(APP_URL . '/modules/warehouse/warehouse_dashboard.php', 'Доступ запрещён', 'error');
}

$db = getDB();
$user = getCurrentUser();

// Фильтрация
$filter_type = $_GET['type'] ?? 'all';
$search = trim($_GET['search'] ?? '');
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

$whereConditions = ["mvt.movement_type IN ('receipt', 'consumption', 'return', 'adjustment', 'shipment')"];
$params = [];

if ($filter_type !== 'all') {
    $whereConditions[] = "mvt.movement_type = :type";
    $params['type'] = $filter_type;
}

if (!empty($search)) {
    $whereConditions[] = "(i.name LIKE :search OR i.item_code LIKE :search)";
    $params['search'] = "%{$search}%";
}

if (!empty($date_from)) {
    $whereConditions[] = "mvt.movement_date >= :date_from";
    $params['date_from'] = $date_from . ' 00:00:00';
}

if (!empty($date_to)) {
    $whereConditions[] = "mvt.movement_date <= :date_to";
    $params['date_to'] = $date_to . ' 23:59:59';
}

$whereClause = implode(' AND ', $whereConditions);

// Получение истории движений
$stmt = $db->prepare("
    SELECT mvt.*, 
           i.name as item_name, 
           i.item_code,
           c.name as category_name,
           u.name as unit_name,
           s.first_name, 
           s.last_name,
           CASE mvt.movement_type
               WHEN 'receipt' THEN 'Поступление'
               WHEN 'consumption' THEN 'Расход'
               WHEN 'return' THEN 'Возврат'
               WHEN 'adjustment' THEN 'Корректировка'
               WHEN 'shipment' THEN 'Отгрузка'
               ELSE mvt.movement_type
           END as operation_name,
           CASE mvt.movement_type
               WHEN 'receipt' THEN 'success'
               WHEN 'consumption' THEN 'warning'
               WHEN 'return' THEN 'info'
               WHEN 'adjustment' THEN 'secondary'
               WHEN 'shipment' THEN 'primary'
               ELSE 'secondary'
           END as operation_type_class
    FROM movements mvt
    LEFT JOIN items i ON mvt.item_id = i.id
    LEFT JOIN dictionaries c ON i.category_id = c.id AND c.dict_type = 'category'
    LEFT JOIN dictionaries u ON i.unit_id = u.id AND u.dict_type = 'unit'
    LEFT JOIN staff s ON mvt.employee_id = s.id
    WHERE {$whereClause}
    ORDER BY mvt.movement_date DESC
    LIMIT 100
");
$stmt->execute($params);
$movements = $stmt->fetchAll();

// Статистика по типам операций
$stmt = $db->query("
    SELECT 
        movement_type,
        COUNT(*) as count,
        SUM(quantity) as total_quantity
    FROM movements
    WHERE movement_type IN ('receipt', 'consumption', 'return', 'adjustment', 'shipment')
    GROUP BY movement_type
");
$operationStats = [];
while ($row = $stmt->fetch()) {
    $operationStats[$row['movement_type']] = $row;
}

// Общая статистика
$stmt = $db->query("
    SELECT 
        COUNT(*) as total_operations,
        SUM(CASE WHEN movement_type = 'receipt' THEN quantity ELSE 0 END) as total_receipt,
        SUM(CASE WHEN movement_type = 'consumption' THEN quantity ELSE 0 END) as total_consumption
    FROM movements
    WHERE movement_type IN ('receipt', 'consumption', 'return', 'adjustment', 'shipment')
");
$totalStats = $stmt->fetch();

$pageTitle = 'История движений | PolesieMES';
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
    
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/common-style.css">
    <style>
        .history-card {
            background: var(--glass-bg);
            backdrop-filter: var(--backdrop-blur);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .filter-tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }
        
        .filter-tab {
            padding: 0.6rem 1.2rem;
            border-radius: 8px;
            background: rgba(255,255,255,0.05);
            border: 1px solid var(--glass-border);
            color: var(--text-secondary);
            text-decoration: none;
            transition: all 0.3s ease;
            font-weight: 500;
        }
        
        .filter-tab:hover {
            background: rgba(255,255,255,0.1);
            color: var(--text-primary);
        }
        
        .filter-tab.active {
            background: linear-gradient(135deg, var(--primary-gradient-start), var(--primary-gradient-end));
            color: white;
            border-color: transparent;
        }
        
        .search-input, .date-input {
            background: var(--bg-input);
            border: 1px solid var(--border);
            color: var(--text-primary);
            border-radius: 10px;
            padding: 0.75rem 1rem;
            transition: all 0.3s ease;
        }
        
        .search-input:focus, .date-input:focus {
            background: rgba(30, 30, 45, 0.7);
            border-color: var(--primary-gradient-start);
            box-shadow: 0 0 0 3px rgba(255, 107, 107, 0.2);
            color: var(--text-primary);
        }
        
        .search-container {
            position: relative;
            margin-bottom: 1.5rem;
        }
        
        .search-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            z-index: 1;
        }
        
        .search-input {
            padding-left: 2.75rem;
            width: 300px;
        }
        
        .date-filters {
            display: flex;
            gap: 1rem;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }
        
        .date-input {
            width: auto;
        }
        
        .operation-badge {
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .operation-receipt { background: #30d158; color: white; }
        .operation-consumption { background: #ffd60a; color: black; }
        .operation-return { background: #32ade6; color: white; }
        .operation-adjustment { background: #6c757d; color: white; }
        .operation-shipment { background: #ff6b6b; color: white; }
        
        .table-custom {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table-custom th {
            background: rgba(255,255,255,0.05);
            color: var(--text-secondary);
            font-weight: 600;
            padding: 1rem;
            text-align: left;
            border-bottom: 2px solid var(--glass-border);
        }
        
        .table-custom td {
            padding: 1rem;
            border-bottom: 1px solid var(--glass-border);
            color: var(--text-primary);
        }
        
        .table-custom tr:hover {
            background: rgba(255,255,255,0.03);
        }
        
        .quantity-positive {
            color: var(--success-color);
            font-weight: 600;
        }
        
        .quantity-negative {
            color: var(--danger-color);
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="particles-container"><div class="particle"></div><div class="particle"></div><div class="particle"></div></div>
    <div class="glow-overlay"></div>
    <div class="grid-overlay"></div>
    
    <nav class="navbar">
        <a href="<?= APP_URL ?>" class="nav-brand"><div class="brand-logo"><svg viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg></div><span class="brand-name">PolesieMES</span></a>
        <ul class="nav-menu">
            <li><a href="<?= APP_URL ?>/modules/warehouse/warehouse_dashboard.php" class="nav-link"><i class="fas fa-warehouse"></i> Склад</a></li>
            <li><a href="inventory.php" class="nav-link"><i class="fas fa-boxes"></i> Остатки</a></li>
            <li><a href="history.php" class="nav-link active"><i class="fas fa-history"></i> История</a></li>
        </ul>
        <div class="user-menu">
            <span><?= e($_SESSION['full_name']) ?> (<?= e(getRoleName($_SESSION['role'])) ?>)</span>
            <a href="<?= APP_URL ?>/modules/auth/logout.php" class="btn-logout">Выход</a>
        </div>
    </nav>

    <div class="main-content">
        <div class="page-header">
            <div class="page-title">
                <h1><i class="fas fa-history"></i> История движений</h1>
                <p>Все операции на складе</p>
            </div>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?= $totalStats['total_operations'] ?? 0 ?></div>
                <div class="stat-label">Всего операций</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--success-color);"><?= number_format($totalStats['total_receipt'] ?? 0, 2, ',', ' ') ?></div>
                <div class="stat-label">📥 Поступило</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--warning-color);"><?= number_format($totalStats['total_consumption'] ?? 0, 2, ',', ' ') ?></div>
                <div class="stat-label">📤 Израсходовано</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--info-color);"><?= count($movements) ?></div>
                <div class="stat-label">Записей в истории</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="history-card">
            <div class="filter-tabs">
                <a href="?type=all&search=<?= e($search) ?>&date_from=<?= e($date_from) ?>&date_to=<?= e($date_to) ?>" class="filter-tab <?= $filter_type === 'all' ? 'active' : '' ?>">Все</a>
                <a href="?type=receipt&search=<?= e($search) ?>&date_from=<?= e($date_from) ?>&date_to=<?= e($date_to) ?>" class="filter-tab <?= $filter_type === 'receipt' ? 'active' : '' ?>">Поступление</a>
                <a href="?type=consumption&search=<?= e($search) ?>&date_from=<?= e($date_from) ?>&date_to=<?= e($date_to) ?>" class="filter-tab <?= $filter_type === 'consumption' ? 'active' : '' ?>">Расход</a>
                <a href="?type=return&search=<?= e($search) ?>&date_from=<?= e($date_from) ?>&date_to=<?= e($date_to) ?>" class="filter-tab <?= $filter_type === 'return' ? 'active' : '' ?>">Возврат</a>
                <a href="?type=adjustment&search=<?= e($search) ?>&date_from=<?= e($date_from) ?>&date_to=<?= e($date_to) ?>" class="filter-tab <?= $filter_type === 'adjustment' ? 'active' : '' ?>">Корректировка</a>
                <a href="?type=shipment&search=<?= e($search) ?>&date_from=<?= e($date_from) ?>&date_to=<?= e($date_to) ?>" class="filter-tab <?= $filter_type === 'shipment' ? 'active' : '' ?>">Отгрузка</a>
            </div>
            
            <div class="search-container">
                <i class="fas fa-search search-icon"></i>
                <input type="text" class="search-input" placeholder="Поиск по названию или артикулу..." 
                       value="<?= e($search) ?>" onchange="window.location.href='?type=<?= $filter_type ?>&search='+this.value+'&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>'">
            </div>
            
            <div class="date-filters">
                <label style="color: var(--text-secondary);">Период:</label>
                <input type="date" class="date-input" value="<?= e($date_from) ?>" onchange="applyDateFilter()">
                <span style="color: var(--text-secondary);">—</span>
                <input type="date" class="date-input" value="<?= e($date_to) ?>" onchange="applyDateFilter()">
                <?php if (!empty($date_from) || !empty($date_to)): ?>
                <a href="?type=<?= $filter_type ?>&search=<?= e($search) ?>" class="filter-tab" style="padding: 0.5rem 1rem;"><i class="fas fa-times"></i></a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Movements Table -->
        <div class="history-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <h3 style="color: var(--text-primary); margin: 0;"><i class="fas fa-list"></i> Операции</h3>
                <span style="color: var(--text-secondary);"><?= count($movements) ?> записей</span>
            </div>
            
            <?php if (empty($movements)): ?>
            <div style="text-align: center; padding: 3rem 1rem; color: var(--text-secondary);">
                <i class="fas fa-inbox" style="font-size: 4rem; color: var(--text-muted); margin-bottom: 1rem;"></i>
                <p>Операции не найдены</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>Дата и время</th>
                            <th>Операция</th>
                            <th>Материал</th>
                            <th>Артикул</th>
                            <th>Категория</th>
                            <th>Количество</th>
                            <th>Ед. изм.</th>
                            <th>Сотрудник</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($movements as $movement): ?>
                        <tr>
                            <td><?= date('d.m.Y H:i', strtotime($movement['movement_date'])) ?></td>
                            <td>
                                <span class="operation-badge operation-<?= $movement['operation_type_class'] ?>">
                                    <?= e($movement['operation_name']) ?>
                                </span>
                            </td>
                            <td><strong><?= e($movement['item_name'] ?? '-') ?></strong></td>
                            <td><?= e($movement['item_code'] ?? '-') ?></td>
                            <td><?= e($movement['category_name'] ?? '-') ?></td>
                            <td>
                                <span class="<?= $movement['quantity'] > 0 ? 'quantity-positive' : 'quantity-negative' ?>">
                                    <?= $movement['quantity'] > 0 ? '+' : '' ?><?= number_format($movement['quantity'], 2) ?>
                                </span>
                            </td>
                            <td><?= e($movement['unit_name'] ?? '-') ?></td>
                            <td><?= e($movement['first_name'] ?? '') ?> <?= e($movement['last_name'] ?? '') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleMobileMenu() {
            const navMenu = document.querySelector('.nav-menu');
            navMenu.classList.toggle('active');
        }
        
        function applyDateFilter() {
            const dateFrom = document.querySelectorAll('.date-input')[0].value;
            const dateTo = document.querySelectorAll('.date-input')[1].value;
            const search = document.querySelector('.search-input').value;
            const type = '<?= $filter_type ?>';
            
            let url = `?type=${type}&search=${encodeURIComponent(search)}`;
            if (dateFrom) url += `&date_from=${dateFrom}`;
            if (dateTo) url += `&date_to=${dateTo}`;
            
            window.location.href = url;
        }
    </script>
</body>
</html>
