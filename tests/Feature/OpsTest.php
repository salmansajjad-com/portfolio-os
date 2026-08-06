<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/** Long enough to satisfy the controller's minimum-length guard. */
const OPS_TEST_TOKEN = 'correct-secret-token-long-enough-to-pass-the-guard';

beforeEach(function () {
    config(['ops.token' => null]);
});

it('returns not found for ops when token is empty', function () {
    config(['ops.token' => '']);
    config(['ops.token' => null]);

    $this->get('/_ops/cache-clear?token=anything')
        ->assertNotFound();

    $this->get('/_ops/migrate')
        ->assertNotFound();
});

it('rejects wrong ops token', function () {
    config(['ops.token' => OPS_TEST_TOKEN]);

    $this->get('/_ops/cache-clear?token=wrong-token')
        ->assertForbidden();

    $this->get('/_ops/cache-clear')
        ->assertForbidden();
});

it('refuses to run at all when the configured token is too short to be safe', function () {
    // A guessable token on an endpoint that runs migrations is worse than none,
    // so the route disappears rather than accepting it.
    config(['ops.token' => 'short']);

    $this->get('/_ops/cache-clear?token=short')->assertNotFound();
    $this->get('/_ops/migrate?token=short')->assertNotFound();
});

it('accepts the ops token as a bearer header so it stays out of access logs', function () {
    config(['ops.token' => OPS_TEST_TOKEN]);

    $this->withHeader('Authorization', 'Bearer '.OPS_TEST_TOKEN)
        ->get('/_ops/cache-clear')
        ->assertOk();
});

it('does not let the ops route rewrite Blade layouts', function () {
    config(['ops.token' => OPS_TEST_TOKEN]);

    $layout = resource_path('views/layouts/guest.blade.php');
    $before = file_get_contents($layout);

    $this->get('/_ops/cache-clear?token='.OPS_TEST_TOKEN)->assertOk();

    expect(file_get_contents($layout))->toBe($before);
});

it('rejects unknown ops actions even with a valid token', function () {
    config(['ops.token' => OPS_TEST_TOKEN]);

    $this->get('/_ops/destroy-all?token='.OPS_TEST_TOKEN.'')
        ->assertNotFound();
});

it('runs cache-clear with a valid token and returns plain text', function () {
    config(['ops.token' => OPS_TEST_TOKEN]);

    $response = $this->get('/_ops/cache-clear?token='.OPS_TEST_TOKEN.'');

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/plain');
    $response->assertSee('exit=', false);
});

it('runs migrate --force with a valid token in testing', function () {
    config(['ops.token' => OPS_TEST_TOKEN]);

    // Tables already migrated by RefreshDatabase; artisan migrate should be a no-op success.
    $response = $this->get('/_ops/migrate?token='.OPS_TEST_TOKEN.'');

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/plain');
    $response->assertSee('exit=0', false);
    expect(Schema::hasTable('users'))->toBeTrue();
});

it('runs storage-link ops and ensures public storage path exists', function () {
    config(['ops.token' => OPS_TEST_TOKEN]);

    $response = $this->get('/_ops/storage-link?token='.OPS_TEST_TOKEN.'');

    $response->assertOk();
    expect(
        is_link(public_path('storage'))
        || is_dir(public_path('storage'))
    )->toBeTrue();
});

it('serves public storage files via media fallback route', function () {
    $dir = storage_path('app/public/ops-test');
    File::ensureDirectoryExists($dir);
    File::put($dir.'/hello.txt', 'public-disk-fallback');

    $response = $this->get('/media/public/ops-test/hello.txt');
    $response->assertOk();
    expect($response->streamedContent())->toBe('public-disk-fallback');

    $this->get('/media/public/../private/secret.txt')
        ->assertNotFound();
});

it('restores missing Livewire dist assets via livewire-assets ops', function () {
    config(['ops.token' => OPS_TEST_TOKEN]);

    $dist = base_path('vendor/livewire/livewire/dist');
    $min = $dist.'/livewire.min.js';
    expect(is_file($min))->toBeTrue();

    // Simulate missing dist by renaming; ops should re-fetch from GitHub.
    $backup = $dist.'.bak-ops-test';
    if (is_dir($backup)) {
        File::deleteDirectory($backup);
    }
    rename($dist, $backup);

    try {
        $response = $this->get('/_ops/livewire-assets?token='.OPS_TEST_TOKEN.'');
        $response->assertOk();
        $response->assertSee('livewire.min.js exists=yes', false);
        expect(is_file($min) && filesize($min) > 1000)->toBeTrue();
    } finally {
        if (is_dir($dist)) {
            File::deleteDirectory($dist);
        }
        if (is_dir($backup)) {
            rename($backup, $dist);
        }
    }
});
