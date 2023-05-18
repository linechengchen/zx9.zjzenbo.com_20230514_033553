<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use ModStart\Admin\Auth\Admin;
use ModStart\Core\Dao\ModelUtil;
use ModStart\Core\Input\Response;
use ModStart\Module\ModuleManager;
use Module\Cms\Util\CmsCatUtil;
use Module\Cms\Util\CmsModelUtil;

class RefreshSystem extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'RefreshSystem';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '重置数据库';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        Artisan::call("migrate:fresh");

        Admin::add("admin", "admin");
        foreach (ModuleManager::listAllInstalledModulesInRequiredOrder() as $module) {
            $ret = ModuleManager::install($module);

        }
        if (file_exists($file = public_path('data_demo/data.php'))) {
            $data = include($file);
            if (!empty($data['inserts'])) {
                foreach ($data['inserts'] as $table => $records) {
                    ModelUtil::insertAll($table, $records);
                }
            }
            if (!empty($data['updates'])) {
                foreach ($data['updates'] as $record) {
                    DB::table($record['table'])->where($record['where'])->update($record['update']);
                }
            }
        }
        $this->add_cms_model_yearnews();
        $this->add_cms_cat_yearnews();

    }

    public function add_cms_model_yearnews() {
        $data=[
            "title" => "会展年历",
            "name" => "yearnews",
            "enable" => true,
            "mode" => "1",
            "listTemplate" => "yearnews.blade.php",
            "detailTemplate" => "yearnews.blade.php",
            "pageTemplate" => "default.blade.php",
            "formTemplate" => "default.blade.php",
        ];
        ModelUtil::insert('cms_model', $data);
    }

    public function add_cms_cat_yearnews()
    {
        $data = [ // module\Cms\Admin\Controller\ModelController.php:237
            "created_at"=> "2023-05-18 00:21:36",
            "updated_at"=> "2023-05-18 00:21:37",
            "pid"=> 0,
            "sort"=> 0,
            "title"=> "会展年历",
            "subTitle"=> null,
            "bannerBg"=> null,
            "url"=> "yearnews",
            "modelId"=> 4,
            "listTemplate"=> "yearnews.blade.php",
            "detailTemplate"=> "yearnews.blade.php",
            "seoTitle"=> null,
            "seoDescription"=> null,
            "seoKeywords"=> null,
            "icon"=> null,
            "cover"=> null,
            "visitMemberGroupEnable"=> null,
            "visitMemberGroups"=> null,
            "visitMemberVipEnable"=> null,
            "visitMemberVips"=> null,
            "pageTemplate"=> null,
            "formTemplate"=> null,
            "memberUserPostEnable"=> null,
            "postMemberGroupEnable"=> null,
            "postMemberGroups"=> null,
            "postMemberVipEnable"=> null,
            "postMemberVips"=> null,
            "pageSize"=> null,
            "enable"=> 1

        ];
        $data = ModelUtil::insert('cms_cat', $data);
    }
}
