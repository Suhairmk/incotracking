<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Services\ActivityLogger;
use App\Services\TransactionImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * Excel in and out for the credit ledger.
 *
 * The import is two deliberate steps: parse into a preview the administrator
 * can edit, delete and assign staff to, then commit exactly that payload. No
 * file is ever written to the database on upload alone.
 */
class AdminTransactionImportController extends Controller
{
    public function __construct(
        private readonly TransactionImportService $importer,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * Parse an uploaded statement into the editable preview.
     */
    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xlsm,xls', 'max:20480'],
        ]);

        try {
            $parsed = $this->importer->parse($request->file('file') ?? throw new RuntimeException('No file received.'));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $parsed,
            'message' => sprintf(
                '%d shops read, %d transactions ready to review.',
                $parsed['summary']['shops'],
                $parsed['summary']['entries']
            ),
        ]);
    }

    /**
     * Save the confirmed preview.
     */
    public function commit(Request $request): JsonResponse
    {
        // Validation guarantees the shape; the raw rows are handed over below
        // so the shop details the preview carried (area, phone, GSTIN, the
        // existing id) survive — `validated()` drops unlisted keys.
        $request->validate([
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.shop' => ['required', 'array'],
            'rows.*.shop.name' => ['required', 'string', 'max:120'],
            'rows.*.shop.existing_id' => ['nullable', 'integer', 'exists:shops,id'],
            'rows.*.shop.area' => ['nullable', 'string', 'max:120'],
            'rows.*.shop.phone' => ['nullable', 'string', 'max:30'],
            'rows.*.shop.gst_number' => ['nullable', 'string', 'max:20'],
            'rows.*.shop.is_visible_to_field' => ['nullable', 'boolean'],
            'rows.*.deleted' => ['sometimes', 'boolean'],
            'rows.*.is_visible_to_field' => ['sometimes', 'boolean'],
            'rows.*.employee_ids' => ['sometimes', 'array'],
            'rows.*.employee_ids.*' => ['integer', 'exists:users,id'],
            'rows.*.entries' => ['nullable', 'array'],
            'rows.*.entries.*.deleted' => ['sometimes', 'boolean'],
            'rows.*.entries.*.type' => ['required', 'string', 'max:16'],
            'rows.*.entries.*.amount_paise' => ['required', 'integer', 'not_in:0'],
            'rows.*.entries.*.entry_date' => ['required', 'date_format:Y-m-d'],
        ]);

        try {
            $result = $this->importer->commit(['rows' => $request->input('rows', [])], $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->logger->log('admin.credit_imported', $request->user(), null, [
            'shops_created' => $result['shops_created'],
            'entries_created' => $result['entries_created'],
            'entries_skipped' => $result['entries_skipped'],
        ]);

        return response()->json([
            'data' => $result,
            'message' => $result['message'],
        ]);
    }

    /**
     * The whole ledger as an .xlsx: one sheet per transaction with its
     * running balance, one sheet of outstanding totals.
     */
    public function export(Request $request)
    {
        $validated = $request->validate([
            'shop_id' => ['nullable', 'integer', 'exists:shops,id'],
        ]);

        $shops = Shop::query()
            ->when($validated['shop_id'] ?? null, fn ($query, $id) => $query->whereKey($id))
            ->with('creditEntries')
            ->orderBy('name')
            ->get();

        [$spreadsheet, $filename] = $this->importer->buildExport($shops);

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        ob_start();
        $writer->save('php://output');
        $contents = (string) ob_get_clean();
        $spreadsheet->disconnectWorksheets();

        return response($contents, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length' => strlen($contents),
            'Cache-Control' => 'no-store',
        ]);
    }
}
