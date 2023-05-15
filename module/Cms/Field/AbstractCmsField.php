<?php


namespace Module\Cms\Field;


use ModStart\Core\Input\InputPackage;
use ModStart\Field\AutoRenderedFieldValue;
use ModStart\Form\Form;
use ModStart\Support\Concern\HasFields;

abstract class AbstractCmsField
{
    abstract public function name();

    abstract public function title();

    public function prepareDataOrFail($data)
    {
        return $data;
    }

    public function prepareInputOrFail($field, InputPackage $input)
    {
        return $input->getTrimString($field['name']);
    }

    public function serializeValue($value, $data)
    {
        return $value;
    }

    public function unserializeValue($value, $data)
    {
        return $value;
    }

    public function convertMysqlType($field)
    {
        return "VARCHAR($field[maxLength])";
    }

    public function renderForGrid($viewData)
    {
        return AutoRenderedFieldValue::makeView('modstart::core.field.text-grid', $viewData);
    }

    
    public function renderForForm(Form $form, $field)
    {
        return null;
    }

    public function renderForUserInput($field, $record = null)
    {
        return '<div class="ub-text-muted">暂不支持 ' . $field['fieldType'] . '</div>';
    }

    public function renderForFieldEdit()
    {
        return '';
    }

    public function renderForFieldEditScript()
    {
        return 'null';
    }
}
