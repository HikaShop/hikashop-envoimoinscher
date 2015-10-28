<?php
/**
 *
 */
class plgHikashopshippingEnvoimoinscher extends hikashopShippingPlugin
{
	var $multiple = true;
	var $name = 'envoimoinscher';
	var $doc_form = 'envoimoinscher';

	var $use_cache = true;

	// List of all carriers that envoimoinscher offers
	var $envoimoinscher_methods = array(
		array('key' => 1, 'code' => 'UPSE', 'name' => 'UPS'),
		array('key' => 2, 'code' => 'FEDX', 'name' => 'FedEx'),
		array('key' => 3, 'code' => 'CHRP', 'name' => 'Chronopost'),
		array('key' => 4, 'code' => 'TNTE', 'name' => 'TNT Express'),
		array('key' => 5, 'code' => 'SOGP', 'name' => 'Relais Colis'),
		array('key' => 6, 'code' => 'MONR', 'name' => 'Mondial Relay'),
		array('key' => 7, 'code' => 'POFR', 'name' => 'La Poste'),
		array('key' => 8 ,'code' => 'COPR', 'name' => 'Colis Privé'),
		array('key' => 9 ,'code' => 'DHLF', 'name' => 'DHL FREIGHT'),
		array('key' => 10, 'code' => 'SODX', 'name' => 'Sodexi'),
		array('key' => 11, 'code' => 'BREG', 'name' => 'Breger'),
		array('key' => 12, 'code' => 'GUIN', 'name' => 'Guisnel Distribution'),
		array('key' => 13, 'code' => 'VARI', 'name' => 'Varillon Logistique'),
		array('key' => 14, 'code' => 'TATX', 'name' => 'Tatex'),
		array('key' => 15, 'code' => 'DHLE', 'name' => 'DHL Express'),
		array('key' => 16, 'code' => 'GLSY', 'name' => 'GLS')
	);

	var $result;
	var $pickups = array();
	var $collection = false;
	var $delivery = false;
	var $lpCl;
	var $userInfo = array();

	/**
	 *  To include the library envoimoinscher
	 */
	function init() {
		static $init = null;
		if($init !== null)
			return $init;

		try {
			include_once(dirname(__FILE__) . DS . 'lib' . DS . 'envoimoinscher.php');
			$init = true;
		} catch(Exception $e) {
			$app = JFactory::getApplication();
			if($app->isAdmin())
				hikashop_display($e->getMessage());
			$init = false;
		}
		return $init;
	}

	/**
	 *
	 */
	function shippingMethods(&$main) {
		$methods = array();
		if(!empty($main->shipping_params->methodsList))
			$main->shipping_params->methods = unserialize($main->shipping_params->methodsList);

		if(!empty($main->shipping_params->methods)) {
			foreach($main->shipping_params->methods as $method) {
				$selected = null;
				foreach($this->envoimoinscher_methods as $envoimoinscher) {
					if($envoimoinscher['code'] == $method) {
						$selected = $envoimoinscher;
						break;
					}
				}
				if($selected)
					$methods[$main->shipping_id . '-' . $selected['key']] = $selected['name'];
			}
		}
		return $methods;
	}

	/**
	 *
	 */
	function onShippingDisplay(&$order, &$dbrates, &$usable_rates, &$messages) {
		if(!hikashop_loadUser())
			return false;

		if(empty($order->shipping_address))
			return true;

		if($this->loadShippingCache($order, $usable_rates, $messages))
			return true;

		$local_usable_rates = array();
		$local_messages = array();

		$ret = parent::onShippingDisplay($order, $dbrates, $local_usable_rates, $local_messages);
		if($ret === false)
			return false;
		$currentShippingZone = null;
		$currentCurrencyId = null;

		$cache_usable_rates = array();
		$cache_messages = array();

		$found = true;
		$usableWarehouses = array();
		$zoneClass = hikashop_get('class.zone');
		$zones = $zoneClass->getOrderZones($order);

		if(!function_exists('curl_init')) {
			$app = JFactory::getApplication();
			$app->enqueueMessage('The Envoimoinscher shipping plugin needs the CURL library installed but it seems that it is not available on your server. Please contact your web hosting to set it up.','error');
			return false;
		}

		$app = JFactory::getApplication();

		$order_clone = new stdClass();
		$variables = array('products','cart_id','coupon','shipping_address','volume','weight','volume_unit','weight_unit');
		foreach($variables as $var) {
			if(isset($order->$var))
				$order_clone->$var = $order->$var;
		}

		$shipping_key = sha1(serialize($order_clone) . serialize($local_usable_rates));
		$result = $app->getUserState(HIKASHOP_COMPONENT.'.shipping.envoimoinscher_result', $this->result);

		// when cache doesn't work we generate a key in order not to redo everything at each step
		if(isset($result['shipping_key']) && $result['shipping_key'] == $shipping_key)
			return;

		if(empty($order->shipping_address))
			return true;

		foreach($local_usable_rates as $k => $rate) {
			if(!empty($rate->shipping_params->methodsList)) {
				$rate->shipping_params->methods = unserialize($rate->shipping_params->methodsList);
			} else {
				$cache_messages['no_shipping_methods_configured'] = 'No shipping methods selected in the Envoimoinscher shipping plugin options';
				continue;
			}

			if($order->weight <= 0 || ($order->volume <= 0 && @$rate->shipping_params->exclude_dimensions != 1))
				continue;

			$currencyClass = hikashop_get('class.currency');
			$this->shipping_currency_id = hikashop_getCurrency();
			$currency = $currencyClass->get($this->shipping_currency_id);
			$this->shipping_currency_code = $currency->currency_code;

			$cart = hikashop_get('class.cart');
			$null = null;
			$cart->loadAddress($null,$order->shipping_address->address_id, 'object', 'shipping');

			$sending_type = strtolower($rate->shipping_params->sending_type);
			// Get all data for send requests to envoimoinscher
			$this->getData($null, $rate, $order, $sending_type, false);

			if(empty($this->result)) {
				$cache_messages['no_rates'] = JText::_('NO_SHIPPING_METHOD_FOUND');
				continue;
			}

			$new_usable_rates = array();
			foreach($this->result as $key => $method) {
				if($key === "shipping_key")
					continue;

				$o = (!HIKASHOP_PHP5) ? $rate : clone($rate);
				$o->shipping_price = $method["Prix"];
				$selected_method = '';
				$name = '';
				$selected_method = $method['Transporteur'];
				$name = $method['Service'];
				$o->shipping_name = $selected_method." / ".$name;

				if(!empty($selected_method))
					$o->shipping_id .= '-' . $selected_method." / ".$name;

				$new_usable_rates[] = $o;
			}

			foreach($new_usable_rates as $i => $rate) {
				$usable_rates[$rate->shipping_id] = $rate;
				$cache_usable_rates[$rate->shipping_id] = $rate;
			}
			$this->result = array();
		}

		$this->setShippingCache($order, $cache_usable_rates, $cache_messages);
		if(!empty($cache_messages)) {
			foreach($cache_messages as $k => $msg) {
				$messages[$k] = $msg;
			}
		}
	}

	/**
	 *
	 */
	function getShippingDefaultValues(&$element) {
		if(!isset($this->envoimoinscher))
			$this->envoimoinscher = JRequest::getCmd('name','envoimoinscher');

		$element->shipping_name = 'Envoimoinscher';
		$element->shipping_description = '';
		$element->group_package = 0;
		$element->shipping_images = 'envoimoinscher';
		$element->shipping_type = $this->envoimoinscher;
		$element->shipping_params->post_code = '';
		$element->shipping_params->pickup_type = '01';
		$element->shipping_params->destination_type = 'auto';
	}

