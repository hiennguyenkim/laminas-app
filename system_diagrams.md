# 📊 Sơ Đồ Hệ Thống — Quản Lý Thư Viện HCMUE

> **Stack:** Laminas (Zend) Framework · PHP · MySQL · MVC Pattern

---

## 1. Use Case Diagram

```plantuml
@startuml
left to right direction

' Định nghĩa hình người (actor)
actor "Khách (Guest)" as Guest
actor "Sinh viên (Student)" as Student
actor "Quản trị viên (Admin)" as Admin

' Ranh giới hệ thống
package "Hệ Thống Quản Lý Thư Viện HCMUE" {
    usecase "Xem danh mục sách" as UC_View
    usecase "Đăng nhập" as UC_Login
    usecase "Đăng ký tài khoản" as UC_Register
    usecase "Đăng xuất" as UC_Logout
    
    usecase "Tìm kiếm & Lọc sách" as UC_Search
    usecase "Xem chi tiết sách" as UC_ViewDetail
    usecase "Tự đăng ký mượn sách" as UC_Borrow
    usecase "Xem lịch sử mượn cá nhân" as UC_History
    
    usecase "Quản lý sách" as UC_ManageBook
    usecase "Lập phiếu mượn" as UC_Lend
    usecase "Xác nhận trả sách" as UC_Return
    usecase "Quản lý người dùng" as UC_ManageUser
    usecase "Xem Dashboard Thống kê" as UC_Dashboard
}

' 
Guest -- UC_View
Guest -- UC_Login
Guest -- UC_Register

Student -- UC_Search
Student -- UC_ViewDetail
Student -- UC_Borrow
Student -- UC_History
Student -- UC_Logout

Admin -- UC_ManageBook
Admin -- UC_Lend
Admin -- UC_Return
Admin -- UC_ManageUser
Admin -- UC_Dashboard
Admin -- UC_Logout
Admin -- UC_Search
Admin -- UC_ViewDetail

'
UC_Borrow .> UC_Login : <<include>>
UC_History .> UC_Login : <<include>>
UC_ManageBook .> UC_Login : <<include>>
UC_Lend .> UC_Login : <<include>>
UC_Return .> UC_Login : <<include>>
UC_ManageUser .> UC_Login : <<include>>
UC_Dashboard .> UC_Login : <<include>>
UC_Logout .> UC_Login : <<include>>

@enduml
```

---

## 2. Sequence Diagram — Đăng nhập

```mermaid
sequenceDiagram
    actor User as Người dùng
    participant Browser as Trình duyệt
    participant AuthCtrl as AuthController
    participant LoginForm as LoginForm
    participant UserTable as UserTable
    participant Session as AuthSessionContainer
    participant DB as Database

    User->>Browser: Truy cập /auth/login
    Browser->>AuthCtrl: GET loginAction()
    AuthCtrl->>Session: currentUser() — kiểm tra session
    Session-->>AuthCtrl: null (chưa đăng nhập)
    AuthCtrl-->>Browser: Hiển thị form đăng nhập

    User->>Browser: Nhập username + password → Submit
    Browser->>AuthCtrl: POST loginAction()
    AuthCtrl->>LoginForm: setData($postData)
    LoginForm-->>AuthCtrl: isValid() = true
    AuthCtrl->>UserTable: getByUsername($username)
    UserTable->>DB: SELECT * FROM users WHERE username=?
    DB-->>UserTable: User row
    UserTable-->>AuthCtrl: User entity

    AuthCtrl->>AuthCtrl: password_verify($password, $hash)
    alt Xác thực thành công
        AuthCtrl->>Session: user = [id, username, role, ...]
        AuthCtrl-->>Browser: redirect → /book (Admin) | /catalog (Student)
    else Sai mật khẩu
        AuthCtrl-->>Browser: Flash error + hiển thị lại form
    end
```

---

## 3. Sequence Diagram — Mượn sách

