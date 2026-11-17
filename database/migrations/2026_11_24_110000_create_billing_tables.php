<?php

declare(strict_types=1);

use Database\Seeders\PlanSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plans and billing (spec §20, D80). The billable is the workspace (the paying tenant), so
 * Cashier's customer columns live on `workspaces` and its subscriptions point at a
 * workspace. Prices are integer paise / cents; limits are null for "unlimited".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('key', 30)->unique();
            $table->string('name', 60);
            $table->string('tagline')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            // Enterprise: priced with the team ("Talk to us"), never bought through Checkout.
            $table->boolean('custom_pricing')->default(false);
            $table->boolean('popular')->default(false);
            $table->bigInteger('price_monthly_inr')->nullable();
            $table->bigInteger('price_yearly_inr')->nullable();
            $table->bigInteger('price_monthly_usd')->nullable();
            $table->bigInteger('price_yearly_usd')->nullable();
            $table->unsignedInteger('max_companies')->nullable();
            $table->unsignedInteger('max_users')->nullable();
            $table->unsignedInteger('max_vault_items')->nullable();
            $table->unsignedInteger('max_storage_mb')->nullable();
            $table->json('features')->nullable();
            $table->timestamps();
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->foreignId('plan_id')->nullable()->after('owner_id')->constrained();
            $table->string('billing_cycle', 10)->nullable()->after('plan_id');
            $table->string('billing_currency', 3)->nullable()->after('billing_cycle');
            $table->string('billing_email')->nullable();
            $table->string('billing_name')->nullable();
            $table->string('billing_gstin', 15)->nullable();
            // Cashier's customer columns (trial_ends_at already exists: the no-card trial).
            $table->string('stripe_id')->nullable()->index();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            // When payments started failing; read-only follows after the grace period (D81).
            $table->timestamp('past_due_since')->nullable();
            // The paid period's end, kept from webhooks so the page needn't ask Stripe.
            $table->timestamp('renews_at')->nullable();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('stripe_id')->unique();
            $table->string('stripe_status');
            $table->string('stripe_price')->nullable();
            $table->integer('quantity')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'stripe_status']);
        });

        Schema::create('subscription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('stripe_id')->unique();
            $table->string('stripe_product');
            $table->string('stripe_price');
            $table->string('meter_id')->nullable();
            $table->integer('quantity')->nullable();
            $table->string('meter_event_name')->nullable();
            $table->timestamps();

            $table->index(['subscription_id', 'stripe_price']);
        });

        // Every install needs the plans (placeholder prices and limits until the owner edits them).
        (new PlanSeeder)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_items');
        Schema::dropIfExists('subscriptions');

        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_id');
            $table->dropIndex(['stripe_id']);
            $table->dropColumn(['billing_cycle', 'billing_currency', 'billing_email', 'billing_name', 'billing_gstin', 'stripe_id', 'pm_type', 'pm_last_four', 'past_due_since', 'renews_at']);
        });

        Schema::dropIfExists('plans');
    }
};
