<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\ProjectStatus;
use App\Models\Company;
use App\Models\Project;
use App\Support\CurrentCompany;
use Inertia\Testing\AssertableInertia as Assert;

function projectIn(Company $company, string $name, array $attributes = []): Project
{
    return Project::query()->withoutGlobalScope('company')->create(['company_id' => $company->id, 'name' => $name, 'status' => ProjectStatus::Active] + $attributes);
}

test('admins see the projects list', function () {
    $admin = userWithRole();
    projectIn(companyOf($admin), 'Saarthi portal', ['client' => 'Saarthi JV']);

    $this->actingAs($admin)
        ->get(route('settings.projects'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/projects')
            ->has('projects', 1)
            ->where('projects.0.name', 'Saarthi portal')
            ->where('projects.0.client', 'Saarthi JV')
            ->where('projects.0.status', 'active')
            ->where('projects.0.records', 0));
});

test('roles without the Settings page are denied', function (CompanyRole $role) {
    $this->actingAs(userWithRole($role))->get(route('settings.projects'))->assertForbidden();
    $this->actingAs(userWithRole($role))->post(route('settings.projects.store'), ['name' => 'X'])->assertForbidden();
})->with([CompanyRole::Partner, CompanyRole::Member, CompanyRole::Viewer]);

test('adding a project validates the name and keeps names unique per company', function () {
    $admin = userWithRole();
    $company = companyOf($admin);
    projectIn($company, 'Internal');

    $this->actingAs($admin)->post(route('settings.projects.store'), ['name' => ''])
        ->assertSessionHasErrors(['name' => 'Add the project’s name.']);
    $this->actingAs($admin)->post(route('settings.projects.store'), ['name' => 'Internal'])
        ->assertSessionHasErrors(['name' => 'There’s already a project with this name.']);

    // Another company's project of the same name doesn't clash.
    projectIn(companyOf(userWithRole()), 'Asha Retail store');

    $this->actingAs($admin)->post(route('settings.projects.store'), ['name' => ' Asha Retail store ', 'client' => 'Asha Retail'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Project::query()->withoutGlobalScope('company')->where('company_id', $company->id)->where('name', 'Asha Retail store')->value('client'))
        ->toBe('Asha Retail');
});

test('a project can be renamed, archived and restored', function () {
    $admin = userWithRole();
    $project = projectIn(companyOf($admin), 'Kiran site');

    $this->actingAs($admin)->put(route('settings.projects.update', $project), ['name' => 'Kiran Exports site', 'client' => null])->assertRedirect();
    expect($project->fresh()->name)->toBe('Kiran Exports site');

    $this->actingAs($admin)->patch(route('settings.projects.status', $project))->assertRedirect();
    expect($project->fresh()->status)->toBe(ProjectStatus::Archived);

    $this->actingAs($admin)->patch(route('settings.projects.status', $project))->assertRedirect();
    expect($project->fresh()->status)->toBe(ProjectStatus::Active);
});

test('unused projects can be deleted', function () {
    $admin = userWithRole();
    $project = projectIn(companyOf($admin), 'Old pitch');

    $this->actingAs($admin)->delete(route('settings.projects.destroy', $project))->assertRedirect();

    expect(Project::query()->withoutGlobalScope('company')->find($project->id))->toBeNull();
});

test('another company’s project is out of reach', function () {
    $admin = userWithRole();
    $theirs = projectIn(companyOf(userWithRole()), 'Theirs');

    $this->actingAs($admin)->put(route('settings.projects.update', $theirs), ['name' => 'Mine now'])->assertNotFound();
    $this->actingAs($admin)->delete(route('settings.projects.destroy', $theirs))->assertNotFound();

    expect($theirs->fresh()->name)->toBe('Theirs');
});

test('every company starts with an Internal project', function () {
    $books = singleBooks();
    app(CurrentCompany::class)->set($books->company, CompanyRole::Admin);

    expect(Project::query()->pluck('name')->all())->toBe(['Internal']);
});
