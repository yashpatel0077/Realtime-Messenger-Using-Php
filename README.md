# PHP Messenger

A full-featured messenger application built with **PHP, MySQL, JavaScript, AJAX, HTML, and CSS**.

It supports:

- One-to-one chat
- Group chat
- User search
- Unread messages
- Pin / unpin chat
- Hide / unhide chat
- File attachments
- Profile popup
- Group members popup
- Optional PHPMailer integration for email features like OTP, verification, and password reset

---

## Features

- One-to-one private messaging
- Group chat creation and messaging
- AJAX-based sending and loading
- User search to start new conversations
- Pin chat / unpin chat
- Hide / unhide chats and groups
- Unread message badge
- Profile popup and group members popup
- Responsive chat UI
- Attachment support:
  - Images
  - Audio
  - PDF
  - TXT
  - HTML
  - CSS
  - JS
  - JSON
  - XML
  - Markdown
- Attachment size limit: **10 MB**
- Optional email sending with PHPMailer

---

## Tech Stack

- **Frontend:** HTML, CSS, JavaScript
- **Backend:** PHP
- **Database:** MySQL
- **Async Requests:** AJAX / Fetch / XMLHttpRequest
- **Server:** Apache / XAMPP
- **Mail Library:** PHPMailer (optional)

---

## Project Structure

```bash
php-messenger/
│
├── index.php
├── login.php
├── logout.php
├── config.php
├── send_message.php
├── send_group_message.php
├── get_messages.php
├── chat_action.php
├── check_username.php
├── set_profile.php
├── verify_otp.php
├── settings.php
├── style.css
├── verify_otp.css
├── mail_config.php
├── uploads/
│   └── chat/
├── PHPMailer/
│   └── src/
│       ├── Exception.php
│       ├── PHPMailer.php
│       └── SMTP.php
└── README.md
