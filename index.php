<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/User.php';
require_once __DIR__ . '/MatrixEngine.php';
require_once __DIR__ . '/VaultEngine.php';
require_once __DIR__ . '/PoolEngine.php';
require_once __DIR__ . '/AdminConfig.php';

// Initialize Database Schema if using SQLite
if (DB_DRIVER === 'sqlite') {
    Database::initSchema();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Altima - Decentralized Direct-Selling Ecosystem</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #f8fafc; margin: 0; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; background: #1e293b; padding: 30px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.5); }
        h1, h2, h3 { color: #38bdf8; }
        .btn { display: inline-block; background: #0284c7; color: white; padding: 10px 20px; text-decoration: none; border-radius: 6px; font-weight: bold; margin-right: 10px; }
        .btn:hover { background: #0369a1; }
        .btn-admin { background: #e11d48; }
        .btn-admin:hover { background: #be123c; }
        .card { background: #334155; padding: 20px; margin-bottom: 20px; border-radius: 8px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Welcome to Altima</h1>
        <p>Decentralized Direct-Selling Peer-to-Peer Referral Ecosystem</p>

        <div class="card">
            <h3>Quick Navigation</h3>
            <a href="register.php" class="btn">Register / Activate Tier</a>
            <a href="dashboard.php" class="btn">User Dashboard</a>
            <a href="admin_dashboard.php" class="btn btn-admin">Admin Governance</a>
        </div>

        <div class="card">
            <h3>Core Platform Features</h3>
            <ul>
                <li><strong>Foundational Entry:</strong> $30 USDT (BEP-20 Standard).</li>
                <li><strong>100% Fee Recovery:</strong> 3 Direct Referrals = $30 USDT Recovery.</li>
                <li><strong>Matrix Architecture:</strong> Forced 3x3 BFS Auto-Spillover across 7 Levels.</li>
                <li><strong>Rebirth Flywheel:</strong> Automated parallel position creation at $30 threshold.</li>
                <li><strong>Progression Pools:</strong> Cyclical Graduation (50 $\rightarrow$ 100 $\rightarrow$ 200 USDT Pools).</li>
                <li><strong>Zero Company Retention:</strong> 100% Peer-to-Peer Liquidity Distribution.</li>
            </ul>
        </div>
    </div>
</body>
</html>
