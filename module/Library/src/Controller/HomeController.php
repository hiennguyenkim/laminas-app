<?php

declare(strict_types=1);

namespace Library\Controller;

use Library\Session\AuthSessionContainer;
use Laminas\Http\Response;
use Laminas\View\Model\ViewModel;

/**
 * @psalm-suppress PropertyNotSetInConstructor
 * @psalm-suppress PossiblyUnusedMethod
 */
class HomeController extends BaseController
{
    public function __construct(
        AuthSessionContainer $authSessionContainer,
        private ?\Laminas\Db\Adapter\AdapterInterface $dbAdapter = null
    ) {
        parent::__construct($authSessionContainer);
    }

    public function indexAction(): Response
    {
        $currentUser = $this->currentUser();
        if ($currentUser !== null) {
            if (($currentUser['role'] ?? '') === 'admin') {
                return $this->redirect()->toRoute('library/dashboard');
            }
            if (($currentUser['role'] ?? '') === 'student') {
                return $this->redirect()->toRoute('student/dashboard');
            }
        }

        $path = $this->httpRequest()->getUri()->getPath();
        if ($path === '/admin' || $path === '/admin/' || $path === '/student' || $path === '/student/') {
            return $this->redirect()->toRoute('library/auth', ['action' => 'login']);
        }

        return $this->redirect()->toRoute('announcements');
    }

    public function maintenanceAction(): ViewModel
    {
        $maintenanceUntil = null;
        if ($this->dbAdapter) {
            try {
                $statement = $this->dbAdapter->query("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
                $resultUntil = iterator_to_array($statement->execute(['maintenance_until']));
                $maintenanceUntil = count($resultUntil) > 0 ? $resultUntil[0]['setting_value'] : null;
            } catch (\Throwable $t) {
                // ignore
            }
        }

        $this->layout()->setTerminal(true);

        return new ViewModel([
            'maintenanceUntil' => $maintenanceUntil,
        ]);
    }
}
