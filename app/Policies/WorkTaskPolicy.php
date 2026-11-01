<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkTask;
use App\Services\Tasks\TaskBoard;
use App\Support\Access\AccessGate;

class WorkTaskPolicy
{
    public function __construct(
        private AccessGate $access,
        private TaskBoard $tasks,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allows($user, 'tasks.view');
    }

    public function view(User $user, WorkTask $task): bool
    {
        return $this->tasks->query($user)->whereKey($task->id)->exists();
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user, 'tasks.create');
    }

    public function update(User $user, WorkTask $task): bool
    {
        return $this->view($user, $task) && $this->access->allows($user, 'tasks.edit');
    }
}
