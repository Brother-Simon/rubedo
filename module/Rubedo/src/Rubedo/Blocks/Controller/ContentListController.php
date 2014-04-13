<?php
/**
 * Rubedo -- ECM solution
 * Copyright (c) 2013, WebTales (http://www.webtales.fr/).
 * All rights reserved.
 * licensing@webtales.fr
 *
 * Open Source License
 * ------------------------------------------------------------------------------------------
 * Rubedo is licensed under the terms of the Open Source GPL 3.0 license. 
 *
 * @category   Rubedo
 * @package    Rubedo
 * @copyright  Copyright (c) 2012-2013 WebTales (http://www.webtales.fr)
 * @license    http://www.gnu.org/licenses/gpl.html Open Source GPL 3.0 license
 */
namespace Rubedo\Blocks\Controller;

use Rubedo\Services\Manager;
use Zend\View\Model\JsonModel;
use WebTales\MongoFilters\Filter;

/**
 *
 * @author jbourdin
 * @category Rubedo
 * @package Rubedo
 */
class ContentListController extends AbstractController
{

    protected $_defaultTemplate = 'contentlist';

    public function indexAction()
    {
        $output = $this->_getList();
        $blockConfig = $this->getParamFromQuery('block-config');
        $output["blockConfig"] = $blockConfig;
        if (! $output["blockConfig"]['columns']) {
            $output["blockConfig"]['columns'] = 1;
        }
        $output['blockConfig']['showOnlyTitle']=isset($output['blockConfig']['showOnlyTitle']) ? $output['blockConfig']['showOnlyTitle'] : false;
        $output['blockConfig']['summaryHeight']=isset($output['blockConfig']['summaryHeight']) ? $output['blockConfig']['summaryHeight'] : false;
        
        if (isset($blockConfig['displayType']) && ! empty($blockConfig['displayType'])) {
            $template = Manager::getService('FrontOfficeTemplates')->getFileThemePath("blocks/" . $blockConfig['displayType'] . ".html.twig");
        } else {
            $template = Manager::getService('FrontOfficeTemplates')->getFileThemePath("blocks/" . $this->_defaultTemplate . ".html.twig");
        }
        $css = array();
        $js = array(
            $this->getRequest()->getBasePath() . '/' . Manager::getService('FrontOfficeTemplates')->getFileThemePath("js/contentList.js")
        );
        return $this->_sendResponse($output, $template, $css, $js);
    }

    protected function _getList()
    {
        // init services
        $this->_dataReader = Manager::getService('Contents');
        $this->_typeReader = Manager::getService('ContentTypes');
        $this->_queryReader = Manager::getService('Queries');
        
        // get params & context
        $blockConfig = $this->getParamFromQuery('block-config');
        $queryId = $this->getParamFromQuery('query-id', $blockConfig['query']);
        
        $output = $this->getParamFromQuery();
        // build query
        $filters = $this->_queryReader->getFilterArrayById($queryId);
        if ((isset($blockConfig['filterByUser']))&&($blockConfig['filterByUser'])){
            $currentUser=Manager::getService("CurrentUser")->getCurrentUser();
            if ($currentUser){
                $filters['filter']->addFilter(Filter::factory('Value')->setName('createUser.id')
                    ->setValue($currentUser['id']));
            } else {
                return array();
            }
        }

        if ($filters !== false) {
            $queryType = $filters["queryType"];
            $query = $this->_queryReader->getQueryById($queryId);
            
            if ($queryType === "manual" && $query != false && isset($query['query']) && is_array($query['query'])) {
                $contentOrder = $query['query'];
                $keyOrder = array();
                $contentArray = array();
                
                // getList
                $unorderedContentArray = $this->getContentList($filters, $this->setPaginationValues($blockConfig));
                
                foreach ($contentOrder as $value) {
                    foreach ($unorderedContentArray['data'] as $subKey => $subValue) {
                        if ($value === $subValue['id']) {
                            $keyOrder[] = $subKey;
                        }
                    }
                }
                
                foreach ($keyOrder as $value) {
                    $contentArray["data"][] = $unorderedContentArray["data"][$value];
                }
                
                $contentArray["page"] = $unorderedContentArray["page"];
                
                $nbItems = $unorderedContentArray["count"];
            } else {
            	$ismagic = isset($blockConfig['filterByUser']) ? $blockConfig['filterByUser'] : false;
                $contentArray = $this->getContentList($filters, $this->setPaginationValues($blockConfig), $ismagic);
                $nbItems = $contentArray["count"];
            }
        } else {
            $nbItems = 0;
        }
        
        if ($nbItems > 0) {
            $contentArray['page']['nbPages'] = (int) ceil(($nbItems) / $contentArray['page']['limit']);
            $contentArray['page']['limitPage'] = min(array(
                $contentArray['page']['nbPages'],
                10
            ));
            $typeArray = $this->_typeReader->getList();
            $contentTypeArray = array();
            foreach ($typeArray['data'] as $dataType) {
                if (isset($dataType['code']) && ! empty($dataType['code'])) {
                    $templateName = $dataType['code'] . ".html.twig";
                } else {
                    $templateName = preg_replace('#[^a-zA-Z]#', '', $dataType["type"]);
                    $templateName .= ".html.twig";
                }
                $path = Manager::getService('FrontOfficeTemplates')->getFileThemePath("/blocks/shortsingle/" . $templateName);
                if (Manager::getService('FrontOfficeTemplates')->templateFileExists($path)) {
                    $contentTypeArray[(string) $dataType['id']] = $path;
                } else {
                    $contentTypeArray[(string) $dataType['id']] = Manager::getService('FrontOfficeTemplates')->getFileThemePath("/blocks/shortsingle/default.html.twig");
                }
            }
            foreach ($contentArray['data'] as $vignette) {
                $fields = $vignette['fields'];
                $fields['title'] = $fields['text'];
                unset($fields['text']);
                $fields['id'] = (string) $vignette['id'];
                $fields['typeId'] = $vignette['typeId'];
                $fields['type'] = $contentTypeArray[(string) $vignette['typeId']];
                $fields["locale"] = Manager::getService('CurrentLocalization')->getCurrentLocalization();
                $data[] = $fields;
            }
            $output['blockConfig'] = $blockConfig;
            $output['blockConfig']['showOnlyTitle']=isset($output['blockConfig']['showOnlyTitle']) ? $output['blockConfig']['showOnlyTitle'] : false;
            $output['blockConfig']['summaryHeight']=isset($output['blockConfig']['summaryHeight']) ? $output['blockConfig']['summaryHeight'] : false;
            $output["data"] = $data;
            $output["query"]['type'] = $queryType;
            $output["query"]['id'] = $queryId;
            $output['prefix'] = $this->getParamFromQuery('prefix');
            $output["page"] = $contentArray['page'];
            
            $defaultLimit = isset($blockConfig['pageSize']) ? $blockConfig['pageSize'] : 6;
            $output['limit'] = $this->getParamFromQuery('limit', $defaultLimit);
            
            $singlePage = isset($blockConfig['singlePage']) ? $blockConfig['singlePage'] : $this->getParamFromQuery('current-page');
            $output['singlePage'] = $this->getParamFromQuery('single-page', $singlePage);
            $displayType = isset($blockConfig['displayType']) ? $blockConfig['displayType'] : $this->getParamFromQuery('displayType', null);
            $output['displayType'] = $displayType;
            $output['xhrUrl'] = $this->url()->fromRoute('blocks', array(
                'controller' => 'ContentList',
                'action' => 'xhr-get-items'
            ));
        }
        
        return $output;
    }

