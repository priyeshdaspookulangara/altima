<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/User.php';
require_once __DIR__ . '/AdminConfig.php';

class MatrixEngine {
    private PDO $pdo;
    private User $userModel;
    private AdminConfig $adminConfig;

    public function __construct() {
        $this->pdo = Database::getConnection();
        $this->userModel = new User();
        $this->adminConfig = new AdminConfig();
    }

    /**
     * Executes Forced 3x3 BFS Spillover Placement starting from rootNodeId up to 7 levels relative depth.
     */
    public function findFirstAvailableNode(int $rootNodeId): int {
        $stmt = $this->pdo->prepare("SELECT level FROM matrix_nodes WHERE id = ?");
        $stmt->execute([$rootNodeId]);
        $rootLevel = (int)$stmt->fetchColumn();

        $queue = [$rootNodeId];

        while (!empty($queue)) {
            $currentNodeId = array_shift($queue);

            // Fetch current node's child count
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM matrix_nodes WHERE parent_node_id = ?");
            $stmt->execute([$currentNodeId]);
            $childCount = (int)$stmt->fetchColumn();

            if ($childCount < 3) {
                return $currentNodeId;
            }

            // Fetch children IDs and levels to enqueue for level-by-level scanning
            $stmt = $this->pdo->prepare("SELECT id, level FROM matrix_nodes WHERE parent_node_id = ? ORDER BY id ASC");
            $stmt->execute([$currentNodeId]);
            $children = $stmt->fetchAll();

            foreach ($children as $child) {
                // Allow enqueuing child node if its children would be within relative 7 levels from root
                $relativeDepth = ((int)$child['level'] + 1) - $rootLevel;
                if ($relativeDepth <= 7) {
                    $queue[] = (int)$child['id'];
                }
            }
        }

        // Global fallback: find any node in matrix with < 3 children
        $stmt = $this->pdo->query("SELECT id FROM matrix_nodes WHERE (SELECT COUNT(*) FROM matrix_nodes c WHERE c.parent_node_id = matrix_nodes.id) < 3 ORDER BY id ASC LIMIT 1");
        $fallbackId = $stmt->fetchColumn();

        return $fallbackId ? (int)$fallbackId : $rootNodeId;
    }

    /**
     * Registers and activates a new user or position in the 3x3 Foundational Matrix.
     */
    public function registerAndPlace(string $walletAddress, ?string $referrerWallet = null, bool $isRebirth = false, ?int $existingUserId = null): array {
        if ($isRebirth && $existingUserId) {
            $userId = $existingUserId;
            $user = $this->userModel->getById($userId);
            $referrerId = $user['referrer_id'] ? (int)$user['referrer_id'] : null;
        } else {
            $referrer = $referrerWallet ? $this->userModel->getByWallet($referrerWallet) : null;
            $referrerId = $referrer ? (int)$referrer['id'] : null;

            $user = $this->userModel->create($walletAddress, $referrerId);
            $userId = (int)$user['id'];

            // Direct Referral Commission & 3-Direct 100% Fee Recovery
            if ($referrerId) {
                $this->processDirectReferralBonus($referrerId, $userId);
            }
        }

        // Determine parent matrix node for forced 3x3 placement
        $parentNodeId = null;
        $level = 1;

        // Check if any node exists in matrix tree
        $stmt = $this->pdo->prepare("SELECT id FROM matrix_nodes WHERE parent_node_id IS NULL ORDER BY id ASC LIMIT 1");
        $stmt->execute();
        $globalRootNode = $stmt->fetch();

        if ($globalRootNode) {
            $rootNodeId = (int)$globalRootNode['id'];

            if ($referrerId) {
                // Find referrer's primary matrix node
                $stmt = $this->pdo->prepare("SELECT id, level FROM matrix_nodes WHERE user_id = ? ORDER BY id ASC LIMIT 1");
                $stmt->execute([$referrerId]);
                $refNode = $stmt->fetch();
                if ($refNode) {
                    $rootNodeId = (int)$refNode['id'];
                }
            } elseif ($isRebirth) {
                // For rebirth, start search from user's primary matrix node
                $stmt = $this->pdo->prepare("SELECT id, level FROM matrix_nodes WHERE user_id = ? ORDER BY id ASC LIMIT 1");
                $stmt->execute([$userId]);
                $userNode = $stmt->fetch();
                if ($userNode) {
                    $rootNodeId = (int)$userNode['id'];
                }
            }

            $parentNodeId = $this->findFirstAvailableNode($rootNodeId);
            $stmt = $this->pdo->prepare("SELECT level FROM matrix_nodes WHERE id = ?");
            $stmt->execute([$parentNodeId]);
            $parentLevel = (int)$stmt->fetchColumn();
            $level = $parentLevel + 1;
        }

        // Insert new matrix node for the user (or rebirth position)
        $stmt = $this->pdo->prepare("INSERT INTO matrix_nodes (user_id, parent_node_id, level, is_rebirth) VALUES (?, ?, ?, ?)");
        $stmt->execute([$userId, $parentNodeId, $level, $isRebirth ? 1 : 0]);
        $newNodeId = (int)$this->pdo->lastInsertId();

        // Distribute matrix depth payouts across 7 upline levels
        $this->distributeMatrixDepthPayouts($newNodeId);

        return $this->userModel->getById($userId);
    }

