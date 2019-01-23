<?php
namespace Jspeedz\MultiProcessor\Callback\Close;

use Closure;

/**
 * Please note, this class is experimental
 */
class DoctrineConnections {
    public function getCallback($doctrine): Closure {
        return function() use ($doctrine) {
            /**
             * @var \Doctrine\DBAL\Connection[] $connections
             */
            $connections = $doctrine->getConnections();
            foreach($connections as $connection) {
                $connection->close();
            }
        };
    }
}
