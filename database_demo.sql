-- ═══════════════════════════════════════════════════════════════════════
-- HCMUE Library System — Rich Demo/Seed Data
-- Chạy file này SAU KHI đã chạy database.sql để nạp dữ liệu mẫu hoàn chỉnh.
-- ═══════════════════════════════════════════════════════════════════════

USE library_db;

-- ── DỌN DẸP DỮ LIỆU CŨ ───────────────────────────────────────────────
SET FOREIGN_KEY_CHECKS = 0;
DELETE FROM chat_logs;
DELETE FROM ticket_messages;
DELETE FROM support_tickets;
DELETE FROM announcements;
DELETE FROM book_imports;
DELETE FROM book_reviews;
DELETE FROM borrow_records;
DELETE FROM books;
DELETE FROM users;

ALTER TABLE chat_logs AUTO_INCREMENT = 1;
ALTER TABLE ticket_messages AUTO_INCREMENT = 1;
ALTER TABLE support_tickets AUTO_INCREMENT = 1;
ALTER TABLE announcements AUTO_INCREMENT = 1;
ALTER TABLE book_imports AUTO_INCREMENT = 1;
ALTER TABLE book_reviews AUTO_INCREMENT = 1;
ALTER TABLE borrow_records AUTO_INCREMENT = 1;
ALTER TABLE books AUTO_INCREMENT = 1;
ALTER TABLE users AUTO_INCREMENT = 1;
SET FOREIGN_KEY_CHECKS = 1;

-- ───────────────────────────────────────────────
-- 1. NẠP DỮ LIỆU MẪU: users (Tất cả mật khẩu là Admin@123)
-- ───────────────────────────────────────────────
INSERT INTO users (username, email, password, full_name, role, account_status, lock_reason, locked_at, phone) VALUES
('admin',    'admin@library.local',    '$2y$10$yFpGq4323AOg24smPvUYN.w25VXTBBYjhBMboCAaCfZwrbcvwSQja', 'Quản trị viên', 'admin', 'active', NULL, NULL, '0901234567'),
('student1', 'student1@library.local', '$2y$10$yFpGq4323AOg24smPvUYN.w25VXTBBYjhBMboCAaCfZwrbcvwSQja', 'Nguyễn Văn An',  'student', 'active', NULL, NULL, '0987654321'),
('student2', 'student2@library.local', '$2y$10$yFpGq4323AOg24smPvUYN.w25VXTBBYjhBMboCAaCfZwrbcvwSQja', 'Trần Thị Bình',  'student', 'active', NULL, NULL, '0912345678'),
('student3', 'student3@library.local', '$2y$10$yFpGq4323AOg24smPvUYN.w25VXTBBYjhBMboCAaCfZwrbcvwSQja', 'Lê Minh Cường',  'student', 'active', NULL, NULL, '0934567890'),
('student4', 'student4@library.local', '$2y$10$yFpGq4323AOg24smPvUYN.w25VXTBBYjhBMboCAaCfZwrbcvwSQja', 'Phạm Thu Dung',  'student', 'active', NULL, NULL, '0976543210'),
('student5', 'student5@library.local', '$2y$10$yFpGq4323AOg24smPvUYN.w25VXTBBYjhBMboCAaCfZwrbcvwSQja', 'Hoàng Huy',      'student', 'active', NULL, NULL, '0943210987'),
('student6', 'student6@library.local', '$2y$10$yFpGq4323AOg24smPvUYN.w25VXTBBYjhBMboCAaCfZwrbcvwSQja', 'Đỗ Ngọc Khánh',  'student', 'active', NULL, NULL, '0956789012'),
('student7', 'student7@library.local', '$2y$10$yFpGq4323AOg24smPvUYN.w25VXTBBYjhBMboCAaCfZwrbcvwSQja', 'Vũ Hải Long',    'student', 'locked', 'Không trả sách đúng hạn nhiều lần', '2026-05-15 14:00:00', '0967890123'),
('student8', 'student8@library.local', '$2y$10$yFpGq4323AOg24smPvUYN.w25VXTBBYjhBMboCAaCfZwrbcvwSQja', 'Bùi Phương Mai', 'student', 'active', NULL, NULL, '0923456789'),
('student9', 'student9@library.local', '$2y$10$yFpGq4323AOg24smPvUYN.w25VXTBBYjhBMboCAaCfZwrbcvwSQja', 'Trịnh Đức Nam',  'student', 'active', NULL, NULL, '0911223344'),
('student10','student10@library.local','$2y$10$yFpGq4323AOg24smPvUYN.w25VXTBBYjhBMboCAaCfZwrbcvwSQja', 'Ngô Quỳnh Như', 'student', 'active', NULL, NULL, '0955667788');

