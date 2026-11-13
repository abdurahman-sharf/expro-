<?php

namespace App\Libraries\EInvoicing;

/**
 * Where the signing key may live. The private key is never kept in the repository, in the web root or in
 * the database: the settings name a file outside the application directory, read when a document is signed,
 * and both the settings and the signer refuse a path that resolves to anywhere inside it, through a link or
 * a parent segment as much as by its plain name.
 */
final class KeyPath
{
    /**
     * @param list<string>|null $protected The directories of the application, the project root, the code, the web root and the writable directory when none is given.
     *
     * @throws EInvoiceException
     */
    public static function check(string $path, ?array $protected = null): string
    {
        if ($path === '' || str_contains($path, "\0") || preg_match('#^(/|[A-Za-z]:[\\\\/])#', $path) !== 1) {
            throw new EInvoiceException(EInvoiceError::KeyPathUnsafe, 'The path of a key must be absolute.', ['field' => $path]);
        }

        $resolved = self::resolve($path);

        foreach ($protected ?? self::applicationDirectories() as $directory) {
            $root = realpath($directory);

            if ($root !== false && ($resolved === $root || str_starts_with($resolved, rtrim($root, '/\\') . DIRECTORY_SEPARATOR))) {
                throw new EInvoiceException(EInvoiceError::KeyPathUnsafe, 'The path of a key is inside the application directory.', ['field' => $path]);
            }
        }

        return $resolved;
    }

    /**
     * @return list<string>
     */
    private static function applicationDirectories(): array
    {
        return array_values(array_unique([ROOTPATH, APPPATH, FCPATH, WRITEPATH]));
    }

    /**
     * The real path of a file that may not exist yet: the real path of the nearest directory that does,
     * and the segments after it, none of which may climb back up.
     */
    private static function resolve(string $path): string
    {
        $real = realpath($path);

        if ($real !== false) {
            return $real;
        }

        $segments = [];
        $current  = $path;

        while ($current !== dirname($current) && realpath($current) === false) {
            array_unshift($segments, basename($current));
            $current = dirname($current);
        }

        if (in_array('..', $segments, true) || in_array('.', $segments, true)) {
            throw new EInvoiceException(EInvoiceError::KeyPathUnsafe, 'The path of a key must not climb out of a directory that does not exist.', ['field' => $path]);
        }

        $base = realpath($current);

        return rtrim($base === false ? $current : $base, '/\\') . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
    }
}
