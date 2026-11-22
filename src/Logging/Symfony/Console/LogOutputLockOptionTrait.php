<?php
namespace Jspeedz\MultiProcessor\Logging\Symfony\Console;

use Jspeedz\MultiProcessor\Logging\LogOutputLock;
use LogicException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Adds an opt-in --lock-log-output option to a Symfony Console command that runs the MultiProcessor.
 *
 * The LogOutputLock is injected through setLogOutputLock(), which Symfony autowiring calls because of the #[Required]
 * attribute. Without autowiring, call setLogOutputLock() yourself.
 *
 * The trait deliberately does not override configure() or initialize(): a command's own methods would silently
 * replace trait methods with the same name. Call the helpers from your own configure() and initialize() instead.
 *
 * Requires symfony/console 7.4 or 8 (which installs symfony/service-contracts for #[Required]).
 *
 * @phpstan-require-extends Command
 */
trait LogOutputLockOptionTrait {
    public const string LOCK_LOG_OUTPUT_OPTION = 'lock-log-output';

    private ?LogOutputLock $logOutputLock = null;

    /**
     * The lock shared with the CrossProcessLockHandler instances wrapping your stdout/stderr log handlers.
     */
    #[Required]
    public function setLogOutputLock(LogOutputLock $logOutputLock): void {
        $this->logOutputLock = $logOutputLock;
    }

    /**
     * @throws LogicException When no lock was injected
     */
    public function getLogOutputLock(): LogOutputLock {
        if($this->logOutputLock === null) {
            throw new LogicException(sprintf(
                'No LogOutputLock was set on %s. Enable autowiring for the command, or call setLogOutputLock() before running it.',
                static::class,
            ));
        }

        return $this->logOutputLock;
    }

    /**
     * Call from configure().
     */
    protected function addLogOutputLockOption(): void {
        $this->addOption(
            self::LOCK_LOG_OUTPUT_OPTION,
            null,
            InputOption::VALUE_NONE,
            'Lock stdout/stderr log writes across the forked processes, so concurrent log records are never interleaved. '
            . 'Processes wait for each other while logging, which can slow down log-heavy runs',
        );
    }

    /**
     * Call from initialize() (or execute()), before MultiProcessor::run() forks, so the parent and all children use the lock.
     */
    protected function enableLogOutputLockFromInput(InputInterface $input): void {
        if($input->getOption(self::LOCK_LOG_OUTPUT_OPTION) === true) {
            $this->getLogOutputLock()->enable();
        }
    }
}
