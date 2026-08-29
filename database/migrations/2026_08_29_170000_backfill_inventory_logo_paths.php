<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @return array<string, string>
     */
    private function logoPathsByCode(): array
    {
        return [
            'RH' => '/images/senders/radio-hamburg.png',
            'OAH' => '/images/senders/80er-90er-oldie-antenne-hamburg.png',
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('inventories')) {
            return;
        }

        foreach ($this->logoPathsByCode() as $code => $path) {
            DB::table('inventories')
                ->where('code', $code)
                ->update(['logo_path' => $path]);
        }

        DB::table('inventories')
            ->where('name', 'Radio Hamburg')
            ->update(['logo_path' => '/images/senders/radio-hamburg.png']);

        DB::table('inventories')
            ->where('name', '80er 90er OLDIE ANTENNE Hamburg')
            ->update(['logo_path' => '/images/senders/80er-90er-oldie-antenne-hamburg.png']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('inventories')) {
            return;
        }

        foreach (array_keys($this->logoPathsByCode()) as $code) {
            DB::table('inventories')
                ->where('code', $code)
                ->update(['logo_path' => null]);
        }

        DB::table('inventories')
            ->whereIn('name', ['Radio Hamburg', '80er 90er OLDIE ANTENNE Hamburg'])
            ->update(['logo_path' => null]);
    }
};
