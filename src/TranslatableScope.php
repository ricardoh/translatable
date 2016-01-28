<?php namespace Laraplus\Data;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Grammars\SqlServerGrammar;

class TranslatableScope implements Scope
{
    protected $table;

    protected $i18nTable;

    protected $locale;

    protected $fallback;

    protected $joinType = 'leftJoin';

    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $builder
     * @param  \Illuminate\Database\Eloquent\Model $model
     * @return void
     */
    public function apply(Builder $builder, Model $model)
    {
        $this->table = $model->getTable();
        $this->locale = $model->getLocale();
        $this->i18nTable = $model->getI18nTable();
        $this->fallback = $model->getFallbackLocale();

        $this->createJoin($builder, $model);
        $this->createWhere($builder, $model);
        $this->createSelect($builder, $model);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder $builder
     * @param  \Illuminate\Database\Eloquent\Model $model
     */
    protected function createJoin(Builder $builder, Model $model)
    {
        $joinType = $this->getJoinType($model);

        $clause = $this->getJoinClause($model, $this->locale, $this->i18nTable);
        $builder->$joinType($this->i18nTable, $clause);

        if($model->shouldFallback()) {
            $clause = $this->getJoinClause($model, $this->fallback, $this->i18nTable . '_fallback');
            $builder->$joinType("{$this->i18nTable} as {$this->i18nTable}_fallback", $clause);
        }
    }

    /**
     * @param \Illuminate\Database\Eloquent\Model $model
     * @return string
     */
    protected function getJoinType(Model $model)
    {
        $innerJoin = !$model->shouldFallback() && $model->getOnlyTranslated();

        return $innerJoin ? 'join' : 'leftJoin';
    }

    /**
     * @param \Illuminate\Database\Eloquent\Model $model
     * @param string $locale
     * @param string $alias
     * @return callable
     */
    protected function getJoinClause(Model $model, $locale, $alias)
    {
        return function (JoinClause $join) use ($model, $locale, $alias) {
            $primary = $model->getKeyName();
            $foreign = $model->getForeignKey();
            $langKey = $model->getLocaleKey();

            $join->on($alias . '.' . $foreign, '=', $this->table . '.' . $primary)
                ->where($alias . '.' . $langKey, '=', $locale);
        };
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $builder
     * @param \Illuminate\Database\Eloquent\Model $model
     */
    protected function createWhere(Builder $builder, Model $model)
    {
        if($model->getOnlyTranslated() && $model->shouldFallback()) {
            $key = $model->getForeignKey();
            $primary = "{$this->i18nTable}.{$key}";
            $fallback = "{$this->i18nTable}_fallback.{$key}";

            $ifNull = $builder->getQuery()->compileIfNull($primary, $fallback);

            $builder->whereRaw("$ifNull is not null");
        }
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $builder
     * @param \Illuminate\Database\Eloquent\Model $model
     */
    protected function createSelect(Builder $builder, Model $model)
    {
        if($builder->getQuery()->columns) {
            return;
        }

        $select = $this->formatColumns($builder, $model);

        $builder->select(array_merge([$this->table . '.*'], $select));
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $builder
     * @param \Illuminate\Database\Eloquent\Model $model
     * @return array
     */
    protected function formatColumns(Builder $builder, Model $model)
    {
        $map = function ($field) use ($builder, $model) {
            if (!$model->shouldFallback()) {
                return "{$this->i18nTable}.{$field}";
            }

            $primary = "{$this->i18nTable}.{$field}";
            $fallback = "{$this->i18nTable}_fallback.{$field}";
            $alias = $field;

            return new Expression($builder->getQuery()->compileIfNull($primary, $fallback, $alias));
        };

        return array_map($map, $model->translatableAttributes());
    }


    /**
     * @param Grammar $grammar
     * @return string
     */
    protected function getIfNull(Grammar $grammar)
    {
        return $grammar instanceof SqlServerGrammar ? 'isnull' : 'ifnull';
    }

    /**
     * Extend the builder.
     * @param Builder $builder
     */
    public function extend(Builder $builder)
    {
        $builder->macro('onlyTranslated', function (Builder $builder, $locale = null) {
            $builder->getModel()->setOnlyTranslated(true);

            if($locale) {
                $builder->getModel()->setLocale($locale);
            }

            return $builder;
        });

        $builder->macro('withUntranslated', function (Builder $builder) {
            $builder->getModel()->setOnlyTranslated(false);

            return $builder;
        });

        $builder->macro('withFallback', function (Builder $builder, $fallbackLocale = null) {
            $builder->getModel()->setWithFallback(true);

            if($fallbackLocale) {
                $builder->getModel()->setFallbackLocale($fallbackLocale);
            }

            return $builder;
        });

        $builder->macro('withoutFallback', function (Builder $builder) {
            $builder->getModel()->setWithFallback(false);

            return $builder;
        });

        $builder->macro('translate', function (Builder $builder, $locale) {
            $builder->getModel()->setLocale($locale);

            return $builder;
        });
    }
}