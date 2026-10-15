<?php

use App\Models\CatalogueCategory;
use App\Models\FashionStyle;
use App\Models\MeasurementTemplate;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StyleImage;
use App\Models\StyleVersion;
use App\Services\Catalogue\ProductService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('public');
    [$this->house, $this->owner] = makeHouse('Atelier');
    [$this->gown, $this->trouser, $this->category] = CurrentTenant::run($this->house, fn () => [
        MeasurementTemplate::where('code', 'womens_gown')->first(),
        MeasurementTemplate::where('code', 'trouser')->first(),
        CatalogueCategory::where('name', 'Gowns & dresses')->first(),
    ]);
});

function stylePayload($test, array $over = []): array
{
    return array_merge([
        'name' => 'Mermaid lace gown', 'category_id' => $test->category->id, 'audience' => 'female',
        'description' => 'Fitted to the knee, flared hem.', 'technical_notes' => 'Corset boning, invisible zip.',
        'base_price' => '250000', 'estimated_cost' => '140000', 'lead_time_days' => '21',
        'fabric_quantity' => '5', 'fabric_unit' => 'yd',
        'price_options' => [['label' => 'Hand beading', 'amount' => '60000'], ['label' => '', 'amount' => '']],
        'templates' => [$test->gown->id], 'show_in_catalogue' => '1',
        'images' => [UploadedFile::fake()->image('front.jpg', 800, 1000), UploadedFile::fake()->image('back.jpg', 800, 1000)],
    ], $over);
}

it('installs starter categories for a new house', function () {
    CurrentTenant::run($this->house, fn () => expect(CatalogueCategory::count())->toBe(count(CatalogueCategory::STARTER)));
});

it('creates a style as a draft with pictures and publishes it', function () {
    $this->actingAs($this->owner)->post('/styles', stylePayload($this))->assertRedirect();

    $style = FashionStyle::withoutGlobalScopes()->first();
    $draft = StyleVersion::withoutGlobalScopes()->where('style_id', $style->id)->first();
    expect($style->code)->toBe('STY-00001')
        ->and($style->current_version_id)->toBeNull()
        ->and($draft->status)->toBe('draft')
        ->and($draft->options())->toBe([['label' => 'Hand beading', 'amount' => 60000.0]])
        ->and(StyleImage::withoutGlobalScopes()->count())->toBe(2);
    Storage::disk('public')->assertExists(StyleImage::withoutGlobalScopes()->first()->path);

    $this->actingAs($this->owner)->post("/styles/{$style->public_id}/publish", ['change_notes' => 'First release'])->assertRedirect();
    $style->refresh();
    expect($style->current_version_id)->toBe($draft->id)
        ->and($draft->fresh()->status)->toBe('published')
        ->and($draft->templateLinks()->first()->template_version_id)->toBe($this->gown->current_version_id);

    $this->actingAs($this->owner)->get('/styles')->assertOk()->assertSee('Mermaid lace gown')->assertSee('250,000');
    $this->actingAs($this->owner)->get("/styles/{$style->public_id}")->assertOk()->assertSee('Hand beading')->assertSee('Corset boning')->assertSee('140,000');
});

it('requires a measurement template before publishing', function () {
    $this->actingAs($this->owner)->post('/styles', stylePayload($this, ['templates' => [], 'images' => []]))->assertRedirect();
    $style = FashionStyle::withoutGlobalScopes()->first();

    $this->actingAs($this->owner)->post("/styles/{$style->public_id}/publish")->assertSessionHasErrors('templates');
    expect($style->fresh()->current_version_id)->toBeNull();
});

