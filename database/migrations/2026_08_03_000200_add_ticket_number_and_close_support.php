<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('help_tickets', 'ticket_number')) {
            Schema::table('help_tickets', function (Blueprint $table) {
                $table->string('ticket_number', 32)->nullable()->after('id');
                $table->timestamp('closed_at')->nullable()->after('status');
            });

            Schema::table('help_tickets', function (Blueprint $table) {
                $table->unique('ticket_number');
            });
        } elseif (! Schema::hasColumn('help_tickets', 'closed_at')) {
            Schema::table('help_tickets', function (Blueprint $table) {
                $table->timestamp('closed_at')->nullable()->after('status');
            });
        }

        $tickets = DB::table('help_tickets')->whereNull('ticket_number')->orderBy('created_at')->get(['id', 'created_at']);
        $counters = [];
        foreach ($tickets as $ticket) {
            $day = substr((string) $ticket->created_at, 0, 10);
            $key = str_replace('-', '', $day);
            $counters[$key] = ($counters[$key] ?? 0) + 1;
            $number = 'HELP-'.$key.'-'.str_pad((string) $counters[$key], 4, '0', STR_PAD_LEFT);
            DB::table('help_tickets')->where('id', $ticket->id)->update(['ticket_number' => $number]);
        }

        if (Schema::hasColumn('help_ticket_replies', 'author_role')) {
            return;
        }

        Schema::rename('help_ticket_replies', 'help_ticket_replies_old');

        try {
            DB::statement('DROP INDEX IF EXISTS "help_ticket_replies_help_ticket_id_created_at_index"');
        } catch (Throwable $e) {
            // ignore
        }

        Schema::create('help_ticket_replies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('help_ticket_id');
            $table->uuid('admin_id')->nullable()->index();
            $table->uuid('user_id')->nullable()->index();
            $table->string('author_role', 16)->default('admin');
            $table->text('message');
            $table->timestamp('emailed_at')->nullable();
            $table->timestamps();
            $table->index(['help_ticket_id', 'created_at'], 'help_replies_ticket_created_idx');
        });

        $rows = DB::table('help_ticket_replies_old')->get();
        foreach ($rows as $row) {
            DB::table('help_ticket_replies')->insert([
                'id' => $row->id,
                'help_ticket_id' => $row->help_ticket_id,
                'admin_id' => $row->admin_id,
                'user_id' => null,
                'author_role' => 'admin',
                'message' => $row->message,
                'emailed_at' => $row->emailed_at,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }

        Schema::drop('help_ticket_replies_old');
    }

    public function down(): void
    {
        if (Schema::hasColumn('help_tickets', 'ticket_number')) {
            Schema::table('help_tickets', function (Blueprint $table) {
                $table->dropUnique(['ticket_number']);
                $table->dropColumn(['ticket_number', 'closed_at']);
            });
        }
    }
};
