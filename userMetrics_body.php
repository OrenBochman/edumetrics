<?php
ini_set('display_errors', 'On');
error_reporting(E_ALL|E_STRICT);

//adjust path
$path = '/data/project/orwell01/public_html/lib/zf/library/';
set_include_path(get_include_path() . PATH_SEPARATOR . $path);

//TODO: move this to the api reader class
// load Zend classes :
require_once '/data/project/orwell01/public_html/lib/zf/library/Zend/Loader.php';
Zend_Loader::loadClass('Zend_Rest_Client');
/**
 * data for user metrics
 * @author Oren Bochman
 */

//constants
///////////////////////////////////////////////////////////////////
   define("DB_HOST_SUFFIX",".labsdb");
   define("DB_NAME_SUFFIX","_p");

   function load_messege($key,$ui_language){
    static $translations = NULL;
    if (is_null($translations)) {
       $lang_file = 'userMetrics.i18n.php';
    $lang_file_content = file_get_contents($lang_file);
    /* Load the language file as a JSON object and transform it into an associative array */
    $translations = json_decode($lang_file_content, true);
    }
    return $translations[$ui_language][$key];       
  }

class User_Metrics_Model {

  //properties
  ///////////////////////////////////////////////////////////////////
  public $mod_user           = "Jimbo Wales";
  public $mod_page           = "Wikipedia:Sandbox";
  public $mod_prefix         = "China";
  public $mod_from           = "20010101";
  
 // public $mod_to             = (date('Y',time()).date('m',time()).date('j',time())); 
  public $mod_to             = "20140101";
  public $mod_debug          = true;  
  
  private $mod_dbhost        = "enwiki.labsdb";
  private $mod_dbname        = "enwiki_p";
  private $mod_dbuser;       // database user login    set by mod_get_credentials
  private $mod_dbpass;       // database user password set by mod_get_credentials
  private $mod_connection;

  public $mod_project_language  = "en";
  public $mod_project           = "wiki";
  public $mod_project_name_msg; //i18n message for language selector
  public $mod_ui_language       = "en";
  public $mod_ui_language_msg;

  public $mod_project_name;
  //UI messages componnents
  public $mod_from_tip          = "format using YYYYMMDD";
  public $mod_to_tip            = "format using YYYYMMDD";
  public $mod_from_placeholder  = "from date";
  public $mod_to_placeholder    = "end date";
  public $mod_output_format     = "json";
  
  public $mod_talk_count        = 0;
  public $mod_page_count        = 0;
  public $mod_user_count        = 0;
  public $mod_user_talk_count   = 0;
  public $mod_db_user;
  public $mod_monthly_records; //table of edits by name space
  public $mod_daily_records;   //table of edits by day
  //Database operations
  /////////////////////////////////////////////////////////////////
  // private $mod_results;
  //methods
  ////////////////////////////////////////////////////////////////////
  //ctor
  function __construct(){ 	
    $this->mod_get_credentials();
     //load i18n messages
    $this->mod_project_name_msg=load_messege("mod_project_name_msg",$this->mod_ui_language);
    $this->mod_ui_language_msg =load_messege("mod_ui_language_msg" ,$this->mod_ui_language);
  
  }
  
  //destructor (free resources)
  function __destruct(){
    if(isset($this->mod_connection))
      $this->mod_close_connection();
  }
  // set database name and database host, based on the naming convention documented at: 
  // https://wikitech.wikimedia.org/wiki/Nova_Resource:Tools/Help#Production_replicas
  // /etc/hosts
  private function mod_set_dbname(){
              
    switch ($this->mod_project) {
      //for language dependent projects
      default:
      case "wikinews":
      case "wikiquote":
      case "wikisource":
      case "wikiversity":  
      case "wikibooks":
      case "wiktionary":
      case "wiki":           
          $this->mod_dbhost = $this->mod_project_language  . $this->mod_project . constant("DB_HOST_SUFFIX");
          $this->mod_dbname = $this->mod_project_language  . $this->mod_project . constant("DB_NAME_SUFFIX");   
          break;
      //for language indepepndent projects          
      case "commonswiki":   
      case "metawiki":    
      case "specieswiki":           
      case "mediawikiwiki": 
      case "incubatorwiki":
          $this->mod_dbhost = $this->mod_project . constant("DB_HOST_SUFFIX");
          $this->mod_dbname = $this->mod_project . constant("DB_NAME_SUFFIX");      
          break;       
    }
  }
  
