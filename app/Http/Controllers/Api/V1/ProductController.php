<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends ApiController
{
    public function index(Request $request)
    {
        $search = trim((string) $request->input('search', $request->input('name', '')));
        $barcode = trim((string) $request->input('barcode', ''));
        $perPage = max(1, min($request->integer('per_page', 50), 100));

        $products = Product::query()
            ->with('inventory')
            ->when($request->boolean('active', true), fn ($query) => $query->where('active', true))
            ->when($search !== '', function ($query) use ($search): void {
                $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search, 'UTF-8').'%']);
            })
            ->when($barcode !== '', fn ($query) => $query->where('barcode', $barcode))
            ->orderBy('name')
            ->paginate($perPage);

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

    public function show(string $productId)
    {
        $product = Product::query()->find($productId);

        if ($product === null || ! $product->active) {
            return $this->error('not_found', 'Product not found.', 404);
        }

        return $this->data($this->productArray($product->load('inventory')));
    }

    public function byBarcode(string $barcode)
    {
        $product = Product::query()
            ->where('barcode', $barcode)
            ->where('active', true)
            ->with('inventory')
            ->first();

        if ($product === null) {
            return $this->error('not_found', 'Product not found for this barcode.', 404);
        }

        return $this->data($this->productArray($product));
    }
}
