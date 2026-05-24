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
