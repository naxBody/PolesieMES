-- PolesieMES - Система управления производством ОАО "Полесьеэлектромаш"
-- База данных для XAMPP/phpMyAdmin

CREATE DATABASE IF NOT EXISTS polesie_mes CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE polesie_mes;

-- Таблица должностей
CREATE TABLE positions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(20) UNIQUE,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Таблица сотрудников
CREATE TABLE employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_code VARCHAR(20) UNIQUE NOT NULL,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    middle_name VARCHAR(50),
    position_id INT,
    email VARCHAR(100),
    phone VARCHAR(20),
    department VARCHAR(100),
    hire_date DATE,
    status ENUM('active', 'vacation', 'sick', 'terminated') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (position_id) REFERENCES positions(id)
);

-- Таблица пользователей (авторизация)
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNIQUE,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'manager', 'technologist', 'operator', 'quality_inspector', 'warehouse_keeper') NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
);

-- Таблица клиентов
CREATE TABLE customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    inn VARCHAR(20),
    address TEXT,
    phone VARCHAR(20),
    email VARCHAR(100),
    contact_person VARCHAR(100),
    country VARCHAR(50) DEFAULT 'Беларусь',
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Таблица продуктов/изделий
CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_code VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(200) NOT NULL,
    description TEXT,
    category VARCHAR(100),
    unit VARCHAR(20) DEFAULT 'шт',
    base_price DECIMAL(12,2),
    currency VARCHAR(3) DEFAULT 'BYN',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Таблица заказов
CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(50) UNIQUE NOT NULL,
    customer_id INT,
    order_date DATE NOT NULL,
    delivery_date DATE,
    priority ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal',
    status ENUM('new', 'confirmed', 'in_production', 'quality_check', 'ready', 'shipped', 'completed', 'cancelled') DEFAULT 'new',
    total_amount DECIMAL(15,2),
    currency VARCHAR(3) DEFAULT 'BYN',
    notes TEXT,
    manager_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id),
    FOREIGN KEY (manager_id) REFERENCES employees(id)
);

-- Состав заказа
CREATE TABLE order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(12,2),
    total_price DECIMAL(15,2),
    status ENUM('pending', 'in_production', 'completed', 'cancelled') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id)
);

-- Технологические этапы
CREATE TABLE production_stages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(50) UNIQUE,
    description TEXT,
    sequence_order INT,
    estimated_hours DECIMAL(8,2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Производственные задания
CREATE TABLE production_tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_number VARCHAR(50) UNIQUE NOT NULL,
    order_item_id INT,
    product_id INT,
    stage_id INT,
    quantity INT,
    planned_start DATETIME,
    planned_end DATETIME,
    actual_start DATETIME,
    actual_end DATETIME,
    status ENUM('planned', 'in_progress', 'paused', 'completed', 'rejected') DEFAULT 'planned',
    assigned_to INT,
    work_center VARCHAR(100),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (order_item_id) REFERENCES order_items(id),
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (stage_id) REFERENCES production_stages(id),
    FOREIGN KEY (assigned_to) REFERENCES employees(id)
);

-- Контроль качества
CREATE TABLE quality_checks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    check_number VARCHAR(50) UNIQUE NOT NULL,
    production_task_id INT,
    inspector_id INT,
    check_date DATETIME,
    check_type ENUM('incoming', 'in_process', 'final', 'random') NOT NULL,
    result ENUM('pending', 'passed', 'failed', 'conditional') DEFAULT 'pending',
    defects_found INT DEFAULT 0,
    defects_description TEXT,
    measurements JSON,
    photos_path VARCHAR(255),
    comments TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (production_task_id) REFERENCES production_tasks(id),
    FOREIGN KEY (inspector_id) REFERENCES employees(id)
);

-- Склад материалов
CREATE TABLE materials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    material_code VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(200) NOT NULL,
    category VARCHAR(100),
    unit VARCHAR(20),
    min_stock INT DEFAULT 0,
    current_stock DECIMAL(12,3) DEFAULT 0,
    price DECIMAL(12,2),
    currency VARCHAR(3) DEFAULT 'BYN',
    supplier VARCHAR(200),
    location VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Движение материалов
CREATE TABLE material_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    material_id INT NOT NULL,
    movement_type ENUM('receipt', 'consumption', 'return', 'adjustment') NOT NULL,
    quantity DECIMAL(12,3) NOT NULL,
    reference_type VARCHAR(50),
    reference_id INT,
    warehouse_from VARCHAR(100),
    warehouse_to VARCHAR(100),
    employee_id INT,
    notes TEXT,
    movement_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (material_id) REFERENCES materials(id)
);

-- Оборудование
CREATE TABLE equipment (
    id INT AUTO_INCREMENT PRIMARY KEY,
    equipment_code VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(200) NOT NULL,
    type VARCHAR(100),
    location VARCHAR(100),
    status ENUM('operational', 'maintenance', 'broken', 'offline') DEFAULT 'operational',
    last_maintenance DATE,
    next_maintenance DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Журнал событий
CREATE TABLE activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(100) NOT NULL,
    module VARCHAR(50),
    record_id INT,
    description TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Справочник единиц измерения
CREATE TABLE units (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    short_name VARCHAR(20),
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Категории материалов
CREATE TABLE material_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(50) UNIQUE,
    description TEXT,
    parent_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES material_categories(id)
);

-- Расположения/места хранения
CREATE TABLE locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(50) UNIQUE,
    type ENUM('warehouse', 'shop_floor', 'office', 'external') DEFAULT 'warehouse',
    address TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Категории оборудования
CREATE TABLE equipment_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(50) UNIQUE,
    description TEXT,
    parent_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES equipment_categories(id)
);

-- Категории продукции
CREATE TABLE product_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(50) UNIQUE,
    description TEXT,
    parent_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES product_categories(id)
);

