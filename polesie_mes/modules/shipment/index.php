<?php
/**
 * Модуль отгрузки - Главная страница
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

// Обработка действий
$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && hasRole(['admin', 'manager', 'logistician'])) {
    try {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'ship_order':
                $order_id = (int)$_POST['order_id'];
                $tracking_number = trim($_POST['tracking_number'] ?? '');
                $carrier = trim($_POST['carrier'] ?? '');
                $driver_name = trim($_POST['driver_name'] ?? '');
                $vehicle_number = trim($_POST['vehicle_number'] ?? '');
                
                $stmt = $db->prepare("
                    UPDATE orders 
                    SET status = 'shipped', 
                        tracking_number = ?,
                        carrier = ?,
                        driver_name = ?,
                        vehicle_number = ?,
                        updated_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$tracking_number, $carrier, $driver_name, $vehicle_number, $order_id]);
                
                logActivity($user['id'], 'ship_order', 'shipment', $order_id, 'Заказ отгружен');
                $successMessage = "Заказ успешно отгружен";
                break;
                
            case 'complete_order':
                $order_id = (int)$_POST['order_id'];
                $received_at = date('Y-m-d H:i:s');
                $notes = trim($_POST['completion_notes'] ?? '');
                
                $stmt = $db->prepare("
                    UPDATE orders 
                    SET status = 'completed', 
                        received_at = ?,
                        completion_notes = ?,
                        updated_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$received_at, $notes, $order_id]);
                
                logActivity($user['id'], 'complete_order', 'shipment', $order_id, 'Отгрузка завершена');
                $successMessage = "Отгрузка успешно завершена";
                break;
        }
    } catch (Exception $e) {
        $errorMessage = "Ошибка: " . $e->getMessage();
    }
}

// Получение готовых к отгрузке заказов
$stmt = $db->query("
    SELECT o.*, c.name as customer_name, c.inn, c.address, c.phone as customer_phone, c.email,
           s.first_name as manager_first_name, s.last_name as manager_last_name,
           DATEDIFF(NOW(), o.delivery_date) as days_since_delivery
    FROM orders o
    LEFT JOIN partners c ON o.customer_id = c.id
    LEFT JOIN staff s ON o.manager_id = s.id
    WHERE o.status IN ('ready', 'shipped')
    ORDER BY o.delivery_date ASC
");
$shipmentOrders = $stmt->fetchAll();

// Статистика по отгрузкам
$stmt = $db->query("
    SELECT 
        status,
        COUNT(*) as count,
        COALESCE(SUM(total_amount), 0) as total_amount
    FROM orders
    WHERE status IN ('ready', 'shipped', 'completed')
    GROUP BY status
");
$shipmentStats = [];
while ($row = $stmt->fetch()) {
    $shipmentStats[$row['status']] = $row;
}

// Завершенные отгрузки за месяц
$stmt = $db->query("
    SELECT COUNT(*) as completed_count, COALESCE(SUM(total_amount), 0) as total_amount
    FROM orders
    WHERE status = 'completed'
    AND MONTH(updated_at) = MONTH(CURRENT_DATE())
    AND YEAR(updated_at) = YEAR(CURRENT_DATE())
");
$monthlyStats = $stmt->fetch();

// Все отгрузки с историей
$stmt = $db->query("
    SELECT o.*, c.name as customer_name, 
           DATE_FORMAT(o.updated_at, '%d.%m.%Y %H:%i') as last_update
    FROM orders o
    LEFT JOIN partners c ON o.customer_id = c.id
    WHERE o.status IN ('ready', 'shipped', 'completed')
    ORDER BY o.updated_at DESC
    LIMIT 50
");
$shipmentHistory = $stmt->fetchAll();

// Отгруженные заказы для завершения
$stmt = $db->query("
    SELECT o.*, c.name as customer_name, c.address,
           DATE_FORMAT(o.updated_at, '%d.%m.%Y %H:%i') as shipped_date
    FROM orders o
    LEFT JOIN partners c ON o.customer_id = c.id
    WHERE o.status = 'shipped'
    ORDER BY o.updated_at DESC
");
$shippedOrders = $stmt->fetchAll();

$pageTitle = 'Отгрузка продукции | ' . APP_NAME;
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
        .status-badge {
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .status-ready { background: #ffd60a; color: black; }
        .status-shipped { background: #32ade6; color: white; }
        .status-completed { background: #30d158; color: white; }
        .status-cancelled { background: #ff453a; color: white; }

        .modal-content {
            background: var(--bg-card);
            backdrop-filter: var(--backdrop-blur);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            box-shadow: var(--shadow-lg);
        }

        .modal-header {
            border-bottom: 1px solid var(--glass-border);
            background: linear-gradient(135deg, rgba(255, 107, 107, 0.1), rgba(255, 142, 83, 0.05));
            border-radius: 16px 16px 0 0;
            padding: 1.5rem;
        }

        .modal-title {
            color: var(--text-primary);
            font-weight: 600;
            font-size: 1.25rem;
        }

        .modal-body {
            padding: 2rem;
            color: var(--text-primary);
        }

        .modal-footer {
            border-top: 1px solid var(--glass-border);
            padding: 1.5rem 2rem;
            background: rgba(255, 255, 255, 0.02);
            border-radius: 0 0 16px 16px;
        }

        .btn-close {
            filter: invert(1);
        }

        .form-label {
            color: var(--text-secondary);
            font-weight: 500;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }

        .form-control, .form-select {
            background: var(--bg-input);
            border: 1px solid var(--border);
            color: var(--text-primary);
            border-radius: 10px;
            padding: 0.75rem 1rem;
            transition: all 0.3s ease;
        }

        .form-control:focus, .form-select:focus {
            background: rgba(30, 30, 45, 0.7);
            border-color: var(--primary-gradient-start);
            box-shadow: 0 0 0 3px rgba(255, 107, 107, 0.2);
            color: var(--text-primary);
        }

        .form-control::placeholder {
            color: var(--text-muted);
        }

        .input-group-custom {
            position: relative;
        }

        .input-group-custom .input-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            z-index: 1;
        }

        .input-group-custom .form-control,
        .input-group-custom .form-select {
            padding-left: 2.75rem;
        }

        .section-divider {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin: 1.5rem 0 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--glass-border);
        }

        .section-divider:first-child {
            margin-top: 0;
        }

        .section-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: linear-gradient(135deg, var(--primary-gradient-start), var(--primary-gradient-end));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.9rem;
        }

        .section-divider span {
            color: var(--text-primary);
            font-weight: 600;
            font-size: 1rem;
        }

        .alert {
            border-radius: 12px;
            border: none;
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
        }

        .alert-success {
            background: rgba(48, 209, 88, 0.15);
            color: #30d158;
            border: 1px solid rgba(48, 209, 88, 0.3);
        }

        .alert-danger {
            background: rgba(255, 69, 58, 0.15);
            color: #ff453a;
            border: 1px solid rgba(255, 69, 58, 0.3);
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
            <li><a href="<?= APP_URL ?>/modules/production/index.php" class="nav-link"><i class="fas fa-cogs"></i> Производство</a></li>
            <?php endif; ?>
            <?php if (hasRole(['admin', 'manager', 'warehouse_keeper'])): ?>
            <li><a href="<?= APP_URL ?>/modules/warehouse/warehouse_dashboard.php" class="nav-link"><i class="fas fa-warehouse"></i> Склад</a></li>
            <?php endif; ?>
            <?php if (hasRole(['admin', 'manager', 'technologist'])): ?>
            <li><a href="<?= APP_URL ?>/modules/equipment/index.php" class="nav-link"><i class="fas fa-tools"></i> Оборудование</a></li>
            <?php endif; ?>
            <?php if (hasRole(['admin', 'manager', 'logistician'])): ?>
            <li><a href="<?= APP_URL ?>/modules/shipment/index.php" class="nav-link active"><i class="fas fa-truck"></i> Отгрузка</a></li>
            <?php endif; ?>
            <?php if (hasRole(['admin', 'manager', 'technologist'])): ?>
            <li><a href="<?= APP_URL ?>/modules/documents/index.php" class="nav-link"><i class="fas fa-file-contract"></i> Документы</a></li>
            <?php endif; ?>
            <?php if (hasRole('admin')): ?>
            <li><a href="<?= APP_URL ?>/modules/employees/index.php" class="nav-link"><i class="fas fa-users"></i> Сотрудники</a></li>
            <?php endif; ?>
        </ul>
        
        <div class="user-menu">
            <span style="color: var(--text-secondary);"><?= e(getCurrentUser()['username']) ?></span>
            <a href="<?= APP_URL ?>/modules/auth/logout.php" class="btn-logout">
                <i class="fas fa-sign-out-alt"></i> Выход
            </a>
        </div>

        <button class="mobile-menu-btn" onclick="toggleMobileMenu()">
            <span></span><span></span><span></span>
        </button>
    </nav>

    <!-- Основной контент -->
    <div class="main-content">
        <div class="page-header">
            <div class="page-title">
                <h1><i class="fas fa-truck-loading"></i> Отгрузка продукции</h1>
                <p>Управление отгрузкой и доставкой заказов клиентам</p>
            </div>
        </div>

        <?php if ($successMessage): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?= $successMessage ?>
        </div>
        <?php endif; ?>

        <?php if ($errorMessage): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i> <?= $errorMessage ?>
        </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value" style="color: #ffd60a;"><?= $shipmentStats['ready']['count'] ?? 0 ?></div>
                <div class="stat-label">📦 Готовы к отгрузке</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: #32ade6;"><?= $shipmentStats['shipped']['count'] ?? 0 ?></div>
                <div class="stat-label">🚚 В пути</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: #30d158;"><?= $monthlyStats['completed_count'] ?? 0 ?></div>
                <div class="stat-label">✅ Отгружено за месяц</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--primary-gradient-start);"><?= number_format((float)($monthlyStats['total_amount'] ?? 0), 0, ',', ' ') ?></div>
                <div class="stat-label">💰 Сумма за месяц (BYN)</div>
            </div>
        </div>

        <!-- Ready for Shipment -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-boxes"></i> Готовы к отгрузке
                </div>
                <div class="card-stats">
                    <span class="badge bg-secondary">Всего: <?= count($shipmentOrders) ?></span>
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($shipmentOrders)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>Нет заказов, готовых к отгрузке</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>№ заказа</th>
                                <th>Клиент</th>
                                <th>Адрес доставки</th>
                                <th>Контакты</th>
                                <th>Дата поставки</th>
                                <th>Статус</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($shipmentOrders as $order): ?>
                            <tr>
                                <td><strong><?= e($order['order_number']) ?></strong></td>
                                <td><?= e($order['customer_name']) ?></td>
                                <td><?= e($order['address']) ?></td>
                                <td>
                                    <?= e($order['customer_phone']) ?><br>
                                    <small style="color: var(--text-secondary);"><?= e($order['email']) ?></small>
                                </td>
                                <td>
                                    <?= date('d.m.Y', strtotime($order['delivery_date'])) ?>
                                    <?php if ($order['days_since_delivery'] > 0): ?>
                                        <br><small style="color: var(--warning-color);">+<?= $order['days_since_delivery'] ?> дн.</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-badge status-<?= e($order['status']) ?>">
                                        <?php
                                        $statusNames = ['ready' => 'Готов', 'shipped' => 'В пути', 'completed' => 'Завершён', 'cancelled' => 'Отменён'];
                                        echo $statusNames[$order['status']] ?? $order['status'];
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <?php if ($order['status'] == 'ready'): ?>
                                        <button class="btn btn-warning btn-sm" onclick="showShipModal(<?= $order['id'] ?>, '<?= e($order['order_number']) ?>', '<?= e($order['customer_name']) ?>')">
                                            <i class="fas fa-truck"></i> Отгрузить
                                        </button>
                                        <?php elseif ($order['status'] == 'shipped'): ?>
                                        <button class="btn btn-success btn-sm" onclick="showCompleteModal(<?= $order['id'] ?>, '<?= e($order['order_number']) ?>')">
                                            <i class="fas fa-check"></i> Завершить
                                        </button>
                                        <?php endif; ?>
                                        <a href="order_details.php?order_id=<?= $order['id'] ?>" class="btn btn-primary btn-sm" title="Просмотр полной информации о заказе">
                                            <i class="fas fa-eye"></i> Информация
                                        </a>
                                        <a href="../documents/index.php?order_id=<?= $order['id'] ?>" class="btn btn-info btn-sm">
                                            <i class="fas fa-file-alt"></i> Документы
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- In Transit Orders -->
        <?php if (!empty($shippedOrders)): ?>
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-shipping-fast"></i> В пути
                </div>
                <div class="card-stats">
                    <span class="badge bg-info">Всего: <?= count($shippedOrders) ?></span>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>№ заказа</th>
                                <th>Клиент</th>
                                <th>Адрес</th>
                                <th>Отгружен</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($shippedOrders as $order): ?>
                            <tr>
                                <td><strong><?= e($order['order_number']) ?></strong></td>
                                <td><?= e($order['customer_name']) ?></td>
                                <td><?= e($order['address']) ?></td>
                                <td><?= $order['shipped_date'] ?></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-success btn-sm" onclick="showCompleteModal(<?= $order['id'] ?>, '<?= e($order['order_number']) ?>')">
                                            <i class="fas fa-check"></i> Завершить
                                        </button>
                                        <a href="order_details.php?order_id=<?= $order['id'] ?>" class="btn btn-primary btn-sm" title="Просмотр полной информации о заказе">
                                            <i class="fas fa-eye"></i> Информация
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Shipment History -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-history"></i> История отгрузок
                </div>
                <div class="card-stats">
                    <span class="badge bg-secondary">Последние 50</span>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>№ заказа</th>
                                <th>Клиент</th>
                                <th>Сумма (BYN)</th>
                                <th>Статус</th>
                                <th>Последнее обновление</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($shipmentHistory as $order): ?>
                            <tr>
                                <td><strong><?= e($order['order_number']) ?></strong></td>
                                <td><?= e($order['customer_name']) ?></td>
                                <td><?= number_format((float)($order['total_amount'] ?? 0), 2, ',', ' ') ?></td>
                                <td>
                                    <span class="status-badge status-<?= e($order['status']) ?>">
                                        <?php
                                        $statusNames = ['ready' => 'Готов', 'shipped' => 'В пути', 'completed' => 'Завершён', 'cancelled' => 'Отменён'];
                                        echo $statusNames[$order['status']] ?? $order['status'];
                                        ?>
                                    </span>
                                </td>
                                <td><?= e($order['last_update']) ?></td>
                                <td>
                                    <a href="order_details.php?order_id=<?= $order['id'] ?>" class="btn btn-primary btn-sm" title="Просмотр полной информации о заказе">
                                        <i class="fas fa-eye"></i> Информация
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Ship Order -->
    <div class="modal fade" id="shipOrderModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="ship_order">
                    <input type="hidden" name="order_id" id="ship_order_id">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-truck-loading"></i> Отгрузка заказа <span id="ship_order_number"></span></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="section-divider">
                            <div class="section-icon"><i class="fas fa-info-circle"></i></div>
                            <span>Информация о доставке</span>
                        </div>

                        <p class="mb-3">Клиент: <strong id="ship_customer_name"></strong></p>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Номер отслеживания</label>
                                <div class="input-group-custom">
                                    <i class="fas fa-barcode input-icon"></i>
                                    <input type="text" name="tracking_number" class="form-control" placeholder="TT123456789">
                                </div>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Перевозчик</label>
                                <div class="input-group-custom">
                                    <i class="fas fa-truck input-icon"></i>
                                    <input type="text" name="carrier" class="form-control" placeholder="Название компании">
                                </div>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">ФИО водителя</label>
                                <div class="input-group-custom">
                                    <i class="fas fa-user input-icon"></i>
                                    <input type="text" name="driver_name" class="form-control" placeholder="Иванов И.И.">
                                </div>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Номер автомобиля</label>
                                <div class="input-group-custom">
                                    <i class="fas fa-car input-icon"></i>
                                    <input type="text" name="vehicle_number" class="form-control" placeholder="AB1234CD">
                                </div>
                            </div>
                        </div>

                        <div class="alert alert-info mb-0">
                            <i class="fas fa-info-circle"></i> После подтверждения заказ получит статус "В пути"
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                        <button type="submit" class="btn btn-warning">
                            <i class="fas fa-truck"></i> Подтвердить отгрузку
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Complete Order -->
    <div class="modal fade" id="completeOrderModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="complete_order">
                    <input type="hidden" name="order_id" id="complete_order_id">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-check-circle"></i> Завершение отгрузки <span id="complete_order_number"></span></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="section-divider">
                            <div class="section-icon"><i class="fas fa-clipboard-check"></i></div>
                            <span>Подтверждение получения</span>
                        </div>

                        <p class="mb-3">Подтвердите, что заказ был доставлен и получен клиентом.</p>

                        <div class="mb-3">
                            <label class="form-label">Примечание (опционально)</label>
                            <div class="input-group-custom">
                                <i class="fas fa-comment input-icon"></i>
                                <textarea name="completion_notes" class="form-control" rows="3" placeholder="Комментарий к доставке..."></textarea>
                            </div>
                        </div>

                        <div class="alert alert-success mb-0">
                            <i class="fas fa-check-circle"></i> После подтверждения заказ получит статус "Завершён"
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-check"></i> Подтвердить завершение
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function showShipModal(orderId, orderNumber, customerName) {
            document.getElementById('ship_order_id').value = orderId;
            document.getElementById('ship_order_number').textContent = orderNumber;
            document.getElementById('ship_customer_name').textContent = customerName;
            new bootstrap.Modal(document.getElementById('shipOrderModal')).show();
        }

        function showCompleteModal(orderId, orderNumber) {
            document.getElementById('complete_order_id').value = orderId;
            document.getElementById('complete_order_number').textContent = orderNumber;
            new bootstrap.Modal(document.getElementById('completeOrderModal')).show();
        }

        function toggleMobileMenu() {
            const navMenu = document.querySelector('.nav-menu');
            navMenu.classList.toggle('active');
        }
    </script>
</body>
</html>