  //Read credentials from replica.my.cnf
  private function mod_get_credentials(){
    $cnf = parse_ini_file("/data/project/orwell01/replica.my.cnf");
    $this->mod_dbuser = $cnf["user"];
    $this->mod_dbpass = $cnf["password"];
  }

  //DB1. Create a database connection
  function mod_connect_db(){
    $connection;
    //lazy init
    if (!isset($this->mod_connection)){
       $this->mod_set_dbname();
      //sanity checks!      
      if (!isset($this->mod_dbhost)) die("dbhost not set");
      if (!isset($this->mod_dbuser)) die("dbuser not set");
      if (!isset($this->mod_dbpass)) die("dbpass not set");
      if (!isset($this->mod_dbname)) die("dbname not set");
      //make the connection
      $connection  = mysqli_connect($this->mod_dbhost,
                                    $this->mod_dbuser,
                                    $this->mod_dbpass,
                                    $this->mod_dbname);
                                    
    if ($this->mod_debug) echo __FUNCTION__ .": <b>host:</b>".$this->mod_dbhost." <b>database:</b>".$this->mod_dbname."<br/>";
                                    
      //Test if connection occured
      if (mysqli_connect_errno() ) die(__FUNCTION__.": Database connection failed: " . mysqli_connect_error() . "  (" . mysqli_connect_errno() . ")" );
      $this->mod_connection  = $connection;
    }
  }

  //DB2.1 Perform the database query
  public function mod_get_usr_edits_daily (){  
    //lazy connect
    if (!isset($this->mod_connection)) $this->mod_connect_db();
    //santize input
    $mod_user   = mysqli_real_escape_string($this->mod_connection,$this->mod_user);
    $mod_from   = mysqli_real_escape_string($this->mod_connection,$this->mod_from);
    $mod_to     = mysqli_real_escape_string($this->mod_connection,$this->mod_to);     
    //sanity checks      
    if (strlen($mod_from)!=14) $mod_from = substr_replace($mod_from,"20010101000000",0);   
    if (strlen($mod_to)  !=14) $mod_to   = substr_replace($mod_to,"20140101000000"  ,0);
    if ($this->mod_debug) echo __FUNCTION__.": <b>mod_from:</b> ".$mod_from." <b>mod_to:</b>".$mod_to."<br/>";    
    // assemble the query    
    $query   = 
    "SELECT EXTRACT(YEAR  FROM rev_timestamp) AS YY,
            EXTRACT(MONTH FROM rev_timestamp) AS MM,
       		EXTRACT(DAY   FROM rev_timestamp) AS DD,
       		COUNT(*)
  	   FROM revision_userindex 
       JOIN page 
         ON page_id = rev_page 
      WHERE rev_user_text = '{$mod_user}'
        AND rev_timestamp BETWEEN '{$mod_from}' AND '{$mod_to}' 
   GROUP BY YY,MM,DD
   ORDER BY YY,MM,DD ASC";    
    //trace print query
    if ($this->mod_debug) echo __FUNCTION__ .": <b>query:</b><pre>".$query."</pre><br/>";
    //run the query
    $results = mysqli_query($this->mod_connection,$query);
    //test if there is a database query error
   if(!$results) die("Database query failed");
   $this->mod_daily_records=$results;   
  }
  
