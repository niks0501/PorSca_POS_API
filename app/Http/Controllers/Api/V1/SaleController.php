<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Sale;
use Illuminate\Http\Request;

class SaleController extends ApiController
{
    public function index(Request $request)
    {
        $sales = Sale::query()
            ->where('status', 'completed')
            ->with('items.product')
            ->latest('completed_at')
            ->paginate(min((int) $request->integer('per_page', 50), 100));

        return $this->data([
            'items' => $sales->getCollection()->map(fn (Sale $sale) => $this->saleArray($sale))->values()->all(),
            'pagination' => [
                'current_page' => $sales->currentPage(),
                'last_page' => $sales->lastPage(),
                'per_page' => $sales->perPage(),
                'total' => $sales->total(),
            ],
        ]);
    }

    public function show(Sale $sale)
    {
        return $this->data($this->saleArray($sale->load('items.product')));
    }
}
