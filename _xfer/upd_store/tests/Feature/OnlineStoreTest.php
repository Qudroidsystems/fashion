<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentIntent;
use App\Models\ShopOrder;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Notifications\NewOnlineOrder;
use App\Services\Catalogue\ProductService;
use App\Services\Inventory\StockLedger;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

const SHOP = 'http://adunni.fashion.test';

beforeEach(function () {
    Notification::fake();
    Storage::fake('public');
    Cache::flush();
    [$this->house, $this->owner] = makeHouse('Adunni Couture');
    $this->house->update(['slug' => 'adunni', 'phone' => '08031112222']);
    $this->actingAs($this->owner)->put('/website', ['enabled' => 1, 'slug' => 'adunni', 'whatsapp' => '08031112222', 'show_prices' => 1])->assertRedirect();

    CurrentTenant::run($this->house, function () {
        $this->branch = Branch::where('is_main', true)->first();
        $this->dress = app(ProductService::class)->save(null, ['name' => 'Ankara wrap dress', 'show_in_catalogue' => true, 'track_stock' => true], [
            ['size' => 'M', 'colour' => 'Blue', 'price' => 25000, 'cost_price' => 12000],
            ['size' => 'L', 'colour' => 'Blue', 'price' => 25000, 'cost_price' => 12000],
        ]);
        $this->m = $this->dress->variants->firstWhere('size', 'M');
        $this->l = $this->dress->variants->firstWhere('size', 'L');
        $ledger = app(StockLedger::class);
        $ledger->post(InventoryItem::where('product_variant_id', $this->m->id)->first(), $this->branch->id, 3, 'opening', ['unit_cost' => 12000]);
        $ledger->post(InventoryItem::where('product_variant_id', $this->l->id)->first(), $this->branch->id, 1, 'opening', ['unit_cost' => 12000]);
    });
    CurrentTenant::context()->clear();
    $this->actingAs($this->owner)->put('/store/settings', storeSettings())->assertSessionHasNoErrors();
    auth()->logout();
});

function storeSettings(array $extra = []): array
{
    return array_replace_recursive([
        'enabled' => 1, 'guest_checkout' => 1, 'delivery_enabled' => 1, 'pickup_enabled' => 1, 'default_fee' => 2000,
        'methods' => ['paystack' => 1, 'stripe' => 1, 'bank_transfer' => 1, 'whatsapp' => 1, 'pay_on_delivery' => 1],
        'bank_name' => 'GTBank', 'account_name' => 'Adunni Couture Ltd', 'account_number' => '0123456789',
    ], $extra);
}

function checkoutData(array $extra = []): array
{
    return array_merge(['first_name' => 'Ngozi', 'last_name' => 'Eze', 'email' => 'ngozi@example.com', 'phone' => '0803 555 1111',
        'fulfilment' => 'delivery', 'address_line1' => '12 Allen Avenue', 'city' => 'Ikeja', 'state' => 'Lagos', 'payment_method' => 'bank_transfer', 'agree' => 1], $extra);
}

function inShop($test, callable $fn)
{
    $r = CurrentTenant::run($test->house, $fn);
    CurrentTenant::context()->clear();

    return $r;
}

it('shows the shop, adds to the basket and keeps prices and stock on the server', function () {
    $this->get(SHOP . '/')->assertOk()->assertSee('Ankara wrap dress')->assertSee('Shop');
    $this->get(SHOP . '/shop')->assertOk()->assertSee('Ankara wrap dress')->assertSee('₦25,000');
    $this->get(SHOP . '/shop?size[]=XL')->assertOk()->assertDontSee('Ankara wrap dress');
    $this->get(SHOP . '/product/' . $this->dress->slug)->assertOk()->assertSee('Add to basket')->assertSee('wa.me/2348031112222', false);

    $this->postJson(SHOP . '/cart/add', ['variant_id' => $this->m->id, 'quantity' => 2])->assertOk()->assertJson(['ok' => true, 'count' => 2]);
    $this->postJson(SHOP . '/cart/add', ['variant_id' => $this->m->id, 'quantity' => 2])->assertStatus(422)->assertJsonFragment(['message' => 'Only 3 of Ankara wrap dress (M / Blue) left.']);
    $this->getJson(SHOP . '/cart/mini')->assertOk()->assertJson(['count' => 2, 'total' => '₦50,000']);
    $this->get(SHOP . '/cart')->assertOk()->assertSee('₦50,000');

    // Another house's variant can't be added.
    [$other] = makeHouse('Other House');
    $foreign = CurrentTenant::run($other, fn () => app(ProductService::class)->save(null, ['name' => 'Foreign', 'show_in_catalogue' => true, 'track_stock' => false], [['price' => 10]]))->variants->first();
    CurrentTenant::context()->clear();
    $this->postJson(SHOP . '/cart/add', ['variant_id' => $foreign->id])->assertStatus(422);
});

