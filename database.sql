-- ═══════════════════════════════════════════════════════════════════════
-- HCMUE Library System — Complete Database Schema (Structure only)
-- Chạy file này để khởi tạo cấu trúc cơ sở dữ liệu hoàn chỉnh.
-- ═══════════════════════════════════════════════════════════════════════

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE DATABASE IF NOT EXISTS library_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE library_db;

-- ── DỌN DẸP BẢNG CŨ (NẾU CÓ) ──────────────────────────────────────────
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS system_settings;
DROP TABLE IF EXISTS book_categories;
DROP TABLE IF EXISTS public_chats;
DROP TABLE IF EXISTS notifications;
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
    google_id      VARCHAR(255) DEFAULT NULL UNIQUE COMMENT 'Google OAuth ID',
    is_approved    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '0=chờ duyệt, 1=đã duyệt',
    nickname       VARCHAR(100) DEFAULT NULL,
    date_of_birth  DATE         DEFAULT NULL,
    avatar_url     VARCHAR(255) DEFAULT NULL,
    account_status ENUM('active','locked') NOT NULL DEFAULT 'active',
    lock_reason    VARCHAR(255) DEFAULT NULL,
    locked_at      DATETIME     DEFAULT NULL,
    locked_until   DATE         DEFAULT NULL COMMENT 'NULL = khóa vĩnh viễn, DATE = khóa tạm thời',
    phone          VARCHAR(20)  DEFAULT NULL,
    borrow_limit   TINYINT UNSIGNED NOT NULL DEFAULT 5,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_account_status (account_status),
    INDEX idx_google_id (google_id)
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
    import_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    book_id        INT UNSIGNED DEFAULT NULL          COMMENT 'FK to books.book_id, set after sync',
    invoice_code   VARCHAR(50)  NOT NULL              COMMENT 'Mã hóa đơn (auto-generated if blank)',
    title          VARCHAR(255) NOT NULL              COMMENT 'Tên sách nhập',
    author         VARCHAR(150) NOT NULL DEFAULT 'Khác',
    isbn           VARCHAR(30)  DEFAULT NULL,
    category       VARCHAR(100) NOT NULL DEFAULT 'Khác',
    publisher      VARCHAR(255) DEFAULT NULL,
    published_year YEAR         DEFAULT NULL,
    quantity       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    import_type    ENUM('purchase','donation','other') NOT NULL DEFAULT 'purchase',
    invoice_url    VARCHAR(500) DEFAULT NULL,
    price          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    note           TEXT         DEFAULT NULL,
    imported_by    INT UNSIGNED NOT NULL              COMMENT 'admin user_id',
    status         ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved',
    import_date    DATE         DEFAULT NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_import_book  FOREIGN KEY (book_id)     REFERENCES books(book_id) ON DELETE SET NULL,
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

-- ───────────────────────────────────────────────
-- 10. BẢNG notifications (Thông báo hệ thống)
-- ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS notifications (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED DEFAULT NULL,
    sender_id  INT UNSIGNED DEFAULT NULL,
    title      VARCHAR(255) NOT NULL,
    message    TEXT NOT NULL,
    type       ENUM('system','ticket','borrow_alert','general','ticket_answered','borrow','borrow_approved') NOT NULL DEFAULT 'system',
    is_read    TINYINT(1) NOT NULL DEFAULT 0,
    related_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notification_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────
-- 10.1. BẢNG user_notifications_read (Vết đọc thông báo chung)
-- ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS user_notifications_read (
    user_id         INT UNSIGNED NOT NULL,
    notification_id INT UNSIGNED NOT NULL,
    read_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, notification_id),
    CONSTRAINT fk_read_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_read_noti FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────
-- 10.2. BẢNG user_notifications_hidden (Vết ẩn thông báo chung)
-- ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS user_notifications_hidden (
    user_id         INT UNSIGNED NOT NULL,
    notification_id INT UNSIGNED NOT NULL,
    hidden_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, notification_id),
    CONSTRAINT fk_hidden_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_hidden_noti FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────
-- 10.3. BẢNG ai_responses_cache (Bộ nhớ đệm phản hồi AI)
-- ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ai_responses_cache (
    prompt_hash     CHAR(64) PRIMARY KEY,
    prompt_text     TEXT NOT NULL,
    response_text   TEXT NOT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────
-- 11. BẢNG public_chats (Kênh thảo luận công khai)
-- ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS public_chats (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    message    VARCHAR(1000) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    is_pinned  TINYINT(1) NOT NULL DEFAULT 0,
    reactions  VARCHAR(1000) DEFAULT NULL,
    INDEX idx_created_at (created_at),
    CONSTRAINT fk_chat_user_public FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────
-- 12. BẢNG book_categories (Danh mục thể loại sách)
-- ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS book_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO book_categories (name) VALUES 
('Công nghệ thông tin'),
('Văn học nước ngoài'),
('Kỹ năng học tập'),
('Tâm lý / Sức khỏe'),
('Kinh tế / Kinh doanh'),
('Khoa học'),
('Kỹ năng sống'),
('Văn học Việt Nam'),
('Triết học'),
('Tiểu thuyết'),
('Thiếu nhi'),
('Sức khỏe'),
('Lịch sử'),
('Tôn giáo / Tâm linh'),
('Ngoại ngữ'),
('Y học'),
('Xã hội học'),
('Công nghệ'),
('Ẩm thực'),
('Toán học'),
('Địa lý'),
('Khác');

-- ───────────────────────────────────────────────
-- 13. BẢNG system_settings (Cài đặt hệ thống)
-- ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO system_settings (setting_key, setting_value) VALUES
('maintenance_mode', '0'),
('maintenance_until', NULL);

-- ───────────────────────────────────────────────
-- 14. BẢNG penalty_logs (Lịch sử xử phạt sinh viên)
-- ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS penalty_logs (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL COMMENT 'Sinh viên bị phạt',
    admin_id    INT UNSIGNED DEFAULT NULL COMMENT 'Admin thực hiện (NULL = hệ thống tự động)',
    reason      VARCHAR(500) NOT NULL,
    locked_until DATE        DEFAULT NULL COMMENT 'NULL = khóa vĩnh viễn',
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_penalty_user  FOREIGN KEY (user_id)  REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_penalty_admin FOREIGN KEY (admin_id) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_penalty_user (user_id),
    INDEX idx_penalty_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

