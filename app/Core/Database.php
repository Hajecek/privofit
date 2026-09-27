<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;

final class Database
{
    private ?PDO $pdo = null;

    /** @var list<callable(): void> */
    private array $afterCommit = [];

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $host = (string) Env::get('DB_HOST', '127.0.0.1');
        $port = (string) Env::get('DB_PORT', '3306');
        $name = (string) Env::get('DB_DATABASE', 'privofit');
        $user = (string) Env::get('DB_USERNAME', 'root');
        $pass = (string) Env::get('DB_PASSWORD', '');
        $charset = (string) Env::get('DB_CHARSET', 'utf8mb4');

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $name, $charset);

        try {
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5,
            ];
            if (defined('PDO::MYSQL_ATTR_CONNECT_TIMEOUT')) {
                $options[PDO::MYSQL_ATTR_CONNECT_TIMEOUT] = 5;
            }
            $this->pdo = new PDO($dsn, $user, $pass, $options);
            $this->pdo->exec("SET time_zone = '+00:00'");
            $this->pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
        } catch (PDOException $e) {
            throw new HttpException(503, 'Databáze je teď nedostupná.');
        }

        return $this->pdo;
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $param = is_int($key) ? $key + 1 : $key;
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($param, $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function fetchColumn(string $sql, array $params = []): mixed
    {
        return $this->query($sql, $params)->fetchColumn();
    }

    public function insert(string $table, array $data): string
    {
        $columns = array_keys($data);
        $placeholders = array_map(static fn (string $col): string => ':' . $col, $columns);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );
        $this->query($sql, $data);
        return $this->pdo()->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = [];
        foreach (array_keys($data) as $column) {
            $sets[] = $column . ' = :' . $column;
        }
        $sql = sprintf('UPDATE %s SET %s WHERE %s', $table, implode(', ', $sets), $where);
        return $this->query($sql, $data + $whereParams)->rowCount();
    }

    public function afterCommit(callable $callback): void
    {
        if (!$this->pdo()->inTransaction()) {
            $callback();
            return;
        }
        $this->afterCommit[] = $callback;
    }

    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();
        $started = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $started = true;
        }
        try {
            $result = $callback($this);
            if ($started) {
                $pdo->commit();
                $this->runAfterCommit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($started) {
                $this->afterCommit = [];
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }
            throw $e;
        }
    }

    private function runAfterCommit(): void
    {
        $hooks = $this->afterCommit;
        $this->afterCommit = [];
        foreach ($hooks as $hook) {
            $hook();
        }
    }

    public function ping(): bool
    {
        try {
            $this->pdo()->query('SELECT 1');
            return true;
        } catch (PDOException) {
            return false;
        }
    }
}
