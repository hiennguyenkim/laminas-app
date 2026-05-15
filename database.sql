SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE DATABASE IF NOT EXISTS library_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE library_db;

-- -------------------------------------------------------
-- 1. Bảng users
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    user_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username    VARCHAR(50)  NOT NULL UNIQUE,
    email       VARCHAR(100) NOT NULL UNIQUE,
    password    VARCHAR(255) NOT NULL,
    full_name   VARCHAR(100) NOT NULL,
    role        ENUM('admin','student') NOT NULL DEFAULT 'student',
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- 2. Bảng books
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS books (
    book_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title       VARCHAR(255) NOT NULL,
    author      VARCHAR(150) NOT NULL,
    isbn        VARCHAR(30)  DEFAULT NULL,
    category    VARCHAR(100) NOT NULL DEFAULT 'Khác',
    quantity    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    status      ENUM('available','borrowed','unavailable') NOT NULL DEFAULT 'available',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- 3. Bảng borrow_records
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS borrow_records (
    borrow_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    book_id     INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    borrow_date DATE         NOT NULL,
    return_date DATE         DEFAULT NULL,
    status      ENUM('borrowed','returned','overdue') NOT NULL DEFAULT 'borrowed',
    returned_at DATETIME     DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_borrow_book FOREIGN KEY (book_id) REFERENCES books(book_id)  ON DELETE CASCADE,
    CONSTRAINT fk_borrow_user FOREIGN KEY (user_id) REFERENCES users(user_id)  ON DELETE CASCADE
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'borrow_records'
      AND COLUMN_NAME = 'returned_at'
);
SET @ddl := IF(
    @col_exists = 0,
    'ALTER TABLE borrow_records ADD COLUMN returned_at DATETIME DEFAULT NULL AFTER status',
    'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


SET FOREIGN_KEY_CHECKS = 0;

DELETE FROM borrow_records;
DELETE FROM books;
DELETE FROM users;

ALTER TABLE borrow_records AUTO_INCREMENT = 1;
ALTER TABLE books AUTO_INCREMENT = 1;
ALTER TABLE users AUTO_INCREMENT = 1;

SET FOREIGN_KEY_CHECKS = 1;

-- Tài khoản mặc định:
-- admin/admin role: admin / Admin@123
-- student role: student1..student10 / Admin@123
INSERT INTO users (username, email, password, full_name, role) VALUES
('admin',   'admin@library.local',    '$2y$10$yFpGq4323AOg24smPvUYN.w25VXTBBYjhBMboCAaCfZwrbcvwSQja', 'Quản trị viên', 'admin'),
('student1','student1@library.local', '$2y$10$yFpGq4323AOg24smPvUYN.w25VXTBBYjhBMboCAaCfZwrbcvwSQja', 'Nguyễn Văn An',  'student'),
('student2','student2@library.local', '$2y$10$yFpGq4323AOg24smPvUYN.w25VXTBBYjhBMboCAaCfZwrbcvwSQja', 'Trần Thị Bình',  'student'),
('student3','student3@library.local', '$2y$10$yFpGq4323AOg24smPvUYN.w25VXTBBYjhBMboCAaCfZwrbcvwSQja', 'Lê Minh Cường',  'student'),
('student4','student4@library.local', '$2y$10$yFpGq4323AOg24smPvUYN.w25VXTBBYjhBMboCAaCfZwrbcvwSQja', 'Phạm Thu Dung',  'student'),
('student5','student5@library.local', '$2y$10$yFpGq4323AOg24smPvUYN.w25VXTBBYjhBMboCAaCfZwrbcvwSQja', 'Hoàng Gia Huy',  'student'),
('student6','student6@library.local', '$2y$10$yFpGq4323AOg24smPvUYN.w25VXTBBYjhBMboCAaCfZwrbcvwSQja', 'Đỗ Ngọc Khánh',  'student'),
('student7','student7@library.local', '$2y$10$yFpGq4323AOg24smPvUYN.w25VXTBBYjhBMboCAaCfZwrbcvwSQja', 'Vũ Hải Long',    'student'),
('student8','student8@library.local', '$2y$10$yFpGq4323AOg24smPvUYN.w25VXTBBYjhBMboCAaCfZwrbcvwSQja', 'Bùi Phương Mai', 'student'),
('student9','student9@library.local', '$2y$10$yFpGq4323AOg24smPvUYN.w25VXTBBYjhBMboCAaCfZwrbcvwSQja', 'Trịnh Đức Nam',  'student'),
('student10','student10@library.local', '$2y$10$yFpGq4323AOg24smPvUYN.w25VXTBBYjhBMboCAaCfZwrbcvwSQja', 'Ngô Quỳnh Như', 'student');

INSERT INTO books (title, author, isbn, category, quantity, status) VALUES
('Dế Mèn phiêu lưu ký', 'Tô Hoài', NULL, 'Thiếu nhi', 8, 'available'),
('Vợ chồng A Phủ', 'Tô Hoài', NULL, 'Văn học Việt Nam', 4, 'available'),
('Truyện Tây Bắc', 'Tô Hoài', NULL, 'Văn học Việt Nam', 3, 'available'),
('Cát bụi chân ai', 'Tô Hoài', NULL, 'Hồi ký', 2, 'available'),
('Tắt đèn', 'Ngô Tất Tố', NULL, 'Văn học Việt Nam', 6, 'available'),
('Lều chõng', 'Ngô Tất Tố', NULL, 'Văn học Việt Nam', 2, 'available'),
('Việc làng', 'Ngô Tất Tố', NULL, 'Văn học Việt Nam', 1, 'unavailable'),
('Số đỏ', 'Vũ Trọng Phụng', NULL, 'Văn học Việt Nam', 5, 'available'),
('Giông tố', 'Vũ Trọng Phụng', NULL, 'Văn học Việt Nam', 2, 'available'),
('Làm đĩ', 'Vũ Trọng Phụng', NULL, 'Văn học Việt Nam', 1, 'available'),
('Chí Phèo', 'Nam Cao', NULL, 'Văn học Việt Nam', 4, 'available'),
('Lão Hạc', 'Nam Cao', NULL, 'Văn học Việt Nam', 0, 'borrowed'),
('Đời thừa', 'Nam Cao', NULL, 'Văn học Việt Nam', 2, 'available'),
('Sống mòn', 'Nam Cao', NULL, 'Văn học Việt Nam', 2, 'available'),
('Đôi mắt', 'Nam Cao', NULL, 'Văn học Việt Nam', 3, 'available'),
('Vợ nhặt', 'Kim Lân', NULL, 'Văn học Việt Nam', 4, 'available'),
('Con chó xấu xí', 'Kim Lân', NULL, 'Văn học Việt Nam', 2, 'available'),
('Đất rừng phương Nam', 'Đoàn Giỏi', NULL, 'Thiếu nhi', 5, 'available'),
('Tuổi thơ dữ dội', 'Phùng Quán', NULL, 'Thiếu nhi', 6, 'available'),
('Tuổi thơ im lặng', 'Duy Khán', NULL, 'Hồi ký', 2, 'available'),
('Nỗi buồn chiến tranh', 'Bảo Ninh', NULL, 'Tiểu thuyết', 0, 'borrowed'),
('Rừng xà nu', 'Nguyễn Trung Thành', NULL, 'Văn học Việt Nam', 3, 'available'),
('Đất nước đứng lên', 'Nguyên Ngọc', NULL, 'Tiểu thuyết', 2, 'available'),
('Bến quê', 'Nguyễn Minh Châu', NULL, 'Truyện ngắn', 3, 'available'),
('Cỏ lau', 'Nguyễn Minh Châu', NULL, 'Truyện ngắn', 2, 'available'),
('Chiếc thuyền ngoài xa', 'Nguyễn Minh Châu', NULL, 'Truyện ngắn', 3, 'available'),
('Cánh đồng bất tận', 'Nguyễn Ngọc Tư', NULL, 'Truyện ngắn', 5, 'available'),
('Gió lẻ và 9 câu chuyện khác', 'Nguyễn Ngọc Tư', NULL, 'Truyện ngắn', 2, 'available'),
('Sông', 'Nguyễn Ngọc Tư', NULL, 'Tiểu thuyết', 2, 'available'),
('Biên bản chiến tranh 1-2-3-4.75', 'Trần Mai Hạnh', NULL, 'Lịch sử', 1, 'available'),
('Thời xa vắng', 'Lê Lựu', NULL, 'Tiểu thuyết', 2, 'available'),
('Bến không chồng', 'Dương Hướng', NULL, 'Tiểu thuyết', 0, 'borrowed'),
('Mảnh đất lắm người nhiều ma', 'Nguyễn Khắc Trường', NULL, 'Tiểu thuyết', 2, 'available'),
('Mùa lá rụng trong vườn', 'Ma Văn Kháng', NULL, 'Tiểu thuyết', 3, 'available'),
('Đám cưới không có giấy giá thú', 'Ma Văn Kháng', NULL, 'Tiểu thuyết', 1, 'available'),
('Vang bóng một thời', 'Nguyễn Tuân', NULL, 'Tùy bút', 2, 'available'),
('Tùy bút Sông Đà', 'Nguyễn Tuân', NULL, 'Tùy bút', 1, 'unavailable'),
('Người lái đò sông Đà', 'Nguyễn Tuân', NULL, 'Tùy bút', 2, 'available'),
('Hai đứa trẻ', 'Thạch Lam', NULL, 'Truyện ngắn', 3, 'available'),
('Gió lạnh đầu mùa', 'Thạch Lam', NULL, 'Truyện ngắn', 4, 'available'),
('Nắng trong vườn', 'Thạch Lam', NULL, 'Truyện ngắn', 2, 'available'),
('Dưới bóng hoàng lan', 'Thạch Lam', NULL, 'Truyện ngắn', 2, 'available'),
('Những ngày thơ ấu', 'Nguyên Hồng', NULL, 'Hồi ký', 3, 'available'),
('Bỉ vỏ', 'Nguyên Hồng', NULL, 'Tiểu thuyết', 2, 'available'),
('Lá cờ thêu sáu chữ vàng', 'Nguyễn Huy Tưởng', NULL, 'Thiếu nhi', 4, 'available'),
('Sống mãi với Thủ đô', 'Nguyễn Huy Tưởng', NULL, 'Tiểu thuyết lịch sử', 2, 'available'),
('Đêm hội Long Trì', 'Nguyễn Huy Tưởng', NULL, 'Tiểu thuyết lịch sử', 1, 'available'),
('Truyện Kiều', 'Nguyễn Du', NULL, 'Cổ điển', 3, 'available'),
('Lục Vân Tiên', 'Nguyễn Đình Chiểu', NULL, 'Cổ điển', 1, 'unavailable'),
('Nhật ký trong tù', 'Hồ Chí Minh', NULL, 'Thơ', 4, 'available'),
('Cho tôi xin một vé đi tuổi thơ', 'Nguyễn Nhật Ánh', NULL, 'Thiếu nhi', 6, 'available'),
('Tôi thấy hoa vàng trên cỏ xanh', 'Nguyễn Nhật Ánh', NULL, 'Thiếu nhi', 5, 'available'),
('Mắt biếc', 'Nguyễn Nhật Ánh', NULL, 'Tiểu thuyết', 0, 'borrowed'),
('Cô gái đến từ hôm qua', 'Nguyễn Nhật Ánh', NULL, 'Tiểu thuyết', 4, 'available'),
('Ngồi khóc trên cây', 'Nguyễn Nhật Ánh', NULL, 'Tiểu thuyết', 3, 'available'),
('Bảy bước tới mùa hè', 'Nguyễn Nhật Ánh', NULL, 'Tiểu thuyết', 4, 'available'),
('Hạ đỏ', 'Nguyễn Nhật Ánh', NULL, 'Tiểu thuyết', 2, 'available'),
('Cà phê cùng Tony', 'Tony Buổi Sáng', NULL, 'Kỹ năng sống', 7, 'available'),
('Trên đường băng', 'Tony Buổi Sáng', NULL, 'Kỹ năng sống', 6, 'available'),
('Tuổi trẻ đáng giá bao nhiêu', 'Rosie Nguyễn', NULL, 'Kỹ năng sống', 6, 'available');
