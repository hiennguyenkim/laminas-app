-- ═══════════════════════════════════════════════════════════════════════
-- HCMUE Library System — Rich Demo/Seed Data  (v2 — khớp schema mới)
-- Chạy file này SAU KHI đã chạy database.sql để nạp dữ liệu mẫu hoàn chỉnh.
-- Mật khẩu tất cả tài khoản: Admin@123
-- ═══════════════════════════════════════════════════════════════════════

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET CHARACTER SET utf8mb4;

USE library_db;

-- ── DỌN DẸP DỮ LIỆU CŨ ───────────────────────────────────────────────
SET FOREIGN_KEY_CHECKS = 0;
DELETE FROM notifications;
DELETE FROM chat_logs;
DELETE FROM ticket_messages;
DELETE FROM support_tickets;
DELETE FROM announcements;
DELETE FROM book_imports;
DELETE FROM book_reviews;
DELETE FROM borrow_records;
DELETE FROM books;
DELETE FROM users;

ALTER TABLE notifications   AUTO_INCREMENT = 1;
ALTER TABLE chat_logs       AUTO_INCREMENT = 1;
ALTER TABLE ticket_messages AUTO_INCREMENT = 1;
ALTER TABLE support_tickets AUTO_INCREMENT = 1;
ALTER TABLE announcements   AUTO_INCREMENT = 1;
ALTER TABLE book_imports    AUTO_INCREMENT = 1;
ALTER TABLE book_reviews    AUTO_INCREMENT = 1;
ALTER TABLE borrow_records  AUTO_INCREMENT = 1;
ALTER TABLE books           AUTO_INCREMENT = 1;
ALTER TABLE users           AUTO_INCREMENT = 1;
SET FOREIGN_KEY_CHECKS = 1;

-- ───────────────────────────────────────────────
-- 1. USERS (1 admin + 10 sinh viên; mật khẩu Admin@123)
-- ───────────────────────────────────────────────
INSERT INTO users (username, email, password, full_name, role, account_status, lock_reason, locked_at, phone) VALUES
('admin',     'admin@library.local',     '$2y$10$yFpGq4323AOg24smPvUYN.w25VXTBBYjhBMboCAaCfZwrbcvwSQja', 'Quản trị viên',   'admin',   'active', NULL, NULL, '0901234567'),
('student1',  'student1@library.local',  '$2y$10$yFpGq4323AOg24smPvUYN.w25VXTBBYjhBMboCAaCfZwrbcvwSQja', 'Nguyễn Văn An',   'student', 'active', NULL, NULL, '0987654321'),
('student2',  'student2@library.local',  '$2y$10$yFpGq4323AOg24smPvUYN.w25VXTBBYjhBMboCAaCfZwrbcvwSQja', 'Trần Thị Bình',   'student', 'active', NULL, NULL, '0912345678'),
('student3',  'student3@library.local',  '$2y$10$yFpGq4323AOg24smPvUYN.w25VXTBBYjhBMboCAaCfZwrbcvwSQja', 'Lê Minh Cường',   'student', 'active', NULL, NULL, '0934567890'),
('student4',  'student4@library.local',  '$2y$10$yFpGq4323AOg24smPvUYN.w25VXTBBYjhBMboCAaCfZwrbcvwSQja', 'Phạm Thu Dung',   'student', 'active', NULL, NULL, '0976543210'),
('student5',  'student5@library.local',  '$2y$10$yFpGq4323AOg24smPvUYN.w25VXTBBYjhBMboCAaCfZwrbcvwSQja', 'Hoàng Huy',       'student', 'active', NULL, NULL, '0943210987'),
('student6',  'student6@library.local',  '$2y$10$yFpGq4323AOg24smPvUYN.w25VXTBBYjhBMboCAaCfZwrbcvwSQja', 'Đỗ Ngọc Khánh',   'student', 'active', NULL, NULL, '0956789012'),
('student7',  'student7@library.local',  '$2y$10$yFpGq4323AOg24smPvUYN.w25VXTBBYjhBMboCAaCfZwrbcvwSQja', 'Vũ Hải Long',     'student', 'locked', 'Không trả sách đúng hạn nhiều lần', '2026-05-15 14:00:00', '0967890123'),
('student8',  'student8@library.local',  '$2y$10$yFpGq4323AOg24smPvUYN.w25VXTBBYjhBMboCAaCfZwrbcvwSQja', 'Bùi Phương Mai',  'student', 'active', NULL, NULL, '0923456789'),
('student9',  'student9@library.local',  '$2y$10$yFpGq4323AOg24smPvUYN.w25VXTBBYjhBMboCAaCfZwrbcvwSQja', 'Trịnh Đức Nam',   'student', 'active', NULL, NULL, '0911223344'),
('student10', 'student10@library.local', '$2y$10$yFpGq4323AOg24smPvUYN.w25VXTBBYjhBMboCAaCfZwrbcvwSQja', 'Ngô Quỳnh Như',   'student', 'active', NULL, NULL, '0955667788');

