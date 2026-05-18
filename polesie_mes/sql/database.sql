-- PolesieMES - Система управления производством ОАО "Полесьеэлектромаш"
-- База данных для XAMPP/phpMyAdmin
-- Максимально оптимизированная версия: 10 таблиц с сохранением всего функционала

CREATE DATABASE IF NOT EXISTS polesie_mes CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE polesie_mes;

-- ==================== БАЗОВЫЕ СПРАВОЧНИКИ (3 таблицы) ====================

-- 1. ЕДИНЫЙ СПРАВОЧНИК (заменяет: units, categories, locations, positions)
CREATE TABLE dictionaries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dict_type ENUM('unit', 'category', 'location', 'position') NOT NULL,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(200) NOT NULL,
    parent_id INT NULL,
    extra_data JSON,
    description TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dict_type (dict_type),
    INDEX idx_code (code)
);

-- 2. СОТРУДНИКИ И ПОЛЬЗОВАТЕЛИ (объединяет: employees, users)
CREATE TABLE staff (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_code VARCHAR(20) UNIQUE NOT NULL,
    username VARCHAR(50) UNIQUE,
    password VARCHAR(255),
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    middle_name VARCHAR(50),
    position_id INT,
    email VARCHAR(100),
    phone VARCHAR(20),
    department VARCHAR(100),
    role ENUM('admin', 'director', 'manager', 'operator', 'warehouse_keeper') NOT NULL,
    hire_date DATE,
    status ENUM('active', 'vacation', 'sick', 'terminated') DEFAULT 'active',
    is_active BOOLEAN DEFAULT TRUE,
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (position_id) REFERENCES dictionaries(id)
);

-- 3. КОНТРАГЕНТЫ (клиенты и поставщики)
CREATE TABLE partners (
    id INT AUTO_INCREMENT PRIMARY KEY,
    partner_type ENUM('customer', 'supplier', 'both') DEFAULT 'customer',
    name VARCHAR(200) NOT NULL,
    inn VARCHAR(20),
    kpp VARCHAR(20),
    address TEXT,
    phone VARCHAR(20),
    email VARCHAR(100),
    contact_person VARCHAR(100),
    country VARCHAR(50) DEFAULT 'Беларусь',
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_partner_type (partner_type)
);

-- ==================== ПРОИЗВОДСТВО И ЗАКАЗЫ (4 таблицы) ====================

-- 4. НОМЕНКЛАТУРА (объединяет: products, materials, equipment)
CREATE TABLE items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_type ENUM('product', 'material', 'equipment') NOT NULL,
    item_code VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(200) NOT NULL,
    category_id INT,
    location_id INT,
    unit_id INT,
    current_stock DECIMAL(12,3) DEFAULT 0,
    min_stock INT DEFAULT 0,
    price DECIMAL(12,2),
    currency VARCHAR(3) DEFAULT 'BYN',
    supplier_id INT,
    status ENUM('operational', 'maintenance', 'broken', 'offline', 'active', 'inactive') DEFAULT 'active',
    extra_specs JSON,
    description TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES dictionaries(id),
    FOREIGN KEY (location_id) REFERENCES dictionaries(id),
    FOREIGN KEY (unit_id) REFERENCES dictionaries(id),
    FOREIGN KEY (supplier_id) REFERENCES partners(id),
    INDEX idx_item_type (item_type),
    INDEX idx_item_code (item_code)
);

-- 5. ЗАКАЗЫ (состав заказа в JSON для оптимизации)
CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(50) UNIQUE NOT NULL,
    customer_id INT NOT NULL,
    order_date DATE NOT NULL,
    delivery_date DATE,
    priority ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal',
    status ENUM('new', 'confirmed', 'in_production', 'quality_check', 'ready', 'shipped', 'completed', 'cancelled') DEFAULT 'new',
    items_json JSON,
    total_amount DECIMAL(15,2),
    currency VARCHAR(3) DEFAULT 'BYN',
    notes TEXT,
    manager_id INT,
    -- Поля для отгрузки
    tracking_number VARCHAR(100),
    carrier VARCHAR(200),
    driver_name VARCHAR(200),
    vehicle_number VARCHAR(50),
    shipped_at DATETIME,
    received_at DATETIME,
    completion_notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES partners(id),
    FOREIGN KEY (manager_id) REFERENCES staff(id),
    INDEX idx_order_number (order_number),
    INDEX idx_status (status)
);

-- 6. ПРОИЗВОДСТВЕННЫЕ ЗАДАНИЯ (включает этапы и контроль качества)
CREATE TABLE production_tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_number VARCHAR(50) UNIQUE NOT NULL,
    order_id INT,
    product_id INT,
    stage_name VARCHAR(100),
    stage_sequence INT,
    quantity INT,
    planned_start DATETIME,
    planned_end DATETIME,
    actual_start DATETIME,
    actual_end DATETIME,
    status ENUM('planned', 'in_progress', 'paused', 'completed', 'rejected') DEFAULT 'planned',
    assigned_to INT,
    work_center VARCHAR(100),
    qc_result ENUM('pending', 'passed', 'failed', 'conditional'),
    qc_inspector_id INT,
    qc_date DATETIME,
    qc_defects INT DEFAULT 0,
    qc_description TEXT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id),
    FOREIGN KEY (product_id) REFERENCES items(id),
    FOREIGN KEY (assigned_to) REFERENCES staff(id),
    FOREIGN KEY (qc_inspector_id) REFERENCES staff(id),
    INDEX idx_task_number (task_number),
    INDEX idx_status (status)
);

-- 7. ДВИЖЕНИЕ МАТЕРИАЛОВ (складские операции + отгрузки + ТО)
CREATE TABLE movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    movement_type ENUM('receipt', 'consumption', 'return', 'adjustment', 'shipment', 'maintenance') NOT NULL,
    item_id INT NOT NULL,
    quantity DECIMAL(12,3) NOT NULL,
    reference_type VARCHAR(50),
    reference_id INT,
    warehouse_from VARCHAR(100),
    warehouse_to VARCHAR(100),
    employee_id INT,
    partner_id INT,
    cost DECIMAL(12,2),
    notes TEXT,
    movement_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (item_id) REFERENCES items(id),
    FOREIGN KEY (employee_id) REFERENCES staff(id),
    FOREIGN KEY (partner_id) REFERENCES partners(id),
    INDEX idx_movement_type (movement_type),
    INDEX idx_item_id (item_id)
);

-- 7a. ЗАКАЗЫ ПОСТАВЩИКАМ (ожидаемые поставки для склада)
CREATE TABLE purchase_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(50) UNIQUE NOT NULL,
    supplier_id INT NOT NULL,
    order_date DATE NOT NULL,
    expected_delivery DATE,
    actual_delivery DATE,
    status ENUM('draft', 'sent', 'confirmed', 'partial', 'received', 'cancelled') DEFAULT 'draft',
    priority ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal',
    items_json JSON,
    total_amount DECIMAL(15,2),
    currency VARCHAR(3) DEFAULT 'BYN',
    notes TEXT,
    created_by INT,
    received_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES partners(id),
    FOREIGN KEY (created_by) REFERENCES staff(id),
    FOREIGN KEY (received_by) REFERENCES staff(id),
    INDEX idx_order_number (order_number),
    INDEX idx_status (status),
    INDEX idx_expected_delivery (expected_delivery)
);

-- ==================== ЖУРНАЛЫ И ЛОГИРОВАНИЕ (3 таблицы) ====================

-- 8. ЖУРНАЛ СОБЫТИЙ (activity_log + maintenance_logs)
CREATE TABLE journal (
    id INT AUTO_INCREMENT PRIMARY KEY,
    journal_type ENUM('activity', 'maintenance') NOT NULL,
    user_id INT,
    action VARCHAR(100),
    module VARCHAR(50),
    record_id INT,
    item_id INT,
    technician_id INT,
    maintenance_type ENUM('preventive', 'corrective', 'emergency', 'inspection'),
    scheduled_date DATE,
    completed_date DATE,
    maintenance_status ENUM('planned', 'in_progress', 'completed', 'cancelled'),
    cost DECIMAL(12,2),
    parts_used TEXT,
    description TEXT,
    ip_address VARCHAR(45),
    extra_data JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES staff(id),
    FOREIGN KEY (item_id) REFERENCES items(id),
    FOREIGN KEY (technician_id) REFERENCES staff(id),
    INDEX idx_journal_type (journal_type)
);

