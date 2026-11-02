<?php

namespace Feeder\Core\Tests\Unit\Authorization\Services;

use Feeder\Core\Authorization\Services\PermissionService;
use Feeder\Core\Enums\UserType;
use Feeder\Core\Models\User;
use PHPUnit\Framework\TestCase;

class PermissionServiceSuperAdminBypassTest extends TestCase
{
    public function test_super_admin_has_settlement_and_other_permissions_without_role_grants(): void
    {
        $service = new PermissionService();
        $user = new User();
        $user->user_type = UserType::SUPER_ADMIN->value;

        $this->assertTrue($service->hasPermission($user, 'settlements.suppliers.view'));
        $this->assertTrue($service->hasPermission($user, 'settlements.suppliers.lock'));
        $this->assertTrue($service->hasAnyPermission($user, ['settlements.suppliers.view']));
        $this->assertTrue($service->hasAllPermissions($user, [
            'settlements.suppliers.view',
            'settlements.suppliers.payments.record',
        ]));
    }

    public function test_super_admin_has_any_permission_requires_non_empty_list(): void
    {
        $service = new PermissionService();
        $user = new User();
        $user->user_type = UserType::SUPER_ADMIN->value;

        $this->assertFalse($service->hasAnyPermission($user, []));
    }
}
