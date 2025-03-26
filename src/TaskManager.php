<?php

namespace Ody\Task;

/**
 * TaskManager - Manages the task queue and processing
 */
class TaskManager
{
    /**
     * @var TaskManager Singleton instance
     */
    private static ?TaskManager $instance = null;

    /**
     * @var Server Swoole server instance
     */
    private Server $server;

    /**
     * @var array Priority queues for tasks
     */
    private array $queues = [];

    /**
     * @var array Delayed tasks
     */
    private array $delayedTasks = [];

    /**
     * @var int Next task ID
     */
    private int $nextTaskId = 1;

    /**
     * @var array Task statuses
     */
    private array $taskStatuses = [];

    /**
     * @var array Task groups
     */
    private array $taskGroups = [];

    /**
     * @var array Task batches
     */
    private array $taskBatches = [];

    /**
     * @var array Global middleware
     */
    private array $middleware = [];

    /**
     * @var array Task retry configurations
     */
    private array $retryConfigs = [];

    /**
     * @var array Metrics for monitoring
     */
    private array $metrics = [
        'total_tasks' => 0,
        'completed_tasks' => 0,
        'failed_tasks' => 0,
        'retried_tasks' => 0,
        'cancelled_tasks' => 0,
        'average_execution_time' => 0,
    ];

    /**
     * Private constructor for singleton pattern
     * TODO: was set to private
     */
    public function __construct()
    {
        // Initialize priority queues
        $this->queues = [
            Task::PRIORITY_HIGH => [],
            Task::PRIORITY_NORMAL => [],
            Task::PRIORITY_LOW => [],
        ];
    }

    /**
     * Get the singleton instance
     *
     * @return TaskManager
     */
    public static function getInstance(): TaskManager
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Set the Swoole server instance
     *
     * @param Server $server
     * @return void
     */
    public function setServer(Server $server): void
    {
        $this->server = $server;

        // Set up timer to process delayed tasks
        $this->server->tick(100, function () {
            $this->processDelayedTasks();
        });
    }

    /**
     * Enqueue a task for immediate execution
     *
     * @param string $taskClass
     * @param array $params
     * @param int $priority
     * @return int Task ID
     */
    public function enqueue(string $taskClass, array $params = [], int $priority = Task::PRIORITY_NORMAL): int
    {
        // Apply global middleware
        $processedParams = $this->applyMiddleware($params);

        $taskId = $this->enqueue($taskClass, $processedParams, $priority);

        // Initialize task status
        $this->taskStatuses[$taskId] = [
            'id' => $taskId,
            'class' => $taskClass,
            'status' => Task::STATUS_PENDING,
            'attempts' => 0,
            'max_attempts' => 1,
            'created_at' => microtime(true),
            'started_at' => null,
            'completed_at' => null,
            'execution_time' => null,
            'result' => null,
            'error' => null,
        ];

        $this->metrics['total_tasks']++;

        return $taskId;
    }

    /**
     * Enqueue a task with retry options
     *
     * @param string $taskClass
     * @param array $params
     * @param array $retryOptions
     * @param int $priority
     * @return int Task ID
     */
    public function enqueueWithRetry(
        string $taskClass,
        array $params = [],
        array $retryOptions = ['attempts' => 3, 'delay' => 1000, 'multiplier' => 2],
        int $priority = Task::PRIORITY_NORMAL
    ): int {
        $taskId = $this->enqueue($taskClass, $params, $priority);

        // Store retry configuration
        $this->retryConfigs[$taskId] = $retryOptions;

        // Initialize task status
        $this->taskStatuses[$taskId] = [
            'id' => $taskId,
            'class' => $taskClass,
            'status' => Task::STATUS_PENDING,
            'attempts' => 0,
            'max_attempts' => $retryOptions['attempts'],
            'created_at' => microtime(true),
            'started_at' => null,
            'completed_at' => null,
            'execution_time' => null,
            'result' => null,
            'error' => null,
        ];

        $this->metrics['total_tasks']++;

        return $taskId;
    }

