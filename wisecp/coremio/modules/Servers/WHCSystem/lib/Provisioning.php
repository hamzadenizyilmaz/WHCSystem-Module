<?php
declare(strict_types=1);
namespace WHCSystem\Module;
require_once __DIR__ . '/ApiClient.php';

/** Native billing hooks use this server-side path, which is not exposed by Panel::mutate. */
final class Provisioning
{
    public function __construct(private readonly ApiClient $api,private readonly string $platform)
    {
        if(!in_array($platform,['whmcs','wisecp'],true))throw new \InvalidArgumentException('Unknown platform');
    }
    public function create(array $request): array
    {
        return $this->api->request('POST','/'.$this->platform.'/services',$request,bin2hex(random_bytes(16)));
    }
    public function lifecycle(string $service,string $customer,string $action): array
    {
        if(!in_array($action,['suspend','unsuspend','terminate'],true))throw new \InvalidArgumentException('Invalid lifecycle action');
        return $this->api->request('POST',$this->service($service,$customer).'/lifecycle?customerId='.rawurlencode($customer),
            ['action'=>$action,'confirm'=>true],bin2hex(random_bytes(16)));
    }
    public function package(string $service,string $customer,string $product): array
    {
        return $this->api->request('POST',$this->service($service,$customer).'/package?customerId='.rawurlencode($customer),
            ['productId'=>$product],bin2hex(random_bytes(16)));
    }
    public function status(string $service,string $customer): array
    {
        return $this->api->request('GET',$this->service($service,$customer).'/lifecycle?customerId='.rawurlencode($customer));
    }
    public function wait(string $service,string $customer,int $seconds=45,?string $expectedOperation=null): array
    {
        $deadline=microtime(true)+$seconds;
        do {
            $result=$this->status($service,$customer);
            if($expectedOperation!==null&&($result['data']['id']??null)!==$expectedOperation)
                throw new ApiError(409,'OPERATION_SUPERSEDED');
            if(($result['verified']??false)===true&&($result['data']['state']??'')==='SUCCEEDED')return $result;
            if(in_array($result['data']['state']??'',['FAILED','FAILED_REQUIRES_ATTENTION','CANCELLED'],true))
                throw new ApiError(422,(string)($result['data']['errorCode']??'OPERATION_FAILED'));
            if(microtime(true)>=$deadline)break;
            usleep(500000);
        }while(true);
        throw new ApiError(504,'OPERATION_STILL_PENDING');
    }
    private function service(string $service,string $customer): string
    {
        foreach([$service,$customer] as $id)if(!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$id))throw new \InvalidArgumentException('Invalid identity');
        return '/'.$this->platform.'/services/'.rawurlencode($service);
    }
}
