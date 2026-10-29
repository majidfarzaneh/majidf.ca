<?php

namespace App\Http\Controllers\Portal;

use App\Enums\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ServiceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return ServiceResource::collection(
            $request->user()->services()->where('status', '!=', ServiceStatus::Cancelled)->orderBy('sort')->orderBy('id')->get()
        );
    }
}
