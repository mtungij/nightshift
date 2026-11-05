<?php

use App\Http\Controllers\BusinessOnboardingController;
use App\Http\Controllers\BusinessProfileController;
use App\Http\Controllers\LanguagePreferenceController;
use App\Http\Controllers\NightshiftController;
use App\Http\Controllers\OpenShiftController;
use App\Http\Controllers\ShiftStockController;
use App\Http\Controllers\WhatsAppController;
use App\Http\Middleware\RequireOperationalShift;
use App\Http\Middleware\RequireWhatsAppAccess;
use App\Livewire\Workspace;
use App\Models\Company;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->check() ? redirect()->route('dashboard') : view('pages::auth.login'))->name('home');
Route::post('/settings/language', [LanguagePreferenceController::class, 'update'])->middleware('throttle:30,1')->name('language.update');
Route::middleware('guest')->group(function () {
    Route::get('/companies/{company}/login', function (Company $company) {
        abort_unless($company->active, 404);
        session()->put('login_company_id', $company->id);

        return view('pages::auth.login', ['loginCompany' => $company]);
    })->name('company.login');
    Route::get('/register-business', [BusinessOnboardingController::class, 'create'])->name('business.register');
    Route::post('/register-business', [BusinessOnboardingController::class, 'store'])->middleware('throttle:5,1')->name('business.store');
});
Route::middleware(['auth'])->group(function () {
    Route::prefix('settings/whatsapp')->middleware([RequireWhatsAppAccess::class, 'throttle:30,1'])->group(function () {
        Route::get('/', [WhatsAppController::class, 'index'])->name('whatsapp.settings');
        Route::post('/', [WhatsAppController::class, 'save']);
        Route::post('/connection/{action}', [WhatsAppController::class, 'connection']);
        Route::post('/recipients/{id?}', [WhatsAppController::class, 'recipient']);
        Route::post('/send/{type}', [WhatsAppController::class, 'send']);
    });
    Route::get('/settings/business', [BusinessProfileController::class, 'edit'])->name('business.edit');
    Route::post('/settings/business', [BusinessProfileController::class, 'update'])->name('business.update');
    Route::get('/business/logo', [BusinessProfileController::class, 'logo'])->name('business.logo');
    Route::get('/setup', function () {
        Gate::authorize('manage-company');

        return redirect('/dashboard#setup');
    })->name('business.setup');
    Route::get('/open-shift', [OpenShiftController::class, 'show'])->name('shift.open');
    Route::post('/open-shift', [OpenShiftController::class, 'store'])->middleware('throttle:20,1')->name('shift.open.store');
    // History remains available after the cashier closes their operational shift.
    Route::get('/api/nightshift/shift-stock/{id}', [ShiftStockController::class, 'show'])->whereNumber('id');
    Route::get('/shift-stock/{id}', [ShiftStockController::class, 'page'])->whereNumber('id');
    Route::get('/shift-stock/{id}/print', [ShiftStockController::class, 'sheet'])->whereNumber('id');
    Route::get('/dashboard', Workspace::class)->middleware(RequireOperationalShift::class)->name('dashboard');
    Route::prefix('api/nightshift')->middleware(['throttle:120,1', RequireOperationalShift::class])->group(function () {
        Route::get('/products/{id}/image', [NightshiftController::class, 'productImage'])->name('nightshift.product-image');
        Route::get('/bootstrap', [NightshiftController::class, 'snapshot']);
        Route::post('/commands/{command}', [NightshiftController::class, 'command']);
        Route::get('/reports/{name}/export', [NightshiftController::class, 'exportReport']);
        Route::get('/reports/{name}', [NightshiftController::class, 'report']);
        Route::get('/receipts/{id}', [NightshiftController::class, 'receipt'])->name('nightshift.receipt');
        Route::post('/sales-tickets/{token}/dispatch', [NightshiftController::class, 'dispatchTicket'])->name('nightshift.ticket-dispatch');
        Route::get('/sales-tickets/{token}', [NightshiftController::class, 'salesTicket'])->name('nightshift.sales-ticket');
        Route::get('/issue-tickets/{id}', [NightshiftController::class, 'issueTicket'])->name('nightshift.issue-ticket');
    });
});
require __DIR__.'/settings.php';
