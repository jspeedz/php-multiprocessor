<?php
namespace Jspeedz\MultiProcessor\Enum;

enum StopOnFatalMethodEnum: string {
    case GRACEFUL = 'graceful';
    case NORMAL = 'normal';
    case IMMEDIATE = 'immediate';
}