<?php
declare(strict_types=1);

/**
 * Modern PHP 8.2+ uyumlu, güvenli ve performans odaklı PDO sarmalayıcı.
 * Prepared statements, statement cache, gelişmiş sorgu oluşturucu ve yardımcı metodlar içerir.
 */
class PdoDb
{
    protected static ?self $_instance = null;
    public static string $prefix = '';

    protected PDO $_pdo;
    protected ?string $_query = null;
    protected ?string $_lastQuery = null;
    protected array $_queryOptions = [];
    protected array $_join = [];
    protected array $_where = [];
    protected array $_having = [];
    protected array $_orderBy = [];
    protected array $_groupBy = [];
    protected array $_bindParams = [];
    public int $count = 0;
    public int $totalCount = 0;
    protected bool $isSubQuery = false;
    public string $returnType = 'array';
    protected ?string $_tableName = null;
    protected ?int $_limit = null;
    protected ?int $_offset = null;

    /** @var array<string, PDOStatement> */
    protected array $_stmtCache = [];
    protected int $_stmtCacheMaxSize = 150;

    public function __construct(
        string|array|null $host = null,
        ?string $username = null,
        ?string $password = null,
        ?string $db = null,
        int $port = 3306,
        string $charset = 'utf8mb4'
    ) {
        if (is_array($host)) {
            extract($host, EXTR_SKIP);
        }

        if ($host !== null) {
            $dsn = "mysql:host={$host};dbname={$db};port={$port};charset={$charset}";
            $this->connect($dsn, $username, $password);
        }

        if (!$this->isSubQuery) {
            self::$_instance = $this;
        }
    }

    private function connect(string $dsn, ?string $username, ?string $password): void
    {
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ];