    public function xhrGetItemsAction()
    {
        $this->init();
        $twigVars = $this->_getList();
        
        $displayType = $this->getParamFromQuery('displayType', false);
        $columnsNb = $this->getParamFromQuery('columnsNb', '1');
        
        $twigVars["columnNb"] = $columnsNb;
        $twigVars['blockConfig']= array();
        $twigVars['blockConfig']['showOnlyTitle']=$this->getParamFromQuery('showOnlyTitle', false);
        $twigVars['blockConfig']['summaryHeight']=$this->getParamFromQuery('summaryHeight', false);
        if ($twigVars['blockConfig']['showOnlyTitle']==="false"){
            $twigVars['blockConfig']['showOnlyTitle']=false;
        }

        if ($displayType) {
            $template = Manager::getService('FrontOfficeTemplates')->getFileThemePath("blocks/contentList/" . $displayType . ".html.twig");
        } else {
            $template = Manager::getService('FrontOfficeTemplates')->getFileThemePath("blocks/contentList/list.html.twig");
        }
        
        $html = Manager::getService('FrontOfficeTemplates')->render($template, $twigVars);
        $pager = Manager::getService('FrontOfficeTemplates')->render(Manager::getService('FrontOfficeTemplates')->getFileThemePath("blocks/contentList/pager.html.twig"), $twigVars);
        
        $data = array(
            'html' => $html,
            'pager' => $pager
        );
        return new JsonModel($data);
    }

    /**
     * Return a list of contents based on Filters and Pagination
     *
     * @param \Webtales\MongoFilters\IFilter $filters            
     * @param array $pageData            
     * @return array
     */
    protected function getContentList($filters, $pageData, $ismagic = false)
    {	
        $filters["sort"] = isset($filters["sort"]) ? $filters["sort"] : array();
        $contentArray = $this->_dataReader->getOnlineList($filters["filter"], $filters["sort"], (($pageData['currentPage'] - 1) * $pageData['limit']) + $pageData['skip'], $pageData['limit'],$ismagic);
        $contentArray['page'] = $pageData;
        $contentArray['count'] = max(0, $contentArray['count'] - $pageData['skip']);
        return $contentArray;
    }

    protected function setPaginationValues($blockConfig)
    {
        $defaultLimit = isset($blockConfig['pageSize']) ? $blockConfig['pageSize'] : 6;
        $defaultSkip = isset($blockConfig['resultsSkip']) ? $blockConfig['resultsSkip'] : 0;
        $pageData['skip'] = $this->getParamFromQuery('skip', $defaultSkip);
        $pageData['limit'] = $this->getParamFromQuery('limit', $defaultLimit);
        $pageData['currentPage'] = $this->getParamFromQuery("page", 1);
        return $pageData;
    }

    public function getContentsAction()
    {
        $this->_dataReader = Manager::getService('Contents');
        $data = $this->getParamFromQuery();
        if (isset($data['block']['query'])) {
            
            $filters = Manager::getService('Queries')->getFilterArrayById($data['block']['query']);
            if ($filters !== false) {
                $contentList = $this->_dataReader->getOnlineList($filters['filter'], $filters["sort"], (($data['pagination']['page'] - 1) * $data['pagination']['limit']), intval($data['pagination']['limit']));
            } else {
                $contentList = array(
                    'count' => 0
                );
            }
            if ($contentList["count"] > 0) {
                foreach ($contentList['data'] as $content) {
                    $returnArray[] = array(
                        'text' => $content['text'],
                        'id' => $content['id']
                    );
                }
                $returnArray['total'] = count($returnArray);
                $returnArray["success"] = true;
            } else {
                $returnArray = array(
                    "success" => false,
                    "msg" => "No contents found"
                );
            }
        } else {
            $returnArray = array(
                "success" => false,
                "msg" => "No query found"
            );
        }
        
        return new JsonModel($returnArray);
        }
}
