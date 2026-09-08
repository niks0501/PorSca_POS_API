<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Inventory;
use App\Models\Product;
use Illuminate\Http\Request;

class InventoryController extends ApiController
{
    public function index(Request $request)
    {
        $inventory = Inventory::query()
            ->with('product')
            ->when($request->boolean('low_stock'), fn ($query) => $query->whereColumn('quantity', '<=', 'reorder_level'))
            ->orderBy('product_id')
            ->paginate(min((int) $request->integer('per_page', 50), 100));

        return $this->data([
            'items' => $inventory->getCollection()->map(fn (Inventory $stock) => $this->inventoryArray($stock))->values()->all(),
            'pagination' => [
                'current_page' => $inventory->currentPage(),
                'last_page' => $inventory->lastPage(),
                'per_page' => $inventory->perPage(),
                'total' => $inventory->total(),
            ],
        ]);
    }

    public function show(string $productId)
    {
        $product = Product::query()->find($productId);
        if ($product === null) {
            return $this->error('not_found', 'Product not found.', 404);
        }

        $inventory = $product->inventory;
        if ($inventory === null) {
            return $this->error('not_found', 'Inventory not found.', 404);
        }

        return $this->data($this->inventoryArray($inventory->load('product')));
    }

    private function inventoryArray(Inventory $inventory): array
    {
        $stock = $this->stockArray($inventory);

        return [
            'product_id' => $inventory->product_id,
            'sku' => $inventory->product?->sku,
            'barcode' => $inventory->product?->barcode,
            'product_name' => $inventory->product?->name,
            ...$stock,
            'updated_at' => $inventory->updated_at?->toISOString(),
        ];
    }
}
