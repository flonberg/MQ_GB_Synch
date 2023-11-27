<?php

//require_once('H:\inetpub\lib\ESB\_prod_\ESBRestProto.inc');
require_once 'H:\inetpub\lib\sqlsrvLibFL_dev.php';
require_once('H:\inetpub\lib\phpDB.inc');
require_once('H:\inetpub\lib\switchConnMQ.inc');
require_once('.\ESBLocationSettingsClass.php');
$wDir = getcwd();
$mylocation = new Location(); 
$libPath = getLibPath();

require_once("H:\inetpub\lib\FL_ESB\\".$mylocation->path."\\ESBRestSched.inc");
require_once("H:\inetpub\lib\FL_ESB\\".$mylocation->path."\\ESButils.inc");
require_once("H:\inetpub\lib\FL_ESB\\".$mylocation->path."\\ESBproto-prod.inc");


	$handle242 = connectToDB(0);
	echo "<pre>"; print_r($params); echo "</pre>"; 
//	$gp2 = makeIncFile();									// gp2  open the 'a+', MQ-WBSynchAll  incremental log file which records successive runs
	$fp = makeNonIncFile($params);								// fp  open the 'w+' MQ_Synch file which overWrites for each run 
	$oe = makeOnlyEditFile();
	$tf = makeTestFile();
	$params = packParams();
	$reSched = new ESBRestReschedule(); 							// instanciate the SOAP class for ReScheculing
	$tslt = new ESBRestTimeslot();           						// instantiate the REST class for getting th timeSlots
	$reSched->debug = false;
	$reSched->locate();									// orrig run of locate function is before debug is set. 
	$handle = connectMSQ();                                    				// connect to MQ database                     
	$dBugArray = array();							
	$firstDate = setFirstDate($params);
	$numLoops = setNumLoops($params);
	echo "<br> 32 numLoops is $numLoops <br>"; 

/**
 * Does Synchronizing one day at a time
 */
	if (isset($params['StartDate']))
		$firstDate = new DateTime($params['StartDate']);
	if (isset($params['EndDate']))
		$EndDate = new DateTime($params['EndDate']);
	$MQday = clone $firstDate;								// MQday is modified to advance to next day
	for ($i = 0; $i < $numLoops; $i++){						// go forward by 1 day, repeat number specified times
		echo "<br> 44 i is $i <br>"; 
		fwrite($fp, "\r\n \r\n \r\n for theDay ". $MQday->format("Y-m-d")."\r\n");
		$MQdayClone = clone $MQday;
		$MQrow = getFromMQ_dual($MQdayClone);						// get data from MQ
		$WBdayClone = clone $MQday;
		$WBrow242 = getFromWBdual($row,  $MQday,  &$dBugArray, 0);			// get data from WB, from 242 if running in _dev_ or WB if running in _prod_ 
		$MQdayClone = clone $MQday;
		$isEdit = matchAppointments($WBrow242, $MQrow, $MQday, $oe);
//		makeLogEntry( "poll","info",  __FILE__, $logMessage);
		$MQday = advanceToNextWeekday($MQday);				
		if (isset($EndDate) && $MQday > $EndDate)					// 9-1-2021 
		{ echo "<br> 55 <br>"; break;}
	}
	fclose($fp); fclose($oe); fclose($tf);
	exit("clean");

function getLibPath(){
		$wd = getcwd();
		if (strpos($wd, "prod") !== FALSE)
			return "_prod_";
		if (strpos($wd, "dev") !== FALSE)
			return "_dev_";
	}

/**
 * Gets Appts from WB and stored them in an array with the PatID as key.  If > 1 appt, sort by StartTime
 */
function getFromWBdual($row,  $firstDay,  $dBugArray, $mode){
	global $fp, $tslt, $params, $mylocation, $tf;
	$dates2 = make2WBdates($firstDay);
	fwrite($fp, "\r\n______________________ \r\n  getting From WB for ". $dates2['start'] ." to ".   $dates2['end'] ."\r\n ");
	echo "<br> \r\n______________________ \r\n  getting From WB for ". $dates2['start'] ." to ".   $dates2['end'] ."\r\n <br> ";
	$ts  = $tslt->timeslotRestRequest("","", $dates2['start'], $dates2['end']);	// get the timeSlots for the day
	$adHocIndex = 0;
	foreach ($ts as $key=>$val){
		if (strpos($val['SessionState'], "CANCELED") !== FALSE){
			fwrite($fp, "\r\n found CANCELED TimeSlot for ". $val["PatientID"]);
			continue;
		}
		if (is_null($patArray[$val['PatientID']]))
			$patArray[$val['PatientID']] = array();
		$localTime = new DateTime( $val['StartDateTime']);				// create DateTime from string $val['StartDateTime'] which is UTC
		$localTime->setTimeZone(new DateTimezone('America/New_York'));			// transform to local timeSone
		$val['WBStartTimeLocal'] = $localTime->format('Y-m-d H:i');			// Store for Display
		/*  Ad Hoc change appt to SIM  */
		if ($params['ChangeWBtoSIM'] == 1 && strpos($val['PatientID'], $params['PatID']) !== FALSE && $key == 0){
			echo "<br> change to SIM ". $val['PatientID'] ." <br>"; 
		}
		array_push($patArray[$val['PatientID']], $val);					// PUSH the appt data into Pt array
	}
	if (is_null($ts)){									// if NO WB appts found. 
		fwrite($fp, "\r\n No WB records found for ". $firstDay);
		$dBugArray[$firstDay] = "No WB records found ";
	}
	foreach ($patArray as $key => $val){							// if there are > 1 appt 
		if (count($val) > 1){								// sort earlier => first
			usort($val, "earlier");
			$patArray[$key] = $val;							// replace orriginal with the sorted one. 
		}
	}

    	if (isset($params['ChangeWBtoSIM'])){							// this param choses either the '0' or '1' appt to be changed
		 $patArray[$params['PatID']][$params['ChangeWBtoSIM']]['SessionLabel'] = 'SIM'; //  Change the SessionLabel for TESTing
	    }
	$tat = print_r($patArray[$params['PatID']], true); fwrite($tf, "\r\n from WB \r\n"); fwrite($fp, $tat);		// write out the element being tested
	return $patArray;
}
/**
 * Get Appts. from Mosaiq 
 */
