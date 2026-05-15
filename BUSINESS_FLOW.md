# Luồng Nghiệp vụ - Hệ thống Quản lý Thư viện HCMUE

## Sơ đồ Luồng Nghiệp vụ

```mermaid
---
id: 085f72ff-6996-4a51-9b6f-aa31f435961c
---
graph TD
    %% Khởi đầu
    Start((Người dùng)) -->|Truy cập URL| Routing{Kiểm tra trạng thái}

    %% Luồng Guest
    Routing -->|Chưa đăng nhập / Guest| PublicCatalog[Xem Danh Mục Sách Công Khai]
    PublicCatalog -->|Nhấn Đăng nhập| Login[Trang Đăng Nhập / Đăng Ký]
    
    %% Luồng Đăng nhập
    Login -->|Xác thực Thành công| AuthCheck{Phân quyền Role}

    %% Luồng Sinh viên
    AuthCheck -->|Student| StudentDashboard[Khu vực Sinh viên]
    StudentDashboard --> ViewBooks[Xem & Tìm Kiếm Sách]
    StudentDashboard --> BorrowSelf[Tự đăng ký mượn sách]
    StudentDashboard --> ViewHistory[Xem lịch sử phiếu mượn cá nhân]
    
    BorrowSelf -->|Ghi nhận DB| CreateBorrowRecord[Tạo phiếu mượn mới]

    %% Luồng Admin
    AuthCheck -->|Admin| AdminDashboard[Dashboard Tổng Quan]
    AdminDashboard --> BookManage[Quản lý Kho Sách]
    AdminDashboard --> TransactionManage[Quản lý Mượn / Trả Sách]
    
    BookManage --> AddBook[Thêm Sách mới]
    BookManage --> EditBook[Sửa thông tin Sách]
    BookManage --> DeleteBook[Xóa Sách]
    
    TransactionManage --> BorrowForStudent[Lập phiếu mượn cho Sinh viên]
    TransactionManage --> ReturnBook[Xác nhận Trả sách]
    
    BorrowForStudent -->|Ghi nhận DB| CreateBorrowRecord
    ReturnBook -->|Cập nhật DB| UpdateBorrowRecord[Đóng phiếu mượn & Hoàn sách]

    %% Database Operations
    CreateBorrowRecord --> |1. Insert borrow_records<br/>2. Update books status=borrowed| Database[(Cơ sở dữ liệu)]
    UpdateBorrowRecord --> |1. Update borrow_records status=returned<br/>2. Update books status=available| Database
    AddBook -.-> Database
    EditBook -.-> Database
    DeleteBook -.-> Database
```

## Tóm tắt Luồng Nghiệp vụ

### 🔐 Luồng Xác thực
- Người dùng đăng nhập bằng tài khoản
- Xác thực quyền (Admin hoặc Sinh viên)
- Vào Dashboard tương ứng

### 👨‍💼 Luồng Admin
- **Quản lý Sách**: Thêm/Sửa/Xóa/Xem sách
- **Quản lý Người dùng**: Xem danh sách, quản lý quyền
- **Xem Báo cáo**: Thống kê sách, giao dịch

### 👨‍🎓 Luồng Sinh viên

**Mượn sách:**
1. Tìm kiếm → Chọn sách
2. Nếu sách còn → Tạo BorrowRecord (Status: borrowed)
3. Cập nhật số lượng sách (Quantity - 1)
4. Xác nhận mượn thành công

**Trả sách:**
1. Xem danh sách sách đang mượn
2. Nhấn trả → Cập nhật BorrowRecord (Status: returned)
3. Cập nhật số lượng sách (Quantity + 1)
4. Xác nhận trả thành công

**Xử lý quá hạn:**
- Hệ thống kiểm tra ngày trả
- Nếu quá hạn → Status: overdue

## Các thành phần liên quan

### Controllers (Điều khiển)
- `AuthController` - Xử lý đăng nhập/đăng xuất
- `DashboardController` - Hiển thị dashboard
- `BookController` - Quản lý sách
- `UserController` - Quản lý người dùng (Admin)
- `TransactionController` - Quản lý giao dịch mượn/trả

### Services (Dịch vụ)
- `CirculationService` - Xử lý logic mượn/trả sách

### Models (Dữ liệu)
- `User` - Thông tin người dùng
- `Book` - Thông tin sách
- `BorrowRecord` - Ghi nhận mượn/trả

### Database Tables
- `users` - Danh sách người dùng (role: admin, student)
- `books` - Danh sách sách (status: available, borrowed, unavailable)
- `borrow_records` - Ghi nhận mượn/trả (status: borrowed, returned, overdue)
