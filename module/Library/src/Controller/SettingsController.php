<?php

declare(strict_types=1);

namespace Library\Controller;

use Library\Session\AuthSessionContainer;
use Laminas\Http\Response;
use Laminas\View\Model\ViewModel;
use Laminas\Db\Adapter\AdapterInterface;

class SettingsController extends BaseController
{
    public function __construct(
        AuthSessionContainer $authSessionContainer,
        private AdapterInterface $dbAdapter
    ) {
        parent::__construct($authSessionContainer);
    }

    // ── Main settings page ────────────────────────────────────────────
    public function indexAction(): Response|ViewModel
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $announcements = iterator_to_array(
            $this->dbAdapter->query(
                "SELECT a.*, u.full_name AS creator_name
                 FROM announcements a
                 LEFT JOIN users u ON a.created_by = u.user_id
                 ORDER BY a.created_at DESC"
            )->execute()
        );

        // Detect current logo (uploaded file takes priority)
        $logoFile = null;
        $uploadDir = 'public/img/uploads/';
        foreach (['logo.png', 'logo.jpg', 'logo.jpeg', 'logo.svg', 'logo.webp'] as $f) {
            if (file_exists($uploadDir . $f)) {
                $logoFile = $f;
                break;
            }
        }

        return new ViewModel([
            'announcements' => $announcements,
            'logoFile'      => $logoFile,
        ]);
    }

    // ── Upload logo ───────────────────────────────────────────────────
    public function logoAction(): Response
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('library/settings');
        }

        $uploadDir = getcwd() . '/public/img/uploads/';
        $file = $_FILES['logo'] ?? null;

        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $this->flash()->addErrorMessage('Không nhận được file upload. Vui lòng thử lại.');
            return $this->redirect()->toRoute('library/settings');
        }

        $maxSize = 2 * 1024 * 1024; // 2 MB
        if ($file['size'] > $maxSize) {
            $this->flash()->addErrorMessage('File quá lớn. Tối đa 2MB.');
            return $this->redirect()->toRoute('library/settings');
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['png', 'jpg', 'jpeg', 'svg', 'webp'];
        if (!in_array($ext, $allowed)) {
            $this->flash()->addErrorMessage('Định dạng không hỗ trợ. Chỉ chấp nhận: ' . implode(', ', $allowed));
            return $this->redirect()->toRoute('library/settings');
        }

        // Remove old logo files
        foreach ($allowed as $oldExt) {
            $oldFile = $uploadDir . 'logo.' . $oldExt;
            if (file_exists($oldFile)) {
                unlink($oldFile);
            }
        }

        $destPath = $uploadDir . 'logo.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            $this->flash()->addErrorMessage('Lỗi khi lưu file. Kiểm tra quyền thư mục public/img/uploads/.');
            return $this->redirect()->toRoute('library/settings');
        }

        $this->flash()->addSuccessMessage('Đã cập nhật logo thư viện thành công.');
        return $this->redirect()->toRoute('library/settings');
    }

    // ── Create announcement ───────────────────────────────────────────
    public function announcementAction(): Response
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('library/settings');
        }

        $currentUser = $this->currentUser();
        $data = $this->postData();

        $title     = trim((string)($data['title'] ?? ''));
        $content   = trim((string)($data['content'] ?? ''));
        $type      = $data['type'] ?? 'general';
        $startDate = !empty($data['start_date']) ? $data['start_date'] : null;
        $endDate   = !empty($data['end_date'])   ? $data['end_date']   : null;
        $isActive  = isset($data['is_active']) ? 1 : 0;

        $allowedTypes = ['event', 'contest', 'holiday', 'general'];
        if (!in_array($type, $allowedTypes)) {
            $type = 'general';
        }

        if ($title === '' || $content === '') {
            $this->flash()->addErrorMessage('Tiêu đề và nội dung bản tin không được để trống.');
            return $this->redirect()->toRoute('library/settings');
        }

        try {
            $this->dbAdapter->query(
                "INSERT INTO announcements (title, content, type, start_date, end_date, is_active, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$title, $content, $type, $startDate, $endDate, $isActive, $currentUser['id']]
            );
            $this->flash()->addSuccessMessage('Đã đăng bản tin "' . htmlspecialchars($title) . '" thành công.');
        } catch (\Throwable $e) {
            $this->flash()->addErrorMessage('Lỗi hệ thống: ' . $e->getMessage());
        }

        return $this->redirect()->toRoute('library/settings');
    }

    // ── Toggle active/inactive announcement ───────────────────────────
    public function toggleAction(): Response
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $id = (int)$this->params()->fromRoute('id', 0);
        if ($id > 0) {
            $this->dbAdapter->query(
                "UPDATE announcements SET is_active = NOT is_active WHERE id = ?",
                [$id]
            );
            $this->flash()->addSuccessMessage('Đã cập nhật trạng thái bản tin.');
        }

        return $this->redirect()->toRoute('library/settings');
    }

    // ── Delete announcement ───────────────────────────────────────────
    public function deleteAnnouncementAction(): Response
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $id = (int)$this->params()->fromRoute('id', 0);
        if ($id > 0) {
            $this->dbAdapter->query("DELETE FROM announcements WHERE id = ?", [$id]);
            $this->flash()->addSuccessMessage('Đã xóa bản tin.');
        }

        return $this->redirect()->toRoute('library/settings');
    }
}