	/**
	 *
	 */
	function onShippingConfiguration(&$element) {
		parent::onShippingConfiguration($element);

		$this->envoimoinscher = JRequest::getCmd('name','envoimoinscher');
		$elements = array($element);
		$key = key($elements);

		$js = '
function checkAllBox(id, type) {
	var toCheck = document.getElementById(id).getElementsByTagName("input");
	for(i = 0 ; i < toCheck.length ; i++) {
		if(toCheck[i].type != "checkbox")
			continue;
		toCheck[i].checked = (type == "check")
	}
}';
		$doc = JFactory::getDocument();
		$doc->addScriptDeclaration("<!--\n".$js."\n//-->\n");

	}

	/**
	 *
	 */
	function onShippingConfigurationSave(&$element) {
		parent::onShippingConfigurationSave($element);
		if(!$this->init())
			return false;

		$app = JFactory::getApplication();
		$db = JFactory::getDBO();
		$methods = array();
		if(empty($element->shipping_params->emc_login) ||
			empty($element->shipping_params->emc_password) ||
			empty($element->shipping_params->api_key) ||
			empty($element->shipping_params->sender_lastname) ||
			empty($element->shipping_params->sender_firstname) ||
			empty($element->shipping_params->sender_email) ||
			empty($element->shipping_params->sender_company) ||
			empty($element->shipping_params->sender_phone) ||
			empty($element->shipping_params->sender_address) ||
			empty($element->shipping_params->sender_city) ||
			empty($element->shipping_params->sender_postcode) ||
			empty($element->shipping_params->sender_country)
		 ){
			$app->enqueueMessage(JText::sprintf('ENTER_INFO', 'Envoimoinscher', JText::_('SENDER_INFORMATIONS').' ('.JText::_( 'HIKA_LOGIN' ).', '.JText::_( 'HIKA_PASSWORD' ).', '.JText::_( 'FEDEX_API_KEY' ).', '.JText::_( 'LASTNAME' ).', '.JText::_( 'FIRSTNAME' ).', '.JText::_( 'HIKA_EMAIL' ).', '.JText::_( 'COMPANY' ).', '.JText::_( 'TELEPHONE' ).', '.JText::_( 'ADDRESS' ).', '.JText::_( 'CITY' ).', '.JText::_( 'POST_CODE' ).', '.JText::_( 'COUNTRY' ).')'));
		}

		// TODO : Refactor ! (use JRequest...)
		if(isset($_REQUEST['data']['shipping_methods'])) {
			foreach($_REQUEST['data']['shipping_methods'] as $method){
				foreach($this->envoimoinscher_methods as $envoimoinscherMethod) {
					$name = $envoimoinscherMethod['name'];
					if($name == $method['name']) {
						$obj = new stdClass();
						$methods[strip_tags($method['name'])] = strip_tags($envoimoinscherMethod['code']);
					}
				}
			}
		} else {
			$app->enqueueMessage(JText::sprintf('CHOOSE_SHIPPING_SERVICE'));
		}

		$element->shipping_params->methodsList = serialize($methods);

		// we call the function of the library to get all the product categories and we display
		//
		if(!empty($element->shipping_params->emc_login) && !empty($element->shipping_params->emc_password) && !empty($element->shipping_params->api_key)) {
			$contentCl = new Env_ContentCategory(array(
				'user' => @$element->shipping_params->emc_login,
				'pass' => @$element->shipping_params->emc_password,
				'key' => @$element->shipping_params->api_key
			));
			$config = hikashop_config();
			$contentCl->setPlatformParams('hikashop', $config->get('version'), $config->get('version'));
			$contentCl->setEnv($element->shipping_params->environment);
			$contentCl->getCategories();
			@$contentCl->getContents();
			$element->shipping_params->contentCl = array(
				'categories' => $contentCl->categories,
				'contents' => $contentCl->contents
			);
			if(!empty($contentCl->curlErrorText)) {
				$app->enqueueMessage($contentCl->curlErrorText, 'error');
			}
			if(!empty($contentCl->respErrorsList)) {
				foreach($contentCl->respErrorsList as $err) {
					$app->enqueueMessage('[ ' . $err['code'] . ' ] ' . $err['message'], 'error');
				}
			}
		}

		$czone_code = @$element->shipping_params->sender_country;
		if(!empty($czone_code)) {
			$query = 'SELECT zone_id, zone_code_2 FROM '.hikashop_table('zone').' WHERE zone_namekey = ' . $db->Quote($czone_code);
			$db->setQuery($query);
			$czone = $db->loadObject();
			$country = $czone->zone_code_2;
			if($country == 'FX')
				$country = 'FR';

			// To display the drop off points for each service in the config
			$lpCl = new Env_ListPoints(array(
				'user' => $element->shipping_params->emc_login,
				'pass' => $element->shipping_params->emc_password,
				'key' => $element->shipping_params->api_key
			));
			$config = hikashop_config();
			$lpCl->setPlatformParams('hikashop', $config->get('version'), $config->get('version'));

			// environment 'test' or 'prod'
			$lpCl->setEnv($element->shipping_params->environment);

			foreach($methods as $name => $code){
				$params = array(
					'srv_code' => $name,
					'collecte' => 'exp',
					'pays' => $country,
					'cp' => $element->shipping_params->sender_postcode,
					'ville' => $element->shipping_params->sender_city
				);
				$lpCl->getListPoints($code, $params);

				if(!$lpCl->curlError && !$lpCl->respError) {
					$element->shipping_params->envoimoinscher_dropoff[$code] = $lpCl->listPoints;
					unset($lpCl->listPoints);
					$lpCl->listPoints = array();
				}
			}

			if(!empty($lpCl->curlErrorText)) {
				$app->enqueueMessage($lpCl->curlErrorText, 'error');
			}
			if(!empty($lpCl->respErrorsList)) {
				foreach($lpCl->respErrorsList as $err) {
					$app->enqueueMessage('[ ' . $err['code'] . ' ] ' . $err['message'], 'error');
				}
			}
		}

		return true;
	}

