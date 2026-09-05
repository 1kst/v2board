<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateContentSiteAssignments extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('v2_notice_site')) {
            Schema::create('v2_notice_site', function (Blueprint $table) {
                $table->unsignedInteger('notice_id');
                $table->unsignedTinyInteger('site_id');
                $table->primary(['notice_id', 'site_id']);
                $table->index('site_id');
            });
        }

        if (!Schema::hasTable('v2_knowledge_site')) {
            Schema::create('v2_knowledge_site', function (Blueprint $table) {
                $table->unsignedInteger('knowledge_id');
                $table->unsignedTinyInteger('site_id');
                $table->primary(['knowledge_id', 'site_id']);
                $table->index('site_id');
            });
        }

        // Existing records predate multi-site ownership and therefore belong
        // to the main Chunqiux site unless an administrator reassigns them.
        if (Schema::hasTable('v2_notice')) {
            DB::statement('INSERT IGNORE INTO v2_notice_site (notice_id, site_id) SELECT id, 1 FROM v2_notice');
        }
        if (Schema::hasTable('v2_knowledge')) {
            DB::statement('INSERT IGNORE INTO v2_knowledge_site (knowledge_id, site_id) SELECT id, 1 FROM v2_knowledge');
        }
    }

    public function down()
    {
        Schema::dropIfExists('v2_notice_site');
        Schema::dropIfExists('v2_knowledge_site');
    }
}
