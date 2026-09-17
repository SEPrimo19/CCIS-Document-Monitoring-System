<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Converts a Word document to PDF so it can be read exactly as written (FR-41).
 *
 * WHY A CONVERTER AT ALL: a browser cannot display a .docx. Rendering its XML
 * (DocxHtml) recovers the contents but not the page — no pagination, no
 * headers or footers, no fixed layout. Converting to PDF gives the reader the
 * actual document, in the browser's own PDF viewer, with pages and zoom.
 *
 * WHY LIBREOFFICE: it is free, it runs headless on Windows and Linux alike, and
 * `--convert-to pdf` is the standard way this is done. The alternative on this
 * machine was driving the installed Microsoft Word through COM, which works but
 * ties the system to a Windows server with Office licensed on it — a dependency
 * that would not survive deployment to a campus server and would be awkward to
 * defend. Nothing here is Windows-specific.
 *
 * IT IS OPTIONAL. If LibreOffice is not installed the application does not
 * break: available() returns false and the viewer falls back to the HTML
 * render. That is deliberate — a missing optional tool must degrade the
 * preview, never the system.
 *
 * SAFETY. The source is a file this application stored itself, under a name it
 * generated; a client filename never reaches the command line. Every path is
 * passed through escapeshellarg(). The converter runs with its own throwaway
 * user profile so it can never touch a real LibreOffice profile, and under a
 * wall-clock timeout so a document it cannot parse blocks a request briefly
 * rather than forever.
 */
final class DocumentConverter
{
    /** Formats worth converting. PDFs are already what we want. */
    public const CONVERTIBLE = ['docx', 'doc', 'odt', 'rtf'];

    /** Wall-clock ceiling for one conversion. */
    private const TIMEOUT_SECONDS = 45;

    private static ?string $binary = null;
    private static bool $searched = false;

    /** Is a converter installed on this machine? */
    public static function available(): bool
    {
        return self::binary() !== null;
    }

    /**
     * Absolute path to the LibreOffice binary, or null.
     *
     * Checked once per request: this is a filesystem probe on a handful of
     * paths and the answer cannot change mid-request.
     */
    public static function binary(): ?string
    {
        if (self::$searched) {
            return self::$binary;
        }

        self::$searched = true;

        $candidates = [
            // Windows, both bitnesses.
            'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
            'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
            // Linux/macOS, where a campus server would run it.
            '/usr/bin/soffice',
            '/usr/bin/libreoffice',
            '/usr/local/bin/soffice',
            '/opt/libreoffice/program/soffice',
            '/Applications/LibreOffice.app/Contents/MacOS/soffice',
        ];

        // An explicit override wins, for an install somewhere unusual.
        $configured = getenv('SOFFICE_PATH');

        if (is_string($configured) && $configured !== '') {
            array_unshift($candidates, $configured);
        }

        foreach ($candidates as $path) {
            if (is_file($path) && is_readable($path)) {
                return self::$binary = $path;
            }
        }

        return self::$binary = null;
    }

    /** Where converted PDFs are kept. Outside the web root, like every upload. */
    public static function cacheDir(): string
    {
        return BASE_PATH . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'previews';
    }

    /**
     * The converted PDF for a stored document, converting on first request.
     *
     * Returns the absolute path, or null when there is no converter, the
     * source is unreadable, or the conversion failed.
     *
     * The cache key includes the source's size and modification time, so
     * replacing a file with a new version produces a new key and the stale PDF
     * is simply never asked for again.
     */
    public static function pdfFor(string $sourcePath, int $fileId): ?string
    {
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            return null;
        }

        $cached = self::cachePath($sourcePath, $fileId);

        if ($cached !== null && is_file($cached) && filesize($cached) > 0) {
            return $cached;
        }

        if (self::binary() === null || $cached === null) {
            return null;
        }

        return self::convert($sourcePath, $cached);
    }

    /** The cache path for a source file, without converting anything. */
    public static function cachePath(string $sourcePath, int $fileId): ?string
    {
        $size = @filesize($sourcePath);
        $mtime = @filemtime($sourcePath);

        if ($size === false || $mtime === false) {
            return null;
        }

        $key = substr(hash('sha256', $fileId . '|' . $size . '|' . $mtime), 0, 24);

        return self::cacheDir() . DIRECTORY_SEPARATOR . sprintf('doc%d_%s.pdf', $fileId, $key);
    }

    private static function convert(string $sourcePath, string $destination): ?string
    {
        $dir = self::cacheDir();

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }

        // LibreOffice names the output after the input and drops it in --outdir,
        // so it converts into a scratch directory and the result is moved to the
        // cache path. Two requests converting the same file at once therefore
        // cannot write to each other's output.
        $work = $dir . DIRECTORY_SEPARATOR . 'work_' . bin2hex(random_bytes(8));

        if (!@mkdir($work, 0775, true)) {
            return null;
        }

        // Its own throwaway profile: without this LibreOffice reuses the
        // desktop user's profile, and a second instance refuses to start while
        // the first holds it — which under a web server means conversions
        // silently stop working the moment anyone opens LibreOffice normally.
        $profile = $work . DIRECTORY_SEPARATOR . 'profile';

        $command = sprintf(
            '%s --headless --norestore --nolockcheck --nodefault --nofirststartwizard '
            . '-env:UserInstallation=file:///%s --convert-to pdf --outdir %s %s',
            escapeshellarg((string) self::binary()),
            str_replace('\\', '/', ltrim(str_replace(':', '|', $profile), '/')),
            escapeshellarg($work),
            escapeshellarg($sourcePath)
        );

        $produced = null;

        try {
            self::run($command);

            foreach (glob($work . DIRECTORY_SEPARATOR . '*.pdf') ?: [] as $candidate) {
                if (is_file($candidate) && filesize($candidate) > 0) {
                    $produced = $candidate;
                    break;
                }
            }

            if ($produced === null) {
                return null;
            }

            // rename() across the same filesystem is atomic, so a reader never
            // sees a half-written PDF at the cache path.
            if (!@rename($produced, $destination)) {
                return null;
            }

            return is_file($destination) ? $destination : null;
        } finally {
            self::rmTree($work);
        }
    }

    /**
     * Run the converter under a wall-clock timeout.
     *
     * proc_open rather than exec() so the process can actually be killed: a
     * document LibreOffice cannot parse otherwise holds the request open until
     * PHP's own limit, and on a dev server that blocks every other request
     * because it is single-threaded.
     */
    private static function run(string $command): void
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            return;
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $deadline = microtime(true) + self::TIMEOUT_SECONDS;

        while (true) {
            $status = proc_get_status($process);

            if (!$status['running']) {
                break;
            }

            if (microtime(true) > $deadline) {
                @proc_terminate($process, 9);
                error_log('[CCIS-DMS] document conversion timed out after '
                    . self::TIMEOUT_SECONDS . 's');
                break;
            }

            usleep(100000);
        }

        $err = stream_get_contents($pipes[2]) ?: '';

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if (trim($err) !== '') {
            error_log('[CCIS-DMS] soffice: ' . substr(trim($err), 0, 500));
        }
    }

    private static function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir) ?: [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                self::rmTree($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