    /**
     * Enqueue a delayed task
     *
     * @param string $taskClass
     * @param array $params
     * @param int $delayMs
     * @param int $priority
     * @return int Task ID
     */
    public function enqueueDelayed(string $taskClass, array $params = [], int $delayMs = 1000, int $priority = Task::PRIORITY_NORMAL): int
    {
        // Validate that the task class implements TaskInterface
        $this->validateTaskClass($taskClass);

        $taskId = $this->getNextTaskId();
        $executeAt = microtime(true) + ($delayMs / 1000);

        $this->delayedTasks[] = [
            'id' => $taskId,
            'class' => $taskClass,
            'params' => $params,
            'priority' => $priority,
            'executeAt' => $executeAt,
        ];

        // Sort delayed tasks by execution time
        usort($this->delayedTasks, function ($a, $b) {
            return $a['executeAt'] <=> $b['executeAt'];
        });

        return $taskId;
    }

    /**
     * Process delayed tasks that are due
     */
    private function processDelayedTasks(): void
    {
        $now = microtime(true);
        $tasksToProcess = [];

        // Find tasks that are due
        foreach ($this->delayedTasks as $key => $task) {
            if ($task['executeAt'] <= $now) {
                $tasksToProcess[] = $task;
                unset($this->delayedTasks[$key]);
            } else {
                // Since tasks are sorted by time, we can break once we find one that's not due
                break;
            }
        }

        // Reset array keys
        $this->delayedTasks = array_values($this->delayedTasks);

        // Enqueue the due tasks
        foreach ($tasksToProcess as $task) {
            $this->enqueue($task['class'], $task['params'], $task['priority']);
        }
    }

    /**
     * Add a task to a group
     *
     * @param int $taskId
     * @param string $groupName
     * @return void
     */
    public function addTaskToGroup(int $taskId, string $groupName): void
    {
        if (!isset($this->taskGroups[$groupName])) {
            $this->taskGroups[$groupName] = [
                'tasks' => [],
                'completed' => 0,
                'failed' => 0,
                'results' => [],
            ];
        }

        $this->taskGroups[$groupName]['tasks'][] = $taskId;
    }

    /**
     * Add a task to a batch
     *
     * @param int $taskId
     * @param string $batchId
     * @return void
     */
    public function addTaskToBatch(int $taskId, string $batchId): void
    {
        if (!isset($this->taskBatches[$batchId])) {
            $this->taskBatches[$batchId] = [
                'tasks' => [],
                'completed' => 0,
                'failed' => 0,
                'results' => [],
            ];
        }

        $this->taskBatches[$batchId]['tasks'][] = $taskId;
    }

    /**
     * Wait for all tasks in a group to complete
     *
     * @param string $groupName
     * @param int $timeout
     * @return array
     */
    public function waitForGroup(string $groupName, int $timeout = 30000): array
    {
        if (!isset($this->taskGroups[$groupName])) {
            return ['error' => 'Group not found'];
        }

        $group = $this->taskGroups[$groupName];
        $endTime = microtime(true) + ($timeout / 1000);

        while (microtime(true) < $endTime) {
            $allCompleted = true;

            foreach ($group['tasks'] as $taskId) {
                $status = $this->getTaskStatus($taskId);

                if (!in_array($status['status'], [Task::STATUS_COMPLETED, Task::STATUS_FAILED, Task::STATUS_CANCELLED])) {
                    $allCompleted = false;
                    break;
                }
            }

            if ($allCompleted) {
                return $this->taskGroups[$groupName]['results'];
            }

            // Sleep for a short time before checking again
            usleep(100000); // 100ms
        }

        // Timeout reached
        return [
            'error' => 'Timeout waiting for group completion',
            'completed' => $this->taskGroups[$groupName]['completed'],
            'total' => count($this->taskGroups[$groupName]['tasks']),
            'partial_results' => $this->taskGroups[$groupName]['results'],
        ];
    }

    /**
     * Wait for all tasks in a batch to complete
     *
     * @param string $batchId
     * @param int $timeout
     * @return array
     */
    public function waitForBatch(string $batchId, int $timeout = 30000): array
    {
        if (!isset($this->taskBatches[$batchId])) {
            return ['error' => 'Batch not found'];
        }

        $batch = $this->taskBatches[$batchId];
        $endTime = microtime(true) + ($timeout / 1000);

        while (microtime(true) < $endTime) {
            $allCompleted = true;

            foreach ($batch['tasks'] as $taskId) {
                $status = $this->getTaskStatus($taskId);

                if (!in_array($status['status'], [Task::STATUS_COMPLETED, Task::STATUS_FAILED, Task::STATUS_CANCELLED])) {
                    $allCompleted = false;
                    break;
                }
            }

            if ($allCompleted) {
                return $this->taskBatches[$batchId]['results'];
            }

            // Sleep for a short time before checking again
            usleep(100000); // 100ms
        }

        // Timeout reached
        return [
            'error' => 'Timeout waiting for batch completion',
            'completed' => $this->taskBatches[$batchId]['completed'],
            'total' => count($this->taskBatches[$batchId]['tasks']),
            'partial_results' => $this->taskBatches[$batchId]['results'],
        ];
    }

