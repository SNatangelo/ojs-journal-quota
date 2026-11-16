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
 * @brief Stima dello spazio che ogni rivista occupa nel database.
 *
 *  Il database non dice quanto spazio occupa una singola riga, quindi la stima
 *  divide ogni tabella in proporzione alle righe: se il 30% delle righe di
 *  metrics_submission è della rivista 2, alla rivista 2 va il 30% dello spazio
 *  della tabella (dati e indici, da information_schema).
 *
 *  Per sapere di quale rivista è una riga si seguono le chiavi esterne fino a
 *  una colonna context_id/journal_id (per esempio parole dell'indice di
 *  ricerca → oggetto di ricerca → submission → rivista), oppure le colonne
 *  assoc_type/assoc_id dei log. Le tabelle che non portano a nessuna rivista
 *  (registro ROR, sessioni, utenti, impostazioni del sito) sono di tutto il
 *  sito e non contano per nessuna. Solo MySQL e MariaDB.
 */

namespace APP\plugins\generic\journalQuota\classes;

use APP\core\Application;
use Illuminate\Support\Facades\DB;
use Throwable;

class QuotaDatabase
{
    /** Tabelle che non si contano: il registro del plugin è un costo del plugin, non della rivista */
    public const EXCLUDED_TABLES = [QuotaSchemaMigration::TABLE];

    private const MAX_DEPTH = 6;

    /** Tipi di assoc_type e tabella/colonna a cui puntano */
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
    /** Colonne che possono essere vuote: tabella => [colonna, ...] */
    private array $nullable = [];
    private array $foreignKeys = [];
    private array $routes = [];

    public static function supported(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb']);
    }

    /**
     * Byte stimati per rivista e, per ognuna, le tabelle più pesanti.
     *
     * @return array contextId => ['bytes' => int, 'tables' => [tabella => byte]]
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
                error_log("journalQuota: stima del database, tabella {$table}: " . $e->getMessage());
            }
        }
        foreach ($result as &$entry) {
            arsort($entry['tables']);
            $entry['tables'] = array_slice(array_filter($entry['tables']), 0, 5, true);
        }
        return $result;
    }

    /**
     * Strade da una tabella a una colonna con l'id della rivista. Ogni strada è
     * un elenco di passi: ['fk', colonna, tabella, colonna], ['assoc', tipo,
     * tabella, colonna] e alla fine ['ctx', colonna]. Più strade quando la
     * tabella usa assoc_type (una per tipo); altrimenti la più corta.
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

        // Chiave esterna verso una tabella che porta a una rivista: si sceglie la
        // più corta (per esempio impostazioni di un log → log → submission),
        // preferendo le colonne sempre piene (un DOI può mancare, la submission no)
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
        // Un risultato vuoto può dipendere dal ciclo che si sta esplorando: non si memorizza
        if ($routes) {
            $this->routes[$table] = $routes;
        }
        return $routes;
    }

    /** Righe della tabella per rivista, sommando le strade */
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
