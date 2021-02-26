<?php

namespace Anakadote\ImageManager;

use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\ServiceProvider;

class ImageManagerServiceProvider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot()
    {        
        $this->publishes(
            [__DIR__ . '/assets' => public_path('vendor/anakadote/image-manager')]
        );
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(Anakadote\ImageManager\Facades\ImageManager::class, function($app) {
            return new ImageManager;
        });
        
        $this->app['laravel-5-image-manager'] = $this->app->make(Anakadote\ImageManager\Facades\ImageManager::class);
        
        $this->app->booting(function() {
            $loader = AliasLoader::getInstance();
            $loader->alias('ImageManager', 'Anakadote\ImageManager\Facades\ImageManager');
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [];
    }
}
