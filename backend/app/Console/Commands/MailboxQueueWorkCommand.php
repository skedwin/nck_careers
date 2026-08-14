<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class MailboxQueueWorkCommand extends Command
{
    protected $signature = 'mailbox:queue-work
        {--timeout=600 : Per-job timeout passed to queue:work}
        {--memory=512 : Restart worker when memory (MB) exceeded}
        {--max-time=1800 : Restart worker after N seconds (memory hygiene)}
        {--sleep=1 : Seconds to wait when no job is available}
        {--tries=5 : Default max attempts for jobs without their own tries}';

    protected $description = 'Run the mail queue worker and auto-restart if it exits (keeps attachment downloads going)';

    public function handle(): int
    {
        $timeout = max(120, (int) $this->option('timeout'));
        $memory = max(128, (int) $this->option('memory'));
        $maxTime = max(300, (int) $this->option('max-time'));
        $sleep = max(1, (int) $this->option('sleep'));
        $tries = max(1, (int) $this->option('tries'));

        $this->info('Durable queue worker started (auto-restarts on exit).');
        $this->line(sprintf(
            'queues=mail-sync,mail-import,default timeout=%ds memory=%dMB max-time=%ds',
            $timeout,
            $memory,
            $maxTime
        ));

        while (true) {
            $process = new Process([
                PHP_BINARY,
                'artisan',
                'queue:work',
                '--queue=mail-sync,mail-import,default',
                '--sleep='.$sleep,
                '--tries='.$tries,
                '--timeout='.$timeout,
                '--memory='.$memory,
                '--max-time='.$maxTime,
            ], base_path());

            $process->setTimeout(null);
            $process->run(function (string $type, string $buffer): void {
                $this->output->write($buffer);
            });

            $code = $process->getExitCode() ?? 1;
            $this->warn(sprintf(
                '[%s] queue:work exited with code %d — restarting in 2s…',
                now()->format('H:i:s'),
                $code
            ));

            sleep(2);
        }
    }
}
