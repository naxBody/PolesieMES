<?php
/**
 * Модуль управления сотрудниками - Главная страница
 * PolesieMES - Система управления производством ОАО "Полесьеэлектромаш"
 * 
 * Контроль сотрудников, должностей, квалификации
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

// Проверка авторизации
requireAuth();

$db = getDB();
$user = getCurrentUser();

// Получение всех сотрудников с информацией
$stmt = $db->query("
    SELECT e.*, p.name as position_name,
           CASE 
               WHEN e.status = 'active' THEN 'normal'
               WHEN e.status = 'vacation' THEN 'info'
               WHEN e.status = 'sick' THEN 'warning'
               WHEN e.status = 'terminated' THEN 'critical'
               ELSE 'normal'
           END as status_class
    FROM employees e
    LEFT JOIN positions p ON e.position_id = p.id
    ORDER BY e.last_name ASC, e.first_name ASC
");
$employees = $stmt->fetchAll();

// Статистика по сотрудникам
$stmt = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN status = 'vacation' THEN 1 ELSE 0 END) as vacation,
        SUM(CASE WHEN status = 'sick' THEN 1 ELSE 0 END) as sick,
        SUM(CASE WHEN status = 'terminated' THEN 1 ELSE 0 END) as terminated
    FROM employees
");
$employeeStats = $stmt->fetch();

// Сотрудники в отпуске или на больничном
$stmt = $db->query("
    SELECT e.*, p.name as position_name
    FROM employees e
    LEFT JOIN positions p ON e.position_id = p.id
    WHERE e.status IN ('vacation', 'sick')
    ORDER BY e.updated_at DESC
    LIMIT 10
");
$awayEmployees = $stmt->fetchAll();

// Новые сотрудники (за последний месяц)
$stmt = $db->query("
    SELECT e.*, p.name as position_name
    FROM employees e
    LEFT JOIN positions p ON e.position_id = p.id
    WHERE e.hire_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ORDER BY e.hire_date DESC
    LIMIT 10
");
$newEmployees = $stmt->fetchAll();

// Проблемы с сотрудниками
$employeeIssues = [];

if (($employeeStats['sick'] ?? 0) > 0) {
    $employeeIssues[] = [
        'type' => 'warning',
        'title' => 'На больничном',
        'count' => $employeeStats['sick'],
        'message' => 'Сотрудники временно нетрудоспособны',
        'recommendation' => 'Контролировать сроки возвращения и при необходимости искать замену'
    ];
}

if (($employeeStats['vacation'] ?? 0) > 0) {
    $employeeIssues[] = [
        'type' => 'info',
        'title' => 'В отпуске',
        'count' => $employeeStats['vacation'],
        'message' => 'Сотрудники в плановом отпуске',
        'recommendation' => 'Убедиться, что их обязанности распределены между другими сотрудниками'
    ];
}

$pageTitle = 'Управление сотрудниками | ' . APP_NAME;
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
            <li><a href="<?= APP_URL ?>/modules/equipment/index.php" class="nav-link"><i class="fas fa-tools"></i> Оборудование</a></li>
            <li><a href="<?= APP_URL ?>/modules/gost_docs/index.php" class="nav-link"><i class="fas fa-file-contract"></i> ГОСТ Документы</a></li>
            <li><a href="<?= APP_URL ?>/modules/employees/index.php" class="nav-link active"><i class="fas fa-users"></i> Сотрудники</a></li>
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
                <h1><i class="fas fa-users"></i> Управление сотрудниками</h1>
                <p>Контроль персонала, должностей и квалификации</p>
            </div>
            <?php if (hasRole(['admin', 'manager'])): ?>
            <a href="create.php" class="btn-primary-custom">
                <i class="fas fa-plus"></i> Добавить сотрудника
            </a>
            <?php endif; ?>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?= $employeeStats['total'] ?></div>
                <div class="stat-label">Всего сотрудников</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--success-color);"><?= $employeeStats['active'] ?></div>
                <div class="stat-label">Активные</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--info-color);"><?= $employeeStats['vacation'] ?></div>
                <div class="stat-label">В отпуске</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--warning-color);"><?= $employeeStats['sick'] ?></div>
                <div class="stat-label">На больничном</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--danger-color);"><?= $employeeStats['terminated'] ?></div>
                <div class="stat-label">Уволенные</div>
            </div>
        </div>

        <!-- Issues & Recommendations -->
        <?php if (!empty($employeeIssues)): ?>
        <div class="issues-section">
            <h2 class="section-title"><i class="fas fa-exclamation-triangle"></i> Проблемы и рекомендации</h2>
            <div class="issues-grid">
                <?php foreach ($employeeIssues as $issue): ?>
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

        <!-- Employees Away -->
        <?php if (!empty($awayEmployees)): ?>
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-plane-departure"></i> Отсутствуют на работе
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Сотрудник</th>
                                <th>Должность</th>
                                <th>Отдел</th>
                                <th>Статус</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($awayEmployees as $employee): ?>
                            <tr>
                                <td>
                                    <strong><?= e($employee['first_name']) ?> <?= e($employee['last_name']) ?></strong>
                                </td>
                                <td><?= e($employee['position_name'] ?? '-') ?></td>
                                <td><?= e($employee['department'] ?? '-') ?></td>
                                <td>
                                    <span class="badge-status badge-<?= $employee['status'] ?>">
                                        <?= $employee['status'] == 'vacation' ? 'В отпуске' : 'На больничном' ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="view.php?id=<?= $employee['id'] ?>" class="btn-action">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="edit.php?id=<?= $employee['id'] ?>" class="btn-action">
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

        <!-- New Employees -->
        <?php if (!empty($newEmployees)): ?>
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-user-plus"></i> Новые сотрудники
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Сотрудник</th>
                                <th>Должность</th>
                                <th>Отдел</th>
                                <th>Дата приема</th>
                                <th>Контакты</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($newEmployees as $employee): ?>
                            <tr>
                                <td>
                                    <strong><?= e($employee['first_name']) ?> <?= e($employee['last_name']) ?></strong>
                                </td>
                                <td><?= e($employee['position_name'] ?? '-') ?></td>
                                <td><?= e($employee['department'] ?? '-') ?></td>
                                <td><?= date('d.m.Y', strtotime($employee['hire_date'])) ?></td>
                                <td>
                                    <?php if ($employee['email']): ?>
                                        <i class="fas fa-envelope"></i> <?= e($employee['email']) ?>
                                    <?php endif; ?>
                                    <?php if ($employee['phone']): ?>
                                        <br><i class="fas fa-phone"></i> <?= e($employee['phone']) ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="view.php?id=<?= $employee['id'] ?>" class="btn-action">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="edit.php?id=<?= $employee['id'] ?>" class="btn-action">
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

        <!-- All Employees -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-users"></i> Все сотрудники
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
                                <th>ФИО</th>
                                <th>Табельный №</th>
                                <th>Должность</th>
                                <th>Отдел</th>
                                <th>Email</th>
                                <th>Телефон</th>
                                <th>Дата приема</th>
                                <th>Статус</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($employees as $employee): ?>
                            <tr>
                                <td>
                                    <strong><?= e($employee['last_name']) ?> <?= e($employee['first_name']) ?> <?= e($employee['middle_name'] ?? '') ?></strong>
                                </td>
                                <td><?= e($employee['employee_code']) ?></td>
                                <td><?= e($employee['position_name'] ?? '-') ?></td>
                                <td><?= e($employee['department'] ?? '-') ?></td>
                                <td>
                                    <?php if ($employee['email']): ?>
                                        <i class="fas fa-envelope"></i> <?= e($employee['email']) ?>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted);">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($employee['phone']): ?>
                                        <i class="fas fa-phone"></i> <?= e($employee['phone']) ?>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted);">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= date('d.m.Y', strtotime($employee['hire_date'])) ?></td>
                                <td>
                                    <span class="badge-status badge-<?= $employee['status'] ?>">
                                        <?= $employee['status'] == 'active' ? 'Активен' :
                                            ($employee['status'] == 'vacation' ? 'Отпуск' :
                                            ($employee['status'] == 'sick' ? 'Больничный' : 'Уволен')) ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="view.php?id=<?= $employee['id'] ?>" class="btn-action">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <?php if (hasRole(['admin', 'manager'])): ?>
                                    <a href="edit.php?id=<?= $employee['id'] ?>" class="btn-action">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Smooth scroll for navbar
        window.addEventListener('scroll', function() {
            const navbar = document.querySelector('.navbar');
            if (window.scrollY > 50) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
        });
    </script>
</body>
</html>
