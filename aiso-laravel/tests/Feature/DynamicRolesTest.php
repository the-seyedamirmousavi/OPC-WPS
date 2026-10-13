<?php

namespace Tests\Feature;

use App\Models\AppRole;
use App\Models\AppUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsAgentWorld;
use Tests\TestCase;

/** Roles are defined by the company: their name, whether their people receive tasks, and the capabilities they hold. */
class DynamicRolesTest extends TestCase
{
    use BuildsAgentWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWorld();
    }

    /** Defines a role as the owner and returns its key. */
    private function defineRole(array $body): string
    {
        $this->as('owner');

        return $this->postJson('/api/roles', $body)->assertCreated()->json('key');
    }

    /** Gives a person a role, as the owner. */
    private function hire(string $id, string $role): void
    {
        $this->as('owner');
        $this->postJson('/api/users', ['id' => $id, 'fullName' => "Person {$id}", 'role' => $role, 'password' => 'Passw0rd!new'])->assertOk();
    }

    public function test_the_company_defines_a_role_with_its_own_name_and_capabilities(): void
    {
        $key = $this->defineRole(['name' => 'Quality', 'description' => 'Checks the output', 'receivesTasks' => false,
            'capabilities' => ['production.view']]);

        $this->assertStringStartsWith('ROLE-', $key);
        $this->assertDatabaseHas('app_role', ['key' => $key, 'name' => 'Quality', 'receives_tasks' => false, 'locked' => false]);
    }

    public function test_a_role_that_can_only_view_reads_production_but_cannot_change_it(): void
    {
        $key = $this->defineRole(['name' => 'Viewer', 'capabilities' => ['production.view']]);
        $this->hire('viewer1', $key);
        $this->operation('OP-1', 'BLOCKED');

        $this->as('viewer1');
        $this->getJson('/api/projects')->assertOk();
        $this->postJson('/api/operations/OP-1/unblock')->assertForbidden();
        $this->assertSame('BLOCKED', DB::table('operation')->where('id', 'OP-1')->value('status'));
        $this->getJson('/api/users')->assertForbidden();
    }

    public function test_a_role_with_users_manage_adds_people_and_a_role_without_it_cannot(): void
    {
        $key = $this->defineRole(['name' => 'HR', 'capabilities' => ['users.view', 'users.manage']]);
        $this->hire('hr1', $key);
        $this->hire('plain1', 'USER_2');

        $this->as('hr1');
        $this->postJson('/api/users', ['id' => 'new1', 'fullName' => 'New', 'role' => 'USER_3', 'password' => 'Passw0rd!new'])->assertOk();

        $this->as('plain1');
        $this->postJson('/api/users', ['id' => 'new2', 'fullName' => 'New', 'role' => 'USER_3', 'password' => 'Passw0rd!new'])->assertForbidden();
    }

    public function test_a_role_that_manages_accounts_cannot_give_or_change_a_role_that_manages_others(): void
    {
        $key = $this->defineRole(['name' => 'HR', 'capabilities' => ['users.view', 'users.manage']]);
        $this->hire('hr1', $key);

        $this->as('hr1');
        $this->postJson('/api/users', ['id' => 'mgr2', 'fullName' => 'Manager two', 'role' => 'MANAGER', 'password' => 'Passw0rd!new'])
            ->assertForbidden();
        $this->patchJson('/api/users/manager', ['active' => false])->assertForbidden();
        $this->patchJson('/api/users/user1', ['role' => 'MANAGER'])->assertForbidden();

        // the owner still may
        $this->as('owner');
        $this->patchJson('/api/users/user1', ['role' => 'MANAGER'])->assertOk();
    }

    public function test_only_a_role_that_receives_tasks_sees_its_own_work(): void
    {
        $field = $this->defineRole(['name' => 'Field', 'receivesTasks' => true]);
        $quality = $this->defineRole(['name' => 'Quality', 'receivesTasks' => false]);
        $this->hire('field1', $field);
        $this->hire('quality1', $quality);

        $this->as('field1');
        $this->getJson('/api/my/tasks')->assertOk();

        $this->as('quality1');
        $this->getJson('/api/my/tasks')->assertForbidden();
    }

    public function test_the_capabilities_of_a_person_are_what_the_page_and_the_session_receive(): void
    {
        $key = $this->defineRole(['name' => 'HR', 'capabilities' => ['users.view', 'users.manage'], 'receivesTasks' => false]);
        $this->hire('hr1', $key);

        $this->as('owner');
        $row = collect($this->getJson('/api/users')->json())->firstWhere('id', 'hr1');
        $this->assertSame(['users.view', 'users.manage'], $row['capabilities']);
        $this->assertSame('HR', $row['roleName']);

        $this->as('hr1');
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('capabilities', ['users.view', 'users.manage']);
    }

    public function test_a_change_to_a_role_changes_what_its_people_may_do_at_once(): void
    {
        $key = $this->defineRole(['name' => 'Changer', 'capabilities' => []]);
        $this->hire('ch1', $key);

        $this->as('ch1');
        $this->getJson('/api/projects')->assertForbidden();

        $this->as('owner');
        $this->patchJson("/api/roles/{$key}", ['capabilities' => ['production.view']])->assertOk();

        $this->as('ch1');
        $this->getJson('/api/projects')->assertOk();
    }

    public function test_a_role_that_people_hold_cannot_be_deleted_and_an_empty_one_can(): void
    {
        $used = $this->defineRole(['name' => 'Used']);
        $this->hire('holder1', $used);
        $empty = $this->defineRole(['name' => 'Empty']);

        $this->as('owner');
        $this->deleteJson("/api/roles/{$used}")->assertStatus(409);
        $this->deleteJson("/api/roles/{$empty}")->assertNoContent();
        $this->assertDatabaseMissing('app_role', ['key' => $empty]);
    }

    public function test_the_owner_and_the_agent_roles_are_locked_and_built_in_roles_cannot_be_deleted(): void
    {
        $this->as('owner');

        $this->patchJson('/api/roles/OWNER', ['name' => 'Boss'])->assertForbidden();
        $this->patchJson('/api/roles/AGENT', ['capabilities' => ['production.view']])->assertForbidden();
        $this->deleteJson('/api/roles/MANAGER')->assertForbidden();
        $this->deleteJson('/api/roles/OWNER')->assertForbidden();
    }

    public function test_an_unknown_capability_is_refused_and_the_locked_roles_cannot_be_given_to_a_person(): void
    {
        $this->as('owner');

        $this->postJson('/api/roles', ['name' => 'Bad', 'capabilities' => ['root.access']])->assertStatus(400);
        $this->postJson('/api/users', ['id' => 'bad1', 'fullName' => 'Bad', 'role' => 'OWNER', 'password' => 'Passw0rd!new'])->assertStatus(400);
        $this->postJson('/api/users', ['id' => 'bad2', 'fullName' => 'Bad', 'role' => 'AGENT', 'password' => 'Passw0rd!new'])->assertStatus(400);
        $this->postJson('/api/users', ['id' => 'bad3', 'fullName' => 'Bad', 'role' => 'NOPE', 'password' => 'Passw0rd!new'])->assertStatus(400);
    }

    public function test_only_the_owner_defines_roles(): void
    {
        $this->as('manager');

        $this->postJson('/api/roles', ['name' => 'Mine'])->assertForbidden();
        $this->assertDatabaseMissing('app_role', ['name' => 'Mine']);
    }

    public function test_a_managing_role_stays_with_the_owner_in_the_excel_import_rule(): void
    {
        // the rule is data: a role that manages others is one that holds a managerial capability
        $this->assertTrue(AppRole::query()->find(AppRole::MANAGER)->isManagerial());
        $this->assertFalse(AppRole::query()->find(AppRole::USER_1)->isManagerial());
        $this->assertTrue(AppUser::query()->find('owner')->isOwner());
    }
}
