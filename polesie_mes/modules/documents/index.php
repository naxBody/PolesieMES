<?php
/**
 * Модуль управления документами - Главная страница
 * PolesieMES - Система управления производством ОАО "Полесьеэлектромаш"
 * 
 * Реестр технической документации, ГОСТов, инструкций и договоров
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

// Проверка авторизации
requireAuth();

$db = getDB();
$user = getCurrentUser();

// Демо-данные документов (так как таблица еще не создана в БД)
$documents = [
    ['id' => 1, 'name' => 'ГОСТ 2.105-95 ЕСКД', 'type' => 'ГОСТ', 'status' => 'active', 'date' => '2023-10-15', 'size' => '2.4 MB'],
    ['id' => 2, 'name' => 'Инструкция по охране труда', 'type' => 'Инструкция', 'status' => 'active', 'date' => '2023-11-20', 'size' => '1.1 MB'],
    ['id' => 3, 'name' => 'План эвакуации 2024', 'type' => 'План', 'status' => 'archive', 'date' => '2024-01-10', 'size' => '5.8 MB'],
    ['id' => 4, 'name' => 'Договор поставки №45', 'type' => 'Договор', 'status' => 'draft', 'date' => '2024-02-05', 'size' => '0.8 MB'],
    ['id' => 5, 'name' => 'Сертификат соответствия', 'type' => 'Сертификат', 'status' => 'active', 'date' => '2023-12-12', 'size' => '1.5 MB'],
    ['id' => 6, 'name' => 'ГОСТ 19.101-78 ЕСПД', 'type' => 'ГОСТ', 'status' => 'active', 'date' => '2023-09-05', 'size' => '3.2 MB'],
    ['id' => 7, 'name' => 'Регламент техобслуживания', 'type' => 'Инструкция', 'status' => 'active', 'date' => '2024-03-01', 'size' => '1.8 MB'],
];

// Статистика
$total_docs = count($documents);
$active_docs = count(array_filter($documents, fn($d) => ($d['status'] ?? '') === 'active'));
$archive_docs = count(array_filter($documents, fn($d) => ($d['status'] ?? '') === 'archive'));
$draft_docs = count(array_filter($documents, fn($d) => ($d['status'] ?? '') === 'draft'));

$pageTitle = 'Управление документами | ' . APP_NAME;
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

            <?php if (hasRole(['admin', 'manager', 'warehouse_keeper'])): ?>
            <li>
                <a href="<?= APP_URL ?>/modules/warehouse/warehouse_dashboard.php" class="nav-link">
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
                <p>Реестр технической документации, ГОСТов и инструкций</p>
            </div>
            <?php if (hasRole(['admin', 'manager', 'technologist'])): ?>
            <a href="#" class="btn-primary-custom" onclick="alert('Функция загрузки документа будет доступна в следующей версии'); return false;">
                <i class="fas fa-plus"></i> Добавить документ
            </a>
            <?php endif; ?>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?= $total_docs ?></div>
                <div class="stat-label">Всего документов</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--success-color);"><?= $active_docs ?></div>
                <div class="stat-label">Действующие</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--warning-color);"><?= $archive_docs ?></div>
                <div class="stat-label">В архиве</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--info-color);"><?= $draft_docs ?></div>
                <div class="stat-label">Черновики</div>
            </div>
        </div>

        <!-- Documents Table -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-list"></i> Реестр документов
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Наименование</th>
                                <th>Тип</th>
                                <th>Дата добавления</th>
                                <th>Размер</th>
                                <th>Статус</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($documents as $doc): ?>
                            <tr>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <i class="fas fa-file-pdf" style="color: var(--danger-color);"></i>
                                        <?= e($doc['name']) ?>
                                    </div>
                                </td>
                                <td><?= e($doc['type']) ?></td>
                                <td><?= formatDate($doc['date']) ?></td>
                                <td><?= e($doc['size']) ?></td>
                                <td>
                                    <?php
                                    $statusClass = 'badge-success';
                                    $statusText = 'Действует';
                                    if ($doc['status'] === 'archive') {
                                        $statusClass = 'badge-secondary';
                                        $statusText = 'Архив';
                                    } elseif ($doc['status'] === 'draft') {
                                        $statusClass = 'badge-warning';
                                        $statusText = 'Черновик';
                                    }
                                    ?>
                                    <span class="badge <?= $statusClass ?>"><?= $statusText ?></span>
                                </td>
                                <td>
                                    <a href="view.php?id=<?= $doc['id'] ?>" class="btn-action" title="Просмотр"><i class="fas fa-eye"></i></a>
                                    <button class="btn-action" title="Скачать" onclick="alert('Скачивание файла...')"><i class="fas fa-download"></i></button>
                                    <?php if (hasRole(['admin', 'manager', 'technologist'])): ?>
                                    <button class="btn-action" title="Редактировать" onclick="alert('Функция редактирования будет доступна в следующей версии')"><i class="fas fa-edit"></i></button>
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

    <script>
        // Navbar scroll effect
        window.addEventListener('scroll', function() {
            const navbar = document.getElementById('navbar');
            if (window.scrollY > 50) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
        });

        // Mobile menu toggle
        function toggleMobileMenu() {
            const menu = document.querySelector('.nav-menu');
            menu.classList.toggle('active');
        }
    </script>
</body>
</html>