-- 8a. ЖУРНАЛ АКТИВНОСТИ (для совместимости с модулями)
CREATE TABLE activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    username VARCHAR(50),
    action VARCHAR(100),
    module VARCHAR(50),
    record_id INT,
    description TEXT,
    ip_address VARCHAR(45),
    extra_data JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES staff(id),
    INDEX idx_module (module),
    INDEX idx_record_id (record_id),
    INDEX idx_created_at (created_at)
);

-- 9. ТЕХНОЛОГИЧЕСКИЕ МАРШРУТЫ (production_stages как справочник)
CREATE TABLE tech_routes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT,
    stage_name VARCHAR(100) NOT NULL,
    stage_code VARCHAR(50),
    sequence_order INT,
    estimated_hours DECIMAL(8,2),
    work_center VARCHAR(100),
    description TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES items(id),
    INDEX idx_product_id (product_id)
);

-- ==================== НАСТРОЙКИ СИСТЕМЫ (1 таблица) ====================

-- 10. СИСТЕМНЫЕ НАСТРОЙКИ И ОТЧЁТЫ
CREATE TABLE system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    setting_type ENUM('string', 'number', 'boolean', 'json', 'report') DEFAULT 'string',
    module VARCHAR(50),
    description TEXT,
    updated_by INT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES staff(id)
);

-- ==================== ЗАПОЛНЕНИЕ ДАННЫМИ ====================

-- Единицы измерения
INSERT INTO dictionaries (dict_type, code, name) VALUES
('unit', 'PCS', 'Штука'),
('unit', 'KG', 'Килограмм'),
('unit', 'M', 'Метр'),
('unit', 'L', 'Литр'),
('unit', 'SET', 'Набор'),
('unit', 'PAIR', 'Пара');

-- Категории
INSERT INTO dictionaries (dict_type, code, name, description) VALUES
('category', 'METAL', 'Металлопрокат', 'Черные и цветные металлы'),
('category', 'ELECTRO', 'Электротехнические материалы', 'Провода, кабели, изоляция'),
('category', 'PAINT', 'Лакокрасочные материалы', 'Краски, эмали, растворители'),
('category', 'FASTENER', 'Крепеж', 'Болты, гайки, винты'),
('category', 'PACKAGE', 'Упаковочные материалы', 'Тара и упаковка'),
('category', 'RAW', 'Сырье', 'Основное сырье'),
('category', 'MOTOR', 'Электродвигатели', 'Асинхронные и синхронные двигатели'),
('category', 'GENERATOR', 'Генераторы', 'Электрогенераторы'),
('category', 'TRANSFORMER', 'Трансформаторы', 'Силовые трансформаторы'),
('category', 'PUMP', 'Насосное оборудование', 'Насосы и насосные агрегаты'),
('category', 'CONTROL', 'Устройства управления', 'Щиты управления и автоматики'),
('category', 'CABLE', 'Кабельная продукция', 'Силовые и контрольные кабели'),
('category', 'MACHINE', 'Станки', 'Металлообрабатывающие станки'),
('category', 'CRANE', 'Подъемное оборудование', 'Краны, тали, подъемники'),
('category', 'WELD', 'Сварочное оборудование', 'Сварочные аппараты'),
('category', 'MEASURE', 'Измерительные приборы', 'КИП'),
('category', 'TRANSPORT', 'Транспорт', 'Погрузчики, тележки');

-- Расположения
INSERT INTO dictionaries (dict_type, code, name) VALUES
('location', 'WH-A1', 'Склад А1 - Металлопрокат'),
('location', 'WH-B2', 'Склад Б2 - Электроматериалы'),
('location', 'WH-V1', 'Склад В1 - Лакокрасочные'),
('location', 'WH-G1', 'Склад Г1 - Крепеж'),
('location', 'SHOP-1', 'Цех №1'),
('location', 'SHOP-2', 'Цех №2');

-- Должности
INSERT INTO dictionaries (dict_type, code, name, description) VALUES
('position', 'ADMIN', 'Администратор', 'Полный доступ ко всем модулям'),
('position', 'DIRECTOR', 'Директор', 'Просмотр всей информации о предприятии'),
('position', 'MANAGER', 'Менеджер', 'Управление заказами, клиентами, производством'),
('position', 'OPERATOR', 'Оператор', 'Работа с производственными заданиями'),
('position', 'STOREKEEPER', 'Кладовщик', 'Учет материалов на складе');

-- Сотрудники и пользователи (9 человек, 5 ролей)
INSERT INTO staff (employee_code, username, password, first_name, last_name, middle_name, position_id, email, phone, department, role, hire_date, status) VALUES
('EMP000', 'director', 'director123', 'Николай', 'Петров', 'Васильевич', 2, 'director@polesie.by', '+375290000000', 'Руководство', 'director', '2015-01-10', 'active'),
('EMP001', 'admin', 'admin123', 'Александр', 'Иванов', 'Петрович', 1, 'admin@polesie.by', '+375291111111', 'Администрация', 'admin', '2018-01-15', 'active'),
('EMP002', 'manager1', 'manager123', 'Елена', 'Смирнова', 'Владимировна', 2, 'manager@polesie.by', '+375292222222', 'Производство', 'manager', '2018-03-20', 'active'),
('EMP003', 'manager2', 'sales123', 'Дмитрий', 'Козлов', 'Андреевич', 2, 'sales@polesie.by', '+375293333333', 'Отдел продаж', 'manager', '2019-06-10', 'active'),
('EMP004', 'manager3', 'tech2024', 'Сергей', 'Федоров', 'Игоревич', 2, 'tech@polesie.by', '+375295555555', 'Технический отдел', 'manager', '2019-09-01', 'active'),
('EMP005', 'operator1', 'oper123', 'Андрей', 'Волков', 'Николаевич', 3, 'operator1@polesie.by', '+375297777777', 'Цех №1', 'operator', '2021-01-10', 'active'),
('EMP006', 'operator2', 'oper456', 'Ирина', 'Лебедева', 'Алексеевна', 3, 'operator2@polesie.by', '+375298888888', 'Цех №1', 'operator', '2021-03-15', 'active'),
('EMP007', 'warehouse1', 'store123', 'Виктор', 'Григорьев', 'Сергеевич', 4, 'store1@polesie.by', '+375291212121', 'Склад', 'warehouse_keeper', '2019-04-10', 'active'),
('EMP008', 'warehouse2', 'store456', 'Екатерина', 'Васильева', 'Андреевна', 4, 'store2@polesie.by', '+375291313131', 'Склад', 'warehouse_keeper', '2020-08-15', 'active');

