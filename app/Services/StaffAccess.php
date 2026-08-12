<?php

namespace App\Services;

use App\Models\Business;
use App\Models\User;

class StaffAccess
{
    public const STAFF_ROLES = ['admin', 'super_admin', 'sub_admin'];

    public const ELEVATED_ROLES = ['admin', 'super_admin'];

    public static function isStaff(?User $user): bool
    {
        return $user !== null && in_array($user->role, self::STAFF_ROLES, true);
    }

    public static function isElevated(?User $user): bool
    {
        return $user !== null && in_array($user->role, self::ELEVATED_ROLES, true);
    }

    public static function isSuper(?User $user): bool
    {
        return $user !== null && $user->role === 'super_admin';
    }

    /**
     * Role claim sent to the Project/FastAPI layer.
     * Sub-admins act as admin there so RAG and user sync keep working.
     */
    public static function projectApiRole(User $user): string
    {
        if ($user->role === 'sub_admin') {
            return 'admin';
        }

        return $user->role ?: 'user';
    }

    /**
     * Parent admin id used for business scoping (sub_admin → creator).
     */
    public static function scopeOwnerId(User $actor): ?string
    {
        if ($actor->role === 'sub_admin') {
            return $actor->created_by ?: null;
        }

        if ($actor->role === 'admin') {
            return $actor->id;
        }

        return null;
    }

    public static function canAccessBusiness(User $actor, Business $business): bool
    {
        if (self::isSuper($actor)) {
            return true;
        }

        $ownerId = self::scopeOwnerId($actor);
        if (! $ownerId) {
            return false;
        }

        return (string) $business->admin_id === (string) $ownerId;
    }

    /**
     * Whether the actor may create / update / delete / activate the target account.
     */
    public static function canManageTarget(User $actor, User $target): bool
    {
        if (! self::isStaff($actor)) {
            return false;
        }

        if ((string) $actor->id === (string) $target->id) {
            return false;
        }

        // Sub-admins may only manage site users.
        if ($actor->role === 'sub_admin') {
            if ($target->role !== 'user') {
                return false;
            }

            return self::targetInActorScope($actor, $target);
        }

        // Regular admins cannot manage other admins or super_admins.
        if ($actor->role === 'admin') {
            if (in_array($target->role, ['admin', 'super_admin'], true)) {
                return false;
            }

            if ($target->role === 'sub_admin') {
                return (string) $target->created_by === (string) $actor->id;
            }

            if ($target->role === 'user') {
                return self::targetInActorScope($actor, $target);
            }

            return false;
        }

        // super_admin
        return true;
    }

    public static function canCreateSubAdmin(User $actor): bool
    {
        return self::isElevated($actor);
    }

    public static function canCreateSiteUser(User $actor): bool
    {
        return self::isStaff($actor);
    }

    private static function targetInActorScope(User $actor, User $target): bool
    {
        if ($target->role !== 'user') {
            return false;
        }

        if (self::isSuper($actor)) {
            return true;
        }

        $ownerId = self::scopeOwnerId($actor);
        if (! $ownerId || ! $target->business_id) {
            return false;
        }

        $business = Business::query()->find($target->business_id);

        return $business && (string) $business->admin_id === (string) $ownerId;
    }
}
