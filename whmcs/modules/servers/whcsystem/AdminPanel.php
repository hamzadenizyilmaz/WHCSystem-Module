<?php
declare(strict_types=1);

use WHCSystem\Module\Panel;
use WHCSystem\Module\ApiError;

function whcsystem_admin_actor(): object
{
    if (!class_exists(\WHMCS\Authentication\CurrentUser::class)) throw new ApiError(403,'PLATFORM_AUTHORIZATION_UNAVAILABLE');
    $actor = new \WHMCS\Authentication\CurrentUser();
    $admin = $actor->admin();
    if (!$actor->isAuthenticatedAdmin() || $actor->isMasqueradingAdmin() || !$admin || !$admin->hasPermission('View Clients Products/Services'))
        throw new ApiError(403,'AUTHORIZATION_DENIED');
    return $admin;
}

function whcsystem_admin_render(array $params): string
{
    $admin = whcsystem_admin_actor();
    $id = (string)$params['serviceid'];
    $token = Panel::csrf('whmcs-admin-'.(int)$admin->id,$id);
    $base = rtrim((string)\WHMCS\Config\Setting::getValue('SystemURL'),'/').'/modules/servers/whcsystem';
    $page = $_GET['whcPage'] ?? 'adminservice';
    if (!in_array($page,['adminservice','settings','mapping','logs','logdetail'],true)) $page='adminservice';
    $vm=[]; $plans=[];
    try { $vm=whcsystem_panel($params)->read('overview')['data']??[]; } catch (ApiError) {}
    if ($page==='mapping') {
        try { $plans=whcsystem_api($params)->request('GET','/whmcs/products')['data']??[]; } catch (ApiError) {}
    }
    $canConfigure=$admin->hasPermission('Configure Servers');
    $values=['Sunucu adı'=>(string)($params['serverhostname']??''),'TLS doğrulama'=>'TLS ✓','Süre aşımı'=>'30 s',
        'API token'=>'JWT','Webhook secret'=>'—','Provider'=>$vm['providerName']??'—','Bağlantı durumu'=>$vm?'✓':'—',
        'Ürün / paket'=>(string)($params['productname']??$params['pid']??''),'WHC planı'=>(string)($params['configoption1']??''),
        'Konsol izni'=>!empty($vm['capabilities']['console'])?'✓':'—','DNS / PTR'=>!empty($vm['capabilities']['rdns'])?'PTR':'—',
        'Backup / snapshot'=>(string)($vm['snapshotLimit']??'—'),'Disk genişletme'=>isset($vm['maxDiskBytes'])?round($vm['maxDiskBytes']/1073741824).' GB':'—'];
    if ($canConfigure && !empty($params['serverhostname'])) {
        $hostname=(string)$params['serverhostname']; if (!str_contains($hostname,'://')) $hostname='https://'.$hostname;
        $values['API URL']=rtrim($hostname,'/').(str_ends_with(rtrim($hostname,'/'),'/whcapi/v3')?'':'/whcapi/v3');
    }
    $language=['turkish'=>'tr','english'=>'en','german'=>'de'][strtolower((string)($admin->language??'english'))]??'en';
    return Panel::render('whmcs',$page,['admin'=>true,'assetBase'=>$base.'/assets','endpoint'=>$base.'/admin.php',
        'theme'=>'auto','language'=>$language,'serviceId'=>$id,'csrf'=>$token,'requestFields'=>['token'=>generate_token('plain')],
        'initialData'=>$vm,'adminValues'=>$values,'plans'=>array_column($plans,'externalProductId'),
        'canCommand'=>$admin->hasPermission('Perform Module Command Operations'),
        'canMap'=>$admin->hasPermission('Configure Products/Services'),'canConfigure'=>$canConfigure,
        'nativeServerId'=>$canConfigure?(int)($params['serverid']??0):0,
        'operationId'=>is_string($_GET['whcOperation']??null)?substr($_GET['whcOperation'],0,64):''],true);
}
