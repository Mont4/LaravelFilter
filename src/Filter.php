<?php

namespace Mont4\LaravelFilter;

use Box\Spout\Common\Type;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Shared\File;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Rap2hpoutre\FastExcel\FastExcel;
use Box\Spout\Writer\WriterFactory;

abstract class Filter
{

    const METHOD_LIKE  = 'like';
    const METHOD_EQUAL = 'equal';

    protected $resourceFilter = NULL;

    protected $rtlSheet = false;

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


    public function __construct(QueryBuilder $query)
    {
        $this->request = app('request');
        $this->query   = $query;
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

    private function normalizeInput()
    {
        $fields = $this->request->input();

        $data = [
            'method' => 'paginate',
            'page'   => 1,
            'limit'  => 15,
            'order'  => [],
            'filter' => [],
        ];
        foreach ($fields as $key => $value) {
            $fieldType = 'string';
            if (in_array($key, $this->ignoreColumns))
                continue;
            if ($key == 'filter_method_request') {
                $data['method'] = $value;
                continue;
            }
            if ($key == 'page')
                continue;
            if ($key == 'limit') {
                $data['limit'] = $value;
                continue;
            }

            // ------------------------------------ Order ------------------------------------
            if ($key == 'filter_order') {
                $value = json_decode($value, true);
                if (filled($value)) {
                    $data['order'] = [
                        'field'     => $value['column'],
                        'direction' => $value['direction'] == 'ascending' ? 'asc' : 'desc',
                    ];
                }

                continue;
            }

            // ------------------------------------ Filter ------------------------------------
            if (!$value) {
                continue;
            }

            $method = self::METHOD_EQUAL;
            if (Str::contains($value, '~')) {
                $value  = str_replace('~', '', $value);
                $method = self::METHOD_LIKE;
            }

            $datum[] = [
                'field'  => $key,
                'type'   => $fieldType,
                'value'  => $value,
                'method' => $method,
            ];

            $data['filter'] = $datum;
        }

        return $data;
    }

    public function handle()
    {
        $data = $this->normalizeInput();

        if (filled($data['order'])) {
            $this->query->orderBy($data['order']['field'], $data['order']['direction']);
        } else {
            $this->query->orderBy('created_at', 'desc');
        }

        foreach ($data['filter'] as $datum) {
            if (method_exists($this, $datum['field'])) {
                $method = $datum['field'];
                $this->$method($datum['value']);
            } else if ($datum['method'] == self::METHOD_LIKE) {
                $this->query->where($datum['field'], 'like', "%{$datum['value']}%");
            } else if ($datum['method'] == self::METHOD_EQUAL) {
                $this->query->where($datum['field'], $datum['value']);
            }
        }

        return $this->{$data['method']}($data);
    }

    public function paginate($data)
    {
        $paginate = $this->query->paginate($data['limit']);

        if ($collection = $this->getResourceCollection($paginate)) {
            return $collection;
        }

        return $paginate;
    }

    public function excel($data)
    {
        ini_set('max_execution_time', 120);

        if (!$this->resourceFilter) {
            throw new \Exception("resource not found");
        }

        $pFilename = @tempnam(realpath(sys_get_temp_dir()), 'phpxltmp') . '.xlsx';

        $writer = WriterFactory::create(Type::XLSX);
        $writer->setShouldUseInlineStrings(true)
            ->setTempFolder(sys_get_temp_dir())
            ->openToFile($pFilename);

        // ------------------------------------ header ------------------------------------
        $header = [];
        foreach ($this->excelHeaders as $excelHeader) {
            $header[] = $excelHeader;
        }
        $writer->addRow($header);

        // ------------------------------------ Rows ------------------------------------
        $this->query->chunk(2000, function ($rows) use ($writer) {
            $rows = $this->getResourceCollection($rows)->jsonSerialize();

            $sheetData = [];
            foreach ($rows as $row) {
                $datum = [];
                foreach ($row as $key => $value) {
                    if (is_array($value))
                        continue;

                    if (in_array($key, $this->excelIgnoreColumns))
                        continue;

                    $datum[] = $value;
                }

                $sheetData[] = $datum;
            }

            $writer->addRows($sheetData);
        });

        $writer->close();

        $filename = $this->excelPrefixFileName . date("Y-m-d H:i:s") . '.xlsx';

        return response()->download($pFilename, $filename);
    }

    public function getResourceCollection($rows)
    {
        if ($this->resourceFilter) {
            return $this->resourceFilter::collection($rows);
        }

        return false;
    }
}
