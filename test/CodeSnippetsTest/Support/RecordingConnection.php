<?php

declare(strict_types=1);

namespace CodeSnippetsTest\Support;

class RecordingConnection
{
    /** @var array<int, string> */
    public $sql = [];

    /**
     * @var array<int, array{0: string, 1: array<string, mixed>}>
     */
    public $inserts = [];

    public function exec($sql): void
    {
        $this->sql[] = (string) $sql;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert($table, array $data): int
    {
        $this->inserts[] = [(string) $table, $data];
        return 1;
    }
}
