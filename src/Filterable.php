<?php

namespace Mont4\Filter;

use Illuminate\Database\Eloquent\Builder;

/**
 * Trait Filterable
 *
 * @package Mont4\Filter
 *
 * @method static mixed filter()
 */
trait Filterable
{
	public function scopeFilter(Builder $query)
	{
		$filter = $this->getFilterProvider($query);

		return $filter->handle();
	}

    protected function getFilterProvider($query)
	{
		$filterClass = get_called_class() . 'Filter';

		$filterClass = str_replace('\\Models\\', '\\Filters\\', $filterClass);

		return new $filterClass($query);
	}
}