```mermaid
sequenceDiagram
    actor Actor as Student / Admin
    participant Browser as Trình duyệt
    participant TransCtrl as TransactionController
    participant BorrowForm as BorrowForm
    participant CircSvc as CirculationService
    participant BookTable as BookTable
    participant BorrowTable as BorrowTable
    participant UserTable as UserTable
    participant DB as Database

    Actor->>Browser: GET /transaction/borrow
    Browser->>TransCtrl: borrowAction() — GET
    TransCtrl->>BookTable: fetchAll() — lấy sách còn quantity > 0
    TransCtrl->>UserTable: fetchStudentOptions()
    TransCtrl-->>Browser: Render form mượn sách

    Actor->>Browser: Chọn sách, sinh viên, ngày → Submit
    Browser->>TransCtrl: borrowAction() — POST
    TransCtrl->>BorrowForm: setData() + isValid()
    BorrowForm-->>TransCtrl: valid

    TransCtrl->>CircSvc: borrowBook(bookId, userId, borrowDate, returnDate)
    CircSvc->>CircSvc: Validate ngày (MAX_LOAN_DAYS = 30)
    CircSvc->>UserTable: getUser(userId) — kiểm tra role = student
    CircSvc->>BorrowTable: hasOverdueLoans(userId)
    CircSvc->>BorrowTable: countActiveLoansForUser(userId) — MAX = 5
    CircSvc->>DB: BEGIN TRANSACTION
    CircSvc->>BorrowTable: hasActiveLoan(userId, bookId)
    CircSvc->>BookTable: decrementAvailability(bookId)
    Note over BookTable,DB: UPDATE books SET quantity=quantity-1,<br/>status='borrowed' WHERE book_id=?
    CircSvc->>BorrowTable: borrow(bookId, userId, dates)
    Note over BorrowTable,DB: INSERT INTO borrow_records ...
    CircSvc->>DB: COMMIT

    CircSvc-->>TransCtrl: void (thành công)
    TransCtrl-->>Browser: Flash success + redirect /transaction
```

---

## 4. Sequence Diagram — Trả sách

```mermaid
sequenceDiagram
    actor Admin as Admin
    participant Browser as Trình duyệt
    participant TransCtrl as TransactionController
    participant CircSvc as CirculationService
    participant BorrowTable as BorrowTable
    participant BookTable as BookTable
    participant DB as Database

    Admin->>Browser: Xem danh sách phiếu mượn
    Browser->>TransCtrl: indexAction() — GET
    TransCtrl->>BorrowTable: fetchAllWithDetails(filters)
    DB-->>BorrowTable: Danh sách borrow_records JOIN books, users
    TransCtrl-->>Browser: Hiển thị bảng giao dịch

    Admin->>Browser: Nhấn "Xác nhận trả" → POST
    Browser->>TransCtrl: returnAction() — POST /transaction/return/{id}
    TransCtrl->>TransCtrl: requireAdmin() — kiểm tra quyền
    TransCtrl->>CircSvc: returnBook(recordId)

    CircSvc->>DB: BEGIN TRANSACTION
    CircSvc->>BorrowTable: getRecord(recordId)
    DB-->>BorrowTable: BorrowRecord entity
    CircSvc->>CircSvc: Kiểm tra status ≠ 'returned'
    CircSvc->>BorrowTable: returnBook(recordId)
    Note over BorrowTable,DB: UPDATE borrow_records<br/>SET status='returned', returned_at=NOW()
    CircSvc->>BookTable: incrementAvailability(bookId)
    Note over BookTable,DB: UPDATE books SET quantity=quantity+1,<br/>status='available'
    CircSvc->>DB: COMMIT

    CircSvc-->>TransCtrl: void
    TransCtrl-->>Browser: Flash success + redirect /transaction
```

---

## 5. Sequence Diagram — Đăng ký tài khoản

```mermaid
sequenceDiagram
    actor Guest as Khách
    participant Browser as Trình duyệt
    participant AuthCtrl as AuthController
    participant RegForm as RegisterForm
    participant UserTable as UserTable
    participant DB as Database

    Guest->>Browser: GET /auth/register
    Browser->>AuthCtrl: registerAction() — GET
    AuthCtrl-->>Browser: Hiển thị form đăng ký

    Guest->>Browser: Nhập thông tin → Submit
    Browser->>AuthCtrl: POST registerAction()
    AuthCtrl->>RegForm: setData() + isValid()

    AuthCtrl->>UserTable: usernameExists($username)
    DB-->>UserTable: false (chưa tồn tại)
    AuthCtrl->>UserTable: emailExists($email)
    DB-->>UserTable: false

    AuthCtrl->>AuthCtrl: password_hash($password, PASSWORD_DEFAULT)
    AuthCtrl->>UserTable: saveUser(User entity, $hash)
    Note over UserTable,DB: INSERT INTO users (username, email, password,<br/>full_name, role='student')

    AuthCtrl-->>Browser: Flash "Đăng ký thành công" + redirect /auth/login
```