-- ───────────────────────────────────────────────
-- 2. BOOKS (20 đầu sách đa thể loại)
-- ───────────────────────────────────────────────
INSERT INTO books (title, author, isbn, category, description, publisher, published_year, import_date, cover_image_url, quantity, status) VALUES
-- Văn học Việt Nam
('Dế Mèn phiêu lưu ký',           'Tô Hoài',          '9786042186715', 'Thiếu nhi',          'Tác phẩm văn học thiếu nhi kinh điển kể về cuộc phiêu lưu của chú Dế Mèn.',                     'NXB Kim Đồng',         2020, '2026-01-10', NULL, 8,  'available'),
('Vợ chồng A Phủ',                 'Tô Hoài',          '9786049635222', 'Văn học Việt Nam',   'Truyện ngắn phản ánh cuộc sống khổ cực của đồng bào miền núi Tây Bắc.',                          'NXB Văn Học',          2018, '2026-01-10', NULL, 4,  'available'),
('Tắt đèn',                        'Ngô Tất Tố',       '9786045887222', 'Văn học Việt Nam',   'Tác phẩm hiện thực phê phán về thân phận người nông dân nghèo trước Cách mạng.',                 'NXB Văn Học',          2017, '2026-01-15', NULL, 6,  'available'),
('Số đỏ',                          'Vũ Trọng Phụng',   '9786049692484', 'Văn học Việt Nam',   'Tác phẩm trào phúng xuất sắc nhất văn học Việt Nam hiện đại.',                                   'NXB Trẻ',              2019, '2026-01-18', NULL, 5,  'available'),
('Chí Phèo',                       'Nam Cao',           '9786046924956', 'Văn học Việt Nam',   'Truyện ngắn kiệt tác thể hiện bi kịch tha hóa của Chí Phèo.',                                   'NXB Văn Học',          2016, '2026-01-20', NULL, 4,  'available'),
('Lão Hạc',                        'Nam Cao',           '9786046924963', 'Văn học Việt Nam',   'Bức tranh cảm động về lòng tự trọng và tình cha con của người nông dân nghèo.',                  'NXB Văn Học',          2016, '2026-01-20', NULL, 0,  'borrowed'),
('Vợ nhặt',                        'Kim Lân',           '9786049635331', 'Văn học Việt Nam',   'Truyện ngắn xuất sắc về nạn đói 1945 và vẻ đẹp tình người.',                                    'NXB Văn Học',          2019, '2026-01-22', NULL, 4,  'available'),
-- Thiếu nhi
('Đất rừng phương Nam',            'Đoàn Giỏi',        '9786042186999', 'Thiếu nhi',          'Tiểu thuyết phiêu lưu về thiên nhiên trù phú và con người Nam Bộ anh dũng.',                     'NXB Kim Đồng',         2021, '2026-01-25', NULL, 5,  'available'),
('Tuổi thơ dữ dội',                'Phùng Quán',        '9786049887307', 'Thiếu nhi',          'Bản hùng ca về những người lính nhỏ tuổi trong chiến tranh.',                                    'NXB Kim Đồng',         2019, '2026-01-25', NULL, 6,  'available'),
('Cho tôi xin một vé đi tuổi thơ', 'Nguyễn Nhật Ánh', '9786041162489', 'Thiếu nhi',          'Tác phẩm đưa bạn đọc quay về thế giới tuổi thơ hồn nhiên.',                                     'NXB Trẻ',              2018, '2026-02-01', NULL, 6,  'available'),
('Mắt biếc',                       'Nguyễn Nhật Ánh', '9786041162502', 'Tiểu thuyết',        'Câu chuyện tình đơn phương da diết giữa Ngạn và Hà Lan.',                                        'NXB Trẻ',              2019, '2026-02-01', NULL, 0,  'borrowed'),
-- Kỹ năng / Kinh doanh
('Bí mật tư duy triệu phú',        'T. Harv Eker',     '9786045887668', 'Kinh tế / Kinh doanh','Giúp thay đổi hoàn toàn tư duy tài chính để đạt được thành công bền vững.',                     'NXB Tổng Hợp TP.HCM',  2018, '2026-02-05', NULL, 10, 'available'),
('Cà phê cùng Tony',               'Tony Buổi Sáng',   '9786041162700', 'Kỹ năng sống',       'Các bài viết truyền cảm hứng cho giới trẻ về thái độ sống.',                                     'NXB Trẻ',              2017, '2026-02-05', NULL, 7,  'available'),
('Tuổi trẻ đáng giá bao nhiêu',    'Rosie Nguyễn',     '9786045887888', 'Kỹ năng sống',       'Khích lệ người trẻ học hỏi, trải nghiệm và cống hiến hết mình.',                                'NXB Nhã Nam',          2018, '2026-02-10', NULL, 6,  'available'),
-- CNTT
('Lập trình PHP căn bản',          'Nhiều tác giả',    '9786048011234', 'Công nghệ thông tin', 'Giáo trình lập trình PHP từ cơ bản đến nâng cao.',                                               'NXB Thông Tin & TT',   2022, '2026-03-01', NULL, 5,  'available'),
('Clean Code',                     'Robert C. Martin', '9780132350884', 'Công nghệ thông tin', 'Hướng dẫn viết code sạch, dễ bảo trì và chuyên nghiệp.',                                         'Prentice Hall',        2008, '2026-03-01', NULL, 3,  'available'),
('Design Patterns',                'Gang of Four',     '9780201633610', 'Công nghệ thông tin', 'Các mẫu thiết kế phần mềm kinh điển dành cho lập trình viên.',                                   'Addison-Wesley',       1994, '2026-03-05', NULL, 2,  'available'),
-- Lịch sử / Khoa học
('Sapiens: Lược sử loài người',    'Yuval Noah Harari','9786041145016', 'Lịch sử',            'Tổng quan về lịch sử phát triển của loài người từ thời tiền sử đến hiện đại.',                   'NXB Tri Thức',         2017, '2026-03-10', NULL, 4,  'available'),
('Vũ trụ trong vỏ hạt dẻ',        'Stephen Hawking',  '9786041145023', 'Khoa học',            'Giải thích những bí ẩn của vũ trụ theo ngôn ngữ dễ hiểu nhất.',                                 'NXB Trẻ',              2015, '2026-03-10', NULL, 3,  'available'),
('Đắc nhân tâm',                   'Dale Carnegie',    '9786041145030', 'Kỹ năng sống',       'Cuốn sách kinh điển về nghệ thuật giao tiếp và chinh phục lòng người.',                          'NXB Tổng Hợp TP.HCM',  2016, '2026-03-15', NULL, 8,  'available');

