-- ═══════════════════════════════════════════════════════════════════════
-- HDPE Library System — Database Migrations (Phase 2 → 4)
-- Chạy file này trên database library_db
-- ═══════════════════════════════════════════════════════════════════════

USE library_db;

-- ───────────────────────────────────────────────
-- PHASE 2.1 — User Profile Extensions
-- ───────────────────────────────────────────────
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS nickname       VARCHAR(100)  DEFAULT NULL AFTER full_name,
    ADD COLUMN IF NOT EXISTS date_of_birth  DATE          DEFAULT NULL AFTER nickname,
    ADD COLUMN IF NOT EXISTS avatar_url     VARCHAR(255)  DEFAULT NULL AFTER date_of_birth,
    ADD COLUMN IF NOT EXISTS account_status ENUM('active','locked') NOT NULL DEFAULT 'active' AFTER avatar_url,
    ADD COLUMN IF NOT EXISTS lock_reason    VARCHAR(255)  DEFAULT NULL AFTER account_status,
    ADD COLUMN IF NOT EXISTS locked_at      DATETIME      DEFAULT NULL AFTER lock_reason,
    ADD COLUMN IF NOT EXISTS phone          VARCHAR(20)   DEFAULT NULL AFTER locked_at;

-- Thêm index nếu chưa có
ALTER TABLE users ADD INDEX IF NOT EXISTS idx_account_status (account_status);

-- ───────────────────────────────────────────────
-- PHASE 2.2 — Book Reviews / Feedback
-- ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS book_reviews (
    review_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    book_id     INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    rating      TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT '1-5 sao',
    comment     TEXT DEFAULT NULL,
    is_hidden   TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Admin an review vi pham',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_review_book FOREIGN KEY (book_id) REFERENCES books(book_id) ON DELETE CASCADE,
    CONSTRAINT fk_review_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT uq_review_book_user UNIQUE (book_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────
-- PHASE 3.2 — Extended Book Info + Import Log
-- ───────────────────────────────────────────────
ALTER TABLE books
    ADD COLUMN IF NOT EXISTS description     TEXT          DEFAULT NULL AFTER category,
    ADD COLUMN IF NOT EXISTS publisher       VARCHAR(255)  DEFAULT NULL AFTER description,
    ADD COLUMN IF NOT EXISTS published_year  YEAR          DEFAULT NULL AFTER publisher,
    ADD COLUMN IF NOT EXISTS import_date     DATE          DEFAULT NULL AFTER published_year,
    ADD COLUMN IF NOT EXISTS cover_image_url VARCHAR(255)  DEFAULT NULL AFTER import_date;

CREATE TABLE IF NOT EXISTS book_imports (
    import_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    book_id     INT UNSIGNED NOT NULL,
    quantity    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    import_date DATE NOT NULL,
    note        VARCHAR(500) DEFAULT NULL,
    imported_by INT UNSIGNED NOT NULL COMMENT 'admin user_id',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_import_book  FOREIGN KEY (book_id)     REFERENCES books(book_id) ON DELETE CASCADE,
    CONSTRAINT fk_import_admin FOREIGN KEY (imported_by) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────
-- PHASE 4.1 — Announcements / News Board
-- ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS announcements (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title       VARCHAR(255) NOT NULL,
    content     TEXT NOT NULL,
    image_url   VARCHAR(255) DEFAULT NULL,
    type        ENUM('event','contest','holiday','general') NOT NULL DEFAULT 'general',
    start_date  DATE DEFAULT NULL,
    end_date    DATE DEFAULT NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_by  INT UNSIGNED NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_announce_admin FOREIGN KEY (created_by) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────
-- PHASE 4.2 — Support Tickets
-- ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS support_tickets (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    category    ENUM('lost_item','damaged_book','card_issue','other') NOT NULL DEFAULT 'other',
    title       VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    status      ENUM('open','in_progress','closed') NOT NULL DEFAULT 'open',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ticket_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_messages (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id   INT UNSIGNED NOT NULL,
    sender_id   INT UNSIGNED NOT NULL,
    sender_role ENUM('user','admin') NOT NULL,
    message     TEXT NOT NULL,
    sent_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_msg_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_msg_sender FOREIGN KEY (sender_id) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────
-- PHASE 4.3 — Chatbot Logs
-- ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS chat_logs (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED DEFAULT NULL COMMENT 'NULL = khach vang lai',
    message    TEXT NOT NULL,
    response   TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_chat_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────
-- Sample Announcements data
-- ───────────────────────────────────────────────
INSERT INTO announcements (title, content, type, start_date, end_date, is_active, created_by)
SELECT 'Thư viện mở cửa xuyên hè 2026', 'Thư viện HDPE sẽ phục vụ bạn đọc xuyên suốt mùa hè từ 7:30 đến 21:00 các ngày trong tuần.', 'general', '2026-06-01', '2026-08-31', 1, user_id
FROM users WHERE role = 'admin' LIMIT 1;

INSERT INTO announcements (title, content, type, start_date, end_date, is_active, created_by)
SELECT 'Cuộc thi đọc sách tháng 6', 'Tham gia cuộc thi đọc sách tháng 6 để nhận nhiều phần thưởng hấp dẫn! Đăng ký tại quầy thư viện.', 'contest', '2026-06-01', '2026-06-30', 1, user_id
FROM users WHERE role = 'admin' LIMIT 1;
