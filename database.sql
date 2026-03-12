CREATE DATABASE IF NOT EXISTS chat_app
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE chat_app;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS group_messages;
DROP TABLE IF EXISTS group_chat_settings;
DROP TABLE IF EXISTS group_members;
DROP TABLE IF EXISTS groups;
DROP TABLE IF EXISTS chat_settings;
DROP TABLE IF EXISTS messages;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  display_name VARCHAR(100) NOT NULL,
  profile_name VARCHAR(100) DEFAULT NULL,
  username VARCHAR(100) DEFAULT NULL,
  email VARCHAR(150) DEFAULT NULL,
  phone VARCHAR(20) DEFAULT NULL,
  password VARCHAR(255) NOT NULL,
  avatar VARCHAR(255) DEFAULT NULL,
  profile_completed TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_phone (phone),
  KEY idx_users_display_name (display_name),
  KEY idx_users_profile_name (profile_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sender_id INT UNSIGNED NOT NULL,
  receiver_id INT UNSIGNED NOT NULL,
  message TEXT DEFAULT NULL,
  attachment_path VARCHAR(255) DEFAULT NULL,
  attachment_name VARCHAR(500) DEFAULT NULL,
  attachment_mime VARCHAR(80) DEFAULT NULL,
  attachment_size INT UNSIGNED DEFAULT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_messages_sender_receiver (sender_id, receiver_id),
  KEY idx_messages_receiver_sender (receiver_id, sender_id),
  KEY idx_messages_receiver_read (receiver_id, is_read),
  KEY idx_messages_created_at (created_at),
  CONSTRAINT fk_messages_sender
    FOREIGN KEY (sender_id) REFERENCES users(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_messages_receiver
    FOREIGN KEY (receiver_id) REFERENCES users(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE chat_settings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  other_id INT UNSIGNED NOT NULL,
  is_pinned TINYINT(1) NOT NULL DEFAULT 0,
  is_hidden TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_chat_settings_user_other (user_id, other_id),
  KEY idx_chat_settings_user_hidden (user_id, is_hidden),
  KEY idx_chat_settings_user_pinned (user_id, is_pinned),
  CONSTRAINT fk_chat_settings_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_chat_settings_other
    FOREIGN KEY (other_id) REFERENCES users(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE groups (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_groups_created_by (created_by),
  KEY idx_groups_name (name),
  CONSTRAINT fk_groups_created_by
    FOREIGN KEY (created_by) REFERENCES users(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE group_members (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  group_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  role ENUM('admin', 'member') NOT NULL DEFAULT 'member',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_group_members_group_user (group_id, user_id),
  KEY idx_group_members_user (user_id),
  CONSTRAINT fk_group_members_group
    FOREIGN KEY (group_id) REFERENCES groups(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_group_members_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE group_chat_settings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  group_id INT UNSIGNED NOT NULL,
  is_pinned TINYINT(1) NOT NULL DEFAULT 0,
  is_hidden TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_group_chat_settings_user_group (user_id, group_id),
  KEY idx_group_chat_settings_user_hidden (user_id, is_hidden),
  KEY idx_group_chat_settings_user_pinned (user_id, is_pinned),
  CONSTRAINT fk_group_chat_settings_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_group_chat_settings_group
    FOREIGN KEY (group_id) REFERENCES groups(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE group_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  group_id INT UNSIGNED NOT NULL,
  sender_id INT UNSIGNED NOT NULL,
  message TEXT DEFAULT NULL,
  attachment_path VARCHAR(255) DEFAULT NULL,
  attachment_name VARCHAR(500) DEFAULT NULL,
  attachment_mime VARCHAR(80) DEFAULT NULL,
  attachment_size INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_group_messages_group (group_id),
  KEY idx_group_messages_sender (sender_id),
  KEY idx_group_messages_group_created (group_id, created_at),
  CONSTRAINT fk_group_messages_group
    FOREIGN KEY (group_id) REFERENCES groups(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_group_messages_sender
    FOREIGN KEY (sender_id) REFERENCES users(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO users
(display_name, profile_name, username, email, phone, password, avatar, profile_completed)
VALUES
('Demo User 1', 'Demo User 1', 'demo1', 'demo1@example.com', '9000000001', '$2y$10$abcdefghijklmnopqrstuv', NULL, 1),
('Demo User 2', 'Demo User 2', 'demo2', 'demo2@example.com', '9000000002', '$2y$10$abcdefghijklmnopqrstuv', NULL, 1);

INSERT INTO groups (name, created_by)
VALUES ('Demo Group', 1);

INSERT INTO group_members (group_id, user_id, role)
VALUES
(1, 1, 'admin'),
(1, 2, 'member');