    /**
     * Cancel a specific task
     *
     * @param int $taskId
     * @return bool
     */
    public function cancelTask(int $taskId): bool
    {
        if (!isset($this->taskStatuses[$taskId])) {
            return false;
        }

        $status = $this->taskStatuses[$taskId]['status'];

        // Can only cancel pending tasks
        if ($status === Task::STATUS_PENDING) {
            $this->taskStatuses[$taskId]['status'] = Task::STATUS_CANCELLED;
            $this->metrics['cancelled_tasks']++;
            return true;
        }

        return false;
    }

    /**
     * Cancel all tasks in a group
     *
     * @param string $groupName
     * @return bool
     */
    public function cancelGroup(string $groupName): bool
    {
        if (!isset($this->taskGroups[$groupName])) {
            return false;
        }

        $success = true;

        foreach ($this->taskGroups[$groupName]['tasks'] as $taskId) {
            $result = $this->cancelTask($taskId);
            $success = $success && $result;
        }

        return $success;
    }

    /**
     * Cancel all tasks in a batch
     *
     * @param string $batchId
     * @return bool
     */
    public function cancelBatch(string $batchId): bool
    {
        if (!isset($this->taskBatches[$batchId])) {
            return false;
        }

        $success = true;

        foreach ($this->taskBatches[$batchId]['tasks'] as $taskId) {
            $result = $this->cancelTask($taskId);
            $success = $success && $result;
        }

        return $success;
    }

    /**
     * Get the status of a task
     *
     * @param int $taskId
     * @return array
     */
    public function getTaskStatus(int $taskId): array
    {
        return $this->taskStatuses[$taskId] ?? [
            'id' => $taskId,
            'status' => 'unknown',
            'error' => 'Task not found',
        ];
    }

    /**
     * Apply middleware to task parameters
     *
     * @param array $params
     * @return array
     */
    private function applyMiddleware(array $params): array
    {
        $processedParams = $params;

        foreach ($this->middleware as $middleware) {
            if (is_callable($middleware)) {
                $processedParams = $middleware($processedParams);
            } elseif (is_string($middleware) && class_exists($middleware)) {
                $instance = new $middleware();
                $task = ['params' => $processedParams];
                $result = $instance->process($task);
                $processedParams = $result['params'];
            }
        }

        return $processedParams;
    }

    /**
     * Handle task execution with retry and monitoring
     *
     * @param array $data
     * @return mixed
     */
    public function handleTask(array $data)
    {
        $taskId = $data['id'];
        $className = $data['class'];
        $params = $data['params'];

        // Update task status
        $this->taskStatuses[$taskId]['status'] = Task::STATUS_PROCESSING;
        $this->taskStatuses[$taskId]['attempts']++;
        $this->taskStatuses[$taskId]['started_at'] = microtime(true);

        try {
            $task = new $className();
            $result = $task->handle($params);

            $this->taskStatuses[$taskId]['status'] = Task::STATUS_COMPLETED;
            $this->taskStatuses[$taskId]['completed_at'] = microtime(true);
            $this->taskStatuses[$taskId]['execution_time'] =
                $this->taskStatuses[$taskId]['completed_at'] - $this->taskStatuses[$taskId]['started_at'];
            $this->taskStatuses[$taskId]['result'] = $result;

            $this->metrics['completed_tasks']++;
            $this->updateExecutionTimeMetric($this->taskStatuses[$taskId]['execution_time']);

            // Update group/batch if applicable
            $this->updateGroupOrBatchStatus($taskId, true, $result);

            return $result;
        } catch (\Exception $e) {
            $error = $e->getMessage();
            $this->taskStatuses[$taskId]['error'] = $error;

            // Check if we should retry
            if (isset($this->retryConfigs[$taskId])) {
                $config = $this->retryConfigs[$taskId];
                $attempts = $this->taskStatuses[$taskId]['attempts'];

                if ($attempts < $config['attempts']) {
                    $this->taskStatuses[$taskId]['status'] = Task::STATUS_RETRYING;
                    $this->metrics['retried_tasks']++;

                    // Calculate delay with exponential backoff if specified
                    $delay = $config['delay'];
                    if (isset($config['multiplier']) && $config['multiplier'] > 1) {
                        $delay = $delay * (pow($config['multiplier'], $attempts - 1));
                    }

                    // Re-enqueue with delay
                    $this->enqueueDelayed($className, $params, $delay);
                    return ['retrying' => true, 'attempt' => $attempts, 'error' => $error];
                }
            }

            // No more retries or no retry configured
            $this->taskStatuses[$taskId]['status'] = Task::STATUS_FAILED;
            $this->taskStatuses[$taskId]['completed_at'] = microtime(true);
            $this->taskStatuses[$taskId]['execution_time'] =
                $this->taskStatuses[$taskId]['completed_at'] - $this->taskStatuses[$taskId]['started_at'];

            $this->metrics['failed_tasks']++;

            // Update group/batch if applicable
            $this->updateGroupOrBatchStatus($taskId, false, ['error' => $error]);

            return ['error' => $error, 'status' => 'failed'];
        }
    }

