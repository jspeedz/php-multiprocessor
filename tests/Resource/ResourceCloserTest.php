<?php
namespace Jspeedz\MultiProcessor\Tests\Resource;

use Jspeedz\MultiProcessor\Enum\ResourceCloseCallbackTriggerEnum;
use Jspeedz\MultiProcessor\Resource\ResourceCloser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResourceCloser::class)]
final class ResourceCloserTest extends TestCase {
    #[TestWith([ResourceCloseCallbackTriggerEnum::ONCE])]
    #[TestWith([ResourceCloseCallbackTriggerEnum::ALWAYS])]
    public function testCallbacksRunInRegistrationOrderForTheirTrigger(ResourceCloseCallbackTriggerEnum $trigger): void {
        $closer = new ResourceCloser();
        $calls = [];
        $closer->add(function() use (&$calls): void { $calls[] = 'first'; }, $trigger);
        $closer->add(function() use (&$calls): void { $calls[] = 'second'; }, $trigger);
        $other = $trigger === ResourceCloseCallbackTriggerEnum::ONCE
            ? ResourceCloseCallbackTriggerEnum::ALWAYS : ResourceCloseCallbackTriggerEnum::ONCE;

        $closer->close($other);
        self::assertSame([], $calls);

        $closer->close($trigger);
        self::assertSame(['first', 'second'], $calls);
    }
}
