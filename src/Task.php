<?php

namespace Ody\Task;

class Task
{
    // Original constants
    public const PRIORITY_NORMAL = 10;
    public const PRIORITY_HIGH = 20;
    public const PRIORITY_LOW = 5;

    // Additional status constants
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_RETRYING = 'retrying';

    /**
     * Original execute method
     */
    public static function execute(string $taskClass, array $params = [], int $priority = self::PRIORITY_NORMAL): int
    {
        return TaskManager::getInstance()->enqueue($taskClass, $params, $priority);
    }

    /**
     * Original later method
     */
    public static function later(string $taskClass, array $params = [], int $delayMs = 1000, int $priority = self::PRIORITY_NORMAL): int
    {
        return TaskManager::getInstance()->enqueueDelayed($taskClass, $params, $delayMs, $priority);
    }

    /**
     * Execute a task with retry options
     *
     * @param string $taskClass
     * @param array $params
     * @param array $retryOptions
     * @param int $priority
     * @return int Task ID
     */
    public static function withRetry(
        string $taskClass,
        array $params = [],
        array $retryOptions = ['attempts' => 3, 'delay' => 1000, 'multiplier' => 2],
        int $priority = self::PRIORITY_NORMAL
    ): int {
        return TaskManager::getInstance()->enqueueWithRetry($taskClass, $params, $retryOptions, $priority);
    }

    /**
     * Create a new task group
     *
     * @param string $name Optional name for the group
     * @return TaskGroup
     */
    public static function group(string $name = null): TaskGroup
    {
        return new TaskGroup($name);
    }

    /**
     * Create a new task batch
     *
     * @param array $tasks Array of task definitions
     * @return TaskBatch
     */
    public static function batch(array $tasks): TaskBatch
    {
        return new TaskBatch($tasks);
    }

    /**
     * Cancel a task by ID
     *
     * @param int $taskId
     * @return bool
     */
    public static function cancel(int $taskId): bool
    {
        return TaskManager::getInstance()->cancelTask($taskId);
    }

    /**
     * Get status of a task
     *
     * @param int $taskId
     * @return array Task status information
     */
    public static function status(int $taskId): array
    {
        return TaskManager::getInstance()->getTaskStatus($taskId);
    }
}