-- ───────────────────────────────────────────────
-- 3. BOOK_IMPORTS (Phiếu nhập kho — khớp schema mới đầy đủ)
--    Phân bố: Q1 (tháng 1-3), Q2 (tháng 4-6) của 2026
--    Bao gồm cả 3 loại: purchase, donation, other
-- ───────────────────────────────────────────────
INSERT INTO book_imports
    (book_id, invoice_code, title, author, isbn, category, publisher, published_year,
     quantity, import_type, invoice_url, price, note, imported_by, status, import_date)
VALUES
-- Q1 — Tháng 1 (mua sắm đầu năm)
(1,  'HD-2026-001', 'Dế Mèn phiêu lưu ký',        'Tô Hoài',         '9786042186715', 'Thiếu nhi',          'NXB Kim Đồng',        2020, 8,  'purchase', NULL,                                       45000.00, 'Nhập sách thiếu nhi đợt đầu năm 2026.',        1, 'approved', '2026-01-10'),
(2,  'HD-2026-002', 'Vợ chồng A Phủ',              'Tô Hoài',         '9786049635222', 'Văn học Việt Nam',   'NXB Văn Học',         2018, 5,  'purchase', NULL,                                       38000.00, 'Bổ sung sách Tô Hoài đợt 1.',                 1, 'approved', '2026-01-10'),
(3,  'HD-2026-003', 'Tắt đèn',                     'Ngô Tất Tố',      '9786045887222', 'Văn học Việt Nam',   'NXB Văn Học',         2017, 8,  'purchase', NULL,                                       35000.00, 'Bổ sung sách văn học hiện thực.',             1, 'approved', '2026-01-15'),
(8,  'HD-2026-004', 'Đất rừng phương Nam',          'Đoàn Giỏi',       '9786042186999', 'Thiếu nhi',          'NXB Kim Đồng',        2021, 5,  'purchase', NULL,                                       52000.00, 'Bổ sung sách cho góc đọc thiếu nhi.',         1, 'approved', '2026-01-25'),
(9,  'HD-2026-005', 'Tuổi thơ dữ dội',             'Phùng Quán',       '9786049887307', 'Thiếu nhi',          'NXB Kim Đồng',        2019, 6,  'purchase', NULL,                                       48000.00, 'Nhập sách thiếu nhi đợt 2.',                  1, 'approved', '2026-01-25'),