    /**
     * Direct Referral Bonus & 100% Fee Recovery (3 Directs x $10 = $30 total recovery).
     */
    private function processDirectReferralBonus(int $referrerId, int $refereeId): void {
        $bonusAmount = (float)$this->adminConfig->getConfig('direct_bonus_amount', '10.00');

        $this->userModel->addWithdrawableBalance($referrerId, $bonusAmount);

        // Record transaction
        $stmt = $this->pdo->prepare("INSERT INTO transactions (user_id, amount, type, description) VALUES (?, ?, 'DIRECT_BONUS', ?)");
        $stmt->execute([$referrerId, $bonusAmount, "Direct Referral Commission from user ID: $refereeId"]);
    }

    /**
     * Traverses up to 7 upline parent nodes and credits matrix depth payouts & rebirth deductions.
     */
    private function distributeMatrixDepthPayouts(int $nodeId): void {
        $currentNodeId = $nodeId;
        $depth = 1;

        while ($depth <= 7) {
            $stmt = $this->pdo->prepare("SELECT parent_node_id FROM matrix_nodes WHERE id = ?");
            $stmt->execute([$currentNodeId]);
            $parentNodeId = $stmt->fetchColumn();

            if (!$parentNodeId) {
                break; // Top of matrix tree reached
            }

            $stmt = $this->pdo->prepare("SELECT user_id FROM matrix_nodes WHERE id = ?");
            $stmt->execute([$parentNodeId]);
            $uplineUserId = (int)$stmt->fetchColumn();

            // Payout config for this depth tier
            $payoutKey = "matrix_level_payout_" . $depth;
            $rebirthKey = "matrix_rebirth_deduction_" . $depth;

            $payoutAmount = (float)$this->adminConfig->getConfig($payoutKey, '2.00');
            $rebirthDeduction = (float)$this->adminConfig->getConfig($rebirthKey, '0.00');

            $netPayout = max(0.00, $payoutAmount - $rebirthDeduction);

            if ($netPayout > 0) {
                $this->userModel->addWithdrawableBalance($uplineUserId, $netPayout);
                $stmt = $this->pdo->prepare("INSERT INTO transactions (user_id, amount, type, description) VALUES (?, ?, 'MATRIX_LEVEL_PAYOUT', ?)");
                $stmt->execute([$uplineUserId, $netPayout, "Matrix Level $depth Payout from Node #$nodeId"]);
            }

            if ($rebirthDeduction > 0) {
                $this->userModel->addRebirthVaultBalance($uplineUserId, $rebirthDeduction);
                $stmt = $this->pdo->prepare("INSERT INTO transactions (user_id, amount, type, description) VALUES (?, ?, 'REBIRTH_DEDUCTION', ?)");
                $stmt->execute([$uplineUserId, $rebirthDeduction, "Rebirth Vault Allocation at Level $depth from Node #$nodeId"]);

                // Check if rebirth vault reached threshold ($30 USDT)
                $this->checkAndTriggerRebirth($uplineUserId);
            }

            $currentNodeId = (int)$parentNodeId;
            $depth++;
        }
    }

    /**
     * Triggers parallel node placement under the same user ID when rebirth vault balance >= 30 USDT.
     */
    public function checkAndTriggerRebirth(int $userId): void {
        $user = $this->userModel->getById($userId);
        $rebirthBalance = (float)$user['rebirth_vault_balance'];
        $threshold = REBIRTH_TRIGGER_AMOUNT; // 30.00 USDT

        if ($rebirthBalance >= $threshold) {
            // Deduct $30 from rebirth vault
            $this->userModel->deductRebirthVaultBalance($userId, $threshold);

            // Record rebirth event
            $stmt = $this->pdo->prepare("INSERT INTO transactions (user_id, amount, type, description) VALUES (?, ?, 'REBIRTH_TRIGGERED', ?)");
            $stmt->execute([$userId, $threshold, "Auto-Rebirth position spawned under user ID #$userId"]);

            // Place rebirth node into matrix directly attached to user ID
            $this->registerAndPlace($user['wallet_address'], null, true, $userId);
        }
    }
}
