<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\ProductReview;
use App\Models\ShippingZone;
use App\Models\StoreAccount;
use App\Models\StoreBanner;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Coupons, delivery zones, homepage banners, review moderation and the shoppers list. */
class MarketingController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:Manage online store', ['except' => ['reviews', 'moderate', 'accounts']]);
        $this->middleware('permission:Moderate reviews', ['only' => ['reviews', 'moderate']]);
        $this->middleware('permission:View online orders', ['only' => ['accounts']]);
    }

    protected function states(): array
    {
        return (CurrentTenant::get()?->currency_code ?: 'NGN') === 'NGN'
            ? collect(json_decode((string) @file_get_contents(public_path('states_lgas.json')), true) ?: [])->pluck('state')->all() : [];
    }

    /* ----------------------------------------------------------- coupons */

    public function coupons(): View
    {
        return view('store-admin.coupons', ['pagetitle' => 'Coupons', 'coupons' => Coupon::latest('id')->paginate(30), 'tz' => CurrentTenant::get()->timezone]);
    }

    public function saveCoupon(Request $request, ?Coupon $coupon = null): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_\-]+$/', Rule::unique('coupons', 'code')->where('tenant_id', CurrentTenant::id())->ignore($coupon?->id)],
            'description' => 'nullable|string|max:190', 'type' => ['required', Rule::in(array_keys(Coupon::TYPES))],
            'value' => 'required_unless:type,free_shipping|nullable|numeric|min:0|max:999999999', 'min_subtotal' => 'nullable|numeric|min:0',
            'max_discount' => 'nullable|numeric|min:0', 'usage_limit' => 'nullable|integer|min:1', 'usage_per_customer' => 'nullable|integer|min:1',
            'starts_at' => 'nullable|date', 'ends_at' => 'nullable|date|after_or_equal:starts_at', 'is_active' => 'nullable|boolean',
        ], ['code.regex' => 'Use letters, numbers, dashes or underscores (no spaces).']);
        if ($data['type'] === 'percent' && (float) ($data['value'] ?? 0) > 100) {
            return back()->withInput()->withErrors(['value' => 'A percentage cannot be above 100.']);
        }
        $tz = CurrentTenant::get()->timezone ?: config('app.timezone');
        $fields = [
            'code' => $data['code'], 'description' => $data['description'] ?? null, 'type' => $data['type'], 'value' => (float) ($data['value'] ?? 0),
            'min_subtotal' => $data['min_subtotal'] ?? null, 'max_discount' => $data['max_discount'] ?? null, 'usage_limit' => $data['usage_limit'] ?? null,
            'usage_per_customer' => $data['usage_per_customer'] ?? null, 'is_active' => $request->boolean('is_active'),
            'starts_at' => ! empty($data['starts_at']) ? \Carbon\Carbon::parse($data['starts_at'], $tz)->startOfDay()->utc() : null,
            'ends_at' => ! empty($data['ends_at']) ? \Carbon\Carbon::parse($data['ends_at'], $tz)->endOfDay()->utc() : null,
        ];
        $coupon ? $coupon->update($fields) : $coupon = Coupon::create($fields + ['created_by' => $request->user()->id]);
        AuditLogger::log('store.coupon_saved', $coupon, after: $coupon->only(['code', 'type', 'value', 'is_active']));

        return back()->with('success', "Coupon {$coupon->code} saved.");
    }

    public function deleteCoupon(Coupon $coupon): RedirectResponse
    {
        $coupon->used_count > 0 ? $coupon->update(['is_active' => false]) : $coupon->delete();

        return back()->with('success', $coupon->exists ? 'Coupon switched off (it has been used, so it is kept for the records).' : 'Coupon deleted.');
    }

    /* ------------------------------------------------------ delivery zones */

    public function zones(): View
    {
        return view('store-admin.shipping', ['pagetitle' => 'Delivery zones', 'zones' => ShippingZone::orderBy('sort_order')->orderBy('id')->get(), 'states' => $this->states()]);
    }

    public function saveZone(Request $request, ?ShippingZone $zone = null): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:80', 'states' => 'nullable|array', 'states.*' => 'string|max:80', 'states_text' => 'nullable|string|max:2000',
            'fee' => 'required|numeric|min:0|max:99999999', 'free_over' => 'nullable|numeric|min:0', 'eta' => 'nullable|string|max:60',
            'is_active' => 'nullable|boolean', 'sort_order' => 'nullable|integer|min:0|max:999',
        ]);
        $states = collect($data['states'] ?? [])->merge(preg_split('/[,\n]+/', (string) ($data['states_text'] ?? '')))->map(fn ($s) => trim($s))->filter()->unique()->values()->all();
        $fields = ['name' => $data['name'], 'states' => $states ?: null, 'fee' => $data['fee'], 'free_over' => ($data['free_over'] ?? null) ?: null,
            'eta' => $data['eta'] ?? null, 'is_active' => $request->boolean('is_active'), 'sort_order' => (int) ($data['sort_order'] ?? 0)];
        $zone ? $zone->update($fields) : $zone = ShippingZone::create($fields);
        AuditLogger::log('store.zone_saved', $zone, after: $fields);

        return back()->with('success', "Delivery zone “{$zone->name}” saved.");
    }

    public function deleteZone(ShippingZone $zone): RedirectResponse
    {
        $zone->delete();

        return back()->with('success', 'Delivery zone removed.');
    }

    /* ------------------------------------------------------------ banners */

    public function banners(): View
    {
        return view('store-admin.banners', ['pagetitle' => 'Homepage banners', 'banners' => StoreBanner::orderBy('placement')->orderBy('sort_order')->orderBy('id')->get()]);
    }

    public function saveBanner(Request $request, ?StoreBanner $banner = null): RedirectResponse
    {
        $data = $request->validate([
            'placement' => 'required|in:hero,promo', 'eyebrow' => 'nullable|string|max:80', 'title' => 'required|string|max:120',
            'subtitle' => 'nullable|string|max:200', 'button_text' => 'nullable|string|max:40', 'link' => 'nullable|string|max:255',
            'image' => [$banner ? 'nullable' : 'required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:6144'],
            'text_colour' => 'required|in:light,dark', 'sort_order' => 'nullable|integer|min:0|max:999', 'is_active' => 'nullable|boolean',
            'starts_at' => 'nullable|date', 'ends_at' => 'nullable|date|after_or_equal:starts_at',
        ]);
        $fields = collect($data)->except('image')->all() + ['is_active' => $request->boolean('is_active'), 'sort_order' => (int) ($data['sort_order'] ?? 0)];
        if ($request->hasFile('image')) {
            if ($banner?->image_path) {
                Storage::disk('public')->delete($banner->image_path);
            }
            $fields['image_path'] = $request->file('image')->storeAs('tenants/' . CurrentTenant::require()->public_id . '/banners',
                Str::lower(Str::random(10)) . '.' . $request->file('image')->extension(), 'public');
        }
        $banner ? $banner->update($fields) : $banner = StoreBanner::create($fields);

        return back()->with('success', 'Banner saved.');
    }

    public function deleteBanner(StoreBanner $banner): RedirectResponse
    {
        Storage::disk('public')->delete($banner->image_path);
        $banner->delete();

        return back()->with('success', 'Banner removed.');
    }

    /* ------------------------------------------------------------ reviews */

    public function reviews(Request $request): View
    {
        $status = $request->input('status', 'pending');

        return view('store-admin.reviews', [
            'pagetitle' => 'Product reviews',
            'reviews' => ProductReview::with('product:id,name,slug')->when($status !== 'all', fn ($q) => $q->where('status', $status))->latest('id')->paginate(25)->withQueryString(),
            'status' => $status,
            'counts' => ProductReview::selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status'),
        ]);
    }

    public function moderate(Request $request, ProductReview $review): RedirectResponse
    {
        $data = $request->validate(['status' => 'required|in:approved,rejected,pending', 'reply' => 'nullable|string|max:1000']);
        $review->update(['status' => $data['status'], 'reply' => $data['reply'] ?? $review->reply, 'moderated_by' => $request->user()->id]);
        ProductReview::refreshProduct($review->product_id);

        return back()->with('success', 'Review ' . ($data['status'] === 'approved' ? 'published.' : ($data['status'] === 'rejected' ? 'hidden.' : 'moved back to pending.')));
    }

    /* ----------------------------------------------------------- shoppers */

    public function accounts(Request $request): View
    {
        $term = trim((string) $request->q);

        return view('store-admin.accounts', [
            'pagetitle' => 'Shoppers',
            'accounts' => StoreAccount::with('customer:id,public_id,customer_number,first_name,last_name')->withCount('orders')
                ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('email', 'like', "%{$term}%")->orWhere('first_name', 'like', "%{$term}%")
                    ->orWhere('last_name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%")))
                ->latest('id')->paginate(30)->withQueryString(),
            'tz' => CurrentTenant::get()->timezone,
        ]);
    }
}