-- Q1 — Tháng 2 (mua + tặng)
(10, 'HD-2026-006', 'Cho tôi xin một vé đi tuổi thơ','Nguyễn Nhật Ánh','9786041162489','Thiếu nhi',          'NXB Trẻ',             2018, 6,  'purchase', NULL,                                       55000.00, 'Nhập sách Nguyễn Nhật Ánh đợt đầu.',         1, 'approved', '2026-02-01'),
(12, 'HD-2026-007', 'Bí mật tư duy triệu phú',     'T. Harv Eker',    '9786045887668', 'Kinh tế / Kinh doanh','NXB Tổng Hợp TP.HCM',2018,12,  'purchase', 'http://invoice.hdpe.local/inv007.pdf',     68000.00, 'Nhập sách kỹ năng tài chính.',                1, 'approved', '2026-02-05'),
(13, 'DON-2026-001','Cà phê cùng Tony',             'Tony Buổi Sáng',  '9786041162700', 'Kỹ năng sống',       'NXB Trẻ',             2017, 5,  'donation', NULL,                                       0.00,     'Sách tặng từ Hội Cựu sinh viên khóa 2020.',   1, 'approved', '2026-02-10'),

-- Q1 — Tháng 3 (CNTT + khoa học)
(15, 'HD-2026-008', 'Lập trình PHP căn bản',       'Nhiều tác giả',   '9786048011234', 'Công nghệ thông tin','NXB Thông Tin & TT',  2022, 5,  'purchase', 'http://invoice.hdpe.local/inv008.pdf',     120000.00,'Nhập giáo trình CNTT học kỳ 2.',              1, 'approved', '2026-03-01'),
(16, 'HD-2026-009', 'Clean Code',                  'Robert C. Martin','9780132350884', 'Công nghệ thông tin','Prentice Hall',        2008, 3,  'purchase', 'http://invoice.hdpe.local/inv009.pdf',     350000.00,'Sách kỹ thuật lập trình chuyên sâu.',         1, 'approved', '2026-03-01'),
(17, 'DON-2026-002','Design Patterns',              'Gang of Four',    '9780201633610', 'Công nghệ thông tin','Addison-Wesley',       1994, 2,  'donation', NULL,                                       0.00,     'Tặng bởi giảng viên Khoa CNTT.',             1, 'approved', '2026-03-05'),
(18, 'HD-2026-010', 'Sapiens: Lược sử loài người', 'Yuval Noah Harari','9786041145016','Lịch sử',            'NXB Tri Thức',        2017, 4,  'purchase', NULL,                                       95000.00, 'Bổ sung tủ sách lịch sử - khoa học.',        1, 'approved', '2026-03-10'),
(19, 'HD-2026-011', 'Vũ trụ trong vỏ hạt dẻ',     'Stephen Hawking', '9786041145023', 'Khoa học',            'NXB Trẻ',             2015, 3,  'purchase', NULL,                                       88000.00, 'Bổ sung tủ sách khoa học phổ thông.',        1, 'approved', '2026-03-10'),
(20, 'HD-2026-012', 'Đắc nhân tâm',                'Dale Carnegie',   '9786041145030', 'Kỹ năng sống',       'NXB Tổng Hợp TP.HCM', 2016, 8,  'purchase', 'http://invoice.hdpe.local/inv012.pdf',     72000.00, 'Bổ sung sách kỹ năng mềm phổ biến.',        1, 'approved', '2026-03-15'),

