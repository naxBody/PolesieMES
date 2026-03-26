<?php
/**
 * Функции аутентификации и авторизации
 * Обновленная версия для работы с таблицей staff
 */

// Запуск сессии только если она еще не запущена
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Проверка учетных данных пользователя
 */
function authenticate($username, $password) {
    $db = getDB();
    
    // Запрос к объединенной таблице staff
    $stmt = $db->prepare("
        SELECT id, employee_code, username, password, first_name, last_name, middle_name, 
               position_id, email, phone, department, role, status, is_active, last_login
        FROM staff
        WHERE username = :username AND is_active = 1
    ");
    
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch();
    
    if ($user && $user['password'] === $password) {
        // Обновление времени последнего входа
        $updateStmt = $db->prepare("UPDATE staff SET last_login = NOW() WHERE id = :id");
        $updateStmt->execute(['id' => $user['id']]);
        
        // Логирование входа
        logActivity($user['id'], 'login', 'auth', null, 'Вход в систему');
        
        return $user;
    }
    
    return false;
}

/**
 * Вход пользователя в систему
 */
function login($username, $password) {
    $user = authenticate($username, $password);
    
    if ($user) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['employee_code'] = $user['employee_code'];
        $_SESSION['full_name'] = trim($user['last_name'] . ' ' . $user['first_name'] . ' ' . $user['middle_name']);
        $_SESSION['logged_in'] = true;
        $_SESSION['login_time'] = time();
        
        return true;
    }
    
    return false;
}

/**
 * Выход из системы
 */
function logout() {
    if (isset($_SESSION['user_id'])) {
        logActivity($_SESSION['user_id'], 'logout', 'auth', null, 'Выход из системы');
    }
    
    session_unset();
    session_destroy();
    
    header('Location: ' . APP_URL . '/index.php');
    exit;
}

/**
 * Проверка авторизации
 */
function isLoggedIn() {
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        return false;
    }
    
    // Проверка времени сессии
    if (time() - $_SESSION['login_time'] > SESSION_LIFETIME) {
        logout();
        return false;
    }
    
    // Продление сессии
    $_SESSION['login_time'] = time();
    
    return true;
}

/**
 * Проверка роли пользователя
 */
function hasRole($roles) {
    if (!isLoggedIn()) {
        return false;
    }
    
    if (is_string($roles)) {
        $roles = [$roles];
    }
    
    // Администратор имеет доступ ко всему
    if ($_SESSION['role'] === ROLE_ADMIN) {
        return true;
    }
    
    return in_array($_SESSION['role'], $roles);
}

/**
 * Проверка прав доступа к модулю
 */
function checkAccess($requiredRole = null) {
    if (!isLoggedIn()) {
        header('Location: ' . APP_URL . '/modules/auth/login.php?error=auth_required');
        exit;
    }
    
    if ($requiredRole && !hasRole($requiredRole)) {
        header('Location: ' . APP_URL . '/modules/auth/access_denied.php');
        exit;
    }
}

/**
 * Получение текущего пользователя
 */
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    return [
        'id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'role' => $_SESSION['role'],
        'employee_code' => $_SESSION['employee_code'],
        'full_name' => $_SESSION['full_name']
    ];
}

/**
 * Логирование действий пользователя
 */
function logActivity($userId, $action, $module, $recordId = null, $description = null) {
    $db = getDB();
    
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    
    $stmt = $db->prepare("
        INSERT INTO journal (journal_type, user_id, action, module, record_id, description, ip_address)
        VALUES (:journal_type, :user_id, :action, :module, :record_id, :description, :ip_address)
    ");
    
    try {
        $stmt->execute([
            'journal_type' => 'activity',
            'user_id' => $userId,
            'action' => $action,
            'module' => $module,
            'record_id' => $recordId,
            'description' => $description,
            'ip_address' => $ipAddress
        ]);
    } catch (PDOException $e) {
        // Игнорируем ошибки логирования
        error_log("Ошибка логирования: " . $e->getMessage());
    }
}

/**
 * Перенаправление неавторизованных пользователей
 */
function requireAuth() {
    if (!isLoggedIn()) {
        header('Location: ' . APP_URL . '/index.php?error=auth_required');
        exit;
    }
}

/**
 * Получение полного имени пользователя
 */
function getUserFullName($userId) {
    $db = getDB();
    
    $stmt = $db->prepare("
        SELECT first_name, last_name, middle_name
        FROM staff
        WHERE id = :user_id
    ");
    
    $stmt->execute(['user_id' => $userId]);
    $user = $stmt->fetch();
    
    if ($user) {
        return trim($user['last_name'] . ' ' . $user['first_name'] . ' ' . $user['middle_name']);
    }
    
    return 'Неизвестный пользователь';
}
