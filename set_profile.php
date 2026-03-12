<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require 'config.php';

function esc($v) {
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}

// Must be logged in
if (empty($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$currentUserId = (int)$_SESSION['user_id'];
$isEdit = isset($_GET['edit']) && $_GET['edit'] == '1';

$errors = [];
$success = "";

// Fetch current user
$stmt = $conn->prepare("SELECT id, email, username, profile_name, about, avatar, profile_completed FROM users WHERE id = ? LIMIT 1");
if (!$stmt) {
    die("DB error: prepare failed");
}
$stmt->bind_param("i", $currentUserId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    session_destroy();
    header("Location: index.php");
    exit;
}

/*
  Old code forced redirect when profile_completed=1, so user could never edit again.
  New logic:
  - If profile is completed AND user is NOT in edit mode -> go home
  - If user opens with ?edit=1 -> allow editing always
*/
if ((int)$user['profile_completed'] === 1 && !$isEdit) {
    header("Location: index.php");
    exit;
}

// Handle form submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $profile_name = trim($_POST['profile_name'] ?? '');
    $username     = trim($_POST['username'] ?? '');
    $about        = trim($_POST['about'] ?? '');

    // Basic validation
    if ($profile_name === '' || mb_strlen($profile_name) < 2) {
        $errors[] = "Profile name must be at least 2 characters.";
    }

    // username rules: letters, numbers, underscore, dot; 3-30 chars
    if ($username === '' || !preg_match('/^[a-zA-Z0-9._]{3,30}$/', $username)) {
        $errors[] = "Username must be 3-30 chars and contain only letters, numbers, dot (.) or underscore (_).";
    }

    // Check username uniqueness (excluding me)
    if (!$errors) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("si", $username, $currentUserId);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($exists) {
                $errors[] = "This username is already taken.";
            }
        } else {
            $errors[] = "Database error. Please try again.";
        }
    }

    // Avatar upload (optional)
    $avatarPath = $user['avatar']; // keep old if not uploaded

    if (!$errors && !empty($_FILES['avatar']['name'])) {
        if (!is_dir(__DIR__ . "/uploads")) {
            @mkdir(__DIR__ . "/uploads", 0777, true);
        }

        if ($_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = "Avatar upload failed. Please try again.";
        } else {
            $tmp  = $_FILES['avatar']['tmp_name'];
            $size = (int)($_FILES['avatar']['size'] ?? 0);

            // Limit size (2MB)
            if ($size > 2 * 1024 * 1024) {
                $errors[] = "Avatar must be under 2MB.";
            } else {
                $info = @getimagesize($tmp);
                if (!$info) {
                    $errors[] = "Avatar must be an image (jpg/png/webp).";
                } else {
                    $mime = $info['mime'] ?? '';
                    $ext = '';
                    if ($mime === 'image/jpeg') $ext = 'jpg';
                    elseif ($mime === 'image/png') $ext = 'png';
                    elseif ($mime === 'image/webp') $ext = 'webp';
                    else $errors[] = "Only JPG, PNG, or WEBP allowed.";

                    if (!$errors) {
                        $fileName = "avatar_" . $currentUserId . "_" . time() . "." . $ext;
                        $destFs = __DIR__ . "/uploads/" . $fileName;

                        if (!move_uploaded_file($tmp, $destFs)) {
                            $errors[] = "Could not save uploaded avatar. Check uploads folder permissions.";
                        } else {
                            $avatarPath = "uploads/" . $fileName;
                        }
                    }
                }
            }
        }
    }

    // Save
    if (!$errors) {
        $completed = 1;

        $stmt = $conn->prepare("
            UPDATE users
            SET profile_name = ?, username = ?, about = ?, avatar = ?, profile_completed = ?
            WHERE id = ?
        ");
        if (!$stmt) {
            $errors[] = "Database error. Please try again.";
        } else {
            $stmt->bind_param("ssssii", $profile_name, $username, $about, $avatarPath, $completed, $currentUserId);
            $stmt->execute();
            $stmt->close();

            header("Location: index.php");
            exit;
        }
    }
}