        try {
            $this->_pdo = new PDO($dsn, $username, $password, $options);
        } catch (PDOException $e) {
            throw new PDOException(
                "Veritabanı bağlantısı kurulamadı: " . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    public static function getInstance(): self
    {
        return self::$_instance
            ?? throw new RuntimeException("PdoDb örneği oluşturulmadı. Önce new PdoDb(...) ile başlatın.");
    }

    public function beginTransaction(): bool { return $this->_pdo->beginTransaction(); }
    public function commit(): bool           { return $this->_pdo->commit(); }
    public function rollback(): bool         { return $this->_pdo->rollBack(); }

    public function table(string $tableName): self
    {
        $this->_tableName = self::$prefix . $tableName;
        return $this;
    }

    public function where(string $prop, mixed $value = 'DBNULL', string $operator = '=', string $cond = 'AND'): self
    {
        if ($this->_where === []) {
            $cond = '';
        }
        $this->_where[] = [$cond, $prop, $operator, $value];
        return $this;
    }

    public function orWhere(string $prop, mixed $value = 'DBNULL', string $operator = '='): self
    {
        return $this->where($prop, $value, $operator, 'OR');
    }

    public function whereIn(string $prop, array $values, string $cond = 'AND'): self
    {
        $this->_where[] = [$cond, $prop, 'IN', $values];
        return $this;
    }

    public function whereNull(string $prop, string $cond = 'AND'): self
    {
        $this->_where[] = [$cond, $prop, 'IS', null];
        return $this;
    }

    public function whereNotNull(string $prop, string $cond = 'AND'): self
    {
        $this->_where[] = [$cond, $prop, 'IS NOT', null];
        return $this;
    }

    public function having(string $prop, mixed $value = 'DBNULL', string $operator = '=', string $cond = 'AND'): self
    {
        if ($this->_having === []) {
            $cond = '';
        }
        $this->_having[] = [$cond, $prop, $operator, $value];
        return $this;
    }

    public function orHaving(string $prop, mixed $value = 'DBNULL', string $operator = '='): self
    {
        return $this->having($prop, $value, $operator, 'OR');
    }

    public function groupBy(string $field): self
    {
        $this->_groupBy[] = $field;
        return $this;
    }

    public function join(string $joinTable, string $joinCondition, string $joinType = 'LEFT'): self
    {
        $this->_join[] = [strtoupper($joinType), self::$prefix . $joinTable, $joinCondition];
        return $this;
    }

    public function orderBy(string $field, string $dir = 'DESC'): self
    {
        $this->_orderBy[$field] = strtoupper($dir) === 'ASC' ? 'ASC' : 'DESC';
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->_limit = $limit;
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->_offset = $offset;
        return $this;
    }

    public function distinct(): self
    {
        $this->_queryOptions[] = 'DISTINCT';
        return $this;
    }

    /** Koşullu sorgu ekleme */
    public function when(mixed $condition, callable $callback, callable $default = null): self
    {
        if ($condition) {
            $callback($this);
        } elseif ($default !== null) {
            $default($this);
        }
        return $this;
    }

    /**
     * @return array<int, array<string, mixed>|object>
     */
    public function get(string $tableName = '', ?int $numRows = null, string|array $columns = '*'): array
    {
        if ($tableName !== '') {
            $this->_tableName = self::$prefix . $tableName;
        }

        if ($this->_tableName === null) {
            throw new RuntimeException("Tablo adı belirtilmedi.");
        }

        $cols = is_array($columns) ? implode(', ', $columns) : $columns;
        $options = $this->_queryOptions !== [] ? implode(' ', $this->_queryOptions) . ' ' : '';

        $this->_query = "SELECT {$options}{$cols} FROM `{$this->_tableName}`";
        $this->_buildQuery();

        if ($this->_limit !== null) {
            $limitStr = $this->_offset !== null
                ? "{$this->_offset}, {$this->_limit}"
                : (string) $this->_limit;
            $this->_query .= " LIMIT {$limitStr}";
        } elseif ($numRows !== null) {
            $this->_query .= " LIMIT {$numRows}";
        }

        $stmt = $this->_execute();
        $fetchMode = $this->returnType === 'object' ? PDO::FETCH_OBJ : PDO::FETCH_ASSOC;
        $res = $stmt->fetchAll($fetchMode);
        $this->count = count($res);
        $this->_reset();

        return $res;
    }

    public function first(string|array $columns = '*'): ?array
    {
        $result = $this->limit(1)->get('', null, $columns);
        return $result[0] ?? null;
    }

    public function value(string $column): mixed
    {
        $row = $this->first($column);
        return $row[$column] ?? null;
    }

    /** @return array<int, mixed> */
    public function pluck(string $column): array
    {
        $rows = $this->get('', null, $column);
        return array_column($rows, $column);
    }

    public function count(string $tableName = ''): int
    {
        if ($tableName !== '') {
            $this->_tableName = self::$prefix . $tableName;
        }

        if ($this->_tableName === null) {
            throw new RuntimeException("Tablo adı belirtilmedi.");
        }

        $this->_query = "SELECT COUNT(*) AS total FROM `{$this->_tableName}`";
        $this->_buildQuery();

        $stmt = $this->_execute();
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->_reset();

        return (int) ($res['total'] ?? 0);
    }

    public function insert(string $tableName, array $data): ?string
    {
        $this->_tableName = self::$prefix . $tableName;
        if ($data === []) {
            return null;
        }

        $keys = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($keys), '?'));
        $this->_query = "INSERT INTO `{$this->_tableName}` (`"
            . implode('`, `', $keys)
            . "`) VALUES ({$placeholders})";
        $this->_bindParams = array_values($data);

        $this->_execute();
        $id = $this->_pdo->lastInsertId();
        $this->_reset();

        return $id !== false && $id !== '' ? $id : null;
    }

    public function update(string $tableName, array $data): int
    {
        $this->_tableName = self::$prefix . $tableName;
        if ($data === []) {
            return 0;
        }

        $sets = [];
        foreach (array_keys($data) as $key) {
            $sets[] = "`{$key}` = ?";
        }

        $this->_query = "UPDATE `{$this->_tableName}` SET " . implode(', ', $sets);
        $this->_buildQuery();

        $this->_bindParams = array_merge(array_values($data), $this->_bindParams);

        $stmt = $this->_execute();
        $affected = $stmt->rowCount();
        $this->_reset();

        return $affected;
    }

    public function delete(string $tableName): int
    {
        $this->_tableName = self::$prefix . $tableName;
        $this->_query = "DELETE FROM `{$this->_tableName}`";
        $this->_buildQuery();

        $stmt = $this->_execute();
        $affected = $stmt->rowCount();
        $this->_reset();

        return $affected;
    }