-- ───────────────────────────────────────────────
-- 2. NẠP DỮ LIỆU MẪU: books (Danh mục sách đa dạng)
-- ───────────────────────────────────────────────
INSERT INTO books (title, author, isbn, category, description, publisher, published_year, import_date, cover_image_url, quantity, status) VALUES
('Dế Mèn phiêu lưu ký', 'Tô Hoài', '9786042186715', 'Thiếu nhi', 'Tác phẩm văn học thiếu nhi kinh điển của nhà văn Tô Hoài kể về cuộc phiêu lưu của chú Dế Mèn.', 'NXB Kim Đồng', 2020, '2026-01-10', NULL, 8, 'available'),
('Vợ chồng A Phủ', 'Tô Hoài', '9786049635222', 'Văn học Việt Nam', 'Truyện ngắn phản ánh cuộc sống khổ cực của đồng bào miền núi Tây Bắc dưới ách áp bức phong kiến.', 'NXB Văn Học', 2018, '2026-01-10', NULL, 4, 'available'),
('Truyện Tây Bắc', 'Tô Hoài', '9786046924814', 'Văn học Việt Nam', 'Tập truyện nổi tiếng khắc họa chân thực và sâu sắc đời sống người dân vùng cao Tây Bắc.', 'NXB Văn Học', 2015, '2026-01-12', NULL, 3, 'available'),
('Cát bụi chân ai', 'Tô Hoài', '9786049887123', 'Hồi ký', 'Tác phẩm hồi ký mang tính chân thực về cuộc đời và giới văn nghệ sĩ thời kháng chiến.', 'NXB Hội Nhà Văn', 2020, '2026-01-12', NULL, 2, 'available'),
('Tắt đèn', 'Ngô Tất Tố', '9786045887222', 'Văn học Việt Nam', 'Tác phẩm hiện thực phê phán đỉnh cao về thân phận người nông dân nghèo trước Cách mạng.', 'NXB Văn Học', 2017, '2026-01-15', NULL, 6, 'available'),
('Lều chõng', 'Ngô Tất Tố', '9786045887239', 'Văn học Việt Nam', 'Tiểu thuyết tái hiện chân thực và sinh động thi cử nho học thời phong kiến.', 'NXB Văn Học', 2018, '2026-01-15', NULL, 2, 'available'),
('Số đỏ', 'Vũ Trọng Phụng', '9786049692484', 'Văn học Việt Nam', 'Tác phẩm trào phúng xuất sắc nhất văn học Việt Nam hiện đại.', 'NXB Trẻ', 2019, '2026-01-18', NULL, 5, 'available'),
('Giông tố', 'Vũ Trọng Phụng', '9786049692491', 'Văn học Việt Nam', 'Bức tranh hiện thực đen tối về xã hội thành thị Việt Nam thời kỳ Pháp thuộc.', 'NXB Văn Học', 2017, '2026-01-18', NULL, 2, 'available'),
('Chí Phèo', 'Nam Cao', '9786046924956', 'Văn học Việt Nam', 'Truyện ngắn kiệt tác thể hiện bi kịch tha hóa và bi kịch từ chối làm người của Chí Phèo.', 'NXB Văn Học', 2016, '2026-01-20', NULL, 4, 'available'),
('Lão Hạc', 'Nam Cao', '9786046924963', 'Văn học Việt Nam', 'Bức tranh cảm động về lòng tự trọng và tình cha con vô bờ bến của người nông dân nghèo.', 'NXB Văn Học', 2016, '2026-01-20', NULL, 0, 'borrowed'),
('Sống mòn', 'Nam Cao', '9786046924970', 'Văn học Việt Nam', 'Tiểu thuyết phản ánh bi kịch tinh thần của giới trí thức tiểu tư sản nghèo.', 'NXB Văn Học', 2018, '2026-01-20', NULL, 2, 'available'),
('Vợ nhặt', 'Kim Lân', '9786049635331', 'Văn học Việt Nam', 'Truyện ngắn xuất sắc về nạn đói năm 1945 và vẻ đẹp tình người trong hoạn nạn.', 'NXB Văn Học', 2019, '2026-01-22', NULL, 4, 'available'),
('Đất rừng phương Nam', 'Đoàn Giỏi', '9786042186999', 'Thiếu nhi', 'Tiểu thuyết phiêu lưu lừng danh về thiên nhiên trù phú và con người Nam Bộ anh dũng.', 'NXB Kim Đồng', 2021, '2026-01-25', NULL, 5, 'available'),
('Tuổi thơ dữ dội', 'Phùng Quán', '9786049887307', 'Thiếu nhi', 'Bản hùng ca đầy xúc động về những người lính nhỏ tuổi trong chiến tranh.', 'NXB Kim Đồng', 2019, '2026-01-25', NULL, 6, 'available'),
('Cho tôi xin một vé đi tuổi thơ', 'Nguyễn Nhật Ánh', '9786041162489', 'Thiếu nhi', 'Tác phẩm bán chạy nhất đưa bạn đọc quay về thế giới tuổi thơ hồn nhiên và trong sáng.', 'NXB Trẻ', 2018, '2026-02-01', NULL, 6, 'available'),
('Tôi thấy hoa vàng trên cỏ xanh', 'Nguyễn Nhật Ánh', '9786041162496', 'Thiếu nhi', 'Cuốn tiểu thuyết dịu dàng vẽ nên bức tranh làng quê nghèo yên bình và tuổi thơ đáng nhớ.', 'NXB Trẻ', 2019, '2026-02-01', NULL, 5, 'available'),
('Mắt biếc', 'Nguyễn Nhật Ánh', '9786041162502', 'Tiểu thuyết', 'Câu chuyện tình đơn phương da diết giữa Ngạn và Hà Lan.', 'NXB Trẻ', 2019, '2026-02-01', NULL, 0, 'borrowed'),
('Bí mật tư duy triệu phú', 'T. Harv Eker', '9786045887668', 'Kinh tế / Kinh doanh', 'Quyển sách giúp thay đổi hoàn toàn tư duy tài chính để đạt được thành công bền vững.', 'NXB Tổng Hợp TPHCM', 2018, '2026-02-05', NULL, 10, 'available'),
('Cà phê cùng Tony', 'Tony Buổi Sáng', '9786041162700', 'Kỹ năng sống', 'Tập hợp các bài viết truyền cảm hứng mạnh mẽ cho giới trẻ về thái độ sống.', 'NXB Trẻ', 2017, '2026-02-05', NULL, 7, 'available'),
('Trên đường băng', 'Tony Buổi Sáng', '9786041162701', 'Kỹ năng sống', 'Cẩm nang rèn luyện nhân cách và ý chí vươn ra thế giới dành cho người trẻ.', 'NXB Trẻ', 2018, '2026-02-05', NULL, 6, 'available'),
('Tuổi trẻ đáng giá bao nhiêu', 'Rosie Nguyễn', '9786045887888', 'Kỹ năng sống', 'Cuốn sách khích lệ người trẻ học hỏi, trải nghiệm và cống hiến hết mình.', 'NXB Nhã Nam', 2018, '2026-02-10', NULL, 6, 'available');

