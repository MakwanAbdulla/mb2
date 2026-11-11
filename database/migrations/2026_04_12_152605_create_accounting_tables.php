<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Kontoplan ─────────────────────────────────────────────────────────
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name');
            $table->enum('type', ['asset', 'liability', 'equity', 'income', 'expense']);
            $table->string('vat_code', 10)->nullable();
            $table->boolean('is_group')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // ── Verifikationsserier ───────────────────────────────────────────────
        Schema::create('journal_series', function (Blueprint $table) {
            $table->id();
            $table->char('code', 1)->unique();
            $table->string('name', 100);
            $table->boolean('is_manual')->default(false);
            $table->boolean('is_lockable')->default(true);
            $table->boolean('is_system')->default(false);
            $table->string('default_for', 50)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // ── Räkenskapsår ─────────────────────────────────────────────────────
        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->date('starts_at');
            $table->date('ends_at');
            $table->boolean('is_locked')->default(false);
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // ── Kostnadsställen ───────────────────────────────────────────────────
        Schema::create('cost_centers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->enum('type', ['cost_center', 'project'])->default('cost_center');
            $table->foreignId('parent_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // ── Periodlåsning ─────────────────────────────────────────────────────
        Schema::create('period_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_year_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->timestamp('locked_at');
            $table->foreignId('locked_by')->constrained('users');
            $table->timestamps();
            $table->unique(['fiscal_year_id', 'year', 'month']);
        });

        // ── Betalningsterminer ────────────────────────────────────────────────
        Schema::create('payment_terms', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->unsignedSmallInteger('due_days');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // ── Företagskort (skapas före bank_transactions som refererar hit) ────
        Schema::create('corporate_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('card_reference')->unique();
            $table->string('card_number', 19)->nullable();
            $table->string('label')->nullable();
            $table->boolean('is_active')->default(true);
            $table->date('expires_at')->nullable();
            $table->string('issuer')->nullable()->default('Swedbank');
            $table->timestamps();
        });

        // ── Bankkonton ────────────────────────────────────────────────────────
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('account_number', 50)->nullable();
            $table->string('clearing_number', 10)->nullable();
            $table->string('bank_name', 100)->nullable();
            $table->foreignId('account_id')->constrained('accounts');
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            $table->char('currency', 3)->default('SEK');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->string('bankgiro', 20)->nullable();
            $table->string('plusgiro', 20)->nullable();
            $table->string('swish_number', 20)->nullable();
            $table->string('swish_type', 20)->nullable()->comment('privat | handel | företag');
            $table->string('iban', 34)->nullable();
            $table->timestamps();
        });

        // ── Leverantörsregister ───────────────────────────────────────────────
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->enum('type', ['external', 'member'])->default('external');
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->string('org_number', 20)->nullable();
            $table->string('bankgiro', 20)->nullable();
            $table->string('plusgiro', 20)->nullable();
            $table->string('iban', 34)->nullable();
            $table->string('bank_clearing_number', 10)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->foreignId('default_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->unsignedTinyInteger('default_vat_rate')->default(0);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ── Kundregister ─────────────────────────────────────────────────────
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('org_number', 20)->nullable();
            $table->string('email')->nullable();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('default_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ── Betalkort ─────────────────────────────────────────────────────────
        Schema::create('payment_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('label', 100);
            $table->char('last_four', 4);
            $table->foreignId('account_id')->constrained('accounts');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ── Betalningsleverantörer ────────────────────────────────────────────
        Schema::create('payment_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->enum('type', ['online', 'card_terminal'])->default('online');
            $table->foreignId('account_id')->constrained('accounts');
            $table->foreignId('settlement_bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // ── Verifikationer ────────────────────────────────────────────────────
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_year_id')->constrained();
            $table->char('series', 1);
            $table->unsignedInteger('number');
            $table->date('date');
            $table->string('description', 500);
            $table->nullableMorphs('source');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_locked')->default(false);
            $table->timestamp('edited_at')->nullable();
            $table->string('edit_note', 500)->nullable();
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->boolean('requires_receipt')->default(false);
            $table->timestamps();
            $table->unique(['fiscal_year_id', 'series', 'number']);
        });

        // ── Verifikationsrader ────────────────────────────────────────────────
        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts');
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            $table->unsignedInteger('debit')->default(0);
            $table->unsignedInteger('credit')->default(0);
            $table->string('description', 255)->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->foreignId('superseded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // ── Fakturor ─────────────────────────────────────────────────────────
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['payable', 'receivable']);
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_term_id')->nullable()->constrained('payment_terms')->nullOnDelete();
            $table->string('invoice_number', 50)->nullable();
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->integer('amount_ex_vat');
            $table->integer('vat_amount')->default(0);
            $table->integer('total');
            $table->char('currency', 3)->default('SEK');
            $table->enum('status', ['draft', 'pending_approval', 'approved', 'posted', 'paid', 'cancelled'])->default('draft');
            $table->string('payment_method', 30)->nullable();
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->string('ocr_number', 25)->nullable();
            $table->json('ai_extraction_raw')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('payment_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        // ── Fakturarader ──────────────────────────────────────────────────────
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            $table->string('description');
            $table->integer('amount_ex_vat');
            $table->unsignedTinyInteger('vat_rate')->default(0);
            $table->integer('vat_amount')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // ── Fakturanummerserier ───────────────────────────────────────────────
        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('series', 20);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->unique(['series', 'year']);
            $table->timestamps();
        });

        // ── Banktransaktioner ─────────────────────────────────────────────────
        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained()->cascadeOnDelete();
            $table->date('transaction_date');
            $table->date('booking_date')->nullable();
            $table->date('value_date')->nullable();
            $table->integer('amount');
            $table->integer('balance_ore')->nullable();
            $table->string('description', 500)->nullable();
            $table->string('reference', 100)->nullable();
            $table->string('import_hash', 64)->nullable()->unique();
            $table->text('notes')->nullable();
            $table->enum('status', ['unmatched', 'matched', 'posted', 'ignored'])->default('unmatched');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('corporate_card_id')->nullable()->constrained('corporate_cards')->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->json('raw_data')->nullable();
            $table->timestamps();
        });

        // ── Automatiska bokföringsregler ──────────────────────────────────────
        Schema::create('posting_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('trigger', 50);
            $table->foreignId('debit_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('credit_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('counter_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->char('series', 1)->default('U');
            $table->enum('transaction_type', ['credit', 'debit', 'any'])->default('any');
            $table->integer('amount_min_ore')->nullable();
            $table->integer('amount_max_ore')->nullable();
            $table->string('keyword', 200)->nullable();
            $table->string('from_email_pattern', 255)->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->unsignedTinyInteger('day_from')->nullable();
            $table->unsignedTinyInteger('day_to')->nullable();
            $table->boolean('requires_receipt')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // ── Bokföringsmallar ──────────────────────────────────────────────────
        Schema::create('journal_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->char('series', 1)->default('A');
            $table->json('lines');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ── Budget ────────────────────────────────────────────────────────────
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_year_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->boolean('is_active')->default(false);
            $table->boolean('is_locked')->default(false);
            $table->foreignId('created_by')->constrained('users');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts');
            $table->unsignedInteger('annual_ore')->default(0);
            $table->unsignedSmallInteger('jan_pct')->default(0);
            $table->unsignedSmallInteger('feb_pct')->default(0);
            $table->unsignedSmallInteger('mar_pct')->default(0);
            $table->unsignedSmallInteger('apr_pct')->default(0);
            $table->unsignedSmallInteger('maj_pct')->default(0);
            $table->unsignedSmallInteger('jun_pct')->default(0);
            $table->unsignedSmallInteger('jul_pct')->default(0);
            $table->unsignedSmallInteger('aug_pct')->default(0);
            $table->unsignedSmallInteger('sep_pct')->default(0);
            $table->unsignedSmallInteger('okt_pct')->default(0);
            $table->unsignedSmallInteger('nov_pct')->default(0);
            $table->unsignedSmallInteger('dec_pct')->default(0);
            $table->timestamps();
            $table->unique(['budget_id', 'account_id']);
        });

        // ── Attestregler ──────────────────────────────────────────────────────
        Schema::create('approval_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->enum('invoice_type', ['payable', 'receivable', 'expense', 'any'])->nullable();
            $table->unsignedInteger('amount_min')->nullable();
            $table->unsignedInteger('amount_max')->nullable();
            $table->unsignedTinyInteger('required_approvals')->default(1);
            $table->string('approver_permission', 100);
            $table->boolean('block_submitter')->default(true);
            $table->boolean('block_beneficiary')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('approval_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approved_by')->constrained('users');
            $table->enum('action', ['approved', 'rejected']);
            $table->text('notes')->nullable();
            $table->timestamp('actioned_at');
            $table->timestamps();
        });

        // ── Öppningsbalanser ──────────────────────────────────────────────────
        Schema::create('opening_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->integer('amount')->default(0);
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['fiscal_year_id', 'account_id']);
        });

        // ── Bokföringsdokument ────────────────────────────────────────────────
        Schema::create('accounting_documents', function (Blueprint $table) {
            $table->id();
            $table->string('type', 50)->default('other');
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('fiscal_year_id')->nullable()->constrained('fiscal_years')->nullOnDelete();
            $table->string('disk', 50)->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->text('description')->nullable();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamp('linked_at')->nullable();
            $table->timestamps();

            $table->index('invoice_id');
            $table->index('journal_entry_id');
            $table->index(['fiscal_year_id', 'type']);
        });

        // ── Inkorg: E-post ────────────────────────────────────────────────────
        Schema::create('accounting_inbox_emails', function (Blueprint $table) {
            $table->id();
            $table->string('message_id')->nullable()->unique();
            $table->string('from_email');
            $table->string('from_name')->nullable();
            $table->string('original_from_email', 255)->nullable();
            $table->string('subject')->nullable();
            $table->text('body_text')->nullable();
            $table->mediumText('body_html')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->enum('sender_classification', ['allowed', 'blocked', 'unknown'])->default('unknown');
            $table->boolean('has_attachments')->default(false);
            $table->timestamps();
        });

        // ── Inkorg: Godkända avsändare ────────────────────────────────────────
        Schema::create('accounting_inbox_senders', function (Blueprint $table) {
            $table->id();
            $table->string('pattern', 255)->unique();
            $table->enum('type', ['allowed', 'blocked'])->default('allowed');
            $table->string('label', 100)->nullable();
            $table->foreignId('payment_provider_id')->nullable()->constrained('payment_providers')->nullOnDelete();
            $table->string('original_from_email', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // ── Inkorg: Bilagor ───────────────────────────────────────────────────
        Schema::create('accounting_inbox_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accounting_inbox_email_id')->nullable()->constrained('accounting_inbox_emails')->nullOnDelete();
            $table->string('from_email');
            $table->string('from_name')->nullable();
            $table->string('original_from_email', 255)->nullable();
            $table->string('subject', 500)->nullable();
            $table->timestamp('received_at')->nullable();
            $table->string('email_message_id', 500)->nullable();
            $table->string('filename');
            $table->string('path', 500);
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->char('content_hash', 64)->unique();
            $table->enum('status', ['needs_approval', 'pending', 'linked', 'ignored'])->default('pending');
            $table->foreignId('payment_provider_id')->nullable()->constrained('payment_providers')->nullOnDelete();
            $table->string('extracted_doc_type', 50)->nullable();
            $table->string('extracted_doc_number', 100)->nullable();
            $table->date('extracted_period_start')->nullable();
            $table->date('extracted_period_end')->nullable();
            $table->integer('extracted_net_amount_ore')->nullable();
            $table->integer('extracted_gross_amount_ore')->nullable();
            $table->integer('extracted_fee_amount_ore')->nullable();
            $table->text('extracted_text')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('accounting_document_id')->nullable()->constrained('accounting_documents')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('received_at');
        });

        // ── Bankfilsimporter ──────────────────────────────────────────────────
        Schema::create('bank_statement_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained()->cascadeOnDelete();
            $table->char('file_hash', 64)->unique();
            $table->unsignedInteger('row_count');
            $table->timestamps();
        });

        // ── Utläggsredovisning ────────────────────────────────────────────────
        Schema::create('expense_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->date('purchased_at');
            $table->unsignedInteger('amount_ore');
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            $table->string('receipt_disk')->default('local');
            $table->string('receipt_path')->nullable();
            $table->string('receipt_original_name')->nullable();
            $table->string('receipt_mime_type')->nullable();
            $table->unsignedInteger('receipt_size_bytes')->nullable();
            $table->string('status')->default('submitted');
            $table->timestamp('submitted_at')->nullable();
            $table->string('claim_type')->default('reimbursement');
            $table->foreignId('corporate_card_id')->nullable()->constrained('corporate_cards')->nullOnDelete();
            $table->foreignId('bank_transaction_id')->nullable()->constrained('bank_transactions')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();
        });

        // ── Swish-import ──────────────────────────────────────────────────────
        Schema::create('swish_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained()->cascadeOnDelete();
            $table->char('file_hash', 64)->unique();
            $table->string('filename');
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('matched_count')->default(0);
            $table->timestamps();
        });

        Schema::create('swish_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('swish_import_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained('bank_accounts')->cascadeOnDelete();
            $table->foreignId('bank_transaction_id')->nullable()->constrained('bank_transactions')->nullOnDelete();
            $table->unsignedInteger('row_number');
            $table->date('booking_date');
            $table->date('transaction_date');
            $table->date('value_date');
            $table->string('swish_number', 20);
            $table->string('sender_phone', 20);
            $table->string('sender_name')->nullable();
            $table->string('message')->nullable();
            $table->string('transaction_time', 5)->nullable();
            $table->unsignedInteger('amount_ore');
            $table->string('order_reference')->nullable();
            $table->char('import_hash', 64)->unique();
            $table->timestamps();

            $table->index(['bank_account_id', 'value_date']);
            $table->index('sender_phone');
        });

        // ── Zettle ────────────────────────────────────────────────────────────
        Schema::create('zettle_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('purchase_uuid')->unique();
            $table->foreignId('bank_account_id')->constrained('bank_accounts')->restrictOnDelete();
            $table->foreignId('bank_transaction_id')->nullable()->constrained('bank_transactions')->nullOnDelete();
            $table->date('purchase_date');
            $table->time('purchase_time');
            $table->string('receipt_number')->nullable();
            $table->integer('gross_ore');
            $table->integer('fee_ore');
            $table->integer('net_ore');
            $table->string('payment_type')->nullable();
            $table->string('card_type')->nullable();
            $table->string('card_last_four')->nullable();
            $table->string('staff')->nullable();
            $table->string('description')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
        });

        Schema::create('zettle_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained('bank_accounts')->restrictOnDelete();
            $table->foreignId('bank_transaction_id')->nullable()->constrained('bank_transactions')->nullOnDelete();
            $table->string('payout_uuid')->nullable()->unique();
            $table->date('payout_date');
            $table->integer('amount_ore');
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->index(['bank_account_id', 'payout_date']);
        });

        // ── SumUp ─────────────────────────────────────────────────────────────
        Schema::create('sumup_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_id')->unique();
            $table->string('transaction_code');
            $table->foreignId('bank_account_id')->constrained('bank_accounts')->restrictOnDelete();
            $table->foreignId('bank_transaction_id')->nullable()->constrained('bank_transactions')->nullOnDelete();
            $table->dateTime('purchased_at');
            $table->date('payout_date')->nullable();
            $table->integer('amount_ore');
            $table->char('currency', 3)->default('SEK');
            $table->string('status');
            $table->string('payment_type')->nullable();
            $table->string('entry_mode')->nullable();
            $table->string('card_type')->nullable();
            $table->string('product_summary')->nullable();
            $table->string('staff_email')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
        });

        Schema::create('sumup_payouts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sumup_id')->unique();
            $table->foreignId('bank_account_id')->constrained('bank_accounts')->restrictOnDelete();
            $table->foreignId('bank_transaction_id')->nullable()->constrained('bank_transactions')->nullOnDelete();
            $table->date('payout_date');
            $table->string('transaction_code');
            $table->string('reference');
            $table->string('status');
            $table->string('type')->default('PAYOUT');
            $table->char('currency', 3)->default('SEK');
            $table->integer('amount_ore');
            $table->integer('fee_ore');
            $table->json('raw')->nullable();
            $table->timestamps();
        });

        // Cross-module FKs that depend on journal_entries / cost_centers / customers being created above
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('journal_entry_id')->nullable()->after('invoice_brand')
                ->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->after('user_id')
                ->constrained('customers')->nullOnDelete();
        });

        Schema::table('events', function (Blueprint $table) {
            $table->foreignId('cost_center_id')->nullable()->after('organizer_external_name')
                ->constrained('cost_centers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cost_center_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_entry_id');
            $table->dropConstrainedForeignId('customer_id');
        });

        Schema::dropIfExists('sumup_payouts');
        Schema::dropIfExists('sumup_transactions');
        Schema::dropIfExists('zettle_payouts');
        Schema::dropIfExists('zettle_transactions');
        Schema::dropIfExists('swish_transactions');
        Schema::dropIfExists('swish_imports');
        Schema::dropIfExists('expense_claims');
        Schema::dropIfExists('bank_statement_imports');
        Schema::dropIfExists('accounting_inbox_items');
        Schema::dropIfExists('accounting_inbox_senders');
        Schema::dropIfExists('accounting_inbox_emails');
        Schema::dropIfExists('accounting_documents');
        Schema::dropIfExists('opening_balances');
        Schema::dropIfExists('approval_logs');
        Schema::dropIfExists('approval_rules');
        Schema::dropIfExists('budget_lines');
        Schema::dropIfExists('budgets');
        Schema::dropIfExists('journal_templates');
        Schema::dropIfExists('posting_rules');
        Schema::dropIfExists('bank_transactions');
        Schema::dropIfExists('invoice_sequences');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('payment_providers');
        Schema::dropIfExists('payment_cards');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('vendors');
        Schema::dropIfExists('bank_accounts');
        Schema::dropIfExists('corporate_cards');
        Schema::dropIfExists('payment_terms');
        Schema::dropIfExists('period_locks');
        Schema::dropIfExists('cost_centers');
        Schema::dropIfExists('fiscal_years');
        Schema::dropIfExists('journal_series');
        Schema::dropIfExists('accounts');
    }
};
