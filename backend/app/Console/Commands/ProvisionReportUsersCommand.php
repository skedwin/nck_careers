<?php

namespace App\Console\Commands;

use Database\Seeders\ReportPositionUsersSeeder;
use Illuminate\Console\Command;

class ProvisionReportUsersCommand extends Command
{
    protected $signature = 'users:provision-report-users';

    protected $description = 'Create/update scoped report users (fsduser, commsuser, nusesuser)';

    public function handle(): int
    {
        $created = (new ReportPositionUsersSeeder)->run();

        $this->info('Report users provisioned (save these passwords — they are not stored in plain text):');
        $this->newLine();

        $rows = [];
        foreach ($created as $row) {
            $rows[] = [
                $row['username'],
                $row['email'],
                $row['password'],
                implode(', ', $row['positions']),
            ];
        }

        $this->table(['Username', 'Email', 'Password', 'Positions'], $rows);

        return self::SUCCESS;
    }
}
