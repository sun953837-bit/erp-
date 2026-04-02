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
                $sslOption = null;
                if (defined('Pdo\\Mysql::ATTR_SSL_CA')) {
                    $sslOption = constant('Pdo\\Mysql::ATTR_SSL_CA');
                } elseif (defined('PDO::MYSQL_ATTR_SSL_CA')) {
                    $sslOption = constant('PDO::MYSQL_ATTR_SSL_CA');
                }

                if ($sslOption === null) {
                    return [];
                }

                return array_filter([
                    $sslOption => env('MYSQL_ATTR_SSL_CA'),
                ]);
            })() : [],
        ],
    ],

    'migrations' => 'migrations',
];
