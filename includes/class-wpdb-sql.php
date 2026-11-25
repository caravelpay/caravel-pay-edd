<?php
/**
 * The kit's Sql interface on WordPress's $wpdb.
 *
 * @package CaravelPayEdd
 */

namespace CaravelPay\Edd;

use CaravelPay\Kit\Sql;

if (!defined('ABSPATH')) {
    exit;
}

final class WpdbSql implements Sql
{
    /** @var \wpdb */
    private $wpdb;

    public function __construct($wpdb)
    {
        $this->wpdb = $wpdb;
    }

    public function driver(): string
    {
        return !empty($this->wpdb->caravel_sqlite) ? 'sqlite' : 'mysql';
    }

    public function exec(string $sql): void
    {
        if (false === $this->wpdb->query($sql)) {
            throw new \RuntimeException('Caravel Pay storage: schema statement failed.');
        }
    }

    public function run(string $sql, array $params): int
    {
        $suppress = $this->wpdb->suppress_errors(true);
        try {
            $result = $this->wpdb->query($this->bind($sql, $params));
        } finally {
            $this->wpdb->suppress_errors($suppress);
        }
        if (false === $result) {
            throw new \RuntimeException('Caravel Pay storage: statement failed.');
        }
        return (int) $result;
    }

    public function one(string $sql, array $params): ?array
    {
        $row = $this->wpdb->get_row($this->bind($sql, $params), ARRAY_A);
        if ('' !== (string) $this->wpdb->last_error && null === $row) {
            throw new \RuntimeException('Caravel Pay storage: query failed.');
        }
        return is_array($row) ? $row : null;
    }

    public function all(string $sql, array $params): array
    {
        $rows = $this->wpdb->get_results($this->bind($sql, $params), ARRAY_A);
        if (!is_array($rows)) {
            throw new \RuntimeException('Caravel Pay storage: query failed.');
        }
        return $rows;
    }

    /** "?" placeholders → $wpdb->prepare() (%d for integers, %s otherwise). */
    private function bind(string $sql, array $params): string
    {
        if (!$params) {
            return $sql;
        }
        $index = 0;
        $query = preg_replace_callback('/\?/', function () use (&$index, $params) {
            $value = $params[$index++];
            return is_int($value) ? '%d' : '%s';
        }, $sql);
        $values = array_map(function ($value) {
            return null === $value ? '' : $value;
        }, $params);
        return $this->wpdb->prepare($query, $values);
    }
}