    /**
     * Update group or batch status when a task completes
     *
     * @param int $taskId
     * @param bool $success
     * @param mixed $result
     * @return void
     */
    private function updateGroupOrBatchStatus(int $taskId, bool $success, $result): void
    {
        // Check if task belongs to a group
        foreach ($this->taskGroups as $groupName => &$group) {
            if (in_array($taskId, $group['tasks'])) {
                $group['results'][$taskId] = $result;

                if ($success) {
                    $group['completed']++;
                } else {
                    $group['failed']++;
                }
            }
        }

        // Check if task belongs to a batch
        foreach ($this->taskBatches as $batchId => &$batch) {
            if (in_array($taskId, $batch['tasks'])) {
                $batch['results'][$taskId] = $result;

                if ($success) {
                    $batch['completed']++;
                } else {
                    $batch['failed']++;
                }
            }
        }
    }

    /**
     * Update average execution time metric
     *
     * @param float $executionTime
     * @return void
     */
    private function updateExecutionTimeMetric(float $executionTime): void
    {
        $totalCompleted = $this->metrics['completed_tasks'];
        $currentAvg = $this->metrics['average_execution_time'];

        // Update running average
        if ($totalCompleted === 1) {
            $this->metrics['average_execution_time'] = $executionTime;
        } else {
            $this->metrics['average_execution_time'] =
                (($currentAvg * ($totalCompleted - 1)) + $executionTime) / $totalCompleted;
        }
    }

    /**
     * Get metrics for monitoring
     *
     * @return array
     */
    public function getMetrics(): array
    {
        return $this->metrics;
    }

    /**
     * Dispatch the next task to Swoole
     */
    private function dispatchNextTask(): void
    {
        $task = $this->getNextTask();

        if ($task !== null) {
            $this->server->task($task);
        }
    }

    /**
     * Get the next task from the highest priority queue
     *
     * @return array|null
     */
    public function getNextTask(): ?array
    {
        foreach ($this->queues as $priority => $queue) {
            if (!empty($queue)) {
                $task = array_shift($this->queues[$priority]);
                return $task;
            }
        }

        return null;
    }

    /**
     * Get next task ID
     *
     * @return int
     */
    private function getNextTaskId(): int
    {
        return $this->nextTaskId++;
    }

    /**
     * Validate that a task class implements TaskInterface
     *
     * @param string $taskClass
     * @throws \InvalidArgumentException
     */
    private function validateTaskClass(string $taskClass): void
    {
        if (!class_exists($taskClass)) {
            throw new \InvalidArgumentException("Task class {$taskClass} does not exist");
        }

        $reflection = new ReflectionClass($taskClass);

        if (!$reflection->implementsInterface(TaskInterface::class)) {
            throw new \InvalidArgumentException("Task class {$taskClass} must implement " . TaskInterface::class);
        }
    }

    /**
     * Process a completed task
     *
     * @param int $taskId
     * @param mixed $result
     */
    public function taskComplete(int $taskId, $result): void
    {
        // Handle task completion (can be extended to call callbacks, etc.)
    }
}