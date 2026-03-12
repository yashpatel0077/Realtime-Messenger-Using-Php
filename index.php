<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

session_start();
require 'config.php';

if (empty($_SESSION['user_id'])) {
  header("Location: login.php");
  exit;
}
$user_id = (int) $_SESSION['user_id'];

function esc($v)
{
  return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}
function json_out($arr, int $code = 200)
{
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($arr);
  exit;
}
function hasColumn(mysqli $conn, string $table, string $col): bool
{
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1";
  $st = $conn->prepare($sql);
  if (!$st)
    return false;
  $st->bind_param("ss", $table, $col);
  $st->execute();
  $res = $st->get_result();
  $ok = ($res && $res->num_rows > 0);
  $st->close();
  return $ok;
}

// Check for attachment columns and add them if missing
function ensure_attachment_columns($conn) {
    $tables = ['messages', 'group_messages'];
    
    foreach ($tables as $table) {
        if (!hasColumn($conn, $table, 'attachment_path')) {
            $sql = "ALTER TABLE $table ADD COLUMN attachment_path VARCHAR(500) NULL AFTER message";
            $conn->query($sql);
        }
        if (!hasColumn($conn, $table, 'attachment_name')) {
            $sql = "ALTER TABLE $table ADD COLUMN attachment_name VARCHAR(255) NULL AFTER attachment_path";
            $conn->query($sql);
        }
        if (!hasColumn($conn, $table, 'attachment_size')) {
            $sql = "ALTER TABLE $table ADD COLUMN attachment_size INT NULL AFTER attachment_name";
            $conn->query($sql);
        }
        if (!hasColumn($conn, $table, 'attachment_type')) {
            $sql = "ALTER TABLE $table ADD COLUMN attachment_type VARCHAR(100) NULL AFTER attachment_size";
            $conn->query($sql);
        }
    }
}

// Run the column check
ensure_attachment_columns($conn);

$users_has_username = hasColumn($conn, 'users', 'username');
$users_has_email = hasColumn($conn, 'users', 'email');
$users_has_phone = hasColumn($conn, 'users', 'phone');
$users_has_completed = hasColumn($conn, 'users', 'profile_completed');
$users_has_profile_name = hasColumn($conn, 'users', 'profile_name');

$messages_has_is_read = hasColumn($conn, 'messages', 'is_read');
$message_col = hasColumn($conn, 'messages', 'message') ? 'message' : (hasColumn($conn, 'messages', 'text') ? 'text' : 'message');

function avatar_fallback($name)
{
  $n = trim($name ?: 'User');
  return "https://ui-avatars.com/api/?name=" . urlencode($n) . "&size=128&background=E3E5EA&color=111b21";
}
function user_display_name(array $u): string
{
  if (!empty($u['profile_name']))
    return (string) $u['profile_name'];
  if (!empty($u['display_name']))
    return (string) $u['display_name'];
  if (!empty($u['username']))
    return (string) $u['username'];
  if (!empty($u['email']))
    return (strtok((string) $u['email'], '@') ?: 'User');
  if (!empty($u['phone']))
    return (string) $u['phone'];
  return 'User';
}

function display_attachment($msg) {
    if (empty($msg['attachment_path'])) {
        return '';
    }
    
    $file_ext = strtolower(pathinfo($msg['attachment_name'] ?? '', PATHINFO_EXTENSION));
    
    // Choose icon based on file type
    $icons = [
        'jpg' => 'fa-file-image', 'jpeg' => 'fa-file-image', 'png' => 'fa-file-image', 
        'gif' => 'fa-file-image', 'webp' => 'fa-file-image',
        'pdf' => 'fa-file-pdf',
        'mp3' => 'fa-file-audio', 'wav' => 'fa-file-audio', 'ogg' => 'fa-file-audio', 'm4a' => 'fa-file-audio',
        'txt' => 'fa-file-lines', 'html' => 'fa-file-code', 'htm' => 'fa-file-code',
        'css' => 'fa-file-code', 'js' => 'fa-file-code', 'json' => 'fa-file-code',
        'xml' => 'fa-file-code', 'md' => 'fa-file-lines'
    ];
    $icon = $icons[$file_ext] ?? 'fa-file';
    
    $filename = esc($msg['attachment_name'] ?? 'file');
    $filesize = isset($msg['attachment_size']) ? round($msg['attachment_size'] / 1024) . ' KB' : '';
    $fileid = (int) $msg['id'];
    $type = isset($msg['group_id']) ? 'group' : 'dm';
    
    return '
    <div style="margin-top:8px;padding:8px 12px;background:#f0f2f5;border-radius:12px;display:flex;align-items:center;gap:12px;">
        <i class="fas ' . $icon . '" style="font-size:28px;color:#667781;"></i>
        <div style="min-width:0;flex:1;">
            <div style="font-weight:600;font-size:14px;color:#111b21;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' . $filename . '</div>
            <div style="font-size:12px;color:#667781;margin-top:2px;">' . $filesize . '</div>
        </div>
        <a href="download.php?id=' . $fileid . '&type=' . $type . '&download=1" class="chat-action-btn" style="text-decoration:none;color:#25d366;" title="Download">
            <i class="fas fa-download" style="font-size:18px;"></i>
        </a>
    </div>';
}

/* ---------- Selected / Filter ---------- */
$filter = (string) ($_GET['filter'] ?? 'all'); // all | unread | groups
$selected_user_id = isset($_GET['chat']) ? (int) $_GET['chat'] : 0;
$selected_group_id = isset($_GET['group']) ? (int) $_GET['group'] : 0;
if ($selected_user_id > 0)
  $selected_group_id = 0;
if ($selected_group_id > 0)
  $selected_user_id = 0;

/* ---------- CSS cache bust ---------- */
$cssFile = __DIR__ . '/style.css';
$cssVer = file_exists($cssFile) ? (string) filemtime($cssFile) : (string) time();

/* ---------- AJAX: Get user profile (for center popup) ---------- */
if (isset($_GET['action']) && $_GET['action'] === 'get_user_profile') {
  $pid = (int) ($_GET['id'] ?? 0);
  if ($pid <= 0)
    json_out(['ok' => false, 'error' => 'Invalid user'], 400);

  $sql = "SELECT id, display_name,"
    . ($users_has_profile_name ? " profile_name," : " NULL AS profile_name,")
    . ($users_has_username ? " username," : " NULL AS username,")
    . ($users_has_email ? " email," : " NULL AS email,")
    . ($users_has_phone ? " phone," : " NULL AS phone,")
    . " avatar
    FROM users
    WHERE id=?
    LIMIT 1";
  $st = $conn->prepare($sql);
  if (!$st)
    json_out(['ok' => false, 'error' => 'DB prepare failed'], 500);
  $st->bind_param("i", $pid);
  $st->execute();
  $row = $st->get_result()->fetch_assoc();
  $st->close();
  if (!$row)
    json_out(['ok' => false, 'error' => 'User not found'], 404);

  $name = user_display_name($row);
  $avatar = !empty($row['avatar']) ? (string) $row['avatar'] : avatar_fallback($name);

  json_out([
    'ok' => true,
    'user' => [
      'id' => (int) $row['id'],
      'name' => $name,
      'display_name' => (string) ($row['display_name'] ?? ''),
      'profile_name' => (string) ($row['profile_name'] ?? ''),
      'username' => (string) ($row['username'] ?? ''),
      'email' => (string) ($row['email'] ?? ''),
      'phone' => (string) ($row['phone'] ?? ''),
      'avatar' => $avatar
    ]
  ]);
}

/* ---------- AJAX: Get group members (for center popup) ---------- */
if (isset($_GET['action']) && $_GET['action'] === 'get_group_members') {
  $group_id = (int) ($_GET['group_id'] ?? 0);
  if ($group_id <= 0)
    json_out(['ok' => false, 'error' => 'Invalid group'], 400);

  // must be member
  $chk = $conn->prepare("SELECT 1 FROM group_members WHERE group_id=? AND user_id=? LIMIT 1");
  if (!$chk)
    json_out(['ok' => false, 'error' => 'group_members table missing'], 500);
  $chk->bind_param("ii", $group_id, $user_id);
  $chk->execute();
  $is_member = (bool) $chk->get_result()->fetch_assoc();
  $chk->close();
  if (!$is_member)
    json_out(['ok' => false, 'error' => 'Not a member'], 403);

  $sql = "
    SELECT u.id, u.display_name,
      " . ($users_has_profile_name ? "u.profile_name," : "NULL AS profile_name,") . "
      " . ($users_has_username ? "u.username," : "NULL AS username,") . "
      " . ($users_has_email ? "u.email," : "NULL AS email,") . "
      " . ($users_has_phone ? "u.phone," : "NULL AS phone,") . "
      u.avatar,
      gm.role
    FROM group_members gm
    JOIN users u ON u.id = gm.user_id
    WHERE gm.group_id=?
    ORDER BY (gm.role='admin') DESC, u.display_name ASC, u.id ASC
  ";
  $st = $conn->prepare($sql);
  if (!$st)
    json_out(['ok' => false, 'error' => 'DB prepare failed'], 500);
  $st->bind_param("i", $group_id);
  $st->execute();
  $res = $st->get_result();
  $members = [];
  while ($r = $res->fetch_assoc()) {
    $name = user_display_name($r);
    $members[] = [
      'id' => (int) $r['id'],
      'name' => $name,
      'email' => (string) ($r['email'] ?? ''),
      'phone' => (string) ($r['phone'] ?? ''),
      'username' => (string) ($r['username'] ?? ''),
      'role' => (string) ($r['role'] ?? 'member'),
      'avatar' => !empty($r['avatar']) ? (string) $r['avatar'] : avatar_fallback($name)
    ];
  }
  $st->close();
  json_out(['ok' => true, 'members' => $members]);
}

