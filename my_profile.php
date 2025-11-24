<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/UserManagement.php';
require_once __DIR__ . '/lib/Files.php';
Application::init();
require_login();

$me = current_user();
$isAdmin = !empty($me['is_admin']);

$err = null;
$msg = null;

// Helper function
function nn($v) { 
    $v = is_string($v) ? trim($v) : $v; 
    return ($v === '' ? null : $v); 
}

// Handle POST actions
$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if ($action === 'update_profile') {
        $first = trim($_POST['first_name'] ?? '');
        $last  = trim($_POST['last_name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));

        $errors = [];
        if ($first === '') $errors[] = 'First name is required.';
        if ($last === '')  $errors[] = 'Last name is required.';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';

        if (empty($errors)) {
            try {
                $ctx = UserContext::getLoggedInUserContext();
                $ok = UserManagement::updateUserProfile($ctx, (int)$me['id'], $first, $last, $email);
                if ($ok) {
                    $msg = 'Profile updated.';
                    // Refresh $me
                    $me = UserManagement::findById((int)$me['id']) ?: $me;
                } else {
                    $err = 'Failed to update profile.';
                }
            } catch (Throwable $e) {
                $err = 'Error updating profile: ' . $e->getMessage();
            }
        } else {
            $err = implode(' ', $errors);
        }
    }
}

header_html('My Profile');
?>
<h2>My Profile</h2>
<?php
  // Surface messages from upload_photo redirect
  if (isset($_GET['uploaded'])) { $msg = 'Photo uploaded.'; }
  if (isset($_GET['deleted'])) { $msg = 'Photo removed.'; }
  if (isset($_GET['err'])) { 
    $err = 'Photo upload failed.';
    // Include detailed error message if provided
    if (isset($_GET['err_msg']) && $_GET['err_msg'] !== '') {
      $err .= ' ' . $_GET['err_msg'];
    }
  }
?>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <h3>Profile Photo</h3>
  <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
    <?php
      $meName = trim((string)($me['first_name'] ?? '').' '.(string)($me['last_name'] ?? ''));
      $meInitials = strtoupper((string)substr((string)($me['first_name'] ?? ''),0,1).(string)substr((string)($me['last_name'] ?? ''),0,1));
      $mePhotoUrl = Files::profilePhotoUrl($me['photo_public_file_id'] ?? null, 80);
    ?>
    <?php if ($mePhotoUrl !== ''): ?>
      <img class="avatar" src="<?= h($mePhotoUrl) ?>" alt="<?= h($meName) ?>" style="width:80px;height:80px;border-radius:50%;object-fit:cover;">
    <?php else: ?>
      <div class="avatar avatar-initials" aria-hidden="true" style="width:80px;height:80px;border-radius:50%;background:#007bff;color:white;display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:500;"><?= h($meInitials) ?></div>
    <?php endif; ?>

    <form method="post" action="/upload_photo.php?user_id=<?= (int)$me['id'] ?>&return_to=/my_profile.php" enctype="multipart/form-data" class="stack" style="margin-left:auto;min-width:260px" id="profilePhotoForm">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <label>Upload a new photo
        <input type="file" name="photo" accept="image/*" required>
      </label>
      <div class="actions">
        <button class="button" id="profilePhotoBtn">Upload Photo</button>
      </div>
    </form>
    <?php if (!empty($mePhotoUrl)): ?>
      <form method="post" action="/upload_photo.php?user_id=<?= (int)$me['id'] ?>&return_to=/my_profile.php" onsubmit="return confirm('Remove this photo?');" style="margin-left:12px;">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="delete">
        <button class="button">Remove Photo</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <h3>Personal Information</h3>
  <form method="post" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="update_profile">
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
      <label>First name
        <input type="text" name="first_name" value="<?=h($me['first_name'])?>" required>
      </label>
      <label>Last name
        <input type="text" name="last_name" value="<?=h($me['last_name'])?>" required>
      </label>
      <label>Email
        <input type="email" name="email" value="<?=h($me['email'])?>" required>
      </label>
    </div>

    <div class="actions">
      <button class="primary">Save Profile</button>
      <a class="button" href="/change_password.php">Change Password</a>
    </div>
  </form>
</div>

<script>
  (function(){
    // Add double-click protection to profile photo upload
    var profilePhotoForm = document.getElementById('profilePhotoForm');
    var profilePhotoBtn = document.getElementById('profilePhotoBtn');
    
    if (profilePhotoForm && profilePhotoBtn) {
      profilePhotoForm.addEventListener('submit', function(e) {
        if (profilePhotoBtn.disabled) {
          e.preventDefault();
          return;
        }
        profilePhotoBtn.disabled = true;
        profilePhotoBtn.textContent = 'Uploading...';
      });
    }
  })();
</script>

<?php footer_html(); ?>
