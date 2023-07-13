<?php
if(!function_exists('get_unique_class_id_by_hash')){
  function get_unique_class_id_by_hash($other_ignored_classes = array()) {
    // For getting the calling class name. But, actually a hash of it and no mare than 10 characters of that hash
    // basically to use as a unique ID for that calling class
    // It does this by working up the debug stack. So if there are intermediary classes you want to be ignored, include them in an array!!
    $trace = debug_backtrace();
    $class = $trace[1]['class'];
    for ( $i=1; $i<count( $trace ); $i++ ) {
      if ( isset( $trace[$i] ) ) {
        if ( $class != $trace[$i]['class'] && (! in_array(strval($trace[$i]['class']), $other_ignored_classes) )) {
               return substr(base64_encode(sha1(strval($trace[$i]['class']), true)), 0, 10 );
        }
      }
    }
  }
}

if(!class_exists(lumberghs)){
  class lumberghs {
      /*
       * Ah lumberghs... gotta get those TPS reports IN!
       * These objects harnesses the power of the lumbergh to get shit done
       *
       * You can add a lumbergh with add_lumbergh(). The unique name is important!
       * If you pick a name thats already in use, your add lumbergh will effectively be ignored
       * This is to prevent multiple lumberghs from the same thing. VERY IMPOTANT!!
       *
       * you can list all open lumberghs with get_all_lumberghs()
       *
       * you can also get all lumberghs that are about to escalate
       * (if their demands aren't already met) with get_lumberghs_ready_to_pounce()
       *
       * When called to pounce, the lumberghs will plot out (update the Db) with their next escalation (lumbergh_strategies)
       * Then they will return to you, the function you should call to see if he's already happy
       * As well as a function to use to further escalate
       * And a reference ID for use with both
       *
       * Its up to you to run said functions
       * (lumbergh can't be bothered to do this on his OWN!!!)
       * ok, but really its incase you want to run in your local scope...
       * Anyhow, if the lumbergh should be called off then, you should of course close the lumbergh
       * to prevent further (already plotted) escalations you can do this
       * by calling ok_lumbergh() on the lumbergh object
       */
       public $plugin_hash;
       public $strategies;

      public function __construct($strategies) {
          $sql = "CREATE TABLE IF NOT EXISTS `lumberghs` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `plugin_hash` varchar(11) NOT NULL,
              `unique_name` varchar(255) NOT NULL,
              `next_run` bigint(20) NOT NULL DEFAULT '0',
              `func_makes_him_happy` varchar(45) NOT NULL,
              `strategy` varchar(45) NOT NULL,
              `reference` varchar(255) NOT NULL,
              `current_index` int(11) NOT NULL DEFAULT '0',
              PRIMARY KEY (`id`),
              UNIQUE KEY `unique_name_UNIQUE` (`unique_name`),
              KEY `next_run_index` (`next_run`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
            ";
          db_query($sql);
          $this->plugin_hash = get_unique_class_id_by_hash(array("lumbergh_strategies", "lumbergh_shenanigan", "lumberghs", "lumbergh"));
          $this->strategies = new $strategies();
      }

      #function to check to see if a karne exists yet or not
      function lumbergh_exists($uniqueName) {
        $sql = sprintf("select * from lumberghs where unique_name = '%s' and plugin_hash = '%s';", $uniqueName, get_unique_class_id_by_hash(array("lumbergh_strategies", "lumbergh_shenanigan", "lumberghs", "lumbergh")));
        if (!($res = db_query($sql)) || !db_num_rows($res)) {
            return false;
        } else {
            return true;
        }
      }

      function get_lumbergh($id){
        return new lumbergh($id, $plugin_hash, $this->strategies);
      }

      function get_all_lumberghs() {
        $sql = sprintf("SELECT `id` from `lumberghs` where `plugin_hash` = '%s'", get_unique_class_id_by_hash(array("lumbergh_strategies", "lumbergh_shenanigan", "lumberghs", "lumbergh")));
        $all_lumberghs = array();
        if (($resp = db_query($sql)) && db_num_rows($resp)) {
            while ($row = db_fetch_array($resp)) {
              $lumbergh = $this->get_lumbergh($row['id']);
                array_push($all_lumberghs, $lumbergh);
            }
        }
        return $all_lumberghs;
      }

      function get_lumberghs_ready_to_pounce() {
        $sql = sprintf("SELECT id from lumberghs where next_run <= %d and plugin_hash = '%s';", time(), get_unique_class_id_by_hash(array("lumbergh_strategies", "lumbergh_shenanigan", "lumberghs", "lumbergh")));
        $all_lumberghs = array();
        if (($resp = db_query($sql)) && db_num_rows($resp)) {
            while ($row = db_fetch_array($resp)) {
              $lumbergh = $this->get_lumbergh($row['id']);
                array_push($all_lumberghs, $lumbergh);
            }
        }
        return $all_lumberghs;
      }

      function add_lumbergh($unique_name, $func_makes_him_happy, $reference, $lumbergh_strategy) {
        $origtz = date_default_timezone_get();
        date_default_timezone_set("America/Los Angeles");
        # pretty straight forward insert of the row to create the object
        # only trickery here is a super nest of garbage to the first "next_run" time
        $sql = sprintf('INSERT IGNORE INTO `lumberghs`
                (`unique_name`, `func_makes_him_happy`,`reference`, `strategy`, `next_run`, `plugin_hash`)
                VALUES ("%s","%s","%s","%s", %d, "%s");
                ', $unique_name, $func_makes_him_happy, addslashes($reference), $lumbergh_strategy,
                strtotime($this->strategies->$lumbergh_strategy[0]->str_strtotime_when), get_unique_class_id_by_hash(array("lumbergh_strategies", "lumbergh_shenanigan", "lumberghs", "lumbergh")));
        db_query($sql);
        return db_affected_rows() > 0;
        date_default_timezone_set($origtz);
      }

  }
}

if(!class_exists(lumbergh)){
  class lumbergh {

      public $id;
      public $unique_name;
      public $func_makes_him_happy;
      public $reference;
      public $strategy;
      public $current_strategy_index;
      public $next_run;
      public $is_dead;
      public $plugin_hash;
      public $strategies;

      public function __construct($id, $plugin_hash, $strategies) {
          $this->id = $id;
          $this->plugin_hash = $plugin_hash;
          $this->strategies = $strategies;
          $this->reload_data_from_db();
      }

      function reload_data_from_db(){


          $sql = sprintf("SELECT * from `lumberghs` "
                  . "WHERE `id` = %d", $this->id);
          if (($resp = db_query($sql)) && db_num_rows($resp)) {
              while ($row = db_fetch_array($resp)) {
                  $this->unique_name = $row['unique_name'];
                  $this->func_makes_him_happy = $row['func_makes_him_happy'];
                  $this->reference = $row['reference'];
                  $strategy_name = $row['strategy'];
                  $this->strategy = $this->strategies->$strategy_name;
                  $this->current_strategy_index = $row['current_index'];
                  $this->next_run = $row['next_run'];
                  $this->isdead = False;
              }
          }
      }

      function ok_lumbergh() {
          if ($this->isdead) {
              return true;
          }

          $sql = sprintf("DELETE FROM lumberghs WHERE id = %d;", $this->id);
          db_query($sql);
          return db_affected_rows() > 0;
      }

      function sleep($sleep){
          if(is_int($sleep)){
              # int just updates the next run by that many seconds. easy peasy!
              $sql = sprintf("UPDATE lumberghs SET next_run=next_run+%d, current_index = 0 WHERE id=%d",
                      $secondsToSleep, $this->id);
              db_query($sql);
          } elseif (is_string($sleep)){
              # strings update the next_run by the strtotime value with
              # the current next_run as a base
              # first we need to get the current next_run time
              $next_run = time();
              $sql = sprintf("select next_run from lumberghs where ID = %d;", $this->id);
              if (($resp = db_query($sql)) && db_num_rows($resp)) {
                  while ($row = db_fetch_array($resp)) {
                      $next_run = $row['next_run'];
                  }
              }
              $origtz = date_default_timezone_get();
              date_default_timezone_set("America/Los Angeles");
              $newNextRun = strtotime($sleep, $next_run);
              date_default_timezone_set($origtz);
              # update it!
              $sql = sprintf("UPDATE lumberghs SET next_run=%d, current_index = 0 WHERE id=%d",
                      $newNextRun, $this->id);
              db_query($sql);

          }
      }

      function pounce() {
          # make sure data isn't stale beofre pouncing!
          $this->reload_data_from_db();

          #return false if its not time to run yet or if this object is dead
          if ($this->next_run > time() or $this->isdead) {
              return false;
          }
          $func_to_escalate = $this->strategy[$this->current_strategy_index]->func_escalate;

          if (isset($this->strategy[$this->current_strategy_index + 1])) {
              # if there is a next shenanagain, lets update when and what will happen
              $this_shenanigan = $this->strategy[$this->current_strategy_index];
              $next_shenanigan = $this->strategy[$this->current_strategy_index + 1];


              if ($next_shenanigan->func_escalate === "special_internal_lumbergh_func_redo_last") {
                  # this handles if the last event loops or not
                  # Even if there are additional shenanigans after this in the strat
                  # they will never be reached, because this will continue to loop
                  $origtz = date_default_timezone_get();
                  date_default_timezone_set("America/Los Angeles");
                  $next_shenanigan_when = strtotime($next_shenanigan->str_strtotime_when);
                  date_default_timezone_set($origtz);
                  # really important that we don't incriment the index!!
                  $next_shenanigan_index = $this->current_strategy_index;
              } else {
                  # easy peasy, just get the new str_strtotime_when and func_escalate from the $next_shenanigan
                  $origtz = date_default_timezone_get();
                  date_default_timezone_set("America/Los Angeles");
                  $next_shenanigan_when = strtotime($next_shenanigan->str_strtotime_when);
                  date_default_timezone_set($origtz);
                  # incriment that index so we do the next function next time!
                  $next_shenanigan_index = $this->current_strategy_index + 1;
              }

              # update the next index and timestamp in the DB!
              $sql = sprintf("UPDATE lumberghs SET next_run=%d, current_index=%d WHERE id=%d",
                      $next_shenanigan_when, $next_shenanigan_index, $this->id);
              db_query($sql);

              # update this object internals too!
              $this->current_strategy_index = $next_shenanigan_index;
              $this->next_run = $next_shenanigan_when;
          } else {
              # if there is not a next shenanagain, lets kill this lumbergh off and return what we have left!
              $this->ok_lumbergh();
              $this->isdead = true;
          }

          # now that all thats out of the way, time to return our function and reference!
          return array("func_makes_him_happy" => $this->func_makes_him_happy,
              "func_escalate" => $func_to_escalate,
              "reference" => $this->reference,
              "lumberghId" => $this->id);
      }

  }
}

if(!class_exists(lumbergh_shenanigan)){
  class lumbergh_shenanigan {
      /*
       * We all know lumbergh's going pull some shenangains.
       * But specifically when will he pull this shenanigan?
       * And what specifically will he do?
       */

      public $str_strtotime_when;
      public $func_escalate;

      public function __construct($str_strtotime_when, $func_escalate) {
          # If $str_strtotime_when is an array, find the lowest value and us it!
          if(is_array($str_strtotime_when)){
              $lowest_val = null;
              foreach ($str_strtotime_when as $sub_strtotime){
                  date_default_timezone_set("America/Los Angeles");
                  $this_strtotime_val = strtotime($sub_strtotime);
                  date_default_timezone_set($origtz);
                  if(is_null($lowest_val)){
                      $this->str_strtotime_when = $sub_strtotime;
                      $lowest_val = $this_strtotime_val;
                  } elseif (intval($this_strtotime_val) < intval($lowest_val)) {
                      $this->str_strtotime_when = $sub_strtotime;
                      $lowest_val = $this_strtotime_val;
                  }
              }
              if(is_null($lowest_val)){
                  $this->str_strtotime_when = "now";
              }

          } else {
              $this->str_strtotime_when = $str_strtotime_when;
          }
          $this->func_escalate = $func_escalate;
      }

  }
}


/*
//Example of the object you'll need to put into your code
//(please leave it out of this file)

class rename_this_class_lumbergh_strategies {
     // lumbergh's got different strategies for different situations
     // This class lays all of the shenanigans he will pull if
     // $func_makes_him_happy isnt met.
     //
     // The first element of the array is a str_to_time. If this first element
     // is also an array, all elements of that array will be evaluated and the
     // soonest time will be used
     //
     // The second element of the array is the action
     // the lumbergh will take at that time

     public function __get($property) {
       if (property_exists($this, $property)) {
         return $this->$property;
       }
     }
     // Initiate your strategies here
     function __construct() {

          // This is an example strategy for a lumbergh
          // This lumbergh is trying to get his TPS. Perhaps its a reasonable ask!
          // Before each step, he checks to see if he's already happy or not
          // So, if on the first run he'd already got his report, then even
          // step 1 wouldn't be taken. Otherwise, the "yeaaaaaa" function would be
          // run in the parent class.
          // After 5 minutes, the soooooooo function would run
          // Then, 20 minutes in (now + 5 min + 15 min) the document_it would run
          // Then, again he'd repeat the yeaaaaaa function on the next cron
          // Then repeat the soooooooo function at about 20 minutes in (now + 5 min + 15 min + now + 5 min)
          // Then, the next day at 7am Los Angles time, he'd call have_meeting
          // Finally, "special_internal_lumbergh_func_redo_last" is magic and it'll
          // simply repeat the last step over and over again.
          //
          // Notes: remember before each step, he'd check to make sure he's not
          // already happy. Also, note that any valid strtotime is accepted as
          // the first parameter of the lumbergh_shenanigan and the second is the
          // function you'd like to run IF HE'S NOT HAPPY!

       $this->example_strat = array(
          new lumbergh_shenanigan("now", 'yeaaaaaa'),
          new lumbergh_shenanigan("+5 minute", 'soooooooo'),
          new lumbergh_shenanigan("+15 minute", 'document_it'),
          new lumbergh_shenanigan("now", 'yeaaaaaa'),
          new lumbergh_shenanigan("+5 minute", 'soooooooo'),
          new lumbergh_shenanigan("+1 day 7 AM America/Los_Angeles", 'have_meeting'),
          new lumbergh_shenanigan("+1 day 7 AM America/Los_Angeles", 'special_internal_lumbergh_func_redo_last')
       );
     }
}
*/
