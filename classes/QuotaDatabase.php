<?php
/*
 * classes/QuotaDatabase.php
 * Copyright (C) 2026 Stefano Natangelo
 * SPDX-License-Identifier: AGPL-3.0-only
 */

/**
 * @file plugins/generic/journalQuota/classes/QuotaDatabase.php
 *
 * @class QuotaDatabase
 *
 * @brief Estimates each journal's database storage usage.
 *
 *  The database does not report per-row storage, so the estimate
 *  allocates each table proportionally to its rows: if 30% of
 *  metrics_submission rows belong to journal 2, that journal gets 30%
 *  of the table's data and index space (from information_schema).
 *
 *  To associate rows with journals, follow foreign keys until reaching
 *  a context_id/journal_id column (for example, search index terms
 *  → search object → submission → journal), or use the
 *  assoc_type/assoc_id columns in logs. Tables unrelated to journals
 *  (ROR registry, sessions, users, site settings) are site-wide
 *  and are excluded. MySQL and MariaDB only.
 */

namespace APP\plugins\generic\journalQuota\classes;

use APP\core\Application;
use Illuminate\Support\Facades\DB;
use Throwable;

class QuotaDatabase
{
    /** Excluded tables: the plugin's ledger is a plugin cost, not a journal cost */
    public const EXCLUDED_TABLES = [QuotaSchemaMigration::TABLE];

    private const MAX_DEPTH = 6;

    /** assoc_type values and their target table/column */
    private const ASSOC_TARGETS = [
        Application::ASSOC_TYPE_JOURNAL => ['journals', 'journal_id'],
        Application::ASSOC_TYPE_ISSUE => ['issues', 'issue_id'],
        Application::ASSOC_TYPE_SUBMISSION_FILE => ['submission_files', 'submission_file_id'],
        Application::ASSOC_TYPE_REVIEW_ASSIGNMENT => ['review_assignments', 'review_id'],
        Application::ASSOC_TYPE_SUBMISSION => ['submissions', 'submission_id'],
        Application::ASSOC_TYPE_QUERY => ['queries', 'query_id'],
        Application::ASSOC_TYPE_PUBLICATION => ['publications', 'publication_id'],
    ];

    private array $columns = [];
    /** Nullable columns: table => [column, ...] */
    private array $nullable = [];
    private array $foreignKeys = [];
    private array $routes = [];

    public static function supported(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb']);
    }

    /**
     * Estimated bytes per journal and each journal's largest tables.
     *
     * @return array contextId => ['bytes' => int, 'tables' => [table => bytes]]
     */
    public function measure(): array
    {
        $schema = DB::connection()->getDatabaseName();
        foreach (DB::select('SELECT TABLE_NAME AS t, COLUMN_NAME AS c, IS_NULLABLE AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ?', [$schema]) as $row) {
            $this->columns[$row->t][] = $row->c;
            if ($row->n === 'YES') {
                $this->nullable[$row->t][] = $row->c;
            }
        }
        foreach (DB::select(
            'SELECT TABLE_NAME AS t, COLUMN_NAME AS c, REFERENCED_TABLE_NAME AS rt, REFERENCED_COLUMN_NAME AS rc
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$schema]
        ) as $row) {
            $this->foreignKeys[$row->t][] = [$row->c, $row->rt, $row->rc];
        }
        $sizes = DB::select(
            'SELECT TABLE_NAME AS t, DATA_LENGTH + INDEX_LENGTH AS bytes FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?',
            [$schema, 'BASE TABLE']
        );
        $contextIds = array_map('intval', DB::table('journals')->pluck('journal_id')->all());
        $result = array_fill_keys($contextIds, ['bytes' => 0, 'tables' => []]);

