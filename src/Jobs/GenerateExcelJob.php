<?php

namespace Mont4\LaravelFilter\Jobs;

use App\Mail\OrderExcelDownloadMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
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

    private $userGuard;
    private $userId;
    private $mailFlag;

    /**
     * Create a new job instance.
     *
     */
    public function __construct($query, $resourceFilter, $excelIgnoreColumns, $excelPrefixFileName, $userGuard, $userId, $mailFlag = false)
    {
        $this->query               = \EloquentSerialize::serialize($query);
        $this->resourceFilter      = $resourceFilter;
        $this->excelIgnoreColumns  = $excelIgnoreColumns;
        $this->excelPrefixFileName = $excelPrefixFileName;

        $this->userGuard = $userGuard;
        $this->userId    = $userId;
        $this->mailFlag  = $mailFlag;
    }

    /**
     * Execute the job.
     *
     * @return string
     */
    public function handle()
    {
        auth()->shouldUse($this->userGuard);
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
        $rows  = $this->getResourceCollection($rows)->jsonSerialize();

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

        if ($this->mailFlag) {
            $datum = explode('/', $filenamePath);
            $data  = [
                'file' => last($datum),
            ];

            Mail::to(auth()->user()->email)->send(new OrderExcelDownloadMail($data));
        }

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
