# BÁO CÁO KIỂM THỬ TOÀN DIỆN HỆ THỐNG (QA REPORT)
**Hệ thống Quản lý Thư viện HDPE**

Báo cáo chi tiết kết quả chạy kiểm thử toàn diện (QA testing) cả Frontend và Backend trên hệ thống. 

---

## 1. Kết quả Kiểm thử theo Module

### 1.1. Module Xác thực & Phân quyền (Authentication & Authorization)

| Test case | Role | Kết quả | Trạng thái |
|-----------|------|---------|------------|
| Đăng ký tài khoản sinh viên mới | Guest | Đăng ký thành công với thông tin hợp lệ, tạo bản ghi chưa kích hoạt (`is_approved = 0`) và chuyển sang trang OTP. | ✅ PASS |
| Kích hoạt tài khoản bằng OTP | Guest | Nhập đúng mã OTP từ CSDL (`515943`), tài khoản kích hoạt thành công (`is_approved = 1`). | ✅ PASS |
| Đăng nhập tài khoản Sinh viên | Student | Đăng nhập thành công với mật khẩu `Admin@123`, chuyển hướng chính xác đến `/student/dashboard`. | ✅ PASS |
| Đăng nhập tài khoản Quản trị | Admin | Đăng nhập thành công với mật khẩu `Admin@123`, chuyển hướng chính xác đến `/admin/dashboard`. | ✅ PASS |
| Khóa Brute-Force OTP khôi phục mật khẩu | Guest | Khi nhập sai OTP quá 5 lần, mã OTP bị hủy bỏ khỏi DB và phiên khôi phục mật khẩu bị hủy vì lý do bảo mật. | ✅ PASS |
| Độ mạnh mật khẩu khi Reset | Guest / Student | Mật khẩu bắt buộc >= 8 ký tự, có 1 hoa, 1 ký tự đặc biệt, giao diện hiển thị dynamic strength checklist trực quan. | ✅ PASS |
| E2E Yêu cầu Khôi phục Mật khẩu | Student | Gửi yêu cầu khôi phục mật khẩu cho `student_3` thành công, sinh mã OTP trong DB. | ✅ PASS |
| E2E Đặt lại Mật khẩu với OTP | Student | Nhập đúng mã OTP và mật khẩu mới (`Student@123!`), mật khẩu băm trong DB thay đổi và OTP được xóa sạch. | ✅ PASS |
| E2E Đăng nhập bằng Mật khẩu Mới & CSRF | Student | Sử dụng mật khẩu mới và trích xuất đúng token CSRF để đăng nhập thành công vào `/student/dashboard`. | ✅ PASS |

---

### 1.2. Module Quản lý sách & Danh mục (Book Management)

| Test case | Role | Kết quả | Trạng thái |
|-----------|------|---------|------------|
| Nhập kho sách thủ công (Manual Input) | Admin | Tạo mới sách `QA Manual Book` thành công, lưu đầy đủ thông tin vào bảng `books`. | ✅ PASS |
| Đồng bộ số lượng nhập kho bằng ISBN | Admin | Khi nhập sách trùng ISBN có sẵn, hệ thống cộng dồn số lượng khả dụng và cập nhật trạng thái hoạt động. | ✅ PASS |
| Tra cứu & Tìm kiếm sách | Student / Guest | Tìm kiếm sách theo từ khóa thành công qua catalog và API `/api/books/search`. | ✅ PASS |
| Ẩn sách hết hàng (quantity = 0) | Student | Các sách có `quantity = 0` không hiển thị trên danh sách đăng ký mượn sách. | ✅ PASS |
| Đánh giá & Bình luận sách (Book Review) | Student | Độc giả chỉ được đánh giá sách đã/đang mượn một lần duy nhất, admin có quyền xóa bình luận vi phạm. | ✅ PASS |

---

### 1.3. Module Động cơ mượn/trả sách (Circulation Engine)

| Test case | Role | Kết quả | Trạng thái |
|-----------|------|---------|------------|
| Gửi yêu cầu mượn sách (Pending) | Student | Gửi yêu cầu mượn sách thành công, tạo bản ghi mượn ở trạng thái `pending`. | ✅ PASS |
| Cơ chế Giữ chỗ chắc chắn (Hard Reservation) | Student / Admin | Số lượng sách khả dụng trên kệ giảm ngay lập tức khi student gửi yêu cầu `pending`. Nếu bị từ chối, số lượng sách được hoàn trả về kệ. | ✅ PASS |
| Phê duyệt yêu cầu mượn sách | Admin | Admin duyệt phiếu mượn, trạng thái đổi thành `borrowed`, tính toán hạn trả tối đa 30 ngày. | ✅ PASS |
| Ràng buộc hạn mức mượn (Tối đa 5 cuốn) | Student | Hệ thống chặn mượn mới và báo lỗi khi student đạt giới hạn mượn 5 cuốn. | ✅ PASS |
| Chặn mượn sách khi có sách quá hạn | Student | Sinh viên có sách quá hạn sẽ bị chặn hoàn toàn chức năng đăng ký mượn sách mới. | ✅ PASS |
| Yêu cầu gia hạn sách (Renewal) | Student / Admin | Sinh viên gửi yêu cầu gia hạn, cờ `is_renew_pending = 1`. Admin phê duyệt thì hạn trả tăng thêm 30 ngày. | ✅ PASS |
| E2E Quy trình gia hạn phiếu mượn | Student / Admin | Student gửi yêu cầu gia hạn (`is_renew_pending=1`), Admin phê duyệt (`approve-renew`), cập nhật thành công hạn trả mới và tăng `renew_count` lên 1. | ✅ PASS |

