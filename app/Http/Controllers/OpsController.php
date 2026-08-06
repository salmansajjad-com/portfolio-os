<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class OpsController extends Controller
{
    /**
     * Allowed ops actions only. Plain-text Artisan output.
     *
     * @var list<string>
     */
    /**
     * Shortest token the route will accept before it refuses to run at all.
     */
    private const MIN_TOKEN_LENGTH = 32;

    private const ACTIONS = [
        'migrate',
        'storage-link',
        'cache-clear',
        'optimize',
        'livewire-assets',
    ];

    public function __invoke(Request $request, string $action): Response
    {
        $configured = (string) config('ops.token', '');

        if ($configured === '') {
            throw new NotFoundHttpException;
        }

        // This endpoint runs migrations and writes to the filesystem, so a short
        // token is not a smaller risk than no token — it is a guessable one.
        // Refusing to start is safer than pretending the guard holds.
        if (strlen($configured) < self::MIN_TOKEN_LENGTH) {
            throw new NotFoundHttpException;
        }

        // Prefer the header: a token in the query string ends up in access logs,
        // browser history and Referer headers. The query parameter stays
        // supported because some hosts' file managers cannot send headers.
        $provided = (string) ($request->bearerToken() ?: $request->query('token', ''));

        if ($provided === '' || ! hash_equals($configured, $provided)) {
            throw new AccessDeniedHttpException('Invalid ops token.');
        }

        if (! in_array($action, self::ACTIONS, true)) {
            throw new NotFoundHttpException;
        }

        $output = match ($action) {
            'migrate' => $this->runMigrate(),
            'storage-link' => $this->runStorageLink(),
            'cache-clear' => $this->runCacheClear(),
            'optimize' => $this->runOptimize(),
            'livewire-assets' => $this->runLivewireAssets(),
        };

        return response($output, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function runMigrate(): string
    {
        $exit = Artisan::call('migrate', ['--force' => true]);

        return trim(Artisan::output())."\n[exit={$exit}]\n";
    }

    private function runStorageLink(): string
    {
        $target = storage_path('app/public');
        $link = public_path('storage');
        $lines = [];

        File::ensureDirectoryExists($target);

        if (is_link($link) || (file_exists($link) && ! is_dir($link))) {
            if (is_link($link) && realpath($link) === realpath($target)) {
                return "public/storage already linked to storage/app/public.\n";
            }
        }

        if (is_link($link)) {
            @unlink($link);
            $lines[] = 'Removed stale public/storage symlink.';
        }

        try {
            $exit = Artisan::call('storage:link');
            $lines[] = trim(Artisan::output());
            $lines[] = "[storage:link exit={$exit}]";

            if (is_link($link) || file_exists($link)) {
                $lines[] = 'Storage link OK.';

                return implode("\n", $lines)."\n";
            }
        } catch (Throwable $e) {
            $lines[] = 'storage:link failed: '.$e->getMessage();
        }

        // Shared hosting often blocks symlink(); copy tree instead.
        if (file_exists($link) && ! is_dir($link)) {
            @unlink($link);
        }

        File::ensureDirectoryExists($link);
        File::copyDirectory($target, $link);
        $lines[] = 'Symlink unavailable or incomplete; copied storage/app/public → public/storage.';
        $lines[] = 'Re-run this action after new public media uploads, or upload files under both paths.';

        return implode("\n", $lines)."\n";
    }

    private function runCacheClear(): string
    {
        $parts = [];

        foreach (['config:clear', 'route:clear', 'view:clear', 'cache:clear'] as $cmd) {
            $exit = Artisan::call($cmd);
            $parts[] = trim(Artisan::output())." [{$cmd} exit={$exit}]";
        }

        return implode("\n", $parts)."\n";
    }

    private function runOptimize(): string
    {
        $exit = Artisan::call('optimize');

        return trim(Artisan::output())."\n[exit={$exit}]\n";
    }

    /**
     * Ensure Livewire dist JS exists under vendor (shared-host FTP often omits it).
     * Downloads from the installed package tag on GitHub when missing.
     */
    private function runLivewireAssets(): string
    {
        $distDir = base_path('vendor/livewire/livewire/dist');
        $lines = [];
        File::ensureDirectoryExists($distDir);

        $files = [
            'livewire.min.js',
            'livewire.min.js.map',
            'livewire.js',
            'manifest.json',
        ];

        $version = $this->installedLivewireVersion();
        $tag = $version !== '' ? $version : 'v4.3.5';
        $lines[] = "Livewire package version: {$tag}";
        $lines[] = "dist dir: {$distDir}";

        foreach ($files as $file) {
            $target = $distDir.DIRECTORY_SEPARATOR.$file;
            if (is_file($target) && filesize($target) > 0) {
                $lines[] = "OK existing {$file} (".filesize($target).' bytes)';

                continue;
            }

            $url = "https://raw.githubusercontent.com/livewire/livewire/{$tag}/dist/{$file}";
            $lines[] = "Fetching {$url}";

            try {
                $ctx = stream_context_create([
                    'http' => [
                        'timeout' => 60,
                        'header' => "User-Agent: portfolio-os-ops-livewire-assets\r\n",
                    ],
                    'ssl' => [
                        'verify_peer' => true,
                        'verify_peer_name' => true,
                    ],
                ]);
                $body = @file_get_contents($url, false, $ctx);
                if ($body === false || $body === '') {
                    $lines[] = "FAIL download empty/false for {$file}";

                    continue;
                }
                $written = File::put($target, $body);
                $lines[] = "WROTE {$file} ({$written} bytes)";
            } catch (Throwable $e) {
                $lines[] = "FAIL {$file}: ".$e->getMessage();
            }
        }

        $min = $distDir.DIRECTORY_SEPARATOR.'livewire.min.js';
        $lines[] = 'livewire.min.js exists='.(is_file($min) ? 'yes' : 'no')
            .' size='.(is_file($min) ? (string) filesize($min) : '0');

        return implode("\n", $lines)."\n";
    }

    private function installedLivewireVersion(): string
    {
        $installed = base_path('vendor/composer/installed.json');
        if (! is_file($installed)) {
            return '';
        }

        try {
            $json = json_decode((string) file_get_contents($installed), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return '';
        }

        $packages = $json['packages'] ?? $json;
        if (! is_array($packages)) {
            return '';
        }

        foreach ($packages as $package) {
            if (! is_array($package)) {
                continue;
            }
            if (($package['name'] ?? '') === 'livewire/livewire') {
                return (string) ($package['pretty_version'] ?? $package['version'] ?? '');
            }
        }

        return '';
    }
}
