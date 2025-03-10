<?php
namespace Ody\Task;

/**
 * TaskInterface - All tasks must implement this interface
 */
interface TaskInterface
{
    /**
     * Handle method that will be called when the task is executed
     *
     * @param array $params
     * @return mixed
     */
    public function handle(array $params = []);
}