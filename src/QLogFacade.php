<?php
namespace Unifreak\QLog;

use Illuminate\Support\Facades\Facade;

class QLogFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'qlog';
    }
}
