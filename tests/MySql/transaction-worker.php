<?php

use App\Domain\TenantContext;
use App\Jobs\WhatsApp\SendWhatsAppMessageJob;
use App\Models\User;
use App\Models\WhatsAppReportSchedule;
use App\Services\BusinessUnitService;
use App\Services\CashHandoverService;
use App\Services\CounterCashierService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\SalesTicketService;
use App\Services\ShiftService;
use App\Services\StockService;
use App\Services\StockTransferService;
use App\Services\TransactionService;
use App\Services\TreasuryService;
use App\Services\WhatsApp\CompanyContext;
use App\Services\WhatsApp\ScheduleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../bootstrap-mysql.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'mysql_validation') {
    fwrite(STDERR, "Worker requires mysql_validation.\n");
    exit(1);
}
$request = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$user = User::findOrFail($request['user_id']);
echo "READY\n";
flush();
$deadline = microtime(true) + 15;
while (! is_file($request['barrier'])) {
    if (microtime(true) > $deadline) {
        fwrite(STDERR, "Concurrency barrier timed out.\n");
        exit(1);
    }
    usleep(10000);
}
try {
    $result = app(TenantContext::class)->run($user, function () use ($request) {
        if ($request['action'] === 'whatsapp-schedule') {
            Http::preventStrayRequests();
            $schedule = WhatsAppReportSchedule::findOrFail($request['schedule_id']);
            app(ScheduleService::class)->enqueue($schedule, $request['period']);

            return ['id' => $schedule->id];
        }
        if ($request['action'] === 'whatsapp-deliver') {
            $path = $request['record_file'];
            if (! is_string($path) || ! str_starts_with($path, '/tmp/nightshift-wa-send-')) {
                throw new RuntimeException('Invalid validation recording path');
            }
            Http::preventStrayRequests();
            config(['gowa.password' => 'FAKE-VALIDATION-PASSWORD']);
            Http::fake(function ($http) use ($path) {
                if (str_contains($http->url(), '/status')) {
                    return Http::response(['code' => 'SUCCESS', 'results' => ['state' => 'logged_in']]);
                }
                if (str_contains($http->url(), '/send/message')) {
                    file_put_contents($path, "sent\n", FILE_APPEND | LOCK_EX);

                    return Http::response(['code' => 'SUCCESS', 'results' => ['message_id' => 'FAKE-CONCURRENT-ID']]);
                }
                throw new RuntimeException('Unexpected mocked request');
            });
            (new SendWhatsAppMessageJob($request['company_id'], $request['delivery_id']))->handle(app(CompanyContext::class));

            return ['id' => $request['delivery_id']];
        }
        if ($request['action'] === 'unit-command') {
            return app(TransactionService::class)->run(function () use ($request) {
                $service = app(BusinessUnitService::class);
                $data = $request['payload'];
                if ($request['method'] === 'status') {
                    $service->status(User::findOrFail($data['user_id']), $data['active']);

                    return ['id' => $data['user_id']];
                }
                if ($request['method'] === 'assign') {
                    $service->assign(User::findOrFail($data['user_id']), $data['unit_ids']);

                    return ['id' => $data['user_id']];
                }
                if ($request['method'] === 'product') {
                    $service->product($data);

                    return ['id' => $data['product_id']];
                }
                if ($request['method'] === 'location') {
                    return $service->location($data);
                }

                return $service->save($data);
            });
        }
        if ($request['action'] === 'transfer-post') {
            return app(TransactionService::class)->run(function () use ($request) {
                $service = app(StockTransferService::class);
                $transfer = $service->create($request['payload']);
                $service->send($transfer->id);

                return $service->receive($transfer->id);
            });
        }
        if ($request['action'] === 'cash-receive') {
            return app(TransactionService::class)->idempotent($request['key'], 'cash-receive', $request['payload'], fn () => ['id' => app(CashHandoverService::class)->receive($request['payload']['id'], $request['payload']['amount'])->id]);
        }
        if ($request['action'] === 'treasury-spend') {
            return app(TransactionService::class)->idempotent($request['key'], 'treasury-spend', $request['payload'], fn () => ['id' => app(TreasuryService::class)->spend($request['payload'])->id]);
        }
        if ($request['action'] === 'cashier-limit') {
            return app(CounterCashierService::class)->update($request['counter_id'], $request['limit']);
        }
        if ($request['action'] === 'shift-open') {
            return app(ShiftService::class)->open($request['payload']);
        }
        if ($request['action'] === 'waiter-close') {
            return app(ShiftService::class)->close($request['shift_id'], []);
        }
        if ($request['action'] === 'issue') {
            return app(OrderService::class)->issue($request['order_id']);
        }
        if ($request['action'] === 'unit-pos-send') {
            return app(OrderService::class)->send($request['payload'], $request['key']);
        }
        if ($request['action'] === 'send') {
            $result = app(OrderService::class)->send($request['payload'], $request['key']);
            $batch = app(SalesTicketService::class)->prepare($result['id'], $request['key']);

            return [...$result, 'print_batch' => $batch?->id];
        }
        if ($request['action'] === 'ticket-dispatch') {
            return ['id' => 0, 'print' => app(SalesTicketService::class)->dispatch($request['token'])];
        }
        if ($request['action'] === 'ticket-reprint') {
            return app(SalesTicketService::class)->prepare($request['order_id'], $request['key'], true);
        }
        if ($request['action'] === 'waiter-payment') {
            return app(TransactionService::class)->idempotent($request['key'], 'waiter-payment', $request['payload'], fn () => app(PaymentService::class)->receiveWaiter($request['payload']['waiter_id'], $request['payload']['stock_location_id'], $request['payload']['amount'], 'Cash'));
        }
        if ($request['action'] === 'recon-close') {
            return app(TransactionService::class)->idempotent($request['key'], 'shift-close', ['id' => $request['shift_id']], fn () => ['id' => app(ShiftService::class)->close($request['shift_id'], ['actual_cash' => $request['actual_cash'] ?? '300000'])->id]);
        }
        if ($request['action'] === 'recon-move') {
            return app(TransactionService::class)->run(fn () => app(StockService::class)->change($request['location_id'], $request['product_id'], '1', 'GRN', 'Shift', $request['shift_id']));
        }
        if ($request['action'] === 'payment') {
            $payload = ['id' => $request['order_id'], 'lines' => [['method' => 'Cash', 'amount' => $request['amount']]]];

            return app(TransactionService::class)->idempotent($request['key'], 'payment', $payload, function () use ($payload) {
                $order = app(PaymentService::class)->receive($payload['id'], $payload['lines']);

                return ['id' => $order->id, 'status' => $order->status];
            });
        }
        throw new RuntimeException('Unknown validation worker action.');
    });
    echo json_encode(['code' => 200, 'id' => is_array($result) ? ($result['id'] ?? $result['waiter_id']) : $result->id, 'print_batch' => is_array($result) ? ($result['print_batch'] ?? null) : null, 'print' => is_array($result) ? ($result['print'] ?? null) : null], JSON_THROW_ON_ERROR)."\n";
} catch (ValidationException $error) {
    echo json_encode(['code' => 422, 'errors' => $error->errors()], JSON_THROW_ON_ERROR)."\n";
} catch (AuthorizationException $error) {
    echo json_encode(['code' => 403], JSON_THROW_ON_ERROR)."\n";
} catch (HttpExceptionInterface $error) {
    echo json_encode(['code' => $error->getStatusCode()], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Validation worker failed: '.get_class($error)."\n");
    exit(1);
}
