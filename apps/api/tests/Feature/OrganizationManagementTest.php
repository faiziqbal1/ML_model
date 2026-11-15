<?php

namespace Tests\Feature;

use App\Models\OrganizationInvitation;
use App\Models\Organization;
use App\Models\Dataset;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrganizationManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_initial_organization_requires_verified_email_and_is_created_only_once(): void
    {
        Notification::fake();

        $registration = $this->postJson('/api/register', [
            'name' => 'New Owner',
            'email' => 'owner@example.test',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertCreated();

        $user = User::query()->where('email', 'owner@example.test')->firstOrFail();
        $token = $registration->json('token');

        Notification::assertSentTo($user, VerifyEmail::class);
        $this->withToken($token)->postJson('/api/organizations', ['name' => 'First Company'])
            ->assertForbidden();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addHour(),
            ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())],
        );
        $this->get($verificationUrl)->assertRedirect('http://localhost:5174/?verified=1');
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->postJson('/api/organizations', ['name' => 'First Company'])
            ->assertCreated()
            ->assertJsonPath('organization.role', 'organization_owner');
        $this->withToken($token)->postJson('/api/organizations', ['name' => 'Duplicate Company'])
            ->assertConflict();
    }

    public function test_company_profile_and_user_management_enforce_roles_and_organization_scope(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $ownerToken = $owner->createToken('test-token')->plainTextToken;
        $organization = $this->withToken($ownerToken)->postJson('/api/organizations', [
            'name' => 'Company One',
        ])->assertCreated()->json('organization');

        $admin = User::factory()->create();
        $member = User::factory()->create();
        $adminMembership = $admin->organizationMemberships()->create([
            'organization_id' => $organization['id'],
            'role' => 'organization_admin',
            'status' => 'active',
        ]);
        $memberMembership = $member->organizationMemberships()->create([
            'organization_id' => $organization['id'],
            'role' => 'organization_member',
            'status' => 'active',
        ]);

        $this->actingAs($owner, 'sanctum')->patchJson('/api/organizations/'.$organization['id'], [
            'industry' => 'Technology',
            'timezone' => 'America/New_York',
        ])->assertOk()->assertJsonPath('organization.industry', 'Technology');
        $this->actingAs($admin, 'sanctum')->patchJson('/api/organizations/'.$organization['id'], [
            'description' => 'Managed by an administrator.',
        ])->assertOk();
        $this->actingAs($member, 'sanctum')->patchJson('/api/organizations/'.$organization['id'], [
            'description' => 'Unauthorized change.',
        ])->assertForbidden();

        $this->actingAs($member, 'sanctum')->getJson('/api/organizations/'.$organization['id'].'/users')
            ->assertOk()
            ->assertJsonCount(3);
        $this->actingAs($member, 'sanctum')->postJson('/api/projects', [
            'organization_id' => $organization['id'],
            'name' => 'Not allowed',
        ])->assertForbidden();
        $this->actingAs($admin, 'sanctum')->patchJson(
            '/api/organizations/'.$organization['id'].'/memberships/'.$adminMembership->id,
            ['role' => 'organization_admin'],
        )->assertForbidden();

        $this->actingAs($admin, 'sanctum')->postJson('/api/organizations/'.$organization['id'].'/invitations', [
            'email' => 'invitee@example.test',
            'role' => 'organization_member',
        ])->assertCreated();
        Notification::assertSentOnDemand(OrganizationInvitationNotification::class);
        $this->actingAs($admin, 'sanctum')->postJson('/api/organizations/'.$organization['id'].'/invitations', [
            'email' => 'another@example.test',
            'role' => 'organization_admin',
        ])->assertForbidden();

        $otherOwner = User::factory()->create();
        $otherOrganization = $this->actingAs($otherOwner, 'sanctum')->postJson('/api/organizations', [
            'name' => 'Company Two',
        ])->assertCreated()->json('organization');
        $outsider = User::factory()->create();
        $outsider->organizationMemberships()->create([
            'organization_id' => $otherOrganization['id'],
            'role' => 'organization_member',
            'status' => 'active',
        ]);
        $project = $this->actingAs($owner, 'sanctum')->postJson('/api/projects', [
            'organization_id' => $organization['id'],
            'name' => 'Private project',
        ])->assertCreated()->json('project');
        $dataset = Dataset::query()->create([
            'organization_id' => $organization['id'],
            'project_id' => $project['id'],
            'name' => 'Private dataset',
            'file_path' => 'datasets/private.csv',
            'status' => 'uploaded',
        ]);

        $this->actingAs($outsider, 'sanctum')->getJson('/api/organizations/'.$organization['id'].'/users')
            ->assertForbidden();
        $this->actingAs($member, 'sanctum')->getJson('/api/organizations/'.$organization['id'].'/users')
            ->assertOk()
            ->assertJsonMissing(['email' => $otherOwner->email]);
        $this->actingAs($outsider, 'sanctum')->getJson('/api/projects/'.$project['id'])->assertForbidden();
        $this->actingAs($outsider, 'sanctum')->getJson('/api/projects/'.$project['id'].'/jobs')->assertForbidden();
        $this->actingAs($outsider, 'sanctum')->postJson('/api/projects/'.$project['id'].'/train')->assertForbidden();
        $this->actingAs($outsider, 'sanctum')->getJson('/api/datasets')->assertExactJson([]);
        $this->actingAs($outsider, 'sanctum')->getJson('/api/datasets/'.$dataset->id)->assertForbidden();
        $this->actingAs($outsider, 'sanctum')->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('summary.organization_count', 1)
            ->assertJsonPath('summary.project_count', 0);

        $this->actingAs($owner, 'sanctum')->patchJson(
            '/api/organizations/'.$organization['id'].'/memberships/'.$memberMembership->id,
            ['role' => 'organization_owner'],
        )->assertOk();
        $this->assertDatabaseHas('organization_memberships', [
            'id' => $memberMembership->id,
            'role' => 'organization_owner',
        ]);
        $this->assertDatabaseHas('organization_memberships', [
            'user_id' => $owner->id,
            'role' => 'organization_admin',
        ]);
        $this->actingAs($member, 'sanctum')->patchJson('/api/organizations/'.$organization['id'].'/settings', [
            'registration_policy' => 'invite_only',
        ])->assertOk();
        $this->actingAs($owner, 'sanctum')->patchJson('/api/organizations/'.$organization['id'].'/settings', [
            'registration_policy' => 'open',
        ])->assertForbidden();
        $this->assertDatabaseHas('organization_memberships', [
            'id' => $memberMembership->id,
            'status' => 'active',
        ]);
    }

    public function test_invitation_requires_matching_verified_email_and_single_use_token(): void
    {
        $organization = Organization::query()->create([
            'name' => 'Invitation Company',
            'slug' => 'invitation-company',
            'status' => 'active',
            'registration_policy' => 'open',
        ]);
        $invitationToken = Str::random(64);
        $invitation = OrganizationInvitation::query()->create([
            'organization_id' => $organization->id,
            'email' => 'invitee@example.test',
            'role' => 'organization_member',
            'status' => 'pending',
            'token_hash' => hash('sha256', $invitationToken),
            'expires_at' => now()->addDay(),
        ]);

        $invitee = User::factory()->unverified()->create(['email' => 'invitee@example.test']);
        $inviteeToken = $invitee->createToken('test-token')->plainTextToken;
        $this->withToken($inviteeToken)->postJson('/api/invitations/'.$invitation->id.'/accept', [
            'token' => $invitationToken,
        ])->assertForbidden();

        $invitee->markEmailAsVerified();
        $this->app['auth']->forgetGuards();
        $this->withToken($inviteeToken)->postJson('/api/invitations/'.$invitation->id.'/accept', [
            'token' => Str::random(64),
        ])->assertForbidden();

        $this->withToken($inviteeToken)->postJson('/api/invitations/'.$invitation->id.'/accept', [
            'token' => $invitationToken,
        ])->assertOk();
        $this->assertDatabaseHas('organization_memberships', [
            'organization_id' => $organization->id,
            'user_id' => $invitee->id,
            'role' => 'organization_member',
            'status' => 'active',
        ]);
        $this->withToken($inviteeToken)->postJson('/api/invitations/'.$invitation->id.'/accept', [
            'token' => $invitationToken,
        ])->assertStatus(409);

        $rejectee = User::factory()->create(['email' => 'rejectee@example.test']);
        $rejectToken = Str::random(64);
        $rejection = OrganizationInvitation::query()->create([
            'organization_id' => $organization->id,
            'email' => $rejectee->email,
            'role' => 'organization_member',
            'status' => 'pending',
            'token_hash' => hash('sha256', $rejectToken),
            'expires_at' => now()->addDay(),
        ]);
        $this->actingAs($rejectee, 'sanctum')->postJson('/api/invitations/'.$rejection->id.'/reject', [
            'token' => $rejectToken,
        ])->assertOk();
        $this->assertDatabaseHas('organization_invitations', [
            'id' => $rejection->id,
            'status' => 'rejected',
        ]);
        $this->assertDatabaseMissing('organization_memberships', [
            'organization_id' => $organization->id,
            'user_id' => $rejectee->id,
        ]);
    }
}
