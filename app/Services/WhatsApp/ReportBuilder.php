<?php

namespace App\Services\WhatsApp;

use App\Domain\Decimal as D;
use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\Shift;
use App\Models\StockLocation;
use App\Models\User;
use App\Models\WhatsAppDeliveryLog;
use App\Services\DashboardService;
use App\Services\ReportService;
use App\Support\QuantityFormatter;
use Carbon\CarbonImmutable;

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
        $metrics = app(DashboardService::class)->metricsBetween($shift->branch_id, CarbonImmutable::instance($shift->opened_at), CarbonImmutable::instance($shift->closed_at), $shift->stock_location_id, $shift->id);
        $methods = $metrics['payment_methods'];

        return implode("\n", ['🌙 *'.$t('SHIFT CLOSED', 'ZAMU IMEFUNGWA').'*', '', $t('Branch', 'Tawi').': '.Branch::findOrFail($shift->branch_id)->name, $t('Counter', 'Kaunta').': '.StockLocation::findOrFail($shift->stock_location_id)->name, $t('Cashier', 'Keshia').': '.User::where('company_id', $shift->company_id)->findOrFail($shift->user_id)->name, '', $t('Sales', 'Mauzo').': '.$this->money($metrics['sales']), 'Cash: '.$this->money($methods['Cash'] ?? '0'), $t('Mobile Money', 'Malipo ya Simu').': '.$this->money(D::add(D::add($methods['M-Pesa'] ?? '0', $methods['Airtel Money'] ?? '0'), $methods['Mixx by Yas'] ?? '0')), $t('Other collections', 'Malipo Mengine').': '.$this->money(D::sub($metrics['collected'], D::add($methods['Cash'] ?? '0', D::add(D::add($methods['M-Pesa'] ?? '0', $methods['Airtel Money'] ?? '0'), $methods['Mixx by Yas'] ?? '0')))), $t('Expected cash', 'Cash Inayotarajiwa').': '.$this->money($shift->expected_cash), $t('Actual cash', 'Cash Halisi').': '.$this->money($shift->actual_cash), $t('Cash difference', 'Tofauti ya Cash').': '.$this->money($shift->cash_difference), $t('Waiter outstanding', 'Madeni ya Wahudumu').': '.$this->money($metrics['outstanding']), $t('Breakage', 'Vilivyovunjika').': '.$this->money($metrics['breakages']), $t('Complimentary', 'Vya Bure').': '.$this->money($metrics['complimentary']), '', 'Generated by Nightshift']);
    }
}