-- Движение материалов (транзакции)
CREATE TABLE material_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    material_id INT NOT NULL,
    operation_type ENUM('receipt', 'consumption', 'return', 'adjustment', 'transfer') NOT NULL,
    quantity DECIMAL(12,3) NOT NULL,
    warehouse_from VARCHAR(100),
    warehouse_to VARCHAR(100),
    reference_type VARCHAR(50),
    reference_id INT,
    user_id INT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (material_id) REFERENCES materials(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Отгрузки
CREATE TABLE shipments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    order_item_id INT,
    shipment_number VARCHAR(50) UNIQUE,
    shipment_date DATETIME,
    quantity INT NOT NULL,
    carrier VARCHAR(100),
    tracking_number VARCHAR(100),
    status ENUM('pending', 'in_transit', 'delivered', 'returned') DEFAULT 'pending',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id),
    FOREIGN KEY (order_item_id) REFERENCES order_items(id)
);

-- Журнал технического обслуживания
CREATE TABLE maintenance_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    equipment_id INT NOT NULL,
    technician_id INT,
    maintenance_type ENUM('preventive', 'corrective', 'emergency', 'inspection') NOT NULL,
    description TEXT,
    scheduled_date DATE,
    completed_date DATE,
    status ENUM('planned', 'in_progress', 'completed', 'cancelled') DEFAULT 'planned',
    cost DECIMAL(12,2),
    parts_used TEXT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (equipment_id) REFERENCES equipment(id),
    FOREIGN KEY (technician_id) REFERENCES employees(id)
);

-- ==================== ЗАПОЛНЕНИЕ ДАННЫМИ ====================

-- Единицы измерения
INSERT INTO units (name, short_name, description) VALUES
('Штука', 'шт', 'Единица измерения количества изделий'),
('Килограмм', 'кг', 'Единица измерения массы'),
('Метр', 'м', 'Единица измерения длины'),
('Литр', 'л', 'Единица измерения объема'),
('Набор', 'наб', 'Комплект изделий'),
('Пара', 'пара', 'Два изделия в комплекте');

-- Категории материалов
INSERT INTO material_categories (name, code, description) VALUES
('Металлопрокат', 'METAL', 'Черные и цветные металлы'),
('Электротехнические материалы', 'ELECTRO', 'Провода, кабели, изоляция'),
('Лакокрасочные материалы', 'PAINT', 'Краски, эмали, растворители'),
('Крепеж', 'FASTENER', 'Болты, гайки, винты'),
('Упаковочные материалы', 'PACKAGE', 'Тара и упаковка'),
('Сырье', 'RAW', 'Основное сырье для производства');

-- Расположения
INSERT INTO locations (name, code, type) VALUES
('Склад А1 - Металлопрокат', 'WH-A1', 'warehouse'),
('Склад Б2 - Электроматериалы', 'WH-B2', 'warehouse'),
('Склад В1 - Лакокрасочные', 'WH-V1', 'warehouse'),
('Склад Г1 - Крепеж', 'WH-G1', 'warehouse'),
('Цех №1', 'SHOP-1', 'shop_floor'),
('Цех №2', 'SHOP-2', 'shop_floor');

-- Категории оборудования
INSERT INTO equipment_categories (name, code, description) VALUES
('Станки', 'MACHINE', 'Металлообрабатывающие станки'),
('Подъемное оборудование', 'CRANE', 'Краны, тали, подъемники'),
('Сварочное оборудование', 'WELD', 'Сварочные аппараты'),
('Измерительные приборы', 'MEASURE', 'Контрольно-измерительные приборы'),
('Транспорт', 'TRANSPORT', 'Погрузчики, тележки');

-- Категории продукции
INSERT INTO product_categories (name, code, description) VALUES
('Электродвигатели', 'MOTOR', 'Асинхронные и синхронные двигатели'),
('Генераторы', 'GENERATOR', 'Электрогенераторы различной мощности'),
('Трансформаторы', 'TRANSFORMER', 'Силовые трансформаторы'),
('Насосное оборудование', 'PUMP', 'Насосы и насосные агрегаты'),
('Устройства управления', 'CONTROL', 'Щиты управления и автоматики'),
('Кабельная продукция', 'CABLE', 'Силовые и контрольные кабели');

-- Должности
INSERT INTO positions (name, code, description) VALUES
('Директор', 'DIR', 'Руководитель предприятия'),
('Начальник производства', 'PROD_HEAD', 'Руководитель производственного отдела'),
('Менеджер по продажам', 'SALES_MGR', 'Работа с клиентами и заказами'),
('Технолог', 'TECH', 'Разработка технологических процессов'),
('Оператор станка', 'OPERATOR', 'Работа на производственном оборудовании'),
('Инспектор ОТК', 'QC_INSPECTOR', 'Контроль качества продукции'),
('Кладовщик', 'STOREKEEPER', 'Учет материалов на складе'),
('Инженер', 'ENGINEER', 'Техническое сопровождение'),
('Мастер смены', 'SHIFT_MASTER', 'Руководство сменой');

