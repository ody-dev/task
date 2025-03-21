<?php

namespace Ody\Task;

/**
 * Interface for task middleware
 */
interface TaskMiddlewareInterface
{
    /**
     * Process the task
     *
     * @param array $task
     * @return array
     */
    public function process(array $task): array;
}