	/**
	 * Function to get all informations (sender, receiver, and products informations) required by envoimoinscher
	 */
	function getData($null, &$rate, &$order, &$sending_type, $makeOrder) {

		if($makeOrder == false) {
			// We call the 2 functions to get sender and reveiver informations
			$to = $this->getReceiverData($null,$rate,$order);
			$from = $this->getSenderData($rate);
		} else {
			$rate->shipping_params = $rate->plugin_params;
		}

		if(empty($rate->shipping_params->package_weight)) {
			$package = false;
		} else {
			$package = true;
			$weight_pack = $rate->shipping_params->package_weight;
		}

		$weightClass = hikashop_get('helper.weight');
		$volumeClass = hikashop_get('helper.volume');
		$data = array();
		$i = 1;
		$price_total = 0;

		/*
		 * if group package is off, we create a package for each product
		 * we have to get the weight and the dimensions of each product
		 *
		 * that part need a little refactoring
		 */
		if($rate->shipping_params->group_package == 0){
			foreach($order->products as $product){
				if($product->product_parent_id != 0)
					continue;
			
				if(isset($product->variants)) {
					foreach($product->variants as $variant){
						for($qte=0;$qte<$variant->cart_product_quantity;$qte++){
							$caracs["poids"] = $weightClass->convert($variant->product_weight_orig, $variant->product_weight_unit_orig, 'kg');
							$caracs["longueur"] = $volumeClass->convert($variant->product_length, $variant->product_dimension_unit_orig, 'cm', 'dimension' );
							$caracs["largeur"] = $volumeClass->convert($variant->product_width, $variant->product_dimension_unit_orig, 'cm', 'dimension' );
							if($sending_type != "pli")
								$caracs["hauteur"] = $volumeClass->convert($variant->product_height, $variant->product_dimension_unit_orig, 'cm' , 'dimension');
							$data[$i]["poids"] = $caracs["poids"];
							//we add the weight of the package, if there is no value in the config for the package weight, the default value is 1%
							if($package == true)
								$data[$i]["poids"] += ($data[$i]["poids"]*(float)$weight_pack/100);
							else
								$data[$i]["poids"] += (float)0.1;
							if($caracs["longueur"] != '0.00' && $caracs["largeur"] != 0){
								$data[$i]["longueur"] = $caracs["longueur"];
								$data[$i]["largeur"] = $caracs["largeur"];
								if($sending_type != "pli")
									$data[$i]["hauteur"] = $caracs["hauteur"];
							}
							$price_total += $variant->prices[0]->unit_price->price_value_with_tax;
							$i++;
						}
					}
				}
				else // if product isn't a variant
				{
					for($qte=0;$qte<$product->cart_product_quantity;$qte++){
						$caracs["poids"] = $weightClass->convert($product->product_weight_orig, $product->product_weight_unit_orig, 'kg');
						$caracs["longueur"] = $volumeClass->convert($product->product_length, $product->product_dimension_unit_orig, 'cm', 'dimension' );
						$caracs["largeur"] = $volumeClass->convert($product->product_width, $product->product_dimension_unit_orig, 'cm', 'dimension' );
						if($sending_type != "pli")
							$caracs["hauteur"] = $volumeClass->convert($product->product_height, $product->product_dimension_unit_orig, 'cm' , 'dimension');
						$data[$i]["poids"] = $caracs["poids"];
						if($package == true)
							$data[$i]["poids"] += $data[$i]["poids"]*(float)$weight_pack/100;
						else
							$data[$i]["poids"] += (float)0.1;
						if($caracs["longueur"] != '0.00' && $caracs["largeur"] != 0){
							$data[$i]["longueur"] = $caracs["longueur"];
							$data[$i]["largeur"] = $caracs["largeur"];
							if($sending_type != "pli")
								$data[$i]["hauteur"] = $caracs["hauteur"];
						}
						$price_total += $product->prices[0]->unit_price->price_value_with_tax;
						$i++;
					}
				}
			}
		}
		else // group package activated
		{
			// limits for some types, if we don't respect the limitations, it will no work when sending request to envoimoinscher
			//
			if($sending_type == 'pli') {
				$limitation = array(
					'hauteur' => 2,
					'poids' => 3
				);
			}
			else if ($sending_type == 'colis')
			{
				$limitation = array(
					'poids' => 70,
				);
			}
			$j = 1;
			$data[$j]['poids'] = 0;
			$data[$j]['hauteur'] = 0;
			$data[$j]['longueur'] = 0;
			$data[$j]['largeur'] = 0;
			foreach($order->products as $product) {
				if($product->product_parent_id != 0)
					continue;
			
				if(isset($product->variants)){
					foreach($product->variants as $variant){
						for($i=0;$i<$variant->cart_product_quantity;$i++){
							$caracs["poids"] = $weightClass->convert($variant->product_weight_orig, $variant->product_weight_unit_orig, 'kg');
							if($package == true)
								$caracs["poids"] += $caracs["poids"]*(float)$weight_pack/100;
							else
								$caracs["poids"] += (float)0.1;
							$caracs["longueur"] = $volumeClass->convert($variant->product_length, $variant->product_dimension_unit_orig, 'cm', 'dimension' );
							$caracs["largeur"] = $volumeClass->convert($variant->product_width, $variant->product_dimension_unit_orig, 'cm', 'dimension' );
							//if($sending_type != "pli")
								$caracs["hauteur"] = $volumeClass->convert($variant->product_height, $variant->product_dimension_unit_orig, 'cm' , 'dimension');
							$tmpHeight = $data[$j]['hauteur'] + round($caracs['hauteur'],2);
							$tmpLength = $data[$j]['longueur'] + round($caracs['longueur'],2);
							$tmpWidth = $data[$j]['largeur'] + round($caracs['largeur'],2);
							$dim = $tmpLength+2*$tmpWidth+2*$tmpHeight;
							$x = min($caracs['largeur'],$caracs['hauteur'],$caracs['longueur']);
							if($x == $caracs['largeur']){
								$y = min($caracs['hauteur'],$caracs['longueur']);
								if($y == $caracs['hauteur']) $z = $caracs['longueur'];
								else $z = $caracs['hauteur'];
							}
							if($x == $caracs['hauteur']){
								$y = min($caracs['largeur'],$caracs['longueur']);
								if($y == $caracs['largeur']) $z = $caracs['longueur'];
								else $z = $caracs['largeur'];
							}
							if($x == $caracs['longueur']){
								$y = min($caracs['hauteur'],$caracs['largeur']);
								if($y == $caracs['hauteur']) $z = $caracs['largeur'];
								else $z = $caracs['hauteur'];
							}
							if($sending_type == "pli"){
								if(($data[$j]['poids'] + round($caracs['poids'],2) >= $limitation['poids'] || $data[$j]['hauteur'] > $limitation['hauteur']) &&  $data[$j]['longueur'] != 0){
									//we create a new package if dimensions or weight exceed limitations
									$j++;
									$data[$j]['poids'] = round($caracs['poids'],2);
									$data[$j]['largeur'] = $y;
									$data[$j]['longueur'] = $z;
									$data[$j]['hauteur'] = $x;
									$price_total += $variant->prices[0]->unit_price->price_value_with_tax;
								}
								else{
									$data[$j]['poids'] += round($caracs['poids'],2);
									$data[$j]['largeur'] = max($data[$j]['largeur'],$y);
									$data[$j]['longueur'] = max($data[$j]['longueur'],$z);
									$data[$j]['hauteur'] += $x;
									$price_total += $variant->prices[0]->unit_price->price_value_with_tax;
								}
							}
							else if($sending_type == "colis"){
								if(($data[$j]['poids'] + round($caracs['poids'],2) >= $limitation['poids']) &&  $data[$j]['longueur'] != 0){
									$j++;
									$data[$j]['poids'] = round($caracs['poids'],2);
									$data[$j]['hauteur'] = $y;
									$data[$j]['longueur'] = $z;
									$data[$j]['largeur'] = $x;
									$price_total += $variant->prices[0]->unit_price->price_value_with_tax;
								}
								else{
									$data[$j]['poids'] += round($caracs['poids'],2);
									$data[$j]['hauteur'] = max($data[$j]['hauteur'],$y);
									$data[$j]['longueur'] = max($data[$j]['longueur'],$z);
									$data[$j]['largeur'] += $x;
									$price_total += $variant->prices[0]->unit_price->price_value_with_tax;
								}
							}
							else{
								$data[$j]['poids'] += round($caracs['poids'],2);
								$data[$j]['hauteur'] = max($data[$j]['hauteur'],$y);
								$data[$j]['longueur'] = max($data[$j]['longueur'],$z);
								$data[$j]['largeur'] += $x;
								$price_total += $variant->prices[0]->unit_price->price_value_with_tax;
							}

						}
					}
				}
				else // if product isn't a variant
				{
					for($i=0;$i<$product->cart_product_quantity;$i++){
						$caracs["poids"] = $weightClass->convert($product->product_weight_orig, $product->product_weight_unit_orig, 'kg');
						if($package == true)
							$caracs["poids"] += $caracs["poids"]*(float)$weight_pack/100;
						else
							$caracs["poids"] += (float)0.1;
						$caracs["longueur"] = $volumeClass->convert($product->product_length, $product->product_dimension_unit_orig, 'cm', 'dimension' );
						$caracs["largeur"] = $volumeClass->convert($product->product_width, $product->product_dimension_unit_orig, 'cm', 'dimension' );
						//if($sending_type != "pli")
							$caracs["hauteur"] = $volumeClass->convert($product->product_height, $product->product_dimension_unit_orig, 'cm' , 'dimension');
						$x = min($caracs['largeur'],$caracs['hauteur'],$caracs['longueur']);
						if($x == $caracs['largeur']){
							$y = min($caracs['hauteur'],$caracs['longueur']);
							if($y == $caracs['hauteur']) $z = $caracs['longueur'];
							else $z = $caracs['hauteur'];
						}
						if($x == $caracs['hauteur']){
							$y = min($caracs['largeur'],$caracs['longueur']);
							if($y == $caracs['largeur']) $z = $caracs['longueur'];
							else $z = $caracs['largeur'];
						}
						if($x == $caracs['longueur']){
							$y = min($caracs['hauteur'],$caracs['largeur']);
							if($y == $caracs['hauteur']) $z = $caracs['largeur'];
							else $z = $caracs['hauteur'];
						}
						$tmpHeight = $data[$j]['hauteur'] + round($caracs['hauteur'],2);
						$tmpLength = $data[$j]['longueur'] + round($caracs['longueur'],2);
						$tmpWidth = $data[$j]['largeur'] + round($caracs['largeur'],2);
						$dim = $tmpLength+2*$tmpWidth+2*$tmpHeight;
						if($sending_type == "pli"){
							if(($data[$j]['poids'] + round($caracs['poids'],2) >= $limitation['poids'] || $data[$j]['hauteur'] > $limitation['hauteur']) &&  $data[$j]['longueur'] != 0){
								$j++;
								$data[$j]['poids'] = round($caracs['poids'],2);
								$data[$j]['largeur'] = $y;
								$data[$j]['longueur'] = $z;
								$data[$j]['hauteur'] = $x;
								$price_total += $product->prices[0]->unit_price->price_value_with_tax;
							}
							else{
								$data[$j]['poids'] += round($caracs['poids'],2);
								$data[$j]['largeur'] = max($data[$j]['largeur'],$y);
								$data[$j]['longueur'] = max($data[$j]['longueur'],$z);
								$data[$j]['hauteur'] += $x;
								$price_total += $product->prices[0]->unit_price->price_value_with_tax;
							}
						}
						else if($sending_type == "colis"){
							if(($data[$j]['poids'] + round($caracs['poids'],2) >= $limitation['poids']) &&  $data[$j]['longueur'] != 0){
								$j++;
								$data[$j]['poids'] = round($caracs['poids'],2);
								$data[$j]['hauteur'] = $y;
								$data[$j]['longueur'] = $z;
								$data[$j]['largeur'] = $x;
								$price_total += $product->prices[0]->unit_price->price_value_with_tax;
							}
							else{
								$data[$j]['poids'] += round($caracs['poids'],2);
								$data[$j]['hauteur'] = max($data[$j]['hauteur'],$y);
								$data[$j]['longueur'] = max($data[$j]['longueur'],$z);
								$data[$j]['largeur'] += $x;
								$price_total += $product->prices[0]->unit_price->price_value_with_tax;
							}
						}
						else{
							$data[$j]['poids'] += round($caracs['poids'],2);
							$data[$j]['hauteur'] = max($data[$j]['hauteur'],$y);
							$data[$j]['longueur'] = max($data[$j]['longueur'],$z);
							$data[$j]['largeur'] += $x;
							$price_total += $product->prices[0]->unit_price->price_value_with_tax;
						}
					}
				}
			}
		}
		$data[0]['price'] = $price_total;

		// If makeOrder is false we call EMCrequestmethod to get quotation
		if($makeOrder == false) {
			$this->_EMCrequestMethods($data, $null, $rate, $order, $sending_type, $from, $to);
			return null;
		}
		return $data;
	}

