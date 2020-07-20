<?php  
use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_')) {
    exit;
}
class zibalpayment extends PaymentModule{  

	private $_html = '';
	private $_postErrors = array();

	public function __construct(){  

		$this->name = 'zibalpayment';  
		$this->tab = 'payments_gateways';  
		$this->version = '1.0';  
		$this->author = 'Yahya Kangi';
		$this->controllers = array('payment', 'validation');
		$this->currencies = true;
  		$this->currencies_mode = 'radio';
		
		parent::__construct();  		
		
		$this->page = basename(__FILE__, '.php');
		$this->displayName = $this->l('درگاه پرداخت زیبال');
		$this->description = $this->l('پرداخت آنلاین توسط درگاه پرداخت زیبال');  
		$this->confirmUninstall = $this->l('شما از حذف این ماژول مطمئن هستید ؟');

		if (!sizeof(Currency::checkPaymentCurrencies($this->id)))
			$this->warning = $this->l('ارز تنظیم نشده است');

		$config = Configuration::getMultiple(array('ZIBAL_MERCHANT', ''));			
		if (!isset($config['ZIBAL_MERCHANT']))
			$this->warning = $this->l('شما باید شناسه درگاه (مرچنت) خود را تنظیم کرده باشید');	

	}  
	public function install(){
		 return parent::install()
            && $this->registerHook('paymentOptions')
            && $this->registerHook('paymentReturn')
        ;
	}
	public function uninstall(){
		return Configuration::deleteByName('ZIBAL_MERCHANT')
            && Configuration::deleteByName('ZIBAL_HASHKEY')
            && parent::uninstall()
        ;
	}
	
	public function displayFormSettings()
	{
		$bank_id = Configuration::get('ZIBAL_HASHKEY');
		
		$this->_html .= '
		<form action="'.$_SERVER['REQUEST_URI'].'" method="post">
			<fieldset>
				<legend><img src="../img/admin/cog.gif" alt="" class="middle" />'.$this->l('Settings').'</legend>
				<label>'.$this->l(' شناسه درگاه (مرچنت) - برای تست از zibal استفاده کنید ').'</label>
				<div class="margin-form"><input type="text" size="30" name="ZIBALMERCHANT" value="'.Configuration::get('ZIBAL_MERCHANT').'" /></div>
				
				<p class="hint clear" style="display: block; width: 501px;"><a href="http://zibal.ir" target="_blank" >'.$this->l('زیبال').'</a></p></div>
				<center><input type="submit" name="submitZIBAL" value="'.$this->l('به روز رسانی تنظیمات').'" class="button" /></center>			
			</fieldset>
		</form>';
	}

	public function displayConf()
	{
		$this->_html .= '
		<div class="conf confirm">
			<img src="../img/admin/arrow2.gif" alt="'.$this->l('Confirmation').'" />
			'.$this->l('Settings updated').'
		</div>';
	}
	
	public function displayErrors()
	{
		foreach ($this->_postErrors AS $err)
		$this->_html .= '<div class="alert error">'. $err .'</div>';
	}

       	public function getContent()
	{
		$this->_html = '<h2>'.$this->l('zibalpayment').'</h2>';
		if (isset($_POST['submitZIBAL']))
		{
			if (empty($_POST['ZIBALMERCHANT']))
				$this->_postErrors[] = $this->l('مرچنت را وارد کنید');
			if (empty($_POST['HashKey']))
				 $_POST['HashKey'] = '0';
			if (!sizeof($this->_postErrors))
			{
				Configuration::updateValue('ZIBAL_MERCHANT', $_POST['ZIBALMERCHANT']);
				Configuration::updateValue('ZIBAL_HASHKEY', $_POST['HashKey']);
				$this->displayConf();
			}
			else
				$this->displayErrors();
		}

		$this->displayFormSettings();
		return $this->_html;
	}

	private function displayZibalPayment()
	{
		$this->_html .= '<img src="../modules/zibalpayment/zibal.png" style="float:left; margin-right:15px;"><b>'.$this->l('این ماژول امکان واریز آنلاین توسط درگاه شرکت زیبال را مهیا می سازد');

	}

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

