<?php

declare(strict_types=1);

namespace Library\Service;

use DateTimeImmutable;
use DomainException;
use Library\Model\Table\BookTable;
use Library\Model\Table\BorrowTable;
use Library\Model\Table\UserTable;
use Laminas\Db\Adapter\AdapterInterface;

class CirculationService
{
    private const MAX_LOAN_DAYS = 30;

    public function __construct(
        private AdapterInterface $adapter,
        private BookTable $bookTable,
        private BorrowTable $borrowTable,
        private UserTable $userTable
    ) {
    }

    public function borrowBook(int $bookId, int $userId, string $borrowDate, string $returnDate, bool $isApproved = true): void
    {
        $borrowAt = $this->parseDate($borrowDate, 'Ngày mượn không hợp lệ.');
        $returnAt = $this->parseDate($returnDate, 'Hạn trả không hợp lệ.');

        if ($returnAt < $borrowAt) {
            throw new DomainException('Hạn trả phải sau hoặc bằng ngày mượn.');
        }

        $loanDays = (int) $borrowAt->diff($returnAt)->format('%a');
        if ($loanDays > self::MAX_LOAN_DAYS) {
            throw new DomainException(sprintf(
                'Thời hạn mượn tối đa là %d ngày.',
                self::MAX_LOAN_DAYS
            ));
        }

        $borrower = $this->userTable->getUser($userId);
        if ($borrower->role !== 'student') {
            throw new DomainException('Chỉ tài khoản sinh viên mới được lập phiếu mượn.');
        }

        if ($borrower->isLocked()) {
            $reason = $borrower->lockReason !== '' ? ' (' . $borrower->lockReason . ')' : '';
            throw new DomainException('Tài khoản sinh viên đã bị khóa mượn sách' . $reason . '.');
        }

        if ($this->borrowTable->hasOverdueLoans($userId)) {
            throw new DomainException('Sinh viên này đang có sách quá hạn, vui lòng xử lý quá hạn trước khi mượn mới.');
        }

        if ($this->borrowTable->countActiveLoansForUser($userId) >= $borrower->borrowLimit) {
            throw new DomainException(sprintf(
                'Sinh viên này đã đạt hạn mức mượn tối đa (%d cuốn).',
                $borrower->borrowLimit
            ));
        }

        $connection = $this->adapter->getDriver()->getConnection();
        $connection->beginTransaction();

        try {
            if ($this->borrowTable->hasActiveLoan($userId, $bookId)) {
                throw new DomainException('Sinh viên này đang mượn cuốn sách đã chọn.');
            }

            if ($isApproved) {
                $this->bookTable->decrementAvailability($bookId);
                $this->borrowTable->borrow($bookId, $userId, $borrowDate, $returnDate);
            } else {
                // Bắt đầu áp dụng Giữ chỗ chắc chắn (Hard Reservation)
                $this->bookTable->decrementAvailability($bookId);
                $this->borrowTable->requestBorrow($bookId, $userId, $borrowDate, $returnDate);
                $recordId = (int)$this->adapter->getDriver()->getLastGeneratedValue();
                try {
                    $book = $this->bookTable->getBook($bookId);
                    $stmt = $this->adapter->createStatement(
                        "INSERT INTO notifications (user_id, sender_id, title, message, type, related_id) 
                         VALUES (NULL, ?, 'Yêu cầu mượn sách mới', ?, 'borrow', ?)"
                    );
                    $stmt->execute([
                        $userId,
                        "Độc giả '" . ($borrower->fullName ?? 'Sinh viên') . "' vừa đăng ký mượn cuốn '" . $book->title . "'.",
                        $recordId
                    ]);
                } catch (\Throwable $e) {}
            }
            $connection->commit();
        } catch (\Throwable $throwable) {
            try {
                $connection->rollback();
            } catch (\Throwable) {
            }

            throw $throwable;
        }
    }

