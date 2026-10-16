<?php

use App\Models\Customer;
use App\Models\MeasurementTemplate;
use App\Models\SiteEnquiry;
use App\Models\Tenant;
use App\Services\Catalogue\StyleService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    Cache::flush();
    [$this->house, $this->owner] = makeHouse('Adunni Couture');
    $this->house->update(['slug' => 'adunni', 'phone' => '08031112222', 'brand_color' => '#7A2E4D']);
    CurrentTenant::run($this->house, function () {
        $styles = app(StyleService::class);
        $gown = $styles->create(['name' => 'Mermaid lace gown', 'audience' => 'female', 'show_in_catalogue' => true],
            ['base_price' => 250000, 'description' => 'Fitted to the knee.', 'price_options' => [['label' => 'Hand beading', 'amount' => 60000]], 'lead_time_days' => 21],
            [MeasurementTemplate::where('code', 'trouser')->value('id')]);
        $styles->publish($gown->draft()->first());
        $hidden = $styles->create(['name' => 'Secret prototype', 'audience' => 'female', 'show_in_catalogue' => false], ['base_price' => 1000],
            [MeasurementTemplate::where('code', 'trouser')->value('id')]);
        $styles->publish($hidden->draft()->first());
        $this->gown = $gown->fresh();
    });
    CurrentTenant::context()->clear();
});

function siteOn($test, array $extra = []): void
{
    $test->actingAs($test->owner)->put('/website', array_merge(['enabled' => 1, 'slug' => 'adunni', 'tagline' => 'Bridal and occasion wear, made in Lagos',
        'about' => 'Ten years of bridal work.', 'whatsapp' => '08031112222', 'show_prices' => 1], $extra))->assertRedirect();
    $test->post('/logout');
}

it('publishes the house site on its subdomain and preview address, showing only catalogue styles', function () {
    $this->get('http://adunni.fashion.test/')->assertNotFound()->assertSee('This site isn’t available', false);   // off until switched on
    siteOn($this);

    foreach (['http://adunni.fashion.test', 'http://localhost/s/adunni'] as $base) {
        $this->get("{$base}/")->assertOk()->assertSee('Adunni Couture')->assertSee('Bridal and occasion wear, made in Lagos')
            ->assertSee('Mermaid lace gown')->assertSee('₦250,000')->assertDontSee('Secret prototype')->assertSee('wa.me/2348031112222', false);
        $this->get("{$base}/collection")->assertOk()->assertSee('Mermaid lace gown')->assertDontSee('Secret prototype');
        $this->get("{$base}/collection/{$this->gown->public_id}")->assertOk()->assertSee('Hand beading')->assertSee('Usually ready in about 21 days');
        $this->get("{$base}/shop")->assertOk();
        $this->get("{$base}/contact")->assertOk()->assertSee('Send us a message');
    }
    // The preview address links stay on the preview path; the subdomain uses plain paths.
    $this->get('http://localhost/s/adunni/')->assertSee('href="/s/adunni/collection"', false);
    $this->get('http://adunni.fashion.test/')->assertSee('href="/collection"', false);

    // Prices can be hidden; the main platform domain still shows the landing page.
    siteOn($this, ['show_prices' => 0]);
    $this->get('http://adunni.fashion.test/')->assertOk()->assertDontSee('₦250,000');
    $this->get('http://localhost/')->assertOk()->assertSee('From the tape measure');
    $this->get('http://nobody.fashion.test/')->assertNotFound();
    $this->get('http://www.fashion.test/')->assertOk()->assertSee('From the tape measure');   // reserved labels keep the platform pages
});

