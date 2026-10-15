<?php

namespace App\Http\Controllers\Catalogue;

use App\Http\Controllers\Controller;
use App\Models\CatalogueCategory;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Categories shared by styles and ready-made products. */
class CategoryController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:Manage style|Manage product');
    }

    public function index(): View
    {
        return view('catalogue.categories', [
            'pagetitle'  => 'Catalogue categories',
            'categories' => CatalogueCategory::withCount(['styles', 'products'])->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('catalogue_categories')->where('tenant_id', CurrentTenant::id())],
        ]);
        CatalogueCategory::create($data + ['sort_order' => (int) CatalogueCategory::max('sort_order') + 1]);

        return back()->with('success', 'Category added.');
    }

    public function update(Request $request, CatalogueCategory $category): RedirectResponse
    {
        $data = $request->validate([
            'name'       => ['required', 'string', 'max:80', Rule::unique('catalogue_categories')->where('tenant_id', CurrentTenant::id())->ignore($category->id)],
            'sort_order' => 'nullable|integer|min:0|max:999',
            'is_active'  => 'nullable|boolean',
        ]);
        $category->update(['name' => $data['name'], 'sort_order' => $data['sort_order'] ?? $category->sort_order, 'is_active' => $request->boolean('is_active')]);

        return back()->with('success', 'Category saved.');
    }

    public function destroy(CatalogueCategory $category): RedirectResponse
    {
        if ($category->styles()->exists() || $category->products()->exists()) {
            $category->update(['is_active' => false]);

            return back()->with('success', 'Category is in use, so it was hidden instead of deleted.');
        }
        $category->delete();

        return back()->with('success', 'Category deleted.');
    }
}
