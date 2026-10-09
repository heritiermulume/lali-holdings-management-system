<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => __('You are not authorized to perform this action.'),
            ], 403);
        }

        // Always re-read role permissions from the DB so role edits apply without waiting for a new login.
        $user->refreshAccessRelations();

        if (! $user->is_active) {
            return response()->json([
                'success' => false,
                'message' => __('This account is inactive.'),
            ], 403);
        }

        $allowed = collect($permissions)->contains(
            fn (string $permission) => $user->hasPermission($permission)
        );

        if (! $allowed) {
            return response()->json([
                'success' => false,
                'message' => __('You are not authorized to perform this action.'),
            ], 403);
        }

        return $next($request);
    }
}
