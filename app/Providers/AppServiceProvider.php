<?php

namespace App\Providers;

use App\Models\Department;
use App\Models\User;
use App\Policies\DepartmentPolicy;
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

        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(120)
            ->by($request->ip()));

        RateLimiter::for('staff-login', fn (Request $request): Limit => Limit::perMinute(20)
            ->by('staff-login-ip:'.$request->ip()));
    }
}