-- Клиенты и поставщики
INSERT INTO partners (partner_type, name, inn, address, phone, email, contact_person, country) VALUES
('customer', 'ООО "Белэнерго"', '193123456', 'г. Минск, ул. Энергетиков 10', '+375171234567', 'info@belenergo.by', 'Петров А.С.', 'Беларусь'),
('customer', 'ОАО "Гомсельмаш"', '500123789', 'г. Гомель, ул. Советская 1', '+375232345678', 'zakaz@gomselmash.by', 'Кравченко В.И.', 'Беларусь'),
('customer', 'ЗАО "Минский тракторный завод"', '100456123', 'г. Минск, пр. Партизанский 19', '+375172567890', 'mtz@mtz.by', 'Лукашевич Н.П.', 'Беларусь'),
('customer', 'ООО "Брестэлектромаш"', '291789456', 'г. Брест, ул. Московская 45', '+375162678901', 'bem@brem.by', 'Ковальчук Д.В.', 'Беларусь'),
('customer', 'РУП "Гродноэнерго"', '400321654', 'г. Гродно, ул. Горького 91', '+375152789012', 'info@grodnoenergy.by', 'Новик И.О.', 'Беларусь'),
('customer', 'ЧУП "Витебские электрические сети"', '300654987', 'г. Витебск, пр. Фрунзе 30', '+375212890123', 'ves@ves.by', 'Титов С.А.', 'Беларусь'),
('customer', 'ООО "Могилевтрансстрой"', '700987321', 'г. Могилев, ул. Лазаренко 24', '+375222901234', 'mts@mogilev.by', 'Герасимов П.Р.', 'Беларусь'),
('customer', 'АО "Интер РАО" (Россия)', '7701234567', 'г. Москва, ул. Щепкина 42', '+74951234567', 'info@inter-rao.ru', 'Смирнов К.Л.', 'Россия'),
('customer', 'ТОВ "Укрэнергокомплект"', '12345678', 'г. Киев, пр. Победы 15', '+380441234567', 'info@uek.ua', 'Шевченко О.В.', 'Украина'),
('customer', 'ООО "ЛитЭлектро"', '304567890', 'г. Вильнюс, ул. Гедимино 10', '+37052123456', 'info@litelectro.lt', 'Паулаускас Й.', 'Литва'),
('supplier', 'БМЗ', '500111222', 'г. Жлобин, ул. Промышленная 1', '+375233456789', 'info@bmz.by', 'Иванов П.С.', 'Беларусь'),
('supplier', 'МедьПром', '100333444', 'г. Минск, ул. Заводская 5', '+375175678901', 'sales@medprom.by', 'Петров А.А.', 'Беларусь'),
('supplier', 'БелКраска', '193555666', 'г. Борисов, ул. Химиков 10', '+375177890123', 'info@belkraska.by', 'Сидоров В.В.', 'Беларусь');

-- Продукция
INSERT INTO items (item_type, item_code, name, category_id, unit_id, price, description) VALUES
('product', 'MTR-001', 'Электродвигатель МТ-100', 7, 1, 2500.00, 'Асинхронный трехфазный двигатель 100 кВт'),
('product', 'MTR-002', 'Электродвигатель МТ-200', 7, 1, 4200.00, 'Асинхронный трехфазный двигатель 200 кВт'),
('product', 'MTR-003', 'Электродвигатель МТ-50', 7, 1, 1800.00, 'Асинхронный трехфазный двигатель 50 кВт'),
('product', 'GEN-001', 'Генератор ГС-150', 8, 1, 5500.00, 'Синхронный генератор 150 кВт'),
('product', 'GEN-002', 'Генератор ГС-300', 8, 1, 8900.00, 'Синхронный генератор 300 кВт'),
('product', 'TRF-001', 'Трансформатор ТМ-100', 9, 1, 3200.00, 'Силовой трансформатор 100 кВА'),
('product', 'TRF-002', 'Трансформатор ТМ-250', 9, 1, 5800.00, 'Силовой трансформатор 250 кВА'),
('product', 'TRF-003', 'Трансформатор ТМ-630', 9, 1, 12500.00, 'Силовой трансформатор 630 кВА'),
('product', 'PMP-001', 'Насосный агрегат НА-50', 10, 1, 2100.00, 'Центробежный насос 50 кВт'),
('product', 'PMP-002', 'Насосный агрегат НА-100', 10, 1, 3400.00, 'Центробежный насос 100 кВт'),
('product', 'CTR-001', 'Щит управления ЩУ-1', 11, 1, 850.00, 'Шкаф управления электродвигателями'),
('product', 'CTR-002', 'Щит управления ЩУ-2', 11, 1, 1650.00, 'Шкаф управления с частотным преобразователем'),
('product', 'CBL-001', 'Кабель силовой КВВГ 3х50', 12, 3, 15.50, 'Кабель контрольный виниловый'),
('product', 'CBL-002', 'Кабель силовой КВВГ 3х95', 12, 3, 28.00, 'Кабель контрольный виниловый');

-- Материалы
INSERT INTO items (item_type, item_code, name, category_id, unit_id, current_stock, min_stock, price, supplier_id, location_id) VALUES
('material', 'MAT-ST-001', 'Сталь листовая Ст3 2мм', 1, 2, 2500.00, 500, 2.50, 11, 5),
('material', 'MAT-ST-002', 'Сталь листовая Ст3 5мм', 1, 2, 1800.00, 300, 2.80, 11, 5),
('material', 'MAT-CU-001', 'Медная проволока ПЭТВ-2 0.5мм', 2, 2, 450.00, 100, 45.00, 12, 6),
('material', 'MAT-CU-002', 'Медная проволока ПЭТВ-2 1.0мм', 2, 2, 380.00, 100, 42.00, 12, 6),
('material', 'MAT-CU-003', 'Медная шина ШМ 10х1', 2, 3, 850.00, 200, 18.00, 12, 6),
('material', 'MAT-INS-001', 'Изоляция электрокартон ЭВ', 2, 1, 350.00, 100, 5.50, 12, 6),
('material', 'MAT-INS-002', 'Лакоткань ЛХС-2', 2, 3, 420.00, 150, 12.00, 12, 6),
('material', 'MAT-PRS-001', 'Подшипник 6309-2RS', 4, 1, 180.00, 50, 25.00, 12, 7),
('material', 'MAT-PRS-002', 'Подшипник 6312-2RS', 4, 1, 120.00, 40, 38.00, 12, 7),
('material', 'MAT-PRS-003', 'Подшипник 6315-2RS', 4, 1, 85.00, 30, 52.00, 12, 7),
('material', 'MAT-PNT-001', 'Грунтовка ГФ-021', 3, 2, 220.00, 50, 8.50, 13, 8),
('material', 'MAT-PNT-002', 'Эмаль ПФ-115 синяя', 3, 2, 180.00, 40, 12.00, 13, 8),
('material', 'MAT-PNT-003', 'Эмаль ПФ-115 серая', 3, 2, 165.00, 40, 12.00, 13, 8),
('material', 'MAT-EL-001', 'Клеммная колодка ТК-10', 2, 1, 850.00, 200, 3.50, 12, 9),
('material', 'MAT-EL-002', 'Кабель ПВ3 2.5мм²', 2, 3, 1200.00, 500, 2.80, 12, 10),
('material', 'MAT-EL-003', 'Автоматический выключатель АП-50', 2, 1, 320.00, 100, 15.00, 12, 9),
('material', 'MAT-ST-003', 'Сталь листовая Ст3 8мм', 1, 2, 1200.00, 400, 3.20, 11, 5),
('material', 'MAT-ST-004', 'Уголок стальной 50х50х5', 1, 3, 950.00, 300, 4.50, 11, 5),
('material', 'MAT-CU-004', 'Провод медный ПУГВ 6мм²', 2, 3, 780.00, 200, 8.50, 12, 10),
('material', 'MAT-INS-003', 'Пленка электроизоляционная ПЭТФ', 2, 3, 280.00, 80, 18.00, 12, 6),
('material', 'MAT-PRS-004', 'Сальник кабельный М20х1.5', 4, 1, 450.00, 150, 2.50, 12, 7),
('material', 'MAT-PNT-004', 'Растворитель Р-4', 3, 2, 120.00, 30, 6.50, 13, 8);

