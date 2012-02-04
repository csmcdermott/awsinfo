#!/usr/bin/php -q
<?php

/**
 *  @File
 *  This script displays information about the snapshots associated with an AWS
 *  account.
 *
 *  We offer no warantee or guarantee - use this code at your own risk!
 *  All code is Copyright (C) 2011, Applied Trust Engineering, Inc.
 *
 */

/**
 *  This script expects an API key for Amazon's AWS EC2 service. It gets a list
 *  of all snapshots associated with the account (owner = self) and then
 *  analyzes the resulting list and prints out a details summary.
 *
 *  Written by Chris McDermott, AppliedTrust, chris@appliedtrust.com
 *    v1.1 8/19/2011
 *
 *  @param K
 *    Specify the Amazon EC2 API key.
 *  @param S
 *    Specify the Amazon EC2 API secret key. 
 *
 *  @return
 *    Returns 1 if there were errors, otherwise 0.
 */

putenv('TZ=America/Denver');
error_reporting(-1);

$options = getopt("S:K:f:");
if (!isset($options["S"])) { usage(); }
if (!isset($options["K"])) { usage(); }

$ec2options = array(
  'key' => $options['K'],
  'secret' => $options['S']
);

// This file is the Amazon AWS PHP SDK, available from
// https://aws.amazon.com/sdkforphp/
require_once '/usr/local/aws-php-sdk/sdk.class.php';

/**
 *  @data
 *    A two-dimensional array holding results and information about volumes.
 *    -volumeId: String holding the volumeId for the snapshot:
 *      -count: Integer holding the number of snapshots seen for this volume
 *      -first: Unix timestamp of the oldest snapshot of this volume
 *      -last: Unix timestamp of the newest snapshot of this volume
 *      -snapshots: Array holding the list of snapshotId's of all snapshots of this volume
 *      -text: String holding descriptive text about volume
 */

if (preg_match("/^(i-\w+)$/", $options['f'], $matches)) {
  $filter = array(
    'Filter.1.Name' => 'instance-id',
    'Filter.1.Value' => $options['f'],
  );
} elseif (preg_match("/^\d+\.\d+\.\d+\.\d+$/", $options['f'], $matches)) {
  $filter = array(
    'Filter.1.Name' => 'ip-address',
    'Filter.1.Value' => $options['f'],
  );
} else {
  $filter = array(
    'Filter.1.Name' => 'tag-value',
    'Filter.1.Value' => $options['f'],
  );
}

if (isset($options['f'])) {
  ec2_instance_detail($ec2options, $filter);
} else {
  ec2_client_summary($ec2options);
}

/***********************************************************************************************/

function usage() {
  echo "Boo!\n";
  exit(2);
}

function logthis($type, $message) {
  echo "$type: $message\n";
}

function ec2_client_summary($ec2options) {
  $ec2 = new AmazonEC2($ec2options);
  $response = $ec2->describe_instances();
  if($response->status != '200') {
    logthis('ERROR', 'Non 200 response to ec2->describe_instances: ' . $response->body->Errors->Error->Message);
    exit;
  }
  echo "\nList of EC2 instances:\n";
  foreach($response->body->reservationSet->item as $item) {
    $id = $item->instancesSet->item->instanceId;
    $type = $item->instancesSet->item->instanceType;
    $name = $item->instancesSet->item->tagSet->item->value;
    $state = $item->instancesSet->item->instanceState->name;
    if ($state == "running") {
      $ip = $item->instancesSet->item->ipAddress;
    } else {
      $ip = "\t\t";
    }
    echo " * $id $type ($state) | $ip\t| $name\n";
  }
  echo "\n";
}

function ec2_instance_detail($ec2options, $filter) {
  $ec2 = new AmazonEC2($ec2options);
  $response = $ec2->describe_instances($filter);
  $id = $response->body->reservationSet->item->instancesSet->item->instanceId;
  $name = $response->body->reservationSet->item->instancesSet->item->tagSet->item->value;
  $type = $response->body->reservationSet->item->instancesSet->item->instanceType;
  $ami = $response->body->reservationSet->item->instancesSet->item->imageId;
  $az = $response->body->reservationSet->item->instancesSet->item->placement->availabilityZone;
  $security_groups = "";
  foreach($response->body->reservationSet->item->instancesSet->item->groupSet->item as $group) {
    $security_groups .= $group->groupName . ", ";
  }
  $block_devices = array();
  foreach($response->body->reservationSet->item->instancesSet->item->blockDeviceMapping->item as $device) {
    $volumeId = $device->ebs->volumeId;
    $deviceName = $device->deviceName;
    $volume_response = $ec2->describe_volumes(array('VolumeId' => $volumeId));
    $description = $volume_response->body->volumeSet->item->tagSet->item->value;
    $options = array(
      'Filter.1.Name' => 'volume-id',
      'Filter.1.Value.1' => $volumeId,
    );
    $snapshots_response = $ec2->describe_snapshots($options);
    //print_r($snapshots_response);
    $numsnapshots = count($snapshots_response->body->snapshotSet->item);
    array_push($block_devices, "$volumeId - $deviceName ($description) ($numsnapshots snapshots)");
  }
  $state = $response->body->reservationSet->item->instancesSet->item->instanceState->name;
  if ($state == "running") {
    $ip = $response->body->reservationSet->item->instancesSet->item->ipAddress;
  } else {
    $ip = "(none)\t";
  }
  $reversedns = exec("/usr/bin/dig +short -x $ip >&1 /dev/null");
  if ($reversedns == "") {
    $reversedns = "No IP, so no reverse DNS";
  }
  echo "\n";
  echo "Instance $id\n";
  echo "  IP:\t\t" . $ip . "\t\t\t";
  echo "  Name:\t\t" . $name . "\n";
  echo "  State:\t" . $state . "\t\t\t\t";
  echo "  Type:\t\t" . $type . "\n";
  echo "  AMI:\t\t" . $ami . "\t\t\t";
  echo "  AZ:\t\t" . $az . "\n";
  echo "  Reverse DNS:\t" . $reversedns . "\n";
  echo "  Groups:\t" . $security_groups . "\n";
  echo "  Disks:\n";
  foreach($block_devices as $volume) {
    echo "\t$volume\n";
  }
  echo "\n";
}

?>
