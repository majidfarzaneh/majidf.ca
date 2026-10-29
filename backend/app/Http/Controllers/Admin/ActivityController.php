<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityResource;
use App\Models\Activity;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Post updates (milestones, notes) to a client's activity feed. */
class ActivityController extends Controller
{
    public function store(Request $request): ActivityResource
    {
        $data = $request->validate([
            'user_id' => ['required', Rule::exists('users', 'id')->where('role', 'client')],
            'kind' => ['required', Rule::in(array_keys(Activity::KINDS))],
            'text' => ['required', 'string', 'max:255'],
        ]);

        $activity = Activity::log($data['user_id'], $data['kind'], $data['text']);
        Audit::log('timeline.posted', "Posted to the client's timeline: {$data['text']}", $activity, User::find($data['user_id']));

        return new ActivityResource($activity);
    }

    public function destroy(Activity $activity): JsonResponse
    {
        $activity->delete();

        return response()->json(['ok' => true]);
    }
}
