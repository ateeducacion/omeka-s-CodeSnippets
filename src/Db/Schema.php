<?php

declare(strict_types=1);

namespace CodeSnippets\Db;

/**
 * SQL for the dedicated snippet table.
 *
 * The physical name `code_snippet` is prefixed by the module domain, not by
 * a generic settings blob, so later upgrades can ALTER this table in place.
 */
class Schema
{
    public const TABLE = 'code_snippet';
    public const INDEX_ACTIVE_PRIORITY_ID = 'idx_code_snippet_active_priority_id';

    /**
     * Create-table statement used by install().
     *
     * Matches the Omeka S 4 / Doctrine DBAL MySQL dialect used by core
     * migrations (utf8mb4, InnoDB, unicode collation).
     */
    public static function createTableSql(): string
    {
        $table = self::TABLE;
        $index = self::INDEX_ACTIVE_PRIORITY_ID;

        return "CREATE TABLE `$table` ("
            . '`id` INT NOT NULL AUTO_INCREMENT,'
            . '`name` VARCHAR(255) NOT NULL,'
            . '`description` LONGTEXT DEFAULT NULL,'
            . '`code` LONGTEXT NOT NULL,'
            . '`priority` INT NOT NULL DEFAULT 10,'
            . '`active` TINYINT(1) NOT NULL DEFAULT 0,'
            . '`signature` VARCHAR(96) DEFAULT NULL,'
            . '`run_scope` VARCHAR(32) NOT NULL DEFAULT \'global\','
            . '`created` DATETIME NOT NULL,'
            . '`modified` DATETIME NOT NULL,'
            . '`last_error_type` VARCHAR(255) DEFAULT NULL,'
            . '`last_error_message` LONGTEXT DEFAULT NULL,'
            . '`last_error_line` INT DEFAULT NULL,'
            . '`last_error_at` DATETIME DEFAULT NULL,'
            . "INDEX `$index` (`active`, `priority`, `id`),"
            . 'PRIMARY KEY (`id`)'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB';
    }

    /**
     * SQLite-compatible schema for unit tests.
     */
    public static function createTableSqliteSql(): string
    {
        $table = self::TABLE;
        $index = self::INDEX_ACTIVE_PRIORITY_ID;

        return "CREATE TABLE $table ("
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT,'
            . 'name VARCHAR(255) NOT NULL,'
            . 'description TEXT DEFAULT NULL,'
            . 'code TEXT NOT NULL,'
            . 'priority INTEGER NOT NULL DEFAULT 10,'
            . 'active INTEGER NOT NULL DEFAULT 0,'
            . 'signature VARCHAR(96) DEFAULT NULL,'
            . "run_scope VARCHAR(32) NOT NULL DEFAULT 'global',"
            . 'created TEXT NOT NULL,'
            . 'modified TEXT NOT NULL,'
            . 'last_error_type VARCHAR(255) DEFAULT NULL,'
            . 'last_error_message TEXT DEFAULT NULL,'
            . 'last_error_line INTEGER DEFAULT NULL,'
            . 'last_error_at TEXT DEFAULT NULL'
            . ')';
    }

    public static function createIndexSqliteSql(): string
    {
        $table = self::TABLE;
        $index = self::INDEX_ACTIVE_PRIORITY_ID;
        return "CREATE INDEX $index ON $table (active, priority, id)";
    }

    public static function dropTableSql(): string
    {
        return 'DROP TABLE IF EXISTS `' . self::TABLE . '`';
    }

    public static function selectActiveOrderedSql(): string
    {
        return 'SELECT id, name, description, code, priority, active, run_scope, signature FROM `' . self::TABLE . '`'
            . ' WHERE active = ? ORDER BY priority ASC, id ASC';
    }

    public static function selectActiveOrderedForScopeSql(): string
    {
        return 'SELECT id, name, description, code, priority, active, run_scope, signature FROM `' . self::TABLE . '`'
            . ' WHERE active = ? AND run_scope IN (?, ?) ORDER BY priority ASC, id ASC';
    }

    public static function addRunScopeColumnSql(): string
    {
        return 'ALTER TABLE `' . self::TABLE . '`'
            . " ADD `run_scope` VARCHAR(32) NOT NULL DEFAULT 'global'";
    }

    public static function addSignatureColumnSql(): string
    {
        return 'ALTER TABLE `' . self::TABLE . '` ADD `signature` VARCHAR(96) DEFAULT NULL';
    }
}