-- Сотрудники
INSERT INTO employees (employee_code, first_name, last_name, middle_name, position_id, email, phone, department, hire_date, status) VALUES
('EMP001', 'Александр', 'Иванов', 'Петрович', 1, 'director@polesie.by', '+375291111111', 'Администрация', '2018-01-15', 'active'),
('EMP002', 'Елена', 'Смирнова', 'Владимировна', 2, 'prod.head@polesie.by', '+375292222222', 'Производство', '2018-03-20', 'active'),
('EMP003', 'Дмитрий', 'Козлов', 'Андреевич', 3, 'sales1@polesie.by', '+375293333333', 'Отдел продаж', '2019-06-10', 'active'),
('EMP004', 'Ольга', 'Новикова', 'Сергеевна', 3, 'sales2@polesie.by', '+375294444444', 'Отдел продаж', '2020-02-15', 'active'),
('EMP005', 'Сергей', 'Федоров', 'Игоревич', 4, 'tech1@polesie.by', '+375295555555', 'Технический отдел', '2019-09-01', 'active'),
('EMP006', 'Наталья', 'Морозова', 'Дмитриевна', 4, 'tech2@polesie.by', '+375296666666', 'Технический отдел', '2020-11-20', 'active'),
('EMP007', 'Андрей', 'Волков', 'Николаевич', 5, 'operator1@polesie.by', '+375297777777', 'Цех №1', '2021-01-10', 'active'),
('EMP008', 'Ирина', 'Лебедева', 'Алексеевна', 5, 'operator2@polesie.by', '+375298888888', 'Цех №1', '2021-03-15', 'active'),
('EMP009', 'Михаил', 'Соколов', 'Петрович', 6, 'otk1@polesie.by', '+375299999999', 'ОТК', '2020-05-20', 'active'),
('EMP010', 'Татьяна', 'Попкова', 'Ивановна', 6, 'otk2@polesie.by', '+375291010101', 'ОТК', '2021-07-01', 'active'),
('EMP011', 'Виктор', 'Григорьев', 'Сергеевич', 7, 'store1@polesie.by', '+375291212121', 'Склад', '2019-04-10', 'active'),
('EMP012', 'Екатерина', 'Васильева', 'Андреевна', 7, 'store2@polesie.by', '+375291313131', 'Склад', '2020-08-15', 'active'),
('EMP013', 'Павел', 'Михайлов', 'Владимирович', 8, 'engineer1@polesie.by', '+375291414141', 'Инженерный отдел', '2018-11-01', 'active'),
('EMP014', 'Анна', 'Алексеева', 'Николаевна', 9, 'master1@polesie.by', '+375291515151', 'Цех №1', '2019-02-20', 'active'),
('EMP015', 'Игорь', 'Борисов', 'Дмитриевич', 5, 'operator3@polesie.by', '+375291616161', 'Цех №2', '2022-01-15', 'active');

-- Пользователи (пароли без хеша как запрошено)
INSERT INTO users (employee_id, username, password, role, is_active) VALUES
(1, 'admin', 'admin123', 'admin', TRUE),
(2, 'prod_head', 'production2024', 'manager', TRUE),
(3, 'sales1', 'sales123', 'manager', TRUE),
(4, 'sales2', 'sales456', 'manager', TRUE),
(5, 'tech1', 'tech2024', 'technologist', TRUE),
(6, 'tech2', 'tech456', 'technologist', TRUE),
(7, 'operator1', 'oper123', 'operator', TRUE),
(8, 'operator2', 'oper456', 'operator', TRUE),
(9, 'otk1', 'quality123', 'quality_inspector', TRUE),
(10, 'otk2', 'quality456', 'quality_inspector', TRUE),
(11, 'store1', 'store123', 'warehouse_keeper', TRUE),
(12, 'store2', 'store456', 'warehouse_keeper', TRUE),
(13, 'engineer1', 'eng2024', 'technologist', TRUE),
(14, 'master1', 'master123', 'manager', TRUE);

-- Клиенты
INSERT INTO customers (name, inn, address, phone, email, contact_person, country) VALUES
('ООО "Белэнерго"', '193123456', 'г. Минск, ул. Энергетиков 10', '+375171234567', 'info@belenergo.by', 'Петров А.С.', 'Беларусь'),
('ОАО "Гомсельмаш"', '500123789', 'г. Гомель, ул. Советская 1', '+375232345678', 'zakaz@gomselmash.by', 'Кравченко В.И.', 'Беларусь'),
('ЗАО "Минский тракторный завод"', '100456123', 'г. Минск, пр. Партизанский 19', '+375172567890', 'mtz@mtz.by', 'Лукашевич Н.П.', 'Беларусь'),
('ООО "Брестэлектромаш"', '291789456', 'г. Брест, ул. Московская 45', '+375162678901', 'bem@brem.by', 'Ковальчук Д.В.', 'Беларусь'),
('РУП "Гродноэнерго"', '400321654', 'г. Гродно, ул. Горького 91', '+375152789012', 'info@grodnoenergy.by', 'Новик И.О.', 'Беларусь'),
('ЧУП "Витебские электрические сети"', '300654987', 'г. Витебск, пр. Фрунзе 30', '+375212890123', 'ves@ves.by', 'Титов С.А.', 'Беларусь'),
('ООО "Могилевтрансстрой"', '700987321', 'г. Могилев, ул. Лазаренко 24', '+375222901234', 'mts@mogilev.by', 'Герасимов П.Р.', 'Беларусь'),
('АО "Интер РАО" (Россия)', '7701234567', 'г. Москва, ул. Щепкина 42', '+74951234567', 'info@inter-rao.ru', 'Смирнов К.Л.', 'Россия'),
('ТОВ "Укрэнергокомплект"', '12345678', 'г. Киев, пр. Победы 15', '+380441234567', 'info@uek.ua', 'Шевченко О.В.', 'Украина'),
('ООО "ЛитЭлектро"', '304567890', 'г. Вильнюс, ул. Гедимино 10', '+37052123456', 'info@litelectro.lt', 'Паулаускас Й.', 'Литва');

