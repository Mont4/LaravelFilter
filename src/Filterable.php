<?php

namespace Mont4\LaravelFilter;

use Illuminate\Database\Eloquent\Builder;

/**
 * Trait Filterable
 *
 * @package Mont4\LaravelFilter
 *
 * @method static setFilterResource($resource = NULL)
 * @method mixed filter()
 */
trait Filterable
{
    protected $filterResource;

    public function scopeSetFilterResource($resource = NULL)
    {
        $this->filterResource = $resource;
    }

    public function scopeFilter(Builder $query)
    {
        /** @var Filter $filter */
        $filter = $this->getFilterProvider($query);

        if ($this->filterResource)
            $filter->setResourceCollection($this->filterResource);

        return $filter->handle();
    }

    protected function getFilterProvider($query)
    {
        $filterClass = get_called_class() . 'Filter';

        $filterClass = str_replace('\\Models\\', '\\Filters\\', $filterClass);

        return new $filterClass($query);
    }
}
