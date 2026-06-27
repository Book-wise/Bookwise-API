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
        Schema::create('custom_attribute_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('field_type', ['text', 'number', 'date', 'select', 'checkbox']);
            $table->json('options')->nullable();
            $table->boolean('required')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('custom_attribute_templates');
    }
};
