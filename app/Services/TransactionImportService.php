<?php

namespace App\Services;

use App\Models\Shop;
use App\Models\ShopCreditEntry;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

/**
 * Turns a Tally "Customer Outstanding" workbook into the shop credit ledger.
 *
 * The statement is one block per party: a header row (`NAME [ALIAS]` with the
 * area and phone in C and F), a column header, one row per voucher carrying
 * its date, type, number, amount and the amount already paid against it, then
 * a `Total` row whose Balance is the party's outstanding amount. The preview
 * never writes anything — the administrator edits, deletes and assigns staff
 * first, and only the confirmed payload is saved.
 */
class TransactionImportService
{
    /**
     * Voucher types that raise what the customer owes.
     *
     * @var list<string>
     */
    private const DEBIT_TYPES = ['sale', 'opening'];

    /**
     * @return array<string, mixed>
     */
    public function parse(UploadedFile $file): array
    {
        if (! in_array(strtolower($file->getClientOriginalExtension()), ['xlsx', 'xlsm', 'xls'], true)) {
            throw new RuntimeException('Choose an Excel file (.xlsx).');
        }

        try {
            $reader = IOFactory::createReaderForFile($file->getRealPath());
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($file->getRealPath());
        } catch (\Throwable $e) {
            throw new RuntimeException('That file could not be opened as a spreadsheet.', 0, $e);
        }

        $sheet = $spreadsheet->getActiveSheet();
        $rows = $this->grid($sheet);
        $blocks = $this->blocks($rows);
        $spreadsheet->disconnectWorksheets();

        if ($blocks === []) {
            throw new RuntimeException(
                'No customer outstanding blocks were found. Export the statement from Tally as .xlsx and try again.'
            );
        }

        $knownShops = Shop::query()
            ->get(['id', 'name', 'location_name', 'phone', 'gst_number'])
            ->keyBy(fn (Shop $shop): string => $this->normaliseName($shop->name));

        // One pass over what is already imported, so the preview can grey out
        // transactions a previous import already wrote.
        $alreadyThere = [];
        $existingEntries = ShopCreditEntry::query()
            ->where('source', ShopCreditEntry::SOURCE_IMPORT)
            ->get(['shop_id', 'type', 'amount_paise', 'entry_date', 'reference']);
        foreach ($existingEntries as $existing) {
            $alreadyThere[$this->entryKey(
                $existing->shop_id,
                $existing->type,
                $existing->amount_paise,
                $existing->entry_date?->toDateString(),
                $existing->reference,
            )] = true;
        }

        $payload = [];
        foreach ($blocks as $block) {
            $payload[] = $this->formatBlock($block, $knownShops, $alreadyThere);
        }

        return [
            'rows' => $payload,
            'summary' => [
                'shops' => count($payload),
                'new_shops' => count(array_filter($payload, fn (array $row): bool => $row['shop']['is_new'])),
                'entries' => array_sum(array_map(fn (array $row): int => count($row['entries']), $payload)),
                'balance_paise' => array_sum(array_map(fn (array $row): int => $row['shop']['balance_paise'], $payload)),
            ],
        ];
    }

