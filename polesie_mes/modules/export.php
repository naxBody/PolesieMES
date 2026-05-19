<?php
/**
 * Универсальный экспорт данных в CSV, Excel (XLSX) и PDF
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
    
    $filename = 'istoriya_dvizheniy_' . date('Y-m-d_H-i') . '.' . ($format === 'excel' ? 'xlsx' : ($format === 'pdf' ? 'pdf' : 'csv'));
    
    if ($format === 'csv') {
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
    } elseif ($format === 'excel') {
        exportExcel($data, [
            'Дата и время' => function($row) { return date('d.m.Y H:i', strtotime($row['movement_date'])); },
            'Операция' => 'operation_name',
            'Материал' => 'item_name',
            'Артикул' => 'item_code',
            'Категория' => 'category_name',
            'Количество' => function($row) { return (float)$row['quantity']; },
            'Ед. изм.' => 'unit_name',
            'Сотрудник' => function($row) { return trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')); }
        ], $filename, 'История движений');
    } elseif ($format === 'pdf') {
        exportPDF($data, [
            'Дата' => function($row) { return date('d.m.Y H:i', strtotime($row['movement_date'])); },
            'Операция' => 'operation_name',
            'Материал' => 'item_name',
            'Артикул' => 'item_code',
            'Кол-во' => function($row) { return number_format($row['quantity'], 2, ',', ' '); },
            'Ед.' => 'unit_name'
        ], $filename, 'История движений склада');
    }
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
    
    $filename = 'ostatatki_' . date('Y-m-d_H-i') . '.' . ($format === 'excel' ? 'xlsx' : ($format === 'pdf' ? 'pdf' : 'csv'));
    
    if ($format === 'csv') {
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
    } elseif ($format === 'excel') {
        exportExcel($data, [
            'Название' => 'name',
            'Артикул' => 'item_code',
            'Категория' => 'category_name',
            'Текущий остаток' => function($row) { return (float)$row['current_stock']; },
            'Мин. запас' => function($row) { return (float)$row['min_stock']; },
            'Макс. запас' => function($row) { return (float)($row['max_stock'] ?? 0); },
            'Ед. изм.' => 'unit_name',
            'Статус' => function($row) use ($statusNames) { return $statusNames[$row['stock_status']] ?? $row['stock_status']; }
        ], $filename, 'Остатки материалов');
    } elseif ($format === 'pdf') {
        exportPDF($data, [
            'Название' => 'name',
            'Артикул' => 'item_code',
            'Остаток' => function($row) { return number_format($row['current_stock'], 2, ',', ' '); },
            'Мин.' => function($row) { return number_format($row['min_stock'], 2, ',', ' '); },
            'Ед.' => 'unit_name',
            'Статус' => function($row) use ($statusNames) { return $statusNames[$row['stock_status']] ?? $row['stock_status']; }
        ], $filename, 'Остатки материалов на складе');
    }
}

// ==========================================
// ЭКСПОРТ ЗАКАЗА (orders/view)
// ==========================================
if ($source === 'order_view') {
    $order_id = $_GET['order_id'] ?? 0;
    $format = $_GET['format'] ?? 'pdf';
    
    if (!$order_id) {
        die('Не указан номер заказа');
    }
    
    $stmt = $db->prepare("
        SELECT o.*, 
               c.name as customer_name,
               c.contact_person,
               c.phone as customer_phone,
               c.email as customer_email,
               os.name as status_name
        FROM orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        LEFT JOIN order_statuses os ON o.status_id = os.id
        WHERE o.id = :id
    ");
    $stmt->bindValue(':id', $order_id, PDO::PARAM_INT);
    $stmt->execute();
    $order = $stmt->fetch();
    
    if (!$order) {
        die('Заказ не найден');
    }
    
    $stmt = $db->prepare("
        SELECT oi.*, 
               i.name as item_name, 
               i.item_code,
               i.unit_id,
               u.name as unit_name
        FROM order_items oi
        LEFT JOIN items i ON oi.item_id = i.id
        LEFT JOIN dictionaries u ON i.unit_id = u.id AND u.dict_type = 'unit'
        WHERE oi.order_id = :order_id
        ORDER BY oi.id
    ");
    $stmt->bindValue(':order_id', $order_id, PDO::PARAM_INT);
    $stmt->execute();
    $items = $stmt->fetchAll();
    
    $filename = 'zakaz_' . $order['order_number'] . '_' . date('Y-m-d_H-i') . '.' . ($format === 'excel' ? 'xlsx' : ($format === 'pdf' ? 'pdf' : 'csv'));
    
    if ($format === 'csv') {
        $rows = [];
        foreach ($items as $item) {
            $rows[] = [
                $item['item_name'] ?? '-',
                $item['item_code'] ?? '-',
                number_format($item['quantity'], 2, ',', ' '),
                $item['unit_name'] ?? '-',
                number_format($item['price'], 2, ',', ' '),
                number_format($item['total'], 2, ',', ' ')
            ];
        }
        exportSimpleCSV([
            ['Заказ №', $order['order_number']],
            ['Дата', date('d.m.Y', strtotime($order['created_at']))],
            ['Клиент', $order['customer_name'] ?? '-'],
            ['Статус', $order['status_name'] ?? '-'],
            [],
            ['Наименование', 'Артикул', 'Кол-во', 'Ед.', 'Цена', 'Сумма']
        ], $filename);
        
        // Добавляем товары
        $output = fopen('php://temp', 'r+');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        foreach ($rows as $row) {
            fputcsv($output, $row);
        }
        rewind($output);
        $itemsCSV = stream_get_contents($output);
        fclose($output);
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "Заказ №\t" . $order['order_number'] . "\n";
        echo "Дата\t" . date('d.m.Y', strtotime($order['created_at'])) . "\n";
        echo "Клиент\t" . ($order['customer_name'] ?? '-') . "\n";
        echo "Статус\t" . ($order['status_name'] ?? '-') . "\n\n";
        echo $itemsCSV;
        exit;
    } elseif ($format === 'excel') {
        exportExcel($items, [
            'Наименование' => 'item_name',
            'Артикул' => 'item_code',
            'Кол-во' => function($row) { return (float)$row['quantity']; },
            'Ед.' => 'unit_name',
            'Цена' => function($row) { return (float)$row['price']; },
            'Сумма' => function($row) { return (float)$row['total']; }
        ], $filename, 'Заказ №' . $order['order_number']);
    } elseif ($format === 'pdf') {
        exportOrderPDF($order, $items, $filename);
    }
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
    fputcsv($output, $headers);
    
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
        fputcsv($output, $csvRow);
    }
    
    fclose($output);
    exit;
}

/**
 * Экспорт простого CSV
 */
