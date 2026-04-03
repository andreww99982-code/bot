<?php
require_once __DIR__ . '/_auth.php';
$pageTitle = 'Users';

$msg   = '';
$error = '';

// Handle balance adjustment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add_balance') {
        $uid    = (int)($_POST['user_id'] ?? 0);
        $amount = (float)str_replace(',', '.', $_POST['amount'] ?? '0');
        if ($uid && $amount != 0) {
            Storage::addBalance($uid, $amount);
            $msg = 'Balance updated.';
        } else {
            $error = 'Invalid user or amount.';
        }
    }
}

$users  = Storage::allUsers();
$orders = Storage::allOrders();

// Build spent totals per user
$spentByUser = [];
foreach ($orders as $o) {
    $uid = (int)$o['user_id'];
    $spentByUser[$uid] = ($spentByUser[$uid] ?? 0) + (float)$o['amount'];
}

require_once __DIR__ . '/_header.php';
?>

<?php if ($msg):  ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card">
  <div class="card-header">
    All Users
    <span class="badge badge-gray"><?= count($users) ?> total</span>
  </div>
  <div class="card-body" style="padding:0">
    <?php if (empty($users)): ?>
      <p style="padding:20px;color:#6b7280">No users yet.</p>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>TG ID</th><th>Name</th><th>Username</th><th>Lang</th>
          <th>Balance</th><th>Orders</th><th>Spent</th><th>Registered</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($users as $u):
        $uid      = (int)$u['id'];
        $uOrders  = count(array_filter($orders, fn($o) => (int)$o['user_id'] === $uid));
        $uSpent   = round($spentByUser[$uid] ?? 0, 2);
      ?>
        <tr>
          <td><code><?= $uid ?></code></td>
          <td><?= htmlspecialchars($u['first_name'] ?? '') ?></td>
          <td><?= $u['username'] ? '@'.htmlspecialchars($u['username']) : '—' ?></td>
          <td><?= htmlspecialchars(strtoupper($u['lang'] ?? '—')) ?></td>
          <td><b><?= htmlspecialchars($u['balance'] ?? 0) ?></b> <?= CURRENCY_SIGN ?></td>
          <td><?= $uOrders ?></td>
          <td><?= $uSpent ?> <?= CURRENCY_SIGN ?></td>
          <td><?= isset($u['created_at']) ? date('d.m.Y', $u['created_at']) : '—' ?></td>
          <td>
            <button onclick="openAdjust(<?= $uid ?>, '<?= htmlspecialchars($u['first_name'] ?? $uid) ?>')"
                    class="btn btn-secondary btn-sm">💰 Balance</button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<!-- Balance adjustment modal -->
<div id="adjustModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);
     z-index:999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:12px;padding:28px;width:340px;box-shadow:0 8px 32px rgba(0,0,0,.2)">
    <h3 style="margin-bottom:16px">💰 Adjust Balance</h3>
    <p id="adjustName" style="margin-bottom:16px;color:#6b7280;font-size:14px"></p>
    <form method="post">
      <input type="hidden" name="action" value="add_balance">
      <input type="hidden" name="user_id" id="adjustUserId">
      <div class="form-row">
        <label>Amount (use negative to deduct)</label>
        <input type="number" name="amount" step="0.01" required id="adjustAmount">
      </div>
      <div style="display:flex;gap:10px;margin-top:8px">
        <button type="submit" class="btn btn-primary">Apply</button>
        <button type="button" onclick="closeAdjust()" class="btn btn-secondary">Cancel</button>
      </div>
    </form>
  </div>
</div>
<script>
function openAdjust(id, name) {
  document.getElementById('adjustUserId').value = id;
  document.getElementById('adjustName').textContent = 'User: ' + name + ' (ID: ' + id + ')';
  document.getElementById('adjustAmount').value = '';
  document.getElementById('adjustModal').style.display = 'flex';
}
function closeAdjust() {
  document.getElementById('adjustModal').style.display = 'none';
}
</script>

<?php require_once __DIR__ . '/_footer.php'; ?>
