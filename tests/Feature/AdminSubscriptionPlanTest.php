<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPlanVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminSubscriptionPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_create_and_list_a_draft_plan(): void
    {
        $this->adminSession()->postJson('/api/v1/admin/plans', $this->payload())->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.version', 1);

        $this->adminSession()->getJson('/api/v1/admin/plans')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Starter');
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.subscription_plan.created']);
    }

    public function test_non_admin_users_cannot_access_plan_management(): void
    {
        $this->actingAs(User::factory()->create(), 'web')->getJson('/api/v1/admin/plans')->assertUnauthorized();
        $this->getJson('/api/v1/admin/plans')->assertUnauthorized();
    }

    public function test_plan_validation_rejects_invalid_prices_and_modules(): void
    {
        $this->adminSession()->postJson('/api/v1/admin/plans', array_merge($this->payload(), [
            'monthly_price_paisa' => -1,
            'modules' => ['turf' => 'yes'],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['monthly_price_paisa', 'modules.turf']);
    }

    public function test_publishing_creates_an_immutable_version_and_published_edits_create_a_revision(): void
    {
        $plan = $this->createPlan();

        $this->adminSession()->postJson('/api/v1/admin/plans/'.$plan->id.'/publish')->assertOk()->assertJsonPath('data.status', 'published')->assertJsonPath('published_version', 1);
        $publishedVersion = SubscriptionPlanVersion::where('subscription_plan_id', $plan->id)->firstOrFail();

        $this->adminSession()->putJson('/api/v1/admin/plans/'.$plan->id, array_merge($this->payload(), ['name' => 'Revised']))
            ->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.version', 2);

        $this->assertSame('Starter', $publishedVersion->fresh()->name);
        $this->assertSame('draft', $plan->fresh()->status);

        $this->adminSession()->postJson('/api/v1/admin/plans/'.$plan->id.'/publish')->assertOk()->assertJsonPath('published_version', 2);
        $this->assertCount(2, SubscriptionPlanVersion::where('subscription_plan_id', $plan->id)->get());
    }

    public function test_archived_plan_cannot_be_edited_but_can_be_published_again(): void
    {
        $plan = $this->createPlan();

        $this->adminSession()->postJson('/api/v1/admin/plans/'.$plan->id.'/archive')->assertOk()->assertJsonPath('data.status', 'archived');
        $this->adminSession()->putJson('/api/v1/admin/plans/'.$plan->id, ['name' => 'Not Allowed'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->adminSession()->postJson('/api/v1/admin/plans/'.$plan->id.'/publish')->assertOk()->assertJsonPath('data.status', 'published');
    }

    private function createPlan(): SubscriptionPlan
    {
        $response = $this->adminSession()->postJson('/api/v1/admin/plans', $this->payload());
        return SubscriptionPlan::findOrFail($response->json('data.id'));
    }

    private function adminSession()
    {
        return $this->actingAs($this->admin(), 'platform_admin')->withHeader('Origin', 'http://localhost:3000');
    }

    private function admin(): PlatformAdmin
    {
        return PlatformAdmin::firstOrCreate(
            ['email' => 'plans-admin@example.com'],
            ['name' => 'Plans Admin', 'password' => Hash::make('correct-password'), 'is_active' => true],
        );
    }

    private function payload(): array
    {
        return [
            'name' => 'Starter',
            'slug' => 'starter',
            'description' => 'Starter plan',
            'monthly_price_paisa' => 100000,
            'yearly_price_paisa' => 1000000,
            'modules' => ['turf' => true, 'restaurant' => false, 'shop' => false, 'equipment_rental' => false],
            'max_turfs' => 1,
            'max_branches' => 1,
            'max_staff' => 5,
        ];
    }
}
