<?php
/**
 * Модуль управления документами - Главная страница
 * PolesieMES - Система управления производством ОАО "Полесьеэлектромаш"
 * 
 * Управление всеми документами предприятия:
 * - ГОСТ документы (паспорта, руководства, сертификаты)
 * - Внутренние документы (приказы, распоряжения)
 * - Технические документы (чертежи, спецификации)
 * - Договоры и контракты
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

// Проверка авторизации
requireAuth();

$db = getDB();
$user = getCurrentUser();

// Категории документов
$documentCategories = [
    'gost' => ['name' => 'ГОСТ Документы', 'icon' => 'fa-file-contract', 'color' => 'var(--primary-color)'],
    'internal' => ['name' => 'Внутренние документы', 'icon' => 'fa-file-alt', 'color' => 'var(--info-color)'],
    'technical' => ['name' => 'Технические документы', 'icon' => 'fa-drafting-compass', 'color' => 'var(--warning-color)'],
    'contracts' => ['name' => 'Договоры и контракты', 'icon' => 'fa-handshake', 'color' => 'var(--success-color)'],
];

// Получение последних документов
$stmt = $db->query("
    SELECT d.*, 
           CASE 
               WHEN d.category = 'gost' THEN 'ГОСТ Документы'
               WHEN d.category = 'internal' THEN 'Внутренние документы'
               WHEN d.category = 'technical' THEN 'Технические документы'
               WHEN d.category = 'contracts' THEN 'Договоры и контракты'
               ELSE 'Другое'
           END as category_name,
           e.first_name as author_first_name,
           e.last_name as author_last_name
    FROM documents d
    LEFT JOIN employees e ON d.author_id = e.id
    ORDER BY d.created_at DESC
    LIMIT 20
");
$recentDocuments = $stmt->fetchAll();

// Статистика по документам
$stmt = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN category = 'gost' THEN 1 ELSE 0 END) as gost_count,
        SUM(CASE WHEN category = 'internal' THEN 1 ELSE 0 END) as internal_count,
        SUM(CASE WHEN category = 'technical' THEN 1 ELSE 0 END) as technical_count,
        SUM(CASE WHEN category = 'contracts' THEN 1 ELSE 0 END) as contracts_count
    FROM documents
");
$docStats = $stmt->fetch();

// Документы, требующие внимания (с истекающим сроком действия)
$stmt = $db->query("
    SELECT d.*, 
           CASE 
               WHEN d.category = 'gost' THEN 'ГОСТ Документы'
               WHEN d.category = 'internal' THEN 'Внутренние документы'
               WHEN d.category = 'technical' THEN 'Технические документы'
               WHEN d.category = 'contracts' THEN 'Договоры и контракты'
               ELSE 'Другое'
           END as category_name,
           DATEDIFF(d.expiry_date, NOW()) as days_until_expiry
    FROM documents d
    WHERE d.expiry_date IS NOT NULL 
      AND d.expiry_date <= DATE_ADD(NOW(), INTERVAL 30 DAY)
      AND d.status = 'active'
    ORDER BY d.expiry_date ASC
    LIMIT 10
");
$expiringDocuments = $stmt->fetchAll();

// Проблемы с документами
$documentIssues = [];

if (!empty($expiringDocuments)) {
    $documentIssues[] = [
        'type' => 'warning',
        'title' => 'Истекающий срок действия',
        'count' => count($expiringDocuments),
        'message' => 'Документы требуют продления или обновления',
        'recommendation' => 'Проверить документы и инициировать процедуру продления'
    ];
}

$pageTitle = 'Документы | ' . APP_NAME;
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
        .category-card {
            background: var(--glass-bg);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 2rem;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .category-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            border-color: var(--primary-color);
        }
        
        .category-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.05);
            font-size: 2rem;
        }
        
        .category-count {
            font-size: 2.5rem;
            font-weight: 700;
            margin: 1rem 0;
        }
        
        .document-card {
            background: var(--glass-bg);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
        }
        
        .document-card:hover {
            border-color: var(--primary-color);
            background: rgba(255, 255, 255, 0.08);
        }
        
        .document-meta {
            display: flex;
            gap: 1.5rem;
            margin-top: 1rem;
            font-size: 0.85rem;
            color: var(--text-secondary);
        }
        
        .document-meta i {
            margin-right: 0.5rem;
        }
        
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .status-active {
            background: rgba(48, 209, 88, 0.2);
            color: var(--success-color);
        }
        
        .status-expiring {
            background: rgba(255, 159, 67, 0.2);
            color: var(--warning-color);
        }
        
        .status-expired {
            background: rgba(255, 59, 48, 0.2);
            color: var(--danger-color);
        }
        
        .status-draft {
            background: rgba(142, 142, 147, 0.2);
            color: var(--text-secondary);
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

            <?php if (hasRole(['admin', 'manager', 'technologist', 'operator'])): ?>
            <li>
                <a href="<?= APP_URL ?>/modules/production/index.php" class="nav-link">
                    <i class="fas fa-cogs"></i>
                    Производство
                </a>
            </li>
            <?php endif; ?>

            <?php if (hasRole(['admin', 'manager', 'warehouse_manager'])): ?>
            <li>
                <a href="<?= APP_URL ?>/modules/warehouse/index.php" class="nav-link">
                    <i class="fas fa-warehouse"></i>
                    Склад
                </a>
            </li>
            <?php endif; ?>

            <?php if (hasRole(['admin', 'manager', 'technologist'])): ?>
            <li>
                <a href="<?= APP_URL ?>/modules/equipment/index.php" class="nav-link">
                    <i class="fas fa-tools"></i>
                    Оборудование
                </a>
            </li>
            <?php endif; ?>

            <?php if (hasRole(['admin', 'manager', 'logistician'])): ?>
            <li>
                <a href="<?= APP_URL ?>/modules/shipment/index.php" class="nav-link">
                    <i class="fas fa-truck"></i>
                    Отгрузка
                </a>
            </li>
            <?php endif; ?>

            <?php if (hasRole(['admin', 'manager', 'technologist'])): ?>
            <li>
                <a href="<?= APP_URL ?>/modules/documents/index.php" class="nav-link active">
                    <i class="fas fa-file-contract"></i>
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
                <span class="user-name"><?= e($_SESSION['full_name']) ?></span>
                <span class="user-role"><?= e(getRoleName($_SESSION['role'])) ?></span>
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
                <h1><i class="fas fa-file-contract"></i> Управление документами</h1>
                <p>Централизованное хранение и управление документацией предприятия</p>
            </div>
            <?php if (hasRole(['admin', 'manager', 'technologist'])): ?>
            <a href="create.php" class="btn-primary-custom">
                <i class="fas fa-plus"></i> Добавить документ
            </a>
            <?php endif; ?>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?= $docStats['total'] ?? 0 ?></div>
                <div class="stat-label">Всего документов</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--primary-color);"><?= $docStats['gost_count'] ?? 0 ?></div>
                <div class="stat-label">ГОСТ Документы</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--info-color);"><?= $docStats['internal_count'] ?? 0 ?></div>
                <div class="stat-label">Внутренние</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--warning-color);"><?= $docStats['technical_count'] ?? 0 ?></div>
                <div class="stat-label">Технические</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--success-color);"><?= $docStats['contracts_count'] ?? 0 ?></div>
                <div class="stat-label">Договоры</div>
            </div>
        </div>

        <!-- Document Categories -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-folder-open"></i> Категории документов
                </div>
            </div>
            <div class="card-body">
                <div class="row g-4">
                    <?php foreach ($documentCategories as $key => $category): ?>
                    <div class="col-md-6 col-lg-3">
                        <div class="category-card" onclick="window.location.href='category.php?cat=<?= $key ?>'">
                            <div class="category-icon" style="color: <?= $category['color'] ?>;">
                                <i class="fas <?= $category['icon'] ?>"></i>
                            </div>
                            <h4><?= $category['name'] ?></h4>
                            <div class="category-count" style="color: <?= $category['color'] ?>;">
                                <?= $docStats[$key . '_count'] ?? 0 ?>
                            </div>
                            <p style="color: var(--text-secondary); font-size: 0.9rem;">
                                <?php if ($key == 'gost'): ?>
                                    Паспорта, руководства, сертификаты
                                <?php elseif ($key == 'internal'): ?>
                                    Приказы, распоряжения, инструкции
                                <?php elseif ($key == 'technical'): ?>
                                    Чертежи, спецификации, ТУ
                                <?php else: ?>
                                    Контракты, соглашения, договоры
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Issues & Recommendations -->
        <?php if (!empty($documentIssues)): ?>
        <div class="issues-section">
            <h2 class="section-title"><i class="fas fa-exclamation-triangle"></i> Проблемы и рекомендации</h2>
            <div class="issues-grid">
                <?php foreach ($documentIssues as $issue): ?>
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

        <!-- Expiring Documents -->
        <?php if (!empty($expiringDocuments)): ?>
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-clock"></i> Истекающие документы
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Название</th>
                                <th>Категория</th>
                                <th>Срок действия</th>
                                <th>Дней осталось</th>
                                <th>Статус</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($expiringDocuments as $doc): ?>
                            <tr>
                                <td><strong><?= e($doc['title']) ?></strong></td>
                                <td><?= e($doc['category_name']) ?></td>
                                <td><?= date('d.m.Y', strtotime($doc['expiry_date'])) ?></td>
                                <td>
                                    <span style="color: <?= $doc['days_until_expiry'] <= 7 ? 'var(--danger-color)' : 'var(--warning-color)' ?>;">
                                        <?= $doc['days_until_expiry'] ?> дн.
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge status-<?= $doc['days_until_expiry'] <= 0 ? 'expired' : ($doc['days_until_expiry'] <= 7 ? 'expiring' : 'expiring') ?>">
                                        <?= $doc['days_until_expiry'] <= 0 ? 'Истёк' : 'Истекает' ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="view.php?id=<?= $doc['id'] ?>" class="btn-action">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="edit.php?id=<?= $doc['id'] ?>" class="btn-action">
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
        <?php endif; ?>

        <!-- Recent Documents -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-history"></i> Последние документы
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($recentDocuments)): ?>
                <div style="text-align: center; padding: 3rem; color: var(--text-secondary);">
                    <i class="fas fa-folder-open" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                    <p>Документы ещё не добавлены</p>
                    <?php if (hasRole(['admin', 'manager', 'technologist'])): ?>
                    <a href="create.php" class="btn-primary-custom" style="margin-top: 1rem;">
                        <i class="fas fa-plus"></i> Добавить первый документ
                    </a>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($recentDocuments as $doc): ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="document-card">
                            <div style="display: flex; justify-content: space-between; align-items: start;">
                                <div style="flex: 1;">
                                    <h4 style="margin-bottom: 0.5rem; font-size: 1.1rem;"><?= e($doc['title']) ?></h4>
                                    <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 0.5rem;">
                                        <?= e($doc['category_name']) ?>
                                    </p>
                                    <?php if ($doc['description']): ?>
                                    <p style="font-size: 0.85rem; color: var(--text-secondary);">
                                        <?= e(mb_substr($doc['description'], 0, 100)) ?><?= mb_strlen($doc['description']) > 100 ? '...' : '' ?>
                                    </p>
                                    <?php endif; ?>
                                </div>
                                <span class="status-badge status-<?= $doc['status'] == 'active' ? 'active' : ($doc['status'] == 'draft' ? 'draft' : 'expired') ?>">
                                    <?= $doc['status'] == 'active' ? 'Активен' : ($doc['status'] == 'draft' ? 'Черновик' : 'Архив') ?>
                                </span>
                            </div>
                            <div class="document-meta">
                                <span><i class="fas fa-user"></i> <?= e($doc['author_first_name'] . ' ' . $doc['author_last_name']) ?></span>
                                <span><i class="fas fa-calendar"></i> <?= date('d.m.Y', strtotime($doc['created_at'])) ?></span>
                                <?php if ($doc['expiry_date']): ?>
                                <span><i class="fas fa-hourglass-end"></i> до <?= date('d.m.Y', strtotime($doc['expiry_date'])) ?></span>
                                <?php endif; ?>
                            </div>
                            <div style="margin-top: 1rem; display: flex; gap: 0.5rem;">
                                <a href="view.php?id=<?= $doc['id'] ?>" class="btn-action" style="flex: 1; text-align: center;">
                                    <i class="fas fa-eye"></i> Просмотр
                                </a>
                                <?php if (hasRole(['admin', 'manager', 'technologist'])): ?>
                                <a href="edit.php?id=<?= $doc['id'] ?>" class="btn-action">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleMobileMenu() {
            const navMenu = document.querySelector('.nav-menu');
            navMenu.classList.toggle('active');
        }
    </script>
</body>
</html>
