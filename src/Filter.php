<?php

namespace Mont4\LaravelFilter;

use Box\Spout\Common\Type;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Illuminate\Support\Str;
use Mont4\LaravelFilter\Jobs\GenerateExcelJob;
use Rap2hpoutre\FastExcel\FastExcel;
use Box\Spout\Writer\WriterFactory;

class Filter
{
    public const METHOD_LIKE               = 'like';
    public const METHOD_EQUAL              = 'equal';
    public const METHOD_NOT_EQUAL          = 'not-equal';
    public const METHOD_LESS_THAN_EQUAL    = 'less-than-equal';
    public const METHOD_GREATER_THAN_EQUAL = 'greater-than-equal';
    public const METHOD_INTERSECTION       = 'intersection';
    public const METHOD_UNION              = 'union';
    public const METHOD_ARRAY              = 'array';

    public const ARRAY_METHODS = [
        self::METHOD_ARRAY,
        self::METHOD_UNION,
        self::METHOD_INTERSECTION,
    ];

    /**
     * The name of the filter's corresponding model.
     *
     * @var string|null
     */
    protected $model;

    protected $resourceFilter = NULL;

    protected $availableColumns = [];
    protected $ignoreColumns    = [];

    protected $excelPrefixFileName   = '';
    protected $excelHeaders          = [];
    protected $excelAvailableColumns = [];
    protected $excelIgnoreColumns    = [];

    protected $availableSorts  = [];
    protected $availableFilter = [];

    /**
     * @var QueryBuilder
     */
    protected $query;
    /**
     * @var \Illuminate\Http\Request
     */
    protected $request;


    public function __construct()
    {
        $modelName = $this->modelName();
        $model     = new $modelName();

        $this->query   = $model->query();
        $this->request = app('request');
    }

    /**
     * @param $method
     * @param $args
     *
     * @return mixed
     */
    public function __call($method, $args)
    {
        $resp = call_user_func_array([$this->query, $method], $args);

        // Only return $this if query builder is returned
        // We don't want to make actions to the builder unreachable
        return $resp instanceof QueryBuilder ? $this : $resp;
    }

    /**
     * Get the name of the model that is generated by the factory.
     *
     * @return string
     */
    public function modelName() :string
    {
        $model = Str::of(get_class($this))
            ->replace('App\\Filters\\', 'App\\Models\\')
            ->replaceLast('Filter', '');

        return $this->model ?: $model;
    }

    public static function filter()
    {
        $filter = new static();

        return $filter->handle();
    }

    private function normalizeInput()
    {
        $fields = $this->request->input();


        $methods = [];
        // ------------------------------------ find Filter methods ------------------------------------
        foreach ($fields as $key => $value) {
            if (str_ends_with($key, '_method')) {
                $key           = str_replace('_method', '', $key);
                $methods[$key] = $value;
            }
        }

        $page         = 1;
        $limit        = 25;
        $filterMethod = 'Paginate';

        $filterOrderColumn    = NULL;
        $filterOrderDirection = 'desc';

        $filters = [];
        foreach ($fields as $key => $value) {
            if (!$value) {
                continue;
            }

            // ignore filter method
            if (str_ends_with($key, '_method')) {
                continue;
            }

            if (in_array($key, $this->ignoreColumns)) {
                continue;
            }

            // ------------------------------------ Page ------------------------------------
            if ($key == 'page') {
                $page = (int)$value;
                continue;
            }

            // ------------------------------------ Limit ------------------------------------
            if ($key == 'limit') {
                $limit = (int)$value;
                continue;
            }

            // ------------------------------------ Request method ------------------------------------
            if ($key == 'filter_method_request') {
                $filterMethod = $value;
                continue;
            }

            // ------------------------------------ Order ------------------------------------
            if ($key == 'filter_order_column') {
                $filterOrderColumn = $value;
                continue;
            }

            if ($key == 'filter_order_direction') {
                $filterOrderDirection = $value == 'ascending' ? 'asc' : 'desc';
                continue;
            }

            // ------------------------------------ Filter value ------------------------------------
            [$method, $value] = $this->getFilterMethod($key, $value, $methods);

            $filters[] = [
                'field'  => $key,
                'value'  => $value,
                'method' => $method,
            ];
        }

        return [
            'method'          => ucfirst($filterMethod),
            'page'            => $page,
            'limit'           => $limit,
            'order_column'    => $filterOrderColumn,
            'order_direction' => $filterOrderDirection,
            'filters'         => $filters,
        ];
    }

