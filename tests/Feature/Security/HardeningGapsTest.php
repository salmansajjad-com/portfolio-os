<?php

use App\Enums\DistributionStatus;
use App\Enums\PartnerLedgerType;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Exceptions\LockedDistributionException;
use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Tasks\Show;
use App\Models\DistributionRun;
use App\Models\PartnerProfile;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\PartnerLedgerService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    RateLimiter::clear('password-reset:127.0.0.1');
});

it('stores partner payout details encrypted at rest', function () {
    $partner = User::factory()->create();

    $profile = PartnerProfile::query()->create([
        'user_id' => $partner->id,
        'payout_method' => 'bank',
        'payout_details' => 'Example Bank 00012345678',
        'is_active' => true,
    ]);

    $raw = DB::table('partner_profiles')->where('id', $profile->id)->value('payout_details');

    // A leaked database backup should not hand over banking identifiers when
    // credential secrets in the same database are encrypted.
    expect($raw)->not->toContain('00012345678')
        ->and($profile->fresh()->payout_details)->toBe('Example Bank 00012345678')
        ->and($profile->fresh()->toArray())->not->toHaveKey('payout_details');
});

it('throttles password reset requests per caller, not just per address', function () {
    User::factory()->create(['email' => 'target@example.com']);

    // Laravel throttles a single address; nothing stopped one caller walking a
    // list to flood inboxes or confirm which addresses exist.
    for ($i = 0; $i < 5; $i++) {
        Livewire::test(ForgotPassword::class)
            ->set('email', "user{$i}@example.com")
            ->call('sendResetLink');
    }

    Livewire::test(ForgotPassword::class)
        ->set('email', 'target@example.com')
        ->call('sendResetLink')
        ->assertHasErrors('email');
});

it('refuses to change an approved distribution run or its lines', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $run = DistributionRun::query()->create([
        'period_month' => now()->startOfMonth()->toDateString(),
        'status' => DistributionStatus::Draft,
        'holdback_bps' => 0,
        'total_revenue_paisa' => 1000,
        'total_direct_expense_paisa' => 0,
        'total_shared_expense_paisa' => 0,
        'total_net_profit_paisa' => 1000,
        'total_holdback_paisa' => 0,
        'total_credited_paisa' => 1000,
        'ownership_snapshot' => [],
        'created_by' => $admin->id,
    ]);

    // Approving is allowed; changing the figures afterwards is not, whatever
    // path attempts it — the service guard is not the only writer.
    $run->update([
        'status' => DistributionStatus::Approved,
        'approved_by' => $admin->id,
        'approved_at' => now(),
    ]);

    expect(fn () => $run->update(['total_credited_paisa' => 1]))
        ->toThrow(LockedDistributionException::class)
        ->and(fn () => $run->forceDelete())
        ->toThrow(LockedDistributionException::class)
        ->and((int) DistributionRun::query()->find($run->id)->total_credited_paisa)->toBe(1000);
});

it('reads every partner balance in a single query', function () {
    $ids = [];

    foreach (range(1, 5) as $i) {
        $partner = User::factory()->create();
        $ids[] = $partner->id;

        app(PartnerLedgerService::class)->credit(
            userId: $partner->id,
            amountPaisa: 1000 * $i,
            type: PartnerLedgerType::CapitalIn,
            description: 'Opening capital',
            actor: $partner,
        );
    }

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $balances = app(PartnerLedgerService::class)->balancesFor($ids);

    expect($queries)->toBeLessThanOrEqual(2)
        ->and((int) $balances[$ids[0]])->toBe(1000)
        ->and((int) $balances[$ids[4]])->toBe(5000);
});

it('rejects an executable upload as task evidence', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $project = Project::factory()->create(['domain' => 'upload.test']);
    $task = Task::query()->create([
        'project_id' => $project->id,
        'title' => 'Submit proof',
        'type' => TaskType::AdHoc,
        'status' => TaskStatus::Assigned,
        'created_by' => $admin->id,
    ]);

    // Files land on the private disk with no web-executable path, so this is
    // defence in depth rather than the only thing standing in the way.
    Livewire::actingAs($admin)
        ->test(Show::class, ['task' => $task])
        ->set('evidence', File::create('shell.php', 10))
        ->call('uploadEvidence')
        ->assertHasErrors('evidence');

    Livewire::actingAs($admin)
        ->test(Show::class, ['task' => $task])
        ->set('evidence', File::image('proof.png'))
        ->call('uploadEvidence')
        ->assertHasNoErrors('evidence');
});