-- Оборудование
INSERT INTO items (item_type, item_code, name, category_id, location_id, status, extra_specs) VALUES
('equipment', 'EQ-CNC-001', 'Токарный станок с ЧПУ 16К20Ф3', 13, 11, 'operational', '{"power": "15kW", "year": 2018}'),
('equipment', 'EQ-CNC-002', 'Фрезерный станок с ЧПУ ВМ127', 13, 11, 'operational', '{"power": "20kW", "year": 2019}'),
('equipment', 'EQ-WLD-001', 'Сварочный полуавтомат MIG-350', 15, 11, 'operational', '{"current": "350A", "type": "MIG"}'),
('equipment', 'EQ-WLD-002', 'Сварочный инвертор TIG-200', 15, 11, 'maintenance', '{"current": "200A", "type": "TIG"}'),
('equipment', 'EQ-PNT-001', 'Камера окрасочная КП-1', 13, 12, 'operational', '{"volume": "50m3", "temp": "80C"}'),
('equipment', 'EQ-WND-001', 'Станок намоточный НСТ-500', 13, 12, 'operational', '{"speed": "500rpm", "type": "automatic"}'),
('equipment', 'EQ-WND-002', 'Станок намоточный НСТ-1000', 13, 12, 'operational', '{"speed": "1000rpm", "type": "automatic"}'),
('equipment', 'EQ-TST-001', 'Стенд испытательный СИЭ-100', 16, 12, 'operational', '{"power": "100kW", "type": "universal"}'),
('equipment', 'EQ-TST-002', 'Прибор измерения сопротивления МИКО-1', 16, 12, 'operational', '{"accuracy": "0.1%", "range": "0-1000Ohm"}'),
('equipment', 'EQ-LFT-001', 'Кран мостовой 5т', 14, 11, 'operational', '{"capacity": "5t", "span": "15m"}'),
('equipment', 'EQ-LFT-002', 'Кран мостовой 10т', 14, 12, 'operational', '{"capacity": "10t", "span": "20m"}'),
('equipment', 'EQ-CMP-001', 'Компрессор воздушный КВ-50', 13, 12, 'broken', '{"pressure": "10bar", "flow": "50m3/h"}');

-- Заказы (с items_json вместо отдельной таблицы order_items)
INSERT INTO orders (order_number, customer_id, order_date, delivery_date, priority, status, items_json, total_amount, manager_id, notes) VALUES
('ORD-2024-001', 1, '2024-01-15', '2024-02-15', 'normal', 'completed', '[{"product_id":1,"quantity":5,"unit_price":2500,"total":12500},{"product_id":3,"quantity":2,"unit_price":1800,"total":3600}]', 16100.00, 3, 'Плановый заказ на электродвигатели'),
('ORD-2024-002', 2, '2024-01-20', '2024-03-01', 'high', 'completed', '[{"product_id":2,"quantity":6,"unit_price":4200,"total":25200},{"product_id":9,"quantity":1,"unit_price":2100,"total":2100}]', 27300.00, 3, 'Срочный заказ для сельхозтехники'),
('ORD-2024-003', 3, '2024-02-01', '2024-03-15', 'normal', 'shipped', '[{"product_id":6,"quantity":10,"unit_price":3200,"total":32000},{"product_id":7,"quantity":2,"unit_price":5800,"total":11600},{"product_id":11,"quantity":1,"unit_price":850,"total":850}]', 44450.00, 4, 'Крупный заказ трансформаторов'),
('ORD-2024-004', 4, '2024-02-10', '2024-03-10', 'normal', 'ready', '[{"product_id":9,"quantity":3,"unit_price":2100,"total":6300},{"product_id":10,"quantity":2,"unit_price":3400,"total":6800}]', 13100.00, 3, 'Заказ насосного оборудования'),
('ORD-2024-005', 5, '2024-02-20', '2024-04-01', 'high', 'quality_check', '[{"product_id":1,"quantity":10,"unit_price":2500,"total":25000},{"product_id":2,"quantity":5,"unit_price":4200,"total":21000},{"product_id":6,"quantity":3,"unit_price":3200,"total":9600},{"product_id":12,"quantity":5,"unit_price":1650,"total":8250}]', 63850.00, 4, 'Комплексная поставка для энергосети'),
('ORD-2024-006', 6, '2024-03-01', '2024-04-15', 'normal', 'in_production', '[{"product_id":1,"quantity":8,"unit_price":2500,"total":20000},{"product_id":11,"quantity":4,"unit_price":850,"total":3400}]', 23400.00, 3, 'Заказ электродвигателей'),
('ORD-2024-007', 7, '2024-03-05', '2024-04-20', 'low', 'in_production', '[{"product_id":11,"quantity":6,"unit_price":850,"total":5100},{"product_id":12,"quantity":2,"unit_price":1650,"total":3300}]', 8400.00, 4, 'Щиты управления'),
('ORD-2024-008', 8, '2024-03-10', '2024-05-01', 'urgent', 'confirmed', '[{"product_id":2,"quantity":20,"unit_price":4200,"total":84000},{"product_id":4,"quantity":5,"unit_price":5500,"total":27500},{"product_id":7,"quantity":2,"unit_price":5800,"total":11600}]', 123100.00, 3, 'Экспортный заказ в Россию'),
('ORD-2024-009', 1, '2024-03-15', '2024-04-30', 'normal', 'new', '[{"product_id":3,"quantity":15,"unit_price":1800,"total":27000},{"product_id":10,"quantity":2,"unit_price":3400,"total":6800}]', 33800.00, 4, 'Повторный заказ'),
('ORD-2024-010', 9, '2024-03-18', '2024-05-15', 'normal', 'new', '[{"product_id":1,"quantity":12,"unit_price":2500,"total":30000},{"product_id":6,"quantity":4,"unit_price":3200,"total":12800},{"product_id":9,"quantity":3,"unit_price":2100,"total":6300}]', 49100.00, 3, 'Заказ в Украину'),
('ORD-2023-045', 2, '2023-11-10', '2023-12-20', 'normal', 'completed', '[{"product_id":2,"quantity":15,"unit_price":4200,"total":63000},{"product_id":4,"quantity":3,"unit_price":5500,"total":16500}]', 79500.00, 3, 'Заказ прошлого года'),
('ORD-2023-046', 3, '2023-11-25', '2024-01-15', 'high', 'completed', '[{"product_id":1,"quantity":25,"unit_price":2500,"total":62500},{"product_id":2,"quantity":15,"unit_price":4200,"total":63000},{"product_id":6,"quantity":5,"unit_price":3200,"total":16000}]', 141500.00, 4, 'Крупный заказ МТЗ'),
('ORD-2023-047', 10, '2023-12-01', '2024-02-01', 'normal', 'completed', '[{"product_id":7,"quantity":4,"unit_price":5800,"total":23200},{"product_id":11,"quantity":1,"unit_price":850,"total":850}]', 24050.00, 3, 'Экспорт в Литву'),
('ORD-2023-048', 5, '2023-12-10', '2024-01-30', 'urgent', 'completed', '[{"product_id":1,"quantity":20,"unit_price":2500,"total":50000},{"product_id":2,"quantity":8,"unit_price":4200,"total":33600},{"product_id":12,"quantity":3,"unit_price":1650,"total":4950}]', 88550.00, 4, 'Срочный заказ Гродноэнерго'),
('ORD-2024-011', 1, '2024-03-20', '2024-05-10', 'normal', 'new', '[{"product_id":5,"quantity":8,"unit_price":5500,"total":44000},{"product_id":8,"quantity":3,"unit_price":7200,"total":21600}]', 65600.00, 3, 'Заказ генераторов'),
('ORD-2024-012', 2, '2024-03-22', '2024-05-20', 'high', 'confirmed', '[{"product_id":1,"quantity":15,"unit_price":2500,"total":37500},{"product_id":3,"quantity":10,"unit_price":1800,"total":18000},{"product_id":6,"quantity":5,"unit_price":3200,"total":16000}]', 71500.00, 4, 'Комплексный заказ'),
('ORD-2024-013', 3, '2024-03-25', '2024-06-01', 'normal', 'new', '[{"product_id":7,"quantity":4,"unit_price":5800,"total":23200},{"product_id":9,"quantity":6,"unit_price":2100,"total":12600}]', 35800.00, 3, 'Насосы для агропрома'),
('ORD-2024-014', 4, '2024-03-28', '2024-05-25', 'urgent', 'in_production', '[{"product_id":2,"quantity":25,"unit_price":4200,"total":105000},{"product_id":4,"quantity":8,"unit_price":5500,"total":44000}]', 149000.00, 4, 'Крупный заказ двигателей'),
('ORD-2024-015', 5, '2024-04-01', '2024-06-15', 'normal', 'new', '[{"product_id":11,"quantity":10,"unit_price":850,"total":8500},{"product_id":12,"quantity":8,"unit_price":1650,"total":13200}]', 21700.00, 3, 'Щиты управления'),
('ORD-2024-016', 6, '2024-04-05', '2024-06-20', 'low', 'planned', '[{"product_id":1,"quantity":30,"unit_price":2500,"total":75000},{"product_id":2,"quantity":20,"unit_price":4200,"total":84000},{"product_id":6,"quantity":10,"unit_price":3200,"total":32000}]', 191000.00, 4, 'Масштабная поставка'),
('ORD-2024-017', 7, '2024-04-08', '2024-05-30', 'high', 'confirmed', '[{"product_id":10,"quantity":5,"unit_price":3400,"total":17000},{"product_id":8,"quantity":2,"unit_price":7200,"total":14400}]', 31400.00, 3, 'Заказ насосов и генераторов'),
('ORD-2024-018', 8, '2024-04-10', '2024-07-01', 'normal', 'new', '[{"product_id":3,"quantity":20,"unit_price":1800,"total":36000},{"product_id":5,"quantity":10,"unit_price":5500,"total":55000}]', 91000.00, 4, 'Экспорт в Россию'),
('ORD-2024-019', 9, '2024-04-12', '2024-06-25', 'normal', 'new', '[{"product_id":7,"quantity":3,"unit_price":5800,"total":17400},{"product_id":9,"quantity":5,"unit_price":2100,"total":10500}]', 27900.00, 3, 'Поставка в Украину'),
('ORD-2024-020', 10, '2024-04-15', '2024-07-10', 'low', 'planned', '[{"product_id":1,"quantity":18,"unit_price":2500,"total":45000},{"product_id":11,"quantity":12,"unit_price":850,"total":10200}]', 55200.00, 4, 'Заказ в Литву'),
-- Дополнительные заказы для наполнения графиков (текущий месяц)
('ORD-2026-001', 1, DATE_SUB(NOW(), INTERVAL 25 DAY), DATE_ADD(NOW(), INTERVAL 5 DAY), 'high', 'completed', '[{"product_id":1,"quantity":10,"unit_price":2500,"total":25000}]', 25000.00, 3, 'Текущий заказ 1'),
('ORD-2026-002', 2, DATE_SUB(NOW(), INTERVAL 20 DAY), DATE_ADD(NOW(), INTERVAL 10 DAY), 'normal', 'completed', '[{"product_id":2,"quantity":5,"unit_price":4200,"total":21000}]', 21000.00, 4, 'Текущий заказ 2'),
('ORD-2026-003', 3, DATE_SUB(NOW(), INTERVAL 18 DAY), DATE_ADD(NOW(), INTERVAL 12 DAY), 'normal', 'ready', '[{"product_id":3,"quantity":8,"unit_price":1800,"total":14400}]', 14400.00, 3, 'Текущий заказ 3'),
('ORD-2026-004', 4, DATE_SUB(NOW(), INTERVAL 15 DAY), DATE_ADD(NOW(), INTERVAL 15 DAY), 'urgent', 'in_production', '[{"product_id":1,"quantity":15,"unit_price":2500,"total":37500}]', 37500.00, 4, 'Текущий заказ 4'),
('ORD-2026-005', 5, DATE_SUB(NOW(), INTERVAL 12 DAY), DATE_ADD(NOW(), INTERVAL 18 DAY), 'normal', 'in_production', '[{"product_id":2,"quantity":12,"unit_price":4200,"total":50400}]', 50400.00, 3, 'Текущий заказ 5'),
('ORD-2026-006', 6, DATE_SUB(NOW(), INTERVAL 10 DAY), DATE_ADD(NOW(), INTERVAL 20 DAY), 'high', 'quality_check', '[{"product_id":3,"quantity":20,"unit_price":1800,"total":36000}]', 36000.00, 4, 'Текущий заказ 6'),
('ORD-2026-007', 7, DATE_SUB(NOW(), INTERVAL 8 DAY), DATE_ADD(NOW(), INTERVAL 22 DAY), 'normal', 'new', '[{"product_id":1,"quantity":25,"unit_price":2500,"total":62500}]', 62500.00, 3, 'Текущий заказ 7'),
('ORD-2026-008', 8, DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_ADD(NOW(), INTERVAL 25 DAY), 'low', 'confirmed', '[{"product_id":2,"quantity":18,"unit_price":4200,"total":75600}]', 75600.00, 4, 'Текущий заказ 8');

