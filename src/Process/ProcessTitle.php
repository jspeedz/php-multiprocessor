<?php
namespace Jspeedz\MultiProcessor\Process;

/**
 * The title `ps` shows for the current process. Only works under the CLI SAPI.
 *
 * @internal
 */
final class ProcessTitle {
    /**
     * Until a title is set, the title is the command line the process was started with.
     * PHP doesn't expose that command line. Linux has it in /proc, elsewhere it is rebuilt from the PHP binary name
     * and the script arguments (dropping interpreter options like `-d`).
     */
    public static function get(): string {
        $title = function_exists('cli_get_process_title') ? cli_get_process_title() : null;
        if(is_string($title) && $title !== '') {
            return $title;
        }

        $commandLine = is_readable('/proc/self/cmdline') ? @file_get_contents('/proc/self/cmdline') : false;
        if(is_string($commandLine) && trim($commandLine, "\0") !== '') {
            return str_replace("\0", ' ', rtrim($commandLine, "\0"));
        }

        $arguments = $_SERVER['argv'] ?? null;
        if(is_array($arguments) && $arguments !== []) {
            return basename(PHP_BINARY) . ' ' . implode(' ', array_map(static fn(mixed $argument): string => is_scalar($argument) ? (string) $argument : '', $arguments));
        }

        return PHP_BINARY;
    }

    /**
     * Titles are cosmetic, so failing to set one (outside the CLI SAPI, or an OS that doesn't support it) is ignored.
     */
    public static function set(string $title): void {
        if(function_exists('cli_set_process_title')) {
            @cli_set_process_title($title);
        }
    }
}
