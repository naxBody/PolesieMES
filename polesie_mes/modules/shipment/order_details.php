<?php
/**
 * Страница просмотра деталей заказа для отгрузки
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
$orderId = $_GET['order_id'] ?? null;

if (!$orderId) {
    redirectWithMessage(APP_URL . '/modules/shipment/index.php', 'Заказ не указан', 'error');
}

// Получение информации о заказе
$stmt = $db->prepare("
    SELECT o.*, c.name as customer_name, c.inn, c.address, c.phone as customer_phone, c.email as customer_email,
           s.first_name as manager_first_name, s.last_name as manager_last_name, s.phone as manager_phone, s.email as manager_email
    FROM orders o
    LEFT JOIN partners c ON o.customer_id = c.id
    LEFT JOIN staff s ON o.manager_id = s.id
    WHERE o.id = :id
");
$stmt->execute(['id' => $orderId]);
$order = $stmt->fetch();

if (!$order) {
    redirectWithMessage(APP_URL . '/modules/shipment/index.php', 'Заказ не найден', 'error');
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

// Получение истории изменений заказа
$stmt = $db->prepare("
    SELECT al.*, s.username
    FROM activity_log al
    LEFT JOIN staff s ON al.user_id = s.id
    WHERE al.module = 'orders' AND al.record_id = :order_id
    ORDER BY al.created_at DESC
    LIMIT 20
");
$stmt->execute(['order_id' => $orderId]);
$activityLog = $stmt->fetchAll();

$pageTitle = 'Детали заказа #' . e($order['order_number']) . ' | ' . APP_NAME;
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
        .info-card {
            background: var(--bg-card);
            backdrop-filter: var(--backdrop-blur);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .info-label {
            color: var(--text-secondary);
            font-size: 0.85rem;
            margin-bottom: 0.25rem;
        }
        
        .info-value {
            color: var(--text-primary);
            font-size: 1rem;
            font-weight: 500;
        }
        
        .section-title {
            color: var(--text-primary);
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
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
        
        .table-custom {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        
        .table-custom th {
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-secondary);
            font-weight: 600;
            font-size: 0.85rem;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--glass-border);
        }
        
        .table-custom td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            color: var(--text-primary);
        }
        
        .table-custom tr:last-child td {
            border-bottom: none;
        }
        
        .table-custom tr:hover td {
            background: rgba(255, 255, 255, 0.02);
        }
        
        .status-badge {
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .status-new { background: #ffd60a; color: black; }
        .status-in_production { background: #32ade6; color: white; }
        .status-ready { background: #30d158; color: white; }
        .status-shipped { background: #bf5af2; color: white; }
        .status-completed { background: #30d158; color: white; }
        .status-cancelled { background: #ff453a; color: white; }
        
        .timeline {
            position: relative;
            padding-left: 2rem;
        }
        
        .timeline-item {
            position: relative;
            padding-bottom: 1.5rem;
            padding-left: 1rem;
            border-left: 2px solid var(--glass-border);
        }
        
        .timeline-item:last-child {
            padding-bottom: 0;
            border-left: 2px solid transparent;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -0.5rem;
            top: 0.25rem;
            width: 1rem;
            height: 1rem;
            border-radius: 50%;
            background: var(--primary-gradient-start);
            border: 2px solid var(--bg-card);
        }
        
        .timeline-time {
            color: var(--text-secondary);
            font-size: 0.8rem;
            margin-bottom: 0.25rem;
        }
        
        .timeline-content {
            color: var(--text-primary);
            font-size: 0.9rem;
        }
        
        .contact-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
        }
        
        .contact-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.05);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-secondary);
        }
        
        .print-btn {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-gradient-start), var(--primary-gradient-end));
            border: none;
            color: white;
            font-size: 1.25rem;
            cursor: pointer;
            box-shadow: 0 4px 20px rgba(255, 107, 107, 0.4);
            transition: all 0.3s ease;
            z-index: 1000;
        }
        
        .print-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 30px rgba(255, 107, 107, 0.6);
        }
        
        @media print {
            .navbar, .print-btn, .mobile-menu-btn {
                display: none !important;
            }
            
            .main-content {
                padding: 0 !important;
            }
            
            .card, .info-card {
                break-inside: avoid;
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
    </div>
    <div class="glow-overlay"></div>
    <div class="grid-overlay"></div>
    
    <!-- Navbar -->
    <nav class="navbar">
        <a href="<?= APP_URL ?>" class="nav-brand">
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
            <?php if (hasRole(['admin', 'manager', 'logistician'])): ?>
            <li><a href="<?= APP_URL ?>/modules/shipment/index.php" class="nav-link active"><i class="fas fa-truck"></i> Отгрузка</a></li>
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
                <h1><i class="fas fa-file-invoice"></i> Заказ <?= e($order['order_number']) ?></h1>
                <p>Полная информация о заказе и отгрузке</p>
            </div>
            <div class="page-actions">
                <a href="index.php" class="btn-secondary-custom">
                    <i class="fas fa-arrow-left"></i> Назад к отгрузкам
                </a>
                <a href="../orders/view.php?id=<?= $order['id'] ?>" class="btn-primary-custom">
                    <i class="fas fa-external-link-alt"></i> Открыть в заказах
                </a>
            </div>
        </div>

        <!-- Основная информация -->
        <div class="row">
            <div class="col-lg-8">
                <!-- Информация о заказе -->
                <div class="info-card">
                    <div class="section-title">
                        <div class="section-icon"><i class="fas fa-shopping-cart"></i></div>
                        <span>Информация о заказе</span>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <div class="info-label">Статус</div>
                                <div class="info-value">
                                    <span class="status-badge status-<?= e($order['status']) ?>">
                                        <?= getOrderStatusName($order['status']) ?>
                                    </span>
                                </div>
                            </div>
                            <div class="mb-3">
                                <div class="info-label">Приоритет</div>
                                <div class="info-value">
                                    <span class="badge-priority badge-<?= e($order['priority']) ?>">
                                        <?= getPriorityName($order['priority']) ?>
                                    </span>
                                </div>
                            </div>
                            <div class="mb-3">
                                <div class="info-label">Дата заказа</div>
                                <div class="info-value"><?= formatDate($order['order_date']) ?></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <div class="info-label">Срок поставки</div>
                                <div class="info-value"><?= formatDate($order['delivery_date']) ?></div>
                            </div>
                            <div class="mb-3">
                                <div class="info-label">Сумма заказа</div>
                                <div class="info-value" style="font-size: 1.25rem; color: var(--success-color);">
                                    <strong><?= formatNumber($order['total_amount'] ?? 0, 2) ?> BYN</strong>
                                </div>
                            </div>
                            <?php if (!empty($order['tracking_number'])): ?>
                            <div class="mb-3">
                                <div class="info-label">Трекинг-номер</div>
                                <div class="info-value"><?= e($order['tracking_number']) ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($order['notes']): ?>
                    <hr style="border-color: var(--glass-border);">
                    <div class="mb-3">
                        <div class="info-label">Примечание к заказу</div>
                        <div class="info-value"><?= nl2br(e($order['notes'])) ?></div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Состав заказа -->
                <?php if (!empty($items)): ?>
                <div class="info-card">
                    <div class="section-title">
                        <div class="section-icon"><i class="fas fa-boxes"></i></div>
                        <span>Состав заказа</span>
                    </div>
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>№</th>
                                <th>Продукция</th>
                                <th style="text-align: center;">Кол-во</th>
                                <th style="text-align: right;">Цена</th>
                                <th style="text-align: right;">Сумма</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $itemNum = 1;
                            foreach ($items as $item): 
                                $quantity = $item['quantity'] ?? 0;
                                $unitPrice = $item['unit_price'] ?? 0;
                                $totalPrice = $item['total_price'] ?? ($quantity * $unitPrice);
                            ?>
                            <tr>
                                <td><?= $itemNum++ ?></td>
                                <td><?= e($item['name'] ?? 'Товар #' . ($item['product_id'] ?? $itemNum)) ?></td>
                                <td style="text-align: center;"><?= $quantity ?></td>
                                <td style="text-align: right;"><?= formatNumber($unitPrice, 2) ?> BYN</td>
                                <td style="text-align: right;"><strong><?= formatNumber($totalPrice, 2) ?> BYN</strong></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <!-- Производственные задания -->
                <?php if (!empty($tasks)): ?>
                <div class="info-card">
                    <div class="section-title">
                        <div class="section-icon"><i class="fas fa-cogs"></i></div>
                        <span>Производственные задания</span>
                    </div>
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>Этап</th>
                                <th>Продукция</th>
                                <th>Статус</th>
                                <th>План завершения</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tasks as $task): ?>
                            <tr>
                                <td><?= e($task['stage_name']) ?></td>
                                <td><?= e($task['product_name']) ?></td>
                                <td>
                                    <span class="status-badge status-<?= e($task['status']) ?>">
                                        <?= e($task['status']) ?>
                                    </span>
                                </td>
                                <td><?= formatDate($task['planned_end'], 'd.m.Y') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <!-- История изменений -->
                <?php if (!empty($activityLog)): ?>
                <div class="info-card">
                    <div class="section-title">
                        <div class="section-icon"><i class="fas fa-history"></i></div>
                        <span>История изменений</span>
                    </div>
                    <div class="timeline">
                        <?php foreach ($activityLog as $log): ?>
                        <div class="timeline-item">
                            <div class="timeline-time"><?= date('d.m.Y H:i', strtotime($log['created_at'])) ?></div>
                            <div class="timeline-content">
                                <?= e($log['description']) ?>
                                <?php if ($log['username']): ?>
                                <span style="color: var(--text-secondary); font-size: 0.85rem;">— <?= e($log['username']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="col-lg-4">
                <!-- Информация о клиенте -->
                <div class="info-card">
                    <div class="section-title">
                        <div class="section-icon"><i class="fas fa-building"></i></div>
                        <span>Клиент</span>
                    </div>
                    <div style="margin-bottom: 1.5rem;">
                        <div class="info-value" style="font-size: 1.1rem; margin-bottom: 0.5rem;">
                            <?= e($order['customer_name']) ?>
                        </div>
                        <?php if ($order['inn']): ?>
                        <div class="info-label">ИНН</div>
                        <div class="info-value"><?= e($order['inn']) ?></div>
                        <?php endif; ?>
                    </div>
                    
                    <hr style="border-color: var(--glass-border);">
                    
                    <div class="section-title" style="font-size: 0.95rem; margin-top: 1rem;">
                        <i class="fas fa-map-marker-alt" style="color: var(--text-secondary);"></i>
                        <span>Адрес доставки</span>
                    </div>
                    <div class="info-value" style="margin-bottom: 1rem;">
                        <?= e($order['address'] ?? 'Не указан') ?>
                    </div>
                    
                    <hr style="border-color: var(--glass-border);">
                    
                    <div class="section-title" style="font-size: 0.95rem; margin-top: 1rem;">
                        <i class="fas fa-address-book" style="color: var(--text-secondary);"></i>
                        <span>Контакты</span>
                    </div>
                    <?php if ($order['customer_phone']): ?>
                    <div class="contact-item">
                        <div class="contact-icon">
                            <i class="fas fa-phone"></i>
                        </div>
                        <div class="info-value"><?= e($order['customer_phone']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($order['customer_email']): ?>
                    <div class="contact-item">
                        <div class="contact-icon">
                            <i class="fas fa-envelope"></i>
                        </div>
                        <div class="info-value"><?= e($order['customer_email']) ?></div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Менеджер заказа -->
                <div class="info-card">
                    <div class="section-title">
                        <div class="section-icon"><i class="fas fa-user-tie"></i></div>
                        <span>Менеджер заказа</span>
                    </div>
                    <div class="info-value" style="font-size: 1.1rem; margin-bottom: 0.5rem;">
                        <?= e($order['manager_first_name'] . ' ' . $order['manager_last_name']) ?>
                    </div>
                    <?php if ($order['manager_phone']): ?>
                    <div class="contact-item">
                        <div class="contact-icon">
                            <i class="fas fa-phone"></i>
                        </div>
                        <div class="info-value"><?= e($order['manager_phone']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($order['manager_email']): ?>
                    <div class="contact-item">
                        <div class="contact-icon">
                            <i class="fas fa-envelope"></i>
                        </div>
                        <div class="info-value"><?= e($order['manager_email']) ?></div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Информация об отгрузке -->
                <?php if ($order['status'] == 'shipped' || $order['status'] == 'completed'): ?>
                <div class="info-card">
                    <div class="section-title">
                        <div class="section-icon"><i class="fas fa-truck-loading"></i></div>
                        <span>Информация об отгрузке</span>
                    </div>
                    <?php if (!empty($order['carrier'])): ?>
                    <div class="mb-3">
                        <div class="info-label">Перевозчик</div>
                        <div class="info-value"><?= e($order['carrier']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($order['driver_name'])): ?>
                    <div class="mb-3">
                        <div class="info-label">Водитель</div>
                        <div class="info-value"><?= e($order['driver_name']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($order['vehicle_number'])): ?>
                    <div class="mb-3">
                        <div class="info-label">Автомобиль</div>
                        <div class="info-value"><?= e($order['vehicle_number']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($order['shipped_at'])): ?>
                    <div class="mb-3">
                        <div class="info-label">Дата отгрузки</div>
                        <div class="info-value"><?= formatDate($order['shipped_at']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($order['completion_notes']) && $order['status'] == 'completed'): ?>
                    <hr style="border-color: var(--glass-border);">
                    <div class="mb-3">
                        <div class="info-label">Комментарий о получении</div>
                        <div class="info-value"><?= nl2br(e($order['completion_notes'])) ?></div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Кнопка печати -->
    <button class="print-btn" onclick="window.print()" title="Распечатать">
        <i class="fas fa-print"></i>
    </button>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= APP_URL ?>/assets/js/main.js"></script>
    <script>
        // Mobile menu toggle
        function toggleMobileMenu() {
            const menu = document.querySelector('.nav-menu');
            menu.classList.toggle('active');
        }
    </script>
</body>
</html>
