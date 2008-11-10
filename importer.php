<?php 
/*
Plugin Name: Scriblio Catalog Importer
Plugin URI: http://about.scriblio.net/
Description: Imports MaRC records into Scriblio, provides functions used by other importers.
Version: .01
Author: Casey Bisson
Author URI: http://maisonbisson.com/blog/
*/
/* Copyright 2007 Casey Bisson & Plymouth State University

	This program is free software; you can redistribute it and/or modify 
	it under the terms of the GNU General Public License as published by 
	the Free Software Foundation; either version 2 of the License, or 
	(at your option) any later version. 

	This program is distributed in the hope that it will be useful, 
	but WITHOUT ANY WARRANTY; without even the implied warranty of 
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the 
	GNU General Public License for more details. 

	You should have received a copy of the GNU General Public License 
	along with this program; if not, write to the Free Software 
	Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110-1301 USA 
*/ 

/*

   Revised by K.T. Lam (lblkt@ust.hk), Head of Library Systems, The Hong Kong University of Science and Technology Library
   Purpose: to enhance Scriblio's CJK support and to make it works with HKUST's INNOPPAC.
   Date: 13 November 2007; 17 December 2007; 6 January 2008; 13 May 2008

*/

// The importer 
class Scrib_import { 
	var $importer_code = 'scribimporter'; 
	var $importer_name = 'Scriblio Catalog Importer'; 
	var $importer_desc = 'Imports MaRC records into Scriblio, provides functions used by other importers. <a href="http://about.scriblio.net/wiki">Documentation here</a>.'; 
	 
	// Function that will handle the wizard-like behaviour 
	function dispatch() { 
		if (empty ($_GET['step'])) 
			$step = 0; 
		else 
			$step = (int) $_GET['step']; 

		// load the header
		$this->header();

		switch ($step) { 
			case 0 :
				$this->greet();
				break;
			case 1 : 
				check_admin_referer('import-upload');
				$this->marc_accept_file(); 
				break;
			case 2:
				$this->marc_parse_file(); 
				break; 
			case 3:
				$this->harvest_import(); 
				break; 
			case 4:
				$this->ktnxbye(); 
				break; 
		} 

		// load the footer
		$this->footer();
	} 

	function header() {
		echo '<div class="wrap">';
		echo '<h2>'.__('Scriblio Catalog Importer').'</h2>';
	}

	function footer() {
		echo '</div>';
	}

	function greet() {
		$this->activate();
		echo '<div class="narrow">';
		echo '<p>'.__('Howdy! Start here to import MaRC records into Scriblio.').'</p>';
		echo '<p>'.__('This has not been tested much. Mileage may vary.').'</p>';

		echo '<br /><br />';	
		wp_import_upload_form("admin.php?import=$this->importer_code&amp;step=1");

		echo '<br /><br />';	
		echo '<form action="admin.php?import='. $this->importer_code .'&amp;step=3" method="post">';
		echo '<p class="submit">or jump immediately to <input type="submit" name="next" value="'.__('Publish Harvested Records &raquo;').'" /></p>';
		echo '</form>';
		echo '</div>';
	}

	function ktnxbye() {
		echo '<div class="narrow">';
		echo '<p>'.__('All done.').'</p>';
		echo '</div>';
	}

	function marc_accept_file(){
		$prefs = get_option('scrib_marcimporter');
		$prefs['scrib_marc-warnings'] = array();
		$prefs['scrib_marc-errors'] = array();
		$prefs['scrib_marc-record_start'] = 0;
		$prefs['scrib_marc-records_harvested'] = 0;
		update_option('scrib_marcimporter', $prefs);

		$this->marc_options();
	}

	function marc_options(){
		global $file;
	
		if(empty($this->id)){
			$file = wp_import_handle_upload();
			if ( isset($file['error']) ) {
				echo '<p>'.__('Sorry, there has been an error.').'</p>';
				echo '<p><strong>' . $file['error'] . '</strong></p>';
				return;
			}
			$this->file = $file['file'];
			$this->id = (int) $file['id'];
		}
	
		$prefs = get_option('scrib_marcimporter');

		echo '<div class="narrow">';
		echo '<p>'.__('MaRC file options.').'</p>';

		echo '<p>'.__('All Scriblio records have a &#039;sourceid,&#039; a unique alphanumeric string that&#039;s used to avoid creating duplicate records and, in some installations, link back to the source system for current availability information.').'</p>';
		echo '<p>'.__('The sourceid is made up of two parts: the prefix that you assign, and a ID info from the source record. Many systems assign unique numbers to each record, the challenge is figuring out which field to use.').'</p>';

		echo '<form name="myform" id="myform" action="admin.php?import='. $this->importer_code .'&amp;id='. $this->id .'&amp;step=2" method="post">';
?>
<p><label for="scrib_marc-sourceprefix">The source prefix:<br /><input type="text" name="scrib_marc-sourceprefix" id="scrib_marc-sourceprefix" value="<?php echo attribute_escape( $prefs['scrib_marc-sourceprefix'] ); ?>" /><br />example: bb (must be two characters, a-z and 0-9 accepted)</label></p>
<p><label for="scrib_marc-sourcefield">The source ID field:<br /><input type="text" name="scrib_marc-sourcefield" id="scrib_marc-sourcefield" value="<?php echo attribute_escape( $prefs['scrib_marc-sourcefield'] ); ?>" /><br />example: to use barcodes from Infocenter: [852][0]->subfields['p']<br /> or to use the the Project Gutenburg ID: ['000'][0]->data<br /> or to use the LCCN: ['001'][0]->data</label></p>
<p><br /><br /><label for="scrib_marc-record_start">Start with record number:<br /><input type="text" name="scrib_marc-record_start" id="scrib_marc-record_start" value="<?php echo attribute_escape( $prefs['scrib_marc-record_start'] ); ?>" /></label></p>
<p><label for="scrib_marc-debug"><input type="checkbox" name="scrib_marc-debug" id="scrib_marc-debug" value="1" /> Turn on debug mode.</label></p>
<?php
		echo '<p class="submit"><input type="submit" name="next" value="'.__('Next &raquo;').'" /></p>';
		echo '</form>';
		echo '</div>';
	}

