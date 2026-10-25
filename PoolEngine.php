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
     * Checks if user has completed their 7-level matrix cycle or meets qualification criteria to graduate.
     */
    public function checkAndGraduateUser(int $userId): bool {
        $user = $this->userModel->getById($userId);
        if (!$user) return false;

        if ($user['is_graduated']) {
            return true;
        }

        // Fetch user's root matrix node
        $stmt = $this->pdo->prepare("SELECT id FROM matrix_nodes WHERE user_id = ? ORDER BY id ASC LIMIT 1");
        $stmt->execute([$userId]);
        $rootNodeId = $stmt->fetchColumn();

        if (!$rootNodeId) return false;

        // Check total downline count under user's root node up to 7 levels relative depth
        $stmt = $this->pdo->prepare("
            WITH RECURSIVE DownlineTree AS (
                SELECT id, parent_node_id, level, 1 as relative_depth
                FROM matrix_nodes
                WHERE parent_node_id = ?
                UNION ALL
                SELECT m.id, m.parent_node_id, m.level, dt.relative_depth + 1
                FROM matrix_nodes m
                INNER JOIN DownlineTree dt ON m.parent_node_id = dt.id
                WHERE dt.relative_depth < 7
            )
            SELECT COUNT(*) FROM DownlineTree
        ");
        $stmt->execute([$rootNodeId]);
        $downlineCount = (int)$stmt->fetchColumn();

        // Milestone completion trigger: e.g. completing matrix cycle or reaching threshold downline
        if ($downlineCount >= 3 || $user['current_pool_tier'] !== 'NONE') {
            $this->userModel->setGraduated($userId);
            if ($user['current_pool_tier'] === 'NONE') {
                $this->enterPool($userId, 'POOL_5'); // Entry pool is POOL_5
            }
            return true;
        }

        return false;
    }

    /**
     * Finds the available parent pool node for a 3x2 matrix using Breadth-First Search (BFS).
     */
    private function findAvailablePoolParent(string $poolTier): ?int {
        // Fetch active root pool node for this tier
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
     * Enters a user into a Progression Pool (POOL_5..POOL_2000) with 3x2 BFS Placement.
     */
    public function enterPool(int $userId, string $poolTier, bool $isRecycle = false): bool {
        $validTiers = ['POOL_5', 'POOL_10', 'POOL_30', 'POOL_50', 'POOL_100', 'POOL_200', 'POOL_500', 'POOL_1000', 'POOL_2000'];
        if (!in_array($poolTier, $validTiers)) {
            return false;
        }

        // Check if already in pool tier (unless re-cycling into final tier)
        if (!$isRecycle) {
            $stmt = $this->pdo->prepare("SELECT id FROM pool_nodes WHERE user_id = ? AND pool_tier = ?");
            $stmt->execute([$userId, $poolTier]);
            if ($stmt->fetch()) {
                return true;
            }
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
        $entryFee = (float)$this->adminConfig->getConfig($entryFeeKey, '5.00');

        $desc = $isRecycle ? "Re-entered $poolTier Progression Pool (Recycle Loop)" : "Entered $poolTier Progression Pool";
        $stmt = $this->pdo->prepare("INSERT INTO transactions (user_id, amount, type, description) VALUES (?, ?, 'POOL_ENTRY', ?)");
        $stmt->execute([$userId, $entryFee, $desc]);

        // Distribute 3x2 pool depth payouts to upline pool nodes
        $this->distributePoolPayouts($newPoolNodeId, $poolTier);

        return true;
    }

    /**
     * Distributes Depth 1 and Depth 2 payouts within a 3x2 Progression Pool.
     */
    private function distributePoolPayouts(int $poolNodeId, string $poolTier): void {
        $prefix = strtolower($poolTier);
        $d1Payout = (float)$this->adminConfig->getConfig($prefix . '_d1_payout', '1.00');
        $d2Payout = (float)$this->adminConfig->getConfig($prefix . '_d2_payout', '3.00');

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
        $stmt = $this->pdo->prepare("SELECT id FROM pool_nodes WHERE user_id = ? AND pool_tier = ? ORDER BY id DESC LIMIT 1");
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
            // Sequential progression sequence: 5 -> 10 -> 30 -> 50 -> 100 -> 200 -> 500 -> 1000 -> 2000
            if ($poolTier === 'POOL_5') {
                $this->enterPool($userId, 'POOL_10');
            } elseif ($poolTier === 'POOL_10') {
                $this->enterPool($userId, 'POOL_30');
            } elseif ($poolTier === 'POOL_30') {
                $this->enterPool($userId, 'POOL_50');
            } elseif ($poolTier === 'POOL_50') {
                $this->enterPool($userId, 'POOL_100');
            } elseif ($poolTier === 'POOL_100') {
                $this->enterPool($userId, 'POOL_200');
            } elseif ($poolTier === 'POOL_200') {
                $this->enterPool($userId, 'POOL_500');
            } elseif ($poolTier === 'POOL_500') {
                $this->enterPool($userId, 'POOL_1000');
            } elseif ($poolTier === 'POOL_1000') {
                $this->enterPool($userId, 'POOL_2000');
            } elseif ($poolTier === 'POOL_2000') {
                // Re-cycle into POOL_2000
                $this->enterPool($userId, 'POOL_2000', true);
            }
        }
    }
}
