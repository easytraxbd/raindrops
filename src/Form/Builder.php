<?php
/**
 * Created by PhpStorm.
 * User: rashidul
 * Date: 4/6/2017
 * Time: 10:30 PM
 */

namespace Rashidul\RainDrops\Form;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Rashidul\RainDrops\Facades\JavaScript;
use Rashidul\RainDrops\Helper;
use Rashidul\RainDrops\Html\Element;
use Rashidul\RainDrops\Html\Helper as HtmlHelper;

class Builder
{
    /**
     * @var string
     */
    protected $formType;

    /**
     * @var Model
     */
    protected $model;

    /**
     * @var array
     */
    protected $fieldsOnly = [];

    /**
     * @var Helper
     */
    protected $helper;

    protected $htmlHelper;

    /**
     * @var array
     */
    protected $fieldsExcept = [];

    /**
     * @var string
     */
    protected $templateName = 'default';

    /**
     * @var array
     */
    protected $fieldsModified = [];

    /**
     * @var array
     */
    protected $fieldsAdded = [];

    /**
     * @var array|Config
     */
    protected $configs = [];

    /**
     * @var string
     */
    protected $wrapperElements;

    /**
     * @var null
     */
    protected $errors;

    /**
     * Form object
     * @var | Element
     */
    protected $form;

    /**
     * @var mixed
     */
    protected $formOptions = 'auto';

    /**
     * @var int
     */
    protected $columns = 4;

    /**
     * @var array
     */
    protected $fields = [];

    /**
     * @var array
     */
    protected $hiddenFields = [];

    /**
     * Should csrf field be generated
     * @var bool
     */
    protected $csrfField = true;

    /**
     * @var array
     */
    protected $sections = [];

    /**
     * Old form inputs
     * @var array|mixed
     */
    protected $oldInputs = [];

    /**
     * @var array
     */
    protected $submitButtonOptions = [
        'text' => 'Submit',
        'icon' => 'fa fa-save',
        'class' => 'btn btn-primary',
        'wrapper' => 'div.tc'
    ];

    /**
     * Builder constructor.
     * @internal param $helper
     */
    public function __construct()
    {
        $this->helper = new Helper();
        $this->htmlHelper = new HtmlHelper();
        $this->errors = $this->getErrorsFromRequest();
        $this->oldInputs = $this->getOldInputsFromSession();
        $this->configs = config('raindrops.form');

    }

    /**
     * Start building your form
     *
     * @param $model
     * @return $this
     * @throws Exception
     */
    public function build($model = null)
    {

        if ( !is_null($model) && !$model instanceof Model )
        {
            throw new \InvalidArgumentException("Argument 1 of build method must be an instance of Illuminate\\Database\\Eloquent\\Model");
        }

        $this->model = $model;

        $this->formType = $this->model->exists ? 'edit' : 'create';

        return $this;
    }

    public function section($name, $fields)
    {
        $this->sections[$name] = $fields;

        return $this;
    }

    /**
     * Template name, defined in form config's 'templates' key
     *
     * @param $name
     * @return $this
     * @throws Exception
     */
    public function template( $name )
    {

        $templateName = 'raindrops.form.templates.' . $name;

        if ( ! Config::has( $templateName ) )
        {
            throw new \Exception('template doesn\'t exist in config file');
        }

        $this->templateName = $name;

        return $this;
    }

    /**
     * generate csrf field or not
     *
     * @param $value
     * @return $this
     */
    public function csrf($value)
    {
        $this->csrfField = $value;

        return $this;
    }

    /**
     * Add new fields to the form
     *
     * @param $field
     * @param $options
     * @return $this
     */
    public function add($field, $options)
    {
        $this->fieldsAdded[$field] = $options;

        return $this;
    }

    /**
     * Add a field right after another field
     * @param $after
     * @param $field_name
     * @param $options
     * @return $this
     * @internal param $label
     * @internal param $html
     */
    public function addAfter($after, $field_name, $options)
    {
        // if the after key doesn't exists, just add it at the end
        if (!array_key_exists($after, $this->fieldsAdded))
        {
            $this->fieldsAdded[$field_name] = $options;

            return $this;
        }
        $this->fieldsAdded = Helper::array_insert_after($after, $this->fieldsAdded, $field_name, $options);

        return $this;
    }

