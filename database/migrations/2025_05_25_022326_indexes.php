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
        Schema::table('search', function (Blueprint $table) {
            // Add FULLTEXT index for the 'name' column
            $table->fullText('name', 'idx_search_name');

            // Add regular indexes for other columns
            $table->index('company_id', 'idx_search_company_id');
            $table->index('city', 'idx_search_city');
            $table->index('postal_code', 'idx_search_postal_code');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('search', function (Blueprint $table) {
            // Drop the FULLTEXT index
            $table->dropFullText('idx_search_name');

            // Drop the regular indexes
            $table->dropIndex('idx_search_company_id');
            $table->dropIndex('idx_search_city');
            $table->dropIndex('idx_search_postal_code');
        });
    }
};
