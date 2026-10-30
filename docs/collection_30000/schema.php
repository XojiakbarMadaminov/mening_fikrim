<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::selectOne('SELECT current_database() AS name')->name !== 'mening_fikrim_active') {
    throw new RuntimeException('Fixed development database required.');
}
$schema = ['observed_at' => now()->toAtomString(), 'database' => 'mening_fikrim_active', 'tables' => [], 'migrations' => DB::table('migrations')->orderBy('id')->get()->toArray()];
$text = "# Actual database schema\n\nGenerated from local PostgreSQL introspection and applied Laravel migrations at ".$schema['observed_at'].". Database: mening_fikrim_active. Authorized 30,000 collection adds migration000013 changing campaign CHECK constraints; no new table or column. No users/auth table exists; reporting uses a read-only Laravel request guard. No section/chunk/vector tables exist.\n\n";
foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename") as $table) {
    $name = $table->tablename;
    $columns = DB::select("SELECT column_name,data_type,udt_name,is_nullable,column_default FROM information_schema.columns WHERE table_schema='public' AND table_name=? ORDER BY ordinal_position", [$name]);
    $constraints = DB::select("SELECT conname,pg_get_constraintdef(c.oid) AS definition FROM pg_constraint c JOIN pg_class t ON t.oid=c.conrelid JOIN pg_namespace n ON n.oid=t.relnamespace WHERE n.nspname='public' AND t.relname=? ORDER BY conname", [$name]);
    $indexes = DB::select("SELECT indexname,indexdef FROM pg_indexes WHERE schemaname='public' AND tablename=? ORDER BY indexname", [$name]);
    $schema['tables'][$name] = ['columns' => $columns, 'constraints' => $constraints, 'indexes' => $indexes];
    $text .= '## '.$name."\n\n| Column | PostgreSQL type | Nullable | Default |\n|---|---|---|---|\n";
    foreach ($columns as $column) {
        $text .= '| '.$column->column_name.' | '.$column->data_type.' ('.$column->udt_name.') | '.$column->is_nullable.' | '.str_replace('|', '\\|', $column->column_default ?? '—')." |\n";
    }
    $text .= "\nConstraints and indexes (actual definitions):\n\n```sql\n";
    foreach ($constraints as $constraint) {
        $text .= $constraint->conname.': '.$constraint->definition."\n";
    }
    foreach ($indexes as $index) {
        $text .= $index->indexdef.";\n";
    }
    $text .= "```\n\n";
}
$text .= "## Applied migrations\n\n";
foreach ($schema['migrations'] as $migration) {
    $text .= '- `'.$migration->migration.'` (batch '.$migration->batch.")\n";
}
file_put_contents(__DIR__.'/schema.json', json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
file_put_contents(dirname(__DIR__).'/DATABASE_SCHEMA.md', $text);
echo 'Documented '.count($schema['tables']).' actual tables / '.count($schema['migrations'])." applied migrations.\n";