-- Продукция
INSERT INTO products (product_code, name, description, category, unit, base_price) VALUES
('MTR-001', 'Электродвигатель МТ-100', 'Асинхронный трехфазный двигатель мощностью 100 кВт', 'Электродвигатели', 'шт', 2500.00),
('MTR-002', 'Электродвигатель МТ-200', 'Асинхронный трехфазный двигатель мощностью 200 кВт', 'Электродвигатели', 'шт', 4200.00),
('MTR-003', 'Электродвигатель МТ-50', 'Асинхронный трехфазный двигатель мощностью 50 кВт', 'Электродвигатели', 'шт', 1800.00),
('GEN-001', 'Генератор ГС-150', 'Синхронный генератор 150 кВт', 'Генераторы', 'шт', 5500.00),
('GEN-002', 'Генератор ГС-300', 'Синхронный генератор 300 кВт', 'Генераторы', 'шт', 8900.00),
('TRF-001', 'Трансформатор ТМ-100', 'Силовой трансформатор 100 кВА', 'Трансформаторы', 'шт', 3200.00),
('TRF-002', 'Трансформатор ТМ-250', 'Силовой трансформатор 250 кВА', 'Трансформаторы', 'шт', 5800.00),
('TRF-003', 'Трансформатор ТМ-630', 'Силовой трансформатор 630 кВА', 'Трансформаторы', 'шт', 12500.00),
('PMP-001', 'Насосный агрегат НА-50', 'Центробежный насос с двигателем 50 кВт', 'Насосное оборудование', 'шт', 2100.00),
('PMP-002', 'Насосный агрегат НА-100', 'Центробежный насос с двигателем 100 кВт', 'Насосное оборудование', 'шт', 3400.00),
('CTR-001', 'Щит управления ЩУ-1', 'Шкаф управления электродвигателями', 'Устройства управления', 'шт', 850.00),
('CTR-002', 'Щит управления ЩУ-2', 'Шкаф управления с частотным преобразователем', 'Устройства управления', 'шт', 1650.00),
('CBL-001', 'Кабель силовой КВВГ 3х50', 'Кабель контрольный виниловый', 'Кабельная продукция', 'м', 15.50),
('CBL-002', 'Кабель силовой КВВГ 3х95', 'Кабель контрольный виниловый', 'Кабельная продукция', 'м', 28.00);

-- Заказы (разные периоды и статусы)
INSERT INTO orders (order_number, customer_id, order_date, delivery_date, priority, status, total_amount, manager_id, notes) VALUES
('ORD-2024-001', 1, '2024-01-15', '2024-02-15', 'normal', 'completed', 15000.00, 3, 'Плановый заказ на электродвигатели'),
('ORD-2024-002', 2, '2024-01-20', '2024-03-01', 'high', 'completed', 28500.00, 3, 'Срочный заказ для сельхозтехники'),
('ORD-2024-003', 3, '2024-02-01', '2024-03-15', 'normal', 'shipped', 45000.00, 4, 'Крупный заказ трансформаторов'),
('ORD-2024-004', 4, '2024-02-10', '2024-03-10', 'normal', 'ready', 12300.00, 3, 'Заказ насосного оборудования'),
('ORD-2024-005', 5, '2024-02-20', '2024-04-01', 'high', 'quality_check', 67800.00, 4, 'Комплексная поставка для энергосети'),
('ORD-2024-006', 6, '2024-03-01', '2024-04-15', 'normal', 'in_production', 23400.00, 3, 'Заказ электродвигателей'),
('ORD-2024-007', 7, '2024-03-05', '2024-04-20', 'low', 'in_production', 8900.00, 4, 'Щиты управления'),
('ORD-2024-008', 8, '2024-03-10', '2024-05-01', 'urgent', 'confirmed', 125000.00, 3, 'Экспортный заказ в Россию'),
('ORD-2024-009', 1, '2024-03-15', '2024-04-30', 'normal', 'new', 34500.00, 4, 'Повторный заказ'),
('ORD-2024-010', 9, '2024-03-18', '2024-05-15', 'normal', 'new', 56700.00, 3, 'Заказ в Украину'),
('ORD-2023-045', 2, '2023-11-10', '2023-12-20', 'normal', 'completed', 78900.00, 3, 'Заказ прошлого года'),
('ORD-2023-046', 3, '2023-11-25', '2024-01-15', 'high', 'completed', 145000.00, 4, 'Крупный заказ МТЗ'),
('ORD-2023-047', 10, '2023-12-01', '2024-02-01', 'normal', 'completed', 23400.00, 3, 'Экспорт в Литву'),
('ORD-2023-048', 5, '2023-12-10', '2024-01-30', 'urgent', 'completed', 89000.00, 4, 'Срочный заказ Гродноэнерго');

-- Состав заказов
INSERT INTO order_items (order_id, product_id, quantity, unit_price, total_price, status) VALUES
(1, 1, 5, 2500.00, 12500.00, 'completed'),
(1, 3, 2, 1800.00, 3600.00, 'completed'),
(2, 2, 6, 4200.00, 25200.00, 'completed'),
(2, 9, 1, 2100.00, 2100.00, 'completed'),
(3, 6, 10, 3200.00, 32000.00, 'completed'),
(3, 7, 2, 5800.00, 11600.00, 'completed'),
(3, 11, 1, 850.00, 850.00, 'completed'),
(4, 9, 3, 2100.00, 6300.00, 'completed'),
(4, 10, 2, 3400.00, 6800.00, 'completed'),
(5, 1, 10, 2500.00, 25000.00, 'completed'),
(5, 2, 5, 4200.00, 21000.00, 'completed'),
(5, 6, 3, 3200.00, 9600.00, 'completed'),
(5, 12, 5, 1650.00, 8250.00, 'completed'),
(6, 1, 8, 2500.00, 20000.00, 'in_production'),
(6, 11, 4, 850.00, 3400.00, 'pending'),
(7, 11, 6, 850.00, 5100.00, 'in_production'),
(7, 12, 2, 1650.00, 3300.00, 'pending'),
(8, 2, 20, 4200.00, 84000.00, 'pending'),
(8, 4, 5, 5500.00, 27500.00, 'pending'),
(8, 7, 2, 5800.00, 11600.00, 'pending'),
(9, 3, 15, 1800.00, 27000.00, 'pending'),
(9, 10, 2, 3400.00, 6800.00, 'pending'),
(10, 1, 12, 2500.00, 30000.00, 'pending'),
(10, 6, 4, 3200.00, 12800.00, 'pending'),
(10, 9, 3, 2100.00, 6300.00, 'pending'),
(11, 2, 15, 4200.00, 63000.00, 'completed'),
(11, 4, 3, 5500.00, 16500.00, 'completed'),
(12, 1, 25, 2500.00, 62500.00, 'completed'),
(12, 2, 15, 4200.00, 63000.00, 'completed'),
(12, 6, 5, 3200.00, 16000.00, 'completed'),
(13, 7, 4, 5800.00, 23200.00, 'completed'),
(13, 11, 1, 850.00, 850.00, 'completed'),
(14, 1, 20, 2500.00, 50000.00, 'completed'),
(14, 2, 8, 4200.00, 33600.00, 'completed'),
(14, 12, 3, 1650.00, 4950.00, 'completed');

