<?php

namespace App\Providers;

use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CurrentTenant::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->pointAuthEmailsAtTheSpa();
    }

    /**
     * Both auth emails link to the SPA rather than the API: the user needs
     * a page, not a JSON response. The SPA reads the query string and posts
     * it back to the API.
     */
    protected function pointAuthEmailsAtTheSpa(): void
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        ResetPassword::createUrlUsing(function (object $notifiable, string $token) use ($frontend) {
            return $frontend.'/reset-password?'.http_build_query([
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ]);
        });

        VerifyEmail::createUrlUsing(function (MustVerifyEmail $notifiable) use ($frontend) {
            // Sign the API route, then hand its parameters to the SPA. The
            // SPA replays them against that same route, so the signature —
            // which covers path and query — still validates. Signed
            // relatively on purpose: the API is reached through a dev proxy
            // and a production domain that need not match APP_URL.
            $signed = URL::temporarySignedRoute(
                'verification.verify',
                now()->addMinutes((int) config('auth.verification.expire', 60)),
                [
                    'id' => $notifiable->getKey(),
                    'hash' => sha1($notifiable->getEmailForVerification()),
                ],
                absolute: false,
            );

            parse_str((string) parse_url($signed, PHP_URL_QUERY), $query);

            return $frontend.'/verify-email?'.http_build_query([
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ] + $query);
        });
    }
}
