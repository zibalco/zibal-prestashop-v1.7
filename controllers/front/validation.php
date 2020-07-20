<?php

class zibalpaymentvalidationModuleFrontController extends ModuleFrontController
{
	public function postToZibal($path, $parameters)
	{
		$url ='https://gateway.zibal.ir/v1/'.$path;
		
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS,json_encode($parameters));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$response  = curl_exec($ch);
		curl_close($ch);
		return json_decode($response);
	}

	function statusCodes($code)
{
    switch ($code) 
    {
        case -1:
            return "در انتظار پردخت";
        
        case -2:
            return "خطای داخلی";

        case 1:
            return "پرداخت شده - تاییدشده";

        case 2:
            return "پرداخت شده - تاییدنشده";

        case 3:
            return "لغوشده توسط کاربر";
        
        case 4:
            return "‌شماره کارت نامعتبر می‌باشد";

        case 5:
            return "‌موجودی حساب کافی نمی‌باشد";

        case 6:
            return "رمز واردشده اشتباه می‌باشد";

        case 7:
            return "‌تعداد درخواست‌ها بیش از حد مجاز می‌باشد";
        
        case 8:
            return "‌تعداد پرداخت اینترنتی روزانه بیش از حد مجاز می‌باشد";

        case 9:
            return "مبلغ پرداخت اینترنتی روزانه بیش از حد مجاز می‌باشد";

        case 10:
            return "‌صادرکننده‌ی کارت نامعتبر می‌باشد";
        
        case 11:
            return "خطای سوییچ";

        case 12:
            return "کارت قابل دسترسی نمی‌باشد";

        default:
            return "وضعیت مشخص شده معتبر نیست";
    }
}

	private function resultCodes($error)
	{
		$response = '';
		switch ($error) {

			case '100':
				$response = 'با موفقیت تایید شد.';
				break;

			case '102':
				$response = 'merchant یافت نشد.';
				break;

			case '103':
				$response = 'merchant غیرفعال';
				break;

			case '104':
				$response = 'merchant نامعتبر';
				break;

			case '201':
				$response = 'قبلا تایید شده.';
				break;

			case '105':
				$response = 'amount بایستی بزرگتر از 1,000 ریال باشد.';
				break;

			case '106':
				$response = 'callbackUrl نامعتبر می‌باشد. (شروع با http و یا https';
				break;

			case '113':
				$response = 'amount مبلغ تراکنش از سقف میزان تراکنش بیشتر است.';
				break;

			case '202':
				$response = 'سفارش پرداخت نشده یا ناموفق بوده است.';
				break;

			case '203':
				$response = 'trackId نامعتبر می‌باشد.';
				break;
		}

		return $response;
	}


    public function postProcess()
    {
        $cart = $this->context->cart;

        if ($cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0 || !$this->module->active) {
            Tools::redirect('index.php?controller=order&step=1');
        }
        $authorized = false;
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] == 'zibalpayment') {
                $authorized = true;
                break;
            }
        }

        if (!$authorized) {
            die($this->trans('This payment method is not available.', array(), 'Modules.zibalpayment.Shop'));
        }

        $customer = new Customer($cart->id_customer);

        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $currency = $this->context->currency;
        $total = (float)$cart->getOrderTotal(true, Cart::BOTH);

		$statusCode = $_GET['status'];
		$trackId = isset($_GET['trackId']) ? $_GET['trackId'] : 0;
		$orderId = Tools::getValue('paid');
        $order = new Order($orderId);
		if ($statusCode == '2')
		{
			$data = array(
				'merchant' => Configuration::get('ZIBAL_MERCHANT'),
				'trackId'  => $trackId,
			);

			$result = $this->postToZibal('verify', $data);
			
			if ($result->result == 100) {

				if($currency->iso_code == 'IRR') {
					$newTotal = round(floatval($total));
				} else {
					$newTotal = round(floatval($total)) * 10;
				}

				// echo "amount: "; var_dump($result->amount);
				// echo "newTotal: "; var_dump($newTotal);
				// die();

				if(floatval($result->amount) == $newTotal && floatval($result->amount) > 0)
				{
					$this->module->validateOrder((int)$cart->id, _PS_OS_PAYMENT_, $total, $this->module->displayName, "سفارش تایید شده / کد رهگیری :".$result->refNumber,array(), (int)$currency->id, false, $customer->secure_key);
					
					Tools::redirect('index.php?controller=order-confirmation&id_cart='.(int)$cart->id.'&id_module='.(int)$this->module->id.'&id_order='.$this->module->currentOrder.'&key='.$customer->secure_key);

					$message ='پرداخت انجام شد.';
					
				}
				else
				{
					$message ='مبلغ واریزی با قیمت محصول برابر نیست';
				}
				
			}
		   
		}
		else
		{
			$message = $this->statusCodes($statusCode);
		}
		
		
		 if ((bool)Context::getContext()->customer->is_guest) {
				$url=Context::getContext()->link->getPageLink('guest-tracking', true);
			} else {
				$url=Context::getContext()->link->getPageLink('history', true);
			}
			
		 $this->context->smarty->assign([
				'message' => $message,
				'redirectUrl' => $url,
				'orderReference' => $order->reference,

			]);
			
			return $this->setTemplate('module:zibalpayment/back.tpl');
    }
	
}