-- Технологические маршруты
INSERT INTO tech_routes (product_id, stage_name, stage_code, sequence_order, estimated_hours, work_center) VALUES
(1, 'Подготовка материалов', 'PREP_MAT', 1, 2.0, 'Склад'),
(1, 'Раскрой заготовок', 'CUTTING', 2, 3.0, 'Цех №1'),
(1, 'Механическая обработка', 'MACHINING', 3, 8.0, 'Цех №1, уч.1'),
(1, 'Сварочные работы', 'WELDING', 4, 4.0, 'Цех №1, уч.2'),
(1, 'Грунтовка и покраска', 'PAINTING', 5, 6.0, 'Цех №2, уч.1'),
(1, 'Намотка обмоток', 'WINDING', 6, 12.0, 'Цех №2, уч.2'),
(1, 'Сборка узла', 'ASSEMBLY', 7, 8.0, 'Цех №1'),
(1, 'Электромонтаж', 'ELECTRICAL', 8, 4.0, 'Цех №1'),
(1, 'Предварительные испытания', 'PRE_TEST', 9, 3.0, 'Цех №1'),
(1, 'Контроль качества', 'QC_CHECK', 10, 2.0, 'ОТК'),
(1, 'Упаковка', 'PACKING', 11, 1.5, 'Цех №1'),
(1, 'Отгрузка', 'SHIPPING', 12, 1.0, 'Склад');