-- ───────────────────────────────────────────────
-- 3. NẠP DỮ LIỆU MẪU: book_imports (Nhập kho sách)
-- ───────────────────────────────────────────────
INSERT INTO book_imports (book_id, quantity, import_date, note, imported_by) VALUES
(1, 10, '2026-01-10', 'Nhập sách thiếu nhi đợt đầu năm', 1),
(2, 5, '2026-01-10', 'Nhập đợt đầu năm 2026', 1),
(5, 8, '2026-01-15', 'Bổ sung sách Ngô Tất Tố', 1),
(15, 10, '2026-02-01', 'Nhập đợt sách Nguyễn Nhật Ánh', 1),
(18, 12, '2026-02-05', 'Nhập sách kỹ năng tài chính', 1);

-- ───────────────────────────────────────────────
-- 4. NẠP DỮ LIỆU MẪU: book_reviews (Đánh giá và bình luận)
-- ───────────────────────────────────────────────
INSERT INTO book_reviews (book_id, user_id, rating, comment, is_hidden) VALUES
(1, 2, 5, 'Một cuốn sách tuyệt vời, gắn liền với tuổi thơ của tôi!', 0),
(1, 3, 4, 'Văn phong của Tô Hoài rất sinh động và lôi cuốn người đọc.', 0),
(5, 2, 5, 'Tác phẩm phản ánh chân thực nỗi khổ cực của nông dân Việt Nam xưa.', 0),
(15, 4, 5, 'Rất hay và ý nghĩa, tôi đã đọc đi đọc lại nhiều lần.', 0),
(18, 3, 5, 'Sách kinh điển về tư duy tài chính, khuyên mọi người nên đọc để thay đổi tư duy.', 0);

-- ───────────────────────────────────────────────
-- 5. NẠP DỮ LIỆU MẪU: announcements (Thông báo)
-- ───────────────────────────────────────────────
INSERT INTO announcements (title, content, type, start_date, end_date, is_active, created_by) VALUES
('Thư viện mở cửa xuyên hè 2026', 'Thư viện HDPE sẽ phục vụ bạn đọc xuyên suốt mùa hè từ 7:30 đến 21:00 các ngày trong tuần.', 'general', '2026-06-01', '2026-08-31', 1, 1),
('Cuộc thi đọc sách tháng 6', 'Tham gia cuộc thi đọc sách tháng 6 để nhận nhiều phần thưởng hấp dẫn! Đăng ký tại quầy thư viện.', 'contest', '2026-06-01', '2026-06-30', 1, 1),
('Nghỉ lễ Quốc khánh 2/9', 'Thư viện xin thông báo lịch nghỉ lễ Quốc khánh từ ngày 02/09/2026 đến hết ngày 03/09/2026.', 'holiday', '2026-09-02', '2026-09-03', 1, 1);