    //DB2.2 Perform the database query
  public function mod_get_usr_edits_monthly( ){    
    //lazy connect
    if (!isset($this->mod_connection)) $this->mod_connect_db();
    //santize input
    $mod_user   = mysqli_real_escape_string($this->mod_connection,$this->mod_user);
    $mod_from   = mysqli_real_escape_string($this->mod_connection,$this->mod_from);
    $mod_to     = mysqli_real_escape_string($this->mod_connection,$this->mod_to);     
    //sanity checks      
    if (strlen($mod_from)!=14) $mod_from = substr_replace($mod_from,"20010101000000",0);   
    if (strlen($mod_to)  !=14) $mod_to   = substr_replace($mod_to,"20140101000000"  ,0);      
    if ($this->mod_debug) echo __FUNCTION__.": <b>start_date:</b> ".$mod_from." <b>end_date:</b>".$mod_to."<br/>";    
    // assemble the query 
    $query   = 
//    "SELECT page_namespace, COUNT(*), rev_timestamp 
    "SELECT COUNT(*), v as 'coordination space'
      FROM revision_userindex 
      JOIN page ON page_id = rev_page
      JOIN namespaces ON page_namespace = i 
     WHERE rev_user_text = '{$mod_user}' 
       AND rev_timestamp BETWEEN '{$mod_from}' AND '{$mod_to}' 
     GROUP BY page_namespace;";
    if ($this->mod_debug) echo __FUNCTION__ .": <b>query:</b><pre>".$query."</pre><br/>";   
    $results = mysqli_query($this->mod_connection, $query);    //run the query
    
    if(!$results) die("Database query failed");    
    $this->mod_monthly_records=$results;   
  }
  

  //DB3. Use returned data (if any)
  public function mod_print_user(){
    if (!isset($this->mod_results)) $this->mod_get_usr_edits_monthly();

    while($row = mysqli_fetch_row($this->mod_results)){
      // while($row = mysqli_fetch_assoc($result)){

      //output data from each row
      echo "<li>";
      var_dump($row);
      echo "</li>";
    }
  }
  
  //DB4. release returned data
  public function mod_release_data(){
    mysqli_free_result($this->mod_daily_records);
    mysqli_free_result($this->mod_monthly_records);
    unset($this->mod_daily_records);
    unset($this->mod_monthly_records);
  }

  //DB5. Close the database connection
  public function mod_close_connection(){
  mysqli_close($this->mod_connection);
  unset($this->mod_connection);
  }

	 	
 	//get info via an api call and print results as list
 	function mod_get_API_data(){

 	  try {
 	    // initialize REST client
 	    $wikipedia = new Zend_Rest_Client('http://en.wikipedia.org/w/api.php');

 	    // set query parameters
 	    $wikipedia->action('query');
 	    $wikipedia->list('allcategories');
 	    $wikipedia->acprefix($this->mod_prefix);
 	    $wikipedia->format('xml');

 	    // perform request
 	    // iterate over XML result set
 	    return $result = $wikipedia->get();  //this might need to be stored in a global variable
 	  }  catch (Exception $e) {
 	    die('ERROR: ' . $e->getMessage());
 	  }
 	}//function
}

/**
 * view for user metrics
 * @author Oren Bochman
 */
class User_Metrics_View {
  public $model;
  public $controller;
  
  //checks if form has post params
  public function has_postback(){
    return (count($_POST) >= 1);
  } 
  
  public function set_from_postback(){ 	   	
    //set form vars from postaback 	  
    if( $this->has_postback() ){
       if($this->model->mod_debug) echo __FUNCTION__."<ul>";    
        foreach($_POST as $key => $val){         
            try{     
                $this->model->$key=$val;              
            }catch(Exception $e){
                //if ($this->model->mod_debug) echo 'Message: ' .$e->getMessage();
                echo 'Message: ' .$e->getMessage();
                die ("parameter " .$key. " not supported in view");  
            }       
          if($this->model->mod_debug) echo "<li>" . $key . "=>"  .$val . "</li>";
        }
          if($this->model->mod_debug) echo "</ul>";
                
 	 // $this->model->mod_connect_db(); 	   	   	  
 	  $this->model->mod_get_usr_edits_monthly(); 
 	  $this->model->mod_get_usr_edits_daily(); 	                                 
  	  }                                           
 	}

  //checks if form has get params
  public function has_getback(){
    return (count($_GET) >= 1);
  }

