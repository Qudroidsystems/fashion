<?php

namespace App\Services\Sites;

use App\Models\Customer;
use App\Models\FashionStyle;
use App\Models\Product;
use App\Models\SiteEnquiry;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\NewSiteEnquiry;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\Entitlements;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A fashion house's public website (plan feature "website"; "website_full"
 * adds booking and measurement requests and removes platform branding;
 * "custom_domain" lets the house use its own domain).
 */
class SiteService
{
    public function __construct(protected Entitlements $entitlements)
    {
    }

    /** The house for a subdomain slug, if its site is switched on and its plan includes it. */
    public function bySlug(?string $slug): ?Tenant
    {
        if (! $slug || in_array(strtolower($slug), config('sites.reserved', []), true)) {
            return null;
        }
        $tenant = Tenant::where('slug', strtolower($slug))->first();

        return $tenant && $this->isLive($tenant) ? $tenant : null;
    }

    /** The house whose verified own domain this is. */
    public function byDomain(string $host): ?Tenant
    {
        $host = strtolower(preg_replace('/^www\./', '', $host));
        $tenant = Tenant::where('domain', $host)->whereNotNull('domain_verified_at')->first();

        return $tenant && $this->isLive($tenant) && $this->entitlements->has($tenant, 'custom_domain') ? $tenant : null;
    }

    public function isLive(Tenant $tenant): bool
    {
        return (bool) $tenant->setting('website.enabled', false) && $this->entitlements->has($tenant, 'website')
            && ! in_array($tenant->status, ['suspended', 'cancelled'], true);
    }

    public function full(Tenant $tenant): bool
    {
        return $this->entitlements->has($tenant, 'website_full');
    }

    /** The address the house's site is reachable at. */
    public function url(Tenant $tenant): string
    {
        if ($tenant->domain && $tenant->domain_verified_at && $this->entitlements->has($tenant, 'custom_domain')) {
            return 'https://' . $tenant->domain;
        }
        if ($base = config('sites.domain')) {
            return (request()->isSecure() ? 'https://' : 'http://') . $tenant->slug . '.' . $base;
        }

        return url('/s/' . $tenant->slug);
    }

    /* ------------------------------- content ------------------------------- */

    public function styles(Tenant $tenant)
    {
        return FashionStyle::inCatalogue()->with('category', 'currentVersion.images')->orderBy('name')->get();
    }

    public function featured(Tenant $tenant)
    {
        $all = $this->styles($tenant);
        $ids = array_map('intval', (array) $tenant->setting('website.featured_style_ids', []));
        $picked = $ids ? $all->whereIn('id', $ids)->sortBy(fn ($s) => array_search($s->id, $ids))->values() : collect();

        return ($picked->isNotEmpty() ? $picked : $all)->take(6)->values();
    }

    public function products(Tenant $tenant)
    {
        return Product::inCatalogue()->with(['category', 'variants' => fn ($q) => $q->where('is_active', true)])->orderBy('name')->get()
            ->filter(fn ($p) => $p->variants->isNotEmpty())->values();
    }

    /* ------------------------------ enquiries ------------------------------ */

    /**
     * Save a visitor's message. Honeypot, a minimum fill time and a per-IP cap
     * keep spam out; nothing here creates a customer (that is a staff decision).
     */
    public function enquire(Tenant $tenant, array $data, Request $request): SiteEnquiry
    {
        if (! empty($data['website']) || (isset($data['_t']) && (int) $data['_t'] > 0 && time() - (int) $data['_t'] < 3)) {
            throw ValidationException::withMessages(['message' => 'Please try again in a moment.']);
        }
        $type = $data['type'] ?? 'enquiry';
        if ($type !== 'enquiry' && ! $this->full($tenant)) {
            $type = 'enquiry';
        }
        $key = 'site-enquiry:' . $tenant->id . ':' . $request->ip();
        if (Cache::get($key, 0) >= 5) {
            throw ValidationException::withMessages(['message' => 'You have sent a few messages already — the house will be in touch soon.']);
        }

        return DB::transaction(function () use ($tenant, $data, $type, $request, $key) {
            $enquiry = SiteEnquiry::create([
                'type' => $type, 'name' => Str::limit(trim($data['name']), 120, ''), 'phone' => $data['phone'] ?? null,
                'email' => isset($data['email']) ? mb_strtolower(trim($data['email'])) : null, 'message' => $data['message'] ?? null,
                'style_id' => ! empty($data['style']) ? FashionStyle::inCatalogue()->where('public_id', $data['style'])->value('id') : null,
                'product_id' => ! empty($data['product']) ? Product::inCatalogue()->where('public_id', $data['product'])->value('id') : null,
                'preferred_date' => $type === 'booking' ? ($data['preferred_date'] ?? null) : null,
                'preferred_time' => $type === 'booking' ? ($data['preferred_time'] ?? null) : null,
                'ip' => $request->ip(), 'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            ]);
            Cache::put($key, Cache::get($key, 0) + 1, now()->addHour());
            $staff = User::inCurrentTenant()->get()->filter(fn (User $u) => $u->can('Create customer'));
            DB::afterCommit(fn () => $staff->each(fn (User $u) => $u->notify(new NewSiteEnquiry($enquiry))));

            return $enquiry;
        });
    }

    /** Staff turn an enquiry into a customer (counts toward the plan's customer limit). */
    public function convert(SiteEnquiry $enquiry, User $by): Customer
    {
        if ($enquiry->customer_id) {
            return $enquiry->customer;
        }

        return DB::transaction(function () use ($enquiry, $by) {
            $phone = Customer::normalizePhone($enquiry->phone);
            $customer = ($phone ? Customer::active()->where('phone_normalized', $phone)->first() : null)
                ?? ($enquiry->email ? Customer::active()->where('email', $enquiry->email)->first() : null);
            if (! $customer) {
                $parts = preg_split('/\s+/', trim($enquiry->name), 2);
                $customer = Customer::create(['first_name' => $parts[0], 'last_name' => $parts[1] ?? null, 'phone' => $enquiry->phone,
                    'email' => $enquiry->email, 'source' => 'website', 'created_by' => $by->id]);
                AuditLogger::log('customer.created', $customer, description: 'From a website enquiry');
            }
            $enquiry->update(['status' => 'converted', 'customer_id' => $customer->id, 'handled_by' => $by->id, 'handled_at' => now()]);

            return $customer;
        });
    }

    /* ------------------------------ own domain ------------------------------ */

    /** Does the domain point here (CNAME to the target, or A record to the server)? */
    public function checkDns(Tenant $tenant): array
    {
        $domain = $tenant->domain;
        if (! $domain) {
            return [false, 'No domain set.'];
        }
        $target = rtrim((string) config('sites.cname_target'), '.');
        $ip = config('sites.server_ip');
        $cnames = collect(@dns_get_record($domain, DNS_CNAME) ?: [])->pluck('target')->map(fn ($t) => rtrim(strtolower($t), '.'));
        $as = collect(@dns_get_record($domain, DNS_A) ?: [])->pluck('ip');
        $ok = ($target && $cnames->contains(strtolower($target))) || ($ip && $as->contains($ip));
        if ($ok) {
            $tenant->forceFill(['domain_verified_at' => now()])->save();
            AuditLogger::log('website.domain_verified', $tenant, after: ['domain' => $domain]);

            return [true, "{$domain} points here and is now live."];
        }

        return [false, "{$domain} does not point here yet. DNS changes can take a few hours to spread."];
    }
}
