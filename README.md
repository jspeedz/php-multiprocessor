# php-multiprocessor
Multiprocessor for PHP

## About
This library gives you a way to parallel/asynchronously process tasks using PCNTL that would take quite a while by splitting up work and executing tasks in parallel by forking the running PHP process.
Processor cores are used more efficiently this way as well. You can for example fork as many times as there are cores available on a machine. Or more if the process is easy on resource requirements. 

## Use case examples:
- Processing multiple HTTP/REST API calls in parallel for API's that do not allow bulk actions
- Splitting CPU intensive bulk hashing operations over multiple cores
- Splitting CPU intensive image manipulation operations over multiple cores (etc.)
- Achieving multitenancy architecture with only a single parent process and isolated forks to process tenants

## Prerequisites/Requirements
- Linux or macOS
- PHP 8.4.27 or greater (tested on 8.4, 8.5 and 8.6)
- ext-pcntl
- ext-posix

Optional integrations, each only needs its packages when you use it (not installed with this package):

| Integration | Requires |
|---|---|
| `Callback\Close\ResetDoctrine` | `doctrine/dbal` 4, `doctrine/persistence` 3 or 4 |
| `Logging\Monolog\CrossProcessLockHandler` | `monolog/monolog` 3 |
| `Logging\Symfony\Console\LogOutputLockOptionTrait` | `symfony/console` 7.4 or 8 |

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
2. Forks share the parent's stdout and stderr. Writes to a pipe are only atomic up to `PIPE_BUF` bytes (4096 on Linux, 512 on macOS), so larger output written by forks at the same time can be split and mixed into each other.

   When using Symfony and/or Monolog you can use [Log output locks across forks](#log-output-locks-across-forks) to prevent this for logging.

3. The same problem exists when multiple processors have to write to the same file, writes can interleave and corrupt the file. Use a write lock. Use an exclusive lock with `flock()` around every write.
   Open the file inside the processor, not before forking, so every fork has its own handle (see caveat 1).
    ```php
    $handle = fopen('/path/to/output.log', 'a');
    if (flock($handle, LOCK_EX)) {
        fwrite($handle, $line . PHP_EOL);
        fflush($handle);
        flock($handle, LOCK_UN);
    }
    fclose($handle);

    # Or, for simple appends: 
    file_put_contents('/path/to/output.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    ```
4. Does not run on Windows machines, as Windows does not have PCNTL signaling.
5. No warranty. This package is kind of experimental. It is in professional use in a slightly different format though. 

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

## Log output locks across forks

When using Symfony and/or Monolog, write collisions can be avoided by locking.

The logging lock is **opt-in**: Only enable it when you need it, because processes wait for each other while they log, which can slow down log-heavy runs.

A slow log reader (a full pipe) blocks the process holding the lock, and with it every process waiting to log.

Requirements, only when you use this feature:
- `monolog/monolog` 3, for `CrossProcessLockHandler`
- `symfony/console` 7.4 or 8, for `LogOutputLockOptionTrait`

### Plain PHP
Wrap every handler that writes to stdout/stderr, and enable the lock before `run()` forks:
```php
use Jspeedz\MultiProcessor\Logging\LogOutputLock;
use Jspeedz\MultiProcessor\Logging\Monolog\CrossProcessLockHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

$lock = new LogOutputLock(); // Lock file in sys_get_temp_dir(), or pass a directory
$logger = new Logger('app', [
    new CrossProcessLockHandler(new StreamHandler('php://stderr'), $lock),
]);

$lock->enable(); // Before run(), so the parent and every fork use the lock

$multiProcessor = new MultiProcessor($data, $processor);
$multiProcessor->run();
```

### Logging to files using Monolog
When forks log to a **file**, you don't need this logging lock. Monolog's `StreamHandler` and `RotatingFileHandler` can lock the file with `flock()` during every write themselves:
```php
new \Monolog\Handler\StreamHandler('/var/log/app.log', useLocking: true);
new \Monolog\Handler\RotatingFileHandler('/var/log/app.log', maxFiles: 10, useLocking: true);
```
In Symfony (MonologBundle) this is the `use_locking` option, which only the `stream` and `rotating_file` handler types support:
```yaml
monolog:
    handlers:
        file:
            type: rotating_file
            path: '%kernel.logs_dir%/%kernel.environment%.log'
            use_locking: true
```

### Symfony + Monolog console logger
Register the lock as a service, and a wrapper per handler that writes to stdout/stderr:
```yaml
# config/services.yaml
services:
    Jspeedz\MultiProcessor\Logging\LogOutputLock:
        arguments:
            # Optional, defaults to sys_get_temp_dir(), which is the preferred directory to use.
            $lockDirectory: '/custom/log-dir/php-multiprocessor/locks'
    app.monolog.handler.console_lock:
        class: Jspeedz\MultiProcessor\Logging\Monolog\CrossProcessLockHandler
        arguments:
            $handler: '@monolog.handler.console_output'
            $lock: '@Jspeedz\MultiProcessor\Logging\LogOutputLock'
```

Point the logger at the wrapper, and mark the wrapped handler as `nested` so it is not also added to the loggers directly. Put channel filters on the wrapper, MonologBundle ignores them on nested handlers:
```yaml
# config/packages/monolog.yaml
monolog:
    handlers:
        console:
            type: service
            id: app.monolog.handler.console_lock
            channels: ['!event', '!doctrine']
        console_output:
            type: console
            nested: true
            level: info
```

A handler that is the target of a `fingers_crossed`, `buffer` or `group` handler is wrapped the same way: point the `handler`/`members` option at a `type: service` wrapper marked `nested: true`. A batch flushed by those handlers is written under a single lock, so it stays together in the output.

Then let commands that run the MultiProcessor opt in with a `--lock-log-output` option, using `LogOutputLockOptionTrait`.
```php
use Jspeedz\MultiProcessor\Logging\Symfony\Console\LogOutputLockOptionTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ImportCommand extends Command {
    // With many commands, put the trait and method calls in a shared abstract command class.
    use LogOutputLockOptionTrait;

    protected function configure(): void {
        $this->addLogOutputLockOption();
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void {
        $this->enableLogOutputLockFromInput($input);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        // ... create and run() the MultiProcessor
        return Command::SUCCESS;
    }
}
```
```sh
php bin/console app:import --lock-log-output
```

#### Notes
- The lock file is created by the first locked write, and the main process removes it when it exits. If the main process is killed by SIGKILL, the OOM killer or a segfault while children are still running, children that have not logged yet would create a new lock file and no longer share the lock with the others. In this case, lock files could be left behind without cleaning them up.

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
