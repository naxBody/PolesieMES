<?php
/**
 * Конфигурационный файл системы PolesieMES
 * ОАО "Полесьеэлектромаш"
 */

// Параметры подключения к базе данных (XAMPP)
define('DB_HOST', 'localhost');
define('DB_NAME', 'polesie_mes');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Настройки приложения
define('APP_NAME', 'PolesieMES');
define('APP_TITLE', 'Система управления производством | ОАО "Полесьеэлектромаш"');
define('APP_VERSION', '1.0.0');
define('APP_URL', 'http://localhost/polesie_mes');

// Настройки сессии
define('SESSION_LIFETIME', 3600); // 1 час

// Настройки безопасности
define('ALLOW_PLAIN_PASSWORD', true); // Для учебного проекта

// Пути
define('BASE_PATH', dirname(__DIR__));
define('MODULES_PATH', BASE_PATH . '/modules');
define('ASSETS_PATH', BASE_PATH . '/assets');
define('UPLOADS_PATH', BASE_PATH . '/uploads');

// Локаль
define('DEFAULT_LOCALE', 'ru_BY');
define('TIMEZONE', 'Europe/Minsk');
define('CURRENCY', 'BYN');

// Настройки отображения
define('DATE_FORMAT', 'd.m.Y');
define('DATETIME_FORMAT', 'd.m.Y H:i');
define('ITEMS_PER_PAGE', 20);

// Роли пользователей
define('ROLE_ADMIN', 'admin');
define('ROLE_MANAGER', 'manager');
define('ROLE_TECHNOLOGIST', 'technologist');
define('ROLE_OPERATOR', 'operator');
define('ROLE_QUALITY_INSPECTOR', 'quality_inspector');
define('ROLE_WAREHOUSE_KEEPER', 'warehouse_keeper');

// Статусы заказов
define('ORDER_STATUS_NEW', 'new');
define('ORDER_STATUS_CONFIRMED', 'confirmed');
define('ORDER_STATUS_IN_PRODUCTION', 'in_production');
define('ORDER_STATUS_QUALITY_CHECK', 'quality_check');
define('ORDER_STATUS_READY', 'ready');
define('ORDER_STATUS_SHIPPED', 'shipped');
define('ORDER_STATUS_COMPLETED', 'completed');
define('ORDER_STATUS_CANCELLED', 'cancelled');

// Приоритеты заказов
define('PRIORITY_LOW', 'low');
define('PRIORITY_NORMAL', 'normal');
define('PRIORITY_HIGH', 'high');
define('PRIORITY_URGENT', 'urgent');

// Ошибки
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Установка временной зоны
date_default_timezone_set(TIMEZONE);

// Установка локали
setlocale(LC_ALL, DEFAULT_LOCALE);
