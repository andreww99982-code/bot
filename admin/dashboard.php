<?php
require_once __DIR__ . '/_auth.php';
$pageTitle = 'Dashboard';

$users     = Storage::allUsers();
$orders    = Storage::allOrders();
$products  = Storage::allProducts();
$cats      = Storage::allCategories();
$revenue   = array_sum(array_column($orders, 'amount'));
$topupReqs = Storage::allTopupRequests();
$pendingTopups = count(array_filter($topupReqs, fn($r) => ($r['status'] ?? '') === 'pending'));

require_once __DIR__ . '/_header.php';
?>

<div class="stats-grid" style="margin-bottom:24px">
  <div class="stat-card">
    <div class="value"><?= count($users) ?></div>
    <div class="label">👥 Users</div>
  </div>
  <div class="stat-card">
    <div class="value"><?= count($products) ?></div>
    <div class="label">📦 Products</div>
  </div>
  <div class="stat-card">
    <div class="value"><?= count($orders) ?></div>
    <div class="label">🛒 Orders</div>
  </div>
  <div class="stat-card">
    <div class="value"><?= number_format($revenue, 2) ?> <?= CURRENCY_SIGN ?></div>
    <div class="label">💰 Revenue</div>
  </div>
  <?php if ($pendingTopups > 0): ?>
  <div class="stat-card">
    <div class="value" style="color:#f59e0b"><?= $pendingTopups ?></div>
    <div class="label">⏳ Pending Top-ups</div>
  </div>
  <?php endif; ?>
</div>

<!-- Recent Orders -->
<div class="card">
  <div class="card-header">
    🛒 Recent Orders
    <a href="orders.php" class="btn btn-secondary btn-sm">View all</a>
  </div>
  <div class="card-body" style="padding:0">
    <?php
    $recentOrders = array_slice(array_reverse($orders), 0, 10);
    if (empty($recentOrders)):
    ?>
      <p style="padding:20px;color:#6b7280">No orders yet.</p>
    <?php else: ?>
    <table>
      <thead>
        <tr><th>#</th><th>User</th><th>Product</th><th>Amount</th><th>Date</th></tr>
      </thead>
      <tbody>
      <?php foreach ($recentOrders as $o):
        $prod = Storage::getProduct($o['product_id']);
        $user = Storage::getUser((int)$o['user_id']);
      ?>
        <tr>
          <td><?= (int)$o['id'] ?></td>
          <td><?= htmlspecialchars($user['first_name'] ?? $o['user_id']) ?></td>
          <td><?= htmlspecialchars($prod['name_ru'] ?? '#'.$o['product_id']) ?></td>
          <td><?= htmlspecialchars($o['amount']) ?> <?= CURRENCY_SIGN ?></td>
          <td><?= date('d.m.Y H:i', $o['created_at']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/_footer.php'; ?>
