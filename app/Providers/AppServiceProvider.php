<?php

namespace App\Providers;

use App\Models\Department;
use App\Models\Patient;
use App\Models\User;
use App\Policies\DepartmentPolicy;
use App\Policies\PatientPolicy;
use App\Policies\UserPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (array_keys(config('authorization.permissions')) as $permission) {
            Gate::define($permission, fn (User $user): bool => $user->hasPermission($permission));
        }

        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Department::class, DepartmentPolicy::class);
        Gate::policy(Patient::class, PatientPolicy::class);

        RateLimiter::for('patient-writes', fn (Request $request): Limit => Limit::perMinute(30)
            ->by('patient-writes:'.$request->user('web')?->id.'|'.$request->ip()));

        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(120)
            ->by($request->ip()));

        RateLimiter::for('staff-login', fn (Request $request): Limit => Limit::perMinute(20)
            ->by('staff-login-ip:'.$request->ip()));

        RateLimiter::for('protected-files-upload', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('protected-files-upload:'.$request->user('web')?->id.'|'.$request->ip()));

        RateLimiter::for('staff-management', fn (Request $request): Limit => Limit::perMinute(20)
            ->by('staff-management:'.$request->user('web')?->id.'|'.$request->ip()));

        RateLimiter::for('staff-recovery', fn (Request $request): array => [
            Limit::perMinute(10)->by('staff-recovery-ip:'.$request->ip()),
            Limit::perMinute(5)->by('staff-recovery-user:'.hash('sha256',
                (is_string($request->input('username')) ? mb_strtolower($request->input('username')) : '').'|'.$request->ip())),
        ]);
    }
}
