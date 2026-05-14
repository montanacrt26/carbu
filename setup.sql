-- ═══════════════════════════════════════════════════════════════════════════
-- CarbuFrance - Database Setup
-- ═══════════════════════════════════════════════════════════════════════════
-- 
-- Instructions:
-- 1. Ouvrez phpMyAdmin
-- 2. Cliquez sur "SQL" ou "Importer"
-- 3. Copiez-collez ce script et exécutez-le
-- 4. Ensuite, exécutez setup_admin.php UNE SEULE FOIS pour créer le compte admin
--
-- ═══════════════════════════════════════════════════════════════════════════

-- Créer la base de données
CREATE DATABASE IF NOT EXISTS carburname1 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE carburuser1;

-- ───────────────────────────────────────────────────────────────────────────
-- Table des utilisateurs
-- ───────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('user', 'admin') DEFAULT 'user',
    status ENUM('active', 'suspended', 'pending') DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_login DATETIME NULL,
    login_count INT DEFAULT 0,
    INDEX idx_email (email),
    INDEX idx_username (username),
    INDEX idx_role (role),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────────────────────────────────
-- Table des sessions
-- ───────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    INDEX idx_token (token),
    INDEX idx_user_id (user_id),
    INDEX idx_expires (expires_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────────────────────────────────
-- Table des favoris
-- ───────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS favorites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    station_id VARCHAR(20) NOT NULL,
    station_name VARCHAR(255) NULL,
    station_address VARCHAR(255) NULL,
    station_city VARCHAR(100) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_station (user_id, station_id),
    INDEX idx_user_id (user_id),
    INDEX idx_station_id (station_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────────────────────────────────
-- Table des logs d'activité
-- ───────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(50) NOT NULL,
    details TEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_action (action),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════
-- IMPORTANT: Après avoir exécuté ce script, allez sur:
-- https://votre-site.com/api/setup_admin.php
-- pour créer le compte administrateur
-- ═══════════════════════════════════════════════════════════════════════════
