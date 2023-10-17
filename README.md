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
        'once'
    );
    
    // Run directly after
    $multiProcessor->run();
    ```
    Every fork will re-connect to the database server and have their own connection this way.
    
    If your goal is achieving parallel processing with isolated forks for multitenancy, initialize all resources in the processor to keep them isolated.
2. Does not run on windows machines, as windows does not have PCNTL signaling.
3. No warranty. This package is kind of experimental. It is in professional use in a slightly different format though. 

## Use case examples:
- Processing multiple HTTP/REST API calls in parallel for API's that do not allow bulk actions
- Splitting CPU intensive bulk hashing operations over multiple cores
- Splitting CPU intensive image manipulation operations over multiple cores (etc.)
- Achieving multitenancy architecture with only a single parent process and isolated forks to process tenants

## Prerequisites/Requirements
- Linux or OSX
- PHP 7.1.0 or greater
- ext-pcntl
- ext-posix
- ext-json

## License
GNU GPL 3, do whatever you like with this code.

## Warranty
None. Don't blame me if your billion dollar fusion reactor or spaceship fails due to this code. 
