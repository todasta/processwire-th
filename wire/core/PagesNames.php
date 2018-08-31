<?php namespace ProcessWire;

/**
 * ProcessWire Pages Names
 *
 * ProcessWire 3.x, Copyright 2018 by Ryan Cramer
 * https://processwire.com
 *
 */ 

class PagesNames extends Wire {

	/**
	 * @var Pages
	 * 
	 */
	protected $pages; 

	/**
	 * Name for untitled/temporary pages
	 * 
	 * @var string
	 * 
	 */
	protected $untitledPageName = 'untitled';

	/**
	 * Delimiters that can separate words in page names
	 * 
	 * @var array
	 * 
	 */
	protected $delimiters = array('-', '_', '.');

	/**
	 * Default delimiter for separating words in page names
	 * 
	 * @var string
	 * 
	 */
	protected $delimiter = '-';

	/**
	 * Construct
	 *
	 * @param Pages $pages
	 * 
	 */
	public function __construct(Pages $pages) {
		$this->pages = $pages; 
		$pages->wire($this);
		$untitled = $this->wire('config')->pageNameUntitled;
		if($untitled) $this->untitledPageName = $untitled;
		parent::__construct();
	}

	/**
	 * Assign a name to given Page (if it doesn’t already have one)
	 * 
	 * @param Page $page
	 * @param string $format
	 * @return string Returns page name that was assigned
	 * @throws WireException
	 * 
	 */
	public function setupNewPageName(Page $page, $format = '') {
		
		$pageName = $page->name;

		// check if page already has a non-“untitled” name assigned that we should leave alone
		if(strlen($pageName) && !$this->isUntitledPageName($pageName)) return '';
		
		// determine what format should be used for the generated page name
		if(!strlen($format)) $format = $this->defaultPageNameFormat($page);
		
		// generate a page name from determined format
		$pageName = $this->pageNameFromFormat($page, $format);

		// ensure page name is unique	
		$pageName = $this->uniquePageName($pageName, $page);
		
		// assign to page
		$page->name = $pageName;
		
		// indicate that page has auto-generated name for savePageQuery (provides adjustName behavior for new pages)
		$page->setQuietly('_hasAutogenName', $pageName); 

		return $pageName;
	}

	/**
	 * Does the given page have an auto-generated name (during this request)?
	 * 
	 * @param Page $page
	 * @return string|bool Returns auto-generated name if present, or boolean false if not
	 * 
	 */
	public function hasAutogenName(Page $page) {
		$name = $page->get('_hasAutogenName');
		if(empty($name)) $name = false;
		return $name;
	}

	/**
	 * Is given page name an untitled page name?
	 * 
	 * @param string $name
	 * @return bool
	 * 
	 */
	public function isUntitledPageName($name) {
		list($namePrefix,) = $this->nameAndNumber($name);
		return $namePrefix === $this->untitledPageName;
	}

	/**
	 * If given name has a numbered suffix, return array with name (excluding suffix) and the numbered suffix 
	 * 
	 * Returns array like `[ 'name', 123 ]` where `name` is name without the suffix, and `123` is the numbered suffix.
	 * If the name did not have a numbered suffix, then the 123 will be 0 and `name` will be the given `$name`.
	 * 
	 * @param string $name
	 * @param string $delimiter Character(s) that separate name and numbered suffix
	 * @return array 
	 * 
	 */
	public function nameAndNumber($name, $delimiter = '') {
		if(empty($delimiter)) $delimiter = $this->delimiter;
		$fail = array($name, 0);
		if(strpos($name, $delimiter) === false) return $fail;
		$parts = explode($delimiter, $name);
		$suffix = array_pop($parts);
		if(!ctype_digit($suffix)) return $fail;
		$suffix = ltrim($suffix, '0');
		return array(implode($delimiter, $parts), (int) $suffix); 
	}

	/**
	 * Does the given name or Page have a number suffix? Returns the number if yes, or false if not
	 * 
	 * @param string|Page $name
	 * @param bool $getNamePrefix Return the name prefix rather than the number suffix? (default=false)
	 * @return int|bool|string Returns false if no number suffix, or int for number suffix or string for name prefix (if requested)
	 * 
	 */
	public function hasNumberSuffix($name, $getNamePrefix = false) {
		if($name instanceof Page) $name = $name->name;
		list($namePrefix, $numberSuffix) = $this->nameAndNumber($name);
		if(!$numberSuffix) return false;
		return $getNamePrefix ? $namePrefix : $numberSuffix;
	}