  //checks if form has cookie params
  public function has_cookie_back(){
    return (count($_COOKIE) >= 1);
  }
  
  public function __construct(){
  
    $this->model= new User_Metrics_Model();
    if($this->has_postback()){      
      $this->set_from_postback();
      $this->controller = new User_Metrics_Controller($this->model);      
    }
   
  }
  
  //all the output methods needed!
  public function output(){
    return "<p>" . $this->model->mod_string . "</p>";
  }

  public function fancyHR(){
    echo '<div style="width:16.5%; float:left;height:6px; background:#C35817;"></div>' .
        '<div style="width:16.5%; float:left;height:6px; background:#C68E17;"></div>' .
        '<div style="width:16.5%; float:left;height:6px; background:#E9AB17;"></div>' .
        '<div style="width:16.5%; float:left;height:6px; background:#FBB917;"></div>' .
        '<div style="width:16.5%; float:left;height:6px; background:#FDD017;"></div>' .
        '<div style="width:16.5%; float:left;height:6px; background:#FFFC17;"></div><br/>';
  }
  
  public function output_API_data(){    
    if(!$this->has_postback() || strlen($this->model->mod_prefix)==0)
      return "";
    $rows = $this->model->mod_get_API_data($this->model->mod_prefix);
    $res = "<ol>";
    foreach ($rows->query->allcategories->c as $row){
      $res .=	'<li>';
      $res .=	'<a	href="http://www.wikipedia.org/wiki/Category:'.$row.'">'.$row.'</a></li>';
    }
    $res .= "</ol>";
    return $res;
  }

  public function get_scores_from_results($results,$showHeader=true){
    return;
    if(!$this->has_postback()){
      return ;
    }
      while($row = mysqli_fetch_assoc($results)){

     //   $keys = array_keys($row);
        foreach ($row as $key=>$cell){

        
    	//$this->model->mod_page_count=$results[""]; 
	      switch($key){
	        case "User":
	         $this->model->mod_user_count+=$cell;
	        break;
	       
	        case "Talk":
	         $this->model->mod_user_talk_count+=$cell;
	        break;
	        
	        case "User_talk":
	         $this->model->mod_user_talk_count+=$cell;
	        break;

	      	case "":
	         $this->model->mod_page_count+=$cell;
	        break;	        
	        }
          }       
      }   
    }
  
  //print returned data (if any)
  public function print_tbl_assoc($results,$showHeader=true){
    
    if(!$this->has_postback()){
      return ;
    }
    if (!isset($results)) $results=$this->model->mod_db_user->get_usr_edits_monthly();    
  
    //style the table using bootstrap
    $res = '<table id="sql-table" class="table table-striped table-bordered table-hover table-condensed">';
    while($row = mysqli_fetch_assoc($results)){
      //print row
      if($showHeader)
      {
        //print header
        $res .= "<thead><tr>";
        $keys = array_keys($row);
        for($i=0;$i<count($keys);$i++)
        { 
          //note: empty colums are removed on the client side (requres span tags)
          $res .=  '<th><span>' . $keys[$i] . '</span></th>';
        }
          $res .= '<tbody><tr>';
        $showHeader = false;
      }
      $res .=  '<tbody><tr>';
          // var_dump($row);
      foreach ($row as $key=>$cell){
        $res .= "<td><span>" . $cell . "</span></td>";
        
        switch($key){
	        case "User":
	         $this->model->mod_user_count+=$cell;
	        break;
	       
	        case "Talk":
	         $this->model->mod_user_talk_count+=$cell;
	        break;
	        
	        case "User_talk":
	         $this->model->mod_user_talk_count+=$cell;
	        break;

	      	case "":
	         $this->model->mod_page_count+=$cell;
	        break;	        
	 }
        
        
        
      }
      $res .= "</tr>";
      //output data from each row
  }
  //close table
  $res .=  "</tbody></table>";
  return $res;
  } 
  
