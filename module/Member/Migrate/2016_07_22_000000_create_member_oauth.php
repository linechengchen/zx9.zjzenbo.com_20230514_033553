<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateMemberOauth extends Migration
{
    
    public function up()
    {
        Schema::create('member_oauth', function (Blueprint $table) {
            $table->increments('id');
            $table->timestamps();

            $table->integer('memberUserId')->comment('用户ID')->nullable();
            $table->string('type', 30)->comment('类型')->nullable();
            $table->string('openId', 150)->comment('OpenId')->nullable();

            $table->index(['type', 'openId']);
            $table->index(['memberUserId']);

        });
    }

    
    public function down()
    {
    }
}
