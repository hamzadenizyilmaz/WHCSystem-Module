<?php
declare(strict_types=1);

namespace WISECP\Modules\Servers;

use ServerModule;
use WHCSystem\Module\ApiClient;
use WHCSystem\Module\ApiError;
use WHCSystem\Module\Panel;
use WHCSystem\Module\Provisioning;

require_once __DIR__ . '/lib/Panel.php';
require_once __DIR__ . '/lib/Provisioning.php';

/** Current documented ServerModule surface; deployment compatibility needs the actual WISECP SDK. */
class WHCSystem extends ServerModule
{
    private ?ApiClient $whcApi = null;

    protected function define_server_info(array $server = []): void
    {
        $hostname = trim((string) ($server['hostname'] ?? ''));
        if (!str_contains($hostname, '://')) $hostname = 'https://' . $hostname;
        $this->whcApi = new ApiClient($hostname, (string) ($server['username'] ?? ''), (string) ($server['password'] ?? ''));
    }

    public function test_connect(): array|bool
    {
        $this->api()->request('GET', '/wisecp/status');
        return true;
    }

    public function configure_features(): void
    {
        $this->add_tool('whcsystem', ['name'=>'WHCSystem v7.0.0','group'=>'system','order'=>1,
            'page'=>'whcsystem','type'=>'page-loader','supported'=>true,'capabilities'=>['list','dispatch']]);
    }

    protected function get_tool_action_data_filters(): array
    {
        $filters = parent::get_tool_action_data_filters();
        $filters['whcsystem'] = ['mode'=>'identifier','resource'=>'identifier','secret_payload'=>null,'csrf_token'=>'identifier','key'=>'identifier'];
        return $filters;
    }

    protected function get_tool_action_rules(): array
    {
        $rules = parent::get_tool_action_rules();
        $rules['whcsystem'] = ['dispatch'=>['mode','resource','csrf_token']];
        return $rules;
    }

    public function tool_data(string $tool, string $action = 'index', array $params = []): array
    {
        if ($tool !== 'whcsystem') throw new \RuntimeException('TOOL_UNAVAILABLE');
        $page = Panel::page(is_string($_GET['whcPage'] ?? null) ? $_GET['whcPage'] : 'overview');
        $config = $this->panelConfig();
        try { $config['initialData'] = $this->panel()->read('overview')['data'] ?? []; }
        catch (ApiError $error) { $page = 'unavailable'; }
        return ['panelHtml'=>Panel::render('wisecp',$page,$config)];
    }

    public function tool_action(string $tool, string $action, array $data = []): array
    {
        if ($tool !== 'whcsystem' || $action !== 'dispatch') throw new \RuntimeException('TOOL_UNAVAILABLE');
        if (strlen((string) ($data['secret_payload'] ?? '')) > 65536) throw new \RuntimeException('PAYLOAD_TOO_LARGE');
        $payload = json_decode((string) ($data['secret_payload'] ?? '{}'),true,32,JSON_THROW_ON_ERROR);
        if (!is_array($payload)) throw new \RuntimeException('VALIDATION_FAILED');
        $csrf = (string) ($data['csrf_token'] ?? '');
        if (($data['resource']??'')==='admin-test') {
            $admin=$this->adminIdentity();
            if (($data['mode']??'')!=='mutate' || !hash_equals(Panel::csrf('wisecp-admin-'.$admin,$this->serviceIdentity()),$csrf))
                throw new \RuntimeException('CSRF_INVALID');
            $this->test_connect();
            return ['completed'=>true];
        }
        if (!hash_equals(Panel::csrf('wisecp',$this->serviceIdentity()),$csrf)) throw new \RuntimeException('CSRF_INVALID');
        try {
            return match ($data['mode'] ?? '') {
                'read' => $this->panel()->read((string) ($data['resource'] ?? ''),$payload),
                'mutate' => $this->panel()->mutate((string) ($data['resource'] ?? ''),$payload,$csrf,(string) ($data['key'] ?? '')),
                default => throw new \RuntimeException('VALIDATION_FAILED'),
            };
        } catch (ApiError $error) { throw new \RuntimeException($error->errorCode); }
    }

    public function dashboard_data(): array
    {
        $vm = $this->panel()->read('overview')['data'] ?? [];
        return ['whcsystem'=>$vm];
    }

    /** Called only by the native admin-view tool template. */
    public function admin_tool_html(): string
    {
        $admin=$this->adminIdentity();
        $page=$_GET['whcPage']??'adminservice';
        if(!in_array($page,['settings','mapping','adminservice','logs','logdetail'],true))$page='adminservice';
        $config=$this->panelConfig();
        $vm=[];
        try{$vm=$this->panel()->read('overview')['data']??[];}catch(ApiError){}
        $config['admin']=true;
        $config['initialData']=$vm;
        $config['canTest']=true;
        $config['canConfigure']=false;
        $config['canMap']=false;
        $config['canCommand']=false;
        $config['adminCsrf']=Panel::csrf('wisecp-admin-'.$admin,$this->serviceIdentity());
        $config['adminValues']=['Sunucu adı'=>$this->server['hostname']??'—','API token'=>'JWT','TLS doğrulama'=>'TLS ✓',
            'Süre aşımı'=>'30 s','Provider'=>$vm['providerName']??'—','Bağlantı durumu'=>$vm?'✓':'—',
            'Ürün / paket'=>$this->product['name']??'—','WHC planı'=>$this->options['creation_info']['whcPlan']??'—',
            'Backup / snapshot'=>(string)($vm['snapshotLimit']??'—'),'Disk genişletme'=>isset($vm['maxDiskBytes'])?round($vm['maxDiskBytes']/1073741824).' GB':'—'];
        return Panel::render('wisecp',$page,$config,true);
    }

