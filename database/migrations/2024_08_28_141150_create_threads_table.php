<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('threads', function (Blueprint $table) {
            $table->id();
            $table->string('wa_id'); // Eliminamos la restricción de unicidad aquí
            $table->string('thread_id'); // ID del hilo de conversación
            $table->unsignedBigInteger('bot_id'); // Relación con el bot
            $table->timestamps(); // created_at y updated_at

            // Aseguramos la integridad referencial
            $table->foreign('bot_id')->references('id')->on('bots')->onDelete('cascade');

            // Añadimos un índice único compuesto entre wa_id y bot_id
            $table->unique(['wa_id', 'bot_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('threads');
    }
};