	/**
	 * Function to get receiver infos
	 */
	function getReceiverData($data, &$rate, &$order) {
		if($rate->shipping_params->destination_type == 'res' || ($rate->shipping_params->destination_type == 'auto' && empty($order->shipping_address->address_company))) {
			$user_type = 'particulier';
		} else {
			// destination_type == 'com' || ('auto' && !empty(address_company))
			$user_type = 'entreprise';
		}

		$country = $data->shipping_address->address_country->zone_code_2;
		if($country == 'FX')
			$country = 'FR';

		$to = array(
			'pays' => $country,
			'code_postal' => $data->shipping_address->address_post_code,
			'ville' => $data->shipping_address->address_city,
			'type' => $user_type,
			'adresse' => $data->shipping_address->address_street
		);
		return $to;
	}

	/**
	 * Function to get sender infos
	 */
	function getSenderData(&$rate) {
		$czone_code = '';
		$czone_code = @$rate->shipping_params->sender_country;

		$db = JFactory::getDBO();
		$query = 'SELECT zone_id, zone_code_2 FROM '.hikashop_table('zone').' WHERE zone_namekey = ' . $db->Quote($czone_code);
		$db->setQuery($query);
		$czone = $db->loadObject();

		$country = $czone->zone_code_2;
		if($country == 'FX')
			$country = 'FR';

		$from = array(
			'pays' => $country,
			'code_postal' => $rate->shipping_params->sender_postcode,
			'ville' => $rate->shipping_params->sender_city,
			'type' => $rate->shipping_params->type,
			'adresse' => $rate->shipping_params->sender_address
		);
		return $from;
	}

