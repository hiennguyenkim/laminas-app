# Báo cáo Triển khai Hệ thống Quản lý Thư viện (Phase 2-4)

## 1. Danh sách lỗi đã sửa (Bug Fixes)
- **Lỗi hiển thị Initials (Chữ cái đầu tên):** Fix lỗi cắt chuỗi UTF-8 hiển thị sai ở avatar do dùng `substr`, chuyển sang dùng `mb_substr` (File `library-layout.phtml`).
- **Lỗi Hardcoded API Path:** Sửa lỗi gọi `/api/books/search` bị lỗi đường dẫn khi dự án nằm trong sub-folder `/laminas-app/public`. Đã đổi sang dùng helper `url()` của Laminas (File `borrow.phtml`).
- **Lỗi Filter Thể loại:** Cập nhật hàm lọc theo thể loại ở `BookTable` thành toán tử `LIKE` để tránh lỗi lọc không ra kết quả khi chuỗi không hoàn toàn khớp.
- **Lỗi Xóa Lịch Sử:** Thay đổi `RETURNED_HISTORY_RETENTION_DAYS` từ 30 ngày lên 3650 ngày (10 năm) trong `BorrowTable` để không bị mất lịch sử trên trang Profile của sinh viên.

## 2. Các chức năng mới đã triển khai
1. **Migration & Nâng cấp Database:**
   - Chạy thành công toàn bộ schema SQL cho Phase 2, 3 và 4.
   - Thêm các bảng `book_reviews`, `book_imports`, `announcements`, `support_tickets`, `ticket_messages`, `chat_logs`.
   - Mở rộng cột cho bảng `users` (thêm nickname, account_status, phone...) và `books` (description, publisher, import_date...).

2. **Cơ chế Khóa tài khoản (Block User):**
   - Đã thêm `isLocked()` vào User Entity và logic trong `CirculationService`. Sinh viên bị khóa sẽ không thể lập phiếu mượn mới (sẽ throw Exception và hiển thị trên giao diện mượn).
   - Trang Quản lý Thành viên (Admin): Đã bổ sung nút "Khóa thẻ" và "Mở khóa". Khi khóa có yêu cầu truyền lý do (mặc định là "Vi phạm nội quy" hoặc "Khóa từ trang chi tiết"). Admin có thể quản lý dễ dàng.

3. **Hệ thống AI Chatbox (Recommend Book):**
   - Thiết kế UI chatbox nổi (floating widget) phong cách hiện đại ở layout chính (cho mọi trang).
   - Tích hợp hiệu ứng typing loading để tạo cảm giác realtime.
   - Logic Backend (`BookApiController::chatAction`): Xử lý NLP rule-based (nhận diện keyword như "văn học", "công nghệ", "kinh tế") kết hợp search DB để đưa ra gợi ý sách kèm link trực tiếp đến trang danh mục.
   - Toàn bộ lịch sử chat được lưu vào `chat_logs`.

4. **Trang Hồ sơ cá nhân (User Profile):**
   - Tạo controller mới `ProfileController` và cấu hình route `/admin/profile`.
   - Giao diện đẹp, responsive: Cho phép xem thông tin cá nhân, cập nhật nickname, ngày sinh, số điện thoại, và **upload avatar** ảnh đại diện.
   - Hiển thị thống kê cá nhân (số sách đã mượn, đang mượn, trễ hạn) và danh sách lịch sử mượn trả trực tiếp trên trang hồ sơ.

5. **Bảng tin Thư viện (News Board):**
   - Đã thêm Bảng tin phong cách Portal trường học ở cuối trang Danh mục Sách (Catalog / Trang chủ).
   - Hiển thị các thông báo (Announcements) từ thư viện (sự kiện, cuộc thi, ngày lễ) với UI grid-card bắt mắt, có badge phân loại tự động.

## 3. Vấn đề còn tồn tại & Hướng phát triển tiếp (Đề xuất)
- **Module Quản lý Nhập sách (Import Sách):** Cơ sở dữ liệu và model đã có các trường `import_date` và bảng `book_imports`, tuy nhiên UI CRUD trên màn hình Quản lý sách vẫn chưa được tích hợp hoàn chỉnh do giới hạn về thời gian.
- **Hệ thống Ticket/Feedback:** Các bảng `support_tickets` và `book_reviews` đã sẵn sàng, cần build module Frontend tương tự như Profile để sinh viên có thể post dữ liệu lên.
- **Tích hợp API LLM thật cho Chatbox:** Hiện tại Chatbox dùng keyword-matching. Sau này có thể tích hợp Google Gemini API vào backend để gợi ý sách theo semantic search thay vì exact keyword match.

## 4. Báo cáo QA & Testing
- Các chức năng mới đã được test thủ công trên giao diện thật (local server).
- Các luồng (mượn sách, mở khóa/khóa thẻ) đã được test, UI layout nhất quán, responsive và bắt mắt, Dark Mode/Theme không bị ảnh hưởng.

Dự án đã đáp ứng phần lớn các tiêu chí của Phase 2 và chuẩn bị sẵn sàng nền tảng Data cho Phase 3, 4.
