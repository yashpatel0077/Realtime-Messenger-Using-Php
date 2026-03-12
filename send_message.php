<?php
// send_message.php - Full updated version with file upload support

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require 'config.php';

function json_out($arr, int $code = 200){
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr);
    exit;
}

function handle_file_upload($file, $user_id) {
    $max_size = 10 * 1024 * 1024; // 10MB
    $allowed_types = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif',
        'application/pdf', 'text/plain', 'text/html', 'text/css',
        'application/javascript', 'application/json', 'application/xml',
        'text/markdown', 'audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/webm',
        'image/jpg' // Added for compatibility
    ];
    
    $allowed_extensions = [
        'jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf', 'txt',
        'html', 'htm', 'css', 'js', 'json', 'xml', 'md',
        'mp3', 'wav', 'm4a', 'ogg', 'webm'
    ];

    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $upload_errors = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
        ];
        $error_msg = $upload_errors[$file['error']] ?? 'Unknown upload error';
        return ['ok' => false, 'error' => $error_msg];
    }

    // Check file size
    if ($file['size'] > $max_size) {
        return ['ok' => false, 'error' => 'File too large (max 10MB)'];
    }

    // Check file type using finfo if available
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
    } else {
        // Fallback to file extension check only
        $mime_type = $file['type'];
    }

    // Only check mime type if we have it from finfo
    if (isset($mime_type) && !in_array($mime_type, $allowed_types)) {
        // Allow based on extension as fallback
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_extensions)) {
            return ['ok' => false, 'error' => 'File type not allowed'];
        }
    }

    // Check extension
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_extensions)) {
        return ['ok' => false, 'error' => 'File extension not allowed'];
    }

    // Create upload directory if it doesn't exist
    $upload_dir = __DIR__ . '/uploads/';
    if (!file_exists($upload_dir)) {
        if (!mkdir($upload_dir, 0777, true)) {
            return ['ok' => false, 'error' => 'Failed to create upload directory'];
        }
    }

    // Check if directory is writable
    if (!is_writable($upload_dir)) {
        return ['ok' => false, 'error' => 'Upload directory is not writable'];
    }

    // Generate unique filename with user_id prefix for tracking
    $new_filename = $user_id . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $upload_path = $upload_dir . $new_filename;
    $relative_path = 'uploads/' . $new_filename;

    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $upload_path)) {
        chmod($upload_path, 0644);
        
        // Return file info including name and size for display
        return [
            'ok' => true, 
            'path' => $relative_path,
            'name' => $file['name'],
            'size' => $file['size'],
            'type' => $mime_type ?? $file['type']
        ];
    }

    return ['ok' => false, 'error' => 'Failed to save file'];
}

// Function to ensure attachment columns exist
function ensure_attachment_columns($conn) {
    $columns_to_add = [
        'attachment_path' => "ALTER TABLE messages ADD COLUMN attachment_path VARCHAR(500) NULL AFTER message",
        'attachment_name' => "ALTER TABLE messages ADD COLUMN attachment_name VARCHAR(255) NULL AFTER attachment_path",
        'attachment_size' => "ALTER TABLE messages ADD COLUMN attachment_size INT NULL AFTER attachment_name",
        'attachment_type' => "ALTER TABLE messages ADD COLUMN attachment_type VARCHAR(100) NULL AFTER attachment_size"
    ];
    
    foreach ($columns_to_add as $column => $sql) {
        $check = $conn->query("SHOW COLUMNS FROM messages LIKE '$column'");
        if ($check->num_rows == 0) {
            $conn->query($sql);
        }
    }
}

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) 
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if (empty($_SESSION['user_id'])) {
    if ($isAjax) json_out(['ok' => false, 'error' => 'Not logged in'], 401);
    header("Location: login.php");
    exit;
}

$sender_id = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($isAjax) json_out(['ok' => false, 'error' => 'Invalid method'], 405);
    header("Location: index.php");
    exit;
}

$receiver_id = (int)($_POST['receiver_id'] ?? 0);
$message = trim((string)($_POST['message'] ?? ''));
$attachment_path = null;
$attachment_name = null;
$attachment_size = null;
$attachment_type = null;

if ($receiver_id <= 0 || $receiver_id === $sender_id) {
    if ($isAjax) json_out(['ok' => false, 'error' => 'Invalid receiver'], 400);
    header("Location: index.php");
    exit;
}

// Ensure attachment columns exist
ensure_attachment_columns($conn);

