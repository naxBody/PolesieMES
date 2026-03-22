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
    FROM equipment
");
$stats['equipment'] = $stmt->fetch();

// Последние заказы
$stmt = $db->query("
    SELECT o.*, c.name as customer_name
    FROM orders o
    LEFT JOIN customers c ON o.customer_id = c.id
    ORDER BY o.created_at DESC
    LIMIT 5
");
$recentOrders = $stmt->fetchAll();

// Производственные задания требующие внимания
$stmt = $db->query("
    SELECT pt.*, p.name as product_name, ps.name as stage_name, e.first_name, e.last_name
    FROM production_tasks pt
    LEFT JOIN products p ON pt.product_id = p.id
    LEFT JOIN production_stages ps ON pt.stage_id = ps.id
    LEFT JOIN employees e ON pt.assigned_to = e.id
    WHERE pt.status IN ('in_progress', 'paused')
    ORDER BY pt.planned_end ASC
    LIMIT 5
");
$activeTasks = $stmt->fetchAll();

// Проблемы с материалами (ниже минимального запаса)
$stmt = $db->query("
    SELECT * FROM materials
    WHERE current_stock < min_stock
    ORDER BY (min_stock - current_stock) DESC
    LIMIT 5
");
$lowStockMaterials = $stmt->fetchAll();

// Активные пользователи онлайн
$stmt = $db->query("
    SELECT COUNT(DISTINCT user_id) as online_users 
    FROM sessions 
    WHERE last_activity > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
");
$onlineUsers = $stmt->fetch()['online_users'] ?? 0;

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
            padding: 1rem 2rem;
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
            padding: 0.75rem 2rem;
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
            width: 48px;
            height: 48px;
            background: var(--gradient-primary);
            border-radius: 14px;
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
            width: 28px;
            height: 28px;
            fill: white;
        }
        
        .brand-name {
            font-size: 1.4rem;
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
            gap: 2rem;
            list-style: none;
        }
        
        .nav-link {
            color: var(--text-secondary);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            position: relative;
            padding: 0.5rem 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
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
            width: 40px;
            height: 40px;
            background: var(--gradient-primary);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--glow-primary);
        }
        
        .user-avatar svg {
            width: 22px;
            height: 22px;
            fill: white;
        }
        
        .user-info {
            display: flex;
            flex-direction: column;
        }
        
        .user-name {
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--text-primary);
        }
        
        .user-role {
            font-size: 0.75rem;
            color: var(--text-secondary);
            background: var(--glass-bg);
            padding: 0.2rem 0.6rem;
            border-radius: 6px;
            border: 1px solid var(--border);
        }
        
        .btn-logout {
            padding: 0.6rem 1.25rem;
            background: var(--glass-bg);
            border: 1px solid var(--border);
            border-radius: 12px;
            font-size: 0.875rem;
            font-weight: 600;
            font-family: inherit;
            color: var(--text-primary);
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
            display: flex;
            align-items: center;
            gap: 0.5rem;
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
            padding: 6rem 2rem 2rem;
            max-width: 1400px;
            margin: 0 auto;
            position: relative;
            z-index: 10;
        }
        
        .page-header {
            margin-bottom: 2.5rem;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            flex-wrap: wrap;
            gap: 1.5rem;
        }
        
        .welcome-section h1 {
            font-size: clamp(1.75rem, 3vw, 2.5rem);
            font-weight: 800;
            background: linear-gradient(135deg, #ffffff 0%, rgba(255, 255, 255, 0.7) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 0.5rem;
        }
        
        .welcome-section p {
            color: var(--text-secondary);
            font-size: 1rem;
        }
        
        .quick-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        
        .btn-quick {
            padding: 0.75rem 1.25rem;
            background: var(--glass-bg);
            border: 1px solid var(--border);
            border-radius: 12px;
            color: var(--text-primary);
            font-weight: 600;
            font-size: 0.875rem;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            backdrop-filter: blur(10px);
        }
        
        .btn-quick:hover {
            background: rgba(255, 107, 107, 0.15);
            border-color: var(--border-glow);
            transform: translateY(-2px);
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
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2.5rem;
        }
        
        .stat-card {
            background: var(--bg-card);
            backdrop-filter: var(--backdrop-blur);
            -webkit-backdrop-filter: var(--backdrop-blur);
            border-radius: 20px;
            border: 1px solid var(--border);
            padding: 1.5rem;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            border-color: var(--border-glow);
            box-shadow: var(--shadow-lg), 0 0 30px rgba(255, 107, 107, 0.1);
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 150px;
            height: 150px;
            background: radial-gradient(circle, rgba(255, 107, 107, 0.1) 0%, transparent 60%);
            border-radius: 0 20px 0 100%;
        }
        
        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            background: var(--gradient-primary);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--glow-primary);
        }
        
        .stat-icon svg, .stat-icon i {
            width: 26px;
            height: 26px;
            fill: white;
        }
        
        .stat-icon i {
            font-size: 1.5rem;
        }
        
        .stat-trend {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.6rem;
            border-radius: 8px;
            font-size: 0.75rem;
            font-weight: 600;
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
            font-size: 2.5rem;
            font-weight: 800;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            line-height: 1.2;
        }
        
        .stat-label {
            color: var(--text-secondary);
            font-size: 0.875rem;
            font-weight: 500;
            margin-top: 0.25rem;
        }
        
        .stat-details {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border);
        }
        
        .stat-detail-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8rem;
            color: var(--text-muted);
        }
        
        .stat-detail-item i {
            font-size: 0.7rem;
        }
        
        .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }
        
        .dot.new { background: #5ac8fa; }
        .dot.production { background: #ffd60a; }
        .dot.completed { background: #30d158; }
        .dot.danger { background: #ff453a; }
        
        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
        }
        
        .card {
            background: var(--bg-card);
            backdrop-filter: var(--backdrop-blur);
            -webkit-backdrop-filter: var(--backdrop-blur);
            border-radius: 20px;
            border: 1px solid var(--border);
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        .card:hover {
            border-color: var(--border-glow);
            box-shadow: var(--shadow-lg);
        }
        
        .card-header {
            padding: 1.25rem 1.5rem;
            background: rgba(255, 255, 255, 0.02);
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-title {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-weight: 700;
            font-size: 1rem;
            color: var(--text-primary);
        }
        
        .card-title i {
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
            font-size: 0.9rem;
            color: var(--text-primary);
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
            font-weight: 600;
        }
        
        .badge.new { background: rgba(90, 200, 250, 0.15); color: #5ac8fa; border: 1px solid rgba(90, 200, 250, 0.3); }
        .badge.production { background: rgba(255, 214, 10, 0.15); color: #ffd60a; border: 1px solid rgba(255, 214, 10, 0.3); }
        .badge.completed { background: rgba(48, 209, 88, 0.15); color: #30d158; border: 1px solid rgba(48, 209, 88, 0.3); }
        .badge.danger { background: rgba(255, 69, 58, 0.15); color: #ff453a; border: 1px solid rgba(255, 69, 58, 0.3); }
        .badge.warning { background: rgba(255, 159, 10, 0.15); color: #ff9f0a; border: 1px solid rgba(255, 159, 10, 0.3); }
        
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
            
            <?php if (hasRole(['admin', 'manager'])): ?>
            <li>
                <a href="<?= APP_URL ?>/modules/orders/index.php" class="nav-link">
                    <i class="fas fa-shopping-cart"></i>
                    Заказы
                </a>
            </li>
            <?php endif; ?>
            
            <?php if (hasRole(['admin', 'manager', 'technologist', 'operator'])): ?>
            <li>
                <a href="<?= APP_URL ?>/modules/production/index.php" class="nav-link">
                    <i class="fas fa-cogs"></i>
                    Производство
                </a>
            </li>
            <?php endif; ?>
            
            <?php if (hasRole('admin')): ?>
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
        .btn {
            font-weight: 600;
            padding: 0.5rem 1.25rem;
            border-radius: 10px;
            transition: all 0.3s ease;
            border: none;
        }
        
        .btn-outline-primary {
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
        }
        
        .btn-outline-primary:hover {
            background: var(--gradient-primary);
            border-color: transparent;
            color: white;
            transform: translateY(-2px);
            box-shadow: var(--glow-primary);
        }
        
        /* List Groups */
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
        
        /* Alerts */
        .alert {
            border: none;
            border-radius: 12px;
            padding: 1rem 1.25rem;
        }
        
        /* User dropdown */
        .user-avatar {
            width: 32px;
            height: 32px;
            background: var(--gradient-primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 0.5rem;
            box-shadow: var(--glow-primary);
        }
        
        .user-avatar svg {
            width: 18px;
            height: 18px;
            fill: white;
        }
        
        /* Progress bars */
        .progress {
            background: rgba(48, 54, 61, 0.5);
            border-radius: 10px;
            height: 8px;
        }
        
        .progress-bar {
            background: var(--gradient-primary);
            border-radius: 10px;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }
            
            .stat-value {
                font-size: 1.75rem;
            }
            
            .navbar-collapse {
                background: var(--bg-card);
                padding: 1rem;
                border-radius: 12px;
                margin-top: 1rem;
                border: 1px solid var(--border);
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <!-- Background elements -->
    <div class="grid-bg"></div>
    <div class="glow-overlay"></div>
    
    <!-- Навигация -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center" href="<?= APP_URL ?>">
                <div class="brand-logo">
                    <svg viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                </div>
                <span>PolesieMES</span>
            </a>
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link <?= ($currentPage ?? '') == 'dashboard' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/dashboard/index.php">
                            <i class="fas fa-chart-line me-1"></i>
                            Главная
                        </a>
                    </li>
                    
                    <?php if (hasRole(['admin', 'manager'])): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-shopping-cart me-1"></i>
                            Заказы
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="<?= APP_URL ?>/modules/orders/index.php"><i class="fas fa-list me-2"></i>Все заказы</a></li>
                            <li><a class="dropdown-item" href="<?= APP_URL ?>/modules/orders/create.php"><i class="fas fa-plus me-2"></i>Создать заказ</a></li>
                        </ul>
                    </li>
                    <?php endif; ?>
                    
                    <?php if (hasRole(['admin', 'manager', 'technologist', 'operator'])): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= APP_URL ?>/modules/production/index.php">
                            <i class="fas fa-cogs me-1"></i>
                            Производство
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <?php if (hasRole('admin')): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= APP_URL ?>/modules/employees/index.php">
                            <i class="fas fa-users me-1"></i>
                            Сотрудники
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
                
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" role="button" data-bs-toggle="dropdown">
                            <div class="user-avatar">
                                <svg viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                            </div>
                            <span><?= e($_SESSION['full_name']) ?></span>
                            <span class="badge bg-secondary ms-2"><?= e(getRoleName($_SESSION['role'])) ?></span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="<?= APP_URL ?>/modules/auth/profile.php"><i class="fas fa-user me-2"></i>Профиль</a></li>
                            <li><hr class="dropdown-divider" style="border-color: var(--border);"></li>
                            <li><a class="dropdown-item" href="<?= APP_URL ?>/modules/auth/logout.php"><i class="fas fa-sign-out-alt me-2"></i>Выход</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    
    <!-- Основной контент -->
    <div class="main-content">
        <div class="page-header">
            <h1 class="page-title">Добро пожаловать, <?= e($userFirstName) ?>!</h1>
            <p class="page-subtitle">Обзор производства на <?= date('d.m.Y') ?></p>
        </div>
        
        <!-- Статистические карточки -->
        <div class="row g-4 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card">
                    <div class="card-body">
                        <div class="stat-icon"><i class="fas fa-shopping-cart"></i></div>
                        <div class="stat-value"><?= $stats['orders']['total'] ?? 0 ?></div>
                        <div class="stat-label">Всего заказов</div>
                        <div class="stat-details">
                            <span class="stat-badge new"><i class="fas fa-plus-circle me-1"></i>Новые: <?= $stats['orders']['new_orders'] ?? 0 ?></span>
                            <span class="stat-badge production"><i class="fas fa-cog me-1"></i>В пр-ве: <?= $stats['orders']['production_orders'] ?? 0 ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card">
                    <div class="card-body">
                        <div class="stat-icon"><i class="fas fa-ruble-sign"></i></div>
                        <div class="stat-value"><?= formatCurrency($stats['revenue']) ?></div>
                        <div class="stat-label">Выручка за месяц</div>
                        <div class="stat-details">
                            <span class="stat-badge completed"><i class="fas fa-check-circle me-1"></i>Завершено</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card">
                    <div class="card-body">
                        <div class="stat-icon"><i class="fas fa-tasks"></i></div>
                        <div class="stat-value"><?= $stats['tasks']['total_tasks'] ?? 0 ?></div>
                        <div class="stat-label">Заданий</div>
                        <div class="stat-details">
                            <span class="stat-badge production"><i class="fas fa-play me-1"></i>В работе: <?= $stats['tasks']['in_progress'] ?? 0 ?></span>
                            <span class="stat-badge new"><i class="fas fa-clock me-1"></i>План: <?= $stats['tasks']['planned'] ?? 0 ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card">
                    <div class="card-body">
                        <div class="stat-icon"><i class="fas fa-industry"></i></div>
                        <div class="stat-value"><?= $stats['equipment']['operational'] ?? 0 ?>/<?= $stats['equipment']['total_equipment'] ?? 0 ?></div>
                        <div class="stat-label">Оборудование</div>
                        <div class="stat-details">
                            <?php if (($stats['equipment']['broken'] ?? 0) > 0): ?>
                            <span class="stat-badge danger"><i class="fas fa-exclamation-triangle me-1"></i><?= $stats['equipment']['broken'] ?></span>
                            <?php else: ?>
                            <span class="stat-badge completed"><i class="fas fa-check-circle me-1"></i>Все работает</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Основной контент -->
        <div class="row g-4">
            <div class="col-lg-8">
                <div class="card h-100">
                    <div class="card-header">
                        <span><i class="fas fa-shopping-cart me-2"></i>Последние заказы</span>
                        <a href="<?= APP_URL ?>/modules/orders/index.php" class="btn btn-sm btn-outline-primary">Все заказы</a>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>№ заказа</th>
                                        <th>Клиент</th>
                                        <th>Сумма</th>
                                        <th>Статус</th>
                                        <th>Дата</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentOrders as $order): ?>
                                    <tr>
                                        <td>
                                            <a href="<?= APP_URL ?>/modules/orders/view.php?id=<?= $order['id'] ?>" class="text-decoration-none fw-semibold">
                                                <?= e($order['order_number']) ?>
                                            </a>
                                        </td>
                                        <td><?= e($order['customer_name']) ?></td>
                                        <td><?= formatCurrency($order['total_amount']) ?></td>
                                        <td>
                                            <span class="badge <?= getOrderStatusClass($order['status']) ?>">
                                                <?= getOrderStatusName($order['status']) ?>
                                            </span>
                                        </td>
                                        <td><?= formatDate($order['order_date']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-4">
                <div class="card mb-4">
                    <div class="card-header">
                        <i class="fas fa-exclamation-triangle me-2"></i>Требуют внимания
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <?php foreach ($activeTasks as $task): ?>
                            <div class="list-group-item">
                                <div class="d-flex w-100 justify-content-between">
                                    <h6 class="mb-1 small fw-semibold"><?= e($task['task_number']) ?></h6>
                                    <small class="text-muted"><?= formatDate($task['planned_end']) ?></small>
                                </div>
                                <p class="mb-1 small text-muted"><?= e($task['product_name']) ?></p>
                                <small class="text-primary"><?= e($task['stage_name']) ?></small>
                            </div>
                            <?php endforeach; ?>
                            <?php if (empty($activeTasks)): ?>
                            <div class="p-3 text-center text-muted">
                                <i class="fas fa-check-circle fa-2x mb-2"></i>
                                <p class="mb-0">Все задания в норме</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header text-danger">
                        <i class="fas fa-triangle-exclamation me-2"></i>Заканчиваются материалы
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <?php foreach ($lowStockMaterials as $material): ?>
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between align-items-center">
                                    <small><?= e($material['name']) ?></small>
                                    <span class="badge bg-danger"><?= $material['current_stock'] ?> / <?= $material['min_stock'] ?></span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php if (empty($lowStockMaterials)): ?>
                            <div class="p-3 text-center text-muted">
                                <i class="fas fa-check-circle fa-2x mb-2"></i>
                                <p class="mb-0 small">Запасы в норме</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
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
    </script>
    
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
