<?php

namespace Rebing\GraphQL\Console;

use Illuminate\Console\Command;
use Rebing\GraphQL\GraphQL;

class RunWsServerCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'graphql:wsserver:run';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run websocket server for subscriptions';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $schema = app('graphql')->schema();
        $filters = array_get(config('graphql.schemas.'.config('graphql.default_schema')), 'subscription', []);
        GraphQL::ws($schema, $filters, '0.0.0.0', config('graphql.subscriptions_port') )
            ->run();
    }
}