    /**
     * Add field with raw html
     *
     * @param $field
     * @param $html
     * @return $this
     * @internal param $options
     */
    public function addHtml($field, $html)
    {
        $this->fieldsAdded[$field] = ['html' => $html];

        return $this;
    }

    /**
     * Remove any field from the form
     *
     * @param $fields string | array
     * @return $this
     */
    public function remove($fields)
    {

        if (is_array($fields))
        {
            $this->fieldsExcept = array_merge($this->fieldsExcept, $fields);
        }
        else
        {
            array_push($this->fieldsExcept, $fields);
        }

        return $this;
    }

    /**
     * Render only the given fields
     *
     * @param array $fields
     * @return $this
     */
    public function only($fields = [])
    {
        $this->fieldsOnly = $fields;

        return $this;
    }

    /**
     * Add hidden fields to the form
     * @param $name
     * @param $value
     * @return $this
     */
    public function hidden($name, $value)
    {
        $this->hiddenFields[$name] = $value;

        return $this;
    }

    public function modify($field, $options)
    {
        $this->fieldsModified[$field] = $options;

        return $this;
    }


    /**
     * Determine the form options
     *
     * @return $this
     */
    public function form()
    {
        switch (func_num_args()){

            // if it's a single argument, then its either boolean
            // or an array containing form options ,
            case 1:

                $this->formOptions = func_get_arg(0);

                break;

            // first argument is the action value, and the second one is
            // the method
            case 2:

                $this->formOptions['action'] = func_get_arg(0);
                $this->formOptions['method'] = func_get_arg(1);

                break;

            default:

                $this->formOptions = false;

        }

        return $this;

    }

    /**
     * Any custom classes that should be added to the form element
     *
     * @param $classes
     * @return $this
     */
    public function classes($classes)
    {
        $this->formOptions['class'] = $classes;

        return $this;
    }


    /**
     * Custom id attributes for the form
     *
     * @param $ids string
     * @return $this
     */
    public function ids($ids)
    {
        $this->formOptions['id'] = $ids;

        return $this;
    }

    public function wrapper($elements)
    {
        $this->wrapperElements = $elements;

        return $this;
    }


    public function submit()
    {
        switch (func_num_args()){

            // if it's a single argument, then its either boolean
            // or an array containing form options ,
            case 1:

                $arg = func_get_arg(0);

                if ( is_array($arg) ){
                    $this->submitButtonOptions = array_merge($this->submitButtonOptions, $arg);
                } else {
                    $this->submitButtonOptions = $arg;
                }

                break;

            // first argument is the button text, second is
            // the icon class, third is button class
            case 3:

                $this->submitButtonOptions['text'] = func_get_arg(0);
                $this->submitButtonOptions['icon'] = func_get_arg(1);
                $this->submitButtonOptions['class'] = func_get_arg(2);

                break;

            default:

                $this->submitButtonOptions = false;

        }

        return $this;
    }

    /**
     * number of columns for the form
     * @param $columns
     * @return $this
     */
    public function columns($columns)
    {
        $this->columns = $columns;

        return $this;
    }



    /**
     * Renders the final form markup
     *
     * @return string
     */
    public function render()
    {

        $this->form = $this->initFormObject();

        // build the fields array
        $this->fields = $this->populateFieldsArray();

        // if sections are defined, populate those first
        if ( $this->sections )
        {

            foreach ($this->sections as $header => $fields)
            {
                // build header
                $header = Element::build('div')
                                ->addClass('col-md-12')
                                ->addChild('h3')
                                ->text($header);

                $this->form->addChild($header);

                // field fields for sections
                $section_fields = array_only( $this->fields, $fields );

                $this->populateFormWithElements( $section_fields );

                // remove the fields those are just generated from the fields property
                array_forget( $this->fields, array_keys($section_fields) );

            }

        }

        // generate the rest of the elements without sections
        $this->populateFormWithElements( $this->fields );

        $this->form->text( $this->renderHiddenFields() );

        if ( $this->csrfField )
        {
            $this->form->text( csrf_field() );
        }

        $this->form->text( $this->renderSubmitButton() );

        return $this->form->render();

    }

