<?php

$VERSION = "1.1";

/*************************************************************************
  do not forget to source $HOME/.cron_env
  Which contains the necessary credentials to operate the snapshots
*************************************************************************/

//~ Get command line options
$options = getopt("d::",array("dryrun::","debug::","retainDays:","help"));

if(array_key_exists("help", $options)){
  echo "\nUsage: php $argv[0] [options]\n";
  echo "vers. $VERSION\n";
  echo "\t--dryrun            Do not delete the snapshots\n";
  echo "\t--debug             Print events to the console\n";
  echo "\t--retainDays=[n]    Sets the amount of days to retain default is 30 days\n";
  echo "\t--help              This help\n\n";
  exit(0);
}

//~ Set the timestamp reference
$DEFAULT_RETAIN_DAYS=30;
$RETAINDAYS = array_key_exists("retainDays", $options)?$options['retainDays']:$DEFAULT_RETAIN_DAYS;
$TIMESTAMP_REF = strtotime("-$RETAINDAYS days", strtotime(date("Y-m-dTH:i:s+0000")));

//~ Global variables
$SNAPSHOT_LOG = "/var/log/ec2-snapshots-rotate.log";
$EC2_API_TOOLS = "/var/www/bin/ec2-api-tools/bin/";
$SNAPSHOT_EXCLUDES = array('snap-38exx');

//~ Setting variables from command line
$DEBUG = array_key_exists("debug", $options);
$DRY_RUN = array_key_exists("dryrun", $options);


#~ ------------------------------------------------------------------------------------------
#~ MAIN SCRIPT
#~ Author : F.Brunet
#~ Date : 2014.03.17
#~ Version : $VERSION
#~
#~ What is it:
#~ Rotate the EC2 snapshots
#~ v.1.1
#~ - Fixed unabel to delete a snapshot in use by an AMI
#~ - Fixed output array not purged before receiving snapshots
#~
#~ ------------------------------------------------------------------------------------------

add2log($SNAPSHOT_LOG,"############ Starting Snapshots Rotation ############",$DEBUG);

//~ Get the current instance id
$INSTANCE_ID = chop( exec('/usr/bin/ec2metadata --instance-id') );
add2log($SNAPSHOT_LOG, "Instance ID: ".$INSTANCE_ID,$DEBUG);

//~ Get the volumes attached to this instance
exec($EC2_API_TOOLS."ec2-describe-instance-attribute $INSTANCE_ID -b | awk '{ print $3; }'", $INSTANCE_VOLUMES );

//~ Get the snapshots for each volume and delete the old ones
foreach( $INSTANCE_VOLUMES as $VOL_ID ){
  add2log($SNAPSHOT_LOG, "Treating $VOL_ID", $DEBUG);

  //~ Make sure the output array is empty
  $INSTANCE_SNAPSHOTS = array();

  //~ Getting the list of snapshots for a given volume id and make sure the snapshot is finished
  exec($EC2_API_TOOLS."ec2-describe-snapshots --filter 'progress=100%' --filter 'volume-id=$VOL_ID' | grep -vE '^TAG.*'", $INSTANCE_SNAPSHOTS );

  //~ Going through each snapchot to verify if it can be deleted
  foreach($INSTANCE_SNAPSHOTS AS $SNAPSHOT){
    //~ Splitting the line into elements
    $SS_INFOS = preg_split('/\s+/', $SNAPSHOT);

    //~ Let's verify if the snapshot exist
    //~ exec($EC2_API_TOOLS."ec2-describe-snapshots --filter 'progress=100%' --filter 'volume-id=$VOL_ID' $SS_INFOS[1]",$SS_EXISTS_ARRAY);
    //~ $SS_EXISTS = preg_match('/does not exist$/', $SS_EXISTS_ARRAY[0]);

    //~ We compare the snapshot date with our reference date and exclude some snapshots
    //~ if($TIMESTAMP_REF>strtotime(date($SS_INFOS[4])) && !in_array($SNAPSHOT, $SNAPSHOT_EXCLUDES) && $SS_EXISTS ){
    if($TIMESTAMP_REF>strtotime(date($SS_INFOS[4])) && !in_array($SS_INFOS[1], $SNAPSHOT_EXCLUDES) ){
      add2log($SNAPSHOT_LOG, "$SS_INFOS[4] IID#$INSTANCE_ID VOL#$SS_INFOS[2] SS#$SS_INFOS[1] will be deleted",$DEBUG);

      //~ This snapshot is old and needs to be deleted
      if( $DRY_RUN ){ add2log($SNAPSHOT_LOG, "Dry Run", $DEBUG); }
      else{
        //~ Delete the snaphot
        exec($EC2_API_TOOLS."ec2-delete-snapshot $SS_INFOS[1]",$SS_DELETE);
      }
    }
  }
}

add2log($SNAPSHOT_LOG,"############ Snapshots Rotation Finished ############",$DEBUG);

//~ Clean exit
exit(0);


#~ ------------------------------------------------------------------------------------------
#~ FUNCTIONS
#~ ------------------------------------------------------------------------------------------

//~ This will add a line to a log file
function add2log($filename,$entry,$debug=true){
  $TimeStamp = date("Y-m-dTH:i:s+0000");
  $entry .= "\n";
  $FileHandle=fopen($filename, "a");

  if($FileHandle){
    fwrite($FileHandle, "[ $TimeStamp ] ".$entry);
    fclose($FileHandle);

    //~ If debug is enable we also print some infos to the console
    if($debug){ echo($entry); }
  }
} //~ add2log

?>