    public function approveBorrow(int $recordId, ?string $borrowDate = null, ?string $returnDate = null): void
    {
        $connection = $this->adapter->getDriver()->getConnection();
        $connection->beginTransaction();

        try {
            $record = $this->borrowTable->getRecord($recordId);

            if ($record->status !== 'pending') {
                throw new DomainException('Phiếu mượn này đã được duyệt hoặc xử lý trước đó.');
            }

            if ($borrowDate === null || trim($borrowDate) === '') {
                $borrowDate = date('Y-m-d');
            }
            if ($returnDate === null || trim($returnDate) === '') {
                $returnDate = date('Y-m-d', strtotime('+14 days'));
            }

            $borrowAt = $this->parseDate($borrowDate, 'Ngày mượn không hợp lệ.');
            $returnAt = $this->parseDate($returnDate, 'Hạn trả không hợp lệ.');

            if ($returnAt < $borrowAt) {
                throw new DomainException('Hạn trả phải sau hoặc bằng ngày mượn.');
            }

            $loanDays = (int) $borrowAt->diff($returnAt)->format('%a');
            if ($loanDays > self::MAX_LOAN_DAYS) {
                throw new DomainException(sprintf(
                    'Thời hạn mượn tối đa là %d ngày.',
                    self::MAX_LOAN_DAYS
                ));
            }

            // Decrement book availability and change status to borrowed
            $this->bookTable->decrementAvailability($record->bookId);
            $this->borrowTable->approve($recordId, $borrowDate, $returnDate);

            // Tự động gửi thông báo cho sinh viên
            try {
                $db = $this->adapter;
                $adminId = null;
                try {
                    $adminRow = $db->query("SELECT user_id FROM users WHERE role = 'admin' LIMIT 1")->execute()->current();
                    if ($adminRow) {
                        $adminId = (int)$adminRow['user_id'];
                    }
                } catch (\Throwable $e) {}

                $stmt = $db->createStatement(
                    "INSERT INTO notifications (user_id, sender_id, title, message, type, related_id) 
                     VALUES (?, ?, ?, ?, 'borrow_approved', ?)"
                );
                $stmt->execute([
                    $record->userId,
                    $adminId,
                    'Đăng ký mượn sách được phê duyệt',
                    "Yêu cầu mượn cuốn sách '" . $record->bookTitle . "' của bạn đã được phê duyệt. Hạn trả: " . ($returnDate ?? $record->returnDate),
                    $recordId
                ]);
            } catch (\Throwable $e) {}

            $connection->commit();
        } catch (\Throwable $throwable) {
            try {
                $connection->rollback();
            } catch (\Throwable) {
            }

            throw $throwable;
        }
    }

    public function rejectBorrow(int $recordId): void
    {
        $connection = $this->adapter->getDriver()->getConnection();
        $connection->beginTransaction();

        try {
            $record = $this->borrowTable->getRecord($recordId);

            if ($record->status !== 'pending') {
                throw new DomainException('Phiếu mượn này đã được duyệt hoặc xử lý trước đó.');
            }

            // Delete the pending borrow request
            $this->borrowTable->reject($recordId);

            $connection->commit();
        } catch (\Throwable $throwable) {
            try {
                $connection->rollback();
            } catch (\Throwable) {
            }

            throw $throwable;
        }
    }

    public function returnBook(int $recordId): void
    {
        $connection = $this->adapter->getDriver()->getConnection();
        $connection->beginTransaction();

        try {
            $record = $this->borrowTable->getRecord($recordId);

            if ($record->status === 'returned') {
                throw new DomainException('Phiếu mượn này đã được xác nhận trả trước đó.');
            }

            if (! in_array($record->status, ['borrowed', 'overdue'], true) && ! $record->isOverdue()) {
                throw new DomainException('Chỉ phiếu đang mượn mới có thể xác nhận trả sách.');
            }

            $this->borrowTable->returnBook($recordId);
            $this->bookTable->incrementAvailability($record->bookId);
            $connection->commit();
        } catch (\Throwable $throwable) {
            try {
                $connection->rollback();
            } catch (\Throwable) {
            }

            throw $throwable;
        }
    }

