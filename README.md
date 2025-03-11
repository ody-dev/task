# TaskManager API Reference

## Table of Contents

- [Task Class](#task-class)
- [TaskInterface](#taskinterface)
- [TaskMiddlewareInterface](#taskmiddlewareinterface)
- [TaskGroup Class](#taskgroup-class)
- [TaskBatch Class](#taskbatch-class)
- [TaskManager Class](#taskmanager-class)
- [TaskHandler Class](#Taskhandler-class)

## Task Class

The primary entry point for creating and managing tasks in the system.

### Constants

| Constant | Type | Value | Description |
|----------|------|-------|-------------|
| `PRIORITY_HIGH` | int | 20 | High priority tasks are processed before normal and low priority tasks |
| `PRIORITY_NORMAL` | int | 10 | Default priority level |
| `PRIORITY_LOW` | int | 5 | Low priority tasks are processed after high and normal priority tasks |
| `STATUS_PENDING` | string | 'pending' | Task is waiting to be processed |
| `STATUS_PROCESSING` | string | 'processing' | Task is currently being processed |
| `STATUS_COMPLETED` | string | 'completed' | Task has been completed successfully |
| `STATUS_FAILED` | string | 'failed' | Task has failed |
| `STATUS_CANCELLED` | string | 'cancelled' | Task has been cancelled |
| `STATUS_RETRYING` | string | 'retrying' | Task failed but will be retried |

### Methods

#### `execute(string $taskClass, array $params = [], int $priority = self::PRIORITY_NORMAL): int`

Execute a task immediately.

**Parameters:**
- `$taskClass`: The full class name of the task to execute (must implement TaskInterface)
- `$params`: Parameters to pass to the task's handle method
- `$priority`: Priority level for the task

**Returns:** Task ID

**Example:**
```php
$taskId = Task::execute(\App\Tasks\SendEmailTask::class, [
    'to' => 'user@example.com',
    'subject' => 'Welcome',
    'body' => 'Welcome to our service!'
]);
```

#### `later(string $taskClass, array $params = [], int $delayMs = 1000, int $priority = self::PRIORITY_NORMAL): int`

Schedule a task to run after a delay.

**Parameters:**
- `$taskClass`: The full class name of the task to execute
- `$params`: Parameters to pass to the task's handle method
- `$delayMs`: Delay in milliseconds before executing the task
- `$priority`: Priority level for the task

**Returns:** Task ID

**Example:**
```php
$taskId = Task::later(\App\Tasks\SendReminderTask::class, 
    ['user_id' => 123], 
    60000  // 60 seconds
);
```

#### `withRetry(string $taskClass, array $params = [], array $retryOptions = ['attempts' => 3, 'delay' => 1000, 'multiplier' => 2], int $priority = self::PRIORITY_NORMAL): int`

Execute a task with automatic retry on failure.

**Parameters:**
- `$taskClass`: The full class name of the task to execute
- `$params`: Parameters to pass to the task's handle method
- `$retryOptions`: Retry configuration
  - `attempts`: Maximum number of attempts (including the first attempt)
  - `delay`: Initial delay in milliseconds before retrying
  - `multiplier`: Multiplier for exponential backoff (each retry increases delay by this factor)
- `$priority`: Priority level for the task

**Returns:** Task ID

**Example:**
```php
$taskId = Task::withRetry(\App\Tasks\APIRequestTask::class, 
    ['endpoint' => '/api/data', 'method' => 'GET'], 
    ['attempts' => 5, 'delay' => 1000, 'multiplier' => 2]
);
```

#### `group(string $name = null): TaskGroup`

Create a new task group for organizing related tasks.

**Parameters:**
- `$name`: Optional name for the group (auto-generated if not provided)

**Returns:** A new TaskGroup instance

**Example:**
```php
$group = Task::group('user-onboarding')
    ->add(\App\Tasks\CreateUserTask::class, ['email' => 'user@example.com'])
    ->add(\App\Tasks\SendWelcomeEmailTask::class, ['email' => 'user@example.com']);
```

#### `batch(array $tasks): TaskBatch`

Create a batch for processing multiple similar tasks efficiently.

**Parameters:**
- `$tasks`: Array of task definitions (strings or arrays with 'class' and 'params' keys)

**Returns:** A new TaskBatch instance

**Example:**
```php
$tasks = [];
foreach ($users as $user) {
    $tasks[] = [
        'class' => \App\Tasks\NotifyUserTask::class,
        'params' => ['user_id' => $user->id, 'message' => 'System maintenance']
    ];
}
$batch = Task::batch($tasks);
```

#### `cancel(int $taskId): bool`

Cancel a pending task.

**Parameters:**
- `$taskId`: The ID of the task to cancel

**Returns:** True if the task was cancelled, false otherwise

**Example:**
```php
$cancelled = Task::cancel($taskId);
```

#### `status(int $taskId): array`

Get the current status of a task.

**Parameters:**
- `$taskId`: The ID of the task

**Returns:** Array with task status information

**Example:**
```php
$status = Task::status($taskId);
// Returns: [
//   'id' => 123,
//   'class' => '\App\Tasks\SendEmailTask',
//   'status' => 'completed',
//   'attempts' => 1,
//   'created_at' => 1615480245.7843,
//   'started_at' => 1615480245.8421,
//   'completed_at' => 1615480246.0124,
//   'execution_time' => 0.1703,
//   'result' => [...],
//   'error' => null
// ]
```

## TaskInterface

Interface that all task classes must implement.

### Methods

#### `handle(array $params = [])`

Process the task.

**Parameters:**
- `$params`: Parameters passed to the task

**Returns:** Any value, which will be available in the task result

**Example:**
```php
class SendEmailTask implements TaskInterface
{
    public function handle(array $params = [])
    {
        // Send email logic here
        $result = mail($params['to'], $params['subject'], $params['body']);
        
        return [
            'success' => $result,
            'to' => $params['to'],
            'sent_at' => date('Y-m-d H:i:s')
        ];
    }
}
```

## TaskMiddlewareInterface

Interface for creating task middleware.

### Methods

#### `process(array $task): array`

Process the task before it's enqueued.

**Parameters:**
- `$task`: Array containing task information
  - `params`: The task parameters

**Returns:** Modified task array

**Example:**
```php
class LoggingMiddleware implements TaskMiddlewareInterface
{
    public function process(array $task): array
    {
        // Add logging metadata to the task
        $task['params']['__meta'] = [
            'logged_at' => time(),
            'request_id' => $_SERVER['REQUEST_ID'] ?? uniqid()
        ];
        
        return $task;
    }
}
```

## TaskGroup Class

Manages a group of related tasks.

### Methods

#### `__construct(?string $name = null)`

Create a new task group.

**Parameters:**
- `$name`: Optional name for the group (auto-generated if not provided)

#### `add(string $taskClass, array $params = [], int $priority = Task::PRIORITY_NORMAL): self`

Add a task to the group.

**Parameters:**
- `$taskClass`: The full class name of the task to execute
- `$params`: Parameters to pass to the task's handle method
- `$priority`: Priority level for the task

**Returns:** $this (for method chaining)

#### `concurrency(?int $limit): self`

Set a concurrency limit for the group.

**Parameters:**
- `$limit`: Maximum number of tasks in the group that can run simultaneously (null for no limit)

**Returns:** $this (for method chaining)

#### `allowFailures(bool $allow = true): self`

Configure whether failures in one task should stop other tasks in the group.

**Parameters:**
- `$allow`: If true, other tasks will continue even if some tasks fail

**Returns:** $this (for method chaining)

#### `middleware($middleware): self`

Add middleware to all tasks in this group.

**Parameters:**
- `$middleware`: Middleware class name or callable

**Returns:** $this (for method chaining)

#### `dispatch(): array`

Execute all tasks in the group.

**Returns:** Array of task IDs

#### `wait(int $timeout = 30000): array`

Wait for all tasks in the group to complete.

**Parameters:**
- `$timeout`: Maximum time to wait in milliseconds

**Returns:** Array of task results, indexed by task ID

#### `cancel(): bool`

Cancel all pending tasks in the group.

**Returns:** True if all tasks were cancelled, false otherwise

## TaskBatch Class

Efficiently processes multiple similar tasks.

### Methods

#### `__construct(array $tasks)`

Create a new task batch.

**Parameters:**
- `$tasks`: Array of task definitions (strings or arrays with 'class' and 'params' keys)

#### `dispatch(int $priority = Task::PRIORITY_NORMAL): array`

Execute all tasks in the batch.

**Parameters:**
- `$priority`: Priority level for all tasks in the batch

**Returns:** Array of task IDs

#### `wait(int $timeout = 30000): array`

Wait for all tasks in the batch to complete.

**Parameters:**
- `$timeout`: Maximum time to wait in milliseconds

**Returns:** Array of task results, indexed by task ID

#### `cancel(): bool`

Cancel all pending tasks in the batch.

**Returns:** True if all tasks were cancelled, false otherwise

## TaskManager Class

Core class managing task queues and processing.

### Methods

#### `getInstance(): TaskManager`

Get the singleton instance of the TaskManager.

**Returns:** TaskManager instance

#### `setServer(Server $server): void`

Set the Swoole server instance.

**Parameters:**
- `$server`: Swoole server instance

#### `registerMiddleware($middleware): self`

Register global middleware that applies to all tasks.

**Parameters:**
- `$middleware`: Middleware class name or callable

**Returns:** $this (for method chaining)

#### `enqueue(string $taskClass, array $params = [], int $priority = Task::PRIORITY_NORMAL): int`

Add a task to the queue for immediate execution.

**Parameters:**
- `$taskClass`: The full class name of the task to execute
- `$params`: Parameters to pass to the task's handle method
- `$priority`: Priority level for the task

**Returns:** Task ID

#### `enqueueDelayed(string $taskClass, array $params = [], int $delayMs = 1000, int $priority = Task::PRIORITY_NORMAL): int`

Add a task to be executed after a delay.

**Parameters:**
- `$taskClass`: The full class name of the task to execute
- `$params`: Parameters to pass to the task's handle method
- `$delayMs`: Delay in milliseconds before executing the task
- `$priority`: Priority level for the task

**Returns:** Task ID

#### `enqueueWithRetry(string $taskClass, array $params = [], array $retryOptions = ['attempts' => 3, 'delay' => 1000, 'multiplier' => 2], int $priority = Task::PRIORITY_NORMAL): int`

Add a task with retry options.

**Parameters:**
- `$taskClass`: The full class name of the task to execute
- `$params`: Parameters to pass to the task's handle method
- `$retryOptions`: Retry configuration
  - `attempts`: Maximum number of attempts (including the first attempt)
  - `delay`: Initial delay in milliseconds before retrying
  - `multiplier`: Multiplier for exponential backoff
- `$priority`: Priority level for the task

**Returns:** Task ID

#### `getNextTask(): ?array`

Get the next task from the highest priority queue.

**Returns:** Task data array or null if queues are empty

#### `addTaskToGroup(int $taskId, string $groupName): void`

Add a task to a group.

**Parameters:**
- `$taskId`: The ID of the task
- `$groupName`: The name of the group

#### `addTaskToBatch(int $taskId, string $batchId): void`

Add a task to a batch.

**Parameters:**
- `$taskId`: The ID of the task
- `$batchId`: The ID of the batch

#### `waitForGroup(string $groupName, int $timeout = 30000): array`

Wait for all tasks in a group to complete.

**Parameters:**
- `$groupName`: The name of the group
- `$timeout`: Maximum time to wait in milliseconds

**Returns:** Array of task results, indexed by task ID

#### `waitForBatch(string $batchId, int $timeout = 30000): array`

Wait for all tasks in a batch to complete.

**Parameters:**
- `$batchId`: The ID of the batch
- `$timeout`: Maximum time to wait in milliseconds

**Returns:** Array of task results, indexed by task ID

#### `cancelTask(int $taskId): bool`

Cancel a specific task.

**Parameters:**
- `$taskId`: The ID of the task to cancel

**Returns:** True if the task was cancelled, false otherwise

#### `cancelGroup(string $groupName): bool`

Cancel all tasks in a group.

**Parameters:**
- `$groupName`: The name of the group

**Returns:** True if all tasks were cancelled, false otherwise

#### `cancelBatch(string $batchId): bool`

Cancel all tasks in a batch.

**Parameters:**
- `$batchId`: The ID of the batch

**Returns:** True if all tasks were cancelled, false otherwise

#### `getTaskStatus(int $taskId): array`

Get the status of a task.

**Parameters:**
- `$taskId`: The ID of the task

**Returns:** Array with task status information

#### `getMetrics(): array`

Get metrics for monitoring.

**Returns:** Array with metrics:
- `total_tasks`: Total number of tasks created
- `completed_tasks`: Number of successfully completed tasks
- `failed_tasks`: Number of failed tasks
- `retried_tasks`: Number of retried tasks
- `cancelled_tasks`: Number of cancelled tasks
- `average_execution_time`: Average task execution time in seconds

## TaskHandler Class

Integrates the TaskManager with Swoole.

### Methods

#### `init(Server $server): void`

Initialize the task handler with the server.

**Parameters:**
- `$server`: Swoole server instance

**Example:**
```php
$server = new Swoole\Server('0.0.0.0', 9501);
$server->set([
    'worker_num' => 4,
    'task_worker_num' => 8,
    'task_enable_coroutine' => true,
]);

SwooleTaskHandler::init($server);
```

#### `handleTask(array $data)`

Handle a task execution.

**Parameters:**
- `$data`: Task data array

**Returns:** The result from the task's handle method, or an error array
