<?php
declare(strict_types=1);

namespace Library;

use Laminas\Router\Http\Literal;
use Laminas\Router\Http\Segment;
use Library\Controller\AuthController;
use Library\Controller\BookController;
use Library\Controller\DashboardController;
use Library\Controller\ProfileController;
use Library\Controller\SettingsController;
use Library\Controller\TransactionController;
use Library\Controller\UserController;
use Library\Controller\BookImportController;
use Library\Controller\HomeController;
use Library\Factory\Controller\BookImportControllerFactory;
use Library\Factory\Controller\AuthControllerFactory;
use Library\Factory\Controller\BookControllerFactory;
use Library\Factory\Controller\DashboardControllerFactory;
use Library\Factory\Controller\HomeControllerFactory;
use Library\Factory\Controller\ProfileControllerFactory;
use Library\Factory\Controller\SettingsControllerFactory;
use Library\Factory\Controller\TransactionControllerFactory;
use Library\Factory\Controller\UserControllerFactory;
use Library\Factory\Form\BookFormFactory;
use Library\Factory\Form\BorrowFormFactory;
use Library\Factory\Form\LoginFormFactory;
use Library\Factory\Form\RegisterFormFactory;
use Library\Factory\Form\UserCreateFormFactory;
use Library\Factory\Form\UserEditFormFactory;
use Library\Factory\Service\CirculationServiceFactory;
use Library\Factory\Session\AuthSessionContainerFactory;
use Library\Factory\Table\BookTableFactory;
use Library\Factory\Table\BorrowTableFactory;
use Library\Factory\Table\UserTableFactory;
use Library\Factory\View\Helper\CurrentUserHelperFactory;
use Library\Form\BookForm;
use Library\Form\BorrowForm;
use Library\Form\LoginForm;
use Library\Form\RegisterForm;
use Library\Model\Table\BookTable;
use Library\Model\Table\BorrowTable;
use Library\Model\Table\UserTable;
use Library\Service\CirculationService;
use Library\Session\AuthSessionContainer;
use Library\View\Helper\CurrentUserHelper;
use Library\Controller\Api\BookApiController;
use Library\Controller\Api\UserApiController;
use Library\Controller\Api\BorrowApiController;
use Library\Controller\Api\NotificationApiController;
use Library\Controller\Api\SseController;
use Library\Factory\Controller\Api\BookApiControllerFactory;
use Library\Factory\Controller\Api\UserApiControllerFactory;
use Library\Factory\Controller\Api\BorrowApiControllerFactory;
use Library\Factory\Controller\Api\NotificationApiControllerFactory;
use Library\Factory\Controller\Api\SseControllerFactory;
use Library\Controller\FineController;
use Library\Factory\Controller\FineControllerFactory;

