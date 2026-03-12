<?php
session_start();
require 'config.php';

function esc($v){ 
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); 
}

$email = $_SESSION['login_email'] ?? $_SESSION['otp_email'] ?? '';
if (!$email) {
    header("Location: login.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $otp = trim($_POST['otp'] ?? '');

    // Verify OTP with proper error checking
    $stmt = $conn->prepare("SELECT id, otp_code, otp_expires_at FROM users WHERE email = ? LIMIT 1");
    if (!$stmt) {
        $error = "Database error. Please try again.";
    } else {
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $res = $stmt->get_result();
        $user = $res->fetch_assoc();
        $stmt->close();

        if (!$user) {
            $error = "Account not found.";
        } else {
            $dbOtp = $user['otp_code'] ?? '';
            $exp = $user['otp_expires_at'] ?? null;

            if (empty($otp) || $otp !== $dbOtp || (strtotime($exp) < time())) {
                $error = "Invalid or expired OTP. Please request a new code.";
            } else {
                $userId = (int)$user['id'];

                // Generate avatar
                $username = strtok($email, '@');
                $avatar = "https://ui-avatars.com/api/?name=" . urlencode($username) . "&size=128&background=4285f4&color=fff";

                // Update avatar
                $stmt = $conn->prepare("UPDATE users SET avatar = ? WHERE id = ?");
                $stmt->bind_param("si", $avatar, $userId);
                $stmt->execute();
                $stmt->close();

                // LOGIN SUCCESS - Set session
                $_SESSION['user_id'] = $userId;
                $_SESSION['username'] = $username;
                $_SESSION['email'] = $email;

                // Clear OTP
                $up = $conn->prepare("UPDATE users SET otp_code = NULL, otp_expires_at = NULL WHERE id = ?");
                $up->bind_param("i", $userId);
                $up->execute();
                $up->close();

                // Clear temp sessions
                unset($_SESSION['login_email'], $_SESSION['otp_email'], $_SESSION['otp_fallback']);

                header("Location: index.php"); // Your main app page
                exit;
            }
        }
    }
}

// Check if fallback OTP exists (for testing)
$fallbackOtp = $_SESSION['otp_fallback'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify OTP - Messenger</title>
    <link rel="stylesheet" href="verify_otp.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body>
    <div class="wrap">
        <div class="card">
            <div class="logo"><i class="fas fa-shield-check"></i></div>
            <h1 class="brand">Enter Code</h1>
            <p class="sub">We sent a 6-digit code to:<br><strong><?php echo esc($email); ?></strong></p>
            
            <?php if($error): ?>
                <div class="msg err"><?php echo esc($error); ?></div>
            <?php endif; ?>

            <?php if($fallbackOtp): ?>
                <div class="msg success">
                    🔑 <strong>Testing OTP:</strong> <?php echo esc($fallbackOtp); ?>
                </div>
            <?php endif; ?>

            <form method="post" autocomplete="off">
                <input 
                    type="text" 
                    name="otp" 
                    placeholder="000000"
                    inputmode="numeric" 
                    maxlength="6" 
                    autocomplete="one-time-code" 
                    required 
                    autofocus
                    style="font-size: 24px; font-weight: 600; letter-spacing: 8px; text-align: center;"
                >
                <button type="submit">Continue to Messenger</button>
            </form>

            <div style="margin-top: 24px;">
                <a href="send_otp.php?resend=1" class="btn" style="background: #65676b; font-size: 14px; padding: 12px;">
                    <i class="fas fa-redo"></i> Resend Code
                </a>
                <p style="margin-top: 16px;">
                    <a href="login.php" style="color: #667781; font-size: 14px;">← Back to Login</a>
                </p>
            </div>
        </div>
    </div>
</body>
</html>
