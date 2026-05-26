<?php
/**
 * Minimal session config to avoid INI errors in CLI/Simulated environment.
 */
return [
    'session_config' => [
        // Empty or extremely minimal
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
