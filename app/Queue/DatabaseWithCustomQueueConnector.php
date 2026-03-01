<?php

namespace App\Queue;

use Illuminate\Queue\Connectors\DatabaseConnector;

class DatabaseWithCustomQueueConnector extends DatabaseConnector
{
    public function connect(array $config)
    {
        return new DatabaseWithCustomQueue(
            $this->connections->connection($config['connection'] ?? null),
            $config['table'],
            $config['queue'],
            $config['retry_after'] ?? 60,
            $config['after_commit'] ?? null
        );
    }
}