return [
    // ── Routing ─────────────────────────────────────────────────────────
    'router' => [
        'routes' => [
            'home' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/',
                    'defaults' => ['controller' => HomeController::class, 'action' => 'index'],
                ],
            ],
            'catalog' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/books',
                    'defaults' => ['controller' => BookController::class, 'action' => 'index'],
                ],
            ],
            'maintenance' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/maintenance',
                    'defaults' => ['controller' => HomeController::class, 'action' => 'maintenance'],
                ],
            ],
            'announcements' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/announcements',
                    'defaults' => [
                        'controller' => Controller\AnnouncementController::class,
                        'action'     => 'announcements',
                    ],
                ],
                'may_terminate' => true,
                'child_routes' => [
                    'view' => [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '/view/:id',
                            'constraints' => [
                                'id' => '[0-9]+',
                            ],
                            'defaults' => [
                                'action' => 'viewAnnouncement',
                            ],
                        ],
                    ],
                ],
            ],
            // Google Login Routes
            'google-login' => [
                'type' => Literal::class,
                'priority' => 100,
                'options' => [
                    'route' => '/auth/google',
                    'defaults' => ['controller' => AuthController::class, 'action' => 'googleRedirect'],
                ],
            ],
            'google-callback' => [
                'type' => Literal::class,
                'priority' => 100,
                'options' => [
                    'route' => '/auth/google-callback',
                    'defaults' => ['controller' => AuthController::class, 'action' => 'googleCallback'],
                ],
            ],
            'auth' => [
                'type'    => Segment::class,
                'options' => [
                    'route'       => '/auth[/:action]',
                    'constraints' => ['action' => '[a-zA-Z][a-zA-Z0-9_-]*'],
                    'defaults'    => ['controller' => AuthController::class, 'action' => 'login'],
                ],
            ],
            'library' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/admin',
                    'defaults' => ['controller' => HomeController::class, 'action' => 'index'],
                ],
                'may_terminate' => true,
                'child_routes'  => [
                    'dashboard' => [
                        'type'    => Segment::class,
                        'options' => [
                            'route'    => '/dashboard[/:action]',
                            'constraints' => [
                                'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                            ],
                            'defaults' => ['controller' => DashboardController::class, 'action' => 'index'],
                        ],
                    ],

                    'books-import' => [
                        'type'    => Segment::class,
                        'options' => [
                            'route'    => '/books/import[/:action[/:id]]',
                            'constraints' => [
                                'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                'id'     => '[0-9]+',
                            ],
                            'defaults' => [
                                'controller' => BookImportController::class,
                                'action'     => 'index',
                            ],
                        ],
                    ],
                    'book' => [
                        'type'    => Segment::class,
                        'options' => [
                            'route'    => '/book[/:action[/:id]]',
                            'constraints' => ['action' => '[a-zA-Z][a-zA-Z0-9_-]*', 'id' => '[0-9]+'],
                            'defaults' => [
                                'controller' => Controller\BookController::class,
                                'action'     => 'index',
                            ],
                        ],
                    ],
                    'ticket' => [
                        'type'    => Segment::class,
                        'options' => [
                            'route'    => '/ticket[/:action[/:id]]',
                            'constraints' => ['action' => '[a-zA-Z][a-zA-Z0-9_-]*', 'id' => '[0-9]+'],
                            'defaults' => [
                                'controller' => Controller\TicketController::class,
                                'action'     => 'index',
                            ],
                        ],
                    ],
                    'user' => [
                        'type'    => Segment::class,
                        'options' => [
                            'route'       => '/users[/:action[/:id]]',
                            'constraints' => ['action' => '[a-zA-Z][a-zA-Z0-9_-]*', 'id' => '[0-9]+'],
                            'defaults'    => ['controller' => UserController::class, 'action' => 'index'],
                        ],
                    ],
                    'transaction' => [
                        'type'    => Segment::class,
                        'options' => [
                            'route'       => '/borrow[/:action[/:id]]',
                            'constraints' => ['action' => '[a-zA-Z][a-zA-Z0-9_-]*', 'id' => '[0-9]+'],
                            'defaults'    => ['controller' => TransactionController::class, 'action' => 'index'],
                        ],
                    ],
                    'profile' => [
                        'type'    => Segment::class,
                        'options' => [
                            'route'       => '/profile[/:action]',
                            'constraints' => ['action' => '[a-zA-Z][a-zA-Z0-9_-]*'],
                            'defaults'    => ['controller' => ProfileController::class, 'action' => 'index'],
                        ],
                    ],
                    'settings' => [
                        'type'    => Segment::class,
                        'options' => [
                            'route'       => '/settings[/:action[/:id]]',
                            'constraints' => [
                                'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                'id'     => '[0-9]+',
                            ],
                            'defaults'    => ['controller' => SettingsController::class, 'action' => 'index'],
                        ],
                    ],
                    'fine' => [
                        'type'    => Segment::class,
                        'options' => [
                            'route'       => '/fine[/:action[/:id]]',
                            'constraints' => [
                                'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                'id'     => '[0-9]+',
                            ],
                            'defaults'    => [
                                'controller' => FineController::class,
                                'action'     => 'adminIndex',
                            ],
                        ],
                    ],
                    'announcements' => [
                        'type'    => Segment::class,
                        'options' => [
                            'route'    => '/announcements',
                            'defaults' => [
                                'controller' => Controller\AnnouncementController::class,
                                'action'     => 'announcements',
                            ],
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            'add' => [
                                'type' => Literal::class,
                                'options' => [
                                    'route' => '/add',
                                    'defaults' => [
                                        'action' => 'addAnnouncement',
                                    ],
                                ],
                            ],
                            'edit' => [
                                'type' => Segment::class,
                                'options' => [
                                    'route' => '/edit/:id',
                                    'constraints' => [
                                        'id' => '[0-9]+',
                                    ],
                                    'defaults' => [
                                        'action' => 'editAnnouncement',
                                    ],
                                ],
                            ],
                            'delete' => [
                                'type' => Segment::class,
                                'options' => [
                                    'route' => '/delete/:id',
                                    'constraints' => [
                                        'id' => '[0-9]+',
                                    ],
                                    'defaults' => [
                                        'action' => 'deleteAnnouncement',
                                    ],
                                ],
                            ],
                            'toggle' => [
                                'type' => Segment::class,
                                'options' => [
                                    'route' => '/toggle/:id',
                                    'constraints' => [
                                        'id' => '[0-9]+',
                                    ],
                                    'defaults' => [
                                        'action' => 'toggleAnnouncement',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'student' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/student',
                    'defaults' => ['controller' => HomeController::class, 'action' => 'index'],
                ],
                'may_terminate' => true,
                'child_routes'  => [
                    'dashboard' => [
                        'type'    => Segment::class,
                        'options' => [
                            'route'    => '/dashboard[/:action]',
                            'constraints' => [
                                'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                            ],
                            'defaults' => ['controller' => DashboardController::class, 'action' => 'index'],
                        ],
                    ],
                    'auth' => [
                        'type'    => Segment::class,
                        'options' => [
                            'route'       => '/auth[/:action]',
                            'constraints' => ['action' => '[a-zA-Z][a-zA-Z0-9_-]*'],
                            'defaults'    => ['controller' => AuthController::class, 'action' => 'login'],
                        ],
                    ],
                    'book' => [
                        'type'    => Segment::class,
                        'options' => [
                            'route'    => '/book[/:action[/:id]]',
                            'constraints' => ['action' => '[a-zA-Z][a-zA-Z0-9_-]*', 'id' => '[0-9]+'],
                            'defaults' => [
                                'controller' => Controller\BookController::class,
                                'action'     => 'index',
                            ],
                        ],
                    ],
                    'ticket' => [
                        'type'    => Segment::class,
                        'options' => [
                            'route'    => '/ticket[/:action[/:id]]',
                            'constraints' => ['action' => '[a-zA-Z][a-zA-Z0-9_-]*', 'id' => '[0-9]+'],
                            'defaults' => [
                                'controller' => Controller\TicketController::class,
                                'action'     => 'index',
                            ],
                        ],
                    ],
                    'transaction' => [
                        'type'    => Segment::class,
                        'options' => [
                            'route'       => '/borrow[/:action[/:id]]',
                            'constraints' => ['action' => '[a-zA-Z][a-zA-Z0-9_-]*', 'id' => '[0-9]+'],
                            'defaults'    => ['controller' => TransactionController::class, 'action' => 'index'],
                        ],
                    ],
                    'profile' => [
                        'type'    => Segment::class,
                        'options' => [
                            'route'       => '/profile[/:action]',
                            'constraints' => ['action' => '[a-zA-Z][a-zA-Z0-9_-]*'],
                            'defaults'    => ['controller' => ProfileController::class, 'action' => 'index'],
                        ],
                    ],
                    'announcements' => [
                        'type'    => Literal::class,
                        'options' => [
                            'route'    => '/announcements',
                            'defaults' => [
                                'controller' => Controller\AnnouncementController::class,
                                'action'     => 'announcements',
                            ],
                        ],
                    ],
                    'fine' => [
                        'type'    => Segment::class,
                        'options' => [
                            'route'    => '/fine[/:action[/:id]]',
                            'constraints' => [
                                'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                'id'     => '[0-9]+',
                            ],
                            'defaults' => [
                                'controller' => FineController::class,
                                'action'     => 'index',
                            ],
                        ],
                    ],
                ],
            ],
            'api' => [
                'type' => Segment::class,
                'options' => [
                    'route'    => '/api',
                    'defaults' => [
                        'controller' => BookApiController::class, // Default
                    ],
                ],
                'may_terminate' => false,
                'child_routes' => [
                    'notifications' => [
                        'type'    => Literal::class,
                        'options' => [
                            'route'    => '/notifications',
                            'defaults' => [
                                'controller' => NotificationApiController::class,
                                'action'     => 'index',
                            ],
                        ],
                    ],
                    'sse-notifications' => [
                        'type'    => Literal::class,
                        'options' => [
                            'route'    => '/sse/notifications',
                            'defaults' => [
                                'controller' => SseController::class,
                                'action'     => 'notification',
                            ],
                        ],
                    ],
                    'sse-chat' => [
                        'type'    => Literal::class,
                        'options' => [
                            'route'    => '/sse/chat',
                            'defaults' => [
                                'controller' => SseController::class,
                                'action'     => 'chat',
                            ],
                        ],
                    ],
                    'books-search' => [
                        'type'    => Literal::class,
                        'options' => [
                            'route'    => '/books/search',
                            'defaults' => [
                                'controller' => BookApiController::class,
                                'action'     => 'search',
                            ],
                        ],
                    ],
                    'books-chat' => [
                        'type'    => Literal::class,
                        'options' => [
                            'route'    => '/books/chat',
                            'defaults' => [
                                'controller' => BookApiController::class,
                                'action'     => 'chat',
                            ],
                        ],
                    ],
                    'books-semantic-search' => [
                        'type'    => Literal::class,
                        'options' => [
                            'route'    => '/books/semantic-search',
                            'defaults' => [
                                'controller' => BookApiController::class,
                                'action'     => 'semanticSearch',
                            ],
                        ],
                    ],
                    'books' => [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '/books[/:id]',
                            'constraints' => [
                                'id' => '[0-9]+',
                            ],
                            'defaults' => [
                                'controller' => BookApiController::class,
                            ],
                        ],
                        'may_terminate' => true,
                    ],
                    'users-search' => [
                        'type'    => Literal::class,
                        'options' => [
                            'route'    => '/users/search',
                            'defaults' => [
                                'controller' => UserApiController::class,
                                'action'     => 'search',
                            ],
                        ],
                    ],
                    'users' => [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '/users[/:id]',
                            'constraints' => [
                                'id' => '[0-9]+',
                            ],
                            'defaults' => [
                                'controller' => UserApiController::class,
                            ],
                        ],
                    ],
                    'borrows' => [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '/borrows[/:id]',
                            'constraints' => [
                                'id' => '[0-9]+',
                            ],
                            'defaults' => [
                                'controller' => BorrowApiController::class,
                            ],
                        ],
                    ],
                    'payment' => [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '/payment[/:action]',
                            'constraints' => [
                                'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                            ],
                            'defaults' => [
                                'controller' => \Library\Controller\Api\PaymentApiController::class,
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],

    // ── Controllers (all via explicit Factories) ───────────────────────
    'controllers' => [
        'factories' => [
            Controller\AnnouncementController::class => Factory\Controller\AnnouncementControllerFactory::class,
            BookImportController::class  => BookImportControllerFactory::class,
            SettingsController::class     => SettingsControllerFactory::class,
            HomeController::class        => HomeControllerFactory::class,
            AuthController::class        => AuthControllerFactory::class,
            Controller\BookController::class        => Factory\Controller\BookControllerFactory::class,
            Controller\DashboardController::class   => Factory\Controller\DashboardControllerFactory::class,
            Controller\TicketController::class      => Factory\Controller\TicketControllerFactory::class,
            ProfileController::class     => ProfileControllerFactory::class,
            TransactionController::class => TransactionControllerFactory::class,
            UserController::class        => UserControllerFactory::class,
            BookApiController::class     => BookApiControllerFactory::class,
            UserApiController::class     => UserApiControllerFactory::class,
            BorrowApiController::class   => BorrowApiControllerFactory::class,
            NotificationApiController::class => NotificationApiControllerFactory::class,
            SseController::class => SseControllerFactory::class,
            FineController::class => FineControllerFactory::class,
            \Library\Controller\Api\PaymentApiController::class => \Library\Factory\Controller\Api\PaymentApiControllerFactory::class,
        ],
    ],

    // ── Service Manager (Models) ───────────────────────────────────────
    'service_manager' => [
        'factories' => [
            BookTable::class          => BookTableFactory::class,
            UserTable::class          => UserTableFactory::class,
            BorrowTable::class        => BorrowTableFactory::class,
            \Library\Model\Table\PaymentSessionTable::class => \Library\Factory\Table\PaymentSessionTableFactory::class,
            \Library\Model\Table\AnnouncementTable::class => \Library\Factory\Table\AnnouncementTableFactory::class,
            \Library\Model\Table\PublicChatTable::class => \Library\Factory\Table\PublicChatTableFactory::class,
            \Library\Model\Table\TicketTable::class => \Library\Factory\Table\TicketTableFactory::class,
            \Library\Model\Table\TicketMessageTable::class => \Library\Factory\Table\TicketMessageTableFactory::class,
            \Library\Model\Table\SystemSettingsTable::class => \Library\Factory\Table\SystemSettingsTableFactory::class,
            \Library\Model\Table\BookCategoryTable::class => \Library\Factory\Table\BookCategoryTableFactory::class,
            \Library\Model\Table\NotificationTable::class => \Library\Factory\Table\NotificationTableFactory::class,
            \Library\Model\Table\ChatLogTable::class => \Library\Factory\Table\ChatLogTableFactory::class,
            \Library\Model\Table\BookImportTable::class => \Library\Factory\Table\BookImportTableFactory::class,
            \Library\Model\Table\BookReviewTable::class => \Library\Factory\Table\BookReviewTableFactory::class,
            \Library\Service\GeminiService::class => \Library\Factory\Service\GeminiServiceFactory::class,
            CirculationService::class => CirculationServiceFactory::class,
            AuthSessionContainer::class => AuthSessionContainerFactory::class,
            \Library\Service\MailService::class => \Library\Factory\Service\MailServiceFactory::class,
            \Library\Service\GmailService::class => \Library\Factory\Service\GmailServiceFactory::class,
        ],
    ],

    // ── Forms (all created via FormElementManager factories) ───────────
    'form_elements' => [
        'factories' => [
            LoginForm::class               => LoginFormFactory::class,
            RegisterForm::class            => RegisterFormFactory::class,
            BookForm::class                => BookFormFactory::class,
            BorrowForm::class              => BorrowFormFactory::class,
            'Library\Form\UserCreateForm' => UserCreateFormFactory::class,
            'Library\Form\UserEditForm'   => UserEditFormFactory::class,
        ],
        'shared' => [
            LoginForm::class               => false,
            RegisterForm::class            => false,
            BookForm::class                => false,
            BorrowForm::class              => false,
            'Library\Form\UserCreateForm' => false,
            'Library\Form\UserEditForm'   => false,
        ],
    ],

    'view_helpers' => [
        'factories' => [
            CurrentUserHelper::class => CurrentUserHelperFactory::class,
        ],
        'aliases' => [
            'currentUser' => CurrentUserHelper::class,
        ],
    ],

    // ── View ───────────────────────────────────────────────────────────
    'view_manager' => [
        'display_not_found_reason' => true,
        'display_exceptions'       => true,
        'not_found_template'       => 'error/404',
        'exception_template'       => 'error/index',
        'template_map' => [
            'layout/layout'         => __DIR__ . '/../view/layout/library-layout.phtml',
            'layout/library-layout' => __DIR__ . '/../view/layout/library-layout.phtml',
            'error/404'             => __DIR__ . '/../view/error/404.phtml',
            'error/index'           => __DIR__ . '/../view/error/index.phtml',
        ],
        'template_path_stack' => [
            __DIR__ . '/../view',
        ],
        'strategies' => [
            'ViewJsonStrategy',
        ],
        'json_exceptions' => true,
        'json_options' => 256, // JSON_UNESCAPED_UNICODE
    ],
];
