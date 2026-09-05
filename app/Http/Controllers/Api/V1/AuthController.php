<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Events\UserRegistered;
use App\Events\UserRequestedPasswordReset;
use App\Exceptions\AvatarProcessingUnavailable;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\AvatarUploadRequest;
use App\Http\Requests\V1\ForgotPasswordRequest;
use App\Http\Requests\V1\PasswordChangeRequest;
use App\Http\Requests\V1\ProfileUpdateRequest;
use App\Http\Requests\V1\RegisterRequest;
use App\Http\Requests\V1\ResetPasswordRequest;
use App\Http\Requests\V1\VerifyEmailRequest;
use App\Http\Resources\UserResource;
use App\Models\EmailVerificationToken;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Services\AvatarService;
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
     * Forgot password (REQ-3): mint a single-use 60-minute reset token for an
     * existing user and hand it to the queued email push. The response is the
     * byte-identical 200 for unknown emails (no enumeration), and unknown
     * emails produce no write and no event.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        if ($user !== null) {
            $plainToken = Str::random(64);
            $expiresAt = now()->addMinutes(PasswordResetToken::EXPIRES_IN_MINUTES);

            // Last-wins overwrite (REQ-2): the email PK keeps exactly one row,
            // so a repeated forgot atomically replaces hash/expiry and clears
            // any previous usage, superseding earlier links.
            $token = PasswordResetToken::updateOrCreate(
                ['email' => $user->email],
                [
                    'token' => hash('sha256', $plainToken),
                    'expires_at' => $expiresAt,
                    'used_at' => null,
                ]
            );

            event(new UserRequestedPasswordReset($user, $plainToken, $token));
        }

        return response()->json([
            'message' => 'Si el email existe, recibirás un link para restablecer tu contraseña.',
        ]);
    }

    /**
     * Reset password (REQ-4): consume a single-use 60-minute reset token under
     * a locked transaction and swap the credentials.
     *
     * All state checks happen inside a DB::transaction with the row re-read by
     * email PK under lockForUpdate (verify-email pattern), in strict order:
     * missing row or hash mismatch → invalid_token; past expiry → token_expired;
     * consumed row → token_already_used. On success the token is marked used,
     * the password is persisted through the `hashed` cast, and EVERY Sanctum
     * token of the user is revoked (D3: reset is unauthenticated proof-of-email
     * only, the account may be compromised). No auto-login: the 200 body is the
     * fixed message only.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $hash = hash('sha256', $request->validated('token'));

        $error = DB::transaction(function () use ($request, $hash): ?string {
            $reset = PasswordResetToken::query()
                ->where('email', $request->validated('email'))
                ->lockForUpdate()
                ->first();

            if (! $reset) {
                return 'invalid_token';
            }

            if (! hash_equals($reset->token, $hash)) {
                return 'invalid_token';
            }

            if ($reset->expires_at->isPast()) {
                return 'token_expired';
            }

            if ($reset->used_at !== null) {
                return 'token_already_used';
            }

            // The token row carries no FK (email is identity, REQ-1): a row can
            // outlive its user. Defensive — never reset a nonexistent account.
            $user = User::where('email', $reset->email)->first();

            if (! $user) {
                return 'invalid_token';
            }

            // Consume the token and swap credentials atomically.
            $reset->forceFill(['used_at' => now()])->save();
            $user->password = $request->validated('password'); // cast 'hashed'
            $user->save();

            // Revoke every active session (D3/MD2) — unlike the authenticated
            // changePassword, which deliberately keeps its own session.
            $user->tokens()->delete();

            return null;
        });

        if ($error !== null) {
            return response()->json(['error' => $error], 400);
        }

        return response()->json([
            'message' => 'Tu contraseña fue restablecida correctamente. Ya puedes iniciar sesión.',
        ]);
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

        // Load tenant so UserResource exposes `business` (consistente con /auth/me).
        // Sin esto, el login omitía `business` y el front creía que faltaba onboarding.
        $user = $request->user()->load('tenant');

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
            'user' => new UserResource($request->user()->load(['tenant', 'businesses'])),
        ]);
    }

    /**
     * Cambia el negocio activo del usuario (multi-tenant). Solo admin (org) puede
     * alternar; el provider queda atado a su tenant. Devuelve el user actualizado.
     */
    public function switchTenant(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
        ]);

        $user = $request->user();

        if (! $user->isAdminGeneral()) {
            abort(403, 'Solo el admin general puede cambiar de negocio.');
        }

        $user->tenant_id = $validated['tenant_id'];
        $user->save();

        return response()->json([
            'user' => new UserResource($user->load('tenant')),
        ]);
    }

    /**
     * Actualización de perfil (contrato con el front): solo phone es editable.
     * email y name quedan read-only. Devuelve el user completo (con tenant
     * cargado para exponer `business`) para que el front refresque sin re-peticionar.
     */
    public function updateProfile(ProfileUpdateRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->phone = $request->validated('phone');
        $user->save();

        return response()->json([
            'user' => new UserResource($user->load('tenant')),
        ]);
    }

    /**
     * Subida del avatar del usuario autenticado.
     *
     * El archivo original nunca se persiste: AvatarService genera un thumbnail
     * optimizado (WebP) y guarda su URL pública en users.avatar_url, reemplazando
     * el anterior. Devuelve el user completo para que el front refresque sin
     * re-peticionar /auth/me.
     */
    public function uploadAvatar(AvatarUploadRequest $request): JsonResponse
    {
        $user = $request->user();

        try {
            $url = app(AvatarService::class)->store($request->file('avatar'), $user);
        } catch (AvatarProcessingUnavailable $e) {
            return response()->json([
                'error' => 'avatar_processing_unavailable',
                'detail' => $e->getMessage(),
            ], 501);
        }

        $user->avatar_url = $url;
        $user->save();

        return response()->json([
            'user' => new UserResource($user->load('tenant')),
        ]);
    }

    /**
     * Elimina el avatar del usuario autenticado (DELETE /auth/me/avatar).
     * Borra el archivo y nullifica users.avatar_url; el front vuelve al fallback.
     * Devuelve el user completo para que el front refresque sin re-peticionar.
     */
    public function removeAvatar(Request $request): JsonResponse
    {
        app(AvatarService::class)->remove($request->user());

        return response()->json([
            'user' => new UserResource($request->user()->load('tenant')),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    /**
     * Cambio de contraseña del usuario autenticado (desde Perfil).
     *
     * Toda la validación de reglas y de la contraseña actual vive en
     * PasswordChangeRequest. No se revoca el token actual: la sesión sigue
     * válida tras el cambio (contrato con el front).
     */
    public function changePassword(PasswordChangeRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->password = $request->validated('password'); // cast 'hashed'
        $user->save();

        return response()->json([
            'message' => 'Tu contraseña fue actualizada correctamente.',
        ]);
    }
}