    public function renewBook(int $recordId, int $userId, bool $isAdminDirect = false): void
    {
        $connection = $this->adapter->getDriver()->getConnection();
        $connection->beginTransaction();

        try {
            $record = $this->borrowTable->getRecord($recordId);

            // Kiểm tra trạng thái tài khoản nếu là sinh viên tự yêu cầu (Hạng mục 2)
            if (!$isAdminDirect) {
                $user = $this->userTable->getUser((int)$record->userId);
                if ($user->isLocked()) {
                    throw new DomainException('Tài khoản của bạn hiện đang bị khóa. Vui lòng liên hệ thủ thư để được hỗ trợ.');
                }
            }

            if (!$isAdminDirect && $record->userId !== $userId) {
                throw new DomainException('Bạn không có quyền gia hạn phiếu mượn này.');
            }

            if (!in_array($record->status, ['borrowed', 'overdue'])) {
                throw new DomainException('Chỉ sách đang mượn hoặc quá hạn mới được phép gia hạn.');
            }

            if ($record->renewCount > 0) {
                throw new DomainException('Mỗi cuốn sách chỉ được phép gia hạn 1 lần.');
            }

            if ($record->isRenewPending && !$isAdminDirect) {
                throw new DomainException('Yêu cầu gia hạn của bạn đang chờ thủ thư duyệt.');
            }

            if ($isAdminDirect) {
                // Admin renews directly
                $oldReturnDate = $this->parseDate($record->returnDate, 'Hạn trả cũ không hợp lệ.');
                $newReturnDate = $oldReturnDate->modify('+14 days')->format('Y-m-d');
                $this->borrowTable->renewBook($recordId, $newReturnDate);
            } else {
                // Student requests renewal
                $this->borrowTable->requestRenew($recordId);
                // Gửi thông báo cho admin
                try {
                    $adminRow = $this->adapter->query("SELECT user_id FROM users WHERE role = 'admin' LIMIT 1")->execute()->current();
                    if ($adminRow) {
                        $stmt = $this->adapter->createStatement(
                            "INSERT INTO notifications (user_id, sender_id, title, message, type, related_id) 
                             VALUES (?, ?, 'Yêu cầu gia hạn sách', ?, 'borrow', ?)"
                        );
                        $stmt->execute([
                            $adminRow['user_id'],
                            $userId,
                            "Sinh viên vừa gửi yêu cầu gia hạn cho cuốn '" . $record->bookTitle . "'.",
                            $recordId
                        ]);
                    }
                } catch (\Throwable $e) {}
            }
            
            $connection->commit();
        } catch (\Throwable $throwable) {
            try {
                $connection->rollback();
            } catch (\Throwable) {}
            throw $throwable;
        }
    }

    public function approveRenew(int $recordId): void
    {
        $connection = $this->adapter->getDriver()->getConnection();
        $connection->beginTransaction();

        try {
            $record = $this->borrowTable->getRecord($recordId);

            if (!$record->isRenewPending) {
                throw new DomainException('Không có yêu cầu gia hạn nào đang chờ duyệt cho phiếu này.');
            }

            $oldReturnDate = $this->parseDate($record->returnDate, 'Hạn trả cũ không hợp lệ.');
            $newReturnDate = $oldReturnDate->modify('+14 days')->format('Y-m-d');
            
            $this->borrowTable->approveRenew($recordId, $newReturnDate);

            // Notify student
            try {
                $stmt = $this->adapter->createStatement(
                    "INSERT INTO notifications (user_id, sender_id, title, message, type, related_id) 
                     VALUES (?, NULL, 'Gia hạn thành công', ?, 'borrow_approved', ?)"
                );
                $stmt->execute([
                    $record->userId,
                    "Yêu cầu gia hạn cuốn '" . $record->bookTitle . "' đã được phê duyệt. Hạn trả mới: " . date('d/m/Y', strtotime($newReturnDate)),
                    $recordId
                ]);
            } catch (\Throwable $e) {}

            $connection->commit();
        } catch (\Throwable $throwable) {
            try {
                $connection->rollback();
            } catch (\Throwable) {}
            throw $throwable;
        }
    }

    public function rejectRenew(int $recordId): void
    {
        $connection = $this->adapter->getDriver()->getConnection();
        $connection->beginTransaction();

        try {
            $record = $this->borrowTable->getRecord($recordId);

            if (!$record->isRenewPending) {
                throw new DomainException('Không có yêu cầu gia hạn nào đang chờ duyệt cho phiếu này.');
            }

            $this->borrowTable->rejectRenew($recordId);

            // Notify student
            try {
                $stmt = $this->adapter->createStatement(
                    "INSERT INTO notifications (user_id, sender_id, title, message, type, related_id) 
                     VALUES (?, NULL, 'Từ chối gia hạn', ?, 'borrow_alert', ?)"
                );
                $stmt->execute([
                    $record->userId,
                    "Yêu cầu gia hạn cuốn '" . $record->bookTitle . "' đã bị từ chối. Vui lòng mang sách trả đúng hạn.",
                    $recordId
                ]);
            } catch (\Throwable $e) {}

            $connection->commit();
        } catch (\Throwable $throwable) {
            try {
                $connection->rollback();
            } catch (\Throwable) {}
            throw $throwable;
        }
    }

    private function parseDate(string $dateValue, string $errorMessage): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $dateValue);
        if (
            ! $parsed instanceof DateTimeImmutable
            || $parsed->format('Y-m-d') !== $dateValue
        ) {
            throw new DomainException($errorMessage);
        }

        return $parsed;
    }
}
