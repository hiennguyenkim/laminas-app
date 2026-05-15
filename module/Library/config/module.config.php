<?php
declare(strict_types=1);

namespace Library;

use Laminas\Router\Http\Literal;
use Laminas\Router\Http\Segment;
use Library\Controller\AuthController;
use Library\Controller\BookController;
use Library\Controller\DashboardController;
use Library\Controller\HomeController;
use Library\Controller\TransactionController;
use Library\Controller\UserController;
use Library\Factory\Controller\AuthControllerFactory;
use Library\Factory\Controller\BookControllerFactory;
use Library\Factory\Controller\DashboardControllerFactory;
use Library\Factory\Controller\HomeControllerFactory;
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
use Library\Factory\Controller\Api\BookApiControllerFactory;
use Library\Factory\Controller\Api\UserApiControllerFactory;
use Library\Factory\Controller\Api\BorrowApiControllerFactory;

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
            'library' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/admin',
                    'defaults' => ['controller' => HomeController::class, 'action' => 'index'],
                ],
                'may_terminate' => true,
                'child_routes'  => [
                    'dashboard' => [
                        'type'    => Literal::class,
                        'options' => [
                            'route'    => '/dashboard',
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
                            'route'       => '/books[/:action[/:id]]',
                            'constraints' => ['action' => '[a-zA-Z][a-zA-Z0-9_-]*', 'id' => '[0-9]+'],
                            'defaults'    => ['controller' => BookController::class, 'action' => 'index'],
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
                ],
            ],
            'api' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/api',
                    'defaults' => [
                        'controller' => BookApiController::class, // Default, though routes usually specific
                    ],
                ],
                'may_terminate' => false,
                'child_routes' => [
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
                ],
            ],
        ],
    ],

    // ── Controllers (all via explicit Factories) ───────────────────────
    'controllers' => [
        'factories' => [
            HomeController::class        => HomeControllerFactory::class,
            AuthController::class        => AuthControllerFactory::class,
            BookController::class        => BookControllerFactory::class,
            DashboardController::class   => DashboardControllerFactory::class,
            TransactionController::class => TransactionControllerFactory::class,
            UserController::class        => UserControllerFactory::class,
            BookApiController::class     => BookApiControllerFactory::class,
            UserApiController::class     => UserApiControllerFactory::class,
            BorrowApiController::class   => BorrowApiControllerFactory::class,
        ],
    ],

    // ── Service Manager (Models) ───────────────────────────────────────
    'service_manager' => [
        'factories' => [
            BookTable::class          => BookTableFactory::class,
            UserTable::class          => UserTableFactory::class,
            BorrowTable::class        => BorrowTableFactory::class,
            CirculationService::class => CirculationServiceFactory::class,
            AuthSessionContainer::class => AuthSessionContainerFactory::class,
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