---

## 6. Class Diagram

```mermaid
classDiagram
    class BaseController {
        -AuthSessionContainer $authSession
        +currentUser() array|null
        +requireLogin() Response|null
        +requireAdmin() Response|null
        +isAdmin() bool
        +flash() FlashMessenger
    }

    class AuthController {
        -UserTable $userTable
        -FormElementManager $formElementManager
        -SessionManager $sessionManager
        +loginAction() Response|ViewModel
        +registerAction() Response|ViewModel
        +logoutAction() Response
        -redirectToRoleHome(role) Response
    }

    class BookController {
        -BookTable $bookTable
        -BorrowTable $borrowTable
        -FormElementManager $formElementManager
        +indexAction() Response|ViewModel
        +viewAction() Response|ViewModel
        +addAction() Response|ViewModel
        +editAction() Response|ViewModel
        +deleteAction() Response
    }

    class TransactionController {
        -BorrowTable $borrowTable
        -BookTable $bookTable
        -UserTable $userTable
        -CirculationService $circulationService
        +indexAction() Response|ViewModel
        +borrowAction() Response|ViewModel
        +returnAction() Response
    }

    class UserController {
        -UserTable $userTable
        -BorrowTable $borrowTable
        -FormElementManager $formElementManager
        +indexAction() Response|ViewModel
        +viewAction() Response|ViewModel
        +addAction() Response|ViewModel
        +editAction() Response|ViewModel
        +deleteAction() Response
    }

    class CirculationService {
        -MAX_ACTIVE_LOANS_PER_USER = 5
        -MAX_LOAN_DAYS = 30
        -AdapterInterface $adapter
        -BookTable $bookTable
        -BorrowTable $borrowTable
        -UserTable $userTable
        +borrowBook(bookId, userId, borrowDate, returnDate) void
        +returnBook(recordId) void
        -parseDate(value, msg) DateTimeImmutable
    }

    class BookTable {
        -TableGateway $tableGateway
        +fetchPage(filters, page, perPage) ResultSet
        +fetchAll() ResultSet
        +fetchCategories() array
        +getBook(id) Book
        +saveBook(Book) void
        +deleteBook(id) void
        +decrementAvailability(id) void
        +incrementAvailability(id) void
        +countFiltered(filters) int
        +getSummary() array
    }

    class BorrowTable {
        +fetchAllWithDetails(filters, userId) ResultSet
        +getRecord(id) BorrowRecord
        +borrow(bookId, userId, dates) void
        +returnBook(id) void
        +hasActiveLoan(userId, bookId) bool
        +hasActiveBorrowForBook(bookId) bool
        +countActiveLoansForUser(userId) int
        +hasOverdueLoans(userId) bool
        +hasBorrowHistoryForUser(userId) bool
        +getSummary(userId) array
    }

    class UserTable {
        +getByUsername(username) User|null
        +getUser(id) User
        +fetchAll(filters) ResultSet
        +saveUser(User, hash) void
        +deleteUser(id) void
        +usernameExists(username, excludeId) bool
        +emailExists(email, excludeId) bool
        +countAll() int
        +countByRole(role) int
        +fetchStudentOptions() ResultSet
    }

    class User {
        +int $id
        +string $username
        +string $email
        +string $fullName
        +string $role
        +DateTime $createdAt
        +exchangeArray(data) void
        +getArrayCopy() array
    }

    class Book {
        +int $id
        +string $title
        +string $author
        +string $isbn
        +string $category
        +int $quantity
        +string $status
        +DateTime $createdAt
        +exchangeArray(data) void
    }

    class BorrowRecord {
        +int $id
        +int $bookId
        +int $userId
        +Date $borrowDate
        +Date $returnDate
        +string $status
        +DateTime $returnedAt
        +isOverdue() bool
        +exchangeArray(data) void
    }

    class AuthSessionContainer {
        +array|null $user
    }

    BaseController <|-- AuthController
    BaseController <|-- BookController
    BaseController <|-- TransactionController
    BaseController <|-- UserController

    AuthController --> UserTable
    AuthController --> AuthSessionContainer

    BookController --> BookTable
    BookController --> BorrowTable

    TransactionController --> BorrowTable
    TransactionController --> BookTable
    TransactionController --> UserTable
    TransactionController --> CirculationService

    UserController --> UserTable
    UserController --> BorrowTable

    CirculationService --> BookTable
    CirculationService --> BorrowTable
    CirculationService --> UserTable

    BookTable --> Book
    BorrowTable --> BorrowRecord
    UserTable --> User
```

