<?php namespace Rebing\GraphQL;

use GraphQL\Error\Error;
use Ratchet\Client;
use Ratchet\Client\WebSocket;
use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use Rebing\GraphQL\Error\ValidationError;
use GraphQL\GraphQL as GraphQLBase;
use GraphQL\Schema;
use GraphQL\Type\Definition\ObjectType;
use Rebing\GraphQL\Events\SchemaAdded;
use Rebing\GraphQL\Exception\SchemaNotFound;
use Rebing\GraphQL\Support\PaginationType;
use Rebing\GraphQL\Support\Subscription\SubscriptionManager;
use Rebing\GraphQL\Support\Subscription\SubscriptionServer;
use Session;

const INIT = 'init';
const INIT_SUCCESS = 'init_success';
const INIT_FAIL = 'init_fail';
const SUBSCRIPTION_START = 'subscription_start';
const SUBSCRIPTION_END = 'subscription_end';
const SUBSCRIPTION_SUCCESS = 'subscription_success';
const SUBSCRIPTION_FAIL = 'subscription_fail';
const SUBSCRIPTION_DATA = 'subscription_data';

class GraphQL {

    protected $app;

    protected $schemas = [];
    protected $types = [];
    protected $typesInstances = [];

    public function __construct($app)
    {
        $this->app = $app;
    }

    public function schema($schema = null)
    {
        if($schema instanceof Schema)
        {
            return $schema;
        }

        $this->typesInstances = [];
        foreach($this->types as $name => $type)
        {
            $this->type($name);
        }

        $schemaName = is_string($schema) ? $schema : config('graphql.default_schema', 'default');

        if (!is_array($schema) && !isset($this->schemas[$schemaName])) {
            throw new SchemaNotFound('Type '.$schemaName.' not found.');
        }

        $schema = is_array($schema) ? $schema:$this->schemas[$schemaName];

        $schemaQuery = array_get($schema, 'query', []);
        $schemaMutation = array_get($schema, 'mutation', []);
        $schemaSubscription = array_get($schema, 'subscription', []);
        $schemaTypes = array_get($schema, 'types', []);

        //Get the types either from the schema, or the global types.
        $types = [];
        if (sizeof($schemaTypes)) {
            foreach ($schemaTypes as $name => $type) {
                $objectType = $this->objectType($type, is_numeric($name) ? []:[
                    'name' => $name
                ]);
                $this->typesInstances[$name] = $objectType;
                $types[] = $objectType;
            }
        } else {
            foreach ($this->types as $name => $type) {
                $types[] = $this->type($name);
            }
        }

        $query = $this->objectType($schemaQuery, [
            'name' => 'Query'
        ]);

        $mutation = $this->objectType($schemaMutation, [
            'name' => 'Mutation'
        ]);
        
        $subscription = $this->objectType($schemaSubscription, [
            'name' => 'Subscription'
        ]);

        return new Schema([
            'query'         => $query,
            'mutation'      => !empty($schemaMutation) ? $mutation : null,
            'subscription'  => !empty($schemaSubscription) ? $subscription : null,
            'types'         => $types
        ]);
    }

    /**
     * @param array $opts - additional options, like 'schema', 'context' or 'operationName'
     */
    public function query($query, $params = [], $opts = [])
    {
        $executionResult = $this->queryAndReturnResult($query, $params, $opts);

        $data = [
            'data' => $executionResult->data,
        ];

        // Add errors
        if( ! empty($executionResult->errors))
        {
            $errorFormatter = config('graphql.error_formatter', ['\Rebing\GraphQL', 'formatError']);

            $data['errors'] = array_map($errorFormatter, $executionResult->errors);
        }

        return $data;
    }

    public function queryAndReturnResult($query, $params = [], $opts = [])
    {
        $context = array_get($opts, 'context');
        $schemaName = array_get($opts, 'schema');
        $operationName = array_get($opts, 'operationName');

        $schema = $this->schema($schemaName);

        $result = GraphQLBase::executeAndReturnResult($schema, $query, null, $context, $params, $operationName);
        return $result;
    }

    public function addTypes($types)
    {
        foreach ($types as $name => $type) {
            $this->addType($type, is_numeric($name) ? null:$name);
        }
    }

    public function addType($class, $name = null)
    {
        if(!$name)
        {
            $type = is_object($class) ? $class:app($class);
            $name = $type->name;
        }

        $this->types[$name] = $class;
    }

    public function type($name, $fresh = false)
    {
        if(!isset($this->types[$name]))
        {
            throw new \Exception('Type '.$name.' not found.');
        }

        if(!$fresh && isset($this->typesInstances[$name]))
        {
            return $this->typesInstances[$name];
        }

        $type = $this->types[$name];
        if(!is_object($type))
        {
            $type = app($type);
        }

        $instance = $type->toType();
        $this->typesInstances[$name] = $instance;

        return $instance;
    }

