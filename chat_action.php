<?php
// chat_action.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require 'config.php';

header('Content-Type: application/json; charset=utf-8');

function out($a, $code=200){
  http_response_code($code);
  echo json_encode($a);
  exit;
}

if (empty($_SESSION['user_id'])) out(['ok'=>false,'error'=>'Not logged in'], 401);
$user_id = (int)$_SESSION['user_id'];

$action = trim((string)($_POST['action'] ?? ''));
$other_id = (int)($_POST['other_id'] ?? 0);

if ($other_id <= 0 || $other_id === $user_id) out(['ok'=>false,'error'=>'Invalid user'], 400);

$allowed = ['pin_toggle','hide_chat','unhide_chat'];
if (!in_array($action, $allowed, true)) out(['ok'=>false,'error'=>'Invalid action'], 400);

// Ensure other user exists
$chk = $conn->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
$chk->bind_param("i", $other_id);
$chk->execute();
$exists = $chk->get_result()->fetch_assoc();
$chk->close();
if (!$exists) out(['ok'=>false,'error'=>'User not found'], 404);

// Ensure row exists
$ins = $conn->prepare("
  INSERT INTO chat_settings (user_id, other_id, is_pinned, is_hidden)
  VALUES (?, ?, 0, 0)
  ON DUPLICATE KEY UPDATE user_id = user_id
");
$ins->bind_param("ii", $user_id, $other_id);
$ins->execute();
$ins->close();

if ($action === 'pin_toggle') {
  $up = $conn->prepare("UPDATE chat_settings SET is_pinned = 1 - is_pinned WHERE user_id = ? AND other_id = ?");
  $up->bind_param("ii", $user_id, $other_id);
  $up->execute();
  $up->close();

  $st = $conn->prepare("SELECT is_pinned FROM chat_settings WHERE user_id = ? AND other_id = ? LIMIT 1");
  $st->bind_param("ii", $user_id, $other_id);
  $st->execute();
  $row = $st->get_result()->fetch_assoc();
  $st->close();

  out(['ok'=>true,'is_pinned'=>(int)($row['is_pinned'] ?? 0)]);
}

if ($action === 'hide_chat') {
  $up = $conn->prepare("UPDATE chat_settings SET is_hidden = 1 WHERE user_id = ? AND other_id = ?");
  $up->bind_param("ii", $user_id, $other_id);
  $up->execute();
  $up->close();
  out(['ok'=>true]);
}

if ($action === 'unhide_chat') {
  $up = $conn->prepare("UPDATE chat_settings SET is_hidden = 0 WHERE user_id = ? AND other_id = ?");
  $up->bind_param("ii", $user_id, $other_id);
  $up->execute();
  $up->close();
  out(['ok'=>true]);
}
