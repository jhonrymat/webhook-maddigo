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
        Schema::create('newsletter_statistics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('newsletter_id')->nullable();
            $table->string('email');
            $table->string('message_id')->unique();
            $table->string('status');
            $table->string('source_ip')->nullable();
            $table->string('browser')->nullable();
            $table->string('operating_system')->nullable();
            $table->timestamps();

            // Índices y llaves foráneas
            $table->foreign('newsletter_id')->references('id')->on('newsletters')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('newsletter_statistics');
    }
};
