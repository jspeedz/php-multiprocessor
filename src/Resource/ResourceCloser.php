<?php
namespace Jspeedz\MultiProcessor\Resource;

use Closure;
use Jspeedz\MultiProcessor\Enum\ResourceCloseCallbackTriggerEnum;

/**
 * Closes resource handles before forking, so every fork creates its own.
 *
 * Re-using resources like file handles, MySQL or network connections will cause trouble when forks try to use them.
 * They will try to use the same handles/connections and collide.
 *
 * @internal
 */
final class ResourceCloser {
    /**
     * @var array<value-of<ResourceCloseCallbackTriggerEnum>, list<Closure>>
     */
    private array $callbacks = [
        ResourceCloseCallbackTriggerEnum::ONCE->value => [],
        ResourceCloseCallbackTriggerEnum::ALWAYS->value => [],
    ];

    public function add(Closure $callback, ResourceCloseCallbackTriggerEnum $trigger): void {
        $this->callbacks[$trigger->value][] = $callback;
    }

    /**
     * Run the callbacks registered for the trigger, in registration order.
     */
    public function close(ResourceCloseCallbackTriggerEnum $trigger): void {
        foreach($this->callbacks[$trigger->value] as $callback) {
            $callback();
        }
    }
}
