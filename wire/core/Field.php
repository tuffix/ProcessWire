<?php

/**
 * ProcessWire Field
 *
 * The Field class corresponds to a record in the fields database table 
 * and is managed by the 'Fields' class.
 * 
 * ProcessWire 2.x 
 * Copyright (C) 2010 by Ryan Cramer 
 * Licensed under GNU/GPL v2, see LICENSE.TXT
 * 
 * http://www.processwire.com
 * http://www.ryancramer.com
 *
 */
class Field extends WireData implements Saveable {

	/**  
	 * Field should be automatically joined to the page at page load time
	 *
	 */
	const flagAutojoin = 1; 	

	/** 
	 * Field used by all fieldgroups - all fieldgroups required to contain this field
	 *
	 */
	const flagGlobal = 4; 		

	/**
	 * Permanent/native settings to an individual Field
	 *
 	 * id: Numeric ID corresponding with id in the fields table.
	 * type: Fieldtype object or NULL if no Fieldtype assigned. 
	 * label: String text label corresponding to the <label> field during input. 
	 * flags: 
	 * - autojoin: True if the field is automatically joined with the page, or False if it's value is loaded separately. 
	 * - global: Is this field required by all Fieldgroups?
	 *
	 */
	protected $settings = array(
		'id' => 0, 
		'type' => null, 
		'name' => '', 
		'flags' => 0, 
		'label' => '', 
		); 

	/**
	 * If the field name changed, this is the name of the previous table so that it can be renamed at save time 
	 *
	 */
	protected $prevTable; 

	/**
	 * If the field type changed, this is the previous fieldtype so that it can be changed at save time
	 *
	 */
	protected $prevFieldtype; 


	/**
	 * Set a native setting or a dynamic data property for this Field
	 *
	 */
	public function set($key, $value) {

		if($key == 'name') return $this->setName($value); 
			else if($key == 'type' && $value) return $this->setFieldtype($value); 
			else if($key == 'prevTable') {
				$this->prevTable = $value; 
				return $this; 
			} else if($key == 'prevFieldtype') {
				$this->prevFieldtype = $value;
				return $this; 
			} else if(in_array($key, array('id', 'flags'))) {
				$value = (int) $value; 
			}

		if(isset($this->settings[$key])) $this->settings[$key] = $value; 
			else return parent::set($key, $value); 

		return $this; 
	}

	/**
	 * Get a Field setting or dynamic data property
	 *
	 */
	public function get($key) {
		if($key == 'table') return $this->getTable();
			else if($key == 'prevTable') return $this->prevTable; 
			else if($key == 'prevFieldtype') return $this->prevFieldtype; 
			else if(isset($this->settings[$key])) return $this->settings[$key]; 
		return parent::get($key); 
	}

	/**
	 * Return a key=>value array of the data associated with the database table per Saveable interface
	 *
	 * @return array
	 *
	 */
	public function getTableData() {
		$a = $this->settings; 
		$a['data'] = $this->data; 
		return $a;
	}

	/**
	 * Set the field's name
	 *
	 * @param string $name
	 * @return Field $this
	 *
	 */
	public function setName($name) {
		$name = $this->fuel('sanitizer')->fieldName($name); 

		if(Fields::isNativeName($name)) 
			throw new WireException("Field may not be named '$name' because it is a reserved word"); 

		if($this->fuel('fields') && ($f = $this->fuel('fields')->get($name)) && $f->id != $this->id)
			throw new WireException("Field may not be named '$name' because it is already used by another field"); 

		if(strpos($name, '__') !== false) 
			throw new WireException("Field name '$name' may not have double underscores because this usage is reserved by the core"); 

		if($this->settings['name'] != $name) {
			$this->trackChange('name'); 
			if($this->settings['name']) $this->prevTable = $this->getTable(); // so that Fields can perform a table rename
		}

		$this->settings['name'] = $name; 
		return $this; 
	}

