<?php
/*
 * Main Plugin File
 * Does all the magic!
 *
 * @package    pcDTR
 * @version    3.1.5
 *
 * @author     Otherland <info@otherland.se>
 * @link       http://www.otherland.se
 * @copyright  Copyright (C) 2009 Otherland! All Rights Reserved
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU/GPL
*/

/* 
    PCDTR - PHP+CSS Dynamic Text Replacement
    by Joao Makray <joaomak.net/util/pcdtr> 
*/

// no direct access
defined( '_JEXEC' ) or die( 'Restricted access' );
defined( 'DS' ) || define( 'DS', DIRECTORY_SEPARATOR );
//error_reporting(E_ERROR | E_WARNING | E_PARSE | E_NOTICE);

if($mainframe->isAdmin()) {
	return;
}

jimport( 'joomla.plugin.plugin' );
/**
 * Joomla! pcDTR plugin
 *
 * @package		Joomla
 * @subpackage	System
 */
class plgSystempcDTR extends JPlugin
{
	var $poweredBy			= '<!-- pcDTR 3.1.5 by www.otherland.se %s -->';  // remove this if you wish - or keep it. thanks :)!
	function plgSystempcDTR(& $subject, $config)
	{
		global $mainframe;
		if ($mainframe->isAdmin())
		{
			// This plugin is only relevant for use within the frontend!
			return;
		}
		parent::__construct($subject, $config);
	}
	
	function onAfterInitialise()
	{
		$document 		=& JFactory::getDocument();
		$plugin			=& JPluginHelper::getPlugin('system', 'pcdtr');
		$pluginParams	= new JParameter($plugin->params);

		$document->addStyleSheet(JURI::base(true).'/'.$this->getCss($pluginParams),'text/css',"screen");
		$document->addStyleSheet(JURI::base(true).'/plugins/system/pcdtr/css.php','text/css',"screen");
		$document->addStyleDeclaration('css.change');

		$path = JPath::clean(JPATH_CACHE.DS.'pcDTR'.DS);
		if (!is_dir($path) && !is_file($path))
		{
			jimport('joomla.filesystem.*');
			JFolder::create($path, 0777);
			JFile::write($path.DS."index.html", "<html>\n<body bgcolor=\"#FFFFFF\">\n</body>\n</html>");
		}
	}

	function onAfterRender() 
	{
		$t['start_all'] = microtime(true);
		require_once('pcdtr/parseCSS.php');
		require_once('pcdtr/simple_html_dom.php');
		
		$plugin			=& JPluginHelper::getPlugin('system', 'pcdtr');
		$pluginParams	= new JParameter($plugin->params);
		$css			= new CSS();
		$dom			= new simple_html_dom();
		$body 			= JResponse::getBody();
		$skipClass 		= explode(',', str_replace(' ', '', $pluginParams->get('skip_class')));

		$css->parseFile($this->getCss($pluginParams));
		$css->css = $css->css;
		if (!is_array($css->csstags)) return false;
		$css->csstags = array_reverse($css->csstags,true);

		$dtr			= new pcDTR($pluginParams, $css);
		
		$t['start_dom'] = microtime(true);
		$dom->load($body);
		
		foreach ($css->csstags as $tag) {
			if ($dtr->get(array($tag, 'parentExists'), 0, '_param')) continue;
			
			foreach ($dom->find($tag) as $node)
			{
				if (substr($node->class,-5)=='pcdtr') continue;
				$tmp = $node->parent;
				for ($i = 1; ; $i++) {
					if (!isset($tmp)) {
						break;
					} else {
						$skip = in_array($tmp->class,$skipClass) ? $skip = 1 : $skip = 0;
						if ($skip) break;
					}
					$tmp = $tmp->parent;
				}
				if ($skip) continue;

				if (!$dtr->get(array($tag, 'fontFile'), 0, '_param'))
				{
					$node->outertext.='<!--font not found-->';
					continue;
				}
				
				$split = $dtr->splitElement($node, $tag);

				if (substr($node->parent->class,-5)!='pcdtr')
				{
					if ($node->class) $node->class.=' ';
					$node->class.='pcdtr';
				}
				$node->innertext=$split;
			}
		}
		$css = $dtr->createCss();
		$dom = str_replace('css.change', $css, $dom);
		$t['end_dom'] = microtime(true);
		$t['start_img'] = microtime(true);
		list($groups, $change) = $dtr->createImage();
		if ($groups) 
		{
			foreach ($groups as $n=>$group)
			{
				$outPath = JURI::base(true).DS.substr(JPATH_CACHE, strlen(JPATH_BASE)+1).DS.'pcDTR'.DS;
				$dom = str_replace('url.change.'.$group, $outPath.$change[$n], $dom);
			}
		}
		$t['end_img'] = microtime(true);
		$t['end_all'] = microtime(true);
		$tttime1=round(($t['end_all']-$t['start_all']),4);
		$tttime2=round(($t['end_dom']-$t['start_dom']),4);
		$tttime3=round(($t['end_img']-$t['start_img']),4);

		$this->poweredBy = str_replace('%s', '. Time total: '.$tttime1.'sec. Time Parse: '.$tttime2.'sec. Time img: '.$tttime3.'sec', $this->poweredBy);
		$dom = str_replace('</body>', $this->poweredBy.'</body>', $dom);
		JResponse::setBody($dom);
	}

