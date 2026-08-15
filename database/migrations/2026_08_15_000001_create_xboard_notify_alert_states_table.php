<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('xboard_notify_alert_states', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('machine_id');
            $table->string('condition', 32);
            $table->unsignedInteger('first_detected_at');
            $table->unsignedInteger('last_notified_at')->nullable();
            $table->text('last_summary')->nullable();
            $table->timestamps();

            $table->unique(['machine_id', 'condition'], 'xboard_notify_machine_condition_unique');
            $table->index('last_notified_at', 'xboard_notify_last_notified_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('xboard_notify_alert_states');
    }
};
