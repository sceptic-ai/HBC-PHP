<?php
class Shipfunk_Shipfunk_Model_Carrier extends Mage_Shipping_Model_Carrier_Abstract implements Mage_Shipping_Model_Carrier_Interface
{
    protected $_code = 'shipfunk';
    protected $_result = null;
    protected $_request = null;
    public function collectRates(Mage_Shipping_Model_Rate_Request $request)
    {
        if(!$this->getShipfunkConfigData('shipfunk','base','enabled')){
            return false;
        }
        $this->_request = $request;
        $postCode = $request->getDestPostcode();
        if(!preg_match("/^\d{4,}$/", $postCode)){
            return false;
        }
        $this->_result = $this->_getQuotes($postCode);
        return $this->getResult();
    }
    /**
     * Get result of request
     *
     * @return Result|null
     */
    public function getResult()
    {
        return $this->_result;
    }
    
    public function _getQuotes($postCode)
    {
        $cart = Mage::getSingleton('checkout/session')->getQuote();
        $customer = Mage::getSingleton('customer/session')->getCustomer();
        $request = $this->_request;
        $orderTempId = Mage::helper('shipfunk_shipfunk')->getTmpOrderId($cart->getId());
        $widthAttribute=$this->getShipfunkConfigData('shipfunk','dimensions','width_attribute');
        $widthAttribute = Mage::getModel('eav/entity_attribute')->load($widthAttribute)->getAttributeCode();
        $defaultWidth=$this->getShipfunkConfigData('shipfunk','dimensions','default_width');
        $heightAttribute=$this->getShipfunkConfigData('shipfunk','dimensions','height_attribute');
        $heightAttribute = Mage::getModel('eav/entity_attribute')->load($heightAttribute)->getAttributeCode();
        $defaultHeight=$this->getShipfunkConfigData('shipfunk','dimensions','default_height');
        $depthAttribute=$this->getShipfunkConfigData('shipfunk','dimensions','depth_attribute');
        $depthAttribute = Mage::getModel('eav/entity_attribute')->load($depthAttribute)->getAttributeCode();
        $defaultDepth=$this->getShipfunkConfigData('shipfunk','dimensions','default_depth');
        $defaultWeight=$this->getShipfunkConfigData('shipfunk','weight','default_weight');
        $defaultWeightUnit=$this->getShipfunkConfigData('shipfunk','weight','weight_unit');
        $weightUnitAttribute=$this->getShipfunkConfigData('shipfunk','weight','weight_unit_attribute');
        $weightUnitAttribute = Mage::getModel('eav/entity_attribute')->load($weightUnitAttribute)->getAttributeCode();
        $dimensionsUnitAttribute=$this->getShipfunkConfigData('shipfunk','dimensions','dimension_unit_attribute');
        $dimensionsUnitAttribute = Mage::getModel('eav/entity_attribute')->load($dimensionsUnitAttribute)->getAttributeCode();
        $defaultDimensionsUnit=$this->getShipfunkConfigData('shipfunk','dimensions','dimension_unit');
        
        $defaultWarehouse=$this->getShipfunkConfigData('shipfunk','warehouse','warehouse_name');
        $warehouseAttribute=$this->getShipfunkConfigData('shipfunk','warehouse','warehouse_attribute');
        $warehouseAttribute = Mage::getModel('eav/entity_attribute')->load($warehouseAttribute)->getAttributeCode();
        
        $shipfunkApi=Mage::helper('shipfunk_shipfunk')->getShipfunkApiInfo();
        $lang=$this->getShipfunkConfigData('shipfunk','base','language')==0?'Fi':'En';
        $all=null;
        $type=0;
        $data = array(
            "query" => array(
                "webshop" => array(
                    "api_key" => $shipfunkApi['key']
                ),
        
                "order" => array(
                    "discounts" => array(
                        "type" => $type, // 0: percentage | 1: fixed amount
                        "all" => $all
                    )
                ),
                 "customer" => array(
                    "first_name" => (!empty($customer->getFirstname()))?urlencode($customer->getFirstname()):"Temp fname",
                    "last_name" => (!empty($customer->getLastname()))?urlencode($customer->getLastname()):"Temp lname",
                    "street_address" => (!empty($request->getDestStreet()))?urlencode(str_replace("\n", '', $request->getDestStreet())):"Temp streetaddr",
                    "postal_code" => $postCode,
                    "city" => (!empty($request->getDestCity()))?urlencode($request->getDestCity()):"Temp city",
                    "country" => (!empty($request->getDestCountryId()))?$request->getDestCountryId():"FI",
                    "phone" => (!empty($request->getTelephone()))?urlencode($request->getTelephone()):"1234567890",
                    "email" => (!empty($customer->getEmail()) && !filter_var($customer->getEmail(), FILTER_VALIDATE_EMAIL) === false)?$customer->getEmail():null
                )
            )
        );
        $attributeCode=array(
            'width'=>$widthAttribute,
            'height'=>$heightAttribute,
            'depth'=>$depthAttribute,
            'weightUnit'=>$weightUnitAttribute,
            'dimensionsUnit'=>$dimensionsUnitAttribute,
            'warehouse'=>$warehouseAttribute
        );
        $attributeCodes=array_filter($attributeCode);
        
        $data['query']['order']['language']=$lang;
        $data['query']['order']['monetary']=array(
            "currency" => $cart->getQuoteCurrencyCode(),
            "value" => $cart->getGrandTotal()
        );
        $num = 0;
        $items = empty($cart->getAllItems())?$request->getAllItems():$cart->getAllItems();
        if ($items) {
            foreach ($items as $item) {
                if ($item->getProduct()->isVirtual() || $item->getParentItem()) {
                    continue;
                }
                $collection = Mage::getModel('catalog/product')->load($item->getProductId());
                $categoryIds=$collection->getCategoryIds();
                foreach($categoryIds as $categoryId){
                    $category=Mage::getModel('catalog/category')->load($categoryId);
                    $categoryName=$category->getName();
                    break;
                }
                
                $attributes =$collection->getAttributes();
                foreach($attributeCodes as $k=>$attributeCode){
                    $attr[$k]=$attributes[$attributeCode]->getFrontend()->getValue($collection);
                }
                $weight=empty($item->getWeight())?$defaultWeight:$item->getWeight();
                $dimensionUnit=empty($dimensionsUnitAttribute)?$defaultDimensionsUnit:$attr['dimensionsUnit'];
                $weightUnit=empty($weightUnitAttribute)?$defaultWeightUnit:$attr['weightUnit'];
                $width=empty($widthAttribute)?$defaultWidth:$attr['width'];
                $depth=empty($depthAttribute)?$defaultDepth:$attr['depth'];
                $height=empty($heightAttribute)?$defaultHeight:$attr['height'];
                $warehouse=empty($warehouseAttribute)?$defaultWarehouse:$attr['warehouse'];
                /* if($warehouseAttribute==""){
                    $warehouse=$defaultWarehouse;
                }else{
                    $warehouse=$attr['warehouse'];
                } */
                //the product information
                $data['query']['order']["products"][$num] = array(
                    "amount" => $item->getQty(),
                    "code" => $item->getSku(),
                    "name" => $item->getName(),
                    "category" => $categoryName,
                    "weight" => array(
                        "unit" => $weightUnit,
                        "amount" => $weight
                    ),
                    "dimensions" => array(
                        "unit" => $dimensionUnit,
                        "width" => $width,
                        "depth" => $depth,
                        "height" => $height
                    ),
                    
                    "monetary_value" => round($item->getData('base_row_total_incl_tax'),2),
                    "warehouse" => $warehouse
                );
                $num ++;
            }
        }
        $responseBody=$this->connectToShipfunk($data,$shipfunkApi['url'],$orderTempId,$shipfunkApi['key']);
        return $this->_parseXmlResponse($responseBody,$orderTempId);
    }
    public function _parseXmlResponse($response,$orderTempId)
    {
        $request = $this->_request;
        if(isset($response['response']) && $response['response']){
            $response = $response['response'];
        }else{
            /* $result = Mage::getModel('shipping/rate_result');
            $message=Mage::getStoreConfig('carrier/shipfunk/specificerrmsg');
            $title=Mage::getStoreConfig('carrier/shipfunk/title');
            $error=Mage::getModel('shipping/rate_result_error');
            $error->setCode('shipfunk_error')
                ->setCarrier('shipfunk')
                ->setCarrierTitle($title)
                ->setErrorMessage($message);
            $result->append($error);
            return $result; */
            return false;
        }
        
        $_shipFunkRates=$shipFunkRates= array();
        $categoriesType=$this->getShipfunkConfigData('shipfunk','categories','order_delivery_options_by_the_categories');
        if($categoriesType==0){
            foreach ($response as $item){
                $carrier_title=$item['Companyname'];
                foreach ($item['Options'] as $methods) {
                    $price=$methods['customer_price'];
                    $shipFunkRates[$carrier_title][]=array(
                        'carrier' => $this->_code,
                        //'flag'=>$methods['carriercode'],
                        'carrier_title' => $carrier_title,
                        'method' => $methods['carriercode'],
                        'method_title' => $methods['productname'].' ('.$methods['delivtime'].' ' .Mage::helper('shipfunk')->__("days"). ')',
                        'price' => $price
                    );
                    $_shipFunkRates[$methods['carriercode']]['calculated_price'] = $methods['calculated_price'];
                    $_shipFunkRates[$methods['carriercode']]['customer_price'] = $methods['customer_price'];
                    $_shipFunkRates[$methods['carriercode']]['info'] = $methods['info'];
                    if(!isset($methods['haspickups'])){continue;}
                    foreach ((array)$methods['haspickups'] as $methodInfo){
                        $method_title = '';
                        if($methodInfo['pickup_name'] && $methodInfo['pickup_addr'] && $methodInfo['pickup_postal'] && $methodInfo['pickup_city'] && $methodInfo['pickup_country']){
                            $method_title=$methodInfo['pickup_name'].', '.$methodInfo['pickup_addr'].', '.$methodInfo['pickup_postal'].', '.$methodInfo['pickup_city'].', '.$methodInfo['pickup_country'];
                        }/* else{
                        $method_title = $item['Carriercode'].'_'.$methods['carriercode'];
                        } */
                        if($methodInfo['pickup_id']){
                            $method=$item['Carriercode'].'_'.$methods['carriercode'].'_'.$methodInfo['pickup_id'];
                        }else{
                            $method=$item['Carriercode'].'_'.$methods['carriercode'];
                        }
                        $method_description=$methods['info'];
                        if(!$method_title){
                            continue;
                        }
                        $_shipFunkRates[$methods['carriercode']]['pickups'][] = array(
                            'carrier' => $methods['productname'],
                            'carrier_title' => $carrier_title,
                            'method' => $method,
                            'method_title' => $method_title,
                            'method_description' => $method_description,
                            'price' => $price
                        );
                    }
                }
            }
        }else{
            foreach ($response as $item){
                $carrier_title=$item['Companyname'];
                foreach ($item['Options'] as $methods) {
                    $price=$methods['customer_price'];
                    $title=$methods['category'];
                    if($title==null){
                        $title = Mage::helper('shipfunk')->__("Other");
                    }
                    $shipFunkRates[$carrier_title][]=array(
                        'carrier' => $this->_code,
                        //'flag'=>$methods['carriercode'],
                        'carrier_title' => $title,
                        'method' => $methods['carriercode'],
                        'method_title' => $carrier_title.' - '.$methods['productname'].' ('.$methods['delivtime'].' ' .Mage::helper('shipfunk')->__("days"). ')',
                        'price' => $price
                    );
                    $_shipFunkRates[$methods['carriercode']]['calculated_price'] = $methods['calculated_price'];
                    $_shipFunkRates[$methods['carriercode']]['customer_price'] = $methods['customer_price'];
                    $_shipFunkRates[$methods['carriercode']]['info'] = $methods['info'];
                    if(!isset($methods['haspickups'])){continue;}
                    foreach ((array)$methods['haspickups'] as $methodInfo){
                        $method_title = '';
                        if($methodInfo['pickup_name'] && $methodInfo['pickup_addr'] && $methodInfo['pickup_postal'] && $methodInfo['pickup_city'] && $methodInfo['pickup_country']){
                            $method_title = $methodInfo['pickup_name'].', '.$methodInfo['pickup_addr'].', '.$methodInfo['pickup_postal'].', '.$methodInfo['pickup_city'].', '.$methodInfo['pickup_country'];
                        }/* else{
                        $method_title = $item['Carriercode'].'_'.$methods['carriercode'];
                        } */
                        if($methodInfo['pickup_id']){
                            $method=$item['Carriercode'].'_'.$methods['carriercode'].'_'.$methodInfo['pickup_id'];
                        }else{
                            $method=$item['Carriercode'].'_'.$methods['carriercode'];
                        }
                        $method_description = $methods['info'];
                        if(!$method_title){
                            continue;
                        }
                        $_shipFunkRates[$methods['carriercode']]['pickups'][]=array(
                            'carrier' => $title,
                            'carrier_title' => $carrier_title,
                            'method' => $method,
                            'method_title' => $method_title,
                            'method_description' => $method_description,
                            'price' => $price
                        );
                    }
                }
            }
        }
        Mage::getSingleton('core/session')->setData('shipfunkpickups',json_encode($_shipFunkRates));
        $groupResult = array();
        foreach ($shipFunkRates as $title=>$items){
            $result = Mage::getModel('shipping/rate_result');
            foreach ($items as $item){
                $method = Mage::getModel('shipping/rate_result_method');
                $method->setData($item);
                $result->append($method);
            }
            $groupResult[] = $result;
        }
        return $groupResult;
    }
    public function getAjaxQuotes($postCode,$code = '')
    {
        $address = Mage::getSingleton('checkout/session')->getQuote()->getShippingAddress();
        $cart = Mage::getSingleton('checkout/session')->getQuote();
        $customer = Mage::getSingleton('customer/session')->getCustomer();
        $orderTempId = Mage::helper('shipfunk_shipfunk')->getTmpOrderId($cart->getId());
        $apiProductionKey=$this->getShipfunkConfigData('shipfunk','base','api_production');
        $apiDevelopmentKey=$this->getShipfunkConfigData('shipfunk','base','api_development');
        $environment=$this->getShipfunkConfigData('shipfunk','base','environment');
        $widthAttribute=$this->getShipfunkConfigData('shipfunk','dimensions','width_attribute');
        $widthAttribute = Mage::getModel('eav/entity_attribute')->load($widthAttribute)->getFrontendLabel();
        $defaultWidth=$this->getShipfunkConfigData('shipfunk','dimensions','default_width');
        $heightAttribute=$this->getShipfunkConfigData('shipfunk','dimensions','height_attribute');
        $heightAttribute = Mage::getModel('eav/entity_attribute')->load($heightAttribute)->getFrontendLabel();
        $defaultHeight=$this->getShipfunkConfigData('shipfunk','dimensions','default_height');
        $depthAttribute=$this->getShipfunkConfigData('shipfunk','dimensions','depth_attribute');
        $depthAttribute = Mage::getModel('eav/entity_attribute')->load($depthAttribute)->getFrontendLabel();
        $defaultDepth=$this->getShipfunkConfigData('shipfunk','dimensions','default_depth');
        $defaultWeight=$this->getShipfunkConfigData('shipfunk','weight','default_weight');
        $defaultWeightUnit=$this->getShipfunkConfigData('shipfunk','weight','weight_unit');
        $weightUnitAttribute=$this->getShipfunkConfigData('shipfunk','weight','weight_unit_attribute');
        $weightUnitAttribute = Mage::getModel('eav/entity_attribute')->load($weightUnitAttribute)->getFrontendLabel();
        $dimensionsUnitAttribute=$this->getShipfunkConfigData('shipfunk','dimensions','dimension_unit_attribute');
        $dimensionsUnitAttribute = Mage::getModel('eav/entity_attribute')->load($dimensionsUnitAttribute)->getFrontendLabel();
        $defaultDimensionsUnit=$this->getShipfunkConfigData('shipfunk','dimensions','dimension_unit');
    
        $defaultWarehouse=$this->getShipfunkConfigData('shipfunk','warehouse','warehouse_name');
        $warehouseAttribute=$this->getShipfunkConfigData('shipfunk','warehouse','warehouse_attribute');
        $warehouseAttribute = Mage::getModel('eav/entity_attribute')->load($warehouseAttribute)->getFrontendLabel();
        $shipfunkApi = Mage::helper('shipfunk_shipfunk')->getShipfunkApiInfo();
        $lang=$this->getShipfunkConfigData('shipfunk','base','language')==0?'Fi':'En';
        $data = array(
            "query" => array(
                "webshop" => array(
                    "api_key" => $shipfunkApi['key']
                ),
    
                "order" => array(
                    "discounts" => array(
                        "type" => 0, // 0: percentage | 1: fixed amount
                        "all" => null
                    )
                ),
                "customer" => array(
                    "first_name" => (!empty($customer->getFirstname()))?urlencode($customer->getFirstname()):"Temp fname",
                    "last_name" => (!empty($customer->getLastname()))?urlencode($customer->getLastname()):"Temp lname",
                    "street_address" => (!empty($address->getDestStreet()))?urlencode(str_replace("\n", '', $address->getDestStreet())):"Temp streetaddr",
                    "postal_code" => $postCode,
                    "city" => (!empty($address->getDestCity()))?urlencode($address->getDestCity()):"Temp city",
                    "country" => (!empty($address->getDestCountryId()))?$address->getDestCountryId():"FI",
                    "phone" => (!empty($address->getTelephone()))?urlencode($address->getTelephone()):"1234567890",
                    "email" => (!empty($customer->getEmail()) && !filter_var($customer->getEmail(), FILTER_VALIDATE_EMAIL) === false)?$customer->getEmail():null
                )
            )
        );

        $items = $cart->getAllItems();
        $data['query']['order']['language']=$lang;
        $data['query']['order']['monetary']=array(
            "currency" => $cart->getQuoteCurrencyCode(),
            "value" => $cart->getGrandTotal()
        );
        $num = 0;
        if ($items) {
            foreach ($items as $item) {
                if ($item->getProduct()->isVirtual() || $item->getParentItem()) {
                    continue;
                }
    
                //get dimension  unit
                if($dimensionsUnitAttribute!=""){
                    $dimensionsUnitAttribute=str_replace(' ','',$dimensionsUnitAttribute);
                    $str="get".$dimensionsUnitAttribute.'()';
                    $dimensionUnit=$item->$str;
                }else{
                    $dimensionUnit=$defaultDimensionsUnit;
                }
                //get weight unit
                if($weightUnitAttribute!=""){
                    $weightUnitAttribute=str_replace(' ','',$weightUnitAttribute);
                    $str="get".$weightUnitAttribute.'()';
                    $weightUnitAttribute=$item->$str;
                }else{
                    $weightUnit=$defaultWeightUnit;
                }
                //get product attribute of weight
                $weight=$item->getWeight();
                if($weight==""){
                    $weight=$defaultWeight;
                }
                //get product attribute of width
                if($widthAttribute==""){
                    $width=$defaultWidth;
                }else{
                    $widthAttribute=str_replace(' ','',$widthAttribute);
                    $str="get".$widthAttribute.'()';
                    $width=$item->$str;
    
                }
                //get product attribute of depth
                if($depthAttribute==""){
                    $depth=$defaultWidth;
                }else{
                    $depthAttribute=str_replace(' ','',$depthAttribute);
                    $str="get".$depthAttribute.'()';
                    $depth=$item->$str;
                }
                //get product attribute of height
                if($heightAttribute==""){
                    $height=$defaultHeight;
                }else{
                    $heightAttribute=str_replace(' ','',$heightAttribute);
                    $str="get".$heightAttribute.'()';
                    $height=$item->$str;
                }
                //get product attribute of warehouse
                if($warehouseAttribute==""){
                    $warehouse=$defaultWarehouse;
                }else{
                    $warehouseAttribute=str_replace(' ','',$warehouseAttribute);
                    $str="get".$warehouseAttribute.'()';
                    $warehouse=$item->$str;
                }
                //the product information
                $data['query']['order']["products"][$num] = array(
                    "amount" => $item->getQty(),
                    "code" => $item->getSku(),
                    "name" => urlencode($item->getName()),
                    "category" => urlencode("Test Category A"),
                    "weight" => array(
                        "unit" => $weightUnit,
                        "amount" => $weight
                    ),
                    "dimensions" => array(
                        "unit" => $dimensionUnit,
                        "width" => $width,
                        "depth" => $depth,
                        "height" => $height
                    ),
    
                    "monetary_value" => round($item->getData('base_row_total_incl_tax'),2),
                    "warehouse" => $warehouse
                );
                $num ++;
            }
        }
        //Mage::getSingleton('core/session')->setData('shipfunkpickupatas',json_encode($data));
        $responseBody=$this->connectToShipfunk($data,$shipfunkApi['url'],$orderTempId,$shipfunkApi['key']);
        if(!isset($responseBody['response'])){
            return $responseBody['Error']['Message'];
        }
        $response = $responseBody['response'];
        $_shipFunkRates=$shipFunkRates= array();
        $categoriesType=$this->getShipfunkConfigData('shipfunk','categories','order_delivery_options_by_the_categories');
        if($categoriesType==0){
            foreach ($response as $item){
                $carrier_title=$item['Companyname'];
                foreach ($item['Options'] as $methods) {
                    if($code && $methods['carriercode'] != $code){
                        continue;
                    }
                    $price=$methods['customer_price'];
                    $_shipFunkRates[$methods['carriercode']]['calculated_price'] = $methods['calculated_price'];
                    $_shipFunkRates[$methods['carriercode']]['customer_price'] = $methods['customer_price'];
                    $_shipFunkRates[$methods['carriercode']]['info'] = $methods['info'];
                    if(!isset($methods['haspickups'])){continue;}
                    foreach ((array)$methods['haspickups'] as $methodInfo){
                        $method_title = '';
                        if($methodInfo['pickup_name'] && $methodInfo['pickup_addr'] && $methodInfo['pickup_postal'] && $methodInfo['pickup_city'] && $methodInfo['pickup_country']){
                            $method_title=$methodInfo['pickup_name'].', '.$methodInfo['pickup_addr'].', '.$methodInfo['pickup_postal'].', '.$methodInfo['pickup_city'].', '.$methodInfo['pickup_country'];
                        }/* else{
                        $method_title = $item['Carriercode'].'_'.$methods['carriercode'];
                        } */
                        if($methodInfo['pickup_id']){
                            $method=$item['Carriercode'].'_'.$methods['carriercode'].'_'.$methodInfo['pickup_id'];
                        }else{
                            $method=$item['Carriercode'].'_'.$methods['carriercode'];
                        }
                        $method_description=$methods['info'];
                        if(!$method_title){
                            continue;
                        }
                        $_shipFunkRates[$methods['carriercode']]['pickups'][]=array(
                            'carrier' => $methods['productname'],
                            'carrier_title' => $carrier_title,
                            'method' => $method,
                            'method_title' => $method_title,
                            'method_description' => $method_description,
                            'price' => $price
                        );
                    }
                }
            }
        }else{
            foreach ($response as $item){
                $carrier_title=$item['Companyname'];
                foreach ($item['Options'] as $methods) {
                    if($code && $methods['carriercode'] != $code){
                        continue;
                    }
                    $price=$methods['customer_price'];
                    $title=$methods['category'];
                    if($title==null){
                        $title=$carrier_title;
                    }
                    $_shipFunkRates[$methods['carriercode']]['calculated_price'] = $methods['calculated_price'];
                    $_shipFunkRates[$methods['carriercode']]['customer_price'] = $methods['customer_price'];
                    $_shipFunkRates[$methods['carriercode']]['info'] = $methods['info'];
                    if(!isset($methods['haspickups'])){continue;}
                    foreach ((array)$methods['haspickups'] as $methodInfo){
                        $method_title = '';
                        if($methodInfo['pickup_name'] && $methodInfo['pickup_addr'] && $methodInfo['pickup_postal'] && $methodInfo['pickup_city'] && $methodInfo['pickup_country']){
                            $method_title = $methodInfo['pickup_name'].', '.$methodInfo['pickup_addr'].', '.$methodInfo['pickup_postal'].', '.$methodInfo['pickup_city'].', '.$methodInfo['pickup_country'];
                        }/* else{
                        $method_title = $item['Carriercode'].'_'.$methods['carriercode'];
                        } */
                        if($methodInfo['pickup_id']){
                            $method=$item['Carriercode'].'_'.$methods['carriercode'].'_'.$methodInfo['pickup_id'];
                        }else{
                            $method=$item['Carriercode'].'_'.$methods['carriercode'];
                        }
                        $method_description = $methods['info'];
                        if(!$method_title){
                            continue;
                        }
                        $_shipFunkRates[$methods['carriercode']]['pickups'][]=array(
                            'carrier' => $title,
                            'carrier_title' => $carrier_title,
                            'method' => $method,
                            'method_title' => $method_title,
                            'method_description' => $method_description,
                            'price' => $price
                        );
                    }
                }
            }
        }
        return $_shipFunkRates;
    }
    public function getShipfunkConfigData($sessionName,$groupName,$field)
    {
        $path=$sessionName.'/'.$groupName.'/'.$field;
        $storeId = Mage::app()->getStore()->getStoreId();
        return Mage::getStoreConfig($path, $storeId);
    }
    public function getAllowedMethods()
    {
        return array(
            'shipfunk' => 'Shipfunk'
        );
    }
    public function connectToShipfunk($data,$apiUrl,$orderTempId,$key){
        $dataString = json_encode($data);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl."get_delivery_options/true/json/json/".$orderTempId);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array("Authorization: {$key}"));
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['sf_get_delivery_options' => $dataString]));
        $server_output = curl_exec ($ch);
        curl_close ($ch);
        return json_decode($server_output,true);
    }
}
