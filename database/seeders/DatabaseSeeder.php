<?php

namespace Database\Seeders;

use App\Models\DocumentSource;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        foreach ([['name' => 'Lex.uz', 'slug' => 'lex', 'type' => 'scraper', 'base_url' => 'https://lex.uz'], ['name' => 'Manual', 'slug' => 'manual', 'type' => 'manual', 'base_url' => null]] as $source) {
            DocumentSource::firstOrCreate(['slug' => $source['slug']], $source + ['is_active' => true]);
        }
    }
}
