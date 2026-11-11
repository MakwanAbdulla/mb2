<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Undertecknare (återanvänds mellan dokument hos DocSign) ─────────────
        // A signer is a user (member or not — the member is reached via
        // user->member) or an external one-off person (user_id null, matched on
        // e-mail). Members without an account have no e-mail and can't sign anyway.
        Schema::create('signing_signers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('company')->nullable();
            // DocSign requires e-mail OR phone (enforced in SigningSigner::saving).
            $table->string('email')->nullable();
            $table->string('phone_number', 20)->nullable();
            $table->string('sign_method', 20);
            $table->string('provider_party_id', 50)->nullable()->unique();
            $table->timestamps();

            $table->index(['user_id', 'sign_method']);
            $table->index(['email', 'sign_method']);
            $table->index(['phone_number', 'sign_method']);
        });

        // ── Signeringsärenden ───────────────────────────────────────────────────
        Schema::create('signing_requests', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('signable');
            $table->string('name');
            $table->text('comment')->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('provider', 20)->default('docsign');
            $table->string('provider_document_id', 50)->nullable();
            $table->boolean('send_reminders')->default(true);
            $table->boolean('send_receipt')->default(true);
            $table->boolean('is_demo')->default(false);
            $table->string('callback_token_hash', 64)->nullable();
            $table->string('signed_disk', 50)->nullable();
            $table->string('signed_path')->nullable();
            // DocSign's signed PDF carries no digital signature, so we record the
            // file's hash when downloading — internal evidence it hasn't changed since.
            $table->char('signed_sha256', 64)->nullable();
            $table->text('last_error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_document_id']);
            $table->index(['status', 'last_synced_at']);
        });

        // ── Bilagor ─────────────────────────────────────────────────────────────
        Schema::create('signing_request_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('signing_request_id')->constrained('signing_requests')->cascadeOnDelete();
            $table->string('name');
            $table->string('disk', 50)->default('local');
            $table->string('path');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // ── Undertecknare per ärende ────────────────────────────────────────────
        Schema::create('signing_request_signers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('signing_request_id')->constrained('signing_requests')->cascadeOnDelete();
            $table->foreignId('signing_signer_id')->constrained('signing_signers')->restrictOnDelete();
            $table->string('role_label')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('signed_at')->nullable();
            $table->string('signed_by_name')->nullable();
            $table->text('sign_url')->nullable(); // encrypted cast — whoever has it can sign
            $table->timestamps();

            // Explicit name: the generated one exceeds MySQL's 64-character limit.
            $table->unique(['signing_request_id', 'signing_signer_id'], 'signing_request_signers_request_signer_unique');
        });

        // ── Händelselogg ────────────────────────────────────────────────────────
        Schema::create('signing_request_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('signing_request_id')->constrained('signing_requests')->cascadeOnDelete();
            $table->foreignId('signing_request_signer_id')->nullable()->constrained('signing_request_signers')->nullOnDelete();
            $table->string('type', 30);
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['signing_request_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signing_request_events');
        Schema::dropIfExists('signing_request_signers');
        Schema::dropIfExists('signing_request_files');
        Schema::dropIfExists('signing_requests');
        Schema::dropIfExists('signing_signers');
    }
};
