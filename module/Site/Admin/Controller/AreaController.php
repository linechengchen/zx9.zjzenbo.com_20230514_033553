<?php

namespace Module\Site\Admin\Controller;

use Illuminate\Routing\Controller;
use ModStart\Admin\Concern\HasAdminQuickCRUD;
use ModStart\Admin\Layout\AdminConfigBuilder;
use ModStart\Core\Assets\AssetsUtil;
use ModStart\Field\AbstractField;
use ModStart\Field\AutoRenderedFieldValue;
use ModStart\Module\ModuleManager;
use Module\Member\Config\MemberAdminList;
use Module\Vendor\Provider\SiteTemplate\SiteTemplateProvider;

class AreaController extends Controller
{
    use HasAdminQuickCRUD;
    public function setting(AdminConfigBuilder $builder)
    {
        $builder->
            init('area')
            ->field(function ($builder) {

                $builder->id('id', 'ID');
                MemberAdminList::callGridField($builder);
                $builder->display('avatar', '头像')->hookRendering(function (AbstractField $field, $item, $index) {
                    $avatarSmall = AssetsUtil::fixOrDefault($item->avatar, 'asset/image/avatar.svg');
                    $avatarBig = AssetsUtil::fixOrDefault($item->avatarBig, 'asset/image/avatar.svg');
                    return AutoRenderedFieldValue::make("<a href='$avatarBig' class='tw-inline-block' data-image-preview>
                        <img src='$avatarSmall' class='tw-rounded-full tw-w-8 tw-h-8 tw-shadow'></a>");
                })
                ->title('用户管理')
                    ->canShow(false)
                    ->canDelete(true)
                    ->canEdit(false)
                    ->canExport(ModuleManager::getModuleConfig('Member', 'exportEnable',false));
            });


    }

}
