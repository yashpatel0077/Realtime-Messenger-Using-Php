<?php
// login.php (MERGED: original login.php + verify_otp.php)
// NOTE: Functionality kept same: login sends OTP email then shows verify form on same file,
// still uses session keys: login_email / otp_email / otp_fallback, and DB fields otp_code/otp_expires_at.

session_start();
require 'config.php';

function esc($v){
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}

/* =========================
   PHPMailer send function
   (same credentials you had)
   ========================= */
function sendRealEmail($to, $otp) {

    // ===== GMAIL SETTINGS =====
    $smtpHost  = 'smtp.gmail.com';
    $smtpEmail = 'Enter Your Mail ';     // YOUR EMAIL
    $smtpPass  = 'Set Mail Password For Send OTP';          // APP PASSWORD (NO SPACES!)
    // ==========================

    // PHPMailer must exist in: PHPMailer/src/
    if (!file_exists(__DIR__ . '/PHPMailer/src/PHPMailer.php')) {
        return false;
    }

    require_once __DIR__ . '/PHPMailer/src/Exception.php';
    require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
    require_once __DIR__ . '/PHPMailer/src/SMTP.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->SMTPAuth = true;
        $mail->Username = $smtpEmail;
        $mail->Password = $smtpPass;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom($smtpEmail, 'Messenger');
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = 'Your Messenger Login Code';
        $mail->Body = "
            <div style='font-family:Arial,sans-serif;line-height:1.6'>
                <h2>Your login code</h2>
                <p style='font-size:16px'>Use this OTP to login:</p>
                <div style='font-size:32px;font-weight:700;letter-spacing:6px'>$otp</div>
                <p>Valid for <b>10 minutes</b>.</p>
                <p>If you didn't request this, ignore this email.</p>
            </div>
        ";

        return $mail->send();
    } catch (Exception $e) {
        return false;
    }
}

/* =========================
   Determine mode (login/verify)
   ========================= */
$email = $_SESSION['login_email'] ?? $_SESSION['otp_email'] ?? '';
$mode = $email ? 'verify' : 'login';

$error = '';
$success = '';

/* =========================
   LOGIN: send OTP
   ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_otp') {

    $email = trim($_POST['email'] ?? '');

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Enter a valid email address.";
        $mode = 'login';
    } else {

        // keep same behavior (store sessions)
        $_SESSION['login_email'] = $email;

        // Find existing user
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        if (!$stmt) {
            $error = "Database error. Please try again.";
            $mode = 'login';
        } else {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res->fetch_assoc();
            $stmt->close();

            if (!$row) {
                // Create new user (same as your file)
                $displayName = strtok($email, '@');
                $ins = $conn->prepare("INSERT INTO users (email, display_name) VALUES (?, ?)");
                if (!$ins) {
                    $error = "Database error. Please try again.";
                    $mode = 'login';
                } else {
                    $ins->bind_param("ss", $email, $displayName);
                    $ins->execute();
                    $userId = (int)$ins->insert_id;
                    $ins->close();
                }
            } else {
                $userId = (int)$row['id'];
            }

            if (!$error) {
                // Generate OTP and store in DB
                $otp = sprintf("%06d", random_int(0, 999999));
                $expiresAt = date('Y-m-d H:i:s', time() + 600); // 10 minutes

                $up = $conn->prepare("UPDATE users SET otp_code = ?, otp_expires_at = ? WHERE id = ?");
                if (!$up) {
                    $error = "Database error. Please try again.";
                    $mode = 'login';
                } else {
                    $up->bind_param("ssi", $otp, $expiresAt, $userId);
                    $up->execute();
                    $up->close();

                    $_SESSION['otp_email'] = $email;

                    // Send real email
                    if (sendRealEmail($email, $otp)) {
                        $success = "OTP sent to your email! Check inbox/spam.";
                        // keep verify on same page
                        $mode = 'verify';
                    } else {
                        // Fallback for testing (same as your code intent)
                        $_SESSION['otp_fallback'] = $otp;
                        $success = "Email failed - Testing OTP: $otp";
                        $mode = 'verify';
                    }
                }
            }
        }
    }
}

/* =========================
   VERIFY OTP (from verify_otp.php)
   ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_otp') {

    $email = $_SESSION['login_email'] ?? $_SESSION['otp_email'] ?? '';
    if (!$email) {
        header("Location: login.php");
        exit;
    }

    $otp = trim($_POST['otp'] ?? '');

    $stmt = $conn->prepare("SELECT id, otp_code, otp_expires_at FROM users WHERE email = ? LIMIT 1");
    if (!$stmt) {
        $error = "Database error. Please try again.";
        $mode = 'verify';
    } else {
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $res = $stmt->get_result();
        $user = $res->fetch_assoc();
        $stmt->close();

        if (!$user) {
            $error = "Account not found.";
            $mode = 'login';
        } else {
            $dbOtp = $user['otp_code'] ?? '';
            $exp = $user['otp_expires_at'] ?? null;

            if (empty($otp) || $otp !== $dbOtp || (strtotime($exp) < time())) {
                $error = "Invalid or expired OTP. Please request a new code.";
                $mode = 'verify';
            } else {
                $userId = (int)$user['id'];

                // Generate avatar (same as verify_otp.php)
                $username = strtok($email, '@');
                $avatar = "https://ui-avatars.com/api/?name=" . urlencode($username) . "&size=128&background=4285f4&color=fff";

                // Update avatar
                $stmt = $conn->prepare("UPDATE users SET avatar = ? WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param("si", $avatar, $userId);
                    $stmt->execute();
                    $stmt->close();
                }

                // LOGIN SUCCESS - Set session (same as verify_otp.php)
                $_SESSION['user_id'] = $userId;
                $_SESSION['username'] = $username;
                $_SESSION['email'] = $email;

                // Clear OTP
                $up = $conn->prepare("UPDATE users SET otp_code = NULL, otp_expires_at = NULL WHERE id = ?");
                if ($up) {
                    $up->bind_param("i", $userId);
                    $up->execute();
                    $up->close();
                }

                // Clear temp sessions
                unset($_SESSION['login_email'], $_SESSION['otp_email'], $_SESSION['otp_fallback']);

                header("Location: index.php");
                exit;
            }
        }
    }
}

/* =========================
   Resend action (kept same link structure)
   ========================= */
