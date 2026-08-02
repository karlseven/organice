<?php
/** @var array<int,array<string,mixed>> $spaces */
use Core\Csrf;
?>
<div class="home admin">
  <nav class="admin-tabs">
    <a class="active" href="<?= e(url('/admin')) ?>">Spaces</a>
    <a href="<?= e(url('/admin/users')) ?>">Users</a>
    <a href="<?= e(url('/admin/settings')) ?>">Settings</a>
    <a href="<?= e(url('/admin/audit')) ?>">Audit log</a>
  </nav>

  <h1>Spaces</h1>

  <details class="card admin-new">
    <summary>New space</summary>
    <form method="post" action="<?= e(url('/admin/spaces')) ?>" class="grid-form">
      <?= Csrf::field() ?>
      <label for="ns-title">Title</label>
      <input id="ns-title" name="title" required maxlength="160">

      <label for="ns-slug">URL slug</label>
      <input id="ns-slug" name="slug" placeholder="derived from the title" maxlength="80">

      <label for="ns-desc">Description</label>
      <input id="ns-desc" name="description" maxlength="400">

      <label for="ns-vis">Visibility</label>
      <select id="ns-vis" name="visibility">
        <option value="public">Public — anyone can read</option>
        <option value="internal">Internal — any signed-in user</option>
        <option value="private">Private — members only</option>
      </select>

      <label for="ns-accent">Accent</label>
      <input id="ns-accent" name="accent" type="color" value="#5b7cfa">

      <div class="grid-form-actions"><button class="btn" type="submit">Create space</button></div>
    </form>
  </details>

  <?php if ($spaces === []): ?>
    <p class="muted">No spaces yet.</p>
  <?php else: ?>
    <div class="admin-list">
      <?php foreach ($spaces as $s): ?>
        <details class="card">
          <summary>
            <strong><?= e((string)$s['title']) ?></strong>
            <span class="muted">/s/<?= e((string)$s['slug']) ?></span>
            <span class="pill"><?= e((string)$s['visibility']) ?></span>
            <span class="muted"><?= (int)$s['page_count'] ?> pages</span>
          </summary>

          <form method="post" action="<?= e(url('/admin/spaces/' . $s['id'])) ?>" class="grid-form">
            <?= Csrf::field() ?>
            <label>Title</label>
            <input name="title" value="<?= e((string)$s['title']) ?>" required maxlength="160">

            <label>Description</label>
            <input name="description" value="<?= e((string)$s['description']) ?>" maxlength="400">

            <label>Visibility</label>
            <select name="visibility">
              <?php foreach (['public' => 'Public', 'internal' => 'Internal', 'private' => 'Private'] as $v => $l): ?>
                <option value="<?= $v ?>" <?= $s['visibility'] === $v ? 'selected' : '' ?>><?= $l ?></option>
              <?php endforeach; ?>
            </select>

            <label>Accent</label>
            <input name="accent" type="color" value="<?= e((string)$s['accent']) ?>">

            <div class="grid-form-actions">
              <button class="btn btn-sm" type="submit">Save</button>
              <a class="btn btn-ghost btn-sm" href="<?= e(Core\Site::spaceUrl((string)$s['slug'])) ?>">Open</a>
              <a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/spaces/' . $s['id'] . '/members')) ?>">Members</a>
            </div>
          </form>

          <form method="post" action="<?= e(url('/admin/spaces/' . $s['id'] . '/delete')) ?>"
                class="danger-form" data-confirm="Delete &quot;<?= e((string)$s['title']) ?>&quot; and every page in it? This cannot be undone.">
            <?= Csrf::field() ?>
            <button class="btn btn-danger btn-sm" type="submit"><?= icon('trash', 14) ?> Delete space</button>
          </form>
        </details>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