	function marc_parse_file(){
		$interval = 2500;
		if( empty( $_REQUEST[ 'scrib_marc-record_start' ] ))
			$n = 0;
		else
			$n = (int) $_REQUEST[ 'scrib_marc-record_start' ];

		ini_set('memory_limit', '1024M');
		set_time_limit(0);
		ignore_user_abort(TRUE);

		$this->id = (int) $_GET['id'];
		$this->file = get_attached_file($this->id);

		if(empty($_POST['scrib_marc-sourceprefix']) || empty($_POST['scrib_marc-sourcefield']) || empty($this->file)){
			echo '<p>'.__('Sorry, there has been an error.').'</p>';
			echo '<p><strong>Please complete all fields</strong></p>';
			return;
		}

		// save these settings so we can try them again later
		$prefs = get_option('scrib_marcimporter');
		$prefs['scrib_marc-sourceprefix'] = stripslashes($_POST['scrib_marc-sourceprefix']);
		$prefs['scrib_marc-sourcefield'] = stripslashes($_POST['scrib_marc-sourcefield']);
		update_option('scrib_marcimporter', $prefs);

		error_reporting(E_ERROR);

		// initialize the marc library
		require_once(ABSPATH . PLUGINDIR .'/'. plugin_basename(dirname(__FILE__)) .'/includes/php-marc.php');
		$file = new File($this->file);

		$prefs['scrib_marc-records_count'] = count($file->raw);
		update_option('scrib_marcimporter', $prefs);

		if($n > 0 || count($file->raw) > $interval)
			$file->raw = array_slice($file->raw, $n, $interval);

		if(!empty($_POST['scrib_marc-debug'])){
		
			$record = $file->next();

			echo '<h3>The MaRC Record:</h3><pre>';			
			print_r($record->fields());
			echo '</pre><h3>The Tags and Display Record:</h3><pre>';
			$test_pancake = $this->marc_parse_record($record->fields());
			print_r($test_pancake);
			echo '</pre>';
			
			echo '<h3>The Raw Excerpt:</h3>'. $test_pancake['the_excerpt'] .'<br /><br />';
			echo '<h3>The Raw Content:</h3>'. $test_pancake['the_content'] .'<br /><br />';
			echo '<h3>The SourceID: '. $test_pancake['the_sourceid'] .'</h3>';
			
			// bring back that form
			echo '<h2>'.__('File Options').'</h2>';
			echo '<p>File has '. $prefs['scrib_marc-records_count'] .' records.</p>';
			$this->marc_options();
		
		}else{
			// import with status
			$count = 0;
			echo "<p>Reading the file and parsing ". $file->num_records() ." records. Please be patient.<br /><br /></p>";
			echo '<ol>';
			while($file->pointer < count($file->raw)){
				if($record = $file->next()){
					$bibr = &$this->marc_parse_record($record->fields());
					echo "<li>{$bibr['the_title']} {$bibr['the_sourceid']}</li>";
					$count++;
				}
			}
			echo '</ol>';
			
			$prefs['scrib_marc-warnings'] = array_merge($prefs['scrib_marc-warnings'], $file->warn);
			$prefs['scrib_marc-errors'] = array_merge($prefs['scrib_marc-errors'], $file->error);
			$prefs['scrib_marc-records_harvested'] = $prefs['scrib_marc-records_harvested'] + $count;
			update_option('scrib_marcimporter', $prefs);

			if(count($file->raw) >= $interval){
				$prefs['scrib_marc-record_start'] = $n + $interval;
				update_option('scrib_marcimporter', $prefs);

				$this->marc_options();
				?>
				<div class="narrow"><p><?php _e("If your browser doesn't start loading the next page automatically click this link:"); ?> <a href="javascript:nextpage()"><?php _e("Next Records"); ?></a> </p>
				<script language='javascript'>
				<!--
	
				function nextpage() {
					document.getElementById('myform').submit();
				}
				setTimeout( "nextpage()", 1250 );
	
				//-->
				</script>
				</div>
				<?php
			}else{
				$this->marc_done();
				?>
				<script language='javascript'>
				<!--
					window.location='#complete';
				//-->
				</script>
				</div>
				<?php
			}
		}
	}
	