	public function execPayment($cart)
	{
		global $cookie, $smarty;
	
		$purchase_currency = new Currency(Currency::getIdByIsoCode('IRR'));			
		$OrderDesc = Configuration::get('PS_SHOP_NAME'). $this->l(' Order');
		$current_currency = new Currency($this->context->cookie->id_currency);
		
		if($current_currency->id == $purchase_currency->id)
			$PurchaseAmount= number_format($cart->getOrderTotal(true, 3), 0, '', '');		 
		else
			$PurchaseAmount= number_format(Tools::convertPrice($cart->getOrderTotal(true, 3), $purchase_currency), 0, '', '');	 

		$terminal_id = Configuration::get('ZIBAL_MERCHANT');			
		$OrderId = $cart->id;
		$bank_id = Configuration::get('ZIBAL_HASHKEY');
		$amount = $PurchaseAmount;
		$redirect_url = $this->context->link->getModuleLink($this->name, 'validation', array(), true);

		if($purchase_currency->iso_code == 'IRR') {
			$amount = $PurchaseAmount;
		} else {
			$amount = $PurchaseAmount * 10;
		}
		
		@session_start();
		$_SESSION['paymentId'] = $OrderId;

		$data = array(
			'merchant' 	  => $terminal_id,
			'amount'   	  => $amount,
			'callbackUrl' => $redirect_url.'?paid='.$OrderId,
		);

		$result = $this->postToZibal('request', $data);
		$result = (array)$result;

		if (isset($result) && isset($result['result']) && $result['result'] == 100) {
			$hash = Configuration::get('zibal_prestashop_HASH');
			$_SESSION['order' . $OrderId] = md5($OrderId . $amount . $hash);
			$token = $result['trackId'];
			$_SESSION['token'] = $token;

			echo $this->success($this->l('در حال ارجاع به درگاه پرداخت ...'));
			echo '<script>window.location=("https://gateway.zibal.ir/start/' . $result['trackId'] . '");</script>';
		} elseif (isset($result) && isset($result['result']) && $result['result'] != 100) {
			echo $this->error($this->l('مشکلی در پرداخت وجود دارد.') . ' (' . $this->resultCodes($result['result']) . ')');
		} else {
			echo $this->error($this->l('مشکلی در پرداخت وجود دارد.'));
		}
		

	}

	public function success($str) {
		echo '<div class="conf confirm">' . $str . '</div>';
	}

	public function error($str) {
		return '<div class="alert error">' . $str . '</div>';
		die();
	}


	public function confirmPayment($order_amount,$Status,$Refnumber)
	{
		
	}
	public function hookPaymentOptions()
	{
		if (!$this->active) {
			return;
		}

 $this->smarty->assign(
            $this->getTemplateVars()
        );
		$newOption = new PaymentOption();
		$newOption->setCallToActionText($this->trans($this->displayName, array(), 'Modules.zibalpayment.Shop'))
					  ->setAction($this->context->link->getModuleLink($this->name, 'payment', array(), true))
					  ->setAdditionalInformation($this->fetch('module:zibalpayment/payment_info.tpl'));
		$payment_options = array($newOption);

	  return $payment_options;

	}

	public function getTemplateVars()
    {
        $cart = $this->context->cart;
        $total = $this->trans(
            '%amount% (tax incl.)',
            array(
                '%amount%' => Tools::displayPrice($cart->getOrderTotal(true, Cart::BOTH)),
            ),
            'Modules.zibalpayment.Admin'
        );

        $checkOrder = Configuration::get('CHEQUE_NAME');
        if (!$checkOrder) {
            $checkOrder = '___________';
        }

        $checkAddress = Tools::nl2br(Configuration::get('CHEQUE_ADDRESS'));
        if (!$checkAddress) {
            $checkAddress = '___________';
        }

        return array(
            'checkTotal' => $total,
            'checkOrder' => $checkOrder,
            'checkAddress' => $checkAddress,
        );
    }


}