it('takes a bank-transfer order, holds stock, and turns it into a paid order when staff confirm the money', function () {
    $this->postJson(SHOP . '/cart/add', ['variant_id' => $this->m->id, 'quantity' => 2])->assertOk();
    $this->get(SHOP . '/checkout')->assertOk()->assertSee('Bank transfer')->assertSee('0123456789');
    $res = $this->post(SHOP . '/checkout', checkoutData());
    $shop = inShop($this, fn () => ShopOrder::with('items')->first());
    $res->assertRedirect('/order/' . $shop->token);

    expect($shop->status)->toBe('awaiting_confirmation')->and($shop->total)->toBe(52000.0)   // 2 × 25,000 + 2,000 delivery
        ->and($shop->number)->toStartWith('WEB-')->and($shop->items->first()->reservation_id)->not->toBeNull();
    $mItem = inShop($this, fn () => InventoryItem::where('product_variant_id', $this->m->id)->first());
    expect((float) StockBalance::where('item_id', $mItem->id)->value('reserved'))->toBe(2.0);
    Notification::assertSentTo($this->owner, NewOnlineOrder::class);
    $this->getJson(SHOP . '/cart/mini')->assertJson(['count' => 0]);

    // Only one M left to sell now.
    $this->postJson(SHOP . '/cart/add', ['variant_id' => $this->m->id, 'quantity' => 2])->assertStatus(422);

    // The customer sends the receipt.
    $this->get(SHOP . '/order/' . $shop->token)->assertOk()->assertSee($shop->number)->assertSee('0123456789');
    $this->post(SHOP . '/order/' . $shop->token . '/proof', ['proof' => \Illuminate\Http\UploadedFile::fake()->image('receipt.jpg'), 'reference' => 'GT-998'])->assertRedirect();
    expect($shop->fresh()->payment_status)->toBe('pending');

    // Staff confirm the payment: order + payment + stock sold.
    $this->actingAs($this->owner)->get('/store/orders/' . $shop->public_id)->assertOk()->assertSee('GT-998')->assertSee('Record a payment');
    $this->post('/store/orders/' . $shop->public_id . '/payment', ['amount' => 52000, 'method' => 'bank_transfer', 'reference' => 'GT-998'])->assertSessionHasNoErrors();
    $shop = $shop->fresh();
    $order = inShop($this, fn () => Order::with('lines')->find($shop->order_id));
    expect($shop->status)->toBe('confirmed')->and($shop->payment_status)->toBe('paid')->and($order->status)->toBe('confirmed')
        ->and($order->total)->toBe(52000.0)->and($order->balance())->toBe(0.0)->and($order->lines)->toHaveCount(2)
        ->and(inShop($this, fn () => Customer::find($shop->customer_id))->source)->toBe('website')
        ->and(inShop($this, fn () => Payment::where('customer_id', $shop->customer_id)->sum('amount')))->toEqual(52000);
    expect((float) StockBalance::where('item_id', $mItem->id)->value('on_hand'))->toBe(1.0)
        ->and((float) StockBalance::where('item_id', $mItem->id)->value('reserved'))->toBe(0.0)
        ->and(inShop($this, fn () => StockMovement::where('type', 'sale')->where('order_id', $order->id)->sum('quantity')))->toEqual(-2);

    // Pack, send, deliver — the normal order is handed over.
    $this->post('/store/orders/' . $shop->public_id . '/advance', ['to' => 'shipped', 'courier' => 'GIG', 'tracking_number' => 'GIG123'])->assertSessionHasNoErrors();
    $this->post('/store/orders/' . $shop->public_id . '/advance', ['to' => 'delivered'])->assertSessionHasNoErrors();
    expect($shop->fresh()->status)->toBe('delivered')->and($order->fresh()->status)->toBe('delivered');
    auth()->logout();
    $this->get(SHOP . '/order/' . $shop->token)->assertOk()->assertSee('Delivered');
});

