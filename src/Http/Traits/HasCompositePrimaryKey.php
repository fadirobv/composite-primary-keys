<?php

namespace MaksimM\CompositePrimaryKeys\Http\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Concerns\AsPivot;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Arr;
use MaksimM\CompositePrimaryKeys\Eloquent\CompositeKeyQueryBuilder;
use MaksimM\CompositePrimaryKeys\Exceptions\MissingPrimaryKeyValueException;
use MaksimM\CompositePrimaryKeys\Exceptions\WrongKeyException;
use MaksimM\CompositePrimaryKeys\Scopes\CompositeKeyScope;

trait HasCompositePrimaryKey
{
    use NormalizedKeysParser;
    use PrimaryKeyInformation;
    use CompositeRelationships;
    use OptionalBinaryTransformation;

    /**
     * Automatically generate unique binary id.
     */
    public static function bootHasCompositePrimaryKey()
    {
        static::creating(function ($model) {
            foreach ($model->getRawKeyName() as $key) {
                if ((!isset($model->{$key}) || empty($model->{$key})) && in_array($key, $model->getBinaryColumns())) {
                    $v = uniqid(rand(), true);
                    $model->{$key} = $model->hexBinaryColumns() ? strtoupper(
                        md5($v)
                    ) : md5($v, true);
                }
            }
        });
    }

