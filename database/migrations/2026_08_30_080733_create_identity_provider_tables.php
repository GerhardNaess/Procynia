<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * External identity providers and the link between them and Procynia users (Entra ID / SSO).
 *
 * Two tables rather than columns on `users`, for two reasons that matter later:
 *
 *   - Procynia is multi-customer. Customer A and customer B will have different Entra tenants, so
 *     the provider is its own row with its own tenant id, not a global constant in config.
 *   - A user may eventually authenticate through more than one provider (an internal admin through
 *     Procynia's own tenant, a consultant through a customer's). A one-to-many link keeps that open
 *     without another migration.
 *
 * No client secret is stored here. Secrets belong in env today and Key Vault later; the database
 * holds only what is safe to read — tenant id, client id, issuer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_providers', function (Blueprint $table): void {
            $table->id();

            // Null means an internal Procynia provider — the tenant Procynia's own admins sign in
            // through. A customer-scoped provider belongs to exactly one customer, which is what
            // stops an identity from one tenant resolving to a user in another.
            $table->foreignId('customer_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('provider', 32)->default('entra');

            // The Entra directory (tenant) id. Together with the token subject this is the stable
            // identity key — see user_identities.
            $table->string('tenant_id');

            // Application (client) id. Config, not a secret.
            $table->string('client_id');

            // Expected `iss` claim. Stored rather than derived so a sovereign or national cloud can
            // be configured without a code change.
            $table->string('issuer');

            $table->boolean('is_enabled')->default(true);

            // Optional restriction: only these email domains may link through this provider. Empty
            // means no domain restriction beyond the tenant itself.
            $table->json('allowed_email_domains')->nullable();

            $table->timestamps();

            // One provider per (customer, tenant). A second row for the same pair would make
            // resolution ambiguous, which is a security question, not a cosmetic one.
            $table->unique(['customer_id', 'tenant_id', 'provider']);

            $table->index(['tenant_id', 'is_enabled']);
        });

        Schema::create('user_identities', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('identity_provider_id')->constrained()->cascadeOnDelete();

            // The `oid`/`sub` claim. Stable across email and display-name changes, which is why it
            // and not the address is the identity key.
            $table->string('external_subject');

            // Recorded for support and for the one-time linking decision. Never used to
            // re-authenticate on its own.
            $table->string('external_email')->nullable();

            $table->timestamp('last_login_at')->nullable();

            $table->timestamps();

            // A subject is unique within its provider. Without this, two rows could claim the same
            // Entra identity and resolution would depend on row order.
            $table->unique(['identity_provider_id', 'external_subject']);

            // A user links to a given provider at most once.
            $table->unique(['user_id', 'identity_provider_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_identities');
        Schema::dropIfExists('identity_providers');
    }
};
