<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('aplicacion_bot', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aplicacion_id')->constrained('aplicaciones')->onDelete('cascade');
            $table->foreignId('bot_id')->constrained('bots')->onDelete('cascade');
            $table->unique('aplicacion_id'); // Garantiza que una aplicación tenga un solo bot
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('aplicacion_bot');
    }
};
