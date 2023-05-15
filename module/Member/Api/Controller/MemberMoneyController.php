<?php


namespace Module\Member\Api\Controller;

use ModStart\Core\Input\InputPackage;
use ModStart\Core\Input\Response;
use ModStart\Module\ModuleBaseController;
use Module\Member\Auth\MemberUser;
use Module\Member\Support\MemberLoginCheck;
use Module\Member\Util\MemberMoneyUtil;


class MemberMoneyController extends ModuleBaseController implements MemberLoginCheck
{
    
    public function get()
    {
        return Response::generateSuccessData([
            'total' => MemberMoneyUtil::getTotal(MemberUser::id())
        ]);
    }

    
    public function log()
    {
        $input = InputPackage::buildFromInput();
        $option = [];
        $searchInput = $input->getSearchInput();
        $type = $searchInput->getTrimString('type');
        switch ($type) {
            case 'income':
                $option['whereOperate'] = ['change', '>', '0'];
                break;
            case 'payout':
                $option['whereOperate'] = ['change', '<', '0'];
                break;
        }
        $paginateData = MemberMoneyUtil::paginateLog(
            MemberUser::id(),
            $input->getPage(),
            $input->getPageSize(),
            $option
        );
        return Response::generateSuccessPaginate(
            $input->getPage(),
            $input->getPageSize(),
            $paginateData
        );
    }
}