        foreach ($sizes as $size) {
            $table = $size->t;
            if (in_array($table, self::EXCLUDED_TABLES) || !(int) $size->bytes) {
                continue;
            }
            try {
                $routes = $this->routes($table, []);
                if (!$routes) {
                    continue;
                }
                $total = DB::table($table)->count();
                if (!$total) {
                    continue;
                }
                foreach ($this->countByContext($table, $routes) as $contextId => $rows) {
                    if (isset($result[$contextId])) {
                        $bytes = (int) round($size->bytes * min(1, $rows / $total));
                        $result[$contextId]['bytes'] += $bytes;
                        $result[$contextId]['tables'][$table] = $bytes;
                    }
                }
            } catch (Throwable $e) {
                error_log("journalQuota: database estimate, table {$table}: " . $e->getMessage());
            }
        }
        foreach ($result as &$entry) {
            arsort($entry['tables']);
            $entry['tables'] = array_slice(array_filter($entry['tables']), 0, 5, true);
        }
        return $result;
    }

    /**
     * Paths from a table to a journal-ID column. Each path is a
     * list of steps: ['fk', column, table, column], ['assoc', type,
     * table, column], ending with ['ctx', column]. Multiple paths are
     * used for assoc_type tables (one per type); otherwise the shortest.
     */
    private function routes(string $table, array $visiting): array
    {
        if (isset($this->routes[$table])) {
            return $this->routes[$table];
        }
        if (count($visiting) >= self::MAX_DEPTH || in_array($table, $visiting)) {
            return [];
        }
        $visiting[] = $table;
        $columns = $this->columns[$table] ?? [];

        if ($table === 'journals') {
            return $this->routes[$table] = [[['ctx', 'journal_id']]];
        }
        foreach (['context_id', 'journal_id'] as $column) {
            if (in_array($column, $columns)) {
                return $this->routes[$table] = [[['ctx', $column]]];
            }
        }

        // Foreign key to a table linked to a journal: choose the
        // shortest path (for example, log settings → log → submission),
        // preferring non-null columns (a DOI may be absent; a submission cannot).
        $best = null;
        foreach ($this->foreignKeys[$table] ?? [] as [$column, $refTable, $refColumn]) {
            if ($refTable === $table) {
                continue;
            }
            $parent = $this->routes($refTable, $visiting);
            if (!$parent) {
                continue;
            }
            $length = max(array_map('count', $parent))
                + (in_array($column, $this->nullable[$table] ?? []) ? 100 : 0);
            if ($best === null || $length < $best[0]) {
                $best = [$length, array_map(fn ($route) => [['fk', $column, $refTable, $refColumn], ...$route], $parent)];
            }
        }
        if ($best) {
            return $this->routes[$table] = $best[1];
        }

        $routes = [];
        if (in_array('assoc_type', $columns) && in_array('assoc_id', $columns)) {
            foreach (self::ASSOC_TARGETS as $type => [$refTable, $refColumn]) {
                if ($refTable === $table) {
                    continue;
                }
                foreach ($this->routes($refTable, $visiting) as $parent) {
                    $routes[] = [['assoc', $type, $refTable, $refColumn], ...$parent];
                }
            }
        }
        // An empty result may come from the cycle currently being explored: do not cache it.
        if ($routes) {
            $this->routes[$table] = $routes;
        }
        return $routes;
    }

    /** Table rows per journal, summed across paths */
    private function countByContext(string $table, array $routes): array
    {
        $counts = [];
        foreach ($routes as $route) {
            $query = DB::table("{$table} AS t0");
            $alias = 't0';
            $i = 0;
            foreach ($route as $step) {
                if ($step[0] === 'ctx') {
                    $query->whereNotNull("{$alias}.{$step[1]}")
                        ->groupBy("{$alias}.{$step[1]}")
                        ->select(DB::raw("{$alias}.{$step[1]} AS ctx"))
                        ->selectRaw('COUNT(*) AS n');
                    break;
                }
                [$kind, $key, $refTable, $refColumn] = $step;
                $next = 't' . ++$i;
                if ($kind === 'fk') {
                    $query->join("{$refTable} AS {$next}", "{$next}.{$refColumn}", '=', "{$alias}.{$key}");
                } else {
                    $query->join("{$refTable} AS {$next}", "{$next}.{$refColumn}", '=', "{$alias}.assoc_id")
                        ->where("{$alias}.assoc_type", $key);
                }
                $alias = $next;
            }
            foreach ($query->get() as $row) {
                $counts[(int) $row->ctx] = ($counts[(int) $row->ctx] ?? 0) + (int) $row->n;
            }
        }
        return $counts;
    }
}
