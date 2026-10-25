<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/User.php';
require_once __DIR__ . '/AdminConfig.php';

class PoolEngine {
    private PDO $pdo;
    private User $userModel;
    private AdminConfig $adminConfig;

    public function __construct() {
        $this->pdo = Database::getConnection();
        $this->userModel = new User();
        $this->adminConfig = new AdminConfig();
    }

    /**
     * Checks if user has completed Level 7 of the Foundational Matrix or manually qualifies for Graduation.
     */
    public function checkAndGraduateUser(int $userId): bool {
        $user = $this->userModel->getById($userId);
        if (!$user) return false;

        if ($user['is_graduated']) {
            return true;
        }

        // Graduation criteria: Level 7 completion or explicit graduation trigger
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM matrix_nodes WHERE user_id = ? AND level = 7");
        $stmt->execute([$userId]);
        $level7Nodes = (int)$stmt->fetchColumn();

        if ($level7Nodes > 0 || $user['current_pool_tier'] !== 'NONE') {
            $this->userModel->setGraduated($userId);
            if ($user['current_pool_tier'] === 'NONE') {
                $this->enterPool($userId, 'POOL_50');
            }
            return true;
        }

        return false;
    }

    /**
     * Finds the available parent pool node for a 3x2 matrix using Breadth-First Search (BFS).
     */
    private function findAvailablePoolParent(string $poolTier): ?int {
        // Fetch root pool node for this tier
        $stmt = $this->pdo->prepare("SELECT id FROM pool_nodes WHERE pool_tier = ? AND parent_pool_node_id IS NULL ORDER BY id ASC LIMIT 1");
        $stmt->execute([$poolTier]);
        $rootId = $stmt->fetchColumn();

        if (!$rootId) {
            return null; // First node in this pool tier
        }

        $queue = [(int)$rootId];

        while (!empty($queue)) {
            $currentNodeId = array_shift($queue);

            // Check children count
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM pool_nodes WHERE parent_pool_node_id = ? AND pool_tier = ?");
            $stmt->execute([$currentNodeId, $poolTier]);
            $childCount = (int)$stmt->fetchColumn();

            if ($childCount < 3) {
                return $currentNodeId;
            }

            // Enqueue children
            $stmt = $this->pdo->prepare("SELECT id FROM pool_nodes WHERE parent_pool_node_id = ? AND pool_tier = ? ORDER BY id ASC");
            $stmt->execute([$currentNodeId, $poolTier]);
            $children = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($children as $childId) {
                $queue[] = (int)$childId;
            }
        }

        return (int)$rootId;
    }

    /**
     * Enters a user into a Progression Pool (POOL_50, POOL_100, POOL_200) with 3x2 BFS Placement.
     */
    public function enterPool(int $userId, string $poolTier): bool {
        $validTiers = ['POOL_50', 'POOL_100', 'POOL_200'];
        if (!in_array($poolTier, $validTiers)) {
            return false;
        }

        // Check if already in pool tier
        $stmt = $this->pdo->prepare("SELECT id FROM pool_nodes WHERE user_id = ? AND pool_tier = ?");
        $stmt->execute([$userId, $poolTier]);
        if ($stmt->fetch()) {
            return true;
        }

        // Find parent pool node via 3x2 BFS placement
        $parentPoolNodeId = $this->findAvailablePoolParent($poolTier);

        // Insert node into pool_nodes
        $stmt = $this->pdo->prepare("INSERT INTO pool_nodes (user_id, pool_tier, parent_pool_node_id) VALUES (?, ?, ?)");
        $stmt->execute([$userId, $poolTier, $parentPoolNodeId]);
        $newPoolNodeId = (int)$this->pdo->lastInsertId();

        // Update user state
        $this->userModel->updatePoolTier($userId, $poolTier);

        // Record pool entry transaction
        $entryFeeKey = strtolower($poolTier) . '_entry';
        $entryFee = (float)$this->adminConfig->getConfig($entryFeeKey, '50.00');

        $stmt = $this->pdo->prepare("INSERT INTO transactions (user_id, amount, type, description) VALUES (?, ?, 'POOL_ENTRY', ?)");
        $stmt->execute([$userId, $entryFee, "Graduated and Entered $poolTier Progression Pool"]);

        // Distribute 3x2 pool depth payouts to upline pool nodes
        $this->distributePoolPayouts($newPoolNodeId, $poolTier);

        return true;
    }

