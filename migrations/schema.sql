/**
 * Project: Smart Building Energy Management Bot
 * File: migrations/schema.sql
 * Author: Amin Davodian (Mohammadamin Davodian)
 * Website: https://senioramin.com
 * LinkedIn: https://linkedin.com/in/SudoAmin
 * GitHub: https://github.com/SeniorAminam
 * Created: 2025-12-11
 * 
 * Purpose: Database schema for Smart Building Energy Management System
 * Developed by Amin Davodian
 */

-- Create database
CREATE DATABASE IF NOT EXISTS smart_building CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE smart_building;

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    telegram_id BIGINT UNSIGNED NOT NULL UNIQUE,
    username VARCHAR(100),
    first_name VARCHAR(100),
    role ENUM('admin', 'manager', 'consumer') NOT NULL DEFAULT 'consumer',
    building_id BIGINT UNSIGNED NULL,
    unit_id BIGINT UNSIGNED NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_telegram_id (telegram_id),
    INDEX idx_role (role),
    INDEX idx_building_id (building_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Buildings table
CREATE TABLE IF NOT EXISTS buildings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    address TEXT,
    manager_id BIGINT UNSIGNED NULL,
    total_floors INT UNSIGNED DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_manager_id (manager_id),
    FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Units table
CREATE TABLE IF NOT EXISTS units (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    building_id BIGINT UNSIGNED NOT NULL,
    floor_number INT NOT NULL,
    unit_name VARCHAR(50) NOT NULL,
    area_m2 DECIMAL(10, 2) DEFAULT 0,
    occupants_count INT UNSIGNED DEFAULT 1,
    owner_id BIGINT UNSIGNED NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_building_id (building_id),
    INDEX idx_owner_id (owner_id),
    UNIQUE KEY unique_unit (building_id, floor_number, unit_name),
    FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Digital Twin states per unit
CREATE TABLE IF NOT EXISTS unit_twin_states (
    unit_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    scenario ENUM('empty', 'family', 'party', 'night', 'travel') NOT NULL DEFAULT 'family',
    season ENUM('spring', 'summer', 'autumn', 'winter') NOT NULL DEFAULT 'spring',
    eco_mode BOOLEAN NOT NULL DEFAULT FALSE,
    lights_on BOOLEAN NOT NULL DEFAULT TRUE,
    ac_mode ENUM('off', 'low', 'medium', 'high') NOT NULL DEFAULT 'off',
    heating_temp INT NOT NULL DEFAULT 22,
    water_heater_on BOOLEAN NOT NULL DEFAULT TRUE,
    cost_sensitivity INT NOT NULL DEFAULT 50,
    green_sensitivity INT NOT NULL DEFAULT 50,
    monthly_budget_toman BIGINT UNSIGNED NOT NULL DEFAULT 1500000,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_scenario (scenario),
    INDEX idx_season (season),
    FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Consumption readings table
CREATE TABLE IF NOT EXISTS consumption_readings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    unit_id BIGINT UNSIGNED NOT NULL,
    metric_type ENUM('water', 'electricity', 'gas') NOT NULL,
    value DECIMAL(12, 3) NOT NULL,
    simulated BOOLEAN DEFAULT TRUE,
    timestamp TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_unit_metric (unit_id, metric_type),
    INDEX idx_timestamp (timestamp),
    FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Consumption limits table
CREATE TABLE IF NOT EXISTS consumption_limits (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    unit_id BIGINT UNSIGNED NOT NULL,
    metric_type ENUM('water', 'electricity', 'gas') NOT NULL,
    monthly_limit DECIMAL(12, 3) NOT NULL DEFAULT 0,
    price_per_unit DECIMAL(10, 2) NOT NULL DEFAULT 0,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_limit (unit_id, metric_type, period_start),
    INDEX idx_period (period_start, period_end),
    FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Energy credits table
CREATE TABLE IF NOT EXISTS energy_credits (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    unit_id BIGINT UNSIGNED NOT NULL,
    metric_type ENUM('water', 'electricity', 'gas') NOT NULL,
    balance DECIMAL(12, 3) NOT NULL DEFAULT 0,
    last_calculated TIMESTAMP NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_credit (unit_id, metric_type),
    INDEX idx_balance (balance),
    FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Credit transactions table
CREATE TABLE IF NOT EXISTS credit_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    from_unit_id BIGINT UNSIGNED NULL,
    to_unit_id BIGINT UNSIGNED NOT NULL,
    metric_type ENUM('water', 'electricity', 'gas') NOT NULL,
    amount DECIMAL(12, 3) NOT NULL,
    price_per_credit DECIMAL(10, 2) NOT NULL,
    total_price DECIMAL(12, 2) NOT NULL,
    transaction_type ENUM('auto_balance', 'manual_sell', 'manual_buy', 'system_purchase') NOT NULL,
    status ENUM('pending', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    INDEX idx_from_unit (from_unit_id),
    INDEX idx_to_unit (to_unit_id),
    INDEX idx_status (status),
    INDEX idx_created (created_at),
    FOREIGN KEY (from_unit_id) REFERENCES units(id) ON DELETE SET NULL,
    FOREIGN KEY (to_unit_id) REFERENCES units(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Alerts table
CREATE TABLE IF NOT EXISTS alerts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    unit_id BIGINT UNSIGNED NOT NULL,
    alert_type ENUM('over_consumption', 'leak_suspected', 'low_credit', 'high_cost', 'system_message') NOT NULL,
    severity ENUM('info', 'warning', 'critical') NOT NULL DEFAULT 'info',
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at TIMESTAMP NULL,
    INDEX idx_unit_unread (unit_id, is_read),
    INDEX idx_created (created_at),
    FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Monthly invoices table
CREATE TABLE IF NOT EXISTS monthly_invoices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    unit_id BIGINT UNSIGNED NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    water_consumption DECIMAL(12, 3) DEFAULT 0,
    electricity_consumption DECIMAL(12, 3) DEFAULT 0,
    gas_consumption DECIMAL(12, 3) DEFAULT 0,
    water_cost DECIMAL(12, 2) DEFAULT 0,
    electricity_cost DECIMAL(12, 2) DEFAULT 0,
    gas_cost DECIMAL(12, 2) DEFAULT 0,
    credits_earned DECIMAL(12, 2) DEFAULT 0,
    credits_spent DECIMAL(12, 2) DEFAULT 0,
    total_payable DECIMAL(12, 2) DEFAULT 0,
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_invoice (unit_id, period_start),
    INDEX idx_period (period_start, period_end),
    FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- System settings table
CREATE TABLE IF NOT EXISTS system_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NOT NULL,
    description TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default settings
INSERT INTO system_settings (setting_key, setting_value, description) VALUES
('base_price_water', '1500', 'قیمت پایه هر واحد آب (تومان)'),
('base_price_electricity', '2500', 'قیمت پایه هر واحد برق (تومان)'),
('base_price_gas', '2000', 'قیمت پایه هر واحد گاز (تومان)'),
('carbon_factor_water', '0.0003', 'ضریب کربن هر لیتر آب (kg CO2e)'),
('carbon_factor_electricity', '0.7', 'ضریب کربن هر kWh برق (kg CO2e)'),
('carbon_factor_gas', '2.0', 'ضریب کربن هر مترمکعب گاز (kg CO2e)'),
('carbon_daily_target_kg', '10', 'هدف روزانه کربن واحد (kg CO2e)'),
('default_water_limit', '150', 'سقف پیش‌فرض آب ماهانه (مترمکعب)'),
('default_electricity_limit', '500', 'سقف پیش‌فرض برق ماهانه (کیلووات)'),
('default_gas_limit', '100', 'سقف پیش‌فرض گاز ماهانه (مترمکعب)'),
('alert_threshold_percent', '20', 'درصد افزایش برای هشدار بیش‌مصرف'),
('simulation_variance', '15', 'درصد واریانس در شبیه‌سازی داده'),
('auto_balance_enabled', '1', 'فعال بودن بالانس خودکار'),
('demand_price_multiplier', '0.2', 'ضریب افزایش قیمت بر اساس تقاضا')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