    /**
     * Saves the confirmed preview: creates new shops, applies the staff and
     * visibility decisions, then appends every transaction that is not
     * already in the ledger (re-importing the same statement is a no-op).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function commit(array $payload, User $actor): array
    {
        $rows = $payload['rows'] ?? [];
        if (! is_array($rows) || $rows === []) {
            throw new RuntimeException('There is nothing to import.');
        }

        $createdShops = 0;
        $createdEntries = 0;
        $skippedEntries = 0;
        $assignedShops = 0;

        foreach ($rows as $row) {
            if (! is_array($row) || ($row['deleted'] ?? false) === true) {
                continue;
            }

            $shopData = is_array($row['shop'] ?? null) ? $row['shop'] : [];
            $shop = $this->resolveShop($shopData, $actor, $createdShops);

            if ($shop === null) {
                throw new RuntimeException('A row is missing its shop name.');
            }

            if (array_key_exists('employee_ids', $row) && is_array($row['employee_ids'])) {
                $shop->staff()->sync(
                    collect($row['employee_ids'])->map(fn ($id): int => (int) $id)->unique()->values()->all()
                );
                $assignedShops++;
            }

            if (array_key_exists('is_visible_to_field', $row)) {
                $shop->update(['is_visible_to_field' => (bool) $row['is_visible_to_field']]);
            }

            foreach (($row['entries'] ?? []) as $entry) {
                if (! is_array($entry) || ($entry['deleted'] ?? false) === true) {
                    continue;
                }

                $amount = (int) ($entry['amount_paise'] ?? 0);
                if ($amount === 0) {
                    continue;
                }

                $type = (string) ($entry['type'] ?? ShopCreditEntry::TYPE_SALE);
                if (! array_key_exists($type, ShopCreditEntry::typeLabels())) {
                    $type = ShopCreditEntry::TYPE_ADJUSTMENT;
                }

                $reference = filled($entry['reference'] ?? null) ? (string) $entry['reference'] : null;

                // Same statement imported twice? Match on the identity of the
                // transaction rather than a client-supplied id.
                $duplicate = ShopCreditEntry::query()
                    ->where('shop_id', $shop->id)
                    ->where('type', $type)
                    ->where('amount_paise', $amount)
                    ->where('entry_date', (string) ($entry['entry_date'] ?? ''))
                    ->when($reference !== null, fn ($query) => $query->where('reference', $reference))
                    ->when($reference === null, fn ($query) => $query->whereNull('reference'))
                    ->exists();

                if ($duplicate) {
                    $skippedEntries++;

                    continue;
                }

                ShopCreditEntry::create([
                    'shop_id' => $shop->id,
                    'type' => $type,
                    'amount_paise' => $amount,
                    'entry_date' => (string) ($entry['entry_date'] ?? now()->toDateString()),
                    'reference' => $reference,
                    'details' => filled($entry['details'] ?? null) ? (string) $entry['details'] : null,
                    'source' => ShopCreditEntry::SOURCE_IMPORT,
                    'created_by' => $actor->id,
                ]);

                $createdEntries++;
            }
        }

        return [
            'shops_created' => $createdShops,
            'entries_created' => $createdEntries,
            'entries_skipped' => $skippedEntries,
            'shops_assigned' => $assignedShops,
            'message' => sprintf(
                '%d transaction%s imported across %d new shop%s.',
                $createdEntries,
                $createdEntries === 1 ? '' : 's',
                $createdShops,
                $createdShops === 1 ? '' : 's'
            ),
        ];
    }

    /**
     * The whole ledger as a workbook: one row per transaction, with the
     * running balance, plus a shop summary sheet.
     *
     * @param  list<Shop>  $shops
     * @return array{0: Spreadsheet, 1: string}
     */
    public function buildExport(iterable $shops): array
    {
        $spreadsheet = new Spreadsheet;
        $ledger = $spreadsheet->getActiveSheet();
        $ledger->setTitle('Transactions');

        $ledger->fromArray([[
            'Shop', 'Area', 'Type', 'Date', 'Reference', 'Details',
            'Debit (INR)', 'Credit (INR)', 'Outstanding (INR)', 'Source',
        ]]);

        $summary = $spreadsheet->createSheet();
        $summary->setTitle('Outstanding');
        $summary->fromArray([['Shop', 'Area', 'Phone', 'Outstanding (INR)', 'Debits (INR)', 'Credits (INR)', 'Transactions']]);

        $rowIndex = 2;
        foreach ($shops as $shop) {
            $entries = $shop->creditEntries;
            $balance = 0;
            $debits = 0;
            $credits = 0;

            foreach ($entries as $entry) {
                $balance += $entry->amount_paise;
                if ($entry->amount_paise > 0) {
                    $debits += $entry->amount_paise;
                } else {
                    $credits += $entry->amount_paise;
                }

                $ledger->setCellValue('A'.$rowIndex, $shop->name);
                $ledger->setCellValue('B'.$rowIndex, $shop->location_name);
                $ledger->setCellValue('C'.$rowIndex, ShopCreditEntry::typeLabels()[$entry->type] ?? $entry->type);
                $ledger->setCellValue('D'.$rowIndex, $entry->entry_date?->toDateString());
                $ledger->setCellValue('E'.$rowIndex, $entry->reference);
                $ledger->setCellValue('F'.$rowIndex, $entry->details);
                $ledger->setCellValue('G'.$rowIndex, $entry->amount_paise > 0 ? $entry->amount_paise / 100 : null);
                $ledger->setCellValue('H'.$rowIndex, $entry->amount_paise < 0 ? abs($entry->amount_paise) / 100 : null);
                $ledger->setCellValue('I'.$rowIndex, $balance / 100);
                $ledger->setCellValue('J'.$rowIndex, $entry->source);
                $rowIndex++;
            }

            // A shop with no history still appears in the summary.
            if ($entries->isEmpty()) {
                $ledger->setCellValue('A'.$rowIndex, $shop->name);
                $ledger->setCellValue('B'.$rowIndex, $shop->location_name);
                $ledger->setCellValue('J'.$rowIndex, 'no transactions');
                $rowIndex++;
            }

            $summary->setCellValue('A'.$rowIndex, $shop->name);
            $summary->setCellValue('B'.$rowIndex, $shop->location_name);
            $summary->setCellValue('C'.$rowIndex, $shop->phone);
            $summary->setCellValue('D'.$rowIndex, $balance / 100);
            $summary->setCellValue('E'.$rowIndex, $debits / 100);
            $summary->setCellValue('F'.$rowIndex, $credits / 100);
            $summary->setCellValue('G'.$rowIndex, $entries->count());
        }

        foreach ([$ledger, $summary] as $sheet) {
            $sheet->getStyle('A1:J1')->getFont()->setBold(true);
            $sheet->freezePane('A2');
            foreach (range('A', 'J') as $column) {
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }
        }

        return [$spreadsheet, 'credit-ledger-'.now()->format('Y-m-d').'.xlsx'];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function blocks(array $rows): array
    {
        $blocks = [];
        $count = count($rows);
        $index = 1;

        while ($index <= $count) {
            $header = $rows[$index]['A'] ?? null;
            $next = $rows[$index + 1]['A'] ?? null;

            // A party header is the only row that both carries an alias in
            // brackets and is immediately followed by the column header.
            if (! is_string($header) || ! str_contains($header, '[') || $next !== 'VchDate') {
                $index++;

                continue;
            }

            $block = [
                'header' => $header,
                'area' => $this->text($rows[$index]['C'] ?? null),
                'phone_or_gstin' => $this->phoneOrGstin($rows[$index]['F'] ?? null),
                'vouchers' => [],
                'balance' => null,
                'paid' => null,
            ];

            $cursor = $index + 2;
            while ($cursor <= $count) {
                $first = $rows[$cursor]['A'] ?? null;

                if ($first === 'Total') {
                    $block['balance'] = $this->number($rows[$cursor]['I'] ?? null);
                    $block['paid'] = $this->number($rows[$cursor]['H'] ?? null);
                    break;
                }

                // The next party starts without a Total of its own being
                // missed: stop so the outer loop reads it as a header.
                if (is_string($first) && str_contains($first, '[')) {
                    break;
                }

                if (is_numeric($first)) {
                    $block['vouchers'][] = $rows[$cursor];
                }

                $cursor++;
            }

            $blocks[] = $block;
            $index = max($cursor, $index + 1);
        }

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  Collection<string, Shop>  $knownShops
     * @param  array<string, true>  $alreadyThere
     * @return array<string, mixed>
     */
    private function formatBlock(array $block, $knownShops, array $alreadyThere): array
    {
        $name = $this->partyName($block['header']);
        $existing = $knownShops->get($this->normaliseName($name));
        $shopId = $existing?->id;

        $entries = [];
        $balance = 0; // paise, so it compares directly with the printed total

        foreach ($block['vouchers'] as $voucher) {
            $date = $this->dateOf($voucher['A'] ?? null);
            $amount = $this->number($voucher['G'] ?? null);
            $paid = $this->number($voucher['H'] ?? null);
            $reference = $this->text($voucher['D'] ?? null) ?: null;
            $typeLabel = $this->text($voucher['C'] ?? null);
            $type = $this->typeOf($typeLabel);

            if ($amount > 0) {
                $signed = in_array($type, self::DEBIT_TYPES, true) ? $amount : -$amount;
                $entryAmount = (int) round($signed * 100);
                $balance += $entryAmount;
                $entries[] = $this->entry(
                    $shopId,
                    $type,
                    $entryAmount,
                    $date,
                    $reference,
                    $typeLabel !== '' ? $typeLabel : null,
                    $alreadyThere,
                );
            }

            // The statement books the money received against the same voucher
            // rather than listing receipts separately, so the paid column
            // becomes its own receipt row on the same day.
            if ($paid > 0 && in_array($type, self::DEBIT_TYPES, true)) {
                $receiptAmount = -(int) round($paid * 100);
                $balance += $receiptAmount;
                $entries[] = $this->entry(
                    $shopId,
                    ShopCreditEntry::TYPE_RECEIPT,
                    $receiptAmount,
                    $date,
                    $reference,
                    'Paid against '.$typeLabel.($reference !== null ? ' '.$reference : ''),
                    $alreadyThere,
                );
            }
        }

        // A block with no printed total (the report ends mid-party) keeps
        // whatever its own transactions add up to.
        $declared = $block['balance'] === null
            ? $balance
            : (int) round(((float) $block['balance']) * 100);

        // Statements occasionally carry a party whose transactions do not
        // add up to the printed total (a return that only appears in the
        // "last transaction" column). One explicit adjustment keeps the
        // ledger equal to what the statement says is owed.
        if ($declared !== $balance) {
            $difference = $declared - $balance;
            $entries[] = $this->entry(
                $shopId,
                ShopCreditEntry::TYPE_ADJUSTMENT,
                $difference,
                now()->toDateString(),
                'STATEMENT-ADJ',
                'Adjustment so the ledger matches the outstanding total printed on the statement',
                $alreadyThere,
            );
        }

        return [
            'shop' => [
                'existing_id' => $existing?->id,
                'name' => $name,
                'area' => $block['area'],
                'phone' => $this->phoneOrNull($block['phone_or_gstin']),
                'gst_number' => $this->gstinOrNull($block['phone_or_gstin']),
                'is_new' => $existing === null,
                'balance_paise' => $declared,
            ],
            'entries' => $entries,
            'balance_matches' => $declared === $balance,
        ];
    }

    /**
     * One ledger row for the preview, flagged when a previous import already
     * wrote exactly this transaction.
     *
     * @param  array<string, true>  $alreadyThere
     * @return array<string, mixed>
     */
    private function entry(
        ?int $shopId,
        string $type,
        int $amountPaise,
        string $date,
        ?string $reference,
        ?string $details,
        array $alreadyThere,
    ): array {
        $key = $this->entryKey($shopId, $type, $amountPaise, $date, $reference);

        return [
            'type' => $type,
            'amount_paise' => $amountPaise,
            'entry_date' => $date,
            'reference' => $reference,
            'details' => $details,
            'already_imported' => isset($alreadyThere[$key]),
        ];
    }

    private function entryKey(?int $shopId, string $type, int $amountPaise, ?string $date, ?string $reference): string
    {
        return implode('|', [$shopId ?? 0, $type, $amountPaise, $date ?? '', $reference ?? '']);
    }

    /**
     * @param  array<string, mixed>  $shopData
     */
    private function resolveShop(array $shopData, User $actor, int &$createdShops): ?Shop
    {
        $name = trim((string) ($shopData['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        if (! empty($shopData['existing_id'])) {
            $shop = Shop::query()->find((int) $shopData['existing_id']);
            if ($shop !== null) {
                return $shop;
            }
        }

        $match = Shop::query()
            ->whereRaw('UPPER(name) = ?', [mb_strtoupper($name)])
            ->first();

        if ($match !== null) {
            return $match;
        }

        $createdShops++;

        return Shop::create([
            'created_by' => $actor->id,
            'name' => Str::limit($name, 120),
            'type' => Shop::TYPE_SHOP,
            'location_name' => Str::limit(trim((string) ($shopData['area'] ?? '')) ?: 'Not set', 120),
            'phone' => filled($shopData['phone'] ?? null) ? Str::limit((string) $shopData['phone'], 30) : null,
            'gst_number' => filled($shopData['gst_number'] ?? null) ? mb_strtoupper((string) $shopData['gst_number']) : null,
            'notes' => 'Imported from the Tally outstanding statement.',
            // Imported rows carry no pin: the executive drops the location
            // the next time they visit the shop.
            'latitude_e7' => 0,
            'longitude_e7' => 0,
            'is_visible_to_field' => (bool) ($shopData['is_visible_to_field'] ?? true),
        ]);
    }

    /**
     * Every cell of the sheet as a row-indexed grid of raw values.
     *
     * @param  Worksheet  $sheet
     * @return array<int, array<string, mixed>>
     */
    private function grid($sheet): array
    {
        $grid = [];
        $highest = (int) $sheet->getHighestRow();

        for ($row = 1; $row <= $highest; $row++) {
            $values = [];
            foreach (range('A', 'K') as $column) {
                $values[$column] = $sheet->getCell($column.$row)->getValue();
            }
            $grid[$row] = $values;
        }

        return $grid;
    }

    private function text(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');
    }

    private function number(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $clean = str_replace([',', '₹'], '', trim($value));
            if (is_numeric($clean)) {
                return (float) $clean;
            }
        }

        return 0.0;
    }

    /**
     * `A T ASSOCIATES [A T ASSOCIATES]` → `A T ASSOCIATES`.
     */
    private function partyName(string $header): string
    {
        $before = strstr($header, '[', true);
        $name = trim($before !== false ? $before : $header);

        return $name !== '' ? $name : trim(preg_replace('/\[.*?\]/', '', $header) ?? $header);
    }

    private function normaliseName(string $name): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/', ' ', $name) ?? ''));
    }

    /**
     * `Phone : 9495146511` — but some exports put the GSTIN here instead.
     */
    private function phoneOrGstin(mixed $cell): string
    {
        $text = $this->text($cell);
        if ($text === '') {
            return '';
        }

        $value = str_contains($text, ':') ? trim(substr($text, (int) strpos($text, ':') + 1)) : $text;

        return $value;
    }

    private function phoneOrNull(string $value): ?string
    {
        if ($value === '' || $this->isGstin($value)) {
            return null;
        }

        return Str::limit(preg_replace('/\D+/', '', $value) ?: $value, 30);
    }

    private function gstinOrNull(string $value): ?string
    {
        return $this->isGstin($value) ? mb_strtoupper($value) : null;
    }

    private function isGstin(string $value): bool
    {
        return (bool) preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', strtoupper(trim($value)));
    }

    /**
     * Voucher types are free text in Tally exports; map them onto the six
     * ledger types the API accepts.
     */
    private function typeOf(string $label): string
    {
        $lower = mb_strtolower($label);

        return match (true) {
            str_contains($lower, 'opening') => ShopCreditEntry::TYPE_OPENING,
            str_contains($lower, 'return'), str_contains($lower, 'credit note') => ShopCreditEntry::TYPE_SALES_RETURN,
            str_contains($lower, 'receipt'), str_contains($lower, 'payment') => ShopCreditEntry::TYPE_RECEIPT,
            str_contains($lower, 'sale') => ShopCreditEntry::TYPE_SALE,
            default => ShopCreditEntry::TYPE_ADJUSTMENT,
        };
    }

    /**
     * Excel stores days since 1900-01-01 with the phantom 29 Feb 1900, which
     * the shared helper already accounts for.
     */
    private function dateOf(mixed $serial): string
    {
        if (is_numeric($serial)) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $serial)->format('Y-m-d');
            } catch (\Throwable) {
                // Fall through to the text branch.
            }
        }

        if (is_string($serial)) {
            $parsed = strtotime($serial);
            if ($parsed !== false) {
                return date('Y-m-d', $parsed);
            }
        }

        return now()->toDateString();
    }
}
