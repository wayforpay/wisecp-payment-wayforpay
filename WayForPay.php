<?php
// common list
use WayForPay\SDK\Collection\ProductCollection;
use WayForPay\SDK\Credential\AccountSecretTestCredential;
use WayForPay\SDK\Credential\AccountSecretCredential;
use WayForPay\SDK\Domain\Client;
use WayForPay\SDK\Domain\Product;
use WayForPay\SDK\Wizard\PurchaseWizard;
use WayForPay\SDK\Domain\MerchantTypes;


// callback receive
use WayForPay\SDK\Exception\WayForPaySDKException;
use WayForPay\SDK\Handler\ServiceUrlHandler;

require("vendor/autoload.php");

class WayForPay extends PaymentGatewayModule{
    private $wayforpay_object;

    function __construct(){
        $this->name=__CLASS__;
        parent::__construct();
    }

    public function config_fields(){
        global $lang;
        return [
            'account'                     =>[
                'name'       =>$this->lang['config-field-account'],
                'description'=>$this->lang['config-field-account-description'],
                'type'       =>"text",
                'value'      =>$this->config["settings"]["account"] ?? '',
                'placeholder'=>$this->lang['config-field-account'],
            ],
            'private_key'                 =>[
                'name'       =>$this->lang['config-field-private-key'],
                'description'=>$this->lang['config-field-private-key-description'],
                'type'       =>"password",
                'value'      =>$this->config["settings"]["private_key"] ?? '',
                'placeholder'=>$this->lang['config-field-private-key'],
            ],
            'payment_description_template'=>[
                'name'       =>$this->lang['config-field-payment-description-template'],
                'description'=>$this->lang['config-field-payment-description-template-description'],
                'type'       =>"text",
                'value'      =>$this->config["settings"]["payment_description_template"] ?? $this->lang["payment-description-template"],
                'placeholder'=>$this->lang["payment-description-template"],
            ],
            'merchant_domain'             =>[
                'name'       =>$this->lang['config-field-merchant_domain'],
                'description'=>$this->lang['config-field-merchant_domain-description'],
                'type'       =>"text",
                'value'      =>$this->config["settings"]["merchant_domain"] ?? '',
                'placeholder'=>$this->lang['config-field-merchant_domain'],
            ],
            'test_mode'                   =>[
                'name'       =>$this->lang['config-field-test_mode'],
                'description'=>$this->lang['config-field-test_mode-description'],
                'type'       =>"approval",
                'checked'    =>$this->config["settings"]["test_mode"] ?? false,
                'placeholder'=>$this->lang['config-field-test_mode'],
            ],
        ];
    }

    private function GetPrivateKey(){
        return $this->config["settings"]["private_key"] ?? "";
    }

    private function GetAccountNumber(){
        return $this->config["settings"]["account"] ?? "";
    }

    private function GetWayForPayObject(){
        if ($this->wayforpay_object == null){
            if (!$this->IsTestingMode()){
                $this->wayforpay_object=new AccountSecretCredential($this->GetAccountNumber(), $this->GetPrivateKey());
            } else {
                $this->wayforpay_object=new AccountSecretTestCredential();
            }
        }
        return $this->wayforpay_object;
    }

    private function GetPaymentDescription(){
        $template=$this->config["settings"]["payment_description_template"] ?? "Payment for order.";
        $data=[
            'checkout_id'   =>$this->checkout_id,
            'invoice_id'    =>$this->checkout['data']['invoice_id'],
            'user_phone'    =>$this->clientInfo->phone,
            'user_name'     =>$this->clientInfo->name,
            'user_surname'  =>$this->clientInfo->surname,
            'user_full_name'=>$this->clientInfo->full_name,
            'user_email'    =>$this->clientInfo->email
        ];

        $replace_array=array();
        if ($template != '' and count($data) > 0){
            foreach ($data as $data_key=>$data_val){
                $replace_key='{' . $data_key . '}';
                $replace_array[$replace_key]=$data_val;
            }
        }
        return strtr($template, $replace_array);
    }

