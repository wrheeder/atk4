<?php
include_once'Form/Field.php';
/**
 * This class implements generic form, which you can actually use without
 * redeclaring it. Just add fields, buttons and use execute method.
 *
 * @author		Romans <romans@adevel.com>
 * @copyright	See file COPYING
 * @version		$Id$
 */
class Form extends AbstractView {
    protected $form_template = null;
    protected $form_tag = null;
    public $errors=array();
                            // Here we will have a list of errors occured in the form, when we tried to submit it.
                            //  field_name => error

    public $template_chunks=array();
                            // Those templates will be used when rendering form and fields

    protected $data = array(); // This array holds list of values prepared for fields before their initialization. When fields
                            // are initialized they will look into this array to see if there are default value for them.
                            // Afterwards fields will link to $this->data, so changing $this->data['fld_name'] would actually
                            // affect field's value.
                            //  You should use $this->set() and $this->get() to read/write individual field values. You
                            //  should use $this->setStaticSource() to load values from hash
                            //  AAAAAAAAAA: this array is no more!

    public $last_field = null;  // contains reference to last-added field
    public $last_button = null; // contains last added button

    public $bail_out = false;   // if this is true, we won't load data or submit or validate anything.
    protected $loaded_from_db = false;     // if true, update() will try updating existing row. if false - it would insert new
    public $onsubmit = null;
    public $onload = null;

    public $dq = null;
    function init(){
        /**
         * During form initialization it will go through it's own template and search for lots of small template
         * chunks it will be using. If those chunk won't be in template, it will fall back to default values. This way
         * you can re-define how form will look, but only what you need in particular case. If you don't specify template
         * at all, form will work with default look.
         */
        parent::init();

        // commonly replaceable chunks
        $this->grabTemplateChunk('form_comment');
        $this->grabTemplateChunk('form_separator');
        $this->grabTemplateChunk('form_line');      // template for form line, must contain field_caption,field_input,field_error
        if($this->template->is_set('hidden_form_line'))
            $this->grabTemplateChunk('hidden_form_line');
        $this->grabTemplateChunk('field_error');    // template for error code, must contain field_error_str
        //$this->grabTemplateChunk('form');           // template for whole form, must contain form_body, form_buttons, form_action,
                                                    //  and form_name
		$this->grabTemplateChunk('field_mandatory'); // template for marking mandatory fields

        // ok, other grabbing will be done by field themselves as you will add them to the form.
        // They will try to look into this template, and if you don't have apropriate templates
        // for them, they will use default ones.
        $this->template_chunks['form']=$this->template;
        $this->template_chunks['form']->del('form_body');
        $this->template_chunks['form']->del('form_buttons');
        $this->template_chunks['form']->set('form_name',$this->name);

        // After init method have been executed, it's safe for you to add controls on the form. BTW, if
        // you want to have default values such as loaded from the table, then intialize $this->data array
        // to default values of those fields.
        $this->api->addHook('pre-exec',array($this,'loadData'));
    }

	function initializeTemplate($tag, $template){
        $template = $this->form_template?$this->form_template:$template;
        $tag = $this->form_tag?$this->form_tag:$tag;
        return parent::initializeTemplate($tag, $template);
    }
    function defaultTemplate($template = null, $tag = null){
        if ($template){
            $this->form_template = $template;
        }
        if ($tag){
            $this->form_tag = $tag;
        }
        return array($this->form_template?$this->form_template:"form", $this->form_tag?$this->form_tag:"form");
    }
    function grabTemplateChunk($name){
        if($this->template->is_set($name)){
            $this->template_chunks[$name] = $this->template->cloneRegion($name);
        }else{
            //return $this->fatal('missing form tag: '.$name);
            // hmm.. i wonder what ? :)
        }
    }