-- Технологические этапы
INSERT INTO production_stages (name, code, description, sequence_order, estimated_hours) VALUES
('Подготовка материалов', 'PREP_MAT', 'Подбор и подготовка необходимых материалов', 1, 2.0),
('Раскрой заготовок', 'CUTTING', 'Раскрой металлических заготовок по размерам', 2, 3.0),
('Механическая обработка', 'MACHINING', 'Токарная и фрезерная обработка деталей', 3, 8.0),
('Сварочные работы', 'WELDING', 'Сварка корпусных элементов', 4, 4.0),
('Грунтовка и покраска', 'PAINTING', 'Нанесение защитных покрытий', 5, 6.0),
('Намотка обмоток', 'WINDING', 'Намотка медных обмоток статора/ротора', 6, 12.0),
('Сборка узла', 'ASSEMBLY', 'Сборка основных узлов изделия', 7, 8.0),
('Электромонтаж', 'ELECTRICAL', 'Подключение электрических цепей', 8, 4.0),
('Предварительные испытания', 'PRE_TEST', 'Проверка параметров перед ОТК', 9, 3.0),
('Контроль качества', 'QC_CHECK', 'Финальный контроль качества', 10, 2.0),
('Упаковка', 'PACKING', 'Упаковка готовой продукции', 11, 1.5),
('Отгрузка', 'SHIPPING', 'Подготовка к отгрузке', 12, 1.0);

-- Материалы
INSERT INTO materials (material_code, name, category, unit, min_stock, current_stock, price, supplier, location) VALUES
('MAT-ST-001', 'Сталь листовая Ст3 2мм', 'Металлопрокат', 'кг', 500, 2500.00, 2.50, 'Белорусская металлургическая компания', 'Склад А1'),
('MAT-ST-002', 'Сталь листовая Ст3 5мм', 'Металлопрокат', 'кг', 300, 1800.00, 2.80, 'БМЗ', 'Склад А1'),
('MAT-CU-001', 'Медная проволока ПЭТВ-2 0.5мм', 'Электропроводники', 'кг', 100, 450.00, 45.00, 'МедьПром', 'Склад Б2'),
('MAT-CU-002', 'Медная проволока ПЭТВ-2 1.0мм', 'Электропроводники', 'кг', 100, 380.00, 42.00, 'МедьПром', 'Склад Б2'),
('MAT-CU-003', 'Медная шина ШМ 10х1', 'Электропроводники', 'м', 200, 850.00, 18.00, 'ЦветМет', 'Склад Б2'),
('MAT-INS-001', 'Изоляция электрокартон ЭВ', 'Изоляционные материалы', 'лист', 100, 350.00, 5.50, 'Изолятор', 'Склад Б1'),
('MAT-INS-002', 'Лакоткань ЛХС-2', 'Изоляционные материалы', 'м', 150, 420.00, 12.00, 'Изолятор', 'Склад Б1'),
('MAT-PRS-001', 'Подшипник 6309-2RS', 'Комплектующие', 'шт', 50, 180.00, 25.00, 'ПодшипникСервис', 'Склад В1'),
('MAT-PRS-002', 'Подшипник 6312-2RS', 'Комплектующие', 'шт', 40, 120.00, 38.00, 'ПодшипникСервис', 'Склад В1'),
('MAT-PRS-003', 'Подшипник 6315-2RS', 'Комплектующие', 'шт', 30, 85.00, 52.00, 'ПодшипникСервис', 'Склад В1'),
('MAT-PNT-001', 'Грунтовка ГФ-021', 'Лакокрасочные материалы', 'кг', 50, 220.00, 8.50, 'БелКраска', 'Склад Г1'),
('MAT-PNT-002', 'Эмаль ПФ-115 синяя', 'Лакокрасочные материалы', 'кг', 40, 180.00, 12.00, 'БелКраска', 'Склад Г1'),
('MAT-PNT-003', 'Эмаль ПФ-115 серая', 'Лакокрасочные материалы', 'кг', 40, 165.00, 12.00, 'БелКраска', 'Склад Г1'),
('MAT-EL-001', 'Клеммная колодка ТК-10', 'Электрофурнитура', 'шт', 200, 850.00, 3.50, 'ЭлектроКомплект', 'Склад В2'),
('MAT-EL-002', 'Кабель ПВ3 2.5мм²', 'Кабельная продукция', 'м', 500, 1200.00, 2.80, 'АвтоПровод', 'Склад А2'),
('MAT-EL-003', 'Автоматический выключатель АП-50', 'Электрофурнитура', 'шт', 100, 320.00, 15.00, 'ЭлектроКомплект', 'Склад В2');

