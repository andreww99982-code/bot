<?php
require_once __DIR__ . '/_auth.php';
$pageTitle = 'Settings';

$msg   = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settings = [
        'shop_name'        => trim($_POST['shop_name']        ?? ''),
        'support_username' => trim($_POST['support_username'] ?? ''),
        'min_topup'        => max(1, (float)str_replace(',', '.', $_POST['min_topup'] ?? '100')),
        'welcome_text_ru'  => trim($_POST['welcome_text_ru']  ?? ''),
        'welcome_text_en'  => trim($_POST['welcome_text_en']  ?? ''),
    ];

    // Change admin password if provided
    $newPass = trim($_POST['new_password'] ?? '');
    if ($newPass !== '') {
        if (strlen($newPass) < 6) {
            $error = 'Password must be at least 6 characters.';
        } else {
            // Write to config is not safe; instruct user to set env / config instead
            $msg = 'Settings saved. Note: password changes must be applied in config.php or as an environment variable.';
        }
    }

    if (!$error) {
        Storage::saveSettings($settings);
        $msg = $msg ?: 'Settings saved.';
    }
}

$settings = Storage::getSettings();
require_once __DIR__ . '/_header.php';
?>

<?php if ($msg):  ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card">
  <div class="card-header">⚙️ Shop Settings</div>
  <div class="card-body">
    <form method="post">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div class="form-row">
          <label>Shop Name</label>
          <input type="text" name="shop_name" value="<?= htmlspecialchars($settings['shop_name'] ?? '') ?>">
        </div>
        <div class="form-row">
          <label>Support Username (without @)</label>
          <input type="text" name="support_username" value="<?= htmlspecialchars($settings['support_username'] ?? '') ?>">
        </div>
      </div>
      <div class="form-row">
        <label>Minimum Top-up Amount (<?= CURRENCY_SIGN ?>)</label>
        <input type="number" name="min_topup" step="1" min="1"
               value="<?= htmlspecialchars($settings['min_topup'] ?? 100) ?>">
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div class="form-row">
          <label>Welcome Message (RU)</label>
          <textarea name="welcome_text_ru" rows="4"><?= htmlspecialchars($settings['welcome_text_ru'] ?? '') ?></textarea>
        </div>
        <div class="form-row">
          <label>Welcome Message (EN)</label>
          <textarea name="welcome_text_en" rows="4"><?= htmlspecialchars($settings['welcome_text_en'] ?? '') ?></textarea>
        </div>
      </div>
      <button type="submit" class="btn btn-primary">💾 Save Settings</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">🔐 Admin Password</div>
  <div class="card-body">
    <p style="margin-bottom:16px;color:#6b7280;font-size:14px">
      For security, set your password via the <code>ADMIN_PASSWORD</code> environment variable or directly
      in <code>config.php</code>. The field below is informational only.
    </p>
    <form method="post">
      <div class="form-row" style="max-width:320px">
        <label>New Password (leave blank to keep current)</label>
        <input type="password" name="new_password" autocomplete="new-password">
      </div>
      <button type="submit" class="btn btn-secondary">Update Password Info</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">ℹ️ Bot Configuration</div>
  <div class="card-body">
    <p style="font-size:14px;color:#374151;line-height:1.7">
      The following values are configured via <code>config.php</code> or environment variables:<br>
      <b>BOT_TOKEN</b>, <b>WEBHOOK_SECRET</b>, <b>HELEKET_API_KEY</b>, <b>HELEKET_SHOP_ID</b>,
      <b>CRYPTOBOT_TOKEN</b>, <b>BASE_URL</b>.<br><br>
      To register your webhook, run:<br>
      <code style="background:#f3f4f6;padding:6px 10px;border-radius:4px;display:inline-block;margin-top:4px">
        php set_webhook.php
      </code>
    </p>
  </div>
</div>

<?php require_once __DIR__ . '/_footer.php'; ?>
