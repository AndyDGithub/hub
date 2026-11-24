<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\JwtService;
use App\Services\PasswordService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function userWithRole(string $role, string $password = 'correct-horse-battery'): User
    {
        $user = User::query()->create([
            'name' => $role,
            'email' => "{$role}@example.test",
            'password' => app(PasswordService::class)->hash($password),
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function auth(User $user): array
    {
        return ['Authorization' => 'Bearer '.app(JwtService::class)->issueToken($user)];
    }

    // --- Création de comptes -------------------------------------------------

    public function test_public_registration_no_longer_exists(): void
    {
        $this->postJson('/api/auth/register', [])->assertNotFound();
    }

    public function test_admin_creates_user_and_gets_temporary_password_once(): void
    {
        $admin = $this->userWithRole('admin');

        $response = $this->postJson('/api/users', [
            'name' => 'Alice', 'email' => 'alice@example.test', 'role' => 'maintainer',
        ], $this->auth($admin));

        $response->assertCreated()->assertJsonStructure(['temporary_password', 'temporary_password_expires_at']);
        $alice = User::query()->where('email', 'alice@example.test')->firstOrFail();
        $this->assertTrue($alice->hasRole('maintainer'));
        $this->assertTrue($alice->must_change_password);
        // Le mot de passe en clair n'est jamais stocké, seulement son hash.
        $this->assertNotSame($response->json('temporary_password'), $alice->password);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.created', 'auditable_id' => $alice->id, 'user_id' => $admin->id]);
    }

    public function test_maintainer_can_create_reader_but_not_admin(): void
    {
        $maintainer = $this->userWithRole('maintainer');

        $this->postJson('/api/users', ['name' => 'R', 'email' => 'r@example.test', 'role' => 'reader'], $this->auth($maintainer))
            ->assertCreated();
        $this->postJson('/api/users', ['name' => 'X', 'email' => 'x@example.test', 'role' => 'admin'], $this->auth($maintainer))
            ->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->assertDatabaseMissing('users', ['email' => 'x@example.test']);
    }

    public function test_reader_and_anonymous_cannot_create_users(): void
    {
        $reader = $this->userWithRole('reader');
        $payload = ['name' => 'Y', 'email' => 'y@example.test', 'role' => 'reader'];

        $this->postJson('/api/users', $payload, $this->auth($reader))->assertForbidden();
        $this->postJson('/api/users', $payload)->assertUnauthorized();
        $this->assertDatabaseMissing('users', ['email' => 'y@example.test']);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        $admin = $this->userWithRole('admin');

        $this->postJson('/api/users', ['name' => 'A', 'email' => 'admin@example.test', 'role' => 'reader'], $this->auth($admin))
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    // --- Mot de passe temporaire ---------------------------------------------

    private function createInvitee(): array
    {
        $admin = $this->userWithRole('admin');
        $response = $this->postJson('/api/users', [
            'name' => 'Bob', 'email' => 'bob@example.test', 'role' => 'reader',
        ], $this->auth($admin));

        return [User::query()->where('email', 'bob@example.test')->firstOrFail(), $response->json('temporary_password')];
    }

    public function test_temporary_password_must_be_changed_before_anything_else(): void
    {
        [, $temporary] = $this->createInvitee();

        $login = $this->postJson('/api/auth/login', ['email' => 'bob@example.test', 'password' => $temporary])
            ->assertOk()->assertJson(['must_change_password' => true]);
        $headers = ['Authorization' => 'Bearer '.$login->json('access_token')];

        $this->getJson('/api/me', $headers)->assertForbidden()->assertJson(['must_change_password' => true]);

        $this->postJson('/api/auth/password', [
            'current_password' => $temporary,
            'new_password' => 'a-brand-new-password',
            'new_password_confirmation' => 'a-brand-new-password',
        ], $headers)->assertOk();

        $relogin = $this->postJson('/api/auth/login', ['email' => 'bob@example.test', 'password' => 'a-brand-new-password'])
            ->assertOk()->assertJson(['must_change_password' => false]);
        $this->getJson('/api/me', ['Authorization' => 'Bearer '.$relogin->json('access_token')])->assertOk();
    }

    public function test_expired_temporary_password_is_refused(): void
    {
        [$bob, $temporary] = $this->createInvitee();
        $this->travel((int) config('hub.passwords.temporary_ttl_hours') + 1)->hours();

        $this->postJson('/api/auth/login', ['email' => $bob->email, 'password' => $temporary])->assertForbidden();
    }

    // --- Commande console ----------------------------------------------------

    public function test_console_command_creates_first_admin(): void
    {
        $this->artisan('hub:user:create', ['email' => 'andy@example.test', '--name' => 'Andy', '--role' => 'admin'])
            ->expectsOutputToContain('Mot de passe temporaire')
            ->assertSuccessful();

        $this->assertTrue(User::query()->where('email', 'andy@example.test')->firstOrFail()->hasRole('admin'));
    }

    public function test_console_command_rejects_unknown_role_without_writing(): void
    {
        $this->artisan('hub:user:create', ['email' => 'z@example.test', '--role' => 'superuser'])->assertFailed();
        $this->assertDatabaseMissing('users', ['email' => 'z@example.test']);
    }
}