-- Производственные задания (с встроенным контролем качества)
INSERT INTO production_tasks (task_number, order_id, product_id, stage_name, stage_sequence, quantity, planned_start, planned_end, status, assigned_to, work_center, qc_result, qc_inspector_id, qc_date, notes) VALUES
('TSK-2024-001', 1, 1, 'Подготовка материалов', 1, 5, '2024-01-16 08:00:00', '2024-01-16 12:00:00', 'completed', 5, 'Цех №1', 'passed', 2, '2024-01-16 12:00:00', 'Подготовка выполнена'),
('TSK-2024-002', 1, 1, 'Раскрой заготовок', 2, 5, '2024-01-16 13:00:00', '2024-01-17 10:00:00', 'completed', 5, 'Цех №1', 'passed', 2, '2024-01-17 10:00:00', 'Раскрой завершен'),
('TSK-2024-003', 1, 1, 'Механическая обработка', 3, 5, '2024-01-17 11:00:00', '2024-01-18 17:00:00', 'completed', 6, 'Цех №1, уч.1', 'passed', 3, '2024-01-18 17:00:00', 'Мехобработка на ЧПУ'),
('TSK-2024-004', 1, 1, 'Намотка обмоток', 6, 5, '2024-01-19 08:00:00', '2024-01-22 17:00:00', 'completed', 6, 'Цех №2, уч.2', 'passed', 3, '2024-01-22 17:00:00', 'Намотка обмоток'),
('TSK-2024-005', 1, 1, 'Сборка узла', 7, 5, '2024-01-23 08:00:00', '2024-01-25 17:00:00', 'completed', 5, 'Цех №1', 'passed', 2, '2024-01-25 17:00:00', 'Сборка двигателя'),
('TSK-2024-006', 1, 1, 'Электромонтаж', 8, 5, '2024-01-26 08:00:00', '2024-01-26 17:00:00', 'completed', 6, 'Цех №1', 'passed', 3, '2024-01-26 17:00:00', 'Электромонтаж'),
('TSK-2024-007', 1, 1, 'Предварительные испытания', 9, 5, '2024-01-29 08:00:00', '2024-01-29 14:00:00', 'completed', 5, 'Цех №1', 'passed', 2, '2024-01-29 14:00:00', 'Испытания'),
('TSK-2024-008', 1, 1, 'Контроль качества', 10, 5, '2024-01-29 15:00:00', '2024-01-30 12:00:00', 'completed', NULL, 'ОТК', 'passed', 2, '2024-01-30 12:00:00', 'Передано на ОТК'),
('TSK-2024-009', 1, 1, 'Упаковка', 11, 5, '2024-01-30 13:00:00', '2024-01-30 17:00:00', 'completed', 7, 'Цех №1', NULL, NULL, NULL, 'Упаковка'),
('TSK-2024-010', 1, 1, 'Отгрузка', 12, 5, '2024-01-31 08:00:00', '2024-01-31 10:00:00', 'completed', 7, 'Склад', NULL, NULL, NULL, 'Отгружено'),
('TSK-2024-011', 14, 1, 'Подготовка материалов', 1, 20, '2024-03-11 08:00:00', '2024-03-11 16:00:00', 'completed', 5, 'Цех №1', 'passed', 2, '2024-03-11 16:00:00', 'Подготовка'),
('TSK-2024-012', 14, 1, 'Раскрой заготовок', 2, 20, '2024-03-12 08:00:00', '2024-03-13 17:00:00', 'completed', 6, 'Цех №1', 'passed', 3, '2024-03-13 17:00:00', 'Раскрой'),
('TSK-2024-013', 14, 1, 'Механическая обработка', 3, 20, '2024-03-14 08:00:00', '2024-03-18 17:00:00', 'in_progress', 5, 'Цех №1, уч.1', NULL, NULL, NULL, 'Мехобработка в процессе'),
('TSK-2024-014', 14, 1, 'Намотка обмоток', 6, 20, '2024-03-19 08:00:00', '2024-03-25 17:00:00', 'planned', 6, 'Цех №2, уч.2', NULL, NULL, NULL, 'Запланирована намотка'),
('TSK-2024-015', 5, 11, 'Подготовка материалов', 1, 4, '2024-03-06 08:00:00', '2024-03-06 10:00:00', 'completed', 7, 'Склад', 'passed', 2, '2024-03-06 10:00:00', 'Материалы получены'),
('TSK-2024-016', 5, 11, 'Механическая обработка', 3, 4, '2024-03-06 11:00:00', '2024-03-07 17:00:00', 'completed', 6, 'Цех №1, уч.1', 'passed', 3, '2024-03-07 17:00:00', 'Обработка корпуса'),
('TSK-2024-017', 5, 11, 'Грунтовка и покраска', 5, 4, '2024-03-08 08:00:00', '2024-03-08 17:00:00', 'completed', NULL, 'Цех №2, уч.1', 'passed', 2, '2024-03-08 17:00:00', 'Покраска'),
('TSK-2024-018', 5, 11, 'Сборка узла', 7, 4, '2024-03-11 08:00:00', '2024-03-12 17:00:00', 'in_progress', 5, 'Цех №1', NULL, NULL, NULL, 'Сборка щита'),
('TSK-2024-019', 5, 11, 'Электромонтаж', 8, 4, '2024-03-13 08:00:00', '2024-03-13 17:00:00', 'planned', 6, 'Цех №1', NULL, NULL, NULL, 'Электромонтаж'),
('TSK-2024-020', 6, 11, 'Подготовка материалов', 1, 6, '2024-03-06 08:00:00', '2024-03-06 11:00:00', 'completed', 8, 'Склад', 'passed', 2, '2024-03-06 11:00:00', 'Материалы подготовлены'),
('TSK-2024-021', 6, 11, 'Механическая обработка', 3, 6, '2024-03-06 13:00:00', '2024-03-08 17:00:00', 'in_progress', 5, 'Цех №1, уч.1', NULL, NULL, NULL, 'Мехобработка'),
('TSK-2024-022', 7, 12, 'Подготовка материалов', 1, 2, '2024-03-08 08:00:00', '2024-03-08 10:00:00', 'pending', NULL, 'Склад', NULL, NULL, NULL, 'Ожидает начала'),
('TSK-2024-023', 5, 1, 'Подготовка материалов', 1, 10, '2024-02-21 08:00:00', '2024-02-21 16:00:00', 'completed', 5, 'Цех №1', 'passed', 2, '2024-02-21 16:00:00', 'Подготовка'),
('TSK-2024-024', 5, 1, 'Раскрой заготовок', 2, 10, '2024-02-22 08:00:00', '2024-02-23 17:00:00', 'completed', 6, 'Цех №1', 'passed', 3, '2024-02-23 17:00:00', 'Раскрой'),
('TSK-2024-025', 5, 1, 'Механическая обработка', 3, 10, '2024-02-26 08:00:00', '2024-03-01 17:00:00', 'completed', 5, 'Цех №1, уч.1', 'passed', 2, '2024-03-01 17:00:00', 'Мехобработка'),
('TSK-2024-026', 5, 1, 'Сварочные работы', 4, 10, '2024-03-04 08:00:00', '2024-03-05 17:00:00', 'completed', NULL, 'Цех №1, уч.2', 'passed', 3, '2024-03-05 17:00:00', 'Сварка'),
('TSK-2024-027', 5, 1, 'Грунтовка и покраска', 5, 10, '2024-03-06 08:00:00', '2024-03-07 17:00:00', 'completed', NULL, 'Цех №2, уч.1', 'passed', 2, '2024-03-07 17:00:00', 'Покраска'),
('TSK-2024-028', 5, 1, 'Намотка обмоток', 6, 10, '2024-03-08 08:00:00', '2024-03-14 17:00:00', 'completed', 6, 'Цех №2, уч.2', 'passed', 3, '2024-03-14 17:00:00', 'Намотка'),
('TSK-2024-029', 5, 1, 'Сборка узла', 7, 10, '2024-03-15 08:00:00', '2024-03-19 17:00:00', 'completed', 5, 'Цех №1', 'passed', 2, '2024-03-19 17:00:00', 'Сборка'),
('TSK-2024-030', 5, 1, 'Электромонтаж', 8, 10, '2024-03-20 08:00:00', '2024-03-20 17:00:00', 'completed', 6, 'Цех №1', 'passed', 3, '2024-03-20 17:00:00', 'Электромонтаж'),
('TSK-2024-031', 5, 1, 'Предварительные испытания', 9, 10, '2024-03-21 08:00:00', '2024-03-21 17:00:00', 'completed', 5, 'Цех №1', 'passed', 2, '2024-03-21 17:00:00', 'Испытания'),
('TSK-2024-032', 5, 1, 'Контроль качества', 10, 10, '2024-03-22 08:00:00', '2024-03-22 17:00:00', 'completed', NULL, 'ОТК', 'passed', 2, '2024-03-22 17:00:00', 'Контроль качества'),
('TSK-2024-033', 14, 1, 'Намотка обмоток', 6, 20, '2024-03-26 08:00:00', '2024-04-02 17:00:00', 'planned', 6, 'Цех №2, уч.2', NULL, NULL, NULL, 'Запланирована намотка'),
('TSK-2024-034', 14, 1, 'Сборка узла', 7, 20, '2024-04-03 08:00:00', '2024-04-09 17:00:00', 'planned', 5, 'Цех №1', NULL, NULL, NULL, 'Сборка двигателей'),
('TSK-2024-035', 14, 1, 'Электромонтаж', 8, 20, '2024-04-10 08:00:00', '2024-04-10 17:00:00', 'planned', 6, 'Цех №1', NULL, NULL, NULL, 'Электромонтаж'),
('TSK-2024-036', 14, 1, 'Предварительные испытания', 9, 20, '2024-04-11 08:00:00', '2024-04-11 17:00:00', 'planned', 5, 'Цех №1', NULL, NULL, NULL, 'Испытания'),
('TSK-2024-037', 14, 1, 'Контроль качества', 10, 20, '2024-04-12 08:00:00', '2024-04-12 17:00:00', 'planned', NULL, 'ОТК', NULL, NULL, NULL, 'ОТК'),
('TSK-2024-038', 14, 1, 'Упаковка', 11, 20, '2024-04-15 08:00:00', '2024-04-15 17:00:00', 'planned', 7, 'Цех №1', NULL, NULL, NULL, 'Упаковка'),
('TSK-2024-039', 14, 1, 'Отгрузка', 12, 20, '2024-04-16 08:00:00', '2024-04-16 12:00:00', 'planned', 7, 'Склад', NULL, NULL, NULL, 'Отгрузка');

