<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends ApiController
{
    public function index(Request $request)
    {
        $products = Product::query()
            ->with('inventory')
            ->when($request->boolean('active', true), fn ($query) => $query->where('active', true))
            ->orderBy('name')
            ->paginate(min((int) $request->integer('per_page', 50), 100));

        return $this->data([
            'items' => $products->getCollection()->map(fn (Product $product) => $this->productArray($product))->values()->all(),
            'pagination' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ],
        ]);
    }

    public function show(Product $product)
    {
        if (! $product->active) {
            return $this->error('not_found', 'Product not found.', 404);
        }

        return $this->data($this->productArray($product->load('inventory')));
    }
}