function getFromMQ_dual($mqDay){
   global $fp, $handle, $handle242, $mylocation, $params, $tf;
   $intStart = $mqDay->format("Y-m-d");
   $endDate = clone $mqDay;
   $endDate->modify("+ 1 day");
   $nextDay = advanceToNextWeekDay($mqDay);
   $intEnd = $endDate->format("Y-m-d");
   $selStr = "SELECT TOP(100)  Sch_Id, App_DtTm, IDA, PAT_NAME, Duration_time, LOCATION, SysDefStatus, CGroup
	   FROM vw_Schedule 
            WHERE App_DtTm > '".$intStart."'  AND  App_DtTm < '".$intEnd."'                  
	    AND IDA LIKE '[0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]' 
		 AND LOCATION LIKE '%GBPTC' ORDER BY App_DtTm";    					// nnn-nn-nn and GBPTC,  ORDER BY App_DtTm -> earlier is first in array
    $handleUsed = $handle;

											echo "\r\n 377 selStr is <br> $selStr \r\n"; 
    $dB = new getDBData($selStr, $handleUsed);
	/*  Get the parameters for MQ and save them in dataStruct with Pat MRN as key  */
     $apptsFound = 0;
    while ($assoc = $dB->getAssoc()){
	if (isTooEarly($assoc)){
		    continue;;	    
	}
       if (strpos($assoc['SysDefStatus'], 'X') !== FALSE){
  		fwrite($fp, "\r\n found canceled plan for PatId = ". $assoc['IDA'] ." name ". $assoc['PAT_NAME'] ." and ignoring it ");
	        continue;
       }
       $pRow[$assoc['IDA']] = array();					// create array for this patient. 	
       $UTC_dateTime = clone $assoc['App_DtTm'];
       $UTC_dateTime->setTimezone(new DateTimeZone('UTC'));
       $dArray = array(  
	       'SysDefStatus' => $assoc['SysDefStatus'],
	       'Duration'=> $assoc['Duration_time']/(60 * 100),
	       'Sch_Id'=> $assoc['Sch_Id'],
	       'IDA'=> $assoc['IDA'],
	       'CGroup'=> $assoc['CGroup'],
	       'PAT_NAME'=> $assoc['PAT_NAME'],
	       'Location'=> $assoc['LOCATION'],
	       'MQ_StartTime_DateTime'=> $assoc['App_DtTm'],
	       'MQ_StartTimeUTC' => $UTC_dateTime,
		'MQ_StartTimeUTCstringTZ' => $UTC_dateTime->format('Y-m-d\TH:i:s\Z'),	// store the UTC StartTime 
	           );
       		array_push($pRow[$assoc['IDA']], $dArray);							// push the Appt. data onto the PatientKey array
       /*******  add a appt for testing *********/ 
		if (strpos($assoc['IDA'], $params['PatID']) !== FALSE){					// this is the PatID to add a appt for 
       		   if (strpos($mylocation->path, "dev") !== FALSE && $params['AddPlan'] >= 1){			// Params file says add MQ plan
			$addAppt = addMQappt($assoc);
			array_push($pRow[$assoc['IDA']], $addAppt);						// push the Appt. array on to the Patient's array
	 	 }
	      $apptsFound++;	
       }
		if (strpos($assoc['IDA'], $params['PatID']) !== FALSE)					// this is the PatID to add a appt for 
		{	      echo "<br> kkkkkk <br>";  echo "<pre>"; print_r($pRow[$assoc['IDA']]); echo "</pre>"; }
    }											// end of main loop
    foreach ($pRow as $key => $val){							// if there are > 1 appt 
		if (count($val) > 1){							// sort earlier => first
			$num = count($val);
			usort($val, "earlierMQ");
			$pRow[$key] = $val;						// replace orriginal with the sorted one. 
		}
	}
	if (isset($params['ChangeMQtoSIM'])){
		echo "<br> qqqqqqqq  <br>"; 
		 var_dump($pRow[$params['PatID']][$params['ChangeMQtoSIM']]['CGroup']);
	    }
    return $pRow;
}
/**
 * Add an AdHoc Appt. for Testing
 */
