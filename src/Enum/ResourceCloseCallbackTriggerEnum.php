<?php
namespace Jspeedz\MultiProcessor\Enum;

enum ResourceCloseCallbackTriggerEnum: string {
    case ONCE = 'once';
    case ALWAYS = 'always';
}