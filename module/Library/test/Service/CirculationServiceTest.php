<?php

declare(strict_types=1);

namespace LibraryTest\Service;

use DomainException;
use Library\Model\Entity\BorrowRecord;
use Library\Model\Entity\User;
use Library\Model\Table\BookTable;
use Library\Model\Table\BorrowTable;
use Library\Model\Table\UserTable;
use Library\Service\CirculationService;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Adapter\Driver\ConnectionInterface;
use Laminas\Db\Adapter\Driver\DriverInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Library\Service\CirculationService
 */
class CirculationServiceTest extends TestCase
{
    public function testBorrowBookCommitsTransactionWhenRequestIsValid(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('commit');
        $connection->expects(self::never())->method('rollback');

        $bookTable = $this->createMock(BookTable::class);
        $bookTable->expects(self::once())->method('decrementAvailability')->with(10);

        $borrowTable = $this->createMock(BorrowTable::class);
        $borrowTable->method('hasOverdueLoans')->willReturn(false);
        $borrowTable->method('countActiveLoansForUser')->willReturn(1);
        $borrowTable->method('hasActiveLoan')->willReturn(false);
        $borrowTable->expects(self::once())
            ->method('borrow')
            ->with(10, 3, '2026-04-01', '2026-04-15');

        $userTable = $this->createMock(UserTable::class);
        $userTable->method('getUser')->willReturn($this->studentUser(3));

        $service = new CirculationService(
            $this->createAdapter($connection),
            $bookTable,
            $borrowTable,
            $userTable
        );

        $service->borrowBook(10, 3, '2026-04-01', '2026-04-15');
    }

    public function testBorrowBookRejectsStudentWithOverdueLoanBeforeOpeningTransaction(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::never())->method('beginTransaction');

        $bookTable = $this->createMock(BookTable::class);
        $bookTable->expects(self::never())->method('decrementAvailability');

        $borrowTable = $this->createMock(BorrowTable::class);
        $borrowTable->method('hasOverdueLoans')->willReturn(true);
        $borrowTable->expects(self::never())->method('borrow');

        $userTable = $this->createMock(UserTable::class);
        $userTable->method('getUser')->willReturn($this->studentUser(9));

        $service = new CirculationService(
            $this->createAdapter($connection),
            $bookTable,
            $borrowTable,
            $userTable
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('đang có sách quá hạn');

        $service->borrowBook(2, 9, '2026-04-01', '2026-04-20');
    }

    public function testBorrowBookRollsBackWhenPersistenceFails(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::never())->method('commit');
        $connection->expects(self::once())->method('rollback');

        $bookTable = $this->createMock(BookTable::class);
        $bookTable->method('decrementAvailability')
            ->willThrowException(new DomainException('No stock'));

        $borrowTable = $this->createMock(BorrowTable::class);
        $borrowTable->method('hasOverdueLoans')->willReturn(false);
        $borrowTable->method('countActiveLoansForUser')->willReturn(0);
        $borrowTable->method('hasActiveLoan')->willReturn(false);
        $borrowTable->expects(self::never())->method('borrow');

        $userTable = $this->createMock(UserTable::class);
        $userTable->method('getUser')->willReturn($this->studentUser(4));

        $service = new CirculationService(
            $this->createAdapter($connection),
            $bookTable,
            $borrowTable,
            $userTable
        );