  public function print_daily_tbl($record,$showHeader=true){
  
    if(!$this->has_postback()){
      return ;
    }
    
    if (!isset($results)) 
      $results=$this->model->mod_db_user->get_usr_edits_daily();

        //style the table using bootstrap
    $res = '<table id="sql-table" class="table table-striped table-bordered table-hover table-condensed">';
    while($row = mysqli_fetch_assoc($results)){
      //print row
      if($showHeader)
      {
        //print header
        $res .= "<thead><tr>";
        $keys = array_keys($row);
        for($i=0;$i<count($keys);$i++)
        { 
          //note: empty colums are removed on the client side (requres span tags)
          $res .=  '<th><span>' . $keys[$i] . '</span></th>';
        }
          $res .= '<tbody><tr>';
        $showHeader = false;
      }
      $res .=  '<tbody><tr>';
          // var_dump($row);
      foreach ($row as $key=>$cell){
        $res .= "<td><span>" . $cell . "</span></td>";
      }
      $res .= "</tr>";
      //output data from each row
  }
  //close table
  $res .=  "</tbody></table>";
  //release the database data
  $this->model->mod_db_user->release_user();
  return $res;     
  }
  
  public function print_heat_calander($records,$showHeader=true){
    
    if(!$this->has_postback()) return;  
    $max_value=0;
    $min_value=0;
    $first_year=2011;
    $last_year=2011;
            
    if (!isset($records)) die("$records not avaialbe");
      $res="<script>daily_data=";
      $res .= "[";
      $first_time=true;           
      while($row = mysqli_fetch_assoc($records)){//print rows
     // if ($this->model->mod_debug) {var_dump($row);}
      if($first_time==true){
         $res .= "{";
         $first_time=false; 
      }else{
        $res .= ",\n{";
      }      
      switch($this->model->mod_output_format){
        default:
        case "json":
       // [{'Date': '1955-01-01', 'Value': 1}, ...]
          $res .="'Date': '" . $row["YY"] . "-" .str_pad($row["MM"],2,'0',STR_PAD_LEFT) . "-" . str_pad($row["DD"],2,'0',STR_PAD_LEFT) ."', 'Value': " . $row["COUNT(*)"] . "}";
          if($row["COUNT(*)"]>$max_value) $max_value = $row["COUNT(*)"];
          if($row["COUNT(*)"]<$min_value) $min_value = $row["COUNT(*)"];
          if($row["YY"]>$last_year) $last_year = $row["YY"];
          if($row["YY"]<$first_year) $first_year = $row["YY"];
          break;        
        case "array":
          // [ [1955-01-01],1], ... ]      
          $res .= $row["YY"] ."-";
          $res .= $row["MM"] ."-";
          $res .= $row["DD"] .",";
          $res .= $row["COUNT(*)"] ;  
          $res .= "]"; 
          break;     
        case "csv":
          // [ [1955-01-01],1], ... ]      
          $res .= $row["YY"] ."-";
          $res .= $row["MM"] ."-";
          $res .= $row["DD"] .",";
          $res .= $row["COUNT(*)"] ;  
          $res .= "];\n"; 
          break;
      }
    }
    $res .=  "];    
    var first_year=".$first_year.";
    var last_year=" .$last_year .";
    var min_value=" .$min_value .";
    var max_value=" .$max_value .";   
    var user_name='".$this->model->mod_user ."';
    </script>";
    // release the database data
    // $this->model->mod_db_user->release_user();    
    return $res;  
  }
}

/**
 * controller for user metrics
 *
 * @author Oren Bochman
 *
 */
class User_Metrics_Controller {
  private $model;

  public function __construct($model){
    $this->model = $model;   
  }

  //process input
}

//$model = new User_Metrics_Model();
//$controller = new User_Metrics_Controller($model);
//$view = new User_Metrics_View($controller, $model);
$view = new User_Metrics_View();
//echo $view->output();

?>
<!DOCTYPE html>
<html lang="en">
  <title>Oren's Tools</title>
  <head>
    <meta charset="utf-8">
    <meta http-equiv="imagetoolbar" content="no" />
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <link href="../lib/bootstrap/css/bootstrap.min.css" rel="stylesheet" media="screen" />
    <link href="../lib/bootstrap/css/bootstrap-responsive.css" rel="stylesheet">
    <link href="http://code.jquery.com/ui/1.10.3/themes/smoothness/jquery-ui.css" rel="stylesheet" media="screen" />
    <style type="text/css">
