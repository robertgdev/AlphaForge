<?php

namespace App\AlphaForge\Console\Concerns;

use Symfony\Component\Console\Helper\ProgressBar;

trait HasProgressBar
{
    protected ?ProgressBar $progressBar = null;

    protected function startProgressBar(string $message = 'Processing...'): void
    {
        $this->progressBar = $this->output->createProgressBar(100);
        $this->progressBar->setFormat(' %current:3s%%/%max:3s%% [%bar%] %message%');
        $this->progressBar->setMessage($message);
        $this->progressBar->start();
        $this->progressBar->display();
    }

    protected function updateProgress(int $current, int $total, ?string $message = null): void
    {
        if ($this->progressBar === null) {
            return;
        }

        $percentComplete = $total > 0 ? (int) round(($current / $total) * 100) : 0;
        $percentComplete = max(0, min(100, $percentComplete));

        $this->progressBar->setProgress($percentComplete);

        if ($message !== null) {
            $this->progressBar->setMessage($message);
        } else {
            $this->progressBar->setMessage("Processing: {$current}/{$total} records");
        }

        $this->progressBar->display();
    }

    protected function finishProgressBar(): void
    {
        if ($this->progressBar !== null) {
            $this->progressBar->finish();
            $this->newLine(2);
        }
    }

    protected function finishProgressBarOnError(): void
    {
        if ($this->progressBar !== null) {
            $this->progressBar->finish();
            $this->newLine(2);
        }
    }
}