    /** @param array<int|string, mixed> $params */
    public function rawQuery(string $query, array $params = []): array
    {
        try {
            $stmt = $this->_pdo->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new PDOException(
                "Ham sorgu çalıştırılamadı: " . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    protected function _execute(): PDOStatement
    {
        if ($this->_query === null) {
            throw new RuntimeException('Sorgu oluşturulmadı.');
        }

        $cacheKey = md5($this->_query . serialize($this->_bindParams));

        if (isset($this->_stmtCache[$cacheKey])) {
            $stmt = $this->_stmtCache[$cacheKey];
        } else {
            try {
                $stmt = $this->_pdo->prepare($this->_query);
            } catch (PDOException $e) {
                throw new PDOException(
                    "Sorgu hazırlanamadı: " . $e->getMessage() . " | SQL: {$this->_query}",
                    (int) $e->getCode(),
                    $e
                );
            }

            $this->_stmtCache[$cacheKey] = $stmt;

            if (count($this->_stmtCache) > $this->_stmtCacheMaxSize) {
                array_shift($this->_stmtCache);
            }
        }

        try {
            $stmt->execute($this->_bindParams);
        } catch (PDOException $e) {
            throw new PDOException(
                "Sorgu çalıştırılamadı: " . $e->getMessage() . " | SQL: {$this->_query}",
                (int) $e->getCode(),
                $e
            );
        }

        $this->_lastQuery = $this->_query;
        return $stmt;
    }

    protected function _buildQuery(): void
    {
        if ($this->_query === null) {
            return;
        }

        if ($this->_join !== []) {
            foreach ($this->_join as $j) {
                $this->_query .= " {$j[0]} JOIN `{$j[1]}` ON {$j[2]}";
            }
        }

        if ($this->_where !== []) {
            $this->_query .= ' WHERE ';
            $first = true;
            foreach ($this->_where as $w) {
                $cond = $first ? '' : $w[0] . ' ';
                $first = false;

                if ($w[2] === 'IN') {
                    $values = $w[3];
                    $ph = implode(', ', array_fill(0, count($values), '?'));
                    $this->_query .= "{$cond}`{$w[1]}` IN ({$ph}) ";
                    $this->_bindParams = array_merge($this->_bindParams, $values);
                } elseif (in_array($w[2], ['IS', 'IS NOT'], true)) {
                    $this->_query .= "{$cond}`{$w[1]}` {$w[2]} NULL ";
                } else {
                    $this->_query .= "{$cond}`{$w[1]}` {$w[2]} ? ";
                    $this->_bindParams[] = $w[3];
                }
            }
        }

        if ($this->_groupBy !== []) {
            $this->_query .= ' GROUP BY ' . implode(', ', $this->_groupBy);
        }

        if ($this->_having !== []) {
            $this->_query .= ' HAVING ';
            $first = true;
            foreach ($this->_having as $h) {
                $cond = $first ? '' : $h[0] . ' ';
                $first = false;

                if (in_array($h[2], ['IS', 'IS NOT'], true)) {
                    $this->_query .= "{$cond}`{$h[1]}` {$h[2]} NULL ";
                } else {
                    $this->_query .= "{$cond}`{$h[1]}` {$h[2]} ? ";
                    $this->_bindParams[] = $h[3];
                }
            }
        }

        if ($this->_orderBy !== []) {
            $orders = [];
            foreach ($this->_orderBy as $col => $dir) {
                $orders[] = "`{$col}` {$dir}";
            }
            $this->_query .= ' ORDER BY ' . implode(', ', $orders);
        }
    }

    protected function _reset(): void
    {
        $this->_where = [];
        $this->_join = [];
        $this->_orderBy = [];
        $this->_groupBy = [];
        $this->_having = [];
        $this->_bindParams = [];
        $this->_queryOptions = [];
        $this->_query = null;
        $this->_tableName = null;
        $this->_limit = null;
        $this->_offset = null;
    }

    public function toSql(): string
    {
        return $this->_query ?? '(sorgu oluşturulmadı)';
    }

    public function getBindings(): array
    {
        return $this->_bindParams;
    }

    public function toSqlWithBindings(): string
    {
        $q = $this->_query ?? '';
        foreach ($this->_bindParams as $val) {
            $escaped = $val === null ? 'NULL' : $this->_pdo->quote((string) $val);
            $q = preg_replace('/\?/', $escaped, $q, 1) ?? $q;
        }
        return $q;
    }

    public function __clone(): void
    {
        $this->_reset();
    }
}