	/**
	 * Function to get quotation for the order
	 */
	function _EMCrequestMethods(&$data, &$null, &$rate, &$order, $sending_type, $from, $to) {
		if(!$this->init())
			return false;

		$app = JFactory::getApplication();
		$total_price = (int)$data[0]['price'];
		unset($data[0]);

		// get selected carriers in config
		$listMethods = array();
		foreach($rate->shipping_params->methods as $key => $nom) {
			$listMethods[] = $nom;
		}

		$code = $rate->shipping_params->product_category;

		// Informations on sending
		$quotInfo = array(
			'collecte' => date('Y-m-d'),
			'delai' => 'aucun',
			'code_contenu' => (int)$code,
			$sending_type.'.valeur' => $total_price
		);

		$cotCl = new Env_Quotation(array(
			'user' => $rate->shipping_params->emc_login,
			'pass' => $rate->shipping_params->emc_password,
			'key' => $rate->shipping_params->api_key
		));
		$config = hikashop_config();
		$cotCl->setPlatformParams('hikashop', $config->get('version'), $config->get('version'));
		// environment 'prod' or 'test'
		$cotCl->setEnv($rate->shipping_params->environment);

		$cotCl->setPerson('expediteur', $from);
		$cotCl->setPerson('destinataire', $to);
		$cotCl->setType(
			$sending_type,
			$data
		);

		// Send request to get offers
		$cotCl->getQuotation($quotInfo);
		if($cotCl->curlError) {
			$app->enqueueMessage(JText::sprintf('Error while sending the request: %s', $cotCl->curlErrorText), 'error');
			return false;
		}

		if(!$cotCl->respError) {
			$cotCl->getOffers(false);
			if(empty($cotCl->offers))
				return false;

			//we get all the important informations for each offers returned by envoimoinscher
			foreach($listMethods as $liste) {
				foreach($cotCl->offers as $o => $offre) {
					$code = $offre['operator']['code'];
					//we take into account only the services selected in the configuration
					if(strpos($code, $liste) === false)
						continue;

					$this->result[] = array(
						'Transporteur' => $offre['operator']['label'],
						'Service' => $offre['service']['code'],
						'Code' => $offre['operator']['code'],
						'Prix' => $offre['price']['tax-inclusive'],
						'Collecte' => array(
							'Type' => $offre['collection']['type'],
							'Label' => $offre['collection']['label'],
						),
						'Livraison' => array(
							'Type' => $offre['delivery']['type'],
							'Label' => $offre['delivery']['label'],
						),
						// in the characterictics there is the date of the collection of the package
						'Détails' => $offre['characteristics'][1],
						// 'Alertes' => $offre['alert'],
					);
				}
			}
		} else {
			$app->enqueueMessage(JText::_('The request is invalid :'), 'error');
			foreach($cotCl->respErrorsList as $m => $message) {
				$app->enqueueMessage($message['message'], 'error');
			}
		}

		$order_clone = new stdClass();
		$variables = array('products','cart_id','coupon','shipping_address','volume','weight','volume_unit','weight_unit');
		foreach($variables as $var) {
			if(isset($order->$var))
				$order_clone->$var = $order->$var;
		}

		$temp_rate = array();
		$temp_rate[$rate->shipping_id] = $rate;
		unset($temp_rate[$rate->shipping_id]->shipping_params->methods);

		// we generate a key for the order
		$shipping_key = sha1(serialize($order_clone) . serialize($temp_rate));

		// We need the warehouse id to set it in the results
		$warehouse_id = -1;
		if(isset($order->shipping_warehouse_id)) {
			$warehouse_id = (int)$order->shipping_warehouse_id;
		} else {
			$groups_ids = array_keys(($order->shipping_groups));
			$warehouse_id = $groups_ids[0];
		}
		$shipping_id = $rate->shipping_id;

		$result = $app->getUserState(HIKASHOP_COMPONENT.'.shipping.envoimoinscher_result');
		$result[$warehouse_id][$shipping_id] = $this->result;
		$result['shipping_key'] = $shipping_key;

		// Set the results in session
		$app->setUserState(HIKASHOP_COMPONENT.'.shipping.envoimoinscher_result', $result);
	}

	/**
	 *
	 */
	function onCheckoutStepList(&$list) {
		//add step on checkout workflow which is the view for pick up points
		$list['plg.shop.pickuppoints'] = JText::_('PICKUP_POINT');
	}

	/**
	 *
	 */
	function onCheckoutStepDisplay($layoutName, &$html, &$view) {
		if($layoutName != 'plg.shop.pickuppoints')
			return;

		$app = JFactory::getApplication();
		$this->selected_shipping_id = $app->getUserState(HIKASHOP_COMPONENT.'.shipping_id');
		$this->selected_shipping_method = $app->getUserState(HIKASHOP_COMPONENT.'.shipping_method');

		if(empty($this->selected_shipping_id))
			return;

		foreach($this->selected_shipping_id as $k => $shipping_id) {
			$shipping_types = explode('@',$this->selected_shipping_method[$k]);
			if($shipping_types[0] != 'envoimoinscher')
				continue;

			$shipping_ids = explode('-', $shipping_id);
			if($this->pluginParams((int)$shipping_ids[0]) === false)
				continue;

			$result = $app->getUserState(HIKASHOP_COMPONENT.'.shipping.envoimoinscher_result', null);
			if(empty($result))
				return;

			if(!$this->init())
				return false;

			$this->lpCl = new Env_ListPoints(array(
				'user' => $this->plugin_params->emc_login,
				'pass' => $this->plugin_params->emc_password,
				'key' =>$this->plugin_params->api_key
			));
			$config = hikashop_config();
			$this->lpCl->setPlatformParams('hikashop', $config->get('version'), $config->get('version'));
			$this->lpCl->setEnv($this->plugin_params->environment);

			$tmp = explode('-', $shipping_id);
			$choice = explode('@', $tmp[1]);

			$ind = 0;
			$warehouse_id = $choice[1];
			$shipping_id = $tmp[0];

			// Search type of collection and delivery for the offer selected
			//
			foreach($result[$warehouse_id][$shipping_id] as $key => $value) {
				$compare = $value['Transporteur'] . ' / ' . $value['Service'];
				if($compare == $choice[0]) {
					if($value['Collecte']['Type'] == 'DROPOFF_POINT' || $value['Collecte']['Type'] == 'POST_OFFICE')
						$this->collection = true;
					if($value['Livraison']['Type'] == 'PICKUP_POINT')
						$this->delivery = true;
					break;
				}

				$ind++;
			}

			$code = '';
			if($this->collection == true) {
				foreach($this->plugin_params as $key => $value) {
					if($key == $result[$warehouse_id][$shipping_id][$ind]['Code']) {
						$code = $value;
					}
				}

				if(!empty($code)) {
					//we put the code of the drop off point in session
					$cd = explode("$",$code);
					$result[$warehouse_id][$shipping_id]['dropoff'] = $cd;
				} else {
					//case collection type is "post office" code = code of the carrier -POST
					$result[$warehouse_id][$shipping_id]['dropoff'] = array();
					$result[$warehouse_id][$shipping_id]['dropoff'][0] = $result[$warehouse_id][$shipping_id][$ind]['Code'].'-POST';
				}
			} else {
				$result[$warehouse_id][$shipping_id]['dropoff'] = array();
			}

			$result[$warehouse_id][$shipping_id]['key'] = $ind;

			$app->setUserState(HIKASHOP_COMPONENT.'.shipping.envoimoinscher_result', $result);

			// if pick up point
			if($this->delivery == true) {

				// Get the country
				$country = '';
				$czone_code = @$view->orderInfos->shipping_address->address_country[0];
				if(!empty($czone_code)) {
					$db = JFactory::getDBO();
					$query = 'SELECT zone_id, zone_code_2 FROM ' . hikashop_table('zone') . ' WHERE zone_namekey = ' . $db->Quote($czone_code);
					$db->setQuery($query);
					$czone = $db->loadObject();
					$country = $czone->zone_code_2;
					if($country == 'FX')
						$country = 'FR';
				}

				$this->userInfo['pays'] = $country;
				$this->userInfo['cp'] = @$view->orderInfos->shipping_address->address_post_code;
				$this->userInfo['ville'] = @$view->orderInfos->shipping_address->address_city;
				$key_offer = $result[$warehouse_id][$shipping_id]['key'];

				// Get all pick up points for the receiver
				//
				$params2 = array(
					'srv_code' => $result[$warehouse_id][$shipping_id][$key_offer]['Transporteur'],
					'collecte' => 'dest',
					'pays' => $this->userInfo['pays'],
					'cp' => $this->userInfo['cp'],
					'ville' => $this->userInfo['ville']
				);
				$this->lpCl->getListPoints($result[$warehouse_id][$shipping_id][$key_offer]['Code'], $params2);

				//
				if(!$this->lpCl->curlError && !$this->lpCl->respError) {
					$this->warehouse_id = $warehouse_id;
					$this->shipping_id = $shipping_id;
					echo $choice[0].'<br/>'.JText::_( 'CHOOSE_PICKUP_POINT' ).' : ';

					// Call envoimoinscher_view.php
					$this->showPage('view');

				} elseif($this->lpCl->respError) {
					$app->enqueueMessage(JText::sprintf('The request is invalid :'), 'error');
					foreach($this->lpCl->respErrorsList as $m => $message) {
						$app->enqueueMessage(JText::sprintf($message['message']), 'error');
					}

				} else {
					$app->enqueueMessage(JText::sprintf('Error while sending the request: %s', $cotCl->curlErrorText), 'error');
				}
			}

			$this->collection = false;
			$this->delivery = false;
		}
	}