-- Оборудование
INSERT INTO equipment (equipment_code, name, type, location, status, last_maintenance, next_maintenance) VALUES
('EQ-CNC-001', 'Токарный станок с ЧПУ 16К20Ф3', 'Токарный станок', 'Цех №1, участок 1', 'operational', '2024-02-15', '2024-05-15'),
('EQ-CNC-002', 'Фрезерный станок с ЧПУ ВМ127', 'Фрезерный станок', 'Цех №1, участок 1', 'operational', '2024-03-01', '2024-06-01'),
('EQ-WLD-001', 'Сварочный полуавтомат MIG-350', 'Сварочное оборудование', 'Цех №1, участок 2', 'operational', '2024-02-20', '2024-05-20'),
('EQ-WLD-002', 'Сварочный инвертор TIG-200', 'Сварочное оборудование', 'Цех №1, участок 2', 'maintenance', '2024-03-10', '2024-03-20'),
('EQ-PNT-001', 'Камера окрасочная КП-1', 'Окрасочное оборудование', 'Цех №2, участок 1', 'operational', '2024-01-25', '2024-04-25'),
('EQ-WND-001', 'Станок намоточный НСТ-500', 'Намоточное оборудование', 'Цех №2, участок 2', 'operational', '2024-03-05', '2024-06-05'),
('EQ-WND-002', 'Станок намоточный НСТ-1000', 'Намоточное оборудование', 'Цех №2, участок 2', 'operational', '2024-02-28', '2024-05-28'),
('EQ-TST-001', 'Стенд испытательный СИЭ-100', 'Испытательное оборудование', 'Лаборатория ОТК', 'operational', '2024-03-01', '2024-06-01'),
('EQ-TST-002', 'Прибор измерения сопротивления МИКО-1', 'Измерительное оборудование', 'Лаборатория ОТК', 'operational', '2024-02-15', '2024-05-15'),
('EQ-LFT-001', 'Кран мостовой 5т', 'Подъемное оборудование', 'Цех №1', 'operational', '2024-01-10', '2024-04-10'),
('EQ-LFT-002', 'Кран мостовой 10т', 'Подъемное оборудование', 'Цех №2', 'operational', '2024-01-15', '2024-04-15'),
('EQ-CMP-001', 'Компрессор воздушный КВ-50', 'Компрессорное оборудование', 'Компрессорная', 'broken', '2024-02-01', '2024-03-25');

-- Примеры производственных заданий
INSERT INTO production_tasks (task_number, order_item_id, product_id, stage_id, quantity, planned_start, planned_end, status, assigned_to, work_center, notes) VALUES
('TSK-2024-001', 1, 1, 1, 5, '2024-01-16 08:00:00', '2024-01-16 12:00:00', 'completed', 7, 'Цех №1', 'Подготовка материалов выполнена'),
('TSK-2024-002', 1, 1, 2, 5, '2024-01-16 13:00:00', '2024-01-17 10:00:00', 'completed', 7, 'Цех №1', 'Раскрой завершен'),
('TSK-2024-003', 1, 1, 3, 5, '2024-01-17 11:00:00', '2024-01-18 17:00:00', 'completed', 8, 'Цех №1, уч.1', 'Мехобработка на ЧПУ'),
('TSK-2024-004', 1, 1, 6, 5, '2024-01-19 08:00:00', '2024-01-22 17:00:00', 'completed', 15, 'Цех №2, уч.2', 'Намотка обмоток'),
('TSK-2024-005', 1, 1, 7, 5, '2024-01-23 08:00:00', '2024-01-25 17:00:00', 'completed', 7, 'Цех №1', 'Сборка двигателя'),
('TSK-2024-006', 1, 1, 8, 5, '2024-01-26 08:00:00', '2024-01-26 17:00:00', 'completed', 8, 'Цех №1', 'Электромонтаж'),
('TSK-2024-007', 1, 1, 9, 5, '2024-01-29 08:00:00', '2024-01-29 14:00:00', 'completed', 7, 'Цех №1', 'Предварительные испытания'),
('TSK-2024-008', 1, 1, 10, 5, '2024-01-29 15:00:00', '2024-01-30 12:00:00', 'completed', NULL, 'ОТК', 'Передано на ОТК'),
('TSK-2024-009', 1, 1, 11, 5, '2024-01-30 13:00:00', '2024-01-30 17:00:00', 'completed', 11, 'Цех №1', 'Упаковка'),
('TSK-2024-010', 1, 1, 12, 5, '2024-01-31 08:00:00', '2024-01-31 10:00:00', 'completed', 11, 'Склад', 'Отгружено'),
('TSK-2024-011', 14, 1, 1, 20, '2024-03-11 08:00:00', '2024-03-11 16:00:00', 'completed', 7, 'Цех №1', 'Подготовка материалов'),
('TSK-2024-012', 14, 1, 2, 20, '2024-03-12 08:00:00', '2024-03-13 17:00:00', 'completed', 8, 'Цех №1', 'Раскрой'),
('TSK-2024-013', 14, 1, 3, 20, '2024-03-14 08:00:00', '2024-03-18 17:00:00', 'in_progress', 7, 'Цех №1, уч.1', 'Мехобработка в процессе'),
('TSK-2024-014', 14, 1, 6, 20, '2024-03-19 08:00:00', '2024-03-25 17:00:00', 'planned', 15, 'Цех №2, уч.2', 'Запланирована намотка'),
('TSK-2024-015', 15, 11, 1, 4, '2024-03-06 08:00:00', '2024-03-06 10:00:00', 'completed', 11, 'Склад', 'Материалы получены'),
('TSK-2024-016', 15, 11, 3, 4, '2024-03-06 11:00:00', '2024-03-07 17:00:00', 'completed', 8, 'Цех №1, уч.1', 'Обработка корпуса'),
('TSK-2024-017', 15, 11, 5, 4, '2024-03-08 08:00:00', '2024-03-08 17:00:00', 'completed', NULL, 'Цех №2, уч.1', 'Покраска'),
('TSK-2024-018', 15, 11, 7, 4, '2024-03-11 08:00:00', '2024-03-12 17:00:00', 'in_progress', 7, 'Цех №1', 'Сборка щита'),
('TSK-2024-019', 15, 11, 8, 4, '2024-03-13 08:00:00', '2024-03-13 17:00:00', 'planned', 8, 'Цех №1', 'Электромонтаж'),
('TSK-2024-020', 16, 11, 1, 6, '2024-03-06 08:00:00', '2024-03-06 11:00:00', 'completed', 12, 'Склад', 'Материалы подготовлены'),
('TSK-2024-021', 16, 11, 3, 6, '2024-03-06 13:00:00', '2024-03-08 17:00:00', 'in_progress', 7, 'Цех №1, уч.1', 'Мехобработка'),
('TSK-2024-022', 17, 12, 1, 2, '2024-03-08 08:00:00', '2024-03-08 10:00:00', 'pending', NULL, 'Склад', 'Ожидает начала'),
('TSK-2024-023', 10, 1, 1, 10, '2024-02-21 08:00:00', '2024-02-21 16:00:00', 'completed', 7, 'Цех №1', 'Подготовка'),
('TSK-2024-024', 10, 1, 2, 10, '2024-02-22 08:00:00', '2024-02-23 17:00:00', 'completed', 8, 'Цех №1', 'Раскрой'),
('TSK-2024-025', 10, 1, 3, 10, '2024-02-26 08:00:00', '2024-03-01 17:00:00', 'completed', 7, 'Цех №1, уч.1', 'Мехобработка'),
('TSK-2024-026', 10, 1, 4, 10, '2024-03-04 08:00:00', '2024-03-05 17:00:00', 'completed', NULL, 'Цех №1, уч.2', 'Сварка'),
('TSK-2024-027', 10, 1, 5, 10, '2024-03-06 08:00:00', '2024-03-07 17:00:00', 'completed', NULL, 'Цех №2, уч.1', 'Покраска'),
('TSK-2024-028', 10, 1, 6, 10, '2024-03-08 08:00:00', '2024-03-14 17:00:00', 'completed', 15, 'Цех №2, уч.2', 'Намотка'),
('TSK-2024-029', 10, 1, 7, 10, '2024-03-15 08:00:00', '2024-03-19 17:00:00', 'completed', 7, 'Цех №1', 'Сборка'),
('TSK-2024-030', 10, 1, 8, 10, '2024-03-20 08:00:00', '2024-03-20 17:00:00', 'completed', 8, 'Цех №1', 'Электромонтаж'),
('TSK-2024-031', 10, 1, 9, 10, '2024-03-21 08:00:00', '2024-03-21 17:00:00', 'completed', 7, 'Цех №1', 'Испытания'),
('TSK-2024-032', 10, 1, 10, 10, '2024-03-22 08:00:00', '2024-03-22 17:00:00', 'completed', NULL, 'ОТК', 'Контроль качества');

