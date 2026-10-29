<?php

namespace App\Console\Commands;

use App\Ingest\AdIngestor;
use App\Ingest\Cadence;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class IngestAds extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ads:ingest
        {--ticks=0 : Stop after this many cycles (0 = run until stopped)}
        {--interval=1000 : Milliseconds from the start of one cycle to the start of the next}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Simulate live ingest: every cycle, discover 1–5 new ads and stop 1–2 running ones';

    private bool $running = true;

    /**
     * Execute the console command.
     */
    public function handle(AdIngestor $ingestor): int
    {
        $maxTicks = filter_var($this->option('ticks'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $intervalMs = filter_var($this->option('interval'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($maxTicks === false || $intervalMs === false) {
            $this->error('--ticks and --interval must be whole numbers of 0 or more.');

            return self::INVALID;
        }

        // Finish the current cycle, then exit cleanly on Ctrl-C or `docker stop`.
        $this->trap([SIGINT, SIGTERM], fn () => $this->running = false);

        $this->info('Ingesting'.($maxTicks > 0 ? " for {$maxTicks} cycles" : ' until stopped (Ctrl-C)').'...');

        for ($done = 0; $this->running && ($maxTicks === 0 || $done < $maxTicks); $done++) {
            $startedAt = hrtime(true);
            $tick = $ingestor->tick(CarbonImmutable::now('UTC'));

            $this->line(sprintf(
                '[%s] +%d new (ids %s)  -%d stopped (ids %s)',
                CarbonImmutable::now('UTC')->format('H:i:s'),
                count($tick->insertedIds),
                implode(',', $tick->insertedIds),
                count($tick->deactivatedIds),
                implode(',', $tick->deactivatedIds) ?: '-',
            ));

            $isLastTick = $maxTicks > 0 && $done + 1 >= $maxTicks;
            $sleepUs = Cadence::sleepMicroseconds($intervalMs, hrtime(true) - $startedAt);
            if ($sleepUs > 0 && ! $isLastTick) {
                usleep($sleepUs); // a SIGINT/SIGTERM cuts this short; the loop then exits
            }
        }

        $this->info("Stopped after {$done} cycles.");

        return self::SUCCESS;
    }
}
