<?php

//Database operations
/////////////////////////////////////////////////////////////////
class User_DB {

 //properties
 ///////////////////////////////////////////////////////////////////
 private $dbhost = "enwiki.labsdb";
 private $dbname = "enwiki_p";
 private $dbuser;
 private $dbpass;

 private $connection;
 private $results;

   //methods
   ////////////////////////////////////////////////////////////////////

   //constructor
   function __construct(){

     $this->get_credentials();
     $this->get_db_connection();
   }

   //destructor (free resources)
   function __destruct(){
      if(isset($this->connection))
         $this->close_connection();
   }

   //Read creadentials from repolica..my.cnf
   private function get_credentials(){
      $cnf = parse_ini_file("/data/project/orwell01/replica.my.cnf");
      $this->dbuser = $cnf["user"];
      $this->dbpass = $cnf["password"];
   }

   //DB1. Create a database connection
   function get_db_connection(){

     $connection;
     //lazy init
     if (!isset($this->connection)){
       $this-> get_credentials();

       //sanity checks!
       if (!isset($this->dbhost)) die("dbhost not set");
       if (!isset($this->dbuser)) die("dbuser not set");
       if (!isset($this->dbpass)) die("dbpass not set");
       if (!isset($this->dbname)) die("dbname not set");

       //make the connection
       $connection  = mysqli_connect($this->dbhost,
                                     $this->dbuser,
                                     $this->dbpass,
                                     $this->dbname);
       //Test if connection occured
       if (mysqli_connect_errno() )
         die("Database connection failed: " . mysqli_connect_error() . "  (" . mysqli_connect_errno() . ")" );
       $this->connection  = $connection;
     }
   }

   //DB2. Perform the database query
   function query_tbl($user='Jimbo Wales',$debug=False){
     //sanity checks!
     if (!isset($this->connection)) $this->get_db_connection();
     //santize $user
     $user= mysqli_real_escape_string($this->connection,$user);
     // assemble the query
     $query  = "SELECT * ";
     $query .= "FROM user ";
     $query .= "WHERE user_name LIKE '" . $user ."%' ";
     $query .= "ORDER BY user_editcount DESC ";
     $query .= "LIMIT 20;";
     //trace print query
     if ($debug) echo "<b>" . $query . "</b><br/>";
     //run the query
     $results = mysqli_query($this->connection,$query);
     //test if there is a database query error
     if(!$results){
       die("Database query failed");
    }
    $this->results=$results;
   }

   //DB3. Use returned data (if any)
   public function print_user(){
    if (!isset($this->results)) $this->query_tbl();

     while($row = mysqli_fetch_row($this->results)){
     // while($row = mysqli_fetch_assoc($result)){

     //output data from each row
       echo "<li>";
       var_dump($row);
       echo "</li>";
    }
   }
   //DB3. Use returned data (if any)
   public function print_tbl_assoc(){
    if (!isset($this->results)) $this->query_tbl();
    $showHeader= true;

     //print header
     echo '<table id="sql-table" class="table table-striped table-bordered table-hover table-condensed">';
     while($row = mysqli_fetch_assoc($this->results)){
       //print row


 //Outputs a header if nessicary

       if($showHeader)
       {
       	  echo "<thead><tr>";
          $keys = array_keys($row);
          for($i=0;$i<count($keys);$i++)
          {
            echo '<th><span>' . $keys[$i] . '</span></th>';
          }
          echo '<tbody><tr>';
          $showHeader = false;
       }
       echo '<tbody><tr>';
       // var_dump($row);
       foreach ($row as $key=>$cell){
 	 echo "<td><span>" . $cell . "</span></td>";
       }
       echo "</tr>";

     //output data from each row
    }
    //close table
     echo "</tbody></table>";

   }

   //DB4. release returned data
   public function release_user(){
      mysqli_free_result($this->results);
      unset($this->results);
   }

   //DB5. Close the database connection
   public function close_connection(){
      mysqli_close($this->connection);
      unset($this->connection);
   }

}//class
?>