-- ───────────────────────────────────────────────
-- 6. NẠP DỮ LIỆU MẪU: support_tickets (Yêu cầu hỗ trợ)
-- ───────────────────────────────────────────────
INSERT INTO support_tickets (user_id, category, title, description, status) VALUES
(2, 'card_issue', 'Yêu cầu mở khóa thẻ thư viện', 'Thẻ của em bị khóa nhưng em đã trả hết sách quá hạn rồi ạ. Mong thầy cô hỗ trợ kiểm tra mở khóa giúp em.', 'open'),
(3, 'damaged_book', 'Báo cáo sách bị rách trang', 'Cuốn sách Dế Mèn phiêu lưu ký em vừa mượn có một số trang bị rách ở chương 3. Em muốn báo cáo trước để tránh bị phạt khi trả ạ.', 'in_progress'),
(4, 'lost_item', 'Làm mất sách Tuổi thơ dữ dội', 'Em sơ suất làm mất cuốn sách Tuổi thơ dữ dội trong quá trình mượn. Em muốn hỏi thủ tục bồi thường hoặc mua đền thế nào ạ?', 'open');

-- ───────────────────────────────────────────────
-- 7. NẠP DỮ LIỆU MẪU: ticket_messages (Chi tiết tin nhắn hỗ trợ)
-- ───────────────────────────────────────────────
INSERT INTO ticket_messages (ticket_id, sender_id, sender_role, message) VALUES
(2, 2, 'user', 'Mong thầy cô kiểm tra giúp em tài khoản student1 với ạ.'),
(3, 3, 'user', 'Em báo cáo sách rách trang ạ.'),
(3, 1, 'admin', 'Chào em, thầy đã ghi nhận thông tin sách bị rách trang từ trước khi em mượn. Em cứ yên tâm đọc và trả đúng hạn nhé.'),
(3, 3, 'user', 'Dạ em cảm ơn thầy nhiều ạ!');

-- ───────────────────────────────────────────────
-- 8. NẠP DỮ LIỆU MẪU: chat_logs (Lịch sử chatbot)
-- ───────────────────────────────────────────────
INSERT INTO chat_logs (user_id, message, response) VALUES
(2, 'Làm thế nào để mượn sách?', 'Chào bạn! Để mượn sách, bạn hãy đăng nhập tài khoản sinh viên, tìm cuốn sách yêu thích và nhấn nút "Mượn sách" hoặc mang thẻ sinh viên đến quầy thư viện để được thủ thư hỗ trợ lập phiếu nhé.'),
(NULL, 'Giờ mở cửa của thư viện?', 'Thư viện HDPE mở cửa từ 7:30 đến 21:00 tất cả các ngày trong tuần (trừ ngày lễ Tết). Rất hân hạnh được phục vụ bạn!');

-- ───────────────────────────────────────────────
-- 9. NẠP DỮ LIỆU MẪU: borrow_records (Giao dịch mượn/trả - Đầy đủ 4 trạng thái)
-- ───────────────────────────────────────────────
INSERT INTO borrow_records (book_id, user_id, borrow_date, return_date, status, returned_at) VALUES
-- 1. Đang mượn (borrowed)
(1, 2, '2026-05-10', '2026-05-24', 'borrowed', NULL),
(2, 3, '2026-05-11', '2026-05-25', 'borrowed', NULL),
(7, 4, '2026-05-12', '2026-05-26', 'borrowed', NULL),

-- 2. Đã trả (returned)
(5, 2, '2026-05-01', '2026-05-15', 'returned', '2026-05-14 10:30:00'),
(9, 5, '2026-05-02', '2026-05-16', 'returned', '2026-05-15 16:45:00'),
(13, 6, '2026-05-05', '2026-05-19', 'returned', '2026-05-18 09:15:00'),

-- 3. Quá hạn (overdue)
(15, 8, '2026-04-10', '2026-04-24', 'overdue', NULL),
(16, 9, '2026-04-12', '2026-04-26', 'overdue', NULL),

-- 4. Chờ duyệt (pending)
(18, 2, '2026-05-17', '2026-05-31', 'pending', NULL),
(20, 10, '2026-05-18', '2026-06-01', 'pending', NULL);