	/**
	 * Get the name format string that should be used for given $page if no name was assigned
	 * 
	 * @param Page $page
	 * @param array $options
	 *  - `fallbackFormat` (string): Fallback format if another cannot be determined (default='untitled-time').
	 *  - `parent` (Page|null): Optional parent page to use instead of $page->parent (default=null). 
	 * @return string
	 * 
	 */
	public function defaultPageNameFormat(Page $page, array $options = array()) {
		
		$defaults = array(
			'fallbackFormat' => 'untitled-time',
			'parent' => null, 
		);
		
		$options = array_merge($defaults, $options);
		$parent = $options['parent'] ? $options['parent'] : $page->parent();
		$format = ''; 

		if($parent && $parent->id && $parent->template->childNameFormat) {
			// if format specified with parent template, use that
			$format = $parent->template->childNameFormat;
			
		} else if(strlen("$page->title")) {
			// default format is title (when the page has one)
			$format = 'title';
			
		} else if($this->wire('languages') && $page->title instanceof LanguagesValueInterface) {
			// check for multi-language title
			/** @var LanguagesPageFieldValue $pageTitle */
			$pageTitle = $page->title;
			if(strlen($pageTitle->getDefaultValue())) $format = 'title';
		}
		
		if(empty($format)) {
			if($page->id && $options['fallbackFormat']) {
				$format = $options['fallbackFormat'];
			} else {
				$format = 'untitled-time';
			}
		}
		
		return $format;
	}

	/**
	 * Create a page name from the given format
	 *
	 * - Returns a generated page name that is not yet assigned to the page.
	 * - If no format is specified, it first falls back to page parent template `childNameFormat` property (if present).
	 * - If no format can be determined, it falls back to a randomly generated page name.
	 * - Does not check if page name is already in use.
	 *
	 * Options for $format argument:
	 *
	 * - `title` Build name based on “title” field.
	 * - `field` Build name based on any other field name you choose, replace “field” with any field name.
	 * - `text` Text already in the right format (that’s not a field name) will be used literally, replace “text” with your text.
	 * - `random` Randomly generates a name.
	 * - `untitled` Uses an auto-incremented “untitled” name.
	 * - `untitled-time` Uses an “untitled” name followed by date/time number string. 
	 * - `a|b|c` Builds name from first matching field name, where a|b|c are your field names.
	 * - `{field}` Builds name from the given field name.
	 * - `{a|b|c}` Builds name first matching field name, where a|b|c would be replaced with your field names.
	 * - `date:Y-m-d-H-i` Builds name from current date - replace “Y-m-d-H-i” with desired wireDate() format.
	 * - `string with space` A string that does not match one of the above and has space is assumed to be a wireDate() format.
	 * - `string with /` A string that does not match one of the above and has a “/” slash is assumed to be a wireDate() format.
	 *
	 * For formats above that accept a wireDate() format, see `WireDateTime::date()` method for format details. It accepts PHP
	 * date() format, PHP strftime() format, as well as some other predefined options.
	 *
	 * @param Page $page
	 * @param string $format Optional format. If not specified, pulls from $page’s parent template.
	 *
	 * @return string
	 *
	 */
	public function pageNameFromFormat(Page $page, $format = '') {
		
		if(!strlen($format)) $format = $this->defaultPageNameFormat($page);
		$format = trim($format);
		$name = '';
		
		if($format === 'title' && !strlen(trim((string) $page->title))) {
			$format = 'untitled-time';
		}

		if($format === 'title') {
			// title	
			$name = trim((string) $page->title);
			
		} else if($format === 'random') {
			// globally unique randomly generated page name
			$name = $this->uniqueRandomPageName();

		} else if($format === 'untitled') {
			// just untitled
			$name = $this->untitledPageName();
			
		} else if($format === 'untitled-time') {
			// untitled with datetime, i.e. “untitled-0yymmddhhmmss” (note leading 0 differentiates from increment)
			$dateStr = date('ymdHis');
			$name = $this->untitledPageName() . '-0' . $dateStr;

		} else if(strpos($format, '}')) {
			// string with {field_name} to text
			$name = $page->getText($format, true, false);

		} else if(strpos($format, '|')) {
			// field names separated by "|" until one matches
			$name = $page->getUnformatted($format);

		} else if(strpos($format, 'date:') === 0) {
			// specified date format
			list(, $format) = explode('date:', $format);
			if(empty($format)) $format = 'Y-m-d H:i:s';
			$name = wireDate(trim($format));

		} else if(strpos($format, ' ') !== false || strpos($format, '/') !== false) {
			// date assumed when spaces or slashes present in format
			$name = wireDate($format);

		} else if($this->wire('sanitizer')->fieldName($format) === $format) {
			// single field name or predefined string
			// this can also return null, which falls back to if() statement below
			$name = (string) $page->getUnformatted($format);
		}

		if(!strlen($name)) {
			// predefined string that is not a field name
			$name = $format;
		}

		$utf8 = $this->wire('config')->pageNameCharset === 'UTF8';
		$sanitizer = $this->wire('sanitizer');
		$name = $utf8 ? $sanitizer->pageNameUTF8($name) : $sanitizer->pageName($name, Sanitizer::translate);

		return $name;
	}

