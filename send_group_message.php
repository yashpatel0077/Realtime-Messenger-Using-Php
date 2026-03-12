<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require 'config.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$group_id = isset($_POST['group_id']) ? (int) $_POST['group_id'] : 0;
$message = trim($_POST['message'] ?? '');

if ($group_id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid group']);
    exit;
}

// Verify user is a member of the group
$chk = $conn->prepare("SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ? LIMIT 1");
$chk->bind_param("ii", $group_id, $user_id);
$chk->execute();
$is_member = (bool) $chk->get_result()->fetch_assoc();
$chk->close();

if (!$is_member) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not a member of this group']);
    exit;
}

// Handle file upload
$attachment_path = null;
$attachment_name = null;
$attachment_size = null;
$attachment_type = null;

if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['attachment'];
    $max_size = 10 * 1024 * 1024; // 10MB
    
    if ($file['size'] > $max_size) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'File too large (max 10MB)']);
        exit;
    }
    
    // Create uploads directory if it doesn't exist
    $upload_dir = __DIR__ . '/uploads/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    // Generate unique filename
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $new_filename = uniqid() . '_' . time() . '.' . $ext;
    $upload_path = $upload_dir . $new_filename;
    
    if (move_uploaded_file($file['tmp_name'], $upload_path)) {
        $attachment_path = 'uploads/' . $new_filename;
        $attachment_name = $file['name'];
        $attachment_size = $file['size'];
        $attachment_type = $file['type'];
    }
}

// Insert group message with attachment info
$sql = "INSERT INTO group_messages (group_id, sender_id, message, attachment_path, attachment_name, attachment_size, attachment_type, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";

$st = $conn->prepare($sql);
if (!$st) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error']);
    exit;
}

$st->bind_param("iisssis", 
    $group_id, 
    $user_id, 
    $message, 
    $attachment_path, 
    $attachment_name, 
    $attachment_size, 
    $attachment_type
);

if ($st->execute()) {
    echo json_encode(['ok' => true, 'message_id' => $conn->insert_id]);
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to send message']);
}

$st->close();
$conn->close();
?>