/* ---------- AJAX: Find user ---------- */
if (isset($_GET['action']) && $_GET['action'] === 'find_user') {
  $q = trim((string) ($_GET['q'] ?? ''));
  header('Content-Type: application/json; charset=utf-8');
  if (mb_strlen($q) < 2) {
    echo json_encode(['ok' => true, 'users' => []]);
    exit;
  }
  $like = '%' . $q . '%';

  $where = [];
  $types = "i";
  $params = [$user_id];
  if ($users_has_profile_name) {
    $where[] = "profile_name LIKE ?";
    $types .= "s";
    $params[] = $like;
  }
  $where[] = "display_name LIKE ?";
  $types .= "s";
  $params[] = $like;
  if ($users_has_username) {
    $where[] = "username LIKE ?";
    $types .= "s";
    $params[] = $like;
  }
  if ($users_has_email) {
    $where[] = "email LIKE ?";
    $types .= "s";
    $params[] = $like;
  }
  if ($users_has_phone) {
    $where[] = "phone LIKE ?";
    $types .= "s";
    $params[] = $like;
  }

  $sql = "SELECT id,"
    . ($users_has_username ? " username," : " NULL AS username,")
    . ($users_has_profile_name ? " profile_name," : " NULL AS profile_name,")
    . " display_name, avatar,"
    . ($users_has_email ? " email," : " NULL AS email,")
    . ($users_has_phone ? " phone" : " NULL AS phone")
    . " FROM users WHERE id!=? AND (" . implode(" OR ", $where) . ") ORDER BY id DESC LIMIT 10";

  $st = $conn->prepare($sql);
  if (!$st)
    json_out(['ok' => false, 'error' => 'DB prepare failed'], 500);
  $bind = [];
  $bind[] = $types;
  foreach ($params as $k => $v)
    $bind[] =& $params[$k];
  call_user_func_array([$st, 'bind_param'], $bind);

  $st->execute();
  $res = $st->get_result();
  $out = [];
  while ($row = $res->fetch_assoc()) {
    $name = user_display_name($row);
    $out[] = ['id' => (int) $row['id'], 'name' => $name, 'email' => (string) ($row['email'] ?? ''), 'avatar' => (string) ($row['avatar'] ?? '')];
  }
  $st->close();
  echo json_encode(['ok' => true, 'users' => $out]);
  exit;
}

/* ---------- AJAX: group user search ---------- */
if (isset($_GET['action']) && $_GET['action'] === 'group_user_search') {
  $q = trim((string) ($_GET['q'] ?? ''));
  if ($q === '')
    json_out(['ok' => true, 'users' => []]);

  $like = '%' . $q . '%';
  $where = ["display_name LIKE ?"];
  $types = "is";
  $params = [$user_id, $like];

  if ($users_has_profile_name) {
    $where[] = "profile_name LIKE ?";
    $types .= "s";
    $params[] = $like;
  }
  if ($users_has_username) {
    $where[] = "username LIKE ?";
    $types .= "s";
    $params[] = $like;
  }
  if ($users_has_email) {
    $where[] = "email LIKE ?";
    $types .= "s";
    $params[] = $like;
  }

  $sql = "SELECT id, display_name,"
    . ($users_has_profile_name ? " profile_name," : " NULL AS profile_name,")
    . ($users_has_email ? " email," : " NULL AS email,")
    . " avatar
      FROM users
      WHERE id != ?
        AND (" . implode(" OR ", $where) . ")
      ORDER BY id DESC
      LIMIT 30";

  $st = $conn->prepare($sql);
  $bind = [];
  $bind[] = $types;
  foreach ($params as $k => $v)
    $bind[] =& $params[$k];
  call_user_func_array([$st, 'bind_param'], $bind);
  $st->execute();
  $res = $st->get_result();

  $out = [];
  while ($row = $res->fetch_assoc()) {
    $name = user_display_name($row);
    $out[] = ['id' => (int) $row['id'], 'name' => $name, 'email' => (string) ($row['email'] ?? ''), 'avatar' => (string) ($row['avatar'] ?? '')];
  }
  $st->close();
  json_out(['ok' => true, 'users' => $out]);
}