	function getCss($params)
	{
		$cssFile		= $params->get('default_css');
		$customCss 		= explode(',', $params->get('custom_css'));
		if ($customCss[0] == '') return $cssFile;
		foreach ($customCss as $item) {
			list($template, $templateCss) = explode(' ', $item);
			if (JFactory::getApplication()->getTemplate() == $template) {$templateCss = $templateCss; continue;}
		}
		if (is_readable($templateCss)) 
			return $templateCss;
		else
			return $cssFile;
	}
}

class pcDTR
{
	var $_params 	= null;
	var $_data 		= array();
	var $_css		= null;
	var $_items		= array();
	
	function __construct(&$params, $css)
	{
		$this->_params = $params;
		//parse our css and group them into groups
		$this->groupCss($css);
		//create hash strings for our css groups
		$this->createHash();
		//check resample rate
		if ($this->_params->get('resample_rate') > 4 || $this->_params->get('resample_rate') < 1)
			$this->_params->set('resample_rate', 1);
		$this->id = 1;
		$this->lineCounter = 1;
		$this->set('dummy', imagecreate(1, 1));
	}
	
	function set($property, $value=null, $array='_default')
	{
		if (is_array($value)) 
		{
			if (isset($this->_data[$array]->$property) && is_array($this->_data[$array]->$property))
				$this->_data[$array]->$property += $value;
			else 
				$this->_data[$array]->$property = $value;
			return current($value);
		} 
		else 
		{
			$this->_data[$array]->$property = $value;
			return $value;
		}
	}
	
	function get($property, $default=null, $array='_default')
	{
		if (is_array($property)) 
		{
			if (isset($this->_data[$array]->$property[0])) 
			{
				$tmp = $this->_data[$array]->$property[0];
				if (isset($tmp[$property[1]]))
					return $tmp[$property[1]];
			}
		}
		else 
		{
			if (isset($this->_data[$array]->$property))
				return $this->_data[$array]->$property;
		}
		return $default;
	}
	
	function def($property, $default=null)
	{
		if (isset($property)) 
			return $property;
		return $default;
	}	

	/**
	 * Splits up a element
	 *
	 * @param	dom node, element tag
	 * @return 	new innertext with dtr tags
	 */
	function splitElement($node, $tag)
	{
		$this->set('tag', $tag);
		$innertext = '';
		$this->hoverLineCounter = 1;
		$this->set('last_width', 0);
		$string = str_replace(array("\t","\n"),'',$node->innertext);
		$innerSplit = array_filter(explode('<', $string));
		$item = new pcDTRItem();
		
		$item->set('hash', md5(json_encode($innerSplit)));
		$item->set('string', $string);
		$this->set('item', $item);

		if (count($innerSplit) > 1)
		{
			foreach ($innerSplit as $num => $innerstr) {
				if (isset($innerSplit[$num+1])) {
					$nextstr = $innerSplit[$num+1];
					$nexttmp = explode('>', $nextstr);
					$nextInnerTag = $this->set('nextInnerTag', array_shift(explode(' ', trim($nexttmp[0]))));
				}
				
				$tmp = explode('>', $innerstr);
				if(count($tmp) > 1 && substr($innerstr,0,1) != '/')
				{
					$attrs = explode(' ', trim($tmp[0]));
					$innerTag = $this->set('innerTag', array_shift($attrs));
					$innerHTML = $this->changeElement(array_pop($tmp), $tag, $innerTag);
					if ($innerTag == 'br')
						$innertext .= '<'.$tmp[0].'>'.$innerHTML;
					else
						$innertext .= '<'.$tmp[0].'>'.$innerHTML.'</'.$innerTag.'>';	
				}
				elseif (substr($innerstr,0,1) == '/' && $tmp[1] != '' || substr($innerstr,0,1) != '/')
				{
					$innerTag = $this->set('innerTag', substr($innerstr,0,1) == '/' ? substr($innerstr,0,2) : null );
					$innertext .= $this->changeElement(array_pop($tmp), $tag);
				}
			}
		}
		else 
		{
			$innertext = $this->changeElement(trim($node->innertext), $tag);
		}
		$this->_items[$this->get('group')][] = $item;
		$this->id++;
		return $innertext;
	}