    public function objectType($type, $opts = [])
    {
        // If it's already an ObjectType, just update properties and return it.
        // If it's an array, assume it's an array of fields and build ObjectType
        // from it. Otherwise, build it from a string or an instance.
        $objectType = null;
        if ($type instanceof ObjectType) {
            $objectType = $type;
            foreach ($opts as $key => $value) {
                if (property_exists($objectType, $key)) {
                    $objectType->{$key} = $value;
                }
                if (isset($objectType->config[$key])) {
                    $objectType->config[$key] = $value;
                }
            }
        } elseif (is_array($type)) {
            $objectType = $this->buildObjectTypeFromFields($type, $opts);
        } else {
            $objectType = $this->buildObjectTypeFromClass($type, $opts);
        }

        return $objectType;
    }

    protected function buildObjectTypeFromClass($type, $opts = [])
    {
        if (!is_object($type)) {
            $type = $this->app->make($type);
        }

        foreach ($opts as $key => $value) {
            $type->{$key} = $value;
        }

        return $type->toType();
    }

    protected function buildObjectTypeFromFields($fields, $opts = [])
    {
        $typeFields = [];
        foreach ($fields as $name => $field) {
            if (is_string($field)) {
                $field = $this->app->make($field);
                $name = is_numeric($name) ? $field->name:$name;
                $field->name = $name;
                $field = $field->toArray();
            } else {
                $name = is_numeric($name) ? $field['name']:$name;
                $field['name'] = $name;
            }
            $typeFields[$name] = $field;
        }

        return new ObjectType(array_merge([
            'fields' => $typeFields
        ], $opts));
    }

    public function addSchema($name, $schema)
    {
        $this->schemas[$name] = $schema;
    }

    public function clearType($name)
    {
        if (isset($this->types[$name])) {
            unset($this->types[$name]);
        }
    }

    public function clearSchema($name)
    {
        if (isset($this->schemas[$name])) {
            unset($this->schemas[$name]);
        }
    }

    public function clearTypes()
    {
        $this->types = [];
    }

    public function clearSchemas()
    {
        $this->schemas = [];
    }

    public function getTypes()
    {
        return $this->types;
    }

    public function getSchemas()
    {
        return $this->schemas;
    }

    protected function clearTypeInstances()
    {
        $this->typesInstances = [];
    }

    protected function getTypeName($class, $name = null)
    {
        if ($name) {
            return $name;
        }

        $type = is_object($class) ? $class:$this->app->make($class);
        return $type->name;
    }

    public function paginate($typeName, $customName = null)
    {
        $name = $customName ?: $typeName . '_pagination';

        if(!isset($this->typesInstances[$name]))
        {
            $this->typesInstances[$name] = new PaginationType($typeName, $customName);
        }

        return $this->typesInstances[$name];
    }

    public static function formatError(Error $e)
    {
        $error = [
            'message' => $e->getMessage()
        ];

        $locations = $e->getLocations();
        if(!empty($locations))
        {
            $error['locations'] = array_map(function($loc)
            {
                return $loc->toArray();
            }, $locations);
        }

        $previous = $e->getPrevious();
        if($previous && $previous instanceof ValidationError)
        {
            $error['validation'] = $previous->getValidatorMessages();
        }

        return $error;
    }

    /**
     * Check if the schema expects a nest URI name and return the formatted version
     * Eg. 'user/me'
     * will open the query path /graphql/user/me
     *
     * @param $name
     * @param $schemaParameterPattern
     * @param $queryRoute
     *
     * @return mixed
     */
    public static function routeNameTransformer ($name, $schemaParameterPattern, $queryRoute) {
        $multiLevelPath = explode('/', $name);
        $routeName = null;

        if (count($multiLevelPath) > 1) {
            foreach ($multiLevelPath as $multiName) {
                $routeName = !$routeName ? null : $routeName . '/';
                $routeName =
                    $routeName
                    . preg_replace($schemaParameterPattern, '{' . $multiName . '}', $queryRoute);
            }
        }

        return $routeName ?: preg_replace($schemaParameterPattern, '{' . $name . '}', $queryRoute);
    }

    /**
     * Returns a new websocket server bootstraped for GraphQL subscriptions.
     *
     * @param Schema $schema
     * @param array  $filters
     * @param array  $rootValue
     * @param array  $context
     * @param int    $port
     * @param string $host
     *
     * @return IoServer
     */
    public static function server(
        Schema $schema,
        array $filters = null,
        array $rootValue = null,
        array $context = null,
        $port = 8080,
        $host = '0.0.0.0'
    ) {

        $manager = new SubscriptionManager($schema, $filters, $rootValue, $context);
        $server = new SubscriptionServer($manager);

        return IoServer::factory(
            new HttpServer(
                new WsServer(
                    $server
                )
            ),
            $port,
            $host
        );

    }

    /**
     * Publishes the given $payload to the $subscribeName.
     *
     * @param string $subscriptionName
     * @param mixed  $payload
     */
    public static function publish($subscriptionName, $payload = null)
    {

        //$subscriptionsEndpoint = Container\get('graphql_subscriptions_endpoint');
        $subscriptionsEndpoint = config('graphql.subscriptions_endpoint', 'ws://localhost').":".config('graphql.subscriptions_port', '8080');

        Client\connect($subscriptionsEndpoint,  ['graphql-subscriptions'])->then(function (WebSocket $conn) use ($subscriptionName, $payload) {

            $request = [
                'type'         => SUBSCRIPTION_DATA,
                'subscription' => $subscriptionName,
                'payload'      => $payload,
            ];

            $conn->send(json_encode($request));
            $conn->close();
        });

    }
}
