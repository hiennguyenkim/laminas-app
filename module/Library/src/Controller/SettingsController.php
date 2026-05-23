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

        // 1. Logo check
        $logoFile = null;
        $publicDir = getcwd();
        if (basename($publicDir) !== 'public') {
            $publicDir .= '/public';
        }
        $uploadDir = $publicDir . '/img/uploads/';
        foreach (['logo.png', 'logo.jpg', 'logo.jpeg', 'logo.svg', 'logo.webp'] as $f) {
            if (file_exists($uploadDir . $f)) {
                $logoFile = $f;
                break;
            }
        }

        // 2. Fetch maintenance configuration
        $maintenanceMode = '0';
        $maintenanceUntil = null;
        try {
            $stmt = $this->dbAdapter->query("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
            
            $resMode = iterator_to_array($stmt->execute(['maintenance_mode']));
            $maintenanceMode = count($resMode) > 0 ? $resMode[0]['setting_value'] : '0';

            $resUntil = iterator_to_array($stmt->execute(['maintenance_until']));
            $maintenanceUntil = count($resUntil) > 0 ? $resUntil[0]['setting_value'] : null;
        } catch (\Throwable $e) {
            // ignore
        }

        return new ViewModel([
            'logoFile'         => $logoFile,
            'maintenanceMode'  => $maintenanceMode,
            'maintenanceUntil' => $maintenanceUntil,
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

        $publicDir = getcwd();
        if (basename($publicDir) !== 'public') {
            $publicDir .= '/public';
        }
        $uploadDir = $publicDir . '/img/uploads/';

        // Ensure directory exists
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

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

    // ── Maintenance mode settings ────────────────────────────────────
    public function maintenanceAction(): Response
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('library/settings');
        }

        $data = $this->postData();
        $mode = isset($data['maintenance_mode']) ? '1' : '0';
        $until = !empty($data['maintenance_until']) ? $data['maintenance_until'] : null;

        // If maintenance mode is enabled, check if the time is in the future
        if ($mode === '1') {
            if (empty($until)) {
                $this->flash()->addErrorMessage('Vui lòng thiết lập thời gian kết thúc bảo trì.');
                return $this->redirect()->toRoute('library/settings');
            }
            if (strtotime($until) <= time()) {
                $this->flash()->addErrorMessage('Thời gian kết thúc bảo trì phải ở tương lai.');
                return $this->redirect()->toRoute('library/settings');
            }
        }

        try {
            $stmtCheck = $this->dbAdapter->query("SELECT COUNT(*) as count FROM system_settings WHERE setting_key = ?");
            
            $countMode = (int)$stmtCheck->execute(['maintenance_mode'])->current()['count'];
            if ($countMode > 0) {
                $this->dbAdapter->query("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?", [$mode, 'maintenance_mode']);
            } else {
                $this->dbAdapter->query("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)", ['maintenance_mode', $mode]);
            }

            $countUntil = (int)$stmtCheck->execute(['maintenance_until'])->current()['count'];
            if ($countUntil > 0) {
                $this->dbAdapter->query("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?", [$until, 'maintenance_until']);
            } else {
                $this->dbAdapter->query("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)", ['maintenance_until', $until]);
            }

            if ($mode === '1') {
                $this->flash()->addSuccessMessage('Đã kích hoạt chế độ bảo trì thành công đến ' . date('H:i d/m/Y', strtotime($until)) . '.');
            } else {
                $this->flash()->addSuccessMessage('Đã tắt chế độ bảo trì.');
            }
        } catch (\Throwable $e) {
            $this->flash()->addErrorMessage('Lỗi hệ thống khi cập nhật: ' . $e->getMessage());
        }

        return $this->redirect()->toRoute('library/settings');
    }

    // ── Add category ──────────────────────────────────────────────────
    public function addCategoryAction(): Response
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        if (!$this->getRequest()->isPost()) {
            return $this->redirectToRefererOrSettings();
        }

        $data = $this->postData();
        $name = trim((string)($data['category_name'] ?? ''));

        if ($name === '') {
            $this->flash()->addErrorMessage('Tên danh mục không được để trống.');
            return $this->redirectToRefererOrSettings();
        }

        if (mb_strlen($name) > 100) {
            $this->flash()->addErrorMessage('Tên danh mục tối đa 100 ký tự.');
            return $this->redirectToRefererOrSettings();
        }

        try {
            // Check if already exists
            $stmt = $this->dbAdapter->query("SELECT id FROM book_categories WHERE name = ? LIMIT 1");
            $exists = $stmt->execute([$name])->current();
            if ($exists) {
                $this->flash()->addErrorMessage('Danh mục "' . htmlspecialchars($name) . '" đã tồn tại.');
            } else {
                $this->dbAdapter->query("INSERT INTO book_categories (name) VALUES (?)", [$name]);
                $this->flash()->addSuccessMessage('Đã thêm danh mục "' . htmlspecialchars($name) . '" thành công.');
            }
        } catch (\Throwable $e) {
            $this->flash()->addErrorMessage('Lỗi hệ thống: ' . $e->getMessage());
        }

        return $this->redirectToRefererOrSettings();
    }

    // ── Delete category ───────────────────────────────────────────────
    public function deleteCategoryAction(): Response
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $id = (int)$this->params()->fromRoute('id', 0);
        if ($id <= 0) {
            $this->flash()->addErrorMessage('ID danh mục không hợp lệ.');
            return $this->redirectToRefererOrSettings();
        }

        try {
            // Get category name
            $stmt = $this->dbAdapter->query("SELECT name FROM book_categories WHERE id = ? LIMIT 1");
            $cat = $stmt->execute([$id])->current();
            if (!$cat) {
                $this->flash()->addErrorMessage('Không tìm thấy danh mục cần xóa.');
                return $this->redirectToRefererOrSettings();
            }

            $catName = $cat['name'];

            // Check if there are books using this category
            $stmtCheck = $this->dbAdapter->query("SELECT COUNT(*) as cnt FROM books WHERE category = ?");
            $count = (int)($stmtCheck->execute([$catName])->current()['cnt'] ?? 0);

            if ($count > 0) {
                $this->flash()->addErrorMessage('Không thể xóa danh mục "' . htmlspecialchars($catName) . '" vì có ' . $count . ' đầu sách đang thuộc danh mục này.');
            } else {
                $this->dbAdapter->query("DELETE FROM book_categories WHERE id = ?", [$id]);
                $this->flash()->addSuccessMessage('Đã xóa danh mục "' . htmlspecialchars($catName) . '" thành công.');
            }
        } catch (\Throwable $e) {
            $this->flash()->addErrorMessage('Lỗi hệ thống: ' . $e->getMessage());
        }

        return $this->redirectToRefererOrSettings();
    }

    // ── Edit category ─────────────────────────────────────────────────
    public function editCategoryAction(): Response
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        if (!$this->getRequest()->isPost()) {
            return $this->redirectToRefererOrSettings();
        }

        $id = (int)$this->params()->fromRoute('id', 0);
        if ($id <= 0) {
            $this->flash()->addErrorMessage('ID danh mục không hợp lệ.');
            return $this->redirectToRefererOrSettings();
        }

        $data = $this->postData();
        $newName = trim((string)($data['category_name'] ?? ''));

        if ($newName === '') {
            $this->flash()->addErrorMessage('Tên danh mục không được để trống.');
            return $this->redirectToRefererOrSettings();
        }

        if (mb_strlen($newName) > 100) {
            $this->flash()->addErrorMessage('Tên danh mục tối đa 100 ký tự.');
            return $this->redirectToRefererOrSettings();
        }

        try {
            // Get current category info
            $stmt = $this->dbAdapter->query("SELECT name FROM book_categories WHERE id = ? LIMIT 1");
            $cat = $stmt->execute([$id])->current();
            if (!$cat) {
                $this->flash()->addErrorMessage('Không tìm thấy danh mục cần sửa.');
                return $this->redirectToRefererOrSettings();
            }

            $oldName = $cat['name'];

            if ($oldName === $newName) {
                return $this->redirectToRefererOrSettings();
            }

            // Check if new name already exists
            $stmtCheck = $this->dbAdapter->query("SELECT id FROM book_categories WHERE name = ? AND id != ? LIMIT 1");
            $exists = $stmtCheck->execute([$newName, $id])->current();
            if ($exists) {
                $this->flash()->addErrorMessage('Danh mục "' . htmlspecialchars($newName) . '" đã tồn tại.');
            } else {
                $this->dbAdapter->query("UPDATE book_categories SET name = ? WHERE id = ?", [$newName, $id]);
                $this->dbAdapter->query("UPDATE books SET category = ? WHERE category = ?", [$newName, $oldName]);

                $this->flash()->addSuccessMessage('Đã đổi tên danh mục "' . htmlspecialchars($oldName) . '" thành "' . htmlspecialchars($newName) . '" thành công.');
            }
        } catch (\Throwable $e) {
            $this->flash()->addErrorMessage('Lỗi hệ thống: ' . $e->getMessage());
        }

        return $this->redirectToRefererOrSettings();
    }

    private function redirectToRefererOrSettings(): Response
    {
        $referer = $this->getRequest()->getHeader('Referer');
        if ($referer) {
            return $this->redirect()->toUrl($referer->getUri());
        }
        return $this->redirect()->toRoute('library/settings');
    }
}