	/**
	 * Adds pcdtr spans to out elements
	 *
	 * @param 	string text to dtr, element tag, innertag
	 * @return 	a span with text and id
	 */
	function changeElement($string, $tag, $innerTag = null) {
		$inline = 'span';
		$out = '';

		if ($this->get($tag.' '.$innerTag, 0, '_param'))
			$tag = $this->set('tag', $tag.' '.$innerTag);
		else 
			$tag = $this->set('tag', $tag);

		$group = $this->set('group', $this->get(array($tag, 'group'), 'default', '_param'));
		$decoded = html_entity_decode($string, ENT_COMPAT, 'UTF-8');
		$decoded = $this->get(array($tag, 'textUppercase'), 0, '_param') ? mb_strtoupper($decoded) : $decoded;
		$decoded = $this->get(array($tag, 'textLowercase'), 0, '_param') ? mb_strtolower($decoded) : $decoded;

		if ($hovertag = $this->hover()) 
		{
				$tmp = $this->get('last_width', 0);
				$css = $this->get('css_hover:'.$hovertag, 0, $group);
				$lines = $this->imagettftextbox($css->font_size,$this->get(array($hovertag, 'fontFile'), 0, '_param'),$decoded,$css->letter_spacing,$css->line_height,$css->width, 1);
				$this->set('last_width', $tmp);
		}

		$css = $this->get('css:'.$tag, 0, $group);
		$lines = $this->imagettftextbox($css->font_size,$this->get(array($tag, 'fontFile'), 0, '_param'),$decoded,$css->letter_spacing,$css->line_height,$css->width);

		foreach($lines as $key => $item)
		{
			$out .= '<'.$inline.' id="pcdtr'.$key.'">'.htmlspecialchars($item).'</'.$inline.'>';
		}

		return $out;
	}

