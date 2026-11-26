<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\ShopCreditEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The per-shop credit ledger: outstanding balance plus every sale, receipt,
 * return and adjustment that produced it.
 *
 * Executives may read and maintain the ledger only for shops the
 * administrator has assigned to them; administrators see every shop. The
 * assignment check lives in one method so the shop-access pivot can widen it
 * later without touching either controller.
 */
class EmployeeShopCreditController extends Controller
{
    /**
     * Summary plus the paginated transaction history for one shop.
     */
    public function index(Request $request, Shop $shop): JsonResponse
    {
        $this->authorize('viewCredit', $shop);

        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'type' => ['nullable', Rule::in(array_keys(ShopCreditEntry::typeLabels()))],
        ]);

        $query = $shop->creditEntries()
            ->with(['order:id,order_number,status', 'creator:id,name'])
            ->when($validated['type'] ?? null, fn ($q, string $type) => $q->where('type', $type));

        $paginator = $query->paginate((int) ($validated['per_page'] ?? 50));

        $totals = ShopCreditEntry::query()
            ->where('shop_id', $shop->id)
            ->selectRaw('
                COALESCE(SUM(CASE WHEN amount_paise > 0 THEN amount_paise ELSE 0 END), 0) AS debit_paise,
                COALESCE(SUM(CASE WHEN amount_paise < 0 THEN amount_paise ELSE 0 END), 0) AS credit_paise,
                COUNT(*) AS entries
            ')
            ->first();

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (ShopCreditEntry $entry) => $this->formatEntry($entry))->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'shop_id' => $shop->id,
                'shop_name' => $shop->name,
                'balance_paise' => (int) $shop->credit_balance_paise,
                'debit_paise' => (int) $totals->debit_paise,
                'credit_paise' => (int) $totals->credit_paise,
                'entries' => (int) $totals->entries,
            ],
        ]);
    }

    /**
     * Append a manual transaction (receipt, opening balance, adjustment…).
     * Order-generated rows are created by the order flow, never here.
     */
    public function store(Request $request, Shop $shop): JsonResponse
    {
        $this->authorize('updateCredit', $shop);

        $validated = $request->validate([
            'type' => ['required', Rule::in(array_keys(ShopCreditEntry::typeLabels()))],
            'amount_paise' => ['required', 'integer', 'between:-100000000000,100000000000', 'not_in:0'],
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:64'],
            'details' => ['nullable', 'string', 'max:500'],
        ]);

        // A debit must be positive and a credit negative: the sign carries
        // the direction, so the API normalises whatever the app sends.
        $amount = $this->signedAmount($validated['type'], (int) $validated['amount_paise']);

        $entry = ShopCreditEntry::create([
            'shop_id' => $shop->id,
            'type' => $validated['type'],
            'amount_paise' => $amount,
            'entry_date' => $validated['entry_date'],
            'reference' => $validated['reference'] ?? null,
            'details' => $validated['details'] ?? null,
            'source' => ShopCreditEntry::SOURCE_MANUAL,
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'data' => $this->formatEntry($entry->fresh()),
            'balance_paise' => (int) $shop->fresh()->credit_balance_paise,
            'message' => 'Entry added.',
        ], 201);
    }

    /**
     * Edit a manual transaction. Rows the order flow created are read-only:
     * they are reversed by cancelling the order instead.
     */
    public function update(Request $request, ShopCreditEntry $entry): JsonResponse
    {
        $this->authorize('updateCredit', $entry->shop);

        if ($entry->isSystemGenerated()) {
            return response()->json([
                'message' => 'This entry follows an order and cannot be edited.',
            ], 422);
        }

        $validated = $request->validate([
            'type' => ['sometimes', Rule::in(array_keys(ShopCreditEntry::typeLabels()))],
            'amount_paise' => ['sometimes', 'integer', 'between:-100000000000,100000000000', 'not_in:0'],
            'entry_date' => ['sometimes', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:64'],
            'details' => ['nullable', 'string', 'max:500'],
        ]);

        $type = $validated['type'] ?? $entry->type;
        $attributes = [
            'type' => $type,
            'entry_date' => $validated['entry_date'] ?? $entry->entry_date->toDateString(),
            'reference' => $validated['reference'] ?? $entry->reference,
            'details' => $validated['details'] ?? $entry->details,
        ];

        if (array_key_exists('amount_paise', $validated)) {
            $attributes['amount_paise'] = $this->signedAmount(
                $type,
                (int) $validated['amount_paise'],
            );
        }

        $entry->update($attributes);

        return response()->json([
            'data' => $this->formatEntry($entry->fresh()),
            'balance_paise' => (int) $entry->shop->fresh()->credit_balance_paise,
            'message' => 'Entry updated.',
        ]);
    }

    public function destroy(ShopCreditEntry $entry): JsonResponse
    {
        $this->authorize('updateCredit', $entry->shop);

        if ($entry->isSystemGenerated()) {
            return response()->json([
                'message' => 'This entry follows an order and cannot be deleted.',
            ], 422);
        }

        $entry->delete();

        return response()->json([
            'balance_paise' => (int) $entry->shop->fresh()->credit_balance_paise,
            'message' => 'Entry removed.',
        ]);
    }

    /**
     * Debits are stored positive, credits negative, whichever way the app
     * sent them, so the ledger sum is always the outstanding balance.
     */
    protected function signedAmount(string $type, int $amount): int
    {
        $debit = in_array($type, [
            ShopCreditEntry::TYPE_OPENING,
            ShopCreditEntry::TYPE_SALE,
        ], true);

        $magnitude = abs($amount);

        return $debit ? $magnitude : -$magnitude;
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatEntry(ShopCreditEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'shop_id' => $entry->shop_id,
            'type' => $entry->type,
            'type_label' => ShopCreditEntry::typeLabels()[$entry->type] ?? $entry->type,
            'amount_paise' => $entry->amount_paise,
            'entry_date' => $entry->entry_date?->toDateString(),
            'reference' => $entry->reference,
            'details' => $entry->details,
            'order_id' => $entry->order_id,
            'order_number' => $entry->order?->order_number,
            'order_status' => $entry->order?->status?->value,
            'source' => $entry->source,
            'read_only' => $entry->isSystemGenerated(),
            'created_by' => $entry->creator?->name,
            'created_at' => $entry->created_at?->toIso8601String(),
        ];
    }
}
