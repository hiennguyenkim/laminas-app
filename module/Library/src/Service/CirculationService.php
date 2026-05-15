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
    private const MAX_ACTIVE_LOANS_PER_USER = 5;
    private const MAX_LOAN_DAYS = 30;

    public function __construct(
        private AdapterInterface $adapter,
        private BookTable $bookTable,
        private BorrowTable $borrowTable,
        private UserTable $userTable
    ) {
    }

    public function borrowBook(int $bookId, int $userId, string $borrowDate, string $returnDate): void
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

        if ($this->borrowTable->hasOverdueLoans($userId)) {
            throw new DomainException('Sinh viên này đang có sách quá hạn, vui lòng xử lý quá hạn trước khi mượn mới.');
        }

        if ($this->borrowTable->countActiveLoansForUser($userId) >= self::MAX_ACTIVE_LOANS_PER_USER) {
            throw new DomainException(sprintf(
                'Mỗi sinh viên chỉ được mượn tối đa %d cuốn cùng lúc.',
                self::MAX_ACTIVE_LOANS_PER_USER
            ));
        }

        $connection = $this->adapter->getDriver()->getConnection();
        $connection->beginTransaction();

        try {
            if ($this->borrowTable->hasActiveLoan($userId, $bookId)) {
                throw new DomainException('Sinh viên này đang mượn cuốn sách đã chọn.');
            }

            $this->bookTable->decrementAvailability($bookId);
            $this->borrowTable->borrow($bookId, $userId, $borrowDate, $returnDate);
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
