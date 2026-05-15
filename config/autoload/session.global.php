<?php
/**
 * Laminas Session configuration.
 * Runs session_start() automatically via SessionManagerFactory.
 */
return [
    'session_config' => [
        'cookie_lifetime'     => 7200,       // 2 hours
        'gc_maxlifetime'      => 7200,
        'cookie_httponly'     => true,
        'cookie_samesite'     => 'Lax',
        'name'                => 'HCMUE_LIB',
    ],
    'session_manager' => [
        'validators' => [],
    ],
    'session_storage' => [
        'type' => \Laminas\Session\Storage\SessionArrayStorage::class,
    ],
    'session_containers' => [
        'library_auth',
    ],
];