    /**
     * Distributes Depth 1 and Depth 2 payouts within a 3x2 Progression Pool.
     */
    private function distributePoolPayouts(int $poolNodeId, string $poolTier): void {
        $prefix = strtolower($poolTier);
        $d1Payout = (float)$this->adminConfig->getConfig($prefix . '_d1_payout', '10.00');
        $d2Payout = (float)$this->adminConfig->getConfig($prefix . '_d2_payout', '30.00');

        // Fetch parent (Depth 1)
        $stmt = $this->pdo->prepare("SELECT parent_pool_node_id FROM pool_nodes WHERE id = ?");
        $stmt->execute([$poolNodeId]);
        $d1ParentId = $stmt->fetchColumn();

        if ($d1ParentId) {
            $stmt = $this->pdo->prepare("SELECT user_id FROM pool_nodes WHERE id = ?");
            $stmt->execute([$d1ParentId]);
            $d1UserId = (int)$stmt->fetchColumn();

            $this->userModel->addWithdrawableBalance($d1UserId, $d1Payout);
            $stmt = $this->pdo->prepare("INSERT INTO transactions (user_id, amount, type, description) VALUES (?, ?, 'POOL_PAYOUT', ?)");
            $stmt->execute([$d1UserId, $d1Payout, "Depth 1 Reward in $poolTier"]);

            // Fetch grand-parent (Depth 2)
            $stmt = $this->pdo->prepare("SELECT parent_pool_node_id FROM pool_nodes WHERE id = ?");
            $stmt->execute([$d1ParentId]);
            $d2ParentId = $stmt->fetchColumn();

            if ($d2ParentId) {
                $stmt = $this->pdo->prepare("SELECT user_id FROM pool_nodes WHERE id = ?");
                $stmt->execute([$d2ParentId]);
                $d2UserId = (int)$stmt->fetchColumn();

                $this->userModel->addWithdrawableBalance($d2UserId, $d2Payout);
                $stmt = $this->pdo->prepare("INSERT INTO transactions (user_id, amount, type, description) VALUES (?, ?, 'POOL_PAYOUT', ?)");
                $stmt->execute([$d2UserId, $d2Payout, "Depth 2 Reward in $poolTier"]);

                // Check pool completion (12 downline nodes in 3x2)
                $this->checkPoolCompletion($d2UserId, $poolTier);
            }
        }
    }

    /**
     * Checks if user completed the current progression pool and triggers progression to next tier.
     */
    private function checkPoolCompletion(int $userId, string $poolTier): void {
        $stmt = $this->pdo->prepare("SELECT id FROM pool_nodes WHERE user_id = ? AND pool_tier = ? ORDER BY id ASC LIMIT 1");
        $stmt->execute([$userId, $poolTier]);
        $poolNodeId = $stmt->fetchColumn();

        if (!$poolNodeId) return;

        // Count direct and indirect children in 3x2 pool tree (up to 12)
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM pool_nodes
            WHERE parent_pool_node_id = ?
            OR parent_pool_node_id IN (SELECT id FROM pool_nodes WHERE parent_pool_node_id = ?)
        ");
        $stmt->execute([$poolNodeId, $poolNodeId]);
        $totalDownline = (int)$stmt->fetchColumn();

        if ($totalDownline >= 12) {
            // Pool Completed! Auto-Progress to next pool
            if ($poolTier === 'POOL_50') {
                $this->enterPool($userId, 'POOL_100');
            } elseif ($poolTier === 'POOL_100') {
                $this->enterPool($userId, 'POOL_200');
            } elseif ($poolTier === 'POOL_200') {
                // Re-cycle into POOL_200
                $this->enterPool($userId, 'POOL_200');
            }
        }
    }
}
