<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST /admin/users (create_users): Super Admin creates any role; staff
 * granted create_users create only roles below their own.
 */
class AdminCreateUserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function account(string $role): User
    {
        $u = User::create([
            'name' => ucfirst($role) . ' ' . Str::random(4),
            'email' => $role . Str::random(6) . '@example.com',
            'password' => Hash::make('V3r1fy!Strong'),
        ]);
        $u->forceFill(['role' => $role, 'status' => 'active', 'email_verified_at' => now()])->save();

        return $u;
    }

    private function payload(string $role, array $extra = []): array
    {
        return array_merge([
            'role' => $role,
            'name' => 'New ' . $role,
            'email' => 'new-' . $role . Str::random(5) . '@example.com',
            'password' => 'V3r1fy!Strong1',
        ], $extra);
    }

    public function test_superadmin_creates_every_role(): void
    {
        Sanctum::actingAs($this->account('superadmin'));

        foreach (['contributor', 'moderator', 'admin', 'superadmin'] as $role) {
            $res = $this->postJson('/api/v1/admin/users', $this->payload($role))->assertCreated();
            $user = User::findOrFail($res->json('data.id'));
            $this->assertSame($role, $user->role);
            $this->assertSame('active', $user->status);
            $this->assertNotNull($user->email_verified_at);
        }

        $res = $this->postJson('/api/v1/admin/users', $this->payload('business', ['company_name' => 'Acme']))->assertCreated();
        $this->assertSame('Acme', User::findOrFail($res->json('data.id'))->business->company_name);
    }

    public function test_business_requires_company_name(): void
    {
        Sanctum::actingAs($this->account('superadmin'));
        $this->postJson('/api/v1/admin/users', $this->payload('business'))->assertStatus(422);
    }

    public function test_admin_without_permission_is_refused(): void
    {
        Sanctum::actingAs($this->account('admin'));
        $this->postJson('/api/v1/admin/users', $this->payload('contributor'))->assertForbidden();
    }

    public function test_admin_granted_permission_creates_only_lower_roles(): void
    {
        Role::where('name', 'admin')->firstOrFail()->permissions()
            ->attach(Permission::where('name', Permission::CREATE_USERS)->value('id'));
        Sanctum::actingAs($this->account('admin'));

        $this->postJson('/api/v1/admin/users', $this->payload('moderator'))->assertCreated();
        $this->postJson('/api/v1/admin/users', $this->payload('admin'))->assertForbidden();
        $this->postJson('/api/v1/admin/users', $this->payload('superadmin'))->assertForbidden();
    }
}
