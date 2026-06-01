<?php
/**
 * Global configuration: DB adapter (non-sensitive settings).
 * Sensitive credentials go into config/autoload/local.php (git-ignored).
 */
return [
    'db' => [
        'driver'     => 'Pdo_Mysql',
        'hostname'   => getenv('DB_HOST') ?: 'localhost',
        'database'   => getenv('DB_NAME') ?: 'library_db',
        'username'   => getenv('DB_USER') ?: 'root',
        'password'   => getenv('DB_PASS') !== false ? getenv('DB_PASS') : '',
        'port'       => getenv('DB_PORT') ?: 3306,
        'charset'    => 'utf8mb4',
        'collation'  => 'utf8mb4_unicode_ci',
        'driver_options' => [
            \PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
        ],
    ],

    'service_manager' => [
        'factories' => [
            // Laminas\Db adapter via official factory
            \Laminas\Db\Adapter\Adapter::class
                => \Laminas\Db\Adapter\AdapterServiceFactory::class,
        ],
        'aliases' => [
            'db' => \Laminas\Db\Adapter\Adapter::class,
        ],
    ],
    'view_manager' => [
        'strategies' => [
            'ViewJsonStrategy',
        ],
        'json_options' => 256, // JSON_UNESCAPED_UNICODE
    ],
    'gemini' => [
        'api_key' => '', // Đặt API Key thật vào file local.php
    ],
];