    private function adminIdentity(): int
    {
        $id=(int)(\UserManager::LoginData('admin')['id']??0);
        if (!$id || !\Admin::isPrivilege(['PRODUCTS_OPERATION'])) throw new \RuntimeException('AUTHORIZATION_DENIED');
        return $id;
    }

    public function product_configuration(array $data = []): array
    {
        return ['whcPlan'=>['name'=>'WHCSystem plan','type'=>'text','value'=>(string) ($data['whcPlan'] ?? '')]];
    }

    public function save_product_configuration(array &$values): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',(string) ($values['whcPlan'] ?? ''))) throw new \RuntimeException('VALIDATION_FAILED');
    }

    public function create(): array|bool
    {
        $request=['serviceId'=>$this->serviceIdentity(),'customerId'=>$this->customerIdentity(),
            'productId'=>(string)($this->options['creation_info']['whcPlan']??''),'hostname'=>(string)($this->options['domain']??''),
            'username'=>(string)(($this->options['login']['username']??'')?:'root')];
        $password=$this->options['login']['password']??'';
        if($password!=='')$request['password']=$this->decode_str($password);
        $p=new Provisioning($this->api(),'wisecp');$created=$p->create($request);$result=$p->status($this->serviceIdentity(),$this->customerIdentity());
        if(isset($created['data']['id'])&&($result['data']['id']??null)!==$created['data']['id'])throw new \RuntimeException('OPERATION_SUPERSEDED');
        if(in_array($result['data']['state']??'',['FAILED','FAILED_REQUIRES_ATTENTION','CANCELLED'],true))throw new \RuntimeException('PROVISIONING_FAILED');
        return ['status'=>($result['verified']??false)===true?'active':'inprocess','config'=>['service_id'=>$this->serviceIdentity(),'operation_id'=>(string)($result['data']['id']??'')]];
    }
    public function suspend(): bool { return $this->lifecycle('suspend'); }
    public function unsuspend(): bool { return $this->lifecycle('unsuspend'); }
    public function cancel(): bool { return $this->lifecycle('terminate'); }
    public function upgrade(array $new_product = []): bool
    {
        $p=new Provisioning($this->api(),'wisecp');$queued=$p->package($this->serviceIdentity(),$this->customerIdentity(),(string)($new_product['creation_info']['whcPlan']??$this->options['creation_info']['whcPlan']??''));
        $p->wait($this->serviceIdentity(),$this->customerIdentity(),45,$queued['data']['id']??null);return true;
    }
    private function lifecycle(string $action): bool
    {
        $p=new Provisioning($this->api(),'wisecp');$queued=$p->lifecycle($this->serviceIdentity(),$this->customerIdentity(),$action);
        $p->wait($this->serviceIdentity(),$this->customerIdentity(),45,$queued['data']['id']??null);return true;
    }

    private function api(): ApiClient
    {
        return $this->whcApi ?? throw new \RuntimeException('CONNECTION_CONFIGURATION_INVALID');
    }

    private function serviceIdentity(): string { return (string) ($this->service['id'] ?? ''); }
    private function customerIdentity(): string { return (string) ($this->user['id'] ?? ''); }
    private function panel(): Panel
    {
        return new Panel($this->api(),'wisecp',$this->serviceIdentity(),$this->customerIdentity(),
            strtolower((string) ($this->service['status'] ?? '')) === 'active');
    }

    private function panelConfig(): array
    {
        $code = strtolower((string) ($this->lang['locale'] ?? 'en'));
        $language = in_array($code,['tr','en','de'],true) ? $code : 'en';
        return ['assetBase'=>rtrim((string) $this->url,'/') . '/assets','endpoint'=>'',
            'transport'=>'wisecp-tool','serviceId'=>$this->serviceIdentity(),'language'=>$language,'theme'=>'auto',
            'snapshotId'=>is_string($_GET['whcSnapshot']??null)?substr($_GET['whcSnapshot'],0,256):'',
            'recordId'=>is_string($_GET['whcRecord']??null)?substr($_GET['whcRecord'],0,64):'',
            'operationId'=>is_string($_GET['whcOperation']??null)?substr($_GET['whcOperation'],0,64):'',
            'csrf'=>Panel::csrf('wisecp',$this->serviceIdentity()),
            'billing'=>['productName'=>(string) ($this->product['name'] ?? ''),'domain'=>(string) ($this->options['domain'] ?? ''),
                'username'=>(string) ($this->options['login']['username'] ?? ''),'primaryIp'=>(string) ($this->options['ip'] ?? ''),
                'nextDueDate'=>(string) ($this->service['due_date'] ?? ''),'amount'=>(string) ($this->service['total'] ?? '')]];
    }
}
