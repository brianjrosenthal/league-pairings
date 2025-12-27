<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/DivisionManagement.php';
require_once __DIR__ . '/../lib/PreviousGamesManagement.php';
Application::init();
require_login();

$me = current_user();

// Get division_id from URL
$division_id = (int)($_GET['division_id'] ?? 0);

if ($division_id <= 0) {
    redirect('/divisions/index.php', 'err=' . urlencode('Invalid division ID.'));
    exit;
}

// Get division info
$division = DivisionManagement::findById($division_id);

if (!$division) {
    redirect('/divisions/index.php', 'err=' . urlencode('Division not found.'));
    exit;
}

// Get win/loss records for all teams in this division
$records = PreviousGamesManagement::getWinLossRecordsByDivision($division_id);

header_html('Win/Loss Records - ' . h($division['name']));
?>

<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
  <h2>Win/Loss Records: <?= h($division['name']) ?></h2>
  <div>
    <a class="button" href="/divisions/index.php">Back to Divisions</a>
  </div>
</div>

<?php if (empty($records)): ?>
  <div class="card">
    <p class="small">No teams found in this division.</p>
  </div>
<?php else: ?>
  <div class="card">
    <table class="list">
      <thead>
        <tr>
          <th>Team Name</th>
          <th style="text-align:center;">Wins</th>
          <th style="text-align:center;">Losses</th>
          <th style="text-align:center;">Total Games</th>
          <th style="text-align:center;">Win %</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($records as $record): ?>
          <tr>
            <td><?= h($record['team_name']) ?></td>
            <td style="text-align:center;"><?= (int)$record['wins'] ?></td>
            <td style="text-align:center;"><?= (int)$record['losses'] ?></td>
            <td style="text-align:center;"><?= (int)$record['total_games'] ?></td>
            <td style="text-align:center;">
              <?php if ($record['total_games'] > 0): ?>
                <?= number_format($record['win_percentage'], 1) ?>%
              <?php else: ?>
                -
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php footer_html(); ?>