	/**
	 * Set what type of field this is. 
	 *
	 * Type should be either a Fieldtype object or the string name of a Fieldtype object. 
	 *
	 * @param string|Fieldtype $type
	 * @return Field $this
	 *
	 */
	public function setFieldtype($type) {

		if(is_object($type) && $type instanceof Fieldtype) {
			// good for you

		} else if(is_string($type)) {
			$typeStr = $type; 
			$fieldtypes = $this->fuel('fieldtypes'); 
			if(!$type = $fieldtypes->get($type)) throw new WireException("Fieldtype '$typeStr' does not exist");
		} else {
			throw new WireException("Invalid field type in call to Field::setFieldType"); 
		}

		if(!$this->type || ($this->type->name != $type->name)) {
			$this->trackChange("type:{$type->name}"); 
			if($this->type) $this->prevFieldtype = $this->type; 
		}
		$this->settings['type'] = $type; 

		return $this; 
	}

	/**
	 * Save this field's settings and data in the database. 
	 *
	 * To hook ___save, use Fields::save instead
	 *
	 */
	public function save() {
		$fields = $this->getFuel('fields'); 
		return $fields->save($this); 
	}


	/**
	 * Return the number of fieldsets this field is used in
	 *
	 * Primarily used to check if the Field is deleteable. 
	 *
	 */ 
	public function numFieldgroups() {
		return count($this->getFieldgroups()); 
	}

	/**
	 * Return a FieldgroupArray of Fieldgroups using this field
	 *
	 * @return FieldgroupsArray
	 *
	 */ 
	public function getFieldgroups() {
		$fieldgroups = new FieldgroupsArray();
		foreach($this->fuel('fieldgroups') as $fieldgroup) {
			foreach($fieldgroup as $field) {
				if($field->id == $this->id) {
					$fieldgroups->add($fieldgroup); 
					break;
				}
			}
		}
		return $fieldgroups; 
	}

	/**
	 * Return the default value for this field (if set), or null otherwise. 
	 *
	 */
	public function getDefaultValue() {
		$value = $this->get('default'); 
		if($value) return $value; 
		return null;
		
	}

	/**
	 * Get the Inputfield object associated with this Field's Fieldtype
	 *
	 */
	public function getInputfield(Page $page) {

		if(!$this->type) return null;
		$inputfield = $this->type->getInputfield($page, $this);
		if(!$inputfield) return null; 

		// predefined field settings
		$inputfield->attr('name', $this->name); 
		$inputfield->label = $this->label;

		// custom field settings
		foreach($this->data as $key => $value) {
			if($inputfield->has($key)) {
				$inputfield->set($key, $value); 
			}
		}

		return $inputfield; 
	}

	/**
	 * Get any configuration fields associated with the Inputfield
	 *
	 * @return InputfieldWrapper
	 *
	 */
	public function ___getConfigInputfields() {

		$wrapper = new InputfieldWrapper();
		$inputfields = $this->modules->get("InputfieldFieldset"); 
		$inputfields->description = "The following settings are requested by {$this->type->name}"; 
		$inputfields->label = "Fieldtype Settings";

		$fieldtypeInputfields = $this->type->getConfigInputfields($this); 
		if($fieldtypeInputfields) foreach($fieldtypeInputfields as $inputfield) {
			$inputfields->append($inputfield); 
		}

		if(count($inputfields)) $wrapper->append($inputfields); 

		$inputfields = $this->modules->get("InputfieldFieldset"); 
		$dummyPage = $this->fuel('pages')->get("/"); // only using this to satisfy param requirement 

		if($inputfield = $this->getInputfield($dummyPage)) {
			$inputfieldLabel = $inputfield->className(); 
			$inputfields->description = "The following settings are requested by $inputfieldLabel, which accompanies {$this->type->name}";	
			$inputfields->label = "Inputfield Settings";
			$inputfieldInputfields = $inputfield->getConfigInputfields();
			if($inputfieldInputfields) foreach($inputfieldInputfields as $i) { 
				$inputfields->append($i); 
			}
		}

		$wrapper->append($inputfields); 

		return $wrapper; 
	}


	public function getTable() {
		return "field_" . $this->settings['name']; 
	}

	/**
	 * The string value of a Field is always it's name
	 *
	 */
	public function __toString() {
		return $this->settings['name']; 
	}

	
}

