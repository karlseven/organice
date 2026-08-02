<?php
/**
 * @var array<string,mixed> $space
 * @var array<int,array<string,mixed>> $members
 * @var array<int,array<string,mixed>> $addable
 */
use Core\Csrf;

$action = url('/admin/spaces/' . $space['id'] . '/members');
?>
<div class="home admin">
  <nav class="admin-tabs">
    <a href="<?= e(url('/admin')) ?>">Spaces</a>
    <a href="<?= e(url('/admin/users')) ?>">Users</a>
    <a href="<?= e(url('/admin/settings')) ?>">Settings</a>
    <a href="<?= e(url('/admin/audit')) ?>">Audit log</a>
  </nav>

  <h1>Members of <?= e((string)$space['title']) ?></h1>
  <p class="muted">
    This space is <strong><?= e((string)$space['visibility']) ?></strong>.
    <?php if ($space['visibility'] === 'public'): ?>
      Anyone can read it, so membership only controls who can <em>edit</em>.
    <?php elseif ($space['visibility'] === 'internal'): ?>
      Any signed-in user can read it; membership controls who can edit.
    <?php else: ?>
      Only the people listed here can read it at all.
    <?php endif; ?>
  </p>

  <?php if ($addable !== []): ?>
    <details class="card admin-new" open>
      <summary>Add a member</summary>
      <form method="post" action="<?= e($action) ?>" class="grid-form">
        <?= Csrf::field() ?>
        <label for="m-user">User</label>
        <select id="m-user" name="user_id" required>
          <option value="">Choose someone…</option>
          <?php foreach ($addable as $u): ?>
            <option value="<?= (int)$u['id'] ?>">
              <?= e((string)$u['display_name']) ?> — <?= e((string)$u['email']) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <label for="m-role">Role</label>
        <select id="m-role" name="role">
          <option value="viewer">Viewer — can read</option>
          <option value="editor">Editor — can write</option>
          <option value="owner">Owner — can write and manage</option>
        </select>

        <div class="grid-form-actions"><button class="btn" type="submit">Add</button></div>
      </form>
    </details>
  <?php endif; ?>

  <?php if ($members === []): ?>
    <p class="muted">
      Nobody has been added yet.
      <?php if ($space['visibility'] === 'private'): ?>
        A private space with no members is readable only by site admins.
      <?php endif; ?>
    </p>
  <?php else: ?>
    <div class="admin-list">
      <?php foreach ($members as $m): ?>
        <div class="card member-row">
          <div class="member-who">
            <strong><?= e((string)$m['display_name']) ?></strong>
            <span class="muted"><?= e((string)$m['email']) ?></span>
          </div>

          <form method="post" action="<?= e($action) ?>" class="member-form">
            <?= Csrf::field() ?>
            <input type="hidden" name="user_id" value="<?= (int)$m['user_id'] ?>">
            <select name="role" aria-label="Role for <?= e((string)$m['display_name']) ?>">
              <?php foreach (['viewer', 'editor', 'owner'] as $r): ?>
                <option value="<?= $r ?>" <?= $m['role'] === $r ? 'selected' : '' ?>><?= $r ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-sm" type="submit">Save</button>
          </form>

          <form method="post" action="<?= e($action) ?>" class="member-form"
                data-confirm="Remove <?= e((string)$m['display_name']) ?> from this space?">
            <?= Csrf::field() ?>
            <input type="hidden" name="user_id" value="<?= (int)$m['user_id'] ?>">
            <input type="hidden" name="role" value="">
            <button class="btn btn-ghost btn-sm" type="submit"><?= icon('trash', 14) ?></button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <p><a href="<?= e(url('/admin')) ?>">← Back to spaces</a></p>
</div>