---

### 1.4. Module Bảng tin & Kênh thảo luận (Announcement & Discussion)

| Test case | Role | Kết quả | Trạng thái |
|-----------|------|---------|------------|
| Quản lý Bảng tin (Announcement CRUD) | Admin | Admin CRUD bản tin, hệ thống tự động phân loại trạng thái hiển thị hoạt động/sắp tới/hết hạn/ẩn. | ✅ PASS |
| Kênh thảo luận (Public Chat) | Student / Admin | Độc giả gửi tin nhắn chat thành công, lưu cảm xúc (reactions) dạng JSON, Admin có quyền ghim tin nhắn nổi bật. | ✅ PASS |
| Kiểm duyệt nội dung tự động bằng AI | Student | Gửi tin nhắn chứa từ ngữ nhạy cảm bị AI chặn và từ chối lưu vào CSDL. | ✅ PASS |
| E2E Gửi tin nhắn lên Public Chat | Student | Gửi tin nhắn sạch thành công, tin nhắn hiển thị tức thì trên bảng tin và lưu thành công vào bảng `public_chats`. | ✅ PASS |
| E2E Kiểm duyệt & Fallback lọc từ cấm | Student | Tin nhắn chứa từ thô tục bị chặn ngay từ API, trả về mã lỗi 400 và không ghi nhận vào DB. | ✅ PASS |

---

### 1.5. Module Hỏi đáp & Hỗ trợ (Support Ticket)

| Test case | Role | Kết quả | Trạng thái |
|-----------|------|---------|------------|
| Gửi ticket hỗ trợ và Phản hồi | Student / Admin | Sinh viên gửi ticket hỗ trợ, Admin xem và phản hồi, định tuyến theo đúng vai trò không bị lỗi phân quyền. | ✅ PASS |
| E2E Trao đổi thông tin Ticket | Student / Admin | Student tạo ticket -> Admin trả lời -> Student phản hồi tiếp; toàn bộ chuỗi hội thoại được lưu vết chính xác trong `ticket_messages`. | ✅ PASS |

---

### 1.6. Module Cấu hình & Chế độ bảo trì (System Settings & Maintenance)

| Test case | Role | Kết quả | Trạng thái |
|-----------|------|---------|------------|
| Chế độ bảo trì (Maintenance Mode) | Admin / Guest / Student | Kích hoạt bảo trì chuyển hướng tất cả người dùng không phải admin sang trang bảo trì, tự động tắt khi hết hạn. | ✅ PASS |
| Quản lý danh mục thể loại | Admin | Đổi tên thể loại cập nhật cascading sang sách; Xóa thể loại bị chặn nếu vẫn còn sách thuộc thể loại đó. | ✅ PASS |

---

### 1.7. RESTful API Layer

| Test case | Role | Kết quả | Trạng thái |
|-----------|------|---------|------------|
| REST API Sách (`/api/books`) | Public / System | Lấy danh sách, chi tiết sách thành công dạng JSON. | ✅ PASS |
| REST API Thông báo (`/api/notifications`) | Admin / Student | Chặn unauthorized request, trả dữ liệu thông báo cá nhân hóa khi đã login. | ✅ PASS |
| REST API Trợ lý ảo tư vấn sách (`/api/books/chat`) | Student | Gọi Gemini API tư vấn gợi ý sách, áp dụng SHA-256 caching lưu vào `ai_responses_cache` để giảm độ trễ. | ✅ PASS |
| E2E Trích xuất Báo cáo Tài chính Excel | Admin | Gọi API export báo cáo, trả về đúng định dạng binary spreadsheet `.xlsx` kèm đầy đủ tiêu đề content-disposition. | ✅ PASS |

---

## 2. Tổng hợp Kết quả Toàn Hệ thống

*   **Tổng số PHPUnit Unit Tests:** 99/99 tests passed ✅
*   **Tổng số E2E & API Integration Tests:** 33/33 tests passed ✅
*   **Kết quả toàn hệ thống:**
    -   **PASS:** 132
    -   **FAIL:** 0
    -   **SKIP:** 0
