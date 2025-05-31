<?php
namespace Jspeedz\MultiProcessor\Callback\Close;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Closes all Doctrine connections and detaches all attached entities from all entity managers.
 */
class ResetDoctrine {
    public function __construct(
        protected readonly ManagerRegistry $managerRegistry,
    ) {}

    public function getCallback(): Closure {
        $managerRegistry = $this->managerRegistry;
        return function() use ($managerRegistry): void {
            foreach($managerRegistry->getManagers() as $manager) {
                $manager->clear();
            }

            /** @var Connection[] $connections */
            $connections = $managerRegistry->getConnections();
            foreach($connections as $connection) {
                if($connection->isConnected()) {
                    $connection->close();
                }
            }
        };
    }
}