-- Дополнительные производственные задания для наполнения графиков (текущий месяц и последние 7 дней)
INSERT INTO production_tasks (task_number, order_id, product_id, stage_name, stage_sequence, quantity, planned_start, planned_end, status, assigned_to, work_center, qc_result, qc_inspector_id, qc_date, notes) VALUES
('TSK-2026-001', 1, 1, 'Подготовка материалов', 1, 5, DATE_SUB(NOW(), INTERVAL 6 DAY), DATE_SUB(NOW(), INTERVAL 6 DAY), 'completed', 5, 'Цех №1', 'passed', 2, NOW(), 'Подготовка выполнена'),
('TSK-2026-002', 1, 1, 'Раскрой заготовок', 2, 5, DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_SUB(NOW(), INTERVAL 5 DAY), 'completed', 5, 'Цех №1', 'passed', 2, NOW(), 'Раскрой завершен'),
('TSK-2026-003', 2, 1, 'Механическая обработка', 3, 8, DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_SUB(NOW(), INTERVAL 4 DAY), 'completed', 6, 'Цех №1, уч.1', 'passed', 3, NOW(), 'Мехобработка на ЧПУ'),
('TSK-2026-004', 2, 1, 'Намотка обмоток', 6, 8, DATE_SUB(NOW(), INTERVAL 4 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY), 'completed', 6, 'Цех №2, уч.2', 'passed', 3, NOW(), 'Намотка обмоток'),
('TSK-2026-005', 3, 1, 'Сборка узла', 7, 10, DATE_SUB(NOW(), INTERVAL 4 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY), 'completed', 5, 'Цех №1', 'passed', 2, NOW(), 'Сборка двигателя'),
('TSK-2026-006', 3, 1, 'Электромонтаж', 8, 10, DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY), 'completed', 6, 'Цех №1', 'passed', 3, NOW(), 'Электромонтаж'),
('TSK-2026-007', 4, 1, 'Предварительные испытания', 9, 12, DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_SUB(NOW(), INTERVAL 2 DAY), 'completed', 5, 'Цех №1', 'passed', 2, NOW(), 'Испытания'),
('TSK-2026-008', 4, 1, 'Контроль качества', 10, 12, DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 2 DAY), 'completed', NULL, 'ОТК', 'passed', 2, NOW(), 'Передано на ОТК'),
('TSK-2026-009', 5, 1, 'Упаковка', 11, 15, DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY), 'completed', 7, 'Цех №1', NULL, NULL, NULL, 'Упаковка'),
('TSK-2026-010', 5, 1, 'Отгрузка', 12, 15, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY), 'completed', 7, 'Склад', NULL, NULL, NULL, 'Отгружено'),
('TSK-2026-011', 6, 1, 'Подготовка материалов', 1, 20, DATE_SUB(NOW(), INTERVAL 1 DAY), NOW(), 'in_progress', 5, 'Цех №1', NULL, NULL, NULL, 'Подготовка в процессе'),
('TSK-2026-012', 6, 1, 'Раскрой заготовок', 2, 20, NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY), 'planned', 6, 'Цех №1', NULL, NULL, NULL, 'Запланирован раскрой'),
('TSK-2026-013', 7, 1, 'Механическая обработка', 3, 8, DATE_SUB(NOW(), INTERVAL 6 DAY), DATE_SUB(NOW(), INTERVAL 5 DAY), 'completed', 5, 'Цех №1, уч.1', 'passed', 2, NOW(), 'Мехобработка'),
('TSK-2026-014', 7, 1, 'Сварочные работы', 4, 8, DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_SUB(NOW(), INTERVAL 4 DAY), 'completed', NULL, 'Цех №1, уч.2', 'passed', 3, NOW(), 'Сварка'),
('TSK-2026-015', 8, 1, 'Грунтовка и покраска', 5, 10, DATE_SUB(NOW(), INTERVAL 4 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY), 'completed', NULL, 'Цех №2, уч.1', 'passed', 2, NOW(), 'Покраска'),
('TSK-2026-016', 8, 1, 'Намотка обмоток', 6, 10, DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_SUB(NOW(), INTERVAL 2 DAY), 'in_progress', 6, 'Цех №2, уч.2', NULL, NULL, NULL, 'Намотка в процессе'),
('TSK-2026-017', 9, 1, 'Сборка узла', 7, 12, DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY), 'completed', 5, 'Цех №1', 'passed', 2, NOW(), 'Сборка'),
('TSK-2026-018', 9, 1, 'Электромонтаж', 8, 12, DATE_SUB(NOW(), INTERVAL 1 DAY), NOW(), 'in_progress', 6, 'Цех №1', NULL, NULL, NULL, 'Электромонтаж'),
('TSK-2026-019', 10, 1, 'Предварительные испытания', 9, 5, DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_SUB(NOW(), INTERVAL 4 DAY), 'completed', 5, 'Цех №1', 'passed', 2, NOW(), 'Испытания'),
('TSK-2026-020', 10, 1, 'Контроль качества', 10, 5, DATE_SUB(NOW(), INTERVAL 4 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY), 'completed', NULL, 'ОТК', 'passed', 2, NOW(), 'ОТК пройден');

-- Движение материалов
INSERT INTO movements (movement_type, item_id, quantity, reference_type, reference_id, warehouse_from, warehouse_to, employee_id, notes) VALUES
('receipt', 15, 1000.00, 'purchase_order', 1, NULL, 'Склад А1', 7, 'Поступление от БМЗ'),
('receipt', 16, 800.00, 'purchase_order', 1, NULL, 'Склад А1', 7, 'Поступление от БМЗ'),
('receipt', 17, 200.00, 'purchase_order', 2, NULL, 'Склад Б2', 8, 'Поступление медной проволоки'),
('receipt', 18, 150.00, 'purchase_order', 2, NULL, 'Склад Б2', 8, 'Поступление медной проволоки'),
('receipt', 22, 100.00, 'purchase_order', 3, NULL, 'Склад В1', 7, 'Подшипники'),
('consumption', 15, 250.00, 'production_task', 1, 'Склад А1', 'Цех №1', 5, 'На заказ ORD-2024-001'),
('consumption', 17, 45.00, 'production_task', 4, 'Склад Б2', 'Цех №2', 6, 'На намотку обмоток'),
('consumption', 22, 10.00, 'production_task', 5, 'Склад В1', 'Цех №1', 5, 'На сборку двигателей'),
('consumption', 25, 25.00, 'production_task', 7, 'Склад Г1', 'Цех №2', NULL, 'На покраску'),
('consumption', 26, 30.00, 'production_task', 7, 'Склад Г1', 'Цех №2', NULL, 'На покраску'),
('return', 17, 5.00, 'production_task', 4, 'Цех №2', 'Склад Б2', 6, 'Возврат остатков'),
('receipt', 27, 500.00, 'purchase_order', 4, NULL, 'Склад В2', 8, 'Клеммные колодки'),
('receipt', 28, 1000.00, 'purchase_order', 5, NULL, 'Склад А2', 7, 'Кабель ПВ3'),
('consumption', 27, 50.00, 'production_task', 18, 'Склад В2', 'Цех №1', 5, 'На сборку щитов'),
('adjustment', 15, -10.00, 'inventory', NULL, 'Склад А1', NULL, 7, 'Инвентаризация - недостача'),
('receipt', 19, 300.00, 'purchase_order', 6, NULL, 'Склад А1', 8, 'Поступление стали'),
('consumption', 19, 150.00, 'production_task', 13, 'Склад А1', 'Цех №1', 5, 'На производство'),
('consumption', 17, 80.00, 'production_task', 28, 'Склад Б2', 'Цех №2', 6, 'На намотку'),
('receipt', 23, 100.00, 'purchase_order', 7, NULL, 'Склад Г1', 7, 'Грунтовка'),
('receipt', 24, 150.00, 'purchase_order', 7, NULL, 'Склад Г1', 7, 'Эмаль синяя'),
('consumption', 23, 40.00, 'production_task', 27, 'Склад Г1', 'Цех №2', NULL, 'На грунтовку');