if (isset($_GET['resend']) && $_GET['resend'] == '1') {
    // just clear otp and go back to login to send again
    unset($_SESSION['otp_email']);
    header("Location: login.php");
    exit;
}

$fallbackOtp = $_SESSION['otp_fallback'] ?? null;

// Refresh email variable for UI
$email = $_SESSION['login_email'] ?? $_SESSION['otp_email'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messenger Login</title>
    <link rel="stylesheet" href="otp_verify.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
     <style>
        *{box-sizing:border-box;margin:0;padding:0}
        body{font-family:system-ui,Arial;background:#f0f2f5;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
        .wrap{width:100%;max-width:420px}
        .card{background:#fff;border-radius:16px;box-shadow:0 20px 40px rgba(0,0,0,.08);padding:40px 32px;text-align:center;border:1px solid #e9edef}
        .logo{width:64px;height:64px;background:#25D366;border-radius:50%;margin:0 auto 24px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:28px;font-weight:700;box-shadow:0 8px 24px rgba(37,211,102,.3)}
        .brand{font-size:28px;font-weight:700;margin:0 0 8px;color:#111b21}
        .sub{margin:0 0 28px;color:#667781;font-size:15px;line-height:1.4}
        input{width:100%;padding:16px;border:1px solid #e9edef;border-radius:12px;font-size:16px;outline:0;background:#fff;transition:all .2s;margin-bottom:16px;box-shadow:0 1px 3px rgba(0,0,0,.05)}
        input:focus{border-color:#25D366;box-shadow:0 0 0 3px rgba(37,211,102,.1)}
        button{width:100%;padding:16px;border:0;border-radius:12px;background:#25D366;color:#fff;font-weight:600;font-size:16px;cursor:pointer;transition:all .2s}
        button:hover{background:#128C7E;transform:translateY(-1px)}
        .msg{margin:16px 0;padding:14px;border-radius:10px;font-size:14px}
        .err{color:#d93025;background:#ffebee;border:1px solid #f2c7c7}
        .success{color:#25D366;background:#e8f8f5;border:1px solid #c6f6e8}
        .links-row{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  margin-top:14px;
}

.link-btn{
  display:inline-flex;
  align-items:center;
  gap:10px;
  text-decoration:none;
  font-size:14px;
  font-weight:600;
  padding:10px 12px;
  border-radius:10px;
  color:#3b4a54;
  background:#f0f2f5;
  border:1px solid #e9edef;
  transition:background .15s ease, transform .15s ease, border-color .15s ease;
  user-select:none;
}

.link-btn:hover{
  background:#e9edef;
  transform:translateY(-1px);
  border-color:#dde3e7;
}

.link-btn--resend{
  color:#0b846d;
  background:#e8f8f5;
  border-color:#c6f6e8;
}

.link-btn--resend:hover{
  background:#dff6f2;
}

.link-ic{
  font-size:15px;
}

.link-btn--resend:hover .link-ic{
  animation: linkSpin .6s linear;
}

@keyframes linkSpin{
  from { transform: rotate(0deg); }
  to   { transform: rotate(360deg); }
}

.link-btn--back{
  background:transparent;
  border-color:transparent;
  padding:10px 6px;
}

.link-btn--back:hover{
  background:transparent;
  transform:none;
  text-decoration:underline;
}

.link-arrow{
  font-size:16px;
  line-height:1;
}

    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
<div class="logo"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i></div>

        <?php if ($mode === 'verify' && $email): ?>
            <h1 class="brand">Enter Code</h1>
            <p class="sub">We sent a 6-digit code to:<br><strong><?php echo esc($email); ?></strong></p>
        <?php else: ?>
            <h1 class="brand">Login</h1>
            <p class="sub">Enter your email to receive a 6-digit login code.</p>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="msg err"><?php echo esc($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="msg success"><?php echo esc($success); ?></div>
        <?php endif; ?>

        <?php if ($fallbackOtp && $mode === 'verify'): ?>
            <div class="msg success">
                <strong>Testing OTP:</strong> <?php echo esc($fallbackOtp); ?>
            </div>
        <?php endif; ?>

        <?php if ($mode === 'verify' && $email): ?>
            <form method="post" autocomplete="off">
                <input type="hidden" name="action" value="verify_otp">
                <input type="text" name="otp" placeholder="000000"
                       inputmode="numeric" maxlength="6"
                       autocomplete="one-time-code" required autofocus
                       class="otp-input">
                <button type="submit" class="btn">Continue to Messenger</button>
            </form>

            <div class="links links-row">
  <a href="login.php?resend=1" class="link-btn link-btn--resend">
    <i class="fa-solid fa-rotate-right link-ic"></i>
    <span>Resend code</span>
  </a>

  <a href="login.php" class="link-btn link-btn--back">
    <span class="link-arrow">←</span>
    <span>Back</span>
  </a>
</div>

        <?php else: ?>
            <form method="post" autocomplete="off">
                <input type="hidden" name="action" value="send_otp">
                <input type="email" name="email" placeholder="Enter your email" required class="text-input">
                <button type="submit" class="btn">Send OTP</button>
            </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>