// Handle file upload if present
if (!empty($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
    if ($_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $upload_result = handle_file_upload($_FILES['attachment'], $sender_id);
        if (!$upload_result['ok']) {
            if ($isAjax) json_out(['ok' => false, 'error' => $upload_result['error']], 400);
            // For non-AJAX, redirect with error
            header("Location: index.php?chat=" . $receiver_id . "&error=" . urlencode($upload_result['error']));
            exit;
        }
        $attachment_path = $upload_result['path'];
        $attachment_name = $upload_result['name'];
        $attachment_size = $upload_result['size'];
        $attachment_type = $upload_result['type'];
    } else {
        // Handle other upload errors
        $upload_errors = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds server limit',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds form limit',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file',
            UPLOAD_ERR_EXTENSION => 'Upload stopped by extension'
        ];
        $error = $upload_errors[$_FILES['attachment']['error']] ?? 'Unknown upload error';
        
        if ($isAjax) json_out(['ok' => false, 'error' => $error], 400);
        header("Location: index.php?chat=" . $receiver_id . "&error=" . urlencode($error));
        exit;
    }
}

// Check if we have either message or attachment
if ($message === '' && $attachment_path === null) {
    if ($isAjax) json_out(['ok' => false, 'error' => 'Empty message and no file'], 400);
    header("Location: index.php?chat=" . $receiver_id);
    exit;
}

// Limit message length (matches maxlength=1000)
if (mb_strlen($message) > 1000) {
    $message = mb_substr($message, 0, 1000);
}

// Ensure receiver exists (avoid sending to deleted id)
$chk = $conn->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
if (!$chk) {
    if ($isAjax) json_out(['ok' => false, 'error' => 'DB error: ' . $conn->error], 500);
    die("DB error: " . $conn->error);
}
$chk->bind_param("i", $receiver_id);
$chk->execute();
$exists = $chk->get_result()->fetch_assoc();
$chk->close();

if (!$exists) {
    if ($isAjax) json_out(['ok' => false, 'error' => 'User not found'], 404);
    header("Location: index.php");
    exit;
}

// Insert message with attachment columns
$sql = "INSERT INTO messages (
    sender_id, 
    receiver_id, 
    message, 
    attachment_path, 
    attachment_name, 
    attachment_size, 
    attachment_type, 
    created_at
) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    if ($isAjax) json_out(['ok' => false, 'error' => 'DB error: ' . $conn->error], 500);
    die("DB error: " . $conn->error);
}

$stmt->bind_param(
    "iisssis", 
    $sender_id, 
    $receiver_id, 
    $message, 
    $attachment_path, 
    $attachment_name, 
    $attachment_size, 
    $attachment_type
);

if (!$stmt->execute()) {
    if ($isAjax) json_out(['ok' => false, 'error' => 'Failed to send: ' . $stmt->error], 500);
    header("Location: index.php?chat=" . $receiver_id . "&error=" . urlencode('Failed to send message'));
    exit;
}

$newId = (int)$stmt->insert_id;
$stmt->close();

if ($isAjax) {
    // Get the created message to return full data
    $stmt = $conn->prepare("
        SELECT 
            id, 
            sender_id, 
            receiver_id, 
            message, 
            attachment_path, 
            attachment_name, 
            attachment_size, 
            attachment_type, 
            created_at 
        FROM messages 
        WHERE id = ?
    ");
    $stmt->bind_param("i", $newId);
    $stmt->execute();
    $result = $stmt->get_result();
    $newMessage = $result->fetch_assoc();
    $stmt->close();
    
    // Format response for frontend
    $response = [
        'ok' => true,
        'id' => $newId,
        'sender_id' => $sender_id,
        'receiver_id' => $receiver_id,
        'message' => $message,
        'created_at' => $newMessage['created_at']
    ];
    
    // Add attachment info if present
    if ($attachment_path) {
        $response['attachment'] = [
            'path' => $attachment_path,
            'name' => $attachment_name,
            'size' => $attachment_size,
            'type' => $attachment_type,
            'icon' => get_file_icon($attachment_name)
        ];
    }
    
    json_out($response);
}

// Helper function for file icons (can be used in both send_message.php and index.php)
function get_file_icon($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $icons = [
        'jpg' => 'fa-file-image', 'jpeg' => 'fa-file-image', 'png' => 'fa-file-image',
        'gif' => 'fa-file-image', 'webp' => 'fa-file-image',
        'pdf' => 'fa-file-pdf',
        'mp3' => 'fa-file-audio', 'wav' => 'fa-file-audio', 'ogg' => 'fa-file-audio', 'm4a' => 'fa-file-audio',
        'txt' => 'fa-file-lines', 'html' => 'fa-file-code', 'htm' => 'fa-file-code',
        'css' => 'fa-file-code', 'js' => 'fa-file-code', 'json' => 'fa-file-code',
        'xml' => 'fa-file-code', 'md' => 'fa-file-lines'
    ];
    return $icons[$ext] ?? 'fa-file';
}

// PRG redirect for normal form submit
header("Location: index.php?chat=" . $receiver_id);
exit;
?>