    function addField($type,$name,$caption=null,$attr=null){
        if($caption===null)$caption=$name;

        $this->last_field=$this->add('Form_Field_'.$type,$name,'form_body','form_line')
            ->setCaption($caption);
        if (is_array($attr)){
            foreach ($attr as $key => $value){
                $this->last_field->setProperty($key, $value);
            }
        }

        $this->last_field->short_name = $name;

        return $this;
    }
    function disable(){
    	// disables last field
    	$this->last_field->disable();
    	return $this;
    }
    function denyEnter(){
    	// prevents the submit on Enter for the last field
    	// apply it when there is only one field on the form
    	$this->last_field->denyEnter();
    	return $this;
    }
	function setFieldComment($comment){
		/**
		 * Adds a comment to the last field.
		 * Comment will be inserted to the field_comment tag of the form template
		 */
		$this->last_field->comment=$comment;
		return $this;
	}
	function setFormat($format,$separator='-'){
		if($this->last_field instanceof Form_Field_Grouped)$this->last_field->setFormat($format,$separator);
		else throw new BaseException("This field type does not support formats");
		return $this;
	}
    function addComment($comment){
        $this->add('Text','c'.count($this->elements),'form_body')->set(
			$this->template_chunks['form_comment']->set('comment',$comment)->render()
		);
        return $this;
    }
    function addSeparator($separator='<hr>'){

        if(!isset($this->template_chunks['form_separator']))return $this->addComment('<u>'.$separator.'</u>');

        $this->add('Text','c'.count($this->elements),'form_body')->set(
        	$this->template_chunks['form_separator']->set('separator',$separator)->render());
        return $this;
    }
    function addLabel($label){
        return $this->addComment($label);
    }

    function onSubmit($ajax=null){
        return $this->onsubmit=$ajax?$ajax:$this->add('Ajax');
    }
    function onLoad($ajax=null){
        return $this->onload=$ajax?$ajax:$this->add('Ajax');
    }


    // Operating with field values
    function get($field){
        if(!isset($this->elements[$field]))throw new BaseException('Trying to get value of not-existing field: '.$field);
        return ($this->elements[$field] instanceof Form_Field)?$this->elements[$field]->get():null;
    }
    function clearData(){
        $this->downCall('clearFieldValue');
    }
    function setSource($table,$db_fields=null){
        if(is_null($db_fields)){
            $db_fields=array();
            foreach($this->elements as $key=>$el){
                if(!($el instanceof Form_Field))continue;
                if($el->no_save)continue;
                $db_fields[]=$key;
            }
        }
        $this->dq = $this->api->db->dsql()
            ->table($table)
            ->field('*',$table)
            ->limit(1);
        return $this;
    }
    function set($field_or_array,$value=undefined){
        // We use undefined, because 2nd argument of "null" is meaningfull
        if($value===undefined){
            if(is_array($field_or_array)){
                foreach($field_or_array as $key=>$val){
                    if(isset($this->elements[$key]) and $this->elements[$key] instanceof Form_Field)$this->set($key,$val);
                }
                return $this;
            }else{
                $value=$field_or_array;
                $field_or_array=$this->last_field->short_name;
            }
        }

        if(!isset($this->elements[$field_or_array])){
            foreach ($this->elements as $key => $val){
                echo "$key<br />";
            }
            throw new BaseException("Trying to set value for non-existant field $field_or_array");
        }
        //if($this->elements[$field_or_array] instanceof Form_Button)echo caller_lookup(0);
        if($this->elements[$field_or_array] instanceof Form_Field)
        	$this->elements[$field_or_array]->set($value);
        else{
        	//throw new BaseException("Form fields must inherit from Form_Field ($field_or_array)");
        }

        return $this;
    }
    function getAllData(){
        $data=array();
        foreach($this->elements as $key=>$val){
            if($val instanceof Form_Field){
                $data[$key]=$val->get();
            }
        }
        return $data;
    }

    // Modifying existing field properties and behavior
    function setProperty($property,$value=null){
        // Add property to field TAG
        $this->last_field->setProperty($property,$value);
        return $this;
    }



    function validateField($condition,$msg=''){
        $this->last_field->addHook('validate','if(!('.$condition.'))$this->displayFieldError("'.
                    ($msg?$msg:'Error in ".$this->caption."').'");');
        return $this;
    }


/*    function validateNotNULL($msg=''){
        $this->last_field->addHook('validate','if(!$this->get())$this->displayFieldError("'.
                    ($msg?$msg:'$this->caption." is a mandatory field!').'");');
        return $this;
    }*/

    function validateNotNULL($msg=''){
    	$this->last_field->setMandatory();
        $this->last_field->addHook('validate','if(!$this->get())$this->displayFieldError("'.
                    ($msg?$msg:'".$this->caption." is a mandatory field!').'");');
	       return $this;
    }


    function setNotNull($msg=''){
        $this->validateNotNULL($msg);
        return $this;


        // TODO: mark field so that it have that red asterisk
    }
    function setNoSave(){
        $this->last_field->setNoSave();
        return $this;
    }
    function setValueList($list){
        $this->last_field->setValueList($list);
        return $this;
    }
	function onChange(){
		return $this->last_field->onChange();
	}
	function onKeyPress(){
		return $this->last_field->onKeyPress();
	}

