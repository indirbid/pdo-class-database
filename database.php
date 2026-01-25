<?php
namespace App\Database;
use App\Config\Config;
use PDO;
use PDOException;
use Exception;
use Generator;
class Database
{   
    public $pdo;
    private array $params = [];
    private string $query = '';
    private string $queryType = '';
    private int $rowCount = 0;
    private string $table = '';
    private array $join = [];
    private array $groupBy = [];
    private array $having = [];
    private array $orderBy = [];
    private ?array $updateColumns = null;
    private ?string $lastInsertId = null;
    private bool $useGenerator = false;
    private bool $forUpdate = false;
    private bool $lockInShareMode = false;
    private string $prefix = '';
    private bool $transaction = false;
    private array $lastError = [];
    private string $lastErrorCode = ''; 
  
    public function __construct()
    {
        // Config sınıfından verileri çekiyoruz
        $config = Config::getDB();
        
        $dsn = sprintf(
            "%s:host=%s;dbname=%s;port=%s;charset=%s",
            $config['type'], $config['host'], $config['dbname'], $config['port'], $config['charset']
        );

        try {
            $this->pdo = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            throw new Exception("Bağlantı Hatası: " . $e->getMessage());
        }
    }
    // ------------------- TABLE & SELECT -------------------
    public function table(string $table): self
    {
        $this->table = "`{$this->prefix}{$table}`";
        $this->query = "SELECT * FROM {$this->table}";
        $this->queryType = 'SELECT';
        return $this;
    }

    public function select(array $columns = ['*']): self
    {
        $cols = implode(',', array_map(fn($c) => "`{$c}`", $columns));
        $this->query = "SELECT {$cols} FROM {$this->table}";
        return $this;
    }

    // ------------------- WHERE -------------------
    public function where(string $column, string $operator, mixed $value, string $concat = 'AND'): self
    {
        if (!empty($this->whereClause)) {
            $this->whereClause .= " {$concat} ";
        }
        $paramName = ":{$column}";
        $this->params[$paramName] = $value;
        $this->whereClause .= "`{$column}` {$operator} {$paramName}";
        return $this;
    }

    // ------------------- JOIN -------------------
    public function join(string $table, string $on): self
    {
        $joinedTable = "`{$this->prefix}{$table}`";
        $this->query .= " INNER JOIN {$joinedTable} ON {$on}";
        return $this;
    }

    // ------------------- GROUP BY -------------------
    public function groupBy(array $columns): self
    {
        $cols = implode(',', array_map(fn($c) => "`{$c}`", $columns));
        $this->query .= " GROUP BY {$cols}";
        return $this;
    }

    // ------------------- HAVING -------------------
    public function having(string $condition): self
    {
        $this->havingClause = " HAVING {$condition}";
        return $this;
    }

    // ------------------- ORDER BY -------------------
    public function orderBy(array $columns, string $direction = 'ASC'): self
    {
        $cols = implode(',', array_map(fn($c) => "`{$c}`", $columns));
        $this->query .= " ORDER BY {$cols} {$direction}";
        return $this;
    }

    // ------------------- TRANSACTION MANAGEMENT -------------------
    public function beginTransaction(): bool
    {
        if ($this->transaction) {
            throw new Exception("A transaction is already in progress.");
        }
        $this->pdo->beginTransaction();
        $this->transaction = true;
        return true;
    }

    public function commit(): bool
    {
        if (!$this->transaction) {
            throw new Exception("No active transaction to commit.");
        }
        $this->pdo->commit();
        $this->transaction = false;
        return true;
    }

    public function rollback(): bool
    {
        if (!$this->transaction) {
            throw new Exception("No active transaction to rollback.");
        }
        $this->pdo->rollback();
        $this->transaction = false;
        return true;
    }

    // ------------------- ERROR HANDLING -------------------
    public function getLastError(): array
    {
        return $this->lastError;
    }

    public function getLastErrorCode(): string
    {
        return $this->lastErrorCode;
    }

    // ------------------- HELPERS -------------------
    public function setPrefix(string $prefix): void
    {
        $this->prefix = $prefix;
    }

    private function reset(): void
    {
        $this->params = [];
        $this->query = '';
        $this->queryType = '';
        $this->updateColumns = null;
        $this->forUpdate = false;
        $this->lockInShareMode = false;
        $this->useGenerator = false;
    }

    private function checkTransactionStatus(): void
    {
        if ($this->transaction) {
            $this->rollback();
        }
    }

    public function fetchAll(): array
    {
        try {
            $stmt = $this->pdo->prepare($this->query);
            $stmt->execute($this->params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->lastError[] = $e->getMessage();
            $this->lastErrorCode = $e->getCode();
            return [];
        }
    }

    public function fetch(): array
    {
        try {
            $stmt = $this->pdo->prepare($this->query);
            $stmt->execute($this->params);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            $this->lastError[] = $e->getMessage();
            $this->lastErrorCode = $e->getCode();
            return [];
        }
    }

    public function generator(): \Generator
    {
        try {
            $stmt = $this->pdo->prepare($this->query);
            $stmt->execute($this->params);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                yield $row;
            }
        } catch (PDOException $e) {
            $this->lastError[] = $e->getMessage();
            $this->lastErrorCode = $e->getCode();
        }
    }

    public function insert(array $data): bool
    {
        try {
            $columns = implode(',', array_map(fn($c) => "`{$c}`", array_keys($data)));
            $values = implode(',', array_fill(0, count($data), '?'));
            $this->query = "INSERT INTO {$this->table} ({$columns}) VALUES ({$values})";
            $stmt = $this->pdo->prepare($this->query);
            $stmt->execute(array_values($data));
            $this->lastInsertId = $this->pdo->lastInsertId();
            return true;
        } catch (PDOException $e) {
            $this->lastError[] = $e->getMessage();
            $this->lastErrorCode = $e->getCode();
            return false;
        }
    }

    public function update(array $data): bool
    {
        try {
            $setClause = implode(',', array_map(fn($c) => "`{$c}` = ?", array_keys($data)));
            $this->query = "UPDATE {$this->table} SET {$setClause}";
            if (!empty($this->whereClause)) {
                $this->query .= " WHERE {$this->whereClause}";
            }
            $stmt = $this->pdo->prepare($this->query);
            $stmt->execute(array_merge(array_values($data), array_values($this->params)));
            return true;
        } catch (PDOException $e) {
            $this->lastError[] = $e->getMessage();
            $this->lastErrorCode = $e->getCode();
            return false;
        }
    }

    public function delete(): bool
    {
        try {
            $this->query = "DELETE FROM {$this->table}";
            if (!empty($this->whereClause)) {
                $this->query .= " WHERE {$this->whereClause}";
            }
            $stmt = $this->pdo->prepare($this->query);
            $stmt->execute(array_values($this->params));
            return true;
        } catch (PDOException $e) {
            $this->lastError[] = $e->getMessage();
            $this->lastErrorCode = $e->getCode();
            return false;
        }
    }

    public function rowCount(): int
    {
        return $this->rowCount;
    }

    public function lastInsertId()
    {
        return $this->lastInsertId;
    }
}