it('takes enquiries without creating customers, keeps out bots, and lets staff convert them', function () {
    siteOn($this);
    $this->post('http://adunni.fashion.test/contact', ['name' => 'Ngozi Eze', 'phone' => '0803 555 1111', 'message' => 'I need a gown for March.',
        'style' => $this->gown->public_id, 'type' => 'booking', 'preferred_date' => now()->addDays(3)->toDateString(), '_t' => time() - 10])
        ->assertRedirect('/contact')->assertSessionHas('sent');
    $e = CurrentTenant::run($this->house, fn () => SiteEnquiry::first());
    expect($e->name)->toBe('Ngozi Eze')->and($e->style_id)->toBe($this->gown->id)->and($e->type)->toBe('booking')
        ->and(CurrentTenant::run($this->house, fn () => Customer::where('first_name', 'Ngozi')->exists()))->toBeFalse();
    Notification::assertSentTo($this->owner, \App\Notifications\NewSiteEnquiry::class);

    // Bots: the hidden field, or a form sent too fast.
    $this->post('http://adunni.fashion.test/contact', ['name' => 'Bot', 'phone' => '1', 'website' => 'http://spam'])->assertSessionHasErrors();
    $this->post('http://adunni.fashion.test/contact', ['name' => 'Bot', 'phone' => '1', '_t' => time()])->assertSessionHasErrors('message');
    $this->post('http://adunni.fashion.test/contact', ['name' => 'No contact'])->assertSessionHasErrors('phone');
    expect(CurrentTenant::run($this->house, fn () => SiteEnquiry::count()))->toBe(1);

    // Staff see it and turn it into a customer.
    $this->actingAs($this->owner)->get('/website/enquiries')->assertOk()->assertSee('Ngozi Eze')->assertSee('Mermaid lace gown');
    $this->post("/website/enquiries/{$e->public_id}/convert")->assertRedirect();
    $c = CurrentTenant::run($this->house, fn () => Customer::where('first_name', 'Ngozi')->first());
    expect($c->source)->toBe('website')->and($e->fresh()->status)->toBe('converted')->and($e->fresh()->customer_id)->toBe($c->id);

    // Another house can't see or touch it.
    [, $other] = makeHouse('Other');
    $this->actingAs($other)->post("/website/enquiries/{$e->public_id}/convert")->assertNotFound();
});

it('follows the plan: Basic shows platform branding and plain enquiries, Pro adds bookings, Business adds its own domain', function () {
    siteOn($this);
    onPlan($this->house, 'basic');
    $this->get('http://adunni.fashion.test/')->assertOk()->assertSee('Made with')->assertSee('Make an enquiry');
    $this->get('http://adunni.fashion.test/contact?type=booking')->assertOk()->assertSee('Send us a message')->assertDontSee('Preferred day');
    $this->post('http://adunni.fashion.test/contact', ['name' => 'A', 'phone' => '0801', 'type' => 'booking', '_t' => time() - 10])->assertRedirect();
    expect(CurrentTenant::run($this->house, fn () => SiteEnquiry::first()->type))->toBe('enquiry');

    onPlan($this->house, 'pro');
    $this->get('http://adunni.fashion.test/')->assertDontSee('Made with')->assertSee('Book a fitting');
    $this->get('http://adunni.fashion.test/contact?type=booking')->assertSee('Preferred day');

    // Own domain: only on Business, and only once it is verified.
    $this->actingAs($this->owner)->put('/website', ['enabled' => 1, 'slug' => 'adunni', 'domain' => 'adunnicouture.com']);
    expect($this->house->fresh()->domain)->toBeNull();   // Pro: ignored
    onPlan($this->house, 'business');
    $this->actingAs($this->owner)->put('/website', ['enabled' => 1, 'slug' => 'adunni', 'domain' => 'www.AdunniCouture.com']);
    expect($this->house->fresh()->domain)->toBe('adunnicouture.com');
    $this->post('/logout');
    $this->get('http://adunnicouture.com/')->assertOk()->assertSee('From the tape measure');   // not verified yet: platform page
    $this->house->fresh()->forceFill(['domain_verified_at' => now()])->save();
    $this->get('http://adunnicouture.com/')->assertOk()->assertSee('Adunni Couture')->assertSee('href="/collection"', false);

    // Plan without a website: the site goes offline (nothing is deleted).
    onPlan($this->house, 'free');
    $this->get('http://adunni.fashion.test/')->assertNotFound();
    $this->actingAs($this->owner)->get('/website')->assertOk()->assertSee('Read-only.');
    $this->put('/website', ['enabled' => 1, 'slug' => 'adunni'])->assertSessionHasErrors('plan');
});

it('checks the address a house picks, and keeps website settings to managers', function () {
    makeHouse('Taken House');
    Tenant::where('name', 'Taken House')->update(['slug' => 'taken']);
    $this->actingAs($this->owner)->put('/website', ['enabled' => 1, 'slug' => 'taken'])->assertSessionHasErrors('slug');
    $this->put('/website', ['enabled' => 1, 'slug' => 'admin'])->assertSessionHasErrors('slug');
    $this->put('/website', ['enabled' => 1, 'slug' => 'Bad Slug!'])->assertSessionHasErrors('slug');
    $this->put('/website', ['enabled' => 1, 'slug' => 'adunni-couture'])->assertSessionHasNoErrors();
    $this->get('/website')->assertOk()->assertSee('adunni-couture');

    $tailor = addStaff($this->house, 'Tailor');
    $this->actingAs($tailor)->get('/website')->assertForbidden();
    $this->actingAs($tailor)->get('/website/enquiries')->assertOk();   // tailors can view customers
});
