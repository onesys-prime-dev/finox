<?php

namespace App\Providers;

use App\Models\Cheque;
use App\Models\DocumentFile;
use App\Models\PrintJob;
use App\Models\Receipt;
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
            'bank' => \App\Models\Bank::class,
            'bank_account' => \App\Models\BankAccount::class,
            'payee' => \App\Models\Payee::class,
            'role' => \App\Models\Role::class,
            'print_template' => \App\Models\PrintTemplate::class,
            'receipt_category' => \App\Models\ReceiptCategory::class,
            'setting' => \App\Models\SystemSetting::class,
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
