# php-multiprocessor
Multi processor for PHP

## About
This library gives you a way to parallel/asynchronously process tasks using PCNTL that would take quite a while by splitting up work and executing tasks in parallel by forking the running PHP process.
Processor cores are used more efficiently this way as well. You can for example fork as many times as there are cores available on a machine. Or more if the process is easy on resource requirements. 

## Caveats
1. This script forks/clones all resources initialized before actually forking into separate processes.
Using the same resources like file handles, MySQL or any other network connections will cause trouble. When forks try to use them at the same time they will simply collide.

    To prevent this issue, register close/disconnect callbacks which are executed either `once` before the first fork is made, or `always` before every fork.

    Example: closing all open stream resources once before starting to fork processes:
    ```php
    $multiProcessor->addCloseResourceCallback(
        (new \Jspeedz\MultiProcessor\Callback\Close\StreamResources)->getCallback(),
        ResourceCloseCallbackTriggerEnum::ONCE,
    );
    
    // Run directly after
    $multiProcessor->run();
    ```
    Every fork will re-connect to the database server and have their own connection this way.

    `StreamResources` closes every open stream except PHP's standard streams and in-memory streams. Pass handles that must stay open, like a lock file held with `flock()` (closing it would release the lock):
    ```php
    $lock = fopen('/var/run/my-job.lock', 'c');
    flock($lock, LOCK_EX | LOCK_NB) or exit('Already running');

    $multiProcessor->addCloseResourceCallback(
        (new \Jspeedz\MultiProcessor\Callback\Close\StreamResources(keepOpen: [$lock]))->getCallback(),
        ResourceCloseCallbackTriggerEnum::ONCE,
    );
    ```
    
    If your goal is achieving parallel processing with isolated forks for multitenancy, initialize all resources in the processor to keep them isolated.
2. Does not run on windows machines, as windows does not have PCNTL signaling.
3. No warranty. This package is kind of experimental. It is in professional use in a slightly different format though. 

## Stopping when a child fatals
By default, when a child fatals, the other children are stopped and the parent exits with a non-zero exit code. `stopOnFatalMethod()` picks how running children are stopped:

| Method | Running children |
|---|---|
| `graceful` (default) | Complete their chunk, no new children are started |
| `normal` | Get SIGTERM, and SIGKILL when they're still running after the grace period |
| `immediate` | Get SIGKILL right away |

The grace period of the `normal` method defaults to 10 seconds. Children that are blocked in a system call or ignore SIGTERM are killed once it is over, so the parent never waits on them indefinitely. Set it to the time your processor needs to shut down cleanly:
```php
$multiProcessor->stopOnFatalMethod(\Jspeedz\MultiProcessor\Enum\StopOnFatalMethodEnum::NORMAL);
$multiProcessor->setTerminateGracePeriod(30.0); // seconds, 0 or more
```

## Use case examples:
- Processing multiple HTTP/REST API calls in parallel for API's that do not allow bulk actions
- Splitting CPU intensive bulk hashing operations over multiple cores
- Splitting CPU intensive image manipulation operations over multiple cores (etc.)
- Achieving multitenancy architecture with only a single parent process and isolated forks to process tenants

## Prerequisites/Requirements
- Linux or MacOS
- PHP 8.4.27 or greater
- ext-pcntl
- ext-posix
- ext-json

## Tests

Install development dependencies and run the test suite:

```sh
composer install
composer run test
phpstan analyse --configuration=phpstan.neon --memory-limit=256M
```

## License
GNU GPL 3, do whatever you like with this code.

## Warranty
None. Don't blame me if your billion dollar fusion reactor or spaceship fails due to this code. 
