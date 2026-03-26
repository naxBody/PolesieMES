<?php
/**
 * Модуль отгрузки - Завершение отгрузки
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireAuth();

$db = getDB();
$user = getCurrentUser();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    header('Location: index.php');
    exit;
}

// Получение заказа
$stmt = $db->prepare("SELECT * FROM orders WHERE id = ?");
$stmt->execute([$id]);
$order = $stmt->fetch();

if (!$order) {
    header('Location: index.php');
    exit;
}

// Обновление статуса на completed
$stmt = $db->prepare("UPDATE orders SET status = 'completed', updated_at = NOW() WHERE id = ?");
$stmt->execute([$id]);

header('Location: index.php?success=1');
exit;
