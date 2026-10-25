<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/User.php';
require_once __DIR__ . '/MatrixEngine.php';

if (DB_DRIVER === 'sqlite') {
    Database::initSchema();
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $wallet = trim($_POST['wallet_address'] ?? '');
    $referrer = trim($_POST['referrer_wallet'] ?? '');

    if (empty($wallet)) {
        $error = 'Wallet address is required.';
    } else {
        try {
            $matrixEngine = new MatrixEngine();
            $user = $matrixEngine->registerAndPlace($wallet, $referrer ?: null);
            $message = "Registration & 3x3 Matrix Activation Successful! User ID: " . $user['id'];
        } catch (Exception $e) {
            $error = "Registration failed: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Altima - Member Registration</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #f8fafc; margin: 0; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; background: #1e293b; padding: 30px; border-radius: 12px; }
        h1 { color: #38bdf8; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: bold; }
        input[type="text"] { width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #475569; background: #0f172a; color: white; box-sizing: border-box; }
        .btn { background: #0284c7; color: white; padding: 12px 20px; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; font-weight: bold; }
        .btn:hover { background: #0369a1; }
        .alert-success { background: #15803d; color: white; padding: 12px; border-radius: 6px; margin-bottom: 20px; }
        .alert-error { background: #b91c1c; color: white; padding: 12px; border-radius: 6px; margin-bottom: 20px; }
        a { color: #38bdf8; text-decoration: none; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Register / Activate Position</h1>

        <?php if ($message): ?>
            <div class="alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>Your BEP-20 Wallet Address ($30 USDT Activation):</label>
                <input type="text" name="wallet_address" placeholder="0x..." required>
            </div>
            <div class="form-group">
                <label>Sponsor / Referrer Wallet Address (Optional):</label>
                <input type="text" name="referrer_wallet" placeholder="0x...">
            </div>
            <button type="submit" class="btn">Activate Position ($30 USDT)</button>
        </form>

        <p style="margin-top: 20px;"><a href="index.php">&larr; Back to Home</a> | <a href="dashboard.php">Go to Dashboard &rarr;</a></p>
    </div>
</body>
</html>