        $this->expectException(DomainException::class);
        $service->borrowBook(99, 4, '2026-04-01', '2026-04-10');
    }

    public function testReturnBookCommitsAndRestoresAvailability(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('commit');
        $connection->expects(self::never())->method('rollback');

        $record = new BorrowRecord();
        $record->id = 15;
        $record->bookId = 6;
        $record->status = 'borrowed';
        $record->returnDate = '2099-12-31';

        $bookTable = $this->createMock(BookTable::class);
        $bookTable->expects(self::once())->method('incrementAvailability')->with(6);

        $borrowTable = $this->createMock(BorrowTable::class);
        $borrowTable->method('getRecord')->with(15)->willReturn($record);
        $borrowTable->expects(self::once())->method('returnBook')->with(15);

        $service = new CirculationService(
            $this->createAdapter($connection),
            $bookTable,
            $borrowTable,
            $this->createMock(UserTable::class)
        );

        $service->returnBook(15);
    }

    public function testReturnBookRejectsAlreadyReturnedRecord(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::never())->method('commit');
        $connection->expects(self::once())->method('rollback');

        $record = new BorrowRecord();
        $record->id = 18;
        $record->bookId = 7;
        $record->status = 'returned';
        $record->returnDate = '2026-04-10';

        $bookTable = $this->createMock(BookTable::class);
        $bookTable->expects(self::never())->method('incrementAvailability');

        $borrowTable = $this->createMock(BorrowTable::class);
        $borrowTable->method('getRecord')->with(18)->willReturn($record);
        $borrowTable->expects(self::never())->method('returnBook');

        $service = new CirculationService(
            $this->createAdapter($connection),
            $bookTable,
            $borrowTable,
            $this->createMock(UserTable::class)
        );

        $this->expectException(DomainException::class);
        $service->returnBook(18);
    }

    private function createAdapter(ConnectionInterface $connection): AdapterInterface
    {
        $driver = $this->createMock(DriverInterface::class);
        $driver->method('getConnection')->willReturn($connection);

        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->method('getDriver')->willReturn($driver);

        return $adapter;
    }

    private function studentUser(int $id): User
    {
        $user = new User();
        $user->id = $id;
        $user->role = 'student';
        $user->username = 'student' . $id;
        $user->fullName = 'Student ' . $id;

        return $user;
    }

    public function testReturnBookRestoresBorrowLimitWhenThreeConsecutiveOnTimeReturns(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('commit');

        $record = new BorrowRecord();
        $record->id = 15;
        $record->bookId = 6;
        $record->userId = 3;
        $record->status = 'borrowed';
        $record->returnDate = '2099-12-31';

        $bookTable = $this->createMock(BookTable::class);
        $borrowTable = $this->createMock(BorrowTable::class);
        $borrowTable->method('getRecord')->with(15)->willReturn($record);
        
        $user = $this->studentUser(3);
        $user->borrowLimit = 4;
        
        $userTable = $this->createMock(UserTable::class);
        $userTable->method('getUser')->with(3)->willReturn($user);
        $userTable->expects(self::once())->method('saveUser')->with(self::callback(function($u) {
            return $u->borrowLimit === 5;
        }));

        $stmt = $this->createMock(\Laminas\Db\Adapter\Driver\StatementInterface::class);
        $result = $this->createMock(\Laminas\Db\Adapter\Driver\ResultInterface::class);
        
        $recentReturnsData = [
            ['returned_at' => '2026-06-01 10:00:00', 'return_date' => '2026-06-05'],
            ['returned_at' => '2026-06-02 10:00:00', 'return_date' => '2026-06-06'],
            ['returned_at' => '2026-06-03 10:00:00', 'return_date' => '2026-06-07'],
        ];
        
        $result->method('valid')->willReturnCallback(function() use (&$recentReturnsData) {
            return key($recentReturnsData) !== null;
        });
        $result->method('current')->willReturnCallback(function() use (&$recentReturnsData) {
            return current($recentReturnsData);
        });
        $result->method('next')->willReturnCallback(function() use (&$recentReturnsData) {
            next($recentReturnsData);
            return null;
        });
        $result->method('key')->willReturnCallback(function() use (&$recentReturnsData) {
            return key($recentReturnsData);
        });
        $result->method('rewind')->willReturnCallback(function() use (&$recentReturnsData) {
            reset($recentReturnsData);
            return null;
        });
        
        $stmt->method('execute')->willReturn($result);
        
        $notiStmt = $this->createMock(\Laminas\Db\Adapter\Driver\StatementInterface::class);
        $notiStmt->method('execute')->willReturn($this->createMock(\Laminas\Db\Adapter\Driver\ResultInterface::class));

        $adapter = $this->createMock(\Laminas\Db\Adapter\Adapter::class);
        $driver = $this->createMock(DriverInterface::class);
        $driver->method('getConnection')->willReturn($connection);
        $adapter->method('getDriver')->willReturn($driver);
        
        $adapter->method('query')->willReturn($stmt);
        $adapter->method('createStatement')->willReturn($notiStmt);

        $service = new CirculationService(
            $adapter,
            $bookTable,
            $borrowTable,
            $userTable
        );

        $service->returnBook(15);
    }

    public function testReturnBookDoesNotRestoreBorrowLimitWhenOneReturnIsLate(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('commit');

        $record = new BorrowRecord();
        $record->id = 15;
        $record->bookId = 6;
        $record->userId = 3;
        $record->status = 'borrowed';
        $record->returnDate = '2099-12-31';

        $bookTable = $this->createMock(BookTable::class);
        $borrowTable = $this->createMock(BorrowTable::class);
        $borrowTable->method('getRecord')->with(15)->willReturn($record);
        
        $user = $this->studentUser(3);
        $user->borrowLimit = 4;
        
        $userTable = $this->createMock(UserTable::class);
        $userTable->method('getUser')->with(3)->willReturn($user);
        // saveUser should NOT be called since one book was returned late
        $userTable->expects(self::never())->method('saveUser');

        $stmt = $this->createMock(\Laminas\Db\Adapter\Driver\StatementInterface::class);
        $result = $this->createMock(\Laminas\Db\Adapter\Driver\ResultInterface::class);
        
        $recentReturnsData = [
            ['returned_at' => '2026-06-01 10:00:00', 'return_date' => '2026-06-05'],
            ['returned_at' => '2026-06-10 10:00:00', 'return_date' => '2026-06-06'], // LATE!
            ['returned_at' => '2026-06-03 10:00:00', 'return_date' => '2026-06-07'],
        ];
        
        $result->method('valid')->willReturnCallback(function() use (&$recentReturnsData) {
            return key($recentReturnsData) !== null;
        });
        $result->method('current')->willReturnCallback(function() use (&$recentReturnsData) {
            return current($recentReturnsData);
        });
        $result->method('next')->willReturnCallback(function() use (&$recentReturnsData) {
            next($recentReturnsData);
            return null;
        });
        $result->method('key')->willReturnCallback(function() use (&$recentReturnsData) {
            return key($recentReturnsData);
        });
        $result->method('rewind')->willReturnCallback(function() use (&$recentReturnsData) {
            reset($recentReturnsData);
            return null;
        });
        
        $stmt->method('execute')->willReturn($result);
        
        $notiStmt = $this->createMock(\Laminas\Db\Adapter\Driver\StatementInterface::class);
        $notiStmt->method('execute')->willReturn($this->createMock(\Laminas\Db\Adapter\Driver\ResultInterface::class));

        $adapter = $this->createMock(\Laminas\Db\Adapter\Adapter::class);
        $driver = $this->createMock(DriverInterface::class);
        $driver->method('getConnection')->willReturn($connection);
        $adapter->method('getDriver')->willReturn($driver);
        
        $adapter->method('query')->willReturn($stmt);
        $adapter->method('createStatement')->willReturn($notiStmt);

        $service = new CirculationService(
            $adapter,
            $bookTable,
            $borrowTable,
            $userTable
        );

        $service->returnBook(15);
    }

    public function testReturnBookDoesNotRestoreBorrowLimitWhenLimitIsAlreadyFive(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('commit');

        $record = new BorrowRecord();
        $record->id = 15;
        $record->bookId = 6;
        $record->userId = 3;
        $record->status = 'borrowed';
        $record->returnDate = '2099-12-31';

        $bookTable = $this->createMock(BookTable::class);
        $borrowTable = $this->createMock(BorrowTable::class);
        $borrowTable->method('getRecord')->with(15)->willReturn($record);
        
        $user = $this->studentUser(3);
        $user->borrowLimit = 5; // ALREADY AT STANDARD MAX 5
        
        $userTable = $this->createMock(UserTable::class);
        $userTable->method('getUser')->with(3)->willReturn($user);
        // saveUser should NOT be called since the limit is already 5
        $userTable->expects(self::never())->method('saveUser');

        $stmt = $this->createMock(\Laminas\Db\Adapter\Driver\StatementInterface::class);
        $result = $this->createMock(\Laminas\Db\Adapter\Driver\ResultInterface::class);
        
        $recentReturnsData = [
            ['returned_at' => '2026-06-01 10:00:00', 'return_date' => '2026-06-05'],
            ['returned_at' => '2026-06-02 10:00:00', 'return_date' => '2026-06-06'],
            ['returned_at' => '2026-06-03 10:00:00', 'return_date' => '2026-06-07'],
        ];
        
        $result->method('valid')->willReturnCallback(function() use (&$recentReturnsData) {
            return key($recentReturnsData) !== null;
        });
        $result->method('current')->willReturnCallback(function() use (&$recentReturnsData) {
            return current($recentReturnsData);
        });
        $result->method('next')->willReturnCallback(function() use (&$recentReturnsData) {
            next($recentReturnsData);
            return null;
        });
        $result->method('key')->willReturnCallback(function() use (&$recentReturnsData) {
            return key($recentReturnsData);
        });
        $result->method('rewind')->willReturnCallback(function() use (&$recentReturnsData) {
            reset($recentReturnsData);
            return null;
        });
        
        $stmt->method('execute')->willReturn($result);
        
        $notiStmt = $this->createMock(\Laminas\Db\Adapter\Driver\StatementInterface::class);
        $notiStmt->method('execute')->willReturn($this->createMock(\Laminas\Db\Adapter\Driver\ResultInterface::class));

        $adapter = $this->createMock(\Laminas\Db\Adapter\Adapter::class);
        $driver = $this->createMock(DriverInterface::class);
        $driver->method('getConnection')->willReturn($connection);
        $adapter->method('getDriver')->willReturn($driver);
        
        $adapter->method('query')->willReturn($stmt);
        $adapter->method('createStatement')->willReturn($notiStmt);

        $service = new CirculationService(
            $adapter,
            $bookTable,
            $borrowTable,
            $userTable
        );

        $service->returnBook(15);
    }
}


