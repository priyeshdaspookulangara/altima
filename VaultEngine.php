<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/User.php';
require_once __DIR__ . '/MatrixEngine.php';

class VaultEngine {
    private PDO $pdo;
    private User $userModel;
    private MatrixEngine $matrixEngine;

    public function __construct() {
        $this->pdo = Database::getConnection();
        $this->userModel = new User();
        $this->matrixEngine = new MatrixEngine();
    }

    /**
     * Retrieves full transaction ledger history for a user.
     */
    public function getUserLedger(int $userId): array {
        $stmt = $this->pdo->prepare("SELECT * FROM transactions WHERE user_id = ? ORDER BY id DESC");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /**
     * Processes a P2P withdrawal request for withdrawable balance.
     */
    public function withdraw(int $userId, float $amount): bool {
        $user = $this->userModel->getById($userId);
        if (!$user) {
            return false;
        }

        $balance = (float)$user['withdrawable_balance'];
        if ($amount <= 0 || $amount > $balance) {
            return false;
        }

        // Deduct withdrawable balance
        $stmt = $this->pdo->prepare("UPDATE users SET withdrawable_balance = withdrawable_balance - ? WHERE id = ?");
        $stmt->execute([$amount, $userId]);

        // Record withdrawal transaction
        $stmt = $this->pdo->prepare("INSERT INTO transactions (user_id, amount, type, description) VALUES (?, ?, 'WITHDRAWAL', ?)");
        $stmt->execute([$userId, -$amount, "P2P Wallet Withdrawal to " . $user['wallet_address']]);

        return true;
    }

    /**
     * Returns system-wide financial statistics (100% P2P verification).
     */
    public function getSystemFinancialStats(): array {
        $totalEarned = (float)$this->pdo->query("SELECT SUM(total_earnings) FROM users")->fetchColumn();
        $totalWithdrawable = (float)$this->pdo->query("SELECT SUM(withdrawable_balance) FROM users")->fetchColumn();
        $totalRebirthVault = (float)$this->pdo->query("SELECT SUM(rebirth_vault_balance) FROM users")->fetchColumn();
        $totalTransactions = (int)$this->pdo->query("SELECT COUNT(*) FROM transactions")->fetchColumn();

        return [
            'total_earnings' => $totalEarned,
            'total_withdrawable' => $totalWithdrawable,
            'total_rebirth_vault' => $totalRebirthVault,
            'total_transactions' => $totalTransactions,
            'company_deduction' => 0.00 // Guaranteed 0% company fee retention
        ];
    }
}
