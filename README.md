# HCMUE Library — Hệ thống Quản lý Thư viện

Ứng dụng quản lý thư viện xây dựng trên **Laminas MVC Skeleton** theo kiến trúc MVC chuẩn. Repo hiện được chuẩn hóa theo module `Library` để tránh lệch schema và lỗi bootstrap.

## Yêu cầu hệ thống

| Phần mềm | Phiên bản |
|---|---|
| PHP | ≥ 8.1 (khuyến nghị 8.2) |
| PHP extension | `intl` (khuyến nghị bật trong cả CLI và FPM/Apache) |
| MySQL / MariaDB | ≥ 8.0 |
| Composer | ≥ 2.x |

## Khởi động nhanh

### 1. Cài đặt dependencies
```bash
composer install
```

### 2. Tạo Database
```bash
# Sử dụng MySQL CLI
mysql --default-character-set=utf8mb4 -u root -p < database.sql

# Hoặc import file database.sql qua phpMyAdmin
```

### 3. Cấu hình Database
Chỉnh sửa `config/autoload/local.php`:
```php
return [
    'db' => [
        'hostname' => 'localhost',
        'database' => 'library_db',
        'username' => 'root',
        'password' => 'your_password',
        'charset'  => 'utf8mb4',
        'collation'=> 'utf8mb4_unicode_ci',
    ],
];
```

### Sửa lỗi font tiếng Việt (nếu dữ liệu đã bị lỗi)
Nếu dữ liệu đang hiển thị kiểu `Nguyá»…n` thay vì `Nguyễn`, chạy script sửa encoding:
```bash
mysql --default-character-set=utf8mb4 -u root -p < database_fix_encoding.sql
```

### Đồng bộ DB theo kịch bản MVC (users có email)
Nếu bạn đang nâng cấp từ DB cũ, chạy thêm:
```bash
mysql --default-character-set=utf8mb4 -u root -p < database_align_scenario.sql
```

### Migrate khóa chính theo nghiệp vụ (khuyến nghị cho DB cũ)
Nếu DB cũ còn dùng cột `id`, chạy thêm:
```bash
mysql --default-character-set=utf8mb4 -u root -p < database_migrate_primary_keys.sql
```
Sau migration:
- `users.user_id` là khóa chính.
- `books.book_id` là khóa chính.
- `borrow_records.borrow_id` là khóa chính.

### 4. Chạy ứng dụng

**Cách 1 — PHP Built-in Server (phát triển):**
```bash
# Windows XAMPP
C:\xampp\php\php.exe -S localhost:8000 -t public

# Linux / Mac
php -S localhost:8000 -t public
```

**Cách 2 — XAMPP Apache (Virtual Host):**
```apache
<VirtualHost *:80>
    ServerName library.local
    DocumentRoot "D:/PHP/public"
    <Directory "D:/PHP/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Truy cập: `http://localhost:8000/`
Mặc định hệ thống sẽ chuyển hướng về trang danh mục công khai: `http://localhost:8000/books`
Trang này hiển thị danh sách sách trước khi đăng nhập, nút `Đăng nhập` nằm ở góc trên bên phải.

**Tài khoản mặc định:**
- Admin: `admin` / `Admin@123`
- Sinh viên: `student1` ... `student10` / `Admin@123`

---

## Cấu trúc Module Library

```
module/Library/
├── config/
│   └── module.config.php      ← Routes + DI factory bindings
├── src/
│   ├── Module.php             ← Entry point
│   ├── Controller/
│   │   ├── AuthController.php        ← Login/Logout
│   │   ├── BookController.php        ← CRUD sách
│   │   ├── DashboardController.php   ← Tổng quan
│   │   └── TransactionController.php ← Mượn/Trả
│   ├── Model/
│   │   ├── Entity/
│   │   │   ├── Book.php
│   │   │   ├── User.php
│   │   │   └── BorrowRecord.php
│   │   └── Table/
│   │       ├── BookTable.php         ← Inventory management
│   │       ├── UserTable.php
│   │       └── BorrowTable.php       ← JOIN queries, overdue detection
│   ├── Form/
│   │   ├── BookForm.php              ← ISBN validation
│   │   ├── LoginForm.php
│   │   └── BorrowForm.php            ← Date validation
│   └── Factory/
│       ├── Controller/               ← Controller factories
│       ├── Form/                     ← Form factories (FormElementManager)
│       └── Table/                    ← TableGateway factories
└── view/
    └── library/
        ├── auth/login.phtml
        ├── dashboard/index.phtml
        ├── book/{index,add}.phtml
        └── transaction/{index,borrow}.phtml
```