	/**
	 *
	 */
	function onBeforeCheckoutStep($controllerName, &$go_back, $original_go_back, &$controller) {
	}

	/**
	 * We get the pick up point selected for each offer
	 */
	function onAfterCheckoutStep($controllerName, &$go_back, $original_go_back, &$controller) {
		$app = JFactory::getApplication();
		$this->selected_shipping_id = $app->getUserState(HIKASHOP_COMPONENT.'.shipping_id');
		$this->selected_shipping_method = $app->getUserState(HIKASHOP_COMPONENT.'.shipping_method');

		if(empty($this->selected_shipping_id))
			return;

		foreach($this->selected_shipping_id as $k => $shipping_id) {
			$shipping_types = explode('@', $this->selected_shipping_method[$k]);
			if($shipping_types[0] != 'envoimoinscher')
				continue;

			$shipping_ids = explode('-', $shipping_id);
			if($this->pluginParams((int)$shipping_ids[0]) === false)
				continue;

			$choice = explode('@', $shipping_ids[1]);
			$warehouse_id = $choice[1];
			$shipping_id = $shipping_ids[0];
			$result = $app->getUserState(HIKASHOP_COMPONENT.'.shipping.envoimoinscher_result');

			foreach($result[$warehouse_id][$shipping_id] as $key => $value) {
				$compare = $value['Transporteur'] . ' / ' . $value['Service'];
				if($compare != $choice[0])
					continue;

				if($value['Livraison']["Type"] == 'PICKUP_POINT') {
					$name = $shipping_id . '-emc_pickup@' . $warehouse_id;

					// >e get the selected pick up point
					$pickup = JRequest::getString($name, null);
					$this->pickups[$warehouse_id][$shipping_id] = $pickup;

					// If no pick up point selected, go_back = true so that we can't confirm the order
					if($pickup == null) {
						$go_back = true;
						return;
					}
				}
				break;
			}
		}

		// We set in cache the selected pick up point for each shipping method
		$app->setUserState(HIKASHOP_COMPONENT.'.shipping.envoimoinscher_Pickup_point',$this->pickups);
	}

	/**
	 * We put in order_shipping_params the code of the service, drop off point and pick up point for each offer
	 */
	function onBeforeOrderCreate(&$order, &$do) {
		if($order->order_type != 'sale')
			return;

		$app = JFactory::getApplication();
		$this->selected_shipping_id = $app->getUserState(HIKASHOP_COMPONENT.'.shipping_id');
		$this->selected_shipping_method = $app->getUserState(HIKASHOP_COMPONENT.'.shipping_method');

		if(empty($this->selected_shipping_id))
			return;

		foreach($this->selected_shipping_id as $k => $shipping_id) {
			$shipping_types = explode('@',$this->selected_shipping_method[$k]);
			if($shipping_types[0] != 'envoimoinscher')
				continue;

			$shipping_ids = explode('-', $shipping_id);
			if($this->pluginParams((int)$shipping_ids[0]) === false)
				continue;

			$choice = explode('@', $shipping_ids[1]);
			$warehouse_id = $choice[1];

			$shipping_id = $shipping_ids[0];
			$result = $app->getUserState(HIKASHOP_COMPONENT.'.shipping.envoimoinscher_result');

			// We unset the unselected offers to have less informations in cache
			$size = (count($result[$warehouse_id][$shipping_id]) - 2);
			for($j = 0; $j < $size; $j++) {
				if($j == $result[$warehouse_id][$shipping_id]['key'])
					continue;
				unset($result[$warehouse_id][$shipping_id][$j]);
			}
			$app->setUserState(HIKASHOP_COMPONENT.'.shipping.envoimoinscher_result', $result);

			$key_offer = $result[$warehouse_id][$shipping_id]['key'];
			$dropoff_info = $result[$warehouse_id][$shipping_id][$key_offer]['Collecte']['Type'];
			$pickup_info = $result[$warehouse_id][$shipping_id][$key_offer]['Livraison']['Type'];

			$pickups = $app->getUserState(HIKASHOP_COMPONENT.'.shipping.envoimoinscher_Pickup_point');

			if($dropoff_info == 'DROPOFF_POINT') {
				if(!empty($result[$warehouse_id][$shipping_id]['dropoff'][1])) {
					$dropoff_info .= '<br/>' .
						$result[$warehouse_id][$shipping_id][$key_offer]['Collecte']['Label'] . '<br/>' .
						$result[$warehouse_id][$shipping_id]['dropoff'][0] . '<br/>' .
						$result[$warehouse_id][$shipping_id]['dropoff'][1];
				} else {
					//if it's DROPOFF_POINTt and there is no code for the drop off, the type is POST OFFICE
					$dropoff_info .= '<br/>' .
						'dépôt au bureau de poste' . '<br/>' .
						$result[$warehouse_id][$shipping_id]['dropoff'][0];
				}
			} else if($dropoff_info == 'POST_OFFICE') {
				$dropoff_info .= '<br/>' .
					$result[$warehouse_id][$shipping_id][$key_offer]['Collecte']['Label'] . '<br/>' .
					$result[$warehouse_id][$shipping_id]['dropoff'][0];
			} else {
				$dropoff_info .= '<br/>' .
					$result[$warehouse_id][$shipping_id][$key_offer]['Collecte']['Label'];
			}

			// Date of collection
			$dropoff_info .= '<br/>' .
				$result[$warehouse_id][$shipping_id][$key_offer]['Détails'];

			if($pickup_info == 'PICKUP_POINT') {
				$code = explode('$', $pickups[$warehouse_id][$shipping_id]);
				$pickup_info .= '<br/>' .
					$result[$warehouse_id][$shipping_id][$key_offer]['Livraison']['Label'] . '<br/>' .
					$code[0] . '<br/>' .
					$code[1];
			} else {
				$pickup_info .= '<br/>' .
					$result[$warehouse_id][$shipping_id][$key_offer]['Livraison']['Label'];
			}

			$EMC_params = array(
				'code' => $result[$warehouse_id][$shipping_id][$key_offer]['Code'],
				'drop_off' => $dropoff_info,
				'pick_up' => $pickup_info,
				'reference' => ''
			);
			$order->order_shipping_params->EMC_params[$shipping_id . '-' . $choice[0] . '@' . $warehouse_id] = $EMC_params;
		}

		// Clear cache
		$app->setUserState(HIKASHOP_COMPONENT.'.shipping.envoimoinscher_result', null);
		$app->setUserState(HIKASHOP_COMPONENT.'.shipping.envoimoinscher_Pickup_point', null);
	}