-- Отгрузки (как тип движения)
INSERT INTO movements (movement_type, item_id, quantity, reference_type, reference_id, warehouse_from, warehouse_to, employee_id, partner_id, notes) VALUES
('shipment', 1, 5, 'order', 1, 'Склад готовой продукции', NULL, 7, 1, 'Отгрузка по ORD-2024-001'),
('shipment', 2, 6, 'order', 2, 'Склад готовой продукции', NULL, 7, 2, 'Отгрузка по ORD-2024-002'),
('shipment', 6, 10, 'order', 3, 'Склад готовой продукции', NULL, 8, 3, 'Отгрузка по ORD-2024-003'),
('shipment', 9, 3, 'order', 4, 'Склад готовой продукции', NULL, 7, 4, 'Отгрузка по ORD-2024-004'),
('shipment', 1, 20, 'order', 12, 'Склад готовой продукции', NULL, 7, 3, 'Отгрузка по ORD-2023-046'),
('shipment', 2, 15, 'order', 12, 'Склад готовой продукции', NULL, 7, 3, 'Отгрузка по ORD-2023-046');

-- Журнал событий
INSERT INTO journal (journal_type, user_id, action, module, record_id, description, ip_address) VALUES
('activity', 1, 'login', 'system', NULL, 'Вход в систему', '192.168.1.10'),
('activity', 2, 'create', 'orders', 1, 'Создан заказ ORD-2024-001', '192.168.1.11'),
('activity', 3, 'update', 'orders', 2, 'Обновлен статус заказа ORD-2024-002', '192.168.1.12'),
('activity', 5, 'start', 'production', 1, 'Начато выполнение задания TSK-2024-001', '192.168.1.20'),
('activity', 6, 'complete', 'production', 3, 'Завершено задание TSK-2024-003', '192.168.1.21'),
('activity', 7, 'receipt', 'warehouse', 15, 'Оприходован материал MAT-ST-001', '192.168.1.30'),
('activity', 8, 'shipment', 'warehouse', 1, 'Отгружена продукция MTR-001', '192.168.1.31'),
('activity', 2, 'qc_pass', 'quality', 8, 'ОТК пройден для партии из 5 двигателей', '192.168.1.11'),
('activity', 4, 'create', 'production', 13, 'Создано производственное задание', '192.168.1.13'),
('activity', 1, 'settings', 'admin', NULL, 'Изменены настройки системы', '192.168.1.10');

-- Журнал ТО оборудования
INSERT INTO journal (journal_type, item_id, technician_id, maintenance_type, scheduled_date, completed_date, maintenance_status, cost, parts_used, description) VALUES
('maintenance', 27, 5, 'preventive', '2024-02-15', '2024-02-15', 'completed', 500.00, 'Фильтры, масло', 'Плановое ТО токарного станка'),
('maintenance', 28, 6, 'preventive', '2024-03-01', '2024-03-01', 'completed', 650.00, 'Ремень, смазка', 'Плановое ТО фрезерного станка'),
('maintenance', 29, 5, 'preventive', '2024-02-20', '2024-02-20', 'completed', 300.00, 'Контактная группа', 'ТО сварочного полуавтомата'),
('maintenance', 30, NULL, 'corrective', '2024-03-10', NULL, 'in_progress', 0, 'TBD', 'Ремонт сварочного инвертора'),
('maintenance', 31, 6, 'preventive', '2024-01-25', '2024-01-25', 'completed', 800.00, 'Фильтры, форсунки', 'ТО окрасочной камеры'),
('maintenance', 32, 5, 'preventive', '2024-03-05', '2024-03-05', 'completed', 450.00, 'Щетки, подшипники', 'ТО намоточного станка'),
('maintenance', 33, 6, 'preventive', '2024-02-28', '2024-02-28', 'completed', 450.00, 'Щетки, подшипники', 'ТО намоточного станка'),
('maintenance', 34, 4, 'preventive', '2024-03-01', '2024-03-01', 'completed', 1200.00, 'Датчики, калибровка', 'Поверка испытательного стенда'),
('maintenance', 35, 4, 'preventive', '2024-02-15', '2024-02-15', 'completed', 200.00, 'Калибровка', 'Поверка измерительного прибора'),
('maintenance', 36, 5, 'preventive', '2024-01-10', '2024-01-10', 'completed', 1500.00, 'Трос, смазка', 'ТО крана 5т'),
('maintenance', 37, 6, 'preventive', '2024-01-15', '2024-01-15', 'completed', 2000.00, 'Трос, смазка', 'ТО крана 10т'),
('maintenance', 38, NULL, 'emergency', '2024-02-01', NULL, 'in_progress', 0, 'TBD', 'Аварийный ремонт компрессора');

-- Системные настройки
INSERT INTO system_settings (setting_key, setting_value, setting_type, module, description, updated_by) VALUES
('company_name', 'ОАО "Полесьеэлектромаш"', 'string', 'general', 'Название компании', 1),
('currency_default', 'BYN', 'string', 'finance', 'Валюта по умолчанию', 1),
('working_hours_start', '08:00', 'string', 'production', 'Начало рабочего дня', 1),
('working_hours_end', '17:00', 'string', 'production', 'Конец рабочего дня', 1),
('min_stock_alert', 'true', 'boolean', 'warehouse', 'Уведомления о мин. остатке', 1),
('qc_required', 'true', 'boolean', 'quality', 'Обязательный контроль качества', 1),
('auto_numbering', 'true', 'boolean', 'general', 'Авто-нумерация заказов', 1),
('report_retention_days', '365', 'number', 'reports', 'Срок хранения отчетов (дни)', 1);

-- Ожидаемые поставки (заказы поставщикам) с детализацией по материалам
INSERT INTO purchase_orders (order_number, supplier_id, order_date, expected_delivery, status, priority, items_json, notes, created_by) VALUES
('PO-2024-001', 11, '2024-03-01', '2024-03-15', 'confirmed', 'normal', '[{"item_id": 17, "quantity": 500, "price": 25.50}, {"item_id": 18, "quantity": 300, "price": 32.00}]', 'Поставка металлопроката', 2),
('PO-2024-002', 12, '2024-03-05', '2024-03-18', 'sent', 'high', '[{"item_id": 19, "quantity": 200, "price": 85.00}, {"item_id": 20, "quantity": 150, "price": 45.00}]', 'Медная проволока для обмоток', 2),
('PO-2024-003', 13, '2024-03-08', '2024-03-20', 'confirmed', 'normal', '[{"item_id": 21, "quantity": 100, "price": 120.00}, {"item_id": 22, "quantity": 80, "price": 95.00}]', 'Лакокрасочные материалы', 2),
('PO-2024-004', 11, '2024-03-10', '2024-03-25', 'draft', 'low', '[{"item_id": 17, "quantity": 1000, "price": 24.00}]', 'Дополнительный заказ стали', 2),
('PO-2024-005', 12, '2024-03-12', '2024-03-22', 'partial', 'urgent', '[{"item_id": 19, "quantity": 500, "price": 82.00}, {"item_id": 20, "quantity": 300, "price": 43.00}]', 'Срочная поставка меди', 2);

-- Индексы для производительности
CREATE INDEX idx_orders_customer ON orders(customer_id);
CREATE INDEX idx_orders_status ON orders(status);
CREATE INDEX idx_orders_manager ON orders(manager_id);
CREATE INDEX idx_production_tasks_order ON production_tasks(order_id);
CREATE INDEX idx_production_tasks_product ON production_tasks(product_id);
CREATE INDEX idx_production_tasks_status ON production_tasks(status);
CREATE INDEX idx_movements_item ON movements(item_id);
CREATE INDEX idx_movements_type ON movements(movement_type);
CREATE INDEX idx_items_type ON items(item_type);
CREATE INDEX idx_items_category ON items(category_id);
CREATE INDEX idx_staff_role ON staff(role);
CREATE INDEX idx_partners_type ON partners(partner_type);
CREATE INDEX idx_purchase_orders_supplier ON purchase_orders(supplier_id);
CREATE INDEX idx_purchase_orders_status ON purchase_orders(status);
