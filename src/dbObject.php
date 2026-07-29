<?php
declare(strict_types=1);

/**
 * Basit Collection sınıfı – model koleksiyonlarını rahat yönetmek için.
 */
class Collection implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
    /** @var array<int, mixed> */
    protected array $items = [];

    public function __construct(array $items = [])
    {
        $this->items = array_values($items);
    }

    public function all(): array { return $this->items; }

    public function first(callable $callback = null): mixed
    {
        if ($callback === null) {
            return $this->items[0] ?? null;
        }
        foreach ($this->items as $item) {
            if ($callback($item)) {
                return $item;
            }
        }
        return null;
    }

    public function last(): mixed
    {
        return $this->items === [] ? null : $this->items[array_key_last($this->items)];
    }

    public function pluck(string $key): array
    {
        $results = [];
        foreach ($this->items as $item) {
            if (is_array($item)) {
                $results[] = $item[$key] ?? null;
            } elseif (is_object($item)) {
                $results[] = $item->{$key} ?? null;
            }
        }
        return $results;
    }

    public function map(callable $callback): self
    {
        return new self(array_map($callback, $this->items));
    }

    public function filter(callable $callback = null): self
    {
        if ($callback === null) {
            return new self(array_filter($this->items));
        }
        return new self(array_values(array_filter($this->items, $callback)));
    }

    public function each(callable $callback): self
    {
        foreach ($this->items as $key => $item) {
            if ($callback($item, $key) === false) {
                break;
            }
        }
        return $this;
    }

    public function isEmpty(): bool { return $this->items === []; }
    public function isNotEmpty(): bool { return !$this->isEmpty(); }
    public function count(): int { return count($this->items); }

    public function toArray(): array
    {
        return array_map(static function ($item) {
            return $item instanceof dbObject ? $item->toArray() : $item;
        }, $this->items);
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public function jsonSerialize(): array { return $this->toArray(); }
    public function getIterator(): Traversable { return new ArrayIterator($this->items); }

    public function offsetExists(mixed $offset): bool { return isset($this->items[$offset]); }
    public function offsetGet(mixed $offset): mixed { return $this->items[$offset] ?? null; }
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }
    public function offsetUnset(mixed $offset): void { unset($this->items[$offset]); }
}

#[Attribute(Attribute::TARGET_CLASS)]
class Table
{
    public function __construct(public readonly string $name) {}
}

#[Attribute(Attribute::TARGET_PROPERTY)]
class Column
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly bool $fillable = true,
        public readonly bool $guarded = false,
    ) {}
}

/**
 * Aktif Kayıt tabanlı model temel sınıfı.
 * PHP 8.2+, soft-delete, ilişki, scope, eager loading, collection ve transaction destekli.
 */
abstract class dbObject
{
    protected readonly PdoDb $db;
    protected readonly string $table;
    protected string $primaryKey = 'id';

    protected array $attributes = [];
    protected array $original = [];
    protected bool $exists = false;

    protected bool $timestamps = true;
    protected bool $softDeletes = false;
    protected string $deletedAtColumn = 'deleted_at';

    protected array $fillable = [];
    protected array $guarded = ['id', 'created_at', 'updated_at', 'deleted_at'];
    protected array $hidden = [];
    protected array $casts = [];

    protected array $relations = [];
    protected array $with = [];

    /** @var array<string, callable> */
    protected static array $globalScopes = [];

    public function __construct(array $attributes = [])
    {
        $this->db = PdoDb::getInstance();

        $ref = new ReflectionClass($this);
        $tableAttrs = $ref->getAttributes(Table::class);
        $this->table = $tableAttrs
            ? $tableAttrs[0]->newInstance()->name
            : $this->guessTable();

        if ($attributes !== []) {
            $this->forceFill($attributes);
            $this->exists = true;
            $this->syncOriginal();
        }
    }

    private function guessTable(): string
    {
        return strtolower((new ReflectionClass($this))->getShortName()) . 's';
    }

    /* ------------------------------------------------------------------
     * Query Builder Proxy
     * ----------------------------------------------------------------*/

    public static function query(): static
    {
        $instance = new static();
        $instance->applyGlobalScopes();
        return $instance;
    }

    public static function __callStatic(string $method, array $parameters): mixed
    {
        return static::query()->$method(...$parameters);
    }

