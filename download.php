<?php
// download.php - Secure file download script

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require 'config.php';

// Function to send error and exit
function send_error($message, $code = 403) {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Error: $message";
    exit;
}

// Check if user is logged in
if (empty($_SESSION['user_id'])) {
    send_error('Not logged in', 401);
}

$user_id = (int)$_SESSION['user_id'];

// Get parameters
$message_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$type = isset($_GET['type']) ? $_GET['type'] : '';
$action = isset($_GET['download']) ? 'download' : (isset($_GET['view']) ? 'view' : (isset($_GET['preview']) ? 'preview' : 'download'));

if ($message_id <= 0 || !in_array($type, ['dm', 'group'])) {
    send_error('Invalid request parameters', 400);
}

try {
    $attachment_path = null;
    $attachment_name = null;
    $attachment_size = null;
    
    if ($type === 'dm') {
        // Get attachment from direct message
        $stmt = $conn->prepare("
            SELECT attachment_path, attachment_name, attachment_size 
            FROM messages 
            WHERE id = ? AND (sender_id = ? OR receiver_id = ?)
            LIMIT 1
        ");
        $stmt->bind_param("iii", $message_id, $user_id, $user_id);
    } else {
        // Get attachment from group message
        $stmt = $conn->prepare("
            SELECT gm.attachment_path, gm.attachment_name, gm.attachment_size 
            FROM group_messages gm
            JOIN group_members gmem ON gmem.group_id = gm.group_id AND gmem.user_id = ?
            WHERE gm.id = ?
            LIMIT 1
        ");
        $stmt->bind_param("ii", $user_id, $message_id);
    }
    
    if (!$stmt) {
        send_error('Database error', 500);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $attachment_path = $row['attachment_path'];
        $attachment_name = $row['attachment_name'];
        $attachment_size = $row['attachment_size'];
    }
    $stmt->close();
    
    if (empty($attachment_path)) {
        send_error('Attachment not found', 404);
    }
    
    // Get the filename from path
    $file = basename($attachment_path);
    
    // Security: Prevent directory traversal attacks
    if (strpos($file, '..') !== false || strpos($file, '/') !== false || strpos($file, '\\') !== false) {
        send_error('Invalid file path', 400);
    }
    
    // Security: Only allow alphanumeric, dots, hyphens and underscores in filename
    if (!preg_match('/^[a-zA-Z0-9._-]+$/', $file)) {
        send_error('Invalid filename format', 400);
    }
    
    $file_path = __DIR__ . '/uploads/' . $file;
    
    // Check if file exists
    if (!file_exists($file_path)) {
        send_error('File not found on server', 404);
    }
    
    // Check if it's a regular file (not a directory)
    if (!is_file($file_path)) {
        send_error('Invalid file', 400);
    }
    
    // Get file info
    $file_size = filesize($file_path);
    $file_name = $attachment_name ?: basename($file_path);
    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
    
    // Define MIME types
    $mime_types = [
        // Images
        'jpg' => 'image/jpeg', 
        'jpeg' => 'image/jpeg', 
        'png' => 'image/png',
        'gif' => 'image/gif', 
        'webp' => 'image/webp',
        
        // Documents
        'pdf' => 'application/pdf', 
        'txt' => 'text/plain',
        'html' => 'text/html', 
        'htm' => 'text/html',
        'css' => 'text/css', 
        'js' => 'application/javascript',
        'json' => 'application/json', 
        'xml' => 'application/xml',
        'md' => 'text/markdown',
        
        // Audio
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav', 
        'm4a' => 'audio/mp4',
        'ogg' => 'audio/ogg', 
        'webm' => 'audio/webm'
    ];
    
    $mime_type = $mime_types[$file_ext] ?? 'application/octet-stream';
    
    // For preview action with images, show inline instead of download
    $content_disposition = 'attachment';
    if ($action === 'view' && in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
        $content_disposition = 'inline';
    } else if ($action === 'preview' && in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
        // For preview, we want to output the image directly
        header('Content-Type: ' . $mime_type);
        header('Content-Length: ' . $file_size);
        readfile($file_path);
        exit;
    }
    
    // Disable output buffering
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Set headers for download/view
    header('Content-Description: File Transfer');
    header('Content-Type: ' . $mime_type);
    header('Content-Disposition: ' . $content_disposition . '; filename="' . $file_name . '"');
    header('Content-Length: ' . $file_size);
    header('Cache-Control: private, max-age=3600, must-revalidate');
    header('Pragma: public');
    header('Expires: 0');
    header('X-Content-Type-Options: nosniff');
    
    // Clear any previous output
    if (ob_get_length()) {
        ob_clean();
        flush();
    }
    
    // Output file
    readfile($file_path);
    exit;
    
} catch (Exception $e) {
    send_error('Server error: ' . $e->getMessage(), 500);
}