	/**
	 * We show in the additional information the drop off point and pick up point and the order reference(only after makeOrder done)
	 */
	function onHikashopBeforeDisplayView(&$view) {
		$app = JFactory::getApplication();
		if(!$app->isAdmin())
			return true;

		if(!isset($view->order->order_shipping_params->EMC_params))
			return;

		$viewName = $view->getName();
	 	$layoutName = $view->getLayout();
		if($viewName != 'order' || ($layoutName != 'show' && $layoutName != 'show_additional'))
			return true;

		$db = JFactory::getDBO();
		foreach($view->order->order_shipping_params->EMC_params as $key => $value) {
			$tmp= explode('@', $key);
			$name = explode(' /', $tmp[0]);
			$warehouse_id = '';
			$warehouse_id = $tmp[1];
			$ware_name = '';

			// To differentiate offers that have same name we add the warehouse name
			if(!empty($warehouse_id)) {
				$query = 'SELECT warehouse_name FROM ' . hikashop_table('warehouse') . ' WHERE warehouse_id = '.(int)$warehouse_id;
				$db->setQuery($query);
				$warehouse = $db->loadObject();
				$ware_name = $warehouse->warehouse_name;
			}

			if($value['code']) {
				$view->extra_data['additional']['shipping_envoimoinscher_'.$name[0].'@'.$warehouse_id.'_code'] = array(
					'title' => 'CODE<br/>' . $name[0] . '<br/>' . $ware_name,
					'data' => $value['code']
				);
			}
			if($value['drop_off']) {
				$view->extra_data['additional']['shipping_envoimoinscher_'.$name[0].'@'.$warehouse_id.'_dropoff'] = array(
					'title' => 'DROP_OFF<br/>' . $name[0] . '<br/>' . $ware_name,
					'data' => $value['drop_off']
				);
			}
			if($value['pick_up']) {
				$view->extra_data['additional']['shipping_envoimoinscher_'.$name[0].'@'.$warehouse_id.'_pickup'] = array(
					'title' => 'PICK_UP<br/>' . $name[0] . '<br/>' . $ware_name,
					'data' => $value['pick_up']
				);
			}
			//there will be a reference once makeOrder is done
			if($value['reference']) {
				$view->extra_data['additional']['shipping_envoimoinscher_'.$name[0].'@'.$warehouse_id.'_reference'] = array(
					'title' => 'REFERENCE<br/>' . $name[0] . '<br/>' . $ware_name,
					'data' => $value['reference']
				);
			}
		}
	}

