<?php

namespace Ody\Task;

class TaskBatch
{
    /**
     * @var array Task definitions
     */
    private array $tasks;

    /**
     * @var string|null Batch ID
     */
    private string $id;

    /**
     * @var array Task IDs in this batch
     */
    private array $taskIds = [];

    /**
     * Create a new task batch
     *
     * @param array $tasks
     */
    public function __construct(array $tasks)
    {
        $this->tasks = $tasks;
        $this->id = 'batch_' . uniqid();
    }

    /**
     * Execute all tasks in the batch
     *
     * @param int $priority
     * @return array Task IDs
     */
    public function dispatch(int $priority = Task::PRIORITY_NORMAL): array
    {
        $taskManager = TaskManager::getInstance();

        foreach ($this->tasks as $task) {
            $className = null;
            $params = [];

            // Handle different task definition formats
            if (is_string($task)) {
                $className = $task;
            } elseif (is_array($task) && isset($task['class'])) {
                $className = $task['class'];
                $params = $task['params'] ?? [];
            }

            if ($className) {
                // Add batch metadata
                $params['__batch'] = [
                    'id' => $this->id,
                    'total' => count($this->tasks),
                ];

                $taskId = $taskManager->enqueue($className, $params, $priority);
                $this->taskIds[] = $taskId;

                // Register the task with the batch
                $taskManager->addTaskToBatch($taskId, $this->id);
            }
        }

        return $this->taskIds;
    }

    /**
     * Wait for all tasks in the batch to complete
     *
     * @param int $timeout Timeout in milliseconds
     * @return array Results of all tasks
     */
    public function wait(int $timeout = 30000): array
    {
        if (empty($this->taskIds)) {
            $this->dispatch();
        }

        return TaskManager::getInstance()->waitForBatch($this->id, $timeout);
    }

    /**
     * Cancel all tasks in the batch
     *
     * @return bool
     */
    public function cancel(): bool
    {
        if (empty($this->taskIds)) {
            return true;
        }

        return TaskManager::getInstance()->cancelBatch($this->id);
    }
}