function addMQappt($assoc){

       $UTC_dateTime = clone $assoc['App_DtTm'];
       $UTC_dateTime->setTimezone(new DateTimeZone('UTC'));
       $d2array = array(  										// Make a Copy of the orrig array
	       		'SysDefStatus' => $assoc['SysDefStatus'],
		        'Duration'=> $assoc['Duration_time']/(60 * 100),
	       		'Sch_Id'=> $assoc['Sch_Id'],
	       		'IDA'=> $assoc['IDA'],
	       		'CGroup'=> $assoc['CGroup'],
			 'PAT_NAME'=> $assoc['PAT_NAME'],
			 'Location'=> $assoc['LOCATION'],
			 'MQ_StartTime_DateTime'=> $assoc['App_DtTm'],
			 'MQ_StartTimeUTC' => $UTC_dateTime,
			  'MQ_StartTimeUTCstringTZ' => $UTC_dateTime->format('Y-m-d\TH:i:s\Z'),	// store the UTC StartTime 
		        );
	    	$d2array['CGroup'] = $params['CGroup'];							// change the Appt Type 
		$cloneForMod  = clone $d2array['MQ_StartTime_DateTime'];				// make CLONES to avoid changing the orrig values
		   //    	$cloneForMod->modify("+ ". $params['minutes'] ." minutes");				// modify the MQStartTime
		if (isset($params['minutes']))
			$cloneForMod = modifyDateTime($cloneForMod, $params['minutes']);
		$d2array['MQ_StartTime_DateTime']= $cloneForMod;					// store the modified time
		$cloneForTZ = clone $cloneForMod;							// make a clone for the TimeZone shift
		 $cloneForTZ->setTimezone(new DateTimeZone('UTC'));					// shift to UTC timeZone
		$d2array['MQ_StartTimeUTC'] = $cloneForTZ;						// store the UTC StartTime
		$d2array['MQ_StartTimeUTCstringTZ'] = $cloneForTZ->format('Y-m-d\TH:i:s\Z');		// stor the TZ format of the modified UTC time. 
   		fwrite($tf, "\r\n Modified and duplicated MQ Appt is \r\n"); $tarr = print_r($d2array, true); fwrite($tf, "\r\n $tarr \r\n");
	return $d2array;
}

/**
 * Matches appointments by ORDER for each Patient for a given day AND does the WB Edit 
 */
function matchAppointments($WB, $MQ, $MQday, $oe)
{
	global $oe, $fp, $reSched, $tf, $params;

	$now = new DateTime();										// 2021-09-03
	$nowStr = $now->format("Y-m-d H:i:s");								// to use for logging. 
	$dateStr = $MQday->format("Y-m-d");
	$lp = 0;
	$apptEdited = FALSE;
		foreach ($MQ as $key =>$val){								// Step thru the MQ PATIENTs
			$numMQ  = count($val);								// Count of MQ appt. for THIS PT. ON THIS DAY
			$numWB  = count($WB[$key]);							// mm for WB
			if ( count($WB[$key]) == 0){							// if there are NO WB appts
				fwrite($fp, "\r\n No WB records found for $key ".$val[0]['PAT_NAME']." \r\n ");			// record in log
				continue;								// goto next
			}
			$matched = FALSE; $lp = 0;							// loop initialization
			foreach ($val as $kkey =>$vval){						// foreach MQ appt for this Pt
				if (strpos($vval['IDA'], $params['PatID']) !== FALSE)
					fwrite($tf, "\r\n \r\n  ---------  \r\n key is $key  PatientName is ". $vval['PAT_NAME']  ." - $numMQ MQ Appts --$numWB WB Appts \r\n");
				fwrite($fp, "\r\n \r\n  ---------  \r\n key is $key  PatientName is ". $vval['PAT_NAME']  ." - $numMQ MQ Appts --$numWB WB Appts \r\n");
				$WBmatchKey = 0;							// set the WBkey to the MQkey   
			   do {										// step thru the WB appts for this Pt. 
				   fwrite($fp, "\r\n The $kkey MQ Appt CGroup is ".$vval['CGroup'] ." the $WBmatchKey WB appt has SessionType = ". $WB[$key][$WBmatchKey]['SessionLabel'] 
				   ." SessionState is ".  $WB[$key][$WBmatchKey]['SessionState']     );
				if (									// match MQ->PRO with WB->TD, OR  MQ->SIM with WB->SIM
					(strpos($vval['CGroup'], 'PRO') !== FALSE &&   strpos($WB[$key][$WBmatchKey]['SessionLabel'], "TD") !== FALSE)	// both are Treatment
					 || (strpos($vval['CGroup'], 'SIM') !== FALSE &&   strpos($WB[$key][$WBmatchKey]['SessionLabel'], "SIM") !== FALSE) // both are SIM
				)									// both R Treatments OR both R SIMs
				{									// if the appts are of the same type  MATCH
					$matched = true; 					
					fwrite($fp, "\r\n For the   MQ appt. CGroup is ". $vval['CGroup'].",	and the $WBmatchKey  WB appt had SessionLable is ". $WB[$key][$WBmatchKey]['SessionLabel']  ." 
						MQ_StartTimeUTC is ". $vval['MQ_StartTimeUTCstringTZ'].", WB StartTimeUTZ is ". $WB[$key][$WBmatchKey]['StartDateTime'] 
							."\r\n Duration is ". $vval['Duration'].", WB Duration is ". $WB[$key][$WBmatchKey]['Duration'] 
					  );
					fwrite($fp, "\r\n These Appoint-Types match so check to see if editing is needed \r\n");
					$timeC = strcmp( $vval['MQ_StartTimeUTCstringTZ']  , $WB[$key][$WBmatchKey]['StartDateTime']) ;  
					fwrite($fp, "\r\n 	MQ_StartTimeUTC is ". $vval['MQ_StartTimeUTCstringTZ'].", WB StartTimeUTZ is ". $WB[$key][$WBmatchKey]['StartDateTime'] );
					fwrite($fp, "\r\n  Duration is ". $vval['Duration'].", WB Duration is ". $WB[$key][$WBmatchKey]['Duration']);
					$tSum = intval($vval['Duration']) - intval($WB[$key][$WBmatchKey]['Duration']);	
					if ($timeC !== 0  || $tSum !== 0  || $params['EditSame'] == 1)			// IF THERE IS A DIFFERENCE  doesn't work unless make these intermed vars $timeC and $tSum 
					{
						$rPm = array('SessionID' => $WB[$key][$WBmatchKey]['SessionID'],
							'SessionID' => $WB[$key][$WBmatchKey]['SessionID'],
							'TimeslotID' => $WB[$key][$WBmatchKey]['TimeslotID'],
							'RoomID' => $WB[$key][$WBmatchKey]['RoomID'],
							'StartTime' => $vval['MQ_StartTimeUTCstringTZ'],
							'Duration' => $MQ[$key][$WBmatchKey]['Duration']
						);
						//  Write the Edit Message to Log Files  //
						if ($params['DryRun'] == 1)
							{fwrite($fp, "\r\n Dry Run so no actual edits");fwrite($fp, "\r\n Dry Run so no actual edits");  }
						fwrite($fp, "\r\n Editing ". $vval['IDA'] ." --- ". $vval['PAT_NAME'] ." from orrig WB Start time of  ".  $WB[$key][$WBmatchKey]['StartDateTime']  ." to ". $vval['MQ_StartTimeUTCstringTZ']    ."\r\n");
						fwrite($fp, " from orrig WB Duration of  ".  $WB[$key][$WBmatchKey]['Duration']  ." to ". $vval['Duration']    ."\r\n");
						fwrite($oe, "\r\n ". $nowStr ."  Editing ". $vval['IDA'] ." --- ". $vval['PAT_NAME'] ." from orrig WB Start time of  ".  $WB[$key][$WBmatchKey]['StartDateTime']  ." to ". $vval['MQ_StartTimeUTCstringTZ']    ."\r\n");
						fwrite($oe, " from orrig WB Duration of  ".  $WB[$key][$WBmatchKey]['Duration']  ." to ". $vval['Duration']    ."\r\n");
						editWBsession($rPm, $vval['IDA'], $oe);						// do the actual edits 
						$apptEdited = TRUE;	
				 	 }
					else {
						fwrite($fp, "\r\n Appointments are synchronized \r\n "); 
					}
				}									// end of 'if session type matches' code branch
				else {									// if the appts DONT match
					fwrite($fp, "\r\n WB Appt $kkey and WB appt. $WBmatchKey are NOT of same type \r\n");
					if (strpos($vval['IDA'], $params['PatID']) !== FALSE)
						fwrite($tf, "\r\n WB Appt $kkey and WB appt. $WBmatchKey are NOT of same type s NO EDITING \r\n");

				}
				if ($lp++ > $numWB) { 
					echo "<br> While loop unEndind <br>";
					fwrite($fp, "\r\n No Type Match appt. in WB for MQ $key appt. $kkey which is type  ".$vval['CGroup']  ."\r\n");
				       	break;
				}

				if ($WBmatchKey++ > $numWB)
						break;; 							// go to the NEXT WB Appt. 
			   } while ( $WBmatchKey < $numWB);							// numWB is number of WB Appts for this patient	
			   //} while ($matched === FALSE && $WBmatchKey < $numWB);	
				if ($matched == FALSE)
					fwrite($fp, "\r\n NO MATCH FOUND for the MQ appt $kkey which is type ". $vval['CGroup'] ." \r\n ");	
			}
	}
	if ($apptEdited === FALSE ){
		fwrite($oe, "\r\n No edits have been required  for $dateStr .\r\n ");
	}
	return $appEdited;
}

