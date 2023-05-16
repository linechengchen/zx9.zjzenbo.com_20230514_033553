<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    
    protected $commands = [
        Commands\MigrateJob::class,
        Commands\RefreshSystem::class
    ];

    
    protected function schedule(Schedule $schedule)
    {
            }

    
    protected function commands()
    {

    }
}
