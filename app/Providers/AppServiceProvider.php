<?php

namespace App\Providers;

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Cheque;
use App\Models\DocumentFile;
use App\Models\Payee;
use App\Models\PrintJob;
use App\Models\PrintTemplate;
use App\Models\Receipt;
use App\Models\ReceiptCategory;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Policies\ChequePolicy;
use App\Policies\DocumentFilePolicy;
use App\Policies\PrintJobPolicy;
use App\Policies\ReceiptPolicy;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        Relation::enforceMorphMap([
            'cheque' => Cheque::class,
            'receipt' => Receipt::class,
            'document' => DocumentFile::class,
            'user' => User::class,
            'print_job' => PrintJob::class,
            'bank' => Bank::class,
            'bank_account' => BankAccount::class,
            'payee' => Payee::class,
            'role' => Role::class,
            'print_template' => PrintTemplate::class,
            'receipt_category' => ReceiptCategory::class,
            'setting' => SystemSetting::class,
        ]);

        Gate::policy(Cheque::class, ChequePolicy::class);
        Gate::policy(Receipt::class, ReceiptPolicy::class);
        Gate::policy(DocumentFile::class, DocumentFilePolicy::class);
        Gate::policy(PrintJob::class, PrintJobPolicy::class);

        // Permission keys ("cheques.create") are checked straight from the user's role.
        // Record-level rules (policies) use plain ability names and are not touched here.
        Gate::before(function (?User $user, string $ability) {
            if ($user && str_contains($ability, '.')) {
                return $user->is_active && $user->hasPermission($ability);
            }

            return null;
        });
    }
}
