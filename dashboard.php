<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/User.php';
require_once __DIR__ . '/MatrixEngine.php';
require_once __DIR__ . '/VaultEngine.php';
require_once __DIR__ . '/PoolEngine.php';

if (DB_DRIVER === 'sqlite') {
    Database::initSchema();
}

$wallet = trim($_GET['wallet'] ?? '');
$userModel = new User();
$vaultEngine = new VaultEngine();
$poolEngine = new PoolEngine();

$user = null;
$transactions = [];
if ($wallet !== '') {
    $user = $userModel->getByWallet($wallet);
    if ($user) {
        $poolEngine->checkAndGraduateUser((int)$user['id']);
        $user = $userModel->getById((int)$user['id']); // Refresh
        $transactions = $vaultEngine->getUserLedger((int)$user['id']);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Altima - Member Dashboard</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #f8fafc; margin: 0; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; background: #1e293b; padding: 30px; border-radius: 12px; }
        h1, h2, h3 { color: #38bdf8; }
        .form-group { margin-bottom: 20px; display: flex; gap: 10px; }
        input[type="text"] { flex: 1; padding: 10px; border-radius: 6px; border: 1px solid #475569; background: #0f172a; color: white; }
        .btn { background: #0284c7; color: white; padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; text-decoration: none; }
        .btn:hover { background: #0369a1; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .stat-card { background: #334155; padding: 15px; border-radius: 8px; border-left: 4px solid #38bdf8; }
        .stat-card .val { font-size: 22px; font-weight: bold; color: #f8fafc; margin-top: 5px; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; background: #334155; border-radius: 8px; overflow: hidden; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #475569; }
        th { background: #0f172a; color: #38bdf8; }
        .positive { color: #4ade80; font-weight: bold; }
        .negative { color: #f87171; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Member Dashboard</h1>

        <form method="GET" class="form-group">
            <input type="text" name="wallet" value="<?= htmlspecialchars($wallet) ?>" placeholder="Enter BEP-20 Wallet Address (0x...)" required>
            <button type="submit" class="btn">View Account</button>
        </form>

        <?php if ($wallet !== '' && !$user): ?>
            <div style="background: #b91c1c; padding: 15px; border-radius: 6px;">
                User wallet address not found. <a href="register.php" style="color: white; font-weight: bold;">Click here to Register</a>.
            </div>
        <?php endif; ?>

        <?php if ($user): ?>
            <h2>Account Details (User ID: #<?= $user['id'] ?>)</h2>
            <div class="grid">
                <div class="stat-card">
                    <div>Direct Referrals</div>
                    <div class="val"><?= $user['direct_referral_count'] ?></div>
                </div>
                <div class="stat-card">
                    <div>Withdrawable Balance</div>
                    <div class="val">$<?= number_format($user['withdrawable_balance'], 2) ?> USDT</div>
                </div>
                <div class="stat-card">
                    <div>Rebirth Vault</div>
                    <div class="val">$<?= number_format($user['rebirth_vault_balance'], 2) ?> USDT</div>
                </div>
                <div class="stat-card">
                    <div>Total Earnings</div>
                    <div class="val">$<?= number_format($user['total_earnings'], 2) ?> USDT</div>
                </div>
                <div class="stat-card">
                    <div>Progression Pool Tier</div>
                    <div class="val" style="color: #facc15;"><?= htmlspecialchars($user['current_pool_tier']) ?></div>
                </div>
            </div>

            <h3>Financial Ledger & Transaction Audit</h3>
            <?php if (empty($transactions)): ?>
                <p>No transactions recorded yet.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Tx ID</th>
                            <th>Type</th>
                            <th>Amount</th>
                            <th>Description</th>
                            <th>Timestamp</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $tx): ?>
                            <tr>
                                <td>#<?= $tx['id'] ?></td>
                                <td><strong><?= htmlspecialchars($tx['type']) ?></strong></td>
                                <td class="<?= $tx['amount'] >= 0 ? 'positive' : 'negative' ?>">
                                    <?= $tx['amount'] >= 0 ? '+' : '' ?>$<?= number_format($tx['amount'], 2) ?> USDT
                                </td>
                                <td><?= htmlspecialchars($tx['description']) ?></td>
                                <td><?= $tx['created_at'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>

        <p style="margin-top: 30px;"><a href="index.php" class="btn">&larr; Back to Home</a></p>
    </div>
</body>
</html>
