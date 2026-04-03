<?php
require_once __DIR__ . '/_auth.php';
$pageTitle = 'Orders';

$orders = array_reverse(Storage::allOrders());

// Handle top-up approval
$msg   = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'approve_topup') {
        $uid    = (int)($_POST['user_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $idx    = (int)($_POST['topup_index'] ?? -1);
        if ($uid && $amount > 0 && $idx >= 0) {
            Storage::addBalance($uid, $amount);
            // Mark as approved
            $all = Storage::allTopupRequests();
            if (isset($all[$idx])) {
                $all[$idx]['status'] = 'approved';
                $all[$idx]['approved_at'] = time();
                Storage::save('topup_requests.json', $all);
            }
            $msg = "Approved top-up of {$amount} for user #{$uid}.";
        }
    }
}

require_once __DIR__ . '/_header.php';
?>

<?php if ($msg):  ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Pending top-ups -->
<?php
$pendingTopups = array_values(array_filter(Storage::allTopupRequests(), fn($r) => ($r['status'] ?? '') === 'pending'));
if (!empty($pendingTopups)):
?>
<div class="card">
  <div class="card-header" style="color:#d97706">⏳ Pending Top-up Requests</div>
  <div class="card-body" style="padding:0">
    <table>
      <thead><tr><th>User ID</th><th>Amount</th><th>Provider</th><th>Date</th><th>Action</th></tr></thead>
      <tbody>
      <?php
        $rawTopups = Storage::allTopupRequests();
        foreach ($rawTopups as $idx => $r):
          if (($r['status'] ?? '') !== 'pending') continue;
          $user = Storage::getUser((int)$r['user_id']);
      ?>
        <tr>
          <td><?= (int)$r['user_id'] ?> <?= $user ? '('.htmlspecialchars($user['first_name'] ?? '').')' : '' ?></td>
          <td><b><?= htmlspecialchars($r['amount']) ?></b> <?= CURRENCY_SIGN ?></td>
          <td><?= htmlspecialchars($r['provider'] ?? '') ?></td>
          <td><?= date('d.m.Y H:i', $r['created_at']) ?></td>
          <td>
            <form method="post" style="display:inline">
              <input type="hidden" name="action" value="approve_topup">
              <input type="hidden" name="user_id" value="<?= (int)$r['user_id'] ?>">
              <input type="hidden" name="amount" value="<?= htmlspecialchars($r['amount']) ?>">
              <input type="hidden" name="topup_index" value="<?= $idx ?>">
              <button type="submit" class="btn btn-success btn-sm">✅ Approve</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Orders list -->
<div class="card">
  <div class="card-header">
    All Orders
    <span class="badge badge-gray"><?= count($orders) ?> total</span>
  </div>
  <div class="card-body" style="padding:0">
    <?php if (empty($orders)): ?>
      <p style="padding:20px;color:#6b7280">No orders yet.</p>
    <?php else: ?>
    <table>
      <thead>
        <tr><th>#</th><th>User</th><th>Product</th><th>Amount</th><th>Status</th><th>Date</th></tr>
      </thead>
      <tbody>
      <?php foreach ($orders as $o):
        $prod = Storage::getProduct($o['product_id']);
        $user = Storage::getUser((int)$o['user_id']);
      ?>
        <tr>
          <td><?= (int)$o['id'] ?></td>
          <td>
            <?= htmlspecialchars($user['first_name'] ?? $o['user_id']) ?>
            <small style="color:#6b7280">(<?= (int)$o['user_id'] ?>)</small>
          </td>
          <td><?= htmlspecialchars($prod['name_ru'] ?? '#'.$o['product_id']) ?></td>
          <td><?= htmlspecialchars($o['amount']) ?> <?= CURRENCY_SIGN ?></td>
          <td>
            <span class="badge <?= ($o['status'] ?? '') === 'completed' ? 'badge-green' : 'badge-gray' ?>">
              <?= htmlspecialchars($o['status'] ?? '—') ?>
            </span>
          </td>
          <td><?= date('d.m.Y H:i', $o['created_at']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/_footer.php'; ?>