it('scenario 31 for styles: editing creates a new version and the old one stays frozen', function () {
    $this->actingAs($this->owner)->post('/styles', stylePayload($this, ['action' => 'publish']))->assertRedirect();
    $style = FashionStyle::withoutGlobalScopes()->first();
    $v1 = StyleVersion::withoutGlobalScopes()->find($style->current_version_id);

    // Opening the editor makes draft v2 copied from v1 (pictures shared, templates copied).
    $this->actingAs($this->owner)->get("/styles/{$style->public_id}/edit")->assertRedirect("/styles/{$style->public_id}");   // viewing never creates a draft
    $this->actingAs($this->owner)->post("/styles/{$style->public_id}/draft")->assertRedirect("/styles/{$style->public_id}/edit");
    $this->actingAs($this->owner)->get("/styles/{$style->public_id}/edit")->assertOk()->assertSee('v2');
    $v2 = StyleVersion::withoutGlobalScopes()->where('style_id', $style->id)->where('version_no', 2)->first();
    expect($v2->status)->toBe('draft')->and($v2->images()->count())->toBe(2)->and((float) $v2->base_price)->toBe(250000.0);

    // Remove a picture from the draft only: the file stays because v1 still uses it.
    $img = $v2->images()->first();
    $this->actingAs($this->owner)->delete("/style-images/{$img->id}")->assertRedirect();
    Storage::disk('public')->assertExists($img->path);
    expect($v1->images()->count())->toBe(2);

    $this->actingAs($this->owner)->put("/styles/{$style->public_id}", stylePayload($this, [
        'base_price' => '275000', 'templates' => [$this->gown->id, $this->trouser->id], 'images' => [], 'action' => 'publish', 'change_notes' => 'New price',
    ]))->assertRedirect();

    expect($style->fresh()->current_version_id)->toBe($v2->id)
        ->and($v1->fresh()->status)->toBe('retired')
        ->and((float) $v1->fresh()->base_price)->toBe(250000.0)
        ->and($v2->fresh()->templateLinks()->count())->toBe(2);

    expect(fn () => $v1->fresh()->update(['base_price' => 1]))->toThrow(LogicException::class);
    expect(fn () => $v1->fresh()->delete())->toThrow(LogicException::class);

    $this->actingAs($this->owner)->get("/styles/{$style->public_id}?version=1")->assertOk()->assertSee('older version')->assertSee('250,000');
});

it('lets a designer edit drafts but not publish', function () {
    $designer = addStaff($this->house, 'Designer');

    $this->actingAs($designer)->post('/styles', stylePayload($this, ['images' => []]))->assertRedirect();
    $style = FashionStyle::withoutGlobalScopes()->first();
    $this->actingAs($designer)->get("/styles/{$style->public_id}/edit")->assertOk()->assertDontSee('Save &amp; publish', false)->assertDontSee('Estimated cost');
    $this->actingAs($designer)->post("/styles/{$style->public_id}/publish")->assertForbidden();
    $this->actingAs($designer)->put("/styles/{$style->public_id}", stylePayload($this, ['images' => [], 'action' => 'publish']))->assertForbidden();

    // Designer can't see costing, so saving doesn't wipe the owner's cost figure.
    expect((float) StyleVersion::withoutGlobalScopes()->first()->estimated_cost)->toBe(0.0);
    $this->actingAs($this->owner)->put("/styles/{$style->public_id}", stylePayload($this, ['images' => []]))->assertRedirect();
    $this->actingAs($designer)->put("/styles/{$style->public_id}", stylePayload($this, ['images' => [], 'base_price' => '1000']))->assertRedirect();
    expect((float) StyleVersion::withoutGlobalScopes()->first()->estimated_cost)->toBe(140000.0);

    $cashier = addStaff($this->house, 'Cashier');
    $this->actingAs($cashier)->get('/styles')->assertForbidden();
});

