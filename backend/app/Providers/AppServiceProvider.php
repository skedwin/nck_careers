<?php

namespace App\Providers;

use App\Services\AI\AIServiceInterface;
use App\Services\AI\AiServiceFactory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Microsoft\MicrosoftExtendSocialite;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AIServiceInterface::class, function ($app) {
            return $app->make(AiServiceFactory::class)->make();
        });
    }

    public function boot(): void
    {
        Event::listen(SocialiteWasCalled::class, MicrosoftExtendSocialite::class.'@handle');
    }
}
