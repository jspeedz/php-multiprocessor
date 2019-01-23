<?php
namespace Jspeedz\MultiProcessor\Callback;

use Closure;

interface CallbackInterface {
    /**
     * @param array $params
     *
     * @return Closure
     */
    public function getCallback(array $params): Closure;
}
