<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the filters the reporting screens actually use.
 *
 * Scorecards look up a person's approved work inside a month, the dashboard
 * looks up credentials expiring per project, and the task list filters out
 * recurrence sources on every page. Each of those was a scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->index(['assigned_to', 'approved_at'], 'tasks_assignee_approved_index');
            $table->index(['project_id', 'is_recurrence_source', 'status'], 'tasks_project_source_status_index');
        });

        Schema::table('articles', function (Blueprint $table) {
            $table->index(['writer_id', 'approved_at'], 'articles_writer_approved_index');
        });

        Schema::table('links', function (Blueprint $table) {
            $table->index(['created_by', 'approved_at'], 'links_creator_approved_index');
        });

        Schema::table('credentials', function (Blueprint $table) {
            $table->index(['project_id', 'expires_on'], 'credentials_project_expiry_index');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex('tasks_assignee_approved_index');
            $table->dropIndex('tasks_project_source_status_index');
        });

        Schema::table('articles', function (Blueprint $table) {
            $table->dropIndex('articles_writer_approved_index');
        });

        Schema::table('links', function (Blueprint $table) {
            $table->dropIndex('links_creator_approved_index');
        });

        Schema::table('credentials', function (Blueprint $table) {
            $table->dropIndex('credentials_project_expiry_index');
        });
    }
};
