<?php

use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\NightshiftDemoSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../tests/bootstrap-mysql.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'mysql_validation' || DB::connection()->getDatabaseName() !== 'nightshift_validation') {
    throw new RuntimeException('Browser fixtures require the isolated MySQL validation database.');
}
$app->make(Kernel::class)->call('db:seed', ['--class' => NightshiftDemoSeeder::class, '--force' => true]);
$company = Company::firstOrCreate(['name' => 'Staff visibility foreign company']);
$foreign = User::firstOrCreate(['email' => 'foreign@staff-visibility.test'], ['name' => 'Foreign staff visibility', 'password' => 'Secure!Password2026']);
$foreign->forceFill(['company_id' => $company->id, 'role_id' => Role::where('name', 'Company Admin / Owner')->firstOrFail()->id])->save();
$branch = app(TenantContext::class)->run($foreign, fn () => Branch::firstOrCreate(['name' => 'Foreign staff branch']));
$foreign->branches()->syncWithoutDetaching([$branch->id => ['company_id' => $company->id]]);
echo json_encode(['foreign_user_id' => $foreign->id, 'foreign_branch_id' => $branch->id], JSON_THROW_ON_ERROR)."\n";
