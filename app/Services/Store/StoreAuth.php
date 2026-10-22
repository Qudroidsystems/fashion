<?php

namespace App\Services\Store;

use App\Models\StoreAccount;
use App\Models\Tenant;

/**
 * Shopper sign-in, kept apart from staff logins: one session key per house,
 * so being signed in to one house's shop says nothing about another's.
 */
class StoreAuth
{
    protected array $cache = [];

    public static function key(Tenant $tenant): string
    {
        return 'store_account_' . $tenant->id;
    }

    public function account(Tenant $tenant): ?StoreAccount
    {
        if (array_key_exists($tenant->id, $this->cache)) {
            return $this->cache[$tenant->id];
        }
        $id = session(self::key($tenant));
        $account = $id ? StoreAccount::withoutTenantScope()->where('tenant_id', $tenant->id)->where('status', 'active')->find($id) : null;

        return $this->cache[$tenant->id] = $account;
    }

    public function login(Tenant $tenant, StoreAccount $account): void
    {
        session()->regenerate();
        session([self::key($tenant) => $account->id]);
        $account->forceFill(['last_login_at' => now()])->save();
        $this->cache[$tenant->id] = $account;
    }

    public function logout(Tenant $tenant): void
    {
        session()->forget(self::key($tenant));
        unset($this->cache[$tenant->id]);
    }
}