it('sends WhatsApp orders to the rep with the full order, and lets the rep change and confirm them', function () {
    $this->postJson(SHOP . '/cart/add', ['variant_id' => $this->m->id, 'quantity' => 1])->assertOk();
    $this->post(SHOP . '/checkout', checkoutData(['payment_method' => 'whatsapp', 'email' => '', 'fulfilment' => 'pickup', 'pickup_branch_id' => $this->branch->id]))
        ->assertSessionHas('open_whatsapp', true);
    $shop = inShop($this, fn () => ShopOrder::first());
    $page = $this->get(SHOP . '/order/' . $shop->token)->assertOk();
    $page->assertSee('https://wa.me/2348031112222?text=', false)->assertSee(rawurlencode('Order ' . $shop->number), false)->assertSee(rawurlencode('1 × Ankara wrap dress (M / Blue)'), false);

    $this->actingAs($this->owner)->put('/store/orders/' . $shop->public_id . '/items', ['items' => [
        ['variant_id' => $this->m->id, 'quantity' => 1], ['variant_id' => $this->l->id, 'quantity' => 1],
    ], 'discount' => 1000])->assertSessionHasNoErrors();
    expect($shop->fresh()->total)->toBe(49000.0);
    $this->get('/store/orders/' . $shop->public_id)->assertOk()->assertSee('Send to customer');
    $this->post('/store/orders/' . $shop->public_id . '/accept')->assertSessionHasNoErrors();
    $shop = $shop->fresh();
    expect($shop->status)->toBe('confirmed')->and($shop->payment_status)->toBe('unpaid')
        ->and(inShop($this, fn () => Customer::find($shop->customer_id))->source)->toBe('whatsapp');
});

it('takes card payments with Paystack, verified with Paystack and recorded once', function () {
    inShop($this, function () {
        $t = $this->house->fresh();
        $t->forceFill(['settings' => array_merge($t->settings, ['paystack' => ['enabled' => true, 'public_key' => 'pk_test_x', 'secret_key' => Crypt::encryptString('sk_test_abc')]])])->save();
    });
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/abc']]),
        'api.paystack.co/transaction/verify/*' => function ($request) {
            $intent = PaymentIntent::withoutGlobalScopes()->latest('id')->first();

            return Http::response(['status' => true, 'data' => ['status' => 'success', 'amount' => (int) round($intent->amount * 100), 'currency' => 'NGN', 'channel' => 'card', 'id' => 991]]);
        },
    ]);
    $this->postJson(SHOP . '/cart/add', ['variant_id' => $this->l->id])->assertOk();
    $this->post(SHOP . '/checkout', checkoutData(['payment_method' => 'paystack']))->assertRedirect('https://checkout.paystack.com/abc');
    $shop = inShop($this, fn () => ShopOrder::first());
    $intent = inShop($this, fn () => PaymentIntent::first());
    expect($shop->status)->toBe('pending_payment')->and($intent->shop_order_id)->toBe($shop->id)->and($intent->amount)->toBe(27000.0);

    $this->get(SHOP . '/checkout/paystack?reference=' . $intent->reference)->assertRedirect('/order/' . $shop->token);
    expect($shop->fresh()->payment_status)->toBe('paid')->and($shop->fresh()->status)->toBe('confirmed');

    // The webhook arriving afterwards does not pay twice.
    $body = json_encode(['event' => 'charge.success', 'data' => ['id' => 991, 'reference' => $intent->reference]]);
    $this->call('POST', '/webhooks/paystack/' . $this->house->public_id, [], [], [], ['HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'sk_test_abc'), 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();
    expect(inShop($this, fn () => Payment::count()))->toBe(1);
});