/**
 * Does the EDIT of the WB 
 */
function editWBsession($eArray, $patID, $oe){
	global $reSched, $fp, $params, $tf;
	echo "<br> 327 <br>"; var_dump($eArray);

	if ($params['DryRun'] == 0){
			fwrite($fp, "\r\n Edited WB with the following params.  \r\n");fwrite($oe, "\r\n Edited WB with the following params.  \r\n");			// write to OnlyEdits log
			$result = $reSched->rescheduleRestRequest($eArray['SessionID'],$eArray['TimeslotID'], $eArray['StartTime'], $eArray['RoomID'], $eArray['Duration']);// do the reschedule
	}
	else {
		fwrite($fpf, "\r\n would have edited $patID \r\n");fwrite($oe, "\r\n would have edited $patID with the following params \r\n");
				}
	$tst = print_r($eArray, true); fwrite($fp, $tst); fwrite($oe, $tst); 				// 8-31  write editParams to BOTH log files.
//	if (strpos($patID, $params['PatID']) !== FALSE)
	fwrite($fp, "\r\n If the REST rescheduleRestRequest die NOT work it would return error. ");
	ob_start(); var_dump($result); $data = ob_get_clean();fwrite($fp, $data); fwrite($oe, $data);	// write the result of the edit. 

}
/**
 * Mosaiq people put appointments at 6:40 AM as a placeholder, not as an actual schedule. Therefore the synchronizer needs to ignore there appointments.
 */
