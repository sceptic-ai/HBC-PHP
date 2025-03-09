<?php
ini_set ( 'display_errors', 1 );
ini_set ( 'display_startup_errors', 1 );
error_reporting ( E_ALL );

define ( '__ROOT__', str_replace ( 'public', '', __DIR__ ) );
define ( '__DATA__', __ROOT__ . 'data' );

use DI\Container;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

require __DIR__ . '/../vendor/autoload.php';

$container = new Container ();
AppFactory::setContainer ( $container );

$app = AppFactory::create ();

class Config {
    function __construct () {
        $filepath = __DATA__ . DIRECTORY_SEPARATOR . 'config.json';
        if ( !is_readable ( $filepath ) )
            throw new \RuntimeException ( 'Unable to load configuration file: `$filepath`.' );
        $c = json_decode ( file_get_contents ( $filepath ) );
        if ( json_last_error () !== JSON_ERROR_NONE )
            throw new \RuntimeException ( "Couldn't decode configuration file: `$filepath`." );
        foreach ( $c as $key => $value )
            $this -> { $key } = $value;
    }
}

class Dependency {
    private $config = null;
    public $something = null;
    function __construct ( $config ) {
        $this -> config = $config;
        $this -> something = rand ( 0, 100 );
    }
}

$container -> set ( 'config', function () {
    return new Config ();
});

$container -> set ( 'dependency', function () use ( $container ) {
    return new Dependency ( $container -> get ( 'config' ) );
});

$container -> set ( 'db', function () use ( $container ) {
    $config = $container -> get ( 'config' ) -> database;
    $pdo = new PDO ( 'mysql:host=' . $config -> host . ';dbname=' . $config -> name, $config -> user, $config -> password );
    $pdo -> setAttribute ( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
    $pdo -> setAttribute ( PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC );
    $pdo -> setAttribute ( PDO::MYSQL_ATTR_INIT_COMMAND, "SET NAMES 'utf8'" );
    return $pdo;
});

//http://www.slimframework.com/docs/v4/objects/request.html
//http://www.slimframework.com/docs/v4/concepts/middleware.html
class JsonBodyParserMiddleware implements MiddlewareInterface
{
    public function process ( Request $request, RequestHandler $handler ): Response
    {
        $contentType = $request -> getHeaderLine ( 'Content-Type' );
        if ( strstr ( $contentType, 'application/json' ) ) {
            $contents = json_decode ( file_get_contents ( 'php://input' ),false );
            if ( json_last_error () === JSON_ERROR_NONE ) {
                $request = $request -> withParsedBody ( $contents );
            }
        }
        return $handler -> handle ( $request );
    }
}
$jsonBodyParserMiddleware = new JsonBodyParserMiddleware ();
$app -> add ( $jsonBodyParserMiddleware );

//http://www.slimframework.com/docs/v4/middleware/error-handling.html
//$app -> addErrorMiddleware ( true, true, true );

$app -> get ( '/', function ( Request $request, Response $response ) {
    $response -> getBody () -> write ( file_get_contents ( 'index.html' ) );
    return $response
//        -> withHeader ( 'Content-Type', 'application/json' )
        -> withStatus ( 200 );
});

$app -> get ( '/json', function ( Request $request, Response $response ) {

    $posts = [];
    $client = new MongoDB\Client(
        'mongodb://root:PASSWORD@mongodb.backend_network:27017/?retryWrites=true&w=majority&ssl=false'
    );
    $db = $client -> MyDB;
    $collection = $db -> postCollection;
    $cursor = $collection -> find ();
    foreach ( $cursor as $document )
        $posts [] = $document;
    $response -> getBody () -> write ( json_encode ( [ 'posts' => $posts ] ) );
    return $response
        -> withHeader ( 'Content-Type', 'application/json' )
        -> withStatus ( 200 );

});

//http://www.slimframework.com/docs/v4/cookbook/enable-cors.html
$app -> options ( '/{routes:.+}', function ( $request, $response, $args ) {
    return $response;
});

$app -> add ( function ( $request, $handler ) {
    $response = $handler -> handle ( $request );
    return $response
        -> withHeader ( 'Access-Control-Allow-Origin', '*' )
        -> withHeader ( 'Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization' )
        -> withHeader ( 'Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS' );
});

try {
    $app -> run ();
}
catch ( Exception $e ) {
    header ( 'Content-Type: application/json' );
    http_response_code ( 500 );
    $payload = [ 'status' => 'error', 'exception' => [ 'message' => $e -> getMessage (), 'file' => $e -> getFile () ] ];
    echo json_encode ( $payload );
} finally {
}