-- Q2 — Tháng 4
(4,  'HD-2026-013', 'Số đỏ',                       'Vũ Trọng Phụng',  '9786049692484', 'Văn học Việt Nam',   'NXB Trẻ',             2019, 5,  'purchase', NULL,                                       42000.00, 'Bổ sung tủ sách văn học Q2.',                1, 'approved', '2026-04-05'),
(5,  'HD-2026-014', 'Chí Phèo',                    'Nam Cao',          '9786046924956', 'Văn học Việt Nam',   'NXB Văn Học',         2016, 4,  'purchase', NULL,                                       36000.00, 'Bổ sung sách Nam Cao đợt 2.',                1, 'approved', '2026-04-05'),
(14, 'HD-2026-015', 'Tuổi trẻ đáng giá bao nhiêu', 'Rosie Nguyễn',    '9786045887888', 'Kỹ năng sống',       'NXB Nhã Nam',         2018, 6,  'purchase', NULL,                                       62000.00, 'Nhập bổ sung sách kỹ năng tháng 4.',         1, 'approved', '2026-04-10'),

-- Q2 — Tháng 5 (mua + khác)
(7,  'HD-2026-016', 'Vợ nhặt',                     'Kim Lân',          '9786049635331', 'Văn học Việt Nam',   'NXB Văn Học',         2019, 4,  'purchase', NULL,                                       34000.00, 'Bổ sung sách trước mùa thi HK2.',            1, 'approved', '2026-05-02'),
(11, 'OTH-2026-001','Mắt biếc',                    'Nguyễn Nhật Ánh', '9786041162502', 'Tiểu thuyết',        'NXB Trẻ',             2019, 3,  'other',    NULL,                                       0.00,     'Chuyển từ tủ sách hội sinh viên.',           1, 'approved', '2026-05-08'),
(6,  'HD-2026-017', 'Lão Hạc',                     'Nam Cao',          '9786046924963', 'Văn học Việt Nam',   'NXB Văn Học',         2016, 3,  'purchase', NULL,                                       33000.00, 'Nhập bổ sung sách Nam Cao tháng 5.',         1, 'approved', '2026-05-15');

