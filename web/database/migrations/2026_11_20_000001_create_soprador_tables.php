<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['user', 'admin'])->default('user')->after('password');
        });

        Schema::create('readings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('device_id', 40)->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->longText('value_enc');
            $table->timestamp('ts')->useCurrent()->index();
        });

        Schema::create('commands', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 30);
            $table->longText('value_enc');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->boolean('acked')->default(false);
            $table->timestamp('ts')->useCurrent();
        });

        Schema::create('logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->longText('action_enc');
            $table->timestamp('ts')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logs');
        Schema::dropIfExists('commands');
        Schema::dropIfExists('readings');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};