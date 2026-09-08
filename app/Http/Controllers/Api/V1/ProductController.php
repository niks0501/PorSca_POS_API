<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Inventory;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->createRules());

        try {
            $product = DB::transaction(function () use ($validated): Product {
                $product = Product::create([
                    'sku' => $validated['sku'] ?? $this->generateSku(),
                    'barcode' => $validated['barcode'],
                    'name' => $validated['name'],
                    'category' => $validated['category'] ?? null,
                    'description' => $validated['description'] ?? null,
                    'price' => $validated['price'],
                    'currency' => 'PHP',
                    'active' => true,
                ]);

                $product->inventory()->create([
                    'quantity' => $validated['stock'],
                    'reorder_level' => $validated['reorder_level'] ?? 0,
                ]);

                return $product->load('inventory');
            });
        } catch (QueryException $exception) {
            if ($this->isUniqueConstraintViolation($exception)) {
                return $this->error(
                    'validation_error',
                    'The request could not be validated.',
                    422,
                    ['barcode' => ['The barcode has already been taken.']],
                );
            }

            throw $exception;
        }

        return $this->data($this->productArray($product), 201);
    }

    public function show(string $productId)
    {
        $product = Product::query()->find($productId);

        if ($product === null || ! $product->active) {
            return $this->error('not_found', 'Product not found.', 404);
        }

        return $this->data($this->productArray($product->load('inventory')));
    }

    public function update(Request $request, string $productId): JsonResponse
    {
        $validated = $request->validate($this->updateRules($productId));
        $product = DB::transaction(function () use ($validated, $productId): ?Product {
            $product = Product::query()->whereKey($productId)->lockForUpdate()->first();
            if ($product === null) {
                return null;
            }

            $product->update(Arr::only($validated, [
                'sku',
                'barcode',
                'name',
                'category',
                'description',
                'price',
            ]));

            $inventory = Inventory::query()->where('product_id', $product->id)->lockForUpdate()->first();
            if ($inventory === null) {
                $inventory = Inventory::create([
                    'product_id' => $product->id,
                    'quantity' => $validated['stock'] ?? 0,
                    'reorder_level' => $validated['reorder_level'] ?? 0,
                ]);
            } else {
                if (array_key_exists('stock', $validated)) {
                    $inventory->quantity = $validated['stock'];
                }
                if (array_key_exists('reorder_level', $validated)) {
                    $inventory->reorder_level = $validated['reorder_level'];
                }
                $inventory->save();
            }

            return $product->fresh()->load('inventory');
        });

        if ($product === null) {
            return $this->error('not_found', 'Product not found.', 404);
        }

        return $this->data($this->productArray($product));
    }

    public function updateStock(Request $request, string $productId): JsonResponse
    {
        $input = $request->all();
        if (! array_key_exists('stock', $input) && array_key_exists('quantity', $input)) {
            $input['stock'] = $input['quantity'];
        }

        $validated = Validator::make($input, [
            'stock' => ['required', 'integer', 'min:0'],
            'reorder_level' => ['sometimes', 'integer', 'min:0'],
        ])->validate();

        $product = DB::transaction(function () use ($validated, $productId): ?Product {
            $product = Product::query()->whereKey($productId)->lockForUpdate()->first();
            if ($product === null) {
                return null;
            }

            $inventory = Inventory::query()->where('product_id', $product->id)->lockForUpdate()->first();
            if ($inventory === null) {
                Inventory::create([
                    'product_id' => $product->id,
                    'quantity' => $validated['stock'],
                    'reorder_level' => $validated['reorder_level'] ?? 0,
                ]);
            } else {
                $inventory->quantity = $validated['stock'];
                if (array_key_exists('reorder_level', $validated)) {
                    $inventory->reorder_level = $validated['reorder_level'];
                }
                $inventory->save();
            }

            return $product->fresh()->load('inventory');
        });

        if ($product === null) {
            return $this->error('not_found', 'Product not found.', 404);
        }

        return $this->data($this->productArray($product));
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

    /** @return array<string, array<int, string|Rule>> */
    private function createRules(): array
    {
        return [
            'sku' => ['sometimes', 'string', 'max:64', Rule::unique('products', 'sku')],
            'barcode' => ['required', 'string', 'regex:/^\d{8,64}$/', Rule::unique('products', 'barcode')],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string'],
            'price' => ['required', 'integer', 'min:0'],
            'stock' => ['required', 'integer', 'min:0'],
            'reorder_level' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /** @return array<string, array<int, string|Rule>> */
    private function updateRules(string $productId): array
    {
        return [
            'sku' => ['sometimes', 'string', 'max:64', Rule::unique('products', 'sku')->ignore($productId)],
            'barcode' => ['sometimes', 'string', 'regex:/^\d{8,64}$/', Rule::unique('products', 'barcode')->ignore($productId)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string'],
            'price' => ['sometimes', 'required', 'integer', 'min:0'],
            'stock' => ['sometimes', 'required', 'integer', 'min:0'],
            'reorder_level' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    private function generateSku(): string
    {
        do {
            $sku = 'PROD-'.Str::upper(Str::random(8));
        } while (Product::query()->where('sku', $sku)->exists());

        return $sku;
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return str_starts_with((string) $exception->getCode(), '23');
    }
}
