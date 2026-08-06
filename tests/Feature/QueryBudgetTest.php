<?php

use App\Enums\RevenueSource;
use App\Livewire\Dashboard;
use App\Livewire\Projects\Index as ProjectsIndex;
use App\Models\Project;
use App\Models\Revenue;
use App\Models\User;
use App\Services\ProjectOwnershipService;
use App\Support\Currency;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

/**
 * These budgets exist because both screens used to scale their query count with
 * the number of projects on the page — a per-row revenue, cost, profit and open
 * task lookup each. On shared hosting that is the difference between a snappy
 * page and one people avoid. The numbers are ceilings, not targets; tighten them
 * if you make things faster, but do not raise them without a reason.
 */
function countQueries(callable $work): int
{
    $count = 0;
    DB::listen(function () use (&$count) {
        $count++;
    });

    $work();

    return $count;
}

function budgetAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole('admin');

    return $user;
}

function seedProjects(User $owner, int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        $project = Project::factory()->create([
            'domain' => "budget-{$i}.test",
            'status' => 'monetized',
        ]);

        app(ProjectOwnershipService::class)->sync($project, [
            ['user_id' => $owner->id, 'share_bps' => 10000],
        ]);

        Revenue::query()->create([
            'project_id' => $project->id,
            'period_month' => now()->startOfMonth()->toDateString(),
            'source' => RevenueSource::Adsense,
            'amount_usd_cents' => 0,
            'fx_rate_e6' => 0,
            'amount_pkr_paisa' => 1_000_00,
            'currency_input' => Currency::code(),
            'created_by' => $owner->id,
        ]);
    }
}

it('keeps the projects list query count flat as projects are added', function () {
    $admin = budgetAdmin();
    seedProjects($admin, 12);

    $queries = countQueries(function () use ($admin) {
        Livewire::actingAs($admin)->test(ProjectsIndex::class)->assertOk();
    });

    expect($queries)->toBeLessThan(30);
})->group('performance');

it('keeps the dashboard query count flat as projects are added', function () {
    $admin = budgetAdmin();
    seedProjects($admin, 12);

    $queries = countQueries(function () use ($admin) {
        Livewire::actingAs($admin)->test(Dashboard::class)->assertOk();
    });

    expect($queries)->toBeLessThan(40);
})->group('performance');
