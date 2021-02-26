<?php

namespace Anakadote\ImageManager\Facades;
 
use Illuminate\Support\Facades\Facade;
 
class ImageManager extends Facade {
 
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor() { return 'laravel-5-image-manager'; }
 
}