-- Контроль качества
INSERT INTO quality_checks (check_number, production_task_id, inspector_id, check_date, check_type, result, defects_found, defects_description, comments) VALUES
('QC-2024-001', 8, 9, '2024-01-29 16:00:00', 'final', 'passed', 0, NULL, 'Все параметры в норме. Двигатели готовы к отгрузке.'),
('QC-2024-002', 32, 9, '2024-03-22 15:00:00', 'final', 'passed', 0, NULL, 'Партия из 10 двигателей прошла все испытания успешно.'),
('QC-2024-003', 25, 10, '2024-03-01 16:00:00', 'in_process', 'passed', 0, NULL, 'Мехобработка выполнена согласно чертежам.'),
('QC-2024-004', 26, 10, '2024-03-05 15:00:00', 'in_process', 'conditional', 2, 'Незначительные дефекты сварных швов', 'Требуется зачистка швов перед покраской'),
('QC-2024-005', 27, 9, '2024-03-07 16:00:00', 'in_process', 'passed', 0, NULL, 'Покрытие равномерное, толщина в норме.'),
('QC-2024-006', 28, 10, '2024-03-14 16:00:00', 'in_process', 'passed', 0, NULL, 'Сопротивление обмоток соответствует требованиям.'),
('QC-2024-007', 29, 9, '2024-03-19 16:00:00', 'in_process', 'passed', 0, NULL, 'Сборка выполнена качественно.'),
('QC-2024-008', 30, 10, '2024-03-20 16:00:00', 'in_process', 'passed', 0, NULL, 'Электрические соединения проверены.'),
('QC-2024-009', 31, 9, '2024-03-21 16:00:00', 'final', 'passed', 0, NULL, 'Испытания под нагрузкой успешны.');

