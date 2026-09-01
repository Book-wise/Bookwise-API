<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Events\UserRegistered;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\RegisterRequest;
use App\Http\Requests\V1\VerifyEmailRequest;
use App\Http\Resources\UserResource;
use App\Models\EmailVerificationToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'phone' => $request->validated('phone'),
            'password' => $request->validated('password'),
            // Registration always creates a technical ADMIN account (BR1);
            // the frontend can never set the role.
            'role' => UserRole::ADMIN,
            'tenant_id' => null,
            'email_verified_at' => null,
        ]);

        $plainToken = Str::random(64);

        $verification = EmailVerificationToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addHours(48),
        ]);

        event(new UserRegistered($user, $plainToken, $verification));

        return response()->json([
            'message' => 'Tu cuenta fue creada. Revisa tu correo para verificar tu email.',
            'user' => new UserResource($user),
        ], 201);
    }

    /**
     * Verify a registration email with a single-use token.
     *
     * All state checks happen inside a locked transaction (D7): the token row
     * is re-read with lockForUpdate so two concurrent PATCHes with the same
     * token can never both succeed (BR3).
     */
    public function verifyEmail(VerifyEmailRequest $request): JsonResponse
    {
        $hash = hash('sha256', $request->validated('token'));

        [$verification, $error] = DB::transaction(function () use ($hash): array {
            $verification = EmailVerificationToken::query()
                ->where('token_hash', $hash)
                ->lockForUpdate()
                ->first();

            if (! $verification) {
                return [null, 'invalid_token'];
            }

            if ($verification->expires_at->isPast()) {
                return [null, 'token_expired'];
            }

            if ($verification->used_at !== null) {
                return [null, 'token_already_used'];
            }

            $verification->forceFill(['used_at' => now()])->save();
            $verification->user->forceFill(['email_verified_at' => now()])->save();

            return [$verification, null];
        });

        if ($error !== null) {
            return response()->json(['error' => $error], 400);
        }

        return response()->json([
            'message' => 'Tu email fue verificado correctamente.',
            'user' => new UserResource($verification->user),
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($request->only('email', 'password'))) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        $user = $request->user();

        // Verified-email gate (BR7/R8.1): no token until the email is verified.
        if ($user->email_verified_at === null) {
            return response()->json([
                'error' => 'email_not_verified',
                'detail' => 'Debes verificar tu email antes de iniciar sesión.',
            ], 403);
        }

        $token = $user->createToken('api', $user->role->tokenAbilities())->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user),
        ]);
    }

    /**
     * Current authenticated user's profile (R10.1).
     *
     * tenant is eager-loaded so UserResource can expose the nested business
     * payload; a user without a tenant gets onboarding_complete=false and
     * business=null.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => new UserResource($request->user()->load('tenant')),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }
}
