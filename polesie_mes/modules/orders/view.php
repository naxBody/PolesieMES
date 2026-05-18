<?php
/**
 * Универсальная страница просмотра заказа
 * PolesieMES - Система управления производством ОАО "Полесьеэлектромаш"
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireAuth();

$db = getDB();
$user = getCurrentUser();

// Получение ID заказа
$orderId = $_GET['id'] ?? null;

if (!$orderId) {
    redirectWithMessage(APP_URL . '/modules/orders/index.php', 'Заказ не указан', 'error');
}

// Получение информации о заказе
$stmt = $db->prepare("
    SELECT o.*, c.name as customer_name, c.inn, c.address, c.phone as customer_phone, c.email as customer_email,
           s.first_name as manager_first_name, s.last_name as manager_last_name
    FROM orders o
    LEFT JOIN partners c ON o.customer_id = c.id
    LEFT JOIN staff s ON o.manager_id = s.id
    WHERE o.id = :id
");
$stmt->execute(['id' => $orderId]);
$order = $stmt->fetch();

if (!$order) {
    redirectWithMessage(APP_URL . '/modules/orders/index.php', 'Заказ не найден', 'error');
}

// Декодирование состава заказа
$items = json_decode($order['items_json'], true) ?: [];

// Получение связанных производственных заданий
$stmt = $db->prepare("
    SELECT pt.*, i.name as product_name
    FROM production_tasks pt
    LEFT JOIN items i ON pt.product_id = i.id
    WHERE pt.order_id = :order_id
    ORDER BY pt.stage_sequence
");
$stmt->execute(['order_id' => $orderId]);
$tasks = $stmt->fetchAll();

// Расчет дней до поставки
$deliveryDate = new DateTime($order['delivery_date']);
$today = new DateTime();
$daysDiff = $today->diff($deliveryDate)->days;
$isOverdue = $deliveryDate < $today && !in_array($order['status'], ['completed', 'cancelled']);

$pageTitle = 'Заказ #' . e($order['order_number']) . ' | ' . APP_NAME;
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/common-style.css">
    <style>
        .order-header-card {
            background: linear-gradient(135deg, rgba(255, 107, 107, 0.15), rgba(255, 142, 83, 0.08));
            border: 1px solid rgba(255, 107, 107, 0.3);
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
            backdrop-filter: blur(20px);
        }
        
        .order-number-display {
            font-size: 2.5rem;
            font-weight: 800;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 0.5rem;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }
        
        .info-item {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            padding: 1.25rem;
            transition: all 0.3s ease;
        }
        
        .info-item:hover {
            background: rgba(255, 255, 255, 0.05);
            border-color: rgba(255, 107, 107, 0.3);
            transform: translateY(-2px);
        }
        
        .info-label {
            color: var(--text-secondary);
            font-size: 0.85rem;
            font-weight: 500;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .info-label i {
            color: var(--primary-gradient-start);
        }
        
        .info-value {
            color: var(--text-primary);
            font-size: 1.1rem;
            font-weight: 600;
        }
        
        .status-badge-large {
            padding: 0.5rem 1.25rem;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .timeline-section {
            margin-top: 2rem;
        }
        
        .timeline {
            position: relative;
            padding: 2rem 0;
        }
        
        .timeline::before {
            content: '';
            position: absolute;
            left: 30px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: var(--glass-border);
        }
        
        .timeline-item {
            position: relative;
            padding-left: 80px;
            margin-bottom: 2rem;
        }
        
        .timeline-marker {
            position: absolute;
            left: 0;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: var(--bg-card);
            border: 2px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1;
            transition: all 0.3s ease;
        }
        
        .timeline-item.active .timeline-marker {
            background: var(--gradient-primary);
            border-color: var(--primary-gradient-start);
            box-shadow: 0 0 20px rgba(255, 107, 107, 0.4);
        }
        
        .timeline-marker i {
            font-size: 1.25rem;
            color: var(--text-secondary);
        }
        
        .timeline-item.active .timeline-marker i {
            color: white;
        }
        
        .timeline-content {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            padding: 1.25rem;
        }
        
        .timeline-title {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }
        
        .timeline-date {
            color: var(--text-secondary);
            font-size: 0.85rem;
        }
        
        .items-table {
            background: rgba(255, 255, 255, 0.02);
            border-radius: 12px;
            overflow: hidden;
        }
        
        .items-table th {
            background: rgba(255, 107, 107, 0.15);
            color: var(--primary-gradient-start);
            font-weight: 600;
            padding: 1rem;
            border-bottom: 2px solid var(--glass-border);
        }
        
        .items-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--glass-border);
            vertical-align: middle;
        }
        
        .items-table tr:last-child td {
            border-bottom: none;
        }
        
        .items-table tr:hover {
            background: rgba(255, 255, 255, 0.03);
        }
        
        .customer-card, .manager-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1rem;
        }
        
        .customer-card h5, .manager-card h5 {
            color: var(--primary-gradient-start);
            font-weight: 600;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .contact-row {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.5rem 0;
            color: var(--text-secondary);
        }
        
        .contact-row i {
            color: var(--primary-gradient-start);
            width: 20px;
        }
        
        .action-buttons {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        
        .btn-export {
            background: rgba(48, 209, 88, 0.2);
            color: var(--success-color);
            border: 1px solid rgba(48, 209, 88, 0.3);
            padding: 0.6rem 1.25rem;
            border-radius: 12px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .btn-export:hover {
            background: rgba(48, 209, 88, 0.3);
            transform: translateY(-2px);
            color: white;
        }
        
        .overdue-warning {
            background: rgba(255, 69, 58, 0.15);
            border: 1px solid rgba(255, 69, 58, 0.4);
            border-radius: 12px;
            padding: 1rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .overdue-warning i {
            font-size: 1.5rem;
            color: var(--danger-color);
        }
        
        .progress-section {
            margin-top: 2rem;
        }
        
        .task-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            transition: all 0.3s ease;
        }
        
        .task-card:hover {
            background: rgba(255, 255, 255, 0.05);
            border-color: rgba(255, 107, 107, 0.3);
        }
        
        .task-info {
            flex: 1;
        }
        
        .task-name {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
        }
        
        .task-stage {
            color: var(--text-secondary);
            font-size: 0.85rem;
        }
        
        .task-status {
            padding: 0.35rem 0.75rem;
            border-radius: 8px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .total-amount-display {
            font-size: 2rem;
            font-weight: 800;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
    </style>
</head>
<body>
    <div class="particles-container"><div class="particle"></div><div class="particle"></div></div>
    <div class="glow-overlay"></div><div class="grid-overlay"></div>
    
    <nav class="navbar">
        <a href="<?= APP_URL ?>" class="nav-brand">
            <div class="brand-logo"><svg viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg></div>
            <span class="brand-name">PolesieMES</span>
        </a>
        <ul class="nav-menu">
            <li><a href="<?= APP_URL ?>/modules/dashboard/index.php" class="nav-link"><i class="fas fa-chart-line"></i> Главная</a></li>
            <li><a href="<?= APP_URL ?>/modules/orders/index.php" class="nav-link active"><i class="fas fa-shopping-cart"></i> Заказы</a></li>
            <?php if (hasRole(['admin', 'manager', 'technologist', 'operator'])): ?>
            <li><a href="<?= APP_URL ?>/modules/production/index.php" class="nav-link"><i class="fas fa-cogs"></i> Производство</a></li>
            <?php endif; ?>
            <?php if (hasRole(['admin', 'manager', 'warehouse_keeper'])): ?>
            <li><a href="<?= APP_URL ?>/modules/warehouse/warehouse_dashboard.php" class="nav-link"><i class="fas fa-warehouse"></i> Склад</a></li>
            <?php endif; ?>
            <?php if (hasRole(['admin', 'manager', 'logistician'])): ?>
            <li><a href="<?= APP_URL ?>/modules/shipment/index.php" class="nav-link"><i class="fas fa-truck"></i> Отгрузка</a></li>
            <?php endif; ?>
            <?php if (hasRole('admin')): ?>
            <li><a href="<?= APP_URL ?>/modules/employees/index.php" class="nav-link"><i class="fas fa-users"></i> Сотрудники</a></li>
            <?php endif; ?>
        </ul>
        <div class="user-menu">
            <span><?= e($_SESSION['full_name']) ?></span>
            <a href="<?= APP_URL ?>/modules/auth/logout.php" class="btn-logout">Выход</a>
        </div>
    </nav>

    <div class="main-content">
        <div class="page-header">
            <div>
                <a href="index.php" class="btn-secondary" style="margin-bottom: 1rem;">
                    <i class="fas fa-arrow-left"></i> Назад к списку
                </a>
                <h1 style="margin-bottom: 0.5rem;"><i class="fas fa-file-invoice"></i> Заказ <?= e($order['order_number']) ?></h1>
                <p style="color: var(--text-secondary);">Детальная информация о заказе</p>
            </div>
            <div class="action-buttons">
                <?php if (hasRole(['admin', 'manager'])): ?>
                <a href="edit.php?id=<?= $order['id'] ?>" class="btn-primary-custom">
                    <i class="fas fa-edit"></i> Редактировать
                </a>
                <?php endif; ?>
                <button onclick="exportOrder('pdf')" class="btn-export">
                    <i class="fas fa-file-pdf"></i> PDF
                </button>
                <button onclick="exportOrder('excel')" class="btn-export">
                    <i class="fas fa-file-excel"></i> Excel
                </button>
                <button onclick="exportOrder('csv')" class="btn-export">
                    <i class="fas fa-file-csv"></i> CSV
                </button>
            </div>
        </div>

        <?php if ($isOverdue): ?>
        <div class="overdue-warning">
            <i class="fas fa-exclamation-triangle"></i>
            <div>
                <strong style="color: var(--danger-color);">Просрочен на <?= $daysDiff ?> дн.</strong>
                <div style="color: var(--text-secondary); font-size: 0.9rem;">Срок поставки истёк <?= formatDate($order['delivery_date']) ?></div>
            </div>
        </div>
        <?php elseif ($daysDiff <= 3 && !in_array($order['status'], ['completed', 'cancelled'])): ?>
        <div class="alert-warning-custom">
            <i class="fas fa-clock"></i>
            <div>
                <strong>Внимание!</strong> До поставки осталось <?= $daysDiff ?> дн.
            </div>
        </div>
        <?php endif; ?>

        <!-- Основная карточка заказа -->
        <div class="order-header-card">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
                <div>
                    <div class="order-number-display"><?= e($order['order_number']) ?></div>
                    <div style="color: var(--text-secondary); font-size: 1.1rem;">
                        <i class="fas fa-calendar"></i> Создан: <?= formatDate($order['order_date']) ?>
                    </div>
                </div>
                <div style="text-align: right;">
                    <div class="total-amount-display"><?= formatNumber($order['total_amount'], 2) ?> BYN</div>
                    <div style="color: var(--text-secondary);">Общая сумма</div>
                </div>
            </div>
            
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label"><i class="fas fa-flag"></i> Статус</div>
                    <div class="info-value">
                        <span class="status-badge-large badge-status badge-<?= e($order['status']) ?>">
                            <i class="fas fa-circle-check"></i>
                            <?= getOrderStatusName($order['status']) ?>
                        </span>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-label"><i class="fas fa-bolt"></i> Приоритет</div>
                    <div class="info-value">
                        <span class="badge-priority badge-<?= e($order['priority']) ?>" style="padding: 0.5rem 1rem; font-size: 0.85rem;">
                            <?= getPriorityName($order['priority']) ?>
                        </span>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-label"><i class="fas fa-truck"></i> Срок поставки</div>
                    <div class="info-value"><?= formatDate($order['delivery_date']) ?></div>
                    <div style="color: var(--text-secondary); font-size: 0.85rem; margin-top: 0.25rem;">
                        <?php if ($daysDiff > 0): ?>
                            <i class="fas fa-hourglass-half"></i> Осталось дней: <?= $daysDiff ?>
                        <?php else: ?>
                            <i class="fas fa-calendar-check"></i> Поставка сегодня
                        <?php endif; ?>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-label"><i class="fas fa-user-tie"></i> Менеджер</div>
                    <div class="info-value"><?= e($order['manager_first_name'] . ' ' . $order['manager_last_name']) ?></div>
                </div>
            </div>
            
            <?php if ($order['notes']): ?>
            <div style="margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid var(--glass-border);">
                <div class="info-label"><i class="fas fa-sticky-note"></i> Примечание</div>
                <div style="color: var(--text-primary); line-height: 1.6;"><?= nl2br(e($order['notes'])) ?></div>
            </div>
            <?php endif; ?>
        </div>

        <div class="row">
            <div class="col-lg-8">
                <!-- Состав заказа -->
                <?php if (!empty($items)): ?>
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">
                            <i class="fas fa-boxes"></i> Состав заказа
                        </div>
                        <div style="color: var(--text-secondary);"><?= count($items) ?> поз.</div>
                    </div>
                    <div class="card-body">
                        <table class="table items-table">
                            <thead>
                                <tr>
                                    <th style="width: 40%;">Продукция</th>
                                    <th style="width: 15%; text-align: center;">Кол-во</th>
                                    <th style="width: 20%; text-align: right;">Цена</th>
                                    <th style="width: 25%; text-align: right;">Сумма</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                <tr>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 0.75rem;">
                                            <div style="width: 40px; height: 40px; background: rgba(255, 107, 107, 0.2); border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                                                <i class="fas fa-box" style="color: var(--primary-gradient-start);"></i>
                                            </div>
                                            <strong><?= e($item['name'] ?? 'Товар #' . $item['product_id']) ?></strong>
                                        </div>
                                    </td>
                                    <td style="text-align: center; font-weight: 600;"><?= $item['quantity'] ?></td>
                                    <td style="text-align: right;"><?= formatNumber($item['unit_price'], 2) ?> BYN</td>
                                    <td style="text-align: right; font-weight: 600; color: var(--primary-gradient-start);">
                                        <?= formatNumber($item['total_price'], 2) ?> BYN
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Производственные задания -->
                <?php if (!empty($tasks)): ?>
                <div class="card progress-section">
                    <div class="card-header">
                        <div class="card-title">
                            <i class="fas fa-tasks"></i> Производственные задания
                        </div>
                        <div style="color: var(--text-secondary);"><?= count($tasks) ?> этапов</div>
                    </div>
                    <div class="card-body">
                        <div class="timeline">
                            <?php foreach ($tasks as $idx => $task): ?>
                            <div class="timeline-item <?= $idx === 0 ? 'active' : '' ?>">
                                <div class="timeline-marker">
                                    <i class="fas fa-<?= $task['status'] === 'completed' ? 'check' : ($task['status'] === 'in_progress' ? 'cog' : 'clock') ?>"></i>
                                </div>
                                <div class="timeline-content">
                                    <div class="timeline-title"><?= e($task['stage_name']) ?></div>
                                    <div style="color: var(--text-secondary); margin-bottom: 0.5rem;"><?= e($task['product_name']) ?></div>
                                    <div style="display: flex; justify-content: space-between; align-items: center;">
                                        <span class="task-status badge-status badge-<?= e($task['status']) ?>"><?= e($task['status']) ?></span>
                                        <span class="timeline-date"><i class="fas fa-calendar"></i> План: <?= formatDate($task['planned_end'], 'd.m.Y') ?></span>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="col-lg-4">
                <!-- Клиент -->
                <div class="customer-card">
                    <h5><i class="fas fa-building"></i> Клиент</h5>
                    <div style="font-size: 1.25rem; font-weight: 700; color: var(--text-primary); margin-bottom: 1rem;">
                        <?= e($order['customer_name']) ?>
                    </div>
                    <?php if ($order['inn']): ?>
                    <div class="contact-row">
                        <i class="fas fa-fingerprint"></i>
                        <span>ИНН: <?= e($order['inn']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($order['customer_phone']): ?>
                    <div class="contact-row">
                        <i class="fas fa-phone"></i>
                        <a href="tel:<?= e($order['customer_phone']) ?>" style="color: var(--text-primary); text-decoration: none;"><?= e($order['customer_phone']) ?></a>
                    </div>
                    <?php endif; ?>
                    <?php if ($order['customer_email']): ?>
                    <div class="contact-row">
                        <i class="fas fa-envelope"></i>
                        <a href="mailto:<?= e($order['customer_email']) ?>" style="color: var(--text-primary); text-decoration: none;"><?= e($order['customer_email']) ?></a>
                    </div>
                    <?php endif; ?>
                    <?php if ($order['address']): ?>
                    <div class="contact-row" style="align-items: flex-start;">
                        <i class="fas fa-map-marker-alt" style="margin-top: 0.25rem;"></i>
                        <span><?= e($order['address']) ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Менеджер -->
                <div class="manager-card">
                    <h5><i class="fas fa-user-circle"></i> Ответственный менеджер</h5>
                    <div style="display: flex; align-items: center; gap: 1rem;">
                        <div style="width: 50px; height: 50px; background: var(--gradient-primary); border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                            <i class="fas fa-user" style="color: white; font-size: 1.5rem;"></i>
                        </div>
                        <div>
                            <div style="font-weight: 600; color: var(--text-primary);">
                                <?= e($order['manager_first_name'] . ' ' . $order['manager_last_name']) ?>
                            </div>
                            <div style="color: var(--text-secondary); font-size: 0.85rem;">Менеджер по продажам</div>
                        </div>
                    </div>
                </div>

                <!-- Дополнительная информация -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">
                            <i class="fas fa-info-circle"></i> Информация
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="contact-row">
                            <i class="fas fa-hashtag"></i>
                            <span>ID заказа: #<?= $order['id'] ?></span>
                        </div>
                        <div class="contact-row">
                            <i class="fas fa-clock"></i>
                            <span>Создан: <?= date('d.m.Y H:i', strtotime($order['created_at'])) ?></span>
                        </div>
                        <div class="contact-row">
                            <i class="fas fa-sync"></i>
                            <span>Обновлён: <?= date('d.m.Y H:i', strtotime($order['updated_at'])) ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= APP_URL ?>/assets/js/main.js"></script>
    <script>
        function exportOrder(format) {
            const orderId = <?= $orderId ?>;
            const orderNumber = '<?= e($order['order_number']) ?>';
            
            // Здесь будет логика экспорта
            alert('Экспорт заказа ' + orderNumber + ' в формате ' + format.toUpperCase() + '. Функция в разработке.');
            
            // Пример URL для экспорта:
            // window.location.href = APP_URL + '/modules/orders/export.php?id=' + orderId + '&format=' + format;
        }
    </script>
</body>
</html>