    private function populateFieldsArray()
    {

        $defaults = $this->model ? $this->model->getFormFields() : [];

        // added fields
        $fields = array_merge($defaults, $this->fieldsAdded);

        // removed fields
        if ( !empty( $this->fieldsExcept ) )
        {
            $fields = array_except( $fields, $this->fieldsExcept );
        }

        // if $fieldsOnly field is set, keep only those and discard others
        if ( !empty($this->fieldsOnly) ){

            $new_array = [];

            foreach ($this->fieldsOnly as $item) {

                if ( array_key_exists($item, $fields) ){

                    $new_array[$item] = $fields[$item];

                }
            }

            $fields = $new_array;

        }

        // do any modifications needed
        $fields = $this->doModifications($fields);


        return $fields;

    }

    /**
     * Do the required modifications
     *
     * @param $fields
     * @return mixed
     */
    private function doModifications($fields)
    {
        if ( empty($this->fieldsModified) ){
            return $fields;
        }

        foreach ($this->fieldsModified as $field => $options){

            if ( array_key_exists($field, $fields)){

                $new_options = array_replace($fields[$field], $options);

                $fields[$field] = $new_options;
            }
        }

        return $fields;
    }

    /**
     * Render hidden input fields, if any specified
     *
     * @return string
     */
    private function renderHiddenFields()
    {
        $data = '';
        $stub = '<input type="hidden" name="%s" value="%s"/>';

        if ( !empty($this->hiddenFields) ){

            foreach ($this->hiddenFields as $field => $value) {
                $data .= sprintf($stub, $field, $value);
            }
        }

        return $data;
    }

    private function renderSubmitButton()
    {

        if ( !$this->submitButtonOptions ){
            return '';
        }

        $button = Element::build('button')
                        ->setType('submit')
                        ->addClass($this->submitButtonOptions['class'])
                        ->text($this->submitButtonOptions['text']);

        $icon = Element::build('i')
                    ->addClass($this->submitButtonOptions['icon']);

        $button->text($icon);

        if ( strlen($this->submitButtonOptions['wrapper']) ) {
            $wrapper = $this->htmlHelper->elementFromSyntax( $this->submitButtonOptions['wrapper'] );
            return $wrapper->text($button)->render();
        }

        return $button->render();

    }

    private function wrapWithForm($data)
    {

        $stub = '<form action="%s" method="%s" enctype="multipart/form-data">%s</form>';

        $action = $this->helper->returnIfExists($this->formOptions, 'action');
        $method = $this->helper->returnIfExists($this->formOptions, 'method');

        return sprintf($stub, $action, $method, $data);
    }

    private function isRequired($options)
    {
        return isset($options['validations']) && str_contains($options['validations'], 'required')
            ? true
            : false;
    }

    private function isUnique($options)
    {
        return isset($options['validations']) && str_contains($options['validations'], 'unique')
            ? true
            : false;
    }

    private function getLabel($options, $required, $unique)
    {
        $required = $required ? ' <span class="required-field">*</span>' : '';

        $unique = $unique ? ' (Unique)' : '';

        return $options['label'] . $unique . $required;
    }

    private function getErrorsFromRequest()
    {

        $request = app(Request::class);

        if ( $request->session()->exists('errors') )
        {
            return $request->session()->get('errors')->getBag('default');
        }

        return null;
    }

