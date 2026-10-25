-- Altima Database Schema for MySQL / MariaDB

CREATE TABLE IF NOT EXISTS admin_configs (
    config_key VARCHAR(64) PRIMARY KEY,
    config_value TEXT NOT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    wallet_address VARCHAR(64) NOT NULL UNIQUE,
    referrer_id INT NULL,
    direct_referral_count INT DEFAULT 0,
    withdrawable_balance DECIMAL(18, 2) DEFAULT 0.00,
    rebirth_vault_balance DECIMAL(18, 2) DEFAULT 0.00,
    total_earnings DECIMAL(18, 2) DEFAULT 0.00,
    current_pool_tier VARCHAR(32) DEFAULT 'NONE', -- NONE, POOL_5, POOL_10, POOL_30, POOL_50, POOL_100, POOL_200
    is_graduated TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (referrer_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS matrix_nodes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    parent_node_id INT NULL,
    level INT NOT NULL, -- 1 to 7 depth relative to root or parent
    depth_position INT NOT NULL DEFAULT 0,
    is_rebirth TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_node_id) REFERENCES matrix_nodes(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS pool_nodes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    pool_tier VARCHAR(32) NOT NULL, -- POOL_5, POOL_10, POOL_30, POOL_50, POOL_100, POOL_200
    parent_pool_node_id INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    amount DECIMAL(18, 2) NOT NULL,
    type VARCHAR(64) NOT NULL, -- DIRECT_BONUS, MATRIX_LEVEL_PAYOUT, REBIRTH_DEDUCTION, POOL_PAYOUT, WITHDRAWAL
    description TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
