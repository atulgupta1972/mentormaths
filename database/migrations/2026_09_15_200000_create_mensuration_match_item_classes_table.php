<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mensuration_match_item_classes', function (Blueprint $table) {
            $table->id();
            $table->string('item_key', 64)->unique();
            $table->json('classes'); // e.g. [4,5,6,7] — empty array = shown to none
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mensuration_match_item_classes');
    }
};