function exportSimpleCSV($rows, $filename) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    foreach ($rows as $row) {
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit;
}

/**
 * Экспорт в Excel (XLSX) с использованием SimpleXLSXGen
 */
function exportExcel($data, $columns, $filename, $sheetName = 'Данные') {
    // Создаем простой XLSX файл вручную (без внешних библиотек)
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Для простоты используем HTML таблицу с MIME типом Excel
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta charset="UTF-8"></head><body>';
    echo '<table border="1">';
    
    // Заголовки
    echo '<tr style="background-color: #4CAF50; color: white; font-weight: bold;">';
    foreach (array_keys($columns) as $header) {
        echo '<th>' . htmlspecialchars($header) . '</th>';
    }
    echo '</tr>';
    
    // Данные
    foreach ($data as $row) {
        echo '<tr>';
        foreach ($columns as $key => $field) {
            $value = is_callable($field) ? $field($row) : ($row[$field] ?? '');
            echo '<td>' . htmlspecialchars((string)$value) . '</td>';
        }
        echo '</tr>';
    }
    
    echo '</table></body></html>';
    exit;
}

/**
 * Экспорт в PDF
 */
function exportPDF($data, $columns, $filename, $title = 'Экспорт данных') {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Генерируем HTML для PDF
    echo generatePDFHTML($data, $columns, $title);
    exit;
}

/**
 * Экспорт заказа в PDF
 */