	function marc_parse_record($marcrecord){
		// an anonymous callback function to trim arrays
		$trimmer = create_function('&$x', 'return ( trim ( trim ( trim ( $x ) , ",.;:/" ) ) );');

		foreach($marcrecord as $fields){
			foreach($fields as $field){

				// Authors
				if(($field->tagno == 100) || ($field->tagno == 110)){
					$temp = ereg_replace(',$', '', $field->subfields['a'] .' '. $field->subfields['d']);
					$atomic['author'][] = $temp;
				}else if($field->tagno == 110){
					$temp = $field->subfields['a'];
					$atomic['author'][] = $temp;

				}else if(($field->tagno > 699) && ($field->tagno < 721)){
					$temp = ereg_replace(',$', '', $field->subfields['a'] .' '. $field->subfields['d']);
					$atomic['author'][] = $temp;

				
				//Standard Numbers
				}else if($field->tagno == 10){
					$temp = explode(' ', trim($field->subfields['a']));
					$atomic['lccn'][] = ereg_replace('[^0-9]', '', $temp[0]);
				}else if($field->tagno == 20){
					$temp = trim($field->subfields['a']) . ' ';
					$temp = ereg_replace('[^0-9|x|X]', '', strtolower(substr($temp, 0, strpos($temp, ' '))));
					$atomic['isbn'][] = $temp;
				}else if($field->tagno == 22){
					$temp = trim($field->subfields['a']) . ' ';
					$temp = ereg_replace('[^0-9|x|X|\-]', '', strtolower(substr($temp, 0, strpos($temp, ' '))));
					$atomic['issn'][] = $temp;
				
				//Call Numbers
				}else if($field->tagno == 852){ // callnums from InfoCenter
					$temp = trim($field->subfields['h']);
					$atomic['callnumber'][] = $temp;

					$temp = trim($field->subfields['b']);
					$atomic['location'][] = $temp;

					$atomic['acqdate'][] = $field->subfields[x]{14}.$field->subfields[x]{15}.$field->subfields[x]{16}.$field->subfields[x]{17} .'-'. $field->subfields[x]{18}.$field->subfields[x]{19} .'-'. $field->subfields[x]{20}.$field->subfields[x]{21};

				//Titles
				}else if($field->tagno == 245){
					$temp = ucwords(trim(trim(trim(ereg_replace('/$', '', $field->subfields['a']) .' '. trim(ereg_replace('/$', '', $field->subfields['b']))), ',.;:/')));
					$atomic['title'][] = $temp;
					$atomic['attribution'][] = trim(trim(trim($field->subfields['c']), ',.;:/'));
				}else if($field->tagno == 240){
					$temp = ucwords(trim(trim(trim(ereg_replace('/$', '', $field->subfields['a']) .' '. trim(ereg_replace('/$', '', $field->subfields['b']))), ',.;:/')));
					$atomic['alttitle'][] = $temp;
				}else if(($field->tagno > 719) && ($field->tagno < 741)){
					$temp = $field->subfields['a'];
					$atomic['alttitle'][] = $field->subfields['a'];
				
				//Dates
				}else if($field->tagno == 260){
					$temp = str_pad(substr(ereg_replace('[^0-9]', '', $field->subfields['c']), 0, 4), 4 , '5');
					$atomic['pubyear'][] = $temp;
				}else if($field->tagno == 005){
					$atomic['catdate'][] = $field->data{0}.$field->data{1}.$field->data{2}.$field->data{3} .'-'. $field->data{4}.$field->data{5} .'-'. $field->data{6}.$field->data{7};
				}else if($field->tagno == 008){
					$atomic['pubyear'][] = substr($field->data, 14, 4);
				
				//Subjects
				}else if(($field->tagno > 599) && ($field->tagno < 700)){
					$atomic['subject'][] = trim(trim(implode(' -- ', $field->subfields)), '.');
					
					if($atomic['subjkey']){
						$atomic['subjkey'] = array_unique(array_merge($atomic['subjkey'], array_map($trimmer, array_values($field->subfields))));
					}else{
						$atomic['subjkey'] = array_map($trimmer, array_values($field->subfields));
					}	
			
				//URLs
				}else if($field->tagno == 856){
					unset($temp);
					$temp['href'] = $temp['title'] = str_replace(' ', '', $field->subfields['u']);
					$temp['title'] = trim(parse_url( $temp['href'] , PHP_URL_HOST), 'www.');
					if($field->subfields['3'])
						$temp['title'] = $field->subfields['3'];
					if($field->subfields['z'])
						$temp['title'] = $field->subfields['z'];
					$atomic['url'][] = '<a href="'. $temp['href'] .'">'. $temp['title'] .'</a>';
			
				//Notes
				}else if(($field->tagno > 299) && ($field->tagno < 400)){
					$atomic['physdesc'][] = implode(' ', array_values($field->subfields));
				}else if(($field->tagno > 399) && ($field->tagno < 500)){
					$atomic['title'][] = implode("\n", array_values($field->subfields));
				}else if(($field->tagno > 799) && ($field->tagno < 841)){
					$atomic['series'][] = implode("\n", array_values($field->subfields));
				}else if(($field->tagno > 499) && ($field->tagno < 600)){
					$line = implode("\n", array_values($field->subfields));
					if($field->tagno == 504)
						continue;
					if($field->tagno == 505){
						$atomic['contents'][] = str_replace(array('> ','> ','> '), '>', '<li>'. str_replace('--', "</li>\n<li>", trim(str_replace(array(' ', ' ', ' '), ' ', $line))) .'</li>');
						continue;
					}
					$atomic['notes'][] = str_replace(' ', ' ', $line);
				}
				
				//Format
				if((!$atomic['format']) && ($field->tagno > 239) && ($field->tagno < 246)){
					$temp = ucwords(strtolower(str_replace('[', '', str_replace(']', '', $field->subfields['h']))));
					
					if(eregi('^book', $temp)){
						$format = 'Book';
						$formats = 'Books';
	
					}else if(eregi('^micr', $temp)){
						$format = 'Microform';
	
					}else if(eregi('^electr', $temp)){
						$format = 'Website';
						$formats = 'Websites';
	
					}else if(eregi('^vid', $temp)){
						$format = 'Video';
					}else if(eregi('^motion', $temp)){
						$format = 'Video';
	
					}else if(eregi('^audi', $temp)){
						$format = 'Audio';
					}else if(eregi('^cass', $temp)){
						$format = 'Audio';
					}else if(eregi('^phono', $temp)){
						$format = 'Audio';
					}else if(eregi('^record', $temp)){
						$format = 'Audio';
					}else if(eregi('^sound', $temp)){
						$format = 'Audio';
	
					}else if(eregi('^carto', $temp)){
						$format = 'Map';
						$formats = 'Maps';
					}else if(eregi('^map', $temp)){
						$format = 'Map';
						$formats = 'Maps';
					}else if(eregi('^globe', $temp)){
						$format = 'Map';
						$formats = 'Maps';
	
					}else if($temp){
						$format = 'Classroom Material';
						//$format = $temp;
					}
	
					if(!$formats)
						$formats = $format;
					
					if($format){
						$atomic['format'][] = $format;
						$atomic['formats'][] = $formats;
					}
				}
			}
		}

		if(!$atomic['format'][0]){
			$atomic['format'][0] = 'Book';
		}

		if(!$atomic['acqdate'])
			$atomic['acqdate'] = $atomic['catdate'];

		if(!$atomic['catdate'][0])
			$atomic['catdate'][0] = '1984-01-01';

		if($atomic['pubyear'][0] > (date(Y) + 5))
			$atomic['pubyear'][0] = substr($atomic['catdate'][0],0,4);

		if($atomic['pubyear'][0]){
			$atomic['pubdate'][] = $atomic['pubyear'][0].substr($atomic['catdate'][0],4);
		}
		
		if($atomic['alttitle'])
			$atomic['title'] = array_unique(array_merge($atomic['title'], $atomic['alttitle']));

		$atomic['the_sourceid'] = substr(ereg_replace('[^a-z|0-9]', '', strtolower($_POST['scrib_marc-sourceprefix'])), 0, 2) . trim(eval('return($marcrecord'. str_replace(array('(',')','$'), '', stripslashes($_POST['scrib_marc-sourcefield'])) .');'));

		if(!empty($atomic['title']) && !empty($atomic['the_sourceid'])){

			$atomic['tags']['subj'] = $atomic['subjkey'];
			$atomic['tags']['auth'] = $atomic['author'];
			$atomic['tags']['isbn'] = $atomic['isbn'];
			$atomic['tags']['title'] = $atomic['title'];
			$atomic['tags']['format'] = $atomic['formats'];
			$atomic['tags']['pubyear'] = $atomic['pubyear'];

			if($sweets = $this->get_sweets($atomic['isbn'])){
				if(!empty($sweets['img']));
					$atomic['img'] = $sweets['img'];
				if(!empty($sweets['summary'])){
					$atomic['shortdescription'] = html_entity_decode($sweets['summary']);
				}
			}
			$atomic['the_title'] = $atomic['title'][0];
			$atomic['the_pubdate'] = $atomic['pubdate'][0];
			$atomic['the_acqdate'] = $atomic['acqdate'][0];
	
			$atomic['the_excerpt'] = $this->the_excerpt($atomic);
			$atomic['the_content'] = $this->the_content($atomic);

			$this->insert_harvest($atomic);
			return($atomic);
		}else{
			return(FALSE);
		}
	}

