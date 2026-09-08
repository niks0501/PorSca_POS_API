<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Transaction;
use Illuminate\Http\Request;

class TransactionController extends ApiController
{
    public function index(Request $request)
    {
        $transactions = Transaction::query()
            ->when($request->filled('payment_id'), fn ($query) => $query->where('payment_id', $request->integer('payment_id')))
            ->when($request->filled('sale_id'), fn ($query) => $query->where('sale_id', $request->integer('sale_id')))
            ->latest('occurred_at')
            ->paginate(min((int) $request->integer('per_page', 50), 100));

        return $this->data([
            'items' => $transactions->getCollection()->map(fn (Transaction $transaction) => $this->transactionArray($transaction))->values()->all(),
            'pagination' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
            ],
        ]);
    }

    public function show(Transaction $transaction)
    {
        return $this->data($this->transactionArray($transaction));
    }
}
