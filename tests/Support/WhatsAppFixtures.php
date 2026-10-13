<?php

namespace Tests\Support;

use App\Jobs\WhatsApp\SendOperationalAlertJob;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\Shift;
use App\Models\StockLocation;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\WhatsAppRecipient;
use App\Models\WhatsAppSetting;
use App\Services\GoodsReceiptService;
use App\Services\OrderService;
use App\Services\ShiftService;
use App\Services\WhatsApp\CompanyContext;
use App\Services\WhatsApp\RecipientService;
use App\Services\WhatsApp\SettingsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

trait WhatsAppFixtures
{
    protected User $owner;

    protected User $waiter;

    protected User $cashier;

    protected Branch $branch;

    protected Branch $otherBranch;

    protected StockLocation $counter;

    protected Product $product;

    protected ProductUnit $piece;

    protected Shift $cashierShift;

    protected WhatsAppSetting $settings;

    protected WhatsAppRecipient $recipient;

    protected function setupWhatsApp(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        config(['gowa.password' => 'fake-password-not-for-real-use', 'gowa.username' => 'fake-user']);
        $this->owner = User::factory()->create(['name' => 'WhatsApp Owner']);
        $this->actingAs($this->owner);
        $this->branch = Branch::create(['name' => 'Masaki']);
        $this->otherBranch = Branch::create(['name' => 'Mbezi']);
        $this->counter = StockLocation::create(['branch_id' => $this->branch->id, 'name' => 'Main Counter', 'type' => 'COUNTER']);
        $category = Category::create(['name' => 'Beer']);
        $unit = Unit::create(['name' => 'Bottle']);
        $this->product = Product::create(['name' => 'Serengeti', 'sku' => 'WA-TEST', 'category_id' => $category->id, 'base_unit_id' => $unit->id, 'purchase_cost' => '2000', 'minimum_stock' => '24']);
        $this->piece = ProductUnit::create(['product_id' => $this->product->id, 'unit_id' => $unit->id, 'conversion_quantity' => '1', 'selling_price' => '3000']);
        $supplier = Supplier::create(['name' => 'Test supplier']);
        app(GoodsReceiptService::class)->post(['stock_location_id' => $this->counter->id, 'supplier_id' => $supplier->id, 'received_on' => now()->toDateString(), 'items' => [['product_unit_id' => $this->piece->id, 'quantity' => '100', 'unit_cost' => '2000']]]);
        $this->waiter = $this->whatsAppStaff('Waiter');
        $this->cashier = $this->whatsAppStaff('Cashier');
        app(ShiftService::class)->open(['user_id' => $this->waiter->id, 'stock_location_id' => $this->counter->id, 'type' => 'WAITER']);
        $this->cashierShift = app(ShiftService::class)->open(['user_id' => $this->cashier->id, 'stock_location_id' => $this->counter->id, 'type' => 'CASHIER']);
        $this->settings = app(SettingsService::class)->current();
        $this->settings->update(['device_id' => 'company-'.$this->owner->company_id, 'provisioned' => true, 'connection_status' => 'CONNECTED', 'options' => [...config('whatsapp.defaults'), 'enabled' => true, 'daily_report' => true, 'daily_time' => '00:00']]);
        $this->recipient = app(RecipientService::class)->save(['name' => 'Owner Recipient', 'phone' => '0629123456', 'label' => 'Owner', 'all_branches' => true, 'branch_ids' => [], 'daily_report' => true, 'shift_reports' => true, 'critical_alerts' => true, 'active' => true]);
    }

    protected function whatsAppStaff(string $role): User
    {
        $user = User::factory()->create(['company_id' => $this->owner->company_id, 'role_id' => Role::where('name', $role)->firstOrFail()->id]);
        $user->branches()->attach($this->branch->id, ['company_id' => $this->owner->company_id]);

        return $user;
    }

    protected function issueWhatsAppOrder(string $quantity = '2'): Order
    {
        $this->actingAs($this->owner);
        $order = app(OrderService::class)->create(['stock_location_id' => $this->counter->id, 'waiter_id' => $this->waiter->id, 'status' => 'WAITING_FOR_ISSUE', 'discount' => '0', 'items' => [['product_unit_id' => $this->piece->id, 'quantity' => $quantity]]]);
        $this->actingAs($this->cashier);
        $order = app(OrderService::class)->issue($order->id);
        $this->actingAs($this->owner);

        return $order;
    }

    protected function connectedFake(): void
    {
        Http::fake([
            'notify.buildcore.site/devices/*/status' => Http::response(['code' => 'SUCCESS', 'results' => ['is_connected' => true, 'is_logged_in' => true]], 200),
            'notify.buildcore.site/send/message' => Http::response(['code' => 'SUCCESS', 'results' => ['message_id' => 'FAKE-MESSAGE-ID']], 200),
        ]);
    }

    protected function scanWhatsApp(): void
    {
        (new SendOperationalAlertJob($this->owner->company_id))->handle(app(CompanyContext::class));
    }
}