    /**
     * Constructing the form object
     * @return \Rashidul\RainDrops\Html\Markup
     */
    private function initFormObject()
    {

        if ($this->formOptions) {

            $method_field = '';

            // first we need to check if the form option is a string
            // and set to 'auto' if it is, then we will predict the `method`
            // and `action` value automatically, otherwise if $this->>formOptions
            // is an array, then user provided the values for action & method explicitly
            // we'll use those
            if ( is_string($this->formOptions) && $this->formOptions === 'auto')
            {
                // first make it an array
                $this->formOptions = [];

                // check if the $this->model is hydrated or an instance of
                // a database row, if hydrated then its a create form, and if instance
                // then its a edit form
                if ( $this->model->exists )
                {
                    $method_field = method_field('PUT');
                    $this->formOptions['method'] = 'POST';
                    $this->formOptions['action'] = url($this->model->getShowUrl());
                }
                else
                {
                    $this->formOptions['method'] = 'POST';
                    $this->formOptions['action'] = url($this->model->getBaseUrl());
                }
            }
            else
            {
                if (array_key_exists('method', $this->formOptions)
                    && in_array($this->formOptions['method'], ['PUT', 'PATCH', 'DELETE']) ) {

                    $method_field = method_field($this->formOptions['method']);
                    $this->formOptions['method'] = 'POST';
                }

                if (array_key_exists('action', $this->formOptions) ) {

                    $this->formOptions['action'] = url($this->formOptions['action']);
                }
            }

            return Element::build('form')
                ->text($method_field)
                ->addClass($this->configs['form_class'])
                ->set(['enctype' => 'multipart/form-data'])
                ->set($this->formOptions);

        }

        // return empty element if form is false
        return Element::build('');
    }

