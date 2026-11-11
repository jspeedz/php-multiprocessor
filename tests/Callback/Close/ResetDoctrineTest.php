<?php
namespace Jspeedz\MultiProcessor\Tests\Callback\Close;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Jspeedz\MultiProcessor\Callback\Close\ResetDoctrine;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResetDoctrine::class)]
final class ResetDoctrineTest extends TestCase {
    public function testCallbackClearsEveryManagerAndClosesOnlyConnectedConnections(): void {
        $firstManager = $this->createMock(ObjectManager::class);
        $secondManager = $this->createMock(ObjectManager::class);
        $firstManager->expects(self::once())->method('clear');
        $secondManager->expects(self::once())->method('clear');

        $connected = $this->createMock(Connection::class);
        $connected->expects(self::once())->method('isConnected')->willReturn(true);
        $connected->expects(self::once())->method('close');
        $disconnected = $this->createMock(Connection::class);
        $disconnected->expects(self::once())->method('isConnected')->willReturn(false);
        $disconnected->expects(self::never())->method('close');

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::once())->method('getManagers')->willReturn([
            'first' => $firstManager, 'second' => $secondManager,
        ]);
        $registry->expects(self::once())->method('getConnections')->willReturn([
            'connected' => $connected, 'disconnected' => $disconnected,
        ]);

        $callback = (new ResetDoctrine($registry))->getCallback();
        self::assertInstanceOf(Closure::class, $callback);
        $callback();
    }

    public function testCreatingCallbackDoesNotAccessTheRegistry(): void {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::never())->method('getManagers');
        $registry->expects(self::never())->method('getConnections');

        self::assertInstanceOf(Closure::class, (new ResetDoctrine($registry))->getCallback());
    }

    public function testCallbackAcceptsAnEmptyRegistry(): void {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::once())->method('getManagers')->willReturn([]);
        $registry->expects(self::once())->method('getConnections')->willReturn([]);

        self::assertNull((new ResetDoctrine($registry))->getCallback()());
    }

    public function testEachInvocationChecksTheCurrentConnectionState(): void {
        $manager = $this->createMock(ObjectManager::class);
        $manager->expects(self::exactly(2))->method('clear');
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))->method('isConnected')->willReturnOnConsecutiveCalls(true, false);
        $connection->expects(self::once())->method('close');
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::exactly(2))->method('getManagers')->willReturn(['default' => $manager]);
        $registry->expects(self::exactly(2))->method('getConnections')->willReturn(['default' => $connection]);

        $callback = (new ResetDoctrine($registry))->getCallback();
        $callback();
        $callback();
    }
}
