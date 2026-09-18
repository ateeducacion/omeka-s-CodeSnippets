<?php

declare(strict_types=1);

namespace CodeSnippetsTest\Support;

use PDO;
use PDOStatement;

/**
 * Minimal Doctrine DBAL-compatible connection used by SnippetRepository tests.
 */
class PdoConnection
{
    /** @var PDO */
    private $pdo;

    /** @var bool */
    private $inTransaction = false;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function fetchAssociative(string $sql, array $params = [])
    {
        $statement = $this->run($sql, $params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? false : $row;
    }

    public function fetchAllAssociative(string $sql, array $params = []): array
    {
        $statement = $this->run($sql, $params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $quoted = [];
        foreach ($columns as $column) {
            $quoted[] = '`' . $column . '`';
        }
        $sql = 'INSERT INTO `' . $table . '` (' . implode(', ', $quoted) . ') VALUES (' . $placeholders . ')';
        $statement = $this->run($sql, array_values($data));
        return $statement->rowCount();
    }

    public function update(string $table, array $data, array $identifier): int
    {
        $sets = [];
        $params = [];
        foreach ($data as $column => $value) {
            $sets[] = '`' . $column . '` = ?';
            $params[] = $value;
        }
        $wheres = [];
        foreach ($identifier as $column => $value) {
            $wheres[] = '`' . $column . '` = ?';
            $params[] = $value;
        }
        $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $sets)
            . ' WHERE ' . implode(' AND ', $wheres);
        $statement = $this->run($sql, $params);
        return $statement->rowCount();
    }

    public function delete(string $table, array $identifier): int
    {
        $wheres = [];
        $params = [];
        foreach ($identifier as $column => $value) {
            $wheres[] = '`' . $column . '` = ?';
            $params[] = $value;
        }
        $sql = 'DELETE FROM `' . $table . '` WHERE ' . implode(' AND ', $wheres);
        $statement = $this->run($sql, $params);
        return $statement->rowCount();
    }

    public function lastInsertId(): string
    {
        return (string) $this->pdo->lastInsertId();
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
        $this->inTransaction = true;
    }

    public function commit(): void
    {
        $this->pdo->commit();
        $this->inTransaction = false;
    }

    public function rollBack(): void
    {
        $this->pdo->rollBack();
        $this->inTransaction = false;
    }

    public function isTransactionActive(): bool
    {
        return $this->inTransaction;
    }

    public function exec(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    /**
     * @param array<int, mixed> $params
     */
    private function run(string $sql, array $params): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $normalized = [];
        foreach ($params as $value) {
            $normalized[] = $value;
        }
        $statement->execute($normalized);
        return $statement;
    }
}