-- ───────────────────────────────────────────────
-- 4. BORROW_RECORDS (Giao dịch mượn/trả đủ 4 trạng thái)
-- ───────────────────────────────────────────────
INSERT INTO borrow_records (book_id, user_id, borrow_date, return_date, status, returned_at) VALUES
-- borrowed (đang mượn)
(1,  2,  '2026-05-10', '2026-05-24', 'borrowed', NULL),
(2,  3,  '2026-05-11', '2026-05-25', 'borrowed', NULL),
(4,  4,  '2026-05-12', '2026-05-26', 'borrowed', NULL),
(8,  5,  '2026-05-13', '2026-05-27', 'borrowed', NULL),
(15, 6,  '2026-05-14', '2026-05-28', 'borrowed', NULL),
-- returned (đã trả)
(3,  2,  '2026-05-01', '2026-05-15', 'returned', '2026-05-14 10:30:00'),
(5,  3,  '2026-05-02', '2026-05-16', 'returned', '2026-05-15 16:45:00'),
(7,  6,  '2026-05-05', '2026-05-19', 'returned', '2026-05-18 09:15:00'),
(13, 4,  '2026-04-20', '2026-05-04', 'returned', '2026-05-03 11:00:00'),
(20, 5,  '2026-04-22', '2026-05-06', 'returned', '2026-05-05 14:30:00'),
-- overdue (quá hạn)
(9,  8,  '2026-04-10', '2026-04-24', 'overdue',  NULL),
(10, 9,  '2026-04-12', '2026-04-26', 'overdue',  NULL),
(16, 7,  '2026-04-15', '2026-04-29', 'overdue',  NULL),
-- pending (chờ duyệt)
(12, 2,  '2026-05-17', '2026-05-31', 'pending',  NULL),
(14, 10, '2026-05-18', '2026-06-01', 'pending',  NULL),
(18, 3,  '2026-05-19', '2026-06-02', 'pending',  NULL);

-- ───────────────────────────────────────────────
-- 5. BOOK_REVIEWS (Đánh giá và bình luận)
-- ───────────────────────────────────────────────
INSERT INTO book_reviews (book_id, user_id, rating, comment, is_hidden) VALUES
(1,  2, 5, 'Tuyệt vời! Gắn liền với ký ức tuổi thơ của tôi. Văn phong Tô Hoài rất sinh động.',   0),
(1,  3, 4, 'Đọc rất lôi cuốn, thích cách kể chuyện hấp dẫn của tác giả.',                        0),
(3,  2, 5, 'Phản ánh chân thực cuộc sống nông dân. Đây là tác phẩm phải đọc!',                    0),
(5,  4, 4, 'Bi kịch của Chí Phèo vẫn còn nguyên giá trị đến ngày nay.',                           0),
(10, 3, 5, 'Nguyễn Nhật Ánh viết rất hay, đọc mà thấy tuổi thơ ùa về.',                          0),
(12, 5, 5, 'Sách kinh điển về tư duy tài chính. Mọi người nên đọc ít nhất một lần.',              0),
(16, 2, 4, 'Clean Code giúp tôi cải thiện cách viết code rất nhiều. Rất đáng đọc!',               0),
(18, 6, 5, 'Sapiens là cuốn sách mở mang tầm nhìn nhất tôi từng đọc!',                            0),
(20, 4, 5, 'Đắc nhân tâm — kinh điển mọi thời đại về giao tiếp và ứng xử.',                      0),
(4,  3, 3, 'Hay nhưng châm biếm hơi nặng, cần đọc kỹ mới hiểu hết.',                              0);

