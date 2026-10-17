<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('lendings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('member_id')
                ->constrained()
                ->restrictOnDelete();

            // Referensi eksternal ke Book Service.
            $table->unsignedBigInteger('book_copy_id');

            // UUID untuk mengidentifikasi reservasi di Book Service.
            $table->uuid('reservation_reference')->unique();

            $table->date('borrowed_at');
            $table->date('due_at');
            $table->date('returned_at')->nullable();

            $table->string('status', 20)->default('PENDING');

            $table->timestamps();

            $table->index(['member_id', 'status']);
            $table->index(['status', 'due_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lendings');
    }
};