    private function IsTestingMode(){
        return (bool) ($this->config["settings"]["test_mode"] ?? false);
    }

    public function area($params=[]){
        $merchant_domain=$this->config["settings"]["merchant_domain"];
        if ($this->IsTestingMode()) $merchant_domain='google.com';

        $form_html=PurchaseWizard::get($this->GetWayForPayObject())
            ->setOrderReference($this->checkout_id)
            ->setAmount(round($params["amount"], 2))
            ->setCurrency($this->currency($params["currency"]))
            ->setOrderDate(new \DateTime())
            ->setMerchantDomainName('https://' . $merchant_domain)
            //    ->setMerchantTransactionType(MerchantTypes::TRANSACTION_AUTO)
            //    ->setMerchantTransactionType(MerchantTypes::TRANSACTION_AUTH) //  hold
            ->setClient(new Client(
                $this->clientInfo->name,
                $this->clientInfo->surname,
                $this->clientInfo->email,
                $this->clientInfo->phone,
                $this->clientInfo->address->country_name,
            ))
            ->setProducts(new ProductCollection(array(
                new Product($this->GetPaymentDescription(), round($params["amount"], 2), 1)
            )))
            ->setReturnUrl($this->links['return'])
            ->setServiceUrl($this->links['callback'])
            ->getForm()
            ->getAsString();

        $form_html="<div style='text-align:center;'>
                        <div style='margin: 0 auto; width:25%'>{$form_html}</div>
                     </div>";
        return $form_html;
    }

    public function callback(){
        try{
            $handler=new ServiceUrlHandler($this->GetWayForPayObject());
            $response=$handler->parseRequestFromPostRaw();

            $response_data=$handler->getSuccessResponse($response->getTransaction());
            $transaction=$response->getTransaction();
            $data=json_decode($response_data, true);

        } catch (WayForPaySDKException $e){
            $this->error=$e->getMessage();
            return false;
        }

        $order_id=(int) $transaction->getOrderReference();

        if (!$order_id){
            $this->error='Order not found.';
            return false;
        }

        // Let's get the checkout information.
        $checkout=$this->get_checkout($order_id);
        // Checkout invalid error
        if (!$checkout){
            $this->error='Checkout ID unknown';
            return false;
        }

        // You introduce checkout to the system
        $this->set_checkout($checkout);

        $message_details=[
            'EMail'    =>$transaction->getEmail(),
            'Phone'    =>$transaction->getPhone(),
            'Status'   =>$transaction->getStatus(),
            'Card'     =>$transaction->getCardPan(),
            'Card Type'=>$transaction->getCardType(),
            'Bank'     =>$transaction->getIssuerBankName(),
        ];
        $message_array=array();
        foreach ($message_details as $detail_name=>$detail_type){
            $message_array[]="{$detail_name}: {$detail_type}";
        }

        switch ($transaction->getStatus()){
            case 'Approved': // Успішний платіж
                return [
                    'status' =>'successful',
                    'paid'   =>[
                        'amount'  =>$transaction->getAmount(),
                        'currency'=>$transaction->getCurrency(),
                    ],
                    'message'=>implode(' / ', $message_array)
                ];
            break;

            case 'WaitingAuthComplete':
            case 'InProcessing':
            case 'Pending': // Очікується
                return [
                    'status' =>'pending',
                    'message'=>implode(' / ', $message_array)
                ];
            break;

            case 'Expired': // Неуспішний платіж
            case 'Voided':
            case 'Declined':
                $message_array=[
                    'Error Code'=>$transaction->getReason()->getCode(),
                    'Error'     =>$transaction->getReason()->getMessage(),
                ];
                return [
                    'status' =>'error',
                    'message'=>implode(' / ', $message_array)
                ];
            break;

            default:
                $this->error='Unknown status';
                return false;
            break;
        }

        return false;
    }
}
