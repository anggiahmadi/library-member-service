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

    private function createMember(): Member
    {
        return Member::create([
            'user_id' => 1,
            'member_number' => 'MBR-001',
            'name' => 'Budi Santoso',
            'email' => 'budi@example.com',
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
}
