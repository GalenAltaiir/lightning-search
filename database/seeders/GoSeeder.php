<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Symfony\Component\Process\Process;

class GoSeeder extends Seeder
{
    public function run(): void
    {
        $path = base_path('GoScripts/Seeder/go-seeder');
        $count = 1000000; // or pass from env/config

        $process = new Process([$path, (string) $count]);
        $process->setTimeout(null);
        $process->setIdleTimeout(null);

        $process->run(function ($type, $buffer) {
            if ($type === Process::OUT) {
                // Write stdout buffer live to artisan console
                $this->command->getOutput()->write($buffer);
            } else {
                $this->command->error($buffer);
            }
        });

        if (!$process->isSuccessful()) {
            $this->command->error("Go seeder failed.");
        } else {
            $this->command->info("Go seeder finished successfully.");
        }
    }
}
