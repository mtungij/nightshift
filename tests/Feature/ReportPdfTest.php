<?php

namespace Tests\Feature;

use App\Contracts\ReportPdfRenderer;
use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shift;
use App\Models\StockLocation;
use App\Models\User;
use App\Services\AuditService;
use App\Services\BusinessUnitService;
use App\Services\TreasuryService;
use Database\Seeders\NightshiftDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CapturingPdfRenderer implements ReportPdfRenderer
{
    public string $html = '';

    public string $orientation = '';

    public int $calls = 0;

    public function render(string $html, string $orientation): string
    {
        $this->html = $html;
        $this->orientation = $orientation;
        $this->calls++;

        return "%PDF-1.7\nfixture";
    }
}
class ReportPdfTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private CapturingPdfRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(NightshiftDemoSeeder::class);
        $this->owner = User::where('email', 'admin@nightshift.test')->firstOrFail();
        $this->actingAs($this->owner);
        $this->renderer = new CapturingPdfRenderer;
        $this->app->instance(ReportPdfRenderer::class, $this->renderer);
    }

    private function filterQuery(array $extra = []): string
    {
        return http_build_query(['from' => now()->toDateString(), 'to' => now()->toDateString(), ...$extra]);
    }

    private function rows(): array
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML($this->renderer->html);
        $xpath = new \DOMXPath($dom);
        $rows = [];
        foreach ($xpath->query('//table/tbody/tr') as $tr) {
            $row = [];
            foreach ($xpath->query('./td', $tr) as $td) {
                $row[] = trim($td->textContent);
            }$rows[] = $row;
        }

        return $rows;
    }

    public function test_screen_csv_and_pdf_share_exact_rows_and_filters(): void
    {
        $branch = Branch::firstOrFail();
        $url = '/api/nightshift/reports/Daily%20Sales';
        $query = $this->filterQuery(['branch_id' => $branch->id]);
        $screen = $this->getJson($url.'?'.$query)->assertOk()->json();
        $csv = $this->get($url.'/export?'.$query)->assertOk()->streamedContent();
        $this->get($url.'/pdf?'.$query)->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame($screen['rows'], $this->rows());
        foreach ($screen['rows'] as $row) {
            foreach (array_slice($row, 3, 3) as $amount) {
                $this->assertStringContainsString((string) $amount, $csv);
            }
        }
        $this->assertStringContainsString($branch->name, $this->renderer->html);
        $this->assertStringContainsString($this->owner->name, $this->renderer->html);
        $this->assertStringContainsString(now()->toDateString(), $this->renderer->html);
    }

    public function test_supported_counter_unit_staff_product_and_category_filters_match_screen_and_pdf(): void
    {
        $order = Order::whereNotNull('issued_at')->whereNotIn('status', ['VOIDED', 'CANCELLED', 'DRAFT', 'WAITING_FOR_ISSUE'])->firstOrFail();
        $item = $order->items()->firstOrFail();
        $filters = ['branch_id' => $order->branch_id, 'business_unit_id' => $order->business_unit_id, 'stock_location_id' => $order->stock_location_id, 'waiter_id' => $order->waiter_id, 'issued_by' => $order->issued_by, 'product_id' => $item->product_id, 'category_id' => Product::findOrFail($item->product_id)->category_id];
        $query = $this->filterQuery($filters);
        $screen = $this->getJson('/api/nightshift/reports/Daily%20Sales?'.$query)->assertOk()->json();
        $this->get('/api/nightshift/reports/Daily%20Sales/pdf?'.$query)->assertOk();
        $this->assertSame($screen['rows'], $this->rows());
        $this->assertNotEmpty($screen['rows']);
    }

    public function test_pdf_ignores_navigation_page_and_exports_all_filtered_pages_or_rejects_oversized_report(): void
    {
        $base = (array) DB::table('orders')->whereNotNull('issued_at')->first();
        unset($base['id']);
        for ($i = 0; $i < 105; $i++) {
            Order::create([...$base, 'invoice_number' => 'PDF-PAGE-'.$i, 'issued_at' => now(), 'created_at' => now()]);
        }
        $this->get('/api/nightshift/reports/Daily%20Sales/pdf?'.$this->filterQuery(['page' => 2]))->assertOk();
        $rows = $this->rows();
        $this->assertGreaterThan(100, count($rows));
        $this->assertStringContainsString('PDF-PAGE-0', $this->renderer->html);
        $this->assertStringContainsString('PDF-PAGE-104', $this->renderer->html);
        config(['report_pdf.max_rows' => 100]);
        $before = $this->renderer->calls;
        $this->getJson('/api/nightshift/reports/Daily%20Sales/pdf?'.$this->filterQuery())->assertUnprocessable();
        $this->assertSame($before, $this->renderer->calls);
    }

    public function test_empty_report_and_both_languages_use_pdf_download_labels(): void
    {
        foreach (['en', 'sw'] as $locale) {
            $this->owner->forceFill(['language_preference' => $locale])->save();
            $this->get('/api/nightshift/reports/Daily%20Sales/pdf?from=2001-01-01&to=2001-01-01')->assertOk();
            $this->assertStringContainsString($locale === 'en' ? 'No records found' : 'Hakuna rekodi zilizopatikana', $this->renderer->html);
            $this->get('/audit-trail')->assertOk()->assertSee($locale === 'en' ? 'Download PDF' : 'Pakua PDF');
        }
    }

    public function test_tampered_company_branch_unit_counter_and_staff_are_rejected_before_rendering(): void
    {
        $foreign = User::factory()->create();

        $location = app(TenantContext::class)->run($foreign, function () use ($foreign) {
            $b = Branch::create(['name' => 'Foreign PDF branch']);

            $unit = \App\Models\BusinessUnit::create([
                'branch_id' => $b->id,
                'name' => 'Foreign PDF Operations',
                'code' => 'PDF-'.$b->id,
                'active' => true,
            ]);

            $foreign->branches()->syncWithoutDetaching([
                $b->id => [
                    'company_id' => $foreign->company_id,
                ],
            ]);

            $foreign->businessUnits()->syncWithoutDetaching([
                $unit->id => [
                    'company_id' => $foreign->company_id,
                ],
            ]);

            return StockLocation::create([
                'branch_id' => $b->id,
                'business_unit_id' => $unit->id,
                'name' => 'Foreign PDF counter',
                'type' => 'COUNTER',
            ]);
        });
        foreach (['branch_id' => $location->branch_id, 'stock_location_id' => $location->id, 'waiter_id' => $foreign->id] as $key => $id) {
            $this->getJson('/api/nightshift/reports/Daily%20Sales/pdf?'.$this->filterQuery([$key => $id]))->assertNotFound();
        }
        $this->getJson('/api/nightshift/reports/Daily%20Sales/pdf?'.$this->filterQuery(['company_id' => $foreign->company_id]))->assertForbidden();
        $this->getJson('/api/nightshift/reports/Daily%20Sales/pdf?'.$this->filterQuery(['business_unit_id' => $location->business_unit_id]))->assertForbidden();
        foreach (['/treasury?pdf=1', '/cash-handovers?pdf=1', '/audit-trail?pdf=1'] as $url) {
            $this->getJson($url.'&company_id='.$foreign->company_id)->assertForbidden();
        }
        $this->assertSame(0, $this->renderer->calls);
    }

    public function test_bar_manager_cannot_export_restaurant_or_mismatched_hierarchy(): void
    {
        $service = app(BusinessUnitService::class);
        $branch = Branch::firstOrFail();
        $bar = $service->save(['branch_id' => $branch->id, 'name' => 'Bar PDF', 'code' => 'BARPDF', 'active' => true]);
        $restaurant = $service->save(['branch_id' => $branch->id, 'name' => 'Restaurant PDF', 'code' => 'RESTPDF', 'active' => true]);
        $manager = User::factory()->create(['company_id' => $this->owner->company_id, 'role_id' => Role::where('name', 'Manager')->value('id'), 'active' => true]);
        $manager->branches()->attach($branch->id, ['company_id' => $manager->company_id]);
        $service->assign($manager, [$bar->id]);
        $counter = StockLocation::create(['branch_id' => $branch->id, 'business_unit_id' => $restaurant->id, 'name' => 'Restaurant PDF counter', 'type' => 'COUNTER']);
        $this->actingAs($manager);
        $this->getJson('/api/nightshift/reports/Daily%20Sales/pdf?'.$this->filterQuery(['business_unit_id' => $restaurant->id]))->assertForbidden();
        $this->getJson('/api/nightshift/reports/Daily%20Sales/pdf?'.$this->filterQuery(['stock_location_id' => $counter->id]))->assertNotFound();
        $this->actingAs($this->owner);
        $other = Branch::create(['name' => 'Other PDF branch']);
        $this->getJson('/api/nightshift/reports/Daily%20Sales/pdf?'.$this->filterQuery(['branch_id' => $other->id, 'stock_location_id' => $counter->id]))->assertUnprocessable();
    }

    public function test_unsupported_filters_are_rejected_instead_of_silently_ignored(): void
    {
        foreach (['status' => 'PAID', 'method' => 'Cash', 'search' => 'invoice'] as $field => $value) {
            $this->getJson('/api/nightshift/reports/Daily%20Sales/pdf?'.$this->filterQuery([$field => $value]))->assertUnprocessable();
        }
        $this->getJson('/api/nightshift/reports/Daily%20Sales/pdf?from=2026-11-11&to=2026-11-10')->assertUnprocessable();
    }

    public function test_audit_pdf_is_friendly_filtered_paginated_and_redacted(): void
    {
        for ($i = 0; $i < 30; $i++) {
            app(AuditService::class)->record('USER_SAVED', 'PDF audit', 'User', $this->owner->id, [], ['pin' => 'PDF_SECRET_PIN', 'token' => 'PDF_SECRET_TOKEN', 'private_key' => 'PDF_SECRET_KEY'], Branch::firstOrFail()->id);
        }
        $this->get('/audit-trail?pdf=1&module=PDF%20audit&page=2')->assertOk();
        $this->assertCount(30, $this->rows());
        $this->assertStringContainsString('Staff Member Updated', $this->renderer->html);
        $this->assertStringNotContainsString('PDF_SECRET', $this->renderer->html);
        $this->assertStringNotContainsString('Previous value', $this->renderer->html);
    }

    public function test_treasury_pdf_preserves_totals_and_all_financial_rows_remain_unchanged(): void
    {
        $before = app(TreasuryService::class)->dashboard([]);
        $snap = [];
        foreach (['orders', 'payments', 'waiter_ledger_entries', 'stocks', 'treasury_entries', 'shifts'] as $table) {
            $snap[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $this->get('/treasury?pdf=1&'.$this->filterQuery())->assertOk();
        foreach ($before['totals'] as $amount) {
            $this->assertStringContainsString($amount, $this->renderer->html);
        }
        $this->get('/treasury/report?pdf=1&name=Treasury%20Ledger&'.$this->filterQuery())->assertOk();
        $this->get('/cash-handovers?pdf=1')->assertOk();
        foreach ($snap as $table => $json) {
            $this->assertSame($json, DB::table($table)->orderBy('id')->get()->toJson());
        }
    }

    public function test_shift_pdf_uses_existing_reconciliation_and_overnight_context(): void
    {
        $shift = Shift::where('type', 'CASHIER')->firstOrFail();
        $report = $this->getJson('/api/nightshift/shift-stock/'.$shift->id)->assertOk()->json();
        $this->get('/shift-stock/'.$shift->id.'/pdf')->assertOk();
        $this->assertSame('landscape', $this->renderer->orientation);
        $this->assertStringContainsString($report['number'], $this->renderer->html);
        $this->assertStringContainsString($report['cash']['expected'], $this->renderer->html);
        $this->assertStringContainsString('Generated by', $this->renderer->html);
    }
}
