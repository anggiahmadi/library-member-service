<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lending;
use App\Models\Member;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class LendingController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Lending::query()
                ->with('member')
                ->orderByDesc('id')
                ->paginate(10)
        );
    }

    public function show(Lending $lending): JsonResponse
    {
        return response()->json(
            $lending->load('member')
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'member_id' => [
                'required',
                'integer',
                'exists:members,id',
            ],
            'book_copy_id' => [
                'required',
                'integer',
                'min:1',
            ],
        ]);

        $member = Member::findOrFail($validated['member_id']);

        if ($member->status !== Member::ACTIVE) {
            return response()->json([
                'message' => 'Anggota tidak aktif.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Batas jumlah pinjaman adalah aturan bisnis.
        // Untuk latihan ini, hanya ACTIVE yang dihitung.
        $activeCount = Lending::query()
            ->where('member_id', $member->id)
            ->where('status', Lending::ACTIVE)
            ->count();

        if ($activeCount >= 3) {
            return response()->json([
                'message' => 'Batas maksimal 3 pinjaman aktif tercapai.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Buat transaksi PENDING sebelum memanggil service eksternal.
        $lending = Lending::create([
            'member_id' => $member->id,
            'book_copy_id' => $validated['book_copy_id'],
            'reservation_reference' => (string) Str::uuid(),
            'borrowed_at' => now()->toDateString(),
            'due_at' => now()->addDays(7)->toDateString(),
            'status' => Lending::PENDING,
        ]);

        return $this->completeReservation($lending);
    }

    private function completeReservation(Lending $lending): JsonResponse
    {
        $baseUrl = rtrim(
            config('services.book_service.url'),
            '/'
        );

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->connectTimeout(2)
                ->timeout(5)
                ->post(
                    $baseUrl
                    . '/api/book-copies/'
                    . $lending->book_copy_id
                    . '/reserve',
                    [
                        'reservation_reference' =>
                            $lending->reservation_reference,
                    ]
                );
        } catch (ConnectionException $exception) {
            // Jangan langsung REJECTED: Book Service mungkin
            // sudah berhasil reservasi tetapi respons tidak diterima.
            return response()->json([
                'message' => 'Hasil reservasi belum dapat dipastikan.',
                'lending_id' => $lending->id,
                'status' => Lending::PENDING,
                'next_action' => 'Retry the same reservation reference.',
            ], Response::HTTP_ACCEPTED);
        }

        if ($response->successful()) {
            $lending->update([
                'status' => Lending::ACTIVE,
            ]);

            return response()->json([
                'message' => 'Peminjaman berhasil.',
                'data' => $lending->fresh()->load('member'),
            ], Response::HTTP_CREATED);
        }

        if ($response->status() === Response::HTTP_CONFLICT) {
            $lending->update([
                'status' => Lending::REJECTED,
            ]);

            return response()->json([
                'message' => 'Eksemplar tidak tersedia.',
                'lending_id' => $lending->id,
                'status' => Lending::REJECTED,
            ], Response::HTTP_CONFLICT);
        }

        // Error 5xx atau respons tak terduga tidak selalu berarti
        // reservasi gagal. Pertahankan status PENDING.
        return response()->json([
            'message' => 'Reservasi belum dapat dikonfirmasi.',
            'lending_id' => $lending->id,
            'status' => Lending::PENDING,
        ], Response::HTTP_ACCEPTED);
    }

    public function retry(Lending $lending): JsonResponse
    {
        if ($lending->status === Lending::PENDING) {
            return $this->completeReservation($lending);
        }

        if ($lending->status === Lending::RETURN_PENDING) {
            return $this->completeReturn($lending);
        }

        if ($lending->status === Lending::LOST_PENDING) {
            return $this->completeLostReport($lending);
        }

        return response()->json([
            'message' => 'Transaksi tidak sedang menunggu pemulihan.',
            'status' => $lending->status,
        ], Response::HTTP_CONFLICT);
    }

    public function returnBook(Lending $lending): JsonResponse
    {
        if ($lending->status === Lending::RETURNED) {
            return response()->json([
                'message' => 'Buku sudah dikembalikan.',
                'data' => $lending,
            ]);
        }

        if ($lending->status === Lending::ACTIVE) {
            $lending->update([
                'status' => Lending::RETURN_PENDING,
            ]);
        } elseif ($lending->status !== Lending::RETURN_PENDING) {
            return response()->json([
                'message' => 'Transaksi tidak dapat dikembalikan pada status ini.',
            ], Response::HTTP_CONFLICT);
        }

        return $this->completeReturn($lending->fresh());
    }

    private function completeReturn(Lending $lending): JsonResponse
    {
        $baseUrl = rtrim(
            config('services.book_service.url'),
            '/'
        );

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->connectTimeout(2)
                ->timeout(5)
                ->post(
                    $baseUrl
                    . '/api/book-copies/'
                    . $lending->book_copy_id
                    . '/release',
                    [
                        'reservation_reference' =>
                            $lending->reservation_reference,
                    ]
                );
        } catch (ConnectionException $exception) {
            return response()->json([
                'message' => 'Pengembalian menunggu konfirmasi Book Service.',
                'lending_id' => $lending->id,
                'status' => Lending::RETURN_PENDING,
            ], Response::HTTP_ACCEPTED);
        }

        if ($response->successful()) {
            $returnedAt = now()->toDateString();

            $lending->update([
                'status' => Lending::RETURNED,
                'returned_at' => $returnedAt,
            ]);

            return response()->json([
                'message' => 'Pengembalian berhasil.',
                'data' => $lending->fresh(),
                'overdue_days' => $lending->fresh()->overdueDays(),
            ]);
        }

        return response()->json([
            'message' => 'Pengembalian belum dapat dikonfirmasi.',
            'lending_id' => $lending->id,
            'status' => Lending::RETURN_PENDING,
        ], Response::HTTP_ACCEPTED);
    }

    public function reportLost(Lending $lending): JsonResponse
    {
        if ($lending->status === Lending::LOST) {
            return response()->json([
                'message' => 'Buku sudah tercatat hilang.',
                'data' => $lending,
            ]);
        }

        if ($lending->status === Lending::ACTIVE) {
            $lending->update([
                'status' => Lending::LOST_PENDING,
            ]);
        } elseif ($lending->status !== Lending::LOST_PENDING) {
            return response()->json([
                'message' => 'Transaksi tidak dapat ditandai hilang pada status ini.',
            ], Response::HTTP_CONFLICT);
        }

        return $this->completeLostReport($lending->fresh());
    }

    private function completeLostReport(Lending $lending): JsonResponse
    {
        $baseUrl = rtrim(
            config('services.book_service.url'),
            '/'
        );

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->connectTimeout(2)
                ->timeout(5)
                ->post(
                    $baseUrl
                    . '/api/book-copies/'
                    . $lending->book_copy_id
                    . '/mark-lost',
                    [
                        'reservation_reference' =>
                            $lending->reservation_reference,
                    ]
                );
        } catch (ConnectionException $exception) {
            return response()->json([
                'message' => 'Pelaporan kehilangan menunggu konfirmasi.',
                'lending_id' => $lending->id,
                'status' => Lending::LOST_PENDING,
            ], Response::HTTP_ACCEPTED);
        }

        if ($response->successful()) {
            $lending->update([
                'status' => Lending::LOST,
            ]);

            return response()->json([
                'message' => 'Buku berhasil ditandai hilang.',
                'data' => $lending->fresh(),
            ]);
        }

        return response()->json([
            'message' => 'Pelaporan kehilangan belum dapat dikonfirmasi.',
            'lending_id' => $lending->id,
            'status' => Lending::LOST_PENDING,
        ], Response::HTTP_ACCEPTED);
    }
}
