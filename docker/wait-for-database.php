#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Aguarda o banco ficar disponível usando PDO (mesmo driver do Laravel).
 * Evita depender do cliente mysql/mariadb CLI do Alpine, incompatível com
 * caching_sha2_password do MySQL 8.
 */

$maxAttempts = max(1, (int) (getenv('GETFY_DB_WAIT_ATTEMPTS') ?: 60));
$sleepSeconds = max(1, (int) (getenv('GETFY_DB_WAIT_SLEEP') ?: 1));

$connection = (string) (getenv('DB_CONNECTION') ?: 'mysql');
$host = (string) (getenv('DB_HOST') ?: ($connection === 'pgsql' ? 'postgres' : 'mysql'));
$port = (string) (getenv('DB_PORT') ?: ($connection === 'pgsql' ? '5432' : '3306'));
$database = (string) (getenv('DB_DATABASE') ?: 'getfy');
$username = (string) (getenv('DB_USERNAME') ?: 'getfy');

$password = getenv('DB_PASSWORD');
if ($password === false) {
    $password = getenv('MYSQL_PASSWORD');
}
if ($password === false) {
    $password = 'getfy';
}

function mysqlPdoSslOption(string $suffix): ?int
{
    $legacy = 'PDO::MYSQL_ATTR_'.$suffix;
    if (defined($legacy)) {
        return constant($legacy);
    }

    $php85 = 'Pdo\\Mysql::ATTR_'.$suffix;
    if (defined($php85)) {
        return constant($php85);
    }

    return null;
}

function connectDatabase(
    string $connection,
    string $host,
    string $port,
    string $database,
    string $username,
    string $password
): PDO {
    if ($connection === 'pgsql') {
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $database);

        return new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    }

    if ($connection === 'mysql' || $connection === 'mariadb') {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $host,
            $port,
            $database
        );

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ];

        $sslCa = getenv('MYSQL_ATTR_SSL_CA');
        if (is_string($sslCa) && $sslCa !== '' && strtolower($sslCa) !== 'null') {
            $sslCaOpt = mysqlPdoSslOption('SSL_CA');
            if ($sslCaOpt !== null) {
                $options[$sslCaOpt] = $sslCa;
            }
        } else {
            $verifyOpt = mysqlPdoSslOption('SSL_VERIFY_SERVER_CERT');
            if ($verifyOpt !== null) {
                // MySQL 8 na rede Docker: TLS opcional; não falhar boot por certificado autoassinado.
                $options[$verifyOpt] = false;
            }
        }

        return new PDO($dsn, $username, $password, $options);
    }

    throw new InvalidArgumentException("DB_CONNECTION não suportado no boot: {$connection}");
}

$lastError = 'desconhecido';

for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
    try {
        $pdo = connectDatabase($connection, $host, $port, $database, $username, $password);
        $pdo->query('SELECT 1');

        exit(0);
    } catch (Throwable $e) {
        $lastError = $e->getMessage();
        if ($attempt < $maxAttempts) {
            sleep($sleepSeconds);
        }
    }
}

fwrite(
    STDERR,
    sprintf(
        "Banco indisponível após %d tentativas (DB_CONNECTION=%s, DB_HOST=%s, DB_PORT=%s, DB_DATABASE=%s, DB_USERNAME=%s).\nÚltimo erro PDO: %s\n",
        $maxAttempts,
        $connection,
        $host,
        $port,
        $database,
        $username,
        $lastError
    )
);

exit(1);
