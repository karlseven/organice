<?php
/** @var array<int,array<string,mixed>> $users */
use Core\Auth;
use Core\Csrf;
?>
<div class="home admin">
  <nav class="admin-tabs">
    <a href="<?= e(url('/admin')) ?>">Spaces</a>
    <a class="active" href="<?= e(url('/admin/users')) ?>">Users</a>
    <a href="<?= e(url('/admin/settings')) ?>">Settings</a>
    <a href="<?= e(url('/admin/audit')) ?>">Audit log</a>
  </nav>

  <h1>Users</h1>

  <details class="card admin-new">
    <summary>New user</summary>
    <form method="post" action="<?= e(url('/admin/users')) ?>" class="grid-form">
      <?= Csrf::field() ?>
      <label for="nu-email">Email</label>
      <input id="nu-email" name="email" type="email" required>

      <label for="nu-name">Display name</label>
      <input id="nu-name" name="display_name" maxlength="120">

      <label for="nu-user">Username</label>
      <input id="nu-user" name="username" maxlength="60" placeholder="derived from the name">

      <label for="nu-role">Role</label>
      <select id="nu-role" name="role">
        <option value="viewer">Viewer — read only</option>
        <option value="editor">Editor — writes in spaces they belong to</option>
        <option value="admin">Admin — everything</option>
      </select>

      <label for="nu-pass">Password</label>
      <input id="nu-pass" name="password" type="password" required minlength="10"
             autocomplete="new-password">

      <div class="grid-form-actions"><button class="btn" type="submit">Create user</button></div>
    </form>
  </details>

  <div class="admin-list">
    <?php foreach ($users as $u): ?>
      <?php $self = (int)$u['id'] === Auth::id(); ?>
      <details class="card">
        <summary>
          <strong><?= e((string)$u['display_name']) ?></strong>
          <span class="muted"><?= e((string)$u['email']) ?></span>
          <span class="pill"><?= e((string)$u['role']) ?></span>
          <?php if (!$u['is_active']): ?><span class="pill pill-lock">inactive</span><?php endif; ?>
          <?php if ($self): ?><span class="pill">you</span><?php endif; ?>
        </summary>

        <form method="post" action="<?= e(url('/admin/users/' . $u['id'])) ?>" class="grid-form">
          <?= Csrf::field() ?>
          <label>Display name</label>
          <input name="display_name" value="<?= e((string)$u['display_name']) ?>" maxlength="120"
                 <?= $self ? 'disabled' : '' ?>>

          <label>Role</label>
          <select name="role" <?= $self ? 'disabled' : '' ?>>
            <?php foreach (['viewer', 'editor', 'admin'] as $r): ?>
              <option value="<?= $r ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= $r ?></option>
            <?php endforeach; ?>
          </select>

          <label>Active</label>
          <select name="is_active" <?= $self ? 'disabled' : '' ?>>
            <option value="1" <?= $u['is_active'] ? 'selected' : '' ?>>Yes</option>
            <option value="0" <?= $u['is_active'] ? '' : 'selected' ?>>No</option>
          </select>

          <label>New password</label>
          <input name="password" type="password" minlength="10" autocomplete="new-password"
                 placeholder="leave blank to keep" <?= $self ? 'disabled' : '' ?>>

          <div class="grid-form-actions">
            <?php if ($self): ?>
              <p class="muted">You cannot change your own role or status — that is what stops the
                 last admin locking themselves out.</p>
            <?php else: ?>
              <button class="btn btn-sm" type="submit">Save</button>
            <?php endif; ?>
          </div>
        </form>
      </details>
    <?php endforeach; ?>
  </div>
</div>
