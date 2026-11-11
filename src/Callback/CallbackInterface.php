<?php
namespace Jspeedz\MultiProcessor\Callback;

use Closure;

/**
 * @template TParam
 */
interface CallbackInterface {
    /**
     * @param array<TParam> $params
     *
     * @return Closure
     */
    public function getCallback(array $params): Closure;
}