    /**
     * Populate the $form object with elements
     *
     * @param $section_fields
     * @return array
     */
    private function populateFormWithElements($section_fields)
    {

        if ( empty($section_fields) ) return ;

        $templateRoot = $this->configs['template_groups'][$this->configs['default_template_group']];

//        $lastField = Helper::getLastKey($section_fields);

        $elementClass = 'form-control';


        $markup = '';

        foreach ( $section_fields as $field => $options) {

            if (is_array($options)) {

                // form is false then abort
                if (array_key_exists('form', $options) && !$options['form']) {
                    continue;
                }

                if (array_key_exists('form', $options) && $options['form'] != $this->formType) {
                    continue;
                }

                // get the form's html from a model method
                if (array_key_exists('form', $options) && $options['form'] === 'method'){
                    $markup .= $this->model->{$options['formMethod']}();
                    continue;
                }

                // raw html
                if (array_key_exists('html', $options) && $options['html'] != '') {
                    $markup .= $options['html'];
                    continue;
                }

                $value = $this->model->exists ? $this->model->getOriginal($field) : null;

                if (isset($options['value'])){
                    $value = $options['value'];
                }

                // if old input value exists in the session
                // for this field, use that
                if (!empty($this->oldInputs[$field])) {
                    $value = $this->oldInputs[$field];
                }

                $required = $this->isRequired($options);

                $unique = $this->isUnique($options);

                $label = $this->getLabel($options, $required, $unique);

                $attributes = isset($options['attributes']) ? $options['attributes'] : null;

                $error_class = '';

                $error_text = '';

                if ($this->errors != null && $this->errors->any()) {
                    if ($this->errors->has($field)) {
                        $error_class = 'has-error';
                        $error_text = $this->errors->first($field);
                    }
                }

                switch ($options['type']) {
                    case 'textarea':

                        $element = Element::build('textarea')
                            ->addClass($elementClass)
                            ->setName($field)
                            ->setRequired($required)
                            ->set('rows', '10')
                            ->set($attributes)
                            ->text($value)
                            ->render();

                        break;

                    case 'editor':

                        $element = Element::build('textarea')
                            ->addClass($elementClass)
                            ->addClass('editor')
                            ->setName($field)
                            ->setRequired($required)
                            ->set('rows', '10')
                            ->set($attributes)
                            ->text($value)
                            ->render();

                        break;

                    case 'select':

                        $element = Element::build('select')
                            ->addClass($elementClass)
                            ->addClass('select2')
                            ->setName($field)
                            ->set($attributes)
                            ->setRequired($required);

                        $default = Element::build('option')
                            ->setSelected(true)
                            ->setValue('')
                            ->setDisabled(true)
                            ->text('--Select One--');

                        $element->addChild($default);

                        foreach ($options['options'] as $option_key => $option_value) {

                            $isSelected = $value === $option_key ? true : false;

                            $option = Element::build('option')
                                ->setValue($option_key)
                                ->setSelected($isSelected)
                                ->text($option_value);

                            $element->addChild($option);

                        }

                        $element = $element->render();

                        break;

                    case 'select_db':

                        // TODO.
                        // need to let user filter some records before adding them to option list
                        // this data should be pulled via eloquent instead of raw DB query
                        // refactor it to use the new Element class
                        $table_data = DB::table($options['table'])->select($options['options'])->get();

                        $option_key = $options['options'][0];

                        $element = sprintf('<select name="%s" class="form-control select2" %s>', $field, $required);

                        $element .= '<option value="" disabled selected>--Select One--</option>';
                        foreach ($table_data as $table_data_single) {
                            $option_value = count($options['options']) > 2 ? $table_data_single->{$options['options'][1]} . ' ' . $table_data_single->{$options['options'][2]} : $table_data_single->{$options['options'][1]};
                            $element .= sprintf('<option value="%s">%s</option>', $table_data_single->{$option_key}, $option_value);
                        }
                        $element .= '</select>';

                        break;

                    // build a dropdown for a foreign key
                    // type is `relation` and mostly belongsTo relation
                    // for Eloquent
                    case 'relation':

                        // get the related model
                        $relatedModel = $this->model->{$options['options'][0]}()->getRelated();

                        // get collection of all rows from the related model
                        if (isset($options['scope'])) {
                            $relatedCollection = $relatedModel->{$options['scope']}()->get();
                        } else {
                            $relatedCollection = $relatedModel->all();
                        }

                        // if this dropdwn depends on the value from another field, there should be
                        // a `depends` key on the field's option array, just add that field's name as a data
                        // attribute on the select element. we'll handle it on the cleintside via jQuery
                        $dependsOn = '';
                        if (isset($options['parent']) && $options['parent'] != '') {
                            $dependsOn = 'data-parent="' . $options['parent'] . '"';

                            // send the collection to javascript, we need that to populate the
                            // options dynamically on the view
                            JavaScript::put(
                                [
                                    $field => [
                                        'data' => $relatedCollection->toArray(),
                                        'indexColumn' => $options['options'][1],
                                        'selectedId' => $value,
                                        'keyName' => $relatedModel->getKeyName()
                                    ]
                                ]
                            );

                            // generate dropdown with no options
                            $element = sprintf('<select name="%s" class="form-control select2" %s %s>', $field, $dependsOn, $required);

                            $element .= '<option value="" disabled selected>--Select One--</option>';
                            $element .= '</select>';

                            break;

                        }

                        // generate the dropdown
                        $element = sprintf('<select name="%s" class="form-control select2" %s %s>', $field, $dependsOn, $required);

                        $element .= '<option value="" disabled selected>--Select One--</option>';
                        $element .= \Rashidul\RainDrops\Form\Helper::collectionToOptions($relatedCollection, ['id', $options['options'][1]], $value);
                        $element .= '</select>';

                        break;

                    // eloquent many to many relation
                    case 'relation_many':

                        // get the related model
                        $relationName = $options['options'][0];
                        $relatedModel = $this->model->{$relationName}()->getRelated();

                        // if model exists, get the ids of related model
                        $values = [];

                        if ($this->model->exists){
                            $values = $this->model->{$relationName}->pluck($relatedModel->getKeyName())->toArray();
                        }

                        // get collection of all rows from the related model
                        if (isset($options['scope'])) {
                            $relatedCollection = $relatedModel->$options['scope']()->get();
                        } else {
                            $relatedCollection = $relatedModel->all();
                        }

                        // generate the dropdown
                        $element = sprintf('<select name="%s[]" class="form-control select2" %s multiple>', $field, $required);

                        $element .= \Rashidul\RainDrops\Form\Helper::collectionToOptions($relatedCollection, ['id', $options['options'][1]], $values);
                        $element .= '</select>';

                        break;

                    case 'date':

                        $element = Element::build('input')
                            ->addClass($elementClass)
                            ->addClass('datepicker')
                            ->setName($field)
                            ->setType('text')
                            ->set($attributes)
                            ->setValue($value)
                            ->setRequired($required)
                            ->render();

                        break;

                    case 'date_time':

                        $element = Element::build('input')
                            ->addClass($elementClass)
                            ->addClass('datetimepicker')
                            ->setName($field)
                            ->setValue($value)
                            ->setType('text')
                            ->set($attributes)
                            ->setRequired($required)
                            ->render();

                        break;

                    case 'file':

                        $element = Element::build('input')
                            ->addClass($elementClass)
                            ->setName($field)
                            ->setType('file')
                            ->set($attributes)
                            ->set('accept', $options['accept'])
                            ->setRequired($required)
                            ->render();

                        break;

                    case 'image':

                        $accepts = (isset($options['accept'])) ? $options['accept'] : 'image/*';
                        $element = Element::build('input')
                            ->addClass($elementClass)
                            ->setName($field)
                            ->setType('file')
                            ->set($attributes)
                            ->set('accept', $accepts)
                            ->setRequired($required)
                            ->render();

                        break;

                    case 'currency':

                        $element = Element::build('input')
                            ->addClass($elementClass)
                            ->setName($field)
                            ->setType('number')
                            ->setValue($value)
                            ->set($attributes)
                            ->set('step', $this->getPrecision($options['precision']))
                            ->setRequired($required)
                            ->render();

                        break;

                    // TODO.
                    // 2. extract the element generation code to diffeerent methods,

                    case 'time':

                        $element = Element::build('input')
                            ->addClass($elementClass)
                            ->addClass('timepicker')
                            ->setName($field)
                            ->setValue($value)
                            ->set($attributes)
                            ->setType('text')
                            ->setRequired($required)
                            ->render();

                        break;

                    case 'checkbox':
                        $element = Element::build('input')
                            ->setName($field)
                            ->setType('checkbox')
                            ->set($attributes)
                            ->setRequired($required);
                        if ($value) {
                            $element->set('checked', 'checked');
                        }
                        $element = $element->render();

                        break;

                    default:

                        $element = Element::build('input')
                            ->addClass($elementClass)
                            ->setName($field)
                            ->setValue($value)
                            ->set($attributes)
                            ->setType($options['type'])
                            ->setRequired($required)
                            ->render();

                        $fields[$field] = $element;


                }

                /*$placeholders = [
                    '{error_class}' => $error_class,
                    '{label_text}' => $label,
                    '{element}' => $element,
                    '{error_text}' => $error_text

                ];*/

                $data = [
                    'error_class' => $error_class,
                    'label' => $label,
                    'field' => $element,
                    'error_text' => $error_text,
                ];

                if ($options['type'] == 'checkbox'){
                    $element = view($templateRoot . '.checkbox', $data)->render();
                } elseif ($options['type'] == 'radio'){

                } else {
                    $element = view($templateRoot. '.basic', $data)->render();
                }

                $markup .= $element;

                /*$wrapper = Element::build('div')
                    ->addClass($wrapperClass)
                    ->text($element)
                    ->render();

                $row->text($element);*/
            }
            else
            {
                $markup .= $options;
            }

//            $count++;

            // when we generated elements as the column number, or there's no
            // elemnt left to build
            // then we add the row in the form, nullify the row object,
            // and reset the counter
            /*if ($count === $this->columns || $field === $lastField)
            {
                // add the row to form
                $this->form->text($row->render());

                // clear the previous row object and create a new
                $row = null;
                $row = Element::build('div')
                    ->addClass('row');

                // reset the counter
                $count = 0;
            }*/



        }

        $this->form->text($markup);


    }

    /**
     * Returns old form inputs from session
     * @return mixed
     */
    private function getOldInputsFromSession()
    {
        $request = app(Request::class);

        return $request->session()->getOldInput();
    }

    /**
     * Get wrapper class for a single element
     * based on the number of columns set for the form
     * @return string
     */
    protected function getWrapperClass()
    {
        switch ($this->columns)
        {
            case 1:
                return 'col-md-12';
                break;

            case 2:
                return 'col-md-6';
                break;

            case 3:
                return 'col-md-4';
                break;

            case 4:
                return 'col-md-3';
                break;
        }

        return 'col-md-6';
    }

    private function getPrecision($precision)
    {
        $test = str_pad(1, $precision , '0', STR_PAD_LEFT);
        return '0.' . $test;
    }



}