---

## 7. Entity-Relationship Diagram (ERD)

```mermaid
erDiagram
    USERS {
        int user_id PK
        varchar username UK
        varchar email UK
        varchar password
        varchar full_name
        enum role "admin|student"
        timestamp created_at
    }

    BOOKS {
        int book_id PK
        varchar title
        varchar author
        varchar isbn
        varchar category
        smallint quantity
        enum status "available|borrowed|unavailable"
        datetime created_at
    }

    BORROW_RECORDS {
        int borrow_id PK
        int book_id FK
        int user_id FK
        date borrow_date
        date return_date
        enum status "borrowed|returned|overdue"
        datetime returned_at
        datetime created_at
    }

    USERS ||--o{ BORROW_RECORDS : "mượn sách"
    BOOKS ||--o{ BORROW_RECORDS : "được mượn"
```

---

## 8. Activity Diagram — Luồng mượn sách (Business Rules)

```mermaid
flowchart TD
    Start([Yêu cầu mượn sách]) --> CheckLogin{Đã đăng nhập?}
    CheckLogin -- Chưa --> RedirectLogin[Chuyển trang đăng nhập]
    CheckLogin -- Rồi --> ValidateDate{Ngày hợp lệ?\nreturn_date >= borrow_date}

    ValidateDate -- Không --> ErrDate[Lỗi: Hạn trả sai]
    ValidateDate -- Đúng --> CheckMaxDays{Số ngày ≤ 30?}

    CheckMaxDays -- Không --> ErrDays[Lỗi: Vượt 30 ngày]
    CheckMaxDays -- Đúng --> CheckRole{user.role = 'student'?}

    CheckRole -- Không --> ErrRole[Lỗi: Chỉ sinh viên được mượn]
    CheckRole -- Đúng --> CheckOverdue{Còn sách quá hạn?}

    CheckOverdue -- Có --> ErrOverdue[Lỗi: Xử lý quá hạn trước]
    CheckOverdue -- Không --> CheckMaxLoans{Đang mượn < 5 quyển?}

    CheckMaxLoans -- Không --> ErrMaxLoans[Lỗi: Đã mượn tối đa 5 quyển]
    CheckMaxLoans -- Đúng --> CheckDuplicate{Đã mượn sách này chưa?}

    CheckDuplicate -- Rồi --> ErrDuplicate[Lỗi: Đã mượn cuốn này]
    CheckDuplicate -- Chưa --> BeginTx[BEGIN TRANSACTION]

    BeginTx --> DecrBook["BookTable::decrementAvailability()\nquantity - 1"]
    DecrBook --> InsertRecord["BorrowTable::borrow()\nINSERT borrow_records status='borrowed'"]
    InsertRecord --> CommitTx[COMMIT]
    CommitTx --> Success([✅ Mượn sách thành công])
```

---

## 9. Activity Diagram — Luồng trả sách

```mermaid
flowchart TD
    Start([Admin chọn "Trả sách"]) --> CheckAdmin{isAdmin()?}
    CheckAdmin -- Không --> Forbidden[403 Redirect]
    CheckAdmin -- Đúng --> CheckPost{POST request?}

    CheckPost -- Không --> Redirect[Redirect về danh sách]
    CheckPost -- Đúng --> BeginTx[BEGIN TRANSACTION]

    BeginTx --> GetRecord["BorrowTable::getRecord(id)"]
    GetRecord --> CheckStatus{status = 'returned'?}

    CheckStatus -- Rồi --> ErrReturned[Lỗi: Đã trả trước đó]
    CheckStatus -- Chưa --> UpdateRecord["BorrowTable::returnBook()\nstatus='returned', returned_at=NOW()"]

    UpdateRecord --> IncrBook["BookTable::incrementAvailability()\nquantity + 1"]
    IncrBook --> Commit[COMMIT]
    Commit --> Success([✅ Trả sách thành công])
```

