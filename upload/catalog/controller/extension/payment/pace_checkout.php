<?php
class ControllerExtensionPaymentPaceCheckout extends Controller
{
	public function index()
	{

		$this->load->model('setting/setting');
		$setting                                           = $this->model_setting_setting->getSetting('payment_pace_checkout');
		$data['payment_pace_checkout_widget_status']       = $setting['payment_pace_checkout_widget_status'];
		$data['payment_pace_checkout_primary_color']       = $setting['payment_pace_checkout_primary_color'];
		$data['payment_pace_checkout_second_color']        = $setting['payment_pace_checkout_second_color'];
		$data['payment_pace_checkout_text_timeline_color'] = $setting['payment_pace_checkout_text_timeline_color'];
		$data['payment_pace_checkout_background_color']    = $setting['payment_pace_checkout_background_color'];
		$data['payment_pace_checkout_foreground_color']    = $setting['payment_pace_checkout_foreground_color'];
		$data['payment_pace_checkout_fontsize']            = $setting['payment_pace_checkout_fontsize'];
		$data['price']                                     = $this->cart->getTotal();

		return $this->load->view('extension/payment/pace_checkout', $data);
	}

	public function confirm()
	{
		$errors = array();

		try {
			$this->load->model('extension/module/pace');
			$this->load->model('checkout/order');
			$this->load->model('setting/setting');
			$this->load->language('extension/payment/pace_checkout');

			if ($this->session->data['payment_method']['code'] == 'pace_checkout') {
				$data = $this->session->data;
				$result = [];
				$order = $this->model_extension_module_pace->getOrder($this->session->data['order_id']);
				$transaction = $this->model_extension_module_pace->getOrderTransaction($this->session->data['order_id']);

				if (!$transaction) {
					$result = $this->handleCreateTransaction();
					$transaction = json_decode($result, true);
					// attach order id to transaction
					$transaction['order_id'] = (int) $this->session->data['order_id'];
					
					if ( isset( $transaction['error'] ) ) {
						$errors['error'] = sprintf($this->language->get('create_transaction_error'), $transaction['correlation_id']);
						throw new \Exception("Can not create transaction");
					}

					$order_status = (int) $this->model_extension_module_pace->updateOrderStatus($transaction); /*update orders status based on Pace transaction*/
					$this->model_checkout_order->addOrderHistory($this->session->data['order_id'], $order_status);
					$this->model_extension_module_pace->insertTransaction($this->session->data['order_id'], $transaction['transactionID'], $result);
				}

				$setting                         = $this->model_setting_setting->getSetting('payment_pace_checkout');
				$transaction['pace_mode']        = $setting['payment_pace_checkout_pace_mode'];
				$transaction['redirect_success'] = $this->url->link('checkout/success');
				$transaction['redirect_failure'] = $this->url->link('checkout/failure');
			}

			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput(json_encode($transaction));
		} catch (Exception $e) {
			// throw new \Exception( $e->getMessage );
			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput(json_encode($errors));
		}
	}

	public function updateOrderStatus() {
		extract( $_GET ); /* retrieve get params */

		$this->load->model( 'extension/module/pace' );
		$statuses = $this->model_extension_module_pace->updateOrderStatus( $status );
		$_sql = sprintf( "UPDATE `%sorder` SET order_status_id=%d WHERE order_id=%d", DB_PREFIX, $statuses, $orderID );
		// do update
		$this->db->query( $_sql );
	}

	private function setCart()
	{
		$data = $this->session->data;
		return array(
			'items'		   => [],
			'amount'	   => $this->cart->getTotal() * 100,
			// 'currency'     =>  $data['currency'],
			'currency'     =>  "SGD",
			'referenceID'  => (string) $data['order_id'],
			'redirectUrls' => array(
				'success' => $this->url->link('checkout/success'),
				'failed'  => $this->url->link('checkout/failure_pace')
			)
		);
	}

	public function get_source_order_items($items, &$source)
	{

		array_walk($items, function ($item, $id) use (&$source) {
			// get WC_Product item by ID
			$source_item = array(
				'itemID' 		 => "$item[product_id]",
				'itemType'		 => 'qwerqwerqwe',
				'reference' 	 =>  "$item[product_id]",
				'name' 			 => $item['name'],
				'productUrl' 	 =>  $this->url->link('product/product', 'product_id=' . $item['product_id']),
				'imageUrl' 		 =>	$this->config->get('config_url') . "/" . $item['image'],
				'quantity' 		 => (int) $item['quantity'],
				'tags'			 => [""],
				'unitPriceCents' => (string) $item["total"]
			);
			$source['items'][] =  $source_item;
		});

		return $source;
	}

	private function handleCreateTransaction()
	{
		$cart  = $this->setCart();
		$this->get_source_order_items($this->cart->getProducts(), $cart);

		$ch = curl_init();

		$data = $this->model_extension_module_pace->getUser();


		curl_setopt($ch, CURLOPT_URL, $data['api'] . '/v1/checkouts');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($cart));

		$headers = array();
		$headers[] = 'Content-Type: text/plain';
		$headers[] = 'Authorization: Basic ' . base64_encode($data['user_name'] . ':' . $data['password']);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

		$result = curl_exec($ch);
		if (curl_errno($ch)) {
			echo 'Error:' . curl_error($ch);
		}
		curl_close($ch);

		$ch = curl_init();
		return $result;
	}
}