-- Движение материалов
INSERT INTO material_movements (material_id, movement_type, quantity, reference_type, reference_id, warehouse_from, warehouse_to, employee_id, notes) VALUES
(1, 'receipt', 1000.00, 'purchase_order', 1, NULL, 'Склад А1', 11, 'Поступление от БМЗ'),
(2, 'receipt', 800.00, 'purchase_order', 1, NULL, 'Склад А1', 11, 'Поступление от БМЗ'),
(3, 'receipt', 200.00, 'purchase_order', 2, NULL, 'Склад Б2', 12, 'Поступление медной проволоки'),
(4, 'receipt', 150.00, 'purchase_order', 2, NULL, 'Склад Б2', 12, 'Поступление медной проволоки'),
(8, 'receipt', 100.00, 'purchase_order', 3, NULL, 'Склад В1', 11, 'Подшипники'),
(1, 'consumption', 250.00, 'production_task', 1, 'Склад А1', 'Цех №1', 7, 'На заказ ORD-2024-001'),
(3, 'consumption', 45.00, 'production_task', 4, 'Склад Б2', 'Цех №2', 15, 'На намотку обмоток'),
(8, 'consumption', 10.00, 'production_task', 5, 'Склад В1', 'Цех №1', 7, 'На сборку двигателей'),
(11, 'consumption', 25.00, 'production_task', 7, 'Склад Г1', 'Цех №2', NULL, 'На покраску'),
(12, 'consumption', 30.00, 'production_task', 7, 'Склад Г1', 'Цех №2', NULL, 'На покраску синей эмалью'),
(1, 'consumption', 500.00, 'production_task', 11, 'Склад А1', 'Цех №1', 7, 'На крупную партию'),
(3, 'consumption', 180.00, 'production_task', 14, 'Склад Б2', 'Цех №2', 15, 'На намотку 20 двигателей');

-- Журнал активности (примеры)
INSERT INTO activity_log (user_id, action, module, record_id, description, ip_address) VALUES
(1, 'login', 'auth', NULL, 'Вход в систему', '192.168.1.100'),
(2, 'view', 'dashboard', NULL, 'Просмотр панели управления', '192.168.1.101'),
(3, 'create', 'orders', 9, 'Создан новый заказ ORD-2024-009', '192.168.1.102'),
(4, 'update', 'orders', 10, 'Обновлен заказ ORD-2024-010', '192.168.1.103'),
(5, 'create', 'production', 13, 'Создано производственное задание TSK-2024-013', '192.168.1.104'),
(9, 'create', 'quality', 9, 'Создана проверка качества QC-2024-009', '192.168.1.105'),
(11, 'create', 'warehouse', NULL, 'Проведено поступление материалов', '192.168.1.106'),
(2, 'approve', 'production', 13, 'Утверждено производственное задание', '192.168.1.101'),
(3, 'complete', 'orders', 1, 'Заказ ORD-2024-001 завершен', '192.168.1.102'),
(1, 'view', 'reports', NULL, 'Сформирован отчет по производству', '192.168.1.100');

-- Транзакции материалов
INSERT INTO material_transactions (material_id, operation_type, quantity, warehouse_from, warehouse_to, reference_type, reference_id, user_id, notes) VALUES
(1, 'receipt', 1000.00, NULL, 'Склад А1', 'purchase_order', 1, 11, 'Поступление от БМЗ'),
(2, 'receipt', 800.00, NULL, 'Склад А1', 'purchase_order', 1, 11, 'Поступление от БМЗ'),
(3, 'receipt', 200.00, NULL, 'Склад Б2', 'purchase_order', 2, 12, 'Поступление медной проволоки'),
(4, 'receipt', 150.00, NULL, 'Склад Б2', 'purchase_order', 2, 12, 'Поступление медной проволоки'),
(8, 'receipt', 100.00, NULL, 'Склад В1', 'purchase_order', 3, 11, 'Подшипники'),
(1, 'consumption', 250.00, 'Склад А1', 'Цех №1', 'production_task', 1, NULL, 'На заказ ORD-2024-001'),
(3, 'consumption', 45.00, 'Склад Б2', 'Цех №2', 'production_task', 4, NULL, 'На намотку обмоток'),
(8, 'consumption', 10.00, 'Склад В1', 'Цех №1', 'production_task', 5, NULL, 'На сборку двигателей'),
(11, 'consumption', 25.00, 'Склад Г1', 'Цех №2', 'production_task', 7, NULL, 'На покраску'),
(12, 'consumption', 30.00, 'Склад Г1', 'Цех №2', 'production_task', 7, NULL, 'На покраску синей эмалью'),
(1, 'consumption', 500.00, 'Склад А1', 'Цех №1', 'production_task', 11, NULL, 'На крупную партию'),
(3, 'consumption', 180.00, 'Склад Б2', 'Цех №2', 'production_task', 14, NULL, 'На намотку 20 двигателей');

-- Отгрузки
INSERT INTO shipments (order_id, order_item_id, shipment_number, shipment_date, quantity, status, notes) VALUES
(1, 1, 'SHP-2024-001', '2024-02-15 10:00:00', 5, 'delivered', 'Отгрузка электродвигателей МТ-100'),
(1, 2, 'SHP-2024-002', '2024-02-15 10:00:00', 2, 'delivered', 'Отгрузка электродвигателей МТ-50'),
(2, 3, 'SHP-2024-003', '2024-03-01 14:00:00', 6, 'delivered', 'Срочная отгрузка для сельхозтехники'),
(3, 5, 'SHP-2024-004', '2024-03-15 09:00:00', 10, 'delivered', 'Крупная поставка трансформаторов');

-- Журнал технического обслуживания
INSERT INTO maintenance_logs (equipment_id, technician_id, maintenance_type, description, scheduled_date, completed_date, status, cost, notes) VALUES
(1, 13, 'preventive', 'Плановое техническое обслуживание', '2024-01-15', '2024-01-15', 'completed', 150.00, 'Замена масла, проверка подшипников'),
(2, 13, 'corrective', 'Ремонт после поломки', '2024-02-10', '2024-02-12', 'completed', 450.00, 'Замена двигателя привода'),
(3, NULL, 'preventive', 'Плановая проверка', '2024-03-01', NULL, 'planned', NULL, 'Запланировано ТО крана'),
(4, 13, 'inspection', 'Ежегодная проверка безопасности', '2024-01-20', '2024-01-20', 'completed', 100.00, 'Все параметры в норме'),
(5, NULL, 'preventive', 'Плановое ТО', '2024-03-15', NULL, 'in_progress', NULL, 'Текущее обслуживание сварочного аппарата');
