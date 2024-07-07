<?php

	/**
	 * @author Anton Puttemans (tunafish)
	 * @package plugins
	 */
	
	$plugin_is_filter 	= 9|CLASS_PLUGIN;
	$plugin_description = gettext('Reverse Geocodes images with GPS data.<br />
		Populates the <em>`location`</em>, <em>`city`</em>, <em>`state`</em> and <em>`country`</em> fields in the database.<br />
		When adding new images or hitting the "refresh metadata" button in the overview tab <a href="http://code.google.com/apis/maps/documentation/geocoding/#ReverseGeocoding">Google\'s Geocode API</a> is queried to reverse geocode the GPS data.<br />The plugin will convert coordinates to address components, looping over your active languages.<br /><br />
		<strong>Note: </strong>This plugin will not work when the image has <em>IPTC sublocation</em>, <em>IPTC city</em>, <em>IPTC state/province</em> or <em>IPTC country</em> metadata.<br />In this case ZP will automatically populate these fields for you, but NOT multilanguage..<br /><br />
		<p class=notebox>The cURL library is needed to transfer the data.<br />PHP +5.2 is required to parse the JSON responses.</p>'
	);
	$plugin_author 		= "Anton Puttemans (tunafish)";
	$plugin_version 	= '0.2';


	$plugin_disable = (function_exists('curl_init')) ? false : gettext('The <em>php_curl</em> extension is required');
	if ($plugin_disable) {
		setOption('zp_plugin_google_reverse_geocode', 0);
	} else {
		$option_interface 	= 'google_reverse_geocode_options';	
		zp_register_filter('image_metadata', 'google_reverse_geocode_new_image');
	}

	$option_interface 	= 'google_reverse_geocode_options';	


	class google_reverse_geocode_options {
	
		function google_reverse_geocode_options() {
			setOptionDefault('img_location', 'route');
			setOptionDefault('img_city', 'locality');
			setOptionDefault('img_state', 'administrative_area_level_1');
			setOptionDefault('img_country', 'country');
			
			setOptionDefault('rescan', 0);
		}
	
		function handleOption($option, $currentValue) {}
		
		function getOptionsSupported() {
			return 	array(
						array(
							'type'	=> OPTION_TYPE_NOTE, 
							'order' => 0.5,
							'desc'	=> "<p class='notebox'>" . gettext("<strong>Note: </strong>leave fields empty if you don't want them to process.") . "</p>"
						), 
						gettext('location') => array(
													'key' 	=> 'img_location', 
													'type' 	=> OPTION_TYPE_TEXTBOX, 
													'order' => 1, 
													'desc' 	=> gettext('suggested address component type') . ': <strong>route</strong>'
											),
						gettext('city') => array(
													'key' 	=> 'img_city', 
													'type' 	=> OPTION_TYPE_TEXTBOX, 
													'order' => 2, 
													'desc' 	=> gettext('suggested address component type') . ': <strong>locality</strong>'
											), 
						gettext('state') => array(
													'key' 	=> 'img_state', 
													'type' 	=> OPTION_TYPE_TEXTBOX, 
													'order' => 3, 
													'desc' 	=> gettext('suggested address component type') . ': <strong>administrative_area_level_1</strong> || <strong>administrative_area_level_2</strong>'
											),
						gettext('country') => array(
													'key' 	=> 'img_country', 
													'type' 	=> OPTION_TYPE_TEXTBOX, 
													'order' => 4, 
													'desc' 	=> gettext('suggested address component type') . ': <strong>country</strong>'
											),
						gettext('rescan all') => array(
													'key'	=> 'rescan', 
													'type'	=> OPTION_TYPE_CHECKBOX,
													'order'	=> 5,
													'desc'	=> gettext('If unchecked, only images with no data in the fields <em>`location`</em>, <em>`city`</em>, <em>`state`</em> or <em>`country`</em> will be reverse geocoded.<br />
																		<strong>Tip: </strong>You can rescan individual fields for specific images by clearing the data in admin-edit images<br /><br />
																		If checked, all images will be rescanned and reverse geocoded again.<br />
																		<strong>Warning: </strong>Rescanning will override all fields again so any individual field edits will be lost.'
																)
											),																		
						gettext('<p class="notebox">Address Component Types documentation on <a href="http://code.google.com/apis/maps/documentation/geocoding/#Types">Google Geocoding API</a></p>') => array(
													'key' 	=> 'note', 
													'type' 	=> OPTION_TYPE_CUSTOM,
													'order' => 6,
													'desc' 	=> ''
												)
					);
		}
	
	}


	function google_reverse_geocode_new_image($image) {

		$lat 		= $image->get('EXIFGPSLatitude');
		$lng 		= $image->get('EXIFGPSLongitude');
		$lat_ref	= $image->get('EXIFGPSLatitudeRef');
		$lng_ref	= $image->get('EXIFGPSLongitudeRef');
		
		$db_location 	= $image->get('location');
		$db_city 		= $image->get('city');
		$db_state 		= $image->get('state');
		$db_country 	= $image->get('country');

		// is GPS data found?
		if (!empty($lat) && !empty($lng) && !empty($lat_ref) && !empty($lng_ref)) {
		
			// convert N-E-S-W to decimal
			($lat_ref == "N") ? $lat_ref = "" : $lat_ref = "-";
			($lng_ref == "E") ? $lng_ref = "" : $lng_ref = "-";
			// string for Google
			$latlng = "$lat_ref$lat,$lng_ref$lng";
			
			// MULTI LANGUAGE
			if (getOption('multi_lingual')) {
						// multilanguage arrays for the fields
						$arr_location = $arr_city = $arr_state = $arr_country = array();
	
						$_languages = generateLanguageList();
						foreach ($_languages as $text => $locale) {

							$locale_code = substr($locale, 0, 2);
							$geocodeURL = "http://maps.googleapis.com/maps/api/geocode/json?latlng=$latlng&language=$locale_code&sensor=false";													
							$ch = curl_init($geocodeURL);
							curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
							$result = curl_exec($ch);
							$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
							curl_close($ch);
							
							if ($httpCode == 200) {
								$data = json_decode($result);
								if ($data->status == "OK") {
											if (count($data->results)) {
												foreach ($data->results[0]->address_components as $component) {
													
													if (getOption('rescan')) {
														if (in_array(getOption('img_location'), $component->types)) $arr_location[$locale] = $component->long_name;
													} else {
														if (empty($db_location)) { 
															if (in_array(getOption('img_location'), $component->types)) $arr_location[$locale] = $component->long_name; 
														}
													}
													
													if (getOption('rescan')) {
														if (in_array(getOption('img_city'), $component->types)) $arr_city[$locale] = $component->long_name;
													} else {
														if (empty($db_city)) { 
															if (in_array(getOption('img_city'), $component->types)) $arr_city[$locale] = $component->long_name;
														}												
													}
													
													if (getOption('rescan')) {
														if (in_array(getOption('img_state'), $component->types)) $arr_state[$locale] = $component->long_name;
													} else {
														if (empty($db_state)) { 	
															if (in_array(getOption('img_state'), $component->types)) $arr_state[$locale] = $component->long_name;
														}					
													}
													
													if (getOption('rescan')) {
															if (in_array(getOption('img_country'), $component->types)) $arr_country[$locale] = $component->long_name;
													} else {
														if (empty($db_country)) {
															if (in_array(getOption('img_country'), $component->types)) $arr_country[$locale] = $component->long_name;
														}							
													}

												}
											}
											
											if (!empty($arr_location)) 	$image->setLocation(serialize($arr_location));
											if (!empty($arr_city)) 		$image->setCity(serialize($arr_city));
											if (!empty($arr_state)) 	$image->setState(serialize($arr_state));
											if (!empty($arr_country)) 	$image->setCountry(serialize($arr_country));
											
											$image->save();	
								}
							}
						}
			
			}
			
			// SINGLE LANGUAGE
			else {
						// single language strings for the fields
						$str_location = $str_city = $str_state = $str_country = "";
				
						$locale = getUserLocale();
						
							$locale_code = substr($locale, 0, 2);
							$geocodeURL = "http://maps.googleapis.com/maps/api/geocode/json?latlng=$latlng&language=$locale_code&sensor=false";
							$ch = curl_init($geocodeURL);
							curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
							$result = curl_exec($ch);
							$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
							curl_close($ch);
	
							if ($httpCode == 200) {
								$data = json_decode($result);
								if ($data->status == "OK") {
											if (count($data->results)) {
												foreach ($data->results[0]->address_components as $component) {
												
													if (getOption('rescan')) {
														if (in_array(getOption('img_location'), $component->types)) $str_location = $component->long_name;													
													} else {
														if (empty($db_location)) { 
															if (in_array(getOption('img_location'), $component->types)) $str_location = $component->long_name;
														}													
													}
													
													if (getOption('rescan')) {
														if (in_array(getOption('img_city'), $component->types)) $str_city = $component->long_name;										
													} else {
														if (empty($db_city)) { 
															if (in_array(getOption('img_city'), $component->types)) $str_city = $component->long_name;
														}													
													}

													if (getOption('rescan')) {
														if (in_array(getOption('img_state'), $component->types)) $str_state = $component->long_name;													
													} else {
														if (empty($db_state)) { 	
															if (in_array(getOption('img_state'), $component->types)) $str_state = $component->long_name;
														}													
													}													

													if (getOption('rescan')) {
														if (in_array(getOption('img_country'), $component->types)) $str_country = $component->long_name;												
													} else {
														if (empty($db_country)) {
															if (in_array(getOption('img_country'), $component->types)) $str_country = $component->long_name;
														}												
													}													

												}
											}
											
											if (!empty($str_location)) 	$image->setLocation($str_location);
											if (!empty($str_city)) 		$image->setCity($str_city);
											if (!empty($str_state)) 	$image->setState($str_state);
											if (!empty($str_country)) 	$image->setCountry($str_country);
											
											$image->save();	
								}
							}			
			
			}

		}

		return $image;
	}

?>