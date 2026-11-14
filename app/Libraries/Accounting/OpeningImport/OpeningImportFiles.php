<?php

namespace App\Libraries\Accounting\OpeningImport;

/**
 * Keeps the file that was uploaded for the preview until it is approved, under the fingerprint of its own text,
 * so that the approval reads the file that was previewed and a file that was changed cannot take its place. The
 * files of a week ago are removed whenever a new one is kept.
 */
final class OpeningImportFiles
{
    private const KEEP_SECONDS = 604800;

    public function __construct(private readonly ?string $directory = null)
    {
    }

    public function store(string $text): string
    {
        $token = hash('sha256', $text);
        $path  = $this->path($token);

        $this->purge();

        if (! is_file($path)) {
            file_put_contents($path, $text, LOCK_EX);
            chmod($path, 0640);
        } else {
            touch($path);
        }

        return $token;
    }

    public function read(string $token): ?string
    {
        if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            return null;
        }

        $path = $this->path($token);

        return is_file($path) ? (string) file_get_contents($path) : null;
    }

    public function forget(string $token): void
    {
        if (preg_match('/^[0-9a-f]{64}$/', $token) === 1 && is_file($this->path($token))) {
            unlink($this->path($token));
        }
    }

    private function path(string $token): string
    {
        $directory = $this->directory ?? WRITEPATH . 'uploads/opening_import';

        if (! is_dir($directory)) {
            mkdir($directory, 0750, true);
        }

        return rtrim($directory, '/') . '/' . $token . '.csv';
    }

    private function purge(): void
    {
        foreach (glob(dirname($this->path(str_repeat('0', 64))) . '/*.csv') ?: [] as $file) {
            if (filemtime($file) < time() - self::KEEP_SECONDS) {
                unlink($file);
            }
        }
    }
}
