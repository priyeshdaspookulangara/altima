<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/AdminConfig.php';
require_once __DIR__ . '/VaultEngine.php';

if (DB_DRIVER === 'sqlite') {
    Database::initSchema();
}

$adminConfig = new AdminConfig();
$vaultEngine = new VaultEngine();

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($_POST as $key => $value) {
        if ($key !== 'save_config') {
            $adminConfig->setConfig($key, trim($value));
        }
    }
    $message = 'Dynamic administrative parameters updated successfully!';
}

$configs = $adminConfig->getAllConfigs();
$stats = $vaultEngine->getSystemFinancialStats();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Altima - Dynamic Admin Governance</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #f8fafc; margin: 0; padding: 20px; }
        .container { max-width: 950px; margin: 0 auto; background: #1e293b; padding: 30px; border-radius: 12px; }
        h1, h2, h3 { color: #f43f5e; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .stat-card { background: #334155; padding: 15px; border-radius: 8px; border-left: 4px solid #f43f5e; }
        .stat-card .val { font-size: 22px; font-weight: bold; color: #f8fafc; margin-top: 5px; }
        .form-group { margin-bottom: 15px; display: flex; align-items: center; justify-content: space-between; background: #334155; padding: 10px 15px; border-radius: 6px; }
        label { font-weight: bold; flex: 1; }
        input[type="text"] { width: 180px; padding: 8px; border-radius: 4px; border: 1px solid #475569; background: #0f172a; color: white; text-align: right; }
        .btn { background: #e11d48; color: white; padding: 12px 25px; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; font-weight: bold; }
        .btn:hover { background: #be123c; }
        .alert-success { background: #15803d; color: white; padding: 12px; border-radius: 6px; margin-bottom: 20px; }
        a { color: #38bdf8; text-decoration: none; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Admin Governance & Ecosystem Control</h1>

        <?php if ($message): ?>
            <div class="alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <h2>System Financial Audit (100% P2P Verification)</h2>
        <div class="grid">
            <div class="stat-card">
                <div>Total Member Earnings</div>
                <div class="val">$<?= number_format($stats['total_earnings'], 2) ?> USDT</div>
            </div>
            <div class="stat-card">
                <div>Withdrawable Balances</div>
                <div class="val">$<?= number_format($stats['total_withdrawable'], 2) ?> USDT</div>
            </div>
            <div class="stat-card">
                <div>Rebirth Vault Allocations</div>
                <div class="val">$<?= number_format($stats['total_rebirth_vault'], 2) ?> USDT</div>
            </div>
            <div class="stat-card">
                <div>Corporate Retention Fee</div>
                <div class="val" style="color: #4ade80;">$0.00 (0%)</div>
            </div>
        </div>

        <h2>Dynamic Parameter Calibration</h2>
        <form method="POST">
            <h3>Direct Referral & Milestone Recovery Rules</h3>
            <div class="form-group">
                <label>Direct Referral Bonus Amount (USDT)</label>
                <input type="text" name="direct_bonus_amount" value="<?= htmlspecialchars($configs['direct_bonus_amount'] ?? '10.00') ?>">
            </div>
            <div class="form-group">
                <label>100% Recovery Milestone Direct Referral Count</label>
                <input type="text" name="direct_bonus_milestone_count" value="<?= htmlspecialchars($configs['direct_bonus_milestone_count'] ?? '3') ?>">
            </div>
            <div class="form-group">
                <label>100% Recovery Milestone Reward (USDT)</label>
                <input type="text" name="direct_bonus_milestone_reward" value="<?= htmlspecialchars($configs['direct_bonus_milestone_reward'] ?? '30.00') ?>">
            </div>

            <h3>Foundational Matrix Depth Payout Schedule (7 Levels)</h3>
            <?php for ($i = 1; $i <= 7; $i++): ?>
                <div class="form-group">
                    <label>Level <?= $i ?> Matrix Payout Amount (USDT)</label>
                    <input type="text" name="matrix_level_payout_<?= $i ?>" value="<?= htmlspecialchars($configs["matrix_level_payout_$i"] ?? '2.00') ?>">
                </div>
            <?php endfor; ?>

            <h3>Rebirth Vault Deduction Schedule (7 Levels)</h3>
            <?php for ($i = 1; $i <= 7; $i++): ?>
                <div class="form-group">
                    <label>Level <?= $i ?> Rebirth Vault Allocation (USDT)</label>
                    <input type="text" name="matrix_rebirth_deduction_<?= $i ?>" value="<?= htmlspecialchars($configs["matrix_rebirth_deduction_$i"] ?? '0.00') ?>">
                </div>
            <?php endfor; ?>

            <h3>Progression Pools Calibration (5, 10, 30, 50, 100, 200, 500, 1000, 2000 USDT Pools)</h3>
            <?php
            $poolTiers = [5, 10, 30, 50, 100, 200, 500, 1000, 2000];
            foreach ($poolTiers as $pt):
            ?>
                <div class="form-group">
                    <label>Pool <?= $pt ?> Depth 1 Payout (USDT)</label>
                    <input type="text" name="pool_<?= $pt ?>_d1_payout" value="<?= htmlspecialchars($configs["pool_{$pt}_d1_payout"] ?? '1.00') ?>">
                </div>
                <div class="form-group">
                    <label>Pool <?= $pt ?> Depth 2 Payout (USDT)</label>
                    <input type="text" name="pool_<?= $pt ?>_d2_payout" value="<?= htmlspecialchars($configs["pool_{$pt}_d2_payout"] ?? '3.00') ?>">
                </div>
            <?php endforeach; ?>

            <button type="submit" name="save_config" class="btn" style="margin-top: 15px;">Save & Apply Settings</button>
        </form>

        <p style="margin-top: 30px;"><a href="index.php">&larr; Back to Home</a></p>
    </div>
</body>
</html>
