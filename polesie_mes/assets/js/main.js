/**
 * Основной JavaScript файл системы PolesieMES
 */

document.addEventListener('DOMContentLoaded', function() {
    // Инициализация tooltip'ов Bootstrap
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Инициализация popover'ов Bootstrap
    var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
    popoverTriggerList.map(function (popoverTriggerEl) {
        return new bootstrap.Popover(popoverTriggerEl);
    });
    
    // Автозакрытие алертов через 5 секунд
    var alerts = document.querySelectorAll('.alert:not(.alert-permanent)');
    alerts.forEach(function(alert) {
        setTimeout(function() {
            var bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        }, 5000);
    });
    
    // Подтверждение действий
    var confirmButtons = document.querySelectorAll('[data-confirm]');
    confirmButtons.forEach(function(button) {
        button.addEventListener('click', function(e) {
            var message = this.getAttribute('data-confirm') || 'Вы уверены?';
            if (!confirm(message)) {
                e.preventDefault();
                return false;
            }
        });
    });
    
    // Автоподсчет высоты textarea
    var textareas = document.querySelectorAll('textarea.auto-grow');
    textareas.forEach(function(textarea) {
        textarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
        });
    });
    
    // Форматирование чисел в input
    var numberInputs = document.querySelectorAll('input[data-number-format]');
    numberInputs.forEach(function(input) {
        input.addEventListener('blur', function() {
            var value = parseFloat(this.value.replace(/\s/g, '').replace(',', '.'));
            if (!isNaN(value)) {
                var decimals = this.getAttribute('data-number-format') || 2;
                this.value = value.toFixed(decimals).replace('.', ',');
            }
        });
    });
    
    // Поиск по таблицам
    var searchInputs = document.querySelectorAll('input[data-table-search]');
    searchInputs.forEach(function(input) {
        input.addEventListener('keyup', function() {
            var searchTerm = this.value.toLowerCase();
            var tableId = this.getAttribute('data-table-search');
            var table = document.getElementById(tableId);
            
            if (table) {
                var rows = table.querySelectorAll('tbody tr');
                rows.forEach(function(row) {
                    var text = row.textContent.toLowerCase();
                    row.style.display = text.includes(searchTerm) ? '' : 'none';
                });
            }
        });
    });
    
    // Сортировка таблиц
    var sortHeaders = document.querySelectorAll('th[data-sortable]');
    sortHeaders.forEach(function(header) {
        header.addEventListener('click', function() {
            var table = this.closest('table');
            var columnIndex = this.cellIndex;
            var ascending = !this.classList.contains('sort-asc');
            
            // Удаление классов сортировки со всех заголовков
            table.querySelectorAll('th').forEach(function(th) {
                th.classList.remove('sort-asc', 'sort-desc');
            });
            
            // Добавление класса текущему заголовку
            this.classList.add(ascending ? 'sort-asc' : 'sort-desc');
            
            // Сортировка
            sortTable(table, columnIndex, ascending);
        });
    });
    
    // Печать страницы
    window.printPage = function() {
        window.print();
    };
    
    // Экспорт таблицы в CSV
    window.exportTableToCSV = function(tableId, filename) {
        var table = document.getElementById(tableId);
        if (!table) return;
        
        var rows = table.querySelectorAll('tr');
        var csv = [];
        
        rows.forEach(function(row) {
            var cols = row.querySelectorAll('td, th');
            var csvRow = [];
            
            cols.forEach(function(col) {
                csvRow.push('"' + col.textContent.trim() + '"');
            });
            
            csv.push(csvRow.join(','));
        });
        
        downloadCSV(csv.join('\n'), filename);
    };
    
    function downloadCSV(csv, filename) {
        var blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
        var link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = filename;
        link.click();
    }
    
    // Обновление счетчиков в реальном времени
    window.updateCounter = function(elementId, value) {
        var element = document.getElementById(elementId);
        if (element) {
            animateValue(element, parseInt(element.textContent), value, 1000);
        }
    };
    
    function animateValue(obj, start, end, duration) {
        var range = end - start;
        var current = start;
        var increment = end > start ? 1 : -1;
        var stepTime = Math.abs(Math.floor(duration / range));
        
        var timer = setInterval(function() {
            current += increment;
            obj.textContent = current;
            if (current == end) {
                clearInterval(timer);
            }
        }, stepTime);
    }
    
    // Уведомления
    window.showNotification = function(message, type) {
        type = type || 'info';
        
        var icons = {
            success: 'check-circle',
            error: 'exclamation-circle',
            warning: 'exclamation-triangle',
            info: 'info-circle'
        };
        
        var notification = document.createElement('div');
        notification.className = 'alert alert-' + type + ' alert-dismissible fade show position-fixed';
        notification.style.cssText = 'top: 70px; right: 20px; z-index: 9999; min-width: 300px;';
        notification.innerHTML = '<i class="fas fa-' + icons[type] + ' me-2"></i>' + message + 
                                '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
        
        document.body.appendChild(notification);
        
        setTimeout(function() {
            notification.remove();
        }, 5000);
    };
    
    // AJAX запросы
    window.ajaxRequest = function(url, method, data) {
        method = method || 'GET';
        
        return fetch(url, {
            method: method,
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: method !== 'GET' ? JSON.stringify(data) : null
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        });
    };
    
    // Модальные окна
    window.openModal = function(modalId) {
        var modal = new bootstrap.Modal(document.getElementById(modalId));
        modal.show();
    };
    
    window.closeModal = function(modalId) {
        var modalElement = document.getElementById(modalId);
        var modal = bootstrap.Modal.getInstance(modalElement);
        if (modal) {
            modal.hide();
        }
    };
    
    // Табы
    var tabTriggers = document.querySelectorAll('[data-bs-toggle="tab"]');
    tabTriggers.forEach(function(trigger) {
        trigger.addEventListener('shown.bs.tab', function(event) {
            // Сохранение активного таба в localStorage
            if (event.target.id) {
                localStorage.setItem('activeTab', event.target.id);
            }
        });
    });
    
    // Восстановление активного таба
    var activeTab = localStorage.getItem('activeTab');
    if (activeTab) {
        var tabElement = document.querySelector('[id="' + activeTab + '"]');
        if (tabElement) {
            var tab = new bootstrap.Tab(tabElement);
            tab.show();
        }
    }
    
    // Кнопка "Наверх"
    var scrollButton = document.createElement('button');
    scrollButton.className = 'btn btn-primary position-fixed';
    scrollButton.style.cssText = 'bottom: 20px; right: 20px; display: none; z-index: 1000;';
    scrollButton.innerHTML = '<i class="fas fa-arrow-up"></i>';
    scrollButton.onclick = function() {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };
    document.body.appendChild(scrollButton);
    
    window.addEventListener('scroll', function() {
        if (window.pageYOffset > 300) {
            scrollButton.style.display = 'block';
        } else {
            scrollButton.style.display = 'none';
        }
    });
    
    // Функция сортировки таблицы
    function sortTable(table, column, ascending) {
        var rows = Array.from(table.querySelectorAll('tbody tr'));
        
        rows.sort(function(a, b) {
            var aValue = a.cells[column].textContent.trim();
            var bValue = b.cells[column].textContent.trim();
            
            // Попытка числового сравнения
            var aNum = parseFloat(aValue.replace(',', '.'));
            var bNum = parseFloat(bValue.replace(',', '.'));
            
            if (!isNaN(aNum) && !isNaN(bNum)) {
                return ascending ? aNum - bNum : bNum - aNum;
            }
            
            // Строковое сравнение
            return ascending ? 
                aValue.localeCompare(bValue, 'ru') : 
                bValue.localeCompare(aValue, 'ru');
        });
        
        rows.forEach(function(row) {
            table.querySelector('tbody').appendChild(row);
        });
    }
    
    console.log('PolesieMES initialized successfully');
});
