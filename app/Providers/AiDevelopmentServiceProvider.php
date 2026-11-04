<?php

namespace App\Providers;

use App\Jobs\ChunkLegalDocumentJob;
use App\Services\Legal\AiDatabaseGuard;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use LogicException;

class AiDevelopmentServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! config('ai.enabled')) {
            return;
        }
        // The isolated copy must never fetch new source documents, even via direct jobs.
        $this->app->make(Factory::class)->preventStrayRequests();
        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            if (str_starts_with($event->command ?? '', 'documents:') || str_starts_with($event->command ?? '', 'schedule:')
                || in_array($event->command, ['queue:listen', 'queue:retry', 'queue:restart'], true)) {
                throw new LogicException('Parser and scheduler commands are disabled in AI DEV.');
            }
            if (str_starts_with($event->command ?? '', 'migrate')) {
                app(AiDatabaseGuard::class)->connection();
                if ($event->command !== 'migrate' || $event->input->getOption('database') !== 'pgsql_ai'
                    || $event->input->getOption('path') !== ['database/ai-migrations']) {
                    throw new LogicException('Use legal:migrate for isolated AI migrations.');
                }
            }
            if ($event->command === 'queue:work') {
                app(AiDatabaseGuard::class)->connection();
                if ($event->input->getArgument('connection') !== 'ai_database' || $event->input->getOption('queue') !== 'legal-chunking') {
                    throw new LogicException('AI DEV workers require ai_database --queue=legal-chunking.');
                }
            }
        });
        Queue::before(function ($event): void {
            app(AiDatabaseGuard::class)->connection();
            if ($event->connectionName !== 'ai_database' || $event->job->getQueue() !== 'legal-chunking'
                || ($event->job->payload()['data']['commandName'] ?? null) !== ChunkLegalDocumentJob::class) {
                throw new LogicException('Only legal chunking jobs are permitted in AI DEV.');
            }
        });
    }
}