## Cách hệ thống xử lý Dependency Injection

Laminas sử dụng **ServiceManager** để quản lý toàn bộ dependencies:

```
HTTP Request
    │
    ▼
public/index.php       ← Điểm vào duy nhất
    │
    ▼
ServiceManager         ← Container chứa tất cả services
    │ inject
    ▼
Controller::__construct(BookTable $table)   ← DI qua Constructor
    │ factory creates
    ▼
BookTableFactory       ← Lấy Adapter từ ServiceManager
    │ inject
    ▼
BookTable(TableGateway)← Nhận Adapter, không biết gì về config
    │ uses
    ▼
Laminas\Db\Adapter     ← Đọc từ config/autoload/global.php + local.php
```

**Tại sao cần Factory?**

```php
// Cách cũ (khó test, tạo coupling):
class BookController {
    public function __construct() {
        $pdo = new PDO('mysql:...');           // ❌ hardcoded
        $this->table = new BookTable($pdo);    // ❌ manual creation
    }
}

// Laminas DI (có thể mock, testable):
class BookController {
    public function __construct(BookTable $table) { // ✅ injected
        $this->table = $table;
    }
}

class BookControllerFactory {
    public function __invoke(ContainerInterface $c): BookController {
        return new BookController($c->get(BookTable::class)); // ✅ ServiceManager resolves
    }
}
```

Ngoài Controller/Table/Service, project hiện cũng đăng ký Form qua `form_elements` để controller không còn `new Form(...)` trực tiếp.

## API Routes

| URL | Method | Controller | Mô tả |
|---|---|---|---|
| `/` | GET | HomeController | Điều hướng đến `/books` (catalog công khai) |
| `/books` | GET | BookController | Danh mục sách công khai (guest xem được, góc phải có nút đăng nhập) |
| `/admin` | GET | HomeController | Entry nội bộ khu quản trị (guest sẽ chuyển sang đăng nhập) |
| `/admin/auth/login` | GET/POST | AuthController | Đăng nhập |
| `/admin/auth/register` | GET/POST | AuthController | Đăng ký |
| `/admin/auth/logout` | GET | AuthController | Đăng xuất |
| `/admin/dashboard` | GET | DashboardController | Tổng quan |
| `/admin/books` | GET | BookController | Danh mục/Quản lý sách trong khu đăng nhập |
| `/admin/books/add` | GET/POST | BookController | Thêm sách (admin) |
| `/admin/books/edit/:id` | GET/POST | BookController | Sửa sách (admin) |
| `/admin/books/delete/:id` | POST | BookController | Xóa sách (admin) |
| `/admin/borrow` | GET | TransactionController | Danh sách phiếu mượn/trả |
| `/admin/borrow/borrow` | GET/POST | TransactionController | Lập phiếu mượn (admin hoặc sinh viên cho chính mình) |
| `/admin/borrow/return/:id` | POST | TransactionController | Xác nhận trả (admin) |

## Phân quyền nghiệp vụ

- `Guest`: chỉ xem danh mục `/books`, không được mượn/sửa/xóa.
- `Student`: xem danh mục, mượn sách cho chính tài khoản của mình, xem phiếu mượn cá nhân.
- `Admin`: toàn quyền quản lý sách, người dùng, mượn/trả cho sinh viên.

## Ghi chú vận hành

- `Application` đã được tắt khỏi `config/modules.config.php`; phần đang hoạt động và được duy trì là `Library`.
- Cache config mặc định đã tắt để tránh tình trạng sửa module/config xong nhưng ứng dụng vẫn đọc cấu hình cũ trong `data/cache`.
