<?php

return [
    'default' => env('DB_CONNECTION', 'mysql'),

    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
        ],

        'mysql' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'forge'),
            'username' => env('DB_USERNAME', 'forge'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? (static function (): array {
                $sslCa = trim((string) env('MYSQL_ATTR_SSL_CA', ''));
                if ($sslCa === '') {
                    return [];
                }

                $sslOption = null;
                $pdoMysqlClass = 'Pdo\\Mysql';
                if (class_exists($pdoMysqlClass) && defined($pdoMysqlClass.'::ATTR_SSL_CA')) {
                    $sslOption = constant($pdoMysqlClass.'::ATTR_SSL_CA');
                } elseif (PHP_VERSION_ID < 80400 && defined('PDO::MYSQL_ATTR_SSL_CA')) {
                    $sslOption = constant('PDO::MYSQL_ATTR_SSL_CA');
                }

                if ($sslOption === null) {
                    return [];
                }

                return [
                    $sslOption => $sslCa,
                ];
            })() : [],
        ],
    ],

    'migrations' => 'migrations',
];
