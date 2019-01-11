# phpMultiProcessor
Multi processor for PHP

## About
This library gives you a way to parallel/asynchronously process tasks that would take quite a while by splitting up work and executing tasks in parallel by forking the running PHP process.
Processor cores are used more efficiently this way as well. You can for example fork as many times as there are cores available on a machine. Or more if the process is easy on CPU requirements. 

## Caveats
This script forks/clones all resources initialized before actually forking into separate processes.
Using the same resources like file handles, MySQL or network connections will cause trouble. When forks try to use them at the same time they will simply collide.

To prevent this issue, register close/disconnect callbacks which are executed either before the first fork is made, or before every fork.

Example: closing all doctrine database connections before forking:
```php
$multiProcessor->addCloseResourceCallback(function() use ($doctrine): void {
    /**
     * @var \Doctrine\DBAL\Connection[] $connections
     */
    $connections = $doctrine->getConnections();
    foreach($connections as $connection) {
        $connection->close();
    }
}, 'once');`

// Run directly after
$multiProcessor->run();
```
Every fork will re-connect to the database server and have their own connection this way.
Please note, the state of $doctrine will be locked by using the 'use' statement after creating the closure. So register the closures just before the run statement. 

### Use cases:
- Processing multiple HTTP/REST API calls at the same time for API's that do not allow bulk actions
- Splitting bulk hashing operations over multiple cores

## Prerequisites/Requirements
- PHP 7.2.0 or greater