	/**
	 * Calculates how much text to fit in each inline element
	 */
	function imagettftextbox($font_size, $font_file, $string, $kerning = 0, $line_height = 0, $width = 0, $hover = 0) {
		$black = imagecolorallocate($this->get('dummy'), 0, 0, 0);
		$group = $this->get('group', 'default');
		$item = $this->get('item');
		$lines = array();

		if ($line_height) 
		{
			$height = $line_height;
		} 
		else
		{
			$bbox = imagettfbbox($font_size,0,$font_file,$this->_params->get('test_string'));
			$height = abs($bbox[5])+abs($bbox[3]);
		}

		$bbox = imagettftext($this->get('dummy'), $font_size*$this->_params->get('resample_rate'), 0, 0, 0, $black, $font_file, ' ');
		$line_space = ($bbox[2] + $kerning) / $this->_params->get('resample_rate');

		if ($width==0)
		{
			$wrap = wordwrap($string, $this->_params->get('letter_wrap'), ' \n');
			$text_lines = explode('\n',$wrap);
			
			foreach ($text_lines as $word) 
			{
				if ($this->get('nextInnerTag') == 'br' && end($text_lines) == $word) $word = rtrim($word);
				if ($this->get('innerTag') == 'br') $word = ltrim($word);
				$line_width = 0;
				$letters = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY);
				foreach ($letters as $letter)
				{
					$bbox = imagettftext($this->get('dummy'), $font_size*$this->_params->get('resample_rate'), 0, 0, 0, $black, $font_file, $letter);
					$line_width += (($bbox[2] + $kerning) / $this->_params->get('resample_rate'));
				}
				$this->set('width', $this->get('width', 0, $group) < round($line_width) ? round($line_width) : $this->get('width', null, $group), $group);
				$lines[$this->lineCounter] = $word;
				if ($hover)
					$item->set('hover_lines', array($this->hoverLineCounter++ => array('line' => $word, 'width' => round($line_width), 'height' => $height)));
				elseif ($ishover = $this->hover())
					$item->set('lines', array($this->lineCounter++ => array('tag' => $this->get('tag'), 'line' => $word, 'width' => round($line_width), 'height' => $height, 'hovertag' => $ishover)));
				else
					$item->set('lines', array($this->lineCounter++ => array('tag' => $this->get('tag'), 'line' => $word, 'width' => round($line_width), 'height' => $height)));
			}	
		}
		else
		{
			$this->set('width', $this->get('width', 0, $group) < $width ? $width : $this->get('width', null, $group), $group);
			$text = "";
			$line_width_total = 0 + $this->get('last_width', 0);
			$text_line = explode(' ', trim($string));
	
			foreach ($text_line as $num => $words) 
			{
				$line_width = 0;
				$word = $words;
				if ($this->get('innerTag') == '/a' && $num == 0) $word = ' '.$words;
				$letters = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY);
				foreach ($letters as $letter)
				{
					$bbox = imagettftext($this->get('dummy'), $font_size*$this->_params->get('resample_rate'), 0, 0, 0, $black, $font_file, $letter);
					$line_width += (($bbox[2] + $kerning) / $this->_params->get('resample_rate'));
				}
				$line_width_total += $line_width;
				
				if ( $line_width_total > $width ) {
					$lines[$this->lineCounter] = rtrim($text);
					if ($hover)
						$item->set('hover_lines', array($this->hoverLineCounter++ => array('line' => $text, 'width' => round($line_width_total - $line_width - $line_space) - $this->get('last_width', 0), 'height' => $height)));
					elseif ($ishover = $this->hover())
						$item->set('lines', array($this->lineCounter++ => array('tag' => $this->get('tag'), 'line' => $text, 'width' => round($line_width_total - $line_width - $line_space) - $this->get('last_width', 0), 'height' => $height, 'hovertag' => $ishover)));
					else
						$item->set('lines', array($this->lineCounter++ => array('tag' => $this->get('tag'), 'line' => $text, 'width' => round($line_width_total - $line_width - $line_space) - $this->get('last_width', 0), 'height' => $height)));

					$line_width_total = $line_width + $line_space;
					$text = $word.' ';
					$this->set('last_width', 0);
				}
				else
				{
					$line_width_total += $line_space;
					$text .= $word.' ';
				}
			}
			if ($this->get('nextInnerTag') == 'a' || $this->get('nextInnerTag') == 'span' || $this->get('nextInnerTag') == '/span') {
				$lines[$this->lineCounter] = $text;
				$line_width_total = round($line_width_total);			
			} else {
				$lines[$this->lineCounter] = rtrim($text);
				$line_width_total = round($line_width_total - $line_space);
			}
			if ($hover)
				$item->set('hover_lines', array($this->hoverLineCounter++ => array('line' => $text, 'width' =>  $line_width_total - $this->get('last_width', 0), 'height' => $height)));
			elseif ($ishover = $this->hover())
				$item->set('lines', array($this->lineCounter++ => array('tag' => $this->get('tag'), 'line' => $text, 'width' => $line_width_total - $this->get('last_width', 0), 'height' => $height, 'hovertag' => $ishover)));
			else
				$item->set('lines', array($this->lineCounter++ => array('tag' => $this->get('tag'), 'line' => $text, 'width' => $line_width_total - $this->get('last_width', 0), 'height' => $height)));

