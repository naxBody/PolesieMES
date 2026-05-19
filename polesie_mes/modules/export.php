<?php
/**
 * Экспорт данных в CSV
 * PolesieMES - Система управления производством ОАО "Полесьеэлектромаш"
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/helpers.php';

requireAuth();

$db = getDB();
$action = $_GET['action'] ?? '';
$source = $_GET['source'] ?? '';

// ==========================================
// ЭКСПОРТ ИСТОРИИ ДВИЖЕНИЙ (warehouse/history)
// ==========================================
if ($source === 'warehouse_history') {
    $filter_type = $_GET['type'] ?? 'all';
    $search = trim($_GET['search'] ?? '');
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';
    $employee_id = $_GET['employee_id'] ?? '';
    $category_id = $_GET['category_id'] ?? '';
    $format = $_GET['format'] ?? 'csv';
    
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
    
    if (!empty($employee_id)) {
        $whereConditions[] = "mvt.employee_id = :employee_id";
        $params['employee_id'] = $employee_id;
    }
    
    if (!empty($category_id)) {
        $whereConditions[] = "i.category_id = :category_id";
        $params['category_id'] = $category_id;
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
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
               END as operation_name
        FROM movements mvt
        LEFT JOIN items i ON mvt.item_id = i.id
        LEFT JOIN dictionaries c ON i.category_id = c.id AND c.dict_type = 'category'
        LEFT JOIN dictionaries u ON i.unit_id = u.id AND u.dict_type = 'unit'
        LEFT JOIN staff s ON mvt.employee_id = s.id
        WHERE {$whereClause}
        ORDER BY mvt.movement_date DESC
    ");
    foreach ($params as $key => $value) {
        $stmt->bindValue(":$key", $value);
    }
    $stmt->execute();
    $data = $stmt->fetchAll();
    
    $filename = 'istoriya_dvizheniy_' . date('Y-m-d_H-i') . '.csv';
    
    exportCSV($data, [
        'Дата и время' => function($row) { return date('d.m.Y H:i', strtotime($row['movement_date'])); },
        'Операция' => 'operation_name',
        'Материал' => 'item_name',
        'Артикул' => 'item_code',
        'Категория' => 'category_name',
        'Количество' => function($row) { return number_format($row['quantity'], 2, ',', ' '); },
        'Ед. изм.' => 'unit_name',
        'Сотрудник' => function($row) { return trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')); }
    ], $filename);
}

// ==========================================
// ЭКСПОРТ ОСТАТКОВ (warehouse/inventory)
// ==========================================
if ($source === 'warehouse_inventory') {
    $filter = $_GET['filter'] ?? 'all';
    $search = trim($_GET['search'] ?? '');
    $category_id = $_GET['category_id'] ?? '';
    $sort = $_GET['sort'] ?? 'name';
    $sort_order = $_GET['sort_order'] ?? 'ASC';
    $format = $_GET['format'] ?? 'csv';
    
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
    ");
    foreach ($params as $key => $value) {
        $stmt->bindValue(":$key", $value);
    }
    $stmt->execute();
    $data = $stmt->fetchAll();
    
    $statusNames = [
        'critical' => 'Нет на складе',
        'low' => 'Низкий запас',
        'normal' => 'Норма',
        'overstock' => 'Избыток'
    ];
    
    $filename = 'ostatatki_' . date('Y-m-d_H-i') . '.csv';
    
    exportCSV($data, [
        'Название' => 'name',
        'Артикул' => 'item_code',
        'Категория' => 'category_name',
        'Текущий остаток' => function($row) { return number_format($row['current_stock'], 2, ',', ' '); },
        'Мин. запас' => function($row) { return number_format($row['min_stock'], 2, ',', ' '); },
        'Макс. запас' => function($row) { return number_format($row['max_stock'] ?? 0, 2, ',', ' '); },
        'Ед. изм.' => 'unit_name',
        'Статус' => function($row) use ($statusNames) { return $statusNames[$row['stock_status']] ?? $row['stock_status']; }
    ], $filename);
}

// ==========================================
// ЭКСПОРТ ЗАКАЗА (orders/view)
// ==========================================
if ($source === 'order_view') {
    $order_id = $_GET['order_id'] ?? 0;
    $format = $_GET['format'] ?? 'csv';
    
    if (!$order_id) {
        die('Не указан номер заказа');
    }
    
    $stmt = $db->prepare("
        SELECT o.*, 
               p.name as customer_name,
               p.contact_person,
               p.phone as customer_phone,
               p.email as customer_email,
               o.status as status_name
        FROM orders o
        LEFT JOIN partners p ON o.customer_id = p.id
        WHERE o.id = :id
    ");
    $stmt->bindValue(':id', $order_id, PDO::PARAM_INT);
    $stmt->execute();
    $order = $stmt->fetch();
    
    if (!$order) {
        die('Заказ не найден');
    }
    
    // Получаем товары из JSON
    $itemsData = json_decode($order['items_json'], true) ?: [];
    
    // Формируем массив items в совместимом формате
    $items = [];
    foreach ($itemsData as $itemData) {
        $itemId = $itemData['product_id'] ?? $itemData['item_id'] ?? 0;
        
        // Получаем информацию о товаре
        $stmt = $db->prepare("
            SELECT i.id, i.name, i.item_code, i.unit_id, u.name as unit_name
            FROM items i
            LEFT JOIN dictionaries u ON i.unit_id = u.id AND u.dict_type = 'unit'
            WHERE i.id = :id
        ");
        $stmt->bindValue(':id', $itemId, PDO::PARAM_INT);
        $stmt->execute();
        $itemInfo = $stmt->fetch();
        
        $items[] = [
            'item_name' => $itemInfo['name'] ?? ($itemData['name'] ?? '-'),
            'item_code' => $itemInfo['item_code'] ?? ($itemData['item_code'] ?? '-'),
            'quantity' => $itemData['quantity'] ?? 0,
            'unit_name' => $itemInfo['unit_name'] ?? '-',
            'price' => $itemData['unit_price'] ?? $itemData['price'] ?? 0,
            'total' => $itemData['total'] ?? $itemData['total_price'] ?? 0
        ];
    }
    
    $filename = 'zakaz_' . $order['order_number'] . '_' . date('Y-m-d_H-i') . '.csv';
    
    // Формируем данные для экспорта в CSV с правильной структурой по колонкам
    $output = fopen('php://temp', 'r+');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM для UTF-8
    
    // Заголовок заказа
    fputcsv($output, ['Заказ №', $order['order_number']]);
    fputcsv($output, ['Дата', date('d.m.Y', strtotime($order['created_at']))]);
    fputcsv($output, ['Клиент', $order['customer_name'] ?? '-']);
    fputcsv($output, ['Статус', $order['status_name'] ?? '-']);
    fputcsv($output, []);
    
    // Заголовки колонок товаров
    fputcsv($output, ['Наименование', 'Артикул', 'Кол-во', 'Ед.', 'Цена', 'Сумма'], ';');
    
    // Товары
    foreach ($items as $item) {
        fputcsv($output, [
            $item['item_name'] ?? '-',
            $item['item_code'] ?? '-',
            number_format($item['quantity'], 2, ',', ' '),
            $item['unit_name'] ?? '-',
            number_format($item['price'], 2, ',', ' '),
            number_format($item['total'], 2, ',', ' ')
        ], ';');
    }
    
    rewind($output);
    $csvContent = stream_get_contents($output);
    fclose($output);
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $csvContent;
    exit;
}

// ==========================================
// ФУНКЦИИ ЭКСПОРТА
// ==========================================

/**
 * Экспорт в CSV
 */
function exportCSV($data, $columns, $filename) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM для UTF-8
    
    // Заголовки
    $headers = array_keys($columns);
    fputcsv($output, $headers, ';');
    
    // Данные
    foreach ($data as $row) {
        $csvRow = [];
        foreach ($columns as $key => $field) {
            if (is_callable($field)) {
                $csvRow[] = $field($row);
            } else {
                $csvRow[] = $row[$field] ?? '';
            }
        }
        fputcsv($output, $csvRow, ';');
    }
    
    fclose($output);
    exit;
}

// Если ничего не подошло
die('Неверные параметры экспорта');
