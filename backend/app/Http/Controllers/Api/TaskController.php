<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    use RespondsWithJson;

    public function index(Request $request): JsonResponse
    {
        return $this->paginated(
            Task::query()
                ->where('user_id', $request->user()->id)
                ->latest()
                ->paginate($request->integer('per_page', 20))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'in:pending,in_progress,completed,cancelled'],
            'priority' => ['nullable', 'in:low,medium,high,critical'],
            'due_date' => ['nullable', 'date'],
            'company_id' => ['nullable', 'exists:companies,id'],
        ]);

        $data['user_id'] = $request->user()->id;

        return $this->created(Task::query()->create($data));
    }

    public function update(Request $request, Task $task): JsonResponse
    {
        abort_unless($task->user_id === $request->user()->id || $request->user()->isGroupAdmin(), 403);

        $task->fill($request->validate([
            'title' => ['sometimes', 'string'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'in:pending,in_progress,completed,cancelled'],
            'priority' => ['sometimes', 'in:low,medium,high,critical'],
            'due_date' => ['nullable', 'date'],
        ]))->save();

        return $this->ok($task->fresh());
    }
}
