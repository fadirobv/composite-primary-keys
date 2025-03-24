<?php

namespace MaksimM\CompositePrimaryKeys\Scopes;

use Illuminate\Database\Eloquent\Builder;
use MaksimM\CompositePrimaryKeys\Exceptions\MissingPrimaryKeyValueException;
use MaksimM\CompositePrimaryKeys\Http\Traits\NormalizedKeysParser;

class CompositeKeyScope
{
    use NormalizedKeysParser;

    private $key;
    private $ids;
    private $inverse;
    private $binary_columns;

    public function __construct($key, $ids, $inverse, $binary_columns = [])
    {
        $this->key = $key;
        $this->ids = $ids;
        $this->inverse = $inverse;
        $this->binary_columns = $binary_columns;
    }

    /**
     * @return mixed
     */
    public function getBinaryColumns()
    {
        return $this->binary_columns;
    }

    /**
     * Apply a query scope.
     *
     * @param \Illuminate\Database\Query\Builder|Builder $query
     *
     */
    public function apply($query)
    {
        $query->where(function ($query) {
            foreach ($this->ids as $compositeKey) {
                // try to parse normalized key
                if (!is_array($compositeKey)) {
                    $compositeKey = $this->parseNormalizedKey($compositeKey);
                } else {
                    if (method_exists($query->getModel(), 'hexBinaryColumns') && $query->getModel()->hexBinaryColumns()) {
                        foreach ($compositeKey as $key => $value) {
                            $compositeKey[$key] = in_array($key, $this->getBinaryColumns()) ? $this->recoverBinaryKey(
                                $key,
                                $value
                            ) : $value;
                        }
                    }
                }

                $queryWriter = function ($query) use ($compositeKey) {
                    /*
                     * @var \Illuminate\Database\Query\Builder $query
                     */
                    foreach ($this->key as $key) {
                        if (empty($compositeKey)) {
                            $query->where($query->getModel()->qualifyColumn($key), null);
                        } else {
                            if (!isset($compositeKey[$key])) {
                                throw new MissingPrimaryKeyValueException(
                                    $key,
                                    'Missing value for key '.$key.' in record '.json_encode($compositeKey)
                                );
                            }

                            if ($this->inverse) {
                                $query->orWhere($query->getModel()->qualifyColumn($key), '!=', $compositeKey[$key]);
                            } else {
                                $query->where($query->getModel()->qualifyColumn($key), $compositeKey[$key]);
                            }
                        }
                    }
                };

                if ($this->inverse) {
                    $query->where($queryWriter);
                } else {
                    $query->orWhere($queryWriter);
                }
            }
        });
    }
}