    public function handle()
    {
        $data = $this->normalizeInput();

        if ($data['order_column']) {
            $this->query->orderBy($data['order_column'], $data['order_direction']);
        }

        foreach ($data['filters'] as $filter) {
            if (method_exists($this, $filter['field'])) {
                $this->{$filter['field']}($filter['value'], $this->query);
                continue;
            }

            if ($filter['field'] == 'delete_status') {
                // ------------------------------------ Delete Status ------------------------------------
                if (!is_array($filter['value'])) {
                    $filter['value'] = [$filter['value']];
                }

                if (
                    in_array('deleted', $filter['value']) &&
                    in_array('not_deleted', $filter['value'])
                ) {
                    $this->query->withTrashed();
                } else if (in_array('deleted', $filter['value'])) {
                    $this->query->onlyTrashed();
                } else {
                    $this->query->withoutTrashed();
                }
                continue;
            }

            switch ($filter['method']) {
                case self::METHOD_LIKE:
                    $this->query->where($filter['field'], 'like', "%{$filter['value']}%");
                    break;
                case self::METHOD_EQUAL:
                    $this->query->where($filter['field'], $filter['value']);
                    break;
                case self::METHOD_NOT_EQUAL:
                    $this->query->where($filter['field'], '<>', $filter['value']);
                    break;
                case self::METHOD_LESS_THAN_EQUAL:
                    $this->query->where($filter['field'], '<=', $filter['value']);
                    break;
                case self::METHOD_GREATER_THAN_EQUAL:
                    $this->query->where($filter['field'], '>=', $filter['value']);
                    break;
                case self::METHOD_UNION:
                    $this->query->whereNotIn($filter['field'], $filter['value']);
                    break;
                case self::METHOD_INTERSECTION:
                case self::METHOD_ARRAY:
                    $this->query->whereIn($filter['field'], $filter['value']);
                    break;
            }
        }

        return $this->{"method{$data['method']}"}($data);
    }

    public function methodPaginate($data)
    {
        $paginate = $this->query->paginate($data['limit']);

        if ($collection = $this->getResourceCollection($paginate)) {
            return $collection;
        }

        return $paginate;
    }

    public function methodExcel($data)
    {
        if (!$this->resourceFilter) {
            throw new \Exception("resource not found");
        }

        if ($this->query->count() > 10000) {
            dispatch(new GenerateExcelJob($this->query, $this->resourceFilter, $this->excelIgnoreColumns, $this->excelPrefixFileName, auth()->user()->kind, auth()->id(), true));

            return [
                'success' => true,
                'message' => 'اکسل برای شما ایمیل خواهد شد.',
            ];
        }

        $filePath = dispatch_now(new GenerateExcelJob($this->query, $this->resourceFilter, $this->excelIgnoreColumns, $this->excelPrefixFileName, auth()->user()->kind, auth()->id()));

        $filename = $this->excelPrefixFileName . date("Y-m-d H:i:s") . '.xlsx';

        return response()->download($filePath, $filename);
    }

    public function getResourceCollection($rows)
    {
        if ($this->resourceFilter) {
            return $this->resourceFilter::collection($rows);
        }

        return false;
    }

    public function setResourceCollection($resource = NULL)
    {
        $this->resourceFilter = $resource;
    }

    /**
     * @param string $key
     * @param        $value
     * @param array  $methods
     *
     * @return array
     */
    private function getFilterMethod(string $key, $value, array $methods) :array
    {
        $method = self::METHOD_EQUAL;
        if (is_array($value)) {
            $method = self::METHOD_ARRAY;
        }

        if (array_key_exists($key, $methods)) {
            $method = $methods[$key];
        }

        if (in_array($method, self::ARRAY_METHODS) && !is_array($value)) {
            $value = [$value];
        }

        return [$method, $value];
    }
}
