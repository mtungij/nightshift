<?php

namespace App\Services\WhatsApp;

use App\Domain\Decimal as D;
use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\Shift;
use App\Models\ShiftStockSnapshot;
use App\Models\User;
use App\Models\WhatsAppDeliveryLog;
use App\Services\CashHandoverService;
use App\Services\DashboardService;
use App\Services\OperationalPeriodReportService;
use App\Services\ReportPdfService;
use App\Services\ReportService;
use App\Services\ReportShiftService;
use App\Support\QuantityFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

final class ReportBuilder
{
    private function label(string $en, string $sw, string $language): string
    {
        return $language === 'sw' ? $sw : $en;
    }

    public function money(string|int $value): string
    {
        $company = app(TenantContext::class)->user()->company;
        $decimal = D::money((string) $value);
        [$whole, $fraction] = explode('.', $decimal);
        $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole);

        return ($company->currency === 'TZS' ? 'TSh' : $company->currency).' '.$whole.($fraction === '00' ? '' : '.'.$fraction);
    }

    /** @param array<string, mixed> $filters
     * @return list<list<mixed>>
     */
    private function rows(string $report, array $filters): array
    {
        $rows = [];
        for ($page = 1; ; $page++) {
            $result = app(ReportService::class)->generate($report, [...$filters, 'page' => $page]);
            array_push($rows, ...$result['rows']);
            if (! $result['has_more']) {
                return $rows;
            }
        }
    }

    /** @param list<int> $branches
     * @return array<string, mixed>
     */
    public function dailyData(string $date, array $branches): array
    {
        $company = app(TenantContext::class)->user()->company;
        $from = CarbonImmutable::parse($date, $company->timezone ?: 'Africa/Dar_es_Salaam')->startOfDay()->timezone(config('app.timezone'));
        $to = $from->timezone($company->timezone ?: 'Africa/Dar_es_Salaam')->endOfDay()->timezone(config('app.timezone'));
        $data = ['sales' => '0', 'collected' => '0', 'outstanding' => '0', 'complimentary' => '0', 'breakages' => '0', 'voids' => '0', 'cash_shortage' => '0', 'cash_difference' => '0', 'returned' => '0', 'debits' => '0', 'credits' => '0', 'units' => '0', 'open_orders' => 0, 'payment_methods' => [], 'branches' => [], 'products' => [], 'waiters' => []];
        foreach ($branches as $branchId) {
            $branch = Branch::findOrFail($branchId);
            $metrics = app(DashboardService::class)->metricsBetween($branchId, $from, $to);
            foreach (['sales', 'collected', 'outstanding', 'complimentary', 'breakages', 'voids', 'cash_difference', 'cash_shortage'] as $key) {
                $data[$key] = D::add($data[$key], $metrics[$key]);
            }
            $data['open_orders'] += $metrics['open_orders'];
            $data['branches'][] = ['name' => $branch->name, 'sales' => $metrics['sales']];
            foreach ($metrics['payment_methods'] as $method => $amount) {
                $data['payment_methods'][$method] = D::add($data['payment_methods'][$method] ?? '0', (string) $amount);
            }
            $filters = ['from' => $date, 'to' => $date, 'timezone' => $company->timezone, 'branch_id' => $branchId];
            foreach ($this->rows('Sales by Product', $filters) as $row) {
                $key = (string) $row[0];
                $data['units'] = D::add($data['units'], (string) $row[1], 6);
                $data['products'][$key] = D::add($data['products'][$key] ?? '0', (string) $row[1], 6);
            }
            foreach ($this->rows('Sales by Waiter', $filters) as $row) {
                $key = (string) $row[0];
                $data['waiters'][$key] = D::add($data['waiters'][$key] ?? '0', (string) $row[1]);
            }
            foreach ($this->rows('Waiter Settlement', $filters) as $row) {
                $data['debits'] = D::add($data['debits'], (string) $row[1]);
                $data['credits'] = D::add($data['credits'], (string) $row[2]);
            }
            foreach ($this->rows('Returns', $filters) as $row) {
                $data['returned'] = D::add($data['returned'], (string) $row[2]);
            }
        }
        uasort($data['products'], fn ($a, $b) => -D::cmp($a, $b, 6));
        uasort($data['waiters'], fn ($a, $b) => -D::cmp($a, $b));

        return $data;
    }

    public function daily(WhatsAppDeliveryLog $log): string
    {
        if (! empty($log->payload['period_start'])) {
            return $this->period($log);
        }
        $company = app(TenantContext::class)->user()->company;
        $lang = (string) ($log->payload['language'] ?? 'sw');
        $date = (string) $log->payload['date'];
        $data = $this->dailyData($date, $log->scope_branch_ids);
        $t = fn ($en, $sw) => $this->label($en, $sw, $lang);
        $methods = $data['payment_methods'];
        $mobile = D::add(D::add($methods['M-Pesa'] ?? '0', $methods['Airtel Money'] ?? '0'), $methods['Mixx by Yas'] ?? '0');
        $lines = ['🍻 *NIGHTSHIFT - '.$t('DAILY OWNER REPORT', 'TAARIFA YA LEO').'*', '', $t('Business', 'Biashara').': '.$company->name, $t('Date', 'Tarehe').': '.CarbonImmutable::parse($date)->format('d/m/Y'), ''];
        foreach ($data['branches'] as $branch) {
            $lines[] = $branch['name'].': '.$this->money($branch['sales']);
        }
        $lines = [...$lines, '', '💰 *'.$t('SALES', 'MAUZO').'*', $t('Total sales', 'Jumla ya Mauzo').': '.$this->money($data['sales']), 'Cash: '.$this->money($methods['Cash'] ?? '0'), $t('Mobile Money', 'Malipo ya Simu').': '.$this->money($mobile), $t('Bank / Card', 'Benki / Kadi').': '.$this->money(D::add($methods['Bank'] ?? '0', $methods['Card'] ?? '0')), '', '👥 *'.$t('WAITERS', 'WAHUDUMU').'*', $t('Ledger debits today', 'Thamani Iliyoingia Leo').': '.$this->money($data['debits']), $t('Ledger credits today', 'Malipo na Marekebisho Leo').': '.$this->money($data['credits']), $t('Outstanding balance', 'Deni Lililobaki').': '.$this->money($data['outstanding']), '', '📦 *STOCK*', $t('Units sold', 'Bidhaa Zilizouzwa').': '.QuantityFormatter::format($data['units']), $t('Breakage', 'Vilivyovunjika').': '.$this->money($data['breakages']), $t('Complimentary', 'Vya Bure').': '.$this->money($data['complimentary']), $t('Returns', 'Marejesho').': '.$this->money($data['returned']), '', '⚠️ *'.$t('CONTROL', 'UDHIBITI').'*', $t('Voids', 'Oda Zilizofutwa').': '.$this->money($data['voids']), $t('Cash shortage', 'Pungufu ya Cash').': '.$this->money($data['cash_shortage'])];
        if ($data['products'] !== []) {
            $key = array_key_first($data['products']);
            $lines[] = '\n🏆 '.$t('Top product', 'Bidhaa Inayouzwa Zaidi').': '.$key.' — '.QuantityFormatter::format($data['products'][$key]);
        }
        if ($data['waiters'] !== []) {
            $key = array_key_first($data['waiters']);
            $lines[] = '🏆 '.$t('Top waiter', 'Mhudumu Bora').': '.$key.' — '.$this->money($data['waiters'][$key]);
        }
        $lines[] = $t('Open orders', 'Oda Zilizo Wazi').': '.$data['open_orders'];
        $lines[] = '\nGenerated by Nightshift';

        return str_replace('\\n', "\n", implode("\n", $lines));
    }

    public function shift(WhatsAppDeliveryLog $log): string
    {
        $shift = Shift::findOrFail($log->reference_id);
        if ($shift->status !== 'CLOSED' || $shift->type !== 'CASHIER' || ! $shift->closed_at) {
            throw new GowaException('This cashier shift has not closed.');
        }
        $lang = (string) ($log->payload['language'] ?? 'sw');
        $t = fn ($en, $sw) => $this->label($en, $sw, $lang);
        $metrics = ['sales' => '0', 'collected' => '0', 'outstanding' => '0', 'payment_methods' => []];
        foreach ($this->rows('Daily Sales', ['shift_id' => $shift->id]) as $row) {
            $metrics['sales'] = D::add($metrics['sales'], (string) $row[3]);
        }
        foreach ($this->rows('Payment Method Report', ['shift_id' => $shift->id]) as $row) {
            $metrics['payment_methods'][$row[0]] = $row[1];
            $metrics['collected'] = D::add($metrics['collected'], (string) $row[1]);
        }
        foreach ($this->rows('Waiter Outstanding', ['shift_id' => $shift->id]) as $row) {
            $metrics['outstanding'] = D::add($metrics['outstanding'], (string) $row[3]);
        }
        $context = app(ReportShiftService::class)->context($shift);
        $handover = app(CashHandoverService::class)->active($shift);
        $custody = $handover ? app(CashHandoverService::class)->present($handover) : null;
        $snapshot = ShiftStockSnapshot::where('shift_id', $shift->id)->where('kind', 'CLOSING')->first();
        $methods = $metrics['payment_methods'];

        return implode("\n", ['🌙 *'.$t('SHIFT CLOSED', 'ZAMU IMEFUNGWA').'*', '', 'Shift: '.$shift->number, $t('Opened', 'Ilifunguliwa').': '.$context['opened_at'], $t('Closed', 'Ilifungwa').': '.$context['closed_at'], $t('Business Unit', 'Kitengo cha Biashara').': '.$context['business_unit'], $t('Branch', 'Tawi').': '.$context['branch'], ...(($context['show_location'] ?? true) ? [$t('Counter', 'Kaunta').': '.$context['counter']] : []), $t('Cashier', 'Keshia').': '.User::where('company_id', $shift->company_id)->findOrFail($shift->user_id)->name, '', $t('Sales', 'Mauzo').': '.$this->money($metrics['sales']), 'Cash: '.$this->money($methods['Cash'] ?? '0'), $t('Mobile Money', 'Malipo ya Simu').': '.$this->money(D::add(D::add($methods['M-Pesa'] ?? '0', $methods['Airtel Money'] ?? '0'), $methods['Mixx by Yas'] ?? '0')), $t('Other collections', 'Malipo Mengine').': '.$this->money(D::sub($metrics['collected'], D::add($methods['Cash'] ?? '0', D::add(D::add($methods['M-Pesa'] ?? '0', $methods['Airtel Money'] ?? '0'), $methods['Mixx by Yas'] ?? '0')))), $t('Expected cash', 'Cash Inayotarajiwa').': '.$this->money($shift->expected_cash), $t('Actual cash', 'Cash Halisi').': '.$this->money($shift->actual_cash), $t('Cash difference', 'Tofauti ya Cash').': '.$this->money($shift->cash_difference), $t('Waiter outstanding', 'Madeni ya Wahudumu').': '.$this->money($metrics['outstanding']), $t('Stock variance', 'Tofauti ya stoo').': '.($snapshot ? $this->money((string) ($snapshot->data['totals']['variance_value'] ?? '0')) : '—'), $t('Cash handover', 'Makabidhiano ya cash').': '.($handover ? $this->money($handover->amount).' · '.($custody['to_name'] ?? '—') : '—'), '', 'Generated by Nightshift']);
    }

    public function period(WhatsAppDeliveryLog $log): string
    {
        if ($log->message_type === 'TREASURY_SUMMARY') {
            Gate::authorize('view-treasury');
        }
        $data = $log->report_data ?? app(OperationalPeriodReportService::class)->build(['include_details' => $log->message_type !== 'TREASURY_SUMMARY' && ($log->payload['format'] ?? 'TEXT') === 'TEXT_PDF', 'period_start' => $log->payload['period_start'], 'period_end' => $log->payload['period_end'], 'branch_ids' => $log->scope_branch_ids, 'business_unit_ids' => $log->scope_business_unit_ids ?? [], 'stock_location_id' => $log->payload['stock_location_id'] ?? null]);
        if (! $log->report_data) {
            $log->update(['report_data' => $data]);
        }
        $lang = (string) ($log->payload['language'] ?? 'sw');
        $t = fn ($en, $sw) => $this->label($en, $sw, $lang);
        $zone = $log->payload['timezone'] ?? app(TenantContext::class)->user()->company->timezone;
        $kind = match ($log->message_type) {
            'WEEKLY' => $t('WEEKLY REPORT', 'TAARIFA YA WIKI'),'MONTHLY' => $t('MONTHLY REPORT', 'TAARIFA YA MWEZI'),'TREASURY_SUMMARY' => $t('TREASURY SUMMARY', 'MUHTASARI WA HAZINA'),default => $t('DAILY REPORT', 'TAARIFA YA SIKU')
        };
        $lines = ['*NIGHTSHIFT — '.$kind.'*', app(TenantContext::class)->user()->company->name, CarbonImmutable::parse($data['period_start'])->timezone($zone)->format('d M Y H:i').' → '.CarbonImmutable::parse($data['period_end'])->timezone($zone)->format('d M Y H:i').' ('.$zone.')', ''];
        $m = $data['metrics'];
        if ($log->message_type !== 'TREASURY_SUMMARY') {
            $lines[] = '*'.$t('Sales / Orders', 'Mauzo / Oda').'*';
            foreach (['sales' => ['Total sales', 'Jumla ya mauzo'], 'outstanding' => ['Outstanding at shift close / live', 'Deni wakati wa kufunga zamu / sasa']] as $key => $label) {
                $lines[] = $t(...$label).': '.$this->money($m[$key]);
            }
            foreach ($data['payment_methods'] as $method => $amount) {
                $lines[] = $method.': '.$this->money($amount);
            }
            $lines[] = $t('Orders / Paid / Unpaid', 'Oda / Zilizolipwa / Zisizolipwa').': '.$m['orders'].' / '.$m['paid_orders'].' / '.$m['unpaid_orders'];
            $lines[] = '';
            $lines[] = '*'.$t('Waiter sessions', 'Zamu za wahudumu').'*';
            foreach (['waiter_issued' => ['Issued', 'Zilizotolewa'], 'waiter_settled' => ['Settled / credited', 'Zilizolipwa / zilizosahihishwa'], 'waiter_outstanding' => ['Outstanding', 'Deni']] as $key => $label) {
                $lines[] = $t(...$label).': '.$this->money($m[$key]);
            }
            $lines[] = $t('Units sold', 'Bidhaa Zilizouzwa').': '.QuantityFormatter::format($m['units']);
            foreach (array_slice($data['stock_reconciliations'], 0, 10) as $stock) {
                $lines[] = (($stock['show_location'] ?? true) ? $stock['counter'] : $stock['business_unit']).' · '.$t('Stock variance', 'Tofauti ya stoo').': '.$this->money((string) ($stock['totals']['variance_value'] ?? '0'));
            }
            $lines[] = '';
            $lines[] = '*'.$t('Cashiers', 'Makashia').'*';
            foreach (['expected_cash' => ['Expected cash (closed / live)', 'Cash inayotarajiwa (zilizofungwa / sasa)'], 'actual_cash' => ['Counted cash (closed shifts)', 'Cash iliyohesabiwa (zamu zilizofungwa)'], 'cash_difference' => ['Difference (closed shifts)', 'Tofauti (zamu zilizofungwa)']] as $key => $label) {
                $lines[] = $t(...$label).': '.$this->money($m[$key]);
            }
            foreach (array_slice($data['branches'], 0, 20, true) as $name => $amount) {
                $lines[] = $name.': '.$this->money($amount);
            }
            if ($log->payload['unit_breakdown'] ?? true) {
                foreach (array_slice($data['business_units'], 0, 20, true) as $name => $amount) {
                    $lines[] = $name.': '.$this->money($amount);
                }
            }
        }
        $lines[] = '';
        if ($data['can_view_treasury'] ?? true) {
            $lines[] = '*'.$t('Treasury', 'Hazina').'*';
            $lines[] = $t('Cash received from source shifts', 'Cash iliyopokelewa kutoka zamu husika').': '.$this->money($m['cash_received']);
            $treasuryLabels = ['Cash Used for Purchases' => ['Purchases', 'Manunuzi'], 'Expenses' => ['Expenses', 'Matumizi'], 'Salaries and Wages' => ['Wages', 'Mishahara'], 'Bank Deposits' => ['Bank deposits', 'Amana za benki'], 'Cash Custody Balance' => ['Available treasury cash (current)', 'Cash ya hazina iliyopo (sasa)']];
            foreach ($data['treasury'] as $key => $amount) {
                $lines[] = $t(...$treasuryLabels[$key]).': '.$this->money($amount);
            }
        }
        $lines[] = '';
        $lines[] = $t('Closed / Open shifts', 'Zamu zilizofungwa / zilizo wazi').': '.$m['closed_shifts'].' / '.$m['open_shifts'];
        $live = array_values(array_filter([...$data['shifts'], ...$data['carryover_open_shifts']], fn ($shift) => $shift['status'] === 'Open / Live'));
        foreach (array_slice($live, 0, 10) as $shift) {
            if ($shift['status'] === 'Open / Live') {
                $lines[] = '*'.$t('OPEN / LIVE', 'IPO WAZI / INAENDELEA').'* '.$shift['number'].' · '.$shift['employee'].' · '.$shift['business_unit'].(($shift['show_location'] ?? true) ? ' · '.$shift['counter'] : '').' · '.$shift['opened_at'];
            }
        }
        if ($data['carryover_open_shifts'] !== []) {
            $lines[] = $t('Earlier open shifts are listed separately; their sales are not counted again.', 'Zamu za awali zilizo wazi zimeorodheshwa kando; mauzo yake hayajahesabiwa tena.');
        }
        if (count($data['branches']) > 20 || count($data['business_units']) > 20 || count($data['stock_reconciliations']) > 10 || count($live) > 10) {
            $lines[] = $t('Detail lists are shortened; totals are complete. See the PDF or Nightshift.', 'Orodha zimefupishwa; jumla ni kamili. Angalia PDF au Nightshift.');
        }
        $lines[] = $t('Generated', 'Imetengenezwa').': '.CarbonImmutable::parse($data['generated_at'])->timezone($zone)->format('d M Y H:i');

        return implode("\n", $lines);
    }

    public function attachment(WhatsAppDeliveryLog $log): string
    {
        if ($log->attachment_path && Storage::disk('local')->exists($log->attachment_path)) {
            return Storage::disk('local')->get($log->attachment_path);
        }
        $message = (string) $log->message;
        $rows = array_map(fn ($line) => [trim(str_replace('*', '', $line))], explode("\n", $message));
        $previous = app()->getLocale();
        try {
            app()->setLocale($log->payload['language'] ?? 'sw');
            $sections = [['headers' => ['Summary'], 'rows' => $rows]];
            foreach ($log->message_type === 'TREASURY_SUMMARY' ? [] : ($log->report_data['source_reports'] ?? []) as $section) {
                if (! is_array($section) || ! is_array($section['headers'] ?? null) || ! is_array($section['rows'] ?? null)) {
                    throw new GowaException('The saved report details are unavailable.');
                }
                $sections[] = ['title' => (string) ($section['title'] ?? ''), 'headers' => $section['headers'], 'rows' => $section['rows']];
            }
            if ($log->report_data && $log->message_type !== 'TREASURY_SUMMARY') {
                foreach (['branches' => 'Branch', 'business_units' => 'Business Unit'] as $key => $label) {
                    if ($key === 'business_units' && ! ($log->payload['unit_breakdown'] ?? true)) {
                        continue;
                    }
                    $detailRows = [];
                    foreach ($log->report_data[$key] ?? [] as $name => $amount) {
                        $detailRows[] = [$name, $this->money($amount)];
                    }
                    if ($detailRows !== []) {
                        $sections[] = ['headers' => [$label, 'Sales'], 'rows' => $detailRows];
                    }
                }
                $stockRows = [];
                foreach ($log->report_data['stock_reconciliations'] ?? [] as $stock) {
                    $stockRows[] = [($stock['business_unit'] ?? $stock['counter']).(isset($stock['business_unit']) && ($stock['show_location'] ?? true) ? ' · '.$stock['counter'] : ''), isset($stock['totals']['variance_value']) ? $this->money($stock['totals']['variance_value']) : '—'];
                }
                if ($stockRows !== []) {
                    $sections[] = ['headers' => ['Business Unit', 'Stock variance'], 'rows' => $stockRows];
                }
                $shiftRows = [];
                $showLocations = collect([...($log->report_data['shifts'] ?? []), ...($log->report_data['carryover_open_shifts'] ?? [])])->contains(fn ($shift) => $shift['show_location'] ?? true);
                foreach ([...($log->report_data['shifts'] ?? []), ...($log->report_data['carryover_open_shifts'] ?? [])] as $shift) {
                    $shiftRows[] = [$shift['number'], $shift['employee'], $shift['business_unit'], ...($showLocations ? [($shift['show_location'] ?? true) ? $shift['counter'] : '—'] : []), $shift['opened_at'], $shift['closed_at'] ?? '—', $shift['status']];
                }
                if ($shiftRows !== []) {
                    $sections[] = ['headers' => ['Shift', 'Employee', 'Business Unit', ...($showLocations ? ['Counter'] : []), 'Opened', 'Closed', 'Status'], 'rows' => $shiftRows];
                }
            }
            app(ReportPdfService::class)->checkLimit(array_sum(array_map(fn ($section) => count($section['rows']), $sections)));
            $response = app(ReportPdfService::class)->download('WhatsApp Report', [], $sections);
            $contents = $response->getContent();
            if (! is_string($contents)) {
                throw new GowaException('The report PDF could not be prepared.');
            }
            $path = 'whatsapp/reports/'.$log->company_id.'/'.$log->id.'.pdf';
            if (! Storage::disk('local')->put($path, $contents)) {
                throw new GowaException('The report PDF could not be stored.');
            }
            $log->update(['attachment_path' => $path]);

            return $contents;
        } finally {
            app()->setLocale($previous);
        }
    }
}
