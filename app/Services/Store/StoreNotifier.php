<?php

namespace App\Services\Store;

use App\Models\ShopOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\NewOnlineOrder;
use App\Notifications\ShopOrderUpdate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/** Who hears about an online order. Failures never stop the order. */
class StoreNotifier
{
    public function __construct(protected StoreSettings $settings)
    {
    }

    public function staff(Tenant $tenant, ShopOrder $order, string $kind = 'placed', ?string $extra = null): void
    {
        rescue(function () use ($tenant, $order, $kind, $extra) {
            $staff = User::inCurrentTenant()->get()->filter(fn (User $u) => $u->can('View online orders'));
            Notification::send($staff, new NewOnlineOrder($order, $kind, $extra));
            if ($kind === 'placed') {
                $emails = collect(preg_split('/[,;\s]+/', (string) StoreSettings::get($tenant, 'notify_emails')))->filter(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))->values();
                if ($emails->isNotEmpty()) {
                    Notification::route('mail', $emails->all())->notify(new NewOnlineOrder($order, $kind, $extra));
                }
                WhatsApp::notifyReps($order, $tenant, $this->settings->url($tenant, 'order/' . $order->token), route('store.orders.show', $order->public_id));
            }
        }, fn ($e) => Log::warning('Online order staff alert failed', ['order' => $order->number, 'error' => $e->getMessage()]), false);
    }

    public function customer(Tenant $tenant, ShopOrder $order, string $event, ?string $extra = null): void
    {
        if (! $order->email || str_ends_with($order->email, '.invalid')) {
            return;
        }
        rescue(fn () => Notification::route('mail', $order->email)->notify(
            new ShopOrderUpdate($order->loadMissing('items', 'pickupBranch'), $tenant, $event, $this->settings->url($tenant, 'order/' . $order->token), $extra)
        ), fn ($e) => Log::warning('Online order e-mail failed', ['order' => $order->number, 'error' => $e->getMessage()]), false);
    }
}
