<?php

namespace App\Http\Controllers\Admin;

use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityResource;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\ProjectRequestResource;
use App\Http\Resources\ServiceResource;
use App\Http\Resources\TicketResource;
use App\Http\Resources\UserResource;
use App\Mail\WelcomeMail;
use App\Models\User;
use App\Services\OfflinePayments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ClientController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $search = $request->string('search')->trim()->toString();

        $clients = $this->withStats(User::query()->where('role', Role::Client))
            ->when($search, fn (Builder $q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('company', 'like', "%{$search}%")))
            ->orderByDesc('is_active')->orderBy('company')->orderBy('name')
            ->get();

        return UserResource::collection($clients);
    }

    public function store(Request $request): UserResource
    {
        $data = $this->validated($request);
        $client = User::create($data + ['role' => Role::Client, 'notification_prefs' => User::DEFAULT_PREFS]);

        if ($request->boolean('send_invite')) {
            Mail::to($client)->queue(new WelcomeMail($client));
        }

        return new UserResource($client);
    }

    public function show(User $client): JsonResponse
    {
        abort_if($client->isAdmin(), 404);
        $client = $this->withStats(User::query())->findOrFail($client->id);

        return response()->json([
            'client' => new UserResource($client),
            'services' => ServiceResource::collection($client->services()->orderBy('sort')->get()),
            'invoices' => InvoiceResource::collection($client->invoices()->with('payments')->latest('id')->get()),
            'payments' => PaymentResource::collection($client->payments()->with('invoices')->latest('id')->limit(20)->get()),
            'tickets' => TicketResource::collection($client->tickets()->withCount('messages')->latest('last_activity_at')->get()),
            'requests' => ProjectRequestResource::collection($client->projectRequests()->latest()->get()),
            'activity' => ActivityResource::collection($client->activities()->latest()->latest('id')->limit(30)->get()),
        ]);
    }

    public function update(Request $request, User $client): UserResource
    {
        abort_if($client->isAdmin(), 404);
        $client->update($this->validated($request, $client));

        return new UserResource($client);
    }

    public function destroy(User $client): JsonResponse
    {
        abort_if($client->isAdmin(), 404);
        $client->delete();
        Storage::disk(OfflinePayments::DISK)->deleteDirectory("receipts/{$client->id}");

        return response()->json(['ok' => true]);
    }

    public function invite(User $client): JsonResponse
    {
        abort_if($client->isAdmin(), 404);
        Mail::to($client)->queue(new WelcomeMail($client));

        return response()->json(['ok' => true]);
    }

    private function withStats(Builder $query): Builder
    {
        return $query
            ->withCount(['services', 'tickets as open_tickets_count' => fn ($q) => $q->where('status', '!=', TicketStatus::Resolved)])
            ->withSum(['invoices as outstanding_cents' => fn ($q) => $q->where('status', InvoiceStatus::Unpaid)], 'total_cents');
    }

    private function validated(Request $request, ?User $client = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users')->ignore($client?->id)],
            'company' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:500'],
            'admin_notes' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $data['email'] = mb_strtolower($data['email']);

        return $data;
    }
}
