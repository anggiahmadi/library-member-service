<?php

namespace Tests\Feature;

use App\Models\Lending;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MemberServiceTest extends TestCase
{
    use RefreshDatabase;

    private function createMember(): Member
    {
        return Member::create([
            'member_number' => 'MBR-001',
            'name' => 'Budi Santoso',
            'email' => 'budi@example.com',
            'status' => Member::ACTIVE,
        ]);
    }

    public function test_it_creates_a_member(): void
    {
        $response = $this->postJson('/api/members', [
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
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('status', Lending::PENDING);

        $this->assertDatabaseHas('lendings', [
            'member_id' => $member->id,
            'book_copy_id' => 1,
            'status' => Lending::PENDING,
        ]);
    }
}