it('takes card payments with Stripe through the signed webhook', function () {
    $this->actingAs($this->owner)->put('/store/settings', storeSettings(['stripe_publishable_key' => 'pk_test_1', 'stripe_secret_key' => 'sk_test_1', 'stripe_webhook_secret' => 'whsec_abc']))->assertSessionHasNoErrors();
    auth()->logout();
    Http::fake([
        'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/pay/cs_test_1']),
        'api.stripe.com/v1/checkout/sessions/cs_test_1' => Http::response(['id' => 'cs_test_1', 'status' => 'complete', 'payment_status' => 'paid', 'amount_total' => 2700000, 'currency' => 'ngn']),
    ]);
    $this->postJson(SHOP . '/cart/add', ['variant_id' => $this->l->id])->assertOk();
    $this->post(SHOP . '/checkout', checkoutData(['payment_method' => 'stripe']))->assertRedirect('https://checkout.stripe.com/pay/cs_test_1');
    $shop = inShop($this, fn () => ShopOrder::first());

    $payload = json_encode(['id' => 'evt_1', 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_test_1']]]);
    $t = time();
    $sig = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $payload, 'whsec_abc');
    $this->call('POST', '/webhooks/stripe/' . $this->house->public_id, [], [], [], ['HTTP_STRIPE_SIGNATURE' => 't=1,v1=bad', 'CONTENT_TYPE' => 'application/json'], $payload)->assertStatus(400);
    $this->call('POST', '/webhooks/stripe/' . $this->house->public_id, [], [], [], ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'], $payload)->assertOk();
    $this->call('POST', '/webhooks/stripe/' . $this->house->public_id, [], [], [], ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'], $payload)->assertOk();
    expect($shop->fresh()->payment_status)->toBe('paid')->and(inShop($this, fn () => Payment::count()))->toBe(1);
});

it('applies coupons and releases stock from orders nobody paid for', function () {
    $this->actingAs($this->owner)->post('/store/coupons', ['code' => 'easter10', 'type' => 'percent', 'value' => 10, 'is_active' => 1, 'usage_limit' => 5])->assertSessionHasNoErrors();
    auth()->logout();
    $this->postJson(SHOP . '/cart/add', ['variant_id' => $this->m->id, 'quantity' => 2])->assertOk();
    $this->post(SHOP . '/cart/coupon', ['code' => 'NOPE'])->assertSessionHasErrors('code');
    $this->post(SHOP . '/cart/coupon', ['code' => 'EASTER10'])->assertSessionHasNoErrors();
    $this->post(SHOP . '/checkout', checkoutData());
    $shop = inShop($this, fn () => ShopOrder::first());
    expect($shop->discount_total)->toBe(5000.0)->and($shop->total)->toBe(47000.0)->and($shop->coupon_code)->toBe('EASTER10');

    $this->travel(4)->days();
    $this->artisan('fhmp:store-expire')->assertSuccessful();
    expect($shop->fresh()->status)->toBe('cancelled')
        ->and(inShop($this, fn () => StockReservation::where('status', 'active')->count()))->toBe(0)
        ->and(inShop($this, fn () => \App\Models\Coupon::first()->used_count))->toBe(0);
});

it('keeps online orders inside the house and the back office to the right people', function () {
    $this->postJson(SHOP . '/cart/add', ['variant_id' => $this->m->id])->assertOk();
    $this->post(SHOP . '/checkout', checkoutData());
    $shop = inShop($this, fn () => ShopOrder::first());

    $tailor = addStaff($this->house, 'Tailor');
    $this->actingAs($tailor)->get('/store/orders')->assertForbidden();
    $rep = addStaff($this->house, 'Online Sales Rep');
    $this->actingAs($rep)->get('/store/orders')->assertOk()->assertSee($shop->number);
    $this->actingAs($rep)->get('/store/settings')->assertForbidden();

    [, $otherOwner] = makeHouse('Other');
    $this->actingAs($otherOwner)->get('/store/orders/' . $shop->public_id)->assertNotFound();
    $this->actingAs($otherOwner)->get('/store/orders')->assertOk()->assertDontSee($shop->number);

    // A rep takes a WhatsApp order and records the transfer at once.
    $this->actingAs($rep)->post('/store/orders', ['first_name' => 'Bisi', 'phone' => '0802 000 0000', 'fulfilment' => 'pickup', 'pickup_branch_id' => $this->branch->id,
        'payment_method' => 'bank_transfer', 'source' => 'whatsapp', 'items' => [['variant_id' => $this->l->id, 'quantity' => 1]],
        'paid_amount' => 25000, 'paid_method' => 'bank_transfer', 'paid_reference' => 'ZEN-1'])->assertSessionHasNoErrors()->assertRedirect();
    $taken = inShop($this, fn () => ShopOrder::where('first_name', 'Bisi')->first());
    expect($taken->source)->toBe('whatsapp')->and($taken->payment_status)->toBe('paid')->and($taken->order_id)->not->toBeNull();

    // Closing the shop keeps the site but sends basket pages home.
    $this->actingAs($this->owner)->put('/store/settings', storeSettings(['enabled' => 0]));
    auth()->logout();
    $this->get(SHOP . '/cart')->assertRedirect('/');
    $this->get(SHOP . '/')->assertOk()->assertDontSee('Add to basket');
});

it('lets shoppers register, keep their basket, and see their orders', function () {
    $this->postJson(SHOP . '/cart/add', ['variant_id' => $this->m->id])->assertOk();
    $this->post(SHOP . '/account/register', ['first_name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'secret-pass', 'password_confirmation' => 'secret-pass'])->assertRedirect('/account');
    $this->getJson(SHOP . '/cart/mini')->assertJson(['count' => 1]);
    $this->post(SHOP . '/checkout', checkoutData(['email' => 'ada@example.com']))->assertRedirect();
    $this->get(SHOP . '/account/orders')->assertOk()->assertSee('WEB-');
    $this->post(SHOP . '/account/logout');
    $this->get(SHOP . '/account/orders')->assertRedirect('/account/login');
    $this->post(SHOP . '/account/login', ['email' => 'ada@example.com', 'password' => 'wrong'])->assertSessionHasErrors('email');
    $this->post(SHOP . '/account/login', ['email' => 'ada@example.com', 'password' => 'secret-pass'])->assertRedirect('/account');

    // Same e-mail on another house's shop is a different account.
    expect(\App\Models\StoreAccount::withoutTenantScope()->where('email', 'ada@example.com')->count())->toBe(1);
});

it('opens every back-office screen and manages pictures, prices, zones, banners and reviews', function () {
    $this->actingAs($this->owner);
    foreach (['/store', '/store/orders', '/store/orders/create', '/store/products', '/store/coupons', '/store/shipping', '/store/banners', '/store/reviews', '/store/shoppers', '/store/settings', '/store/variants?q=ankara'] as $url) {
        $this->get($url)->assertOk();
    }
    $this->get('/store/products/' . $this->dress->public_id)->assertOk()->assertSee('Upload pictures');
    $this->post('/store/products/' . $this->dress->public_id . '/images', ['images' => [\Illuminate\Http\UploadedFile::fake()->image('a.jpg', 600, 800), \Illuminate\Http\UploadedFile::fake()->image('b.jpg', 600, 800)], 'colour' => 'Blue'])->assertSessionHasNoErrors();
    $this->put('/store/products/' . $this->dress->public_id, ['name' => 'Ankara wrap dress', 'slug' => 'ankara-wrap', 'show_in_catalogue' => 1, 'is_featured' => 1, 'badge' => 'New',
        'variants' => [$this->m->id => ['price' => 22000, 'compare_at_price' => 25000, 'cost_price' => 11000, 'is_active' => 1], $this->l->id => ['price' => 25000, 'is_active' => 1]]])->assertSessionHasNoErrors();
    expect((float) $this->m->fresh()->price)->toBe(22000.0)->and((float) $this->m->fresh()->compare_at_price)->toBe(25000.0)->and($this->dress->fresh()->images()->count())->toBe(2);
    $this->post('/store/shipping', ['name' => 'Lagos', 'states' => ['Lagos'], 'fee' => 3000, 'free_over' => 100000, 'is_active' => 1])->assertSessionHasNoErrors();
    $this->post('/store/banners', ['placement' => 'hero', 'title' => 'New season', 'text_colour' => 'light', 'is_active' => 1, 'image' => \Illuminate\Http\UploadedFile::fake()->image('hero.jpg', 1920, 800)])->assertSessionHasNoErrors();
    auth()->logout();

    $this->get(SHOP . '/')->assertOk()->assertSee('New season');
    $this->get(SHOP . '/product/ankara-wrap')->assertOk()->assertSee('₦22,000')->assertSee('₦25,000')->assertSee('-12%');
    $this->get(SHOP . '/shop?sale=1')->assertOk()->assertSee('Ankara wrap dress');
    $this->post(SHOP . '/product/ankara-wrap/review', ['rating' => 5, 'name' => 'Tola', 'body' => 'Lovely fit.'])->assertRedirect();
    $review = inShop($this, fn () => \App\Models\ProductReview::first());
    $this->get(SHOP . '/product/ankara-wrap')->assertDontSee('Lovely fit.');
    $this->actingAs($this->owner)->patch('/store/reviews/' . $review->id, ['status' => 'approved', 'reply' => 'Thank you!'])->assertSessionHasNoErrors();
    auth()->logout();
    $this->get(SHOP . '/product/ankara-wrap')->assertSee('Lovely fit.')->assertSee('Thank you!');

    // Lagos delivery fee comes from the zone.
    $this->postJson(SHOP . '/cart/add', ['variant_id' => $this->m->id])->assertOk();
    $this->postJson(SHOP . '/checkout/quote', ['fulfilment' => 'delivery', 'state' => 'Lagos'])->assertJson(['shipping' => '₦3,000', 'total' => '₦25,000']);
    foreach (['/wishlist', '/track', '/account/login', '/account/register', '/account/forgot', '/collection', '/contact', '/cart', '/checkout'] as $url) {
        $this->get(SHOP . $url)->assertOk();
    }
});
