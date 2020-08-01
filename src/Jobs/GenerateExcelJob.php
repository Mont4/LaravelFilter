<?php

namespace Mont4\LaravelFilter\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mont4\LaravelFilter\Filter;
use Box\Spout\Common\Type;
use Box\Spout\Writer\WriterFactory;

class GenerateExcelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;


    private $query;
    private $resourceFilter;
    private $excelIgnoreColumns;
    private $excelPrefixFileName;

    private $userId;

    /**
     * Create a new job instance.
     *
     */
    public function __construct($query, $resourceFilter, $excelIgnoreColumns, $excelPrefixFileName, $userId)
    {
        $this->query               = \EloquentSerialize::serialize($query);
        $this->resourceFilter      = $resourceFilter;
        $this->excelIgnoreColumns  = $excelIgnoreColumns;
        $this->excelPrefixFileName = $excelPrefixFileName;

        $this->userId = $userId;
    }

    /**
     * Execute the job.
     *
     * @return string
     */
    public function handle()
    {
        auth()->shouldUse('place_owner');
        auth()->loginUsingId($this->userId);

        if (!file_exists(base_path("storage/app/public/order/"))) {
            mkdir(base_path("storage/app/public/order/"));
        }

        $filename     = $this->excelPrefixFileName . date("Y-m-d") . "-" . Str::random(10) . '.xlsx';
        $filenamePath = base_path("storage/app/public/order/" . $filename);

        $writer = WriterFactory::create(Type::XLSX);
        $writer->setShouldUseInlineStrings(true)
            ->setTempFolder(sys_get_temp_dir())
            ->openToFile($filenamePath);

        // ------------------------------------ header ------------------------------------
        $query = \EloquentSerialize::unserialize($this->query);
        $rows  = $query->limit(1)->get();
        $rows = $this->getResourceCollection($rows)->jsonSerialize();

        $header = [];
        foreach ($rows as $row) {
            foreach ($row as $key => $value) {
                if (is_array($value) || is_object($value))
                    continue;

                if (in_array($key, $this->excelIgnoreColumns))
                    continue;

                $header[] = trans("validation.attributes.{$key}");
            }
        }
        $writer->addRow($header);

        // ------------------------------------ Rows ------------------------------------
        $query->chunk(2000, function ($rows) use ($writer) {
            $rows = $this->getResourceCollection($rows)->jsonSerialize();

            $sheetData = [];
            foreach ($rows as $row) {
                $datum = [];
                foreach ($row as $key => $value) {
                    if (is_array($value) || is_object($value))
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

        return $filenamePath;
    }

    public function getResourceCollection($rows)
    {
        if ($this->resourceFilter) {
            return $this->resourceFilter::collection($rows);
        }

        return false;
    }
}
