<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectRequestResource;
use App\Models\Activity;
use App\Models\ProjectRequest;
use App\Support\AdminAlert;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProjectRequestController extends Controller
{
    public function store(Request $request): ProjectRequestResource
    {
        $data = $request->validate([
            'types' => ['required', 'array', 'min:1'],
            'types.*' => [Rule::in(array_keys(ProjectRequest::TYPES))],
            'budget' => ['required', Rule::in(array_keys(ProjectRequest::BUDGETS))],
            'description' => ['nullable', 'string', 'max:5000'],
        ], ['types.required' => 'Pick at least one thing we are building.']);

        $user = $request->user();
        $projectRequest = $user->projectRequests()->create($data + ['status' => 'new']);

        Activity::log($user, 'request', 'Project request sent · '.implode(', ', $projectRequest->typeLabels()), $projectRequest);
        Audit::log('request.created', 'Requested a new project · '.implode(', ', $projectRequest->typeLabels()), $projectRequest);
        AdminAlert::send('request', 'New project request from '.($user->company ?: $user->name), [
            'Building' => implode(', ', $projectRequest->typeLabels()),
            'Budget' => 'CAD '.ProjectRequest::BUDGETS[$data['budget']],
            'Details' => Str::limit($data['description'] ?? '—', 1500),
        ], '/requests');

        return new ProjectRequestResource($projectRequest);
    }
}