// For avatar initial
$initial = strtoupper(substr(($user['profile_name'] ?: $user['email'] ?: 'U'), 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo $isEdit ? 'Edit Profile' : 'Set Profile'; ?></title>

  <style>
    :root{
      --bg:#0b141a;
      --card:#111b21;
      --muted:#8696a0;
      --text:#e9edef;
      --line:#22313a;
      --accent:#00a884;
      --accent2:#00bfa5;
      --danger:#ff5c5c;
      --dangerBg:rgba(255,92,92,.12);
      --shadow: 0 20px 60px rgba(0,0,0,.45);
      --radius:16px;
    }

    *{ box-sizing:border-box; }
    body{
      margin:0;
      font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, "Noto Sans", "Helvetica Neue", sans-serif;
      background: radial-gradient(1200px 700px at 20% -20%, rgba(0,191,165,.28), transparent 60%),
                  radial-gradient(900px 520px at 120% 0%, rgba(0,168,132,.22), transparent 55%),
                  var(--bg);
      color:var(--text);
      min-height:100vh;
    }

    .topbar{
      height:64px;
      display:flex;
      align-items:center;
      justify-content:space-between;
      padding:0 18px;
      border-bottom:1px solid rgba(255,255,255,.06);
      background: rgba(17,27,33,.72);
      backdrop-filter: blur(10px);
      position: sticky;
      top:0;
      z-index:10;
    }

    .brand{
      display:flex;
      align-items:center;
      gap:10px;
      font-weight:700;
      letter-spacing:.3px;
    }

    .brand-badge{
      width:34px;height:34px;
      border-radius:10px;
      display:grid;
      place-items:center;
      background: linear-gradient(135deg, var(--accent2), var(--accent));
      color:#062b23;
      font-weight:900;
    }

    .top-actions a{
      color:var(--text);
      text-decoration:none;
      font-weight:600;
      padding:10px 12px;
      border-radius:12px;
      border:1px solid rgba(255,255,255,.08);
      background: rgba(0,0,0,.15);
    }
    .top-actions a:hover{ border-color: rgba(0,191,165,.35); }

    .wrap{
      min-height: calc(100vh - 64px);
      display:flex;
      align-items:center;
      justify-content:center;
      padding: 20px 14px;
    }

    .card{
      width:100%;
      max-width: 560px;
      border-radius: var(--radius);
      background: rgba(17,27,33,.90);
      border: 1px solid rgba(255,255,255,.08);
      box-shadow: var(--shadow);
      padding: 22px;
    }

    .card h1{
      margin: 0 0 6px;
      font-size: 22px;
      letter-spacing:.2px;
    }
    .card p{
      margin: 0 0 18px;
      color: var(--muted);
      line-height: 1.5;
      font-size: 14px;
    }

    .alert{
      border-radius: 14px;
      padding: 12px 14px;
      margin-bottom: 14px;
      border: 1px solid rgba(255,255,255,.08);
      background: rgba(0,0,0,.18);
      color: var(--text);
      font-size: 14px;
    }
    .alert-danger{
      border-color: rgba(255,92,92,.25);
      background: var(--dangerBg);
    }
    .alert-danger div{ color: #ffd2d2; }

    .grid{
      display:grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
    }
    @media (max-width: 560px){
      .grid{ grid-template-columns: 1fr; }
    }

    .field label{
      display:block;
      font-size: 13px;
      color: var(--muted);
      margin: 6px 0 8px;
    }
    .control, textarea{
      width:100%;
      padding: 12px 14px;
      border-radius: 14px;
      border: 1px solid rgba(255,255,255,.10);
      background: rgba(0,0,0,.18);
      color: var(--text);
      outline:none;
      font-size: 14px;
      transition: border-color .15s ease, box-shadow .15s ease;
    }
    textarea{
      min-height: 110px;
      resize: vertical;
    }
    .control:focus, textarea:focus{
      border-color: rgba(0,191,165,.45);
      box-shadow: 0 0 0 4px rgba(0,191,165,.12);
    }

    .hint{
      margin-top: 6px;
      font-size: 12px;
      color: var(--muted);
    }

    .avatar-row{
      display:flex;
      gap: 14px;
      align-items:center;
      padding: 12px;
      border-radius: 14px;
      border: 1px solid rgba(255,255,255,.08);
      background: rgba(0,0,0,.14);
      margin: 14px 0;
    }
    .avatar{
      width: 62px;
      height: 62px;
      border-radius: 50%;
      overflow:hidden;
      background: rgba(255,255,255,.08);
      display:grid;
      place-items:center;
      font-weight:800;
      color:#dfe7ea;
      flex: 0 0 auto;
    }
    .avatar img{ width:100%; height:100%; object-fit:cover; display:block; }

    .btn{
      width:100%;
      padding: 12px 14px;
      border: 0;
      border-radius: 14px;
      background: linear-gradient(135deg, var(--accent2), var(--accent));
      color: #062b23;
      font-weight: 800;
      cursor:pointer;
      font-size: 14px;
      letter-spacing:.2px;
    }
    .btn:hover{ filter: brightness(1.02); }
  </style>
</head>

<body>
  <div class="topbar">
    <div class="brand">
      <div class="brand-badge">M</div>
      <div>Messenger</div>
    </div>

    <div class="top-actions">
      <a href="logout.php">Logout</a>
    </div>
  </div>

  <div class="wrap">
    <div class="card">
      <h1><?php echo $isEdit ? 'Edit your profile' : 'Complete your profile'; ?></h1>
      <p><?php echo $isEdit ? 'Update your profile details anytime.' : 'This is required one time. After saving, next login will go directly to home.'; ?></p>

      <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
          <?php foreach ($errors as $e): ?>
            <div><?php echo esc($e); ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="post" enctype="multipart/form-data" autocomplete="off">
        <div class="grid">
          <div class="field">
            <label>Profile name</label>
            <input class="control" type="text" name="profile_name"
                   value="<?php echo esc($_POST['profile_name'] ?? $user['profile_name']); ?>" required>
          </div>

          <div class="field">
            <label>Username</label>
            <input class="control" type="text" name="username"
                   value="<?php echo esc($_POST['username'] ?? $user['username']); ?>" required>
            <div class="hint">Allowed: letters, numbers, dot, underscore. Min 3 chars.</div>
          </div>
        </div>

        <div class="field">
          <label>About</label>
          <textarea name="about" placeholder="Write something..."><?php echo esc($_POST['about'] ?? $user['about']); ?></textarea>
        </div>

        <div class="avatar-row">
          <div class="avatar">
            <?php if (!empty($user['avatar'])): ?>
              <img src="<?php echo esc($user['avatar']); ?>" alt="Avatar">
            <?php else: ?>
              <?php echo esc($initial); ?>
            <?php endif; ?>
          </div>

          <div class="field" style="margin:0; flex:1;">
            <label>Avatar (optional)</label>
            <input class="control" type="file" name="avatar" accept="image/*">
            <div class="hint">JPG/PNG/WEBP, max 2MB.</div>
          </div>
        </div>

        <button class="btn" type="submit"><?php echo $isEdit ? 'Save changes' : 'Save profile'; ?></button>
      </form>
    </div>
  </div>
</body>
</html>
