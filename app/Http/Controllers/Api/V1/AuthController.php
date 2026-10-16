<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends ApiController
{
    /**
     * Use the configured bcrypt cost for missing-account checks to avoid a
     * cheaper password-verification path that reveals whether an email exists.
     */
    private const DUMMY_HASH_TEMPLATE = '$2y$%02d$O6465KiSK1gsAdl9.Xj9e.uCn0Adtb4tsNOG7sNX6GvA7v6FNVHw6';

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:255'],
        ]);

        return DB::transaction(function () use ($credentials): JsonResponse {
            $user = User::query()
                ->where('email', $credentials['email'])
                ->lockForUpdate()
                ->first();

            $passwordMatches = Hash::check(
                $credentials['password'],
                $user?->password ?? sprintf(self::DUMMY_HASH_TEMPLATE, (int) config('hashing.bcrypt.rounds', 12)),
            );

            if ($user === null || ! $passwordMatches || ! $user->is_active) {
                return $this->error('unauthorized', 'The provided credentials are incorrect.', 401);
            }

            $deviceName = $credentials['device_name'] ?? 'mobile';
            $token = $user->createToken($deviceName, ['*']);

            return $this->data([
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'user' => $this->userArray($user),
            ]);
        });
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        return $this->data(['user' => $this->userArray($request->user())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function userArray(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'is_active' => $user->is_active,
        ];
    }
}