body {
	padding-top: 60px;
	padding-bottom: 40px;
	shape-rendering: crispEdges;
}
      
.sidebar-nav {
 padding: 9px 0;
}
  
@media ( max-width : 980px) { /* Enable use of floated navbar text */
  	.navbar-text.pull-right {
 float: none;
 padding-left: 5px;
 padding-right: 5px;
  	}
}
  
legend {
 border: none;
 font-size: 95%;
 padding: 0.5em;
 width: auto;
 margin-bottom: 1px;
 line-height: 0 px;
}
  
fieldset {
 border: 1px solid rgb(47, 111, 171);
 margin: 1em 0px;
 padding: 0px 1em 1em;
 line-height: 1.5em;
}

//d3.js heat map calander
.day {
 fill: #fff;
 stroke: #ccc;
}

.month {
 fill: none;
 stroke: #000;
 stroke-width: 2px;
}

.RdYlGn .q0-11{fill:rgb(165,0,38)}
.RdYlGn .q1-11{fill:rgb(215,48,39)}
.RdYlGn .q2-11{fill:rgb(244,109,67)}
.RdYlGn .q3-11{fill:rgb(253,174,97)}
.RdYlGn .q4-11{fill:rgb(254,224,139)}
.RdYlGn .q5-11{fill:rgb(255,255,191)}
.RdYlGn .q6-11{fill:rgb(217,239,139)}
.RdYlGn .q7-11{fill:rgb(166,217,106)}
.RdYlGn .q8-11{fill:rgb(102,189,99)}
.RdYlGn .q9-11{fill:rgb(26,152,80)}
.RdYlGn .q10-11{fill:rgb(0,104,55)}
    </style>
  </head>
  <body>
    <form id="myform" action="userMetrics_body.php" method="post" class="form-horizontal"  novalidate="novalidate">
	  <div class="navbar navbar-inverse navbar-fixed-top">
		<div class="navbar-inner">
			<div class="container-fluid">
			  <button type="button" class="btn btn-navbar" data-toggle="collapse" data-target=".nav-collapse">
			    <span class="icon-bar"></span> <span class="icon-bar"></span> <span	class="icon-bar"></span>
			  </button>
			  <a class="brand" href="#"><?php echo $view->model->mod_project_name_msg; ?> </a>
				<div class="nav-collapse collapse">
					<p class="navbar-text pull-right">Logged in as <a href="#" class="navbar-link">Username</a>
					</p><ul class="nav">
						<li class="active"><a href="#">Home</a></li>
						<li><a href="#about">About</a></li>
						<li><a href="#contact">Contact</a></li>
						<li>
						
        				<!--		
        						<div class="bfh-selectbox bfh-languages" data-language="en_US" data-available="en,fr,es" data-flags="true">
                                  <input type="hidden" value="">
                                  <a class="bfh-selectbox-toggle" role="button" data-toggle="bfh-selectbox" href="#">
                                    <span class="bfh-selectbox-option input-medium" data-option=""></span>
                                    <b class="caret"></b>
                                  </a>
                                  <div class="bfh-selectbox-options">
                                  <div role="listbox">
                                    <ul role="option">
                                    </ul>
                                  </div>
                                  </div>
                                </div>
        				 -->		
						 
						<div class="control-group">
								<label class="control-label" for="mod_ui_language"><?php echo $view->model->mod_ui_language_msg; ?></label>
								<div class="controls">
									<select name="mod_ui_language" 
									        class="span1"
									       
									        value="<?php echo $view->model->mod_ui_language; ?>"
										    placeholder="project">
  									  <option value="en">english</option>                      
                                      <option value="he">עברית</option>                                     	
									</select>
								</div>
							</div> 
						</li>
					</ul>
				</div><!--/.nav-collapse -->
			</div>
		</div>
	</div>
	<!-- side bar start -->
	<div class="container-fluid">
		<div class="row-fluid">
			<div class="span2">
				<div class="well sidebar-nav">
					<img
						src="http://wikitech.wikimedia.org/w/images/thumb/6/60/Wikimedia_labs_logo.svg/120px-Wikimedia_labs_logo.svg.png"
						title="Main Page" style="display: block; margin-left: auto; margin-right: auto"/>
					<ul class="nav nav-list">
						<li class="nav-header">Navigation</li>
						<li><a href="/">Wikimedia Tool Labs</a></li>
						<li><a href="/orewll01/..">Main Page</a></li>
						<li class="active"><a href="/orewll01">Orwell01</a></li>
						<li class="nav-header">Development</li>
						<li><a
							href="https://git.wikimedia.org/summary/mediawiki%2Fcore.git">View
								code changes</a></li>
						<li><a
							href="https://gerrit.wikimedia.org/r/#/q/project:mediawiki/core+branch:master,n,z">Code
								review</a></li>
						<li><a href="https://git.wikimedia.org/">Browse repository</a></li>
						<li><a
							href="https://doc.wikimedia.org/mediawiki-core/master/php/html/">Code
								Docs</a></li>
						<li><a href="https://integration.wikimedia.org/ci/">CI on Jenkins</a>
						</li>
						<li class="nav-header">Bugs & Support</li>
						<li><a href="http://meta.wikimedia.org/wiki/User talk:OrenBochman">My
								talk page</a></li>
						<li><a href="http://www.mediawiki.org/wiki/Bugzilla">Bug tracker</a>
						</li>
						<li><a
							href="https://webchat.freenode.net/?channels=#wikimedia-labs">IRC
								support</a></li>
					</ul>
				</div>
			</div>
			<!--span3-->

			<!-- input -->
			<div class="span9">
				<div>
					<h2>User and Article Details</h2>
						<fieldset>
							<legend>Tool Input</legend>
							<div class="control-group">
								<label class="control-label" for="mod_user">Username *</label>
								<div class="controls">
									<input id="username" type="text" name="mod_user"  required="required"
										value="<?php echo $view->model->mod_user; ?>"
										placeholder="user name">
								</div>
							</div>
							<div class="control-group">
								<label class="control-label" for="mod_page">Page</label>
								<div class="controls">
									<input type="text" name="mod_page" 
										value="<?php echo $view->model->mod_page; ?>"
										placeholder="page"></input>
								</div>
							</div>
							
							<div class="control-group">
								<label class="control-label" for="mod_project_language">Language & Project *</label>
								<div class="controls ">
									<input class="span1" type="text" name="mod_project_language" required="required"
										   value="<?php echo $view->model->mod_project_language ; ?>"
										   placeholder="en"></input>
								   						
									<select type="text" name="mod_project" class="span3"
										value="<?php echo $view->model->mod_project ; ?>"
										placeholder="project">
  									  <option value="wiki">.wikipedia.org</option>                      
                                      <option value="wiktionary">.wikitionary.org</option>
                                      <option value="wikibooks">.wikibooks.org</option>
                                      <option value="wikinews">.wikinews.org</option>
                                      <option value="wikiquote">.wikiquote.org</option>	
                                      <option value="wikisource">.wikisource.org</option>	
                                      <option value="wikiversity">.wikiversity.org</option>	
                                      <option value="commonswiki">commons.wikimedia.org</option>
                                      <option value="metawiki">meta.wikimedia.org</option>	
                                      <option value="specieswiki">species.wikimedia.org</option>	
                                      <option value="mediawikiwiki" >mediawiki.org</option>	
                                      <option value="incubatorwiki">incubator.wikimedia.org</option>		
									</select>
								</div>
							</div>						
							
							<!-- div class="control-group">
								<label class="control-label" for="mod_prefix">Category prefix</label>
								<div class="controls">
									<input type="text" 
									       name="mod_prefix"
										   value="<?php echo $view->model->mod_prefix; ?>"
										   placeholder="catagory prefix"></input>
								</div>
							</div-->							
							<div class="control-group">	
							    <label class="control-label" for="mod_debug">Debug</label>
								<div class="controls">												
									<input id="mod_debug" 
									       name="mod_debug"
									       type="checkbox" 
									       value="<?php echo $view->model->mod_debug; ?>" />
						        </div>								
							</div>							
							<div class="control-group">
								<label class="control-label" for="mod_from" >Start Date *</label>
								<div class="controls">
									<input id="mod_from" 
									       name="mod_from" 
									       pattern="[0-9 ]{6,14}"
									       maxlength="14"
									       type="datetimeNew" 
										   class="span2 date-format-tip" 
										   required="required"
										   placeholder="<?php echo $view->model->mod_from_placeholder; ?>"
										   title="<?php echo $view->model->mod_from_tip; ?>"
										   value="<?php echo $view->model->mod_from; ?>"></input>
								</div>
							</div>
						
							<div class="control-group">
								<label class="control-label" for="mod_to">End Date *</label>							
								<div class="controls" id="time_range_end">								
									<input id="mod_to"
									       name="mod_to" 
									       type="datetimeNew"
										   class="span2 date-format-tip"
										   required="required"
										   pattern="[0-9 ]{6,14}"
									       maxlength="14"
										   placeholder="<?php echo $view->model->mod_to_placeholder;?>"
										   title="<?php echo $view->model->mod_to_tip; ?>"
										   value="<?php echo $view->model->mod_to; ?>"></input> 
									<input type="submit" 
									       value="Process" 
									       class="btn btn-primary" />
									
	
								</div>								
							</div>							
							
						</fieldset>
					
					<!-- output  -->
					<div id="accordion" style="display:<?php if ($view->has_postback()) {echo "show;";}
					                                       else {echo "none;";} ?> ">
 
						<!-- h2>
							<a href="#">API Search for categories with prefix '<?php echo $view->model->mod_prefix; ?>'</a>
						</h2>

						<div>
							<?php $view->output_API_data(); ?>
							<?php echo $view->fancyHR(); ?>

						</div-->
						<h2><a href="#">Username details</a></h2>
						<div>
							<?php echo $view->print_tbl_assoc($view->model->mod_monthly_records,true);?>
							
							
							<?php echo $view->fancyHR(); ?>
						</div>
						
												<h2>
							<a href="#">Score Analysis</a>
						</h2>
						<div>
						    <?php $view->get_scores_from_results($view->model->mod_monthly_records,true);?>
							Calculated <b>score</b> for User: <?php echo $view->model->mod_user; ?> on Page: <?php echo $view->model->mod_page; ?><br />
							<ul>
								<li>talk page edits: <?php echo $view->model->mod_talk_count; ?></li>
								<li>article page edits: <?php echo $view->model->mod_page_count; ?></li>
								<li>user page edits: <?php echo $view->model->mod_user_count; ?></li>
								<li>user talk page edits: <?php echo $view->model->mod_user_talk_count; ?></li>
							</ul>
							<?php $view->fancyHR(); ?>
						</div>
						
						
						<h2><a href="#">Daily activity</a></h2>
					    <div id="CalHeatMap">					    
							<?php echo $view->print_heat_calander($view->model->mod_daily_records,true);?>
							<?php if($view->has_postback())$view->model->mod_release_data(); //release the database data ?>	
							<?php echo $view->fancyHR(); ?>			
						</div>
					</div><!--  output div -->
				</div><!-- accordion -->
			</div><!-- content div -->
		</div><!-- column div -->
	</div>
</form>
	<script type="text/javascript" src="../lib/bootstrap/js/bootstrap.min.js"></script>
	<script type="text/javascript" src="http://ajax.googleapis.com/ajax/libs/jquery/1.9.1/jquery.min.js"></script>
	<script type="text/javascript" src="http://ajax.googleapis.com/ajax/libs/jqueryui/1.10.3/jquery-ui.min.js"></script>
	<script type="text/javascript" src="http://www.google.com/jsapi"></script>
	<script src="http://d3js.org/d3.v3.min.js" charset="utf-8"></script>
	<script src="../script.js"></script>
</body>
</html>