<?php

namespace Tests\Unit;

use App\Services\IdempotencyService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IdempotencyServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private IdempotencyService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(IdempotencyService::class);
    }

    private function makeRequest(string $key, string $method = 'POST', string $body = '{}'): Request
    {
        $request = Request::create('/test', $method, [], [], [], [], $body);
        $request->headers->set('Idempotency-Key', $key);

        return $request;
    }

    // ── 5.1: acquire/store/release lifecycle, expired key reuse ────

    public function test_acquire_new_key_returns_zero(): void
    {
        $request = $this->makeRequest('uuid-acquire-new');

        $result = $this->service->acquire($request, 'POST /test', 'hash-1');

        $this->assertSame(0, $result);
    }

    public function test_acquire_then_store_then_acquire_returns_two(): void
    {
        $request = $this->makeRequest('uuid-store-complete');

        $this->service->acquire($request, 'POST /test', 'hash-1');
        $this->service->store($request, 'POST /test', 201, ['id' => 1]);

        $result = $this->service->acquire($request, 'POST /test', 'hash-1');

        $this->assertSame(2, $result);
    }

    public function test_acquire_release_acquire_returns_zero(): void
    {
        $request = $this->makeRequest('uuid-release-cycle');

        $this->service->acquire($request, 'POST /test', 'hash-1');
        $this->service->release($request, 'POST /test');

        $result = $this->service->acquire($request, 'POST /test', 'hash-1');

        $this->assertSame(0, $result);
    }

    public function test_expired_key_is_acquired_again(): void
    {
        $request = $this->makeRequest('uuid-expired-reuse');

        // Acquire and store, but manually set expires_at in the past
        $this->service->acquire($request, 'POST /test', 'hash-1');
        $this->service->store($request, 'POST /test', 201, ['id' => 1]);

        DB::table('idempotency_keys')
            ->where('key', 'uuid-expired-reuse')
            ->update(['expires_at' => Carbon::now()->subHour()]);

        // Now try to acquire again — should treat as fresh
        $result = $this->service->acquire($request, 'POST /test', 'hash-2');

        $this->assertSame(0, $result);

        // Verify the key was reclaimed (hash updated)
        $entry = DB::table('idempotency_keys')
            ->where('key', 'uuid-expired-reuse')
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame('hash-2', $entry->request_hash);
        $this->assertNull($entry->response_status);
    }

    // ── 5.2: request hash match vs mismatch ────────────────────────

    public function test_same_key_same_hash_returns_two(): void
    {
        $request = $this->makeRequest('uuid-hash-match');

        $this->service->acquire($request, 'POST /test', 'same-hash');
        $this->service->store($request, 'POST /test', 201, ['id' => 1]);

        $result = $this->service->acquire($request, 'POST /test', 'same-hash');

        $this->assertSame(2, $result);
    }

    public function test_same_key_different_hash_returns_one(): void
    {
        $request = $this->makeRequest('uuid-hash-mismatch');

        $this->service->acquire($request, 'POST /test', 'original-hash');
        $this->service->store($request, 'POST /test', 201, ['id' => 1]);

        // Different hash with same key — should be 409 conflict
        $result = $this->service->acquire($request, 'POST /test', 'different-hash');

        $this->assertSame(1, $result);
    }

    // ── check() method ─────────────────────────────────────────────

    public function test_check_returns_null_when_no_key(): void
    {
        $request = Request::create('/test', 'POST', [], [], [], [], '{}');

        $result = $this->service->check($request, 'POST /test');

        $this->assertNull($result);
    }

    public function test_check_returns_null_when_not_completed(): void
    {
        $request = $this->makeRequest('uuid-check-not-completed');

        $this->service->acquire($request, 'POST /test', 'hash-1');

        $result = $this->service->check($request, 'POST /test');

        $this->assertNull($result);
    }

    public function test_check_returns_cached_response_when_completed(): void
    {
        $request = $this->makeRequest('uuid-check-returns');

        $this->service->acquire($request, 'POST /test', 'hash-1');
        $this->service->store($request, 'POST /test', 201, ['id' => 42]);

        $response = $this->service->check($request, 'POST /test');

        $this->assertNotNull($response);
        $this->assertSame(201, $response->getStatusCode());

        $body = json_decode($response->getContent(), true);
        $this->assertSame(42, $body['id']);
    }

    public function test_check_returns_null_when_expired(): void
    {
        $request = $this->makeRequest('uuid-check-expired');

        $this->service->acquire($request, 'POST /test', 'hash-1');
        $this->service->store($request, 'POST /test', 201, ['id' => 1]);

        DB::table('idempotency_keys')
            ->where('key', 'uuid-check-expired')
            ->update(['expires_at' => Carbon::now()->subHour()]);

        $result = $this->service->check($request, 'POST /test');

        $this->assertNull($result);
    }

    // ── release() method edge cases ────────────────────────────────

    public function test_release_only_deletes_in_flight_entries(): void
    {
        $request = $this->makeRequest('uuid-release-only-inflight');

        $this->service->acquire($request, 'POST /test', 'hash-1');
        $this->service->store($request, 'POST /test', 200, ['ok' => true]);

        // Release should NOT delete because it's completed, not in-flight
        $this->service->release($request, 'POST /test');

        // Check still exists
        $check = $this->service->check($request, 'POST /test');
        $this->assertNotNull($check);
        $this->assertSame(200, $check->getStatusCode());
    }
}
