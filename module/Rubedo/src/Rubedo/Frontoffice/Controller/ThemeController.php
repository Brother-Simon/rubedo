<?php
/**
 * Rubedo -- ECM solution
 * Copyright (c) 2014, WebTales (http://www.webtales.fr/).
 * All rights reserved.
 * licensing@webtales.fr
 *
 * Open Source License
 * ------------------------------------------------------------------------------------------
 * Rubedo is licensed under the terms of the Open Source GPL 3.0 license.
 *
 * @category   Rubedo
 * @package    Rubedo
 * @copyright  Copyright (c) 2012-2014 WebTales (http://www.webtales.fr)
 * @license    http://www.gnu.org/licenses/gpl.html Open Source GPL 3.0 license
 */
namespace Rubedo\Frontoffice\Controller;

use WebTales\MongoFilters\Filter;
use Zend\Mvc\Controller\AbstractActionController;
use Rubedo\Services\Manager;

/**
 * Controller providing access to images in gridFS
 *
 * Receveive Ajax Calls with needed ressources, send true or false for each of
 * them
 *
 *
 * @author jbourdin
 * @category Rubedo
 * @package Rubedo
 *
 */
class ThemeController extends AbstractActionController
{

    function indexAction()
    {
        $theme = $this->params()->fromRoute('theme');
        $filePath = $this->params()->fromRoute('filepath');
        $config = manager::getService('Config');

        /** @var \Rubedo\Collection\Directories $directoriesCollection */
        $directoriesCollection = Manager::getService('Directories');
        $filters = Filter::factory('And');
        $filters
            ->addFilter(
                Filter::factory('Value')
                    ->setName('parentId')
                    ->setValue('root')
            )
            ->addFilter(
                Filter::factory('Value')
                    ->setName('text')
                    ->setValue('theme')
            );
        $rootDirectory = $directoriesCollection->findOne($filters);
        $hasFileInDatabase = false;
        if (!empty($rootDirectory)) {
            $directories = $directoriesCollection->fetchAndSortAllChildren($rootDirectory['id']);
            $directoryExploded = explode('/', $theme . '/' . $filePath);
            $file = array_pop($directoryExploded);
            $dirWhereSearch = $this->recursivePathExist($directories, $directoryExploded);
            if (!empty($dirWhereSearch)) {
                /** @var \Rubedo\Collection\Dam $damCollection */
                $damCollection = Manager::getService('Dam');
                $media = $damCollection->findOne(
                    Filter::factory('And')
                        ->addFilter(
                            Filter::factory('Value')
                                ->setValue($file)
                                ->setName('title')
                        )
                        ->addFilter(
                            Filter::factory('Value')
                                ->setValue($dirWhereSearch)
                                ->setName('directory')
                        )
                );
                if (!empty($media)) {
                    $fileService = Manager::getService('Files');
                    $mimeType = $media['Content-Type'];
                    $gridFSFile = $fileService->findById($media['originalFileId']);
                    if ($gridFSFile instanceof \MongoGridFSFile) {
                        $hasFileInDatabase = true;
                    }
                }
            }
        }

        if (!$hasFileInDatabase) {

            $consolidatedFilePath = Manager::getService('FrontOfficeTemplates')->getFilePath($theme, $filePath);

            if (!$consolidatedFilePath) {
                $consolidatedFilePath = Manager::getService('FrontOfficeTemplates')->getFilePath("default", $filePath);
            }

            if ($consolidatedFilePath) {
                $extension = pathinfo($consolidatedFilePath, PATHINFO_EXTENSION);
                switch ($extension) {
                    case 'php':
                        throw new \Rubedo\Exceptions\NotFound('File does not exist');
                        break;
                    case 'js':
                        $mimeType = 'application/javascript';
                        break;
                    case 'css':
                        $mimeType = 'text/css';
                        break;
                    case 'html':
                        $mimeType = 'text/html';
                        break;
                    case 'json':
                        $mimeType = 'application/json';
                        break;
                    default:
                        if (class_exists('finfo')) {
                            $finfo = new \finfo(FILEINFO_MIME);
                            $mimeType = $finfo->file($consolidatedFilePath);
                        }
                        break;
                }

                $publicThemePath = APPLICATION_PATH . '/public/theme';
                $composedPath = $publicThemePath . '/' . $theme;
                if (!file_exists($composedPath)) {
                    mkdir($composedPath, 0777);
                }

                $composedPath = $composedPath . '/' . dirname($filePath);
                if (!file_exists($composedPath)) {
                    mkdir($composedPath, 0777, true);
                }
                $targetPath = $publicThemePath . '/' . $theme . '/' . $filePath;

                $content = file_get_contents($consolidatedFilePath);

                if (isset($config['rubedo_config']['minify']) && $config['rubedo_config']['minify'] == "1") {
                    if ($mimeType == 'text/css') {
                        $content = \Minify_CSS::minify($content, array(
                            'preserveComments' => false
                        ));
                    } elseif ($mimeType == 'application/javascript') {
                        $content = \JSMin::minify($content);
                    }
                }
            }

        }
        $response = new \Zend\Http\Response\Stream();
        $headers = array(
            'Pragma' => 'Public',
        );
        if (isset($mimeType)) {
            $headers['Content-type'] = $mimeType;
        }
        if (isset($config['rubedo_config']['cachePage']) && $config['rubedo_config']['cachePage'] == "1" && file_put_contents($targetPath, $content)) {
            $stream = fopen($targetPath, 'r');
        } elseif ($hasFileInDatabase) {
            $stream = $gridFSFile->getResource();
            $filelength = $gridFSFile->getSize();

            $headers = array_replace($headers, array(
                'Content-Length' => $filelength,
                'Content-Range' => "bytes 0-/$filelength",
            ));
            fseek($stream, 0);
            $response->setStream($stream);
        } elseif ($consolidatedFilePath) {
            $stream = fopen($consolidatedFilePath, 'r');
        } else {
            throw new \Rubedo\Exceptions\NotFound('File does not exist');
        }

        if (isset($config['rubedo_config']['cachePage']) && $config['rubedo_config']['cachePage'] == "1") {
            $headers['Cache-Control'] = 'public, max-age=' . 7 * 24 * 3600;
            $headers['Expires'] = date(DATE_RFC822, strtotime("7 day"));
        }

        $fileContent = stream_get_contents($stream);
        rewind($stream);
        if($fileContent) {
            $etag = hash("sha256", $fileContent);

            $headers['Etag'] = $etag;

            $browserEtag = $this->getRequest()->getHeader("If-None-Match");

            if($browserEtag && $browserEtag->getFieldValue() === $etag) {
                $response->setStatusCode(304);
            } else {
                $response->setStream($stream);
            }
        }

        $response->getHeaders()->addHeaders($headers);

        return $response;
    }

    protected function recursivePathExist($directories, $directoryExploded)
    {
        $currentDirName = array_shift($directoryExploded);
        foreach ($directories as $directory) {
            if ($directory['text'] === $currentDirName) {
                if (empty($directoryExploded)) {
                    return $directory['id'];
                } elseif (isset($directory['children'])) {
                    return $this->recursivePathExist($directory['children'], $directoryExploded);
                }
                return false;
            }
        }
        return false;
    }
}
