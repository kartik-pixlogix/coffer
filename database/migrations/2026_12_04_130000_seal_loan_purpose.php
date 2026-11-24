<?php

use App\Services\Security\FieldCrypto;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A loan's "what it's for" joined the sealed fields after the first sealing migration had
     * already run on some databases (D149). Widen and seal it; a no-op where that migration
     * covered it.
     */
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table): void {
            $table->text('purpose')->nullable()->change();
        });

        $crypto = app(FieldCrypto::class);

        DB::table('loans')->whereNotNull('purpose')->where('purpose', '<>', '')->where('purpose', 'not like', FieldCrypto::PREFIX.'%')
            ->orderBy('id')->chunkById(200, function ($rows) use ($crypto): void {
                foreach ($rows as $row) {
                    DB::table('loans')->where('id', $row->id)->update(['purpose' => $crypto->seal((int) $row->company_id, (string) $row->purpose, 'loans.purpose')]);
                }
            });
    }

    public function down(): void
    {
        // The first sealing migration's down() opens every sealed field, this one included.
    }
};
