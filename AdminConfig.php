<?php
require_once __DIR__ . '/db.php';

class AdminConfig {
    private PDO $pdo;

    public function __construct() {
        $this->pdo = Database::getConnection();
        $this->initializeDefaultConfigs();
    }

    private function initializeDefaultConfigs(): void {
        $defaults = [
            'direct_bonus_amount' => '10.00',
            'direct_bonus_milestone_count' => '3',
            'direct_bonus_milestone_reward' => '30.00',
            // Level payouts for Levels 1-7 in Foundational Matrix
            'matrix_level_payout_1' => '2.00',
            'matrix_level_payout_2' => '2.00',
            'matrix_level_payout_3' => '2.00',
            'matrix_level_payout_4' => '2.00',
            'matrix_level_payout_5' => '3.00',
            'matrix_level_payout_6' => '3.00',
            'matrix_level_payout_7' => '4.00',
            // Rebirth deductions for Levels 1-7
            'matrix_rebirth_deduction_1' => '0.00',
            'matrix_rebirth_deduction_2' => '0.00',
            'matrix_rebirth_deduction_3' => '10.00',
            'matrix_rebirth_deduction_4' => '20.00',
            'matrix_rebirth_deduction_5' => '30.00',
            'matrix_rebirth_deduction_6' => '50.00',
            'matrix_rebirth_deduction_7' => '100.00',
            // Progression Pool Configs (5, 10, 30, 50, 100, 200 USDT)
            'pool_5_entry' => '5.00',
            'pool_5_d1_payout' => '1.00',
            'pool_5_d2_payout' => '3.00',
            'pool_10_entry' => '10.00',
            'pool_10_d1_payout' => '2.00',
            'pool_10_d2_payout' => '6.00',
            'pool_30_entry' => '30.00',
            'pool_30_d1_payout' => '6.00',
            'pool_30_d2_payout' => '18.00',
            'pool_50_entry' => '50.00',
            'pool_50_d1_payout' => '10.00',
            'pool_50_d2_payout' => '30.00',
            'pool_100_entry' => '100.00',
            'pool_100_d1_payout' => '20.00',
            'pool_100_d2_payout' => '60.00',
            'pool_200_entry' => '200.00',
            'pool_200_d1_payout' => '40.00',
            'pool_200_d2_payout' => '120.00'
        ];

        foreach ($defaults as $key => $val) {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM admin_configs WHERE config_key = ?");
            $stmt->execute([$key]);
            if ($stmt->fetchColumn() == 0) {
                $insert = $this->pdo->prepare("INSERT INTO admin_configs (config_key, config_value) VALUES (?, ?)");
                $insert->execute([$key, $val]);
            }
        }
    }

    public function getConfig(string $key, string $default = '0.00'): string {
        $stmt = $this->pdo->prepare("SELECT config_value FROM admin_configs WHERE config_key = ?");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return $val !== false ? $val : $default;
    }

    public function setConfig(string $key, string $value): void {
        $stmt = $this->pdo->prepare("REPLACE INTO admin_configs (config_key, config_value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)");
        $stmt->execute([$key, $value]);
    }

    public function getAllConfigs(): array {
        $stmt = $this->pdo->query("SELECT config_key, config_value FROM admin_configs");
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }
}
