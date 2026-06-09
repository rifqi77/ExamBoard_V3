<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a long-lived per-attempt `resume_token` to exam_sessions so a student
 * who gets interrupted (closed tab, browser crash, network outage, even an
 * expired 8h exam-access cookie) can land back on the SAME draft session
 * with answers intact via /exams/{code}/resume/{resumeToken}.
 *
 * Coexists with the existing flow: sessions without a resume_token (old
 * rows + brand-new redemptions through /token) still work — the Student
 * Hub falls back to the regular /exams/{code} link, which requires the
 * exam-access cookie.
 *
 * Token is a UUID v4 so it's unguessable; the route ALSO verifies that
 * session.user_id matches the authenticated user, so a leaked token is
 * useless to anyone but its owner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_sessions', function (Blueprint $t) {
            $t->string('resume_token', 191)->nullable()->unique()->after('token_id');
        });
    }

    public function down(): void
    {
        Schema::table('exam_sessions', function (Blueprint $t) {
            $t->dropUnique(['resume_token']);
            $t->dropColumn('resume_token');
        });
    }
};
