<?php
declare(strict_types=1);

use WHMCS\Database\Capsule;
use WHCSystem\Module\ApiError;

require_once dirname(__DIR__, 3) . '/init.php';
require_once __DIR__ . '/whcsystem.php';

function whcsystem_response(int $status, array $data): never
{
    http_response_code($status); header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private'); header('X-Content-Type-Options: nosniff');
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') whcsystem_response(405,['code'=>'METHOD_NOT_ALLOWED']);
if (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) !== 'xmlhttprequest') whcsystem_response(400,['code'=>'VALIDATION_FAILED']);
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536) whcsystem_response(413,['code'=>'PAYLOAD_TOO_LARGE']);
$serviceId = filter_var($_POST['serviceId'] ?? '', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if (!$serviceId) whcsystem_response(400,['code'=>'VALIDATION_FAILED']);
try {
    check_token('WHMCS.default');
    $clientId = whcsystem_client_identity(($_POST['mode'] ?? '') === 'mutate');
    $hosting = Capsule::table('tblhosting as h')->join('tblproducts as p','p.id','=','h.packageid')
        ->where('h.id',(int) $serviceId)->where('h.userid',$clientId)->where('p.servertype','whcsystem')->select(['h.id'])->first();
    if ($hosting === null) whcsystem_response(404,['code'=>'RESOURCE_NOT_FOUND']);
    require_once ROOTDIR . '/includes/modulefunctions.php';
    $params = ModuleBuildParams((int) $serviceId);
    if (!is_array($params) || (int) ($params['userid'] ?? 0) !== $clientId) whcsystem_response(404,['code'=>'RESOURCE_NOT_FOUND']);
    $data = json_decode(is_string($_POST['data'] ?? null) ? $_POST['data'] : '{}',true,32,JSON_THROW_ON_ERROR);
    if (!is_array($data)) whcsystem_response(400,['code'=>'VALIDATION_FAILED']);
    $resource = is_string($_POST['resource'] ?? null) ? $_POST['resource'] : '';
    $panel = whcsystem_panel($params);
    $result = match ($_POST['mode'] ?? '') {
        'read' => $panel->read($resource,$data),
        'mutate' => $panel->mutate($resource,$data,(string) ($_POST['csrf'] ?? ''),(string) ($_POST['key'] ?? '')),
        default => throw new ApiError(400,'VALIDATION_FAILED'),
    };
    whcsystem_response(200,$result);
} catch (ApiError $error) { whcsystem_response($error->status,['code'=>$error->errorCode,'traceId'=>$error->traceId]); }
catch (JsonException|InvalidArgumentException $error) { whcsystem_response(400,['code'=>'VALIDATION_FAILED']); }
catch (Throwable $error) {
    if (function_exists('logModuleCall')) logModuleCall('whcsystem','clientArea',['serviceId'=>(int) $serviceId],get_class($error),null);
    whcsystem_response(500,['code'=>'MODULE_REQUEST_FAILED']);
}