	/**
	 * Get a unique page name
	 *
	 * 1. If given no arguments, it returns a random globally unique page name.
	 * 2. If given just a $name, it returns that name (if globally unique), or an incremented version of it that is globally unique.
	 * 3. If given both $page and $name, it returns given name if unique in parent, or incremented version that is.
	 * 4. If given just a $page, the name is pulled from $page and behavior is the same as #3 above.
	 *
	 * The returned value is not yet assigned to the given $page, so if it is something different than what
	 * is already on $page, you’ll want to assign it manually after this.
	 *
	 * @param string|Page $name Name to make unique, or Page to pull it from.
	 * @param Page||string|null You may optionally specify Page or name in this argument if not in the first.
	 *  Note that specifying a Page here or in the first argument is important if the page already exists, as it is used
	 *  as the page to exclude when checking for name collisions, and we want to exclude $page from that check.
	 * @param array $options 
	 *  - `parent` (Page|null): Optionally specify a different parent if $page does not currently have the parent you want to use.
	 *  - `language` (Language|int): Get unique for this language (if multi-language page names active). 
	 * @return string Returns unique name
	 *
	 */
	public function uniquePageName($name = '', $page = null, array $options = array()) {
		
		$defaults = array(
			'page' => null, 
			'parent' => null, 
			'language' => null 
		);

		$options = array_merge($defaults, $options);

		if($name instanceof Page) {
			$_name = is_string($page) ? $page : '';
			$page = $name;
			$name = $_name;
		}
		
		if($page) {
			if($options['parent'] === null) $options['parent'] = $page->parent();
			if(!strlen($name)) $name = $page->name;
			$options['page'] = $page;
		}
		
		if(!strlen($name)) {
			// no name currently present, so we need to determine what kind of name it should have
			if($page) {
				$format = $this->defaultPageNameFormat($page, array(
					'fallbackFormat' => $page->id ? 'random' : 'untitled-time',
					'parent' => $options['parent']
				));
				$name = $this->pageNameFromFormat($page, $format); 
			} else {
				$name = $this->uniqueRandomPageName();
			}
		}
		
		while($this->pageNameExists($name, $options)) {
			$name = $this->incrementName($name);
		}

		return $name;
	}

	/**
	 * If name exceeds maxLength, truncate it, while keeping any numbered suffixes in place
	 * 
	 * @param string $name
	 * @param int $maxLength
	 * @return string
	 * 
	 */
	public function adjustNameLength($name, $maxLength = 0) {

		if($maxLength < 1) $maxLength = Pages::nameMaxLength;
		if(strlen($name) <= $maxLength) return $name;

		$trims = implode('', $this->delimiters);
		$pos = 0;

		list($namePrefix, $numberSuffix) = $this->nameAndNumber($name);

		if($namePrefix !== $name) {
			$numberSuffix = $this->delimiter . $numberSuffix;
			$maxLength -= strlen($numberSuffix);
		} else {
			$numberSuffix = '';	
		}
	
		if(strlen($namePrefix) > $maxLength) {
			$namePrefix = substr($namePrefix, 0, $maxLength);
		}
	
		// find word delimiter closest to end of string
		foreach($this->delimiters as $c) {
			$p = strrpos($namePrefix, $c);
			if((int) $p > $pos) $pos = $p;
		}
		
		// use word delimiter pos as maxLength when it’s relatively close to the end
		if(!$pos || $pos < (strlen($namePrefix) / 1.3)) $pos = $maxLength;
		
		$name = substr($namePrefix, 0, $pos);
		$name = rtrim($name, $trims);

		// append number suffix if there was one
		if($numberSuffix) $name .= $numberSuffix;
		
		return $name;
	}

	/**
	 * Increment the suffix of a page name, or add one if not present
	 * 
	 * @param string $name
	 * @param int|null $num Number to use, or omit to determine and increment automatically
	 * @return string
	 * 
	 */
	public function incrementName($name, $num = null) {
		
		list($namePrefix, $n) = $this->nameAndNumber($name); 
		
		if($namePrefix !== $name) {
			if($num) {
				$num = (int) $num;
				$name = $namePrefix . $this->delimiter . $num;
			} else {
				$zeros = '';
				while(strpos($name, $namePrefix . $this->delimiter . "0$zeros") === 0) $zeros .= '0';
				$name = $namePrefix . $this->delimiter . $zeros . (++$n);
			}
		} else {
			if(!is_int($num)) $num = 1; 
			$name = $namePrefix . $this->delimiter . $num;
		}
		
		return $this->adjustNameLength($name);
	}

