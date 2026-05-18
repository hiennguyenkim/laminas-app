-- ═══════════════════════════════════════════════════════════════════════
-- HCMUE Library System — Complete Database Schema (Structure only)
-- Chạy file này để khởi tạo cấu trúc cơ sở dữ liệu hoàn chỉnh.
-- ═══════════════════════════════════════════════════════════════════════

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE DATABASE IF NOT EXISTS library_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE library_db;

-- ── DỌN DẸP BẢNG CŨ (NẾU CÓ) ──────────────────────────────────────────
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS chat_logs;
DROP TABLE IF EXISTS ticket_messages;
DROP TABLE IF EXISTS support_tickets;
DROP TABLE IF EXISTS announcements;
DROP TABLE IF EXISTS book_imports;
DROP TABLE IF EXISTS book_reviews;
DROP TABLE IF EXISTS borrow_records;
DROP TABLE IF EXISTS books;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS = 1;

-- ───────────────────────────────────────────────
-- 1. BẢNG users (Thành viên / Quản lý)
-- ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    user_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username       VARCHAR(50)  NOT NULL UNIQUE,
    email          VARCHAR(100) NOT NULL UNIQUE,
    password       VARCHAR(255) NOT NULL,
    full_name      VARCHAR(100) NOT NULL,
    role           ENUM('admin','student') NOT NULL DEFAULT 'student',
    nickname       VARCHAR(100) DEFAULT NULL,
    date_of_birth  DATE         DEFAULT NULL,
    avatar_url     VARCHAR(255) DEFAULT NULL,
    account_status ENUM('active','locked') NOT NULL DEFAULT 'active',
    lock_reason    VARCHAR(255) DEFAULT NULL,
    locked_at      DATETIME     DEFAULT NULL,
    phone          VARCHAR(20)  DEFAULT NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_account_status (account_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────
-- 2. BẢNG books (Danh mục sách)
-- ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS books (
    book_id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title           VARCHAR(255) NOT NULL,
    author          VARCHAR(150) NOT NULL,
    isbn            VARCHAR(30)  DEFAULT NULL,
    category        VARCHAR(100) NOT NULL DEFAULT 'Khác',
    description     TEXT         DEFAULT NULL,
    publisher       VARCHAR(255) DEFAULT NULL,
    published_year  YEAR         DEFAULT NULL,
    import_date     DATE         DEFAULT NULL,
    cover_image_url VARCHAR(255) DEFAULT NULL,
    quantity        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    status          ENUM('available','borrowed','unavailable') NOT NULL DEFAULT 'available',
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────
-- 3. BẢNG borrow_records (Giao dịch mượn/trả sách)
-- ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS borrow_records (
    borrow_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    book_id     INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    borrow_date DATE         NOT NULL,
    return_date DATE         DEFAULT NULL,
    status      ENUM('pending','borrowed','returned','overdue') NOT NULL DEFAULT 'pending',
    returned_at DATETIME     DEFAULT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_borrow_book FOREIGN KEY (book_id) REFERENCES books(book_id) ON DELETE CASCADE,
    CONSTRAINT fk_borrow_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────
-- 4. BẢNG book_reviews (Đánh giá & Bình luận sách)
-- ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS book_reviews (
    review_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    book_id     INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    rating      TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT '1-5 sao',
    comment     TEXT         DEFAULT NULL,
    is_hidden   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Admin ẩn review vi phạm',
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_review_book FOREIGN KEY (book_id) REFERENCES books(book_id) ON DELETE CASCADE,
    CONSTRAINT fk_review_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT uq_review_book_user UNIQUE (book_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────
-- 5. BẢNG book_imports (Nhập kho sách)
-- ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS book_imports (
    import_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    book_id     INT UNSIGNED NOT NULL,
    quantity    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    import_date DATE         NOT NULL,
    note        VARCHAR(500) DEFAULT NULL,
    imported_by INT UNSIGNED NOT NULL COMMENT 'admin user_id',
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_import_book  FOREIGN KEY (book_id)     REFERENCES books(book_id) ON DELETE CASCADE,
    CONSTRAINT fk_import_admin FOREIGN KEY (imported_by) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────
-- 6. BẢNG announcements (Bảng tin thông báo)
-- ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS announcements (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title       VARCHAR(255) NOT NULL,
    content     TEXT         NOT NULL,
    image_url   VARCHAR(255) DEFAULT NULL,
    type        ENUM('event','contest','holiday','general') NOT NULL DEFAULT 'general',
    start_date  DATE         DEFAULT NULL,
    end_date    DATE         DEFAULT NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_by  INT UNSIGNED NOT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_announce_admin FOREIGN KEY (created_by) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────
-- 7. BẢNG support_tickets (Yêu cầu hỗ trợ)
-- ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS support_tickets (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    category    ENUM('lost_item','damaged_book','card_issue','other') NOT NULL DEFAULT 'other',
    title       VARCHAR(255) NOT NULL,
    description TEXT         NOT NULL,
    status      ENUM('open','in_progress','closed') NOT NULL DEFAULT 'open',
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ticket_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────
-- 8. BẢNG ticket_messages (Chi tiết tin nhắn hỗ trợ)
-- ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ticket_messages (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id   INT UNSIGNED NOT NULL,
    sender_id   INT UNSIGNED NOT NULL,
    sender_role ENUM('user','admin') NOT NULL,
    message     TEXT         NOT NULL,
    sent_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_msg_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_msg_sender FOREIGN KEY (sender_id) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────
-- 9. BẢNG chat_logs (Lịch sử hội thoại chatbot)
-- ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS chat_logs (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED DEFAULT NULL COMMENT 'NULL = khách vãng lai',
    message    TEXT     NOT NULL,
    response   TEXT     NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_chat_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