	/**
	 * To make order  when order is updated
	 */
	function onAfterOrderUpdate(&$order, &$send_email) {
		$order_type = isset($order->order_type) ? $order->order_type : $order->old->order_type;

		if($order_type != 'sale'  || empty($order->order_status))
			return;

		// To check the type of the order. if EMC params is empty, no method envoimoinscher has been used
		if(!isset($order->order_shipping_params->EMC_params))
			return;

		if(!$this->init())
			return false;

		$config = hikashop_config();
		$order_confirmed_status = $config->get('order_confirmed_status', 'confirmed');
		$invoice_order_statuses = explode(',', $config->get('invoice_order_statuses', 'confirmed,shipped'));
		if(empty($invoice_order_statuses))
			$invoice_order_statuses = array('confirmed','shipped');

		//if order status is "created" we do anything
		if($order->order_status != $order_confirmed_status && !in_array($order->order_status, $invoice_order_statuses))
			return;

		$order_shipping_params = isset($order->order_shipping_params) ? $order->order_shipping_params : $order->old->order_shipping_params;
		if(is_string($order_shipping_params))
			$order_shipping_params = unserialize($order_shipping_params);
		/*
		 * test if makeOrder has already been done once
		 * if there was an error with one, we can't redo
		 * we can improve this so we can redo those who had an error with the makeOrder
		 */
		$ref_exist = false;
		foreach($order_shipping_params->EMC_params as $value) {
			if(!empty($value['reference']))
				$ref_exist = true;
		}
		if($ref_exist == true)
			return;

		$db = JFactory::getDBO();
		$orderClass = hikashop_get('class.order');
		$fullOrder = $orderClass->loadFullOrder($order->order_id,true,false);

		// we group products by warehouse and shipping method
		//
		$tab_products = array();
		foreach($fullOrder->order_shipping_params->EMC_params as $key => $value) {
			$data = array(
				'products' => array()
			);

			foreach($fullOrder->products as $k => $product) {
				if($product->order_product_shipping_method != 'envoimoinscher')
					continue;

				if($key == $product->order_product_shipping_id){
					$ids_products = explode('@', $product->order_product_shipping_id);
					$warehouse_id = $ids_products[1];
					$data['products'][] = $product;
				}
			}
			if(!empty($data['products'])) {
				$data['warehouse_id'] = $warehouse_id;
				$data['shipping_id'] = $ids_products[0];
				$tab_products[] = $data;
			}
		}

		// Get the destination country
		//
		$czone_code_to = @$fullOrder->shipping_address->address_country;
		$query = 'SELECT zone_id, zone_code_2 FROM ' . hikashop_table('zone') . ' WHERE zone_name_english = ' . $db->Quote($czone_code_to);
		$db->setQuery($query);
		$czone = $db->loadObject();
		$country_to = $czone->zone_code_2;
		if($country_to == 'FX')
			$country_to = 'FR';

		$user_address_title = $fullOrder->shipping_address->address_title;
		$key = 'HIKA_TITLE_' . strtoupper($user_address_title);
		if($key != JText::_($key))
			$user_address_title = JText::_($key);

		$email = @$fullOrder->customer->user_email;
		$phone = @$fullOrder->shipping_address->address_telephone;

		// for each group we collect all informations to send the request makeOrder
		//
		foreach($tab_products as $key => $value) {
			$shipping_ids = explode('-', $value['shipping_id']);
			$this->pluginParams($shipping_ids[0]);

			// check if option make order is disabled
			if($this->plugin_params->make_order == 0)
				continue;

			// receiver informations
			if($this->plugin_params->destination_type == 'res' || ($this->plugin_params->destination_type == 'auto' && empty($fullOrder->shipping_address->address_company)))
				$user_type = 'particulier';
			else
				$user_type = 'entreprise';

			if(empty($email) || empty($phone))
				return;

			$to = array(
				'pays' => $country_to,
				'code_postal' => $fullOrder->shipping_address->address_post_code,
				'type' => $user_type,
				'ville' => $fullOrder->shipping_address->address_city,
				'adresse' => $fullOrder->shipping_address->address_street,
				'civilite' => $user_address_title,
				'prenom' => $fullOrder->shipping_address->address_firstname,
				'nom' => $fullOrder->shipping_address->address_lastname,
				'email' => $email,
				'tel' => $phone
		 	);

			// sender informations
		 	$admin_address_title = $this->plugin_params->sender_civility;
			$key = 'HIKA_TITLE_' . strtoupper($admin_address_title);
			if($key != JText::_($key))
				$admin_address_title = JText::_($key);

			$czone_code_from = @$this->plugin_params->sender_country;
			$query = 'SELECT zone_id, zone_code_2 FROM ' . hikashop_table('zone') . ' WHERE zone_namekey = '.$db->Quote($czone_code_from);
			$db->setQuery($query);
			$czone = $db->loadObject();
			$country_from = $czone->zone_code_2;
			if($country_from == 'FX')
				$country_from = 'FR';

		 	$from = array(
				'pays' => $country_from,
				'code_postal' => $this->plugin_params->sender_postcode,
				'type' => $this->plugin_params->type,
				'ville' => $this->plugin_params->sender_city,
				'adresse' => $this->plugin_params->sender_address,
				'civilite' => $admin_address_title,
				'prenom' => $this->plugin_params->sender_firstname,
				'nom' => $this->plugin_params->sender_lastname,
				'email' => $this->plugin_params->sender_email,
				'tel' => $this->plugin_params->sender_phone,
		 	);

		 	if($this->plugin_params->type == 'entreprise')
		 		$from['societe'] = $this->plugin_params->sender_company;

			$code = (int)$this->plugin_params->product_category;
			$shipping = explode(' / ', $shipping_ids[1]);

			$service = $shipping[1];
			$shipping = $value['shipping_id'] . '@' . $value['warehouse_id'];

			// We get the drop off point, pick up and code for the shipping method
			foreach($fullOrder->order_shipping_params->EMC_params as $k => $v) {
				if($k == $shipping) {
					$dropoff = explode('<br/>', $v['drop_off']);
					$pickup =  explode('<br/>', $v['pick_up']) ;
					$ope = $v['code'];
				}
			}

			$sending_type = strtolower($this->plugin_params->sending_type);
			$collection = $dropoff[0];
			$delivery = $pickup[0];

			// >rray that contains informations about sending
			$quotInfo = array(
				'collecte' => date('Y-m-d'),
				'delai' => 'aucun',
				'code_contenu' => $code,
				'type_emballage.emballage' => 1,
				'operateur' => $ope,
				'raison' => 'sale',
				'service' => $service,
				'collection_type' => $collection,
				'delivery_type' => $delivery,
				'depot.pointrelais' => '',
				'retrait.pointrelais' => '',
				$sending_type.'.description' => ''
			);

			// if there is drop off or pick up point
			if($collection == 'POST_OFFICE' || $collection == 'DROPOFF_POINT')
				$quotInfo['depot.pointrelais'] = $dropoff[2];

			if($delivery == 'PICKUP_POINT')
				$quotInfo['retrait.pointrelais'] = $pickup[2];

			// the availability for the collection of the package by the carrier, compulsory for some offers
			if(isset($this->plugin_params->start_availability) && !empty($this->plugin_params->start_availability))
				$quotInfo['disponibilite.HDE'] = $this->plugin_params->start_availability;

			if(isset($this->plugin_params->end_availability) && !empty($this->plugin_params->end_availability))
				$quotInfo['disponibilite.HLE'] = $this->plugin_params->end_availability;

			foreach($value['products'] as $product) {
				$quotInfo[$sending_type . '.description'] .= '  ' . $product->order_product_name;
			}

			$productClass = hikashop_get('class.product');
			$newOrder = new stdClass();

			// To create a new array order to use the function getData before makeOrder
			// It must be structured in the same way. We set in the array just the necessary data
			//
			foreach($value['products'] as $p) {
				$product = $productClass->get($p->product_id);
				if($product->product_parent_id != 0) {
					// Processing variant product
					//
					$parent = $productClass->get($product->product_parent_id);
					$newProduct = $parent;
					$newProduct->variants = array(0 => $product);
					if($product->product_width == 0 && $product->product_length == 0) {
						$product->product_width = $parent->product_width;
						$product->product_length = $parent->product_length;
						$product->product_height = $parent->product_height;
					}

					$product->cart_product_quantity = (int)$p->order_product_quantity;
					$product->product_weight_orig = $parent->product_weight;
					$product->product_weight_unit_orig = $parent->product_weight_unit;
					$product->product_dimension_unit_orig = $parent->product_dimension_unit;

					//
					$price = new stdClass();
					$price->unit_price = new stdClass();
					$price->unit_price->price_value_with_tax = $p->order_product_price + $p->order_product_tax;
					//
					$product->prices = array(0 => $price);

					//
					$newOrder->products[] = $newProduct;
				} else {
					// Processing main product
					//
					$product->cart_product_quantity = (int)$p->order_product_quantity;
					$product->product_weight_orig = $product->product_weight;
					$product->product_weight_unit_orig = $product->product_weight_unit;
					$product->product_dimension_unit_orig = $product->product_dimension_unit;

					//
					$price = new stdClass();
					$price->unit_price = new stdClass();
					$price->unit_price->price_value_with_tax = $p->order_product_price + $p->order_product_tax;
					//
					$product->prices = array(0 => $price);

					//
					$newOrder->products[] = $product;
				}
			}

			/*
			 * makeOrder = true in order not to get receiver and sender infos because we already did it
			 * and for the makeOrder we have to give more informations
			 */
			$data = $this->getData(null, $this, $newOrder, $sending_type, true);
			$total_price = (int)$data[0]["price"];
			unset($data[0]);

			$quotInfo[$sending_type.'.valeur'] = $total_price;

			$cotCl = new Env_Quotation(array(
				'user' => $this->plugin_params->emc_login,
				'pass' => $this->plugin_params->emc_password,
				'key' =>$this->plugin_params->api_key
			));
			$cotCl->setEnv($this->plugin_params->environment);

			$config = hikashop_config();
			$contentCl->setPlatformParams('hikashop', $config->get('version'), $config->get('version'));

			$cotCl->setPerson('expediteur', $from);
			$cotCl->setPerson('destinataire', $to);

			$cotCl->setType(
				$sending_type,
				$data
			);

			/*
			 * for shipments to the international we have to send more informations for each product
			 * we call the function setProforma of the library for this
			 */
			if($country_to != $country_from) {
				$infos_products = array();
				$i = 1;
				foreach($newOrder->products as $product) {
					if(isset($product->variants)) {
						$nb = $product->variants[0]->cart_product_quantity;
						$price = $product->variants[0]->prices[0]->unit_price->price_value_with_tax;
						$weight = $product->variants[0]->product_weight_orig;
					} else {
						$nb = $product->cart_product_quantity;
						$price = $product->prices[0]->unit_price->price_value_with_tax;
						$weight = $product->product_weight_orig;
					}

					$infos_products[$i++] = array(
						'description_en' => $product->product_name,
						'description_fr' => $product->product_name,
						'nombr' => $nb,
						'valeur' => $price,
						'origine' => $country_from,
						'poids' => $weight
					);
				}

				$cotCl->setProforma($infos_products);
			}

			// send request to make order !!
			$orderPassed = $cotCl->makeOrder($quotInfo, true);

			if(!$cotCl->curlError && !$cotCl->respError) {
				if($orderPassed) {
					// we add reference to order_shipping_params
					$fullOrder->order_shipping_params->EMC_params[$shipping]['reference'] = $cotCl->order['ref'];

					$update_order = new stdClass();
					$update_order->order_id = $fullOrder->order_id;
					$update_order->order_shipping_params = $fullOrder->order_shipping_params;
					$orderClass->save($update_order);
				}
				/*else {
					echo "The shipment was not properly executed. An error has occurred .";
				}*/
			} elseif($cotCl->respError) {
				// The request is invalid, we add message to order history
				$update_history = new stdClass();
				$update_history->history_order_id = $fullOrder->order_id;
				$update_history->history_created = time();
				$update_history->history_notified = 0;
				$update_history->history_ip = hikashop_getIP();
				$update_history->history_reason = 'EnvoiMoinsCher Error';

				$update_history->history_data = '';
				foreach($cotCl->respErrorsList as $m => $message) {
					$update_history->history_data .= $message['message'] . '<br/>';
				}

				$historyClass = hikashop_get('class.history');
				$historyClass->save($update_history);
			} else {
				// An error while sending the request, add message to order history
				//
				$update_history = new stdClass();
				$update_history->history_order_id = $fullOrder->order_id;
				$update_history->history_created = time();
				$update_history->history_notified = 0;
				$update_history->history_ip = hikashop_getIP();
				$update_history->history_reason = 'EnvoiMoinsCher Error';
				$update_history->history_data = $cotCl->curlErrorText;

				$historyClass = hikashop_get('class.history');
				$historyClass->save($update_history);
			}
		}
	}


	/**
	 * To show pick up points in the email and in the front
	 */
	function onAfterOrderProductsListingDisplay(&$order,$key) {
		//if EMC_params isnt set, the shipping plugin used for the order isnt envoimoinscher, in this case, we do anything
		if(!isset($order->order_shipping_params->EMC_params))
			return;

		if($key != 'email_notification_html' && $key != 'order_front_show')
			return;

		foreach($order->order_shipping_params->EMC_params as $key => $value){
			$pickup =  explode('<br/>', $value['pick_up']);

			// We display the name of the pick up point and the address
			if($pickup[0] == 'PICKUP_POINT') {
				$shipping = explode('@',$key);
				echo $shipping[0] . ', ' . JText::_('PICKUP_POINT') . ' : ' . $pickup[3] . '<br/>';
			}
		}
	}
}
