<?php

namespace Tests\Feature;

use App\Models\AppUser;
use App\Models\AuditEvent;
use App\Models\DepartmentSuggestion;
use App\Models\Notification;
use App\Models\WorkResource;
use Database\Seeders\AisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Departments: the owner's panels, the owner's control over members and permissions, and the suggestions to the manager. */
class DepartmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['aiso.seed.enabled' => true, 'aiso.seed.password' => 'Passw0rd!test', 'aiso.seed.language' => 'en']);
        $this->seed(AisoSeeder::class);
        $this->asUser('owner');
    }

    private function asUser(string $id): void
    {
        $this->actingAs(AppUser::query()->find($id));
    }

    private function department(string $name = 'Human resources'): int
    {
        return (int) $this->postJson('/api/departments', ['name' => $name])->assertOk()->json('id');
    }

    private function resource(string $id): void
    {
        WorkResource::query()->create([
            'id' => $id, 'name' => "Machine {$id}", 'resource_type' => 'Machine', 'capacity' => 1,
            'status' => 'ACTIVE', 'sort_order' => 0,
        ]);
    }

    /** @param array<int, array{0: string, 1: string}> $members user id and department role pairs */
    private function staff(int $dept, array $members): void
    {
        $this->putJson("/api/departments/{$dept}/members", [
            'members' => array_map(static fn (array $m) => ['userId' => $m[0], 'role' => $m[1]], $members),
        ])->assertOk();
    }

    public function test_only_the_owner_sees_the_departments_and_their_panels(): void
    {
        $dept = $this->department();

        foreach (['manager', 'user1'] as $who) {
            $this->asUser($who);
            $this->getJson('/api/departments')->assertForbidden();
            $this->getJson("/api/departments/{$dept}")->assertForbidden();
            $this->patchJson("/api/departments/{$dept}", ['name' => 'Renamed'])->assertForbidden();
            $this->putJson("/api/departments/{$dept}/permissions", ['permissions' => ['HEAD' => [], 'MEMBER' => []]])
                ->assertForbidden();
        }

        $this->asUser('owner');
        $this->getJson("/api/departments/{$dept}")->assertOk()->assertJsonPath('name', 'Human resources');
    }

    public function test_the_owner_builds_a_department_with_default_permissions(): void
    {
        $dept = $this->department();
        $this->resource('R-1');
        $this->resource('R-2');
        $this->staff($dept, [['user1', 'HEAD'], ['user2', 'MEMBER']]);

        $this->putJson("/api/departments/{$dept}/resources", ['resourceIds' => ['R-1', 'R-2']])->assertOk();

        $panel = $this->getJson("/api/departments/{$dept}")->assertOk()->json();
        $this->assertSame(['PANEL_VIEW', 'RESOURCES_MANAGE', 'SUGGESTIONS_SUBMIT'], $panel['permissions']['HEAD']);
        $this->assertSame(['PANEL_VIEW', 'SUGGESTIONS_SUBMIT'], $panel['permissions']['MEMBER']);
        $this->assertSame(2, $panel['memberCount']);
        $this->assertSame(2, $panel['resourceCount']);
        // never confirmed, so both are stale from the start
        $this->assertSame(2, $panel['staleResourceCount']);
    }

    public function test_a_resource_not_confirmed_within_the_review_period_is_stale(): void
    {
        $dept = $this->department();
        $this->resource('R-1');
        $this->putJson("/api/departments/{$dept}/resources", ['resourceIds' => ['R-1']])->assertOk();
        $this->staff($dept, [['user1', 'HEAD']]);

        $this->asUser('user1');
        $this->patchJson("/api/departments/{$dept}/resources/R-1", ['capacity' => 2])->assertOk();

        $this->asUser('owner');
        $panel = $this->getJson("/api/departments/{$dept}")->assertOk()->json();
        $this->assertFalse($panel['resources'][0]['stale']);
        $this->assertSame(0, $panel['staleResourceCount']);

        // 31 days later the confirmation has lapsed
        WorkResource::query()->whereKey('R-1')->update(['reviewed_at' => now()->subDays(31)]);
        $panel = $this->getJson("/api/departments/{$dept}")->assertOk()->json();
        $this->assertTrue($panel['resources'][0]['stale']);
        $this->assertSame(1, $panel['staleResourceCount']);
    }

    public function test_a_head_keeps_the_resources_up_to_date_and_a_member_cannot(): void
    {
        $dept = $this->department();
        $this->resource('R-1');
        $this->resource('R-2');
        $this->putJson("/api/departments/{$dept}/resources", ['resourceIds' => ['R-1']])->assertOk();
        $this->staff($dept, [['user1', 'HEAD'], ['user2', 'MEMBER']]);

        $this->asUser('user1');
        $this->patchJson("/api/departments/{$dept}/resources/R-1", ['capacity' => 4, 'description' => 'Second shift'])
            ->assertOk()
            ->assertJsonPath('capacity', 4)
            ->assertJsonPath('stale', false);
        $this->assertNotNull(WorkResource::query()->find('R-1')->reviewed_at);

        // a resource the department does not keep is not its to change
        $this->patchJson("/api/departments/{$dept}/resources/R-2", ['capacity' => 9])->assertNotFound();

        $this->asUser('user2');
        $this->patchJson("/api/departments/{$dept}/resources/R-1", ['capacity' => 9])->assertForbidden();
        $this->assertSame(4, WorkResource::query()->find('R-1')->capacity);
    }

    public function test_an_empty_description_clears_it_and_an_absent_one_is_kept(): void
    {
        $dept = $this->department();
        $this->resource('R-1');
        $this->putJson("/api/departments/{$dept}/resources", ['resourceIds' => ['R-1']])->assertOk();
        $this->staff($dept, [['user1', 'HEAD']]);

        $this->asUser('user1');
        $this->patchJson("/api/departments/{$dept}/resources/R-1", ['description' => 'Night shift'])->assertOk();
        $this->patchJson("/api/departments/{$dept}/resources/R-1", ['status' => 'ACTIVE'])->assertOk();
        $this->assertSame('Night shift', WorkResource::query()->find('R-1')->description);

        $this->patchJson("/api/departments/{$dept}/resources/R-1", ['description' => ''])->assertOk();
        $this->assertNull(WorkResource::query()->find('R-1')->description);
    }

    public function test_a_suggestion_goes_to_the_manager_who_accepts_or_rejects_it(): void
    {
        $dept = $this->department();
        $this->resource('R-1');
        $this->putJson("/api/departments/{$dept}/resources", ['resourceIds' => ['R-1']])->assertOk();
        $this->staff($dept, [['user1', 'HEAD'], ['user2', 'MEMBER']]);

        $this->asUser('user2');
        $this->postJson("/api/departments/{$dept}/suggestions", [
            'title' => 'Add a second shift', 'body' => 'The machine waits all afternoon.', 'resourceId' => 'R-1',
        ])->assertOk()->assertJsonPath('status', 'PENDING')->assertJsonPath('authorId', 'user2');

        $this->assertSame(1, Notification::query()->where('recipient_id', 'manager')
            ->where('kind', 'DEPARTMENT_SUGGESTION')->count());

        // the manager sees it on the dashboard; executives do not
        $this->asUser('user1');
        $this->getJson('/api/department-suggestions?status=PENDING')->assertForbidden();

        $this->asUser('manager');
        $list = $this->getJson('/api/department-suggestions?status=PENDING')->assertOk()->json();
        $this->assertCount(1, $list);
        $this->assertSame('Human resources', $list[0]['departmentName']);
        $this->assertSame('Machine R-1', $list[0]['resourceName']);
        $id = $list[0]['id'];

        $this->postJson("/api/department-suggestions/{$id}/decide", ['decision' => 'ACCEPTED', 'note' => 'Agreed'])
            ->assertOk()
            ->assertJsonPath('status', 'ACCEPTED')
            ->assertJsonPath('managerNote', 'Agreed');
        $this->postJson("/api/department-suggestions/{$id}/decide", ['decision' => 'REJECTED'])->assertConflict();
        $this->assertSame([], $this->getJson('/api/department-suggestions?status=PENDING')->json());

        $this->assertSame(1, Notification::query()->where('recipient_id', 'user2')
            ->where('kind', 'DEPARTMENT_SUGGESTION_DECIDED')->count());
    }

    public function test_the_owner_decides_which_roles_may_do_what(): void
    {
        $dept = $this->department();
        $this->resource('R-1');
        $this->putJson("/api/departments/{$dept}/resources", ['resourceIds' => ['R-1']])->assertOk();
        $this->staff($dept, [['user1', 'HEAD'], ['user2', 'MEMBER']]);

        // take the suggestion right away from members and the resource right from heads
        $this->putJson("/api/departments/{$dept}/permissions", [
            'permissions' => ['HEAD' => ['SUGGESTIONS_SUBMIT'], 'MEMBER' => []],
        ])->assertOk()->assertJsonPath('permissions.MEMBER', []);

        $this->asUser('user2');
        $this->postJson("/api/departments/{$dept}/suggestions", ['title' => 'x', 'body' => 'y'])->assertForbidden();

        $this->asUser('user1');
        $this->patchJson("/api/departments/{$dept}/resources/R-1", ['capacity' => 2])->assertForbidden();
        $this->postJson("/api/departments/{$dept}/suggestions", ['title' => 'x', 'body' => 'y'])->assertOk();

        // a permission the owner grants later works straight away
        $this->asUser('owner');
        $this->putJson("/api/departments/{$dept}/permissions", [
            'permissions' => ['HEAD' => ['RESOURCES_MANAGE'], 'MEMBER' => ['SUGGESTIONS_SUBMIT']],
        ])->assertOk();
        $this->asUser('user2');
        $this->postJson("/api/departments/{$dept}/suggestions", ['title' => 'x', 'body' => 'y'])->assertOk();
    }

    public function test_someone_outside_the_department_cannot_act_in_it(): void
    {
        $dept = $this->department();
        $this->resource('R-1');
        $this->putJson("/api/departments/{$dept}/resources", ['resourceIds' => ['R-1']])->assertOk();

        $this->asUser('user3');
        $this->postJson("/api/departments/{$dept}/suggestions", ['title' => 'x', 'body' => 'y'])->assertForbidden();
        $this->patchJson("/api/departments/{$dept}/resources/R-1", ['capacity' => 2])->assertForbidden();
    }

    public function test_an_inactive_department_takes_no_suggestions_or_resource_changes(): void
    {
        $dept = $this->department();
        $this->resource('R-1');
        $this->putJson("/api/departments/{$dept}/resources", ['resourceIds' => ['R-1']])->assertOk();
        $this->staff($dept, [['user1', 'HEAD']]);

        $this->patchJson("/api/departments/{$dept}", ['status' => 'INACTIVE'])
            ->assertOk()->assertJsonPath('status', 'INACTIVE');

        $this->asUser('user1');
        $this->postJson("/api/departments/{$dept}/suggestions", ['title' => 'x', 'body' => 'y'])->assertConflict();
        $this->patchJson("/api/departments/{$dept}/resources/R-1", ['capacity' => 2])->assertConflict();
    }

    public function test_a_resource_moves_from_one_department_to_another_and_the_change_is_audited(): void
    {
        $hr = $this->department('HR');
        $ops = $this->department('Operations');
        $this->resource('R-1');

        $this->putJson("/api/departments/{$hr}/resources", ['resourceIds' => ['R-1']])->assertOk();
        $this->putJson("/api/departments/{$ops}/resources", ['resourceIds' => ['R-1']])->assertOk();

        $this->assertSame(0, $this->getJson("/api/departments/{$hr}")->json('resourceCount'));
        $this->assertSame(1, $this->getJson("/api/departments/{$ops}")->json('resourceCount'));
        $this->assertTrue(AuditEvent::query()->where('action_name', 'DEPARTMENT_RESOURCES_ASSIGNED')
            ->where('reason', 'like', '%Moved from another department: R-1%')->exists());

        // releasing it leaves it with no department
        $this->putJson("/api/departments/{$ops}/resources", ['resourceIds' => []])->assertOk();
        $this->assertNull(WorkResource::query()->find('R-1')->department_id);
    }

    public function test_the_owner_is_not_made_a_member_and_inactive_users_are_not_added(): void
    {
        $dept = $this->department();

        $this->putJson("/api/departments/{$dept}/members", ['members' => [['userId' => 'owner', 'role' => 'HEAD']]])
            ->assertStatus(400);

        AppUser::query()->whereKey('user3')->update(['active' => false]);
        $this->putJson("/api/departments/{$dept}/members", ['members' => [['userId' => 'user3', 'role' => 'MEMBER']]])
            ->assertStatus(400);
    }

    public function test_a_reset_clears_the_resources_and_their_suggestions_but_keeps_the_departments(): void
    {
        $dept = $this->department();
        $this->resource('R-1');
        $this->putJson("/api/departments/{$dept}/resources", ['resourceIds' => ['R-1']])->assertOk();
        $this->staff($dept, [['user2', 'MEMBER']]);

        $this->asUser('user2');
        $this->postJson("/api/departments/{$dept}/suggestions", [
            'title' => 'x', 'body' => 'y', 'resourceId' => 'R-1',
        ])->assertOk();

        $this->asUser('owner');
        $this->postJson('/api/admin/reset', ['reason' => 'Start over', 'confirm' => 'RESET'])->assertOk();

        $this->assertSame(0, WorkResource::query()->count());
        $this->assertSame(0, DepartmentSuggestion::query()->count());
        $this->getJson("/api/departments/{$dept}")->assertOk()->assertJsonPath('memberCount', 1);
    }

    public function test_owner_changes_are_recorded_in_the_audit_log(): void
    {
        $dept = $this->department();
        $this->staff($dept, [['user1', 'HEAD']]);
        $this->putJson("/api/departments/{$dept}/permissions", [
            'permissions' => ['HEAD' => ['RESOURCES_MANAGE'], 'MEMBER' => []],
        ])->assertOk();

        foreach (['DEPARTMENT_CREATED', 'DEPARTMENT_MEMBERS_UPDATED', 'DEPARTMENT_PERMISSIONS_UPDATED'] as $action) {
            $this->assertTrue(AuditEvent::query()->where('action_name', $action)->exists(), $action);
        }
    }
}
