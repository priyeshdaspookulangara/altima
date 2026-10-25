<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/AdminConfig.php';

class User {
    private PDO $pdo;

    public function __construct() {
        $this->pdo = Database::getConnection();
    }

    public function create(string $walletAddress, ?int $referrerId = null): array {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE wallet_address = ?");
        $stmt->execute([$walletAddress]);
        $existing = $stmt->fetch();
        if ($existing) {
            return $existing;
        }

        $stmt = $this->pdo->prepare("INSERT INTO users (wallet_address, referrer_id) VALUES (?, ?)");
        $stmt->execute([$walletAddress, $referrerId]);
        $userId = (int)$this->pdo->lastInsertId();

        if ($referrerId) {
            $stmt = $this->pdo->prepare("UPDATE users SET direct_referral_count = direct_referral_count + 1 WHERE id = ?");
            $stmt->execute([$referrerId]);
        }

        return $this->getById($userId);
    }

    public function getById(int $id): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public function getByWallet(string $walletAddress): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE wallet_address = ?");
        $stmt->execute([$walletAddress]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public function addWithdrawableBalance(int $userId, float $amount): void {
        $stmt = $this->pdo->prepare("UPDATE users SET withdrawable_balance = withdrawable_balance + ?, total_earnings = total_earnings + ? WHERE id = ?");
        $stmt->execute([$amount, $amount, $userId]);
    }

    public function addRebirthVaultBalance(int $userId, float $amount): void {
        $stmt = $this->pdo->prepare("UPDATE users SET rebirth_vault_balance = rebirth_vault_balance + ? WHERE id = ?");
        $stmt->execute([$amount, $userId]);
    }

    public function deductRebirthVaultBalance(int $userId, float $amount): void {
        $stmt = $this->pdo->prepare("UPDATE users SET rebirth_vault_balance = rebirth_vault_balance - ? WHERE id = ?");
        $stmt->execute([$amount, $userId]);
    }

    public function updatePoolTier(int $userId, string $tier): void {
        $stmt = $this->pdo->prepare("UPDATE users SET current_pool_tier = ? WHERE id = ?");
        $stmt->execute([$tier, $userId]);
    }

    public function setGraduated(int $userId): void {
        $stmt = $this->pdo->prepare("UPDATE users SET is_graduated = 1 WHERE id = ?");
        $stmt->execute([$userId]);
    }
}
