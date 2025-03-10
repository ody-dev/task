<?php

namespace Ody\Task;

class TaskGroup
{
    /**
     * @var string|null Group name
     */
    private ?string $name;

    /**
     * @var array Task definitions
     */
    private array $tasks = [];

    /**
     * @var array Group options
     */
    private array $options = [
        'concurrency' => null,
        'allowFailures' => false,
    ];

    /**
     * @var array Task IDs in this group
     */
    private array $taskIds = [];

    /**
     * @var array Middleware to apply to all tasks in the group
     */
    private array $middleware = [];

    /**
     * Create a new task group
     *
     * @param string|null $name
     */
    public function __construct(?string $name = null)
    {
        $this->name = $name ?? 'group_' . uniqid();
    }

    /**
     * Add a task to the group
     *
     * @param string $taskClass
     * @param array $params
     * @param int $priority
     * @return $this
     */
    public function add(string $taskClass, array $params = [], int $priority = Task::PRIORITY_NORMAL): self
    {
        $this->tasks[] = [
            'class' => $taskClass,
            'params' => $params,
            'priority' => $priority,
        ];

        return $this;
    }

    /**
     * Set concurrency limit
     *
     * @param int|null $limit
     * @return $this
     */
    public function concurrency(?int $limit): self
    {
        $this->options['concurrency'] = $limit;
        return $this;
    }

    /**
     * Allow failures without stopping the group
     *
     * @param bool $allow
     * @return $this
     */
    public function allowFailures(bool $allow = true): self
    {
        $this->options['allowFailures'] = $allow;
        return $this;
    }

    /**
     * Add middleware to all tasks in this group
     *
     * @param callable|string $middleware
     * @return $this
     */
    public function middleware($middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    /**
     * Execute all tasks in the group
     *
     * @return array Task IDs
     */
    public function dispatch(): array
    {
        $taskManager = TaskManager::getInstance();

        // Apply group metadata and middleware to each task
        foreach ($this->tasks as $task) {
            // Enhance params with group information
            $task['params']['__group'] = [
                'name' => $this->name,
                'options' => $this->options,
            ];

            // Apply any group middleware
            $taskWithMiddleware = $task;
            foreach ($this->middleware as $middleware) {
                if (is_callable($middleware)) {
                    $taskWithMiddleware = $middleware($taskWithMiddleware);
                } elseif (is_string($middleware) && class_exists($middleware)) {
                    $instance = new $middleware();
                    $taskWithMiddleware = $instance->process($taskWithMiddleware);
                }
            }

            // Enqueue the task
            $taskId = $taskManager->enqueue(
                $taskWithMiddleware['class'],
                $taskWithMiddleware['params'],
                $taskWithMiddleware['priority']
            );

            $this->taskIds[] = $taskId;

            // Register the task with the group in the task manager
            $taskManager->addTaskToGroup($taskId, $this->name);
        }

        return $this->taskIds;
    }

    /**
     * Wait for all tasks in the group to complete
     *
     * @param int $timeout Timeout in milliseconds
     * @return array Results of all tasks
     */
    public function wait(int $timeout = 30000): array
    {
        if (empty($this->taskIds)) {
            $this->dispatch();
        }

        return TaskManager::getInstance()->waitForGroup($this->name, $timeout);
    }

    /**
     * Cancel all tasks in the group
     *
     * @return bool
     */
    public function cancel(): bool
    {
        if (empty($this->taskIds)) {
            return true;
        }

        return TaskManager::getInstance()->cancelGroup($this->name);
    }
}