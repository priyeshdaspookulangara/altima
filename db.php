<?php
require_once __DIR__ . '/config.php';

class Database {
    private static ?PDO $instance = null;

    public static function getConnection(): PDO {
        if (self::$instance === null) {
            $driver = DB_DRIVER;
            if ($driver === 'sqlite') {
                $dsn = "sqlite:" . SQLITE_FILE;
                self::$instance = new PDO($dsn, null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]);
            } else {
                $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]);
            }
        }
        return self::$instance;
    }

    public static function initSchema(): void {
        $pdo = self::getConnection();

        if (DB_DRIVER === 'sqlite') {
            $sql = "
            CREATE TABLE IF NOT EXISTS admin_configs (
                config_key TEXT PRIMARY KEY,
                config_value TEXT NOT NULL,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                wallet_address TEXT NOT NULL UNIQUE,
                referrer_id INTEGER NULL,
                direct_referral_count INTEGER DEFAULT 0,
                withdrawable_balance REAL DEFAULT 0.00,
                rebirth_vault_balance REAL DEFAULT 0.00,
                total_earnings REAL DEFAULT 0.00,
                current_pool_tier TEXT DEFAULT 'NONE',
                is_graduated INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (referrer_id) REFERENCES users(id) ON DELETE SET NULL
            );

            CREATE TABLE IF NOT EXISTS matrix_nodes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                parent_node_id INTEGER NULL,
                level INTEGER NOT NULL,
                depth_position INTEGER DEFAULT 0,
                is_rebirth INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (parent_node_id) REFERENCES matrix_nodes(id) ON DELETE SET NULL
            );

            CREATE TABLE IF NOT EXISTS pool_nodes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                pool_tier TEXT NOT NULL,
                parent_pool_node_id INTEGER NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                amount REAL NOT NULL,
                type TEXT NOT NULL,
                description TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );
            ";
            $pdo->exec($sql);
        } else {
            $sql = file_get_contents(__DIR__ . '/schema.sql');
            $pdo->exec($sql);
        }
    }
}
