<?php
/**
 * Панель управления (Dashboard) системы PolesieMES
 * Современный дизайн 2026 - Glassmorphism с оранжево-коралловым градиентом
 * ОАО "Полесьеэлектромаш"
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

// Проверка авторизации
requireAuth();

// Перенаправление работников склада на специализированный дашборд
// Администраторы и Директора НЕ перенаправляются, они остаются на главном дашборде
if (isset($_SESSION['role']) && $_SESSION['role'] === 'warehouse_keeper' && $_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'director') {
    header('Location: ' . APP_URL . '/modules/warehouse/warehouse_dashboard.php');
    exit;
}

// Перенаправление директора на панель руководителя
if (isset($_SESSION['role']) && $_SESSION['role'] === 'director') {
    header('Location: ' . APP_URL . '/modules/director/dashboard.php');
    exit;
}

// Директор получает доступ ко всей информации о предприятии
// Администраторы и менеджеры получают доступ ко всем страницам через главный дашборд
// Операторы также могут видеть все страницы, но с акцентом на производство

$db = getDB();
$user = getCurrentUser();

// Получение имени пользователя для приветствия
$userFirstName = !empty($user['full_name']) ? explode(' ', $user['full_name'])[0] : 'Пользователь';
$userRole = $user['role'] ?? 'user';

// Получение расширенной статистики
$stats = [];

// Количество заказов по статусам
$stmt = $db->query("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) as new_orders,
        SUM(CASE WHEN status = 'in_production' THEN 1 ELSE 0 END) as production_orders,
        SUM(CASE WHEN status = 'quality_check' THEN 1 ELSE 0 END) as qc_orders,
        SUM(CASE WHEN status = 'ready' THEN 1 ELSE 0 END) as ready_orders,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_orders
    FROM orders
");
$stats['orders'] = $stmt->fetch();

// Общая сумма завершенных заказов за месяц
$stmt = $db->query("
    SELECT COALESCE(SUM(total_amount), 0) as monthly_revenue
    FROM orders
    WHERE status = 'completed'
    AND MONTH(created_at) = MONTH(CURRENT_DATE())
    AND YEAR(created_at) = YEAR(CURRENT_DATE())
");
$stats['revenue'] = $stmt->fetch()['monthly_revenue'];

// Процент выполнения плана
$stmt = $db->query("
    SELECT
        COUNT(*) as total_completed,
        AVG(DATEDIFF(updated_at, created_at)) as avg_completion_days
    FROM orders
    WHERE status = 'completed'
    AND MONTH(created_at) = MONTH(CURRENT_DATE())
    AND YEAR(created_at) = YEAR(CURRENT_DATE())
");
$efficiency = $stmt->fetch();
$planPercent = $efficiency['total_completed'] > 0 ? min(100, round(($efficiency['total_completed'] / 20) * 100)) : 0;

// Количество производственных заданий
$stmt = $db->query("
    SELECT
        COUNT(*) as total_tasks,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'planned' THEN 1 ELSE 0 END) as planned,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
    FROM production_tasks
");
$stats['tasks'] = $stmt->fetch();

// Оборудование в работе/неисправное
$stmt = $db->query("
    SELECT
        COUNT(*) as total_equipment,
        SUM(CASE WHEN status = 'operational' THEN 1 ELSE 0 END) as operational,
        SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) as maintenance,
        SUM(CASE WHEN status = 'broken' THEN 1 ELSE 0 END) as broken
    FROM items
    WHERE item_type = 'equipment'
");
$stats['equipment'] = $stmt->fetch();

// Последние заказы
$stmt = $db->query("
    SELECT o.*, p.name as customer_name
    FROM orders o
    LEFT JOIN partners p ON o.customer_id = p.id
    ORDER BY o.created_at DESC
    LIMIT 5
");
$recentOrders = $stmt->fetchAll();

// Производственные задания требующие внимания
$stmt = $db->query("
    SELECT pt.*, p.name as product_name, pt.stage_name, s.first_name, s.last_name
    FROM production_tasks pt
    LEFT JOIN items p ON pt.product_id = p.id
    LEFT JOIN staff s ON pt.assigned_to = s.id
    WHERE pt.status IN ('in_progress', 'paused')
    ORDER BY pt.planned_end ASC
    LIMIT 5
");
$activeTasks = $stmt->fetchAll();

// Проблемы с материалами (ниже минимального запаса)
$stmt = $db->query("
    SELECT * FROM items
    WHERE item_type = 'material' AND current_stock < min_stock
    ORDER BY (min_stock - current_stock) DESC
    LIMIT 5
");
$lowStockMaterials = $stmt->fetchAll();

// Активные пользователи онлайн (по активности в журнале событий)
$stmt = $db->query("
    SELECT COUNT(DISTINCT user_id) as online_users
    FROM journal
    WHERE journal_type = 'activity' AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
");
$onlineUsers = $stmt->fetch()['online_users'] ?? 0;

// ==========================================
// АНАЛИТИКА ДЛЯ ГРАФИКОВ ИЗ БД
// ==========================================

// Данные для графика эффективности по дням (7 дней) - с заполнением всех дней недели
// Используем actual_end для выполненных заданий, чтобы показать реальную эффективность
$stmt = $db->query("
    SELECT 
        DATE(COALESCE(actual_end, created_at)) as date,
        DAYNAME(COALESCE(actual_end, created_at)) as day_name,
        COUNT(*) as total_tasks,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_tasks
    FROM production_tasks
    WHERE COALESCE(actual_end, created_at) >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY DATE(COALESCE(actual_end, created_at)), DAYNAME(COALESCE(actual_end, created_at))
    ORDER BY date ASC
");
$efficiencyDataRaw = $stmt->fetchAll();

// Создаем полный массив данных за последние 7 дней с нулями для дней без данных
$efficiencyData = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $dayName = date('l', strtotime("-$i days"));
    $found = false;
    foreach ($efficiencyDataRaw as $row) {
        if ($row['date'] === $date) {
            $efficiencyData[] = [
                'date' => $row['date'],
                'day_name' => $row['day_name'],
                'total_tasks' => $row['total_tasks'],
                'completed_tasks' => $row['completed_tasks']
            ];
            $found = true;
            break;
        }
    }
    if (!$found) {
        $efficiencyData[] = [
            'date' => $date,
            'day_name' => $dayName,
            'total_tasks' => 0,
            'completed_tasks' => 0
        ];
    }
}

// Данные для графика заказов по статусам (все заказы)
$stmt = $db->query("
    SELECT 
        status,
        COUNT(*) as count,
        COALESCE(SUM(total_amount), 0) as total_value
    FROM orders
    GROUP BY status
");
$orderStatusData = $stmt->fetchAll();

// Данные для графика выполнения заказов по неделям месяца
$stmt = $db->query("
    SELECT 
        WEEK(created_at) - WEEK(DATE_FORMAT(CURRENT_DATE(), '%Y-%m-01')) + 1 as week_num,
        COUNT(*) as completed_count,
        COALESCE(SUM(total_amount), 0) as completed_value
    FROM orders
    WHERE status = 'completed'
    AND MONTH(created_at) = MONTH(CURRENT_DATE())
    AND YEAR(created_at) = YEAR(CURRENT_DATE())
    GROUP BY WEEK(created_at)
    ORDER BY week_num ASC
");
$monthlyOrdersData = $stmt->fetchAll();

// Данные для графика выручки по месяцам (6 месяцев)
$stmt = $db->query("
    SELECT 
        DATE_FORMAT(created_at, '%M %Y') as month_name,
        DATE_FORMAT(created_at, '%Y-%m') as month_key,
        COALESCE(SUM(CASE WHEN status = 'completed' THEN total_amount ELSE 0 END), 0) as revenue
    FROM orders
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m'), DATE_FORMAT(created_at, '%M %Y')
    ORDER BY created_at ASC
");
$revenueData = $stmt->fetchAll();

// Данные для графика производства по цехам/участкам
$stmt = $db->query("
    SELECT 
        pt.stage_name as stage,
        COUNT(*) as task_count,
        SUM(CASE WHEN pt.status = 'completed' THEN 1 ELSE 0 END) as completed_count
    FROM production_tasks pt
    WHERE pt.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY pt.stage_name
    ORDER BY task_count DESC
    LIMIT 5
");
$productionByStage = $stmt->fetchAll();

// Данные для комбинированного графика: заказы vs производство
$stmt = $db->query("
    SELECT 
        DATE_FORMAT(o.created_at, '%Y-%m-%d') as date,
        DATE_FORMAT(o.created_at, '%d.%m') as short_date,
        COUNT(DISTINCT o.id) as orders_count,
        COALESCE(SUM(o.total_amount), 0) as orders_value,
        COUNT(DISTINCT pt.id) as tasks_count,
        SUM(CASE WHEN pt.status = 'completed' THEN 1 ELSE 0 END) as completed_tasks
    FROM orders o
    LEFT JOIN production_tasks pt ON o.id = pt.order_id
    WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
    GROUP BY DATE_FORMAT(o.created_at, '%Y-%m-%d'), DATE_FORMAT(o.created_at, '%d.%m')
    ORDER BY o.created_at ASC
");
$ordersVsProductionData = $stmt->fetchAll();

// Заполняем пропущенные дни нулями для графика заказы vs производство
$fullOrdersVsProduction = [];
for ($i = 13; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $shortDate = date('d.m', strtotime("-$i days"));
    $found = false;
    foreach ($ordersVsProductionData as $row) {
        if ($row['date'] === $date) {
            $fullOrdersVsProduction[] = [
                'date' => $row['date'],
                'short_date' => $row['short_date'],
                'orders_count' => $row['orders_count'],
                'orders_value' => $row['orders_value'],
                'tasks_count' => $row['tasks_count'],
                'completed_tasks' => $row['completed_tasks']
            ];
            $found = true;
            break;
        }
    }
    if (!$found) {
        $fullOrdersVsProduction[] = [
            'date' => $date,
            'short_date' => $shortDate,
            'orders_count' => 0,
            'orders_value' => 0,
            'tasks_count' => 0,
            'completed_tasks' => 0
        ];
    }
}

// Просроченные заказы
$stmt = $db->query("
    SELECT o.*, c.name as customer_name, DATEDIFF(NOW(), o.delivery_date) as days_overdue
    FROM orders o
    LEFT JOIN partners c ON o.customer_id = c.id
    WHERE o.status NOT IN ('completed', 'cancelled')
    AND o.delivery_date < NOW()
    ORDER BY o.delivery_date ASC
    LIMIT 5
");
$overdueOrders = $stmt->fetchAll();

// Эффективность производства за неделю
$stmt = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        ROUND(SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 1) as efficiency_percent
    FROM production_tasks
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
");
$weeklyEfficiency = $stmt->fetch();

// Статистика по производству (для модулей)
$stats['production'] = [
    'in_progress' => $stats['tasks']['in_progress'] ?? 0,
    'planned' => $stats['tasks']['planned'] ?? 0
];

// Статистика по складу
$materialStatsStmt = $db->query("SELECT COUNT(*) as total FROM items WHERE item_type = 'material'");
$materialStats = ['total' => $materialStatsStmt->fetch()['total'] ?? 0];

// Статистика по отгрузкам
$shipmentStats = [
    'ready' => ['count' => $stats['orders']['ready_orders'] ?? 0],
    'shipped' => ['count' => 0] // Можно расширить при наличии статуса shipped
];

// ==========================================
// ДОПОЛНИТЕЛЬНАЯ СТАТИСТИКА ДЛЯ ДИРЕКТОРА
// ==========================================
$isDirector = hasRole(['director', 'admin']);

if ($isDirector) {
    // Общая стоимость всех активных заказов
    $stmt = $db->query("SELECT COALESCE(SUM(total_amount), 0) as active_orders_value FROM orders WHERE status NOT IN ('completed', 'cancelled')");
    $directorStats['activeOrdersValue'] = $stmt->fetch()['active_orders_value'];
    
    // Количество сотрудников по ролям
    $stmt = $db->query("SELECT role, COUNT(*) as count FROM staff GROUP BY role");
    $directorStats['employeesByRole'] = $stmt->fetchAll();
    
    // Всего сотрудников
    $stmt = $db->query("SELECT COUNT(*) as total FROM staff");
    $directorStats['totalEmployees'] = $stmt->fetch()['total'];
    
    // Заказы в работе с суммой
    $stmt = $db->query("SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as value FROM orders WHERE status = 'in_production'");
    $directorStats['inProduction'] = $stmt->fetch();
    
    // Просроченные заказы (полное количество)
    $stmt = $db->query("SELECT COUNT(*) as count FROM orders WHERE status NOT IN ('completed', 'cancelled') AND delivery_date < NOW()");
    $directorStats['overdueOrdersCount'] = $stmt->fetch()['count'];
    
    // Материалы с низким запасом (полное количество)
    $stmt = $db->query("SELECT COUNT(*) as count FROM items WHERE item_type = 'material' AND current_stock < min_stock");
    $directorStats['lowStockCount'] = $stmt->fetch()['count'];
    
    // Выполнено заказов за сегодня
    $stmt = $db->query("SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as value FROM orders WHERE status = 'completed' AND DATE(updated_at) = CURDATE()");
    $directorStats['completedToday'] = $stmt->fetch();
    
    // Средняя эффективность за месяц
    $stmt = $db->query("SELECT ROUND(AVG(efficiency_percent), 1) as avg_efficiency FROM (
        SELECT 
            ROUND(SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 1) as efficiency_percent
        FROM production_tasks
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY DATE(created_at)
    ) as daily_efficiency");
    $directorStats['monthlyEfficiency'] = $stmt->fetch()['avg_efficiency'] ?? 0;
}

// ==========================================
// СВОДКА ПО МОДУЛЯМ С ПРОБЛЕМАМИ И РЕКОМЕНДАЦИЯМИ
// ==========================================

// Модуль Заказы - проблемы
$ordersIssues = [];
if (!empty($overdueOrders)) {
    $ordersIssues[] = [
        'type' => 'critical',
        'title' => 'Просроченные заказы',
        'count' => count($overdueOrders),
        'description' => 'Заказы с нарушенными сроками поставки',
        'recommendation' => 'Приоритезировать производство и связаться с клиентами',
        'link' => APP_URL . '/modules/orders/index.php'
    ];
}
$newOrdersCount = $stats['orders']['new_orders'] ?? 0;
if ($newOrdersCount > 5) {
    $ordersIssues[] = [
        'type' => 'warning',
        'title' => 'Много новых заказов',
        'count' => $newOrdersCount,
        'description' => 'Требуют обработки и подтверждения',
        'recommendation' => 'Распределить заказы между менеджерами',
        'link' => APP_URL . '/modules/orders/index.php'
    ];
}

// Модуль Производство - проблемы
$productionIssues = [];
$productionStmt = $db->query("SELECT pt.*, p.name as product_name, DATEDIFF(NOW(), pt.planned_end) as days_overdue
    FROM production_tasks pt LEFT JOIN items p ON pt.product_id = p.id
    WHERE pt.status NOT IN ('completed', 'rejected') AND pt.planned_end < NOW()");
$overdueTasks = $productionStmt->fetchAll();
if (!empty($overdueTasks)) {
    $productionIssues[] = [
        'type' => 'critical',
        'title' => 'Просроченные задания',
        'count' => count($overdueTasks),
        'description' => 'Производственные задания с нарушенными сроками',
        'recommendation' => 'Перераспределить ресурсы и ускорить выполнение',
        'link' => APP_URL . '/modules/production/index.php'
    ];
}
$pausedTasksStmt = $db->query("SELECT COUNT(*) as count FROM production_tasks WHERE status = 'paused'");
$pausedTasksCount = $pausedTasksStmt->fetch()['count'] ?? 0;
if ($pausedTasksCount > 0) {
    $productionIssues[] = [
        'type' => 'warning',
        'title' => 'Приостановленные задания',
        'count' => $pausedTasksCount,
        'description' => 'Задания ожидающие возобновления',
        'recommendation' => 'Выяснить причины простоя и устранить их',
        'link' => APP_URL . '/modules/production/index.php'
    ];
}

// Модуль Склад - проблемы
$warehouseIssues = [];
$lowStockStmt = $db->query("SELECT COUNT(*) as count FROM items WHERE item_type = 'material' AND current_stock < min_stock");
$lowStockCount = $lowStockStmt->fetch()['count'] ?? 0;
$outOfStockStmt = $db->query("SELECT COUNT(*) as count FROM items WHERE item_type = 'material' AND current_stock <= 0");
$outOfStockCount = $outOfStockStmt->fetch()['count'] ?? 0;

if ($outOfStockCount > 0) {
    $warehouseIssues[] = [
        'type' => 'critical',
        'title' => 'Отсутствуют материалы',
        'count' => $outOfStockCount,
        'description' => 'Материалы полностью закончились',
        'recommendation' => 'Срочно оформить заказ поставщикам',
        'link' => APP_URL . '/modules/warehouse/warehouse_dashboard.php'
    ];
}
if ($lowStockCount > 0) {
    $warehouseIssues[] = [
        'type' => 'warning',
        'title' => 'Низкий запас материалов',
        'count' => $lowStockCount,
        'description' => 'Материалы ниже минимального уровня',
        'recommendation' => 'Запланировать пополнение запасов',
        'link' => APP_URL . '/modules/warehouse/warehouse_dashboard.php'
    ];
}

// Модуль Оборудование - проблемы
$equipmentIssues = [];
if (($stats['equipment']['broken'] ?? 0) > 0) {
    $equipmentIssues[] = [
        'type' => 'critical',
        'title' => 'Неисправное оборудование',
        'count' => $stats['equipment']['broken'],
        'description' => 'Оборудование требует ремонта',
        'recommendation' => 'Вызвать ремонтную службу или создать заявку на ремонт',
        'link' => APP_URL . '/modules/equipment/index.php'
    ];
}
$maintenanceCount = $stats['equipment']['maintenance'] ?? 0;
if ($maintenanceCount > 0) {
    $equipmentIssues[] = [
        'type' => 'warning',
        'title' => 'На обслуживании',
        'count' => $maintenanceCount,
        'description' => 'Оборудование проходит плановое ТО',
        'recommendation' => 'Контролировать сроки завершения обслуживания',
        'link' => APP_URL . '/modules/equipment/index.php'
    ];
}

// Модуль Отгрузка - проблемы
$shipmentIssues = [];
$readyToShipStmt = $db->query("SELECT COUNT(*) as count FROM orders WHERE status = 'ready' AND delivery_date < DATE_ADD(NOW(), INTERVAL 3 DAY)");
$urgentShipmentCount = $readyToShipStmt->fetch()['count'] ?? 0;
if ($urgentShipmentCount > 0) {
    $shipmentIssues[] = [
        'type' => 'warning',
        'title' => 'Срочная отгрузка',
        'count' => $urgentShipmentCount,
        'description' => 'Заказы готовые к отгрузке в ближайшие 3 дня',
        'recommendation' => 'Организовать транспортировку и подготовить документы',
        'link' => APP_URL . '/modules/shipment/index.php'
    ];
}

// Модуль Документы - проблемы
$docsIssues = [];
// Проверяем заказы в статусах готовности, которые могут требовать документы
$readyOrdersStmt = $db->query("SELECT COUNT(*) as count FROM orders WHERE status IN ('ready', 'shipped')");
$readyOrdersCount = $readyOrdersStmt->fetch()['count'] ?? 0;
if ($readyOrdersCount > 0) {
    $docsIssues[] = [
        'type' => 'info',
        'title' => 'Требуются документы',
        'count' => $readyOrdersCount,
        'description' => 'Заказы готовые к отгрузке или в пути',
        'recommendation' => 'Проверить наличие сопроводительной документации (паспорта, сертификаты, накладные)',
        'link' => APP_URL . '/modules/documents/index.php'
    ];
}

// Доступные заказы для документов (все активные заказы)
$availableOrdersStmt = $db->query("SELECT COUNT(*) as count FROM orders WHERE status NOT IN ('completed', 'cancelled')");
$availableOrders = ['count' => $availableOrdersStmt->fetch()['count'] ?? 0];
$pendingDocsCount = $readyOrdersCount; // Заказы готовые к отгрузке требуют документы

$pageTitle = 'Панель управления | ' . APP_NAME;
$currentPage = 'dashboard';
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title><?= e($pageTitle) ?></title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    <style>
        :root {
            /* Оранжево-коралловая палитра */
            --primary-gradient-start: #FF6B6B;
            --primary-gradient-end: #FF8E53;
            --primary-glow: rgba(255, 107, 107, 0.4);
            --secondary-glow: rgba(255, 142, 83, 0.3);
            
            /* Темный фон */
            --bg-dark: #0a0a0f;
            --bg-card: rgba(20, 20, 30, 0.6);
            --bg-input: rgba(30, 30, 45, 0.5);
            --border: rgba(255, 255, 255, 0.1);
            --border-glow: rgba(255, 107, 107, 0.3);
            
            /* Текст */
            --text-primary: #ffffff;
            --text-secondary: rgba(255, 255, 255, 0.7);
            --text-muted: rgba(255, 255, 255, 0.4);
            
            /* Градиенты */
            --gradient-primary: linear-gradient(135deg, #FF6B6B 0%, #FF8E53 100%);
            --gradient-bg: linear-gradient(180deg, #0a0a0f 0%, #12121a 100%);
            --gradient-glow: radial-gradient(ellipse 600px 400px at 20% 20%, rgba(255, 107, 107, 0.15) 0%, transparent 50%),
                             radial-gradient(ellipse 500px 350px at 80% 80%, rgba(255, 142, 83, 0.12) 0%, transparent 50%);
            
            /* Glassmorphism */
            --glass-bg: rgba(255, 255, 255, 0.03);
            --glass-border: rgba(255, 255, 255, 0.08);
            --glass-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
            --backdrop-blur: blur(20px);
            
            /* Тени и свечение */
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.3);
            --shadow-md: 0 8px 24px rgba(0, 0, 0, 0.4);
            --shadow-lg: 0 16px 48px rgba(0, 0, 0, 0.5);
            --glow-primary: 0 0 30px var(--primary-glow);
            --glow-secondary: 0 0 20px var(--secondary-glow);
            
            /* Статусы */
            --success-color: #30d158;
            --warning-color: #ffd60a;
            --danger-color: #ff453a;
            --info-color: #5ac8fa;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Outfit', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--gradient-bg);
            min-height: 100vh;
            color: var(--text-primary);
            overflow-x: hidden;
            position: relative;
        }
        
        /* Анимированный фон с частицами */
        .particles-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            overflow: hidden;
            pointer-events: none;
        }
        
        .particle {
            position: absolute;
            border-radius: 50%;
            background: var(--gradient-primary);
            opacity: 0.3;
            animation: float 15s infinite ease-in-out;
        }
        
        .particle:nth-child(1) { width: 80px; height: 80px; top: 10%; left: 15%; animation-delay: 0s; }
        .particle:nth-child(2) { width: 60px; height: 60px; top: 70%; left: 75%; animation-delay: 2s; }
        .particle:nth-child(3) { width: 100px; height: 100px; top: 40%; left: 85%; animation-delay: 4s; }
        .particle:nth-child(4) { width: 40px; height: 40px; top: 80%; left: 25%; animation-delay: 6s; }
        .particle:nth-child(5) { width: 70px; height: 70px; top: 20%; left: 65%; animation-delay: 8s; }
        
        @keyframes float {
            0%, 100% { transform: translate(0, 0) scale(1); opacity: 0.3; }
            25% { transform: translate(20px, -30px) scale(1.1); opacity: 0.5; }
            50% { transform: translate(-15px, 20px) scale(0.9); opacity: 0.4; }
            75% { transform: translate(25px, 15px) scale(1.05); opacity: 0.35; }
        }
        
        /* Glow effect на фон */
        .glow-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: var(--gradient-glow);
            z-index: 1;
            pointer-events: none;
        }
        
        /* Сетка на фоне */
        .grid-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: 
                linear-gradient(rgba(255, 255, 255, 0.02) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, 0.02) 1px, transparent 1px);
            background-size: 80px 80px;
            z-index: 2;
            pointer-events: none;
        }
        
        /* Navbar */
        .navbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            padding: 0.75rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(10, 10, 15, 0.8);
            backdrop-filter: var(--backdrop-blur);
            -webkit-backdrop-filter: var(--backdrop-blur);
            border-bottom: 1px solid var(--border);
            transition: all 0.3s ease;
        }
        
        .navbar.scrolled {
            padding: 0.6rem 2rem;
            background: rgba(10, 10, 15, 0.95);
            box-shadow: var(--shadow-md);
        }
        
        .nav-brand {
            display: flex;
            align-items: center;
            gap: 1rem;
            text-decoration: none;
            color: var(--text-primary);
        }
        
        .brand-logo {
            width: 40px;
            height: 40px;
            background: var(--gradient-primary);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--glow-primary);
            transition: all 0.3s ease;
        }
        
        .brand-logo:hover {
            transform: scale(1.05);
            box-shadow: var(--glow-primary), var(--glow-secondary);
        }
        
        .brand-logo svg {
            width: 24px;
            height: 24px;
            fill: white;
        }
        
        .brand-name {
            font-size: 1.25rem;
            font-weight: 700;
            letter-spacing: -0.5px;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .nav-menu {
            display: flex;
            align-items: center;
            gap: 1.25rem;
            list-style: none;
            flex-wrap: nowrap;
        }
        
        .nav-link {
            color: var(--text-secondary);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.875rem;
            transition: all 0.3s ease;
            position: relative;
            padding: 0.5rem 0;
            display: flex;
            align-items: center;
            gap: 0.4rem;
            white-space: nowrap;
        }
        
        .nav-link::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 0;
            height: 2px;
            background: var(--gradient-primary);
            transition: width 0.3s ease;
        }
        
        .nav-link:hover {
            color: var(--text-primary);
        }
        
        .nav-link:hover::after {
            width: 100%;
        }
        
        .nav-link.active {
            color: var(--text-primary);
        }
        
        .nav-link.active::after {
            width: 100%;
        }
        
        .nav-link i {
            font-size: 1rem;
        }
        
        .user-menu {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .user-avatar {
            width: 36px;
            height: 36px;
            background: var(--gradient-primary);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--glow-primary);
        }
        
        .user-avatar svg {
            width: 20px;
            height: 20px;
            fill: white;
        }
        
        .user-info {
            display: flex;
            flex-direction: column;
        }
        
        .user-name {
            font-weight: 600;
            font-size: 0.85rem;
            color: var(--text-primary);
        }
        
        .user-role {
            font-size: 0.7rem;
            color: var(--text-secondary);
            background: var(--glass-bg);
            padding: 0.15rem 0.5rem;
            border-radius: 6px;
            border: 1px solid var(--border);
        }
        
        .btn-logout {
            padding: 0.5rem 1rem;
            background: var(--glass-bg);
            border: 1px solid var(--border);
            border-radius: 10px;
            font-size: 0.8rem;
            font-weight: 600;
            font-family: inherit;
            color: var(--text-primary);
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        
        .btn-logout:hover {
            background: rgba(255, 107, 107, 0.2);
            border-color: var(--border-glow);
            transform: translateY(-2px);
        }
        
        .mobile-menu-btn {
            display: none;
            background: none;
            border: none;
            cursor: pointer;
            padding: 0.5rem;
        }
        
        .mobile-menu-btn span {
            display: block;
            width: 24px;
            height: 2px;
            background: var(--text-primary);
            margin: 5px 0;
            transition: all 0.3s ease;
            border-radius: 2px;
        }
        
        /* Main Content */
        .main-content {
            padding: 5rem 2rem 2rem;
            max-width: 1400px;
            margin: 0 auto;
            position: relative;
            z-index: 10;
        }
        
        .page-header {
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .welcome-section h1 {
            font-size: clamp(1.5rem, 3vw, 2rem);
            font-weight: 700;
            background: linear-gradient(135deg, #ffffff 0%, rgba(255, 255, 255, 0.7) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 0.25rem;
        }
        
        .welcome-section p {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }
        
        .quick-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        .btn-quick {
            padding: 0.6rem 1rem;
            background: var(--glass-bg);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: #000000 !important;
            font-weight: 600;
            font-size: 0.8rem;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.4rem;
            text-decoration: none;
            backdrop-filter: blur(10px);
        }
        
        .btn-quick:hover {
            background: rgba(255, 107, 107, 0.15);
            border-color: var(--border-glow);
            transform: translateY(-2px);
            color: #ffffff !important;
        }
        
        .btn-quick.primary {
            background: var(--gradient-primary);
            border: none;
            box-shadow: 0 4px 20px rgba(255, 107, 107, 0.3);
        }
        
        .btn-quick.primary:hover {
            box-shadow: 0 8px 30px rgba(255, 107, 107, 0.5), 0 0 40px rgba(255, 142, 83, 0.3);
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2rem;
        }
        
        /* KPI Grid - основные показатели на всю ширину */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2rem;
        }
        
        @media (min-width: 1400px) {
            .kpi-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }
        
        @media (min-width: 1800px) {
            .kpi-grid {
                gap: 1.5rem;
            }
        }
        
        .stat-card {
            background: var(--bg-card);
            backdrop-filter: var(--backdrop-blur);
            -webkit-backdrop-filter: var(--backdrop-blur);
            border-radius: 16px;
            border: 1px solid var(--border);
            padding: 1.25rem;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
            min-height: 160px;
            display: flex;
            flex-direction: column;
        }
        
        .kpi-grid .stat-card {
            min-height: 175px;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            border-color: var(--border-glow);
            box-shadow: var(--shadow-lg), 0 0 40px rgba(255, 107, 107, 0.15);
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100px;
            height: 100px;
            background: radial-gradient(circle, rgba(255, 107, 107, 0.1) 0%, transparent 60%);
            border-radius: 0 16px 0 100%;
            z-index: 0;
        }
        
        .kpi-grid .stat-card::before {
            width: 120px;
            height: 120px;
            background: radial-gradient(circle, rgba(255, 107, 107, 0.12) 0%, transparent 60%);
        }
        
        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.875rem;
            position: relative;
            z-index: 1;
        }
        
        .stat-icon {
            width: 44px;
            height: 44px;
            background: var(--gradient-primary);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--glow-primary);
            flex-shrink: 0;
        }
        
        .kpi-grid .stat-icon {
            width: 48px;
            height: 48px;
        }
        
        .stat-icon svg, .stat-icon i {
            width: 22px;
            height: 22px;
            fill: white;
        }
        
        .kpi-grid .stat-icon svg, 
        .kpi-grid .stat-icon i {
            width: 26px;
            height: 26px;
        }
        
        .stat-icon i {
            font-size: 1.2rem;
        }
        
        .kpi-grid .stat-icon i {
            font-size: 1.4rem;
        }
        
        .stat-trend {
            display: flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.25rem 0.5rem;
            border-radius: 8px;
            font-size: 0.7rem;
            font-weight: 700;
        }
        
        .kpi-grid .stat-trend {
            font-size: 0.75rem;
            padding: 0.3rem 0.6rem;
        }
        
        .stat-trend.up {
            background: rgba(48, 209, 88, 0.2);
            color: #30d158;
        }
        
        .stat-trend.down {
            background: rgba(255, 69, 58, 0.2);
            color: #ff453a;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 800;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            line-height: 1.2;
            margin: 0.5rem 0;
        }
        
        .kpi-grid .stat-value {
            font-size: 2.2rem;
        }
        
        .stat-label {
            color: var(--text-secondary);
            font-size: 0.75rem;
            font-weight: 600;
            margin-top: 0.25rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .stat-details {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-top: auto;
            padding-top: 0.75rem;
            border-top: 1px solid var(--border);
        }
        
        .stat-detail-item {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            font-size: 0.75rem;
            color: var(--text-muted);
            font-weight: 500;
            padding: 0.2rem 0.4rem;
            background: rgba(255, 255, 255, 0.03);
            border-radius: 6px;
        }
        
        .stat-detail-item i {
            font-size: 0.55rem;
        }
        
        .dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            box-shadow: 0 0 6px currentColor;
        }
        
        .kpi-grid .dot {
            width: 9px;
            height: 9px;
        }
        
        .dot.new { 
            background: #5ac8fa; 
            color: #5ac8fa;
        }
        .dot.production { 
            background: #ffd60a; 
            color: #ffd60a;
        }
        .dot.completed { 
            background: #30d158; 
            color: #30d158;
        }
        .dot.danger { 
            background: #ff453a; 
            color: #ff453a;
        }
        .dot.warning {
            background: #ff9f0a;
            color: #ff9f0a;
        }
        
        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.25rem;
        }
        
        .card {
            background: var(--bg-card);
            backdrop-filter: var(--backdrop-blur);
            -webkit-backdrop-filter: var(--backdrop-blur);
            border-radius: 20px;
            border: 1px solid var(--border);
            overflow: hidden;
            transition: all 0.3s ease;
            box-shadow: var(--shadow-md);
        }
        
        .card:hover {
            border-color: var(--border-glow);
            box-shadow: var(--shadow-lg), 0 0 40px rgba(255, 107, 107, 0.1);
            transform: translateY(-2px);
        }
        
        .card-header {
            padding: 1.5rem 1.75rem;
            background: rgba(255, 255, 255, 0.03);
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-title {
            display: flex;
            align-items: center;
            gap: 0.875rem;
            font-weight: 700;
            font-size: 1.05rem;
            color: var(--text-primary);
        }
        
        .card-title i {
            font-size: 1.2rem;
            color: var(--primary-gradient-start);
        }
        
        .card-link {
            color: var(--primary-gradient-start);
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .card-link:hover {
            color: var(--primary-gradient-end);
        }
        
        .card-body {
            padding: 0;
        }
        
        /* Table */
        .table-responsive {
            overflow-x: auto;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table thead th {
            padding: 1rem 1.25rem;
            text-align: left;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-secondary);
            border-bottom: 1px solid var(--border);
        }
        
        .table tbody tr {
            border-bottom: 1px solid var(--border);
            transition: all 0.2s ease;
        }
        
        .table tbody tr:last-child {
            border-bottom: none;
        }
        
        .table tbody tr:hover {
            background: rgba(255, 255, 255, 0.02);
        }
        
        .table tbody td {
            padding: 1rem 1.25rem;
            font-size: 0.95rem;
            color: #000000 !important;
            font-weight: 500;
        }
        
        .table tbody tr {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: none;
        }
        
        .table th {
            color: #000000 !important;
            font-weight: 700;
        }
        
        .order-link {
            color: var(--primary-gradient-start);
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .order-link:hover {
            color: var(--primary-gradient-end);
        }
        
        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.35rem 0.75rem;
            border-radius: 8px;
            font-size: 0.75rem;
            font-weight: 700;
            color: #000000 !important;
            text-shadow: none;
        }
        
        .badge.new { background: rgba(90, 200, 250, 0.9); color: #000000 !important; border: 1px solid rgba(90, 200, 250, 0.5); }
        .badge.production { background: rgba(255, 214, 10, 0.95); color: #000000 !important; border: 1px solid rgba(255, 214, 10, 0.6); }
        .badge.completed { background: rgba(48, 209, 88, 0.9); color: #000000 !important; border: 1px solid rgba(48, 209, 88, 0.5); }
        .badge.danger { background: rgba(255, 69, 58, 0.9); color: #ffffff !important; border: 1px solid rgba(255, 69, 58, 0.5); }
        .badge.warning { background: rgba(255, 159, 10, 0.95); color: #000000 !important; border: 1px solid rgba(255, 159, 10, 0.6); }
        
        /* Side Panel */
        .side-panel {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }
        
        .alert-card {
            background: var(--bg-card);
            backdrop-filter: var(--backdrop-blur);
            -webkit-backdrop-filter: var(--backdrop-blur);
            border-radius: 20px;
            border: 1px solid var(--border);
            overflow: hidden;
        }
        
        .alert-card.danger {
            border-color: rgba(255, 69, 58, 0.3);
        }
        
        .alert-header {
            padding: 1.25rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-weight: 700;
            font-size: 1rem;
        }
        
        .alert-header.danger {
            color: #ff453a;
            background: rgba(255, 69, 58, 0.05);
        }
        
        .alert-header.warning {
            color: #ffd60a;
            background: rgba(255, 214, 10, 0.05);
        }
        
        .alert-list {
            list-style: none;
        }
        
        .alert-item {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--border);
            transition: all 0.2s ease;
        }
        
        .alert-item:last-child {
            border-bottom: none;
        }
        
        .alert-item:hover {
            background: rgba(255, 255, 255, 0.02);
        }
        
        .alert-item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        
        .alert-item-title {
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--text-primary);
        }
        
        .alert-item-meta {
            font-size: 0.8rem;
            color: var(--text-muted);
        }
        
        .recommendation-box {
            background: rgba(255, 107, 107, 0.1);
            border: 1px solid rgba(255, 107, 107, 0.3);
            border-radius: 12px;
            padding: 1rem;
            margin-top: 0.75rem;
        }
        
        .recommendation-box h6 {
            color: #ff6b6b;
            font-size: 0.85rem;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .recommendation-box p {
            font-size: 0.8rem;
            color: var(--text-secondary);
            margin: 0;
            line-height: 1.5;
        }
        
        /* Issue Badges for Module Summary */
        .issues-scroll-container {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            max-height: 400px;
            overflow-y: auto;
            padding-right: 0.5rem;
        }
        
        .issues-scroll-container::-webkit-scrollbar {
            width: 6px;
        }
        
        .issues-scroll-container::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 3px;
        }
        
        .issues-scroll-container::-webkit-scrollbar-thumb {
            background: rgba(255, 107, 107, 0.5);
            border-radius: 3px;
        }
        
        .issue-badge {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            padding: 1rem 1.25rem;
            border-radius: 12px;
            border: 1px solid;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.02);
        }
        
        .issue-badge.critical {
            border-color: rgba(255, 69, 58, 0.4);
            background: rgba(255, 69, 58, 0.08);
        }
        
        .issue-badge.warning {
            border-color: rgba(255, 214, 10, 0.4);
            background: rgba(255, 214, 10, 0.08);
        }
        
        .issue-badge.info {
            border-color: rgba(90, 200, 250, 0.4);
            background: rgba(90, 200, 250, 0.08);
        }
        
        .issue-badge:hover {
            transform: translateX(5px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
        }
        
        .issue-badge-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .issue-badge.critical .issue-badge-icon {
            background: rgba(255, 69, 58, 0.2);
            color: #ff453a;
        }
        
        .issue-badge.warning .issue-badge-icon {
            background: rgba(255, 214, 10, 0.2);
            color: #ffd60a;
        }
        
        .issue-badge.info .issue-badge-icon {
            background: rgba(90, 200, 250, 0.2);
            color: #5ac8fa;
        }
        
        .issue-badge-content {
            flex: 1;
            min-width: 0;
        }
        
        .issue-badge-title {
            font-weight: 700;
            font-size: 0.95rem;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .issue-count-badge {
            background: rgba(255, 255, 255, 0.15);
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .issue-badge-desc {
            font-size: 0.85rem;
            color: var(--text-secondary);
            margin: 0 0 0.5rem 0;
        }
        
        .issue-badge-action {
            font-size: 0.8rem;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .issue-badge-action i {
            color: #ffd60a;
        }
        
        .issue-badge-link {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.1);
            color: var(--text-primary);
            text-decoration: none;
            transition: all 0.3s ease;
            flex-shrink: 0;
        }
        
        .issue-badge-link:hover {
            background: var(--gradient-primary);
            transform: translateX(3px);
        }
        
        /* Modules Grid */
        #modules-grid {
            scroll-margin-top: 100px;
        }
        
        .modules-section-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: var(--text-primary);
        }
        
        .module-card {
            background: rgba(30, 30, 40, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 16px;
            padding: 1.5rem;
            backdrop-filter: blur(15px);
            transition: all 0.3s ease;
            height: 100%;
            display: flex;
            flex-direction: column;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
        }
        
        .module-card:hover {
            border-color: var(--border-glow);
            transform: translateY(-4px);
            box-shadow: 0 12px 40px rgba(255, 107, 107, 0.2), 0 0 30px rgba(255, 107, 107, 0.15);
        }
        
        .module-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .module-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        
        .module-icon.orders { background: rgba(90, 200, 250, 0.2); color: #5ac8fa; }
        .module-icon.production { background: rgba(255, 214, 10, 0.2); color: #ffd60a; }
        .module-icon.warehouse { background: rgba(48, 209, 88, 0.2); color: #30d158; }
        .module-icon.equipment { background: rgba(255, 142, 83, 0.2); color: #FF8E53; }
        .module-icon.shipment { background: rgba(90, 200, 250, 0.2); color: #5ac8fa; }
        .module-icon.gost { background: rgba(255, 107, 107, 0.2); color: #ff6b6b; }
        
        .module-title {
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--text-primary);
        }
        
        .module-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.875rem;
            margin-bottom: 1.25rem;
        }
        
        .module-stat-item {
            background: rgba(30, 30, 40, 0.7);
            padding: 1.25rem;
            border-radius: 12px;
            text-align: center;
            border: 1px solid rgba(255, 255, 255, 0.15);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            backdrop-filter: blur(10px);
        }
        
        .module-stat-value {
            font-size: 2.2rem;
            font-weight: 800;
            color: #ffffff !important;
            margin-bottom: 0.35rem;
        }
        
        .module-stat-label {
            font-size: 0.75rem;
            color: rgba(255, 255, 255, 0.95) !important;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            font-weight: 600;
        }
        
        .module-issues-list {
            list-style: none;
            margin: 0;
            padding: 0;
            flex: 1;
        }
        
        .module-issue-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 0;
            font-size: 0.85rem;
            color: var(--text-secondary);
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }
        
        .module-issue-item:last-child {
            border-bottom: none;
        }
        
        .module-issue-item i {
            font-size: 0.75rem;
        }
        
        .module-issue-item.critical i { color: #ff453a; }
        .module-issue-item.warning i { color: #ffd60a; }
        .module-issue-item.success i { color: #30d158; }
        
        .module-link-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text-primary);
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.3s ease;
            margin-top: auto;
        }
        
        .module-link-btn:hover {
            background: var(--gradient-primary);
            border-color: transparent;
            transform: translateY(-2px);
        }
        
        .empty-state {
            padding: 3rem 1.5rem;
            text-align: center;
            color: var(--text-muted);
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        /* Progress Bar */
        .progress-container {
            margin-top: 1rem;
        }
        
        .progress-label {
            display: flex;
            justify-content: space-between;
            font-size: 0.8rem;
            color: var(--text-secondary);
            margin-bottom: 0.5rem;
        }
        
        .progress-bar {
            height: 8px;
            background: var(--bg-input);
            border-radius: 4px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: var(--gradient-primary);
            border-radius: 4px;
            transition: width 0.5s ease;
        }
        
        /* Chart Container */
        .chart-container {
            position: relative;
            height: 350px;
            padding: 1.5rem;
            background: rgba(255, 255, 255, 0.03);
            border-radius: 16px;
            border: 1px solid var(--border);
        }
        
        .kpi-grid + .content-grid .chart-container {
            height: 380px;
        }
        
        /* Chart KPI Row */
        .chart-kpi-row {
            display: flex;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }
        
        .chart-kpi-item {
            flex: 1;
            min-width: 140px;
            padding: 1rem;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 12px;
            border: 1px solid var(--border);
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .chart-kpi-item:hover {
            background: rgba(255, 255, 255, 0.08);
            transform: translateY(-2px);
        }
        
        .chart-kpi-value {
            font-size: 1.75rem;
            font-weight: 700;
            color: #FF6B6B;
            margin-bottom: 0.25rem;
            line-height: 1.2;
        }
        
        .chart-kpi-label {
            font-size: 0.8rem;
            color: rgba(255, 255, 255, 0.6);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* Card Info Tooltip */
        .card-info-tooltip {
            position: relative;
            margin-left: auto;
        }
        
        .card-info-tooltip i {
            color: rgba(255, 255, 255, 0.5);
            cursor: help;
            font-size: 1rem;
            transition: color 0.3s ease;
        }
        
        .card-info-tooltip:hover i {
            color: #5ac8fa;
        }
        
        .card-info-tooltip .tooltip-text {
            visibility: hidden;
            position: absolute;
            top: 150%;
            right: 0;
            width: 320px;
            background: rgba(15, 15, 26, 0.98);
            color: #fff;
            padding: 1rem;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.4);
            z-index: 1000;
            opacity: 0;
            transition: all 0.3s ease;
            font-size: 0.85rem;
            line-height: 1.6;
        }
        
        .card-info-tooltip:hover .tooltip-text {
            visibility: visible;
            opacity: 1;
        }
        
        .card-info-tooltip .tooltip-text strong {
            color: #FF6B6B;
            display: block;
            margin-bottom: 0.5rem;
        }
        
        /* Chart Legend Detailed */
        .chart-legend-detailed {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border);
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
            color: rgba(255, 255, 255, 0.8);
        }
        
        .legend-color {
            width: 16px;
            height: 16px;
            border-radius: 4px;
            flex-shrink: 0;
        }
        
        /* Chart Legend Grid - новые стили для карточек статусов */
        .chart-legend-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 0.75rem;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border);
        }
        
        .legend-stat-card {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem;
            background: rgba(255, 255, 255, 0.03);
            border-radius: 10px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            transition: all 0.2s ease;
        }
        
        .legend-stat-card:hover {
            background: rgba(255, 255, 255, 0.06);
            border-color: rgba(255, 255, 255, 0.15);
            transform: translateY(-2px);
        }
        
        .legend-stat-icon {
            width: 12px;
            height: 12px;
            border-radius: 3px;
            flex-shrink: 0;
        }
        
        .legend-stat-info {
            display: flex;
            flex-direction: column;
            gap: 0.15rem;
        }
        
        .legend-stat-name {
            font-size: 0.75rem;
            color: rgba(255, 255, 255, 0.6);
            font-weight: 500;
        }
        
        .legend-stat-value {
            font-size: 0.95rem;
            color: rgba(255, 255, 255, 0.9);
            font-weight: 600;
        }
        
        .legend-stat-sum {
            font-size: 0.7rem;
            color: rgba(255, 255, 255, 0.5);
        }
        
        /* Progress Bar Container */
        .progress-bar-container {
            padding: 1rem;
            background: rgba(255, 255, 255, 0.03);
            border-radius: 12px;
            border: 1px solid var(--border);
        }
        
        .progress-label {
            font-size: 0.85rem;
            color: rgba(255, 255, 255, 0.7);
            margin-bottom: 0.5rem;
        }
        
        .progress-track {
            height: 12px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 6px;
            overflow: hidden;
            margin-bottom: 0.5rem;
        }
        
        .progress-fill {
            height: 100%;
            border-radius: 6px;
            transition: width 0.5s ease;
        }
        
        .progress-value {
            font-size: 0.85rem;
            color: rgba(255, 255, 255, 0.6);
            text-align: right;
        }
        
        /* View Toggle */
        .view-toggle {
            display: flex;
            gap: 0.5rem;
            background: var(--glass-bg);
            padding: 0.25rem;
            border-radius: 10px;
            border: 1px solid var(--border);
        }
        
        .view-btn {
            padding: 0.5rem 0.75rem;
            background: transparent;
            border: none;
            border-radius: 8px;
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .view-btn:hover {
            color: var(--text-primary);
        }
        
        .view-btn.active {
            background: var(--gradient-primary);
            color: white;
        }
        
        /* Kanban View */
        .kanban-board {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            padding: 1rem;
        }
        
        .kanban-column {
            background: var(--bg-card);
            border-radius: 16px;
            border: 1px solid var(--border);
            padding: 1rem;
        }
        
        .kanban-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--border);
        }
        
        .kanban-title {
            font-weight: 700;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .kanban-count {
            background: var(--glass-bg);
            padding: 0.25rem 0.6rem;
            border-radius: 8px;
            font-size: 0.75rem;
        }
        
        .kanban-item {
            background: var(--glass-bg);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 0.75rem;
            transition: all 0.3s ease;
        }
        
        .kanban-item:hover {
            border-color: var(--border-glow);
            transform: translateY(-2px);
        }
        
        .kanban-item-title {
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }
        
        .kanban-item-meta {
            font-size: 0.8rem;
            color: var(--text-muted);
            display: flex;
            justify-content: space-between;
        }
        
        /* List Group */
        .list-group-item {
            background: transparent;
            border: none;
            border-bottom: 1px solid var(--border);
            padding: 1rem 1.25rem;
            transition: all 0.2s ease;
            color: var(--text-secondary);
        }
        
        .list-group-item:hover {
            background: rgba(48, 54, 61, 0.3);
        }
        
        .list-group-item:last-child {
            border-bottom: none;
        }
        
        /* Responsive */
        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .navbar {
                padding: 1rem;
            }
            
            .nav-menu {
                display: none;
            }
            
            .mobile-menu-btn {
                display: block;
            }
            
            .main-content {
                padding: 5rem 1rem 1rem;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .user-info {
                display: none;
            }
        }
    </style>
</head>
<body>
    <!-- Background elements -->
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

    <!-- Навигация -->
    <nav class="navbar" id="navbar">
        <a href="<?= APP_URL ?>" class="nav-brand">
            <div class="brand-logo">
                <svg viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
            </div>
            <span class="brand-name">PolesieMES</span>
        </a>

        <ul class="nav-menu">
            <li>
                <a href="<?= APP_URL ?>/modules/dashboard/index.php" class="nav-link <?= ($currentPage ?? '') == 'dashboard' ? 'active' : '' ?>">
                    <i class="fas fa-chart-line"></i>
                    Главная
                </a>
            </li>

            <?php if (hasRole(['admin', 'director', 'manager'])): ?>
            <!-- Заказы - только админ, директор и менеджеры -->
            <li>
                <a href="<?= APP_URL ?>/modules/orders/index.php" class="nav-link">
                    <i class="fas fa-shopping-cart"></i>
                    Заказы
                </a>
            </li>
            <?php endif; ?>

            <?php if (hasRole(['admin', 'director', 'manager', 'technologist', 'operator', 'warehouse_keeper'])): ?>
            <!-- Производство - все основные роли -->
            <li>
                <a href="<?= APP_URL ?>/modules/production/index.php" class="nav-link">
                    <i class="fas fa-cogs"></i>
                    Производство
                </a>
            </li>
            <?php endif; ?>

            <?php if (hasRole(['admin', 'director', 'manager', 'warehouse_keeper'])): ?>
            <!-- Склад -->
            <li>
                <?php if (hasRole(['director', 'admin'])): ?>
                <a href="<?= APP_URL ?>/modules/director/dashboard.php#warehouse-section" class="nav-link">
                    <i class="fas fa-warehouse"></i>
                    Склад
                </a>
                <?php else: ?>
                <a href="<?= APP_URL ?>/modules/warehouse/warehouse_dashboard.php" class="nav-link">
                    <i class="fas fa-warehouse"></i>
                    Склад
                </a>
                <?php endif; ?>
            </li>
            <?php endif; ?>

            <?php if (hasRole(['admin', 'director', 'manager', 'technologist', 'operator', 'warehouse_keeper'])): ?>
            <!-- Оборудование - расширенный доступ -->
            <li>
                <a href="<?= APP_URL ?>/modules/equipment/index.php" class="nav-link">
                    <i class="fas fa-tools"></i>
                    Оборудование
                </a>
            </li>
            <?php endif; ?>

            <?php if (hasRole(['admin', 'director', 'manager', 'logistician', 'warehouse_keeper'])): ?>
            <!-- Отгрузка -->
            <li>
                <a href="<?= APP_URL ?>/modules/shipment/index.php" class="nav-link">
                    <i class="fas fa-truck"></i>
                    Отгрузка
                </a>
            </li>
            <?php endif; ?>

            <?php if (hasRole(['admin', 'director', 'manager', 'technologist', 'warehouse_keeper'])): ?>
            <!-- Документы -->
            <li>
                <a href="<?= APP_URL ?>/modules/documents/index.php" class="nav-link">
                    <i class="fas fa-file-contract"></i>
                    Документы
                </a>
            </li>
            <?php endif; ?>

            <?php if (hasRole(['admin', 'director'])): ?>
            <!-- Сотрудники - только админ и директор -->
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

    <!-- Основной контент -->
    <div class="main-content">
        <!-- KPI Cards - Основные показатели -->
        <div class="kpi-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon">
                        <i class="fas fa-shopping-cart"></i>
                    </div>
                    <?php if (($stats['orders']['new_orders'] ?? 0) > 0): ?>
                    <div class="stat-trend up">
                        <i class="fas fa-arrow-up"></i>
                        +<?= $stats['orders']['new_orders'] ?> новых
                    </div>
                    <?php else: ?>
                    <div class="stat-trend">
                        <i class="fas fa-minus"></i>
                        Без изменений
                    </div>
                    <?php endif; ?>
                </div>
                <div class="stat-value"><?= $stats['orders']['total'] ?? 0 ?></div>
                <div class="stat-label">Всего заказов</div>
                <div class="stat-details">
                    <div class="stat-detail-item">
                        <span class="dot new"></span>
                        Новые: <?= $stats['orders']['new_orders'] ?? 0 ?>
                    </div>
                    <div class="stat-detail-item">
                        <span class="dot production"></span>
                        В пр-ве: <?= $stats['orders']['production_orders'] ?? 0 ?>
                    </div>
                    <div class="stat-detail-item">
                        <span class="dot completed"></span>
                        Готовы: <?= $stats['orders']['ready_orders'] ?? 0 ?>
                    </div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon">
                        <i class="fas fa-ruble-sign"></i>
                    </div>
                    <div class="stat-trend up">
                        <i class="fas fa-calendar"></i>
                        За месяц
                    </div>
                </div>
                <div class="stat-value"><?= formatCurrency($stats['revenue']) ?></div>
                <div class="stat-label">Выручка за месяц</div>
                <div class="stat-details">
                    <div class="stat-detail-item">
                        <span class="dot completed"></span>
                        Завершено: <?= $efficiency['total_completed'] ?? 0 ?>
                    </div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon">
                        <i class="fas fa-tasks"></i>
                    </div>
                    <?php if (($weeklyEfficiency['efficiency_percent'] ?? 0) >= 80): ?>
                    <div class="stat-trend up">
                        <i class="fas fa-arrow-up"></i>
                        <?= $weeklyEfficiency['efficiency_percent'] ?>%
                    </div>
                    <?php else: ?>
                    <div class="stat-trend down">
                        <i class="fas fa-arrow-down"></i>
                        <?= $weeklyEfficiency['efficiency_percent'] ?>%
                    </div>
                    <?php endif; ?>
                </div>
                <div class="stat-value"><?= $stats['tasks']['total_tasks'] ?? 0 ?></div>
                <div class="stat-label">Производственных заданий</div>
                <div class="stat-details">
                    <div class="stat-detail-item">
                        <span class="dot production"></span>
                        В работе: <?= $stats['tasks']['in_progress'] ?? 0 ?>
                    </div>
                    <div class="stat-detail-item">
                        <span class="dot new"></span>
                        План: <?= $stats['tasks']['planned'] ?? 0 ?>
                    </div>
                    <div class="stat-detail-item">
                        <span class="dot completed"></span>
                        Выполнено: <?= $stats['tasks']['completed'] ?? 0 ?>
                    </div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon">
                        <i class="fas fa-industry"></i>
                    </div>
                    <?php if (($stats['equipment']['broken'] ?? 0) > 0): ?>
                    <div class="stat-trend down">
                        <i class="fas fa-exclamation-triangle"></i>
                        <?= $stats['equipment']['broken'] ?> неисправно
                    </div>
                    <?php else: ?>
                    <div class="stat-trend up">
                        <i class="fas fa-check-circle"></i>
                        Все работает
                    </div>
                    <?php endif; ?>
                </div>
                <div class="stat-value"><?= $stats['equipment']['operational'] ?? 0 ?>/<?= $stats['equipment']['total_equipment'] ?? 0 ?></div>
                <div class="stat-label">Оборудование в работе</div>
                <div class="stat-details">
                    <div class="stat-detail-item">
                        <span class="dot completed"></span>
                        Работает: <?= $stats['equipment']['operational'] ?? 0 ?>
                    </div>
                    <?php if (($stats['equipment']['maintenance'] ?? 0) > 0): ?>
                    <div class="stat-detail-item">
                        <span class="dot warning"></span>
                        ТО: <?= $stats['equipment']['maintenance'] ?? 0 ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Сводка проблем по модулям -->
        <?php 
        $allIssues = array_merge($ordersIssues, $productionIssues, $warehouseIssues, $equipmentIssues, $shipmentIssues, $docsIssues);
        if (!empty($allIssues)): 
        ?>
        <div class="card mb-4" style="border-color: rgba(255, 107, 107, 0.3);" id="modules-alerts">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-exclamation-triangle" style="color: #ff6b6b;"></i>
                    Проблемы и рекомендации по модулям
                </div>
                <a href="#modules-grid" class="card-link">Перейти к модулям ↓</a>
            </div>
            <div class="card-body" style="padding: 1.5rem;">
                <div class="issues-scroll-container">
                    <?php foreach ($allIssues as $issue): ?>
                    <div class="issue-badge <?= $issue['type'] ?>">
                        <div class="issue-badge-icon">
                            <i class="fas fa-<?= $issue['type'] == 'critical' ? 'exclamation-circle' : ($issue['type'] == 'warning' ? 'exclamation-triangle' : 'info-circle') ?>"></i>
                        </div>
                        <div class="issue-badge-content">
                            <div class="issue-badge-title"><?= e($issue['title']) ?> <span class="issue-count-badge"><?= $issue['count'] ?></span></div>
                            <p class="issue-badge-desc"><?= e($issue['description']) ?></p>
                            <div class="issue-badge-action">
                                <i class="fas fa-lightbulb"></i> <?= e($issue['recommendation']) ?>
                            </div>
                        </div>
                        <a href="<?= $issue['link'] ?>" class="issue-badge-link">
                            <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Блок рекомендаций и проблем -->
        <?php if (!empty($overdueOrders) || !empty($lowStockMaterials) || ($stats['equipment']['broken'] ?? 0) > 0): ?>
        <div class="card mb-4" style="border-color: rgba(255, 69, 58, 0.3);">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-lightbulb" style="color: #ff9f0a;"></i>
                    Рекомендации по улучшению
                </div>
            </div>
            <div class="card-body" style="padding: 1.5rem;">
                <div class="row g-4">
                    <?php if (!empty($overdueOrders)): ?>
                    <div class="col-md-6">
                        <div class="recommendation-box">
                            <h6><i class="fas fa-clock"></i> Просроченные заказы</h6>
                            <p>Обнаружено <?= count($overdueOrders) ?> заказов с просрочкой. Рекомендуется:</p>
                            <ul style="font-size: 0.8rem; color: var(--text-secondary); margin: 0.5rem 0 0 1.25rem; padding: 0;">
                                <li>Приоритезировать производство просроченных заказов</li>
                                <li>Связаться с клиентами и сообщить новые сроки</li>
                                <li>Проанализировать причины задержек</li>
                            </ul>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($lowStockMaterials)): ?>
                    <div class="col-md-6">
                        <div class="recommendation-box">
                            <h6><i class="fas fa-boxes"></i> Низкие запасы материалов</h6>
                            <p><?= count($lowStockMaterials) ?> материалов ниже минимального уровня. Рекомендуется:</p>
                            <ul style="font-size: 0.8rem; color: var(--text-secondary); margin: 0.5rem 0 0 1.25rem; padding: 0;">
                                <li>Создать заявки на закупку критических материалов</li>
                                <li>Проверить альтернативных поставщиков</li>
                                <li>Оптимизировать складские запасы</li>
                            </ul>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (($stats['equipment']['broken'] ?? 0) > 0): ?>
                    <div class="col-md-6">
                        <div class="recommendation-box">
                            <h6><i class="fas fa-tools"></i> Неисправное оборудование</h6>
                            <p><?= $stats['equipment']['broken'] ?> ед. оборудования требует ремонта. Рекомендуется:</p>
                            <ul style="font-size: 0.8rem; color: var(--text-secondary); margin: 0.5rem 0 0 1.25rem; padding: 0;">
                                <li>Создать заявку в службу технического обслуживания</li>
                                <li>Перераспределить задачи на исправное оборудование</li>
                                <li>Запланировать профилактическое обслуживание</li>
                            </ul>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Основной контент -->
        <div class="content-grid">
            <!-- Последние заказы -->
            <div class="card mb-4">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-shopping-cart"></i>
                        Последние заказы
                    </div>
                        <div style="display: flex; gap: 0.75rem; align-items: center;">
                            <div class="view-toggle">
                                <button class="view-btn active" onclick="switchView('table')">
                                    <i class="fas fa-table"></i>
                                </button>
                                <button class="view-btn" onclick="switchView('kanban')">
                                    <i class="fas fa-columns"></i>
                                </button>
                            </div>
                            <a href="<?= APP_URL ?>/modules/orders/index.php" class="card-link">Все заказы →</a>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Table View -->
                        <div id="tableView" class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>№ заказа</th>
                                        <th>Клиент</th>
                                        <th>Сумма</th>
                                        <th>Статус</th>
                                        <th>Дата</th>
                                        <th>Действия</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentOrders as $order): ?>
                                    <tr>
                                        <td>
                                            <a href="<?= APP_URL ?>/modules/orders/view.php?id=<?= $order['id'] ?>" class="order-link">
                                                <?= e($order['order_number']) ?>
                                            </a>
                                        </td>
                                        <td><?= e($order['customer_name'] ?? 'Не указан') ?></td>
                                        <td><?= formatCurrency($order['total_amount']) ?></td>
                                        <td>
                                            <span class="badge <?= getOrderStatusClass($order['status']) ?>">
                                                <?= getOrderStatusName($order['status']) ?>
                                            </span>
                                        </td>
                                        <td><?= formatDate($order['order_date']) ?></td>
                                        <td>
                                            <a href="<?= APP_URL ?>/modules/orders/view.php?id=<?= $order['id'] ?>" class="btn-quick" style="padding: 0.4rem 0.75rem; font-size: 0.75rem;">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Kanban View (скрыт по умолчанию) -->
                        <div id="kanbanView" style="display: none;">
                            <div class="kanban-board">
                                <?php
                                $statuses = [
                                    'new' => ['name' => 'Новые', 'icon' => 'fa-plus-circle', 'class' => 'new'],
                                    'in_production' => ['name' => 'В производстве', 'icon' => 'fa-cog', 'class' => 'production'],
                                    'quality_check' => ['name' => 'Контроль качества', 'icon' => 'fa-check-double', 'class' => 'warning'],
                                    'ready' => ['name' => 'Готовы', 'icon' => 'fa-box', 'class' => 'completed']
                                ];
                                foreach ($statuses as $statusKey => $statusInfo):
                                    $statusOrders = array_filter($recentOrders, fn($o) => $o['status'] === $statusKey);
                                ?>
                                <div class="kanban-column">
                                    <div class="kanban-header">
                                        <div class="kanban-title">
                                            <i class="fas <?= $statusInfo['icon'] ?>" style="color: var(--<?= $statusInfo['class'] ?>);"></i>
                                            <?= $statusInfo['name'] ?>
                                        </div>
                                        <span class="kanban-count"><?= count($statusOrders) ?></span>
                                    </div>
                                    <?php foreach ($statusOrders as $order): ?>
                                    <div class="kanban-item">
                                        <div class="kanban-item-title"><?= e($order['order_number']) ?></div>
                                        <div class="kanban-item-meta">
                                            <span><?= e($order['customer_name'] ?? 'Клиент') ?></span>
                                            <span><?= formatCurrency($order['total_amount']) ?></span>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                    <?php if (empty($statusOrders)): ?>
                                    <div class="empty-state" style="padding: 1rem;">
                                        <p style="font-size: 0.8rem;">Нет заказов</p>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Графики и аналитика -->
                <div class="content-grid" style="margin-bottom: 1.5rem;">
                <!-- График эффективности -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">
                            <i class="fas fa-chart-line"></i>
                            Эффективность производства (7 дней)
                        </div>
                        <div class="card-info-tooltip">
                            <i class="fas fa-info-circle"></i>
                            <span class="tooltip-text">
                                <strong>Что показывает:</strong> Динамику выполненных производственных заданий за последние 7 дней.<br>
                                <strong>Для чего:</strong> Помогает оценить производительность цеха и выявить дни с низкой эффективностью.<br>
                                <strong>Как читать:</strong> Чем выше точка на графике, тем больше заданий выполнено в этот день.
                            </span>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="chart-kpi-row">
                            <div class="chart-kpi-item">
                                <?php $totalCompleted = array_sum(array_column($efficiencyData, 'completed_tasks')); ?>
                                <div class="chart-kpi-value"><?= $totalCompleted ?></div>
                                <div class="chart-kpi-label">Всего выполнено</div>
                            </div>
                            <div class="chart-kpi-item">
                                <div class="chart-kpi-value"><?= round($totalCompleted / 7, 1) ?></div>
                                <div class="chart-kpi-label">В среднем в день</div>
                            </div>
                            <div class="chart-kpi-item">
                                <?php 
                                $prevWeekStmt = $db->query("SELECT COUNT(*) as cnt FROM production_tasks WHERE status = 'completed' AND created_at BETWEEN DATE_SUB(NOW(), INTERVAL 14 DAY) AND DATE_SUB(NOW(), INTERVAL 7 DAY)");
                                $prevWeekCompleted = $prevWeekStmt->fetch()['cnt'] ?? 0;
                                $growthPercent = $prevWeekCompleted > 0 ? round((($totalCompleted - $prevWeekCompleted) / $prevWeekCompleted) * 100, 1) : 0;
                                ?>
                                <div class="chart-kpi-value" style="color: <?= $growthPercent >= 0 ? '#30d158' : '#ff453a' ?>;">
                                    <?= $growthPercent >= 0 ? '+' : '' ?><?= $growthPercent ?>%
                                </div>
                                <div class="chart-kpi-label">К прошлой неделе</div>
                            </div>
                            <div class="chart-kpi-item">
                                <?php $totalTasks = array_sum(array_column($efficiencyData, 'total_tasks')); ?>
                                <div class="chart-kpi-value" style="color: #5ac8fa;"><?= $totalTasks ?></div>
                                <div class="chart-kpi-label">Всего заданий</div>
                            </div>
                        </div>
                        <div class="chart-container" style="height: 280px;">
                            <canvas id="efficiencyChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Диаграмма статусов заказов -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">
                            <i class="fas fa-chart-pie"></i>
                            Статусы заказов
                        </div>
                        <div class="card-info-tooltip">
                            <i class="fas fa-info-circle"></i>
                            <span class="tooltip-text">
                                <strong>Что показывает:</strong> Распределение заказов по стадиям выполнения в реальном времени.<br>
                                <strong>Для чего:</strong> Позволяет быстро оценить загрузку производства и количество заказов в работе.<br>
                                <strong>Как читать:</strong> Каждый сектор показывает долю заказов в определённом статусе от общего количества.
                            </span>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="chart-kpi-row">
                            <div class="chart-kpi-item">
                                <div class="chart-kpi-value"><?= $stats['orders']['total'] ?? 0 ?></div>
                                <div class="chart-kpi-label">Всего заказов</div>
                            </div>
                            <div class="chart-kpi-item">
                                <div class="chart-kpi-value" style="color: #30d158;"><?= $stats['orders']['completed_orders'] ?? 0 ?></div>
                                <div class="chart-kpi-label">Завершено</div>
                            </div>
                            <div class="chart-kpi-item">
                                <div class="chart-kpi-value" style="color: #ffd60a;"><?= ($stats['orders']['in_production'] ?? 0) + ($stats['orders']['quality_check'] ?? 0) ?></div>
                                <div class="chart-kpi-label">В работе</div>
                            </div>
                            <div class="chart-kpi-item">
                                <div class="chart-kpi-value" style="color: #bf5af2;"><?= $stats['orders']['ready_orders'] ?? 0 ?></div>
                                <div class="chart-kpi-label">Готовы</div>
                            </div>
                        </div>
                        <div class="chart-legend-grid">
                            <?php foreach ($orderStatusData as $item): ?>
                            <div class="legend-stat-card">
                                <div class="legend-stat-icon" style="background: <?= 
                                    $item['status'] === 'new' ? '#5ac8fa' :
                                    ($item['status'] === 'in_production' ? '#ffd60a' :
                                    ($item['status'] === 'quality_check' ? '#ff9f0a' :
                                    ($item['status'] === 'ready' ? '#bf5af2' : '#30d158')))
                                ?>;"></div>
                                <div class="legend-stat-info">
                                    <div class="legend-stat-name"><?= 
                                        $item['status'] === 'new' ? 'Новые' :
                                        ($item['status'] === 'in_production' ? 'В производстве' :
                                        ($item['status'] === 'quality_check' ? 'На контроле качества' :
                                        ($item['status'] === 'ready' ? 'Готовы к отгрузке' : 'Завершены')))
                                    ?></div>
                                    <div class="legend-stat-value"><?= $item['count'] ?> заказ.</div>
                                    <div class="legend-stat-sum"><?= number_format($item['total_value'], 0, '.', ' ') ?> ₽</div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="chart-container" style="height: 250px; margin-top: 1rem;">
                            <canvas id="ordersStatusChart"></canvas>
                        </div>
                    </div>
                </div>
                </div>

                <!-- Комбинированный график: Заказы vs Производство -->
                <div class="card" style="margin-bottom: 1.5rem;">
                    <div class="card-header">
                        <div class="card-title">
                            <i class="fas fa-chart-mixed"></i>
                            Заказы и Производство (14 дней)
                        </div>
                        <div class="card-info-tooltip">
                            <i class="fas fa-info-circle"></i>
                            <span class="tooltip-text">
                                <strong>Что показывает:</strong> Сравнение поступающих заказов и производственных мощностей в динамике.<br>
                                <strong>Для чего:</strong> Помогает выявить дисбаланс между спросом и производственными возможностями.<br>
                                <strong>Как читать:</strong> Голубая линия — новые заказы, оранжевая — созданные задания, зелёная — выполненные задания.
                            </span>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="chart-kpi-row">
                            <div class="chart-kpi-item">
                                <?php $totalOrders14 = array_sum(array_column($fullOrdersVsProduction, 'orders_count')); ?>
                                <div class="chart-kpi-value"><?= $totalOrders14 ?></div>
                                <div class="chart-kpi-label">Новых заказов</div>
                            </div>
                            <div class="chart-kpi-item">
                                <?php $totalTasks14 = array_sum(array_column($fullOrdersVsProduction, 'tasks_count')); ?>
                                <div class="chart-kpi-value"><?= $totalTasks14 ?></div>
                                <div class="chart-kpi-label">Создано заданий</div>
                            </div>
                            <div class="chart-kpi-item">
                                <?php $totalCompleted14 = array_sum(array_column($fullOrdersVsProduction, 'completed_tasks')); ?>
                                <div class="chart-kpi-value" style="color: #30d158;"><?= $totalCompleted14 ?></div>
                                <div class="chart-kpi-label">Выполнено заданий</div>
                            </div>
                            <div class="chart-kpi-item">
                                <?php $completionRate = $totalTasks14 > 0 ? round(($totalCompleted14 / $totalTasks14) * 100, 1) : 0; ?>
                                <div class="chart-kpi-value" style="color: #bf5af2;"><?= $completionRate ?>%</div>
                                <div class="chart-kpi-label">% выполнения</div>
                            </div>
                        </div>
                        <div class="chart-container" style="height: 350px;">
                            <canvas id="ordersVsProductionChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Боковая панель -->
            <div class="side-panel">
                <!-- Сводка по модулям -->
                <div id="modules-grid" style="margin-bottom: 1.5rem;">
                    <h2 class="modules-section-title">
                        <i class="fas fa-th-large"></i> Модули системы
                    </h2>
                    <div class="modules-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1rem;">
                        
                        <!-- Заказы -->
                        <div class="module-card">
                            <div class="module-header">
                                <div class="module-icon orders">
                                    <i class="fas fa-shopping-cart"></i>
                                </div>
                                <div class="module-title">Заказы</div>
                            </div>
                            <div class="module-stats">
                                <div class="module-stat-item">
                                    <div class="module-stat-value"><?= $stats['orders']['total'] ?? 0 ?></div>
                                    <div class="module-stat-label">Всего</div>
                                </div>
                                <div class="module-stat-item">
                                    <div class="module-stat-value" style="color: #30d158;"><?= $stats['orders']['new_orders'] ?? 0 ?></div>
                                    <div class="module-stat-label">Новые</div>
                                </div>
                                <div class="module-stat-item">
                                    <div class="module-stat-value" style="color: #ffd60a;"><?= $stats['orders']['in_production'] ?? 0 ?></div>
                                    <div class="module-stat-label">В работе</div>
                                </div>
                                <div class="module-stat-item">
                                    <div class="module-stat-value" style="color: #5ac8fa;"><?= $stats['orders']['ready'] ?? 0 ?></div>
                                    <div class="module-stat-label">Готовы</div>
                                </div>
                            </div>
                            <ul class="module-issues-list">
                                <?php if (!empty($overdueOrders)): ?>
                                <li class="module-issue-item critical">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <span><?= count($overdueOrders) ?> просрочено</span>
                                </li>
                                <?php else: ?>
                                <li class="module-issue-item success">
                                    <i class="fas fa-check-circle"></i>
                                    <span>Все в срок</span>
                                </li>
                                <?php endif; ?>
                            </ul>
                            <a href="<?= APP_URL ?>/modules/orders/index.php" class="module-link-btn">
                                Перейти <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>

                        <!-- Производство -->
                        <div class="module-card">
                            <div class="module-header">
                                <div class="module-icon production">
                                    <i class="fas fa-cogs"></i>
                                </div>
                                <div class="module-title">Производство</div>
                            </div>
                            <div class="module-stats">
                                <div class="module-stat-item">
                                    <div class="module-stat-value"><?= $stats['tasks']['in_progress'] ?? 0 ?></div>
                                    <div class="module-stat-label">В работе</div>
                                </div>
                                <div class="module-stat-item">
                                    <div class="module-stat-value" style="color: #ffd60a;"><?= $stats['tasks']['planned'] ?? 0 ?></div>
                                    <div class="module-stat-label">В плане</div>
                                </div>
                                <div class="module-stat-item">
                                    <div class="module-stat-value" style="color: #30d158;"><?= $stats['tasks']['completed'] ?? 0 ?></div>
                                    <div class="module-stat-label">Выполнено</div>
                                </div>
                                <div class="module-stat-item">
                                    <div class="module-stat-value" style="color: #5ac8fa;"><?= $stats['tasks']['total_tasks'] ?? 0 ?></div>
                                    <div class="module-stat-label">Всего</div>
                                </div>
                            </div>
                            <ul class="module-issues-list">
                                <?php if ($weeklyEfficiency && $weeklyEfficiency['efficiency_percent']): ?>
                                <li class="module-issue-item success">
                                    <i class="fas fa-chart-line"></i>
                                    <span>Эффективность: <?= $weeklyEfficiency['efficiency_percent'] ?>%</span>
                                </li>
                                <?php endif; ?>
                                <?php if (!empty($overdueTasks)): ?>
                                <li class="module-issue-item critical">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <span><?= count($overdueTasks) ?> просрочено</span>
                                </li>
                                <?php else: ?>
                                <li class="module-issue-item success">
                                    <i class="fas fa-check-circle"></i>
                                    <span>Все по плану</span>
                                </li>
                                <?php endif; ?>
                            </ul>
                            <a href="<?= APP_URL ?>/modules/production/index.php" class="module-link-btn">
                                Перейти <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>

                        <!-- Склад -->
                        <div class="module-card">
                            <div class="module-header">
                                <div class="module-icon warehouse">
                                    <i class="fas fa-warehouse"></i>
                                </div>
                                <div class="module-title">Склад</div>
                            </div>
                            <div class="module-stats">
                                <div class="module-stat-item">
                                    <div class="module-stat-value"><?= $materialStats['total'] ?? 0 ?></div>
                                    <div class="module-stat-label">Позиций</div>
                                </div>
                                <div class="module-stat-item">
                                    <div class="module-stat-value" style="color: #ff453a;"><?= $outOfStockCount ?? 0 ?></div>
                                    <div class="module-stat-label">Нет на складе</div>
                                </div>
                            </div>
                            <ul class="module-issues-list">
                                <?php if ($lowStockCount > 0): ?>
                                <li class="module-issue-item warning">
                                    <i class="fas fa-triangle-exclamation"></i>
                                    <span><?= $lowStockCount ?> ниже нормы</span>
                                </li>
                                <?php else: ?>
                                <li class="module-issue-item success">
                                    <i class="fas fa-check-circle"></i>
                                    <span>Запасы в норме</span>
                                </li>
                                <?php endif; ?>
                            </ul>
                            <?php if (hasRole(['director', 'admin'])): ?>
                            <a href="<?= APP_URL ?>/modules/director/dashboard.php#warehouse-section" class="module-link-btn">
                                Обзор <i class="fas fa-arrow-right"></i>
                            </a>
                            <?php else: ?>
                            <a href="<?= APP_URL ?>/modules/warehouse/warehouse_dashboard.php" class="module-link-btn">
                                Перейти <i class="fas fa-arrow-right"></i>
                            </a>
                            <?php endif; ?>
                        </div>

                        <!-- Оборудование -->
                        <div class="module-card">
                            <div class="module-header">
                                <div class="module-icon equipment">
                                    <i class="fas fa-tools"></i>
                                </div>
                                <div class="module-title">Оборудование</div>
                            </div>
                            <div class="module-stats">
                                <div class="module-stat-item">
                                    <div class="module-stat-value" style="color: #30d158;"><?= $stats['equipment']['operational'] ?? 0 ?></div>
                                    <div class="module-stat-label">В работе</div>
                                </div>
                                <div class="module-stat-item">
                                    <div class="module-stat-value" style="color: #ff453a;"><?= $stats['equipment']['broken'] ?? 0 ?></div>
                                    <div class="module-stat-label">Неисправно</div>
                                </div>
                                <div class="module-stat-item">
                                    <div class="module-stat-value" style="color: #ffd60a;"><?= $stats['equipment']['maintenance'] ?? 0 ?></div>
                                    <div class="module-stat-label">На ТО</div>
                                </div>
                                <div class="module-stat-item">
                                    <div class="module-stat-value" style="color: #5ac8fa;"><?= $stats['equipment']['total_equipment'] ?? 0 ?></div>
                                    <div class="module-stat-label">Всего</div>
                                </div>
                            </div>
                            <ul class="module-issues-list">
                                <?php if (($stats['equipment']['maintenance'] ?? 0) > 0): ?>
                                <li class="module-issue-item warning">
                                    <i class="fas fa-wrench"></i>
                                    <span><?= $stats['equipment']['maintenance'] ?> на ТО</span>
                                </li>
                                <?php endif; ?>
                                <?php if (($stats['equipment']['broken'] ?? 0) == 0): ?>
                                <li class="module-issue-item success">
                                    <i class="fas fa-check-circle"></i>
                                    <span>Все исправно</span>
                                </li>
                                <?php else: ?>
                                <li class="module-issue-item critical">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <span>Требуется ремонт</span>
                                </li>
                                <?php endif; ?>
                            </ul>
                            <a href="<?= APP_URL ?>/modules/equipment/index.php" class="module-link-btn">
                                Перейти <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>

                        <!-- Отгрузка -->
                        <div class="module-card">
                            <div class="module-header">
                                <div class="module-icon shipment">
                                    <i class="fas fa-truck"></i>
                                </div>
                                <div class="module-title">Отгрузка</div>
                            </div>
                            <div class="module-stats">
                                <div class="module-stat-item">
                                    <div class="module-stat-value"><?= $shipmentStats['ready']['count'] ?? 0 ?></div>
                                    <div class="module-stat-label">Готовы</div>
                                </div>
                                <div class="module-stat-item">
                                    <div class="module-stat-value" style="color: #5ac8fa;"><?= $shipmentStats['shipped']['count'] ?? 0 ?></div>
                                    <div class="module-stat-label">В пути</div>
                                </div>
                            </div>
                            <ul class="module-issues-list">
                                <?php if ($urgentShipmentCount > 0): ?>
                                <li class="module-issue-item warning">
                                    <i class="fas fa-clock"></i>
                                    <span><?= $urgentShipmentCount ?> срочных</span>
                                </li>
                                <?php else: ?>
                                <li class="module-issue-item success">
                                    <i class="fas fa-check-circle"></i>
                                    <span>График соблюдается</span>
                                </li>
                                <?php endif; ?>
                            </ul>
                            <a href="<?= APP_URL ?>/modules/shipment/index.php" class="module-link-btn">
                                Перейти <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>

                        <!-- Документы -->
                        <div class="module-card">
                            <div class="module-header">
                                <div class="module-icon gost">
                                    <i class="fas fa-file-contract"></i>
                                </div>
                                <div class="module-title">Документы</div>
                            </div>
                            <div class="module-stats">
                                <div class="module-stat-item">
                                    <div class="module-stat-value"><?= $availableOrders['count'] ?? 0 ?></div>
                                    <div class="module-stat-label">Доступно</div>
                                </div>
                                <div class="module-stat-item">
                                    <div class="module-stat-value" style="color: #ffd60a;"><?= $pendingDocsCount ?? 0 ?></div>
                                    <div class="module-stat-label">Требуют</div>
                                </div>
                            </div>
                            <ul class="module-issues-list">
                                <?php if ($pendingDocsCount > 0): ?>
                                <li class="module-issue-item warning">
                                    <i class="fas fa-file-alt"></i>
                                    <span><?= $pendingDocsCount ?> без документов</span>
                                </li>
                                <?php else: ?>
                                <li class="module-issue-item success">
                                    <i class="fas fa-check-circle"></i>
                                    <span>Все оформлено</span>
                                </li>
                                <?php endif; ?>
                            </ul>
                            <a href="<?= APP_URL ?>/modules/documents/index.php" class="module-link-btn">
                                Перейти <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>

                    </div>
                </div>

                <!-- Требуют внимания -->
                <div class="alert-card">
                    <div class="alert-header warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        Требуют внимания
                    </div>
                    <ul class="alert-list">
                        <?php foreach ($activeTasks as $task): ?>
                        <li class="alert-item">
                            <div class="alert-item-header">
                                <span class="alert-item-title"><?= e($task['task_number']) ?></span>
                                <span class="badge warning">
                                    <i class="fas fa-clock"></i>
                                    <?= formatDate($task['planned_end']) ?>
                                </span>
                            </div>
                            <p class="alert-item-meta"><?= e($task['product_name']) ?></p>
                            <small style="color: var(--primary-gradient-start);"><?= e($task['stage_name']) ?></small>
                        </li>
                        <?php endforeach; ?>
                        <?php if (empty($activeTasks)): ?>
                        <li class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <p>Все задания в норме</p>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>


                <!-- Просроченные заказы -->
                <?php if (!empty($overdueOrders)): ?>
                <div class="alert-card danger">
                    <div class="alert-header danger">
                        <i class="fas fa-hourglass-end"></i>
                        Просрочено
                    </div>
                    <ul class="alert-list">
                        <?php foreach (array_slice($overdueOrders, 0, 3) as $order): ?>
                        <li class="alert-item">
                            <div class="alert-item-header">
                                <span class="alert-item-title"><?= e($order['order_number']) ?></span>
                                <span class="badge danger">
                                    -<?= $order['days_overdue'] ?> дн.
                                </span>
                            </div>
                            <p class="alert-item-meta"><?= e($order['customer_name'] ?? 'Клиент') ?></p>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Scroll effect for navbar
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
            const navMenu = document.querySelector('.nav-menu');
            navMenu.style.display = navMenu.style.display === 'flex' ? 'none' : 'flex';
        }

        // View switcher
        function switchView(view) {
            const tableView = document.getElementById('tableView');
            const kanbanView = document.getElementById('kanbanView');
            const viewBtns = document.querySelectorAll('.view-btn');
            
            if (view === 'table') {
                tableView.style.display = 'block';
                kanbanView.style.display = 'none';
                viewBtns[0].classList.add('active');
                viewBtns[1].classList.remove('active');
            } else {
                tableView.style.display = 'none';
                kanbanView.style.display = 'block';
                viewBtns[0].classList.remove('active');
                viewBtns[1].classList.add('active');
            }
        }

        // Chart.js - Efficiency Chart с реальными данными из БД
        const ctxEfficiency = document.getElementById('efficiencyChart').getContext('2d');
        const gradientEfficiency = ctxEfficiency.createLinearGradient(0, 0, 0, 250);
        gradientEfficiency.addColorStop(0, 'rgba(255, 107, 107, 0.5)');
        gradientEfficiency.addColorStop(1, 'rgba(255, 107, 107, 0.0)');

        // Подготовка данных за 7 дней
        const efficiencyLabels = <?= json_encode(array_map(function($d) { 
            return substr($d['day_name'], 0, 3); 
        }, $efficiencyData)) ?: json_encode(['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс']) ?>;
        const efficiencyCompleted = <?= json_encode(array_column($efficiencyData, 'completed_tasks')) ?: json_encode([8, 10, 12, 9, 11, 6, 5]) ?>;

        new Chart(ctxEfficiency, {
            type: 'line',
            data: {
                labels: efficiencyLabels,
                datasets: [{
                    label: 'Выполнено заданий',
                    data: efficiencyCompleted,
                    borderColor: '#FF6B6B',
                    backgroundColor: gradientEfficiency,
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#FF6B6B',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(15, 15, 26, 0.9)',
                        titleColor: '#fff',
                        bodyColor: 'rgba(255, 255, 255, 0.8)',
                        borderColor: 'rgba(255, 255, 255, 0.1)',
                        borderWidth: 1,
                        padding: 12,
                        displayColors: false,
                        callbacks: {
                            label: function(context) {
                                return 'Выполнено: ' + context.parsed.y + ' заданий';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { color: 'rgba(255, 255, 255, 0.05)' },
                        ticks: { color: 'rgba(255, 255, 255, 0.7)' }
                    },
                    y: {
                        grid: { color: 'rgba(255, 255, 255, 0.05)' },
                        ticks: { color: 'rgba(255, 255, 255, 0.7)', stepSize: 5 },
                        beginAtZero: true
                    }
                }
            }
        });

        // Chart.js - Orders Status Pie Chart с реальными данными
        const ctxOrdersStatus = document.getElementById('ordersStatusChart').getContext('2d');
        
        // Подготовка данных статусов заказов
        const statusMap = {
            'new': 0,
            'in_production': 0,
            'quality_check': 0,
            'ready': 0,
            'completed': 0
        };
        <?php foreach ($orderStatusData as $item): ?>
        statusMap['<?= $item['status'] ?>'] = <?= $item['count'] ?>;
        <?php endforeach; ?>
        
        new Chart(ctxOrdersStatus, {
            type: 'doughnut',
            data: {
                labels: ['Новые', 'В производстве', 'На контроле качества', 'Готовы', 'Завершены'],
                datasets: [{
                    data: [
                        statusMap['new'],
                        statusMap['in_production'],
                        statusMap['quality_check'],
                        statusMap['ready'],
                        statusMap['completed']
                    ],
                    backgroundColor: [
                        '#5ac8fa',
                        '#ffd60a',
                        '#ff9f0a',
                        '#bf5af2',
                        '#30d158'
                    ],
                    borderWidth: 0,
                    hoverOffset: 10
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '60%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: 'rgba(255, 255, 255, 0.8)',
                            font: { size: 11 },
                            padding: 15
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(15, 15, 26, 0.9)',
                        titleColor: '#fff',
                        bodyColor: 'rgba(255, 255, 255, 0.8)',
                        borderColor: 'rgba(255, 255, 255, 0.1)',
                        borderWidth: 1,
                        padding: 12,
                        callbacks: {
                            label: function(context) {
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percent = ((context.parsed / total) * 100).toFixed(1);
                                return context.label + ': ' + context.parsed + ' (' + percent + '%)';
                            }
                        }
                    }
                }
            }
        });


        // Chart.js - Комбинированный график: Заказы vs Производство
        const ctxOrdersVsProduction = document.getElementById('ordersVsProductionChart').getContext('2d');

        const ordersVsProdLabels = <?= json_encode(array_column($fullOrdersVsProduction, 'short_date')) ?>;
        const ordersCountData = <?= json_encode(array_column($fullOrdersVsProduction, 'orders_count')) ?>;
        const tasksCountData = <?= json_encode(array_column($fullOrdersVsProduction, 'tasks_count')) ?>;
        const completedTasksData = <?= json_encode(array_column($fullOrdersVsProduction, 'completed_tasks')) ?>;

        new Chart(ctxOrdersVsProduction, {
            type: 'line',
            data: {
                labels: ordersVsProdLabels,
                datasets: [
                    {
                        label: 'Новые заказы',
                        data: ordersCountData,
                        borderColor: '#5ac8fa',
                        backgroundColor: 'rgba(90, 200, 250, 0.1)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: '#5ac8fa',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Производственные задания',
                        data: tasksCountData,
                        borderColor: '#ff9f0a',
                        backgroundColor: 'rgba(255, 159, 10, 0.1)',
                        borderWidth: 3,
                        fill: false,
                        tension: 0.4,
                        pointBackgroundColor: '#ff9f0a',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Выполнено заданий',
                        data: completedTasksData,
                        borderColor: '#30d158',
                        backgroundColor: 'rgba(48, 209, 88, 0.2)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: '#30d158',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 5,
                        pointHoverRadius: 7,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        labels: {
                            color: 'rgba(255, 255, 255, 0.8)',
                            usePointStyle: true,
                            pointStyle: 'circle',
                            padding: 15
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(15, 15, 26, 0.95)',
                        titleColor: '#fff',
                        bodyColor: 'rgba(255, 255, 255, 0.8)',
                        borderColor: 'rgba(255, 255, 255, 0.1)',
                        borderWidth: 1,
                        padding: 12,
                        displayColors: true,
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ' + context.parsed.y;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { color: 'rgba(255, 255, 255, 0.05)' },
                        ticks: { color: 'rgba(255, 255, 255, 0.7)' }
                    },
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        grid: { color: 'rgba(255, 255, 255, 0.05)' },
                        ticks: { color: 'rgba(255, 255, 255, 0.7)', stepSize: 1 },
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Количество',
                            color: 'rgba(255, 255, 255, 0.6)'
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        grid: { drawOnChartArea: false },
                        ticks: { color: 'rgba(48, 209, 88, 0.8)', stepSize: 1 },
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Выполнено',
                            color: 'rgba(48, 209, 88, 0.8)'
                        }
                    }
                }
            }
        });
    </script>

    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
