<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Log;

final class LogRotator
{
    public function __construct(
        private readonly int $maxSize = 10_485_760,
        private readonly int $maxFiles = 5,
        private readonly bool $compress = true,
    ) {}

    public function rotateIfNeeded(string $logPath): void
    {
        if (!file_exists($logPath)) {
            return;
        }

        if (filesize($logPath) < $this->maxSize) {
            return;
        }

        $this->rotate($logPath);
    }

    private function rotate(string $logPath): void
    {
        $this->deleteOldest($logPath);

        for ($i = $this->maxFiles - 1; $i >= 1; $i--) {
            $source = $this->getBackupPath($logPath, $i);
            if (!file_exists($source)) {
                continue;
            }
            $target = $this->getBackupPath($logPath, $i + 1);
            @rename($source, $target);
        }

        $firstBackup = $this->getBackupPath($logPath, 1);
        if ($this->compress && function_exists('gzencode')) {
            $content = @file_get_contents($logPath);
            if ($content !== false) {
                $compressed = @gzencode($content);
                if ($compressed !== false) {
                    @file_put_contents($firstBackup, $compressed);
                    @file_put_contents($logPath, '');
                    return;
                }
            }
        }

        $uncompressedBackup = $logPath . '.' . 1;
        @rename($logPath, $uncompressedBackup);
        @file_put_contents($logPath, '');
    }

    private function getBackupPath(string $logPath, int $index): string
    {
        return $logPath . '.' . $index . ($this->compress ? '.gz' : '');
    }

    private function deleteOldest(string $logPath): void
    {
        $oldest = $this->getBackupPath($logPath, $this->maxFiles + 1);
        if (file_exists($oldest)) {
            @unlink($oldest);
        }
    }
}
