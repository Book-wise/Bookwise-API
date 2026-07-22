<?php

namespace Tests\Integration;

use App\Enums\UserRole;
use App\Models\BlockedSlot;
use App\Models\Booking;
use App\Models\BookingStatus;
use App\Models\Client;
use App\Models\Location;
use App\Models\Provider;
use App\Models\Service;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * @group mysql-concurrency
 */
class MySqlBookingConcurrencyTest extends TestCase
{
    private const ITERATIONS = 20;

    private BookingStatus $confirmedStatus;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireDedicatedMySqlDatabase();

        Artisan::call('migrate:fresh', ['--force' => true]);

        $this->confirmedStatus = BookingStatus::create([
            'name' => 'Confirmed',
            'is_cancellation' => false,
        ]);

        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->token = $admin->createToken('mysql-concurrency', ['*'])->plainTextToken;
    }

    public function test_same_provider_and_location_interval_allows_exactly_one_booking(): void
    {
        foreach (range(1, self::ITERATIONS) as $iteration) {
            $fixture = $this->fixture($iteration);
            $interval = $this->interval($iteration, 10, 60);

            $results = $this->runConcurrently([
                $this->createRequest($fixture, $interval),
                $this->createRequest($fixture, $interval, $fixture['other_client_id']),
            ]);

            $this->assertIncompatibleBookingResults($results, $fixture['location_id'], $interval);
        }
    }

    public function test_partially_overlapping_intervals_allow_exactly_one_booking(): void
    {
        foreach (range(1, self::ITERATIONS) as $iteration) {
            $fixture = $this->fixture($iteration);
            $first = $this->interval($iteration, 10, 60);
            $second = $this->interval($iteration, 10, 60, 30);

            $results = $this->runConcurrently([
                $this->createRequest($fixture, $first),
                $this->createRequest($fixture, $second, $fixture['other_client_id']),
            ]);

            $this->assertIncompatibleBookingResults($results, $fixture['location_id'], $first);
        }
    }

    public function test_adjacent_intervals_can_coexist(): void
    {
        foreach (range(1, self::ITERATIONS) as $iteration) {
            $fixture = $this->fixture($iteration);
            $first = $this->interval($iteration, 10, 60);
            $second = $this->interval($iteration, 11, 60);

            $results = $this->runConcurrently([
                $this->createRequest($fixture, $first),
                $this->createRequest($fixture, $second, $fixture['other_client_id']),
            ]);

            $this->assertSuccessfulStatuses($results, 2);
            $this->assertSame(2, $this->activeBookingsOverlapping($fixture['location_id'], $first['start_time'], $second['end_time']));
            $this->assertNoOpenTransactions();
        }
    }

    public function test_creation_and_reschedule_to_the_same_interval_allow_exactly_one_operation(): void
    {
        foreach (range(1, self::ITERATIONS) as $iteration) {
            $fixture = $this->fixture($iteration);
            $target = $this->interval($iteration, 13, 60);
            $source = $this->interval($iteration, 8, 60);
            $booking = $this->booking($fixture, $source, $fixture['other_client_id']);

            $results = $this->runConcurrently([
                $this->createRequest($fixture, $target),
                $this->updateRequest($booking->id, $target),
            ]);

            $this->assertOneSuccessfulOperationAndConflict($results);
            $this->assertSame(1, $this->activeBookingsOverlapping($fixture['location_id'], $target['start_time'], $target['end_time']));
            $this->assertNoOpenTransactions();
        }
    }

    public function test_two_reschedules_to_the_same_interval_allow_exactly_one_operation(): void
    {
        foreach (range(1, self::ITERATIONS) as $iteration) {
            $fixture = $this->fixture($iteration);
            $target = $this->interval($iteration, 13, 60);
            $first = $this->booking($fixture, $this->interval($iteration, 8, 60));
            $second = $this->booking($fixture, $this->interval($iteration, 10, 60), $fixture['other_client_id']);

            $results = $this->runConcurrently([
                $this->updateRequest($first->id, $target),
                $this->updateRequest($second->id, $target),
            ]);

            $this->assertOneSuccessAndOneConflict($results, 200);
            $this->assertSame(1, $this->activeBookingsOverlapping($fixture['location_id'], $target['start_time'], $target['end_time']));
            $this->assertNoOpenTransactions();
        }
    }

    public function test_booking_and_agenda_block_allow_exactly_one_occupancy(): void
    {
        foreach (range(1, self::ITERATIONS) as $iteration) {
            $fixture = $this->fixture($iteration);
            $interval = $this->interval($iteration, 10, 60);

            $results = $this->runConcurrently([
                $this->createRequest($fixture, $interval),
                $this->blockRequest($fixture, $interval),
            ]);

            $this->assertOneSuccessAndOneConflict($results);

            $bookings = $this->activeBookingsOverlapping($fixture['location_id'], $interval['start_time'], $interval['end_time']);
            $blocks = BlockedSlot::where('location_id', $fixture['location_id'])
                ->where('start_time', '<', $interval['end_time'])
                ->where('end_time', '>', $interval['start_time'])
                ->count();

            $this->assertSame(1, $bookings + $blocks);
            $this->assertNoOpenTransactions();
        }
    }

    public function test_different_locations_do_not_conflict(): void
    {
        foreach (range(1, self::ITERATIONS) as $iteration) {
            $first = $this->fixture($iteration);
            $second = $this->fixture($iteration + 1000);
            $interval = $this->interval($iteration, 10, 60);

            $results = $this->runConcurrently([
                $this->createRequest($first, $interval),
                $this->createRequest($second, $interval),
            ]);

            $this->assertSuccessfulStatuses($results, 2);
            $this->assertSame(1, $this->activeBookingsOverlapping($first['location_id'], $interval['start_time'], $interval['end_time']));
            $this->assertSame(1, $this->activeBookingsOverlapping($second['location_id'], $interval['start_time'], $interval['end_time']));
            $this->assertNoOpenTransactions();
        }
    }

    /** @return array{location_id: int, provider_id: int, client_id: int, other_client_id: int, service_id: int, status_id: int} */
    private function fixture(int $suffix): array
    {
        $location = Location::create(['name' => "Concurrency location {$suffix}", 'address' => "Address {$suffix}"]);
        $provider = Provider::create([
            'first_name' => 'Concurrency',
            'last_name' => "Provider {$suffix}",
            'email' => "provider-{$suffix}@example.test",
            'location_id' => $location->id,
            'active' => true,
        ]);
        $service = Service::create([
            'name' => "Concurrency service {$suffix}",
            'price' => 10000,
            'duration_minutes' => 60,
            'active' => true,
        ]);
        $client = Client::create([
            'first_name' => 'Concurrency',
            'last_name' => "Client {$suffix}",
            'email' => "client-{$suffix}@example.test",
        ]);
        $otherClient = Client::create([
            'first_name' => 'Concurrency',
            'last_name' => "Other client {$suffix}",
            'email' => "other-client-{$suffix}@example.test",
        ]);

        return [
            'location_id' => $location->id,
            'provider_id' => $provider->id,
            'client_id' => $client->id,
            'other_client_id' => $otherClient->id,
            'service_id' => $service->id,
            'status_id' => $this->confirmedStatus->id,
        ];
    }

    /** @return array{start_time: string, end_time: string} */
    private function interval(int $iteration, int $hour, int $durationMinutes, int $offsetMinutes = 0): array
    {
        $start = now()->addDays(30 + $iteration)->startOfDay()->addHours($hour)->addMinutes($offsetMinutes);

        return [
            'start_time' => $start->toIso8601String(),
            'end_time' => $start->copy()->addMinutes($durationMinutes)->toIso8601String(),
        ];
    }

    /** @param array{location_id: int, provider_id: int, client_id: int, other_client_id: int, service_id: int, status_id: int} $fixture */
    /** @param array{start_time: string, end_time: string} $interval */
    private function booking(array $fixture, array $interval, ?int $clientId = null): Booking
    {
        return Booking::create([
            'location_id' => $fixture['location_id'],
            'provider_id' => $fixture['provider_id'],
            'service_id' => $fixture['service_id'],
            'client_id' => $clientId ?? $fixture['client_id'],
            'status_id' => $fixture['status_id'],
            'start_time' => $interval['start_time'],
            'end_time' => $interval['end_time'],
            'price' => 10000,
        ]);
    }

    /** @param array{location_id: int, provider_id: int, client_id: int, other_client_id: int, service_id: int, status_id: int} $fixture */
    /** @param array{start_time: string, end_time: string} $interval */
    /** @return array{method: string, uri: string, payload: array<string, int|string>} */
    private function createRequest(array $fixture, array $interval, ?int $clientId = null): array
    {
        return [
            'method' => 'POST',
            'uri' => '/api/v1/bookings',
            'payload' => [
                ...$interval,
                'location_id' => $fixture['location_id'],
                'provider_id' => $fixture['provider_id'],
                'service_id' => $fixture['service_id'],
                'client_id' => $clientId ?? $fixture['client_id'],
                'status_id' => $fixture['status_id'],
            ],
        ];
    }

    /** @param array{start_time: string, end_time: string} $interval */
    /** @return array{method: string, uri: string, payload: array<string, string>} */
    private function updateRequest(int $bookingId, array $interval): array
    {
        return [
            'method' => 'PATCH',
            'uri' => "/api/v1/bookings/{$bookingId}",
            'payload' => $interval,
        ];
    }

    /** @param array{location_id: int, provider_id: int, client_id: int, other_client_id: int, service_id: int, status_id: int} $fixture */
    /** @param array{start_time: string, end_time: string} $interval */
    /** @return array{method: string, uri: string, payload: array<string, int|string>} */
    private function blockRequest(array $fixture, array $interval): array
    {
        return [
            'method' => 'POST',
            'uri' => '/api/v1/blocked-slots',
            'payload' => [
                ...$interval,
                'location_id' => $fixture['location_id'],
                'provider_id' => $fixture['provider_id'],
                'reason' => 'Concurrency verification',
            ],
        ];
    }

    /**
     * @param  array<int, array{method: string, uri: string, payload: array<string, int|string>}>  $requests
     * @return array<int, array{status: int, body: array<string, mixed>}>
     */
    private function runConcurrently(array $requests): array
    {
        $barrier = sys_get_temp_dir().'/bookwise-concurrency-'.bin2hex(random_bytes(8));
        mkdir($barrier, 0700, true);

        $children = [];
        foreach ($requests as $index => $request) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('Unable to fork concurrent request worker.');
            }

            if ($pid === 0) {
                $this->runWorker($barrier, $index, $request);
            }

            $children[] = $pid;
        }

        try {
            foreach (array_keys($requests) as $index) {
                $this->waitForFile("{$barrier}/ready-{$index}");
            }

            touch("{$barrier}/release");

            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'Concurrent worker exited unexpectedly.');
            }

            $results = [];
            foreach (array_keys($requests) as $index) {
                $result = json_decode((string) file_get_contents("{$barrier}/result-{$index}.json"), true, flags: JSON_THROW_ON_ERROR);
                $this->assertArrayNotHasKey('exception', $result, $result['exception'] ?? 'Concurrent worker failed.');
                $results[] = $result;
            }

            return $results;
        } finally {
            foreach (glob("{$barrier}/*") ?: [] as $file) {
                unlink($file);
            }
            rmdir($barrier);
        }
    }

    /** @param array{method: string, uri: string, payload: array<string, int|string>} $request */
    private function runWorker(string $barrier, int $index, array $request): never
    {
        DB::disconnect();
        DB::purge();
        DB::reconnect();
        DB::statement('SET SESSION innodb_lock_wait_timeout = 5');

        touch("{$barrier}/ready-{$index}");
        $this->waitForFile("{$barrier}/release");

        try {
            $httpRequest = Request::create(
                $request['uri'],
                $request['method'],
                $request['payload'],
                [],
                [],
                [
                    'HTTP_ACCEPT' => 'application/json',
                    'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
                ],
            );
            $response = app()->handle($httpRequest);

            file_put_contents("{$barrier}/result-{$index}.json", json_encode([
                'status' => $response->getStatusCode(),
                'body' => json_decode($response->getContent(), true) ?? [],
            ], JSON_THROW_ON_ERROR));
        } catch (\Throwable $exception) {
            file_put_contents("{$barrier}/result-{$index}.json", json_encode([
                'exception' => $exception::class.': '.$exception->getMessage(),
            ], JSON_THROW_ON_ERROR));

            exit(1);
        }

        exit(0);
    }

    private function waitForFile(string $path): void
    {
        $deadline = microtime(true) + 10;

        while (! file_exists($path)) {
            if (microtime(true) >= $deadline) {
                $this->fail("Concurrency barrier timed out waiting for {$path}.");
            }

            usleep(1000);
        }
    }

    /** @param array<int, array{status: int, body: array<string, mixed>}> $results */
    private function assertIncompatibleBookingResults(array $results, int $locationId, array $interval): void
    {
        $this->assertOneSuccessAndOneConflict($results);
        $this->assertSame(1, $this->activeBookingsOverlapping($locationId, $interval['start_time'], $interval['end_time']));
        $this->assertNoOpenTransactions();
    }

    /** @param array<int, array{status: int, body: array<string, mixed>}> $results */
    private function assertOneSuccessAndOneConflict(array $results, int $successStatus = 201): void
    {
        $statuses = array_column($results, 'status');
        sort($statuses);

        $this->assertSame([$successStatus, 409], $statuses, json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertNotSame(500, $results[0]['status']);
        $this->assertNotSame(500, $results[1]['status']);
    }

    /** @param array<int, array{status: int, body: array<string, mixed>}> $results */
    private function assertOneSuccessfulOperationAndConflict(array $results): void
    {
        $statuses = array_column($results, 'status');
        sort($statuses);

        $this->assertContains($statuses[0], [200, 201], json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(409, $statuses[1], json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertNotSame(500, $results[0]['status']);
        $this->assertNotSame(500, $results[1]['status']);
    }

    /** @param array<int, array{status: int, body: array<string, mixed>}> $results */
    private function assertSuccessfulStatuses(array $results, int $expected): void
    {
        $statuses = array_column($results, 'status');
        sort($statuses);

        $this->assertSame(array_fill(0, $expected, 201), $statuses, json_encode($results, JSON_THROW_ON_ERROR));
    }

    private function activeBookingsOverlapping(int $locationId, string $startTime, string $endTime): int
    {
        return Booking::where('location_id', $locationId)
            ->active()
            ->where('start_time', '<', $endTime)
            ->where('end_time', '>', $startTime)
            ->count();
    }

    private function assertNoOpenTransactions(): void
    {
        $transactions = DB::selectOne('SELECT COUNT(*) AS count FROM information_schema.innodb_trx');
        $this->assertSame(0, (int) $transactions->count);
    }

    private function requireDedicatedMySqlDatabase(): void
    {
        if (config('database.default') !== 'mysql' || env('BOOKWISE_MYSQL_CONCURRENCY_TEST') !== '1') {
            $this->markTestSkipped('Set BOOKWISE_MYSQL_CONCURRENCY_TEST=1 and use a dedicated MySQL database to run this suite.');
        }

        if (! extension_loaded('pcntl')) {
            $this->markTestSkipped('The MySQL concurrency suite requires the pcntl PHP extension.');
        }

        $database = (string) config('database.connections.mysql.database');
        if (! str_starts_with($database, 'bookwise_api_concurrency_')) {
            $this->fail('MySQL concurrency tests require a disposable database named bookwise_api_concurrency_*.');
        }
    }
}
