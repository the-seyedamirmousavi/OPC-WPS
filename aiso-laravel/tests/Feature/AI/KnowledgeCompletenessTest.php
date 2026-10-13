<?php

namespace Tests\Feature\AI;

use Tests\TestCase;

/**
 * The AI decides from the descriptions in config/aiso.php, and people read about the same things in USER-MANUAL.md.
 * These guards fail when an intent or action is added without a full description, or without being documented.
 */
class KnowledgeCompletenessTest extends TestCase
{
    public function test_every_intent_and_action_is_fully_described(): void
    {
        foreach (config('aiso.agent.kinds') as $kind => $spec) {
            $this->assertNotSame('', trim((string) ($spec['purpose'] ?? '')), "{$kind}: purpose");
            $this->assertNotSame('', trim((string) ($spec['summary'] ?? '')), "{$kind}: summary");
            $this->assertNotEmpty($spec['intents'], "{$kind}: intents");

            foreach ($spec['intents'] as $name => $intent) {
                foreach (['description', 'returns', 'calls'] as $field) {
                    $this->assertNotSame('', trim((string) ($intent[$field] ?? '')), "{$kind}.{$name}: {$field}");
                }
                $this->assertArrayHasKey('params', $intent, "{$kind}.{$name}: params");
            }
            foreach ($spec['actions'] as $name => $action) {
                foreach (['description', 'calls'] as $field) {
                    $this->assertNotSame('', trim((string) ($action[$field] ?? '')), "{$kind}.{$name}: {$field}");
                }
                $this->assertContains($action['risk'] ?? null, ['low', 'medium', 'high'], "{$kind}.{$name}: risk");
                $this->assertArrayHasKey('params', $action, "{$kind}.{$name}: params");
            }
        }
    }

    public function test_the_guide_explains_every_role_and_permission(): void
    {
        $guide = config('aiso.agent.guide');
        foreach (['OWNER', 'MANAGER', 'USER_1', 'USER_2', 'USER_3', 'AGENT'] as $role) {
            $this->assertNotSame('', trim((string) ($guide['roles'][$role] ?? '')), "role {$role}");
        }
        foreach (['HEAD', 'MEMBER'] as $role) {
            $this->assertNotSame('', trim((string) ($guide['department_roles'][$role] ?? '')), "department role {$role}");
        }
        foreach (['PANEL_VIEW', 'RESOURCES_MANAGE', 'SUGGESTIONS_SUBMIT'] as $permission) {
            $this->assertNotSame('', trim((string) ($guide['permissions'][$permission] ?? '')), "permission {$permission}");
        }
        $this->assertNotEmpty($guide['approval']);
    }

    public function test_the_user_manual_documents_every_intent_and_action(): void
    {
        $manual = file_get_contents(base_path('USER-MANUAL.md'));
        $missing = [];
        foreach (config('aiso.agent.kinds') as $kind => $spec) {
            foreach (array_merge(array_keys($spec['intents']), array_keys($spec['actions'])) as $name) {
                if (!str_contains($manual, '`' . $name . '`')) {
                    $missing[] = "{$kind}.{$name}";
                }
            }
        }
        $this->assertSame([], $missing, 'USER-MANUAL.md does not mention: ' . implode(', ', $missing));
    }
}
