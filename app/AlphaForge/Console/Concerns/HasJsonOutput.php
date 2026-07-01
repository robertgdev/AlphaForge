<?php

namespace App\AlphaForge\Console\Concerns;

use App\AlphaForge\Common\Util\MemoryHelper;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\ArrayInput;

use function Safe\file_put_contents;
use function Safe\json_encode;

trait HasJsonOutput
{
    protected function jsonEnabled(): bool
    {
        return (bool) $this->option('json');
    }

    protected function schemaEnabled(): bool
    {
        return $this->hasOption('schema') && (bool) $this->option('schema');
    }

    /**
     * If --schema was passed, output the parameter schema and return the exit code.
     * Call this at the top of every handle() method.
     *
     * @return int|null Returns the exit code if --schema was handled, null to continue normally.
     */
    protected function handleSchemaFlag(): ?int
    {
        if (! $this->schemaEnabled()) {
            return null;
        }

        $commandName = $this->getName();

        try {
            $schemaCmd = $this->getApplication()->find('alphaforge:schema');

            $exitCode = $schemaCmd->run(
                new ArrayInput(['name' => $commandName]),
                $this->output,
            );

            return $exitCode;
        } catch (\Throwable $e) {
            $this->line(json_encode([
                'command' => $commandName,
                'success' => false,
                'data' => null,
                'error' => 'Schema generation failed: '.$e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::FAILURE;
        }
    }

    /**
     * @return int|null Returns Command::FAILURE if conflict detected, null if OK.
     */
    protected function validateJsonFormatConflict(string $formatOption): ?int
    {
        if ($this->jsonEnabled() && $formatOption !== 'table') {
            return $this->outputJsonError('Cannot use --json and --format together. Use one or the other.');
        }

        return null;
    }

    protected function outputJsonError(string $message): int
    {
        if ($this->jsonEnabled()) {
            return $this->outputJson(false, null, $message);
        }

        $this->error($message);
        $this->debugMemory();

        return Command::FAILURE;
    }

    protected function outputJson(bool $success, mixed $data, ?string $error = null, ?string $outputPath = null): int
    {
        $payload = [
            'command' => $this->getName(),
            'success' => $success,
            'data' => $data,
            'error' => $error,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($outputPath) {
            $dir = dirname($outputPath);
            if ($dir !== '.' && ! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($outputPath, $json);
            if (! $this->jsonEnabled()) {
                $this->info("Output written to {$outputPath}");
            }
        } else {
            $this->line($json);
        }

        return $success ? Command::SUCCESS : Command::FAILURE;
    }

    protected function debugMemory(): void
    {
        if ($this->jsonEnabled()) {
            return;
        }

        if (! $this->hasOption('debug')) {
            return;
        }

        if ($this->option('debug')) {
            $peak = memory_get_peak_usage(true);
            $formatted = MemoryHelper::formatBytes($peak);
            $this->newLine();
            $this->line("<fg=gray>debug: peak memory {$formatted}</>");
        }
    }
}
