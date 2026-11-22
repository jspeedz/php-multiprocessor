<?php
namespace Jspeedz\MultiProcessor\Tests\Logging\Symfony\Console;

use Jspeedz\MultiProcessor\Logging\Symfony\Console\LogOutputLockOptionTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Example command using LogOutputLockOptionTrait. Also analysed by PHPStan, so the trait is checked in a real command.
 */
final class LogOutputLockOptionCommand extends Command {
    use LogOutputLockOptionTrait;

    public ?bool $enabledDuringExecute = null;

    public function __construct() {
        parent::__construct('test:log-output-lock');
    }

    protected function configure(): void {
        $this->addLogOutputLockOption();
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void {
        $this->enableLogOutputLockFromInput($input);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        // Reads the property, so the tests can also run this command without an injected lock
        $this->enabledDuringExecute = $this->logOutputLock?->isEnabled();
        return Command::SUCCESS;
    }
}
