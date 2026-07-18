<?php
namespace AdorationScheduler\Tests\Support;

/**
 * Lightweight in-memory stand-in for $wpdb.
 *
 * Deliberately does NOT parse or execute real SQL — building a SQL engine
 * just to unit-test PHP logic is a rabbit hole with a bad cost/benefit
 * ratio. Instead this is the classic "queue the next response, then assert
 * what was asked for" test double:
 *
 *   $wpdb->will_return_var(1);              // next get_var() call returns 1
 *   $repo->exists_for_slot_person_date(...) // repository code runs unmodified
 *   $this->assertStringContainsString('slot_id = 5', $wpdb->last_query()['sql']);
 *
 * This verifies two things real integration tests would also check, without
 * needing a real database: (1) the repository builds the query you'd expect
 * given its inputs, and (2) the repository correctly interprets whatever the
 * database hands back. What it can't catch — actual SQL syntax errors, real
 * JOIN/GROUP BY semantics, real column types — is exactly what the deferred
 * wp-phpunit integration suite (tests/Integration/) is for.
 *
 * insert()/update()/delete() DO maintain a real in-memory table of rows
 * (keyed by table name), since several tests need insert() to actually
 * produce a usable insert_id and have update()/delete() reflect state for
 * a scenario spanning multiple calls in one test.
 */
class FakeWpdb
{
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    public string $last_error = '';

    /** @var array<int, array{sql:string, raw:string, args:array}> */
    public array $queries = [];

    /** @var array<string, array<int, array<string, mixed>>> table name => rows (assoc arrays, always includes 'id') */
    public array $tables = [];

    private int $next_id = 1;

    /** @var array<int, mixed> */
    private array $var_queue = [];
    /** @var array<int, mixed> */
    private array $row_queue = [];
    /** @var array<int, mixed> */
    private array $results_queue = [];
    /** @var array<int, mixed> */
    private array $col_queue = [];

    // --- Programmable responses, used by tests to set up scenarios --------

    public function will_return_var($value): void
    {
        $this->var_queue[] = $value;
    }

    public function will_return_row($value): void
    {
        $this->row_queue[] = $value;
    }

    public function will_return_results($value): void
    {
        $this->results_queue[] = $value;
    }

    public function will_return_col($value): void
    {
        $this->col_queue[] = $value;
    }

    /**
     * @return array{sql:string, raw:string, args:array}|null
     */
    public function last_query(): ?array
    {
        if (empty($this->queries)) return null;
        return $this->queries[count($this->queries) - 1];
    }

    /**
     * Seeds a fake table directly (bypassing insert()) — handy for setting
     * up "existing rows" a test's WHERE-matching stand-in table needs.
     */
    public function seed(string $table, array $row): int
    {
        $id = $row['id'] ?? $this->next_id++;
        $row['id'] = $id;
        $this->tables[$table][] = $row;
        return (int) $id;
    }

    // --- Core $wpdb surface used by this plugin's repositories/services ---

    public function prepare(string $query, ...$args): string
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }

        $i = 0;
        $sql = preg_replace_callback('/%[dsf%]/', function ($m) use (&$i, $args) {
            if ($m[0] === '%%') return '%';

            $val = $args[$i] ?? null;
            $i++;

            switch ($m[0]) {
                case '%d':
                    return (string) (int) $val;
                case '%f':
                    return (string) (float) $val;
                case '%s':
                default:
                    return "'" . addslashes((string) $val) . "'";
            }
        }, $query);

        $this->queries[] = ['sql' => $sql, 'raw' => $query, 'args' => $args];

        return $sql;
    }

    public function get_var($query = null)
    {
        if ($query !== null) $this->queries[] = ['sql' => $query, 'raw' => $query, 'args' => []];
        return array_shift($this->var_queue);
    }

    public function get_row($query = null, $output = null)
    {
        if ($query !== null) $this->queries[] = ['sql' => $query, 'raw' => $query, 'args' => []];
        return array_shift($this->row_queue);
    }

    public function get_results($query = null, $output = null)
    {
        if ($query !== null) $this->queries[] = ['sql' => $query, 'raw' => $query, 'args' => []];
        if (empty($this->results_queue)) return [];
        return array_shift($this->results_queue);
    }

    public function get_col($query = null)
    {
        if ($query !== null) $this->queries[] = ['sql' => $query, 'raw' => $query, 'args' => []];
        if (empty($this->col_queue)) return [];
        return array_shift($this->col_queue);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string>|string|null $format
     */
    public function insert(string $table, array $data, $format = null): int
    {
        $id = $this->next_id++;
        $data['id'] = $id;
        $this->tables[$table][] = $data;
        $this->insert_id = $id;
        return 1; // wpdb::insert() returns rows-affected (1 on success), not the id
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public function update(string $table, array $data, array $where, $format = null, $where_format = null): int
    {
        $count = 0;
        foreach ($this->tables[$table] ?? [] as &$row) {
            if ($this->row_matches($row, $where)) {
                foreach ($data as $k => $v) {
                    $row[$k] = $v;
                }
                $count++;
            }
        }
        unset($row);
        return $count;
    }

    /**
     * @param array<string, mixed> $where
     */
    public function delete(string $table, array $where, $where_format = null): int
    {
        $count = 0;
        $rows = $this->tables[$table] ?? [];
        $this->tables[$table] = array_values(array_filter($rows, function ($row) use ($where, &$count) {
            if ($this->row_matches($row, $where)) {
                $count++;
                return false;
            }
            return true;
        }));
        return $count;
    }

    public function query(string $sql)
    {
        $this->queries[] = ['sql' => $sql, 'raw' => $sql, 'args' => []];
        return 0;
    }

    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    private function row_matches(array $row, array $where): bool
    {
        foreach ($where as $k => $v) {
            if (!array_key_exists($k, $row) || (string) $row[$k] !== (string) $v) {
                return false;
            }
        }
        return true;
    }
}