	function marc_done(){
		$prefs = get_option('scrib_marcimporter');

		// click next
		echo '<div class="narrow">';

		if(count($prefs['scrib_marc-warnings'])){
			echo '<h3 id="warnings">Warnings</h3>';
			echo '<a href="#complete">bottom</a> &middot; <a href="#errors">errors</a>';
			echo '<ol><li>';
			echo implode($prefs['scrib_marc-warnings'], '</li><li>');
			echo '</li></ol>';
		}

		if(count($prefs['scrib_marc-errors'])){
			echo '<h3 id="errors">Errors</h3>';
			echo '<a href="#complete">bottom</a> &middot; <a href="#warnings">warnings</a>';
			echo '<ol><li>';
			echo implode($prefs['scrib_marc-errors'], '</li><li>');
			echo '</li></ol>';
		}		

		echo '<h3 id="complete">'.__('Processing complete.').'</h3>';
		echo '<p>'. $prefs['scrib_marc-records_harvested'] .' of '. $prefs['scrib_marc-records_count'] .' '.__('records harvested.').' with '. count($prefs['scrib_marc-warnings']) .' <a href="#warnings">warnings</a> and '. count($prefs['scrib_marc-errors']) .' <a href="#errors">errors</a>.</p>';

		echo '<p>'.__('Continue to the next step to publish those harvested catalog entries.').'</p>';

		echo '<form action="admin.php?import='. $this->importer_code .'&amp;step=3" method="post">';
		echo '<p class="submit"><input type="submit" name="next" value="'.__('Publish Harvested Records &raquo;').'" /></p>';
		echo '</form>';

		echo '</div>';
	}































	
	function update_record( $sourceid ) { 
		global $wpdb, $bsuite; 

		if( !$bsuite->get_lock( 'scrib_passvupd' ))
			return( FALSE );

//		if ( wp_cache_get( $sourceid, 'scrib_passvupd' ) > time())
//			return( FALSE );

		$post = $wpdb->get_row('SELECT * FROM '. $this->harvest_table .' WHERE source_id = "'. $wpdb->escape( $sourceid ) .'" AND imported = 0 LIMIT 1');

		if( isset( $post->source_id )) {
			$post_id = $this->insert_post( unserialize( $post->content ));

			if( $post_id )
				$wpdb->get_var('UPDATE '. $this->harvest_table .' SET imported = 1 WHERE source_id = "'. $post->source_id .'"');
			else
				$wpdb->get_var('UPDATE '. $this->harvest_table .' SET imported = -1 WHERE source_id = "'. $post->source_id .'"');
		}
//		wp_cache_add( $sourceid, time() + 86400, 'scrib_passvupd', 86400 );
	} 

	function harvest_import() { 
		global $wpdb; 
	
		$interval = 25;

		if( isset( $_GET[ 'n' ] ) == false ) {
			$n = 0;
		} else {
			$n = intval( $_GET[ 'n' ] );
		}
	
		$posts = $wpdb->get_results('SELECT * FROM '. $this->harvest_table .' WHERE imported = 0 LIMIT 0,'. $interval, ARRAY_A);

		if( is_array( $posts ) ) {
			$count = $wpdb->get_var('SELECT COUNT(*) FROM '. $this->harvest_table .' WHERE imported = 0');

			echo "<p>Fetching records in batches of $interval...importing them...making coffee. Please be patient.<br /><br /></p>";
			echo '<ol>';
			foreach( $posts as $post ) {
				set_time_limit( 900 );

				$post_id = $this->insert_post(unserialize($post['content']));

				if($post_id){
					$wpdb->get_var('UPDATE '. $this->harvest_table .' SET imported = 1 WHERE source_id = "'. $post['source_id'] .'"');

					echo '<li><a href="'. get_permalink($post_id) .'" target="_blank">'. get_the_title($post_id) .'</a></li>';
					flush();
				}else{
					$wpdb->get_var('UPDATE '. $this->harvest_table .' SET imported = -1 WHERE source_id = "'. $post['source_id'] .'"');
				}
			}

			echo '</ol>';
			?>
			<p><?php _e("If your browser doesn't start loading the next page automatically click this link:"); ?> <a href="<?php echo get_option('siteurl'); ?>/wp-admin/admin.php?import=<?php echo $this->importer_code; ?>&step=3&n=<?php echo ($n + $interval) ?>"><?php _e("Next Posts"); ?></a> </p>
			<script language='javascript'>
			<!--

			function nextpage() {
				location.href="<?php echo get_option('siteurl'); ?>/wp-admin/admin.php?import=<?php echo $this->importer_code; ?>&step=3&n=<?php echo ($n + $interval) ?>";

			}
			setTimeout( "nextpage()", 250 );

			//-->
			</script>
			<?php
			echo '<p>'. ($count - $interval) .' records remain to be imported.</p>';
		} else {
			echo '<p>That&#039;s all folks. kthnxbye.</p>';
		}

		echo '<pre>';
		print_r( $wpdb->queries );
		echo '</pre>';
		?><?php echo get_num_queries(); ?> queries. <?php timer_stop(1); ?> seconds. <?php
	} 