/* ---------- AJAX: user chat settings ---------- */
if (isset($_POST['action']) && in_array($_POST['action'], ['pin_toggle', 'hide_chat', 'unhide_chat'], true)) {
  $action = (string) $_POST['action'];
  $other_id = (int) ($_POST['other_id'] ?? 0);
  if ($other_id <= 0 || $other_id === $user_id)
    json_out(['ok' => false, 'error' => 'Invalid user'], 400);

  $ins = $conn->prepare("INSERT INTO chat_settings (user_id, other_id, is_pinned, is_hidden)
                       VALUES (?, ?, 0, 0)
                       ON DUPLICATE KEY UPDATE user_id=user_id");
  if (!$ins)
    json_out(['ok' => false, 'error' => 'chat_settings table missing'], 500);
  $ins->bind_param("ii", $user_id, $other_id);
  $ins->execute();
  $ins->close();

  if ($action === 'pin_toggle') {
    $up = $conn->prepare("UPDATE chat_settings SET is_pinned=1-is_pinned WHERE user_id=? AND other_id=?");
    $up->bind_param("ii", $user_id, $other_id);
    $up->execute();
    $up->close();
    json_out(['ok' => true]);
  }
  if ($action === 'hide_chat') {
    $up = $conn->prepare("UPDATE chat_settings SET is_hidden=1 WHERE user_id=? AND other_id=?");
    $up->bind_param("ii", $user_id, $other_id);
    $up->execute();
    $up->close();
    json_out(['ok' => true]);
  }
  if ($action === 'unhide_chat') {
    $up = $conn->prepare("UPDATE chat_settings SET is_hidden=0 WHERE user_id=? AND other_id=?");
    $up->bind_param("ii", $user_id, $other_id);
    $up->execute();
    $up->close();
    json_out(['ok' => true]);
  }
}

/* ---------- AJAX: group chat settings ---------- */
if (isset($_POST['action']) && in_array($_POST['action'], ['group_pin_toggle', 'group_hide', 'group_unhide'], true)) {
  $action = (string) $_POST['action'];
  $group_id = (int) ($_POST['group_id'] ?? 0);
  if ($group_id <= 0)
    json_out(['ok' => false, 'error' => 'Invalid group'], 400);

  $ins = $conn->prepare("INSERT INTO group_chat_settings (user_id, group_id, is_pinned, is_hidden)
                       VALUES (?, ?, 0, 0)
                       ON DUPLICATE KEY UPDATE user_id=user_id");
  if (!$ins)
    json_out(['ok' => false, 'error' => 'group_chat_settings table missing'], 500);
  $ins->bind_param("ii", $user_id, $group_id);
  $ins->execute();
  $ins->close();

  if ($action === 'group_pin_toggle') {
    $up = $conn->prepare("UPDATE group_chat_settings SET is_pinned=1-is_pinned WHERE user_id=? AND group_id=?");
    $up->bind_param("ii", $user_id, $group_id);
    $up->execute();
    $up->close();
    json_out(['ok' => true]);
  }
  if ($action === 'group_hide') {
    $up = $conn->prepare("UPDATE group_chat_settings SET is_hidden=1 WHERE user_id=? AND group_id=?");
    $up->bind_param("ii", $user_id, $group_id);
    $up->execute();
    $up->close();
    json_out(['ok' => true]);
  }
  if ($action === 'group_unhide') {
    $up = $conn->prepare("UPDATE group_chat_settings SET is_hidden=0 WHERE user_id=? AND group_id=?");
    $up->bind_param("ii", $user_id, $group_id);
    $up->execute();
    $up->close();
    json_out(['ok' => true]);
  }
}

/* ---------- AJAX: Create group ---------- */
if (isset($_POST['action']) && $_POST['action'] === 'create_group') {
  $group_name = trim((string) ($_POST['group_name'] ?? ''));
  $members = $_POST['members'] ?? [];

  if (mb_strlen($group_name) < 2)
    json_out(['ok' => false, 'error' => 'Enter group name'], 400);
  if (!is_array($members))
    $members = [];
  $members = array_values(array_unique(array_filter(array_map('intval', $members), fn($v) => $v > 0 && $v !== $user_id)));
  if (count($members) < 1)
    json_out(['ok' => false, 'error' => 'Select users to add to the group.'], 400);

  $st = $conn->prepare("INSERT INTO groups (name, created_by) VALUES (?, ?)");
  if (!$st)
    json_out(['ok' => false, 'error' => 'DB prepare failed (groups)'], 500);
  $st->bind_param("si", $group_name, $user_id);
  $ok = $st->execute();
  $gid = (int) $conn->insert_id;
  $st->close();
  if (!$ok || $gid <= 0)
    json_out(['ok' => false, 'error' => 'Failed to create group'], 500);

  $st = $conn->prepare("INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, 'admin')");
  $st->bind_param("ii", $gid, $user_id);
  $st->execute();
  $st->close();

  $st = $conn->prepare("INSERT IGNORE INTO group_members (group_id, user_id, role) VALUES (?, ?, 'member')");
  foreach ($members as $mid) {
    $st->bind_param("ii", $gid, $mid);
    $st->execute();
  }
  $st->close();

  json_out(['ok' => true, 'group_id' => $gid]);
}

/* ---------- AJAX: Send group message ---------- */
if (isset($_POST['action']) && $_POST['action'] === 'send_group_message') {
  $gid = (int) ($_POST['group_id'] ?? 0);
  $msg = trim((string) ($_POST['message'] ?? ''));
  if ($gid <= 0 || $msg === '')
    json_out(['ok' => false, 'error' => 'Invalid'], 400);

  $chk = $conn->prepare("SELECT 1 FROM group_members WHERE group_id=? AND user_id=? LIMIT 1");
  $chk->bind_param("ii", $gid, $user_id);
  $chk->execute();
  $okMember = (bool) $chk->get_result()->fetch_assoc();
  $chk->close();
  if (!$okMember)
    json_out(['ok' => false, 'error' => 'Not a member'], 403);

  $st = $conn->prepare("INSERT INTO group_messages (group_id, sender_id, message) VALUES (?, ?, ?)");
  $st->bind_param("iis", $gid, $user_id, $msg);
  $st->execute();
  $st->close();
  json_out(['ok' => true]);
}

/* ---------- Current user ---------- */
$selectCompleted = $users_has_completed ? "profile_completed" : "0 AS profile_completed";
$st = $conn->prepare("SELECT display_name, profile_name, avatar, email, $selectCompleted FROM users WHERE id=? LIMIT 1");
$st->bind_param("i", $user_id);
$st->execute();
$current_user = $st->get_result()->fetch_assoc();
$st->close();
if (!$current_user) {
  session_destroy();
  header("Location: login.php");
  exit;
}
if ((int) ($current_user['profile_completed'] ?? 0) !== 1) {
  header("Location: set_profile.php");
  exit;
}
$myName = user_display_name($current_user);
if (empty($current_user['avatar']))
  $current_user['avatar'] = avatar_fallback($myName);

/* ---------- Selected user chat header ---------- */
$chat_user = null;
if ($selected_user_id > 0) {
  $sql = "SELECT id, display_name, profile_name, avatar, email"
    . ($users_has_username ? ", username" : ", NULL AS username")
    . ($users_has_phone ? ", phone" : ", NULL AS phone")
    . " FROM users WHERE id=? LIMIT 1";
  $st = $conn->prepare($sql);
  $st->bind_param("i", $selected_user_id);
  $st->execute();
  $chat_user = $st->get_result()->fetch_assoc();
  $st->close();
  if (!$chat_user)
    $selected_user_id = 0;
  else {
    $chat_user['name'] = user_display_name($chat_user);
    if (empty($chat_user['avatar']))
      $chat_user['avatar'] = avatar_fallback($chat_user['name']);
  }
}

/* ---------- Selected group header ---------- */
$chat_group = null;
if ($selected_group_id > 0) {
  $st = $conn->prepare("
    SELECT g.id, g.name
    FROM groups g
    JOIN group_members gm ON gm.group_id=g.id
    WHERE g.id=? AND gm.user_id=?
    LIMIT 1
  ");
  $st->bind_param("ii", $selected_group_id, $user_id);
  $st->execute();
  $chat_group = $st->get_result()->fetch_assoc();
  $st->close();
  if (!$chat_group)
    $selected_group_id = 0;
}

/* ---------- Unread counts ---------- */
$unreadCount = 0;
if ($messages_has_is_read) {
  $st = $conn->prepare("SELECT COUNT(*) AS c FROM messages WHERE receiver_id=? AND is_read=0");
  $st->bind_param("i", $user_id);
  $st->execute();
  $unreadCount = (int) (($st->get_result()->fetch_assoc()['c'] ?? 0));
  $st->close();
}

/* ---------- Contacts (users) list ---------- */
$contacts = null;
if ($filter !== 'groups') {
  $sql = "
    SELECT
      u.id, u.display_name,
      " . ($users_has_profile_name ? "u.profile_name," : "NULL AS profile_name,") . "
      u.avatar, u.email
      " . ($users_has_username ? ", u.username" : ", NULL AS username") . ",
      m.$message_col AS last_message,
      m.created_at AS last_time,
      m.sender_id AS last_sender,
      COALESCE(cs.is_pinned,0) AS is_pinned,
      COALESCE(uc.unread_count,0) AS unread_count
    FROM users u
    JOIN (
      SELECT CASE WHEN sender_id=? THEN receiver_id ELSE sender_id END AS other_id, MAX(id) AS last_msg_id
      FROM messages
      WHERE sender_id=? OR receiver_id=?
      GROUP BY other_id
    ) t ON t.other_id = u.id
    JOIN messages m ON m.id = t.last_msg_id
    LEFT JOIN chat_settings cs ON cs.user_id=? AND cs.other_id=u.id
    LEFT JOIN (
      SELECT sender_id AS other_id, COUNT(*) AS unread_count
      FROM messages
      WHERE receiver_id=? AND is_read=0
      GROUP BY sender_id
    ) uc ON uc.other_id=u.id
    WHERE u.id!=?
      AND COALESCE(cs.is_hidden,0)=0
  ";
  if ($filter === 'unread') {
    $sql .= " AND COALESCE(uc.unread_count,0) > 0 ";
  }
  $sql .= " ORDER BY COALESCE(cs.is_pinned,0) DESC, m.created_at DESC LIMIT 50";

  $st = $conn->prepare($sql);
  $st->bind_param("iiiiii", $user_id, $user_id, $user_id, $user_id, $user_id, $user_id);
  $st->execute();
  $contacts = $st->get_result();
  $st->close();
}

/* ---------- Groups list (visible) ---------- */
$groups_list = null;
$st = $conn->prepare("
  SELECT g.id, g.name,
         MAX(gm2.created_at) AS last_time,
         COALESCE(gcs.is_pinned,0) AS is_pinned
  FROM groups g
  JOIN group_members gm ON gm.group_id=g.id AND gm.user_id=?
  LEFT JOIN group_messages gm2 ON gm2.group_id=g.id
  LEFT JOIN group_chat_settings gcs ON gcs.user_id=? AND gcs.group_id=g.id
  WHERE COALESCE(gcs.is_hidden,0)=0
  GROUP BY g.id
  ORDER BY COALESCE(gcs.is_pinned,0) DESC, last_time DESC, g.id DESC
  LIMIT 50
");
$st->bind_param("ii", $user_id, $user_id);
$st->execute();
$groups_list = $st->get_result();
$st->close();

/* ---------- Build ALL list (users + groups) ---------- */
$all_items = [];
if ($filter === 'all') {
  if ($groups_list) {
    while ($g = $groups_list->fetch_assoc()) {
      $all_items[] = [
        'type' => 'group',
        'id' => (int) $g['id'],
        'name' => (string) $g['name'],
        'avatar' => avatar_fallback((string) $g['name']),
        'last_time' => $g['last_time'] ?? null,
        'is_pinned' => (int) ($g['is_pinned'] ?? 0),
        'unread_count' => 0
      ];
    }
    $groups_list->data_seek(0);
  }
  if ($contacts) {
    while ($c = $contacts->fetch_assoc()) {
      $name = user_display_name($c);
      $all_items[] = [
        'type' => 'user',
        'id' => (int) $c['id'],
        'name' => $name,
        'avatar' => !empty($c['avatar']) ? $c['avatar'] : avatar_fallback($name),
        'last_time' => $c['last_time'] ?? null,
        'is_pinned' => (int) ($c['is_pinned'] ?? 0),
        'unread_count' => (int) ($c['unread_count'] ?? 0),
        'last_sender' => (int) ($c['last_sender'] ?? 0),
        'last_message' => (string) ($c['last_message'] ?? '')
      ];
    }
    $contacts->data_seek(0);
  }

  usort($all_items, function ($a, $b) {
    if (($a['is_pinned'] ?? 0) !== ($b['is_pinned'] ?? 0))
      return ($b['is_pinned'] ?? 0) <=> ($a['is_pinned'] ?? 0);
    $ta = $a['last_time'] ? strtotime($a['last_time']) : 0;
    $tb = $b['last_time'] ? strtotime($b['last_time']) : 0;
    if ($ta !== $tb)
      return $tb <=> $ta;
    return ($b['id'] ?? 0) <=> ($a['id'] ?? 0);
  });
}

/* ---------- Hidden lists ---------- */
$hidden_users = null;
$st = $conn->prepare("
  SELECT u.id, u.display_name,
    " . ($users_has_profile_name ? "u.profile_name," : "NULL AS profile_name,") . "
    u.avatar, u.email
    " . ($users_has_username ? ", u.username" : ", NULL AS username") . "
  FROM chat_settings cs
  JOIN users u ON u.id=cs.other_id
  WHERE cs.user_id=? AND cs.is_hidden=1
  ORDER BY cs.updated_at DESC
  LIMIT 200
");
$st->bind_param("i", $user_id);
$st->execute();
$hidden_users = $st->get_result();
$st->close();

$hidden_groups = null;
$st = $conn->prepare("
  SELECT g.id, g.name
  FROM group_chat_settings gcs
  JOIN groups g ON g.id=gcs.group_id
  JOIN group_members gm ON gm.group_id=g.id AND gm.user_id=?
  WHERE gcs.user_id=? AND gcs.is_hidden=1
  ORDER BY gcs.updated_at DESC
  LIMIT 200
");
$st->bind_param("ii", $user_id, $user_id);
$st->execute();
$hidden_groups = $st->get_result();
$st->close();

/* ---------- Messages area data ---------- */
$user_messages = null;
$group_messages = null;

if ($selected_user_id > 0) {
  if ($messages_has_is_read) {
    $up = $conn->prepare("UPDATE messages SET is_read=1 WHERE sender_id=? AND receiver_id=? AND is_read=0");
    $up->bind_param("ii", $selected_user_id, $user_id);
    $up->execute();
    $up->close();
  }

  $st = $conn->prepare("
    SELECT id, sender_id, receiver_id, $message_col AS message, 
           attachment_path, attachment_name, attachment_size, attachment_type, created_at
    FROM messages
    WHERE (sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?)
    ORDER BY id ASC LIMIT 300
  ");
  $st->bind_param("iiii", $user_id, $selected_user_id, $selected_user_id, $user_id);
  $st->execute();
  $user_messages = $st->get_result();
  $st->close();
}

if ($selected_group_id > 0) {
  $st = $conn->prepare("
    SELECT gm.id, gm.sender_id, u.display_name, u.profile_name, u.avatar, 
           gm.message, gm.attachment_path, gm.attachment_name, gm.attachment_size, gm.attachment_type, gm.created_at
    FROM group_messages gm
    JOIN users u ON u.id = gm.sender_id
    WHERE gm.group_id = ?
    ORDER BY gm.id ASC
    LIMIT 300
  ");
  $st->bind_param("i", $selected_group_id);
  $st->execute();
  $group_messages = $st->get_result();
  $st->close();
}

$bodyClass = "app-body";
if ($selected_user_id > 0 || $selected_group_id > 0)
  $bodyClass .= " is-chat-open";
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <title>Messenger</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="style.css?v=<?php echo esc($cssVer); ?>" type="text/css">
  <style>
    /* Additional styles for attachments */
    .attachment-container {
      margin-top: 8px;
    }
    .attachment-preview {
      background: #f0f2f5;
      border-radius: 12px;
      padding: 8px;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .chat-action-btn {
      width: 32px;
      height: 32px;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #667781;
      background: transparent;
      border: none;
      cursor: pointer;
    }
    .chat-action-btn:hover {
      background: rgba(0,0,0,0.05);
      color: #111b21;
    }
    .image-preview {
      cursor: pointer;
      transition: opacity 0.2s;
    }
    .image-preview:hover {
      opacity: 0.9;
    }
  </style>
</head>

<body class="<?php echo esc($bodyClass); ?>">

  <div class="app">
    <aside class="mini-sidebar">
      <div class="mini-top">
        <div class="mini-item mini-item-primary" title="Unread">
          <i class="fas fa-inbox"></i>
          <?php if ($unreadCount > 0): ?><span class="mini-badge">
              <?php echo (int) $unreadCount; ?>
            </span>
          <?php endif; ?>
        </div>
        <div class="mini-item" title="Create group" onclick="openCreateGroup()"><i class="fas fa-users"></i></div>
      </div>
      <div class="mini-bottom">
        <div class="mini-item mini-small" title="Settings" onclick="location.href='settings.php'"><i
            class="fas fa-cog"></i></div>
        <div class="mini-item mini-small" title="Profile" onclick="location.href='set_profile.php?edit=1'"><i
            class="fas fa-user"></i></div>
      </div>
    </aside>

    <section class="chat-sidebar" id="chatSidebar">
      <div class="chat-sidebar-header">
        <div class="chat-sidebar-title">Chats</div>
        <div class="chat-sidebar-actions">
          <button type="button" class="icon-btn" title="Add user" onclick="openFindUserPopup()"><i
              class="fas fa-user-plus"></i></button>
          <button type="button" class="icon-btn" title="More" onclick="toggleChatsMenu(event)"><i
              class="fas fa-ellipsis-v"></i></button>

          <div class="dropdown" id="chatsMenu" style="display:none;">
            <button type="button" class="dropdown-item" onclick="openFindUserPopup(); closeChatsMenu();"><i
                class="fas fa-user-plus"></i> Add user</button>
            <button type="button" class="dropdown-item" onclick="openCreateGroup(); closeChatsMenu();"><i
                class="fas fa-users"></i> Create group</button>
            <button type="button" class="dropdown-item" onclick="openHiddenChats(); closeChatsMenu();"><i
                class="fas fa-eye"></i> Unhide</button>
            <button type="button" class="dropdown-item" onclick="location.href='set_profile.php?edit=1'"><i
                class="fas fa-user"></i> Profile</button>
            <button type="button" class="dropdown-item" onclick="location.href='logout.php'"><i
                class="fas fa-right-from-bracket"></i> Logout</button>
          </div>
        </div>
      </div>

      <div class="chat-sidebar-search">
        <i class="fas fa-search"></i>
        <input type="text" id="searchInput" placeholder="Search" onkeyup="filterChats()">
      </div>

      <div class="chat-sidebar-filters">
        <button type="button" class="filter-pill <?php echo ($filter === 'all' ? 'filter-pill-active' : ''); ?>"
          onclick="setFilter('all')">All</button>
        <button type="button" class="filter-pill <?php echo ($filter === 'unread' ? 'filter-pill-active' : ''); ?>"
          onclick="setFilter('unread')">Unread</button>
        <button type="button" class="filter-pill <?php echo ($filter === 'groups' ? 'filter-pill-active' : ''); ?>"
          onclick="setFilter('groups')">Groups</button>
      </div>

      <div class="chat-sidebar-list" id="contactsList">
        <?php if ($filter === 'all'): ?>

          <?php if (count($all_items) > 0): ?>
            <?php foreach ($all_items as $it): ?>
              <?php
              $type = $it['type'];
              $id = (int) $it['id'];
              $name = (string) $it['name'];
              $av = (string) $it['avatar'];
              $time = !empty($it['last_time']) ? date('H:i', strtotime($it['last_time'])) : '';
              $un = (int) ($it['unread_count'] ?? 0);

              $active = '';
              if ($type === 'user' && $id === $selected_user_id)
                $active = 'chat-row-active';
              if ($type === 'group' && $id === $selected_group_id)
                $active = 'chat-row-active';
              ?>
              <div class="chat-row contact-row <?php echo $active; ?>" data-name="<?php echo esc($name); ?>"
                onclick="<?php echo ($type === 'user') ? "openChat($id)" : "openGroup($id)"; ?>">
                <div class="chat-avatar" style="background-image:url('<?php echo esc($av); ?>')"></div>
                <div class="chat-main-info">
                  <div class="chat-name contact-name">
                    <?php echo esc($name); ?>
                  </div>
                  <div class="chat-preview">
                    <?php echo $type === 'group' ? 'Group' : 'Chat'; ?>
                  </div>
                </div>
                <div class="chat-meta">
                  <span class="chat-time">
                    <?php echo esc($time); ?>
                  </span>
                  <?php if ($type === 'user' && $un > 0): ?><span class="unread-badge">
                      <?php echo $un; ?>
                    </span>
                  <?php endif; ?>
                </div>

                <div class="chat-actions" onclick="event.stopPropagation();">
                  <div class="chat-more-wrap">
                    <button class="chat-action-btn" type="button"
                      onclick="toggleItemMore(event,'<?php echo $type; ?>',<?php echo $id; ?>)"><i
                        class="fas fa-ellipsis-v"></i></button>
                    <div class="chat-more-menu" id="itemMore_<?php echo $type; ?>_<?php echo $id; ?>">
                      <?php if ($type === 'user'): ?>
                        <button type="button" class="chat-more-item"
                          onclick="openUserProfile(<?php echo $id; ?>); closeAllItemMore();"><i class="fas fa-user"></i> See
                          profile</button>
                        <button type="button" class="chat-more-item"
                          onclick="pinChat(<?php echo $id; ?>); closeAllItemMore();"><i class="fas fa-thumbtack"></i> Pin /
                          Unpin</button>
                        <button type="button" class="chat-more-item"
                          onclick="hideChat(<?php echo $id; ?>); closeAllItemMore();"><i class="fas fa-eye-slash"></i> Hide
                          chat</button>
                        <button type="button" class="chat-more-item chat-more-danger"
                          onclick="deleteChat(<?php echo $id; ?>); closeAllItemMore();"><i class="fas fa-trash"></i> Delete
                          chat</button>
                      <?php else: ?>
                        <button type="button" class="chat-more-item"
                          onclick="openGroupMembers(<?php echo $id; ?>); closeAllItemMore();"><i class="fas fa-users"></i> View
                          members</button>
                        <button type="button" class="chat-more-item"
                          onclick="pinGroup(<?php echo $id; ?>); closeAllItemMore();"><i class="fas fa-thumbtack"></i> Pin /
                          Unpin</button>
                        <button type="button" class="chat-more-item"
                          onclick="hideGroup(<?php echo $id; ?>); closeAllItemMore();"><i class="fas fa-eye-slash"></i> Hide
                          group</button>
                        <button type="button" class="chat-more-item chat-more-danger"
                          onclick="deleteGroupChat(<?php echo $id; ?>); closeAllItemMore();"><i class="fas fa-trash"></i> Delete
                          group chat</button>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>

              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="chat-row chat-row-demo">
              <div class="chat-main-info">
                <div class="chat-name">No chats</div>
                <div class="chat-preview">Start a conversation or create a group.</div>
              </div>
            </div>
          <?php endif; ?>

        <?php elseif ($filter === 'groups'): ?>

          <?php if ($groups_list && $groups_list->num_rows > 0): ?>
            <?php while ($g = $groups_list->fetch_assoc()): ?>
              <?php $gid = (int) $g['id'];
              $gname = (string) $g['name'];
              $gav = avatar_fallback($gname); ?>
              <div class="chat-row contact-row <?php echo ($gid === $selected_group_id) ? 'chat-row-active' : ''; ?>"
                data-name="<?php echo esc($gname); ?>" onclick="openGroup(<?php echo $gid; ?>)">
                <div class="chat-avatar" style="background-image:url('<?php echo esc($gav); ?>')"></div>
                <div class="chat-main-info">
                  <div class="chat-name contact-name">
                    <?php echo esc($gname); ?>
                  </div>
                  <div class="chat-preview">Group</div>
                </div>
                <div class="chat-meta"><span class="chat-time"></span></div>

                <div class="chat-actions" onclick="event.stopPropagation();">
                  <div class="chat-more-wrap">
                    <button class="chat-action-btn" type="button"
                      onclick="toggleItemMore(event,'group',<?php echo $gid; ?>)"><i class="fas fa-ellipsis-v"></i></button>
                    <div class="chat-more-menu" id="itemMore_group_<?php echo $gid; ?>">
                      <button type="button" class="chat-more-item"
                        onclick="openGroupMembers(<?php echo $gid; ?>); closeAllItemMore();"><i class="fas fa-users"></i> View
                        members</button>
                      <button type="button" class="chat-more-item"
                        onclick="pinGroup(<?php echo $gid; ?>); closeAllItemMore();"><i class="fas fa-thumbtack"></i> Pin /
                        Unpin</button>
                      <button type="button" class="chat-more-item"
                        onclick="hideGroup(<?php echo $gid; ?>); closeAllItemMore();"><i class="fas fa-eye-slash"></i> Hide
                        group</button>
                      <button type="button" class="chat-more-item chat-more-danger"
                        onclick="deleteGroupChat(<?php echo $gid; ?>); closeAllItemMore();"><i class="fas fa-trash"></i>
                        Delete group chat</button>
                    </div>
                  </div>
                </div>

              </div>
            <?php endwhile; ?>
          <?php else: ?>
            <div class="chat-row chat-row-demo">
              <div class="chat-main-info">
                <div class="chat-name">No groups</div>
                <div class="chat-preview">Create your first group.</div>
              </div>
            </div>
          <?php endif; ?>

        <?php else: /* unread */?>

          <?php if ($contacts && $contacts->num_rows > 0): ?>
            <?php while ($c = $contacts->fetch_assoc()): ?>
              <?php
              $cid = (int) $c['id'];
              $name = user_display_name($c);
              $av = !empty($c['avatar']) ? $c['avatar'] : avatar_fallback($name);
              $un = (int) ($c['unread_count'] ?? 0);
              $lastMsg = trim((string) ($c['last_message'] ?? ''));
              $lastMsg = mb_substr($lastMsg, 0, 60) . (mb_strlen($lastMsg) > 60 ? '…' : '');
              $prefix = ((int) ($c['last_sender'] ?? 0) === $user_id) ? 'You: ' : '';
              $time = $c['last_time'] ? date('H:i', strtotime($c['last_time'])) : '';
              ?>
              <div class="chat-row contact-row <?php echo ($cid === $selected_user_id) ? 'chat-row-active' : ''; ?>"
                data-name="<?php echo esc($name); ?>" onclick="openChat(<?php echo $cid; ?>)">
                <div class="chat-avatar" style="background-image:url('<?php echo esc($av); ?>')"></div>
                <div class="chat-main-info">
                  <div class="chat-name contact-name">
                    <?php echo esc($name); ?>
                  </div>
                  <div class="chat-preview">
                    <?php echo esc($prefix . $lastMsg); ?>
                  </div>
                </div>
                <div class="chat-meta">
                  <span class="chat-time">
                    <?php echo esc($time); ?>
                  </span>
                  <?php if ($un > 0): ?><span class="unread-badge">
                      <?php echo $un; ?>
                    </span>
                  <?php endif; ?>
                </div>

                <div class="chat-actions" onclick="event.stopPropagation();">
                  <div class="chat-more-wrap">
                    <button class="chat-action-btn" type="button"
                      onclick="toggleItemMore(event,'user',<?php echo $cid; ?>)"><i class="fas fa-ellipsis-v"></i></button>
                    <div class="chat-more-menu" id="itemMore_user_<?php echo $cid; ?>">
                      <button type="button" class="chat-more-item"
                        onclick="openUserProfile(<?php echo $cid; ?>); closeAllItemMore();"><i class="fas fa-user"></i> See
                        profile</button>
                      <button type="button" class="chat-more-item"
                        onclick="pinChat(<?php echo $cid; ?>); closeAllItemMore();"><i class="fas fa-thumbtack"></i> Pin /
                        Unpin</button>
                      <button type="button" class="chat-more-item"
                        onclick="hideChat(<?php echo $cid; ?>); closeAllItemMore();"><i class="fas fa-eye-slash"></i> Hide
                        chat</button>
                      <button type="button" class="chat-more-item chat-more-danger"
                        onclick="deleteChat(<?php echo $cid; ?>); closeAllItemMore();"><i class="fas fa-trash"></i> Delete
                        chat</button>
                    </div>
                  </div>
                </div>

              </div>
            <?php endwhile; ?>
          <?php else: ?>
            <div class="chat-row chat-row-demo">
              <div class="chat-main-info">
                <div class="chat-name">No chats</div>
                <div class="chat-preview">No unread chats.</div>
              </div>
            </div>
          <?php endif; ?>

        <?php endif; ?>
      </div>
    </section>

    <main class="main-content" id="mainContent">
      <header class="main-header">
        <?php if ($selected_group_id > 0 && $chat_group): ?>
          <button type="button" class="mobile-back" onclick="location.href='index.php'" aria-label="Back"><i
              class="fas fa-arrow-left"></i></button>
          <div class="header-chat-info">
            <div class="header-avatar"
              style="background-image:url('<?php echo esc(avatar_fallback($chat_group['name'])); ?>')"></div>
            <div class="header-text">
              <div class="header-name">
                <?php echo esc($chat_group['name']); ?>
              </div>
              <div class="header-status">group</div>
            </div>
          </div>
        <?php elseif ($selected_user_id > 0 && $chat_user): ?>
          <button type="button" class="mobile-back" onclick="location.href='index.php'" aria-label="Back"><i
              class="fas fa-arrow-left"></i></button>
          <div class="header-chat-info">
            <div class="header-avatar" style="background-image:url('<?php echo esc($chat_user['avatar']); ?>')"></div>
            <div class="header-text">
              <div class="header-name">
                <?php echo esc($chat_user['name']); ?>
              </div>
              <div class="header-status">online</div>
            </div>
          </div>
        <?php else: ?>
          <div class="header-chat-info">
            <div class="header-avatar" style="background-image:url('<?php echo esc($current_user['avatar']); ?>')"></div>
            <div class="header-text">
              <div class="header-name">
                <?php echo esc($myName); ?>
              </div>
              <div class="header-status">Tap a chat to start messaging</div>
            </div>
          </div>
        <?php endif; ?>
      </header>

      <section class="messages-area" id="messagesArea">
        <?php if ($selected_group_id > 0 && $chat_group && $group_messages): ?>
          <?php while ($m = $group_messages->fetch_assoc()):
            $is_me = ((int) $m['sender_id'] === $user_id);
            $senderName = user_display_name($m);
            $senderAv = !empty($m['avatar']) ? $m['avatar'] : avatar_fallback($senderName);
            ?>
            <div class="bubble-row <?php echo $is_me ? 'bubble-row-me' : 'bubble-row-them'; ?>"
              data-id="<?php echo (int) $m['id']; ?>">
              <?php if (!$is_me): ?>
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                  <div
                    style="width:18px;height:18px;border-radius:50%;background-image:url('<?php echo esc($senderAv); ?>');background-size:cover;background-position:center;">
                  </div>
                  <div style="font-size:12px;color:#667781;font-weight:700;">
                    <?php echo esc($senderName); ?>
                  </div>
                </div>
              <?php endif; ?>
              <div class="bubble">
                <?php echo nl2br(esc($m['message'] ?? '')); ?>
                <?php echo display_attachment($m); ?>
              </div>
              <div class="bubble-time">
                <?php echo esc(date('H:i', strtotime($m['created_at'] ?? 'now'))); ?>
              </div>
            </div>
          <?php endwhile; ?>

        <?php elseif ($selected_user_id > 0 && $chat_user && $user_messages): ?>
          <?php while ($m = $user_messages->fetch_assoc()):
            $is_me = ((int) $m['sender_id'] === $user_id); ?>
            <div class="bubble-row <?php echo $is_me ? 'bubble-row-me' : 'bubble-row-them'; ?>"
              data-id="<?php echo (int) $m['id']; ?>">
              <div class="bubble">
                <?php echo nl2br(esc($m['message'] ?? '')); ?>
                <?php echo display_attachment($m); ?>
              </div>
              <div class="bubble-time">
                <?php echo esc(date('H:i', strtotime($m['created_at'] ?? 'now'))); ?>
              </div>
            </div>
          <?php endwhile; ?>

        <?php else: ?>
          <div class="messages-empty">
            <div class="messages-empty-title">Select a chat</div>
            <div class="messages-empty-sub">Choose from your recent conversations</div>
          </div>
        <?php endif; ?>
      </section>

    <?php if ($selected_group_id > 0 && $chat_group): ?>
      <form class="input-bar" id="groupSendForm" enctype="multipart/form-data" onsubmit="return false;">
        <input type="hidden" id="group_id" value="<?php echo (int)$selected_group_id; ?>">

        <label class="attach-btn" title="Attach">
          <i class="fa-solid fa-paperclip"></i>
          <input type="file" id="group_file" hidden
                 accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,.txt,.html,.htm,.css,.js,.json,.xml,.md,audio/*">
        </label>

        <input type="text" id="group_message" class="input-field" placeholder="Type a message..." maxlength="1000">

        <button type="button" class="input-send" onclick="sendGroupMessage()" title="Send">
          <i class="fa-solid fa-paper-plane"></i>
        </button>
      </form>

    <?php else: ?>
      <form class="input-bar" id="sendForm" enctype="multipart/form-data"
            <?php echo ($selected_user_id > 0) ? '' : 'style="display:none"'; ?> onsubmit="return false;">
        <input type="hidden" id="receiver_id" value="<?php echo (int)$selected_user_id; ?>">

        <label class="attach-btn" title="Attach">
          <i class="fa-solid fa-paperclip"></i>
          <input type="file" id="dm_file" hidden
                 accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,.txt,.html,.htm,.css,.js,.json,.xml,.md,audio/*">
        </label>

        <input type="text" id="dm_message" class="input-field" placeholder="Type a message..." maxlength="1000">

        <button type="button" class="input-send" onclick="sendDMMessage()" title="Send">
          <i class="fa-solid fa-paper-plane"></i>
        </button>
      </form>
    <?php endif; ?>
    </main>
  </div>

  <!-- Hidden Chats Dialog -->
  <dialog id="hiddenChatsDialog" class="find-user-dialog">
    <form method="dialog" class="find-user-form">
      <div class="find-user-head">
        <div class="find-user-title">Hidden</div>
        <button type="button" class="icon-btn" onclick="closeHiddenChats()" aria-label="Close"><i
            class="fas fa-times"></i></button>
      </div>

      <div class="find-user-status" style="margin-top:10px;">Unhide users / groups from here.</div>

      <div class="find-user-results" style="margin-top:10px; max-height:55vh; overflow:auto;">
        <?php if ($hidden_groups && $hidden_groups->num_rows > 0): ?>
          <div class="find-user-status" style="margin-bottom:8px;">Hidden groups</div>
          <?php while ($hg = $hidden_groups->fetch_assoc()):
            $gid = (int) $hg['id'];
            $gname = (string) $hg['name']; ?>
            <div class="chat-row" style="cursor:default;">
              <div class="chat-avatar" style="background-image:url('<?php echo esc(avatar_fallback($gname)); ?>')"></div>
              <div class="chat-main-info">
                <div class="chat-name">
                  <?php echo esc($gname); ?>
                </div>
                <div class="chat-preview">Group</div>
              </div>
              <div class="chat-actions" onclick="event.stopPropagation();">
                <div class="chat-more-wrap">
                  <button class="chat-action-btn" type="button"
                    onclick="toggleItemMore(event,'hidden_group',<?php echo $gid; ?>)"><i
                      class="fas fa-ellipsis-v"></i></button>
                  <div class="chat-more-menu" id="itemMore_hidden_group_<?php echo $gid; ?>">
                    <button type="button" class="chat-more-item"
                      onclick="unhideGroup(<?php echo $gid; ?>); closeAllItemMore();"><i class="fas fa-eye"></i> Unhide
                      group</button>
                    <button type="button" class="chat-more-item" onclick="openGroup(<?php echo $gid; ?>)"><i
                        class="fas fa-comment"></i> Open</button>
                  </div>
                </div>
              </div>
            </div>
          <?php endwhile; ?>
        <?php endif; ?>

        <?php if ($hidden_users && $hidden_users->num_rows > 0): ?>
          <div class="find-user-status" style="margin:14px 0 8px;">Hidden chats</div>
          <?php while ($hu = $hidden_users->fetch_assoc()):
            $uid = (int) $hu['id'];
            $uname = user_display_name($hu);
            $uav = !empty($hu['avatar']) ? $hu['avatar'] : avatar_fallback($uname); ?>
            <div class="chat-row" style="cursor:default;">
              <div class="chat-avatar" style="background-image:url('<?php echo esc($uav); ?>')"></div>
              <div class="chat-main-info">
                <div class="chat-name">
                  <?php echo esc($uname); ?>
                </div>
                <div class="chat-preview">User</div>
              </div>
              <div class="chat-actions" onclick="event.stopPropagation();">
                <div class="chat-more-wrap">
                  <button class="chat-action-btn" type="button"
                    onclick="toggleItemMore(event,'hidden_user',<?php echo $uid; ?>)"><i
                      class="fas fa-ellipsis-v"></i></button>
                  <div class="chat-more-menu" id="itemMore_hidden_user_<?php echo $uid; ?>">
                    <button type="button" class="chat-more-item"
                      onclick="unhideChat(<?php echo $uid; ?>); closeAllItemMore();"><i class="fas fa-eye"></i> Unhide
                      chat</button>
                    <button type="button" class="chat-more-item" onclick="openChat(<?php echo $uid; ?>)"><i
                        class="fas fa-comment"></i> Open</button>
                  </div>
                </div>
              </div>
            </div>
          <?php endwhile; ?>
        <?php endif; ?>

        <?php if (($hidden_groups && $hidden_groups->num_rows === 0) && ($hidden_users && $hidden_users->num_rows === 0)): ?>
          <div class="messages-empty" style="background:#fff;">
            <div class="messages-empty-title" style="font-size:16px;">Nothing hidden</div>
            <div class="messages-empty-sub">You don't have hidden chats.</div>
          </div>
        <?php endif; ?>
      </div>

      <div class="find-user-footer">
        <button type="button" class="btn-secondary" onclick="closeHiddenChats()">Close</button>
      </div>
    </form>
  </dialog>

  <!-- Create group dialog -->
  <dialog id="createGroupDialog" class="find-user-dialog">
    <form method="dialog" class="find-user-form" onsubmit="return false;">
      <div class="find-user-head">
        <div class="find-user-title">Create group</div>
        <button type="button" class="icon-btn" onclick="closeCreateGroup()" aria-label="Close"><i
            class="fas fa-times"></i></button>
      </div>

      <div class="find-user-search" style="margin-top:12px;">
        <i class="fas fa-users"></i>
        <input id="groupNameInput" type="text" placeholder="Group name" autocomplete="off">
      </div>

      <div class="find-user-search" style="margin-top:12px;">
        <i class="fas fa-search"></i>
        <input id="groupUserSearch" type="text" placeholder="Search users to add" autocomplete="off">
      </div>

      <div id="groupUsersBox" class="find-user-results" style="margin-top:10px; max-height:38vh;"></div>
      <div id="groupStatus" class="find-user-status">Select users and click Create.</div>

      <div class="find-user-footer">
        <button type="button" class="btn-secondary" onclick="closeCreateGroup()">Cancel</button>
        <button type="button" class="btn-secondary" style="border-color:#25d366;"
          onclick="createGroupNow()">Create</button>
      </div>
    </form>
  </dialog>

  <!-- Find user dialog -->
  <dialog id="findUserDialog" class="find-user-dialog">
    <form method="dialog" class="find-user-form">
      <div class="find-user-head">
        <div class="find-user-title">Find user</div>
        <button type="button" class="icon-btn" onclick="closeFindUserPopup()" aria-label="Close"><i
            class="fas fa-times"></i></button>
      </div>
      <div class="find-user-search"><i class="fas fa-search"></i><input id="findUserInput" type="text"
          placeholder="Search by name / email" autocomplete="off"></div>
      <div id="findUserStatus" class="find-user-status">Type at least 2 letters…</div>
      <div id="findUserResults" class="find-user-results"></div>
      <div class="find-user-footer"><button type="button" class="btn-secondary"
          onclick="closeFindUserPopup()">Close</button></div>
    </form>
  </dialog>

  <!-- Center popup dialog used for BOTH profile + group members -->
  <dialog id="centerPopup" class="profile-dialog">
    <div class="profile-box">
      <div class="profile-head">
        <div class="profile-title" id="centerPopupTitle">Info</div>
        <button type="button" class="icon-btn" onclick="closeCenterPopup()" aria-label="Close"><i
            class="fas fa-times"></i></button>
      </div>
      <div id="centerPopupContent" style="margin-top:10px;">
        <div style="color:#667781;font-size:13px;">Loading...</div>
      </div>
    </div>
  </dialog>

  <script>
    function escapeHtml(str) {
      return String(str || '')
        .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;').replaceAll("'", "&#039;");
    }

    /* navigation */
    function openChat(id) { location.href = 'index.php?chat=' + id + '&filter=<?php echo esc($filter); ?>'; }
    function openGroup(id) { location.href = 'index.php?group=' + id + '&filter=<?php echo esc($filter); ?>'; }
    function setFilter(f) { const u = new URL(location.href); u.searchParams.set('filter', f); u.searchParams.delete('chat'); u.searchParams.delete('group'); location.href = u.toString(); }

    /* search */
    function filterChats() {
      const q = (document.getElementById('searchInput').value || '').toLowerCase();
      document.querySelectorAll('.contact-row').forEach(r => {
        const name = (r.getAttribute('data-name') || '').toLowerCase();
        r.style.display = name.includes(q) ? 'flex' : 'none';
      });
    }

    /* top menu */
    function toggleChatsMenu(e) { e.stopPropagation(); const m = document.getElementById('chatsMenu'); m.style.display = (m.style.display === 'block') ? 'none' : 'block'; }
    function closeChatsMenu() { const m = document.getElementById('chatsMenu'); if (m) m.style.display = 'none'; }
    document.addEventListener('click', closeChatsMenu);

    /* item dropdown */
    function closeAllItemMore() { document.querySelectorAll('.chat-more-menu').forEach(m => m.style.display = 'none'); }
    function toggleItemMore(ev, type, id) {
      ev.stopPropagation();
      const el = document.getElementById('itemMore_' + type + '_' + id);
      if (!el) return;
      const open = el.style.display === 'block';
      closeAllItemMore();
      el.style.display = open ? 'none' : 'block';
    }
    document.addEventListener('click', closeAllItemMore);

    /* dialogs */
    const findDlg = document.getElementById('findUserDialog');
    const hiddenDlg = document.getElementById('hiddenChatsDialog');
    const groupDlg = document.getElementById('createGroupDialog');
    const centerPopup = document.getElementById('centerPopup');

    function openFindUserPopup() { closeChatsMenu(); if (findDlg && !findDlg.open) findDlg.showModal(); }
    function closeFindUserPopup() { if (findDlg && findDlg.open) findDlg.close(); }

    function openHiddenChats() { if (hiddenDlg && !hiddenDlg.open) hiddenDlg.showModal(); }
    function closeHiddenChats() { if (hiddenDlg && hiddenDlg.open) hiddenDlg.close(); }

    function openCreateGroup() { closeChatsMenu(); if (groupDlg && !groupDlg.open) groupDlg.showModal(); selectedMembers.clear(); groupNameInput.value = ''; groupUserSearch.value = ''; groupUsersBox.innerHTML = ''; groupStatus.textContent = 'Select users and click Create.'; }
    function closeCreateGroup() { if (groupDlg && groupDlg.open) groupDlg.close(); }

    function closeCenterPopup() { if (centerPopup && centerPopup.open) centerPopup.close(); }

    /* backend calls */
    async function postForm(fd) {
      const res = await fetch('index.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      return await res.json();
    }
    async function postChatAction(otherId, action) {
      const fd = new FormData();
      fd.append('action', action);
      fd.append('other_id', otherId);
      return await postForm(fd);
    }
    async function postGroupAction(groupId, action) {
      const fd = new FormData();
      fd.append('action', action);
      fd.append('group_id', groupId);
      return await postForm(fd);
    }

    /* user chat actions */
    async function pinChat(id) { const d = await postChatAction(id, 'pin_toggle'); if (!d.ok) return alert(d.error || 'Pin failed'); location.reload(); }
    async function hideChat(id) {
      if (!confirm('Hide this chat?')) return;
      const d = await postChatAction(id, 'hide_chat'); if (!d.ok) return alert(d.error || 'Hide failed');
      const u = new URL(location.href); if (u.searchParams.get('chat') == String(id)) location.href = 'index.php'; else location.reload();
    }
    async function unhideChat(id) { const d = await postChatAction(id, 'unhide_chat'); if (!d.ok) return alert(d.error || 'Unhide failed'); location.reload(); }
    async function deleteChat(id) { if (!confirm('Delete this chat from your list?')) return; return hideChat(id); }

    /* group actions */
    async function pinGroup(id) { const d = await postGroupAction(id, 'group_pin_toggle'); if (!d.ok) return alert(d.error || 'Pin failed'); location.reload(); }
    async function hideGroup(id) {
      if (!confirm('Hide this group?')) return;
      const d = await postGroupAction(id, 'group_hide'); if (!d.ok) return alert(d.error || 'Hide failed');
      const u = new URL(location.href); if (u.searchParams.get('group') == String(id)) location.href = 'index.php'; else location.reload();
    }
    async function unhideGroup(id) { const d = await postGroupAction(id, 'group_unhide'); if (!d.ok) return alert(d.error || 'Unhide failed'); location.reload(); }
    async function deleteGroupChat(id) { if (!confirm('Delete this group chat from your list?')) return; return hideGroup(id); }

    /* Center popup: User profile */
    async function openUserProfile(userId) {
      if (!centerPopup) return;
      document.getElementById('centerPopupTitle').textContent = 'Profile';
      const box = document.getElementById('centerPopupContent');
      box.innerHTML = `<div style="color:#667781;font-size:13px;">Loading...</div>`;
      if (!centerPopup.open) centerPopup.showModal();

      const res = await fetch('index.php?action=get_user_profile&id=' + encodeURIComponent(userId), { credentials: 'same-origin' });
      const data = await res.json();
      if (!data.ok) {
        box.innerHTML = `<div style="color:#d93025;font-weight:800;">${escapeHtml(data.error || 'Error')}</div>`;
        return;
      }
      const u = data.user;

      const lines = [];
      if (u.username) lines.push(`<div class="profile-row"><i class="fas fa-at"></i><div>@${escapeHtml(u.username)}</div></div>`);
      if (u.email) lines.push(`<div class="profile-row"><i class="fas fa-envelope"></i><div>${escapeHtml(u.email)}</div></div>`);
      if (u.phone) lines.push(`<div class="profile-row"><i class="fas fa-phone"></i><div>${escapeHtml(u.phone)}</div></div>`);

      box.innerHTML = `
        <div class="profile-body">
          <div class="profile-avatar" style="background-image:url('${(u.avatar || '').replace(/'/g, "\\'")}')"></div>
          <div class="profile-lines">
            <div class="profile-name">${escapeHtml(u.name)}</div>
            <div class="profile-meta">
              ${lines.length ? lines.join('') : '<div style="color:#667781;">No extra info</div>'}
            </div>
          </div>
        </div>
        <div class="profile-actions">
          <button class="btn" type="button" onclick="closeCenterPopup()">Close</button>
        </div>
      `;
    }

    /* Center popup: Group members */
    async function openGroupMembers(groupId) {
      if (!centerPopup) return;
      document.getElementById('centerPopupTitle').textContent = 'Group members';
      const box = document.getElementById('centerPopupContent');
      box.innerHTML = `<div style="color:#667781;font-size:13px;">Loading...</div>`;
      if (!centerPopup.open) centerPopup.showModal();

      const res = await fetch('index.php?action=get_group_members&group_id=' + encodeURIComponent(groupId), { credentials: 'same-origin' });
      const data = await res.json();
      if (!data.ok) {
        box.innerHTML = `<div style="color:#d93025;font-weight:800;">${escapeHtml(data.error || 'Error')}</div>`;
        return;
      }
      const members = data.members || [];
      if (!members.length) {
        box.innerHTML = `<div style="color:#667781;font-size:13px;">No members found</div>`;
        return;
      }

      box.innerHTML = `
        <div style="color:#667781;font-size:13px;">Tap a member to see profile.</div>
        <div class="members-list">
          ${members.map(m => {
            const sub = (m.email || m.phone || (m.username ? ('@' + m.username) : '')) || '';
            const roleBadge = (m.role && m.role.toLowerCase() === 'admin') ? `<span class="badge-role">ADMIN</span>` : '';
            return `
              <div class="member-item" onclick="openUserProfile(${m.id})">
                <div class="member-av" style="background-image:url('${(m.avatar || '').replace(/'/g, "\\'")}')"></div>
                <div style="min-width:0;">
                  <div class="member-name">${escapeHtml(m.name)}</div>
                  <div class="member-sub">${escapeHtml(sub)}</div>
                </div>
                ${roleBadge}
              </div>
            `;
          }).join('')}
        </div>
        <div class="profile-actions">
          <button class="btn" type="button" onclick="closeCenterPopup()">Close</button>
        </div>
      `;
    }

    /* find user search */
    const findInput = document.getElementById('findUserInput');
    const findStatus = document.getElementById('findUserStatus');
    const findResults = document.getElementById('findUserResults');
    let t = null;
    async function runUserSearch(q) {
      findStatus.textContent = 'Searching...';
      const res = await fetch('index.php?action=find_user&q=' + encodeURIComponent(q), { credentials: 'same-origin' });
      const data = await res.json();
      if (!data.ok) { findStatus.textContent = data.error || 'Search failed'; findResults.innerHTML = ''; return; }
      const users = data.users || [];
      if (!users.length) { findStatus.textContent = 'No users found'; findResults.innerHTML = ''; return; }
      findStatus.textContent = 'Select a user to chat';
      findResults.innerHTML = users.map(u => {
        const avatar = (u.avatar && u.avatar.length) ? u.avatar : ('https://ui-avatars.com/api/?name=' + encodeURIComponent(u.name) + '&size=128&background=E3E5EA&color=111b21');
        return `
          <div class="find-user-row">
            <div class="find-user-avatar" style="background-image:url('${avatar.replace(/'/g, "\\\\'")}')"></div>
            <div class="find-user-info" style="min-width:0;">
              <div class="find-user-name">${escapeHtml(u.name)}</div>
              <div class="find-user-sub">${escapeHtml(u.email || '')}</div>
            </div>
            <button type="button" class="find-user-btn" style="padding:8px 10px;border-radius:10px;" onclick="openUserProfile(${u.id})">Info</button>
            <button type="button" class="find-user-btn" style="padding:8px 10px;border-radius:10px;" onclick="openChat(${u.id})">Chat</button>
          </div>`;
      }).join('');
    }
    if (findInput) {
      findInput.addEventListener('input', () => {
        clearTimeout(t);
        const q = (findInput.value || '').trim();
        if (q.length < 2) { findStatus.textContent = 'Type at least 2 letters…'; findResults.innerHTML = ''; return; }
        t = setTimeout(() => runUserSearch(q), 250);
      });
    }

    /* group create selection */
    const groupNameInput = document.getElementById('groupNameInput');
    const groupUserSearch = document.getElementById('groupUserSearch');
    const groupUsersBox = document.getElementById('groupUsersBox');
    const groupStatus = document.getElementById('groupStatus');
    let selectedMembers = new Map();

    function renderGroupUsers(users) {
      if (!users.length) { groupUsersBox.innerHTML = '<div class="find-user-status">No users found</div>'; return; }
      groupUsersBox.innerHTML = users.map(u => {
        const checked = selectedMembers.has(u.id) ? 'checked' : '';
        const avatar = (u.avatar && u.avatar.length) ? u.avatar : ('https://ui-avatars.com/api/?name=' + encodeURIComponent(u.name) + '&size=128&background=E3E5EA&color=111b21');
        return `
          <label class="find-user-row" style="cursor:pointer;">
            <input type="checkbox" data-uid="${u.id}" ${checked} style="margin-right:10px;">
            <div class="find-user-avatar" style="background-image:url('${avatar.replace(/'/g, "\\\\'")}')"></div>
            <div class="find-user-info">
              <div class="find-user-name">${escapeHtml(u.name)}</div>
              <div class="find-user-sub">${escapeHtml(u.email || '')}</div>
            </div>
          </label>`;
      }).join('');

      groupUsersBox.querySelectorAll('input[type="checkbox"][data-uid]').forEach(cb => {
        cb.addEventListener('change', () => {
          const id = parseInt(cb.getAttribute('data-uid'), 10);
          const user = users.find(x => x.id === id);
          if (!user) return;
          if (cb.checked) selectedMembers.set(id, user);
          else selectedMembers.delete(id);
          groupStatus.textContent = `${selectedMembers.size} user(s) selected.`;
        });
      });
    }
    let gTimer = null;
    async function searchGroupUsers(q) {
      const res = await fetch('index.php?action=group_user_search&q=' + encodeURIComponent(q), { credentials: 'same-origin' });
      const data = await res.json();
      if (!data.ok) { groupUsersBox.innerHTML = `<div class="find-user-status">${escapeHtml(data.error || 'Error')}</div>`; return; }
      renderGroupUsers(data.users || []);
    }
    if (groupUserSearch) {
      groupUserSearch.addEventListener('input', () => {
        clearTimeout(gTimer);
        const q = (groupUserSearch.value || '').trim();
        gTimer = setTimeout(() => searchGroupUsers(q), 250);
      });
    }
    async function createGroupNow() {
      const name = (groupNameInput.value || '').trim();
      if (name.length < 2) { groupStatus.textContent = 'Enter group name.'; return; }
      if (selectedMembers.size < 1) { groupStatus.textContent = 'Select users to add to the group.'; return; }
      groupStatus.textContent = 'Creating...';

      const fd = new FormData();
      fd.append('action', 'create_group');
      fd.append('group_name', name);
      [...selectedMembers.keys()].forEach(id => fd.append('members[]', String(id)));

      const d = await postForm(fd);
      if (!d.ok) { groupStatus.textContent = d.error || 'Create failed'; return; }
      groupStatus.textContent = 'Group created.';
      setTimeout(() => { closeCreateGroup(); openGroup(d.group_id); }, 300);
    }

    (function () {
      const area = document.getElementById('messagesArea');
      if (area) area.scrollTop = area.scrollHeight;
    })();
    
    const MAX_FILE = 10 * 1024 * 1024; // 10MB

    function validateFile(file){
      if(!file) return {ok: true};
      if(file.size > MAX_FILE) return {ok: false, msg: "Max file size is 10 MB"};

      // allowed extensions (your list)
      const allowedExt = ["jpg","jpeg","png","webp","gif","pdf","txt","html","htm","css","js","json","xml","md","mp3","wav","m4a","ogg","webm"];
      const name = file.name || "";
      const ext = name.includes(".") ? name.split(".").pop().toLowerCase() : "";
      if(ext && !allowedExt.includes(ext)) return {ok: false, msg: "This file type is not allowed"};
      return {ok: true};
    }

    function sendDMMessage() {
      const receiverId = document.getElementById("receiver_id").value;
      const msg = document.getElementById("dm_message").value.trim();
      const fileInput = document.getElementById("dm_file");
      const file = fileInput.files[0] || null;

      const v = validateFile(file);
      if(!v.ok){ alert(v.msg); fileInput.value = ''; return; }
      if(!msg && !file){ return; }

      // Show sending indicator
      const sendBtn = document.querySelector('#sendForm .input-send');
      const originalHtml = sendBtn.innerHTML;
      sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
      sendBtn.disabled = true;

      const fd = new FormData();
      fd.append("receiver_id", receiverId);
      fd.append("message", msg);
      if(file) fd.append("attachment", file);

      const xhr = new XMLHttpRequest();
      xhr.open("POST", "send_message.php", true);
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      xhr.onload = function(){
        sendBtn.innerHTML = originalHtml;
        sendBtn.disabled = false;
        
        if(xhr.status === 200){
          try {
            const resp = JSON.parse(xhr.responseText);
            if(resp.ok) {
              document.getElementById("dm_message").value = "";
              fileInput.value = "";
              location.reload(); // Simple reload to show new message
            } else {
              alert(resp.error || "Failed to send");
            }
          } catch(e) {
            alert("Failed to send message");
          }
        } else {
          alert("Failed to send: Server error");
        }
      };
      xhr.onerror = function() {
        sendBtn.innerHTML = originalHtml;
        sendBtn.disabled = false;
        alert("Network error");
      };
      xhr.send(fd);
    }

    function sendGroupMessage() {
      const groupId = document.getElementById("group_id").value;
      const msg = document.getElementById("group_message").value.trim();
      const fileInput = document.getElementById("group_file");
      const file = fileInput.files[0] || null;

      const v = validateFile(file);
      if(!v.ok){ alert(v.msg); fileInput.value = ''; return; }
      if(!msg && !file){ return; }

      // Show sending indicator
      const sendBtn = document.querySelector('#groupSendForm .input-send');
      const originalHtml = sendBtn.innerHTML;
      sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
      sendBtn.disabled = true;

      const fd = new FormData();
      fd.append("group_id", groupId);
      fd.append("message", msg);
      if(file) fd.append("attachment", file);

      const xhr = new XMLHttpRequest();
      xhr.open("POST", "send_group_message.php", true);
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      xhr.onload = function(){
        sendBtn.innerHTML = originalHtml;
        sendBtn.disabled = false;
        
        if(xhr.status === 200){
          try {
            const resp = JSON.parse(xhr.responseText);
            if(resp.ok) {
              document.getElementById("group_message").value = "";
              fileInput.value = "";
              location.reload(); // Simple reload to show new message
            } else {
              alert(resp.error || "Failed to send");
            }
          } catch(e) {
            alert("Failed to send message");
          }
        } else {
          alert("Failed to send: Server error");
        }
      };
      xhr.onerror = function() {
        sendBtn.innerHTML = originalHtml;
        sendBtn.disabled = false;
        alert("Network error");
      };
      xhr.send(fd);
    }

    // Show selected file name
    document.getElementById('dm_file')?.addEventListener('change', function(e) {
      const fileName = e.target.files[0]?.name;
      if(fileName) {
        const input = document.getElementById('dm_message');
        input.placeholder = `File: ${fileName} (type message or leave empty)`;
      }
    });

    document.getElementById('group_file')?.addEventListener('change', function(e) {
      const fileName = e.target.files[0]?.name;
      if(fileName) {
        const input = document.getElementById('group_message');
        input.placeholder = `File: ${fileName} (type message or leave empty)`;
      }
    });
  </script>
</body>
</html>