    /**
     * Destroy the models for the given IDs.
     *
     * @param array|int $ids
     *
     * @return int
     */
    public static function destroy($ids)
    {
        // We'll initialize a count here so we will return the total number of deletes
        // for the operation. The developers can then check this number as a boolean
        // type value or get this total count of records deleted for logging, etc.
        $count = 0;

        $ids = is_array($ids) ? $ids : func_get_args();

        foreach ((new static())->applyIds($ids)->get() as $model) {
            if ($model->delete()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Reload the current model instance with fresh attributes from the database.
     *
     * @return $this
     */
    public function refresh()
    {
        if (!$this->exists) {
            return $this;
        }

        $this->setRawAttributes(
            $this->setKeysForSelectQuery(
                $this->newQueryWithoutScopes()
            )->firstOrFail()->attributes
        );

        $this->load(collect($this->relations)->reject(function ($relation) {
            return $relation instanceof Pivot
                || (is_object($relation) && in_array(AsPivot::class, class_uses_recursive($relation), true));
        })->keys()->all());

        $this->syncOriginal();

        return $this;
    }

    /**
     * Set the keys for a select query.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function setKeysForSelectQuery($query)
    {
        foreach ($this->getRawKeyName() as $key) {
            $query->where($key, '=', $this->original[$key]);
        }

        return $query;
    }

    /**
     * Convert the object into something JSON serializable.
     *
     * @return mixed
     */
    public function jsonSerialize(): mixed
    {
        $attributes = $this->toArray();
        foreach ($attributes as $key => $value) {
            if (in_array($key, $this->getBinaryColumns())) {
                $attributes[$key] = strtoupper(bin2hex($value));
            }
        }

        // append virtual row id
        if (count($this->getRawKeyName()) > 1) {
            $attributes[$this->getNormalizedKeyName()] = $this->getNormalizedKey();
        }

        return $attributes;
    }

    /**
     * Get the primary key for the model.
     *
     * @return array
     */
    public function getRawKeyName()
    {
        return $this->hasCompositeIndex() ? $this->primaryKey : [$this->primaryKey];
    }

    /**
     * Get the value of the model's primary key.
     *
     * @return mixed
     */
    public function getRawKey()
    {
        $attributes = [];

        foreach ($this->getRawKeyName() as $key) {
            $attributes[$key] = $this->getAttribute($key);
        }

        return $attributes;
    }

    /**
     * Get the value indicating whether the IDs are incrementing.
     *
     * @return bool
     */
    public function getIncrementing()
    {
        return false;
    }

    /**
     * Get the primary key for the model.
     *
     * @return string
     */
    public function getKeyName()
    {
        return $this->getNormalizedKeyName();
    }

    /**
     * Get virtual string key, required for proper collection support.
     *
     * @return mixed
     */
    public function getKey()
    {
        return $this->getNormalizedKey();
    }

    /**
     * @param \Illuminate\Database\Query\Builder $query
     * @param array|string $ids
     * @param bool $inverse
     *
     * @throws MissingPrimaryKeyValueException
     * @throws WrongKeyException
     */
    public function scopeApplyIds($query, $ids, $inverse = false)
    {
        $keys = ($instance = new static())->getRawKeyName();

        if (!is_array($ids) || Arr::isAssoc($ids)) {
            $ids = [$ids];
        }

        if ($this->hasCompositeIndex()) {
            (new CompositeKeyScope($keys, $ids, $inverse, $this->getBinaryColumns()))->apply($query);
        } else {
            //remap hex ID to binary ID even if index is not composite
            if ($this->shouldProcessBinaryAttribute($keys[0])) {
                $ids = array_map(function ($hex) use ($keys) {
                    return $this->recoverBinaryKey($keys[0], $hex);
                }, $ids);
            }
            if ($inverse) {
                $query->whereNotIn($this->qualifyColumn($keys[0]), $ids);
            } else {
                $query->whereIn($this->qualifyColumn($keys[0]), $ids);
            }
        }
    }

    /**
     * Get a new query to restore one or more models by their queueable IDs.
     *
     * @param array|int $ids
     *
     * @return Builder
     * @throws MissingPrimaryKeyValueException
     *
     * @throws WrongKeyException
     */
    public function newQueryForRestoration($ids)
    {
        if (!is_array($ids)) {
            $ids = [$ids];
        }

        return $this->newQueryWithoutScopes()->applyIds(
            array_map(
                function ($normalizedKey) {
                    return $this->hasCompositeIndex() ? $this->parseNormalizedKey($normalizedKey) : $normalizedKey;
                },
                $ids
            )
        );
    }

    /**
     * @param \Illuminate\Database\Query\Builder $query
     *
     * @return Builder|static
     */
    public function newEloquentBuilder($query)
    {
        return new CompositeKeyQueryBuilder($query);
    }

    public function getBinaryColumns()
    {
        return $this->binaryColumns ?? [];
    }

    /**
     * Get the primary key value for a save query.
     *
     * @return mixed
     */
    protected function getKeyForSaveQuery()
    {
        $originalKeys = array_intersect_key($this->original, array_flip($this->getRawKeyName()));

        return array_merge($this->getRawKey(), $originalKeys);
    }

    /**
     * Set the keys for a save update query.
     *
     * @param Builder $query
     *
     * @return Builder
     * @throws MissingPrimaryKeyValueException
     *
     */
    protected function setKeysForSaveQuery($query)
    {
        foreach ($this->getRawKeyName() as $key) {
            if (isset($this->{$key})) {
                $query->where($key, '=', $this->getAttributeFromArray($key));
            } else {
                throw new MissingPrimaryKeyValueException($key, 'Missing value for key ' . $key);
            }
        }

        return $query;
    }

    /**
     * Run the increment or decrement method on the model.
     *
     * @param string $column
     * @param float|int $amount
     * @param array $extra
     * @param string $method
     *
     * @return int
     */
    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        $query = $this->newQueryWithoutRelationships();

        if (!$this->exists) {
            return $query->{$method}($column, $amount, $extra);
        }

        $this->{$column} = $this->isClassDeviable($column)
            ? $this->deviateClassCastableAttribute($method, $column, $amount)
            : $this->{$column} + ($method === 'increment' ? $amount : $amount * -1);

        $this->forceFill($extra);

        if ($this->fireModelEvent('updating') === false) {
            return false;
        }

        return tap($this->setKeysForSaveQuery($query)->{$method}($column, $amount, $extra), function () use ($column) {
            $this->syncChanges();

            $this->fireModelEvent('updated', false);

            $this->syncOriginalAttribute($column);
        });
    }

    /**
     * Retrieve the model for a bound value.
     *
     * @param mixed $value
     *
     * @return Model|null
     */
    public function resolveRouteBinding($value, $field = NULL)
    {
        if ($field === NULL && $this->hasCompositeIndex() && $this->getRouteKeyName() == $this->getKeyName()) {
            return $this->whereKey($value)->first();
        }

        return $this->where($field ?? $this->getRouteKeyName(), $value)->first();
    }
}
