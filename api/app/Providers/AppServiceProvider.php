<?php

namespace App\Providers;

use App\Auth\ApiKeys;
use App\Models\PersonalAccessToken;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

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
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // v0's contract passes the key as ?api_key=. Everything else takes only the
        // Authorization: Bearer header, so keys stay out of URLs (and access logs).
        Sanctum::getAccessTokenFromRequestUsing(function (Request $request): ?string {
            $token = $request->is('api/v0', 'api/v0/*') ? $request->query('api_key') : $request->bearerToken();

            return is_string($token) && ApiKeys::hasValidFormat($token) ? $token : null;
        });

        // Revoked keys stay in the table (so they can still be listed) but never authenticate.
        // Checked on every request, so a revocation applies to the very next call.
        Sanctum::authenticateAccessTokensUsing(
            fn (PersonalAccessToken $token, bool $isValid): bool => $isValid && ! $token->isRevoked(),
        );
    }
}
