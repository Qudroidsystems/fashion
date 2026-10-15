<?php

namespace App\Services\Catalogue;

use App\Models\CatalogueCategory;
use App\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;

/** Starter catalogue categories for a new fashion house. Safe to run again. */
class CatalogueStarter
{
    public static function install(Tenant $tenant): void
    {
        CurrentTenant::run($tenant, function () {
            foreach (CatalogueCategory::STARTER as $i => $name) {
                CatalogueCategory::firstOrCreate(['name' => $name], ['sort_order' => $i]);
            }
        });
    }
}