---

## 10. Component Diagram — Kiến trúc hệ thống

```mermaid
graph TB
    subgraph Client["🌐 Client Layer"]
        Browser["Trình duyệt"]
    end

    subgraph Laminas["⚙️ Laminas MVC Framework"]
        Router["Router\n/auth /book /transaction /user"]
        
        subgraph Controllers["Controllers"]
            AuthCtrl["AuthController"]
            BookCtrl["BookController"]
            TransCtrl["TransactionController"]
            UserCtrl["UserController"]
            HomeCtrl["HomeController"]
        end

        subgraph Forms["Forms"]
            LoginForm["LoginForm"]
            RegisterForm["RegisterForm"]
            BookForm["BookForm"]
            BorrowForm["BorrowForm"]
            UserForm["UserForm"]
        end

        subgraph Services["Services"]
            CircSvc["CirculationService\n(Business Logic)"]
        end

        subgraph Models["Model Layer"]
            BookTable["BookTable"]
            BorrowTable["BorrowTable"]
            UserTable["UserTable"]
        end

        subgraph Session["Session"]
            AuthSession["AuthSessionContainer"]
        end

        subgraph Views["View Templates"]
            AuthViews["auth/login\nauth/register"]
            BookViews["book/index\nbook/view\nbook/form"]
            TxViews["transaction/index\ntransaction/borrow"]
            UserViews["user/index\nuser/form"]
            Layout["layout/default"]
        end
    end

    subgraph Data["🗄️ Data Layer"]
        MySQL[("MySQL\nlibrary_db")]
    end

    Browser -->|HTTP Request| Router
    Router --> AuthCtrl & BookCtrl & TransCtrl & UserCtrl & HomeCtrl
    
    AuthCtrl --> LoginForm & RegisterForm
    BookCtrl --> BookForm
    TransCtrl --> BorrowForm
    UserCtrl --> UserForm

    AuthCtrl --> UserTable
    BookCtrl --> BookTable & BorrowTable
    TransCtrl --> CircSvc
    UserCtrl --> UserTable & BorrowTable

    CircSvc --> BookTable & BorrowTable & UserTable

    BookTable & BorrowTable & UserTable -->|Laminas DB Adapter| MySQL

    AuthCtrl & BookCtrl & TransCtrl & UserCtrl --> AuthSession
    AuthCtrl & BookCtrl & TransCtrl & UserCtrl --> Views
    Views --> Browser
```

---

## 11. State Diagram — Trạng thái sách (Book Status)

```mermaid
stateDiagram-v2
    [*] --> available : Thêm sách mới (quantity > 0)
    available --> borrowed : borrowBook() — quantity = 0
    available --> unavailable : Admin đánh dấu không khả dụng
    borrowed --> available : returnBook() — quantity += 1
    unavailable --> available : Admin cập nhật quantity > 0
    borrowed --> [*] : Xóa sách (chặn nếu còn active loan)
    available --> [*] : Xóa sách
```

---

## 12. State Diagram — Trạng thái phiếu mượn (BorrowRecord Status)

```mermaid
stateDiagram-v2
    [*] --> borrowed : borrowBook() — INSERT borrow_records
    borrowed --> returned : returnBook() — Admin xác nhận trả
    borrowed --> overdue : Hệ thống kiểm tra return_date < TODAY
    overdue --> returned : returnBook() — Admin xác nhận trả muộn
    returned --> [*]
```

---

## Tóm tắt phân quyền

| Chức năng | Guest | Student | Admin |
|---|:---:|:---:|:---:|
| Xem danh mục sách công khai | ✅ | ✅ | ✅ |
| Đăng nhập / Đăng ký | ✅ | — | — |
| Tìm kiếm & lọc sách | — | ✅ | ✅ |
| Tự đăng ký mượn sách | — | ✅ | — |
| Xem lịch sử mượn cá nhân | — | ✅ | ✅ |
| Quản lý sách (CRUD) | — | — | ✅ |
| Lập phiếu mượn cho sinh viên | — | — | ✅ |
| Xác nhận trả sách | — | — | ✅ |
| Quản lý người dùng (CRUD) | — | — | ✅ |
| Xem tổng quan dashboard | — | — | ✅ |

