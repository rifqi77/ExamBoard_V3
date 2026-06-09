<?php

namespace App\Http\Middleware;

use App\Support\Capabilities;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = $request->user();
        $impersonator = $request->attributes->get('impersonator');

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'uid' => $user->id,
                    'username' => $user->username,
                    'fullName' => $user->full_name,
                    'role' => $user->role,
                    'active' => (bool) $user->active,
                    'subject' => $user->subject,
                    'capabilities' => $user->role === 'teacher'
                        ? Capabilities::fill($user->capabilities)
                        : null,
                    'impersonationSourceUid' => $user->getAttribute('impersonation_source_uid'),
                ] : null,
                'impersonator' => $impersonator ? [
                    'uid' => $impersonator->id,
                    'fullName' => $impersonator->full_name,
                ] : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