function isTooEarly($dT){
	global $fp;
	$cutDt = new DateTime($dT['App_DtTm']->format('Y-m-d'));					// Create a date for the cutoff time 
	$cutDt->modify('+ 7 hours');									// move the time to 7AM
	$tst = $dT['App_DtTm'] < $cutDt;								// Compare cutOff to actual Appt Time
	if ($tst)											// If the appt. is < 7 AM
  		fwrite($fp, "\r\n Appt. at ".$dT['App_DtTm']->format('Y-m-d H:i')  ." before 7AM  PatId = ". $dT['IDA'] ." name ". $dT['PAT_NAME'] ." and ignoring it ");
	return $tst;											// return Boolean of is Appt. < 7AM
}
function writeLogEntry($val,$vval, $key, $kkey, $WB, $fp){

	$numMQ  = count($val);
	$numWB  = count($WB[$key]);
	fwrite($fp, "\r\n MQ has $numMQ appts WB had $numWB \r\n");	
	$MQstartTime  = $vval['MQ_StartTime_DateTime']->format("Y-m-d H:i");
	fwrite($fp, "\r\n found $numMQ appts for ". $vval['PAT_NAME'] ."   ". $key);
	fwrite($fp," \r\n  $kkey   MQ  \t   ".$MQstartTime ."  \t  ". $vval['CGroup'] ." \t   ". $vval['Duration']    ." \n");
        fwrite ($fp, "	$kkey  WB  \t  ". $WB[$key][$kkey]['WBStartTimeLocal'] ." \t ".$WB[$key][$kkey]['SessionLabel'] ." \t ". $WB[$key][$kkey]['Duration'] ."\r\n ");
}
function checkSessionType($MQtype, $WBtype)
{
	if (strpos($WBtype, 'TD') !== FALSE && strpos($MQtype, 'PRO') !==FALSE)
		return true; 
	if (strpos($WBtype, 'SIM') !== FALSE && strpos($MQtype, 'SIM') !==FALSE)
		return true; 
	return 
		false;
}

/**
 * Script uses params from ReconMQ_WBlib.inc UNLESS there are GET params. So, if there are GET params substitute those for ReconMQ_WBlib.inc params
 */
function packParams(){
	global $fp, $oe, $mylocation;
	$paramFileName ="H:\inetpub\lib\FL_ESB\\".$mylocation->path."\\ReconMQ_WB_dual_Params.txt"; // changed to FL_ESB to avoid SVN
	$inp = file_get_contents($paramFileName);
	$ret = json_decode($inp, true);
	fwrite($oe, "\r\n  387 params are \r\n ");
	fwrite($fp, "\r\n 387 params are \r\n ");
	$ts = print_r($ret, true); fwrite($fp, $ts); fwrite($oe, $ts);
	 echo "<pre>"; print_r($ret); echo "</pre>" ; 
	return $ret;	
}
function getLoc(){
    $CWD = getcwd();
	if (strpos($CWD, 'qa') !== FALSE) $loc = '_qa_';
	if (strpos($CWD, 'dev') !== FALSE) $loc = '_dev_';
	if (strpos($CWD, 'prod') !== FALSE) $loc = '_prod_';
	return $loc;
}
function makeTestFile(){
	global $params; 
	$loc = getLoc();	
	$fileName = "H:\\inetpub\\esblogs\\".$loc."\\MQ-WB_TestFile.log"; 
	$fp = fopen($fileName, "w+");
	$now = new DateTime();
	$nowStr = $now->format("Y-m-d H:i:s");
	if ($params['AddPlan'] == 1){
		fwrite($fp, "\r\n ". $params['phrase'] ." \r\n ");
		fwrite($fp, "\r\n Adding a MQ PRO plan with a start time moved by ". $params['TimeIncrement'] ." minutes, to test 2 MQ and 2WB appointment matching.  \r\n ");
	}
		
	fwrite($fp, "\r\n Date is  $nowStr \r\n ");
	fwrite($fp, "\r\n Input Parameters are \r\n "); 
	$tr = print_r($params, true); fwrite($fp, $tr);
	return $fp;
}

/**
 * Create a log file which only records edits
 */
function makeOnlyEditFile(){
	global $params, $mylocation;
	$CWD = getcwd();
	if (strpos($CWD, 'qa') !== FALSE) $loc = '_qa_';
	if (strpos($CWD, 'dev') !== FALSE) $loc = '_dev_';
	if (strpos($CWD, 'prod') !== FALSE) $loc = '_prod_';
    	$fileName = "H:\\inetpub\\esblogs\\".$loc."\\MQ-WB_OnlyEdits_dual.log"; 
	$fp = fopen($fileName, "a+");
	$now = new DateTime(); $nowString = $now->format('Y-m-d H:i:s');
	fwrite($fp, "\r\n ______ \r\n  $nowString mylocation->path is ". $mylocation->path ." \r\n ");
	return $fp;
}	

/**
 * Create a log file which is overwritten by successive runs of the script
 */
function makeNonIncFile($params){
	global  $mylocation;
	$loc = $mylocation->path;
	$now = new DateTime(); $nowString = $now->format('Y-m-d');
	$CWD = getcwd();
	if (strpos($CWD, 'qa') !== FALSE) $loc = '_qa_';
	if (strpos($CWD, 'dev') !== FALSE) $loc = '_dev_';
	if (strpos($CWD, 'prod') !== FALSE) $loc = '_prod_';
        $fileName = "H:\\inetpub\\esblogs\\".$loc."\\MQ-WBSynch_dual".$nowString.".log"; 
	try {
		$fp = fopen($fileName, "w+");
		if ($fp) echo "\r\n $fileName opened";
	}
	catch(Exception $e) {
		echo 'Message'. $e->getMessage();
	}
	if ($params['debug'] == '1') { echo "<br> 99 filename is $fileName <br>"; var_dump($fp);} 
	$now = new DateTime(); $nowString = $now->format('Y-m-d H:i:s');
	try {
		$ret = fwrite($fp, "\r\n ______ \r\n  $nowString mylocation->path is ". $mylocation->path ." \r\n ");
		if ($res === false)
			echo "\r\n first write to $fileName failed ";
		else
			echo "\r\n first write to $fileName succeeded"; 
	} 
	catch(Exception $e) {
		echo 'first write failed '. $e->getMessage();
	}
        $ds = print_r($params, true); fwrite($fp, "\r\n params \r\n"); fwrite($fp, $ds);
	return $fp;
}	

/**
 * Create a running log file which is added tp by successive runs of the script
 */
