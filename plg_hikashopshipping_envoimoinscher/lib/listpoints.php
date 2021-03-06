<?php
/**
 * EnvoiMoinsCher API list points class.
 * 
 * It can be used to load informations about parcel points (for pickup and dropoff)
 * @package Env
 * @author EnvoiMoinsCher <dev@envoimoinscher.com>
 * @version 1.0
 */

class Env_ListPoints extends Env_WebService {

	/**
	 * Contains Points informations.
	 *
	 * <samp>
	 * Structure :<br>
	 * $listPoints[x] 	=> array(<br>
	 * &nbsp;&nbsp;['code'] 				=> data<br>
	 * &nbsp;&nbsp;['name'] 				=> data<br>
	 * &nbsp;&nbsp;['address'] 		=> data<br>
	 * &nbsp;&nbsp;['city'] 				=> data<br>
	 * &nbsp;&nbsp;['zipcode'] 		=> data<br>
	 * &nbsp;&nbsp;['country'] 		=> data<br>
	 * &nbsp;&nbsp;['description'] => data<br>
	 * &nbsp;&nbsp;['days'][x]			=> array(<br>
	 * &nbsp;&nbsp;&nbsp;&nbsp;['weekday'] 		=> data<br>
	 * &nbsp;&nbsp;&nbsp;&nbsp;['open_am'] 		=> data<br>
	 * &nbsp;&nbsp;&nbsp;&nbsp;['close_am']		=> data<br>
	 * &nbsp;&nbsp;&nbsp;&nbsp;['open_pm'] 		=> data<br>
	 * &nbsp;&nbsp;&nbsp;&nbsp;['close_pm']	 	=> data<br>
	 * &nbsp;&nbsp;)<br>
	 * )
	 * </samp>
	 * @access public
	 * @var array
	 */
	public $listPoints = array();

	/**
	 * Function loads all points.
	 * @param $ope Folder ope
	 * @param $infos Parameters for the request to the api<br>
	 * <samp>
	 * Example : <br>
	 * array(<br>
	 * &nbsp;&nbsp;"srv_code" => "RelaisColis", <br>
	 * &nbsp;&nbsp;"collecte" => "exp", <br>
	 * &nbsp;&nbsp;"pays" => "FR", <br>
	 * &nbsp;&nbsp;"cp" => "75011", <br>
	 * &nbsp;&nbsp;"ville" => "PARIS"<br>
	 * )
	 * @access public
	 * @return Void
	 */
	public function getListPoints($ope, $infos) {
		$this->param = $infos;
		$this->setGetParams(array());
		$this->setOptions(array("action" => "/api/v1/$ope/listpoints"));
		$this->doListRequest();
	}

	/**
	 * Function executes points request and prepares the $listPoints array.
	 * @access private
	 * @return Void
	 */
	private function doListRequest() {
		$source = parent::doRequest();

		/* Uncomment if ou want to display the XML content */
		//echo '<textarea>'.$source.'</textarea>';

		/* We make sure there is an XML answer and try to parse it */
		if($source !== false) {
			parent::parseResponse($source);
			if(count($this->respErrorsList) == 0) {

				/* The XML file is loaded, we now gather the datas */
				$points = $this->xpath->query("/points/point");
				foreach($points as $pointIndex => $point){
					$pointInfo = array(
						'code' => $this->xpath->query('./code',$point)->item(0)->nodeValue,
						'name' => $this->xpath->query('./name',$point)->item(0)->nodeValue,
						'address' => $this->xpath->query('./address',$point)->item(0)->nodeValue,
						'city' => $this->xpath->query('./city',$point)->item(0)->nodeValue,
						'zipcode' => $this->xpath->query('./zipcode',$point)->item(0)->nodeValue,
						'country' => $this->xpath->query('./country',$point)->item(0)->nodeValue,
						'phone' => $this->xpath->query('./phone',$point)->item(0)->nodeValue,
						'description' => $this->xpath->query('./description',$point)->item(0)->nodeValue,
						'days' => array()
						);
					$days = $this->xpath->query('./schedule/day',$point);
					foreach($days as $dayIndex => $day){
						$pointInfo['days'][$dayIndex] = array(
							'weekday' => $this->xpath->query('./weekday',$day)->item(0)->nodeValue,
							'open_am' => $this->xpath->query('./open_am',$day)->item(0)->nodeValue,
							'close_am' => $this->xpath->query('./close_am',$day)->item(0)->nodeValue,
							'open_pm' => $this->xpath->query('./open_pm',$day)->item(0)->nodeValue,
							'close_pm' => $this->xpath->query('./close_pm',$day)->item(0)->nodeValue,
							);
					}
					$this->listPoints[$pointIndex] = $pointInfo;
				}
			}
		}
	}

}