<?php

namespace Ody\Task;

class LoggingMiddleware implements TaskMiddlewareInterface
{
    public function process(array $task): array
    {
        // Wrap the original params in our own structure
        $originalParams = $task['params'];

        $task['params'] = [
            '__original' => $originalParams,
            '__meta' => [
                'logged_at' => time(),
                'user_id' => $_SESSION['user_id'] ?? null,
            ],
        ];

        // Log the task execution
        // logger()->info("Task {$task['class']} queued", ['params' => $task['params']]);

        return $task;
    }
}