    function addSubmit($label,$name=null,$color=null){
        $this->last_field = $this->add('Form_Submit',isset($name)?$name:$label,'form_buttons')
            ->setLabel($label)
            ->setNoSave();
        if (!is_null($color))
        	$this->last_field->setColor($color);

        if(!isset($this->onsubmit))$this->onsubmit=false;  // do not perform ajax submit
        return $this;
    }
    function addAjaxButtonAction($label,$name=null){
        return $this->addButton($label,$name);
    }
    function addDefaultButton($label,$name=null,$color=null){
        return $this->onSubmit($this->addButton($label,$name,$color));
    }
    function addButton($label,$name=null,$color=null){

        // Now add the regular button first
        $field = $this->last_button = $this->add('Form_Button',isset($name)?$name:$label,'form_buttons')
            ->setLabel($label);

        if (!is_null($color))
        	$field->setColor($color);

        $field->setNoSave();

        // And teach it to use AJAX
        return $field->onclick = $field->add('Ajax')->useProgressIndicator($this->name.'_loading');
    }
    function addCondition($field,$value=null){
        $this->dq
            ->set($field,$value)
            ->where($field,$value);
        return $this;
    }
    function addConditionFromGET($field='id',$get_field=null){
        // If GET pases an argument you need to put into your where clause, this is the function you should use.
        if(!isset($get_field))$get_field=$field;
        $this->api->stickyGET($get_field);
        return $this->addCondition($field,$_GET[$get_field]);
    }
    function loadData(){
        /**
         * This call will be sent to fields, and they will initialize their values from $this->data
         */
        if($this->bail_out)return;
        if($this->dq){
            // we actually initialize data from database
            $data = $this->dq->do_getHash();
            if($data){
                $this->set($data);
                $this->loaded_from_db=true;
            }
        }
    }

	function isLoadedFromDB(){
		return $this->loaded_from_db;
	}
	function update()
	{
        if(!$this->dq)throw new BaseException("Can't save, query was not initialized");
        foreach($this->elements as $short_name => $element)
        	if($element instanceof Form_Field)if(!$element->no_save){
                //if(is_null($element->get()))
                //$this->api->logger->logVar($element->get(),$short_name.': ');
                $this->dq->set($short_name, $element->get());
        }
        if($this->loaded_from_db){
            // id is present, let's do update
            //$this->api->logger->logVar($this->dq->update());
            return $this->dq->do_update();
        }else{
            // id is not present
            return $this->dq->do_insert();
        }
	}



    function submitted(){
        /**
         * Default down-call submitted will automatically call this method if form was submitted
         */
        // We want to give flexibility to our controls and grant them a chance to
        // hook to those spots here.
        // On Windows platform mod_rewrite is lowercasing all the urls.

        if($_GET['submit']!=$this->name)return;
        if($this->bail_out)return;

        $this->downCall('loadPOST');
        $this->downCall('validate');

        return empty($this->errors);
    }
    function isSubmitted(){
        // This is alternative way for form submission. After  form is initialized you can call this method. It will
        // hurry up all the steps, but you will have ready-to-use form right away and can make submission handlers
        // easier
        $this->loadData();
        $result = $_POST && $this->submitted();
        $this->bail_out=true;
        return $result;
    }
    function render(){
        // Assuming, that child fields already inserted their HTML code into 'form'/form_body using 'form_line'
        // Assuming, that child buttons already inserted their HTML code into 'form'/form_buttons

        // That means - we will submit our form through Ajax
        if(!isset($this->onsubmit)){
            //$this->onSubmit()->submitForm($this);
        }
        if($this->onsubmit){
            $this->template->trySet('form_onsubmit',$this->onsubmit->ajaxFunc('return false')->getString());
        }
        if($this->onload){
            $this->template->trySet('form_onload',str_replace("\n","",$this->onload->getString()));
        }
        $this->template_chunks['form']
            ->set('form_action',$this->api->getDestinationURL(null,array('submit'=>$this->name)));
        $this->owner->template->append($this->spot,$r=$this->template_chunks['form']->render());
    }
    function isClicked($name){
        return $this->api->isClicked($this->name.'_'.$name);
    }
    /* external error management */
    function setFieldError($field, $name){
        if (isset($this->errors[$field])){
            $existing = $this->errors[$field];
        } else {
            $existing = null;
        }
        $this->errors[$field] = $existing . $name;
    }
}
?>
