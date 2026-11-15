<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Auth\Notifications\VerifyEmail;
use Tests\TestCase;

class AuthAndOrganizationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_frontend_origin_is_allowed_for_api_preflight_requests(): void
    {
        $response = $this->call('OPTIONS', '/api/login', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:5174',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ]);

        $response->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5174');
    }

    public function test_user_can_register_and_login(): void
    {
        $this->seed(RoleSeeder::class);
        Notification::fake();

        $registerResponse = $this->postJson('/api/register', [
            'name' => 'Alice Example',
            'email' => 'alice@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $registerResponse->assertStatus(201)
            ->assertJsonPath('user.email', 'alice@example.com')
            ->assertJsonPath('user.name', 'Alice Example');

        $this->assertDatabaseHas('users', [
            'email' => 'alice@example.com',
        ]);
        Notification::assertSentTo(User::where('email', 'alice@example.com')->first(), VerifyEmail::class);

        $loginResponse = $this->postJson('/api/login', [
            'email' => 'alice@example.com',
            'password' => 'secret123',
        ]);

        $loginResponse->assertStatus(200)
            ->assertJsonPath('user.email', 'alice@example.com')
            ->assertJsonPath('message', 'User logged in successfully.');
    }

    public function test_authenticated_user_can_create_organization_and_list_it(): void
    {
        $this->seed(RoleSeeder::class);

        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $createResponse = $this->withToken($token)->postJson('/api/organizations', [
            'name' => 'Acme Studio',
        ]);

        $createResponse->assertStatus(201)
            ->assertJsonPath('organization.name', 'Acme Studio')
            ->assertJsonPath('organization.role', 'organization_owner');

        $this->assertDatabaseHas('organizations', [
            'name' => 'Acme Studio',
        ]);

        $this->assertDatabaseHas('organization_memberships', [
            'user_id' => $user->id,
            'role' => 'organization_owner',
        ]);

        $listResponse = $this->withToken($token)->getJson('/api/organizations');

        $listResponse->assertOk()->assertJsonFragment(['name' => 'Acme Studio']);
    }

    public function test_revoked_organization_membership_cannot_access_organization_or_projects(): void
    {
        $this->seed(RoleSeeder::class);

        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;
        $organization = $this->withToken($token)->postJson('/api/organizations', [
            'name' => 'Revoked Access Test',
        ])->assertCreated()->json('organization');
        $project = $this->withToken($token)->postJson('/api/projects', [
            'organization_id' => $organization['id'],
            'name' => 'Private project',
        ])->assertCreated()->json('project');

        $user->organizationMemberships()->update(['status' => 'revoked']);

        $this->withToken($token)->getJson('/api/organizations')
            ->assertOk()
            ->assertExactJson([]);
        $this->withToken($token)->getJson('/api/organizations/'.$organization['id'])
            ->assertForbidden();
        $this->withToken($token)->getJson('/api/projects')
            ->assertOk()
            ->assertExactJson([]);
        $this->withToken($token)->getJson('/api/projects/'.$project['id'])
            ->assertForbidden();
    }

    public function test_user_without_membership_cannot_access_an_organization(): void
    {
        $this->seed(RoleSeeder::class);

        $owner = User::factory()->create();
        $organization = $this->actingAs($owner, 'sanctum')->postJson('/api/organizations', [
            'name' => 'Private Organization',
        ])->assertCreated()->json('organization');

        $outsider = User::factory()->create();
        $this->actingAs($outsider, 'sanctum')
            ->getJson('/api/organizations/'.$organization['id'])
            ->assertForbidden();
    }
}
