<?php
declare(strict_types=1);
use WHCSystem\Module\ApiError;
use WHCSystem\Module\Panel;
use WHMCS\Database\Capsule;

require_once dirname(__DIR__,3).'/init.php';
require_once __DIR__.'/whcsystem.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
try {
    if (($_SERVER['REQUEST_METHOD']??'')!=='POST') throw new ApiError(405,'METHOD_NOT_ALLOWED');
    if ((int)($_SERVER['CONTENT_LENGTH']??0)>65536) throw new ApiError(413,'PAYLOAD_TOO_LARGE');
    $admin=whcsystem_admin_actor();
    check_token('WHMCS.default');
    $service=filter_var($_POST['serviceId']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if (!$service) throw new ApiError(400,'VALIDATION_FAILED');
    $csrf=Panel::csrf('whmcs-admin-'.(int)$admin->id,(string)$service);
    if (!hash_equals($csrf,(string)($_POST['csrf']??''))) throw new ApiError(403,'CSRF_INVALID');
    $row=Capsule::table('tblhosting as h')->join('tblproducts as p','p.id','=','h.packageid')->where('h.id',$service)
        ->where('p.servertype','whcsystem')->select(['h.id','h.userid','h.packageid'])->first();
    if (!$row) throw new ApiError(404,'RESOURCE_NOT_FOUND');
    require_once ROOTDIR.'/includes/modulefunctions.php';
    $params=ModuleBuildParams($service);
    if (!is_array($params)||(int)$params['userid']!==(int)$row->userid) throw new ApiError(404,'RESOURCE_NOT_FOUND');
    $data=json_decode((string)($_POST['data']??'{}'),true,32,JSON_THROW_ON_ERROR);
    $resource=(string)($_POST['resource']??'');
    if (($_POST['mode']??'')==='read') {
        if (!in_array($resource,['overview','operations','operation'],true)) throw new ApiError(400,'VALIDATION_FAILED');
        $result=whcsystem_panel($params)->read($resource,$data);
    } elseif (($_POST['mode']??'')==='mutate' && $resource==='admin-test') {
        if (!$admin->hasPermission('Configure Servers')) throw new ApiError(403,'AUTHORIZATION_DENIED');
        whcsystem_api($params)->request('GET','/whmcs/status');
        $result=['completed'=>true];
    } elseif (($_POST['mode']??'')==='mutate' && $resource==='admin-map') {
        if (!$admin->hasPermission('Configure Products/Services')) throw new ApiError(403,'AUTHORIZATION_DENIED');
        if (($data['confirm']??false)!==true) throw new ApiError(400,'CONFIRMATION_REQUIRED');
        $plans=whcsystem_api($params)->request('GET','/whmcs/products')['data']??[];
        $plan=$data['adminPlan']??null;
        if (!is_string($plan)||!in_array($plan,array_column($plans,'externalProductId'),true)) throw new ApiError(400,'VALIDATION_FAILED');
        Capsule::table('tblproducts')->where('id',(int)$row->packageid)->where('servertype','whcsystem')->update(['configoption1'=>$plan]);
        if(function_exists('logActivity'))logActivity('WHCSystem product mapping updated for product #'.(int)$row->packageid);
        $result=['completed'=>true];
    } else {
        $commands=['admin-create'=>'ModuleCreate','admin-suspend'=>'ModuleSuspend','admin-unsuspend'=>'ModuleUnsuspend','admin-terminate'=>'ModuleTerminate','admin-package'=>'ModuleChangePackage'];
        if (($_POST['mode']??'')!=='mutate'||!isset($commands[$resource])) throw new ApiError(400,'VALIDATION_FAILED');
        if (!$admin->hasPermission('Perform Module Command Operations')) throw new ApiError(403,'AUTHORIZATION_DENIED');
        if (($data['confirm']??false)!==true) throw new ApiError(400,'CONFIRMATION_REQUIRED');
        $response=localAPI($commands[$resource],['serviceid'=>$service],(string)$admin->username);
        if (($response['result']??'')!=='success') throw new ApiError(422,'NATIVE_MODULE_COMMAND_FAILED');
        $result=['completed'=>true];
    }
    echo json_encode($result,JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code($error instanceof ApiError?$error->status:500);
    echo json_encode(['code'=>$error instanceof ApiError?$error->errorCode:'MODULE_REQUEST_FAILED']);
}
