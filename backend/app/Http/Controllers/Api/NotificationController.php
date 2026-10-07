<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    use RespondsWithJson;

    public function index(Request $request): JsonResponse
    {
        $query = AppNotification::query()->where('user_id', $request->user()->id)->latest();

        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        return $this->paginated($query->paginate($request->integer('per_page', 20)), extra: [
            'unread_count' => AppNotification::query()
                ->where('user_id', $request->user()->id)
                ->whereNull('read_at')
                ->count(),
        ]);
    }

    public function markRead(Request $request, AppNotification $notification): JsonResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 403);

        $notification->forceFill(['read_at' => now()])->save();

        return $this->ok($notification);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        AppNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return $this->ok(null, __('All notifications marked as read.'));
    }

    public function destroy(Request $request, AppNotification $notification): JsonResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 403);

        $notification->delete();

        return $this->ok(null, __('Notification deleted.'));
    }
}
