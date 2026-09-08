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

    public function show(Product $product)
    {
        $inventory = $product->inventory;
        if ($inventory === null) {
            return $this->error('not_found', 'Inventory not found.', 404);
        }

        return $this->data($this->inventoryArray($inventory->load('product')));
    }

    private function inventoryArray(Inventory $inventory): array
    {
        return [
            'product_id' => $inventory->product_id,
            'sku' => $inventory->product?->sku,
            'product_name' => $inventory->product?->name,
            'quantity' => $inventory->quantity,
            'reorder_level' => $inventory->reorder_level,
            'low_stock' => $inventory->quantity <= $inventory->reorder_level,
            'updated_at' => $inventory->updated_at?->toISOString(),
        ];
    }
}