-- ───────────────────────────────────────────────
-- 6. ANNOUNCEMENTS (Thông báo sự kiện)
-- ───────────────────────────────────────────────
INSERT INTO announcements (title, content, type, start_date, end_date, is_active, created_by) VALUES
('Thư viện mở cửa xuyên hè 2026',         'Thư viện HDPE phục vụ bạn đọc xuyên suốt mùa hè từ 7:30–21:00 tất cả các ngày.', 'general', '2026-06-01', '2026-08-31', 1, 1),
('Cuộc thi đọc sách tháng 6',             'Tham gia cuộc thi đọc sách tháng 6 để nhận nhiều phần thưởng hấp dẫn! Đăng ký tại quầy thư viện.', 'contest', '2026-06-01', '2026-06-30', 1, 1),
('Nghỉ lễ Quốc khánh 2/9',               'Thư viện thông báo lịch nghỉ lễ Quốc khánh từ 02/09/2026 đến hết ngày 03/09/2026.', 'holiday', '2026-09-02', '2026-09-03', 1, 1),
('Bổ sung 20 đầu sách mới tháng 5/2026', 'Thư viện vừa nhập thêm nhiều đầu sách CNTT, Kỹ năng sống và Văn học. Mời bạn đọc vào xem và mượn.', 'general', '2026-05-01', '2026-05-31', 1, 1);

-- ───────────────────────────────────────────────
-- 7. SUPPORT_TICKETS (Yêu cầu hỗ trợ)
-- ───────────────────────────────────────────────
INSERT INTO support_tickets (user_id, category, title, description, status) VALUES
(2, 'card_issue',   'Yêu cầu mở khóa thẻ thư viện',  'Thẻ của em bị khóa nhưng em đã trả hết sách quá hạn rồi ạ. Mong thầy cô hỗ trợ kiểm tra mở khóa giúp em.', 'open'),
(3, 'damaged_book', 'Báo cáo sách bị rách trang',     'Cuốn sách Dế Mèn phiêu lưu ký em vừa mượn có một số trang bị rách ở chương 3. Em muốn báo cáo trước để tránh bị phạt khi trả.', 'in_progress'),
(4, 'lost_item',    'Làm mất sách Tuổi thơ dữ dội',   'Em sơ suất làm mất cuốn sách trong quá trình mượn. Em muốn hỏi thủ tục bồi thường hoặc mua đền thế nào ạ?', 'open'),
(5, 'other',        'Hỏi về thời gian mượn tối đa',   'Em muốn hỏi một lần em được mượn tối đa bao nhiêu cuốn và thời hạn mượn tối đa là bao lâu ạ?', 'closed');

-- ───────────────────────────────────────────────
-- 8. TICKET_MESSAGES (Hội thoại hỗ trợ)
-- ───────────────────────────────────────────────
INSERT INTO ticket_messages (ticket_id, sender_id, sender_role, message) VALUES
(2, 3, 'user',  'Em muốn báo cáo sách bị rách trang ạ. Trang 45-48 bị rách góc.'),
(2, 1, 'admin', 'Chào em, thầy đã ghi nhận. Sách này bị rách từ trước khi em mượn nên em không bị phạt. Cứ yên tâm đọc và trả đúng hạn nhé.'),
(2, 3, 'user',  'Dạ em cảm ơn thầy nhiều ạ!'),
(4, 5, 'user',  'Thầy ơi cho em hỏi thư viện cho mượn tối đa mấy cuốn một lần ạ?'),
(4, 1, 'admin', 'Chào em, mỗi sinh viên được mượn tối đa 3 cuốn cùng lúc, thời hạn mượn 14 ngày. Có thể gia hạn thêm 7 ngày nếu chưa có người khác đặt mượn.'),
(4, 5, 'user',  'Dạ em cảm ơn thầy ạ!');

