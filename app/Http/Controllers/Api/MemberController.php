<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class MemberController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Member::query()
                ->orderBy('name')
                ->paginate(10)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'unique:members,user_id'],
            'member_number' => [
                'required',
                'string',
                'max:50',
                'unique:members,member_number',
            ],
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                'unique:members,email',
            ],
        ]);

        $member = Member::create($validated);

        return response()->json($member, Response::HTTP_CREATED);
    }

    public function show(Member $member): JsonResponse
    {
        return response()->json(
            $member->load('lendings')
        );
    }

    public function update(Request $request, Member $member): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('members', 'email')->ignore($member->id),
            ],
        ]);

        $member->update($validated);

        return response()->json($member);
    }
}
