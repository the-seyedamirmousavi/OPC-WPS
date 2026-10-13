<?php

namespace Tests\Feature;

use App\Models\AppUser;
use App\Models\DepartmentMember;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsAgentWorld;
use Tests\TestCase;

/** Owner-only user accounts in human resources: generated ids, adding, and deleting people who have no work history. */
class UserManagementTest extends TestCase
{
    use BuildsAgentWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWorld();
    }

    private function newUser(array $body = []): array
    {
        return $body + ['fullName' => 'New Person', 'role' => 'USER_1', 'password' => 'Passw0rd!new'];
    }

    public function test_an_account_created_without_an_id_gets_the_next_free_one(): void
    {
        $this->as('owner');

        $this->postJson('/api/users', $this->newUser())->assertOk()->assertJsonPath('id', 'u001');
        $this->postJson('/api/users', $this->newUser(['id' => null]))->assertOk()->assertJsonPath('id', 'u002');
        $this->postJson('/api/users', $this->newUser(['id' => '  ']))->assertOk()->assertJsonPath('id', 'u003');
    }

    public function test_the_generated_id_follows_the_highest_existing_one(): void
    {
        $this->as('owner');
        $this->postJson('/api/users', $this->newUser(['id' => 'u007']))->assertOk();

        $this->postJson('/api/users', $this->newUser())->assertOk()->assertJsonPath('id', 'u008');
    }

    public function test_an_id_typed_by_the_owner_is_still_used_as_it_is(): void
    {
        $this->as('owner');

        $this->postJson('/api/users', $this->newUser(['id' => 'ali.r']))->assertOk()->assertJsonPath('id', 'ali.r');
        $this->postJson('/api/users', $this->newUser(['id' => 'ali.r']))->assertStatus(409);
    }

    public function test_the_owner_deletes_a_person_without_work_history_and_their_memberships_go_with_them(): void
    {
        $this->staff($this->hr(), [['user3', 'MEMBER']]);
        Notification::query()->create(['recipient_id' => 'user3', 'kind' => 'TEST', 'message' => 'hello', 'created_at' => now()]);
        $this->as('owner');

        $this->deleteJson('/api/users/user3')->assertNoContent();

        $this->assertNull(AppUser::query()->find('user3'));
        $this->assertSame(0, DepartmentMember::query()->where('user_id', 'user3')->count());
        $this->assertSame(0, DB::table('notification')->where('recipient_id', 'user3')->count());
        $this->assertDatabaseHas('audit_event', ['action_name' => 'USER_DELETED', 'entity_id' => 'user3']);
    }

    public function test_an_account_with_work_history_is_kept_and_the_owner_is_told_to_deactivate_it(): void
    {
        $this->operation('OP-1', 'COMPLETED');
        DB::table('operation')->where('id', 'OP-1')->update(['assigned_user_id' => 'user2']);
        $this->as('owner');

        $this->deleteJson('/api/users/user2')->assertStatus(409)
            ->assertJsonFragment(['message' => 'User user2 has work history (1 record(s)) and cannot be deleted. Deactivate it instead.']);
        $this->assertNotNull(AppUser::query()->find('user2'));
    }

    public function test_an_account_with_open_work_is_not_deleted(): void
    {
        $this->operation('OP-2', 'ASSIGNED');
        DB::table('operation')->where('id', 'OP-2')->update(['assigned_user_id' => 'user1']);
        $this->as('owner');

        $this->deleteJson('/api/users/user1')->assertStatus(409);
        $this->assertNotNull(AppUser::query()->find('user1'));
    }

    public function test_the_owner_account_and_the_owners_own_account_cannot_be_deleted(): void
    {
        $this->as('owner');

        $this->deleteJson('/api/users/owner')->assertForbidden();
        $this->assertNotNull(AppUser::query()->find('owner'));
    }

    public function test_an_ai_agent_is_not_deleted_from_here(): void
    {
        $this->as('owner');
        $agent = $this->postJson('/api/agents', ['name' => 'Reader', 'instructions' => 'Read the state.'])->assertOk()->json();

        $this->deleteJson('/api/users/' . $agent['id'])->assertForbidden();
        $this->assertNotNull(AppUser::query()->find($agent['id']));
    }

    public function test_only_the_owner_can_delete_or_create_accounts(): void
    {
        $this->as('manager');

        $this->deleteJson('/api/users/user3')->assertForbidden();
        $this->postJson('/api/users', $this->newUser())->assertForbidden();
        $this->assertNotNull(AppUser::query()->find('user3'));
    }
}