function makeIncFile(){
	global $params, $mylocation;
	$CWD = getcwd();
	$now = new DateTime(); $nowString = $now->format('Y-m-d');
	if (strpos($CWD, 'qa') !== FALSE) $loc = '_qa_';
	if (strpos($CWD, 'dev') !== FALSE) $loc = '_dev_';
	if (strpos($CWD, 'prod') !== FALSE) $loc = '_prod_';
        $fileName = "H:\\inetpub\\esblogs\\".$loc."\\MQ-WBSynchAll_dual".$nowString.".log"; 
	$gp = fopen($fileName, "a+");
	$now = new DateTime(); $nowString = $now->format('Y-m-d H:i:s');
	fwrite($gp, "\r\n ______ \r\n  $nowString mylocation->path is ". $mylocation->path ." \r\n ");
        $ds = print_r($params, true); fwrite($gp, "\r\n WB dates \r\n"); fwrite($gp, $ds);
	return $gp; 
	}	

function getWBfrom242($date)
{
	global $handle242;
	$dateStr = substr($date, 0, 10);
	echo "<br> 259 <br>"; var_dump($dateStr);
	$selStr = "SELECT * FROM ProtomSchedule WHERE DateString = '$dateStr'";
	$dB =  new getDBData($selStr, $handle242);
	echo "<br> 259 <br>"; var_dump($dB);
	echo "<br>  242 selStr 282 <br> $selStr <br>"; 
	$wholeArray = array();
	$lp = 0;
	while ($assoc = $dB->getAssoc()){
		$wholeArray[$lp++] = $assoc;
				
	}
	return $wholeArray;
//	echo "<pre>"; print_r($wholeArray); echo "</pre>"; 
}

function earlier($a, $b)
{
	$aTS = strtotime($a['StartTime']);
	$bTS = strtotime($b['StartTime']);
	return $aTS - $bTS;
}
function earlierMQ($a, $b)
{
	return $b['MQ_StartTime_DateTime'] < $a['MQ_StartTime_DateTime'];
}

function makeIntervalDates(){
	global $numDays;
	$firstDay = new DateTime();
	$tDay = new DateTime();
	for ($i=0; $i < $numDays; $i++){
		$tDay = advanceToNextWeekday($tDay);
	}
	$startDateString = $firstDay->format('Y-m-d');
	$endDateString = $tDay->format('Y-m-d');
	return array($startDateString, $endDateString);
}
/**
 * Make startDate and endDate, formatted for Mosaiq, skippin thru weekends. Since $dt is an Object it is passed by reference. 
 */
function makeMosaiqDates($dt){
	$mQ['firstDay'] = $dt->format('Y-m-d');
	$dt = advanceToNextWeekday($dt);
	$mQ['nextDay'] = $dt->format('Y-m-d');
	return $mQ; 
}

function advanceToNextWeekday($d){
    	$d->modify('+1 days');
	$d = goToMonday($d);
   	return $d;
}
function advanceToNextWeekdayString($dString){
	$d = new DateTime($dString);
    	$d->modify('+1 days');
	$d = goToMonday($d);
   	return $d->format("Y-m-d");
}
function modifyDateTime($DtTm, $n){
	if ($n > 0)
		$DtTm->modify("+ $abs minutes");
	if ($n < 0){
		$abs = abs($n);
		$DtTm->modify("- $abs minutes");
	}
	return $DtTm;
}
function makeFirstDate(){
	$date_time = new Datetime('midnight', new Datetimezone('America/New_York'));
	
	$fDate = new DateTime();
	$str1 = $fDate->format("Y-m-d");
	$str2 = "T00:00:00Z";
	return new DateTime($str1 . $str2);;
}

function goToMonday($d){
    if ($d->format('w') == '6')						    	// if it is a Saturday		
	    $d->modify('+2 days');					    	// go forward to Monday	
    if ($d->format('w') == '0')						    	// if it is a Sunday		
	    $d->modify('+1 days');					    	// go forward to Monday	
    return $d;
}
function make2WBdates($firstDate){
	$firstDateClone = clone $firstDate;					// don't affect orriginal $firstDate
	$firstDateClone->setTimeZone(new DateTimeZone('UTC'));			// transform to timeZone used in WB
	$secondDateClone = clone $firstDateClone;				// don't affect $firstDateClone
	$secondDateClone->modify("+ 1 Day");						
	$start = $firstDateClone->format("Y-m-d\TH:i:s\Z");
	$end = $secondDateClone->format("Y-m-d\TH:i:s\Z");
	$res =  array('start'=>$start, 'end'=>$end);
	return $res;
}
function setNumLoops($params){
	$numLoops = $params['NumDays'];
	if ($params['Go3Weeks'] == 1){
		$numLoops = numDaysToGo3Weeks();						// number of days to go forward 3 weeks
		echo "<br> 560 numLoops is $numLoops <br>";
	}
	else
		$numLoops = $params['NumDays'];
	return $numLoops;
	}	

/**
 * Calculate the number of iterations of the main loop to make the synchronizer go forward 3 weeks
 */
function numDaysToGo3Weeks(){
	global $params;
	$today = new Datetime();
	$dow = $today->format('w');								// dayOfWeek number, Sunday => 0
	$numLoops =  15 - $dow + 1;
	if ($params['debug'] == '1') { $numLoops = 3; echo "\r\n  numLoops is $numLoops \r\n"; }
	return $numLoops;
	}	
function setFirstDate($params){
	$firstDate = new Datetime('midnight', new Datetimezone('America/New_York'));		// Start of DataCollection interval is TODAY at MIDNIGHT
	if (isset($params["StartDate"])) 							// Set StartDate by Params file
		$firstDate = new Datetime($params['StartDate'],new Datetimezone('America/New_York'));
	$firstDate->setTimezone(new DateTimeZone('UTC'));
	return $firstDate;
}	


