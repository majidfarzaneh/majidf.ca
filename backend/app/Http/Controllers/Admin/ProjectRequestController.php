<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ProjectRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectRequestResource;
use App\Models\ProjectRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ProjectRequestController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return ProjectRequestResource::collection(
            ProjectRequest::query()->with('user')
                ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
                ->latest()
                ->get()
        );
    }

    public function update(Request $request, ProjectRequest $projectRequest): ProjectRequestResource
    {
        $projectRequest->update($request->validate([
            'status' => ['sometimes', Rule::enum(ProjectRequestStatus::class)],
            'admin_notes' => ['nullable', 'string', 'max:5000'],
        ]));

        return new ProjectRequestResource($projectRequest->load('user'));
    }

    public function destroy(ProjectRequest $projectRequest): JsonResponse
    {
        $projectRequest->delete();

        return response()->json(['ok' => true]);
    }
}
