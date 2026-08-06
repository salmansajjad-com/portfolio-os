<?php

use App\Enums\RevenueSource;
use App\Exceptions\LockedDistributionException;
use App\Models\DistributionLine;
use App\Models\DistributionRun;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Project;
use App\Models\Revenue;
use App\Models\User;
use App\Services\DistributionService;
use App\Services\ProjectOwnershipService;
use App\Support\Currency;
use App\Support\Money;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function integrityAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole('admin');

    return $user;
}

function integrityRevenue(Project $project, string $month, int $paisa, User $actor): Revenue
{
    return Revenue::query()->create([
        'project_id' => $project->id,
        'period_month' => $month.'-01',
        'source' => RevenueSource::Adsense,
        'amount_usd_cents' => 0,
        'fx_rate_e6' => 0,
        'amount_pkr_paisa' => $paisa,
        'currency_input' => Currency::code(),
        'created_by' => $actor->id,
    ]);
}

it('never creates or loses minor units when splitting by basis points', function () {
    // Rounding each share on its own would pay 1 unit to both halves of a 1-unit profit.
    expect(array_sum(Money::allocateBps(1, ['a' => 5000, 'b' => 5000])))->toBe(1);

    // Thirds cannot divide evenly; the leftover unit must still land somewhere.
    $thirds = Money::allocateBps(100, ['a' => 3333, 'b' => 3333, 'c' => 3334]);
    expect(array_sum($thirds))->toBe(100);

    foreach ([0, 1, 2, 7, 99, 100_00, 999_999] as $amount) {
        $split = Money::allocateBps($amount, ['a' => 3333, 'b' => 3333, 'c' => 3334]);
        expect(array_sum($split))->toBe($amount);
    }

    expect(Money::allocateBps(500, []))->toBe([])
        ->and(array_sum(Money::allocateBps(0, ['a' => 10000])))->toBe(0);
});

it('credits exactly the distributable profit across an uneven three-way split', function () {
    $admin = integrityAdmin();
    $one = User::factory()->create();
    $two = User::factory()->create();
    $three = User::factory()->create();

    $project = Project::factory()->create(['domain' => 'thirds.test', 'status' => 'monetized']);
    app(ProjectOwnershipService::class)->sync($project, [
        ['user_id' => $one->id, 'share_bps' => 3333],
        ['user_id' => $two->id, 'share_bps' => 3333],
        ['user_id' => $three->id, 'share_bps' => 3334],
    ]);

    // An odd number of minor units, so the split cannot come out even.
    integrityRevenue($project, '2026-04', 100_01, $admin);

    $run = app(DistributionService::class)->approve(
        app(DistributionService::class)->createDraft('2026-04', $admin),
        $admin
    );

    $lines = $run->lines()->get();
    $credited = (int) $lines->sum('credited_paisa');
    $gross = (int) $lines->sum('gross_share_paisa');

    expect($lines)->toHaveCount(3)
        ->and($gross)->toBe(100_01)
        ->and($credited)->toBe(100_01)
        ->and((int) $run->total_credited_paisa)->toBe(100_01);
});

it('pays what the figures say at approval, not what the draft captured', function () {
    $admin = integrityAdmin();
    $project = Project::factory()->create(['domain' => 'stale.test', 'status' => 'monetized']);
    app(ProjectOwnershipService::class)->sync($project, [
        ['user_id' => $admin->id, 'share_bps' => 10000],
    ]);

    integrityRevenue($project, '2026-03', 10_000_00, $admin);

    $dist = app(DistributionService::class);
    $draft = $dist->createDraft('2026-03', $admin);
    expect((int) $draft->total_credited_paisa)->toBe(10_000_00);

    // Late revenue lands after the draft was raised.
    integrityRevenue($project, '2026-03', 5_000_00, $admin);

    $approved = $dist->approve($draft->fresh(), $admin);

    expect((int) $approved->total_revenue_paisa)->toBe(15_000_00)
        ->and((int) $approved->total_credited_paisa)->toBe(15_000_00);
});