-- ───────────────────────────────────────────────
-- 9. NOTIFICATIONS (Thông báo hệ thống mẫu)
-- ───────────────────────────────────────────────
INSERT INTO notifications (user_id, sender_id, title, message, type, is_read, related_id) VALUES
-- Thông báo cho admin (user_id IS NULL)
(NULL, 2, 'Yêu cầu mượn sách mới',   'Sinh viên <strong>Nguyễn Văn An</strong> vừa đăng ký mượn cuốn <strong>Bí mật tư duy triệu phú</strong>.', 'borrow',  0, 14),
(NULL, 4, 'Yêu cầu mượn sách mới',   'Sinh viên <strong>Phạm Thu Dung</strong> vừa đăng ký mượn cuốn <strong>Tuổi trẻ đáng giá bao nhiêu</strong>.','borrow', 0, 15),
(NULL, 3, 'Yêu cầu mượn sách mới',   'Sinh viên <strong>Trần Thị Bình</strong> vừa đăng ký mượn cuốn <strong>Mắt biếc</strong>.', 'borrow',  0, 16),
(NULL, 2, 'Yêu cầu hỗ trợ mới',      'Độc giả <strong>Nguyễn Văn An</strong> gửi ticket mới: <em>Yêu cầu mở khóa thẻ thư viện</em>.',  'ticket',  0, 1),
(NULL, 4, 'Yêu cầu hỗ trợ mới',      'Độc giả <strong>Phạm Thu Dung</strong> gửi ticket mới: <em>Hỏi về thời gian mượn tối đa</em>.',  'ticket',  1, 4),
-- Thông báo cho sinh viên
(2, 1, 'Phiếu mượn đã được duyệt',   'Cuốn sách <strong>Dế Mèn phiêu lưu ký</strong> của bạn đã được thủ thư phê duyệt thành công!',  'borrow_approved', 0, 7),
(3, 1, 'Phiếu mượn đã được duyệt',   'Cuốn sách <strong>Vợ chồng A Phủ</strong> của bạn đã được thủ thư phê duyệt thành công!',       'borrow_approved', 1, 8),
(3, 1, 'Có phản hồi hỗ trợ',         'Thủ thư đã trả lời yêu cầu hỗ trợ của bạn: <em>Báo cáo sách bị rách trang</em>.',               'ticket_answered',  0, 2),
(5, 1, 'Có phản hồi hỗ trợ',         'Thủ thư đã trả lời yêu cầu hỗ trợ của bạn: <em>Hỏi về thời gian mượn tối đa</em>.',             'ticket_answered',  1, 4),
(8, 1, 'Sách quá hạn trả',           'Cuốn sách <strong>Tuổi thơ dữ dội</strong> đã quá hạn trả vào ngày 2026-04-24. Vui lòng trả sách sớm.', 'borrow_alert', 0, 9),
(9, 1, 'Sách quá hạn trả',           'Cuốn sách <strong>Cho tôi xin một vé đi tuổi thơ</strong> đã quá hạn trả vào ngày 2026-04-26. Vui lòng trả sách sớm.', 'borrow_alert', 0, 10);

-- ───────────────────────────────────────────────
-- 10. CHAT_LOGS (Lịch sử chatbot)
-- ───────────────────────────────────────────────
INSERT INTO chat_logs (user_id, message, response) VALUES
(2,    'Làm thế nào để mượn sách?',          'Chào bạn! Để mượn sách, bạn hãy đăng nhập, tìm cuốn sách yêu thích và nhấn nút "Mượn sách". Thủ thư sẽ duyệt trong vòng 24 giờ.'),
(NULL, 'Giờ mở cửa của thư viện?',           'Thư viện HDPE mở cửa từ 7:30 đến 21:00 tất cả các ngày trong tuần (trừ ngày lễ Tết). Rất hân hạnh được phục vụ bạn!'),
(3,    'Tìm sách về lập trình PHP',          'Thư viện đang có cuốn "Lập trình PHP căn bản" (5 quyển) và "Clean Code" (3 quyển) — rất phù hợp cho bạn học lập trình!'),
(4,    'Sách của Nam Cao có những cuốn nào?','Thư viện đang có 2 tác phẩm của Nam Cao: "Chí Phèo" (4 quyển) và "Lão Hạc" (còn 0 quyển, hiện đang được mượn hết).'),
(5,    'Tôi muốn tìm sách kỹ năng sống',     'Thư viện có nhiều sách kỹ năng sống hay: "Đắc nhân tâm", "Cà phê cùng Tony", "Tuổi trẻ đáng giá bao nhiêu", "Bí mật tư duy triệu phú". Bạn muốn mượn cuốn nào?');
