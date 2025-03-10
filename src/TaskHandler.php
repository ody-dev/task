<?php

namespace Ody\Task;

use Swoole\Server;

/**
 * TaskHandler - Integrates the TaskManager with Swoole
 */
class TaskHandler
{
    /**
     * Initialize the task handler with the Swoole server
     *
     * @param Server $server
     */
    public static function init(\Swoole\Server $server): void
    {
        $taskManager = new TaskManager();
        $taskManager->setServer($server);

        // Register default middleware
        $taskManager->registerMiddleware(new LoggingMiddleware());

        // Set up Swoole task handlers
        $server->on('task', function (\Swoole\Server $server, int $taskId, int $workerId, $data) use ($taskManager) {
            return $taskManager->handleTask($data);
        });

        $server->on('finish', function (\Swoole\Server $server, int $taskId, $data) use ($taskManager) {
            $taskManager->taskComplete($taskId, $data);
        });
    }

    /**
     * Handle a task
     *
     * @param array $data
     * @return mixed
     */
    public static function handleTask(array $data)
    {
        $class = $data['class'];
        $params = $data['params'];

        try {
            $task = new $class();
            return $task->handle($params);
        } catch (\Exception $e) {
            // Log error
            return ['error' => $e->getMessage()];
        }
    }
}