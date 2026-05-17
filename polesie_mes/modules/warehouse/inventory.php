<?php
/**
 * Склад - Остатки материалов
 * PolesieMES - Система управления производством ОАО "Полесьеэлектромаш"
 * Полнофункциональная страница с фильтрами, поиском, экспортом и пагинацией
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

// ==========================================
// ФИЛЬТРАЦИЯ И ПОИСК
// ==========================================
$filter = $_GET['filter'] ?? 'all';
$search = trim($_GET['search'] ?? '');
$category_id = $_GET['category_id'] ?? '';
$sort = $_GET['sort'] ?? 'name';
$sort_order = $_GET['sort_order'] ?? 'ASC';
$items_per_page = 25;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $items_per_page;

$whereConditions = ["i.item_type = 'material'"];
$params = [];

if ($filter === 'critical') {
    $whereConditions[] = "i.current_stock <= 0";
} elseif ($filter === 'low') {
    $whereConditions[] = "i.current_stock > 0 AND i.current_stock < i.min_stock";
} elseif ($filter === 'normal') {
    $whereConditions[] = "i.current_stock >= i.min_stock AND i.current_stock <= i.min_stock * 2";
} elseif ($filter === 'overstock') {
    $whereConditions[] = "i.current_stock > i.min_stock * 2";
}

if (!empty($search)) {
    $whereConditions[] = "(i.name LIKE :search OR i.item_code LIKE :search)";
    $params['search'] = "%{$search}%";
}

if (!empty($category_id)) {
    $whereConditions[] = "i.category_id = :category_id";
    $params['category_id'] = $category_id;
}

$whereClause = implode(' AND ', $whereConditions);

// Подсчет общего количества записей для пагинации
$countStmt = $db->prepare("SELECT COUNT(*) as total FROM items i WHERE {$whereClause}");
$countStmt->execute($params);
$totalRecords = $countStmt->fetch()['total'];
$totalPages = ceil($totalRecords / $items_per_page);

// Получение всех материалов с остатками и пагинацией
$stmt = $db->prepare("
    SELECT i.*, 
           c.name as category_name,
           u.name as unit_name,
           CASE 
               WHEN i.current_stock <= 0 THEN 'critical'
               WHEN i.current_stock < i.min_stock THEN 'low'
               WHEN i.current_stock > i.min_stock * 2 THEN 'overstock'
               ELSE 'normal'
           END as stock_status
    FROM items i
    LEFT JOIN dictionaries c ON i.category_id = c.id AND c.dict_type = 'category'
    LEFT JOIN dictionaries u ON i.unit_id = u.id AND u.dict_type = 'unit'
    WHERE {$whereClause}
    ORDER BY i.{$sort} {$sort_order}
    LIMIT :limit OFFSET :offset
");
$stmt->bindValue(':limit', $items_per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
foreach ($params as $key => $value) {
    $stmt->bindValue(":$key", $value);
}
$stmt->execute();
$filteredMaterials = $stmt->fetchAll();

// Получение всех материалов без пагинации для статистики
$stmt_all = $db->prepare("
    SELECT i.*, 
           c.name as category_name,
           u.name as unit_name,
           CASE 
               WHEN i.current_stock <= 0 THEN 'critical'
               WHEN i.current_stock < i.min_stock THEN 'low'
               WHEN i.current_stock > i.min_stock * 2 THEN 'overstock'
               ELSE 'normal'
           END as stock_status
    FROM items i
    LEFT JOIN dictionaries c ON i.category_id = c.id AND c.dict_type = 'category'
    LEFT JOIN dictionaries u ON i.unit_id = u.id AND u.dict_type = 'unit'
    WHERE i.item_type = 'material'
");
$stmt_all->execute();
$allMaterials = $stmt_all->fetchAll();

// ==========================================
// СТАТИСТИКА
// ==========================================
$stmt = $db->query("
    SELECT 
        COUNT(*) as total_items,
        SUM(CASE WHEN current_stock <= 0 THEN 1 ELSE 0 END) as out_of_stock,
        SUM(CASE WHEN current_stock < min_stock AND current_stock > 0 THEN 1 ELSE 0 END) as low_stock,
        SUM(CASE WHEN current_stock >= min_stock AND current_stock <= min_stock * 2 THEN 1 ELSE 0 END) as normal,
        SUM(CASE WHEN current_stock > min_stock * 2 THEN 1 ELSE 0 END) as overstock,
        SUM(current_stock) as total_stock
    FROM items
    WHERE item_type = 'material'
");
$materialStats = $stmt->fetch();

// Категории для фильтра
$stmt = $db->query("SELECT id, name FROM dictionaries WHERE dict_type = 'category' ORDER BY name");
$categories = $stmt->fetchAll();

// Экспорт в CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ostatatki_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM для UTF-8
    
    fputcsv($output, ['Название', 'Артикул', 'Категория', 'Текущий остаток', 'Мин. запас', 'Макс. запас', 'Ед. изм.', 'Статус']);
    
    $stmt_export = $db->prepare("
        SELECT i.*, 
               c.name as category_name,
               u.name as unit_name,
               CASE 
                   WHEN i.current_stock <= 0 THEN 'critical'
                   WHEN i.current_stock < i.min_stock THEN 'low'
                   WHEN i.current_stock > i.min_stock * 2 THEN 'overstock'
                   ELSE 'normal'
               END as stock_status
        FROM items i
        LEFT JOIN dictionaries c ON i.category_id = c.id AND c.dict_type = 'category'
        LEFT JOIN dictionaries u ON i.unit_id = u.id AND u.dict_type = 'unit'
        WHERE {$whereClause}
        ORDER BY i.{$sort} {$sort_order}
    ");
    foreach ($params as $key => $value) {
        $stmt_export->bindValue(":$key", $value);
    }
    $stmt_export->execute();
    $exportMaterials = $stmt_export->fetchAll();
    
    $statusNames = [
        'critical' => 'Нет на складе',
        'low' => 'Низкий запас',
        'normal' => 'Норма',
        'overstock' => 'Избыток'
    ];
    
    foreach ($exportMaterials as $material) {
        fputcsv($output, [
            $material['name'],
            $material['item_code'],
            $material['category_name'] ?? '-',
            number_format($material['current_stock'], 2, ',', ' '),
            number_format($material['min_stock'], 2, ',', ' '),
            number_format($material['max_stock'] ?? 0, 2, ',', ' '),
            $material['unit_name'] ?? '-',
            $statusNames[$material['stock_status']] ?? $material['stock_status']
        ]);
    }
    fclose($output);
    exit;
}

$pageTitle = 'Остатки материалов | PolesieMES';
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
        .inventory-card {
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
        
        .search-input {
            background: var(--bg-input);
            border: 1px solid var(--border);
            color: var(--text-primary);
            border-radius: 10px;
            padding: 0.75rem 1rem 0.75rem 2.75rem;
            transition: all 0.3s ease;
            width: 300px;
        }
        
        .search-input:focus {
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
        
        .stock-badge {
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .stock-critical { background: #ff453a; color: white; }
        .stock-low { background: #ffd60a; color: black; }
        .stock-normal { background: #30d158; color: white; }
        .stock-overstock { background: #32ade6; color: white; }
        
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
            <li><a href="inventory.php" class="nav-link active"><i class="fas fa-boxes"></i> Остатки</a></li>
            <li><a href="history.php" class="nav-link"><i class="fas fa-history"></i> История</a></li>
        </ul>
        <div class="user-menu">
            <span><?= e($_SESSION['full_name']) ?> (<?= e(getRoleName($_SESSION['role'])) ?>)</span>
            <a href="<?= APP_URL ?>/modules/auth/logout.php" class="btn-logout">Выход</a>
        </div>
    </nav>

    <div class="main-content">
        <div class="page-header">
            <div class="page-title">
                <h1><i class="fas fa-boxes"></i> Остатки материалов</h1>
                <p>Все материалы на складе с текущими остатками</p>
            </div>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?= $materialStats['total_items'] ?? 0 ?></div>
                <div class="stat-label">Всего позиций</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--success-color);"><?= number_format($materialStats['total_stock'] ?? 0, 0, ',', ' ') ?></div>
                <div class="stat-label">Общий остаток</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--success-color);"><?= $materialStats['normal'] ?? 0 ?></div>
                <div class="stat-label">Норма</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--warning-color);"><?= $materialStats['low_stock'] ?? 0 ?></div>
                <div class="stat-label">Низкий запас</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--danger-color);"><?= $materialStats['out_of_stock'] ?? 0 ?></div>
                <div class="stat-label">Нет на складе</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--info-color);"><?= $materialStats['overstock'] ?? 0 ?></div>
                <div class="stat-label">Избыток</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="inventory-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
                <div class="filter-tabs" style="margin-bottom: 0;">
                    <a href="?filter=all&search=<?= e($search) ?>&category_id=<?= $category_id ?>&sort=<?= $sort ?>&sort_order=<?= $sort_order ?>" class="filter-tab <?= $filter === 'all' ? 'active' : '' ?>">Все</a>
                    <a href="?filter=critical&search=<?= e($search) ?>&category_id=<?= $category_id ?>&sort=<?= $sort ?>&sort_order=<?= $sort_order ?>" class="filter-tab <?= $filter === 'critical' ? 'active' : '' ?>">Нет на складе</a>
                    <a href="?filter=low&search=<?= e($search) ?>&category_id=<?= $category_id ?>&sort=<?= $sort ?>&sort_order=<?= $sort_order ?>" class="filter-tab <?= $filter === 'low' ? 'active' : '' ?>">Низкий запас</a>
                    <a href="?filter=normal&search=<?= e($search) ?>&category_id=<?= $category_id ?>&sort=<?= $sort ?>&sort_order=<?= $sort_order ?>" class="filter-tab <?= $filter === 'normal' ? 'active' : '' ?>">Норма</a>
                    <a href="?filter=overstock&search=<?= e($search) ?>&category_id=<?= $category_id ?>&sort=<?= $sort ?>&sort_order=<?= $sort_order ?>" class="filter-tab <?= $filter === 'overstock' ? 'active' : '' ?>">Избыток</a>
                </div>
                <a href="?export=csv&filter=<?= $filter ?>&search=<?= e($search) ?>&category_id=<?= $category_id ?>&sort=<?= $sort ?>&sort_order=<?= $sort_order ?>" class="btn-primary-custom" style="padding: 0.6rem 1.2rem;"><i class="fas fa-file-csv"></i> Экспорт CSV</a>
            </div>
            
            <div style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: center;">
                <div class="search-container" style="margin-bottom: 0; flex: 1; min-width: 250px;">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" class="search-input" placeholder="Поиск по названию или артикулу..." 
                           value="<?= e($search) ?>" onchange="applyFilters()" style="width: 100%;">
                </div>
                
                <select class="search-input" style="width: auto; min-width: 200px;" onchange="applyFilters()">
                    <option value="">Все категории</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>" <?= $category_id == $cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                
                <select class="search-input" style="width: auto;" onchange="changeSort(this.value)">
                    <option value="name_ASC" <?= $sort === 'name' && $sort_order === 'ASC' ? 'selected' : '' ?>>По названию (А-Я)</option>
                    <option value="name_DESC" <?= $sort === 'name' && $sort_order === 'DESC' ? 'selected' : '' ?>>По названию (Я-А)</option>
                    <option value="current_stock_ASC" <?= $sort === 'current_stock' && $sort_order === 'ASC' ? 'selected' : '' ?>>По остатку (возрастание)</option>
                    <option value="current_stock_DESC" <?= $sort === 'current_stock' && $sort_order === 'DESC' ? 'selected' : '' ?>>По остатку (убывание)</option>
                    <option value="item_code_ASC" <?= $sort === 'item_code' && $sort_order === 'ASC' ? 'selected' : '' ?>>По артикулу</option>
                </select>
            </div>
        </div>

        <!-- Materials Table -->
        <div class="inventory-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <h3 style="color: var(--text-primary); margin: 0;"><i class="fas fa-list"></i> Список материалов</h3>
                <span style="color: var(--text-secondary);"><?= count($filteredMaterials) ?> из <?= count($allMaterials) ?></span>
            </div>
            
            <?php if (empty($filteredMaterials)): ?>
            <div style="text-align: center; padding: 3rem 1rem; color: var(--text-secondary);">
                <i class="fas fa-inbox" style="font-size: 4rem; color: var(--text-muted); margin-bottom: 1rem;"></i>
                <p>Материалы не найдены</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th><a href="#" onclick="changeSort('name')" style="color: inherit; text-decoration: none;">Название <i class="fas fa-sort"></i></a></th>
                            <th>Артикул</th>
                            <th>Категория</th>
                            <th><a href="#" onclick="changeSort('current_stock')" style="color: inherit; text-decoration: none;">Текущий остаток <i class="fas fa-sort"></i></a></th>
                            <th>Мин. запас</th>
                            <th>Ед. изм.</th>
                            <th>Статус</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($filteredMaterials as $material): ?>
                        <tr>
                            <td><strong><?= e($material['name']) ?></strong></td>
                            <td><?= e($material['item_code']) ?></td>
                            <td><?= e($material['category_name'] ?? '-') ?></td>
                            <td><?= number_format($material['current_stock'], 2, ',', ' ') ?></td>
                            <td><?= number_format($material['min_stock'], 2, ',', ' ') ?></td>
                            <td><?= e($material['unit_name'] ?? '-') ?></td>
                            <td>
                                <span class="stock-badge stock-<?= $material['stock_status'] ?>">
                                    <?php
                                    $statusNames = [
                                        'critical' => 'Нет на складе',
                                        'low' => 'Низкий запас',
                                        'normal' => 'Норма',
                                        'overstock' => 'Избыток'
                                    ];
                                    echo $statusNames[$material['stock_status']] ?? $material['stock_status'];
                                    ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div style="display: flex; justify-content: center; align-items: center; gap: 0.5rem; margin-top: 1.5rem; flex-wrap: wrap;">
                <?php if ($page > 1): ?>
                <a href="?page=1&filter=<?= $filter ?>&search=<?= e($search) ?>&category_id=<?= $category_id ?>&sort=<?= $sort ?>&sort_order=<?= $sort_order ?>" class="filter-tab"><i class="fas fa-angle-double-left"></i></a>
                <a href="?page=<?= $page - 1 ?>&filter=<?= $filter ?>&search=<?= e($search) ?>&category_id=<?= $category_id ?>&sort=<?= $sort ?>&sort_order=<?= $sort_order ?>" class="filter-tab"><i class="fas fa-angle-left"></i></a>
                <?php endif; ?>
                
                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                for ($i = $startPage; $i <= $endPage; $i++):
                ?>
                <a href="?page=<?= $i ?>&filter=<?= $filter ?>&search=<?= e($search) ?>&category_id=<?= $category_id ?>&sort=<?= $sort ?>&sort_order=<?= $sort_order ?>" class="filter-tab <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                <a href="?page=<?= $page + 1 ?>&filter=<?= $filter ?>&search=<?= e($search) ?>&category_id=<?= $category_id ?>&sort=<?= $sort ?>&sort_order=<?= $sort_order ?>" class="filter-tab"><i class="fas fa-angle-right"></i></a>
                <a href="?page=<?= $totalPages ?>&filter=<?= $filter ?>&search=<?= e($search) ?>&category_id=<?= $category_id ?>&sort=<?= $sort ?>&sort_order=<?= $sort_order ?>" class="filter-tab"><i class="fas fa-angle-double-right"></i></a>
                <?php endif; ?>
            </div>
            <p style="text-align: center; color: var(--text-secondary); margin-top: 0.5rem; font-size: 0.9rem;">
                Страница <?= $page ?> из <?= $totalPages ?>
            </p>
            <?php endif; ?>
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
        
        function applyFilters() {
            const searchInput = document.querySelector('.search-input[type="text"]');
            const categorySelect = document.querySelectorAll('.search-input')[1];
            const search = searchInput.value;
            const categoryId = categorySelect.value;
            const filter = '<?= $filter ?>';
            const sort = '<?= $sort ?>';
            const sortOrder = '<?= $sort_order ?>';
            
            let url = `?filter=${filter}&search=${encodeURIComponent(search)}&category_id=${categoryId}&sort=${sort}&sort_order=${sortOrder}`;
            window.location.href = url;
        }
        
        function changeSort(value) {
            const [sort, sortOrder] = value.split('_');
            const search = document.querySelector('.search-input[type="text"]').value;
            const categoryId = document.querySelectorAll('.search-input')[1].value;
            const filter = '<?= $filter ?>';
            
            let url = `?filter=${filter}&search=${encodeURIComponent(search)}&category_id=${categoryId}&sort=${sort}&sort_order=${sortOrder}`;
            window.location.href = url;
        }
    </script>
</body>
</html>