	/**
	 * Is the given name is use by a page?
	 *
	 * @param string $name
	 * @param array $options
	 *  - `page` (Page|int): Ignore this Page or page ID
	 *  - `parent` (Page|int): Limit search to only this parent.
	 *  - `multilang` (bool): Check other languages if multi-language page names supported? (default=false)
	 *  - `language` (Language|int): Limit check to only this language [also implies multilang option] (default=null)
	 *
	 * @return int Returns quantity of pages using name, or 0 if name not in use.
	 *
	 */
	public function pageNameExists($name, array $options = array()) {

		$defaults = array(
			'page' => null,
			'parent' => null,
			'language' => null,
			'multilang' => false,
		);

		$options = array_merge($defaults, $options);
		$languages = $options['multilang'] || $options['language'] ? $this->wire('languages') : null;
		if($languages && !$this->wire('modules')->isInstalled('LanguageSupportPageNames')) $languages = null;

		$wheres = array();
		$binds = array();
		$parentID = $options['parent'] === null ? null : (int) "$options[parent]";
		$pageID = $options['page'] === null ? null : (int) "$options[page]";

		if($languages) {
			foreach($languages as $language) {
				if($options['language'] && "$options[language]" !== "$language") continue;
				$property = $language->isDefault() ? 'name' : 'name' . (int) $language->id;
				$wheres[] = "$property=:name$language->id";
				$binds[":name$language->id"] = $name;
			}
			$wheres = array('(' . implode(' OR ', $wheres) . ')');
		} else {
			$wheres[] = 'name=:name';
			$binds[':name'] = $name;
		}

		if($parentID) {
			$wheres[] = 'parent_id=:parent_id';
			$binds[':parent_id'] = $parentID; 
		}
		if($pageID) {
			$wheres[] = 'id!=:id';
			$binds[':id'] = $pageID;
		}

		$sql = 'SELECT COUNT(*) FROM pages WHERE ' . implode(' AND ', $wheres);
		$query = $this->wire('database')->prepare($sql);

		foreach($binds as $key => $value) {
			$query->bindValue($key, $value);
		}

		$query->execute();
		$qty = (int) $query->fetchColumn();
		$query->closeCursor();

		return $qty;
	}

	/**
	 * Get a random, globally unique page name
	 *
	 * @param array $options
	 *  - `page` (Page): If name is or should be assigned to a Page, specify it here. (default=null)
	 *  - `length` (int): Required/fixed length, or omit for random length (default=0).
	 *  - `min` (int): Minimum required length, if fixed length not specified (default=6).
	 *  - `max` (int): Maximum allowed length, if fixed length not specified (default=min*2).
	 *  - `alpha` (bool): Include alpha a-z letters? (default=true)
	 *  - `numeric` (bool): Include numeric digits 0-9? (default=true)
	 *  - `confirm` (bool): Confirm that name is globally unique? (default=true)
	 *  - `parent` (Page|int): If specified, name must only be unique for this parent Page or ID (default=0).
	 *  - `prefix` (string): Prepend this prefix to page name (default='').
	 *  - `suffix` (string): Append this suffix to page name (default='').
	 *
	 * @return string
	 *
	 */
	public function uniqueRandomPageName($options = array()) {

		$defaults = array(
			'page' => null,
			'length' => 0,
			'min' => 6,
			'max' => 0,
			'alpha' => true,
			'numeric' => true,
			'confirm' => true,
			'parent' => 0,
			'prefix' => '',
			'suffix' => '',
		);

		if(is_int($options)) $options = array('length' => $options);
		$options = array_merge($defaults, $options);
		$rand = new WireRandom();
		$this->wire($rand);

		do {
			if($options['length'] < 1) {
				if($options['min'] < 1) $options['min'] = 6;
				if($options['max'] < $options['min']) $options['max'] = $options['min'] * 2;
				if($options['min'] == $options['max']) {
					$length = $options['max'];
				} else {
					$length = mt_rand($options['min'], $options['max']);
				}
			} else {
				$length = (int) $options['length'];
			}

			if($options['alpha'] && $options['numeric']) {
				$name = $rand->alphanumeric($length, array('upper' => false, 'noStart' => '0123456789'));
			} else if($options['numeric']) {
				$name = $rand->numeric($length);
			} else {
				$name = $rand->alpha($length);
			}

			$name = $options['prefix'] . $name . $options['suffix'];

			if($options['confirm']) {
				$qty = $this->pageNameExists($name, array('page' => $options['page']));
			} else {
				$qty = 0;
			}

		} while($qty);

		if($options['page'] instanceof Page) $options['page']->set('name', $name);

		return $name;
	}

	/**
	 * Return the untitled page name string
	 * 
	 * @return string
	 * 
	 */
	public function untitledPageName() {
		return $this->untitledPageName;
	}
	
}
