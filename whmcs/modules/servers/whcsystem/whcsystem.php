<?php
declare(strict_types=1);

use WHCSystem\Module\ApiClient;
use WHCSystem\Module\ApiError;
use WHCSystem\Module\Panel;
use WHCSystem\Module\Provisioning;

if (!defined('WHMCS')) { http_response_code(403); return; }
require_once __DIR__ . '/lib/Panel.php';
require_once __DIR__ . '/lib/Provisioning.php';
require_once __DIR__ . '/ClientAccess.php';
require_once __DIR__ . '/AdminPanel.php';

function whcsystem_MetaData(): array
{
    return ['DisplayName'=>'WHCSystem v7.0.0','APIVersion'=>'1.1','RequiresServer'=>true,'DefaultSSLPort'=>'443'];
}

function whcsystem_ConfigOptions(): array
{
    return ['WHC plan'=>['Type'=>'text','Size'=>'40','Description'=>'WHCSystemAPI module product mapping ID']];
}

function whcsystem_api(array $params): ApiClient
{
    $hostname = trim((string) ($params['serverhostname'] ?? ''));
    if (!str_contains($hostname, '://')) $hostname = 'https://' . $hostname;
    return new ApiClient($hostname, (string) ($params['serverusername'] ?? ''), (string) ($params['serverpassword'] ?? ''));
}

function whcsystem_panel(array $params): Panel
{
    return new Panel(whcsystem_api($params), 'whmcs', (string) $params['serviceid'], (string) $params['userid'],
        strtolower((string) ($params['status'] ?? '')) === 'active');
}

function whcsystem_TestConnection(array $params): array
{
    try { whcsystem_api($params)->request('GET','/whmcs/status'); return ['success'=>true,'error'=>'']; }
    catch (Throwable $error) { return ['success'=>false,'error'=>$error instanceof ApiError ? $error->errorCode : 'CONNECTION_CONFIGURATION_INVALID']; }
}

function whcsystem_ClientArea(array $params): array
{
    $page = Panel::page(is_string($_GET['whcPage'] ?? null) ? $_GET['whcPage'] : 'overview');
    $language = strtolower((string) ($params['clientsdetails']['language'] ?? 'turkish'));
    $language = ['turkish'=>'tr','english'=>'en','german'=>'de'][$language] ?? 'en';
    $config = ['assetBase'=>'modules/servers/whcsystem/assets','endpoint'=>'modules/servers/whcsystem/ajax.php',
        'serviceId'=>(string) $params['serviceid'],'theme'=>'auto','language'=>$language,
        'csrf'=>Panel::csrf('whmcs',(string) $params['serviceid']),
        'requestFields'=>['token'=>function_exists('generate_token') ? generate_token('plain') : ''],
        'billing'=>['productName'=>(string) ($params['productname'] ?? ''),'domain'=>(string) ($params['domain'] ?? ''),
            'username'=>(string) ($params['username'] ?? ''),'primaryIp'=>(string) ($params['dedicatedip'] ?? ''),
            'nextDueDate'=>(string) ($params['nextduedate'] ?? ''),'amount'=>(string) ($params['amount'] ?? '')],
        'snapshotId'=>is_string($_GET['whcSnapshot'] ?? null) ? substr($_GET['whcSnapshot'],0,256) : '',
        'operationId'=>is_string($_GET['whcOperation'] ?? null) ? substr($_GET['whcOperation'],0,64) : ''];
    $config['recordId']=is_string($_GET['whcRecord']??null)?substr($_GET['whcRecord'],0,64):'';
    try {
        if (whcsystem_client_identity(false) !== (int)$params['userid']) throw new ApiError(403,'AUTHORIZATION_DENIED');
        $config['initialData'] = whcsystem_panel($params)->read('overview')['data'] ?? [];
    }
    catch (Throwable $error) { $page = 'unavailable'; $config['initialData'] = []; }
    return ['tabOverviewReplacementTemplate'=>'templates/clientarea.tpl','templateVariables'=>['whcPanelHtml'=>Panel::render('whmcs',$page,$config)]];
}

function whcsystem_AdminServicesTabFields(array $params): array
{
    try {
        return ['WHCSystem v7.0.0'=>whcsystem_admin_render($params)];
    } catch (Throwable $error) { return ['WHCSystem'=>'SERVICE_BINDING_OR_CONNECTION_UNAVAILABLE']; }
}

function whcsystem_CreateAccount(array $params): string
{
    try {
        $provision=new Provisioning(whcsystem_api($params),'whmcs');
        $request=['serviceId'=>(string)$params['serviceid'],'customerId'=>(string)$params['userid'],
            'productId'=>(string)($params['configoption1']?:($params['pid']??'')),'hostname'=>(string)($params['domain']??''),
            'username'=>(string)(($params['username']??'')?:'root')];
        if(!empty($params['password']))$request['password']=(string)$params['password'];
        $created=$provision->create($request);$provision->wait((string)$params['serviceid'],(string)$params['userid'],45,$created['data']['id']??null);return 'success';
    }catch(Throwable $error){return $error instanceof ApiError?$error->errorCode:'MODULE_REQUEST_FAILED';}
}
function whcsystem_TerminateAccount(array $params): string { return whcsystem_lifecycle($params,'terminate'); }
function whcsystem_ChangePackage(array $params): string
{
    try{$p=new Provisioning(whcsystem_api($params),'whmcs');$queued=$p->package((string)$params['serviceid'],(string)$params['userid'],(string)(($params['configoption1']??'')?:($params['pid']??'')));
        $p->wait((string)$params['serviceid'],(string)$params['userid'],45,$queued['data']['id']??null);return 'success';
    }catch(Throwable $error){return $error instanceof ApiError?$error->errorCode:'MODULE_REQUEST_FAILED';}
}

function whcsystem_SuspendAccount(array $params): string { return whcsystem_lifecycle($params, 'suspend'); }
function whcsystem_UnsuspendAccount(array $params): string { return whcsystem_lifecycle($params, 'unsuspend'); }
function whcsystem_lifecycle(array $params, string $action): string
{
    try{$p=new Provisioning(whcsystem_api($params),'whmcs');$queued=$p->lifecycle((string)$params['serviceid'],(string)$params['userid'],$action);
        $p->wait((string)$params['serviceid'],(string)$params['userid'],45,$queued['data']['id']??null);return 'success';
    }catch(Throwable $error){return $error instanceof ApiError?$error->errorCode:'MODULE_REQUEST_FAILED';}
}
