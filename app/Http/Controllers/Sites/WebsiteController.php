<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\FashionStyle;
use App\Models\SiteEnquiry;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\Entitlements;
use App\Services\Sites\SiteService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Settings › Website, and the inbox of messages sent from it. */
class WebsiteController extends Controller
{
    public function __construct(protected SiteService $sites, protected Entitlements $entitlements)
    {
        $this->middleware('permission:Manage tenant settings', ['only' => ['edit', 'update', 'checkDomain']]);
        $this->middleware('permission:View customer', ['only' => ['enquiries', 'updateEnquiry']]);
        $this->middleware('permission:Create customer', ['only' => ['convert']]);
    }

    public function edit(): View
    {
        $tenant = CurrentTenant::require();

        return view('sites.settings', [
            'pagetitle' => 'Website',
            'tenant'    => $tenant,
            'site'      => (array) $tenant->setting('website', []),
            'url'       => $this->sites->url($tenant),
            'live'      => $this->sites->isLive($tenant),
            'allowed'   => $this->entitlements->has($tenant, 'website'),
            'full'      => $this->sites->full($tenant),
            'canDomain' => $this->entitlements->has($tenant, 'custom_domain'),
            'styles'    => FashionStyle::inCatalogue()->orderBy('name')->get(['id', 'name']),
            'subdomains' => (bool) config('sites.domain'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $tenant = CurrentTenant::require();
        abort_unless($this->entitlements->has($tenant, 'website'), 403, $this->entitlements->upgradeMessage($tenant, 'website'));
        $data = $request->validate([
            'enabled'      => 'nullable|boolean',
            'slug'         => ['required', 'string', 'min:3', 'max:40', 'regex:/^[a-z0-9][a-z0-9-]*[a-z0-9]$/', Rule::notIn(config('sites.reserved', [])),
                Rule::unique('tenants', 'slug')->ignore($tenant->id)],
            'headline'     => 'nullable|string|max:120',
            'tagline'      => 'nullable|string|max:200',
            'about'        => 'nullable|string|max:3000',
            'hours'        => 'nullable|string|max:300',
            'whatsapp'     => 'nullable|string|max:30',
            'instagram'    => 'nullable|string|max:60',
            'tiktok'       => 'nullable|string|max:60',
            'facebook'     => 'nullable|string|max:100',
            'show_prices'  => 'nullable|boolean',
            'featured'     => 'nullable|array|max:6',
            'featured.*'   => 'integer',
            'hero'         => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
            'remove_hero'  => 'nullable|boolean',
            'domain'       => ['nullable', 'string', 'max:190', 'regex:/^(?!-)[a-z0-9-]+(\.[a-z0-9-]+)+$/i', Rule::unique('tenants', 'domain')->ignore($tenant->id)],
        ], [
            'slug.regex' => 'Use lower-case letters, numbers and dashes only, e.g. adunni-couture.',
            'slug.not_in' => 'That address is reserved. Please choose another.',
            'domain.regex' => 'Enter a domain like adunnicouture.com (no https:// or slashes).',
        ]);
        $before = ['slug' => $tenant->slug, 'domain' => $tenant->domain, 'website' => $tenant->setting('website')];

        $site = (array) $tenant->setting('website', []);
        if ($request->boolean('remove_hero') && ! empty($site['hero_path'])) {
            Storage::disk('public')->delete($site['hero_path']);
            $site['hero_path'] = null;
        }
        if ($request->hasFile('hero')) {
            if (! empty($site['hero_path'])) {
                Storage::disk('public')->delete($site['hero_path']);
            }
            $site['hero_path'] = $request->file('hero')->storeAs('tenants/' . $tenant->public_id . '/site', 'hero-' . Str::random(6) . '.' . $request->file('hero')->extension(), 'public');
        }
        foreach (['headline', 'tagline', 'about', 'hours', 'whatsapp', 'instagram', 'tiktok', 'facebook'] as $k) {
            $site[$k] = $data[$k] ?? null;
        }
        $site['enabled'] = $request->boolean('enabled');
        $site['show_prices'] = $request->boolean('show_prices');
        $site['featured_style_ids'] = FashionStyle::inCatalogue()->whereIn('id', $data['featured'] ?? [])->pluck('id')->all();

        $domain = $this->entitlements->has($tenant, 'custom_domain') && ! empty($data['domain']) ? strtolower(preg_replace('/^www\./i', '', $data['domain'])) : null;
        $tenant->fill(['slug' => $data['slug'], 'settings' => array_merge($tenant->settings ?? [], ['website' => $site])]);
        if ($domain !== $tenant->domain) {
            $tenant->domain = $domain;
            $tenant->domain_verified_at = null;
        }
        $tenant->save();
        AuditLogger::log('website.updated', $tenant, $before, ['slug' => $tenant->slug, 'domain' => $tenant->domain, 'enabled' => $site['enabled']]);

        return back()->with('success', $site['enabled'] ? 'Website saved and live.' : 'Website saved. Switch it on when you are ready.');
    }

    public function checkDomain(): RedirectResponse
    {
        [$ok, $message] = $this->sites->checkDns(CurrentTenant::require());

        return back()->with($ok ? 'success' : 'warning', $message);
    }

    /* ------------------------------ enquiries ------------------------------ */

    public function enquiries(Request $request): View
    {
        $status = $request->input('status', 'open');

        return view('sites.enquiries', [
            'pagetitle' => 'Website enquiries',
            'enquiries' => SiteEnquiry::with('style:id,public_id,name', 'product:id,public_id,name', 'customer:id,public_id,first_name,last_name', 'handler:id,name')
                ->when($status === 'open', fn ($q) => $q->whereIn('status', ['new', 'contacted']))
                ->when(in_array($status, array_keys(SiteEnquiry::STATUSES), true), fn ($q) => $q->where('status', $status))
                ->latest('id')->paginate(25)->withQueryString(),
            'status'    => $status,
            'counts'    => SiteEnquiry::selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status'),
            'tz'        => CurrentTenant::get()->timezone,
            'siteUrl'   => $this->sites->url(CurrentTenant::get()),
        ]);
    }

    public function updateEnquiry(Request $request, SiteEnquiry $enquiry): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['new', 'contacted', 'closed', 'spam'])]]);
        $enquiry->update(['status' => $data['status'], 'handled_by' => $request->user()->id, 'handled_at' => now()]);

        return back()->with('success', 'Marked as ' . strtolower($enquiry->status_label) . '.');
    }

    public function convert(Request $request, SiteEnquiry $enquiry): RedirectResponse
    {
        $customer = $this->sites->convert($enquiry, $request->user());

        return redirect()->route('customers.show', $customer)->with('success', "{$customer->name} is now a customer.");
    }
}
