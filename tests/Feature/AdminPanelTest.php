<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\Platform;
use App\Models\User;
use App\Observers\AuditObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create(['role' => 'owner', 'is_active' => true]);
    }

    private function editor(): User
    {
        return User::factory()->create(['role' => 'editor', 'is_active' => true]);
    }

    public function test_admin_login_page_is_reachable(): void
    {
        $this->get('/admin/login')->assertOk();
    }

    public function test_guest_cannot_reach_the_panel(): void
    {
        $this->get('/admin')->assertRedirect();
    }

    public function test_admin_routes_always_send_noindex(): void
    {
        // /admin 必須無條件 noindex，即使日後正式前台開放索引。
        $this->get('/admin/login')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_active_roles_may_access_the_panel(): void
    {
        $panel = filament()->getPanel('admin');

        $this->assertTrue($this->owner()->canAccessPanel($panel));
        $this->assertTrue($this->editor()->canAccessPanel($panel));
    }

    public function test_inactive_or_unknown_role_users_cannot_access_the_panel(): void
    {
        $panel = filament()->getPanel('admin');

        $inactive = User::factory()->create(['role' => 'owner', 'is_active' => false]);
        $stranger = User::factory()->create(['role' => 'subscriber', 'is_active' => true]);

        $this->assertFalse($inactive->canAccessPanel($panel));
        $this->assertFalse($stranger->canAccessPanel($panel));
    }

    public function test_only_owner_may_publish(): void
    {
        $platform = Platform::factory()->create();

        $this->assertTrue($this->owner()->can('publish', $platform));
        $this->assertFalse($this->editor()->can('publish', $platform));
    }

    public function test_slug_is_locked_after_first_publish(): void
    {
        $owner = $this->owner();

        $draft = Platform::factory()->create(['first_published_at' => null]);
        $published = Platform::factory()->create(['first_published_at' => now()]);

        $this->assertTrue($owner->can('updateSlug', $draft));
        $this->assertFalse($owner->can('updateSlug', $published));
        $this->assertFalse($this->editor()->can('updateSlug', $draft));
    }

    public function test_nobody_can_hard_delete_content(): void
    {
        $platform = Platform::factory()->create();

        $this->assertFalse($this->owner()->can('forceDelete', $platform));
        $this->assertFalse($this->editor()->can('forceDelete', $platform));
    }

    public function test_audit_log_is_owner_read_only(): void
    {
        $log = AdminAuditLog::create([
            'auditable_type' => Platform::class,
            'auditable_id' => 1,
            'action' => 'created',
        ]);

        $this->assertTrue($this->owner()->can('viewAny', AdminAuditLog::class));
        $this->assertFalse($this->editor()->can('viewAny', AdminAuditLog::class));
        $this->assertFalse($this->owner()->can('delete', $log));
        $this->assertFalse($this->owner()->can('update', $log));
    }

    public function test_content_changes_are_recorded_to_the_audit_log(): void
    {
        $platform = Platform::factory()->create();

        $this->assertDatabaseHas('admin_audit_logs', [
            'auditable_type' => Platform::class,
            'auditable_id' => $platform->id,
            'action' => 'created',
        ]);
    }

    public function test_audit_log_never_stores_secrets(): void
    {
        $platform = Platform::factory()->create();
        $platform->update(['name' => 'Renamed', 'meta_description' => 'x']);

        // 模擬含機密欄位的 payload 進入 redaction。
        $log = AdminAuditLog::create([
            'auditable_type' => Platform::class,
            'auditable_id' => $platform->id,
            'action' => 'updated',
            'after' => ['password' => 'super-secret', 'api_token' => 'abc123', 'name' => 'ok'],
        ]);

        $observer = new AuditObserver;
        $method = new \ReflectionMethod($observer, 'redact');
        $redacted = $method->invoke($observer, $log->after);

        $this->assertSame('[redacted]', $redacted['password']);
        $this->assertSame('[redacted]', $redacted['api_token']);
        $this->assertSame('ok', $redacted['name']);
    }

    public function test_owner_creation_command_is_registered_and_never_takes_a_password_argument(): void
    {
        $commands = Artisan::all();

        $this->assertArrayHasKey('iglf:create-owner', $commands);

        $definition = $commands['iglf:create-owner']->getDefinition();
        $this->assertFalse($definition->hasArgument('password'));
        $this->assertFalse($definition->hasOption('password'));
    }
}
