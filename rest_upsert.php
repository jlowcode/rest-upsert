<?php
/**
 * Submit or update data to a REST service
 *
 * @package     Joomla.Plugin
 * @subpackage  Fabrik.form.rest
 * @copyright   Copyright (C) 2005-2016  Media A-Team, Inc. - All rights reserved.
 * @license     GNU/GPL http://www.gnu.org/copyleft/gpl.html
 */

// No direct access
defined('_JEXEC') or die('Restricted access');

use Joomla\Utilities\ArrayHelper;

// Require the abstract plugin class
require_once COM_FABRIK_FRONTEND . '/models/plugin-form.php';

/**
 * Submit or update data to a REST service
 *
 * @package     Joomla.Plugin
 * @subpackage  Fabrik.form.rest
 * @since       3.0
 */
class PlgFabrik_FormRest_upsert extends PlgFabrik_Form
{
    protected $api_url;
    protected $sent = false;
    protected $method;
    protected $row_id;

    protected function getInfoData() {
        $params = $this->getParams();
        $w = new FabrikWorker;
        $formModel = $this->getModel();
        $listName = $formModel->getTableName();
        $worker = FabrikWorker::getPluginManager();
        $this->data = $this->getProcessData();

        if (!$this->shouldProcess('rest_upsert_conditon', null, $params)) {
            return false;
        }

        $this->api_url = $params->get('api_url', '');
        if (!$this->api_url) {
            return false;
        }

        // Used for updating previously added records. Need previous pk val to ensure new records are still created.
        $origData = $formModel->getOrigData();
        $origData = FArrayHelper::getValue($origData, 0, new stdClass);

        if (isset($origData->__pk_val)) {
            $this->data['origid'] = $origData->__pk_val;
        }

        $info = array();
        $authentication = new stdClass();
        $authentication->api_key = $params->get('api_key', '');
        $authentication->api_secret = $params->get('api_secret', '');
        $info['authentication'] = json_encode($authentication);

        $options = new stdClass();
        $options->list_id = $params->get('rest_upsert_list_id');
        
        //Fabrik API specification fields
        $options->type = 'site';
        
        $auxiliarElementId = $params->get('rest_upsert_auxiliar_id');
        $auxiliarElement = $worker->getElementPlugin($auxiliarElementId)->element->name;
        $valueElementAuxiliar = $formModel->formData[$auxiliarElement];

        $element = $params->get('rest_upsert_primary_key', '');
        $rowExists = $this->RowExists($options->list_id, $element, $valueElementAuxiliar);

        if(!isset($rowExists)) {
            return false;
        }

        // If row exists and "insert only", or row doesn't exist and "update only", bail out
        if (
            ($rowExists && $params->get('rest_upsert_insert_only', '0') === '1')
            ||
            (!$rowExists && $params->get('rest_upsert_insert_only', '0') === '2')
        ) 
        {
            return false;
        }

        if (!$rowExists) {
            $this->method = 'POST';
        } else {
            $options->row_id = $this->row_id;
            $this->method = 'PUT';
        }

        $fields = json_decode($params->get('rest_upsert_elements_list'));
        $keys = $fields->rest_upsert_element_key;
        $values = $fields->rest_upsert_element_value;
        $defaults = $fields->rest_upsert_element_default;

        $row = new stdClass();
        $i = 0;

        foreach ($keys as $key) {
            $search = str_replace("$listName.", '', $values[$i]);
            if(is_array($formModel->formData[$search])) {
                $value = $formModel->formData[$search][0];
            } else {
                $value = $formModel->formData[$search];
            }

            $row->$key = $value ? $value : $defaults[$i];
            $i++;
        }

        if ($this->method === 'PUT') {
            $options->row_data = $row;
        }
        else {
            $options->row_data = array($row);
        }
        
        $info['options'] = json_encode($options);

        return $info;
    }

    protected function sendData($info) {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_URL, $this->api_url);
        if ($this->method === 'PUT') {
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($info));
        }
        else {
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $info);
        }
        $response = curl_exec($curl);
        $response = json_decode($response);

        curl_close($curl);

        if ($response->error == true) {
            echo JText::sprintf('PLG_FORM_REST_UPSERT_ERROR');
        }
    }

    /**
     * See if row exists on API table
     * @param   string  $list_id          Table id
     * @param   string  $element          Placeholder Element
     * @param   string  $valueElement     Value Element
     *
     * @return bool
     */
    protected function RowExists($list_id, $element, $valueElement)
    {
        $params = $this->getParams();

        $info = array();
        $authentication = new stdClass();
        $authentication->api_key = $params->get('api_key', '');
        $authentication->api_secret = $params->get('api_secret', '');
        $info['authentication'] = json_encode($authentication);

        $filters = new stdClass();
        $filters->$element = $valueElement;

        $options = new stdClass();
        $options->list_id = $params->get('rest_upsert_list_id');
        $options->filters = $filters;

        //Fabrik API specification fields
        $options->data_type = 'list';
        $options->type = 'site';

        $info['options'] = json_encode($options);

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_URL, $this->api_url);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($info));

        $response = curl_exec($curl);
        $response = json_decode($response);
        curl_close($curl);

        if($response->error == true) {
            return null;
        }

        $rowExists = false;
        if($response->error == false && isset($response->data)) {
            $k = FabrikString::shortColName($element);
            $elementBase = FabrikString::rtrimword($element, '___'.$k);
            $elementId = $elementBase.'___id';

            $this->row_id = $response->data['0']->$elementId;
            $rowExists = true;
        }

        return $rowExists;
    }

	public function onAfterProcess()
	{
	    if (!$this->sent) {
            $this->sent = true;
            $info = $this->getInfoData();

            if ($info) {
                $this->sendData($info);
            }
        }
	}
}