it('freezes the ownership it actually paid, so later equity changes do not rewrite history', function () {
    $admin = integrityAdmin();
    $partner = User::factory()->create();

    $project = Project::factory()->create(['domain' => 'freeze.test', 'status' => 'monetized']);
    $ownership = app(ProjectOwnershipService::class);
    $ownership->sync($project, [
        ['user_id' => $admin->id, 'share_bps' => 5000],
        ['user_id' => $partner->id, 'share_bps' => 5000],
    ]);

    integrityRevenue($project, '2026-02', 20_000_00, $admin);

    $dist = app(DistributionService::class);
    $approved = $dist->approve($dist->createDraft('2026-02', $admin), $admin);

    $paidToPartner = (int) $approved->lines()->where('user_id', $partner->id)->value('credited_paisa');
    expect($paidToPartner)->toBe(10_000_00);

    // Equity shifts afterwards. The approved run must not move.
    $ownership->sync($project->fresh(), [
        ['user_id' => $admin->id, 'share_bps' => 9000],
        ['user_id' => $partner->id, 'share_bps' => 1000],
    ]);

    $reloaded = DistributionRun::query()->find($approved->id);
    $snapshotShares = collect($reloaded->ownership_snapshot[(string) $project->id])
        ->pluck('share_bps', 'user_id');

    expect((int) $reloaded->lines()->where('user_id', $partner->id)->value('credited_paisa'))->toBe(10_000_00)
        ->and((int) $snapshotShares[$partner->id])->toBe(5000);
});

it('refuses to change an approved run or its lines', function () {
    $admin = integrityAdmin();
    $project = Project::factory()->create(['domain' => 'locked.test', 'status' => 'monetized']);
    app(ProjectOwnershipService::class)->sync($project, [
        ['user_id' => $admin->id, 'share_bps' => 10000],
    ]);
    integrityRevenue($project, '2026-01', 5_000_00, $admin);

    $dist = app(DistributionService::class);
    $approved = $dist->approve($dist->createDraft('2026-01', $admin), $admin);

    expect(fn () => $approved->update(['total_credited_paisa' => 1]))
        ->toThrow(LockedDistributionException::class);

    $line = $approved->lines()->first();
    expect(fn () => $line->update(['credited_paisa' => 1]))
        ->toThrow(LockedDistributionException::class)
        ->and(fn () => $line->delete())
        ->toThrow(LockedDistributionException::class);

    expect(fn () => $approved->forceDelete())->toThrow(LockedDistributionException::class);

    // The figures survived every attempt.
    expect((int) DistributionRun::query()->find($approved->id)->total_credited_paisa)->toBe(5_000_00);
});

it('will not distribute profit for a project that has no ownership split', function () {
    $admin = integrityAdmin();

    $orphan = Project::factory()->create(['domain' => 'orphan.test', 'status' => 'monetized']);
    integrityRevenue($orphan, '2026-06', 7_000_00, $admin);

    // Silently skipping would leave the profit in the run totals, credited to nobody.
    expect(fn () => app(DistributionService::class)->createDraft('2026-06', $admin))
        ->toThrow(RuntimeException::class);
});

it('lets the database reject a duplicate work expense', function () {
    $admin = integrityAdmin();
    $project = Project::factory()->create(['domain' => 'dupe.test']);

    $row = [
        'project_id' => $project->id,
        'is_shared' => false,
        'expense_category_id' => ExpenseCategory::query()->create([
            'name' => 'Content', 'slug' => 'content-dupe',
        ])->id,
        'amount_paisa' => 1_000_00,
        'currency' => Currency::code(),
        'description' => 'Writer fee',
        'expense_date' => '2026-06-01',
        'source_type' => 'App\\Models\\Article',
        'source_id' => 42,
        'source_key' => 'App\\Models\\Article:42',
        'created_by' => $admin->id,
        'is_paid' => false,
    ];

    Expense::query()->create($row);

    expect(fn () => Expense::query()->create($row))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('keeps distribution lines tied to a locked run out of reach of mass updates', function () {
    $admin = integrityAdmin();
    $project = Project::factory()->create(['domain' => 'mass.test', 'status' => 'monetized']);
    app(ProjectOwnershipService::class)->sync($project, [
        ['user_id' => $admin->id, 'share_bps' => 10000],
    ]);
    integrityRevenue($project, '2025-12', 3_000_00, $admin);

    $dist = app(DistributionService::class);
    $approved = $dist->approve($dist->createDraft('2025-12', $admin), $admin);

    foreach (DistributionLine::query()->where('distribution_run_id', $approved->id)->get() as $line) {
        expect(fn () => $line->update(['share_bps' => 1]))->toThrow(LockedDistributionException::class);
    }
});
