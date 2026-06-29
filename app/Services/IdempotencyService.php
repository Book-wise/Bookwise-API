<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IdempotencyService
{
    private const IN_FLIGHT_TTL_SECONDS = 30;

    private const COMPLETED_TTL_HOURS = 24;

    /**
     * Check for a cached idempotent response without acquiring a slot.
     *
     * Returns the cached JsonResponse if the key exists and is completed,
     * or null if the key doesn't exist, is still in-flight, or has expired.
     */
    public function check(Request $request, string $endpoint): ?JsonResponse
    {
        $key = $request->header('Idempotency-Key');

        if ($key === null) {
            return null;
        }

        $entry = DB::table('idempotency_keys')
            ->where('key', $key)
            ->where('endpoint', $endpoint)
            ->where('method', $request->method())
            ->whereNotNull('response_status')
            ->first();

        if ($entry === null) {
            return null;
        }

        // Check if the cached response has expired
        if ($entry->expires_at !== null && now()->greaterThan(Carbon::parse($entry->expires_at))) {
            DB::table('idempotency_keys')
                ->where('id', $entry->id)
                ->delete();

            return null;
        }

        return response()->json(json_decode($entry->response_body, true), $entry->response_status);
    }

    /**
     * Atomically acquire an idempotency slot for the given request.
     *
     * Uses SELECT ... FOR UPDATE on the unique index (key, endpoint, method)
     * to serialize concurrent requests. MySQL's next-key locking prevents
     * phantom inserts for the same key combination.
     *
     * @return int 0 = acquired (caller should proceed),
     *             1 = in-flight or hash mismatch (caller should 409),
     *             2 = completed (caller should return the cached response)
     */
    public function acquire(Request $request, string $endpoint, string $requestHash): int
    {
        $key = $request->header('Idempotency-Key');
        $method = $request->method();

        // No key = caller doesn't want idempotency, proceed
        if ($key === null) {
            return 0;
        }

        return DB::transaction(function () use ($key, $endpoint, $method, $requestHash) {
            // Acquire gap/row lock on the unique index to serialize
            $existing = DB::table('idempotency_keys')
                ->where('key', $key)
                ->where('endpoint', $endpoint)
                ->where('method', $method)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                // Key exists — determine its state

                // Handle expired entries
                if ($existing->expires_at !== null && now()->greaterThan(Carbon::parse($existing->expires_at))) {
                    // Reclaim the expired slot for a fresh attempt
                    DB::table('idempotency_keys')
                        ->where('id', $existing->id)
                        ->update([
                            'request_hash' => $requestHash,
                            'response_status' => null,
                            'response_body' => null,
                            'expires_at' => Carbon::now()->addSeconds(self::IN_FLIGHT_TTL_SECONDS),
                            'updated_at' => Carbon::now(),
                        ]);

                    return 0;
                }

                // AD-2: Hash mismatch — signal conflict
                if ($existing->request_hash !== $requestHash) {
                    return 1;
                }

                // AD-4: NULL response_status means in-flight
                if ($existing->response_status === null) {
                    return 1;
                }

                // Completed — caller should return the cached response
                return 2;
            }

            // No existing entry — insert a fresh processing slot
            DB::table('idempotency_keys')->insert([
                'key' => $key,
                'endpoint' => $endpoint,
                'method' => $method,
                'request_hash' => $requestHash,
                'response_status' => null,
                'response_body' => null,
                'expires_at' => Carbon::now()->addSeconds(self::IN_FLIGHT_TTL_SECONDS),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            return 0;
        });
    }

    /**
     * Store the completed response in the idempotency slot.
     *
     * Sets the response status, body, and extends the TTL to 24 hours.
     */
    public function store(Request $request, string $endpoint, int $status, array $body): void
    {
        $key = $request->header('Idempotency-Key');

        DB::table('idempotency_keys')
            ->where('key', $key)
            ->where('endpoint', $endpoint)
            ->where('method', $request->method())
            ->update([
                'response_status' => $status,
                'response_body' => json_encode($body),
                'expires_at' => Carbon::now()->addHours(self::COMPLETED_TTL_HOURS),
                'updated_at' => Carbon::now(),
            ]);
    }

    /**
     * Release the idempotency slot (remove processing marker).
     *
     * Called when the operation fails before storing a response,
     * allowing the client to retry with the same key.
     */
    public function release(Request $request, string $endpoint): void
    {
        $key = $request->header('Idempotency-Key');

        if ($key === null) {
            return;
        }

        // Only delete if still in-flight — never purge a completed cache
        DB::table('idempotency_keys')
            ->where('key', $key)
            ->where('endpoint', $endpoint)
            ->where('method', $request->method())
            ->whereNull('response_status')
            ->delete();
    }
}