it('creates ready-made products with variants, SKUs and barcodes', function () {
    $this->actingAs($this->owner)->post('/products', [
        'name' => 'Ankara shirt', 'category_id' => $this->category->id, 'show_in_catalogue' => '1', 'track_stock' => '1',
        'image' => UploadedFile::fake()->image('shirt.jpg'),
        'variants' => [
            ['size' => 'M', 'colour' => 'Blue', 'price' => '15000', 'cost_price' => '8000', 'generate_barcode' => '1', 'is_active' => '1'],
            ['size' => 'L', 'colour' => 'Blue', 'price' => '16000', 'barcode' => '5901234123457', 'is_active' => '1'],
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $product = Product::withoutGlobalScopes()->first();
    $vars = ProductVariant::withoutGlobalScopes()->orderBy('sort_order')->get();
    expect($product->code)->toBe('PRD-00001')
        ->and($vars[0]->sku)->toBe('PRD-00001-M-BLUE')
        ->and(strlen($vars[0]->barcode))->toBe(13)
        ->and((int) substr($vars[0]->barcode, -1))->toBe(ProductService::ean13CheckDigit(substr($vars[0]->barcode, 0, 12)))
        ->and($vars[1]->barcode)->toBe('5901234123457');

    // Same barcode on another product is refused.
    $this->actingAs($this->owner)->post('/products', ['name' => 'Copy', 'variants' => [['price' => '1', 'barcode' => '5901234123457']]])
        ->assertSessionHasErrors('variants.0.barcode');

    // Update: change a price, drop the L row.
    $this->actingAs($this->owner)->put("/products/{$product->public_id}", [
        'name' => 'Ankara shirt', 'variants' => [['id' => $vars[0]->id, 'size' => 'M', 'colour' => 'Blue', 'sku' => $vars[0]->sku, 'barcode' => $vars[0]->barcode, 'price' => '15500', 'is_active' => '1']],
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect(ProductVariant::withoutGlobalScopes()->count())->toBe(1)
        ->and((float) ProductVariant::withoutGlobalScopes()->first()->price)->toBe(15500.0)
        ->and(Product::withoutGlobalScopes()->first()->code)->toBe('PRD-00001');

    $this->actingAs($this->owner)->get('/products')->assertOk()->assertSee('Ankara shirt')->assertSee('15,500');
    $this->actingAs($this->owner)->getJson('/products/lookup?code=' . $vars[0]->barcode)->assertOk()->assertJsonPath('variant.sku', 'PRD-00001-M-BLUE');
});

it('keeps catalogue data inside its house', function () {
    $this->actingAs($this->owner)->post('/styles', stylePayload($this, ['images' => []]));
    $style = FashionStyle::withoutGlobalScopes()->first();
    [$other, $otherOwner] = makeHouse('Other');

    $this->actingAs($otherOwner)->get("/styles/{$style->public_id}")->assertNotFound();
    $this->actingAs($otherOwner)->get('/styles')->assertOk()->assertDontSee('Mermaid');
    // Can't link another house's category or template.
    $this->actingAs($otherOwner)->post('/styles', stylePayload($this, ['images' => []]))->assertSessionHasErrors('category_id');

    $this->actingAs($this->owner)->post('/products', ['name' => 'Cap', 'variants' => [['price' => '5000', 'sku' => 'CAP-1']]]);
    $this->actingAs($otherOwner)->post('/products', ['name' => 'Cap', 'variants' => [['price' => '5000', 'sku' => 'CAP-1']]])->assertSessionHasNoErrors();
    expect(ProductVariant::withoutGlobalScopes()->where('sku', 'CAP-1')->count())->toBe(2);
});

it('exposes styles, products and the customer catalogue over the API', function () {
    Sanctum::actingAs($this->owner);

    $id = $this->postJson('/api/v1/styles', [
        'name' => 'Senator kaftan', 'audience' => 'male', 'base_price' => 90000, 'estimated_cost' => 50000,
        'template_ids' => [$this->trouser->id], 'show_in_catalogue' => true,
    ])->assertCreated()->assertJsonPath('data.version.status', 'draft')->json('data.id');

    $versionId = $this->getJson("/api/v1/styles/{$id}")->assertOk()->json('data.version.id');
    $this->postJson("/api/v1/style-versions/{$versionId}/publish")->assertOk()->assertJsonPath('data.status', 'published');
    $this->patchJson("/api/v1/styles/{$id}", ['base_price' => 95000])->assertOk()->assertJsonPath('data.version.version_no', 2);
    $this->postJson("/api/v1/style-versions/{$versionId}/publish")->assertStatus(409);

    CurrentTenant::run($this->house, fn () => app(ProductService::class)->save(null,
        ['name' => 'Cap', 'show_in_catalogue' => true, 'track_stock' => true], [['price' => 5000, 'cost_price' => 2000, 'barcode' => '2000000000008']]));

    $this->getJson('/api/v1/products/lookup?code=2000000000008')->assertOk()->assertJsonPath('data.variant.cost_price', 2000);

    $cat = $this->getJson('/api/v1/catalogue')->assertOk();
    expect($cat->json('data.styles.0.name'))->toBe('Senator kaftan')
        ->and($cat->json('data.styles.0.base_price'))->toEqual(90000)          // live v1, not the draft
        ->and(json_encode($cat->json()))->not->toContain('estimated_cost')->not->toContain('cost_price');

    // A cashier sees products but not costs.
    $cashier = addStaff($this->house, 'Cashier');
    Sanctum::actingAs($cashier);
    $this->getJson('/api/v1/products/lookup?code=2000000000008')->assertOk()->assertJsonMissingPath('data.variant.cost_price');
    $this->getJson('/api/v1/styles')->assertForbidden();
});
