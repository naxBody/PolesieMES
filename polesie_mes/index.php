<?php
/**
 * Главный файл системы PolesieMES
 * Точка входа в приложение
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth_functions.php';
require_once __DIR__ . '/includes/helpers.php';

// Если пользователь авторизован - перенаправляем на панель управления
if (isLoggedIn()) {
    header('Location: ' . APP_URL . '/modules/dashboard/index.php');
    exit;
}

// Иначе - на страницу входа
header('Location: ' . APP_URL . '/modules/auth/login.php');
exit;
