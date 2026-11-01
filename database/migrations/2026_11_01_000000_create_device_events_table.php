<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Security event log: motion, device presence and camera changes.
     * Servo positions are intentionally not stored (high-frequency control
     * commands; the latest position lives in the retained MQTT message).
     */
    public function up(): void
    {
        Schema::create('device_events', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32);
            $table->string('source', 16);
            $table->json('payload')->nullable();
            $table->string('snapshot_path')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['type', 'occurred_at']);
            $table->index(['source', 'occurred_at']);
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_events');
    }
};
