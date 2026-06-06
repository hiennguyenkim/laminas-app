<?php

declare(strict_types=1);

namespace Library\Controller;

use Library\Session\AuthSessionContainer;
use Library\Model\Table\UserTable;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Http\Response;
use Laminas\View\Model\ViewModel;
use DomainException;

/**
 * @psalm-suppress PropertyNotSetInConstructor
 * @psalm-suppress PossiblyUnusedMethod
 */
class FineController extends BaseController
{
    public function __construct(
        AuthSessionContainer $authSessionContainer,
        private UserTable $userTable,
        private AdapterInterface $db
    ) {
        parent::__construct($authSessionContainer);
    }

    public function indexAction(): Response|ViewModel
    {
        $loginRedirect = $this->requireLogin();
        if ($loginRedirect instanceof Response) {
            return $loginRedirect;
        }

        $currentUser = $this->currentUser();
        if ($currentUser === null || ($currentUser['role'] ?? '') !== 'student') {
            $this->flash()->addErrorMessage('Chỉ sinh viên mới được truy cập trang này.');
            return $this->redirect()->toRoute('announcements');
        }

        $userId = $currentUser['id'];

        // Fetch all fines for the student
        $sql = "SELECT * FROM fines WHERE user_id = ? ORDER BY status ASC, created_at DESC";
        $stmt = $this->db->createStatement($sql);
        $resultSet = $stmt->execute([$userId]);
        $fines = iterator_to_array($resultSet);

        return new ViewModel([
            'fines' => $fines,
        ]);
    }

    public function checkoutAction(): Response|ViewModel
    {
        $loginRedirect = $this->requireLogin();
        if ($loginRedirect instanceof Response) {
            return $loginRedirect;
        }

        $currentUser = $this->currentUser();
        if ($currentUser === null || ($currentUser['role'] ?? '') !== 'student') {
            $this->flash()->addErrorMessage('Chỉ sinh viên mới được truy cập trang này.');
            return $this->redirect()->toRoute('announcements');
        }

        $userId = $currentUser['id'];
        $fineId = $this->routeInt('id');

        // Fetch specific fine
        $sql = "SELECT * FROM fines WHERE fine_id = ? AND user_id = ? LIMIT 1";
        $stmt = $this->db->createStatement($sql);
        $fine = $stmt->execute([$fineId, $userId])->current();

        if (!$fine) {
            $this->flash()->addErrorMessage('Không tìm thấy khoản phạt yêu cầu.');
            return $this->redirect()->toRoute('student/fine');
        }

        if ($fine['status'] === 'paid') {
            $this->flash()->addInfoMessage('Khoản phạt này đã được thanh toán trước đó.');
            return $this->redirect()->toRoute('student/fine');
        }

        $request = $this->httpRequest();
        if ($request->isPost()) {
            $connection = $this->db->getDriver()->getConnection();
            $connection->beginTransaction();

            try {
                // 1. Update fine status to paid
                $updateSql = "UPDATE fines SET status = 'paid', paid_at = NOW() WHERE fine_id = ?";
                $this->db->query($updateSql)->execute([$fineId]);

                // 2. Check if student still has any other unpaid fines
                $checkSql = "SELECT COUNT(*) AS cnt FROM fines WHERE user_id = ? AND status = 'unpaid'";
                $checkStmt = $this->db->createStatement($checkSql);
                $checkRes = $checkStmt->execute([$userId])->current();
                $unpaidCount = (int)($checkRes['cnt'] ?? 0);

                $unlocked = false;
                if ($unpaidCount === 0) {
                    // 3. Unlock student account in database
                    $this->userTable->unlockUser($userId);

                    // 4. Restore standard borrow limit to 5
                    $userObj = $this->userTable->getUser($userId);
                    $userObj->borrowLimit = 5;
                    $this->userTable->saveUser($userObj);

                    // 5. Update logged-in session user status to reflect active immediately
                    $session = $this->authSession();
                    if (isset($session->user) && is_array($session->user)) {
                        $session->user['account_status'] = 'active';
                        $session->user['locked_until'] = '';
                    }

                    // 6. Insert notification for account unlocking
                    $notifySql = "INSERT INTO notifications (user_id, sender_id, title, message, type, related_id, created_at) 
                                  VALUES (?, 0, 'Tài khoản đã mở khóa', ?, 'system', ?, NOW())";
                    $notifyMessage = "Tài khoản của bạn đã được mở khóa và khôi phục hạn mức mượn 5 cuốn sau khi hoàn tất nộp phạt.";
                    $this->db->query($notifySql)->execute([$userId, $notifyMessage, $fineId]);

                    $unlocked = true;
                }

                $connection->commit();

                if ($unlocked) {
                    $this->flash()->addSuccessMessage('Thanh toán thành công! Tài khoản của bạn đã được mở khóa hoạt động trở lại.');
                } else {
                    $this->flash()->addSuccessMessage('Thanh toán thành công. Vui lòng hoàn tất nộp phạt các khoản còn lại để mở khóa tài khoản.');
                }

                return $this->redirect()->toRoute('student/fine');
            } catch (\Throwable $e) {
                try {
                    $connection->rollback();
                } catch (\Throwable $rollbackError) {}

                $this->flash()->addErrorMessage('Đã xảy ra lỗi trong quá trình xử lý thanh toán: ' . $e->getMessage());
                return $this->redirect()->toRoute('student/fine');
            }
        }

        return new ViewModel([
            'fine' => $fine,
        ]);
    }
}
