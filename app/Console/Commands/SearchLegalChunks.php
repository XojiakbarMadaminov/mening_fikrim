<?php

namespace App\Console\Commands;

use App\Services\Legal\AiDatabaseGuard;
use App\Services\Legal\LegalKeywordSearch;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

class SearchLegalChunks extends Command
{
    protected $signature = 'legal:search {query} {--language=} {--quality=structured} {--category=} {--page=1} {--limit=10}';

    protected $description = 'Bounded keyword retrieval from current AI DEV chunks with full source attribution';

    public function handle(AiDatabaseGuard $guard, LegalKeywordSearch $search): int
    {
        $guard->connection();
        $page = filter_var($this->option('page'), FILTER_VALIDATE_INT);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($page === false || $limit === false) {
            $this->error('page and limit must be integers.');

            return self::FAILURE;
        }
        try {
            $result = $search->search($this->argument('query'), $this->option('language'), $this->option('quality'), $this->option('category'), $page, $limit);
        } catch (QueryException $e) {
            if ((string) $e->getCode() !== '57014') {
                throw $e;
            }
            $this->error('AI keyword query exceeded the five-second budget; narrow the query or filters.');

            return self::FAILURE;
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
