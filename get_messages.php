<?php
// get_messages.php - Updated to include attachments

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require 'config.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not logged in']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

$chat_id = (int)($_GET['chat_id'] ?? 0);
$after_id = (int)($_GET['after_id'] ?? 0);

if ($chat_id <= 0) {
    echo json_encode(['ok' => true, 'messages' => []]);
    exit;
}

// Check if attachment column exists
$attachment_field = "NULL AS attachment";
$check = $conn->query("SHOW COLUMNS FROM messages LIKE 'attachment'");
if ($check && $check->num_rows > 0) {
    $attachment_field = "attachment";
}

$stmt = $conn->prepare("
    SELECT id, sender_id, receiver_id, message, $attachment_field as attachment, created_at
    FROM messages
    WHERE (
            (sender_id = ? AND receiver_id = ?)
         OR (sender_id = ? AND receiver_id = ?)
          )
      AND id > ?
    ORDER BY id ASC
    LIMIT 200
");
$stmt->bind_param("iiiii", $user_id, $chat_id, $chat_id, $user_id, $after_id);
$stmt->execute();
$res = $stmt->get_result();

$messages = [];
while ($row = $res->fetch_assoc()) {
    $messages[] = [
        'id' => (int)$row['id'],
        'sender_id' => (int)$row['sender_id'],
        'receiver_id' => (int)$row['receiver_id'],
        'message' => (string)$row['message'],
        'attachment' => $row['attachment'] ? (string)$row['attachment'] : null,
        'created_at' => (string)$row['created_at'],
    ];
}
$stmt->close();

echo json_encode(['ok' => true, 'messages' => $messages]);
exit;