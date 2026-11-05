<?php

namespace Tests\Feature;

use App\Models\Lending;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class MemberServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.book_service.url' => 'http://book-service.test']);
    }

    private function createMember(
        int $userId = 1,
        string $memberNumber = 'MBR-001',
        string $name = 'Budi Santoso',
        string $email = 'budi@example.com'
    ): Member {
        return Member::create([
            'user_id' => $userId,
            'member_number' => $memberNumber,
            'name' => $name,
            'email' => $email,
            'status' => Member::ACTIVE,
        ]);
    }

    public function test_it_creates_a_member(): void
    {
        $response = $this->postJson('/api/members', [
            'user_id' => 1,
            'member_number' => 'MBR-001',
            'name' => 'Budi Santoso',
            'email' => 'budi@example.com',
        ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'Budi Santoso');

        $this->assertDatabaseHas('members', [
            'member_number' => 'MBR-001',
        ]);
    }

    public function test_it_updates_member_name_and_email(): void
    {
        $member = $this->createMember();

        $this->patchJson("/api/members/{$member->id}", [
            'name' => 'Budi Wijaya',
            'email' => 'budi.wijaya@example.com',
        ])->assertOk()
            ->assertJsonPath('name', 'Budi Wijaya')
            ->assertJsonPath('email', 'budi.wijaya@example.com');

        $this->assertDatabaseHas('members', [
            'id' => $member->id,
            'name' => 'Budi Wijaya',
            'email' => 'budi.wijaya@example.com',
        ]);
    }

    public function test_it_rejects_an_email_used_by_another_member(): void
    {
        $member = $this->createMember();
        $otherMember = Member::create([
            'user_id' => 2,
            'member_number' => 'MBR-002',
            'name' => 'Siti Aminah',
            'email' => 'siti@example.com',
            'status' => Member::ACTIVE,
        ]);

        $this->patchJson("/api/members/{$member->id}", [
            'email' => $otherMember->email,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_it_rejects_an_inactive_member(): void
    {
        $member = $this->createMember();

        $member->update([
            'status' => Member::INACTIVE,
        ]);

        Http::fake();

        $response = $this->postJson('/api/lendings', [
            'member_id' => $member->id,
            'book_copy_id' => 1,
            'due_at' => now()->addDays(7)->toDateString(),
        ]);

        $response->assertUnprocessable();

        Http::assertNothingSent();

        $this->assertDatabaseCount('lendings', 0);
    }

    public function test_it_creates_a_lending_when_reservation_succeeds(): void
    {
        $member = $this->createMember();

        Http::fake(function (ClientRequest $request) {
            return Http::response([
                'message' => 'Eksemplar berhasil direservasi.',
                'status' => 'LOANED',
            ], 200);
        });

        $response = $this->postJson('/api/lendings', [
            'member_id' => $member->id,
            'book_copy_id' => 1,
            'due_at' => now()->addDays(7)->toDateString(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', Lending::ACTIVE);

        $this->assertDatabaseHas('lendings', [
            'member_id' => $member->id,
            'book_copy_id' => 1,
            'status' => Lending::ACTIVE,
        ]);

        Http::assertSent(function (ClientRequest $request) {
            return str_contains(
                $request->url(),
                '/api/book-copies/1/reserve'
            ) && ! empty($request['reservation_reference']);
        });
    }

    public function test_it_rejects_a_lending_when_copy_is_unavailable(): void
    {
        $member = $this->createMember();

        Http::fake(function (ClientRequest $request) {
            return Http::response([
                'message' => 'Eksemplar tidak tersedia.',
            ], 409);
        });

        $response = $this->postJson('/api/lendings', [
            'member_id' => $member->id,
            'book_copy_id' => 1,
            'due_at' => now()->addDays(7)->toDateString(),
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('status', Lending::REJECTED);

        $this->assertDatabaseHas('lendings', [
            'member_id' => $member->id,
            'book_copy_id' => 1,
            'status' => Lending::REJECTED,
        ]);
    }

    public function test_it_keeps_lending_pending_if_reservation_times_out(): void
    {
        $member = $this->createMember();

        Http::fake(function (ClientRequest $request) {
            throw new ConnectionException('Simulated timeout');
        });

        $response = $this->postJson('/api/lendings', [
            'member_id' => $member->id,
            'book_copy_id' => 1,
            'due_at' => now()->addDays(7)->toDateString(),
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('status', Lending::PENDING);

        $this->assertDatabaseHas('lendings', [
            'member_id' => $member->id,
            'book_copy_id' => 1,
            'status' => Lending::PENDING,
        ]);
    }

    public function test_it_rejects_a_past_due_date(): void
    {
        $member = $this->createMember();

        $this->postJson('/api/lendings', [
            'member_id' => $member->id,
            'book_copy_id' => 1,
            'due_at' => now()->subDay()->toDateString(),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['due_at']);

        $this->assertDatabaseCount('lendings', 0);
    }

    public function test_it_marks_the_copy_lost_when_a_lending_is_reported_lost(): void
    {
        $member = $this->createMember();
        $reservationReference = (string) Str::uuid();
        $lending = Lending::create([
            'member_id' => $member->id,
            'book_copy_id' => 12,
            'reservation_reference' => $reservationReference,
            'borrowed_at' => now()->toDateString(),
            'due_at' => now()->addDays(7)->toDateString(),
            'status' => Lending::ACTIVE,
        ]);

        Http::fake([
            '*' => Http::response([
                'message' => 'Eksemplar ditandai hilang.',
                'status' => 'LOST',
            ]),
        ]);

        $this->postJson("/api/lendings/{$lending->id}/report-lost")
            ->assertOk()
            ->assertJsonPath('data.status', Lending::LOST);

        $this->assertDatabaseHas('lendings', [
            'id' => $lending->id,
            'status' => Lending::LOST,
        ]);

        Http::assertSent(function (ClientRequest $request) use ($reservationReference) {
            return str_contains(
                $request->url(),
                '/api/book-copies/12/mark-lost'
            ) && $request['reservation_reference'] === $reservationReference;
        });
    }

    public function test_it_lists_members_alphabetically_with_pagination(): void
    {
        Member::create([
            'user_id' => 2,
            'member_number' => 'MBR-002',
            'name' => 'Zed Member',
            'email' => 'zed@example.com',
            'status' => Member::ACTIVE,
        ]);
        Member::create([
            'user_id' => 3,
            'member_number' => 'MBR-003',
            'name' => 'Ava Member',
            'email' => 'ava@example.com',
            'status' => Member::INACTIVE,
        ]);

        $this->getJson('/api/members')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Ava Member')
            ->assertJsonPath('data.1.name', 'Zed Member')
            ->assertJsonPath('total', 2)
            ->assertJsonPath('per_page', 10);
    }

    public function test_it_shows_a_member_with_their_lendings(): void
    {
        $member = $this->createMember();
        $lending = $this->createLending($member);

        $this->getJson("/api/members/{$member->id}")
            ->assertOk()
            ->assertJsonPath('id', $member->id)
            ->assertJsonPath('lendings.0.id', $lending->id)
            ->assertJsonPath('lendings.0.status', Lending::ACTIVE);
    }

    public function test_it_rejects_duplicate_member_user_id_member_number_and_email(): void
    {
        $this->createMember();

        $this->postJson('/api/members', [
            'user_id' => 1,
            'member_number' => 'MBR-002',
            'name' => 'Duplicate User',
            'email' => 'another@example.com',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['user_id']);

        $this->postJson('/api/members', [
            'user_id' => 2,
            'member_number' => 'MBR-001',
            'name' => 'Duplicate Number',
            'email' => 'another@example.com',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['member_number']);

        $this->postJson('/api/members', [
            'user_id' => 2,
            'member_number' => 'MBR-002',
            'name' => 'Duplicate Email',
            'email' => 'budi@example.com',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_it_rejects_invalid_member_creation_data(): void
    {
        $this->postJson('/api/members', [
            'user_id' => 'not-an-integer',
            'member_number' => str_repeat('M', 51),
            'name' => '',
            'email' => 'not-an-email',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'user_id',
                'member_number',
                'name',
                'email',
            ]);
    }

    public function test_it_allows_a_member_to_keep_their_own_email_and_rejects_invalid_updates(): void
    {
        $member = $this->createMember();

        $this->patchJson("/api/members/{$member->id}", [
            'email' => $member->email,
        ])->assertOk()
            ->assertJsonPath('email', $member->email);

        $this->patchJson("/api/members/{$member->id}", [
            'name' => '',
            'email' => 'invalid-email',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email']);

        $this->assertDatabaseHas('members', [
            'id' => $member->id,
            'name' => 'Budi Santoso',
            'email' => 'budi@example.com',
        ]);
    }

    public function test_it_lists_lendings_newest_first_with_member_data(): void
    {
        $member = $this->createMember();
        $older = $this->createLending($member, Lending::RETURNED);
        $newer = $this->createLending($member, Lending::ACTIVE);

        $this->getJson('/api/lendings')
            ->assertOk()
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.0.member.name', $member->name)
            ->assertJsonPath('data.1.id', $older->id)
            ->assertJsonPath('total', 2);
    }

    public function test_it_shows_a_lending_with_member_data(): void
    {
        $member = $this->createMember();
        $lending = $this->createLending($member);

        $this->getJson("/api/lendings/{$lending->id}")
            ->assertOk()
            ->assertJsonPath('id', $lending->id)
            ->assertJsonPath('member.id', $member->id)
            ->assertJsonPath('member.email', $member->email);
    }

    public function test_it_rejects_lending_with_invalid_member_copy_or_due_date(): void
    {
        Http::fake();

        $this->postJson('/api/lendings', [
            'member_id' => 999,
            'book_copy_id' => 0,
            'due_at' => 'not-a-date',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['member_id', 'book_copy_id', 'due_at']);

        Http::assertNothingSent();
        $this->assertDatabaseCount('lendings', 0);
    }

    public function test_it_rejects_a_fourth_active_lending_without_calling_book_service(): void
    {
        $member = $this->createMember();
        $this->createLending($member);
        $this->createLending($member);
        $this->createLending($member);
        Http::fake();

        $this->postJson('/api/lendings', [
            'member_id' => $member->id,
            'book_copy_id' => 4,
            'due_at' => now()->addDays(7)->toDateString(),
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Batas maksimal 3 pinjaman aktif tercapai.');

        Http::assertNothingSent();
        $this->assertDatabaseCount('lendings', 3);
    }

    public function test_it_keeps_a_lending_pending_when_book_service_returns_an_unexpected_error(): void
    {
        $member = $this->createMember();
        Http::fake([
            '*' => Http::response(['message' => 'Service unavailable'], 503),
        ]);

        $this->postJson('/api/lendings', [
            'member_id' => $member->id,
            'book_copy_id' => 8,
            'due_at' => now()->addDays(7)->toDateString(),
        ])->assertAccepted()
            ->assertJsonPath('message', 'Reservasi belum dapat dikonfirmasi.')
            ->assertJsonPath('status', Lending::PENDING);

        $this->assertDatabaseHas('lendings', [
            'member_id' => $member->id,
            'book_copy_id' => 8,
            'status' => Lending::PENDING,
        ]);
    }

    public function test_it_retries_a_pending_reservation_using_the_same_reference(): void
    {
        $member = $this->createMember();
        $lending = $this->createLending($member, Lending::PENDING);
        $reference = $lending->reservation_reference;
        Http::fake([
            '*' => Http::response([
                'message' => 'Eksemplar berhasil direservasi.',
            ], 200),
        ]);

        $this->postJson("/api/lendings/{$lending->id}/retry")
            ->assertCreated()
            ->assertJsonPath('data.status', Lending::ACTIVE);

        $this->assertDatabaseHas('lendings', [
            'id' => $lending->id,
            'reservation_reference' => $reference,
            'status' => Lending::ACTIVE,
        ]);
        Http::assertSent(fn (ClientRequest $request) => $request->url() ===
            "http://book-service.test/api/book-copies/{$lending->book_copy_id}/reserve"
            && $request['reservation_reference'] === $reference);
    }

    public function test_it_marks_a_pending_reservation_rejected_when_retry_conflicts(): void
    {
        $lending = $this->createLending($this->createMember(), Lending::PENDING);
        Http::fake([
            '*' => Http::response(['message' => 'Unavailable'], 409),
        ]);

        $this->postJson("/api/lendings/{$lending->id}/retry")
            ->assertConflict()
            ->assertJsonPath('status', Lending::REJECTED);

        $this->assertDatabaseHas('lendings', [
            'id' => $lending->id,
            'status' => Lending::REJECTED,
        ]);
    }

    public function test_it_rejects_retry_for_a_lending_that_is_not_pending_recovery(): void
    {
        $lending = $this->createLending($this->createMember(), Lending::ACTIVE);
        Http::fake();

        $this->postJson("/api/lendings/{$lending->id}/retry")
            ->assertConflict()
            ->assertJsonPath('message', 'Transaksi tidak sedang menunggu pemulihan.')
            ->assertJsonPath('status', Lending::ACTIVE);

        Http::assertNothingSent();
    }

    public function test_it_returns_a_lending_and_reports_overdue_days(): void
    {
        $member = $this->createMember();
        $lending = $this->createLending($member, Lending::ACTIVE, now()->subDays(3)->toDateString());
        Http::fake([
            '*' => Http::response(['message' => 'Eksemplar berhasil dilepas.'], 200),
        ]);

        $this->postJson("/api/lendings/{$lending->id}/return")
            ->assertOk()
            ->assertJsonPath('message', 'Pengembalian berhasil.')
            ->assertJsonPath('data.status', Lending::RETURNED)
            ->assertJsonPath('overdue_days', 3);

        $this->assertDatabaseHas('lendings', [
            'id' => $lending->id,
            'status' => Lending::RETURNED,
        ]);
        $this->assertNotNull($lending->fresh()->returned_at);
        Http::assertSent(fn (ClientRequest $request) => $request->url() ===
            "http://book-service.test/api/book-copies/{$lending->book_copy_id}/release"
            && $request['reservation_reference'] === $lending->reservation_reference);
    }

    public function test_it_leaves_a_return_pending_when_book_service_cannot_confirm(): void
    {
        $lending = $this->createLending($this->createMember(), Lending::ACTIVE);
        Http::fake(function () {
            throw new ConnectionException('Simulated timeout');
        });

        $this->postJson("/api/lendings/{$lending->id}/return")
            ->assertAccepted()
            ->assertJsonPath('message', 'Pengembalian menunggu konfirmasi Book Service.')
            ->assertJsonPath('status', Lending::RETURN_PENDING);

        $this->assertDatabaseHas('lendings', [
            'id' => $lending->id,
            'status' => Lending::RETURN_PENDING,
            'returned_at' => null,
        ]);
    }

    public function test_it_retries_a_pending_return_and_completes_it(): void
    {
        $lending = $this->createLending($this->createMember(), Lending::RETURN_PENDING);
        Http::fake([
            '*' => Http::response([], 200),
        ]);

        $this->postJson("/api/lendings/{$lending->id}/retry")
            ->assertOk()
            ->assertJsonPath('message', 'Pengembalian berhasil.')
            ->assertJsonPath('data.status', Lending::RETURNED);

        $this->assertDatabaseHas('lendings', [
            'id' => $lending->id,
            'status' => Lending::RETURNED,
        ]);
    }

    public function test_it_does_not_repeat_a_completed_return_or_return_an_invalid_status(): void
    {
        $returned = $this->createLending($this->createMember(), Lending::RETURNED);
        $pending = $this->createLending(
            $this->createMember(2, 'MBR-002', 'Siti Aminah', 'siti@example.com'),
            Lending::PENDING
        );
        Http::fake();

        $this->postJson("/api/lendings/{$returned->id}/return")
            ->assertOk()
            ->assertJsonPath('message', 'Buku sudah dikembalikan.');

        $this->postJson("/api/lendings/{$pending->id}/return")
            ->assertConflict()
            ->assertJsonPath('message', 'Transaksi tidak dapat dikembalikan pada status ini.');

        Http::assertNothingSent();
    }

    public function test_it_keeps_return_pending_when_book_service_returns_an_error_response(): void
    {
        $lending = $this->createLending($this->createMember(), Lending::ACTIVE);
        Http::fake([
            '*' => Http::response(['message' => 'Temporary error'], 500),
        ]);

        $this->postJson("/api/lendings/{$lending->id}/return")
            ->assertAccepted()
            ->assertJsonPath('message', 'Pengembalian belum dapat dikonfirmasi.')
            ->assertJsonPath('status', Lending::RETURN_PENDING);

        $this->assertDatabaseHas('lendings', [
            'id' => $lending->id,
            'status' => Lending::RETURN_PENDING,
        ]);
    }

    public function test_it_does_not_repeat_a_completed_lost_report_or_report_an_invalid_status(): void
    {
        $lost = $this->createLending($this->createMember(), Lending::LOST);
        $pending = $this->createLending(
            $this->createMember(2, 'MBR-002', 'Siti Aminah', 'siti@example.com'),
            Lending::PENDING
        );
        Http::fake();

        $this->postJson("/api/lendings/{$lost->id}/report-lost")
            ->assertOk()
            ->assertJsonPath('message', 'Buku sudah tercatat hilang.');

        $this->postJson("/api/lendings/{$pending->id}/report-lost")
            ->assertConflict()
            ->assertJsonPath('message', 'Transaksi tidak dapat ditandai hilang pada status ini.');

        Http::assertNothingSent();
    }

    public function test_it_keeps_a_lost_report_pending_when_book_service_cannot_confirm(): void
    {
        $lending = $this->createLending($this->createMember(), Lending::ACTIVE);
        Http::fake(function () {
            throw new ConnectionException('Simulated timeout');
        });

        $this->postJson("/api/lendings/{$lending->id}/report-lost")
            ->assertAccepted()
            ->assertJsonPath('message', 'Pelaporan kehilangan menunggu konfirmasi.')
            ->assertJsonPath('status', Lending::LOST_PENDING);

        $this->assertDatabaseHas('lendings', [
            'id' => $lending->id,
            'status' => Lending::LOST_PENDING,
        ]);
    }

    public function test_it_retries_a_pending_lost_report_and_completes_it(): void
    {
        $lending = $this->createLending($this->createMember(), Lending::LOST_PENDING);
        Http::fake([
            '*' => Http::response(['message' => 'Marked lost'], 200),
        ]);

        $this->postJson("/api/lendings/{$lending->id}/retry")
            ->assertOk()
            ->assertJsonPath('message', 'Buku berhasil ditandai hilang.')
            ->assertJsonPath('data.status', Lending::LOST);

        $this->assertDatabaseHas('lendings', [
            'id' => $lending->id,
            'status' => Lending::LOST,
        ]);
    }

    public function test_it_keeps_a_lost_report_pending_when_book_service_returns_an_error_response(): void
    {
        $lending = $this->createLending($this->createMember(), Lending::ACTIVE);
        Http::fake([
            '*' => Http::response(['message' => 'Temporary error'], 503),
        ]);

        $this->postJson("/api/lendings/{$lending->id}/report-lost")
            ->assertAccepted()
            ->assertJsonPath('message', 'Pelaporan kehilangan belum dapat dikonfirmasi.')
            ->assertJsonPath('status', Lending::LOST_PENDING);

        $this->assertDatabaseHas('lendings', [
            'id' => $lending->id,
            'status' => Lending::LOST_PENDING,
        ]);
    }

    private function createLending(
        Member $member,
        string $status = Lending::ACTIVE,
        ?string $dueAt = null
    ): Lending {
        return Lending::create([
            'member_id' => $member->id,
            'book_copy_id' => Lending::query()->count() + 1,
            'reservation_reference' => (string) Str::uuid(),
            'borrowed_at' => now()->toDateString(),
            'due_at' => $dueAt ?? now()->addDays(7)->toDateString(),
            'status' => $status,
        ]);
    }
}
