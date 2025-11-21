<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/TimeslotManagement.php';
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

// Get all timeslots
$timeslots = TimeslotManagement::listTimeslots();

header_html('Timeslots');
?>

<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
  <h2>Timeslots</h2>
  <div style="display:flex;gap:8px;">
    <?php if (!empty($timeslots) && $me['is_admin']): ?>
      <form method="post" action="/timeslots/delete_all_eval.php" style="display:inline;margin:0;" 
            onsubmit="return confirm('Are you sure you want to delete ALL timeslots? This will permanently delete all timeslots and their availability records. This action cannot be undone.');">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <button type="submit" class="button" style="background:#d32f2f;color:white;border:none;cursor:pointer;">
          Delete All Timeslots
        </button>
      </form>
    <?php endif; ?>
    <a class="button" href="/timeslots/add.php">Add</a>
  </div>
</div>

<?php if (empty($timeslots)): ?>
  <p class="small">No timeslots found.</p>
<?php else: ?>
  <div class="card">
    <table class="list">
      <thead>
        <tr>
          <th>Date</th>
          <th>Modifier</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($timeslots as $timeslot): ?>
          <tr>
            <td><?= h(TimeslotManagement::formatDate($timeslot['date'])) ?></td>
            <td><?= h($timeslot['modifier'] ?? '') ?></td>
            <td class="small">
              <a class="button small" href="/timeslots/locations.php?id=<?= (int)$timeslot['id'] ?>">Locations</a>
              <a class="button small" href="/timeslots/teams.php?id=<?= (int)$timeslot['id'] ?>">Teams</a>
              <a class="button small" href="/timeslots/edit.php?id=<?= (int)$timeslot['id'] ?>">Edit</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php footer_html(); ?>
