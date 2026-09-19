<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products') && !Schema::hasColumn('products', 'model')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('model', 120)->nullable()->after('name');
            });
        }

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (!Schema::hasColumn('users', 'must_change_password')) {
                    $table->boolean('must_change_password')->default(false)->after('password');
                }
                if (!Schema::hasColumn('users', 'temporary_password_set_at')) {
                    $table->timestamp('temporary_password_set_at')->nullable()->after('must_change_password');
                }
            });
        }

        if (!Schema::hasTable('product_variant_stocks')) {
            Schema::create('product_variant_stocks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->string('color', 50);
                $table->string('size', 10);
                $table->unsignedInteger('stock')->default(0);
                $table->timestamps();
                $table->unique(['product_id', 'color', 'size'], 'pvs_product_color_size_unique');
                $table->index(['product_id', 'stock']);
            });
        }

        if (!Schema::hasTable('password_reset_audits')) {
            Schema::create('password_reset_audits', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('action', 60)->default('admin_temporary_password');
                $table->string('ip_address', 64)->nullable();
                $table->timestamps();
                $table->index(['user_id', 'created_at']);
            });
        }

        if (!Schema::hasTable('newsletter_subscribers')) {
            Schema::create('newsletter_subscribers', function (Blueprint $table) {
                $table->id();
                $table->string('email')->unique();
                $table->string('status', 20)->default('active');
                $table->timestamp('subscribed_at')->nullable();
                $table->timestamp('unsubscribed_at')->nullable();
                $table->timestamps();
                $table->index(['status', 'subscribed_at']);
            });
        }

        if (!Schema::hasTable('store_interactions')) {
            Schema::create('store_interactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
                $table->string('type', 50);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['type', 'created_at']);
                $table->index(['product_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('store_interactions');
        Schema::dropIfExists('newsletter_subscribers');
        Schema::dropIfExists('password_reset_audits');
        Schema::dropIfExists('product_variant_stocks');

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (Schema::hasColumn('users', 'temporary_password_set_at')) {
                    $table->dropColumn('temporary_password_set_at');
                }
                if (Schema::hasColumn('users', 'must_change_password')) {
                    $table->dropColumn('must_change_password');
                }
            });
        }

        if (Schema::hasTable('products') && Schema::hasColumn('products', 'model')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('model');
            });
        }
    }
};
