<?php
/** @var array<int,array<string,mixed>> $entries */

/* Sign-in noise is separated from everything else: a wall of successful logins
   is what makes an audit log go unread, and the entries that matter are the
   ones that changed something. */
$isLogin = static fn(array $x): bool => str_starts_with((string)$x['action'], 'login')
    || $x['action'] === 'logout';
?>
<div class="home admin">
  <nav class="admin-tabs">
    <a href="<?= e(url('/admin')) ?>">Spaces</a>
    <a href="<?= e(url('/admin/users')) ?>">Users</a>
    <a href="<?= e(url('/admin/settings')) ?>">Settings</a>
    <a class="active" href="<?= e(url('/admin/audit')) ?>">Audit log</a>
  </nav>

  <h1>Audit log</h1>
  <p class="muted">
    The 200 most recent security-relevant actions. Page edits are not listed
    here — every one of them is already a revision with an author and a time.
  </p>

  <?php if ($entries === []): ?>
    <p class="muted">Nothing recorded yet.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="audit-table">
        <thead>
          <tr><th>When</th><th>Who</th><th>Action</th><th>Target</th><th>Detail</th><th>From</th></tr>
        </thead>
        <tbody>
          <?php foreach ($entries as $x): ?>
            <tr class="<?= $isLogin($x) ? 'audit-quiet' : '' ?>">
              <td title="<?= e((string)$x['created_at']) ?>"><?= e(ago((string)$x['created_at'])) ?></td>
              <td><?= e((string)$x['actor']) ?></td>
              <td><code><?= e((string)$x['action']) ?></code></td>
              <td class="muted">
                <?= $x['target_type'] !== '' ? e((string)$x['target_type']) : '' ?>
                <?= $x['target_id'] ? '#' . (int)$x['target_id'] : '' ?>
              </td>
              <td class="muted"><?= e((string)$x['detail']) ?></td>
              <td class="muted"><?= e((string)($x['ip_text'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
