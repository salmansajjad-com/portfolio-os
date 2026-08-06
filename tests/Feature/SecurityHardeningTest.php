<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Livewire\Tasks\Index as TasksIndex;
use App\Models\PartnerProfile;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\CsvExportService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

it('neutralises spreadsheet formulas in exported values', function () {
    // Opened in Excel or Sheets, a cell starting with = is executed.
    foreach (['=1+1', '+SUM(A1)', '-2+3', '@cmd', "\tlead", "\rcarriage"] as $dangerous) {
        expect(CsvExportService::neutralise($dangerous))->toStartWith("\t");
    }

    // Ordinary text is left alone, including negative-looking money already formatted.
    expect(CsvExportService::neutralise('Hosting renewal'))->toBe('Hosting renewal')
        ->and(CsvExportService::neutralise('1200.00'))->toBe('1200.00')
        ->and(CsvExportService::neutralise(''))->toBe('');
});

it('ignores an assignee change from someone without permission to assign', function () {
    $staff = User::factory()->create();
    $staff->assignRole('staff');

    $other = User::factory()->create();
    $project = Project::factory()->create(['domain' => 'assign.test']);
    $project->teamMembers()->syncWithoutDetaching([$staff->id]);

    $task = Task::query()->create([
        'project_id' => $project->id,
        'title' => 'Write the launch checklist',
        'type' => TaskType::AdHoc,
        'status' => TaskStatus::Assigned,
        'assigned_to' => $staff->id,
        'created_by' => $staff->id,
    ]);

    expect($staff->hasPermission('tasks.update'))->toBeTrue()
        ->and($staff->hasPermission('tasks.assign'))->toBeFalse();

    // The form hides the field, so the request has to be forged — which is the
    // whole point: hiding a control is not authorisation.
    Livewire::actingAs($staff)
        ->test(TasksIndex::class)
        ->call('edit', $task->id)
        ->set('assigned_to', (string) $other->id)
        ->call('save');

    expect((int) $task->fresh()->assigned_to)->toBe($staff->id);
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

    expect($raw)->not->toContain('00012345678')
        ->and($profile->fresh()->payout_details)->toBe('Example Bank 00012345678')
        ->and($profile->fresh()->toArray())->not->toHaveKey('payout_details');
});
