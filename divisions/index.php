<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/DivisionManagement.php';
Application::init();
require_login();

$me = current_user();

// Handle messages from add/edit operations
$msg = null;
$err = null;
if (isset($_GET['msg'])) {
    $msg = $_GET['msg'];
}
if (isset($_GET['err'])) {
    $err = $_GET['err'];
}

// Handle search
$search = trim($_GET['q'] ?? '');
$divisions = DivisionManagement::listDivisions($search);

header_html('Divisions');
?>

<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
  <h2>Divisions</h2>
  <a class="button" href="/divisions/add.php">Add</a>
</div>

<div class="card">
  <form method="get" class="stack">
    <div class="grid" style="grid-template-columns:1fr auto;gap:12px;">
      <label>Search
        <input type="text" name="q" value="<?=h($search)?>" placeholder="Division name">
      </label>
      <div style="align-self:end;">
        <button type="submit" class="button">Search</button>
      </div>
    </div>
  </form>
  
  <script>
    (function(){
      var form = document.querySelector('form[method="get"]');
      if (!form) return;
      var q = form.querySelector('input[name="q"]');
      var t;
      function submitNow() {
        if (typeof form.requestSubmit === 'function') form.requestSubmit();
        else form.submit();
      }
      if (q) {
        q.addEventListener('input', function(){
          if (t) clearTimeout(t);
          t = setTimeout(submitNow, 600);
        });
      }
    })();
  </script>
</div>

<?php if (empty($divisions)): ?>
  <p class="small">No divisions found.</p>
<?php else: ?>
  <div class="card">
    <table class="list">
      <thead>
        <tr>
          <th>Name</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($divisions as $division): ?>
          <tr>
            <td><?= h($division['name'] ?? '') ?></td>
            <td class="small">
              <a class="button small" href="/divisions/edit.php?id=<?= (int)$division['id'] ?>">Edit</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php footer_html(); ?>
