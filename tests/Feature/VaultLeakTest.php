<?php

use App\Enums\CredentialType;
use App\Livewire\Projects\Show as ProjectShow;
use App\Models\Credential;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectOwnershipService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function vaultAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole('admin');

    return $user;
}

function vaultProject(User $owner): Project
{
    $project = Project::factory()->create(['domain' => 'leak.test']);
    app(ProjectOwnershipService::class)->sync($project, [
        ['user_id' => $owner->id, 'share_bps' => 10000],
    ]);

    return $project->fresh();
}

function vaultCredential(Project $project): Credential
{
    return Credential::factory()->create([
        'project_id' => $project->id,
        'type' => CredentialType::CmsAdmin,
        'label' => 'WP Admin',
        'username' => 'admin-user',
        'secret' => 'plaintext-secret-value-xyz',
    ]);
}

it('keeps a revealed secret out of the Livewire state sent to the browser', function () {
    $admin = vaultAdmin();
    $project = vaultProject($admin);
    $credential = vaultCredential($project);

    $component = Livewire::actingAs($admin)
        ->test(ProjectShow::class, ['project' => $project])
        ->call('revealCredential', $credential->id);

    // The secret is meant to be visible in the rendered output right now...
    $component->assertSee('plaintext-secret-value-xyz');

    // ...but it must not be carried in the serialised component state, which is
    // posted back to the browser on every subsequent request and is readable
    // from the page source long after the reveal.
    $snapshot = json_encode($component->snapshot['data'] ?? []);

    expect($snapshot)->not->toContain('plaintext-secret-value-xyz')
        ->and($snapshot)->not->toContain('admin-user');
});

it('re-masks a revealed secret on the next page load', function () {
    $admin = vaultAdmin();
    $project = vaultProject($admin);
    $credential = vaultCredential($project);

    Livewire::actingAs($admin)
        ->test(ProjectShow::class, ['project' => $project])
        ->call('revealCredential', $credential->id)
        ->assertSee('plaintext-secret-value-xyz');

    // A fresh mount is a fresh page. Nothing should still be unmasked.
    Livewire::actingAs($admin)
        ->test(ProjectShow::class, ['project' => $project])
        ->assertDontSee('plaintext-secret-value-xyz')
        ->assertSee('••••••••');
});

it('does not put a stored username into component state when editing a credential', function () {
    $admin = vaultAdmin();
    $project = vaultProject($admin);
    $credential = vaultCredential($project);

    $component = Livewire::actingAs($admin)
        ->test(ProjectShow::class, ['project' => $project])
        ->call('editCredential', $credential->id);

    $snapshot = json_encode($component->snapshot['data'] ?? []);

    expect($snapshot)->not->toContain('plaintext-secret-value-xyz');
});