function makeLogEntry( $type,$severity,  $file, $message)
{
	global $numDays, $params, $fp;
	$startDay = date('Y-m-d');
	    // print "LOGOUT ".__DIR__." $message";
    $logdata = "[".date("c")."]";
    $logdata .= " ";
    $logdata .= "[".$severity."]";
    $logdata .= " ";
    $logdata .= "[".$file."]";
    $logdata .= " ";
    $logdata .= "[".$message."]";
    $logdata .= "\n";
    $logdate = date("Ymd");
    $CWD = getcwd();
    //if(strpos(__DIR__,"_dev_") !== false)
    if(strpos($CWD,"_dev_") !== false)
    {
      $loc = "_dev_";
    }
    //if(strpos(__DIR__,"_qa_") !== false)
    if(strpos($CWD,"_qa_") !== false)
    {
      $loc = "_qa_";
    }
    //if(strpos(__DIR__,"_prod_") !== false)
    if(strpos($CWD,"_prod_") !== false)
    {
      $loc = "_prod_";
    }

        switch($type)
    {
      case "schedule":
        $logfile = "H:\\inetpub\\esblogs\\".$loc."\\schedule-".$logdate.".log"; 
        file_put_contents($logfile,$logdata, FILE_APPEND | LOCK_EX);
      break;
      case "DICOM":
        $logfile = "H:\\inetpub\\esblogs\\".$loc."\\DICOM-".$logdate.".log"; 
        file_put_contents($logfile,$logdata, FILE_APPEND | LOCK_EX);
      break;
      case "ui":
        $logfile = "H:\\inetpub\\esblogs\\".$loc."\\ui-".$logdate.".log"; 
        file_put_contents($logfile,$logdata, FILE_APPEND | LOCK_EX);
      break;
      case "poll":
        $logfile = "H:\\inetpub\\esblogs\\".$loc."\\polling-".$logdate.".log"; 
        $ret = file_put_contents($logfile,$logdata, FILE_APPEND | LOCK_EX);
//        ob_start(); var_dump($ret); $d = ob_get_clean(); fwrite($fp, "\r\n ret from file_putContents \r\n "); fwrite($fp, $d);		//record the returned result
      break;
	}
}
/**
 * This does actual modification of WB data
 */
