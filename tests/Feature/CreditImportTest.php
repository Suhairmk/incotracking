<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\ShopCreditEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * Excel in and out for the credit ledger: a Tally outstanding statement is
 * parsed into an editable preview, the confirmed rows create shops and
 * transactions exactly once, and the whole ledger exports back to Excel.
 */
class CreditImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    /**
     * A miniature Tally statement: two parties with vouchers and totals, one
     * with money already paid against the voucher.
     *
     * @return list<list<mixed>>
     */
    private function statementRows(): array
    {
        return [
            ['INDU COMPONENTS 2026-2027'],
            ['Customer OutStanding : Customer : <ALL> as on 17-Nov-2026'],
            [],
            [],
            [],
            ['A T ASSOCIATES [A T ASSOCIATES]', '', 'MAVOOR', '', '', 'Phone : 9495001111'],
            ['VchDate', 'Particulars', 'VchType', 'VchNo', '', 'DelayDays', 'Amount', 'Paid', 'Balance', '', 'Last Transaction Details'],
            [46342, 'To A T ASSOCIATES', 'Sales', '26-27/INCO/285', '', '', 6199, 0, 6199],
            ['Total', '', '', '', '', '', 6199, 0, 6199],
            ['____', '____', '____'],
            ['ACON ELECTRICALS [ACON ELECTRICALS]', '', 'THIRUVALOOR,', '', '', 'Phone : 9495146511'],
            ['VchDate', 'Particulars', 'VchType', 'VchNo', '', 'DelayDays', 'Amount', 'Paid', 'Balance', '', 'Last Transaction Details'],
            [46289, 'To ACON ELECTRICALS', 'Sales', '26-27/INCO/196', '', '', 27836, 19904, 7932],
            ['Total', '', '', '', '', '', 27836, 19904, 7932],
            ['____', '____', '____'],
            ['KANIKA TRADING [KANIKA TRADING]', '', 'KOZHIKODE', '', '', 'Phone : 29ABCDE1234F1Z5'],
            ['VchDate', 'Particulars', 'VchType', 'VchNo', '', 'DelayDays', 'Amount', 'Paid', 'Balance', '', 'Last Transaction Details'],
            [46111, 'To KANIKA TRADING', 'Opening', 'Opening Balnce', '', '', 50000, 0, 50000],
            ['Total', '', '', '', '', '', 50000, 0, 50000],
        ];
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function workbookFile(array $rows, string $name = 'statement.xlsx'): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($rows, null, 'A1');

        ob_start();
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save('php://output');
        $binary = (string) ob_get_clean();
        $spreadsheet->disconnectWorksheets();

        return UploadedFile::fake()->createWithContent($name, $binary);
    }

    public function test_the_statement_is_parsed_into_an_editable_preview(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->post('/api/v1/admin/credit-import/preview', [
            'file' => $this->workbookFile($this->statementRows()),
        ], ['Accept' => 'application/json']);

        $response->assertOk();
        $rows = $response->json('data.rows');

        $this->assertCount(3, $rows);

        // First party: one sale, no money received yet.
        $this->assertSame('A T ASSOCIATES', $rows[0]['shop']['name']);
        $this->assertSame('MAVOOR', $rows[0]['shop']['area']);
        $this->assertTrue($rows[0]['shop']['is_new']);
        $this->assertSame(619900, $rows[0]['shop']['balance_paise']);
        $this->assertSame(619900, $rows[0]['entries'][0]['amount_paise']);
        $this->assertSame('sale', $rows[0]['entries'][0]['type']);

        // The paid column becomes a receipt against the same voucher, so the
        // ledger adds up to the balance the statement prints.
        $this->assertSame('ACON ELECTRICALS', $rows[1]['shop']['name']);
        $this->assertSame(793200, $rows[1]['shop']['balance_paise']);
        $this->assertCount(2, $rows[1]['entries']);
        $this->assertSame(2783600, $rows[1]['entries'][0]['amount_paise']);
        $this->assertSame('receipt', $rows[1]['entries'][1]['type']);
        $this->assertSame(-1990400, $rows[1]['entries'][1]['amount_paise']);
        $this->assertSame(
            array_sum(array_column($rows[1]['entries'], 'amount_paise')),
            $rows[1]['shop']['balance_paise']
        );

        // A GSTIN where the phone should be lands in the GST field instead.
        $this->assertSame('29ABCDE1234F1Z5', $rows[2]['shop']['gst_number']);
        $this->assertNull($rows[2]['shop']['phone']);
        $this->assertSame('opening', $rows[2]['entries'][0]['type']);

        $this->assertSame(3, $response->json('data.summary.shops'));
        $this->assertSame(3, $response->json('data.summary.new_shops'));
        $this->assertSame(6413100, $response->json('data.summary.balance_paise'));

        // Nothing has been written yet: a preview is only a preview.
        $this->assertDatabaseCount('shops', 0);
        $this->assertDatabaseCount('shop_credit_entries', 0);
    }

    public function test_the_confirmed_preview_creates_shops_and_transactions_once(): void
    {
        Sanctum::actingAs($this->admin);

        $preview = $this->post('/api/v1/admin/credit-import/preview', [
            'file' => $this->workbookFile($this->statementRows()),
        ], ['Accept' => 'application/json'])->assertOk()->json('data.rows');

        // The administrator reviews, drops one party and assigns staff to
        // another before confirming.
        $preview[2]['deleted'] = true;
        $preview[1]['employee_ids'] = [];

        $employee = User::factory()->create();
        $preview[0]['employee_ids'] = [$employee->id];

        $result = $this->postJson('/api/v1/admin/credit-import/commit', ['rows' => $preview])
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $result['shops_created']);
        $this->assertSame(3, $result['entries_created']);

        $this->assertDatabaseCount('shops', 2);
        $this->assertDatabaseHas('shops', [
            'name' => 'A T ASSOCIATES',
            'location_name' => 'MAVOOR',
            'phone' => '9495001111',
            'latitude_e7' => 0,
        ]);
        $this->assertDatabaseMissing('shops', ['name' => 'KANIKA TRADING']);

        // The granted executive holds a seat; the untouched party has none.
        $shop = Shop::query()->where('name', 'A T ASSOCIATES')->sole();
        $this->assertTrue($shop->staff()->whereKey($employee->id)->exists());

        // Outstanding balances are the sum of the imported transactions.
        $this->assertSame(619900, (int) $shop->fresh()->credit_balance_paise);
        $this->assertSame(
            619900,
            (int) ShopCreditEntry::query()->where('shop_id', $shop->id)->sum('amount_paise')
        );

        $acon = Shop::query()->where('name', 'ACON ELECTRICALS')->sole();
        $this->assertSame(793200, (int) $acon->credit_balance_paise);
        $this->assertSame(
            ShopCreditEntry::SOURCE_IMPORT,
            ShopCreditEntry::query()->where('shop_id', $acon->id)->first()->source
        );

        // Importing the same statement again writes nothing new.
        $again = $this->postJson('/api/v1/admin/credit-import/commit', ['rows' => $preview])
            ->assertOk()
            ->json('data');

        $this->assertSame(0, $again['shops_created']);
        $this->assertSame(0, $again['entries_created']);
        $this->assertSame(3, $again['entries_skipped']);
        $this->assertDatabaseCount('shop_credit_entries', 3);
    }

    public function test_the_ledger_exports_as_a_workbook(): void
    {
        $employee = User::factory()->create();
        $shop = Shop::factory()->create(['created_by' => $employee->id, 'name' => 'Export Me']);
        ShopCreditEntry::factory()->sale(118000)->create([
            'shop_id' => $shop->id,
            'order_id' => null,
            'entry_date' => '2026-11-18',
        ]);

        Sanctum::actingAs($this->admin);

        $response = $this->get('/api/v1/admin/credit-export', ['Accept' => 'application/json']);

        $response->assertOk();
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type')
        );
        // An .xlsx is a zip container: "PK" is its magic number.
        $this->assertSame('PK', substr($response->getContent(), 0, 2));
        $this->assertStringContainsString(
            'credit-ledger-',
            (string) $response->headers->get('Content-Disposition')
        );
    }

    public function test_only_administrators_may_import_or_export(): void
    {
        $employee = User::factory()->create();
        Sanctum::actingAs($employee);

        $this->post('/api/v1/admin/credit-import/preview', [
            'file' => $this->workbookFile($this->statementRows()),
        ], ['Accept' => 'application/json'])->assertForbidden();

        $this->getJson('/api/v1/admin/credit-export')->assertForbidden();
    }

    public function test_a_file_that_is_not_a_statement_is_rejected_with_a_useful_message(): void
    {
        Sanctum::actingAs($this->admin);

        $notExcel = UploadedFile::fake()->createWithContent('notes.txt', 'this is not a spreadsheet');

        $this->post('/api/v1/admin/credit-import/preview', [
            'file' => $notExcel,
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }
}