    public function __call(string $method, array $parameters): mixed
    {
        if (method_exists($this->db, $method)) {
            $this->db->table($this->table);
            $result = $this->db->$method(...$parameters);
            return $result instanceof PdoDb ? $this : $result;
        }
        throw new BadMethodCallException("Metod bulunamadı: {$method}");
    }

    protected function applyGlobalScopes(): void
    {
        if ($this->softDeletes) {
            $this->db->whereNull($this->deletedAtColumn);
        }
        foreach (static::$globalScopes as $scope) {
            $scope($this);
        }
    }

    public static function addGlobalScope(string $name, callable $scope): void
    {
        static::$globalScopes[$name] = $scope;
    }

    /* ------------------------------------------------------------------
     * CRUD
     * ----------------------------------------------------------------*/

    public function fill(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            if ($this->isFillable($key)) {
                $this->__set($key, $value);
            }
        }
        return $this;
    }

    public function forceFill(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            $this->__set($key, $value);
        }
        return $this;
    }

    protected function isFillable(string $key): bool
    {
        try {
            $prop = new ReflectionProperty($this, $key);
            $attrs = $prop->getAttributes(Column::class);
            if ($attrs) {
                $col = $attrs[0]->newInstance();
                if (!$col->fillable) {
                    return false;
                }
            }
        } catch (ReflectionException) {
            // devam
        }

        return $this->fillable !== []
            ? in_array($key, $this->fillable, true)
            : !in_array($key, $this->guarded, true);
    }

    public function __set(string $key, mixed $value): void
    {
        $this->validate($key, $value);

        $method = 'set' . ucfirst($key) . 'Attribute';
        if (method_exists($this, $method)) {
            $this->$method($value);
            return;
        }
        $this->attributes[$key] = $value;
    }

    public function __get(string $key): mixed
    {
        if (array_key_exists($key, $this->attributes)) {
            $value = $this->attributes[$key];
            $method = 'get' . ucfirst($key) . 'Attribute';
            return method_exists($this, $method)
                ? $this->$method($value)
                : $this->castAttribute($key, $value);
        }

        if (array_key_exists($key, $this->relations)) {
            return $this->relations[$key];
        }

        if (method_exists($this, $key)) {
            $relation = $this->$key();
            $this->relations[$key] = $relation;
            return $relation;
        }

        return null;
    }

    protected function validate(string $key, mixed $value): void
    {
        if ($key === 'email' && is_string($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Geçersiz e-posta formatı: {$value}");
        }
    }

    protected function castAttribute(string $key, mixed $value): mixed
    {
        $type = $this->casts[$key] ?? null;

        return match ($type) {
            'int', 'integer'  => (int) $value,
            'bool', 'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'float'           => (float) $value,
            'array'           => is_string($value) ? (json_decode($value, true) ?? []) : $value,
            'datetime'        => $value ? new DateTimeImmutable((string) $value) : null,
            default           => $value,
        };
    }

    public function save(): bool
    {
        if (!$this->fire('saving')) {
            return false;
        }

        $dirty = $this->getDirty();

        if ($dirty === [] && $this->exists) {
            return true;
        }

        if ($this->timestamps) {
            $now = date('Y-m-d H:i:s');
            if (!$this->exists && !isset($this->attributes['created_at'])) {
                $dirty['created_at'] = $this->attributes['created_at'] = $now;
            }
            $dirty['updated_at'] = $this->attributes['updated_at'] = $now;
        }

        if ($this->exists) {
            if (!$this->fire('updating')) {
                return false;
            }
            $this->db->where($this->primaryKey, $this->getKey());
            $affected = $this->db->update($this->table, $dirty);
            if ($affected === 0) {
                return false;
            }
            $this->fire('updated');
        } else {
            if (!$this->fire('creating')) {
                return false;
            }
            $id = $this->db->insert($this->table, $dirty);
            if ($id === null) {
                return false;
            }
            $this->attributes[$this->primaryKey] = $id;
            $this->exists = true;
            $this->fire('created');
        }

        $this->fire('saved');
        $this->syncOriginal();
        return true;
    }

    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }
        if (!$this->fire('deleting')) {
            return false;
        }

        if ($this->softDeletes) {
            $now = date('Y-m-d H:i:s');
            $this->db->where($this->primaryKey, $this->getKey());
            $res = $this->db->update($this->table, [$this->deletedAtColumn => $now]) > 0;
            if ($res) {
                $this->attributes[$this->deletedAtColumn] = $now;
            }
        } else {
            $this->db->where($this->primaryKey, $this->getKey());
            $res = $this->db->delete($this->table) > 0;
            if ($res) {
                $this->exists = false;
            }
        }

        if ($res) {
            $this->fire('deleted');
        }
        return $res;
    }

    public function restore(): bool
    {
        if (!$this->softDeletes || !$this->trashed()) {
            return false;
        }
        $this->db->where($this->primaryKey, $this->getKey());
        $affected = $this->db->update($this->table, [$this->deletedAtColumn => null]);
        if ($affected > 0) {
            $this->attributes[$this->deletedAtColumn] = null;
            return true;
        }
        return false;
    }

    public function forceDelete(): bool
    {
        if (!$this->exists) {
            return false;
        }
        $this->db->where($this->primaryKey, $this->getKey());
        $res = $this->db->delete($this->table) > 0;
        if ($res) {
            $this->exists = false;
        }
        return $res;
    }

    public function trashed(): bool
    {
        return $this->softDeletes && !empty($this->attributes[$this->deletedAtColumn]);
    }

    public static function withTrashed(): static
    {
        $instance = new static();
        // soft delete global scope uygulanmaz
        return $instance;
    }

    public static function onlyTrashed(): static
    {
        $instance = new static();
        $instance->db->whereNotNull($instance->deletedAtColumn);
        return $instance;
    }

    /* ------------------------------------------------------------------
     * Sorgular
     * ----------------------------------------------------------------*/

    public static function paginate(int $perPage = 15, int $page = 1, array $columns = ['*']): array
    {
        $instance = static::query();
        $offset = ($page - 1) * $perPage;

        $total = $instance->db->table($instance->table)->count();

        $items = $instance->db->table($instance->table)
            ->limit($perPage)
            ->offset($offset)
            ->get('', null, $columns);

        $models = array_map(static fn(array $row): static => new static($row), $items);

        if ($instance->with !== []) {
            $instance->eagerLoad($models);
        }

        return [
            'data'         => new Collection($models),
            'current_page' => $page,
            'per_page'     => $perPage,
            'total'        => $total,
            'last_page'    => $total > 0 ? (int) ceil($total / $perPage) : 1,
            'from'         => $total > 0 ? $offset + 1 : 0,
            'to'           => $total > 0 ? min($offset + $perPage, $total) : 0,
        ];
    }

    public static function find(mixed $id): ?static
    {
        $instance = static::query();
        $row = $instance->db->table($instance->table)
            ->where($instance->primaryKey, $id)
            ->first();

        if ($row === null) {
            return null;
        }

        $model = new static($row);
        if ($model->with !== []) {
            $model->eagerLoad([$model]);
        }
        return $model;
    }

    public static function findOrFail(mixed $id): static
    {
        $model = static::find($id);
        if ($model === null) {
            throw new RuntimeException(static::class . " bulunamadı (ID: {$id})");
        }
        return $model;
    }

    public static function all(array $columns = ['*']): Collection
    {
        $instance = static::query();
        $rows = $instance->db->table($instance->table)->get('', null, $columns);
        $models = array_map(static fn(array $r): static => new static($r), $rows);

        if ($instance->with !== []) {
            $instance->eagerLoad($models);
        }
        return new Collection($models);
    }

    public static function get(array $columns = ['*']): Collection
    {
        return static::all($columns);
    }

    public static function first(array $columns = ['*']): ?static
    {
        $instance = static::query();
        $row = $instance->db->table($instance->table)->first($columns);
        return $row ? new static($row) : null;
    }

    /* ------------------------------------------------------------------
     * İlişkiler & Eager Loading
     * ----------------------------------------------------------------*/

    public static function with(string|array $relations): static
    {
        $instance = static::query();
        $instance->with = is_array($relations) ? $relations : func_get_args();
        return $instance;
    }

    public function load(string|array $relations): self
    {
        $this->with = is_array($relations) ? $relations : func_get_args();
        $this->eagerLoad([$this]);
        return $this;
    }

    protected function belongsTo(string $related, string $foreignKey, string $ownerKey = 'id'): ?dbObject
    {
        $foreignValue = $this->attributes[$foreignKey] ?? null;
        return $foreignValue !== null ? $related::find($foreignValue) : null;
    }

    protected function hasMany(string $related, string $foreignKey, string $localKey = 'id'): Collection
    {
        $localValue = $this->getKey();
        if ($localValue === null) {
            return new Collection();
        }

        $relatedInstance = new $related();
        $rows = $relatedInstance->db->table($relatedInstance->table)
            ->where($foreignKey, $localValue)
            ->get();

        $models = array_map(static fn(array $row) => new $related($row), $rows);
        return new Collection($models);
    }

    protected function hasOne(string $related, string $foreignKey, string $localKey = 'id'): ?dbObject
    {
        return $this->hasMany($related, $foreignKey, $localKey)->first();
    }

    protected function eagerLoad(array $models): void
    {
        if ($models === [] || $this->with === []) {
            return;
        }

        foreach ($this->with as $relation) {
            if (!method_exists($this, $relation)) {
                continue;
            }
            foreach ($models as $model) {
                $model->relations[$relation] = $model->$relation();
            }
        }
    }

    /* ------------------------------------------------------------------
     * Yardımcılar
     * ----------------------------------------------------------------*/

    public function refresh(): self
    {
        if (!$this->exists) {
            return $this;
        }
        $fresh = static::find($this->getKey());
        if ($fresh) {
            $this->attributes = $fresh->attributes;
            $this->original   = $fresh->original;
            $this->relations  = [];
        }
        return $this;
    }

    public function fresh(array $with = []): ?static
    {
        if (!$this->exists) {
            return null;
        }
        $query = static::query();
        if ($with !== []) {
            $query->with = $with;
        }
        return $query->find($this->getKey());
    }

    public function replicate(array $except = []): static
    {
        $attributes = $this->attributes;
        unset($attributes[$this->primaryKey], $attributes['created_at'], $attributes['updated_at']);
        foreach ($except as $key) {
            unset($attributes[$key]);
        }
        $clone = new static();
        $clone->forceFill($attributes);
        return $clone;
    }

    public function isDirty(?string $key = null): bool
    {
        $dirty = $this->getDirty();
        return $key === null ? $dirty !== [] : array_key_exists($key, $dirty);
    }

    public function wasChanged(?string $key = null): bool
    {
        return $this->isDirty($key);
    }

    public function getOriginal(?string $key = null): mixed
    {
        return $key === null ? $this->original : ($this->original[$key] ?? null);
    }

    public static function transaction(callable $callback): mixed
    {
        $db = PdoDb::getInstance();
        $db->beginTransaction();
        try {
            $result = $callback();
            $db->commit();
            return $result;
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }

    private function getDirty(): array
    {
        $dirty = [];
        foreach ($this->attributes as $k => $v) {
            if (!array_key_exists($k, $this->original) || $this->original[$k] !== $v) {
                $dirty[$k] = $v;
            }
        }
        return $dirty;
    }

    private function syncOriginal(): void
    {
        $this->original = $this->attributes;
    }

    public function getKey(): mixed
    {
        return $this->attributes[$this->primaryKey] ?? null;
    }

    public function toArray(): array
    {
        $data = $this->attributes;
        foreach ($this->hidden as $h) {
            unset($data[$h]);
        }

        foreach ($this->relations as $key => $value) {
            if ($value instanceof dbObject) {
                $data[$key] = $value->toArray();
            } elseif ($value instanceof Collection) {
                $data[$key] = $value->toArray();
            } elseif (is_array($value)) {
                $data[$key] = array_map(
                    static fn($item) => $item instanceof dbObject ? $item->toArray() : $item,
                    $value
                );
            } else {
                $data[$key] = $value;
            }
        }
        return $data;
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /* ------------------------------------------------------------------
     * Olay Sistemi
     * ----------------------------------------------------------------*/

    /** @var array<string, list<callable>> */
    protected static array $globalListeners = [];

    public static function listen(string $event, callable $callback): void
    {
        self::$globalListeners[$event][] = $callback;
    }

    protected function fire(string $event): bool
    {
        $halt = false;
        if (isset(self::$globalListeners[$event])) {
            foreach (self::$globalListeners[$event] as $cb) {
                if ($cb($this) === false) {
                    $halt = true;
                }
            }
        }
        $method = 'on' . ucfirst($event);
        if (method_exists($this, $method) && $this->$method() === false) {
            $halt = true;
        }
        return !$halt;
    }
}
