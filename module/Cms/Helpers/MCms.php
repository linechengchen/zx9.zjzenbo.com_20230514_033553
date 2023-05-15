<?php

use Module\Cms\Util\CmsCatUtil;
use Module\Cms\Util\CmsContentUtil;
use Module\Cms\Util\CmsMemberPermitUtil;


class MCms
{
    
    public static function get($id)
    {
        return self::getContent($id);
    }

    
    public static function getContent($id)
    {
        return CmsContentUtil::get($id);
    }

    
    public static function getCatByUrl($catUrl)
    {
        return CmsCatUtil::getByUrl($catUrl);
    }

    
    public static function getCat($catId)
    {
        return CmsCatUtil::get($catId);
    }

    
    public static function listCatChildrenByUrl($catUrl)
    {
        $cat = CmsCatUtil::getByUrl($catUrl);
        return self::listCatChildren($cat['id']);
    }

    
    public static function paginateChildrenCatByUrl($catUrl)
    {
        return self::listCatChildrenByUrl($catUrl);
    }

    
    public static function listCatChildren($catId)
    {
        return CmsCatUtil::children($catId);
    }

    
    public static function paginateChildrenCat($catId)
    {
        return self::listCatChildren($catId);
    }

    
    public static function pageContentWithDataByCat($catId, $page = 1, $pageSize = 10, $option = [])
    {
        $cat = CmsCatUtil::get($catId);
        if (empty($cat)) {
            return [
                'total' => 0,
                'records' => [],
            ];
        }
        return CmsContentUtil::paginateCatsWithData([$cat], $page, $pageSize, $option);
    }

    
    public static function pageCatWithData($catId, $page = 1, $pageSize = 10, $option = [])
    {
        return self::pageContentWithDataByCat($catId, $page, $pageSize, $option);
    }

    
    public static function pageContentWithDataByCats($catIds, $page = 1, $pageSize = 10, $option = [])
    {
        $cats = array_values(array_filter(array_map(function ($o) {
            return CmsCatUtil::get($o);
        }, $catIds)));
        if (empty($cats)) {
            return [
                'total' => 0,
                'records' => [],
            ];
        }
        return CmsContentUtil::paginateCatsWithData($cats, $page, $pageSize, $option);
    }

    
    public static function pageCatsWithData($catIds, $page = 1, $pageSize = 10, $option = [])
    {
        return self::pageContentWithDataByCats($catIds, $page, $pageSize, $option);
    }

    
    public static function pageContentWithDataByCatUrl($catUrl, $page = 1, $pageSize = 10, $option = [])
    {
        $cat = CmsCatUtil::getByUrl($catUrl);
        if (empty($cat)) {
            return [
                'total' => 0,
                'records' => [],
            ];
        }
        return CmsContentUtil::paginateCatsWithData([$cat], $page, $pageSize, $option);
    }

    
    public static function pageCatWithDataByUrl($catUrl, $page = 1, $pageSize = 10, $option = [])
    {
        return self::pageContentWithDataByCatUrl($catUrl, $page, $pageSize, $option);
    }

    
    public static function pageContentWithDataByCatsUrl($catUrls, $page = 1, $pageSize = 10, $option = [])
    {
        $cats = array_values(array_filter(array_map(function ($o) {
            return CmsCatUtil::getByUrl($o);
        }, $catUrls)));
        if (empty($cats)) {
            return [
                'total' => 0,
                'records' => [],
            ];
        }
        return CmsContentUtil::paginateCatsWithData($cats, $page, $pageSize, $option);
    }

    
    public static function pageCatsWithDataByUrl($catUrls, $page = 1, $pageSize = 10, $option = [])
    {
        return self::pageContentWithDataByCatsUrl($catUrls, $page, $pageSize, $option);
    }

    
    public static function listContentByCatUrl($catUrl, $page = 1, $pageSize = 10, $option = [])
    {
        $paginateData = self::pageCatByUrl($catUrl, $page, $pageSize, $option);
        return $paginateData['records'];
    }

    
    public static function listCatByUrl($catUrl, $page = 1, $pageSize = 10, $option = [])
    {
        return self::listContentByCatUrl($catUrl, $page, $pageSize, $option);
    }

    
    public static function paginateCatByUrl($catUrl, $page = 1, $pageSize = 10, $option = [])
    {
        return self::listContentByCatUrl($catUrl, $page, $pageSize, $option);
    }

    
    public static function listContentByCat($catId, $page = 1, $pageSize = 10, $option = [])
    {
        $paginateData = self::pageCat($catId, $page, $pageSize, $option);
        return $paginateData['records'];
    }

    
    public static function listCat($catId, $page = 1, $pageSize = 10, $option = [])
    {
        return self::listContentByCat($catId, $page, $pageSize, $option);
    }

    
    public static function paginateCat($catId, $page = 1, $pageSize = 10, $option = [])
    {
        return self::listContentByCat($catId, $page, $pageSize, $option);
    }

    
    public static function pageContentByCatUrl($catUrl, $page = 1, $pageSize = 10, $option = [])
    {
        $cat = CmsCatUtil::getByUrl($catUrl);
        if (empty($cat)) {
            return [
                'total' => 0,
                'records' => [],
            ];
        }
        return CmsContentUtil::paginateCat($cat['id'], $page, $pageSize, $option);
    }

    
    public static function pageCatByUrl($catUrl, $page = 1, $pageSize = 10, $option = [])
    {
        return self::pageContentByCatUrl($catUrl, $page, $pageSize, $option);
    }

    
    public static function pageContentByCat($catId, $page = 1, $pageSize = 10, $option = [])
    {
        return CmsContentUtil::paginateCat($catId, $page, $pageSize, $option);
    }

    
    public static function pageCat($catId, $page = 1, $pageSize = 10, $option = [])
    {
        return self::pageContentByCat($catId, $page, $pageSize, $option);
    }

    
    public static function latestContentByCatUrl($cateUrl, $limit = 10)
    {
        $cat = self::getCatByUrl($cateUrl);
        return self::latestContentByCat($cat['id'], $limit);
    }

    
    public static function latestContentByCat($catId, $limit = 10)
    {
        $paginateData = CmsContentUtil::paginateCat($catId, 1, $limit);
        $records = $paginateData['records'];
        return $records;
    }

    
    public static function latestRecommendContentByCatUrl($cateUrl, $limit = 10)
    {
        $cat = self::getCatByUrl($cateUrl);
        return self::latestRecommendContentByCat($cat['id'], $limit);
    }

    
    public static function latestRecommendContentByCat($catId, $limit = 10)
    {
        $option = [
            'where' => [
                'isRecommend' => true,
            ]
        ];
        $paginateData = CmsContentUtil::paginateCat($catId, 1, $limit, $option);
        $records = $paginateData['records'];
        return $records;
    }

    
    public static function latestTopContentByCatUrl($cateUrl, $limit = 10)
    {
        $cat = self::getCatByUrl($cateUrl);
        return self::latestTopContentByCat($cat['id'], $limit);
    }

    
    public static function latestTopContentByCat($catId, $limit = 10)
    {
        $option = [
            'where' => [
                'isTop' => true,
            ]
        ];
        $paginateData = CmsContentUtil::paginateCat($catId, 1, $limit, $option);
        $records = $paginateData['records'];
        return $records;
    }

    
    public static function latestCat($catId, $limit = 10)
    {
        return self::latestContentByCat($catId, $limit);
    }

    
    public static function getContentData($record)
    {
        if (empty($record)) {
            return null;
        }
        $cat = self::getCat($record['catId']);
        return CmsContentUtil::getData($cat, $record['id']);
    }

    
    public static function getContentDataField($record, $fieldName, $default = null)
    {
        static $pool = [];
        if (empty($record)) {
            return null;
        }
        if (isset($pool[$record['id']])) {
            $data = $pool[$record['id']];
        } else {
            $data = self::getContentData($record);
            $pool[$record['id']] = $data;
        }
        return isset($data[$fieldName]) ? $data[$fieldName] : $default;
    }

    
    public static function nextContent($catId, $recordId)
    {
        return CmsContentUtil::nextOne($catId, $recordId);
    }

    
    public static function nextOne($catId, $recordId)
    {
        return self::nextContent($catId, $recordId);
    }

    
    public static function prevContent($catId, $recordId)
    {
        return CmsContentUtil::prevOne($catId, $recordId);
    }

    
    public static function prevOne($catId, $recordId)
    {
        return self::prevContent($catId, $recordId);
    }


    
    public static function canVisitCat($cat)
    {
        return CmsMemberPermitUtil::canVisitCat($cat);
    }

    
    public static function canPostCat($cat)
    {
        return CmsMemberPermitUtil::canPostCat($cat);
    }

}
