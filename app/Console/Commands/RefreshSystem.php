<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use ModStart\Admin\Auth\Admin;
use ModStart\Core\Dao\ModelUtil;
use ModStart\Core\Input\Response;
use ModStart\Module\ModuleManager;
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

    }
    public function  addyearnewsmodel(){
        $data = [];

        $data['title'] = '会展年历';
        $data['name'] = $input->getTrimString('name');
        $data['enable'] = $input->getBoolean('enable');
        $data['fieldType'] = $input->getTrimString('fieldType');
        $data['fieldData'] = $input->getArray('fieldData');
        $data['isRequired'] = $input->getBoolean('isRequired');
        $data['isSearch'] = $input->getBoolean('isSearch');
        $data['isList'] = $input->getBoolean('isList');
        $data['placeholder'] = $input->getTrimString('placeholder');
        $data['maxLength'] = $input->getInteger('maxLength');
        $data['sort'] = ModelUtil::sortNext('cms_model_field', ['modelId' => $model['id']]);
        $data = ModelUtil::insert('cms_model_field', $data);
        $data['fieldData'] = json_decode($data['fieldData'], true);

        CmsModelUtil::addField($model, $data);
    }
}