			if (!$hover) {
				if ($this->get('nextInnerTag') == 'br')
					$this->set('last_width', 0);
				else
					$this->set('last_width', round($line_width_total));
			}
		}
		return $lines;
	}

	/**
	 * Creates our css string to replace in header
	 *
	 * @return 	css string
	 */
	function createCss() {
		foreach ($this->_items as $group => $item)
		{
			foreach ($item as $id => $values)
			{
				$counter = 1;
				foreach ($values->lines as $num => $value) 
				{
					$this->_css .= "#pcdtr".$num."{background-image:url(url.change.".$group.");background-position:0 -".$this->get('height', 0, $group)."px;width:".$value['width']."px;height:".$value['height']."px;}\n";
					$this->set('height', $this->get('height', 0, $group) + $value['height'], $group);
					if (isset($value['hovertag']))
					{
						$hover = $values->hover_lines->$counter;
						$this->_css .= $value['hovertag']." #pcdtr".$num."{background-position:0 -". $this->get('height', 0, $group)."px;width:".$hover['width']."px;height:".$hover['height']."px;}\n";
						$this->set('height', $this->get('height', 0, $group) + $hover['height'], $group);
						$counter++;
					}					
				}
			}
		}
		return $this->_css;
	}
	/**
	 * Creates all group images
	 *
	 * @return 	groups and hashstrings to replace css in header
	 */
	function createImage() {
		if (count($this->_items) == 0)
		{
			imagedestroy($this->get('dummy'));
			return array(false, false);
		}
		$extension = '.png';
		
		foreach ($this->_items as $group => $item) {
			//check if cached file exists
			$hash1 = $this->get('hash', 0, $group);
			$hash2 = '';
			foreach ($item as $id => $values)
				$hash2 .= $values->hash;

			$hash = md5($hash1.$hash2.$this->_params->get('resample_rate'));

			$cache_filename = JPATH_CACHE.DS.'pcDTR'.DS.$hash.$extension;
			if ($this->_params->get('cache_images') && (file_exists($cache_filename)))
			{
				$groups[] = $group;
				$hashfiles[] = $hash.$extension;
				continue;
			}

			// create big image for resampling
			$canvas = imagecreatetruecolor($this->get('width', null, $group)*$this->_params->get('resample_rate'), $this->get('height', null, $group)*$this->_params->get('resample_rate'));
			imagesavealpha($canvas, true);
			$transcolor = imagecolorallocatealpha($canvas, 0,0,0,127);
			imagefill($canvas ,0,0 ,$transcolor);
			$height = 0;
			foreach ($item as $id => $values)
			{
				$counter = 1;
				foreach ($values->lines as $num => $value)
				{
					list($image, $width) = $this->createItem($value, $group);
					imagecopy($canvas, $image, 0, $height, 0, 0, $width*$this->_params->get('resample_rate'), $value['height']*$this->_params->get('resample_rate'));
					$height += $value['height']*$this->_params->get('resample_rate');
					imagedestroy($image);
					
					if (isset($value['hovertag']))
					{
						//create hover text image
						$hover = $values->hover_lines->$counter;
						$hover['tag'] = $value['hovertag'];
						list($image_hover, $width) = $this->createItem($hover, $group, 'css_hover');
						//copy text image to big image
						imagecopy($canvas, $image_hover, 0, $height, 0, 0, $width*$this->_params->get('resample_rate'), $hover['height']*$this->_params->get('resample_rate'));
						$height += $hover['height']*$this->_params->get('resample_rate');
						imagedestroy($image_hover);
						$counter++;
					}				
				
				}
			}
			//create final image for resampling and keep alpha settings
			$final = imagecreatetruecolor($this->get('width', null, $group), $this->get('height', null, $group));
			imagealphablending($final, false);
			imagesavealpha($final, true);
			imagecopyresampled( $final, $canvas, 0,0,0,0, $this->get('width', null, $group), $this->get('height', null, $group), $this->get('width', null, $group)*$this->_params->get('resample_rate'), $this->get('height', null, $group)*$this->_params->get('resample_rate') );
		
			//for testing!!
			//header('Content-type: ' . $mime_type);
			//imagepng($final);

			// save copy of image for cache
			imagepng($final, $cache_filename);
			
			imagedestroy($final);
			imagedestroy($canvas);

			$groups[] = $group;
			$hashfiles[] = $hash.$extension;
		}
		imagedestroy($this->get('dummy'));
		return array($groups, $hashfiles);
	}

	/**
	 * Creates a text image for the group
	 *
	 * @param 	item to create, css group, type of tag 'css' or 'css_hover'
	 * @return 	an image file and the width
	 */
	function createItem($item, $group, $type='css') {
		// allocate colors, size and draw text
		$rate = $this->_params->get('resample_rate');
		$css = $this->get($type.':'.$item['tag'], null, $group);

		$background_rgb = $this->hex_to_rgb($this->def($css->background_color,'#ffffff'));
		$font_rgb = $this->hex_to_rgb($this->def($css->color,'#000000'));
		
		$maxhbox = imagettfbbox($this->def($css->font_size,20)*$rate, 0, $this->get(array($item['tag'], 'fontFile'), 0, '_param'), $this->_params->get('test_string'));
		// calculate font baseline
		$int_y = abs($maxhbox[5]-$maxhbox[3])-$maxhbox[1];
		// calculate line-height
		$line_height = ($item['height']*$rate - (abs($maxhbox[5])+abs($maxhbox[3])))/2;
		$int_y += $line_height;

		$dip = $this->get_dip($this->get(array($item['tag'], 'fontFile'), 0, '_param'), $this->def($css->font_size,20));
		
		$underline_x = $x = 0;	
		$width = $this->get('width',0,$group) > 0 ? $this->get('width',null,$group)+$rate : $item['width']+$rate+$this->def($css->letter_spacing,0);

		$image = imagecreatetruecolor($width*$rate, $this->get('height',null,$group)*$rate);
		$background_color = imagecolorallocate($image, $background_rgb['red'], $background_rgb['green'], $background_rgb['blue']);
		imagefill($image, 0, 0, $background_color);
		$font_color = imagecolorallocate($image, $font_rgb['red'], $font_rgb['green'], $font_rgb['blue']) ;
		// set transparency
		if ($this->def($css->background_transparent,0))
		{
			$background_color = imagecolorallocatealpha($image, $background_rgb['red'], $background_rgb['green'], $background_rgb['blue'], 127);
			imagefill($image, 0, 0, $background_color);
		}
		$letters = preg_split('//u', $item['line'], -1, PREG_SPLIT_NO_EMPTY);
		foreach ($letters as $letter)
		{	
			$bbox = imagettftext($image, $this->def($css->font_size,20)*$rate, 0, $x, $int_y, $font_color, $this->get(array($item['tag'], 'fontFile'), 0, '_param'), $letter );
			$x = $bbox[2] + $this->def($css->letter_spacing,0);				
		}
		// underline
		$underline_y = (abs($maxhbox[5])+abs($maxhbox[3]))-($dip/2)+$line_height-1;
		if ($this->get(array($item['tag'], 'textUnderline'), 0, '_param')) {imagefilledrectangle($image, $underline_x, $underline_y, $underline_x+$item['width']*$rate, $underline_y+($rate/2), $font_color);}

		return array($image, $width);
	}

	function debug() {
		return '<pre>'.htmlspecialchars(print_r($this, TRUE)).'</pre>';
	}

	/**
	 * Create a hash string of a css group 
	 */
	function createHash() 
	{
		foreach ($this->_data as $group => $css) 
		{
			if ($group[0] == '_') continue;
			$this->set('hash', md5(json_encode($css)), $group);
		}
	}

	/**
	 * Parse and groups css
	 *
	 * @param	css styles
	 */
	function groupCss($css) 
	{
		foreach ($css->css as $tag => $style) 
		{
			if (isset($style['font-size'])) $style['font-size'] = floatval($style['font-size']);
			if (isset($style['font-family'])) $style['font-family'] = $this->findFont($style['font-family']);
			$group = isset($style['group']) ? $this->set($tag, array('group' => $style['group']), '_param') : $this->set($tag, array('group' => 'default'), '_param');

			$parentExists=false;

			$tmp = preg_match('/:hover/', $tag) ? explode(':',$tag) : explode(' ',$tag);
			array_pop($tmp);
			$parentNode = implode(' ',$tmp);
			if(!$this->get($parentNode, 0, '_param'))
			{
				$tmp = explode(' ',$parentNode);
				array_pop($tmp);
				$parentNode = implode(' ',$tmp);				
			}
			if($this->get($parentNode, 0, '_param'))
			{
				$this->set($tag, array('parentExists' => true), '_param');

				$parentStyle = $this->get('css:'.$parentNode, 0, $group);

				$values = array('font-family', 'font-size', 'group', 'width', 'text-align', 'text-decoration', 'text-transform', 'letter-spacing', 'line-height', 'color', 'background-color', 'background-transparent');
				$valuesObj = array('font_family', 'font_size', 'group', 'width', 'text_align', 'text_decoration', 'text_transform', 'letter_spacing', 'line_height', 'color', 'background_color', 'background_transparent');

				foreach ($values as $num => $value)
					if (!isset($style[$value]) && isset($parentStyle->$valuesObj[$num])) $style[$value] = $parentStyle->$valuesObj[$num];
			}
			if (isset($style['text-decoration']) && $style['text-decoration'] == 'underline') $this->set($tag, array('textUnderline' => true), '_param');
			if (isset($style['text-transform']) && $style['text-transform'] == 'uppercase') $this->set($tag, array('textUppercase' => true), '_param');
			if (isset($style['text-transform']) && $style['text-transform'] == 'lowercase') $this->set($tag, array('textLowercase' => true), '_param');
			if (isset($style['font-family']) && $style['font-family'] != '') $this->set($tag, array('fontFile' => JPATH_SITE.DS.$this->_params->get('fonts_dir').DS.$style['font-family']), '_param');
			
			$type = preg_match('/:hover/', $tag) ? 'css_hover:' : 'css:';
			ksort($style);
			$style = new cssItem($style);
			$this->set($type.$tag, $style, $group);
		}
	}
	
	/**
	 * Check if font exists
	 *
	 * @param string font-family to find
	 * @return 	The found item
	 */
	function findFont($family)
	{
		$fontName = strtolower(array_shift(explode(',', $family)));
		foreach( glob(JPATH_SITE.DS.$this->_params->get('fonts_dir').DS.'*') as $item) 
		{
			 $fontItem = explode('.', end(explode('/',$item)));
			 if ($fontName == strtolower($fontItem[0]))
			 	return implode('.',$fontItem);
		}
	}

	/**
	 * Check if tag is a hover tag
	 *
	 * @return	the tag if its found
	 */
	function hover() {
		$tag = $this->get('tag');
		$innerTag = $this->get('innerTag');
		if ($this->get($tag.' '.$innerTag.':hover', 0, '_param'))
			return $tag.' '.$innerTag.':hover';
		if ($this->get($tag.':hover', 0, '_param'))
			return $tag.':hover';
		return false;
	}

	/**
	 * Convert hex to rgb
	 *
	 * @param	hex font color
	 * @return 	rgb output
	 */
	function hex_to_rgb($hex)
	{
		// remove '#'
		if(substr($hex,0,1) == '#')
			$hex = substr($hex,1);
	
		// expand short form ('fff') color
		if(strlen($hex) == 3) {
			$hex = substr($hex,0,1).substr($hex,0,1).substr($hex,1,1).substr($hex,1,1).substr($hex,2,1).substr($hex,2,1);
		}
	
		if(strlen($hex) != 6)
			$this->fatal_error('Error: Invalid color "'.$hex.'"');
	
		// convert
		$rgb['red'] = hexdec(substr($hex,0,2));
		$rgb['green'] = hexdec(substr($hex,2,2));
		$rgb['blue'] = hexdec(substr($hex,4,2));
	
		return $rgb ;
	}

	function get_dip($font,$size)
	{
		$test_chars = array_merge(range('0','9'),range('a','z'),range('A','Z'));
		$test_chars = implode('',$test_chars).'!@#$%^&*()\'"\\/;.,`~<>[]{}-+_-='; 
		$box = imagettfbbox($this->_params->get('resample_rate'), 0, $font, $test_chars);
		return $box[3];
	}	

	function fatal_error($message)
	{
		if (isset($_GET['debug'])) die($message);
		
		// send an image
		if (function_exists('ImageCreate'))
		{
			$width = ImageFontWidth(5) * strlen($message) + 10;
			$height = ImageFontHeight(5) + 10;
			if($image = ImageCreate($width, $height))
			{
				$background = ImageColorAllocate($image, 255, 255, 255);
				$text_color = ImageColorAllocate($image, 0, 0, 0);
				ImageString($image, 5, 5, 5, $message, $text_color);    
				header('Content-type: image/png'); 
				imagepng($image);
				imagedestroy($image);
				exit ;
			}
		}
	
		// send 500 code
		header("HTTP/1.0 500 Internal Server Error");
		print($message);
		exit ;
	}
}

class pcDTRItem
{
	function __construct() {}
	
	function set($property, $value=null, $array='_default')
	{
		if (is_array($value)) 
		{
			foreach ($value as $key => $value)
				$this->$property->$key = $value;
		} 
		else 
		{
			$this->$property = $value;
		}
	}
}

class cssItem
{
	function __construct(&$style) 
	{
		foreach ($style as $key => $value) {
			$key = str_replace('-', '_', $key);
			if(substr($value,-2,2) == 'px')
				$this->$key = floatval($value);
			else
				$this->$key = $value;
		}
	}
	
	function __get($var) {}
}