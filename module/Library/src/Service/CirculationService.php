<?php

declare(strict_types=1);

namespace Library\Service;

use DateTimeImmutable;
use DomainException;
use Library\Model\Table\BookTable;
use Library\Model\Table\BorrowTable;
use Library\Model\Table\UserTable;
use Library\Service\MailService;
use Laminas\Db\Adapter\AdapterInterface;

class CirculationService
{
    private const MAX_LOAN_DAYS = 30;

    public function __construct(
        private AdapterInterface $adapter,
        private BookTable $bookTable,
        private BorrowTable $borrowTable,
        private UserTable $userTable,
        private ?MailService $mailService = null
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

            // FINAL SAFETY CHECK: Ensure user is still active (Race condition prevention)
            $borrower = $this->userTable->getUser((int)$record->userId);
            if ($borrower->isLocked()) {
                throw new DomainException('Không thể duyệt phiếu: Tài khoản sinh viên hiện đang bị khóa.');
            }

            // BUG #4 Fix: Kiểm tra sinh viên có sách quá hạn không (race condition)
            if ($this->borrowTable->hasOverdueLoans((int)$record->userId)) {
                throw new DomainException('Không thể duyệt phiếu: Sinh viên đang có sách quá hạn chưa xử lý.');
            }

            // BUG #5 Fix: Kiểm tra giới hạn mượn (race condition — pending khác có thể đã được duyệt)
            $activeLoans = $this->borrowTable->countActiveLoansForUser((int)$record->userId);
            // Trừ đi 1 vì phiếu pending hiện tại đang được tính trong activeLoans
            if (($activeLoans - 1) >= $borrower->borrowLimit) {
                throw new DomainException(sprintf(
                    'Không thể duyệt phiếu: Sinh viên đã đạt hạn mức mượn tối đa (%d cuốn).',
                    $borrower->borrowLimit
                ));
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

            $this->borrowTable->approve($recordId, $borrowDate, $returnDate);

            // Mark existing pending notifications for this record as read
            try {
                $this->adapter->query("UPDATE notifications SET is_read = 1 WHERE related_id = ? AND type = 'borrow'")->execute([$recordId]);
            } catch (\Throwable $e) {}

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

            // Gửi email cho sinh viên
            if ($this->mailService !== null) {
                try {
                    $subject = "[Thư viện HDPE] Đăng ký mượn sách được phê duyệt";
                    $body = "Chào " . $borrower->fullName . ",\n\n"
                          . "Yêu cầu mượn cuốn sách '" . $record->bookTitle . "' của bạn đã được phê duyệt thành công.\n"
                          . "Hạn trả: " . ($returnDate ?? $record->returnDate) . "\n\n"
                          . "Vui lòng đến thư viện để nhận sách.\n\n"
                          . "Trân trọng,\n"
                          . "Thư viện HDPE";
                    $this->mailService->sendEmail($borrower->email, $borrower->fullName, $subject, $body);
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

    public function rejectBorrow(int $recordId): void
    {
        $connection = $this->adapter->getDriver()->getConnection();
        $connection->beginTransaction();

        try {
            $record = $this->borrowTable->getRecord($recordId);

            if ($record->status !== 'pending') {
                throw new DomainException('Phiếu mượn này đã được duyệt hoặc xử lý trước đó.');
            }

            // Delete the pending borrow request (or mark as rejected if you want to keep history, but here it's deleted)
            $this->borrowTable->reject($recordId);

            // Notify student
            try {
                $stmt = $this->adapter->createStatement(
                    "INSERT INTO notifications (user_id, sender_id, title, message, type, related_id) 
                     VALUES (?, NULL, 'Từ chối yêu cầu mượn sách', ?, 'borrow_alert', ?)"
                );
                $stmt->execute([
                    $record->userId,
                    "Yêu cầu mượn cuốn sách '" . $record->bookTitle . "' của bạn đã bị từ chối.",
                    $recordId
                ]);
            } catch (\Throwable $e) {}

            // Gửi email cho sinh viên
            if ($this->mailService !== null) {
                try {
                    $borrower = $this->userTable->getUser((int)$record->userId);
                    $subject = "[Thư viện HDPE] Từ chối yêu cầu mượn sách";
                    $body = "Chào " . $borrower->fullName . ",\n\n"
                          . "Yêu cầu mượn cuốn sách '" . $record->bookTitle . "' của bạn đã bị từ chối.\n\n"
                          . "Trân trọng,\n"
                          . "Thư viện HDPE";
                    $this->mailService->sendEmail($borrower->email, $borrower->fullName, $subject, $body);
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

            // Notify student
            try {
                $stmt = $this->adapter->createStatement(
                    "INSERT INTO notifications (user_id, sender_id, title, message, type, related_id) 
                     VALUES (?, NULL, 'Xác nhận trả sách', ?, 'borrow_approved', ?)"
                );
                $stmt->execute([
                    $record->userId,
                    "Cảm ơn bạn đã trả cuốn sách '" . $record->bookTitle . "'. Thủ thư đã xác nhận việc trả sách.",
                    $recordId
                ]);
            } catch (\Throwable $e) {}

            // Xử lý phạt theo số lần trả muộn (Hạng mục 2 nâng cấp)
            // Tính tổng số lần từng trả muộn trong lịch sử
            $lateCount = $this->borrowTable->countReturnedLateForUser((int)$record->userId);
            
            if ($lateCount >= 3) {
                $lockDays = 0;
                $reasonPrefix = "";
                $newBorrowLimit = 5;

                if ($lateCount >= 3 && $lateCount <= 4) {
                    $lockDays = 1;
                    $reasonPrefix = "Mốc 1 (3-4 lần)";
                    $newBorrowLimit = 4;
                } elseif ($lateCount == 5) {
                    $lockDays = 3;
                    $reasonPrefix = "Mốc 2 (5 lần)";
                    $newBorrowLimit = 2;
                } elseif ($lateCount == 6) {
                    $lockDays = 7;
                    $reasonPrefix = "Mốc 3 (6 lần)";
                    $newBorrowLimit = 1;
                } else {
                    // Từ 7 lần trở lên: Khóa vĩnh viễn
                    $lockUntil = '9999-12-31';
                    $reason = "Vi phạm Mốc 4: Trả sách trễ hạn từ 7 lần trở lên ({$lateCount} lần). Tạm khóa tài khoản VĨNH VIỄN.";
                    $this->userTable->lockUser((int)$record->userId, $reason, $lockUntil);
                    
                    // Notify student about lock
                    try {
                        $stmt = $this->adapter->createStatement(
                            "INSERT INTO notifications (user_id, sender_id, title, message, type, related_id) 
                             VALUES (?, NULL, 'Tài khoản bị khóa', ?, 'borrow_alert', ?)"
                        );
                        $stmt->execute([$record->userId, $reason, $recordId]);
                    } catch (\Throwable $e) {}

                    $connection->commit();
                    return;
                }

                if ($lockDays > 0) {
                    $lockUntil = date('Y-m-d', strtotime("+$lockDays days"));
                    $reason = "Vi phạm {$reasonPrefix}: Trả sách trễ hạn {$lateCount} lần trong lịch sử. Tạm khóa quyền mượn sách {$lockDays} ngày (đến hết " . date('d/m/Y', strtotime($lockUntil)) . ").";
                    $this->userTable->lockUser((int)$record->userId, $reason, $lockUntil);

                    // Cập nhật borrow_limit của user
                    try {
                        $user = $this->userTable->getUser((int)$record->userId);
                        $user->borrowLimit = $newBorrowLimit;
                        $this->userTable->saveUser($user);
                    } catch (\Throwable $e) {}

                    // Notify student about temporary lock
                    try {
                        $stmt = $this->adapter->createStatement(
                            "INSERT INTO notifications (user_id, sender_id, title, message, type, related_id) 
                             VALUES (?, NULL, 'Tài khoản bị tạm khóa', ?, 'borrow_alert', ?)"
                        );
                        $stmt->execute([$record->userId, $reason, $recordId]);
                    } catch (\Throwable $e) {}
                }
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

    public function cancelRequest(int $recordId, int $userId): void
    {
        $connection = $this->adapter->getDriver()->getConnection();
        $connection->beginTransaction();

        try {
            $record = $this->borrowTable->getRecord($recordId);

            if ($record->status !== 'pending') {
                throw new DomainException('Chỉ có thể hủy yêu cầu đang ở trạng thái chờ duyệt.');
            }

            if ($record->userId !== $userId) {
                throw new DomainException('Bạn không có quyền hủy yêu cầu này.');
            }

            // Restore availability first
            $this->bookTable->incrementAvailability($record->bookId);
            
            // Delete the record
            $this->borrowTable->reject($recordId);

            // Notify admin
            try {
                $adminRow = $this->adapter->query("SELECT user_id FROM users WHERE role = 'admin' LIMIT 1")->execute()->current();
                if ($adminRow) {
                    $stmt = $this->adapter->createStatement(
                        "INSERT INTO notifications (user_id, sender_id, title, message, type, related_id) 
                         VALUES (?, ?, 'Yêu cầu mượn đã bị hủy', ?, 'borrow_alert', ?)"
                    );
                    $stmt->execute([
                        $adminRow['user_id'],
                        $userId,
                        "Độc giả đã hủy yêu cầu mượn cuốn '" . $record->bookTitle . "'.",
                        $recordId
                    ]);
                }
            } catch (\Throwable $e) {}

            $connection->commit();
        } catch (\Throwable $throwable) {
            try {
                $connection->rollback();
            } catch (\Throwable) {}
            throw $throwable;
        }
    }

    public function reportLostBook(int $recordId): void
    {
        $connection = $this->adapter->getDriver()->getConnection();
        $connection->beginTransaction();

        try {
            $record = $this->borrowTable->getRecord($recordId);

            if (in_array($record->status, ['returned', 'lost'])) {
                throw new DomainException('Phiếu mượn này đã kết thúc.');
            }

            // 1. Update borrow record status to 'lost'
            $this->adapter->query("UPDATE borrow_records SET status = 'lost', returned_at = NOW() WHERE borrow_id = ?")->execute([$recordId]);

            // 2. We do NOT increment availability. Instead, if it was the last copy, we might mark book as 'lost'.
            // For simplicity, we just keep the quantity as it is (it was already decremented when borrowed).
            // But we can check if total quantity is now 0 and update status.
            $book = $this->bookTable->getBook($record->bookId);
            if ($book->quantity === 0) {
                $this->adapter->query("UPDATE books SET status = 'lost' WHERE book_id = ?")->execute([$record->bookId]);
            }

            // 3. Penalty: Lock user account permanently
            $reason = sprintf(
                "Làm mất sách: '%s'. Tài khoản bị khóa VĨNH VIỄN cho đến khi hoàn tất thủ tục đền bù.",
                $record->bookTitle
            );
            $this->userTable->lockUser($record->userId, $reason, '9999-12-31');

            // 4. Notify student
            try {
                $stmt = $this->adapter->createStatement(
                    "INSERT INTO notifications (user_id, sender_id, title, message, type, related_id) 
                     VALUES (?, NULL, 'Tài khoản bị khóa vĩnh viễn', ?, 'borrow_alert', ?)"
                );
                $stmt->execute([
                    $record->userId,
                    "Bạn đã báo mất cuốn '" . $record->bookTitle . "'. " . $reason,
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