	function insert_harvest($bibr) {
		global $wpdb;

		$wpdb->get_results("REPLACE INTO $this->harvest_table
			(source_id, harvest_date, imported, content) 
			VALUES ('". $wpdb->escape($bibr['the_sourceid']) ."', NOW(), 0, '". $wpdb->escape(serialize($bibr)) ."')");
	}

	function post_exists($sourceid) {
		if($post_id = get_objects_in_term( is_term($sourceid), 'sourceid' ))
			return($post_id[0]);
//		return(FALSE);
	}

	function get_the_content( $bibr ){
		if( function_exists( 'scribimport_content_'.$source ))
			return( call_user_func( 'scribimport_content_'.$source, $bibr ));
		else if( $bibr['cformatter'] && function_exists( $bibr['cformatter'] ))
			return( call_user_func( $bibr['cformatter'], $bibr ));
		else
			return( $this->the_content( $bibr ));
	}
	
	function get_the_excerpt( $bibr ){
		if( function_exists( 'scribimport_excerpt_'.$source ))
			return( call_user_func( 'scribimport_excerpt_'.$source, $bibr ));
		else if( $bibr['eformatter'] && function_exists( $bibr['eformatter'] ))
			return( call_user_func( $bibr['eformatter'], $bibr ));
		else
			return( $this->the_excerpt( $bibr ));
	}

	function insert_post( $bibr ){
//		return(1);
		global $wpdb, $bsuite, $scrib;

		wp_defer_term_counting( TRUE ); // may improve performance
		remove_filter('content_save_pre', array(&$bsuite, 'innerindex_nametags')); // don't build an inner index for catalog records
		remove_filter('publish_post', '_publish_post_hook', 5, 1); // avoids pinging links in catalog records
		remove_filter('save_post', '_save_post_hook', 5, 2); // don't bother
		kses_remove_filters(); // don't kses filter catalog records
		define( 'WP_IMPORTING', TRUE ); // may improve performance by preventing exection of some unknown hooks

		$source = substr( $bibr['the_sourceid'], 0, 2 );

		if($this->post_exists($bibr['the_sourceid']))
			$postdata['ID']			= $this->post_exists($bibr['the_sourceid']);
	
		$postdata['post_title'] = $wpdb->escape(str_replace('\"', '"', $bibr['the_title']));
//Start HKUST Customization
//		$postdata['post_date'] = $bibr['the_pubdate'];
		$postdata['post_date'] = $bibr['the_acqdate'];
//End HKUST Customization
		$postdata['post_date_gmt']	= $bibr['the_acqdate'];
		$postdata['comment_status'] = get_option('default_comment_status');
		$postdata['ping_status'] 	= get_option('default_pingback_flag');
		$postdata['post_status'] 	= 'publish';
		$postdata['post_type'] 		= 'post';
		$postdata['post_author'] 	= $scrib->options['catalog_author_id'];

		$postdata['post_content'] = $wpdb->escape( $this->get_the_content( $bibr ));
		$postdata['post_excerpt'] = $wpdb->escape( $this->get_the_excerpt( $bibr ));

		if( empty( $postdata['post_title'] ))
			return( FALSE );

		$post_id = wp_insert_post($postdata); // insert the post
		if($post_id){
//			$bsuite->searchsmart_upindex($post_id, $bibr['the_content'], $bibr['the_title']); // update the full text index // disabled because bSuite 4 now builds the search index in the background

			wp_set_object_terms($post_id, $bibr['the_sourceid'], 'sourceid', TRUE);

			if( empty( $bibr['the_category'] ))
				$bibr['the_category'] = (int) $scrib->options['catalog_category_id'];
			wp_set_object_terms($post_id, $bibr['the_category'], 'category', FALSE);

			add_post_meta( $post_id, 'scrib_the_author', $bibr['attribution'][0], TRUE ) or update_post_meta( $post_id, 'scrib_the_author', $bibr['attribution'][0] );
			add_post_meta( $post_id, 'scrib_the_author_link', implode( '|', $bibr['author'] ), TRUE ) or update_post_meta( $post_id, 'scrib_the_author_link', implode( '|', $bibr['author'] ) );
			
			if( is_array( $bibr['bibkeys'] ))
				foreach( $bibr['bibkeys'] as $temp )
					$real_bibkeys[] = key( $temp ) .':'. current( $temp );

			add_post_meta( $post_id, 'scrib_the_bibkeys', serialize( $real_bibkeys ), TRUE ) or update_post_meta( $post_id, 'scrib_the_bibkeys', serialize( $real_bibkeys ));


			//insert the tags
			if(is_array($bibr['tags'])){
				$bibr['tags'] = array_filter($bibr['tags']);

				foreach(array_keys($bibr['tags']) as $taxonomy){
					$bibr['tags'][$taxonomy] = array_filter($bibr['tags'][$taxonomy]);
					register_taxonomy( $taxonomy, 'post' );
					wp_set_object_terms($post_id, $bibr['tags'][$taxonomy], $taxonomy, FALSE);
				}
			}
			return($post_id);
		}
		return(FALSE);
	}
	
	function get_altisbn($isbn) {
		$result = array();

		// OCLC's xISBN
		// http://www.oclc.org/research/projects/xisbn/
		if($xml = file_get_contents('http://labs.oclc.org/xisbn/' . $isbn)){
			foreach ($xml->xpath('/idlist/isbn') as $temp)
				$isbn[] = (string) $temp;
		}
		// the first element of the array is always the same as the query ISBN, delete it
		array_shift($result);
/*	
		Also note LibraryThing's thingISBN
		http://www.librarything.com/thingology/2006/06/introducing-thingisbn_14.php
		'http://www.librarything.com/api/thingISBN/' . $isbn;
*/

		return($result);
	}

	function get_cachejacket( $url, $filename, $dl = TRUE ){
		$uploads = wp_upload_dir( 'jckt-'. substr( $filename, 4, 2 ));

		if( $uploads['path'] ){
			if(!is_dir( $uploads['path'] ))
				mkdir( $uploads['path'], 0775, TRUE );
	
			if( $dl ) 
				exec( 'curl -o '. escapeshellarg( $uploads['path']  .'/'. $filename ) .' '. escapeshellarg( $url ));
	
			return( $uploads['url'] .'/'. $filename );
		}
		return( FALSE );
	}

	function get_sweets( $bibkeys, $the_title = '', $the_author, $the_sourceid ){
		global $bsuite;
		$bsuite->timer_start( 'scrib_import_get_sweets' );

		$prefs = get_option('scrib_marcimporter');

		foreach( $bibkeys as $bibn )
			$bibkey[] = '{"'. key( $bibn ) .'":"'. current( $bibn ) .'"}';

		$url = 'http://api.scriblio.net/v01b/enrich/?output=php&query='. urlencode( '{"bibkeys":['.  implode( $bibkey, ',' ) .'],"title":"'. str_replace( '"', '\"', $the_title ) .'","author":"'. str_replace( '"', '\"', $the_author ) .'"}' );

//echo '<h4>'. $url .'</h4>';
		$session = curl_init( $url );

//		curl_setopt($session, CURLOPT_HEADER, TRUE);
		curl_setopt($session, CURLOPT_RETURNTRANSFER, TRUE);
	
		// there's an ugly hack/work around below. For some reason the API server is returning crunk at the head of the serialized result. I'm removing it with substring, but i should fix it at the source.
		$content = unserialize( substr( curl_exec( $session ), 6 ));
		curl_close( $session );

//print_r( $content );

		$this->queries[] = array( $url, $bsuite->timer_stop( 'scrib_import_get_sweets' ), 'get_sweets' );

		if( $content->status == 'ok' ){
			if( !isset( $content->image->thumb->url )){
				$content->fakejacket->tiny = $content->image->tiny;
				$content->image = $content->fakejacket;
				$content->image->thumb->url = $this->get_cachejacket( $content->image->thumb->url , $the_sourceid .'_t.jpg' );
				$content->image->large = $content->image->thumb;
			
			}else if( $prefs['scrib_marc-cachejacket'] ){
				$content->image->thumb->url = $this->get_cachejacket( $content->image->thumb->url , $the_sourceid .'_t.jpg' );
				$content->image->large->url = $this->get_cachejacket( $content->image->large->url , $the_sourceid .'_l.jpg' );
			
			}
			return( $content );
		}
		return( FALSE );
	}

	function get_summarized($text){
		// api: http://api.scriblio.net/v01a/summarize/?text=...
		
		// The POST URL and parameters
		$request = 'http://api.scriblio.net/v01a/summarize/';
		$postargs = 'text='.urlencode($text);
		
		// Get the curl session object
		$session = curl_init($request);
		
		// Set the POST options.
		curl_setopt ($session, CURLOPT_POST, true);
		curl_setopt ($session, CURLOPT_POSTFIELDS, $postargs);
		curl_setopt($session, CURLOPT_HEADER, FALSE);
		curl_setopt($session, CURLOPT_RETURNTRANSFER, true);
		
		// Do the POST and then close the session
		$response = curl_exec($session);
		curl_close($session);
		
		if(!empty($response))
			return($response);
		else
			return(FALSE);
	}



	function the_excerpt($bibr){
		global $scrib;

		$result = '<ul class="summaryrecord">';

		if($bibr['img']->thumb->url ){
			$result .= '<li class="image">[scrib_bookjacket]<img class="bookjacket" src="'. $bibr['img']->thumb->url .'" width="100" height="'. round( $bibr['img']->thumb->height * ( 100 / $bibr['img']->thumb->width )) .'" alt="'. str_replace(array('[',']'), array('{','}'), htmlentities( array_shift( $bibr['title'] ), ENT_QUOTES, 'UTF-8' )) .'" />[/scrib_bookjacket]</li>';
		}else{
			$result .= '<li class="image">[scrib_bookjacket]<img class="bookjacket" src="' . get_settings('siteurl') .'/'. $scrib->path_web .'/img/jacket/blank_'. urlencode(strtolower($bibr['format'][0])) .'.png" width="100" height="135" alt="'. str_replace(array('[',']'), array('{','}'), htmlentities( array_shift( $bibr['title'] ), ENT_QUOTES, 'UTF-8' )) .'" />[/scrib_bookjacket]</li>';
		}

		$result .= '<li class="attribution"><h3>Attribution</h3>'. array_shift( $bibr['attribution'] ) .'</li>';

		$pubdeets = array();
		if( count( $bibr['format'] ))
			$pubdeets[] = '<span class="format">'. array_shift( $bibr['format'] ) .'</span>';

		if( count( $bibr['edition'] ))
			$pubdeets[] = '<span class="edition">'. array_shift( $bibr['edition'] ) .'</span>';

		if( count( $bibr['publisher'] ))
			$pubdeets[] = '<span class="pubyear">'. array_shift( $bibr['publisher'] ) .'</span>';

		if( count( $bibr['pubyear'] ))
			$pubdeets[] = '<span class="pubyear">'. array_shift( $bibr['pubyear'] ) .'</span>';

		if( count( $pubdeets ))
			$result .= '<li class="publication_details"><h3>Publication Details</h3>'. implode( '<span class="meta-sep">, </span>', $pubdeets ) .'</li>';

		if(count($bibr['url']) > 0){
			$result .= '<li class="links">';
			$result .= '<h3>Links</h3>';
	
			$result .= '<ul>';
			foreach($bibr['url'] as $temp){
				$result .= '<li>' . $temp . '</li>';
			}
			$result .= '</ul>';
			$result .= '</li>';
		}

		if($bibr['shortdescription'])
			$result .= '<li class="reviews"><h3>Description</h3>'. $bibr['shortdescription'] .'</li>';

		// build a block of tags
		$tags = NULL;

		if($bibr['subjkey']){
			foreach($bibr['subjkey'] as $temp){
				$tags[] = '<a href="[scrib_taglink taxonomy="subj" value="'. $temp .'"]" rel="tag">' . $temp . '</a>';
			}
		}

		if($bibr['author']){
			foreach($bibr['author'] as $temp){
				$tags[] = '<a href="[scrib_taglink taxonomy="auth" value="'. $temp .'"]" rel="tag">' . $temp . '</a>';
			}
		}

		if($tags){
			$result .= '<li class="tags"><h3>Tags</h3> '. implode(' &middot; ', $tags) .'</li>';
		}
		// end tag block

		$result .= '<li class="availability"><h3>Availability</h3>[scrib_availability sourceid="'. $bibr['the_sourceid'] .'"]</li>';

		$result .='</ul>';
		
		return($result);
	}		

	function the_content($bibr){
		global $scrib;

		$result = '<ul class="fullrecord">';

		if($bibr['img']->large->url ){
			$result .= '<li class="image">[scrib_bookjacket]<img class="bookjacket" src="'. $bibr['img']->large->url .'" width="'. $bibr['img']->large->width .'" height="'. $bibr['img']->large->height .'" alt="'. str_replace(array('[',']'), array('{','}'), htmlentities( array_shift( $bibr['title'] ), ENT_QUOTES, 'UTF-8' )) .'" />[/scrib_bookjacket]</li>';
		}else{
			$result .= '<li class="image">[scrib_bookjacket]<img class="bookjacket" src="' . get_settings('siteurl') .'/'. $scrib->path_web .'/img/jacket/blank_'. urlencode(strtolower($bibr['format'][0])) .'.png" width="100" height="135" alt="'. str_replace(array('[',']'), array('{','}'), htmlentities( array_shift( $bibr['title'] ), ENT_QUOTES, 'UTF-8' )) .'" />[/scrib_bookjacket]</li>';
		}

		if($bibr['title']){
			$result .= '<li class="title">';
			if( 1 < count($bibr['title'] )){
				$result .= '<h3>Titles</h3>';
			}else{
				$result .= '<h3>Title</h3>';
			}
			
			$result .= '<ul>';
			foreach($bibr['title'] as $temp){
				$result .= '<li>' . $temp . '</li>';
			}
			$result .= '</ul>';
			$result .= '</li>';
		}


		$result .= '<li class="attribution"><h3>Attribution</h3>'. array_shift( $bibr['attribution'] ) .'</li>';

		$pubdeets = array();
		if( count( $bibr['format'] ))
			$pubdeets[] = '<span class="format">'. array_shift( $bibr['format'] ) .'</span>';

		if( count( $bibr['edition'] ))
			$pubdeets[] = '<span class="edition">'. array_shift( $bibr['edition'] ) .'</span>';

		if( count( $bibr['publisher'] ))
			$pubdeets[] = '<span class="pubyear">'. array_shift( $bibr['publisher'] ) .'</span>';

		if( count( $bibr['pubyear'] ))
			$pubdeets[] = '<span class="pubyear">'. array_shift( $bibr['pubyear'] ) .'</span>';

		if( count( $pubdeets ))
			$result .= '<li class="publication_details"><h3>Publication Details</h3>'. implode( '<span class="meta-sep">, </span>', $pubdeets ) .'</li>';

		$result .= '<li class="availability"><h3>Availability</h3>[scrib_availability sourceid="'. $bibr['the_sourceid'] .'"]</li>';

		if( count( $bibr['callnumber'] )){
			$result .= '<li class="callnumber">';
			$result .= '<h3>Call Number</h3>';
	
			$result .= '<ul>';
			foreach($bibr['callnumber'] as $temp)
				$result .= '<li>' . $temp . '</li>';

			$result .= '</ul>';
			$result .= '</li>';
		}

		if( count( $bibr['deweynumber'] )){
			$result .= '<li class="deweynumber">';
			$result .= '<h3>Dewey Number</h3>';
	
			$result .= '<ul>';
			foreach($bibr['deweynumber'] as $temp)
				$result .= '<li>' . $temp . '</li>';

			$result .= '</ul>';
			$result .= '</li>';
		}

		if( count( $bibr['lcclass'] )){
			$result .= '<li class="lcclass">';
			$result .= '<h3>LC Classification</h3>';
	
			$result .= '<ul>';
			foreach($bibr['lcclass'] as $temp)
				$result .= '<li>' . $temp . '</li>';

			$result .= '</ul>';
			$result .= '</li>';
		}

		if(count($bibr['url']) > 0){
			$result .= '<li>';
			$result .= '<h3>Links</h3>';
	
			$result .= '<ul class="links">';
			foreach($bibr['url'] as $temp){
				$result .= '<li>' . $temp . '</li>';
			}
			$result .= '</ul>';
			$result .= '</li>';
		}

		if( !empty( $bibr['description'] ))
			$result .= '<li class="description"><h3>Description</h3>'. $bibr['description'] .'</li>';
		else if( !empty( $bibr['shortdescription'] ))
			$result .= '<li class="description"><h3>Description</h3>'. $bibr['shortdescription'] .'</li>';

		if( count( $bibr['author'] )){
			$result .= '<li class="author">';
			if( 1< count( $bibr['author'] ))
				$result .= '<h3>Authors</h3>';
			else
				$result .= '<h3>Author</h3>';
			
			$result .= '<ul>';
			foreach($bibr['author'] as $temp)
				$result .= '<li><a href="[scrib_taglink taxonomy="auth" value="'. $temp .'"]" rel="tag">' . $temp . '</a></li>';
			$result .= '</ul>';
			$result .= '</li>';
		}

		if( count( $bibr['author_alt'] )){
			$result .= '<li class="author_additional">';
			if( 1< count( $bibr['author_alt'] ))
				$result .= '<h3>Aditional Authors</h3>';
			else
				$result .= '<h3>Aditional Author</h3>';
			
			$result .= '<ul>';
			foreach($bibr['author_alt'] as $temp)
				$result .= '<li><a href="[scrib_taglink taxonomy="auth_alt" value="'. $temp .'"]" rel="tag">' . $temp . '</a></li>';
			$result .= '</ul>';
			$result .= '</li>';
		}

	
		if( count( $bibr['genre'] )){
			$result .= '<li class="genre">';
			$result .= '<h3>Genre</h3>';
			
			$result .= '<ul>';
			foreach($bibr['genre'] as $temp)
				$result .= '<li><a href="[scrib_taglink taxonomy="genre" value="'. $temp .'"]" rel="tag">' . $temp . '</a></li>';
			$result .= '</ul>';
			$result .= '</li>';
		}

		if( count( $bibr['subject'] )){
			$result .= '<li class="subject">';
			$result .= '<h3>Subject</h3>';
	
			$result .= '<ul>';
			foreach( $bibr['subject'] as $temp )
				$result .= '<li><a href="[scrib_taglink taxonomy="subj" value="'. strtolower( str_replace(' -- ', '|', ( $temp ))) .'"]" rel="tag">' . $temp . '</a></li>';
			$result .= '</ul></li>';
		}

		if( count( $bibr['place'] )){
			$result .= '<li class="place">';
			$result .= '<h3>Places in this work</h3>';
			
			$result .= '<ul>';
			foreach($bibr['place'] as $temp)
				$result .= '<li><a href="[scrib_taglink taxonomy="place" value="'. $temp .'"]" rel="tag">' . $temp . '</a></li>';
			$result .= '</ul>';
			$result .= '</li>';
		}

		if( count( $bibr['time'] )){
			$result .= '<li class="time">';
			$result .= '<h3>Time periods in this work</h3>';
			
			$result .= '<ul>';
			foreach($bibr['time'] as $temp)
				$result .= '<li><a href="[scrib_taglink taxonomy="time" value="'. $temp .'"]" rel="tag">' . $temp . '</a></li>';
			$result .= '</ul>';
			$result .= '</li>';
		}

		
	
		if($bibr['notes']){
			$result .= '<li class="notes">';
			$result .= '<h3>Notes</h3>';
	
			$result .= '<ul>';
			foreach($bibr['notes'] as $temp)
				$result .= '<li>' . $temp . '</li>';
			$result .= '</ul></li>';
		}
	
	
		if($bibr['contents']){
			$result .= '<li class="contents">';
			$result .= '<h3>Contents</h3>';
	
			$result .= '<ul>'. $bibr['contents'][0] .'</ul></li>';
		}


		if($bibr['isbn']){
			$result .= '<li class="isbn"><h3>ISBN</h3>';
			$result .= '<ul>';
			foreach($bibr['isbn'] as $temp)
				$result .= '<li id="isbn-'. strtolower( $temp ) .'">'. strtolower( $temp ) . '</li>';
			$result .= '</ul>';
		
			$result .= '</li>';
		}

		if($bibr['issn']){
			$result .= '<li class="issn"><h3>ISSN</h3>';
			$result .= '<ul>';
			foreach($bibr['issn'] as $temp)
				$result .= '<li id="issn-'. strtolower( $temp ) .'">'. strtolower( $temp ) . '</li>';
			$result .= '</ul>';
		
			$result .= '</li>';
		}

		if($bibr['lccn']){
			$result .= '<li class="lccn"><h3>LCCN</h3>';
			$result .= '<ul>';
			foreach($bibr['lccn'] as $temp)
				$result .= '<li id="lccn-'. $temp .'"><a href="http://lccn.loc.gov/'. urlencode( $temp ) .'?referer=scriblio" rel="tag">'. $temp .'</a></li>';
			$result .= '</ul>';
		
			$result .= '</li>';
		}

		if($bibr['olid']){
			$result .= '<li class="olid"><h3>Open Library ID</h3>';
			$result .= '<ul>';
			foreach($bibr['olid'] as $temp)
				$result .= '<li id="olid-'. $temp .'" ><a href="http://openlibrary.org'. $temp .'?referer=scriblio" rel="tag">'. $temp .'</a></li>';
			$result .= '</ul>';
		
			$result .= '</li>';
		}

/*
		if($bibr['tags']){
			$result .= '<li class="tags"><h3>Tags</h3>';
			$result .= '<ul>';
			foreach($bibr['tags'] as $temp){
				$result .= '<li>[tag]'. strtolower($temp) . '[/tag]</li>';
			}
			$result .= '</ul></li>';
		}
		if($bibr['asin'])
			$result .= '<li class="searchinside"><h3>Search inside</h3>[[searchinside|'. $bibr['asin'] .']]</li>';

		if($bibr['asin'])
			$result .= '<li class="amazon_link"><h3>Images and reviews</h3>additional catalog content provided by <a href="'. $bibr['amznurl'] .'">Amazon.com</a></li>';

*/
		$result .= '</ul>';

		return($result);
	}

	//strip leading and trailing ascii punctuations
	function strip_punct($str) {
		// props to K. T. Lam of HKUST

		//strip html entities, i.e. &#59;
		$htmlentity = '\&\#\d\d\;';
		$lead_htmlentity_pattern = '/^'.$htmlentity.'/';
		$trail_htmlentity_pattern = '/'.$htmlentity.'$/';
		$str = preg_replace($lead_htmlentity_pattern, '', preg_replace($trail_htmlentity_pattern, '', $str)); 

		//strip ASCII punctuations
		$puncts = '\s\~\!\@\#\$\%\^\&\*\_\+\`\-\=\{\}\|\[\]\\\:\"\;\'\<\>\?\,\.\/';
		$lead_puncts_pattern = '/^['.$puncts.']+/';
		$trail_puncts_pattern = '/['.$puncts.']+$/';
		$str = preg_replace($trail_puncts_pattern, '', preg_replace($lead_puncts_pattern, '', $str));

		//Strip ()
		$both_pattern = '/^[\(]([^\(|\)]+)[\)]$/';
		$trail_pattern = '/^([^\(]+)[\)]$/';
		$lead_pattern = '/^[\(]([^\)]+)$/';
		$str = preg_replace($lead_pattern, '\\1', preg_replace($trail_pattern,'\\1', preg_replace($both_pattern, '\\1', $str)));

		return ($str);
	}

	function activate() {
		global $wpdb;
	
		$charset_collate = '';
		if ( version_compare( mysql_get_server_info(), '4.1.0', '>=' )) {
			if ( ! empty( $wpdb->charset ))
				$charset_collate = "DEFAULT CHARACTER SET $wpdb->charset";
			if ( ! empty( $wpdb->collate ))
				$charset_collate .= " COLLATE $wpdb->collate";
		}
	
		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta("
			CREATE TABLE $this->harvest_table (
			source_id varchar(50) NOT NULL default '',
			harvest_date timestamp NOT NULL default '0000-00-00 00:00:00',
			imported tinyint(1) default '0',
			content longtext NOT NULL,
			PRIMARY KEY  (source_id),
			KEY imported (imported)
			) $charset_collate");
	} 

	// Default constructor 
	function Scrib_import() {
		global $wpdb;

		register_activation_hook(__FILE__, array(&$this, 'activate'));

		$this->harvest_table = $wpdb->prefix . 'scrib_harvest';

		register_taxonomy( 'sourceid', 'post' );
	} 
} 

// Instantiate and register the importer 
include_once(ABSPATH . 'wp-admin/includes/import.php'); 
if(function_exists('register_importer')) { 
	$scrib_import = new Scrib_import(); 
	register_importer($scrib_import->importer_code, $scrib_import->importer_name, $scrib_import->importer_desc, array (&$scrib_import, 'dispatch')); 
}
?>
