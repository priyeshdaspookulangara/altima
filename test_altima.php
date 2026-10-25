<?php
// Test suite for Altima MLM Engine in Plain Vanilla PHP

putenv('DB_DRIVER=sqlite');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/User.php';
require_once __DIR__ . '/AdminConfig.php';
require_once __DIR__ . '/MatrixEngine.php';
require_once __DIR__ . '/VaultEngine.php';
require_once __DIR__ . '/PoolEngine.php';

// Fresh DB Initialization
if (file_exists(__DIR__ . '/altima.sqlite')) {
    unlink(__DIR__ . '/altima.sqlite');
}
Database::initSchema();

echo "=================================================\n";
echo "   ALTIMA MLM ENGINE INTEGRATION TEST SUITE\n";
echo "=================================================\n\n";

$matrixEngine = new MatrixEngine();
$userModel = new User();
$vaultEngine = new VaultEngine();
$poolEngine = new PoolEngine();
$adminConfig = new AdminConfig();

// Test 1: Root User Creation
echo "[TEST 1] Registering Root Position (0xRoot)...\n";
$root = $matrixEngine->registerAndPlace('0xRoot');
assert($root['id'] == 1, "Root user ID should be 1");
echo "-> PASS: Root created with ID 1.\n\n";

// Test 2: 3 Direct Referrals & 100% Fee Recovery Rule
echo "[TEST 2] Registering 3 Direct Referrals under 0xRoot (0xUser1, 0xUser2, 0xUser3)...\n";
$u1 = $matrixEngine->registerAndPlace('0xUser1', '0xRoot');
$u2 = $matrixEngine->registerAndPlace('0xUser2', '0xRoot');
$u3 = $matrixEngine->registerAndPlace('0xUser3', '0xRoot');

$rootUpdated = $userModel->getById((int)$root['id']);
echo "Root Direct Referrals: " . $rootUpdated['direct_referral_count'] . "\n";
echo "Root Withdrawable Balance: $" . $rootUpdated['withdrawable_balance'] . " USDT\n";

assert($rootUpdated['direct_referral_count'] == 3, "Direct referral count should be 3");
// Direct bonuses: 10 + 10 + 10 = $30 USDT (100% joining fee recovery) + Level 1 payouts (3 * $2 = $6) = $36 total
assert($rootUpdated['withdrawable_balance'] == 36.00, "Withdrawable balance should equal $36.00 (30 direct recovery + 6 level 1 payouts)");
echo "-> PASS: 3 Direct referrals yielded $30 USDT direct recovery (100% joining fee recovery)!\n\n";

// Test 3: Forced 3x3 BFS Spillover Placement
echo "[TEST 3] Testing BFS Spillover (0xUser4 registered under 0xUser1)...\n";
$u4 = $matrixEngine->registerAndPlace('0xUser4', '0xUser1');
$stmt = Database::getConnection()->prepare("SELECT level, parent_node_id FROM matrix_nodes WHERE user_id = ?");
$stmt->execute([$u4['id']]);
$u4Node = $stmt->fetch();
echo "0xUser4 placed at Level " . $u4Node['level'] . " with Parent Node ID #" . $u4Node['parent_node_id'] . "\n";
assert($u4Node['level'] == 3, "0xUser4 should be placed at Level 3 in BFS tree under 0xUser1");
echo "-> PASS: Forced 3x3 BFS spillover placed 0xUser4 at correct depth level.\n\n";

// Test 4: Rebirth Node Placement under same User ID
echo "[TEST 4] Testing Rebirth Vault and Rebirth Node Spawning...\n";
// Manually add $30 to Root's rebirth vault
$userModel->addRebirthVaultBalance(1, 30.00);
$matrixEngine->checkAndTriggerRebirth(1);
$stmt = Database::getConnection()->prepare("SELECT COUNT(*) FROM matrix_nodes WHERE user_id = 1 AND is_rebirth = 1");
$rebirthNodeCount = (int)$stmt->fetchColumn();
assert($rebirthNodeCount == 1, "Root should have 1 rebirth node linked to user ID 1");
echo "-> PASS: Rebirth node successfully spawned and linked to original User ID 1.\n\n";

// Test 5: Dynamic Admin Parameter Calibration
echo "[TEST 5] Modifying Level 3 payout rate via AdminConfig...\n";
$adminConfig->setConfig('matrix_level_payout_3', '5.00');
assert($adminConfig->getConfig('matrix_level_payout_3') == '5.00', "Config updated successfully");
echo "-> PASS: Admin parameter successfully updated dynamically.\n\n";

// Test 6: Progression Pool Graduation and 3x2 BFS Placement
echo "[TEST 6] Testing Graduation into 50 USDT Progression Pool and 3x2 BFS Placement...\n";
$poolEngine->enterPool(1, 'POOL_50'); // Root enters Pool 50
$poolEngine->enterPool(2, 'POOL_50'); // User 1 enters Pool 50
$poolEngine->enterPool(3, 'POOL_50'); // User 2 enters Pool 50

$rootPool = $userModel->getById(1);
echo "Root Pool Tier: " . $rootPool['current_pool_tier'] . "\n";
echo "Root Withdrawable Balance after pool children: $" . $rootPool['withdrawable_balance'] . " USDT\n";
assert($rootPool['current_pool_tier'] == 'POOL_50', "Root should now be in POOL_50");
assert($rootPool['withdrawable_balance'] == 58.00, "Root withdrawable balance updated with pool depth 1 rewards ($36 base + $2 rebirth matrix payout + $20 pool payouts)");
echo "-> PASS: Progression pool 3x2 BFS placement and depth payouts verified.\n\n";

// Test 7: System-wide Financial Audit & Zero Corporate Fee Check
echo "[TEST 7] Auditing System Financial Ledger...\n";
$stats = $vaultEngine->getSystemFinancialStats();
print_r($stats);
assert($stats['company_deduction'] == 0.00, "Company retention fee must strictly be $0.00");
echo "-> PASS: 100% Peer-to-Peer distribution model verified with $0 corporate fee retention.\n\n";

echo "=================================================\n";
echo "   ALL ALTIMA INTEGRATION TESTS PASSED 100%!\n";
echo "=================================================\n";
