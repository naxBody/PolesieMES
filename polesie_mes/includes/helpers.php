<?php
/**
 * Общие вспомогательные функции
 */

/**
 * Форматирование даты
 */
function formatDate($date, $format = DATE_FORMAT) {
    if (empty($date)) {
        return '';
    }
    
    if (is_string($date)) {
        $date = strtotime($date);
    }
    
    return date($format, $date);
}

/**
 * Форматирование даты и времени
 */
function formatDateTime($datetime, $format = DATETIME_FORMAT) {
    if (empty($datetime)) {
        return '';
    }
    
    if (is_string($datetime)) {
        $datetime = strtotime($datetime);
    }
    
    return date($format, $datetime);
}

/**
 * Форматирование числа
 */
function formatNumber($number, $decimals = 2) {
    return number_format($number, $decimals, ',', ' ');
}

/**
 * Форматирование валюты
 */
function formatCurrency($amount, $currency = CURRENCY) {
    $currencies = [
        'BYN' => 'Br',
        'USD' => '$',
        'EUR' => '€',
        'RUB' => '₽'
    ];
    
    $symbol = $currencies[$currency] ?? $currency;
    return formatNumber($amount, 2) . ' ' . $symbol;
}

/**
 * Получение названия статуса заказа
 */
function getOrderStatusName($status) {
    $statuses = [
        'new' => 'Новый',
        'confirmed' => 'Подтвержден',
        'in_production' => 'В производстве',
        'quality_check' => 'Контроль качества',
        'ready' => 'Готов к отгрузке',
        'shipped' => 'Отгружен',
        'completed' => 'Завершен',
        'cancelled' => 'Отменен'
    ];
    
    return $statuses[$status] ?? $status;
}

/**
 * Получение класса для статуса заказа
 */
function getOrderStatusClass($status) {
    $classes = [
        'new' => 'badge-info',
        'confirmed' => 'badge-primary',
        'in_production' => 'badge-warning',
        'quality_check' => 'badge-secondary',
        'ready' => 'badge-success',
        'shipped' => 'badge-info',
        'completed' => 'badge-success',
        'cancelled' => 'badge-danger'
    ];
    
    return $classes[$status] ?? 'badge-secondary';
}

/**
 * Получение названия приоритета заказа
 */
function getPriorityName($priority) {
    $priorities = [
        'low' => 'Низкий',
        'normal' => 'Обычный',
        'high' => 'Высокий',
        'urgent' => 'Срочный'
    ];
    
    return $priorities[$priority] ?? $priority;
}

/**
 * Получение класса для приоритета
 */
function getPriorityClass($priority) {
    $classes = [
        'low' => 'text-muted',
        'normal' => 'text-primary',
        'high' => 'text-warning',
        'urgent' => 'text-danger'
    ];
    
    return $classes[$priority] ?? '';
}

/**
 * Получение названия роли
 */
function getRoleName($role) {
    $roles = [
        'admin' => 'Администратор',
        'director' => 'Директор',
        'manager' => 'Менеджер',
        'technologist' => 'Технолог',
        'operator' => 'Оператор',
        'quality_inspector' => 'Инспектор ОТК',
        'warehouse_keeper' => 'Кладовщик'
    ];
    
    return $roles[$role] ?? $role;
}

/**
 * Безопасный вывод данных
 */
function e($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Генерация уникального номера
 */
function generateUniqueNumber($prefix, $table, $column) {
    $db = getDB();
    
    $year = date('Y');
    $pattern = $prefix . '-' . $year . '-%';
    
    $stmt = $db->prepare("
        SELECT MAX(CAST(SUBSTRING_INDEX({$column}, '-', -1) AS UNSIGNED)) as max_num
        FROM {$table}
        WHERE {$column} LIKE :pattern
    ");
    
    $stmt->execute(['pattern' => $pattern]);
    $result = $stmt->fetch();
    
    $nextNum = ($result['max_num'] ?? 0) + 1;
    
    return $prefix . '-' . $year . '-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
}

/**
 * Перенаправление с сообщением
 */
function redirectWithMessage($url, $message, $type = 'success') {
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
    header('Location: ' . $url);
    exit;
}

/**
 * Получение и очистка flash-сообщения
 */
function getFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        $type = $_SESSION['flash_type'] ?? 'info';
        unset($_SESSION['flash_message'], $_SESSION['flash_type']);
        return ['message' => $message, 'type' => $type];
    }
    return null;
}

/**
 * Проверка на AJAX запрос
 */
function isAjaxRequest() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
}

/**
 * JSON ответ
 */
function jsonResponse($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Валидация email
 */
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Валидация телефона (белорусский формат)
 */
function isValidPhoneBY($phone) {
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    return preg_match('/^(\+375|375)?(25|29|33|44)[0-9]{7}$/', $phone);
}

/**
 * Очистка строки от XSS
 */
function cleanInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

/**
 * Расчет разницы дат в днях
 */
function dateDiffInDays($date1, $date2) {
    $datetime1 = new DateTime($date1);
    $datetime2 = new DateTime($date2);
    $interval = $datetime1->diff($datetime2);
    return $interval->days;
}

/**
 * Проверка просрочки даты
 */
function isOverdue($date) {
    return strtotime($date) < time();
}

/**
 * Получение возраста по дате рождения
 */
function getAge($birthDate) {
    $birth = new DateTime($birthDate);
    $today = new DateTime('today');
    return $birth->diff($today)->y;
}
