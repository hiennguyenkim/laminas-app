<?php

declare(strict_types=1);

namespace Library;

use Laminas\Mvc\MvcEvent;
use Laminas\Session\Container as SessionContainer;
use Laminas\Session\SessionManager;

class Module
{
    /**
     * @return array<array-key, mixed>
     */
    public function getConfig(): array
    {
        /** @var array<array-key, mixed>|mixed $config */
        $config = include __DIR__ . '/../config/module.config.php';

        return is_array($config) ? $config : [];
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function onBootstrap(MvcEvent $event): void
    {
        $application = $event->getApplication();
        $eventManager = $application->getEventManager();
        $container = $application->getServiceManager();

        if ($container->has(SessionManager::class)) {
            $sessionManager = $container->get(SessionManager::class);
            SessionContainer::setDefaultManager($sessionManager);
            try {
                $sessionManager->start();
            } catch (\Throwable) {
                // If session is corrupt (Fatal Error was happening here), destroy it and start clean
                @session_unset();
                if (isset($_COOKIE[session_name()])) {
                    setcookie(session_name(), '', time() - 3600, '/');
                }
                $sessionManager->destroy();
                $sessionManager->start();
            }
        }

        // Attach Maintenance Check Listener
        $eventManager->attach(MvcEvent::EVENT_ROUTE, function (MvcEvent $e) use ($container) {
            if (defined('PHPUNIT_COMPOSER_INSTALL') || defined('__PHPUNIT_PHAR__')) {
                return;
            }
            $routeMatch = $e->getRouteMatch();
            if (!$routeMatch) {
                return;
            }

            $matchedRouteName = $routeMatch->getMatchedRouteName();

            // Allow static assets, API calls, and auth routes
            $request = $e->getRequest();
            if (!method_exists($request, 'getUri')) {
                return;
            }
            $uri = $request->getUri()->getPath();

            // Check if route is maintenance page or auth page
            if ($matchedRouteName === 'maintenance' || 
                strpos($uri, '/auth') !== false || 
                strpos($matchedRouteName, 'auth') !== false) {
                // Let it proceed (will check maintenance route redirection below)
            } else {
                // Check if maintenance mode is active
                $dbAdapter = $container->get(\Laminas\Db\Adapter\AdapterInterface::class);
                $maintenanceMode = false;

                try {
                    $statement = $dbAdapter->query("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
                    
                    $resultMode = iterator_to_array($statement->execute(['maintenance_mode']));
                    $isModeEnabled = count($resultMode) > 0 && $resultMode[0]['setting_value'] === '1';

                    if ($isModeEnabled) {
                        $resultUntil = iterator_to_array($statement->execute(['maintenance_until']));
                        $untilVal = count($resultUntil) > 0 ? $resultUntil[0]['setting_value'] : null;

                        if ($untilVal) {
                            $now = time();
                            $untilTime = strtotime($untilVal);
                            if ($untilTime > $now) {
                                $maintenanceMode = true;
                            }
                        }
                    }
                } catch (\Throwable $t) {
                    return;
                }

                if ($maintenanceMode) {
                    // Check if current user is admin
                    $authSession = $container->get(\Library\Session\AuthSessionContainer::class);
                    $isAdmin = isset($authSession->user) && ($authSession->user['role'] ?? '') === 'admin';

                    if (!$isAdmin) {
                        // Redirect non-admin to maintenance page
                        $router = $e->getRouter();
                        $url = $router->assemble([], ['name' => 'maintenance']);
                        
                        $response = $e->getResponse();
                        $response->getHeaders()->addHeaderLine('Location', $url);
                        $response->setStatusCode(302);
                        $response->sendHeaders();
                        return $response;
                    }
                }
            }

            // If visiting /maintenance, verify if it is active.
            // If maintenance is NOT active or current user IS admin, redirect them away to home page!
            if ($matchedRouteName === 'maintenance') {
                $dbAdapter = $container->get(\Laminas\Db\Adapter\AdapterInterface::class);
                $maintenanceMode = false;

                try {
                    $statement = $dbAdapter->query("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
                    $resultMode = iterator_to_array($statement->execute(['maintenance_mode']));
                    $isModeEnabled = count($resultMode) > 0 && $resultMode[0]['setting_value'] === '1';

                    if ($isModeEnabled) {
                        $resultUntil = iterator_to_array($statement->execute(['maintenance_until']));
                        $untilVal = count($resultUntil) > 0 ? $resultUntil[0]['setting_value'] : null;

                        if ($untilVal) {
                            $now = time();
                            $untilTime = strtotime($untilVal);
                            if ($untilTime > $now) {
                                $maintenanceMode = true;
                            }
                        }
                    }
                } catch (\Throwable $t) {
                    // ignore
                }

                $authSession = $container->get(\Library\Session\AuthSessionContainer::class);
                $isAdmin = isset($authSession->user) && ($authSession->user['role'] ?? '') === 'admin';

                if (!$maintenanceMode || $isAdmin) {
                    $router = $e->getRouter();
                    $url = $router->assemble([], ['name' => 'home']);
                    
                    $response = $e->getResponse();
                    $response->getHeaders()->addHeaderLine('Location', $url);
                    $response->setStatusCode(302);
                    $response->sendHeaders();
                    return $response;
                }
            }
        }, -100);

        // Pseudo-cron for Auto-Cancel Pending Requests
        $eventManager->attach(MvcEvent::EVENT_ROUTE, function (MvcEvent $e) use ($container) {
            if (defined('PHPUNIT_COMPOSER_INSTALL') || defined('__PHPUNIT_PHAR__')) {
                return;
            }

            try {
                $dbAdapter = $container->get(\Laminas\Db\Adapter\AdapterInterface::class);
                $stmt = $dbAdapter->query("SELECT setting_value FROM system_settings WHERE setting_key = 'last_cron_run' LIMIT 1");
                $result = $stmt->execute()->current();
                
                $lastRun = $result ? (int) $result['setting_value'] : 0;
                $now = time();
                
                // Run if more than 1 hour (3600 seconds) has passed
                if ($now - $lastRun >= 3600) {
                    // Update timestamp immediately to prevent race conditions
                    $dbAdapter->query("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'last_cron_run'")->execute([$now]);
                    
                    // Trigger background script (Windows compatible)
                    $scriptPath = realpath(__DIR__ . '/../../../bin/cron-cancel-pending.php');
                    if ($scriptPath) {
                        $phpBinary = PHP_BINARY;
                        if (strpos(strtolower($phpBinary), 'php') === false || strpos(strtolower($phpBinary), 'php-cgi') !== false) {
                            if (file_exists('C:\xampp\php\php.exe')) {
                                $phpBinary = 'C:\xampp\php\php.exe';
                            } else {
                                $cgiReplaced = str_ireplace('php-cgi.exe', 'php.exe', $phpBinary);
                                if (file_exists($cgiReplaced)) {
                                    $phpBinary = $cgiReplaced;
                                } else {
                                    $phpBinary = 'php';
                                }
                            }
                        }
                        pclose(popen("start /B \"\" \"$phpBinary\" \"$scriptPath\"", "r"));
                    }
                }
            } catch (\Throwable $t) {
                // Ignore cron errors to not break the page load
            }
        }, -101);
    }
}
