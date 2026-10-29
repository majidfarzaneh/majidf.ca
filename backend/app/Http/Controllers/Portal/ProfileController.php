<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function update(Request $request): UserResource
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'company' => ['nullable', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);
        $data['email'] = mb_strtolower($data['email']);

        $user->update($data);

        return new UserResource($user);
    }

    public function notifications(Request $request): UserResource
    {
        $rules = collect(User::DEFAULT_PREFS)->map(fn () => ['sometimes', 'boolean'])->all();
        $user = $request->user();
        $user->update(['notification_prefs' => array_merge($user->prefs(), $request->validate($rules))]);

        return new UserResource($user);
    }
}