function exportOrderPDF($order, $items, $filename) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $html = '<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; }
        .header { text-align: center; margin-bottom: 20px; }
        .title { font-size: 18px; font-weight: bold; color: #333; }
        .info-table { width: 100%; margin-bottom: 20px; border-collapse: collapse; }
        .info-table td { padding: 5px; border-bottom: 1px solid #ddd; }
        .info-label { font-weight: bold; width: 150px; }
        .items-table { width: 100%; border-collapse: collapse; }
        .items-table th { background-color: #4CAF50; color: white; padding: 8px; text-align: left; }
        .items-table td { padding: 6px; border-bottom: 1px solid #ddd; }
        .total { text-align: right; font-weight: bold; margin-top: 15px; font-size: 14px; }
        .footer { margin-top: 30px; text-align: center; font-size: 10px; color: #666; }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">ЗАКАЗ № ' . htmlspecialchars($order['order_number']) . '</div>
        <div>от ' . date('d.m.Y', strtotime($order['created_at'])) . '</div>
    </div>
    
    <table class="info-table">
        <tr>
            <td class="info-label">Клиент:</td>
            <td>' . htmlspecialchars($order['customer_name'] ?? '-') . '</td>
            <td class="info-label">Контактное лицо:</td>
            <td>' . htmlspecialchars($order['contact_person'] ?? '-') . '</td>
        </tr>
        <tr>
            <td class="info-label">Телефон:</td>
            <td>' . htmlspecialchars($order['customer_phone'] ?? '-') . '</td>
            <td class="info-label">Email:</td>
            <td>' . htmlspecialchars($order['customer_email'] ?? '-') . '</td>
        </tr>
        <tr>
            <td class="info-label">Статус:</td>
            <td>' . htmlspecialchars($order['status_name'] ?? '-') . '</td>
            <td class="info-label">Дата доставки:</td>
            <td>' . ($order['delivery_date'] ? date('d.m.Y', strtotime($order['delivery_date'])) : '-') . '</td>
        </tr>
    </table>
    
    <table class="items-table">
        <thead>
            <tr>
                <th>№</th>
                <th>Наименование</th>
                <th>Артикул</th>
                <th>Кол-во</th>
                <th>Ед.</th>
                <th>Цена</th>
                <th>Сумма</th>
            </tr>
        </thead>
        <tbody>';
    
    $total = 0;
    $num = 1;
    foreach ($items as $item) {
        $html .= '<tr>
            <td>' . $num++ . '</td>
            <td>' . htmlspecialchars($item['item_name'] ?? '-') . '</td>
            <td>' . htmlspecialchars($item['item_code'] ?? '-') . '</td>
            <td>' . number_format($item['quantity'], 2, ',', ' ') . '</td>
            <td>' . htmlspecialchars($item['unit_name'] ?? '-') . '</td>
            <td>' . number_format($item['price'], 2, ',', ' ') . '</td>
            <td>' . number_format($item['total'], 2, ',', ' ') . '</td>
        </tr>';
        $total += $item['total'];
    }
    
    $html .= '</tbody>
    </table>
    
    <div class="total">Итого: ' . number_format($total, 2, ',', ' ') . ' руб.</div>
    
    <div class="footer">
        Документ сгенерирован автоматически системой PolesieMES<br>
        ' . date('d.m.Y H:i:s') . '
    </div>
</body>
</html>';
    
    echo $html;
    exit;
}

/**
 * Генерация HTML для PDF
 */
function generatePDFHTML($data, $columns, $title) {
    $html = '<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; }
        .title { font-size: 16px; font-weight: bold; margin-bottom: 15px; color: #333; }
        table { width: 100%; border-collapse: collapse; }
        th { background-color: #4CAF50; color: white; padding: 8px; text-align: left; }
        td { padding: 6px; border-bottom: 1px solid #ddd; }
        tr:nth-child(even) { background-color: #f9f9f9; }
        .footer { margin-top: 20px; text-align: center; font-size: 9px; color: #666; }
    </style>
</head>
<body>
    <div class="title">' . htmlspecialchars($title) . '</div>
    <table>
        <thead>
            <tr>';
    
    foreach (array_keys($columns) as $header) {
        $html .= '<th>' . htmlspecialchars($header) . '</th>';
    }
    
    $html .= '</tr>
        </thead>
        <tbody>';
    
    foreach ($data as $row) {
        $html .= '<tr>';
        foreach ($columns as $key => $field) {
            $value = is_callable($field) ? $field($row) : ($row[$field] ?? '');
            $html .= '<td>' . htmlspecialchars((string)$value) . '</td>';
        }
        $html .= '</tr>';
    }
    
    $html .= '</tbody>
    </table>
    <div class="footer">
        Сгенерировано системой PolesieMES • ' . date('d.m.Y H:i:s') . '
    </div>
</body>
</html>';
    
    return $html;
}

// Если ничего не подошло
die('Неверные параметры экспорта');