function makeRescheduleRequest($row, $MQday){
	global $oep, $fp, $gp2,  $reSched, $tslt, $params, $jMessage, $oe;
	$err = new ERROR();								// class containing 'logout' function  
	$now = new DateTime(); $nowString = $now->format('Y-m-d H:i:s');
	$i = 0;
	$logMessage = "\r\n";
	$cutoffTime = new DateTime($MQday);
	$cutoffTime->modify("+ 7 hours");
	foreach ($params as $key=>$val){
		$logMessage .= " key is $key val is $val  ";
	}
	$logMessage .= "\r\n";
	$numRecordsEdited = 0;								// save num rec edited to write to JW logs. 
	foreach ($row as $key=>$val )							// loop thru the combined WB &  MQ data
    {
	    	if (strlen($val['PAT_NAME']) < 2)
			continue;    
		$jMessage[$key]['LastName'] = $val['PAT_NAME'] ; 			// $jMessage is used to send results to Angular Test Script. 
		$jMessage[$key]['MQ_Apt_Time'] = $val['MQ_StartTime'] ; 
		$jMessage[$key]['WB_Apt_Time'] = $val['WBStartTimeLocal'] ; 
		$jMessage[$key]['MQ_Duration'] = $val['Duration'] ; 
		$jMessage[$key]['WB_Duration'] = $val['WBDuration'] ; 
		if (strcmp($val['SysDefStatus'], 'X') == 0){					// if this is a  CANCELLED SESSION
			fwrite($gp2, "\r\n found canceled plan for PatId = ". $val[$key]['IDA'] ." name ". $val['PAT_NAME'] ." and ignoring it ");
			$jMessage[$key]['result'] = "Canceled Session is ignored"; 
			continue; 								// goToNext	
			}
		if ($val['MQ_StartTime_DateTime'] < $cutoffTime){
			$jMessage[$key]['result'] = "MQ Appt time < 7Am so  ignored"; 
			continue;
		}

		if (isset($val['UTC_MQ_StartTime'])) 						// if this session HAS MQ data 
		{
			fwrite($fp, "\r\n found MQ Pat ". $val['PAT_NAME'] ." appt time ". $val['MQ_StartTime']    );
			if (!isset($val['WBStartUTCTime'])){					// if there is NO WB date
				fwrite($fp, "\r\n patient ". $val['IDA'] ." NOT found in WB \r\n"); // record NO FOUND 
				$jMessage[$key]['result'] = "MQ patient  not found in WhiteBoard"; 
			}
		}

		if (isset($val['WBStartUTCTime']) && isset($val['UTC_MQ_StartTime']))
		{		// if data has been returned for this PatID from WB AND MQ
			

			$MQApp_DtTmString = $val['App_DtTm']->format("Y-m-d\TH:i:s\Z");						// create arg for strtotime
			/*  Compare Duration and StartTime to determine if Edit is needed   */ 
			if ($val['Duration'] == $val['WBDuration'] && $val['MQ_StartTime'] == $val['WBStartTimeLocal'])		// compare the 
			{													// if both StartTime and Duration are Synchronized
				fwrite($gp2, "\r\n ". $val['IDA'] ."--- ". $val['PAT_NAME'] ."   WB_UTC_StartTime is ". $val['WBStartUTCTime']   ."     for  WBStartTimeLocal is ".
						$val['WBStartTimeLocal']." WB and MQ are synchronized \r\n");	
				fwrite($fp, "\r\n ". $val['IDA'] ."--- ". $val['PAT_NAME'] ."   WB_UTC_StartTime is ". $val['WBStartUTCTime']   ."     for  WBStartTimeLocal is ".
						$val['WBStartTimeLocal']." WB and MQ are synchronized \r\n");	
				fwrite($fp, "\r\n ". $val['IDA'] ."  WB_UTC_StartTime is ". $val['WBStartUTCTime']   ." for WBStartTimeLocal is ".$val['WBStartTimeLocal']." MQ StartTime is "
					.$val['MQ_StartTime'] ." WB and MQ are Synchronized\r\n");
				$logMessage = "\r\n for PatID = ". $val['IDA'] .",  MRN  ". $val['PAT_NAME'] ."      MQ StartTime = ". $val['MQ_StartTime']."
					edited from ".$val['WBStartUTCTime'] ." to ". $val['MQ_StartTime_TZ']." and Duration from ". $val['WBDuration']." to ". $val['Duration'] ." minutes" ;
				$jMessage[$key]['result'] .= "MQ and WB are synchronized";				// record for Angular display 
				fwrite($gp2, "\r\n 88  \r\n ". $key ."--".$val['PAT_NAME'] ." Orrig WB Time ". $val['WBStartUTCTime']." MQ UTC time ". $val['MQ_StartTime_TZ']); // RECORD 'BEFORE' DATA
				fwrite($gp2, "\r\n 89  \r\n".  $key ."--".$val['PAT_NAME'] ." Orrig WB Duration ". $val['WBDuration']." MQ Duration ". $val['Duration']); 	 // RECORD 'BEFORE' DATA
					continue;
			}
			// DO THE REST EDIT REQUEST 
			if ($params['DryRun'] == '0'){								// DryRun == 1 ->  NO EDITS
					$result = $reSched->rescheduleRestRequest($val['SessionID'],$val['TimeslotID'], $val['UTC_MQ_StartTime'], $val['RoomID'], $val['Duration']);// do the reschedule
				$jMessage[$key]['result'] .= "  Edited  ". $val['PAT_NAME'] ." StartTime from ". $val['WBStartUTCTime']." to ". $val['MQ_StartTime_TZ'];
				$logMessage = "\r\n for PatID = ". $val['IDA'] .",  MRN  ". $val['PAT_NAME'] ."      MQ StartTime = ". $val['MQ_StartTime']."
					edited from ".$val['WBStartUTCTime'] ." to ". $val['MQ_StartTime_TZ']." and Duration from ". $val['WBDuration']." to ". $val['Duration'] ." minutes" ;
				fwrite($fp, $logMessage);
				fwrite($oe, "\r\n \r\n  on $nowString \r\n");
				fwrite($oe, $logMessage);
				$numRecordsEdited++;
			}
			else {
				$jMessage[$key]['result'] = " Dry Run Would have Edited  ". $val['PAT_NAME'] ." StartTime from ". $val['WBStartTimeLocal']." to ". $val['UTC_MQ_StartTime']
					." and Duration from ". $val['WBDuration'] ." to ". $val['Duration'];
				$logMessage = "\r\n DryRun: would have edited -- for PatID = ". $val['PAT_NAME'] ." MRN ". $val['IDA'] ." MQ StartTime = ". $val['MQ_StartTime']
						." from ".$val['WBStartUTCTime'] ." to ". $val['MQ_StartTime_TZ']." and Duration from ". $val['WBDuration']." to ". $val['Duration'] ." minutes" ;
				fwrite($fp, $logMessage);
				$numRecordsEdited++;
				}
			$WBdates = makeWBdates($params['StartDate']);							// make WB dates to get edited version of TimeSlot Data
			fwrite($gp2, "\r\n $logMessage \r\n ");
			fwrite($gp2, "\r\n ". $val['IDA'] ." WB StartTime updated");
		} 

    }													// end of FOR looop
	if ($numRecordsEdited == 0){
		$json= json_encode($jMessage);

		if (!isset($_GET['EndDate']))
			$retMessage = "All records synchronized for ". $_GET['StartDate'];
		if (isset($params['StartDate']))
			$retMessage = "All records synchronized for ". $params['StartDate'];
		fwrite($fp, "\r\n All records synschonized for ". $MQday." \r\n");
		fwrite($gp2, "\r\n All records synschonized for ". $MQday." \r\n");
		$logMessage .= "\r\n All records synschonized for ". $MQday." \r\n";
		$json= json_encode($jMessage);
		echo $json;
		return $logMessage; 
	}
	else {
	/*	if ($_GET['fromPHP'] == 1){
			echo "<pre>"; print_r($jMessage); echo "</pre>";
			return;
		}
	 */
		$json= json_encode($jMessage);
		echo $json;
		return $logMessage; 
	}
}
function makeWBdates($firstDay)
{
	global $params;

	$str2 = "T04:00:00.000Z";                                                       // make the time for the END of the interval
	$str3 = "T01:00:00.000Z";
	$str1 = $firstDay;
	$dt = new DateTime();
	$dt2 = clone $dt;
	$dt2->modify("+ 1 day");
	echo "<br>  3333333 <br>"; 
	var_dump($dt);
	$str4 = $dt2->format('Y-m-d\TH:i:s\Z');
   	$ret['start']=  $str1.$str3;
    	$ret['end']=  $str4;
	echo "<br> WBdates 999999 <br>"; echo "<pre>"; print_r($ret); echo "</pre>"; 
    	//$ret['end']=  $str1.$str2;
    return $ret;
}


